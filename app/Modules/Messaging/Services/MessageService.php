<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Messaging\SmsText;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Services\PricingService;
use App\Modules\Messaging\Repositories\BatchRepository;
use App\Modules\Messaging\Repositories\MessageRepository;
use App\Modules\SenderIds\Repositories\SenderIdRepository;

/**
 * Quick Send : aperçu, envoi (immédiat ou programmé), annulation, statistiques.
 * Les heures sont stockées en UTC (comme le reste de la base) ; l'interface les convertit à l'affichage.
 */
final class MessageService
{
    /**
     * Prépare un envoi sans rien écrire : valide, résout les destinataires et calcule les segments.
     * @param array<string,mixed> $in
     * @param array{limit?:int,source?:string} $opts limit : plafond de destinataires ; source : quick_send ou campaign
     * @return array<string,mixed>
     */
    public static function plan(int $orgId, array $in, array $opts = []): array
    {
        $body = trim((string) ($in['body'] ?? ''));
        $max = (int) Config::get('messaging.max_body_chars');
        if ($body === '') {
            throw ApiException::validation(['body' => ['Le message est vide.']]);
        }
        if (mb_strlen($body) > $max) {
            throw ApiException::validation(['body' => ["Message trop long ($max caractères maximum)."]]);
        }

        $sender = self::sender($orgId, $in['sender_id_id'] ?? null);
        $scheduled = self::schedule($in['scheduled_at'] ?? null);

        $res = RecipientResolver::resolve($orgId, $in, $opts['limit'] ?? null);
        $wallet = BillingService::wallet($orgId);
        $prices = PricingService::table($orgId, $wallet['currency']);
        $unpriced = 0;
        $totalCost = 0;
        $messages = [];
        $tooLong = 0;
        $empty = 0;
        $missing = 0;
        $segments = 0;
        foreach ($res['recipients'] as $r) {
            $rendered = SmsText::render($body, $r['vars']);
            if ($rendered['missing']) {
                $missing++;
            }
            if ($rendered['text'] === '') {
                $empty++;
                continue;
            }
            $a = SmsText::analyze($rendered['text']);
            if ($a['segments'] > 10 || mb_strlen($rendered['text']) > $max) {
                $tooLong++;
                continue;
            }
            $unit = PricingService::unit($prices, $orgId, $r['country'], $r['operator']);
            if ($unit === null) {
                $unpriced++;
                continue;
            }
            $cost = $unit * $a['segments'];
            $segments += $a['segments'];
            $totalCost += $cost;
            $messages[] = ['recipient' => $r, 'text' => $rendered['text'], 'encoding' => $a['encoding'], 'segments' => $a['segments'], 'cost' => $cost];
        }
        if (!$messages) {
            throw new ApiException('NO_RECIPIENTS', 'Aucun destinataire valide : vérifiez les numéros, les groupes ou les segments choisis.', 422, [
                'invalid' => array_slice($res['invalid'], 0, 20), 'blocked' => $res['blocked'], 'unpriced' => $unpriced,
            ]);
        }

        $template = SmsText::analyze($body);
        return [
            'org_id' => $orgId, 'sender' => $sender, 'body' => $body, 'scheduled_at' => $scheduled, 'messages' => $messages,
            'summary' => [
                'recipients' => count($messages), 'total_segments' => $segments, 'invalid_count' => count($res['invalid']),
                'invalid' => array_slice($res['invalid'], 0, 20), 'blocked' => $res['blocked'], 'duplicates' => $res['duplicates'],
                'missing_variables' => $missing, 'too_long' => $tooLong, 'empty_after_render' => $empty,
                'encoding' => $template['encoding'], 'length' => $template['length'], 'per_segment' => $template['per_segment'],
                'segments_per_message' => $template['segments'], 'variables' => SmsText::variables($body),
                'sender_name' => $sender['name'], 'scheduled_at' => $scheduled, 'unpriced' => $unpriced,
                'cost_micro' => $totalCost, 'currency' => $wallet['currency'], 'available_micro' => $wallet['available_micro'],
                'enough_balance' => $wallet['available_micro'] >= $totalCost,
                'examples' => array_map(static fn(array $m): array => ['to' => $m['recipient']['phone'], 'text' => $m['text'], 'segments' => $m['segments']], array_slice($messages, 0, 3)),
            ],
        ];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function preview(int $orgId, array $in, array $opts = []): array
    {
        return self::plan($orgId, $in, $opts)['summary'];
    }

    /**
     * Crée l'envoi et met chaque message en file. Un même idempotency_key rejoue l'envoi existant (double clic, réseau instable).
     * @param array<string,mixed> $in
     * @param array{limit?:int,source?:string} $opts
     * @return array<string,mixed>
     */
    public static function send(int $orgId, array $in, int $actorId, array $opts = []): array
    {
        $key = isset($in['idempotency_key']) && $in['idempotency_key'] !== '' ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $in['idempotency_key']) ?? '', 0, 64) : null;
        $batches = new BatchRepository();
        if ($key !== null && $key !== '') {
            $existing = Database::first('SELECT id FROM message_batches WHERE organization_id = ? AND idempotency_key = ?', [$orgId, $key]);
            if ($existing) {
                return ['batch' => self::batch((int) $existing['id']), 'replayed' => true] + ['summary' => null];
            }
        }
        $plan = self::plan($orgId, $in, $opts);
        $source = ($opts['source'] ?? 'quick_send') === 'campaign' ? 'campaign' : 'quick_send';
        $sender = $plan['sender'];
        $scheduleSql = $plan['scheduled_at'] ? gmdate('Y-m-d H:i:s', strtotime((string) $plan['scheduled_at'])) : null;
        $chunk = max(50, (int) Config::get('messaging.insert_chunk'));

        try {
            $batchId = Database::transaction(function () use ($batches, $plan, $sender, $scheduleSql, $key, $orgId, $actorId, $chunk, $source): int {
                $w = BillingService::lockWallet($orgId);
                $total = (int) $plan['summary']['cost_micro'];
                if ($w['available_micro'] < $total) {
                    throw new ApiException('INSUFFICIENT_BALANCE', 'Solde insuffisant : il faut ' . \App\Support\Money::format($total, $w['currency']) . ', disponible ' . \App\Support\Money::format(max(0, $w['available_micro']), $w['currency']) . '. Rechargez votre wallet.', 402);
                }
                $batchId = $batches->insert([
                    'uuid' => uuid4(), 'organization_id' => $orgId, 'sender_id_id' => (int) $sender['id'], 'sender_name' => $sender['name'],
                    'source' => $source, 'body' => $plan['body'], 'total_messages' => $plan['summary']['recipients'],
                    'total_segments' => $plan['summary']['total_segments'], 'scheduled_at' => $scheduleSql, 'idempotency_key' => $key ?: null, 'created_by' => $actorId,
                ]);
                Database::execute('UPDATE message_batches SET cost_micro = ? WHERE id = ?', [$total, $batchId]);
                foreach (array_chunk($plan['messages'], $chunk) as $part) {
                    $marks = [];
                    $params = [];
                    foreach ($part as $m) {
                        $r = $m['recipient'];
                        $marks[] = "(?,?,?,?,?,?,?,?,?,?,?,?,'queued',?,?,?,'held')";
                        array_push($params, uuid4(), $orgId, $batchId, $r['contact_id'], (int) $sender['id'], $sender['name'], $r['phone'], $r['country'], $r['operator'],
                            $m['text'], $m['encoding'], $m['segments'], $scheduleSql, $actorId, $m['cost']);
                    }
                    Database::execute(
                        'INSERT INTO messages (uuid, organization_id, batch_id, contact_id, sender_id_id, sender_name, to_e164, country, operator, body, encoding, segments, status, scheduled_at, created_by, cost_micro, billing_status) VALUES '
                        . implode(',', $marks),
                        $params
                    );
                }
                Database::execute('UPDATE wallets SET held_micro = held_micro + ? WHERE id = ?', [$total, $w['id']]);
                return $batchId;
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && $key) { // course : même clé envoyée deux fois en parallèle
                $existing = Database::first('SELECT id FROM message_batches WHERE organization_id = ? AND idempotency_key = ?', [$orgId, $key]);
                if ($existing) {
                    return ['batch' => self::batch((int) $existing['id']), 'replayed' => true, 'summary' => null];
                }
            }
            throw $e;
        }
        return ['batch' => self::batch($batchId), 'replayed' => false, 'summary' => $plan['summary']];
    }

    /** @return array<string,mixed> */
    public static function batch(int $id): array
    {
        $b = (new BatchRepository())->find($id) ?? throw ApiException::notFound('Envoi introuvable.');
        return self::presentBatch($b);
    }

    /** @param array<string,mixed> $b @return array<string,mixed> */
    public static function presentBatch(array $b): array
    {
        foreach (['total_messages', 'total_segments', 'n_queued', 'n_sent', 'n_delivered', 'n_failed', 'n_cancelled'] as $k) {
            $b[$k] = (int) $b[$k];
        }
        $b['n_sending'] = max(0, $b['total_messages'] - $b['n_queued'] - $b['n_sent'] - $b['n_failed'] - $b['n_cancelled']);
        return $b;
    }

    /** Annule un message encore en file (jamais un message déjà pris en charge par le worker). @return array<string,mixed> */
    public static function cancelMessage(int $id): array
    {
        $repo = new MessageRepository();
        $m = $repo->find($id) ?? throw ApiException::notFound('Message introuvable.');
        $n = Database::execute("UPDATE messages SET status = 'cancelled', error_code = 'CANCELLED', error_message = 'Annulé avant envoi.' WHERE id = ? AND organization_id = ? AND status = 'queued'", [$id, $m['organization_id']]);
        if ($n === 0) {
            throw ApiException::conflict('Ce message est déjà parti ou en cours d’envoi : il ne peut plus être annulé.', 'MESSAGE_NOT_CANCELLABLE');
        }
        BillingService::sweep((int) $m['organization_id']);
        return $repo->find($id) ?? $m;
    }

    /** @return array{cancelled:int} */
    public static function cancelBatch(int $id): array
    {
        $b = (new BatchRepository())->find($id) ?? throw ApiException::notFound('Envoi introuvable.');
        $n = Database::execute("UPDATE messages SET status = 'cancelled', error_code = 'CANCELLED', error_message = 'Annulé avant envoi.' WHERE batch_id = ? AND organization_id = ? AND status = 'queued'", [$id, $b['organization_id']]);
        if ($n === 0) {
            throw ApiException::conflict('Aucun message de cet envoi ne peut plus être annulé.', 'MESSAGE_NOT_CANCELLABLE');
        }
        BillingService::sweep((int) $b['organization_id']);
        return ['cancelled' => $n];
    }

    /** @return array<string,mixed> */
    public static function stats(): array
    {
        $repo = new MessageRepository();
        [$scope, $p] = $repo->scope();
        $by = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'cancelled' => 0];
        $since = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
        $segments = 0;
        foreach (Database::select("SELECT m.status, COUNT(*) AS n, SUM(m.segments) AS seg FROM messages m WHERE $scope AND m.created_at >= ? GROUP BY m.status", array_merge($p, [$since])) as $r) {
            $by[$r['status']] = (int) $r['n'];
            if (!in_array($r['status'], ['cancelled', 'queued'], true)) {
                $segments += (int) $r['seg'];
            }
        }
        $today = (int) Database::scalar("SELECT COUNT(*) FROM messages m WHERE $scope AND m.created_at >= ?", array_merge($p, [gmdate('Y-m-d 00:00:00', strtotime('today'))]));
        $final = $by['delivered'] + $by['sent'] + $by['failed'];
        return [
            'by_status' => $by, 'total_30d' => array_sum($by), 'today' => $today, 'segments_30d' => $segments,
            'delivery_rate' => $final > 0 ? round($by['delivered'] / $final * 100, 1) : null,
        ];
    }

    /** @return array<string,mixed> */
    private static function sender(int $orgId, mixed $id): array
    {
        $i = filter_var($id, FILTER_VALIDATE_INT);
        if ($i === false || $i < 1) {
            throw ApiException::validation(['sender_id_id' => ['Choisissez un Sender ID.']]);
        }
        $s = (new SenderIdRepository())->find($i);
        if ($s === null || (int) $s['organization_id'] !== $orgId) {
            throw ApiException::validation(['sender_id_id' => ['Sender ID introuvable.']]);
        }
        if ($s['status'] !== 'approved') {
            throw ApiException::conflict('Ce Sender ID n’est pas approuvé : il ne peut pas servir à envoyer.', 'SENDER_ID_NOT_APPROVED');
        }
        return $s;
    }

    private static function schedule(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $ts = is_string($raw) ? strtotime($raw) : false;
        if ($ts === false) {
            throw ApiException::validation(['scheduled_at' => ['Date de programmation invalide.']]);
        }
        $min = time() + (int) Config::get('messaging.min_schedule_minutes') * 60;
        if ($ts < $min) {
            throw ApiException::validation(['scheduled_at' => ['La programmation doit être dans le futur (au moins ' . Config::get('messaging.min_schedule_minutes') . ' minutes).']]);
        }
        if ($ts > time() + (int) Config::get('messaging.max_schedule_days') * 86400) {
            throw ApiException::validation(['scheduled_at' => ['Programmation limitée à ' . Config::get('messaging.max_schedule_days') . ' jours.']]);
        }
        return gmdate('c', $ts);
    }
}
