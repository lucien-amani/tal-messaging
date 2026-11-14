<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Messaging\Controllers\MessageController;

return static function (Router $r): void {
    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        $view = 'perm:view_messages';
        $send = ['csrf', 'perm:send_messages'];

        $r->get('/messages/quick-send', [MessageController::class, 'quickSendPage'], 'messages.quick_send', ['perm:send_messages']);
        $r->get('/messages', [MessageController::class, 'centerPage'], 'messages.center', [$view]);

        $r->get('/api/messages', [MessageController::class, 'index'], 'messages.list', [$view]);
        $r->get('/api/messages/stats', [MessageController::class, 'stats'], 'messages.stats', [$view]);
        $r->get('/api/messages/{id}', [MessageController::class, 'show'], 'messages.show', [$view]);
        $r->get('/api/message-batches', [MessageController::class, 'batches'], 'messages.batches', [$view]);
        $r->get('/api/message-batches/{id}', [MessageController::class, 'batch'], 'messages.batch', [$view]);

        $r->post('/api/messages/analyze', [MessageController::class, 'analyze'], 'messages.analyze', $send);
        $r->post('/api/messages/preview', [MessageController::class, 'preview'], 'messages.preview', $send);
        $r->post('/api/messages/send', [MessageController::class, 'send'], 'messages.send', $send);
        $r->post('/api/messages/{id}/cancel', [MessageController::class, 'cancel'], 'messages.cancel', $send);
        $r->post('/api/message-batches/{id}/cancel', [MessageController::class, 'cancelBatch'], 'messages.batch_cancel', $send);
    });
};
