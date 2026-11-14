<?php
declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Support\Money;

/**
 * Demandes de recharge : le client déclare son paiement (Mobile Money, virement…) avec sa référence ;
 * l'équipe TAL le confirme après vérification, ce qui crédite le wallet et émet la facture, le tout dans une transaction.
 */
final class PaymentService
{
    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function request(int $orgId, array $in, int $actorId): array
    {
        $amount = Money::parse($in['amount'] ?? null);
        if ($amount === null || $amount < (int) Config::get('billing.min_topup_micro') || $amount > (int) Config::get('billing.max_topup_micro')) {
            throw ApiException::validation(['amount' => ['Montant invalide (minimum ' . Money::format((int) Config::get('billing.min_topup_micro'), '') . ').']]);
        }
        $methods = (array) Config::get('billing.methods');
        $method = (string) ($in['method'] ?? 'mobile_money');
        if (!isset($methods[$method])) {
            throw ApiException::validation(['method' => ['Mode de paiement inconnu.']]);
        }
        $reference = trim((string) ($in['reference'] ?? ''));
        if (mb_strlen($reference) < 4) {
            throw ApiException::validation(['reference' => ['Indiquez la référence de la transaction (4 caractères minimum).']]);
        }
        $pending = (int) Database::scalar("SELECT COUNT(*) FROM payments WHERE organization_id = ? AND status = 'pending'", [$orgId]);
        if ($pending >= (int) Config::get('billing.max_pending_payments')) {
            throw new ApiException('PAYMENT_PENDING_LIMIT', 'Vous avez déjà plusieurs demandes en attente de confirmation.', 422);
        }
        if (Database::first("SELECT id FROM payments WHERE organization_id = ? AND method = ? AND reference = ? AND status IN ('pending','confirmed')", [$orgId, $method, $reference])) {
            throw ApiException::conflict('Cette référence a déjà été déclarée.', 'PAYMENT_DUPLICATE');
        }
        $wallet = BillingService::wallet($orgId);
        Database::execute(
            'INSERT INTO payments (uuid, organization_id, amount_micro, currency, method, reference, note, created_by) VALUES (?,?,?,?,?,?,?,?)',
            [uuid4(), $orgId, $amount, $wallet['currency'], $method, mb_substr($reference, 0, 80), isset($in['note']) ? mb_substr(trim((string) $in['note']), 0, 255) : null, $actorId]
        );
        return self::one(Database::lastInsertId());
    }

    /** Le client retire sa propre demande tant qu'elle n'est pas traitée. @return array<string,mixed> */
    public static function cancel(int $id): array
    {
        $p = self::one($id);
        if (Database::execute("UPDATE payments SET status = 'cancelled' WHERE id = ? AND organization_id = ? AND status = 'pending'", [$id, $p['organization_id']]) === 0) {
            throw ApiException::conflict('Cette demande a déjà été traitée.', 'PAYMENT_NOT_PENDING');
        }
        return self::one($id);
    }

    /** @return array<string,mixed> */
    public static function confirm(int $id, int $actorId): array
    {
        $p = self::one($id);
        try {
            Database::transaction(static function () use ($id, $p, $actorId): void {
                $row = Database::first('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$id]);
                if (!$row || $row['status'] !== 'pending') {
                    throw ApiException::conflict('Cette demande a déjà été traitée.', 'PAYMENT_NOT_PENDING');
                }
                $org = Database::first('SELECT name FROM organizations WHERE id = ?', [$row['organization_id']]);
                $amount = (int) $row['amount_micro'];
                Database::execute(
                    'INSERT INTO invoices (uuid, organization_id, payment_id, customer_name, description, amount_micro, currency) VALUES (?,?,?,?,?,?,?)',
                    [uuid4(), $row['organization_id'], $id, $org['name'] ?? '—', 'Recharge de crédits de messagerie — réf. ' . $row['reference'], $amount, $row['currency']]
                );
                $invoiceId = Database::lastInsertId();
                Database::execute('UPDATE invoices SET number = ? WHERE id = ?', [sprintf('INV-%s-%06d', gmdate('Y'), $invoiceId), $invoiceId]);
                BillingService::applyInTx((int) $row['organization_id'], $amount, 'topup', 'payment', $id, 'Recharge confirmée (' . $row['reference'] . ')', $actorId);
                Database::execute("UPDATE payments SET status = 'confirmed', reviewed_by = ?, reviewed_at = ?, invoice_id = ? WHERE id = ?", [$actorId, gmdate('Y-m-d H:i:s'), $invoiceId, $id]);
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') { // même référence déjà créditée (index unique sur les paiements confirmés)
                throw ApiException::conflict('Cette référence de transaction a déjà été créditée : confirmer à nouveau créerait un doublon.', 'PAYMENT_REFERENCE_USED');
            }
            throw $e;
        }
        return self::one($id);
    }

    /** @return array<string,mixed> */
    public static function reject(int $id, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ApiException::validation(['reason' => ['Indiquez le motif du rejet (5 caractères minimum).']]);
        }
        $p = self::one($id);
        if (Database::execute("UPDATE payments SET status = 'rejected', reject_reason = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ? AND status = 'pending'",
            [mb_substr($reason, 0, 255), $actorId, gmdate('Y-m-d H:i:s'), $id]) === 0) {
            throw ApiException::conflict('Cette demande a déjà été traitée.', 'PAYMENT_NOT_PENDING');
        }
        return self::one($id) + ['organization_id' => $p['organization_id']];
    }

    /** @return array<string,mixed> */
    public static function one(int $id): array
    {
        $row = Database::first(
            'SELECT p.*, o.name AS organization_name, i.number AS invoice_number FROM payments p JOIN organizations o ON o.id = p.organization_id LEFT JOIN invoices i ON i.id = p.invoice_id WHERE p.id = ?',
            [$id]
        );
        // Un client ne voit que ses demandes ; hors périmètre = introuvable (jamais « interdit », pour ne rien révéler)
        $scope = BillingService::scopeOrg(null);
        if (!$row || ($scope !== null && (int) $row['organization_id'] !== $scope)) {
            throw ApiException::notFound('Demande introuvable.');
        }
        $row['amount_micro'] = (int) $row['amount_micro'];
        return $row;
    }
}
