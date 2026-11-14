<?php
declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Modules\Providers\Services\ProviderRouter;
use App\Support\Money;
use App\Tenancy\TenantContext;

/**
 * Données réelles de chaque dashboard (KPIs, alertes, listes). Chaque lien n'est renvoyé que si l'utilisateur a la permission de la page cible,
 * de sorte que tous les écrans restent interconnectés sans jamais proposer un accès refusé.
 */
final class DashboardService
{
    private static function since(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400);
    }

    private static function n(string $sql, array $p = []): int
    {
        return (int) Database::scalar($sql, $p);
    }

    private static function rate(int $delivered, int $failed): ?float
    {
        return ($delivered + $failed) > 0 ? round($delivered / ($delivered + $failed) * 100, 1) : null;
    }

    /** @param array<string,mixed> $user @param array<int,string> $perms @return array<string,mixed> */
    public static function build(string $slug, array $user, ?int $org = null): array
    {
        $perms = (array) $user['permissions'];
        $can = static fn(?string $p): bool => $p === null || in_array($p, $perms, true);
        $data = match ($slug) {
            'ceo' => self::ceo(),
            'cto' => self::cto(),
            'operations' => self::operations(),
            'sales' => self::sales(),
            'support' => self::support((int) $user['id']),
            'client' => self::client($org ?? (int) TenantContext::current()->organizationId),
            default => throw ApiException::notFound('Dashboard inconnu.'),
        };
        $filter = static fn(array $items): array => array_values(array_filter(array_map(static function (array $i) use ($can): array {
            if (isset($i['perm']) && !$can($i['perm'])) {
                $i['href'] = null;
            }
            unset($i['perm']);
            return $i;
        }, $items)));
        $data['kpis'] = $filter($data['kpis']);
        $data['alerts'] = $filter($data['alerts'] ?? []);
        $links = [];
        foreach ((array) Config::get('dashboards.' . $slug . '.links') as $l) {
            if ($can($l['perm'] ?? null)) {
                $links[] = ['label' => $l['label'], 'path' => $l['path'], 'icon' => $l['icon'], 'desc' => $l['desc']];
            }
        }
        $data['links'] = $links;
        $data['mode'] = ProviderRouter::mode();
        return $data;
    }

    /** @return array<string,mixed> */
    private static function ceo(): array
    {
        $d30 = self::since(30);
        $rev = [];
        foreach (Database::select("SELECT currency, SUM(-amount_micro) s FROM wallet_transactions WHERE type = 'charge' AND created_at >= ? GROUP BY currency", [$d30]) as $r) {
            $rev[] = Money::format((int) $r['s'], (string) $r['currency']);
        }
        $m = Database::first("SELECT COUNT(*) total, SUM(status = 'delivered') d, SUM(status = 'failed') f FROM messages WHERE created_at >= ?", [$d30]) ?? [];
        $alerts = [];
        if (($p = self::n("SELECT COUNT(*) FROM payments WHERE status = 'pending'")) > 0) {
            $alerts[] = ['level' => 'warn', 'text' => "$p demande(s) de recharge à confirmer.", 'href' => '/billing', 'perm' => 'approve_payments'];
        }
        if (($s = self::n("SELECT COUNT(*) FROM sender_ids WHERE status = 'pending' AND deleted_at IS NULL")) > 0) {
            $alerts[] = ['level' => 'warn', 'text' => "$s Sender ID(s) en attente de validation.", 'href' => '/sender-ids', 'perm' => 'review_sender_ids'];
        }
        if (($l = self::n('SELECT COUNT(*) FROM wallets WHERE low_balance_micro > 0 AND balance_micro - held_micro <= low_balance_micro')) > 0) {
            $alerts[] = ['level' => 'warn', 'text' => "$l client(s) sous leur seuil de solde bas.", 'href' => '/clients', 'perm' => 'view_all_tenants'];
        }
        if (($t = self::n("SELECT COUNT(*) FROM tickets WHERE priority = 'high' AND status IN ('open','pending')")) > 0) {
            $alerts[] = ['level' => 'danger', 'text' => "$t ticket(s) à priorité haute ouverts.", 'href' => '/tickets', 'perm' => 'manage_tickets'];
        }
        $top = Database::select("SELECT o.name, COUNT(*) total, SUM(m.status = 'delivered') d, SUM(m.status = 'failed') f FROM messages m JOIN organizations o ON o.id = m.organization_id WHERE m.created_at >= ? GROUP BY o.id, o.name ORDER BY total DESC LIMIT 5", [$d30]);
        return [
            'kpis' => [
                ['label' => 'Revenus (30 j)', 'value' => $rev ? implode(' · ', $rev) : '0', 'sub' => 'Messages facturés', 'href' => '/billing', 'perm' => 'view_wallet'],
                ['label' => 'Messages (30 j)', 'value' => (int) ($m['total'] ?? 0), 'sub' => 'Tous clients', 'href' => '/messages', 'perm' => 'view_messages'],
                ['label' => 'Taux de livraison', 'value' => self::rate((int) ($m['d'] ?? 0), (int) ($m['f'] ?? 0)), 'unit' => '%', 'sub' => '30 derniers jours'],
                ['label' => 'Clients actifs', 'value' => self::n("SELECT COUNT(*) FROM organizations WHERE status = 'active' AND deleted_at IS NULL"), 'sub' => '+' . self::n('SELECT COUNT(*) FROM organizations WHERE created_at >= ? AND deleted_at IS NULL', [$d30]) . ' sur 30 j', 'href' => '/clients', 'perm' => 'view_all_tenants'],
                ['label' => 'Prospects à traiter', 'value' => self::n("SELECT COUNT(*) FROM leads WHERE status = 'new'"), 'sub' => 'Site public', 'href' => '/pipeline', 'perm' => 'manage_crm'],
            ],
            'alerts' => $alerts,
            'tables' => [['title' => 'Top clients (30 j)', 'columns' => ['Client', 'Messages', 'Livraison'], 'rows' => array_map(static fn(array $r): array => [$r['name'], (int) $r['total'], self::pct(self::rate((int) $r['d'], (int) $r['f']))], $top)]],
        ];
    }

    private static function pct(?float $v): string
    {
        return $v === null ? '—' : str_replace('.', ',', (string) $v) . ' %';
    }

    /** @return array<string,mixed> */
    private static function cto(): array
    {
        $d24 = self::since(1);
        $prov = Database::select('SELECT code, enabled, sent_count, failed_count FROM providers ORDER BY priority');
        $cfg = (array) Config::get('providers.catalog');
        $alerts = [];
        $mode = ProviderRouter::mode();
        if ($mode['simulation']) {
            $alerts[] = ['level' => 'info', 'text' => 'Mode simulation : aucun fournisseur réel actif. Configurez-les pour envoyer de vrais SMS.', 'href' => '/providers', 'perm' => 'manage_providers'];
        }
        if (($s = self::n("SELECT COUNT(*) FROM messages WHERE status = 'sending' AND locked_at < ?", [gmdate('Y-m-d H:i:s', time() - 600)])) > 0) {
            $alerts[] = ['level' => 'danger', 'text' => "$s message(s) bloqué(s) en envoi depuis plus de 10 minutes : vérifiez le worker.", 'href' => null];
        }
        $errors = Database::select("SELECT error_code, COUNT(*) c FROM messages WHERE status = 'failed' AND created_at >= ? GROUP BY error_code ORDER BY c DESC LIMIT 6", [$d24]);
        return [
            'kpis' => [
                ['label' => 'File d’attente', 'value' => self::n("SELECT COUNT(*) FROM messages WHERE status = 'queued' AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())"), 'sub' => 'Messages prêts à partir', 'href' => '/messages', 'perm' => 'view_messages'],
                ['label' => 'En cours d’envoi', 'value' => self::n("SELECT COUNT(*) FROM messages WHERE status = 'sending'")],
                ['label' => 'Échecs (24 h)', 'value' => self::n("SELECT COUNT(*) FROM messages WHERE status = 'failed' AND created_at >= ?", [$d24])],
                ['label' => 'Fournisseurs actifs', 'value' => count($mode['active']) . ' / ' . count($cfg), 'sub' => $mode['simulation'] ? 'Simulation' : 'Envoi réel', 'href' => '/providers', 'perm' => 'manage_providers'],
                ['label' => 'Clés API actives', 'value' => self::n('SELECT COUNT(*) FROM api_keys WHERE revoked_at IS NULL'), 'sub' => self::n('SELECT COALESCE(SUM(hits), 0) FROM api_rate_limits WHERE window_start >= ?', [time() - 3600]) . ' requêtes (1 h)', 'href' => '/developer', 'perm' => 'manage_api_keys'],
            ],
            'alerts' => $alerts,
            'tables' => [
                ['title' => 'Fournisseurs', 'columns' => ['Fournisseur', 'État', 'Envoyés', 'Échecs'], 'rows' => array_map(static fn(array $p): array => [$cfg[$p['code']]['label'] ?? $p['code'], $p['enabled'] ? 'Activé' : 'Désactivé', (int) $p['sent_count'], (int) $p['failed_count']], $prov)],
                ['title' => 'Principales erreurs (24 h)', 'columns' => ['Code', 'Occurrences'], 'rows' => array_map(static fn(array $e): array => [$e['error_code'] ?: '—', (int) $e['c']], $errors)],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function operations(): array
    {
        $d24 = self::since(1);
        $alerts = [];
        if (($s = self::n("SELECT COUNT(*) FROM sender_ids WHERE status = 'pending' AND deleted_at IS NULL")) > 0) {
            $alerts[] = ['level' => 'warn', 'text' => "$s Sender ID(s) à valider.", 'href' => '/sender-ids', 'perm' => 'review_sender_ids'];
        }
        $ops = Database::select("SELECT COALESCE(operator, 'inconnu') op, COUNT(*) total, SUM(status = 'delivered') d, SUM(status = 'failed') f FROM messages WHERE created_at >= ? GROUP BY operator ORDER BY total DESC", [self::since(7)]);
        $camp = Database::select("SELECT c.id, c.name, c.status, o.name org FROM campaigns c JOIN organizations o ON o.id = c.organization_id WHERE c.deleted_at IS NULL AND c.status IN ('running','launching','scheduled') ORDER BY c.id DESC LIMIT 8");
        return [
            'kpis' => [
                ['label' => 'En file', 'value' => self::n("SELECT COUNT(*) FROM messages WHERE status = 'queued' AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())"), 'href' => '/messages', 'perm' => 'view_messages'],
                ['label' => 'Programmés', 'value' => self::n("SELECT COUNT(*) FROM messages WHERE status = 'queued' AND scheduled_at > UTC_TIMESTAMP()"), 'sub' => 'À venir'],
                ['label' => 'Campagnes actives', 'value' => count($camp), 'href' => '/campaigns', 'perm' => 'view_campaigns'],
                ['label' => 'Livrés (24 h)', 'value' => self::n("SELECT COUNT(*) FROM messages WHERE status = 'delivered' AND created_at >= ?", [$d24])],
                ['label' => 'Échecs (24 h)', 'value' => self::n("SELECT COUNT(*) FROM messages WHERE status = 'failed' AND created_at >= ?", [$d24])],
                ['label' => 'Sender IDs à valider', 'value' => self::n("SELECT COUNT(*) FROM sender_ids WHERE status = 'pending' AND deleted_at IS NULL"), 'href' => '/sender-ids', 'perm' => 'review_sender_ids'],
            ],
            'alerts' => $alerts,
            'tables' => [
                ['title' => 'Campagnes en cours ou programmées', 'columns' => ['Campagne', 'Client', 'Statut'], 'rows' => array_map(static fn(array $c): array => [$c['name'], $c['org'], $c['status']], $camp)],
                ['title' => 'Performance par opérateur (7 j)', 'columns' => ['Opérateur', 'Messages', 'Livraison'], 'rows' => array_map(static fn(array $o): array => [ucfirst((string) $o['op']), (int) $o['total'], self::pct(self::rate((int) $o['d'], (int) $o['f']))], $ops)],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function sales(): array
    {
        $d30 = self::since(30);
        $risk = Database::select(
            "SELECT o.name, MAX(m.created_at) last_msg, COALESCE(w.balance_micro - w.held_micro, 0) avail, w.currency
             FROM organizations o LEFT JOIN messages m ON m.organization_id = o.id LEFT JOIN wallets w ON w.organization_id = o.id
             WHERE o.status = 'active' AND o.deleted_at IS NULL AND o.created_at < ? GROUP BY o.id, o.name, w.balance_micro, w.held_micro, w.currency
             HAVING last_msg IS NULL OR last_msg < ? ORDER BY last_msg LIMIT 8",
            [$d30, $d30]
        );
        $leads = Database::select('SELECT name, company, status, created_at FROM leads ORDER BY id DESC LIMIT 6');
        $alerts = [];
        if (($p = self::n("SELECT COUNT(*) FROM payments WHERE status = 'pending'")) > 0) {
            $alerts[] = ['level' => 'warn', 'text' => "$p demande(s) de recharge à confirmer.", 'href' => '/billing', 'perm' => 'approve_payments'];
        }
        return [
            'kpis' => [
                ['label' => 'Nouveaux prospects', 'value' => self::n("SELECT COUNT(*) FROM leads WHERE status = 'new'"), 'href' => '/pipeline', 'perm' => 'manage_crm'],
                ['label' => 'Prospects qualifiés', 'value' => self::n("SELECT COUNT(*) FROM leads WHERE status = 'qualified'"), 'href' => '/pipeline', 'perm' => 'manage_crm'],
                ['label' => 'Clients actifs', 'value' => self::n("SELECT COUNT(*) FROM organizations WHERE status = 'active' AND deleted_at IS NULL"), 'href' => '/clients', 'perm' => 'view_all_tenants'],
                ['label' => 'Clients suspendus', 'value' => self::n("SELECT COUNT(*) FROM organizations WHERE status IN ('suspended','closed') AND deleted_at IS NULL")],
                ['label' => 'Clients à risque', 'value' => count($risk), 'sub' => 'Aucun envoi depuis 30 j'],
                ['label' => 'Recharges en attente', 'value' => self::n("SELECT COUNT(*) FROM payments WHERE status = 'pending'"), 'href' => '/billing', 'perm' => 'approve_payments'],
            ],
            'alerts' => $alerts,
            'tables' => [
                ['title' => 'Derniers prospects', 'columns' => ['Nom', 'Société', 'Statut'], 'rows' => array_map(static fn(array $l): array => [$l['name'], $l['company'] ?: '—', $l['status']], $leads)],
                ['title' => 'Clients à risque', 'columns' => ['Client', 'Dernier envoi', 'Solde'], 'rows' => array_map(static fn(array $r): array => [$r['name'], $r['last_msg'] ?: 'Jamais', Money::format((int) $r['avail'], (string) ($r['currency'] ?: 'USD'))], $risk)],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function support(int $userId): array
    {
        $recent = Database::select("SELECT t.id, t.subject, t.priority, t.status, o.name org FROM tickets t JOIN organizations o ON o.id = t.organization_id WHERE t.status IN ('open','pending') ORDER BY FIELD(t.priority,'high','normal','low'), t.id DESC LIMIT 8");
        return [
            'kpis' => [
                ['label' => 'Tickets ouverts', 'value' => self::n("SELECT COUNT(*) FROM tickets WHERE status = 'open'"), 'href' => '/tickets', 'perm' => 'manage_tickets'],
                ['label' => 'Non assignés', 'value' => self::n("SELECT COUNT(*) FROM tickets WHERE assigned_to IS NULL AND status IN ('open','pending')"), 'href' => '/tickets', 'perm' => 'manage_tickets'],
                ['label' => 'Mes tickets', 'value' => self::n("SELECT COUNT(*) FROM tickets WHERE assigned_to = ? AND status IN ('open','pending')", [$userId])],
                ['label' => 'Priorité haute', 'value' => self::n("SELECT COUNT(*) FROM tickets WHERE priority = 'high' AND status IN ('open','pending')")],
                ['label' => 'Résolus (7 j)', 'value' => self::n("SELECT COUNT(*) FROM tickets WHERE status IN ('resolved','closed') AND updated_at >= ?", [self::since(7)])],
            ],
            'alerts' => [],
            'tables' => [['title' => 'Tickets à traiter', 'columns' => ['Sujet', 'Client', 'Priorité', 'Statut'], 'rows' => array_map(static fn(array $t): array => [$t['subject'], $t['org'], $t['priority'], $t['status']], $recent)]],
        ];
    }

    /** @return array<string,mixed> */
    private static function client(int $org): array
    {
        $d30 = self::since(30);
        $w = Database::first('SELECT balance_micro, held_micro, low_balance_micro, currency FROM wallets WHERE organization_id = ?', [$org]) ?? ['balance_micro' => 0, 'held_micro' => 0, 'low_balance_micro' => 0, 'currency' => (string) Config::get('billing.default_currency', 'USD')];
        $avail = (int) $w['balance_micro'] - (int) $w['held_micro'];
        $m = Database::first("SELECT COUNT(*) total, SUM(status = 'delivered') d, SUM(status = 'failed') f FROM messages WHERE organization_id = ? AND created_at >= ?", [$org, $d30]) ?? [];
        $approved = self::n("SELECT COUNT(*) FROM sender_ids WHERE organization_id = ? AND status = 'approved' AND deleted_at IS NULL", [$org]);
        $alerts = [];
        if ($w['low_balance_micro'] > 0 && $avail <= (int) $w['low_balance_micro']) {
            $alerts[] = ['level' => 'warn', 'text' => 'Votre solde est bas : rechargez pour ne pas interrompre vos envois.', 'href' => '/billing', 'perm' => 'view_wallet'];
        }
        if ($approved === 0) {
            $alerts[] = ['level' => 'info', 'text' => 'Aucun Sender ID approuvé : demandez-en un pour pouvoir envoyer.', 'href' => '/sender-ids', 'perm' => 'view_sender_ids'];
        }
        if (ProviderRouter::isSimulation()) {
            $alerts[] = ['level' => 'info', 'text' => 'Plateforme en phase de test : les messages sont simulés et aucun SMS réel n’est envoyé pour l’instant.', 'href' => null];
        }
        $camp = Database::select('SELECT id, name, status FROM campaigns WHERE organization_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 5', [$org]);
        $msgs = Database::select('SELECT to_e164, sender_name, status, created_at FROM messages WHERE organization_id = ? ORDER BY id DESC LIMIT 6', [$org]);
        return [
            'kpis' => [
                ['label' => 'Solde disponible', 'value' => Money::format($avail, (string) $w['currency']), 'sub' => 'Recharger', 'href' => '/billing', 'perm' => 'view_wallet'],
                ['label' => 'Messages (30 j)', 'value' => (int) ($m['total'] ?? 0), 'href' => '/messages', 'perm' => 'view_messages'],
                ['label' => 'Taux de livraison', 'value' => self::rate((int) ($m['d'] ?? 0), (int) ($m['f'] ?? 0)), 'unit' => '%', 'sub' => '30 derniers jours'],
                ['label' => 'Campagnes actives', 'value' => self::n("SELECT COUNT(*) FROM campaigns WHERE organization_id = ? AND deleted_at IS NULL AND status IN ('running','launching','scheduled')", [$org]), 'href' => '/campaigns', 'perm' => 'view_campaigns'],
                ['label' => 'Sender IDs approuvés', 'value' => $approved, 'href' => '/sender-ids', 'perm' => 'view_sender_ids'],
                ['label' => 'Tickets ouverts', 'value' => self::n("SELECT COUNT(*) FROM tickets WHERE organization_id = ? AND status IN ('open','pending')", [$org]), 'href' => '/tickets', 'perm' => 'create_tickets'],
            ],
            'alerts' => $alerts,
            'tables' => [
                ['title' => 'Dernières campagnes', 'columns' => ['Campagne', 'Statut'], 'rows' => array_map(static fn(array $c): array => [$c['name'], $c['status']], $camp)],
                ['title' => 'Derniers messages', 'columns' => ['Destinataire', 'Expéditeur', 'Statut'], 'rows' => array_map(static fn(array $x): array => [$x['to_e164'], $x['sender_name'], $x['status']], $msgs)],
            ],
        ];
    }
}
