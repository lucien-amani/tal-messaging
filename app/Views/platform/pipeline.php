<?php /** @var array<string,string> $statuses */ ?>
<div class="mb-6">
  <h2 class="text-2xl font-bold md:text-3xl">Pipeline commercial</h2>
  <p class="mt-1 max-w-2xl text-sm text-mute">Prospects reçus par le formulaire du site public. Qualifiez-les, prenez des notes, puis créez le client dans la page Clients.</p>
</div>
<div id="pl-app" data-config="<?= e((string) json_encode(['statuses' => $statuses], JSON_UNESCAPED_UNICODE)) ?>" class="space-y-4">
  <select class="input w-auto" id="pl-status" aria-label="Statut"><option value="">Tous les statuts</option><?php foreach ($statuses as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
  <div class="tbl-wrap">
    <table class="tbl"><thead><tr><th>Prospect</th><th class="hidden sm:table-cell">Besoin</th><th class="hidden md:table-cell">Reçu le</th><th>Statut</th></tr></thead><tbody id="pl-rows"></tbody></table>
    <div id="pl-state"></div>
  </div>
</div>
