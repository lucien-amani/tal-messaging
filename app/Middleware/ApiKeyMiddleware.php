<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Api\Services\ApiKeyService;
use App\Tenancy\TenantContext;

/** Authentification par clé API (sans session) : fixe le tenant de la clé et ses droits. Alias : apikey:send | apikey:read. */
final class ApiKeyMiddleware implements MiddlewareInterface
{
    private const PERMS = ['send' => ['send_messages', 'view_messages', 'view_wallet'], 'read' => ['view_messages', 'view_wallet']];

    /** @param array<int,string> $required */
    public function __construct(private readonly array $required = [])
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $key = ApiKeyService::authenticate($request->header('authorization'), $request->ip);
        $scopes = explode(',', (string) $key['scopes']);
        foreach ($this->required as $need) {
            if (!in_array($need, $scopes, true)) {
                throw ApiException::forbidden("Cette clé n’a pas le droit « $need ».", 'INSUFFICIENT_SCOPE');
            }
        }
        $rate = ApiKeyService::throttle((int) $key['id']);
        $perms = [];
        foreach ($scopes as $s) {
            $perms = array_merge($perms, self::PERMS[$s] ?? []);
        }
        $request->attributes['user'] = ['id' => (int) $key['created_by'], 'organization_id' => (int) $key['organization_id'], 'permissions' => array_values(array_unique($perms)), 'api_key_id' => (int) $key['id']];
        $request->attributes['audit_org'] = (int) $key['organization_id'];
        TenantContext::forOrganization((int) $key['organization_id']);
        try {
            $response = $next($request);
        } finally {
            TenantContext::reset();
        }
        $response->headers['X-RateLimit-Limit'] = (string) $rate['limit'];
        $response->headers['X-RateLimit-Remaining'] = (string) $rate['remaining'];
        return $response;
    }
}
