/* TAL Messaging — Quick Send : saisie, aperçu en direct et envoi d'un SMS. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, debounce, dateFr, money, modal, confirmBox, toastErr } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#qs-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);

  const GSM = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
  const GSM_EXT = '^{}\\[~]|€\f';
  const VAR_RE = /\{\{\s*([a-z0-9_]{1,40})\s*(?:\|([^{}]{0,60}))?\}\}/gi;
  const state = { orgId: '', senders: [], key: null, busy: false, last: null };
  let seq = 0;

  const $sender = $('#qs-sender'), $numbers = $('#qs-numbers'), $body = $('#qs-body'), $date = $('#qs-date'), $send = $('#qs-send'), $sum = $('#qs-summary-body');
  const mode = () => document.querySelector('input[name=when]:checked').value;
  const checked = (box) => [...box.querySelectorAll('input:checked')].map((i) => Number(i.value));

  /* ---------------------------------------------------- Compteur local */
  function analyze(text) {
    let units = 0; let gsm = true;
    for (const c of text) { if (GSM.includes(c)) units += 1; else if (GSM_EXT.includes(c)) units += 2; else { gsm = false; break; } }
    let single = 160; let multi = 153;
    if (!gsm) { units = 0; for (const c of text) units += c.length > 1 ? 2 : 1; single = 70; multi = 67; }
    const segments = units === 0 ? 0 : units <= single ? 1 : Math.ceil(units / multi);
    return { gsm, units, segments, per: segments <= 1 ? single : multi };
  }
  function updateCounter() {
    const raw = $body.value;
    const hasVars = raw.search(VAR_RE) !== -1;
    const sample = raw.replace(VAR_RE, (m, k, fb) => (fb ? fb.trim() : 'xxxxxxxx'));
    const a = analyze(sample);
    $('#qs-counter').textContent = (hasVars ? '≈ ' : '') + fmt(a.units) + ' caractère' + (a.units > 1 ? 's' : '') + ' · ' + a.segments + ' SMS' + (hasVars ? ' (variables estimées)' : '');
    $('#qs-encoding').textContent = a.gsm ? 'Alphabet standard · 160 caractères par SMS' : 'Caractères spéciaux détectés (accents, emoji…) · 70 caractères par SMS';
  }
  function insertVar(text) {
    const s = $body.selectionStart ?? $body.value.length; const e = $body.selectionEnd ?? s;
    $body.value = $body.value.slice(0, s) + text + $body.value.slice(e);
    $body.focus(); $body.setSelectionRange(s + text.length, s + text.length);
    onChange();
  }

  /* ---------------------------------------------------------- Données */
  const orgQuery = () => (cfg.global && state.orgId ? '&organization_id=' + state.orgId : '');
  function checkList(box, rows, label) {
    clear(box);
    if (!rows.length) { box.append(h('p', { class: 'px-1 py-2 text-mute' }, 'Aucun ' + label + '.')); return; }
    for (const r of rows) {
      box.append(h('label', { class: 'flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-surface2' },
        h('input', { type: 'checkbox', class: 'check', value: r.id, onchange: onChange }),
        h('span', { class: 'min-w-0 flex-1 truncate' }, r.name),
        h('span', { class: 'shrink-0 text-xs text-mute' }, r.contacts_count === null || r.contacts_count === undefined ? '' : fmt(r.contacts_count))));
    }
  }
  async function loadResources() {
    if (cfg.global && !state.orgId) {
      $sender.replaceChildren(h('option', { value: '' }, 'Choisissez d’abord l’organisation'));
      checkList($('#qs-groups'), [], 'groupe'); checkList($('#qs-segments'), [], 'segment');
      return;
    }
    try {
      const [senders, groups, segments] = await Promise.all([
        api('GET', '/api/sender-ids/approved?x=1' + orgQuery()),
        api('GET', '/api/contact-groups?per_page=100' + orgQuery()),
        api('GET', '/api/segments?per_page=100' + orgQuery()),
      ]);
      state.senders = senders;
      $sender.replaceChildren(...(senders.length ? senders.map((s) => h('option', { value: s.id, selected: s.is_default }, s.name + (s.is_default ? ' (par défaut)' : '')))
        : [h('option', { value: '' }, 'Aucun Sender ID approuvé')]));
      const hint = clear($('#qs-sender-hint'));
      if (!senders.length) hint.append('Il vous faut un Sender ID approuvé pour envoyer. ', h('a', { class: 'font-medium text-brand underline', href: url('/sender-ids') }, 'Demander un Sender ID'));
      checkList($('#qs-groups'), groups, 'groupe'); checkList($('#qs-segments'), segments, 'segment');
    } catch (err) { toastErr(err); }
    onChange();
  }

  /* ---------------------------------------------------------- Aperçu */
  function payload() {
    const p = { sender_id_id: Number($sender.value) || 0, body: $body.value, numbers: $numbers.value, group_ids: checked($('#qs-groups')), segment_ids: checked($('#qs-segments')) };
    if (cfg.global) p.organization_id = state.orgId;
    if (mode() === 'later' && $date.value) { const d = new Date($date.value); if (!Number.isNaN(d.getTime())) p.scheduled_at = d.toISOString(); }
    return p;
  }
  const ready = (p) => p.sender_id_id > 0 && p.body.trim() !== '' && (p.numbers.trim() !== '' || p.group_ids.length || p.segment_ids.length) && (mode() === 'now' || !!p.scheduled_at);

  const refresh = debounce(async () => {
    const p = payload(); const mine = ++seq;
    state.last = null; $send.disabled = true;
    if (!ready(p)) { showHint(mode() === 'later' && !p.scheduled_at ? 'Choisissez la date et l’heure d’envoi.' : 'Renseignez les destinataires et le message : le récapitulatif se met à jour tout seul.'); return; }
    showHint('Vérification…');
    try {
      const s = await api('POST', '/api/messages/preview', p);
      if (mine !== seq) return;
      state.last = s; renderSummary(s); $send.disabled = state.busy || !s.enough_balance;
    } catch (err) { if (mine === seq) showHint(err.message + (err.fields ? ' ' + Object.values(err.fields).flat().join(' ') : ''), true); }
  }, 500);

  function onChange() { state.key = null; updateCounter(); refresh(); }
  function showHint(text, bad = false) { clear($sum).append(h('p', { class: bad ? 'text-red-600' : 'text-mute', role: bad ? 'alert' : null }, text)); }

  function row(label, value) { return h('div', { class: 'flex items-baseline justify-between gap-3' }, h('span', { class: 'text-mute' }, label), h('span', { class: 'font-semibold text-ink tabular-nums' }, value)); }
  function warn(text) { return h('p', { class: 'alert alert-warn !py-2 text-xs' }, icon('alert-triangle', 'mt-0.5 shrink-0'), h('span', {}, text)); }
  function renderSummary(s) {
    clear($sum).append(
      h('div', { class: 'space-y-2' },
        row('Destinataires', fmt(s.recipients)), row('SMS facturables', fmt(s.total_segments) + (s.segments_per_message > 1 ? ' (' + s.segments_per_message + ' par message)' : '')),
        row('Coût', money(s.cost_micro, s.currency)), row('Expéditeur', s.sender_name), row('Envoi', s.scheduled_at ? dateFr(s.scheduled_at.replace('T', ' ').slice(0, 19)) : 'Immédiat')),
      s.enough_balance ? null : h('p', { class: 'alert alert-danger !py-2 text-xs', role: 'alert' }, icon('alert-circle', 'mt-0.5 shrink-0'), h('span', {}, 'Solde insuffisant (disponible : ' + money(s.available_micro, s.currency) + '). ', h('a', { class: 'font-semibold underline', href: url('/billing') }, 'Recharger'))),
      s.unpriced ? warn(fmt(s.unpriced) + ' numéro(s) sans tarif ignoré(s). Contactez TAL.') : null,
      s.invalid_count ? warn(fmt(s.invalid_count) + ' numéro(s) invalide(s) ignoré(s) : ' + s.invalid.slice(0, 3).map((i) => i.raw + ' (' + i.reason.toLowerCase() + ')').join(', ') + (s.invalid_count > 3 ? '…' : '')) : null,
      s.blocked ? warn(fmt(s.blocked) + ' contact(s) bloqué(s) exclu(s).') : null,
      s.duplicates ? warn(fmt(s.duplicates) + ' doublon(s) retiré(s).') : null,
      s.missing_variables ? warn(fmt(s.missing_variables) + ' contact(s) sans valeur pour une variable : ajoutez une valeur de repli, par exemple {{first_name|client}}.') : null,
      s.too_long ? warn(fmt(s.too_long) + ' message(s) trop long(s) après personnalisation, ignoré(s).') : null,
      h('div', { class: 'space-y-2 border-t border-line pt-3' }, h('p', { class: 'text-xs font-semibold uppercase tracking-wider text-mute' }, 'Exemples'),
        s.examples.map((e) => h('div', { class: 'rounded-xl bg-surface2 px-3 py-2 text-xs' }, h('p', { class: 'font-mono text-mute' }, e.to), h('p', { class: 'mt-0.5 whitespace-pre-wrap break-words text-ink' }, e.text)))));
  }

  /* ----------------------------------------------------------- Envoi */
  async function send() {
    const s = state.last; if (!s || state.busy) return;
    const when = s.scheduled_at ? 'le ' + dateFr(s.scheduled_at.replace('T', ' ').slice(0, 19)) : 'maintenant';
    if (!(await confirmBox('Envoyer ' + fmt(s.recipients) + ' message(s) (' + fmt(s.total_segments) + ' SMS, ' + money(s.cost_micro, s.currency) + ') depuis « ' + s.sender_name + ' » ' + when + ' ?', { label: 'Envoyer', title: 'Confirmer l’envoi' }))) return;
    state.busy = true; $send.disabled = true;
    state.key = state.key || (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
    try {
      const res = await api('POST', '/api/messages/send', Object.assign(payload(), { idempotency_key: state.key }));
      done(res.batch, res.replayed);
    } catch (err) { toastErr(err); $send.disabled = false; } finally { state.busy = false; }
  }
  function done(batch, replayed) {
    toast(replayed ? 'Cet envoi avait déjà été enregistré.' : (batch.scheduled_at ? 'Envoi programmé.' : 'Messages mis en file : l’envoi démarre.'));
    clear($sum).append(
      h('div', { class: 'flex flex-col items-center gap-2 py-4 text-center' }, icon('circle-check', 'text-4xl text-emerald-600'),
        h('p', { class: 'font-semibold text-ink' }, fmt(batch.total_messages) + ' message(s) enregistré(s)'), h('p', { class: 'text-xs text-mute' }, batch.scheduled_at ? 'Départ prévu ' + dateFr(batch.scheduled_at) : 'Ils partent dans quelques secondes.')),
      h('a', { class: 'btn btn-outline w-full', href: url('/messages?batch=' + batch.id) }, icon('list-details'), 'Suivre cet envoi'),
      h('button', { type: 'button', class: 'btn btn-ghost w-full', onclick: reset }, icon('plus'), 'Nouvel envoi'));
    $send.disabled = true;
  }
  function reset() {
    $body.value = ''; $numbers.value = ''; $date.value = '';
    for (const i of root.querySelectorAll('#qs-groups input, #qs-segments input')) i.checked = false;
    state.key = null; onChange();
  }

  /* ------------------------------------------------------------ Init */
  for (const v of [['Prénom', '{{first_name|client}}'], ['Nom', '{{last_name}}'], ['Nom complet', '{{name|client}}']]) {
    $('#qs-vars').append(h('button', { type: 'button', class: 'chip', onclick: () => insertVar(v[1]) }, icon('braces', 'text-sm'), v[0]));
  }
  $numbers.addEventListener('input', onChange); $body.addEventListener('input', onChange); $sender.addEventListener('change', onChange); $date.addEventListener('change', onChange);
  for (const r of document.querySelectorAll('input[name=when]')) r.addEventListener('change', () => { $('#qs-later').classList.toggle('hidden', mode() !== 'later'); onChange(); });
  $send.addEventListener('click', send);
  $('#qs-form').addEventListener('submit', (e) => e.preventDefault());
  if (cfg.global) {
    api('GET', '/api/organizations?per_page=100').then((orgs) => { for (const o of orgs) $('#qs-org').append(h('option', { value: o.id }, o.name)); }).catch(toastErr);
    $('#qs-org').addEventListener('change', (e) => { state.orgId = e.target.value; loadResources(); });
  }
  updateCounter(); loadResources();
})();
