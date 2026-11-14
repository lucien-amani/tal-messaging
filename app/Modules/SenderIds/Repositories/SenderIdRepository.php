<?php
declare(strict_types=1);

namespace App\Modules\SenderIds\Repositories;

use App\Repositories\TenantRepository;

final class SenderIdRepository extends TenantRepository
{
    protected string $table = 'sender_ids';
    protected string $alias = 's';
    protected string $select = 's.id, s.uuid, s.organization_id, o.name AS organization_name, s.name, s.country, s.purpose, s.description,
        s.sample_message, s.status, s.status_reason, s.reviewed_at, s.is_default, s.created_at, s.updated_at,
        ru.first_name AS reviewer_first_name, ru.last_name AS reviewer_last_name';
    protected array $writable = ['uuid', 'organization_id', 'name', 'country', 'purpose', 'description', 'sample_message', 'status',
        'status_reason', 'reviewed_by', 'reviewed_at', 'is_default', 'created_by'];
    protected array $sortable = ['id' => 's.id', 'name' => 's.name', 'status' => 's.status', 'created_at' => 's.created_at', 'organization' => 'o.name'];

    protected function from(): string
    {
        return '`sender_ids` s JOIN `organizations` o ON o.id = s.organization_id LEFT JOIN `users` ru ON ru.id = s.reviewed_by';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function present(array $row): array
    {
        $name = trim((string) ($row['reviewer_first_name'] ?? '') . ' ' . (string) ($row['reviewer_last_name'] ?? ''));
        unset($row['reviewer_first_name'], $row['reviewer_last_name']);
        $row['reviewed_by_name'] = $name !== '' ? $name : null;
        $row['is_default'] = (bool) $row['is_default'];
        return $row;
    }
}
