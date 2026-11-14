<section class="grid items-center gap-10 lg:grid-cols-2">
  <div>
    <p class="mb-3 inline-flex items-center gap-2 rounded-full bg-brand/10 px-3 py-1 text-xs font-semibold text-brand"><?= icon('map-pin') ?>Pensé pour la RDC et l’Afrique</p>
    <h1 class="font-display text-4xl font-bold leading-tight md:text-5xl">Envoyez des SMS à vos clients, simplement et à grande échelle.</h1>
    <p class="mt-5 max-w-xl text-lg text-mute">Campagnes, messages transactionnels, contacts et wallet prépayé : une seule plateforme, par interface web ou par API.</p>
    <div class="mt-8 flex flex-wrap gap-3">
      <a class="btn btn-primary" href="<?= e(url('/contact')) ?>"><?= icon('message-circle') ?>Parler à l’équipe</a>
      <a class="btn btn-outline" href="<?= e(url('/tarifs')) ?>">Voir les tarifs</a>
    </div>
  </div>
  <div class="card space-y-3 p-6">
    <p class="text-xs font-semibold uppercase tracking-wider text-mute">Exemple d’appel API</p>
    <pre class="overflow-x-auto rounded-lg bg-black/5 p-3 text-xs dark:bg-white/10"><code>POST /api/v1/messages
{ "to": "+243810000011",
  "from": "MONAPP",
  "body": "Votre code est 4821" }

→ 202 { "id": "…", "status": "queued", "cost": "0.035 USD" }</code></pre>
    <p class="text-xs text-mute">Clé API, limitation de débit et idempotence inclus.</p>
  </div>
</section>
<section class="mt-16">
  <h2 class="mb-6 text-2xl font-bold">Tout ce qu’il faut pour communiquer</h2>
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ([
        ['send', 'Quick Send & campagnes', 'Envoi immédiat ou programmé, personnalisation par variables, rapport de livraison.'],
        ['address-book', 'Contacts & segments', 'Import CSV, groupes, segments dynamiques et gestion des désinscriptions.'],
        ['id-badge-2', 'Sender IDs', 'Votre nom d’expéditeur, validé par notre équipe de conformité.'],
        ['wallet', 'Wallet prépayé', 'Rechargez, payez au segment, suivez chaque débit. Aucun abonnement.'],
        ['code', 'API & clés', 'Intégrez l’envoi à vos applications en quelques minutes.'],
        ['users', 'Équipe & rôles', 'Owner, Admin, Campaign Manager, Developer, Viewer : chacun son périmètre.'],
    ] as [$ic, $t, $d]): ?>
      <div class="card p-5"><?= icon($ic, 'text-2xl text-brand') ?><h3 class="mt-3 font-semibold"><?= e($t) ?></h3><p class="mt-1 text-sm text-mute"><?= e($d) ?></p></div>
    <?php endforeach; ?>
  </div>
</section>
<section class="mt-16 grid gap-4 md:grid-cols-3">
  <?php foreach ([['1', 'Créez votre compte', 'Notre équipe ouvre votre organisation et votre premier utilisateur.'], ['2', 'Faites valider votre Sender ID', 'Vous demandez votre nom d’expéditeur, nous le validons.'], ['3', 'Rechargez et envoyez', 'Créditez votre wallet puis lancez vos messages.']] as [$n, $t, $d]): ?>
    <div class="card p-5"><span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand font-bold text-brandfg"><?= $n ?></span><h3 class="mt-3 font-semibold"><?= e($t) ?></h3><p class="mt-1 text-sm text-mute"><?= e($d) ?></p></div>
  <?php endforeach; ?>
</section>
<section class="mt-16 card flex flex-col items-start gap-4 p-8 sm:flex-row sm:items-center sm:justify-between">
  <div><h2 class="text-xl font-bold">Canaux</h2><p class="mt-1 text-sm text-mute">SMS disponible. WhatsApp Business : bientôt, sur la même plateforme.</p></div>
  <a class="btn btn-primary" href="<?= e(url('/contact')) ?>">Demander un accès</a>
</section>
