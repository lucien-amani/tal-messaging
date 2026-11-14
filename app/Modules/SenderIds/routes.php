<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\SenderIds\Controllers\SenderIdController;

return static function (Router $r): void {
    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        $view = 'perm:view_sender_ids';
        $manage = ['csrf', 'perm:manage_sender_ids'];

        $r->get('/sender-ids', [SenderIdController::class, 'page'], 'sender_ids.page', [$view]);

        $r->get('/api/sender-ids', [SenderIdController::class, 'index'], 'sender_ids.list', [$view]);
        $r->get('/api/sender-ids/approved', [SenderIdController::class, 'approved'], 'sender_ids.approved', [$view]);
        $r->get('/api/sender-ids/stats', [SenderIdController::class, 'stats'], 'sender_ids.stats', [$view]);
        $r->post('/api/sender-ids', [SenderIdController::class, 'store'], 'sender_ids.create', $manage);
        $r->get('/api/sender-ids/{id}', [SenderIdController::class, 'show'], 'sender_ids.show', [$view]);
        $r->get('/api/sender-ids/{id}/events', [SenderIdController::class, 'events'], 'sender_ids.events', [$view]);
        $r->put('/api/sender-ids/{id}', [SenderIdController::class, 'update'], 'sender_ids.update', $manage);
        $r->delete('/api/sender-ids/{id}', [SenderIdController::class, 'destroy'], 'sender_ids.delete', $manage);
        $r->post('/api/sender-ids/{id}/default', [SenderIdController::class, 'setDefault'], 'sender_ids.default', $manage);
        // Réservé à TAL : un client, même Owner, ne peut jamais approuver son propre Sender ID.
        $r->post('/api/sender-ids/{id}/review', [SenderIdController::class, 'review'], 'sender_ids.review', ['csrf', 'perm:review_sender_ids']);
    });
};
