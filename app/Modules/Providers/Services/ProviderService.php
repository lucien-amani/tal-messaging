<?php
declare(strict_types=1);

namespace App\Modules\Providers\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Messaging\Providers\AfricasTalkingProvider;
use App\Messaging\Providers\ProviderInterface;
use App\Messaging\Providers\TwilioProvider;
use App\Support\Crypto;

/**
 * Configuration des fournisseurs depuis le panneau CEO/CTO. Les secrets sont chiffrés en base et ne ressortent jamais :
 * l'interface reçoit seulement « has_value ». Un champ secret laissé vide à l'enregistrement conserve l'ancienne valeur.
 */
final class ProviderService
{
    /** @return array<string,mixed> */
    private static function def(string $code): array
    {
        return (array) (Config::get('providers.catalog')[$code] ?? throw ApiException::notFound('Fournisseur inconnu.'));
    }

    /** @return array<string,mixed> */
    private static function row(string $code): array
    {
        self::def($code);
        return Database::first('SELECT * FROM providers WHERE code = ?', [$code]) ?? throw ApiException::notFound('Fournisseur non initialisé : lancez php database/migrate.php.');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> valeurs déchiffrées (usage interne uniquement) */
    public static function values(array $row): array
    {
        $settings = json_decode((string) $row['settings'], true) ?: [];
        $secrets = $row['credentials'] ? (json_decode((string) Crypto::decrypt((string) $row['credentials']), true) ?: []) : [];
        return $settings + $secrets;
    }

    /** @param array<string,mixed> $row */
    public static function isConfigured(array $row): bool
    {
        $vals = self::values($row);
        foreach (self::def((string) $row['code'])['fields'] as $f) {
            if (!empty($f['required']) && trim((string) ($vals[$f['key']] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $row */
    public static function instance(array $row): ProviderInterface
    {
        $v = self::values($row);
        return match ($row['code']) {
            'africastalking' => new AfricasTalkingProvider($v),
            'twilio' => new TwilioProvider($v + ['status_callback' => self::callbackUrl($row, true)]),
            default => throw new \RuntimeException('Fournisseur sans adaptateur : ' . $row['code']),
        };
    }

    /** URL de réception des accusés de réception. $publicOnly : null si l'application n'est pas joignable depuis Internet. */
    public static function callbackUrl(array $row, bool $publicOnly = false): ?string
    {
        $base = rtrim((string) base_url(), '/');
        if ($publicOnly && (!str_starts_with($base, 'https://') || preg_match('#^https://(localhost|127\.|192\.168\.|10\.)#', $base))) {
            return null;
        }
        return $base . '/webhooks/providers/' . $row['code'] . '/' . $row['webhook_secret'];
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        $out = [];
        foreach (Database::select('SELECT * FROM providers ORDER BY priority, id') as $row) {
            if (!isset(Config::get('providers.catalog')[$row['code']])) {
                continue;
            }
            $out[] = self::present($row);
        }
        return $out;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function present(array $row): array
    {
        $def = self::def((string) $row['code']);
        $vals = self::values($row);
        $fields = [];
        foreach ($def['fields'] as $f) {
            $v = $vals[$f['key']] ?? null;
            $fields[] = $f + ['value' => $f['secret'] ? null : ($v ?? (($f['type'] ?? '') === 'bool' ? false : '')), 'has_value' => trim((string) $v) !== ''];
        }
        $configured = self::isConfigured($row);
        return [
            'code' => $row['code'], 'label' => $def['label'], 'description' => $def['description'], 'docs' => $def['docs'], 'dlr' => $def['dlr'], 'fields' => $fields,
            'enabled' => (bool) $row['enabled'], 'configured' => $configured, 'priority' => (int) $row['priority'],
            'countries' => $row['countries'] ? explode(',', (string) $row['countries']) : [], 'webhook_url' => self::callbackUrl($row),
            'last_test_at' => $row['last_test_at'], 'last_test_ok' => $row['last_test_ok'] === null ? null : (bool) $row['last_test_ok'], 'last_test_message' => $row['last_test_message'],
            'sent_count' => (int) $row['sent_count'], 'failed_count' => (int) $row['failed_count'], 'last_success_at' => $row['last_success_at'], 'last_failure_at' => $row['last_failure_at'],
            'state' => !$configured ? 'unconfigured' : ($row['enabled'] ? 'active' : 'ready'),
        ];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function save(string $code, array $in): array
    {
        $row = self::row($code);
        $def = self::def($code);
        $vals = self::values($row);
        $secrets = [];
        $settings = [];
        $changed = false;
        foreach ($def['fields'] as $f) {
            $k = $f['key'];
            $new = $in['fields'][$k] ?? null;
            if (($f['type'] ?? '') === 'bool') {
                $v = (bool) $new;
            } elseif ($f['secret'] && ($new === null || trim((string) $new) === '')) {
                $v = $vals[$k] ?? '';            // champ secret vide : on conserve
            } else {
                $v = trim((string) $new);
                if (mb_strlen($v) > 300) {
                    throw ApiException::validation(['fields' => ["Valeur trop longue pour « {$f['label']} »."]]);
                }
            }
            $old = $vals[$k] ?? ((($f['type'] ?? '') === 'bool') ? false : '');
            if ($v !== $old) {
                $changed = true;
            }
            if ($f['secret']) {
                $secrets[$k] = $v;
            } else {
                $settings[$k] = $v;
            }
        }
        $priority = max(1, min(1000, (int) ($in['priority'] ?? $row['priority'])));
        $countries = [];
        foreach ((array) ($in['countries'] ?? []) as $c) {
            $c = strtoupper((string) $c);
            if (isset(Config::get('countries.countries')[$c])) {
                $countries[$c] = $c;
            }
        }
        $enabled = (bool) ($in['enabled'] ?? $row['enabled']);
        $tmp = ['code' => $code, 'settings' => json_encode($settings), 'credentials' => $secrets ? Crypto::encrypt((string) json_encode($secrets)) : null];
        if ($enabled && !self::isConfigured($tmp)) {
            throw new ApiException('PROVIDER_NOT_CONFIGURED', 'Renseignez tous les champs obligatoires avant d’activer ce fournisseur.', 422);
        }
        Database::execute(
            'UPDATE providers SET enabled = ?, priority = ?, countries = ?, settings = ?, credentials = ?' . ($changed ? ', last_test_at = NULL, last_test_ok = NULL, last_test_message = NULL' : '') . ' WHERE code = ?',
            [(int) $enabled, $priority, $countries ? implode(',', $countries) : null, $tmp['settings'], $tmp['credentials'], $code]
        );
        ProviderRouter::flush();
        return self::present(self::row($code));
    }

    /** @return array<string,mixed> */
    public static function test(string $code): array
    {
        $row = self::row($code);
        if (!self::isConfigured($row)) {
            throw new ApiException('PROVIDER_NOT_CONFIGURED', 'Enregistrez d’abord les identifiants.', 422);
        }
        try {
            $r = self::instance($row)->test();
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'message' => 'Erreur : ' . mb_substr($e->getMessage(), 0, 150)];
        }
        Database::execute('UPDATE providers SET last_test_at = ?, last_test_ok = ?, last_test_message = ? WHERE code = ?', [gmdate('Y-m-d H:i:s'), (int) $r['ok'], mb_substr($r['message'], 0, 255), $code]);
        return self::present(self::row($code));
    }

    /** @return array<string,mixed> */
    public static function rotateWebhook(string $code): array
    {
        self::row($code);
        Database::execute('UPDATE providers SET webhook_secret = ? WHERE code = ?', [bin2hex(random_bytes(20)), $code]);
        return self::present(self::row($code));
    }

    /** Compteurs de santé (ignorés pour le simulateur). */
    public static function record(string $code, bool $ok): void
    {
        if ($code === 'simulator') {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        Database::execute($ok ? 'UPDATE providers SET sent_count = sent_count + 1, last_success_at = ? WHERE code = ?' : 'UPDATE providers SET failed_count = failed_count + 1, last_failure_at = ? WHERE code = ?', [$now, $code]);
    }

    /** @return array<string,mixed> */
    public static function rowByCode(string $code): ?array
    {
        return Database::first('SELECT * FROM providers WHERE code = ?', [$code]);
    }
}
