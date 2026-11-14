<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Providers\Controllers\ProviderController as P;

return static function (Router $r): void {
    // Accusés de réception des fournisseurs : public (aucune session), protégé par le secret contenu dans l'URL.
    $r->post('/webhooks/providers/{code}/{secret}', [P::class, 'webhook'], 'webhook.provider');

    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        $manage = 'perm:manage_providers';
        $r->get('/providers', [P::class, 'page'], 'providers.page', [$manage]);
        $r->get('/api/providers', [P::class, 'index'], 'providers.list', [$manage]);
        $r->put('/api/providers/{code}', [P::class, 'update'], 'providers.update', ['csrf', $manage]);
        $r->post('/api/providers/{code}/test', [P::class, 'test'], 'providers.test', ['csrf', $manage]);
        $r->post('/api/providers/{code}/rotate-webhook', [P::class, 'rotate'], 'providers.rotate', ['csrf', $manage]);
    });
};
