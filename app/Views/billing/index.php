<?php
/** @var array<string,string> $methods */
$cfg = [
    'countries' => $countries, 'global' => $global, 'canTopup' => $canTopup, 'canApprove' => $canApprove, 'canAdjust' => $canAdjust, 'methods' => $methods, 'minTopup' => $minTopup,
];
?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div>
    <h2 class="text-2xl font-bold md:text-3xl">Wallet</h2>
    <p class="mt-1 max-w-2xl text-sm text-mute">
      <?= $global ? 'Soldes, demandes de recharge et factures de vos clients.' : 'Votre solde, vos recharges, vos factures et les tarifs appliqués à vos envois.' ?>
    </p>
  </div>
  <div class="flex flex-wrap gap-2 self-start">
    <?php if ($canPricing): ?>
      <a class="btn btn-outline" href="<?= e(url('/billing/pricing')) ?>"><?= icon('currency-dollar') ?>Gérer les tarifs</a>
    <?php endif; ?>
    <?php if ($canTopup): ?>
      <button type="button" class="btn btn-primary" id="bl-topup"><?= icon('plus') ?>Recharger</button>
    <?php endif; ?>
    <?php if ($canAdjust): ?>
      <button type="button" class="btn btn-outline hidden" id="bl-adjust"><?= icon('adjustments') ?>Ajuster le solde</button>
    <?php endif; ?>
  </div>
</div>

<div id="bl-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">
  <?php if ($global): ?>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
      <select class="input sm:w-72" id="bl-org" aria-label="Organisation"><option value="">Tous les clients (vue d’ensemble)</option></select>
      <div class="field w-full sm:w-64" id="bl-search-wrap">
        <?= icon('search', 'field-icon') ?>
        <input class="input" type="search" id="bl-q" placeholder="Rechercher un client" autocomplete="off" aria-label="Rechercher un client">
      </div>
    </div>
    <div id="bl-overview" class="hidden">
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr><th>Client</th><th class="text-right">Solde</th><th class="hidden text-right sm:table-cell">Réservé</th><th class="text-right">Disponible</th></tr></thead>
          <tbody id="bl-ov-rows"></tbody>
        </table>
        <div id="bl-ov-state"></div>
      </div>
      <div id="bl-ov-pager"></div>
    </div>
  <?php endif; ?>

  <div id="bl-detail" class="<?= $global ? 'hidden' : '' ?>">
    <div id="bl-alert"></div>
    <div id="bl-kpis" class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-live="polite"></div>
    <div class="mb-4 tabs" id="bl-tabs" role="tablist" aria-label="Sections">
      <button type="button" class="tab" role="tab" data-tab="transactions">Transactions</button>
      <button type="button" class="tab" role="tab" data-tab="payments">Recharges</button>
      <button type="button" class="tab" role="tab" data-tab="invoices">Factures</button>
      <button type="button" class="tab" role="tab" data-tab="pricing">Tarifs</button>
    </div>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead id="bl-head"></thead>
        <tbody id="bl-rows"></tbody>
      </table>
      <div id="bl-state"></div>
    </div>
    <div id="bl-pager"></div>
  </div>
</div>
