<?php $abs = (isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '') . rtrim(url('/api/v1'), '/'); ?>
<h1 class="font-display text-3xl font-bold md:text-4xl">Documentation API</h1>
<p class="mt-3 max-w-2xl text-mute">API REST JSON. Obtenez une clé dans votre espace client (API Developer Center). Base : <code class="font-mono"><?= e($abs) ?></code></p>
<div class="mt-8 max-w-3xl space-y-4">
  <div class="card space-y-2 p-5"><h2 class="font-semibold">Authentification</h2><p class="text-sm text-mute">En-tête <code class="font-mono">Authorization: Bearer tal_live_…</code>. Limite : 60 requêtes par minute et par clé (en-têtes <code class="font-mono">X-RateLimit-*</code>).</p></div>
  <div class="card space-y-2 p-5"><h2 class="font-semibold">Envoyer un SMS</h2>
    <pre class="overflow-x-auto rounded-lg bg-black/5 p-3 text-xs dark:bg-white/10"><code>curl -X POST <?= e($abs) ?>/messages \
  -H "Authorization: Bearer tal_live_..." -H "Content-Type: application/json" \
  -H "Idempotency-Key: commande-1042" \
  -d '{"to":"+243810000011","from":"MONAPP","body":"Votre code est 4821"}'</code></pre>
    <p class="text-sm text-mute">« from » : Sender ID approuvé. « to » : un numéro ou une liste (1 000 max). « scheduled_at » : date ISO 8601 facultative. Réponse <code class="font-mono">202</code> : identifiant, nombre de destinataires, segments, coût.</p></div>
  <div class="card space-y-2 p-5"><h2 class="font-semibold">Suivi et solde</h2>
    <pre class="overflow-x-auto rounded-lg bg-black/5 p-3 text-xs dark:bg-white/10"><code>GET <?= e($abs) ?>/messages/{id}
GET <?= e($abs) ?>/batches/{id}
GET <?= e($abs) ?>/balance</code></pre>
    <p class="text-sm text-mute">Statuts : queued, sending, sent, delivered, failed, cancelled.</p></div>
  <div class="card space-y-2 p-5"><h2 class="font-semibold">Erreurs</h2><p class="text-sm text-mute">401 INVALID_API_KEY · 402 INSUFFICIENT_BALANCE · 403 INSUFFICIENT_SCOPE · 422 validation · 429 RATE_LIMITED. Chaque erreur renvoie <code class="font-mono">{"error":{"code","message"}}</code>.</p></div>
</div>
