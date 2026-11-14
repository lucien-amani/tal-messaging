<?php
/** @var array<string,array<string,mixed>> $countries */
/** @var array<string,string> $purposes */
$cfg = [
    'canManage' => $canManage, 'canReview' => $canReview, 'global' => $global, 'limits' => $limits, 'defaultCountry' => $defaultCountry,
    'countries' => array_map(static fn(array $c): string => (string) $c['name'], $countries), 'purposes' => $purposes,
];
?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div>
    <h2 class="text-2xl font-bold md:text-3xl">Sender IDs</h2>
    <p class="mt-1 max-w-2xl text-sm text-mute">
      <?php if ($canReview): ?>
        Validez les noms d’expéditeur demandés par les clients. Le client est informé du motif en cas de rejet ou de suspension.
      <?php else: ?>
        Le nom qui s’affiche chez vos destinataires à la place du numéro. Chaque nom est validé par TAL avant son premier usage.
      <?php endif; ?>
    </p>
  </div>
  <?php if ($canManage): ?>
    <button type="button" class="btn btn-primary self-start" id="si-new"><?= icon('plus') ?>Demander un Sender ID</button>
  <?php endif; ?>
</div>

<div id="sender-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">
  <div id="si-kpis" class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-live="polite"></div>

  <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <div class="tabs self-start" id="si-tabs" role="tablist" aria-label="Filtrer par statut">
      <button type="button" class="tab" role="tab" data-status="">Tous</button>
      <button type="button" class="tab" role="tab" data-status="pending">En attente</button>
      <button type="button" class="tab" role="tab" data-status="approved">Approuvés</button>
      <button type="button" class="tab" role="tab" data-status="rejected">Rejetés</button>
      <button type="button" class="tab" role="tab" data-status="suspended">Suspendus</button>
    </div>
    <div class="flex flex-col gap-2 sm:flex-row">
      <?php if ($global): ?>
        <select class="input sm:w-56" id="si-org" aria-label="Organisation"><option value="">Toutes les organisations</option></select>
      <?php endif; ?>
      <div class="field w-full sm:w-64">
        <?= icon('search', 'field-icon') ?>
        <input class="input" type="search" id="si-q" placeholder="Rechercher un nom" autocomplete="off" aria-label="Rechercher un Sender ID">
      </div>
    </div>
  </div>

  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Sender ID</th>
          <?php if ($global): ?><th class="hidden md:table-cell">Organisation</th><?php endif; ?>
          <th class="hidden sm:table-cell">Usage</th>
          <th>Statut</th>
          <th class="hidden lg:table-cell">Demandé le</th>
          <th class="w-40 text-right">Actions</th>
        </tr>
      </thead>
      <tbody id="si-rows"></tbody>
    </table>
    <div id="si-state"></div>
  </div>
  <div id="si-pager"></div>
</div>
