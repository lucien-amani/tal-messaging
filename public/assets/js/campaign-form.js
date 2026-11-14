/* TAL Messaging — Formulaire de campagne : brouillon, aperçu en direct et lancement. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, debounce, dateFr, money, modal, confirmBox, field, textInput, toastErr } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#cf-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);

  const GSM = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
  const GSM_EXT = '^{}\\[~]|€\f';
  const VAR_RE = /\{\{\s*([a-z0-9_]{1,40})\s*(?:\|([^{}]{0,60}))?\}\}/gi;
  const state = { id: cfg.id, orgId: '', last: null, busy: false, templates: [], pending: null };
  let seq = 0;
  const $name = $('#cf-name'), $sender = $('#cf-sender'), $body = $('#cf-body'), $date = $('#cf-date'), $launch = $('#cf-launch'), $save = $('#cf-save'), $sum = $('#cf-summary');
  const mode = () => document.querySelector('input[name=cf-when]:checked').value;
  const checked = (box) => [...box.querySelectorAll('input:checked')].map((i) => Number(i.value));
  const err = (e) => e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : '');

  function analyze(text) {
    let units = 0; let gsm = true;
    for (const c of text) { if (GSM.includes(c)) units += 1; else if (GSM_EXT.includes(c)) units += 2; else { gsm = false; break; } }
    let single = 160; let multi = 153;
    if (!gsm) { units = 0; for (const c of text) units += c.length > 1 ? 2 : 1; single = 70; multi = 67; }
    return { gsm, units, segments: units === 0 ? 0 : units <= single ? 1 : Math.ceil(units / multi) };
  }
  function updateCounter() {
    const raw = $body.value; const hasVars = raw.search(VAR_RE) !== -1;
    const a = analyze(raw.replace(VAR_RE, (m, k, fb) => (fb ? fb.trim() : 'xxxxxxxx')));
    $('#cf-counter').textContent = (hasVars ? '≈ ' : '') + fmt(a.units) + ' caractère' + (a.units > 1 ? 's' : '') + ' · ' + a.segments + ' SMS' + (hasVars ? ' (variables estimées)' : '');
    $('#cf-encoding').textContent = a.gsm ? 'Alphabet standard · 160 caractères par SMS' : 'Caractères spéciaux détectés · 70 caractères par SMS';
  }
  function insertVar(text) {
    const s = $body.selectionStart ?? $body.value.length; const e = $body.selectionEnd ?? s;
    $body.value = $body.value.slice(0, s) + text + $body.value.slice(e); $body.focus(); $body.setSelectionRange(s + text.length, s + text.length); onChange();
  }

  /* ------------------------------------------------------------ Données */
  const orgQuery = () => (cfg.global && state.orgId ? '&organization_id=' + state.orgId : '');
  function checkList(box, rows, label, selected = []) {
    clear(box);
    if (!rows.length) { box.append(h('p', { class: 'px-1 py-2 text-mute' }, 'Aucun ' + label + '.')); return; }
    for (const r of rows) box.append(h('label', { class: 'flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-surface2' },
      h('input', { type: 'checkbox', class: 'check', value: r.id, checked: selected.includes(r.id), onchange: onChange }),
      h('span', { class: 'min-w-0 flex-1 truncate' }, r.name), h('span', { class: 'shrink-0 text-xs text-mute' }, r.contacts_count == null ? '' : fmt(r.contacts_count))));
  }
  async function loadResources(c = null) {
    if (cfg.global && !state.orgId) {
      $sender.replaceChildren(h('option', { value: '' }, 'Choisissez d’abord l’organisation')); checkList($('#cf-groups'), [], 'groupe'); checkList($('#cf-segments'), [], 'segment'); return;
    }
    try {
      const [senders, groups, segments, templates] = await Promise.all([
        api('GET', '/api/sender-ids/approved?x=1' + orgQuery()), api('GET', '/api/contact-groups?per_page=100' + orgQuery()), api('GET', '/api/segments?per_page=100' + orgQuery()),
        cfg.global ? Promise.resolve([]) : api('GET', '/api/campaign-templates?per_page=100')]);
      const wanted = c?.sender_id_id;
      $sender.replaceChildren(...(senders.length ? senders.map((s) => h('option', { value: s.id, selected: wanted ? s.id === wanted : s.is_default }, s.name + (s.is_default ? ' (par défaut)' : ''))) : [h('option', { value: '' }, 'Aucun Sender ID approuvé')]));
      const hint = clear($('#cf-sender-hint'));
      if (!senders.length) hint.append('Il vous faut un Sender ID approuvé pour lancer. ', h('a', { class: 'font-medium text-brand underline', href: url('/sender-ids') }, 'Demander un Sender ID'));
      checkList($('#cf-groups'), groups, 'groupe', c?.group_ids || []); checkList($('#cf-segments'), segments, 'segment', c?.segment_ids || []);
      state.templates = templates;
      $('#cf-template').replaceChildren(h('option', { value: '' }, '—'), ...templates.map((t) => h('option', { value: t.id }, t.name)));
    } catch (e) { toastErr(e); }
    onChange();
  }

  /* ------------------------------------------------------------- Aperçu */
  function payload() {
    const p = { name: $name.value.trim(), sender_id_id: Number($sender.value) || 0, body: $body.value, group_ids: checked($('#cf-groups')), segment_ids: checked($('#cf-segments')) };
    if (cfg.global && !state.id) p.organization_id = state.orgId;
    if (mode() === 'later' && $date.value) { const d = new Date($date.value); if (!Number.isNaN(d.getTime())) p.scheduled_at = d.toISOString(); }
    return p;
  }
  const previewReady = (p) => p.sender_id_id > 0 && p.body.trim() !== '' && (p.group_ids.length || p.segment_ids.length) && (mode() === 'now' || !!p.scheduled_at);

  const refresh = debounce(async () => {
    const p = payload(); const mine = ++seq; state.last = null; $launch.disabled = true;
    if (!previewReady(p)) { hint(mode() === 'later' && !p.scheduled_at ? 'Choisissez la date et l’heure d’envoi.' : 'Renseignez l’audience et le message : le récapitulatif se met à jour tout seul.'); return; }
    hint('Vérification…');
    try {
      const body = { sender_id_id: p.sender_id_id, body: p.body, group_ids: p.group_ids, segment_ids: p.segment_ids };
      if (p.scheduled_at) body.scheduled_at = p.scheduled_at;
      if (p.organization_id) body.organization_id = p.organization_id;
      if (cfg.global && state.id) body.organization_id = state.orgId;
      const s = await api('POST', '/api/campaigns/preview', body);
      if (mine !== seq) return;
      state.last = s; render(s); $launch.disabled = state.busy || !p.name || !s.enough_balance;
    } catch (e) { if (mine === seq) hint(err(e), true); }
  }, 500);
  function onChange() { updateCounter(); refresh(); }
  function hint(t, bad = false) { clear($sum).append(h('p', { class: bad ? 'text-red-600' : 'text-mute', role: bad ? 'alert' : null }, t)); }
  const row = (l, v) => h('div', { class: 'flex items-baseline justify-between gap-3' }, h('span', { class: 'text-mute' }, l), h('span', { class: 'font-semibold tabular-nums text-ink' }, v));
  const warn = (t) => h('p', { class: 'alert alert-warn !py-2 text-xs' }, icon('alert-triangle', 'mt-0.5 shrink-0'), h('span', {}, t));
  function render(s) {
    clear($sum).append(h('div', { class: 'space-y-2' }, row('Destinataires', fmt(s.recipients)), row('SMS facturables', fmt(s.total_segments) + (s.segments_per_message > 1 ? ' (' + s.segments_per_message + ' par message)' : '')),
      row('Coût', money(s.cost_micro, s.currency)), row('Expéditeur', s.sender_name), row('Envoi', s.scheduled_at ? dateFr(s.scheduled_at.replace('T', ' ').slice(0, 19)) : 'Dès le lancement')),
    s.enough_balance ? null : h('p', { class: 'alert alert-danger !py-2 text-xs', role: 'alert' }, icon('alert-circle', 'mt-0.5 shrink-0'), h('span', {}, 'Solde insuffisant (disponible : ' + money(s.available_micro, s.currency) + '). ', h('a', { class: 'font-semibold underline', href: url('/billing') }, 'Recharger'))),
    s.unpriced ? warn(fmt(s.unpriced) + ' numéro(s) sans tarif ignoré(s). Contactez TAL.') : null,
    s.blocked ? warn(fmt(s.blocked) + ' contact(s) bloqué(s) exclu(s).') : null, s.duplicates ? warn(fmt(s.duplicates) + ' doublon(s) retiré(s).') : null,
    s.missing_variables ? warn(fmt(s.missing_variables) + ' contact(s) sans valeur pour une variable : ajoutez un repli, par exemple {{first_name|client}}.') : null,
    s.too_long ? warn(fmt(s.too_long) + ' message(s) trop long(s) après personnalisation, ignoré(s).') : null,
    h('div', { class: 'space-y-2 border-t border-line pt-3' }, h('p', { class: 'text-xs font-semibold uppercase tracking-wider text-mute' }, 'Exemples'),
      s.examples.map((e) => h('div', { class: 'rounded-xl bg-surface2 px-3 py-2 text-xs' }, h('p', { class: 'font-mono text-mute' }, e.to), h('p', { class: 'mt-0.5 whitespace-pre-wrap break-words text-ink' }, e.text)))));
  }

  /* --------------------------------------------------- Enregistrer, lancer */
  async function persist() {
    const p = payload();
    if (!p.name) { $name.focus(); throw new Error('Donnez un nom à la campagne.'); }
    if (!p.body.trim()) { $body.focus(); throw new Error('Le message est vide.'); }
    if (p.sender_id_id === 0) delete p.sender_id_id;
    const c = state.id ? await api('PUT', '/api/campaigns/' + state.id, p) : await api('POST', '/api/campaigns', p);
    state.id = c.id; if (cfg.global) state.orgId = String(c.organization_id);
    return c;
  }
  async function saveDraft() {
    if (state.busy) return; state.busy = true; $save.disabled = true;
    try { await persist(); toast('Brouillon enregistré.'); } catch (e) { toastErr(e); } finally { state.busy = false; $save.disabled = false; }
  }
  async function launch() {
    const s = state.last; if (!s || state.busy) return;
    const when = s.scheduled_at ? 'le ' + dateFr(s.scheduled_at.replace('T', ' ').slice(0, 19)) : 'maintenant';
    if (!(await confirmBox('Lancer « ' + $name.value.trim() + ' » : ' + fmt(s.recipients) + ' message(s) (' + fmt(s.total_segments) + ' SMS, ' + money(s.cost_micro, s.currency) + ') depuis « ' + s.sender_name + ' » ' + when + ' ?', { label: 'Lancer', title: 'Confirmer le lancement' }))) return;
    state.busy = true; $launch.disabled = true; $save.disabled = true;
    try { await persist(); await api('POST', '/api/campaigns/' + state.id + '/launch'); toast(s.scheduled_at ? 'Campagne programmée.' : 'Campagne lancée.'); location.href = url('/campaigns/' + state.id); }
    catch (e) { toastErr(e); state.busy = false; $launch.disabled = false; $save.disabled = false; }
  }
  function saveTemplate() {
    const text = $body.value.trim(); if (!text) { toastErr(new Error('Écrivez d’abord le message.')); return; }
    const name = textInput('name', '', { maxlength: 100, placeholder: 'Nom du modèle' }); const e = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: 'Enregistrer comme modèle', body: h('div', { class: 'space-y-3' }, field('Nom', name), e),
      actions: [{ label: 'Annuler' }, { label: 'Enregistrer', class: 'btn-primary', onclick: async (ev, close) => {
        try { const t = await api('POST', '/api/campaign-templates', { name: name.value, body: text }); close(); toast('Modèle enregistré.'); state.templates.push(t); $('#cf-template').append(h('option', { value: t.id }, t.name)); }
        catch (x) { e.textContent = err(x); e.classList.remove('hidden'); }
      } }] });
  }

  /* --------------------------------------------------------------- Init */
  const local = (utc) => { const d = new Date(utc.replace(' ', 'T') + 'Z'); const p = (n) => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()); };
  for (const v of [['Prénom', '{{first_name|client}}'], ['Nom', '{{last_name}}'], ['Nom complet', '{{name|client}}']]) $('#cf-vars').append(h('button', { type: 'button', class: 'chip', onclick: () => insertVar(v[1]) }, icon('braces', 'text-sm'), v[0]));
  $body.addEventListener('input', onChange); $name.addEventListener('input', () => { $launch.disabled = !state.last || state.busy || !$name.value.trim() || !state.last.enough_balance; });
  $sender.addEventListener('change', onChange); $date.addEventListener('change', onChange);
  for (const r of document.querySelectorAll('input[name=cf-when]')) r.addEventListener('change', () => { $('#cf-later').classList.toggle('hidden', mode() !== 'later'); onChange(); });
  $('#cf-template').addEventListener('change', (e) => { const t = state.templates.find((x) => String(x.id) === e.target.value); if (t) { $body.value = t.body; onChange(); } });
  $('#cf-save-template').addEventListener('click', saveTemplate); $save.addEventListener('click', saveDraft); $launch.addEventListener('click', launch);
  $('#cf-form').addEventListener('submit', (e) => e.preventDefault());

  (async () => {
    if (state.id) {
      try {
        const c = await api('GET', '/api/campaigns/' + state.id);
        if (c.status !== 'draft') { location.replace(url('/campaigns/' + c.id)); return; }
        $name.value = c.name; $body.value = c.body; state.orgId = String(c.organization_id);
        if (c.scheduled_at) { document.querySelector('input[name=cf-when][value=later]').checked = true; $('#cf-later').classList.remove('hidden'); $date.value = local(c.scheduled_at); }
        await loadResources(c);
      } catch (e) { toastErr(e); }
    } else if (cfg.global) {
      api('GET', '/api/organizations?per_page=100').then((orgs) => { for (const o of orgs) $('#cf-org').append(h('option', { value: o.id }, o.name)); }).catch(toastErr);
      $('#cf-org').addEventListener('change', (e) => { state.orgId = e.target.value; loadResources(); });
      await loadResources();
    } else await loadResources();
    updateCounter();
  })();
})();
