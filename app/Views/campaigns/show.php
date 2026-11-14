<?php
/** @var int $campaignId */
$cfg = ['id' => $campaignId, 'canManage' => $canManage, 'global' => $global];
?>
<div id="cr-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">
  <a class="mb-2 inline-flex items-center gap-1 text-sm text-mute hover:text-ink" href="<?= e(url('/campaigns')) ?>"><?= icon('arrow-left') ?>Campagnes</a>
  <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
    <div class="min-w-0"><h2 class="break-words text-2xl font-bold md:text-3xl" id="cr-title">Campagne</h2><p class="mt-1 text-sm text-mute" id="cr-sub"></p></div>
    <div class="flex flex-wrap gap-2" id="cr-actions"></div>
  </div>
  <div id="cr-kpis" class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5" aria-live="polite"></div>
  <div class="mb-6 card" id="cr-progress"></div>
  <div class="grid gap-6 lg:grid-cols-2">
    <div class="card"><h3 class="mb-4 text-base font-semibold">Par opérateur</h3><div id="cr-operators"></div></div>
    <div class="card"><h3 class="mb-4 text-base font-semibold">Échecs les plus fréquents</h3><div id="cr-errors"></div></div>
  </div>
  <div class="card mt-6"><h3 class="mb-2 text-base font-semibold">Message</h3><p class="whitespace-pre-wrap break-words rounded-xl bg-surface2 px-3 py-2 text-sm" id="cr-body"></p></div>
</div>
