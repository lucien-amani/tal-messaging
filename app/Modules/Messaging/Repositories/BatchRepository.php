<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Repositories;

use App\Repositories\TenantRepository;

final class BatchRepository extends TenantRepository
{
    protected string $table = 'message_batches';
    protected string $alias = 'b';
    protected bool $softDelete = false;
    protected string $select = 'b.id, b.uuid, b.organization_id, o.name AS organization_name, b.sender_id_id, b.sender_name, b.source, b.body,
        b.total_messages, b.total_segments, b.scheduled_at, b.created_by, b.created_at,
        CONCAT_WS(\' \', u.first_name, u.last_name) AS created_by_name,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = b.id AND x.status = \'queued\') AS n_queued,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = b.id AND x.status IN (\'sent\', \'delivered\')) AS n_sent,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = b.id AND x.status = \'delivered\') AS n_delivered,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = b.id AND x.status = \'failed\') AS n_failed,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = b.id AND x.status = \'cancelled\') AS n_cancelled';
    protected array $writable = ['uuid', 'organization_id', 'sender_id_id', 'sender_name', 'source', 'body', 'total_messages', 'total_segments',
        'scheduled_at', 'idempotency_key', 'created_by'];
    protected array $sortable = ['id' => 'b.id', 'created_at' => 'b.created_at'];

    protected function from(): string
    {
        return '`message_batches` b JOIN `organizations` o ON o.id = b.organization_id LEFT JOIN `users` u ON u.id = b.created_by';
    }
}
