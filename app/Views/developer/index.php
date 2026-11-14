<?php
/** @var array<string,string> $scopes */
$base = rtrim(url('/api/v1'), '/');
$abs = (isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '') . $base;
$cfg = ['scopes' => $scopes, 'global' => $global, 'base' => $abs];
?>
<div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
  <div>
    <h2 class="text-2xl font-bold md:text-3xl">API Developer Center</h2>
    <p class="mt-1 max-w-2xl text-sm text-mute">Envoyez des SMS depuis vos applications avec une clé API. Chaque clé est limitée à votre organisation et peut être révoquée à tout moment.</p>
  </div>
  <button type="button" class="btn btn-primary self-start" id="dv-new"><?= icon('plus') ?>Nouvelle clé</button>
</div>
<div id="dv-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>" class="space-y-8">
  <section>
    <h3 class="mb-3 text-lg font-semibold">Clés API</h3>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>Nom</th><?php if ($global): ?><th>Organisation</th><?php endif; ?><th>Clé</th><th class="hidden sm:table-cell">Droits</th><th class="hidden md:table-cell">Dernier usage</th><th>Statut</th><th class="w-24 text-right">Actions</th></tr></thead>
        <tbody id="dv-rows"></tbody>
      </table>
      <div id="dv-state"></div>
    </div>
  </section>
  <section class="space-y-4">
    <h3 class="text-lg font-semibold">Documentation rapide</h3>
    <p class="text-sm text-mute">Base : <code class="font-mono"><?= e($abs) ?></code> · Authentification : en-tête <code class="font-mono">Authorization: Bearer VOTRE_CLÉ</code> · Limite : 60 requêtes/minute par clé · Montants en micro-unités (1 000 000 = 1,00).</p>
    <div class="card space-y-2 p-4"><p class="text-sm font-medium">Envoyer un SMS — <code class="font-mono">POST /messages</code></p>
      <pre class="overflow-x-auto rounded-lg bg-black/5 p-3 text-xs dark:bg-white/10"><code>curl -X POST <?= e($abs) ?>/messages \
  -H "Authorization: Bearer tal_live_..." \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: commande-1042" \
  -d '{"to":"+243810000011","from":"MONAPP","body":"Votre code est 4821"}'</code></pre>
      <p class="text-xs text-mute">« from » doit être un Sender ID approuvé. « to » accepte un numéro ou une liste (1 000 max). « scheduled_at » (ISO 8601) programme l’envoi. Réponse 202 avec l’identifiant, le coût et les rejets. Le solde est réservé à l’envoi : erreur 402 si insuffisant.</p></div>
    <div class="card space-y-2 p-4"><p class="text-sm font-medium">Suivre un message, un envoi, le solde</p>
      <pre class="overflow-x-auto rounded-lg bg-black/5 p-3 text-xs dark:bg-white/10"><code>GET <?= e($abs) ?>/messages/{id}
GET <?= e($abs) ?>/batches/{id}
GET <?= e($abs) ?>/balance</code></pre>
      <p class="text-xs text-mute">Statuts d’un message : queued, sending, sent, delivered, failed, cancelled. Tant qu’aucun fournisseur n’est configuré par TAL, les envois sont simulés.</p></div>
    <div class="card p-4"><p class="text-sm font-medium">Codes d’erreur</p>
      <p class="mt-1 text-xs text-mute">401 INVALID_API_KEY · 403 INSUFFICIENT_SCOPE / ORGANIZATION_INACTIVE · 402 INSUFFICIENT_BALANCE · 422 validation (numéro invalide, Sender ID non approuvé…) · 429 RATE_LIMITED.</p></div>
  </section>
</div>
