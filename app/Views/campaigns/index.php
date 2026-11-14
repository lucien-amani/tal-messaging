<?php
$cfg = ['global' => $global, 'canManage' => $canManage];
?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div>
    <h2 class="text-2xl font-bold md:text-3xl">Campagnes</h2>
    <p class="mt-1 max-w-2xl text-sm text-mute">Préparez un message pour des groupes ou des segments, lancez-le ou programmez-le, puis suivez les résultats.</p>
  </div>
  <?php if ($canManage): ?>
    <div class="flex flex-wrap gap-2 self-start">
      <?php if (!$global): ?>
        <button type="button" class="btn btn-outline" id="cp-templates"><?= icon('template') ?>Modèles</button>
      <?php endif; ?>
      <a class="btn btn-primary" href="<?= e(url('/campaigns/new')) ?>"><?= icon('plus') ?>Nouvelle campagne</a>
    </div>
  <?php endif; ?>
</div>

<div id="cp-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <div class="tabs self-start" id="cp-tabs" role="tablist" aria-label="Filtrer par statut">
      <button type="button" class="tab" role="tab" data-status="">Toutes</button>
      <button type="button" class="tab" role="tab" data-status="draft">Brouillons</button>
      <button type="button" class="tab" role="tab" data-status="scheduled">Programmées</button>
      <button type="button" class="tab" role="tab" data-status="running">En cours</button>
      <button type="button" class="tab" role="tab" data-status="completed">Terminées</button>
      <button type="button" class="tab" role="tab" data-status="cancelled">Annulées</button>
    </div>
    <div class="field w-full sm:w-64">
      <?= icon('search', 'field-icon') ?>
      <input class="input" type="search" id="cp-q" placeholder="Rechercher une campagne" autocomplete="off" aria-label="Rechercher une campagne">
    </div>
  </div>

  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Campagne</th>
          <th>Statut</th>
          <th class="hidden md:table-cell">Avancement</th>
          <th class="hidden lg:table-cell">Livrés</th>
          <th class="hidden xl:table-cell">Date</th>
          <th class="w-36 text-right">Actions</th>
        </tr>
      </thead>
      <tbody id="cp-rows"></tbody>
    </table>
    <div id="cp-state"></div>
  </div>
  <div id="cp-pager"></div>
</div>
