<?php
use App\Services\ThemeService;
$themeDefault = ThemeService::settings()['default_mode'];
$nav = $nav ?? '';
$links = ['pricing' => ['/tarifs', 'Tarifs'], 'docs' => ['/documentation', 'API'], 'contact' => ['/contact', 'Contact']];
?>
<!DOCTYPE html>
<html lang="fr" class="h-full" data-theme-default="<?= e($themeDefault) ?>">
<head>
  <?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="min-h-full">
<header class="sticky top-0 z-30 border-b border-line bg-surface/90 backdrop-blur">
  <div class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
    <a href="<?= e(url('/')) ?>" class="flex items-center gap-2.5">
      <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand text-brandfg"><?= icon('send', 'text-lg') ?></span>
      <span class="font-display text-[17px] font-bold tracking-tight">TAL Messaging</span>
    </a>
    <nav class="hidden items-center gap-1 sm:flex" aria-label="Navigation du site">
      <?php foreach ($links as $k => [$path, $label]): ?>
        <a href="<?= e(url($path)) ?>" class="nav-item !w-auto" <?= $nav === $k ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="flex items-center gap-2">
      <button type="button" class="btn btn-outline btn-icon" data-theme-cycle aria-label="Changer de thème"><?= icon('sun', 'text-lg') ?></button>
      <a href="<?= e(url($logged ? '/dashboard' : '/login')) ?>" class="btn btn-primary"><?= $logged ? 'Mon espace' : 'Connexion' ?></a>
    </div>
  </div>
  <nav class="flex justify-center gap-1 border-t border-line px-2 py-1 sm:hidden" aria-label="Navigation du site (mobile)">
    <?php foreach ($links as $k => [$path, $label]): ?><a href="<?= e(url($path)) ?>" class="nav-item !w-auto" <?= $nav === $k ? 'aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
  </nav>
</header>
<main class="mx-auto w-full max-w-6xl px-4 py-10 sm:px-6 sm:py-14"><?= $content ?></main>
<footer class="border-t border-line">
  <div class="mx-auto flex w-full max-w-6xl flex-col gap-2 px-4 py-6 text-sm text-mute sm:flex-row sm:justify-between sm:px-6">
    <p>© <?= date('Y') ?> TAL Messaging · Plateforme SMS B2B</p>
    <p class="flex gap-4"><a class="link" href="<?= e(url('/tarifs')) ?>">Tarifs</a><a class="link" href="<?= e(url('/documentation')) ?>">API</a><a class="link" href="<?= e(url('/contact')) ?>">Contact</a><a class="link" href="<?= e(url('/login')) ?>">Connexion</a></p>
  </div>
</footer>
<div id="toast" class="pointer-events-none fixed bottom-4 right-4 z-50 w-[calc(100%-2rem)] max-w-sm" role="status" aria-live="polite"></div>
<script src="<?= asset('js/app.js') ?>" defer></script>
<?php foreach (($scripts ?? []) as $js): ?><script src="<?= asset($js) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
