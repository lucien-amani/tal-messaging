<?php
declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Modules\Campaigns\Repositories\CampaignRepository;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Messaging\Services\MessageService;

/**
 * Campagnes : brouillon → lancement (crée un envoi) → suivi → rapport.
 * Le statut « en cours » ou « terminé » est déduit de l'état réel des messages ; l'envoi lui-même est fait par le worker.
 */
final class CampaignService
{
    private static function opts(): array
    {
        return ['limit' => (int) Config::get('campaigns.max_recipients'), 'source' => 'campaign'];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function create(array $in, int $orgId, int $actorId): array
    {
        $id = (new CampaignRepository())->insert(self::fields($in, $orgId) + ['uuid' => uuid4(), 'organization_id' => $orgId, 'created_by' => $actorId]);
        return self::one($id);
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function update(int $id, array $in): array
    {
        $c = self::row($id);
        if ($c['status'] !== 'draft') {
            throw ApiException::conflict('Seul un brouillon peut être modifié. Dupliquez la campagne pour en créer une nouvelle version.', 'CAMPAIGN_LOCKED');
        }
        (new CampaignRepository())->update($id, self::fields($in + ['name' => $c['name'], 'body' => $c['body'], 'sender_id_id' => $c['sender_id_id'],
            'group_ids' => json_decode((string) $c['group_ids'], true) ?: [], 'segment_ids' => json_decode((string) $c['segment_ids'], true) ?: [],
            'scheduled_at' => $c['scheduled_at'] ? $c['scheduled_at'] . ' UTC' : null], (int) $c['organization_id']));
        return self::one($id);
    }

    public static function delete(int $id): void
    {
        $c = self::row($id);
        if (in_array($c['status'], ['launching', 'scheduled', 'running'], true)) {
            throw ApiException::conflict('Annulez d’abord la campagne avant de la supprimer.', 'CAMPAIGN_ACTIVE');
        }
        (new CampaignRepository())->softDelete($id);
    }

    /** @return array<string,mixed> */
    public static function duplicate(int $id, int $actorId): array
    {
        $c = self::row($id);
        $name = mb_substr((string) $c['name'], 0, 105) . ' (copie)';
        return self::create(['name' => $name, 'sender_id_id' => $c['sender_id_id'], 'body' => $c['body'],
            'group_ids' => json_decode((string) $c['group_ids'], true) ?: [], 'segment_ids' => json_decode((string) $c['segment_ids'], true) ?: []], (int) $c['organization_id'], $actorId);
    }

    /** Simulation complète (mêmes règles qu'un lancement) sans rien enregistrer. @param array<string,mixed> $in @return array<string,mixed> */
    public static function preview(int $orgId, array $in): array
    {
        return MessageService::preview($orgId, self::messageInput($in), self::opts());
    }

    /** @return array<string,mixed> */
    public static function launch(int $id, int $actorId): array
    {
        $c = self::row($id);
        $orgId = (int) $c['organization_id'];
        // Verrou : une seule requête peut passer de brouillon à « lancement » (anti double clic / double onglet)
        if (Database::execute("UPDATE campaigns SET status = 'launching' WHERE id = ? AND organization_id = ? AND status = 'draft'", [$id, $orgId]) === 0) {
            throw ApiException::conflict('Cette campagne a déjà été lancée.', 'CAMPAIGN_NOT_DRAFT');
        }
        try {
            $res = MessageService::send($orgId, self::messageInput($c) + ['idempotency_key' => 'campaign-' . $id], $actorId, self::opts());
        } catch (\Throwable $e) {
            Database::execute("UPDATE campaigns SET status = 'draft' WHERE id = ? AND status = 'launching'", [$id]);
            throw $e;
        }
        $b = $res['batch'];
        Database::execute(
            'UPDATE campaigns SET status = ?, batch_id = ?, total_recipients = ?, total_segments = ?, launched_at = ? WHERE id = ?',
            [$c['scheduled_at'] ? 'scheduled' : 'running', $b['id'], $b['total_messages'], $b['total_segments'], gmdate('Y-m-d H:i:s'), $id]
        );
        return self::one($id);
    }

    /** @return array<string,mixed> */
    public static function cancel(int $id): array
    {
        $c = self::row($id);
        if (!in_array($c['status'], ['scheduled', 'running'], true) || !$c['batch_id']) {
            throw ApiException::conflict('Seule une campagne programmée ou en cours peut être annulée.', 'CAMPAIGN_NOT_CANCELLABLE');
        }
        Database::execute("UPDATE messages SET status = 'cancelled', error_code = 'CANCELLED', error_message = 'Campagne annulée.' WHERE batch_id = ? AND organization_id = ? AND status = 'queued'", [$c['batch_id'], $c['organization_id']]);
        Database::execute("UPDATE campaigns SET status = 'cancelled', completed_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s'), $id]);
        BillingService::sweep((int) $c['organization_id']);
        return self::one($id);
    }

    /** @return array<string,mixed> */
    public static function one(int $id): array
    {
        return self::present(self::row($id));
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    public static function present(array $c): array
    {
        foreach (['n_queued', 'n_sending', 'n_sent', 'n_delivered', 'n_failed', 'n_cancelled', 'total_recipients', 'total_segments'] as $k) {
            $c[$k] = (int) $c[$k];
        }
        $c['group_ids'] = json_decode((string) $c['group_ids'], true) ?: [];
        $c['segment_ids'] = json_decode((string) $c['segment_ids'], true) ?: [];
        // Déduction du statut réel à partir des messages ; une campagne finie est enregistrée comme telle
        if (in_array($c['status'], ['scheduled', 'running'], true) && $c['batch_id']) {
            $pending = $c['n_queued'] + $c['n_sending'];
            if ($pending === 0) {
                $c['status'] = 'completed';
                Database::execute("UPDATE campaigns SET status = 'completed', completed_at = ? WHERE id = ? AND status IN ('scheduled', 'running')", [gmdate('Y-m-d H:i:s'), $c['id']]);
                $c['completed_at'] = gmdate('Y-m-d H:i:s');
            } elseif ($c['status'] === 'scheduled' && (!$c['scheduled_at'] || strtotime($c['scheduled_at'] . ' UTC') <= time())) {
                $c['status'] = 'running';
            }
        }
        $final = $c['n_sent'] + $c['n_failed'];
        $c['delivery_rate'] = $final > 0 ? round($c['n_delivered'] / $final * 100, 1) : null;
        $c['progress'] = $c['total_recipients'] > 0 ? (int) round(($c['n_sent'] + $c['n_failed'] + $c['n_cancelled']) / $c['total_recipients'] * 100) : 0;
        return $c;
    }

    /** @return array<string,mixed> */
    public static function report(int $id): array
    {
        $c = self::one($id);
        $byOperator = [];
        $errors = [];
        if ($c['batch_id']) {
            foreach (Database::select(
                "SELECT COALESCE(operator, 'inconnu') AS op, status, COUNT(*) AS n FROM messages WHERE batch_id = ? AND organization_id = ? GROUP BY op, status",
                [$c['batch_id'], $c['organization_id']]
            ) as $r) {
                $o = &$byOperator[$r['op']];
                $o ??= ['operator' => $r['op'], 'total' => 0, 'delivered' => 0, 'failed' => 0];
                $o['total'] += (int) $r['n'];
                if ($r['status'] === 'delivered') {
                    $o['delivered'] += (int) $r['n'];
                } elseif ($r['status'] === 'failed') {
                    $o['failed'] += (int) $r['n'];
                }
                unset($o);
            }
            $errors = Database::select(
                "SELECT COALESCE(error_code, 'UNKNOWN') AS code, MAX(error_message) AS message, COUNT(*) AS n FROM messages
                 WHERE batch_id = ? AND organization_id = ? AND status = 'failed' GROUP BY code ORDER BY n DESC LIMIT 5",
                [$c['batch_id'], $c['organization_id']]
            );
        }
        usort($byOperator, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
        return ['campaign' => $c, 'by_operator' => array_values($byOperator), 'errors' => array_map(static fn(array $e): array => ['code' => $e['code'], 'message' => $e['message'], 'count' => (int) $e['n']], $errors)];
    }

    public static function exportCsv(int $id): string
    {
        $c = self::row($id);
        $out = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['telephone', 'statut', 'operateur', 'segments', 'envoye_le', 'livre_le', 'erreur'], ';', '"', '');
        $safe = static fn(?string $v): string => ($v = (string) $v) !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v;
        if ($c['batch_id']) {
            $max = (int) Config::get('campaigns.export_max_rows');
            $last = 0;
            $written = 0;
            while ($written < $max) {
                $rows = Database::select(
                    'SELECT id, to_e164, status, operator, segments, sent_at, delivered_at, error_message FROM messages WHERE batch_id = ? AND organization_id = ? AND id > ? ORDER BY id LIMIT 1000',
                    [$c['batch_id'], $c['organization_id'], $last]
                );
                if (!$rows) {
                    break;
                }
                foreach ($rows as $r) {
                    fputcsv($out, [$safe($r['to_e164']), $r['status'], $r['operator'], $r['segments'], $r['sent_at'], $r['delivered_at'], $safe($r['error_message'])], ';', '"', '');
                    $last = (int) $r['id'];
                    $written++;
                }
            }
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** @return array<string,mixed> */
    private static function row(int $id): array
    {
        return (new CampaignRepository())->find($id) ?? throw ApiException::notFound('Campagne introuvable.');
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private static function messageInput(array $c): array
    {
        $dec = static fn(mixed $v): array => is_array($v) ? $v : (json_decode((string) $v, true) ?: []);
        return ['sender_id_id' => $c['sender_id_id'], 'body' => $c['body'], 'group_ids' => $dec($c['group_ids'] ?? []), 'segment_ids' => $dec($c['segment_ids'] ?? []),
            'scheduled_at' => $c['scheduled_at'] ? (str_contains((string) $c['scheduled_at'], 'T') ? $c['scheduled_at'] : $c['scheduled_at'] . ' UTC') : null];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> colonnes à enregistrer */
    private static function fields(array $in, int $orgId): array
    {
        $ints = static fn(mixed $v): array => array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []), static fn(int $i): bool => $i > 0)));
        $sched = null;
        if (!empty($in['scheduled_at'])) {
            $ts = strtotime((string) $in['scheduled_at']);
            if ($ts === false) {
                throw ApiException::validation(['scheduled_at' => ['Date de programmation invalide.']]);
            }
            $sched = gmdate('Y-m-d H:i:s', $ts);
        }
        $sender = $in['sender_id_id'] ?? null;
        if ($sender !== null && $sender !== '' && $sender !== 0) {
            $s = (new \App\Modules\SenderIds\Repositories\SenderIdRepository())->find((int) $sender);
            if ($s === null || (int) $s['organization_id'] !== $orgId) {
                throw ApiException::validation(['sender_id_id' => ['Sender ID introuvable.']]);
            }
        }
        return ['name' => trim((string) $in['name']), 'sender_id_id' => $sender ? (int) $sender : null, 'body' => trim((string) $in['body']),
            'group_ids' => json_encode($ints($in['group_ids'] ?? [])), 'segment_ids' => json_encode($ints($in['segment_ids'] ?? [])), 'scheduled_at' => $sched];
    }
}
