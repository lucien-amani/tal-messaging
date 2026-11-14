<?php
/** @var array<string,string> $countries */
?>
<div class="mb-6">
  <h2 class="text-2xl font-bold md:text-3xl">Fournisseurs d’envoi</h2>
  <p class="mt-1 max-w-2xl text-sm text-mute">Connectez les opérateurs et agrégateurs SMS. Tant qu’aucun fournisseur n’est activé et configuré, la plateforme reste en <strong>mode simulation</strong> : aucun SMS réel n’est envoyé. Les clés sont chiffrées et ne sont jamais réaffichées.</p>
</div>
<div id="pv-app" data-config="<?= e((string) json_encode(['countries' => $countries], JSON_UNESCAPED_UNICODE)) ?>">
  <div id="pv-banner" class="mb-4"></div>
  <div id="pv-list" class="grid gap-4 lg:grid-cols-2"></div>
  <div id="pv-state"></div>
</div>
