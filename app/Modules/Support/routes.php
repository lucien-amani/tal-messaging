<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Support\Controllers\TicketController as T;

return static function (Router $r): void {
    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        $any = 'any:create_tickets,manage_tickets';
        $r->get('/tickets', [T::class, 'page'], 'tickets.page', [$any]);
        $r->get('/api/tickets', [T::class, 'index'], 'tickets.list', [$any]);
        $r->get('/api/tickets/{id:\d+}', [T::class, 'show'], 'tickets.show', [$any]);
        $r->post('/api/tickets', [T::class, 'create'], 'tickets.create', ['csrf', 'perm:create_tickets']);
        $r->post('/api/tickets/{id:\d+}/replies', [T::class, 'reply'], 'tickets.reply', ['csrf', $any]);
        $r->put('/api/tickets/{id:\d+}/status', [T::class, 'status'], 'tickets.status', ['csrf', $any]);
        $r->put('/api/tickets/{id:\d+}/assign', [T::class, 'assign'], 'tickets.assign', ['csrf', 'perm:manage_tickets']);
        $r->get('/api/support/search', [T::class, 'search'], 'support.search', ['perm:manage_tickets']);
    });
};
