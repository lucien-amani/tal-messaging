<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Site\Controllers\PipelineController as L;
use App\Modules\Site\Controllers\PublicController as P;

return static function (Router $r): void {
    // Site public (session créée pour le jeton CSRF du formulaire de contact).
    $r->get('/', [P::class, 'home'], 'public.home');
    $r->get('/tarifs', [P::class, 'pricing'], 'public.pricing');
    $r->get('/documentation', [P::class, 'docs'], 'public.docs');
    $r->get('/contact', [P::class, 'contact'], 'public.contact');
    $r->post('/api/public/contact', [P::class, 'submit'], 'public.contact_submit', ['csrf']);

    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        $crm = 'perm:manage_crm';
        $r->get('/pipeline', [L::class, 'page'], 'pipeline.page', [$crm]);
        $r->get('/api/leads', [L::class, 'index'], 'leads.list', [$crm]);
        $r->put('/api/leads/{id:\d+}', [L::class, 'update'], 'leads.update', ['csrf', $crm]);
    });
};
