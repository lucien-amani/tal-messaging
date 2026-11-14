<?php
/** @var int $campaignId */
/** @var int $maxRecipients */
/** @var array<string,int> $limits */
$cfg = ['id' => $campaignId, 'global' => $global, 'limits' => $limits, 'maxRecipients' => $maxRecipients];
?>
<div class="mb-6">
  <a class="mb-2 inline-flex items-center gap-1 text-sm text-mute hover:text-ink" href="<?= e(url('/campaigns')) ?>"><?= icon('arrow-left') ?>Campagnes</a>
  <h2 class="text-2xl font-bold md:text-3xl"><?= $campaignId ? 'Modifier la campagne' : 'Nouvelle campagne' ?></h2>
</div>

<div id="cf-app" data-config="<?= e((string) json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>" class="grid gap-6 lg:grid-cols-3">
  <form id="cf-form" class="space-y-6 lg:col-span-2" novalidate>
    <div class="card space-y-4">
      <h3 class="text-base font-semibold">1. Campagne</h3>
      <?php if ($global && !$campaignId): ?>
        <div><label class="label" for="cf-org">Organisation</label><select class="input" id="cf-org"><option value="">Choisir l’organisation…</option></select></div>
      <?php endif; ?>
      <div><label class="label" for="cf-name">Nom (visible uniquement par vous)</label><input class="input" id="cf-name" maxlength="120" placeholder="Ex. Promotion de fin d’année" autocomplete="off"></div>
      <div>
        <label class="label" for="cf-sender">Sender ID</label>
        <select class="input" id="cf-sender"></select>
        <p class="hint" id="cf-sender-hint"></p>
      </div>
    </div>

    <div class="card space-y-4">
      <h3 class="text-base font-semibold">2. Audience</h3>
      <div class="grid gap-4 sm:grid-cols-2">
        <div><p class="label">Groupes</p><div id="cf-groups" class="max-h-48 space-y-1 overflow-y-auto rounded-xl border border-line p-2 text-sm"></div></div>
        <div><p class="label">Segments</p><div id="cf-segments" class="max-h-48 space-y-1 overflow-y-auto rounded-xl border border-line p-2 text-sm"></div></div>
      </div>
      <p class="hint">Jusqu’à <?= e(number_format($maxRecipients, 0, ',', ' ')) ?> destinataires. Les contacts bloqués sont exclus et chaque numéro n’est utilisé qu’une fois.</p>
    </div>

    <div class="card space-y-4">
      <h3 class="text-base font-semibold">3. Message</h3>
      <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-[12rem] flex-1"><label class="label" for="cf-template">Partir d’un modèle</label><select class="input" id="cf-template"><option value="">—</option></select></div>
        <div class="flex flex-wrap gap-1.5" id="cf-vars" aria-label="Insérer une variable"></div>
      </div>
      <div>
        <label class="label" for="cf-body">Texte du SMS</label>
        <textarea class="input !h-36 py-2.5" id="cf-body" maxlength="<?= (int) $limits['body'] ?>" placeholder="Bonjour {{first_name|client}}, profitez de -20 % ce week-end !"></textarea>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-mute" aria-live="polite"><span id="cf-counter"></span><span id="cf-encoding"></span></div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="cf-save-template"><?= icon('device-floppy') ?>Enregistrer comme modèle</button>
    </div>

    <div class="card space-y-4">
      <h3 class="text-base font-semibold">4. Quand envoyer ?</h3>
      <div class="flex flex-wrap gap-4 text-sm">
        <label class="flex items-center gap-2"><input type="radio" class="check" name="cf-when" value="now" checked>Dès le lancement</label>
        <label class="flex items-center gap-2"><input type="radio" class="check" name="cf-when" value="later">Programmer</label>
      </div>
      <div id="cf-later" class="hidden">
        <label class="label" for="cf-date">Date et heure (votre fuseau horaire)</label>
        <input class="input sm:max-w-xs" type="datetime-local" id="cf-date">
        <p class="hint">Entre <?= (int) $limits['scheduleMinutes'] ?> minutes et <?= (int) $limits['scheduleDays'] ?> jours après le lancement.</p>
      </div>
    </div>
  </form>

  <aside>
    <div class="card sticky top-4 space-y-4" aria-live="polite">
      <h3 class="text-base font-semibold">Récapitulatif</h3>
      <div id="cf-summary" class="space-y-3 text-sm text-mute">Renseignez l’audience et le message : le récapitulatif se met à jour tout seul.</div>
      <div class="space-y-2">
        <button type="button" class="btn btn-primary w-full" id="cf-launch" disabled><?= icon('rocket') ?>Lancer la campagne</button>
        <button type="button" class="btn btn-outline w-full" id="cf-save"><?= icon('device-floppy') ?>Enregistrer le brouillon</button>
      </div>
    </div>
  </aside>
</div>
