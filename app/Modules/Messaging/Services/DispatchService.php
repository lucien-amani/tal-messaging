<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Config;
use App\Core\Database;
use App\Modules\Billing\Services\BillingService;
use App\Messaging\Providers\ProviderInterface;
use App\Modules\Providers\Services\ProviderRouter;
use App\Modules\Providers\Services\ProviderService;
use App\Messaging\Providers\SimulatorProvider;
use App\Support\Logger;

/**
 * Worker d'envoi (lancé par scripts/worker.php, hors requête web).
 * Prend les messages dus en file avec un verrou atomique, les remet au fournisseur, enregistre le résultat,
 * replanifie les échecs temporaires (3 tentatives) et récupère ceux restés bloqués.
 * Processus système : volontairement non limité à une organisation (il sert toutes les organisations).
 */
final class DispatchService
{
    public static function provider(): ProviderInterface
    {
        return ProviderRouter::chain(null)[0];
    }

    /** @return array{claimed:int,delivered:int,sent:int,failed:int,retried:int,cancelled:int,recovered:int,charged:int,released:int} */
    public static function run(?ProviderInterface $provider = null, ?int $limit = null): array
    {
        $cfg = (array) Config::get('messaging.dispatch');
        $limit ??= (int) $cfg['batch_size'];
        $out = ['claimed' => 0, 'delivered' => 0, 'sent' => 0, 'failed' => 0, 'retried' => 0, 'cancelled' => 0, 'recovered' => self::recoverStuck(), 'charged' => 0, 'released' => 0];

        $now = gmdate('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(8));
        $out['claimed'] = Database::execute(
            "UPDATE messages SET status = 'sending', lock_token = ?, locked_at = ?, attempts = attempts + 1
             WHERE status = 'queued' AND (scheduled_at IS NULL OR scheduled_at <= ?) AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
             ORDER BY id LIMIT " . max(1, $limit),
            [$token, $now, $now, $now]
        );
        if ($out['claimed'] === 0) {
            self::settle($out);
            return $out;
        }

        $rows = Database::select(
            "SELECT m.id, m.to_e164, m.sender_name, m.body, m.operator, m.country, m.attempts,
                    s.status AS sender_status, s.deleted_at AS sender_deleted, c.status AS contact_status
             FROM messages m
             LEFT JOIN sender_ids s ON s.id = m.sender_id_id
             LEFT JOIN contacts c ON c.id = m.contact_id AND c.deleted_at IS NULL
             WHERE m.lock_token = ? AND m.status = 'sending'",
            [$token]
        );
        foreach ($rows as $m) {
            $id = (int) $m['id'];
            // Les conditions ont pu changer depuis la mise en file : Sender ID suspendu, contact désinscrit depuis.
            if ($m['sender_status'] !== 'approved' || $m['sender_deleted'] !== null) {
                self::finish($id, 'failed', null, 'SENDER_ID_NOT_APPROVED', 'Le Sender ID n’est plus approuvé.', $provider ?? new SimulatorProvider());
                $out['failed']++;
                continue;
            }
            if ($m['contact_status'] === 'blocked') {
                self::finish($id, 'cancelled', null, 'CONTACT_BLOCKED', 'Le contact a été bloqué avant l’envoi.', $provider ?? new SimulatorProvider());
                $out['cancelled']++;
                continue;
            }
            $chain = $provider ? [$provider] : ProviderRouter::chain((string) $m['country']);
            $used = $chain[0];
            foreach ($chain as $try) {
                $used = $try;
                try {
                    $r = $try->send(['id' => $id, 'to' => (string) $m['to_e164'], 'from' => (string) $m['sender_name'], 'body' => (string) $m['body'],
                    'operator' => $m['operator'], 'country' => $m['country']]);
                } catch (\Throwable $e) {
                    Logger::error('Erreur fournisseur', ['message_id' => $id, 'error' => $e->getMessage()]);
                    $r = ['status' => 'failed', 'provider_message_id' => null, 'error_code' => 'PROVIDER_ERROR', 'error_message' => 'Erreur du fournisseur.', 'retryable' => true];
                }
                ProviderService::record($try->name(), $r['status'] !== 'failed');
                // Bascule sur le fournisseur suivant uniquement si la panne vient du fournisseur (pas d'un numéro invalide).
                if ($r['status'] !== 'failed' || !($r['retryable'] || str_starts_with((string) $r['error_code'], 'PROVIDER_'))) {
                    break;
                }
            }
            if ($r['status'] === 'failed' && $r['retryable'] && (int) $m['attempts'] < (int) $cfg['max_attempts']) {
                $delays = (array) $cfg['retry_delays'];
                $delay = (int) ($delays[min((int) $m['attempts'] - 1, count($delays) - 1)] ?? 60);
                Database::execute(
                    "UPDATE messages SET status = 'queued', lock_token = NULL, locked_at = NULL, next_attempt_at = ?, error_code = ?, error_message = ?, provider = ? WHERE id = ? AND status = 'sending'",
                    [gmdate('Y-m-d H:i:s', time() + $delay), $r['error_code'], $r['error_message'], $used->name(), $id]
                );
                $out['retried']++;
                continue;
            }
            self::finish($id, $r['status'], $r['provider_message_id'], $r['error_code'], $r['error_message'], $used);
            $out[$r['status'] === 'failed' ? 'failed' : $r['status']]++;
        }
        self::settle($out);
        return $out;
    }

    /** Règle le wallet pour tous les messages arrivés à un statut final (débit ou libération de la réservation). */
    private static function settle(array &$out): void
    {
        $b = BillingService::sweep();
        $out['charged'] = $b['charged'];
        $out['released'] = $b['released'];
    }

    private static function finish(int $id, string $status, ?string $providerId, ?string $code, ?string $message, ProviderInterface $provider): void
    {
        $now = gmdate('Y-m-d H:i:s');
        Database::execute(
            "UPDATE messages SET status = ?, provider = ?, provider_message_id = ?, error_code = ?, error_message = ?,
                    sent_at = IF(? IN ('sent','delivered'), ?, sent_at), delivered_at = IF(? = 'delivered', ?, NULL), lock_token = NULL, locked_at = NULL, next_attempt_at = NULL
             WHERE id = ? AND status = 'sending'",
            [$status, $provider->name(), $providerId, $code, $message, $status, $now, $status, $now, $id]
        );
    }

    /** Remet en file (ou échoue) les messages restés « sending » après un arrêt brutal du worker. */
    private static function recoverStuck(): int
    {
        $cfg = (array) Config::get('messaging.dispatch');
        $limit = gmdate('Y-m-d H:i:s', time() - (int) $cfg['stuck_after']);
        Database::execute(
            "UPDATE messages SET status = 'failed', error_code = 'TIMEOUT', error_message = 'Envoi interrompu.', lock_token = NULL, locked_at = NULL
             WHERE status = 'sending' AND locked_at < ? AND attempts >= ?",
            [$limit, (int) $cfg['max_attempts']]
        );
        return Database::execute(
            "UPDATE messages SET status = 'queued', lock_token = NULL, locked_at = NULL WHERE status = 'sending' AND locked_at < ?",
            [$limit]
        );
    }
}
