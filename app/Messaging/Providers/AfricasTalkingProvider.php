<?php
declare(strict_types=1);

namespace App\Messaging\Providers;

use App\Support\HttpClient;

/** Africa's Talking — API SMS v1 (https://developers.africastalking.com/docs/sms/sending). */
final class AfricasTalkingProvider implements ProviderInterface
{
    /** @param array<string,mixed> $cfg username, api_key, sandbox */
    public function __construct(private readonly array $cfg)
    {
    }

    public function name(): string
    {
        return 'africastalking';
    }

    private function host(): string
    {
        return !empty($this->cfg['sandbox']) ? 'api.sandbox.africastalking.com' : 'api.africastalking.com';
    }

    /** @return array<int,string> */
    private function headers(): array
    {
        return ['apiKey: ' . (string) $this->cfg['api_key'], 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
    }

    public function send(array $message): array
    {
        $post = ['username' => (string) $this->cfg['username'], 'to' => $message['to'], 'message' => $message['body']];
        if ($message['from'] !== '' && empty($this->cfg['sandbox'])) {
            $post['from'] = $message['from'];
        }
        $r = HttpClient::request('POST', 'https://' . $this->host() . '/version1/messaging', $this->headers(), http_build_query($post));
        if ($r['error'] !== null || $r['status'] === 0) {
            return self::fail(null, 'PROVIDER_NETWORK', $r['error'] ?? 'Fournisseur injoignable.', true);
        }
        if ($r['status'] === 401) {
            return self::fail(null, 'PROVIDER_AUTH', 'Identifiants Africa’s Talking refusés.', false);
        }
        if ($r['status'] >= 500) {
            return self::fail(null, 'PROVIDER_BUSY', 'Africa’s Talking indisponible (HTTP ' . $r['status'] . ').', true);
        }
        $json = json_decode($r['body'], true);
        $rec = $json['SMSMessageData']['Recipients'][0] ?? null;
        if (!is_array($rec)) {
            return self::fail(null, 'PROVIDER_ERROR', mb_substr((string) ($json['SMSMessageData']['Message'] ?? 'Réponse inattendue du fournisseur.'), 0, 200), true);
        }
        $code = (int) ($rec['statusCode'] ?? 0);
        $ref = isset($rec['messageId']) ? (string) $rec['messageId'] : null;
        if (in_array($code, [100, 101, 102], true)) {
            return ['status' => 'sent', 'provider_message_id' => $ref, 'error_code' => null, 'error_message' => null, 'retryable' => false];
        }
        // 401 RiskHold, 402 InvalidSenderId, 403 InvalidPhoneNumber, 404 UnsupportedNumberType, 405 InsufficientBalance,
        // 406 UserInBlacklist, 407 CouldNotRoute, 500 InternalServerError, 501 GatewayError, 502 RejectedByGateway
        $label = (string) ($rec['status'] ?? 'Échec');
        return match (true) {
            $code === 405 => self::fail($ref, 'PROVIDER_BALANCE', 'Solde insuffisant chez Africa’s Talking.', false),
            $code === 402 => self::fail($ref, 'PROVIDER_SENDER', 'Sender ID non enregistré chez Africa’s Talking.', false),
            in_array($code, [403, 404, 406], true) => self::fail($ref, 'INVALID_NUMBER', $label, false),
            in_array($code, [407, 500, 501, 502], true) => self::fail($ref, 'PROVIDER_BUSY', $label, true),
            default => self::fail($ref, 'REJECTED', $label, false),
        };
    }

    public function test(): array
    {
        $r = HttpClient::request('GET', 'https://' . $this->host() . '/version1/user?username=' . rawurlencode((string) $this->cfg['username']), $this->headers());
        if ($r['error'] !== null) {
            return ['ok' => false, 'message' => $r['error']];
        }
        if ($r['status'] === 401) {
            return ['ok' => false, 'message' => 'Identifiants refusés (vérifiez le username et la clé API).'];
        }
        $json = json_decode($r['body'], true);
        if ($r['status'] === 200 && isset($json['UserData'])) {
            return ['ok' => true, 'message' => 'Connexion réussie. Solde chez le fournisseur : ' . (string) ($json['UserData']['balance'] ?? '—')];
        }
        return ['ok' => false, 'message' => 'Réponse inattendue (HTTP ' . $r['status'] . ').'];
    }

    /** @return array{status:string,provider_message_id:?string,error_code:?string,error_message:?string,retryable:bool} */
    private static function fail(?string $ref, string $code, string $msg, bool $retry): array
    {
        return ['status' => 'failed', 'provider_message_id' => $ref, 'error_code' => $code, 'error_message' => $msg, 'retryable' => $retry];
    }
}
