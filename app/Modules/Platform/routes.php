<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Platform\Controllers\PlatformController as P;

return static function (Router $r): void {
    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        $r->get('/api/dashboards/{slug:ceo|cto|operations|sales|support|client}', [P::class, 'dashboard'], 'dashboards.data');
        $r->get('/team', [P::class, 'teamPage'], 'team.page', ['perm:manage_team']);
        $r->get('/clients', [P::class, 'clientsPage'], 'clients.page', ['perm:view_all_tenants,view_organization']);
        $r->get('/api/clients/{id:\d+}/overview', [P::class, 'overview'], 'clients.overview', ['perm:view_all_tenants,view_organization']);
    });
};
