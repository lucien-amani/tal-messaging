<?php /** @var array<string,string> $countries @var array<string,string> $volumes */ ?>
<h1 class="font-display text-3xl font-bold md:text-4xl">Parlons de votre projet</h1>
<p class="mt-3 max-w-2xl text-mute">Dites-nous ce que vous voulez envoyer : notre équipe commerciale vous répond sous un jour ouvré.</p>
<form id="ct-form" class="card mt-8 max-w-2xl space-y-4 p-6" novalidate>
  <div class="grid gap-4 sm:grid-cols-2">
    <div><label class="label" for="ct-name">Nom *</label><input class="input" id="ct-name" name="name" maxlength="120" required autocomplete="name"></div>
    <div><label class="label" for="ct-email">E-mail *</label><input class="input" id="ct-email" name="email" type="email" maxlength="190" required autocomplete="email"></div>
    <div><label class="label" for="ct-company">Entreprise</label><input class="input" id="ct-company" name="company" maxlength="150" autocomplete="organization"></div>
    <div><label class="label" for="ct-phone">Téléphone</label><input class="input" id="ct-phone" name="phone" maxlength="32" autocomplete="tel"></div>
    <div><label class="label" for="ct-country">Pays</label><select class="input" id="ct-country" name="country"><option value="">—</option><?php foreach ($countries as $c => $n): ?><option value="<?= e($c) ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div><label class="label" for="ct-vol">Volume mensuel estimé</label><select class="input" id="ct-vol" name="monthly_volume"><option value="">—</option><?php foreach ($volumes as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
  </div>
  <div><label class="label" for="ct-msg">Votre besoin *</label><textarea class="input" id="ct-msg" name="message" rows="5" maxlength="2000" required></textarea></div>
  <div class="hidden" aria-hidden="true"><label>Site web<input name="website" tabindex="-1" autocomplete="off"></label></div>
  <p id="ct-err" class="hidden text-sm text-red-600" role="alert"></p>
  <p id="ct-ok" class="hidden alert alert-ok" role="status">Merci ! Votre demande est bien reçue, nous revenons vers vous très vite.</p>
  <button type="submit" class="btn btn-primary" id="ct-send">Envoyer</button>
</form>
