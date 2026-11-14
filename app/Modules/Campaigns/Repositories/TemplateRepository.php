<?php
declare(strict_types=1);

namespace App\Modules\Campaigns\Repositories;

use App\Repositories\TenantRepository;

final class TemplateRepository extends TenantRepository
{
    protected string $table = 'message_templates';
    protected string $alias = 't';
    protected string $select = 't.id, t.uuid, t.organization_id, t.name, t.body, t.created_at, t.updated_at';
    protected array $writable = ['uuid', 'organization_id', 'name', 'body', 'created_by'];
    protected array $sortable = ['id' => 't.id', 'name' => 't.name', 'created_at' => 't.created_at'];
}
