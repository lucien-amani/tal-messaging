<?php
declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Core\Database;
use App\Modules\Billing\Services\BillingService;

/** Accusés de réception (DLR) envoyés par les fournisseurs. L'URL contient un secret propre à chaque fournisseur. */
final class WebhookService
{
    /** @param array<string,mixed> $in @return int nombre de messages mis à jour */
    public static function handle(string $code, string $secret, array $in): int
    {
        $row = ProviderService::rowByCode($code);
        if (!$row || !hash_equals((string) $row['webhook_secret'], $secret)) {
            return -1;
        }
        [$ref, $status, $reason] = match ($code) {
            'africastalking' => [(string) ($in['id'] ?? ''), strtolower((string) ($in['status'] ?? '')), (string) ($in['failureReason'] ?? '')],
            'twilio' => [(string) ($in['MessageSid'] ?? ''), strtolower((string) ($in['MessageStatus'] ?? '')), (string) ($in['ErrorCode'] ?? '')],
            default => ['', '', ''],
        };
        if ($ref === '') {
            return 0;
        }
        $now = gmdate('Y-m-d H:i:s');
        $n = 0;
        if (in_array($status, ['success', 'delivered'], true)) {
            $n = Database::execute("UPDATE messages SET status = 'delivered', delivered_at = ?, error_code = NULL, error_message = NULL WHERE provider = ? AND provider_message_id = ? AND status IN ('sent', 'failed')", [$now, $code, $ref]);
        } elseif (in_array($status, ['failed', 'rejected', 'undelivered', 'expired'], true)) {
            $n = Database::execute("UPDATE messages SET status = 'failed', error_code = 'UNDELIVERED', error_message = ? WHERE provider = ? AND provider_message_id = ? AND status = 'sent'",
                [mb_substr($reason !== '' ? 'Non livré : ' . $reason : 'Non livré (accusé de réception négatif).', 0, 255), $code, $ref]);
        }
        if ($n > 0) {
            BillingService::sweep();
        }
        return $n;
    }
}
