<?php
declare(strict_types=1);

namespace App\Modules\Billing\Controllers;

use App\Controllers\ModuleBase;
use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Billing\Services\PricingService;
use App\Support\Money;
use App\Tenancy\TenantContext;

final class BillingController extends ModuleBase
{
    /** @param array<string,string> $params */
    public function page(Request $request, array $params = []): Response
    {
        return $this->render($request, 'billing/index', [
            'title' => 'Wallet', 'section' => 'billing', 'scripts' => ['js/ui.js', 'js/billing.js'],
            'canTopup' => $request->can('manage_billing'), 'canApprove' => $request->can('approve_payments'), 'canAdjust' => $request->can('adjust_wallets'),
            'canPricing' => $request->can('manage_pricing'),
            'countries' => array_map(static fn(array $c): string => (string) $c['name'], (array) Config::get('countries.countries')),
            'methods' => (array) Config::get('billing.methods'), 'minTopup' => (int) Config::get('billing.min_topup_micro'),
        ]);
    }

    /** @param array<string,string> $params */
    public function pricingPage(Request $request, array $params = []): Response
    {
        return $this->render($request, 'billing/pricing', [
            'title' => 'Tarifs', 'section' => 'pricing', 'scripts' => ['js/ui.js', 'js/pricing.js'],
            'countries' => array_map(static fn(array $c): string => (string) $c['name'], (array) Config::get('countries.countries')),
            'currencies' => (array) Config::get('billing.currencies'),
        ]);
    }

    /** @param array<string,string> $params */
    public function invoicePage(Request $request, array $params): Response
    {
        $inv = Database::first('SELECT i.*, o.name AS organization_name FROM invoices i JOIN organizations o ON o.id = i.organization_id WHERE i.id = ?', [(int) $params['id']]);
        $scope = BillingService::scopeOrg(null);
        if (!$inv || ($scope !== null && (int) $inv['organization_id'] !== $scope)) {
            throw ApiException::notFound('Facture introuvable.');
        }
        return $this->view('billing/invoice', ['title' => 'Facture ' . $inv['number'], 'inv' => $inv, 'issuer' => (array) Config::get('billing.issuer')], 'layouts/print');
    }

    /** @param array<string,string> $params */
    public function wallet(Request $request, array $params): Response
    {
        $w = BillingService::summary($this->targetOrg($request));
        $w['org_id'] = $w['organization_id'];
        return Response::data($w);
    }

    /** Vue d'ensemble des wallets de tous les clients (interne global uniquement). @param array<string,string> $params */
    public function wallets(Request $request, array $params): Response
    {
        if (!TenantContext::current()->global) {
            throw ApiException::forbidden('Réservé à l’équipe TAL.');
        }
        [$page, $per] = $this->paging($request, 25);
        $where = 'o.deleted_at IS NULL';
        $p = [];
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $where .= ' AND o.name LIKE ?';
            $p[] = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
        }
        $total = (int) Database::scalar("SELECT COUNT(*) FROM organizations o WHERE $where", $p);
        $rows = Database::select(
            "SELECT o.id AS organization_id, o.name AS organization_name, COALESCE(w.currency, 'USD') AS currency, COALESCE(w.balance_micro, 0) AS balance_micro,
                    COALESCE(w.held_micro, 0) AS held_micro, COALESCE(w.low_balance_micro, 0) AS low_balance_micro
             FROM organizations o LEFT JOIN wallets w ON w.organization_id = o.id WHERE $where ORDER BY o.name LIMIT " . $per . ' OFFSET ' . (($page - 1) * $per),
            $p
        );
        return Response::json(['data' => array_map([BillingService::class, 'present'], $rows), 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total]]);
    }

    /** @param array<string,string> $params */
    public function transactions(Request $request, array $params): Response
    {
        $type = (string) $request->query('type', '');
        $extra = '';
        $p = [];
        if (in_array($type, ['topup', 'adjustment', 'charge'], true)) {
            $extra = ' AND t.type = ?';
            $p[] = $type;
        }
        return $this->listing($request, 'wallet_transactions t JOIN organizations o ON o.id = t.organization_id', 't', 't.id, t.organization_id, o.name AS organization_name, t.type, t.amount_micro, t.balance_after_micro, t.currency, t.reference_type, t.reference_id, t.message_count, t.description, t.created_at', $extra, $p, 't.id DESC');
    }

    /** @param array<string,string> $params */
    public function payments(Request $request, array $params): Response
    {
        $status = (string) $request->query('status', '');
        $extra = '';
        $p = [];
        if (in_array($status, ['pending', 'confirmed', 'rejected', 'cancelled'], true)) {
            $extra = ' AND t.status = ?';
            $p[] = $status;
        }
        return $this->listing($request, 'payments t JOIN organizations o ON o.id = t.organization_id LEFT JOIN invoices i ON i.id = t.invoice_id', 't',
            't.id, t.organization_id, o.name AS organization_name, t.amount_micro, t.currency, t.method, t.reference, t.note, t.status, t.reject_reason, t.invoice_id, i.number AS invoice_number, t.created_at, t.reviewed_at', $extra, $p, 't.id DESC');
    }

    /** @param array<string,string> $params */
    public function invoices(Request $request, array $params): Response
    {
        return $this->listing($request, 'invoices t JOIN organizations o ON o.id = t.organization_id', 't',
            't.id, t.organization_id, o.name AS organization_name, t.number, t.description, t.amount_micro, t.currency, t.issued_at', '', [], 't.id DESC');
    }

    /** Tarifs applicables à l'organisation (lecture). @param array<string,string> $params */
    public function pricing(Request $request, array $params): Response
    {
        $org = $this->targetOrg($request);
        $w = BillingService::wallet($org);
        return Response::data(['currency' => $w['currency'], 'rules' => PricingService::effective($org, $w['currency'])]);
    }

    /** @param array<string,string> $params */
    public function requestTopup(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, ['amount' => 'required', 'method' => 'string|max:20', 'reference' => 'required|string|max:80', 'note' => 'string|max:255']);
        $org = $this->targetOrg($request);
        $p = PaymentService::request($org, $d, (int) $request->user()['id']);
        $this->audit($request, 'payment', (int) $p['id'], $org);
        return Response::data($p, 201);
    }

    /** @param array<string,string> $params */
    public function cancelPayment(Request $request, array $params): Response
    {
        $p = PaymentService::cancel((int) $params['id']);
        $this->audit($request, 'payment', (int) $p['id'], (int) $p['organization_id']);
        return Response::data($p);
    }

    /** @param array<string,string> $params */
    public function confirmPayment(Request $request, array $params): Response
    {
        $p = PaymentService::confirm((int) $params['id'], (int) $request->user()['id']);
        $this->audit($request, 'payment', (int) $p['id'], (int) $p['organization_id']);
        return Response::data($p);
    }

    /** @param array<string,string> $params */
    public function rejectPayment(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, ['reason' => 'required|string|min:5|max:255']);
        $p = PaymentService::reject((int) $params['id'], $d['reason'], (int) $request->user()['id']);
        $this->audit($request, 'payment', (int) $p['id'], (int) $p['organization_id']);
        return Response::data($p);
    }

    /** @param array<string,string> $params */
    public function adjust(Request $request, array $params): Response
    {
        $d = Validator::validate($request->body, ['amount' => 'required', 'note' => 'required|string|min:5|max:200']);
        $org = $this->targetOrg($request);
        $sign = str_starts_with(trim((string) $d['amount']), '-') ? -1 : 1;
        $micro = Money::parse(ltrim(trim((string) $d['amount']), '+-'));
        if ($micro === null || $micro <= 0 || $micro > (int) Config::get('billing.max_topup_micro')) {
            throw ApiException::validation(['amount' => ['Montant invalide (exemple : 25 ou -5.50).']]);
        }
        $w = BillingService::adjust($org, $sign * $micro, $d['note'], (int) $request->user()['id']);
        $this->audit($request, 'wallet', (int) $w['id'], $org);
        return Response::data(BillingService::summary($org));
    }

    /** @param array<string,string> $params */
    public function pricingRules(Request $request, array $params): Response
    {
        $org = filter_var($request->query('organization_id'), FILTER_VALIDATE_INT);
        return Response::data(PricingService::all($org === false ? null : $org));
    }

    /** @param array<string,string> $params */
    public function pricingSave(Request $request, array $params): Response
    {
        $r = PricingService::save(isset($params['id']) ? (int) $params['id'] : null, $request->body, (int) $request->user()['id']);
        $this->audit($request, 'pricing_rule', (int) $r['id'], $r['organization_id'] !== null ? (int) $r['organization_id'] : null);
        return Response::data($r, isset($params['id']) ? 200 : 201);
    }

    /** @param array<string,string> $params */
    public function pricingDelete(Request $request, array $params): Response
    {
        PricingService::delete((int) $params['id']);
        $this->audit($request, 'pricing_rule', (int) $params['id'], null);
        return Response::data(['deleted' => true]);
    }

    /**
     * Liste paginée d'une table de facturation, toujours bornée à l'organisation du client
     * (jamais lue depuis l'entrée) ; l'interne global peut filtrer par organization_id.
     * @param array<int,mixed> $extraParams
     */
    private function listing(Request $request, string $from, string $alias, string $select, string $extraWhere, array $extraParams, string $order): Response
    {
        [$page, $per] = $this->paging($request, 25);
        $org = BillingService::scopeOrg(filter_var($request->query('organization_id'), FILTER_VALIDATE_INT) ?: null);
        $where = '1 = 1';
        $p = [];
        if ($org !== null) {
            $where .= " AND $alias.organization_id = ?";
            $p[] = $org;
        }
        $where .= $extraWhere;
        $p = array_merge($p, $extraParams);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM $from WHERE $where", $p);
        $rows = Database::select("SELECT $select FROM $from WHERE $where ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), $p);
        foreach ($rows as &$r) {
            foreach (['amount_micro', 'balance_after_micro'] as $k) {
                if (isset($r[$k])) {
                    $r[$k] = (int) $r[$k];
                }
            }
        }
        return Response::json(['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total]]);
    }

    private function audit(Request $request, string $type, int $id, ?int $org): void
    {
        $request->attributes['audit_resource_type'] = $type;
        $request->attributes['audit_resource_id'] = $id;
        if ($org !== null) {
            $request->attributes['audit_org'] = $org;
        }
    }
}
