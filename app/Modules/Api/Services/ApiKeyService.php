<?php
declare(strict_types=1);

namespace App\Modules\Api\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;

/** Clés API des clients : génération, authentification par empreinte, limitation de débit par clé. */
final class ApiKeyService
{
    public const SCOPES = ['send' => 'Envoyer des SMS', 'read' => 'Lire messages et solde'];

    /** @return array{key:string,row:array<string,mixed>} la clé en clair n'existe que dans cette réponse */
    public static function create(int $orgId, string $name, array $scopes, int $actorId): array
    {
        $name = trim($name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            throw ApiException::validation(['name' => ['Le nom doit contenir entre 3 et 80 caractères.']]);
        }
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), array_map('strval', $scopes)));
        if (!$scopes) {
            throw ApiException::validation(['scopes' => ['Choisissez au moins un droit.']]);
        }
        $active = (int) (Database::first('SELECT COUNT(*) c FROM api_keys WHERE organization_id = ? AND revoked_at IS NULL', [$orgId])['c'] ?? 0);
        if ($active >= 20) {
            throw new ApiException('API_KEY_LIMIT', 'Limite de 20 clés actives atteinte : révoquez une clé inutilisée.', 422);
        }
        $key = 'tal_live_' . bin2hex(random_bytes(20));
        Database::execute('INSERT INTO api_keys (organization_id, name, key_prefix, key_hash, scopes, created_by) VALUES (?,?,?,?,?,?)',
            [$orgId, $name, substr($key, 0, 13), hash('sha256', $key), implode(',', $scopes), $actorId]);
        $row = Database::first('SELECT * FROM api_keys WHERE key_hash = ?', [hash('sha256', $key)]);
        return ['key' => $key, 'row' => self::present($row)];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'organization_id' => (int) $r['organization_id'], 'organization_name' => $r['organization_name'] ?? null, 'name' => $r['name'],
            'prefix' => $r['key_prefix'] . '…', 'scopes' => explode(',', (string) $r['scopes']), 'created_at' => $r['created_at'],
            'last_used_at' => $r['last_used_at'], 'revoked_at' => $r['revoked_at'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function list(?int $orgId): array
    {
        $rows = Database::select(
            'SELECT k.*, o.name AS organization_name FROM api_keys k JOIN organizations o ON o.id = k.organization_id' . ($orgId ? ' WHERE k.organization_id = ?' : '') . ' ORDER BY k.revoked_at IS NOT NULL, k.id DESC LIMIT 200',
            $orgId ? [$orgId] : []
        );
        return array_map([self::class, 'present'], $rows);
    }

    public static function revoke(int $id, ?int $orgId): void
    {
        $n = Database::execute('UPDATE api_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL' . ($orgId ? ' AND organization_id = ?' : ''),
            array_merge([gmdate('Y-m-d H:i:s'), $id], $orgId ? [$orgId] : []));
        if ($n === 0) {
            throw ApiException::notFound('Clé introuvable ou déjà révoquée.');
        }
    }

    /** Résout une clé Bearer. Échec fermé : clé inconnue, révoquée ou organisation non active → 401/403. @return array<string,mixed> */
    public static function authenticate(?string $header, string $ip): array
    {
        if ($header === null || !preg_match('/^Bearer\s+(tal_live_[a-f0-9]{40})$/i', trim($header), $m)) {
            throw ApiException::unauthorized('Clé API manquante ou invalide. Envoyez « Authorization: Bearer tal_live_… ».', 'INVALID_API_KEY');
        }
        $k = Database::first('SELECT k.*, o.status AS org_status, o.deleted_at AS org_deleted FROM api_keys k JOIN organizations o ON o.id = k.organization_id WHERE k.key_hash = ?', [hash('sha256', $m[1])]);
        if (!$k || $k['revoked_at'] !== null) {
            throw ApiException::unauthorized('Clé API invalide ou révoquée.', 'INVALID_API_KEY');
        }
        if ($k['org_status'] !== 'active' || $k['org_deleted'] !== null) {
            throw ApiException::forbidden('Le compte de cette organisation n’est pas actif.', 'ORGANIZATION_INACTIVE');
        }
        // Mise à jour de l'usage au plus une fois par minute (évite une écriture par appel).
        Database::execute('UPDATE api_keys SET last_used_at = ?, last_used_ip = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
            [gmdate('Y-m-d H:i:s'), substr($ip, 0, 45), $k['id'], gmdate('Y-m-d H:i:s', time() - 60)]);
        return $k;
    }

    /** Fenêtre d'une minute par clé. @return array{limit:int,remaining:int} */
    public static function throttle(int $keyId): array
    {
        $limit = (int) Config::get('messaging.api_rate_per_minute', 60);
        $w = intdiv(time(), 60) * 60;
        Database::execute('INSERT INTO api_rate_limits (key_id, window_start, hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$keyId, $w]);
        $hits = (int) (Database::first('SELECT hits FROM api_rate_limits WHERE key_id = ? AND window_start = ?', [$keyId, $w])['hits'] ?? 1);
        if (random_int(1, 50) === 1) {
            Database::execute('DELETE FROM api_rate_limits WHERE window_start < ?', [$w - 3600]);
        }
        if ($hits > $limit) {
            throw new ApiException('RATE_LIMITED', "Limite de $limit requêtes par minute atteinte. Réessayez dans quelques secondes.", 429);
        }
        return ['limit' => $limit, 'remaining' => $limit - $hits];
    }
}
