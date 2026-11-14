/* TAL Messaging — Tickets : client (ouvrir/suivre) et équipe support (traiter, assigner, notes internes, recherche). */
(() => {
  'use strict';
  const { $, h, icon, clear, modal, field, textInput, selectInput, empty, pager, toastErr, opLabel } = window.TALUI;
  const { api, toast } = window.TAL;
  const root = $('#tk-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const state = { page: 1 };
  const PRIO = { low: ['Basse', 'badge'], normal: ['Normale', 'badge'], high: ['Haute', 'badge badge-danger'] };
  const ST = { open: 'badge badge-brand', pending: 'badge badge-warn', resolved: 'badge badge-ok', closed: 'badge' };
  const fmt = (d) => (d ? new Date(d.replace(' ', 'T') + 'Z').toLocaleString('fr-FR') : '—');
  const errText = (e) => e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : '');

  for (const [k, v] of Object.entries(cfg.statuses)) $('#tk-status').append(h('option', { value: k }, v));

  async function load() {
    try {
      const q = new URLSearchParams({ page: state.page, per_page: 20 });
      if ($('#tk-status').value) q.set('status', $('#tk-status').value);
      if (cfg.team && $('#tk-assigned').value) q.set('assigned', $('#tk-assigned').value);
      const rows = await api('GET', '/api/tickets?' + q);
      const body = clear($('#tk-rows'));
      for (const t of rows) {
        const [pl, pc] = PRIO[t.priority];
        body.append(h('tr', { class: 'cursor-pointer', dataset: { ticket: t.id }, tabindex: 0, onclick: () => open(t.id), onkeydown: (e) => { if (e.key === 'Enter') open(t.id); } },
          h('td', {}, h('div', { class: 'font-medium' }, t.subject), h('div', { class: 'text-xs text-mute' }, t.author + (t.assignee ? ' · assigné à ' + t.assignee : ''))),
          cfg.team ? h('td', { class: 'hidden md:table-cell' }, t.organization_name) : null,
          h('td', { class: 'hidden sm:table-cell' }, cfg.categories[t.category]),
          h('td', {}, h('span', { class: pc }, pl)),
          h('td', {}, h('span', { class: ST[t.status] }, cfg.statuses[t.status])),
          h('td', { class: 'hidden md:table-cell text-xs' }, fmt(t.last_reply_at) + (t.last_reply_by ? (t.last_reply_by === 'team' ? ' (équipe)' : ' (client)') : ''))));
      }
      clear($('#tk-state'));
      if (!rows.length) $('#tk-state').append(empty('Aucun ticket.', 'headset'));
      clear($('#tk-pager'));
      if (rows.meta && rows.meta.total > rows.meta.per_page) $('#tk-pager').append(pager(rows.meta, (p) => { state.page = p; load(); }));
    } catch (e) { toastErr(e); }
  }

  async function open(id) {
    let t;
    try { t = await api('GET', '/api/tickets/' + id); } catch (e) { toastErr(e); return; }
    const thread = h('div', { class: 'max-h-80 space-y-3 overflow-y-auto' }, t.replies.map((r) => h('div', { class: 'rounded-lg border border-line p-3 text-sm ' + (r.is_internal === '1' || r.is_internal === 1 ? 'bg-amber-50 dark:bg-amber-500/10' : '') },
      h('div', { class: 'mb-1 flex justify-between text-xs text-mute' }, h('span', {}, r.author + (Number(r.from_team) ? ' · équipe TAL' : '') + (Number(r.is_internal) ? ' · note interne' : '')), h('span', {}, fmt(r.created_at))),
      h('p', { class: 'whitespace-pre-wrap' }, r.body))));
    const text = h('textarea', { class: 'input', rows: 3, maxlength: 5000, 'aria-label': 'Votre réponse', placeholder: 'Votre réponse…' });
    const internal = cfg.team ? h('input', { type: 'checkbox', class: 'check' }) : null;
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    const status = cfg.team ? selectInput('status', Object.entries(cfg.statuses), t.status) : null;
    const agent = cfg.team ? selectInput('assigned_to', [['', 'Non assigné'], ...cfg.agents.map((a) => [a.id, a.name])], t.assigned_to || '') : null;
    const act = async (fn, msg, close) => { try { await fn(); toast(msg); close(); load(); } catch (x) { err.textContent = errText(x); err.classList.remove('hidden'); } };
    modal({ title: t.subject, large: true,
      body: h('div', { class: 'space-y-4' },
        h('p', { class: 'text-xs text-mute' }, `${cfg.categories[t.category]} · ${t.organization_name} · ouvert le ${fmt(t.created_at)}${t.reference ? ' · réf. ' + t.reference : ''}`),
        cfg.team ? h('div', { class: 'grid gap-3 sm:grid-cols-2' }, field('Statut', status), field('Assigné à', agent)) : null,
        thread, t.status === 'closed' ? h('p', { class: 'text-sm text-mute' }, 'Ticket fermé.') : h('div', { class: 'space-y-2' }, text, internal ? h('label', { class: 'flex items-center gap-2 text-sm' }, internal, 'Note interne (invisible pour le client)') : null), err),
      actions: [{ label: 'Fermer' },
        ...(cfg.team ? [{ label: 'Mettre à jour', onclick: async (e, close) => act(async () => { if (status.value !== t.status) await api('PUT', `/api/tickets/${id}/status`, { status: status.value }); if (String(agent.value) !== String(t.assigned_to || '')) await api('PUT', `/api/tickets/${id}/assign`, { assigned_to: agent.value || null }); }, 'Ticket mis à jour.', close) }]
          : (t.status !== 'closed' ? [{ label: 'Clore le ticket', onclick: (e, close) => act(() => api('PUT', `/api/tickets/${id}/status`, { status: 'closed' }), 'Ticket fermé.', close) }] : [])),
        ...(t.status !== 'closed' ? [{ label: 'Répondre', class: 'btn-primary', onclick: (e, close) => act(() => api('POST', `/api/tickets/${id}/replies`, { body: text.value, internal: !!internal?.checked }), 'Réponse envoyée.', close) }] : [])] });
  }

  function openNew() {
    const subject = textInput('subject', '', { maxlength: 160, placeholder: 'Résumé du problème' });
    const category = selectInput('category', Object.entries(cfg.categories), 'delivery');
    const priority = selectInput('priority', [['low', 'Basse'], ['normal', 'Normale'], ['high', 'Haute']], 'normal');
    const ref = textInput('reference', '', { maxlength: 64, placeholder: 'Identifiant de message ou de campagne (facultatif)' });
    const body = h('textarea', { class: 'input', rows: 5, maxlength: 5000, name: 'body' });
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: 'Nouveau ticket', large: true,
      body: h('div', { class: 'space-y-4' }, field('Sujet', subject), h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('Catégorie', category), field('Priorité', priority)), field('Référence', ref), field('Description', body), err),
      actions: [{ label: 'Annuler' }, { label: 'Envoyer', class: 'btn-primary', onclick: async (e, close) => {
        e.target.disabled = true;
        try { await api('POST', '/api/tickets', { subject: subject.value, category: category.value, priority: priority.value, reference: ref.value, body: body.value }); close(); toast('Ticket créé.'); load(); }
        catch (x) { err.textContent = errText(x); err.classList.remove('hidden'); e.target.disabled = false; }
      } }] });
  }

  async function search() {
    const q = $('#tk-search').value.trim();
    const box = clear($('#tk-found'));
    try {
      const r = await api('GET', '/api/support/search?q=' + encodeURIComponent(q));
      if (!r.messages.length && !r.campaigns.length) { box.append(h('p', { class: 'text-sm text-mute' }, 'Aucun résultat.')); return; }
      for (const m of r.messages) box.append(h('p', { class: 'text-sm', dataset: { found: 'message' } }, icon('message'), ` ${m.to_e164} · ${m.sender_name} · ${m.status}${m.error_code ? ' (' + m.error_code + ')' : ''} · ${m.provider || '—'} · ${m.organization_name} · ${fmt(m.created_at)} · `, h('code', { class: 'text-xs' }, m.uuid)));
      for (const c of r.campaigns) box.append(h('p', { class: 'text-sm', dataset: { found: 'campaign' } }, icon('speakerphone'), ` ${c.name} · ${c.status} · ${c.organization_name} · `, h('a', { class: 'link', href: window.TAL.url ? window.TAL.url('/campaigns/' + c.id) : '#' }, 'Ouvrir')));
    } catch (e) { toastErr(e); }
  }

  $('#tk-status').addEventListener('change', () => { state.page = 1; load(); });
  $('#tk-assigned')?.addEventListener('change', () => { state.page = 1; load(); });
  $('#tk-new')?.addEventListener('click', openNew);
  $('#tk-search-go')?.addEventListener('click', search);
  $('#tk-search')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') search(); });
  load();
})();
