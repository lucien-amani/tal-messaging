<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Controllers;

use App\Controllers\ModuleBase;
use App\Core\ApiException;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Messaging\SmsText;
use App\Modules\Messaging\Repositories\BatchRepository;
use App\Modules\Messaging\Repositories\MessageRepository;
use App\Modules\Messaging\Services\MessageService;
use App\Tenancy\TenantContext;

final class MessageController extends ModuleBase
{
    private const SEND = [
        'body' => 'required|string|max:1600', 'sender_id_id' => 'required|int', 'numbers' => 'string|max:200000',
        'group_ids' => 'array', 'segment_ids' => 'array', 'scheduled_at' => 'string|max:40', 'idempotency_key' => 'string|max:64',
    ];
    private const STATUSES = ['queued', 'sending', 'sent', 'delivered', 'failed', 'cancelled'];

    /** @param array<string,string> $params */
    public function quickSendPage(Request $request, array $params = []): Response
    {
        return $this->render($request, 'messaging/quick-send', [
            'title' => 'Quick Send', 'section' => 'quick_send', 'scripts' => ['js/ui.js', 'js/quick-send.js'],
            'limits' => ['recipients' => (int) Config::get('messaging.max_recipients_per_send'), 'body' => (int) Config::get('messaging.max_body_chars'),
                'scheduleDays' => (int) Config::get('messaging.max_schedule_days'), 'scheduleMinutes' => (int) Config::get('messaging.min_schedule_minutes')],
            'provider' => \App\Modules\Providers\Services\ProviderRouter::isSimulation() ? 'simulator' : 'live',
        ]);
    }

    /** @param array<string,string> $params */
    public function centerPage(Request $request, array $params = []): Response
    {
        return $this->render($request, 'messaging/messages', [
            'title' => 'Message Center', 'section' => 'messages', 'scripts' => ['js/ui.js', 'js/messages.js'],
            'canSend' => $request->can('send_messages'),
        ]);
    }

    /** @param array<string,string> $params */
    public function preview(Request $request, array $params): Response
    {
        $request->attributes['audit_no_input'] = true;
        $d = Validator::validate($request->body, self::SEND);
        return Response::data(MessageService::preview($this->targetOrg($request), $d + ['body' => $d['body']]));
    }

    /** @param array<string,string> $params */
    public function send(Request $request, array $params): Response
    {
        $request->attributes['audit_no_input'] = true; // le texte et les numéros ne vont pas dans le journal d'audit
        $d = Validator::validate($request->body, self::SEND);
        $orgId = $this->targetOrg($request);
        $res = MessageService::send($orgId, $d, (int) $request->user()['id']);
        $request->attributes['audit_resource_type'] = 'message_batch';
        $request->attributes['audit_resource_id'] = $res['batch']['id'];
        $request->attributes['audit_org'] = $orgId;
        return Response::data($res, $res['replayed'] ? 200 : 201);
    }

    /** @param array<string,string> $params */
    public function analyze(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, ['body' => 'string|max:5000']);
        return Response::data(SmsText::analyze((string) ($d['body'] ?? '')));
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request, 25);
        $where = '1 = 1';
        $p = [];
        $status = (string) $request->query('status', '');
        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw ApiException::validation(['status' => ['Statut inconnu.']]);
            }
            $where .= ' AND m.status = ?';
            $p[] = $status;
        }
        if (($b = filter_var($request->query('batch_id'), FILTER_VALIDATE_INT)) !== false && $b > 0) {
            $where .= ' AND m.batch_id = ?';
            $p[] = $b;
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q);
            $where .= $digits !== '' && strlen($digits) >= 4 ? ' AND m.to_e164 LIKE ?' : ' AND m.body LIKE ?';
            $p[] = $digits !== '' && strlen($digits) >= 4 ? '%' . $digits . '%' : '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
        }
        foreach (['date_from' => '>=', 'date_to' => '<'] as $k => $op) {
            $v = (string) $request->query($k, '');
            if ($v !== '' && ($ts = strtotime($v)) !== false) {
                $where .= " AND m.created_at $op ?";
                $p[] = gmdate('Y-m-d H:i:s', $k === 'date_to' ? $ts + 86400 : $ts);
            }
        }
        if (TenantContext::current()->global && ($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND m.organization_id = ?';
            $p[] = $o;
        }
        return Response::json((new MessageRepository())->paginate($where, $p, $page, $per, (string) $request->query('sort', 'id'), (string) $request->query('dir', 'desc')));
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        return Response::data((new MessageRepository())->find((int) $params['id']) ?? throw ApiException::notFound('Message introuvable.'));
    }

    /** @param array<string,string> $params */
    public function stats(Request $request, array $params): Response
    {
        return Response::data(MessageService::stats());
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $m = MessageService::cancelMessage((int) $params['id']);
        $request->attributes['audit_resource_type'] = 'message';
        $request->attributes['audit_resource_id'] = $m['id'];
        $request->attributes['audit_org'] = $m['organization_id'];
        return Response::data($m);
    }

    /** @param array<string,string> $params */
    public function batches(Request $request, array $params): Response
    {
        [$page, $per] = $this->paging($request, 25);
        $where = '1 = 1';
        $p = [];
        if (TenantContext::current()->global && ($o = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND b.organization_id = ?';
            $p[] = $o;
        }
        $res = (new BatchRepository())->paginate($where, $p, $page, $per, 'id', 'desc');
        $res['data'] = array_map([MessageService::class, 'presentBatch'], $res['data']);
        return Response::json($res);
    }

    /** @param array<string,string> $params */
    public function batch(Request $request, array $params): Response
    {
        return Response::data(MessageService::batch((int) $params['id']));
    }

    /** @param array<string,string> $params */
    public function cancelBatch(Request $request, array $params): Response
    {
        $r = MessageService::cancelBatch((int) $params['id']);
        $request->attributes['audit_resource_type'] = 'message_batch';
        $request->attributes['audit_resource_id'] = (int) $params['id'];
        return Response::data($r);
    }
}
