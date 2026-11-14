/* TAL Messaging — Message Center : messages, envois, annulation, rafraîchissement automatique. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, debounce, dateFr, modal, confirmBox, pager, empty, kpi, opLabel, toastErr } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#mc-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);

  const STATUS = { queued: ['En file', 'badge badge-warn'], sending: ['Envoi…', 'badge badge-brand'], sent: ['Envoyé', 'badge badge-brand'], delivered: ['Livré', 'badge badge-ok'],
    failed: ['Échec', 'badge badge-danger'], cancelled: ['Annulé', 'badge'] };
  const qs = new URLSearchParams(location.search);
  const state = { view: 'messages', page: 1, batch: Number(qs.get('batch')) || 0, active: false };
  const badge = (m) => {
    const scheduled = m.status === 'queued' && m.scheduled_at && new Date(m.scheduled_at.replace(' ', 'T') + 'Z') > new Date();
    const [l, c] = scheduled ? ['Programmé', 'badge badge-brand'] : (STATUS[m.status] || [m.status, 'badge']);
    return h('span', { class: c }, l);
  };
  const errText = (e) => e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : '');

  /* -------------------------------------------------------------- Chargement */
  let seq = 0;
  async function load(silent = false) {
    const mine = ++seq;
    const q = new URLSearchParams({ page: state.page, per_page: 25 });
    if (state.view === 'messages') {
      for (const [k, el] of [['status', '#mc-status'], ['date_from', '#mc-from'], ['date_to', '#mc-to'], ['q', '#mc-q']]) if ($(el).value.trim()) q.set(k, $(el).value.trim());
      if (state.batch) q.set('batch_id', state.batch);
    }
    try {
      const rows = await api('GET', (state.view === 'messages' ? '/api/messages?' : '/api/message-batches?') + q);
      if (mine !== seq) return;
      state.active = state.view === 'messages' ? rows.some((m) => m.status === 'queued' || m.status === 'sending') : rows.some((b) => b.n_queued > 0 || b.n_sending > 0);
      render(rows);
    } catch (err) { if (!silent) toastErr(err); }
  }

  function render(rows) {
    for (const t of document.querySelectorAll('#mc-views .tab')) t.setAttribute('aria-current', t.dataset.view === state.view ? 'page' : 'false');
    $('#mc-filters').classList.toggle('hidden', state.view !== 'messages');
    const chip = clear($('#mc-batch-chip'));
    chip.classList.toggle('hidden', !(state.batch && state.view === 'messages'));
    if (state.batch) chip.append(h('span', { class: 'chip' }, 'Envoi n° ' + state.batch, h('button', { type: 'button', class: 'chip-btn', 'aria-label': 'Retirer le filtre', onclick: () => { state.batch = 0; state.page = 1; load(); } }, icon('x', 'text-xs'))));
    const head = clear($('#mc-head')); const body = clear($('#mc-rows'));
    if (state.view === 'messages') {
      head.append(h('tr', {}, h('th', {}, 'Destinataire'), h('th', { class: 'hidden md:table-cell' }, 'Message'), h('th', { class: 'hidden sm:table-cell' }, 'Expéditeur'), h('th', {}, 'Statut'), h('th', { class: 'hidden lg:table-cell' }, 'SMS'), h('th', { class: 'hidden xl:table-cell' }, 'Créé le')));
      for (const m of rows) body.append(messageRow(m));
    } else {
      head.append(h('tr', {}, h('th', {}, 'Envoi'), h('th', { class: 'hidden md:table-cell' }, 'Message'), h('th', {}, 'Avancement'), h('th', { class: 'hidden lg:table-cell' }, 'SMS'), h('th', { class: 'w-28 text-right' }, '')));
      for (const b of rows) body.append(batchRow(b));
    }
    clear($('#mc-state'));
    if (!rows.length) $('#mc-state').append(empty(state.view === 'messages' ? 'Aucun message pour ces critères.' : 'Aucun envoi pour le moment.', 'message-off'));
    clear($('#mc-pager')).append(pager(rows.meta, (p) => { state.page = p; load(); }));
  }

  function messageRow(m) {
    return h('tr', { class: 'cursor-pointer', dataset: { row: m.id }, onclick: () => openMessage(m.id) },
      h('td', {}, h('p', { class: 'font-mono text-sm font-medium' }, m.to_e164), h('p', { class: 'text-xs text-mute' }, opLabel(m.operator))),
      h('td', { class: 'hidden max-w-xs md:table-cell' }, h('p', { class: 'truncate text-mute' }, m.body)),
      h('td', { class: 'hidden font-mono text-xs sm:table-cell' }, m.sender_name),
      h('td', {}, badge(m), m.error_message && m.status === 'failed' ? h('p', { class: 'mt-1 max-w-[12rem] truncate text-xs text-mute', title: m.error_message }, m.error_message) : null),
      h('td', { class: 'hidden tabular-nums text-mute lg:table-cell' }, m.segments),
      h('td', { class: 'hidden whitespace-nowrap text-mute xl:table-cell' }, dateFr(m.created_at)));
  }

  function batchRow(b) {
    const done = b.n_sent + b.n_failed + b.n_cancelled;
    const bar = h('div', { class: 'progress-bar' }); bar.style.width = Math.round((done / Math.max(1, b.total_messages)) * 100) + '%';
    const parts = [];
    if (b.n_delivered) parts.push(fmt(b.n_delivered) + ' livré(s)');
    if (b.n_sent - b.n_delivered > 0) parts.push(fmt(b.n_sent - b.n_delivered) + ' envoyé(s)');
    if (b.n_queued) parts.push(fmt(b.n_queued) + (b.scheduled_at && new Date(b.scheduled_at.replace(' ', 'T') + 'Z') > new Date() ? ' programmé(s)' : ' en file'));
    if (b.n_failed) parts.push(fmt(b.n_failed) + ' échec(s)');
    if (b.n_cancelled) parts.push(fmt(b.n_cancelled) + ' annulé(s)');
    return h('tr', { class: 'cursor-pointer', dataset: { batch: b.id }, onclick: () => { state.view = 'messages'; state.batch = b.id; state.page = 1; load(); } },
      h('td', {}, h('p', { class: 'font-medium' }, 'n° ' + b.id + ' · ' + fmt(b.total_messages) + ' msg'), h('p', { class: 'text-xs text-mute' }, dateFr(b.created_at) + ' · ' + b.sender_name + (b.created_by_name ? ' · ' + b.created_by_name : ''))),
      h('td', { class: 'hidden max-w-xs md:table-cell' }, h('p', { class: 'truncate text-mute', title: b.body }, b.body)),
      h('td', { class: 'min-w-[11rem]' }, h('div', { class: 'progress' }, bar), h('p', { class: 'mt-1 text-xs text-mute' }, parts.join(' · ') || '—')),
      h('td', { class: 'hidden tabular-nums text-mute lg:table-cell' }, fmt(b.total_segments)),
      h('td', { class: 'text-right' }, cfg.canSend && b.n_queued > 0
        ? h('button', { type: 'button', class: 'btn btn-outline btn-sm', title: 'Annuler les messages encore en file', onclick: (e) => { e.stopPropagation(); cancelBatch(b); } }, icon('player-stop'), 'Annuler') : null));
  }

  async function loadStats() {
    try {
      const s = await api('GET', '/api/messages/stats'); const b = s.by_status;
      clear($('#mc-kpis')).append(kpi('Aujourd’hui', fmt(s.today)), kpi('Livrés (30 j)', fmt(b.delivered), s.delivery_rate === null ? null : s.delivery_rate + ' % de réussite'),
        kpi('En file', fmt(b.queued + b.sending)), kpi('Échecs (30 j)', fmt(b.failed)), kpi('SMS facturables (30 j)', fmt(s.segments_30d)));
    } catch (_) { /* indicateurs facultatifs */ }
  }
  const refresh = () => Promise.all([load(), loadStats()]);

  /* ---------------------------------------------------------------- Actions */
  async function openMessage(id) {
    let m; try { m = await api('GET', '/api/messages/' + id); } catch (err) { toastErr(err); return; }
    const info = (l, v) => h('div', {}, h('dt', { class: 'text-xs uppercase tracking-wider text-mute' }, l), h('dd', { class: 'mt-0.5 text-sm font-medium' }, v || '—'));
    const body = h('div', { class: 'space-y-4' },
      m.status === 'failed' || m.status === 'cancelled' ? h('div', { class: 'alert alert-danger' }, icon('alert-circle', 'mt-0.5 text-lg'), h('span', {}, m.error_message || m.error_code || '—')) : null,
      h('dl', { class: 'grid grid-cols-2 gap-4' }, info('Statut', badge(m)), info('Destinataire', m.to_e164), info('Expéditeur', m.sender_name), info('Opérateur', opLabel(m.operator)),
        info('Segments', m.segments + ' (' + (m.encoding === 'gsm7' ? 'standard' : 'Unicode') + ')'), info('Tentatives', String(m.attempts)),
        info('Créé le', dateFr(m.created_at)), info(m.scheduled_at && m.status === 'queued' ? 'Départ prévu' : 'Envoyé le', dateFr(m.status === 'queued' ? m.scheduled_at : m.sent_at)), info('Livré le', m.delivered_at ? dateFr(m.delivered_at) : null)),
      h('div', {}, h('p', { class: 'label' }, 'Texte'), h('p', { class: 'whitespace-pre-wrap break-words rounded-xl bg-surface2 px-3 py-2 text-sm' }, m.body)));
    const actions = [{ label: 'Fermer' }];
    if (cfg.canSend && m.status === 'queued') actions.push({ label: 'Annuler ce message', class: 'btn-danger', onclick: async (e, close) => {
      e.target.disabled = true;
      try { await api('POST', '/api/messages/' + m.id + '/cancel'); close(); toast('Message annulé.'); refresh(); } catch (err) { toastErr(err); e.target.disabled = false; }
    } });
    modal({ title: 'Message à ' + m.to_e164, body, large: true, actions });
  }

  async function cancelBatch(b) {
    if (!(await confirmBox('Annuler les ' + fmt(b.n_queued) + ' message(s) de l’envoi n° ' + b.id + ' qui ne sont pas encore partis ? Ceux déjà envoyés ne sont pas concernés.', { label: 'Annuler les messages', danger: true }))) return;
    try { const r = await api('POST', '/api/message-batches/' + b.id + '/cancel'); toast(fmt(r.cancelled) + ' message(s) annulé(s).'); refresh(); } catch (err) { toastErr(err); }
  }

  /* -------------------------------------------------------------------- Init */
  for (const t of document.querySelectorAll('#mc-views .tab')) t.addEventListener('click', () => { state.view = t.dataset.view; state.page = 1; if (state.view === 'batches') state.batch = 0; load(); });
  for (const el of ['#mc-status', '#mc-from', '#mc-to']) $(el).addEventListener('change', () => { state.page = 1; load(); });
  $('#mc-q').addEventListener('input', debounce(() => { state.page = 1; load(); }));
  // Suivi en direct tant que des messages sont en file ; en pause quand l'onglet est caché
  setInterval(() => { if (state.active && !document.hidden) refresh(); }, 8000);
  refresh();
})();
