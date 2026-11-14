<?php /** @var array<string,mixed> $cfg */ ?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div>
    <h2 class="text-2xl font-bold md:text-3xl">Clients</h2>
    <p class="mt-1 max-w-2xl text-sm text-mute">Organisations clientes : statut, activité et accès direct à leur wallet, leurs utilisateurs et leurs tickets.</p>
  </div>
  <?php if ($cfg['canManage']): ?><button type="button" class="btn btn-primary self-start" id="cl-new"><?= icon('plus') ?>Nouveau client</button><?php endif; ?>
</div>
<div id="cl-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>" class="space-y-4">
  <div class="flex flex-wrap gap-3">
    <input class="input w-full sm:w-64" id="cl-q" type="search" placeholder="Nom ou e-mail" aria-label="Rechercher">
    <select class="input w-auto" id="cl-status" aria-label="Statut"><option value="">Tous les statuts</option><option value="active">Actif</option><option value="pending">En attente</option><option value="suspended">Suspendu</option><option value="closed">Fermé</option></select>
  </div>
  <div class="tbl-wrap">
    <table class="tbl"><thead><tr><th>Client</th><th class="hidden sm:table-cell">Pays</th><th class="hidden md:table-cell">Création</th><th>Statut</th><th class="w-24 text-right">Fiche</th></tr></thead><tbody id="cl-rows"></tbody></table>
    <div id="cl-state"></div>
  </div>
  <div id="cl-pager"></div>
</div>
