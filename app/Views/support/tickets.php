<?php /** @var array<string,mixed> $cfg */ ?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div>
    <h2 class="text-2xl font-bold md:text-3xl"><?= $cfg['team'] ? 'Tickets support' : 'Assistance' ?></h2>
    <p class="mt-1 max-w-2xl text-sm text-mute"><?= $cfg['team'] ? 'Traitez les demandes des clients. Les notes internes ne sont jamais visibles par le client.' : 'Un problème de livraison, de facturation ou d’intégration ? Ouvrez un ticket, notre équipe vous répond ici.' ?></p>
  </div>
  <?php if ($cfg['canCreate']): ?><button type="button" class="btn btn-primary self-start" id="tk-new"><?= icon('plus') ?>Nouveau ticket</button><?php endif; ?>
</div>
<div id="tk-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>" class="space-y-6">
  <?php if ($cfg['team']): ?>
  <section class="card space-y-3 p-4">
    <label class="label" for="tk-search">Recherche transversale : numéro, identifiant de message ou nom de campagne</label>
    <div class="flex gap-2"><input class="input" id="tk-search" type="search" placeholder="Ex. +243810000011 ou nom de campagne"><button type="button" class="btn" id="tk-search-go"><?= icon('search') ?>Chercher</button></div>
    <div id="tk-found"></div>
  </section>
  <?php endif; ?>
  <div class="flex flex-wrap gap-3">
    <select class="input w-auto" id="tk-status" aria-label="Statut"><option value="">Tous les statuts</option></select>
    <?php if ($cfg['team']): ?><select class="input w-auto" id="tk-assigned" aria-label="Assignation"><option value="">Toutes assignations</option><option value="me">Mes tickets</option><option value="none">Non assignés</option></select><?php endif; ?>
  </div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Sujet</th><?php if ($cfg['team']): ?><th class="hidden md:table-cell">Client</th><?php endif; ?><th class="hidden sm:table-cell">Catégorie</th><th>Priorité</th><th>Statut</th><th class="hidden md:table-cell">Dernière réponse</th></tr></thead>
      <tbody id="tk-rows"></tbody>
    </table>
    <div id="tk-state"></div>
  </div>
  <div id="tk-pager"></div>
</div>
