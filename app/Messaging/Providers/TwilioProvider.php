<?php
declare(strict_types=1);

namespace App\Messaging\Providers;

use App\Support\HttpClient;

/** Twilio — API Messages (https://www.twilio.com/docs/sms/api/message-resource). */
final class TwilioProvider implements ProviderInterface
{
    /** @param array<string,mixed> $cfg account_sid, auth_token, from_override, status_callback */
    public function __construct(private readonly array $cfg)
    {
    }

    public function name(): string
    {
        return 'twilio';
    }

    private function auth(): string
    {
        return (string) $this->cfg['account_sid'] . ':' . (string) $this->cfg['auth_token'];
    }

    public function send(array $message): array
    {
        $from = trim((string) ($this->cfg['from_override'] ?? '')) ?: $message['from'];
        $post = ['To' => $message['to'], 'Body' => $message['body']];
        $post[str_starts_with($from, 'MG') ? 'MessagingServiceSid' : 'From'] = $from;
        if (!empty($this->cfg['status_callback'])) {
            $post['StatusCallback'] = (string) $this->cfg['status_callback'];
        }
        $r = HttpClient::request('POST', 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode((string) $this->cfg['account_sid']) . '/Messages.json',
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($post), $this->auth());
        if ($r['error'] !== null || $r['status'] === 0) {
            return self::fail(null, 'PROVIDER_NETWORK', $r['error'] ?? 'Fournisseur injoignable.', true);
        }
        $json = json_decode($r['body'], true) ?: [];
        if ($r['status'] === 201 || $r['status'] === 200) {
            return ['status' => 'sent', 'provider_message_id' => (string) ($json['sid'] ?? ''), 'error_code' => null, 'error_message' => null, 'retryable' => false];
        }
        $code = (int) ($json['code'] ?? 0);
        $msg = mb_substr((string) ($json['message'] ?? 'Erreur Twilio'), 0, 200);
        return match (true) {
            $r['status'] === 401 || $r['status'] === 403 => self::fail(null, 'PROVIDER_AUTH', 'Identifiants Twilio refusés.', false),
            $r['status'] === 429 || $r['status'] >= 500 => self::fail(null, 'PROVIDER_BUSY', 'Twilio indisponible ou limite atteinte.', true),
            in_array($code, [21211, 21214, 21608, 21610, 21612, 21614], true) => self::fail(null, 'INVALID_NUMBER', $msg, false),
            in_array($code, [21212, 21606, 21611], true) => self::fail(null, 'PROVIDER_SENDER', $msg, false),
            default => self::fail(null, 'REJECTED', $msg, false),
        };
    }

    public function test(): array
    {
        $r = HttpClient::request('GET', 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode((string) $this->cfg['account_sid']) . '.json', ['Accept: application/json'], null, $this->auth());
        if ($r['error'] !== null) {
            return ['ok' => false, 'message' => $r['error']];
        }
        if ($r['status'] === 401) {
            return ['ok' => false, 'message' => 'Identifiants refusés (Account SID ou Auth Token).'];
        }
        $json = json_decode($r['body'], true);
        return $r['status'] === 200 && isset($json['sid'])
            ? ['ok' => true, 'message' => 'Connexion réussie. Compte : ' . (string) ($json['friendly_name'] ?? $json['sid']) . ' (' . (string) ($json['status'] ?? '?') . ')']
            : ['ok' => false, 'message' => 'Réponse inattendue (HTTP ' . $r['status'] . ').'];
    }

    /** @return array{status:string,provider_message_id:?string,error_code:?string,error_message:?string,retryable:bool} */
    private static function fail(?string $ref, string $code, string $msg, bool $retry): array
    {
        return ['status' => 'failed', 'provider_message_id' => $ref, 'error_code' => $code, 'error_message' => $msg, 'retryable' => $retry];
    }
}
