<?php
declare(strict_types=1);

namespace App\Modules\SenderIds\Controllers;

use App\Controllers\ModuleBase;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Modules\SenderIds\Repositories\SenderIdRepository;
use App\Modules\SenderIds\Services\SenderIdService;
use App\Tenancy\TenantContext;

final class SenderIdController extends ModuleBase
{
    private const INPUT = [
        'name' => 'required|string|max:40',
        'country' => 'string|size:2',
        'purpose' => 'string|max:20',
        'description' => 'string|max:500',
        'sample_message' => 'string|max:320',
    ];

    /** @param array<string,string> $params */
    public function page(Request $request, array $params = []): Response
    {
        return $this->render($request, 'senderids/index', [
            'title' => 'Sender IDs',
            'section' => 'sender_ids',
            'scripts' => ['js/ui.js', 'js/senderids.js'],
            'canManage' => $request->can('manage_sender_ids'),
            'canReview' => $request->can('review_sender_ids'),
            'countries' => (array) Config::get('countries.countries'),
            'purposes' => (array) Config::get('sender_ids.purposes'),
            'limits' => ['min' => (int) Config::get('sender_ids.min_length'), 'max' => (int) Config::get('sender_ids.max_length')],
            'defaultCountry' => (string) Config::get('sender_ids.default_country'),
        ]);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request, 25);
        $where = '1 = 1';
        $p = [];
        $status = $request->query('status');
        if (in_array($status, ['pending', 'approved', 'rejected', 'suspended'], true)) {
            $where .= ' AND s.status = ?';
            $p[] = $status;
        }
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $where .= ' AND s.name LIKE ?';
            $p[] = '%' . addcslashes(trim($q), '%_\\') . '%';
        }
        if (TenantContext::current()->global && ($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND s.organization_id = ?';
            $p[] = $o;
        }
        $res = (new SenderIdRepository())->paginate($where, $p, $page, $per, (string) $request->query('sort', 'created_at'), (string) $request->query('dir', 'desc'));
        $res['data'] = array_map([SenderIdRepository::class, 'present'], $res['data']);
        return Response::json($res);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        return Response::data(SenderIdService::one((int) $params['id']));
    }

    /** @param array<string,string> $params */
    public function events(Request $request, array $params): Response
    {
        return Response::data(SenderIdService::events((int) $params['id']));
    }

    /** @param array<string,string> $params */
    public function approved(Request $request, array $params): Response
    {
        $org = TenantContext::current()->global ? (filter_var($request->query('organization_id'), FILTER_VALIDATE_INT) ?: null) : null;
        return Response::data(SenderIdService::approved($org));
    }

    /** @param array<string,string> $params */
    public function stats(Request $request, array $params): Response
    {
        return Response::data(SenderIdService::stats());
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, self::INPUT);
        $orgId = $this->targetOrg($request);
        $s = SenderIdService::request($d, $orgId, (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'sender_id';
        $request->attributes['audit_resource_id'] = $s['id'];
        $request->attributes['audit_org'] = $orgId;
        return Response::data($s, 201);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $rules = self::INPUT;
        $rules['name'] = 'string|max:40';
        $s = SenderIdService::update((int) $params['id'], Validator::validate($request->body, $rules), (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'sender_id';
        $request->attributes['audit_org'] = $s['organization_id'];
        return Response::data($s);
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        SenderIdService::delete((int) $params['id'], (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'sender_id';
        return Response::data(['deleted' => true]);
    }

    /** @param array<string,string> $params */
    public function setDefault(Request $request, array $params): Response
    {
        $s = SenderIdService::setDefault((int) $params['id'], (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'sender_id';
        $request->attributes['audit_org'] = $s['organization_id'];
        return Response::data($s);
    }

    /** Décision de TAL : approve | reject | suspend | reactivate (permission review_sender_ids). @param array<string,string> $params */
    public function review(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, ['decision' => 'required|in:approve,reject,suspend,reactivate', 'reason' => 'string|max:255']);
        $s = SenderIdService::review((int) $params['id'], $d['decision'], $d['reason'] ?? null, (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'sender_id';
        $request->attributes['audit_org'] = $s['organization_id'];
        return Response::data($s);
    }
}
