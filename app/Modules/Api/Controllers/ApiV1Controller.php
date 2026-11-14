<?php
declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Controllers\Controller;
use App\Core\ApiException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Messaging\Services\MessageService;
use App\Support\Money;
use App\Tenancy\TenantContext;

/** API publique v1 (clé API, sans session). Toutes les requêtes sont bornées à l'organisation de la clé. */
final class ApiV1Controller extends Controller
{
    private function org(): int
    {
        return (int) TenantContext::current()->organizationId;
    }

    /** @param array<string,string> $params */
    public function send(Request $request, array $params): Response
    {
        $org = $this->org();
        $in = $request->body;
        $to = $in['to'] ?? '';
        $numbers = is_array($to) ? implode(',', array_map('strval', $to)) : (string) $to;
        $from = trim((string) ($in['from'] ?? ''));
        if ($numbers === '' || $from === '') {
            throw ApiException::validation(array_filter(['to' => $numbers === '' ? ['Champ « to » requis (numéro ou liste).'] : null, 'from' => $from === '' ? ['Champ « from » requis (Sender ID approuvé).'] : null]));
        }
        $sender = Database::first('SELECT id FROM sender_ids WHERE organization_id = ? AND name = ? AND status = ? AND deleted_at IS NULL', [$org, $from, 'approved']);
        if (!$sender) {
            throw ApiException::validation(['from' => ['Sender ID inconnu ou non approuvé pour votre compte.']]);
        }
        $idem = $request->header('idempotency-key') ?: ($in['idempotency_key'] ?? null);
        $r = MessageService::send($org, [
            'numbers' => $numbers, 'sender_id_id' => (int) $sender['id'], 'body' => (string) ($in['body'] ?? ''),
            'scheduled_at' => $in['scheduled_at'] ?? null, 'idempotency_key' => $idem,
        ], (int) $request->user()['id'], ['limit' => 1000, 'source' => 'quick_send']);
        $b = $r['batch'];
        $s = $r['summary'];
        return Response::data([
            'id' => $b['uuid'], 'status' => $b['scheduled_at'] ? 'scheduled' : 'queued', 'replayed' => $r['replayed'],
            'recipients' => (int) $b['total_messages'], 'segments' => (int) $b['total_segments'],
            'cost' => Money::format((int) $b['cost_micro'], (string) BillingService::wallet($org)['currency']), 'cost_micro' => (int) $b['cost_micro'],
            'rejected' => $s ? ['invalid' => $s['invalid'], 'blocked' => $s['blocked'], 'unpriced' => $s['unpriced']] : null,
        ], $r['replayed'] ? 200 : 202);
    }

    /** @param array<string,string> $params */
    public function message(Request $request, array $params): Response
    {
        $m = Database::first('SELECT uuid, to_e164, sender_name, status, segments, encoding, error_code, error_message, scheduled_at, sent_at, delivered_at, created_at, cost_micro FROM messages WHERE organization_id = ? AND uuid = ?', [$this->org(), $params['id']])
            ?? throw ApiException::notFound('Message introuvable.');
        return Response::data([
            'id' => $m['uuid'], 'to' => $m['to_e164'], 'from' => $m['sender_name'], 'status' => $m['status'], 'segments' => (int) $m['segments'], 'encoding' => $m['encoding'],
            'error' => $m['error_code'] ? ['code' => $m['error_code'], 'message' => $m['error_message']] : null,
            'scheduled_at' => $m['scheduled_at'], 'sent_at' => $m['sent_at'], 'delivered_at' => $m['delivered_at'], 'created_at' => $m['created_at'], 'cost_micro' => (int) $m['cost_micro'],
        ]);
    }

    /** @param array<string,string> $params */
    public function batch(Request $request, array $params): Response
    {
        $b = Database::first('SELECT * FROM message_batches WHERE organization_id = ? AND uuid = ?', [$this->org(), $params['id']]) ?? throw ApiException::notFound('Envoi introuvable.');
        $b = MessageService::presentBatch($b);
        return Response::data([
            'id' => $b['uuid'], 'from' => $b['sender_name'], 'total' => $b['total_messages'], 'queued' => $b['n_queued'], 'sending' => $b['n_sending'], 'sent' => $b['n_sent'],
            'delivered' => $b['n_delivered'], 'failed' => $b['n_failed'], 'cancelled' => $b['n_cancelled'], 'scheduled_at' => $b['scheduled_at'], 'created_at' => $b['created_at'],
        ]);
    }

    /** @param array<string,string> $params */
    public function balance(Request $request, array $params): Response
    {
        $w = BillingService::wallet($this->org());
        return Response::data([
            'currency' => $w['currency'], 'available' => Money::format((int) $w['available_micro'], $w['currency']), 'available_micro' => (int) $w['available_micro'],
        ]);
    }
}
