<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Api\Controllers\ApiKeyController as K;
use App\Modules\Api\Controllers\ApiV1Controller as V1;

return static function (Router $r): void {
    // API publique : clé Bearer, aucune session ni CSRF (routes sans état, préfixe api_v1.).
    $r->post('/api/v1/messages', [V1::class, 'send'], 'api_v1.send', ['apikey:send']);
    $r->get('/api/v1/messages/{id}', [V1::class, 'message'], 'api_v1.message', ['apikey:read']);
    $r->get('/api/v1/batches/{id}', [V1::class, 'batch'], 'api_v1.batch', ['apikey:read']);
    $r->get('/api/v1/balance', [V1::class, 'balance'], 'api_v1.balance', ['apikey:read']);

    // Gestion des clés depuis l'interface (session).
    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        $manage = 'perm:manage_api_keys';
        $r->get('/developer', [K::class, 'page'], 'developer.page', [$manage]);
        $r->get('/api/api-keys', [K::class, 'index'], 'apikeys.list', [$manage]);
        $r->post('/api/api-keys', [K::class, 'create'], 'apikeys.create', ['csrf', $manage]);
        $r->delete('/api/api-keys/{id:\d+}', [K::class, 'revoke'], 'apikeys.revoke', ['csrf', $manage]);
    });
};
