<?php
declare(strict_types=1);

namespace App\Modules\Campaigns\Controllers;

use App\Controllers\ModuleBase;
use App\Core\ApiException;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Modules\Campaigns\Repositories\CampaignRepository;
use App\Modules\Campaigns\Repositories\TemplateRepository;
use App\Modules\Campaigns\Services\CampaignService;
use App\Modules\Campaigns\Services\TemplateService;
use App\Tenancy\TenantContext;

final class CampaignController extends ModuleBase
{
    private const INPUT = [
        'name' => 'required|string|min:1|max:120', 'body' => 'required|string|min:1|max:1600', 'sender_id_id' => 'int',
        'group_ids' => 'array', 'segment_ids' => 'array', 'scheduled_at' => 'string|max:40',
    ];
    private const STATUSES = ['draft', 'launching', 'scheduled', 'running', 'completed', 'cancelled'];

    /** @param array<string,string> $params */
    public function indexPage(Request $request, array $params = []): Response
    {
        return $this->render($request, 'campaigns/index', ['title' => 'Campagnes', 'section' => 'campaigns', 'scripts' => ['js/ui.js', 'js/campaigns.js'], 'canManage' => $request->can('manage_campaigns')]);
    }

    /** @param array<string,string> $params */
    public function formPage(Request $request, array $params = []): Response
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id > 0) {
            (new CampaignRepository())->find($id) ?? throw ApiException::notFound('Campagne introuvable.');
        }
        return $this->render($request, 'campaigns/form', ['title' => $id ? 'Modifier la campagne' : 'Nouvelle campagne', 'section' => 'campaigns', 'scripts' => ['js/ui.js', 'js/campaign-form.js'],
            'campaignId' => $id, 'maxRecipients' => (int) Config::get('campaigns.max_recipients'),
            'limits' => ['body' => (int) Config::get('messaging.max_body_chars'), 'scheduleDays' => (int) Config::get('messaging.max_schedule_days'), 'scheduleMinutes' => (int) Config::get('messaging.min_schedule_minutes')]]);
    }

    /** @param array<string,string> $params */
    public function showPage(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        (new CampaignRepository())->find($id) ?? throw ApiException::notFound('Campagne introuvable.');
        return $this->render($request, 'campaigns/show', ['title' => 'Rapport de campagne', 'section' => 'campaigns', 'scripts' => ['js/ui.js', 'js/campaign-report.js'],
            'campaignId' => $id, 'canManage' => $request->can('manage_campaigns')]);
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request, 20);
        $where = '1 = 1';
        $p = [];
        $status = (string) $request->query('status', '');
        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw ApiException::validation(['status' => ['Statut inconnu.']]);
            }
            $where .= ' AND c.status = ?';
            $p[] = $status;
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $where .= ' AND c.name LIKE ?';
            $p[] = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
        }
        if (TenantContext::current()->global && ($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND c.organization_id = ?';
            $p[] = $o;
        }
        $res = (new CampaignRepository())->paginate($where, $p, $page, $per, (string) $request->query('sort', 'id'), (string) $request->query('dir', 'desc'));
        $res['data'] = array_map([CampaignService::class, 'present'], $res['data']);
        return Response::json($res);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        return Response::data(CampaignService::one((int) $params['id']));
    }

    /** @param array<string,string> $params */
    public function report(Request $request, array $params): Response
    {
        return Response::data(CampaignService::report((int) $params['id']));
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, self::INPUT);
        $orgId = $this->targetOrg($request);
        $c = CampaignService::create($d, $orgId, (int) $request->user()['id']);
        $this->audit($request, $c);
        return Response::data($c, 201);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params): Response
    {
        $rules = self::INPUT;
        $rules['name'] = 'string|min:1|max:120';
        $rules['body'] = 'string|min:1|max:1600';
        $c = CampaignService::update((int) $params['id'], Validator::validate($request->body, $rules));
        $this->audit($request, $c);
        return Response::data($c);
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params): Response
    {
        CampaignService::delete((int) $params['id']);
        $request->attributes['audit_resource_type'] = 'campaign';
        $request->attributes['audit_resource_id'] = (int) $params['id'];
        return Response::data(['deleted' => true]);
    }

    /** @param array<string,string> $params */
    public function duplicate(Request $request, array $params): Response
    {
        $c = CampaignService::duplicate((int) $params['id'], (int) $request->user()['id']);
        $this->audit($request, $c);
        return Response::data($c, 201);
    }

    /** @param array<string,string> $params */
    public function preview(Request $request, array $params): Response
    {
        $request->attributes['audit_no_input'] = true;
        $d = Validator::validate($request->body, ['body' => 'required|string|max:1600', 'sender_id_id' => 'required|int', 'group_ids' => 'array', 'segment_ids' => 'array', 'scheduled_at' => 'string|max:40']);
        return Response::data(CampaignService::preview($this->targetOrg($request), $d));
    }

    /** @param array<string,string> $params */
    public function launch(Request $request, array $params): Response
    {
        $c = CampaignService::launch((int) $params['id'], (int) $request->user()['id']);
        $this->audit($request, $c);
        return Response::data($c);
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $c = CampaignService::cancel((int) $params['id']);
        $this->audit($request, $c);
        return Response::data($c);
    }

    /** @param array<string,string> $params */
    public function export(Request $request, array $params): Response
    {
        $c = CampaignService::one((int) $params['id']);
        \App\Services\AuditService::log('campaign.export', 'campaign', (int) $c['id'], ['rows' => $c['total_recipients']], (int) $request->user()['id'], (int) $c['organization_id']);
        return $this->csv(CampaignService::exportCsv((int) $c['id']), 'campagne-' . $c['id'] . '-' . gmdate('Ymd-His') . '.csv');
    }

    /** @param array<string,string> $params */
    public function templates(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request, 50);
        $where = '1 = 1';
        $p = [];
        if (TenantContext::current()->global && ($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND t.organization_id = ?';
            $p[] = $o;
        }
        return Response::json((new TemplateRepository())->paginate($where, $p, $page, $per, 'name', 'asc'));
    }

    /** @param array<string,string> $params */
    public function templateStore(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, ['name' => 'required|string|min:1|max:100', 'body' => 'required|string|min:1|max:1600']);
        $orgId = $this->targetOrg($request);
        $t = TemplateService::create($d, $orgId, (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'message_template';
        $request->attributes['audit_resource_id'] = $t['id'];
        $request->attributes['audit_org'] = $orgId;
        return Response::data($t, 201);
    }

    /** @param array<string,string> $params */
    public function templateUpdate(Request $request, array $params): Response
    {
        $t = TemplateService::update((int) $params['id'], Validator::validate($request->body, ['name' => 'string|min:1|max:100', 'body' => 'string|min:1|max:1600']));
        $request->attributes['audit_resource_type'] = 'message_template';
        $request->attributes['audit_org'] = $t['organization_id'];
        return Response::data($t);
    }

    /** @param array<string,string> $params */
    public function templateDestroy(Request $request, array $params): Response
    {
        TemplateService::delete((int) $params['id']);
        $request->attributes['audit_resource_type'] = 'message_template';
        return Response::data(['deleted' => true]);
    }

    /** @param array<string,mixed> $c */
    private function audit(Request $request, array $c): void
    {
        $request->attributes['audit_no_input'] = true;
        $request->attributes['audit_resource_type'] = 'campaign';
        $request->attributes['audit_resource_id'] = $c['id'];
        $request->attributes['audit_org'] = $c['organization_id'];
    }
}
