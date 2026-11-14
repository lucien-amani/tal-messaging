<?php
declare(strict_types=1);

namespace App\Modules\SenderIds\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Modules\SenderIds\Repositories\SenderIdRepository;
use App\Tenancy\TenantContext;

/**
 * Cycle de vie d'un Sender ID :
 *   pending ──approve──▶ approved ──suspend──▶ suspended ──reactivate──▶ approved
 *      └─────reject────▶ rejected ──(le client corrige et renvoie)──▶ pending
 * Seul TAL (permission review_sender_ids) décide ; le client demande, corrige, annule et choisit son défaut.
 */
final class SenderIdService
{
    private const TRANSITIONS = [
        'approve' => [['pending'], 'approved', 'approved'],
        'reject' => [['pending'], 'rejected', 'rejected'],
        'suspend' => [['approved'], 'suspended', 'suspended'],
        'reactivate' => [['suspended'], 'approved', 'reactivated'],
    ];

    /* --------------------------------------------------------- Validation */

    /** @return string nom normalisé (trim, espaces simples) */
    public static function validateName(string $raw): string
    {
        $cfg = (array) Config::get('sender_ids');
        $name = trim((string) preg_replace('/\s+/', ' ', $raw));
        $len = mb_strlen($name);
        if ($len < $cfg['min_length'] || $len > $cfg['max_length']) {
            throw ApiException::validation(['name' => ["Entre {$cfg['min_length']} et {$cfg['max_length']} caractères."]]);
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $name) !== 1 || preg_match('/[A-Za-z]/', $name) !== 1) {
            throw ApiException::validation(['name' => ['Lettres et chiffres uniquement (espace . _ - autorisés à l’intérieur), avec au moins une lettre, sans accent.']]);
        }
        $flat = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
        if (in_array($flat, (array) $cfg['reserved'], true)) {
            throw new ApiException('SENDER_ID_RESERVED', 'Ce nom est réservé (marque d’opérateur, banque ou institution). Choisissez le nom de votre entreprise ou de votre marque.', 422, ['fields' => ['name' => ['Nom réservé.']]]);
        }
        return $name;
    }

    private static function country(mixed $c): string
    {
        $c = strtoupper((string) ($c ?: Config::get('sender_ids.default_country')));
        if (!isset(((array) Config::get('countries.countries'))[$c])) {
            throw ApiException::validation(['country' => ['Pays inconnu.']]);
        }
        return $c;
    }

    private static function purpose(mixed $p): string
    {
        $p = (string) ($p ?: 'transactional');
        if (!isset(((array) Config::get('sender_ids.purposes'))[$p])) {
            throw ApiException::validation(['purpose' => ['Usage inconnu.']]);
        }
        return $p;
    }

    private static function taken(): ApiException
    {
        return new ApiException('SENDER_ID_TAKEN', 'Ce nom n’est pas disponible dans ce pays. Choisissez-en un autre.', 409, ['fields' => ['name' => ['Nom indisponible.']]]);
    }

    /* ---------------------------------------------------------- Demandes */

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function request(array $in, int $orgId, int $actorId): array
    {
        $cfg = (array) Config::get('sender_ids');
        $name = self::validateName((string) $in['name']);
        $country = self::country($in['country'] ?? null);
        $purpose = self::purpose($in['purpose'] ?? null);

        $active = (int) Database::scalar("SELECT COUNT(*) FROM sender_ids WHERE organization_id = ? AND deleted_at IS NULL AND status <> 'rejected'", [$orgId]);
        if ($active >= (int) $cfg['max_per_org']) {
            throw ApiException::conflict('Nombre maximum de Sender IDs atteint (' . $cfg['max_per_org'] . '). Supprimez-en un ou contactez TAL.', 'SENDER_ID_LIMIT');
        }
        $pending = (int) Database::scalar("SELECT COUNT(*) FROM sender_ids WHERE organization_id = ? AND deleted_at IS NULL AND status = 'pending'", [$orgId]);
        if ($pending >= (int) $cfg['max_pending_per_org']) {
            throw ApiException::conflict('Trop de demandes en attente de validation (' . $cfg['max_pending_per_org'] . ' maximum).', 'SENDER_ID_PENDING_LIMIT');
        }

        $repo = new SenderIdRepository();
        try {
            $id = $repo->insert([
                'uuid' => uuid4(), 'organization_id' => $orgId, 'name' => $name, 'country' => $country, 'purpose' => $purpose,
                'description' => self::opt($in['description'] ?? null), 'sample_message' => self::opt($in['sample_message'] ?? null),
                'status' => 'pending', 'created_by' => $actorId,
            ]);
        } catch (\PDOException $e) {
            throw $e->getCode() === '23000' ? self::taken() : $e;
        }
        self::event($orgId, $id, $actorId, 'requested');
        return self::one($id);
    }

    /** Correction d'une demande en attente ou rejetée ; une demande rejetée corrigée repart en validation. @param array<string,mixed> $in */
    public static function update(int $id, array $in, int $actorId): array
    {
        $repo = new SenderIdRepository();
        $cur = $repo->find($id) ?? throw ApiException::notFound('Sender ID introuvable.');
        if (!in_array($cur['status'], ['pending', 'rejected'], true)) {
            throw ApiException::conflict('Un Sender ID approuvé ou suspendu ne peut pas être modifié. Demandez-en un nouveau.', 'SENDER_ID_LOCKED');
        }
        $upd = [];
        if (isset($in['name'])) {
            $upd['name'] = self::validateName((string) $in['name']);
        }
        if (isset($in['country'])) {
            $upd['country'] = self::country($in['country']);
        }
        if (isset($in['purpose'])) {
            $upd['purpose'] = self::purpose($in['purpose']);
        }
        foreach (['description', 'sample_message'] as $f) {
            if (array_key_exists($f, $in)) {
                $upd[$f] = self::opt($in[$f]);
            }
        }
        $resubmit = $cur['status'] === 'rejected';
        if ($resubmit) {
            $upd += ['status' => 'pending', 'status_reason' => null, 'reviewed_by' => null, 'reviewed_at' => null];
            $pending = (int) Database::scalar("SELECT COUNT(*) FROM sender_ids WHERE organization_id = ? AND deleted_at IS NULL AND status = 'pending'", [$cur['organization_id']]);
            if ($pending >= (int) Config::get('sender_ids.max_pending_per_org')) {
                throw ApiException::conflict('Trop de demandes en attente de validation.', 'SENDER_ID_PENDING_LIMIT');
            }
        }
        try {
            $repo->update($id, $upd);
        } catch (\PDOException $e) {
            throw $e->getCode() === '23000' ? self::taken() : $e;
        }
        self::event((int) $cur['organization_id'], $id, $actorId, $resubmit ? 'resubmitted' : 'updated');
        return self::one($id);
    }

    /** Annulation / retrait par le client (suppression logique ; le nom redevient disponible). */
    public static function delete(int $id, int $actorId): void
    {
        $repo = new SenderIdRepository();
        $cur = $repo->find($id) ?? throw ApiException::notFound('Sender ID introuvable.');
        if ($cur['status'] === 'suspended' && !TenantContext::current()->global) {
            throw ApiException::conflict('Un Sender ID suspendu ne peut être retiré que par TAL.', 'SENDER_ID_LOCKED');
        }
        Database::transaction(function () use ($repo, $id, $cur, $actorId): void {
            $repo->update($id, ['is_default' => 0]);
            $repo->softDelete($id);
            self::event((int) $cur['organization_id'], $id, $actorId, 'cancelled');
        });
    }

    public static function setDefault(int $id, int $actorId): array
    {
        $repo = new SenderIdRepository();
        $cur = $repo->find($id) ?? throw ApiException::notFound('Sender ID introuvable.');
        if ($cur['status'] !== 'approved') {
            throw ApiException::conflict('Seul un Sender ID approuvé peut être défini par défaut.', 'SENDER_ID_NOT_APPROVED');
        }
        Database::transaction(function () use ($cur, $id, $actorId): void {
            Database::execute('UPDATE sender_ids SET is_default = 0 WHERE organization_id = ? AND is_default = 1 AND id <> ?', [$cur['organization_id'], $id]);
            Database::execute('UPDATE sender_ids SET is_default = 1 WHERE id = ?', [$id]);
            self::event((int) $cur['organization_id'], $id, $actorId, 'set_default');
        });
        return self::one($id);
    }

    /* ----------------------------------------------------- Décision de TAL */

    /** @return array<string,mixed> */
    public static function review(int $id, string $decision, ?string $reason, int $actorId): array
    {
        if (!isset(self::TRANSITIONS[$decision])) {
            throw ApiException::validation(['decision' => ['Décision inconnue (approve, reject, suspend, reactivate).']]);
        }
        [$from, $to, $event] = self::TRANSITIONS[$decision];
        $reason = $reason !== null ? trim($reason) : null;
        if (in_array($decision, ['reject', 'suspend'], true) && ($reason === null || mb_strlen($reason) < 5)) {
            throw ApiException::validation(['reason' => ['Indiquez le motif (5 caractères minimum) : il est communiqué au client.']]);
        }
        $repo = new SenderIdRepository();
        $cur = $repo->find($id) ?? throw ApiException::notFound('Sender ID introuvable.');
        if (!in_array($cur['status'], $from, true)) {
            throw ApiException::conflict('Cette décision n’est pas possible depuis le statut « ' . $cur['status'] . ' ».', 'SENDER_ID_BAD_TRANSITION');
        }
        Database::transaction(function () use ($repo, $id, $cur, $to, $event, $reason, $actorId, $decision): void {
            // Verrou de ligne : deux relecteurs simultanés ne décident pas deux fois.
            $locked = Database::first('SELECT status FROM sender_ids WHERE id = ? FOR UPDATE', [$id]);
            if (($locked['status'] ?? null) !== $cur['status']) {
                throw ApiException::conflict('Ce Sender ID vient d’être traité par un autre relecteur.', 'SENDER_ID_BAD_TRANSITION');
            }
            $upd = ['status' => $to, 'status_reason' => in_array($decision, ['reject', 'suspend'], true) ? $reason : null,
                'reviewed_by' => $actorId, 'reviewed_at' => gmdate('Y-m-d H:i:s')];
            if (in_array($to, ['rejected', 'suspended'], true)) {
                $upd['is_default'] = 0;
            }
            $repo->update($id, $upd);
            if ($to === 'approved') {
                // Premier Sender ID approuvé de l'organisation : devient le défaut.
                $hasDefault = Database::scalar('SELECT COUNT(*) FROM sender_ids WHERE organization_id = ? AND is_default = 1 AND deleted_at IS NULL', [$cur['organization_id']]);
                if ((int) $hasDefault === 0) {
                    Database::execute('UPDATE sender_ids SET is_default = 1 WHERE id = ?', [$id]);
                }
            }
            self::event((int) $cur['organization_id'], $id, $actorId, $event, $reason);
        });
        return self::one($id);
    }

    /* ------------------------------------------------------------ Lecture */

    /** @return array<string,mixed> */
    public static function one(int $id): array
    {
        $row = (new SenderIdRepository())->find($id) ?? throw ApiException::notFound('Sender ID introuvable.');
        return SenderIdRepository::present($row);
    }

    /** @return array<int,array<string,mixed>> */
    public static function events(int $id): array
    {
        (new SenderIdRepository())->find($id) ?? throw ApiException::notFound('Sender ID introuvable.');
        return Database::select(
            'SELECT e.id, e.action, e.note, e.created_at, u.first_name, u.last_name, (u.organization_id IS NULL) AS by_tal
             FROM sender_id_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.sender_id = ? ORDER BY e.id DESC LIMIT 50',
            [$id]
        );
    }

    /**
     * Sender IDs utilisables pour un envoi (approuvés), défaut en premier.
     * @return array<int,array<string,mixed>>
     */
    public static function approved(?int $orgId = null): array
    {
        $repo = new SenderIdRepository();
        [$scope, $p] = $repo->scope();
        $sql = "SELECT s.id, s.organization_id, s.name, s.country, s.purpose, s.is_default FROM sender_ids s WHERE $scope AND s.status = 'approved'";
        if ($orgId !== null) {
            $sql .= ' AND s.organization_id = ?';
            $p[] = $orgId;
        }
        $rows = Database::select($sql . ' ORDER BY s.is_default DESC, s.name', $p);
        foreach ($rows as &$r) {
            $r['is_default'] = (bool) $r['is_default'];
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public static function stats(): array
    {
        $repo = new SenderIdRepository();
        [$scope, $p] = $repo->scope();
        $by = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'suspended' => 0];
        foreach (Database::select("SELECT s.status, COUNT(*) AS n FROM sender_ids s WHERE $scope GROUP BY s.status", $p) as $r) {
            $by[(string) $r['status']] = (int) $r['n'];
        }
        $avg = Database::scalar("SELECT AVG(TIMESTAMPDIFF(MINUTE, s.created_at, s.reviewed_at)) FROM sender_ids s WHERE $scope AND s.reviewed_at IS NOT NULL", $p);
        $oldest = Database::scalar("SELECT MIN(s.created_at) FROM sender_ids s WHERE $scope AND s.status = 'pending'", $p);
        return ['by_status' => $by, 'avg_review_minutes' => $avg === null ? null : (int) round((float) $avg), 'oldest_pending_at' => $oldest];
    }

    /* ------------------------------------------------------------ Interne */

    public static function event(int $orgId, int $senderId, ?int $userId, string $action, ?string $note = null): void
    {
        Database::execute(
            'INSERT INTO sender_id_events (organization_id, sender_id, user_id, action, note) VALUES (?, ?, ?, ?, ?)',
            [$orgId, $senderId, $userId, $action, $note !== null ? mb_substr($note, 0, 255) : null]
        );
    }

    private static function opt(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : $v;
    }
}
