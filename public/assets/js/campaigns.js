/* TAL Messaging — Campagnes : liste, actions rapides et gestion des modèles. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, debounce, dateFr, modal, confirmBox, field, textInput, pager, empty, toastErr } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#cp-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);

  window.TALCampaign = window.TALCampaign || {};
  const STATUS = { draft: ['Brouillon', 'badge'], launching: ['Lancement…', 'badge badge-brand'], scheduled: ['Programmée', 'badge badge-brand'], running: ['En cours', 'badge badge-warn'],
    completed: ['Terminée', 'badge badge-ok'], cancelled: ['Annulée', 'badge badge-danger'] };
  const state = { page: 1, status: '' };
  let seq = 0;
  let active = false;

  async function load(silent = false) {
    const mine = ++seq;
    const q = new URLSearchParams({ page: state.page, per_page: 20 });
    if (state.status) q.set('status', state.status);
    if ($('#cp-q').value.trim()) q.set('q', $('#cp-q').value.trim());
    try {
      const rows = await api('GET', '/api/campaigns?' + q);
      if (mine !== seq) return;
      active = rows.some((c) => c.status === 'running' || c.status === 'scheduled');
      render(rows);
    } catch (err) { if (!silent) toastErr(err); }
  }

  function render(rows) {
    for (const t of document.querySelectorAll('#cp-tabs .tab')) t.setAttribute('aria-current', t.dataset.status === state.status ? 'page' : 'false');
    const body = clear($('#cp-rows'));
    for (const c of rows) body.append(row(c));
    clear($('#cp-state'));
    if (!rows.length) $('#cp-state').append(empty(state.status || $('#cp-q').value ? 'Aucune campagne pour ces critères.' : 'Aucune campagne. Créez la première !', 'speakerphone'));
    clear($('#cp-pager')).append(pager(rows.meta, (p) => { state.page = p; load(); }));
  }

  function row(c) {
    const [label, cls] = STATUS[c.status] || [c.status, 'badge'];
    const bar = h('div', { class: 'progress-bar' }); bar.style.width = c.progress + '%';
    const href = url(c.status === 'draft' ? '/campaigns/' + c.id + '/edit' : '/campaigns/' + c.id);
    const btn = (title, ic, fn, extra = '') => h('button', { type: 'button', class: 'btn btn-icon btn-sm btn-ghost ' + extra, title, 'aria-label': title, onclick: (e) => { e.stopPropagation(); fn(); } }, icon(ic));
    const acts = [];
    if (cfg.canManage) {
      acts.push(btn('Dupliquer', 'copy', () => duplicate(c)));
      if (c.status === 'draft') acts.push(btn('Modifier', 'pencil', () => { location.href = url('/campaigns/' + c.id + '/edit'); }));
      if (c.status === 'scheduled' || c.status === 'running') acts.push(btn('Annuler', 'player-stop', () => cancel(c)));
      if (['draft', 'completed', 'cancelled'].includes(c.status)) acts.push(btn('Supprimer', 'trash', () => remove(c)));
    }
    return h('tr', { class: 'cursor-pointer', dataset: { row: c.id }, onclick: () => { location.href = href; } },
      h('td', {}, h('p', { class: 'font-medium' }, c.name), h('p', { class: 'text-xs text-mute' }, (c.sender_name || 'Sans Sender ID') + (cfg.global ? ' · ' + c.organization_name : '') + (c.total_recipients ? ' · ' + fmt(c.total_recipients) + ' destinataires' : ''))),
      h('td', {}, h('span', { class: cls }, label)),
      h('td', { class: 'hidden min-w-[9rem] md:table-cell' }, c.batch_id ? [h('div', { class: 'progress' }, bar), h('p', { class: 'mt-1 text-xs text-mute' }, c.progress + ' %')] : h('span', { class: 'text-mute' }, '—')),
      h('td', { class: 'hidden tabular-nums lg:table-cell' }, c.delivery_rate === null ? '—' : c.delivery_rate + ' %'),
      h('td', { class: 'hidden whitespace-nowrap text-mute xl:table-cell' }, dateFr(c.scheduled_at || c.launched_at || c.created_at)),
      h('td', { class: 'whitespace-nowrap text-right' }, acts));
  }

  async function duplicate(c) {
    try { const n = await api('POST', '/api/campaigns/' + c.id + '/duplicate'); toast('Copie créée en brouillon.'); location.href = url('/campaigns/' + n.id + '/edit'); } catch (err) { toastErr(err); }
  }
  async function cancel(c) {
    if (!(await confirmBox('Annuler « ' + c.name + ' » ? Les messages pas encore partis ne seront pas envoyés.', { label: 'Annuler la campagne', danger: true }))) return;
    try { await api('POST', '/api/campaigns/' + c.id + '/cancel'); toast('Campagne annulée.'); load(); } catch (err) { toastErr(err); }
  }
  async function remove(c) {
    if (!(await confirmBox('Supprimer « ' + c.name + ' » ? Les messages déjà envoyés restent dans le Message Center.', { label: 'Supprimer', danger: true }))) return;
    try { await api('DELETE', '/api/campaigns/' + c.id); toast('Campagne supprimée.'); load(); } catch (err) { toastErr(err); }
  }

  /* ---------------------------------------------------------- Modèles */
  async function openTemplates() {
    const list = h('div', { class: 'space-y-2' });
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    const name = textInput('name', '', { maxlength: 100, placeholder: 'Nom du modèle' });
    const text = h('textarea', { class: 'input !h-24 py-2.5', maxlength: 1600, placeholder: 'Texte du modèle, avec variables si besoin : {{first_name|client}}' });
    let editing = null;
    const reset = () => { editing = null; name.value = ''; text.value = ''; save.textContent = 'Ajouter le modèle'; };
    const save = h('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: async () => {
      err.classList.add('hidden');
      try {
        if (editing) await api('PUT', '/api/campaign-templates/' + editing, { name: name.value, body: text.value });
        else await api('POST', '/api/campaign-templates', { name: name.value, body: text.value });
        reset(); refreshList(); toast('Modèle enregistré.');
      } catch (e) { err.textContent = e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : ''); err.classList.remove('hidden'); }
    } }, 'Ajouter le modèle');
    async function refreshList() {
      try {
        const rows = await api('GET', '/api/campaign-templates?per_page=100');
        clear(list);
        if (!rows.length) list.append(h('p', { class: 'text-sm text-mute' }, 'Aucun modèle pour le moment.'));
        for (const t of rows) list.append(h('div', { class: 'flex items-start gap-2 rounded-xl border border-line p-3' },
          h('div', { class: 'min-w-0 flex-1' }, h('p', { class: 'font-medium' }, t.name), h('p', { class: 'truncate text-xs text-mute' }, t.body)),
          h('button', { type: 'button', class: 'btn btn-icon btn-sm btn-ghost', title: 'Modifier', 'aria-label': 'Modifier', onclick: () => { editing = t.id; name.value = t.name; text.value = t.body; save.textContent = 'Enregistrer'; name.focus(); } }, icon('pencil')),
          h('button', { type: 'button', class: 'btn btn-icon btn-sm btn-ghost', title: 'Supprimer', 'aria-label': 'Supprimer', onclick: async () => { try { await api('DELETE', '/api/campaign-templates/' + t.id); refreshList(); } catch (e) { toastErr(e); } } }, icon('trash'))));
      } catch (e) { toastErr(e); }
    }
    modal({ title: 'Modèles de message', large: true, actions: [{ label: 'Fermer' }],
      body: h('div', { class: 'space-y-4' }, list, h('div', { class: 'space-y-3 border-t border-line pt-4' }, field('Nom', name), field('Texte', text), err, save)) });
    refreshList();
  }

  for (const t of document.querySelectorAll('#cp-tabs .tab')) t.addEventListener('click', () => { state.status = t.dataset.status; state.page = 1; load(); });
  $('#cp-q').addEventListener('input', debounce(() => { state.page = 1; load(); }));
  $('#cp-templates')?.addEventListener('click', openTemplates);
  setInterval(() => { if (active && !document.hidden) load(true); }, 8000);
  load();
})();
