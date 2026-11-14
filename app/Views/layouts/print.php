<?php
use App\Services\ThemeService;
$themeDefault = ThemeService::settings()['default_mode'];
?>
<!DOCTYPE html>
<html lang="fr" data-theme-default="<?= e($themeDefault) ?>">
<head>
  <?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="bg-canvas">
  <main class="mx-auto max-w-3xl px-4 py-8 print:max-w-none print:p-0">
    <?= $content ?>
  </main>
</body>
</html>
