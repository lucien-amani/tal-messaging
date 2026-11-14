<?php
declare(strict_types=1);

namespace App\Modules\Support\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Tenancy\TenantContext;

/**
 * Tickets : un client ne voit que ceux de son organisation (et jamais les notes internes) ;
 * l'équipe (manage_tickets) voit tout, assigne, change le statut et peut écrire des notes internes.
 */
final class TicketService
{
    public const CATEGORIES = ['delivery' => 'Livraison des SMS', 'billing' => 'Facturation / wallet', 'sender_id' => 'Sender ID', 'api' => 'API / intégration', 'account' => 'Compte et accès', 'other' => 'Autre'];
    public const STATUSES = ['open' => 'Ouvert', 'pending' => 'En attente du client', 'resolved' => 'Résolu', 'closed' => 'Fermé'];

    private static function scope(string $alias = 't'): array
    {
        $ctx = TenantContext::current();
        return $ctx->global ? ['1 = 1', []] : ["$alias.organization_id = ?", [(int) $ctx->organizationId]];
    }

    /** @param array<string,mixed> $f @return array{data:array<int,array<string,mixed>>,meta:array<string,int>} */
    public static function list(array $f, int $page, int $per, int $userId, bool $team): array
    {
        [$where, $p] = self::scope();
        foreach (['status' => 't.status', 'priority' => 't.priority', 'category' => 't.category'] as $k => $col) {
            if (!empty($f[$k]) && is_string($f[$k])) {
                $where .= " AND $col = ?";
                $p[] = $f[$k];
            }
        }
        if ($team && ($f['assigned'] ?? '') === 'me') {
            $where .= ' AND t.assigned_to = ?';
            $p[] = $userId;
        } elseif ($team && ($f['assigned'] ?? '') === 'none') {
            $where .= ' AND t.assigned_to IS NULL';
        }
        if (!empty($f['q']) && is_string($f['q'])) {
            $where .= ' AND (t.subject LIKE ? OR t.uuid = ?)';
            array_push($p, '%' . addcslashes(trim($f['q']), '%_\\') . '%', trim($f['q']));
        }
        $total = (int) Database::scalar("SELECT COUNT(*) FROM tickets t WHERE $where", $p);
        $rows = Database::select(
            "SELECT t.id, t.uuid, t.subject, t.category, t.priority, t.status, t.assigned_to, t.last_reply_at, t.last_reply_by, t.created_at, o.name AS organization_name,
                    CONCAT(c.first_name, ' ', c.last_name) AS author, CONCAT(a.first_name, ' ', a.last_name) AS assignee
             FROM tickets t JOIN organizations o ON o.id = t.organization_id JOIN users c ON c.id = t.created_by LEFT JOIN users a ON a.id = t.assigned_to
             WHERE $where ORDER BY FIELD(t.status, 'open', 'pending', 'resolved', 'closed'), FIELD(t.priority, 'high', 'normal', 'low'), t.id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
            $p
        );
        return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total]];
    }

    /** @return array<string,mixed> */
    public static function find(int $id, bool $team): array
    {
        [$where, $p] = self::scope();
        $t = Database::first(
            "SELECT t.*, o.name AS organization_name, CONCAT(c.first_name, ' ', c.last_name) AS author, CONCAT(a.first_name, ' ', a.last_name) AS assignee
             FROM tickets t JOIN organizations o ON o.id = t.organization_id JOIN users c ON c.id = t.created_by LEFT JOIN users a ON a.id = t.assigned_to
             WHERE t.id = ? AND $where",
            array_merge([$id], $p)
        ) ?? throw ApiException::notFound('Ticket introuvable.');
        $t['replies'] = Database::select(
            "SELECT r.id, r.body, r.is_internal, r.created_at, CONCAT(u.first_name, ' ', u.last_name) AS author, (u.organization_id IS NULL) AS from_team
             FROM ticket_replies r JOIN users u ON u.id = r.user_id WHERE r.ticket_id = ?" . ($team ? '' : ' AND r.is_internal = 0') . ' ORDER BY r.id',
            [$id]
        );
        return $t;
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public static function create(int $orgId, int $userId, array $in): array
    {
        $subject = trim((string) ($in['subject'] ?? ''));
        $body = trim((string) ($in['body'] ?? ''));
        $errors = [];
        if (mb_strlen($subject) < 5 || mb_strlen($subject) > 160) {
            $errors['subject'] = ['Le sujet doit contenir entre 5 et 160 caractères.'];
        }
        if (mb_strlen($body) < 10 || mb_strlen($body) > 5000) {
            $errors['body'] = ['Décrivez le problème (10 à 5 000 caractères).'];
        }
        $cat = (string) ($in['category'] ?? 'other');
        $prio = (string) ($in['priority'] ?? 'normal');
        if (!isset(self::CATEGORIES[$cat])) {
            $errors['category'] = ['Catégorie invalide.'];
        }
        if (!in_array($prio, ['low', 'normal', 'high'], true)) {
            $errors['priority'] = ['Priorité invalide.'];
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        $ref = trim((string) ($in['reference'] ?? ''));
        $uuid = uuid4();
        $id = Database::transaction(function () use ($orgId, $userId, $uuid, $subject, $cat, $prio, $ref, $body): int {
            Database::execute('INSERT INTO tickets (uuid, organization_id, created_by, subject, category, priority, reference, last_reply_at, last_reply_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [$uuid, $orgId, $userId, $subject, $cat, $prio, $ref !== '' ? mb_substr($ref, 0, 64) : null, gmdate('Y-m-d H:i:s'), 'client']);
            $id = (int) Database::scalar('SELECT id FROM tickets WHERE uuid = ?', [$uuid]);
            Database::execute('INSERT INTO ticket_replies (ticket_id, user_id, is_internal, body) VALUES (?,?,0,?)', [$id, $userId, $body]);
            return $id;
        });
        return self::find($id, false);
    }

    /** @return array<string,mixed> */
    public static function reply(int $id, int $userId, string $body, bool $internal, bool $team): array
    {
        $t = self::find($id, $team);
        $body = trim($body);
        if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) {
            throw ApiException::validation(['body' => ['Message de 2 à 5 000 caractères.']]);
        }
        if ($t['status'] === 'closed') {
            throw ApiException::conflict('Ce ticket est fermé : ouvrez-en un nouveau.', 'TICKET_CLOSED');
        }
        $internal = $internal && $team;
        Database::execute('INSERT INTO ticket_replies (ticket_id, user_id, is_internal, body) VALUES (?,?,?,?)', [$id, $userId, (int) $internal, $body]);
        if (!$internal) {
            // Réponse équipe → en attente du client ; réponse client → rouvre le ticket.
            Database::execute('UPDATE tickets SET last_reply_at = ?, last_reply_by = ?, status = ?, assigned_to = COALESCE(assigned_to, ?) WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), $team ? 'team' : 'client', $team ? 'pending' : 'open', $team ? $userId : null, $id]);
        }
        return self::find($id, $team);
    }

    /** Statut : l'équipe tout ; le client peut seulement fermer son ticket. @return array<string,mixed> */
    public static function setStatus(int $id, string $status, bool $team): array
    {
        self::find($id, $team);
        if (!isset(self::STATUSES[$status]) || (!$team && $status !== 'closed')) {
            throw ApiException::forbidden('Changement de statut non autorisé.', 'PERMISSION_DENIED');
        }
        Database::execute('UPDATE tickets SET status = ? WHERE id = ?', [$status, $id]);
        return self::find($id, $team);
    }

    /** @return array<string,mixed> */
    public static function assign(int $id, ?int $userId): array
    {
        self::find($id, true);
        if ($userId !== null && !Database::scalar('SELECT 1 FROM users WHERE id = ? AND organization_id IS NULL AND status = ? AND deleted_at IS NULL', [$userId, 'active'])) {
            throw ApiException::validation(['assigned_to' => ['Agent introuvable.']]);
        }
        Database::execute('UPDATE tickets SET assigned_to = ? WHERE id = ?', [$userId, $id]);
        return self::find($id, true);
    }

    /** Recherche transversale pour le support : messages (numéro ou identifiant) et campagnes (nom). @return array<string,mixed> */
    public static function search(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 3) {
            throw ApiException::validation(['q' => ['Saisissez au moins 3 caractères.']]);
        }
        $ctx = TenantContext::current();
        $org = $ctx->global ? '' : ' AND m.organization_id = ' . (int) $ctx->organizationId;
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $messages = Database::select(
            "SELECT m.uuid, m.to_e164, m.sender_name, m.status, m.error_code, m.provider, m.created_at, o.name AS organization_name
             FROM messages m JOIN organizations o ON o.id = m.organization_id WHERE (m.uuid = ? OR m.to_e164 LIKE ?)$org ORDER BY m.id DESC LIMIT 25",
            [$q, $like]
        );
        $corg = $ctx->global ? '' : ' AND c.organization_id = ' . (int) $ctx->organizationId;
        $campaigns = Database::select(
            "SELECT c.id, c.name, c.status, c.created_at, o.name AS organization_name FROM campaigns c JOIN organizations o ON o.id = c.organization_id WHERE c.deleted_at IS NULL AND c.name LIKE ?$corg ORDER BY c.id DESC LIMIT 15",
            [$like]
        );
        return ['messages' => $messages, 'campaigns' => $campaigns];
    }
}
