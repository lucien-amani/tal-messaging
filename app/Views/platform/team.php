<?php /** @var array<string,mixed> $cfg */ ?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div>
    <h2 class="text-2xl font-bold md:text-3xl">Équipe</h2>
    <p class="mt-1 max-w-2xl text-sm text-mute"><?= $cfg['global'] ? 'Comptes internes TAL et utilisateurs des organisations clientes.' : 'Invitez vos collaborateurs et choisissez leur rôle. Un mot de passe temporaire est affiché une seule fois à la création.' ?></p>
  </div>
  <button type="button" class="btn btn-primary self-start" id="tm-new"><?= icon('user-plus') ?>Nouvel utilisateur</button>
</div>
<div id="tm-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>" class="space-y-4">
  <div class="flex flex-wrap gap-3">
    <input class="input w-full sm:w-64" id="tm-q" type="search" placeholder="Nom ou e-mail" aria-label="Rechercher">
    <?php if ($cfg['global']): ?><select class="input w-auto" id="tm-scope" aria-label="Type"><option value="">Tous les comptes</option><option value="internal">Équipe TAL</option></select><?php endif; ?>
  </div>
  <div class="tbl-wrap">
    <table class="tbl"><thead><tr><th>Utilisateur</th><th class="hidden sm:table-cell">Rôle</th><th class="hidden md:table-cell">Dernière connexion</th><th>Statut</th><th class="w-32 text-right">Actions</th></tr></thead><tbody id="tm-rows"></tbody></table>
    <div id="tm-state"></div>
  </div>
  <div id="tm-pager"></div>
</div>
