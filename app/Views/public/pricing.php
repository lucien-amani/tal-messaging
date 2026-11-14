<?php /** @var array<int,array<string,string>> $rules */ ?>
<h1 class="font-display text-3xl font-bold md:text-4xl">Tarifs</h1>
<p class="mt-3 max-w-2xl text-mute">Paiement prépayé au segment SMS (160 caractères en GSM-7, 70 en Unicode). Pas d’abonnement. Des tarifs négociés sont possibles selon vos volumes.</p>
<div class="tbl-wrap mt-8 max-w-2xl">
  <table class="tbl" id="pub-pricing"><thead><tr><th>Destination</th><th class="text-right">Prix par segment</th></tr></thead><tbody>
    <?php foreach ($rules as $r): ?><tr><td><?= e($r['scope']) ?></td><td class="text-right font-medium tabular-nums"><?= e($r['price']) ?></td></tr><?php endforeach; ?>
    <?php if (!$rules): ?><tr><td colspan="2" class="text-mute">Tarifs communiqués sur demande.</td></tr><?php endif; ?>
  </tbody></table>
</div>
<p class="mt-4 text-sm text-mute">Le tarif le plus précis s’applique (client + pays + opérateur, puis pays, puis tarif par défaut). Les taxes éventuelles ne sont pas incluses.</p>
<a class="btn btn-primary mt-6" href="<?= e(url('/contact')) ?>">Demander un tarif sur mesure</a>
