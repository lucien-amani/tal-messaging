<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Campaigns\Controllers\CampaignController as C;

return static function (Router $r): void {
    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        $view = 'perm:view_campaigns';
        $manage = ['csrf', 'perm:manage_campaigns'];

        $r->get('/campaigns', [C::class, 'indexPage'], 'campaigns.page', [$view]);
        $r->get('/campaigns/new', [C::class, 'formPage'], 'campaigns.new', ['perm:manage_campaigns']);
        $r->get('/campaigns/{id}', [C::class, 'showPage'], 'campaigns.report_page', [$view]);
        $r->get('/campaigns/{id}/edit', [C::class, 'formPage'], 'campaigns.edit', ['perm:manage_campaigns']);

        $r->get('/api/campaigns', [C::class, 'index'], 'campaigns.list', [$view]);
        $r->get('/api/campaigns/{id}', [C::class, 'show'], 'campaigns.show', [$view]);
        $r->get('/api/campaigns/{id}/report', [C::class, 'report'], 'campaigns.report', [$view]);
        $r->get('/api/campaigns/{id}/export', [C::class, 'export'], 'campaigns.export', [$view]);
        $r->get('/api/campaign-templates', [C::class, 'templates'], 'campaigns.templates', [$view]);

        $r->post('/api/campaigns/preview', [C::class, 'preview'], 'campaigns.preview', $manage);
        $r->post('/api/campaigns', [C::class, 'store'], 'campaigns.create', $manage);
        $r->put('/api/campaigns/{id}', [C::class, 'update'], 'campaigns.update', $manage);
        $r->delete('/api/campaigns/{id}', [C::class, 'destroy'], 'campaigns.delete', $manage);
        $r->post('/api/campaigns/{id}/duplicate', [C::class, 'duplicate'], 'campaigns.duplicate', $manage);
        $r->post('/api/campaigns/{id}/launch', [C::class, 'launch'], 'campaigns.launch', $manage);
        $r->post('/api/campaigns/{id}/cancel', [C::class, 'cancel'], 'campaigns.cancel', $manage);

        $r->post('/api/campaign-templates', [C::class, 'templateStore'], 'campaigns.template_create', $manage);
        $r->put('/api/campaign-templates/{id}', [C::class, 'templateUpdate'], 'campaigns.template_update', $manage);
        $r->delete('/api/campaign-templates/{id}', [C::class, 'templateDestroy'], 'campaigns.template_delete', $manage);
    });
};
