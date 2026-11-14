<?php
declare(strict_types=1);

namespace App\Messaging\Providers;

/**
 * Fournisseur de test : n'envoie aucun SMS réel. Le comportement dépend des deux derniers chiffres du numéro :
 *   …99 échec définitif (numéro injoignable) · …98 échec temporaire (nouvelle tentative) · …97 accepté sans accusé de réception
 *   tout autre numéro : livré.
 */
final class SimulatorProvider implements ProviderInterface
{
    public function name(): string
    {
        return 'simulator';
    }

    public function test(): array
    {
        return ['ok' => true, 'message' => 'Simulateur : toujours disponible.'];
    }

    public function send(array $message): array
    {
        $tail = substr($message['to'], -2);
        $ref = 'sim_' . bin2hex(random_bytes(6));
        return match ($tail) {
            '99' => ['status' => 'failed', 'provider_message_id' => $ref, 'error_code' => 'UNREACHABLE', 'error_message' => 'Numéro injoignable (simulation).', 'retryable' => false],
            '98' => ['status' => 'failed', 'provider_message_id' => null, 'error_code' => 'PROVIDER_BUSY', 'error_message' => 'Fournisseur indisponible (simulation).', 'retryable' => true],
            '97' => ['status' => 'sent', 'provider_message_id' => $ref, 'error_code' => null, 'error_message' => null, 'retryable' => false],
            default => ['status' => 'delivered', 'provider_message_id' => $ref, 'error_code' => null, 'error_message' => null, 'retryable' => false],
        };
    }
}
