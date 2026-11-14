<?php
declare(strict_types=1);

use App\Core\Router;
use App\Modules\Billing\Controllers\BillingController as B;

return static function (Router $r): void {
    $r->group(['audit', 'auth', 'tenant'], function (Router $r): void {
        $view = 'perm:view_wallet';

        $r->get('/billing', [B::class, 'page'], 'billing.page', [$view]);
        $r->get('/billing/pricing', [B::class, 'pricingPage'], 'billing.pricing_page', ['perm:manage_pricing']);
        $r->get('/billing/invoices/{id}', [B::class, 'invoicePage'], 'billing.invoice', [$view]);

        $r->get('/api/wallet', [B::class, 'wallet'], 'billing.wallet', [$view]);
        $r->get('/api/wallets', [B::class, 'wallets'], 'billing.wallets', [$view, 'perm:view_all_tenants']);
        $r->get('/api/wallet/transactions', [B::class, 'transactions'], 'billing.transactions', [$view]);
        $r->get('/api/payments', [B::class, 'payments'], 'billing.payments', [$view]);
        $r->get('/api/invoices', [B::class, 'invoices'], 'billing.invoices', [$view]);
        $r->get('/api/pricing', [B::class, 'pricing'], 'billing.pricing', [$view]);

        $r->post('/api/payments', [B::class, 'requestTopup'], 'billing.payment_request', ['csrf', 'perm:manage_billing']);
        $r->post('/api/payments/{id}/cancel', [B::class, 'cancelPayment'], 'billing.payment_cancel', ['csrf', 'perm:manage_billing']);
        $r->post('/api/payments/{id}/confirm', [B::class, 'confirmPayment'], 'billing.payment_confirm', ['csrf', 'perm:approve_payments']);
        $r->post('/api/payments/{id}/reject', [B::class, 'rejectPayment'], 'billing.payment_reject', ['csrf', 'perm:approve_payments']);
        $r->post('/api/wallet/adjust', [B::class, 'adjust'], 'billing.adjust', ['csrf', 'perm:adjust_wallets']);

        $r->get('/api/pricing-rules', [B::class, 'pricingRules'], 'billing.rules', ['perm:manage_pricing']);
        $r->post('/api/pricing-rules', [B::class, 'pricingSave'], 'billing.rule_create', ['csrf', 'perm:manage_pricing']);
        $r->put('/api/pricing-rules/{id}', [B::class, 'pricingSave'], 'billing.rule_update', ['csrf', 'perm:manage_pricing']);
        $r->delete('/api/pricing-rules/{id}', [B::class, 'pricingDelete'], 'billing.rule_delete', ['csrf', 'perm:manage_pricing']);
    });
};
