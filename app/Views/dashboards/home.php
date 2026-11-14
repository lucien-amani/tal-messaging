<?php
/** @var array<string,mixed> $dashboard @var string $slug @var array<string,mixed> $user @var bool $global */
$cfg = ['slug' => $slug, 'global' => $global, 'org' => $_GET['organization_id'] ?? null];
?>
<div class="mb-6 flex items-start gap-4">
  <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand text-brandfg"><?= icon((string) $dashboard['icon'], 'text-3xl') ?></span>
  <div>
    <h2 class="text-2xl font-bold md:text-3xl">Dashboard <?= e($dashboard['label']) ?></h2>
    <p class="mt-1 text-sm text-mute md:text-base"><?= e($dashboard['description']) ?> · <?= e($user['first_name'] . ' ' . $user['last_name']) ?> (<?= e($user['role_name']) ?>)</p>
  </div>
</div>
<div id="db-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>" class="space-y-8">
  <div id="db-mode"></div>
  <div id="db-alerts" class="space-y-2"></div>
  <div id="db-kpis" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"></div>
  <div id="db-tables" class="grid gap-6 lg:grid-cols-2"></div>
  <section>
    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-mute">Accès rapides</h3>
    <div id="db-links" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"></div>
  </section>
  <div id="db-state"></div>
</div>
