<?php
/** @var array<string,mixed> $inv */
/** @var array<string,string> $issuer */
use App\Support\Money;
?>
<div class="mb-4 flex items-center justify-between gap-3 print:hidden">
  <a class="inline-flex items-center gap-1 text-sm text-mute hover:text-ink" href="<?= e(url('/billing')) ?>"><?= icon('arrow-left') ?>Wallet</a>
  <button type="button" class="btn btn-primary" id="print-btn"><?= icon('printer') ?>Imprimer / PDF</button>
</div>

<article class="card !p-8 print:border-0 print:p-0 print:shadow-none">
  <header class="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-6">
    <div>
      <p class="font-display text-2xl font-bold"><?= e($issuer['name']) ?></p>
      <p class="mt-1 text-sm text-mute"><?= e($issuer['address']) ?></p>
    </div>
    <div class="text-right">
      <p class="text-xs font-semibold uppercase tracking-wider text-mute">Facture</p>
      <p class="font-mono text-lg font-semibold"><?= e((string) $inv['number']) ?></p>
      <p class="mt-1 text-sm text-mute"><?= e(date('d/m/Y', strtotime((string) $inv['issued_at'] . ' UTC'))) ?></p>
    </div>
  </header>
  <section class="py-6">
    <p class="text-xs font-semibold uppercase tracking-wider text-mute">Facturé à</p>
    <p class="mt-1 text-base font-semibold"><?= e((string) $inv['customer_name']) ?></p>
  </section>
  <table class="w-full text-sm">
    <thead><tr class="border-b border-line text-left text-xs uppercase tracking-wider text-mute"><th class="py-2">Description</th><th class="py-2 text-right">Montant</th></tr></thead>
    <tbody><tr class="border-b border-line"><td class="py-3"><?= e((string) $inv['description']) ?></td><td class="py-3 text-right tabular-nums"><?= e(Money::format((int) $inv['amount_micro'], (string) $inv['currency'])) ?></td></tr></tbody>
    <tfoot><tr><td class="pt-4 text-right font-semibold">Total payé</td><td class="pt-4 text-right text-lg font-bold tabular-nums"><?= e(Money::format((int) $inv['amount_micro'], (string) $inv['currency'])) ?></td></tr></tfoot>
  </table>
  <p class="mt-8 text-xs text-mute">Crédits de messagerie versés sur votre wallet TAL Messaging. Facture acquittée : paiement reçu et confirmé.</p>
</article>
<script src="<?= asset('js/invoice.js') ?>" defer></script>
