<?php
declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Tenancy\TenantContext;

/**
 * Wallet : réservation, débit et libération des crédits.
 *
 * Principe : à la mise en file, le coût de chaque message est RÉSERVÉ (held) ; quand le fournisseur l'accepte il est DÉBITÉ ;
 * s'il échoue ou est annulé il est LIBÉRÉ. Les messages sont la source de vérité : sweep() règle tout message dont le statut
 * est final et dont la réservation est encore ouverte, ce qui rend le système auto-réparant (arrêt brutal du worker, annulation…).
 * Toute écriture sur le solde se fait sous verrou de la ligne du wallet.
 */
final class BillingService
{
    /** Organisation visée par une lecture : forcée pour un client, libre (ou toutes) pour un interne global. */
    public static function scopeOrg(?int $requested): ?int
    {
        $ctx = TenantContext::current();
        return $ctx->global ? ($requested ?: null) : (int) $ctx->organizationId;
    }

    /** @return array<string,mixed> */
    public static function wallet(int $orgId): array
    {
        Database::execute('INSERT IGNORE INTO wallets (organization_id, currency) VALUES (?, ?)', [$orgId, (string) Config::get('billing.default_currency')]);
        $w = Database::first('SELECT * FROM wallets WHERE organization_id = ?', [$orgId]) ?? throw ApiException::notFound('Wallet introuvable.');
        return self::present($w);
    }

    /** À appeler À L'INTÉRIEUR d'une transaction : verrouille la ligne du wallet. @return array<string,mixed> */
    public static function lockWallet(int $orgId): array
    {
        Database::execute('INSERT IGNORE INTO wallets (organization_id, currency) VALUES (?, ?)', [$orgId, (string) Config::get('billing.default_currency')]);
        return self::present(Database::first('SELECT * FROM wallets WHERE organization_id = ? FOR UPDATE', [$orgId]) ?? throw ApiException::notFound('Wallet introuvable.'));
    }

    /** @param array<string,mixed> $w @return array<string,mixed> */
    public static function present(array $w): array
    {
        $w['balance_micro'] = (int) $w['balance_micro'];
        $w['held_micro'] = (int) $w['held_micro'];
        $w['low_balance_micro'] = (int) $w['low_balance_micro'];
        $w['available_micro'] = $w['balance_micro'] - $w['held_micro'];
        $w['low'] = $w['low_balance_micro'] > 0 && $w['available_micro'] <= $w['low_balance_micro'];
        return $w;
    }

    /** @return array<string,mixed> */
    public static function summary(int $orgId): array
    {
        $w = self::wallet($orgId);
        $since = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
        $w['spent_30d_micro'] = (int) Database::scalar("SELECT COALESCE(SUM(cost_micro), 0) FROM messages WHERE organization_id = ? AND billing_status = 'charged' AND created_at >= ?", [$orgId, $since]);
        $w['topups_30d_micro'] = (int) Database::scalar("SELECT COALESCE(SUM(amount_micro), 0) FROM wallet_transactions WHERE organization_id = ? AND type = 'topup' AND created_at >= ?", [$orgId, $since]);
        return $w;
    }

    /**
     * Crédite (ou débite, pour un ajustement négatif) le wallet et écrit la ligne de journal. À APPELER DANS UNE TRANSACTION.
     * @return array<string,mixed> wallet après opération
     */
    public static function applyInTx(int $orgId, int $amountMicro, string $type, ?string $refType, ?int $refId, ?string $description, ?int $actorId, int $messages = 0): array
    {
        $w = self::lockWallet($orgId);
        if ($amountMicro < 0 && $w['available_micro'] + $amountMicro < 0) {
            throw new ApiException('INSUFFICIENT_BALANCE', 'Solde disponible insuffisant pour ce débit (une partie est réservée pour des messages en cours).', 422);
        }
        Database::execute('UPDATE wallets SET balance_micro = balance_micro + ? WHERE id = ?', [$amountMicro, $w['id']]);
        $after = $w['balance_micro'] + $amountMicro;
        Database::execute(
            'INSERT INTO wallet_transactions (uuid, organization_id, wallet_id, type, amount_micro, balance_after_micro, currency, reference_type, reference_id, message_count, description, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [uuid4(), $orgId, $w['id'], $type, $amountMicro, $after, $w['currency'], $refType, $refId, $messages, $description !== null ? mb_substr($description, 0, 255) : null, $actorId]
        );
        $w['balance_micro'] = $after;
        $w['available_micro'] = $after - $w['held_micro'];
        return $w;
    }

    /** Ajustement manuel (CEO) : positif = crédit offert, négatif = correction. @return array<string,mixed> */
    public static function adjust(int $orgId, int $amountMicro, string $note, int $actorId): array
    {
        if ($amountMicro === 0) {
            throw ApiException::validation(['amount' => ['Le montant ne peut pas être nul.']]);
        }
        return Database::transaction(static fn(): array => self::applyInTx($orgId, $amountMicro, 'adjustment', 'manual', null, $note, $actorId));
    }

    /**
     * Règle les réservations des messages arrivés à un statut final (débit si accepté, libération si échec ou annulation).
     * @return array{charged:int,released:int}
     */
    public static function sweep(?int $onlyOrg = null): array
    {
        $out = ['charged' => 0, 'released' => 0];
        $orgs = Database::select(
            "SELECT DISTINCT organization_id FROM messages WHERE billing_status = 'held' AND status IN ('sent','delivered','failed','cancelled')" . ($onlyOrg ? ' AND organization_id = ?' : '') . ' LIMIT 200',
            $onlyOrg ? [$onlyOrg] : []
        );
        $limit = max(100, (int) Config::get('billing.sweep_batch'));
        foreach ($orgs as $o) {
            $orgId = (int) $o['organization_id'];
            do {
                $n = Database::transaction(static function () use ($orgId, $limit, &$out): int {
                    $w = self::lockWallet($orgId);
                    $rows = Database::select(
                        "SELECT id, batch_id, cost_micro, status FROM messages WHERE organization_id = ? AND billing_status = 'held' AND status IN ('sent','delivered','failed','cancelled') ORDER BY id LIMIT $limit FOR UPDATE",
                        [$orgId]
                    );
                    if (!$rows) {
                        return 0;
                    }
                    $charge = [];
                    $release = [];
                    $perBatch = [];
                    $charged = 0;
                    $released = 0;
                    foreach ($rows as $r) {
                        $cost = (int) $r['cost_micro'];
                        if (in_array($r['status'], ['sent', 'delivered'], true)) {
                            $charge[] = (int) $r['id'];
                            $charged += $cost;
                            $b = &$perBatch[(int) $r['batch_id']];
                            $b ??= ['amount' => 0, 'count' => 0];
                            $b['amount'] += $cost;
                            $b['count']++;
                            unset($b);
                        } else {
                            $release[] = (int) $r['id'];
                            $released += $cost;
                        }
                    }
                    foreach ([['charged', $charge], ['released', $release]] as [$to, $ids]) {
                        foreach (array_chunk($ids, 500) as $part) {
                            Database::execute("UPDATE messages SET billing_status = '$to' WHERE billing_status = 'held' AND id IN (" . implode(',', array_fill(0, count($part), '?')) . ')', $part);
                        }
                    }
                    Database::execute('UPDATE wallets SET balance_micro = balance_micro - ?, held_micro = GREATEST(0, held_micro - ?) WHERE id = ?', [$charged, $charged + $released, $w['id']]);
                    $balance = $w['balance_micro'] - $charged;
                    foreach ($perBatch as $batchId => $b) {
                        Database::execute(
                            'INSERT INTO wallet_transactions (uuid, organization_id, wallet_id, type, amount_micro, balance_after_micro, currency, reference_type, reference_id, message_count, description)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                            [uuid4(), $orgId, $w['id'], 'charge', -$b['amount'], $balance, $w['currency'], 'batch', $batchId ?: null, $b['count'], 'Messages envoyés']
                        );
                    }
                    $out['charged'] += count($charge);
                    $out['released'] += count($release);
                    return count($rows);
                });
            } while ($n >= $limit);
        }
        return $out;
    }
}
