<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Controllers\ModuleBase;
use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Platform\Services\DashboardService;
use App\Repositories\OrganizationRepository;
use App\Tenancy\TenantContext;

/** Pages transverses : données des dashboards, Équipe, Clients (fiche client). */
final class PlatformController extends ModuleBase
{
    /** @param array<string,string> $params */
    public function dashboard(Request $request, array $params): Response
    {
        $slug = $params['slug'];
        if (!$request->can('access_dashboard_' . $slug)) {
            throw ApiException::forbidden('Vous n’avez pas accès à ce dashboard.', 'PERMISSION_DENIED');
        }
        $org = null;
        if ($slug === 'client' && TenantContext::current()->global) {
            $org = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT);
            if ($org === false || !(new OrganizationRepository())->find((int) $org)) {
                throw ApiException::validation(['organization_id' => ['Choisissez une organisation pour voir son espace client.']]);
            }
        }
        return Response::data(DashboardService::build($slug, $request->user(), $org === false ? null : $org));
    }

    /** @param array<string,string> $params */
    public function teamPage(Request $request, array $params = []): Response
    {
        $u = $request->user();
        return $this->render($request, 'platform/team', [
            'title' => 'Équipe', 'section' => 'team', 'scripts' => ['js/ui.js', 'js/team.js'],
            'cfg' => ['global' => TenantContext::current()->global, 'me' => (int) $u['id'], 'internal' => $request->can('manage_internal_users')],
        ]);
    }

    /** @param array<string,string> $params */
    public function clientsPage(Request $request, array $params = []): Response
    {
        return $this->render($request, 'platform/clients', [
            'title' => 'Clients', 'section' => 'clients', 'scripts' => ['js/ui.js', 'js/clients.js'],
            'cfg' => ['canManage' => $request->can('manage_organizations'), 'countries' => array_map(static fn(array $c): string => (string) $c['name'], (array) Config::get('countries.countries')),
                'perms' => ['wallet' => $request->can('view_wallet'), 'tickets' => $request->can('manage_tickets'), 'team' => $request->can('manage_team'), 'impersonate' => $request->can('access_dashboard_client')]],
        ]);
    }

    /** Fiche client : toujours via le repository tenant (404 hors périmètre). @param array<string,string> $params */
    public function overview(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $org = (new OrganizationRepository())->find($id) ?? throw ApiException::notFound('Organisation introuvable.');
        $d30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $m = Database::first("SELECT COUNT(*) total, COALESCE(SUM(status = 'delivered'), 0) delivered, COALESCE(SUM(status = 'failed'), 0) failed, MAX(created_at) last_at FROM messages WHERE organization_id = ? AND created_at >= ?", [$id, $d30]) ?? [];
        $w = $request->can('view_wallet') ? BillingService::wallet($id) : null;
        $request->attributes['audit_org'] = $id;
        return Response::data([
            'organization' => $org,
            'wallet' => $w ? ['available_micro' => (int) $w['available_micro'], 'currency' => $w['currency']] : null,
            'messages_30d' => ['total' => (int) $m['total'], 'delivered' => (int) $m['delivered'], 'failed' => (int) $m['failed'], 'last_at' => $m['last_at']],
            'users' => (int) Database::scalar('SELECT COUNT(*) FROM users WHERE organization_id = ? AND deleted_at IS NULL', [$id]),
            'sender_ids' => Database::select("SELECT status, COUNT(*) c FROM sender_ids WHERE organization_id = ? AND deleted_at IS NULL GROUP BY status", [$id]),
            'campaigns' => (int) Database::scalar('SELECT COUNT(*) FROM campaigns WHERE organization_id = ? AND deleted_at IS NULL', [$id]),
            'open_tickets' => (int) Database::scalar("SELECT COUNT(*) FROM tickets WHERE organization_id = ? AND status IN ('open','pending')", [$id]),
            'api_keys' => (int) Database::scalar('SELECT COUNT(*) FROM api_keys WHERE organization_id = ? AND revoked_at IS NULL', [$id]),
        ]);
    }
}
