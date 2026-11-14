<?php
/** @var array<string,string> $countries */
/** @var array<string,string> $currencies */
$cfg = ['countries' => $countries, 'currencies' => $currencies];
?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div>
    <a class="mb-2 inline-flex items-center gap-1 text-sm text-mute hover:text-ink" href="<?= e(url('/billing')) ?>"><?= icon('arrow-left') ?>Wallet</a>
    <h2 class="text-2xl font-bold md:text-3xl">Tarifs</h2>
    <p class="mt-1 max-w-2xl text-sm text-mute">Prix par SMS (par segment). Le tarif le plus précis s’applique : client + pays + opérateur, puis client + pays, puis tarif public du pays, puis tarif par défaut.</p>
  </div>
  <button type="button" class="btn btn-primary self-start" id="pr-new"><?= icon('plus') ?>Nouveau tarif</button>
</div>

<div id="pr-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="mb-4"><select class="input sm:w-72" id="pr-org" aria-label="Portée"><option value="">Tarifs publics et négociés</option></select></div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Portée</th><th>Pays</th><th class="hidden sm:table-cell">Opérateur</th><th class="text-right">Prix par SMS</th><th>Statut</th><th class="w-24 text-right">Actions</th></tr></thead>
      <tbody id="pr-rows"></tbody>
    </table>
    <div id="pr-state"></div>
  </div>
</div>
