<?php
declare(strict_types=1);

namespace App\Modules\Campaigns\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Modules\Campaigns\Repositories\TemplateRepository;

final class TemplateService
{
    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function create(array $in, int $orgId, int $actorId): array
    {
        $count = (int) Database::scalar('SELECT COUNT(*) FROM message_templates WHERE organization_id = ? AND deleted_at IS NULL', [$orgId]);
        if ($count >= (int) Config::get('campaigns.max_templates_per_org')) {
            throw new ApiException('TEMPLATE_LIMIT', 'Limite de modèles atteinte.', 422);
        }
        self::assertFree($orgId, (string) $in['name'], null);
        $id = (new TemplateRepository())->insert(['uuid' => uuid4(), 'organization_id' => $orgId, 'name' => trim((string) $in['name']), 'body' => trim((string) $in['body']), 'created_by' => $actorId]);
        return (new TemplateRepository())->find($id) ?? [];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function update(int $id, array $in): array
    {
        $repo = new TemplateRepository();
        $t = $repo->find($id) ?? throw ApiException::notFound('Modèle introuvable.');
        $data = [];
        if (isset($in['name'])) {
            self::assertFree((int) $t['organization_id'], (string) $in['name'], $id);
            $data['name'] = trim((string) $in['name']);
        }
        if (isset($in['body'])) {
            $data['body'] = trim((string) $in['body']);
        }
        $repo->update($id, $data);
        return $repo->find($id) ?? $t;
    }

    public static function delete(int $id): void
    {
        $repo = new TemplateRepository();
        $repo->find($id) ?? throw ApiException::notFound('Modèle introuvable.');
        $repo->softDelete($id);
    }

    private static function assertFree(int $orgId, string $name, ?int $exceptId): void
    {
        $row = Database::first('SELECT id FROM message_templates WHERE organization_id = ? AND deleted_at IS NULL AND name = ? AND id <> ?', [$orgId, trim($name), $exceptId ?? 0]);
        if ($row) {
            throw ApiException::conflict('Un modèle porte déjà ce nom.', 'TEMPLATE_NAME_TAKEN');
        }
    }
}
