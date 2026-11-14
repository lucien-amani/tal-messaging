<?php
$cfg = ['global' => $global, 'canSend' => $canSend];
?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div>
    <h2 class="text-2xl font-bold md:text-3xl">Message Center</h2>
    <p class="mt-1 max-w-2xl text-sm text-mute">Suivez chaque message : en file, envoyé, livré ou en échec, et annulez ce qui n’est pas encore parti.</p>
  </div>
  <?php if ($canSend): ?>
    <a class="btn btn-primary self-start" href="<?= e(url('/messages/quick-send')) ?>"><?= icon('send') ?>Nouvel envoi</a>
  <?php endif; ?>
</div>

<div id="mc-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">
  <div id="mc-kpis" class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5" aria-live="polite"></div>

  <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <div class="tabs self-start" id="mc-views" role="tablist" aria-label="Affichage">
      <button type="button" class="tab" role="tab" data-view="messages">Messages</button>
      <button type="button" class="tab" role="tab" data-view="batches">Envois</button>
    </div>
    <div id="mc-filters" class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
      <select class="input sm:w-44" id="mc-status" aria-label="Statut">
        <option value="">Tous les statuts</option>
        <option value="queued">En file</option>
        <option value="sent">Envoyés</option>
        <option value="delivered">Livrés</option>
        <option value="failed">Échecs</option>
        <option value="cancelled">Annulés</option>
      </select>
      <input class="input sm:w-40" type="date" id="mc-from" aria-label="Du">
      <input class="input sm:w-40" type="date" id="mc-to" aria-label="Au">
      <div class="field w-full sm:w-56">
        <?= icon('search', 'field-icon') ?>
        <input class="input" type="search" id="mc-q" placeholder="Numéro ou texte" autocomplete="off" aria-label="Rechercher">
      </div>
    </div>
  </div>
  <div id="mc-batch-chip" class="mb-3 hidden"></div>

  <div class="tbl-wrap">
    <table class="tbl">
      <thead id="mc-head"></thead>
      <tbody id="mc-rows"></tbody>
    </table>
    <div id="mc-state"></div>
  </div>
  <div id="mc-pager"></div>
</div>
