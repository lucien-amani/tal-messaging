<?php
declare(strict_types=1);

namespace App\Modules\Support\Controllers;

use App\Controllers\ModuleBase;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Support\Services\TicketService;

final class TicketController extends ModuleBase
{
    /** @param array<string,string> $params */
    public function page(Request $request, array $params = []): Response
    {
        $team = $request->can('manage_tickets');
        $agents = $team ? Database::select("SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name FROM users u WHERE u.organization_id IS NULL AND u.status = 'active' AND u.deleted_at IS NULL ORDER BY u.first_name") : [];
        return $this->render($request, 'support/tickets', [
            'title' => $team ? 'Tickets support' : 'Assistance', 'section' => 'tickets', 'scripts' => ['js/ui.js', 'js/tickets.js'],
            'cfg' => ['team' => $team, 'canCreate' => $request->can('create_tickets'), 'categories' => TicketService::CATEGORIES, 'statuses' => TicketService::STATUSES, 'agents' => $agents,
                'me' => (int) $request->user()['id']],
        ]);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request);
        return Response::json(TicketService::list($request->query, $page, $per, (int) $request->user()['id'], $request->can('manage_tickets')));
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $t = TicketService::find((int) $params['id'], $request->can('manage_tickets'));
        $request->attributes['audit_org'] = (int) $t['organization_id'];
        return Response::data($t);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params): Response
    {
        $org = $this->targetOrg($request);
        $t = TicketService::create($org, (int) $request->user()['id'], $request->body);
        $this->mark($request, (int) $t['id'], $org);
        return Response::data($t, 201);
    }

    /** @param array<string,string> $params */
    public function reply(Request $request, array $params): Response
    {
        $team = $request->can('manage_tickets');
        $t = TicketService::reply((int) $params['id'], (int) $request->user()['id'], (string) ($request->body['body'] ?? ''), !empty($request->body['internal']), $team);
        $this->mark($request, (int) $t['id'], (int) $t['organization_id']);
        return Response::data($t);
    }

    /** @param array<string,string> $params */
    public function status(Request $request, array $params): Response
    {
        $t = TicketService::setStatus((int) $params['id'], (string) ($request->body['status'] ?? ''), $request->can('manage_tickets'));
        $this->mark($request, (int) $t['id'], (int) $t['organization_id']);
        return Response::data($t);
    }

    /** @param array<string,string> $params */
    public function assign(Request $request, array $params): Response
    {
        $raw = $request->body['assigned_to'] ?? null;
        $t = TicketService::assign((int) $params['id'], $raw === null || $raw === '' ? null : (int) $raw);
        $this->mark($request, (int) $t['id'], (int) $t['organization_id']);
        return Response::data($t);
    }

    /** @param array<string,string> $params */
    public function search(Request $request, array $params): Response
    {
        return Response::data(TicketService::search((string) $request->query('q', '')));
    }

    private function mark(Request $request, int $id, int $org): void
    {
        $request->attributes['audit_resource_type'] = 'ticket';
        $request->attributes['audit_resource_id'] = $id;
        $request->attributes['audit_org'] = $org;
        $request->attributes['audit_no_input'] = true; // le contenu des échanges n'entre pas dans le journal
    }
}
