<?php
/** @var array<string,int> $limits */
/** @var string $provider */
$cfg = ['global' => $global, 'limits' => $limits];
?>
<div class="mb-6">
  <h2 class="text-2xl font-bold md:text-3xl">Quick Send</h2>
  <p class="mt-1 max-w-2xl text-sm text-mute">Envoyez un SMS à quelques numéros, à un groupe ou à un segment, tout de suite ou à l’heure choisie.</p>
</div>

<?php if ($provider === 'simulator'): ?>
  <div class="alert alert-warn mb-6" role="status">
    <?= icon('flask', 'mt-0.5 text-lg') ?>
    <span><strong>Mode simulation.</strong> Aucun SMS réel n’est envoyé tant qu’aucun fournisseur n’est branché. Les messages suivent tout le cycle (file, envoi, livraison) pour que vous puissiez tout tester.</span>
  </div>
<?php endif; ?>

<div id="qs-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>" class="grid gap-6 lg:grid-cols-3">
  <form id="qs-form" class="space-y-6 lg:col-span-2" novalidate>
    <?php if ($global): ?>
      <div class="card">
        <label class="label" for="qs-org">Organisation</label>
        <select class="input" id="qs-org"><option value="">Choisir l’organisation…</option></select>
      </div>
    <?php endif; ?>

    <div class="card space-y-4">
      <h3 class="text-base font-semibold">1. Expéditeur</h3>
      <div>
        <label class="label" for="qs-sender">Sender ID</label>
        <select class="input" id="qs-sender"></select>
        <p class="hint" id="qs-sender-hint"></p>
      </div>
    </div>

    <div class="card space-y-4">
      <h3 class="text-base font-semibold">2. Destinataires</h3>
      <div>
        <label class="label" for="qs-numbers">Numéros</label>
        <textarea class="input !h-28 py-2.5 font-mono text-sm" id="qs-numbers" placeholder="0812345678&#10;+243 99 123 4567&#10;un numéro par ligne, ou séparés par une virgule" spellcheck="false"></textarea>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <p class="label">Groupes</p>
          <div id="qs-groups" class="max-h-40 space-y-1 overflow-y-auto rounded-xl border border-line p-2 text-sm"></div>
        </div>
        <div>
          <p class="label">Segments</p>
          <div id="qs-segments" class="max-h-40 space-y-1 overflow-y-auto rounded-xl border border-line p-2 text-sm"></div>
        </div>
      </div>
      <p class="hint">Les contacts bloqués sont exclus automatiquement et chaque numéro n’est utilisé qu’une fois.</p>
    </div>

    <div class="card space-y-4">
      <h3 class="text-base font-semibold">3. Message</h3>
      <div>
        <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
          <label class="label !mb-0" for="qs-body">Texte du SMS</label>
          <div class="flex flex-wrap gap-1.5" id="qs-vars" aria-label="Insérer une variable"></div>
        </div>
        <textarea class="input !h-36 py-2.5" id="qs-body" maxlength="<?= (int) $limits['body'] ?>" placeholder="Bonjour {{first_name|client}}, votre commande est prête."></textarea>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-mute" aria-live="polite">
          <span id="qs-counter"></span>
          <span id="qs-encoding"></span>
        </div>
      </div>
    </div>

    <div class="card space-y-4">
      <h3 class="text-base font-semibold">4. Quand envoyer ?</h3>
      <div class="flex flex-wrap gap-4 text-sm">
        <label class="flex items-center gap-2"><input type="radio" class="check" name="when" value="now" checked>Maintenant</label>
        <label class="flex items-center gap-2"><input type="radio" class="check" name="when" value="later">Programmer</label>
      </div>
      <div id="qs-later" class="hidden">
        <label class="label" for="qs-date">Date et heure (votre fuseau horaire)</label>
        <input class="input sm:max-w-xs" type="datetime-local" id="qs-date">
        <p class="hint">Entre <?= (int) $limits['scheduleMinutes'] ?> minutes et <?= (int) $limits['scheduleDays'] ?> jours à partir de maintenant.</p>
      </div>
    </div>
  </form>

  <aside class="lg:col-span-1">
    <div class="card sticky top-4 space-y-4" id="qs-summary" aria-live="polite">
      <h3 class="text-base font-semibold">Récapitulatif</h3>
      <div id="qs-summary-body" class="space-y-3 text-sm text-mute">Renseignez les destinataires et le message : le récapitulatif se met à jour tout seul.</div>
      <button type="button" class="btn btn-primary w-full" id="qs-send" disabled><?= icon('send') ?>Envoyer</button>
    </div>
  </aside>
</div>
