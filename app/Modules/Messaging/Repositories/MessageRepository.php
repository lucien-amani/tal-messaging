<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Repositories;

use App\Repositories\TenantRepository;

final class MessageRepository extends TenantRepository
{
    protected string $table = 'messages';
    protected string $alias = 'm';
    protected bool $softDelete = false;
    protected string $select = 'm.id, m.uuid, m.organization_id, o.name AS organization_name, m.batch_id, m.contact_id, m.sender_name, m.to_e164, m.country,
        m.operator, m.body, m.encoding, m.segments, m.status, m.error_code, m.error_message, m.provider, m.scheduled_at, m.attempts,
        m.sent_at, m.delivered_at, m.created_at, m.cost_micro, m.billing_status';
    protected array $writable = ['uuid', 'organization_id', 'batch_id', 'contact_id', 'sender_id_id', 'sender_name', 'to_e164', 'country', 'operator', 'body',
        'encoding', 'segments', 'status', 'error_code', 'error_message', 'scheduled_at', 'created_by'];
    protected array $sortable = ['id' => 'm.id', 'created_at' => 'm.created_at', 'status' => 'm.status', 'to' => 'm.to_e164'];

    protected function from(): string
    {
        return '`messages` m JOIN `organizations` o ON o.id = m.organization_id';
    }
}
