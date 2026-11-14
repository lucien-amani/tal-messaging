<?php
declare(strict_types=1);

namespace App\Modules\Campaigns\Repositories;

use App\Repositories\TenantRepository;

final class CampaignRepository extends TenantRepository
{
    protected string $table = 'campaigns';
    protected string $alias = 'c';
    protected string $select = 'c.id, c.uuid, c.organization_id, o.name AS organization_name, c.name, c.sender_id_id, s.name AS sender_name, c.body,
        c.group_ids, c.segment_ids, c.status, c.scheduled_at, c.batch_id, c.total_recipients, c.total_segments, c.launched_at, c.completed_at, c.created_by, c.created_at,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = c.batch_id AND x.status = \'queued\') AS n_queued,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = c.batch_id AND x.status = \'sending\') AS n_sending,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = c.batch_id AND x.status IN (\'sent\', \'delivered\')) AS n_sent,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = c.batch_id AND x.status = \'delivered\') AS n_delivered,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = c.batch_id AND x.status = \'failed\') AS n_failed,
        (SELECT COUNT(*) FROM messages x WHERE x.batch_id = c.batch_id AND x.status = \'cancelled\') AS n_cancelled';
    protected array $writable = ['uuid', 'organization_id', 'name', 'sender_id_id', 'body', 'group_ids', 'segment_ids', 'scheduled_at', 'created_by'];
    protected array $sortable = ['id' => 'c.id', 'name' => 'c.name', 'created_at' => 'c.created_at', 'status' => 'c.status'];

    protected function from(): string
    {
        return '`campaigns` c JOIN `organizations` o ON o.id = c.organization_id LEFT JOIN `sender_ids` s ON s.id = c.sender_id_id';
    }
}
