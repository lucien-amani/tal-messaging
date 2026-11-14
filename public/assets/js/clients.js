/* TAL Messaging — Clients : liste des organisations, fiche client (activité, wallet, accès liés), création et statut. */
(() => {
  'use strict';
  const { $, h, icon, clear, modal, field, textInput, selectInput, empty, pager, debounce, money, fmt, toastErr } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#cl-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const state = { page: 1 };
  const ST = { active: ['Actif', 'badge badge-ok'], pending: ['En attente', 'badge badge-warn'], suspended: ['Suspendu', 'badge badge-danger'], closed: ['Fermé', 'badge'] };
  const errText = (e) => e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : '');
  const date = (d) => (d ? new Date(d.replace(' ', 'T') + 'Z').toLocaleDateString('fr-FR') : '—');

  async function load() {
    try {
      const q = new URLSearchParams({ page: state.page, per_page: 20 });
      if ($('#cl-q').value.trim()) q.set('q', $('#cl-q').value.trim());
      if ($('#cl-status').value) q.set('status', $('#cl-status').value);
      const rows = await api('GET', '/api/organizations?' + q);
      const body = clear($('#cl-rows'));
      for (const o of rows) {
        const [l, c] = ST[o.status] || [o.status, 'badge'];
        body.append(h('tr', { dataset: { org: o.id } }, h('td', {}, h('div', { class: 'font-medium' }, o.name), h('div', { class: 'text-xs text-mute' }, o.email || '')),
          h('td', { class: 'hidden sm:table-cell' }, cfg.countries[o.country] || o.country), h('td', { class: 'hidden md:table-cell' }, date(o.created_at)), h('td', {}, h('span', { class: c }, l)),
          h('td', { class: 'text-right' }, h('button', { type: 'button', class: 'btn btn-sm', onclick: () => overview(o) }, 'Ouvrir'))));
      }
      clear($('#cl-state'));
      if (!rows.length) $('#cl-state').append(empty('Aucun client.', 'building'));
      clear($('#cl-pager'));
      if (rows.meta && rows.meta.total > rows.meta.per_page) $('#cl-pager').append(pager(rows.meta, (p) => { state.page = p; load(); }));
    } catch (e) { toastErr(e); }
  }

  async function overview(o) {
    let d;
    try { d = await api('GET', `/api/clients/${o.id}/overview`); } catch (e) { toastErr(e); return; }
    const m = d.messages_30d;
    const sender = d.sender_ids.map((s) => `${s.c} ${s.status}`).join(', ') || 'aucun';
    const stat = (l, v) => h('div', { class: 'kpi' }, h('p', { class: 'text-xs uppercase tracking-wider text-mute' }, l), h('p', { class: 'mt-1 text-lg font-bold' }, v));
    const status = cfg.canManage ? selectInput('status', Object.entries(ST).map(([k, v]) => [k, v[0]]), o.status) : null;
    const link = (label, path, ic) => h('a', { class: 'btn btn-outline btn-sm', href: url(path) }, icon(ic), label);
    modal({ title: d.organization.name, large: true,
      body: h('div', { class: 'space-y-5' },
        h('div', { class: 'grid gap-3 sm:grid-cols-3' }, stat('Solde', d.wallet ? money(d.wallet.available_micro, d.wallet.currency) : '—'), stat('Messages (30 j)', fmt(m.total)), stat('Livrés / échecs', `${fmt(m.delivered)} / ${fmt(m.failed)}`),
          stat('Utilisateurs', fmt(d.users)), stat('Campagnes', fmt(d.campaigns)), stat('Tickets ouverts', fmt(d.open_tickets))),
        h('p', { class: 'text-sm text-mute' }, `Sender IDs : ${sender} · Clés API actives : ${d.api_keys} · Dernier envoi : ${date(m.last_at)}`),
        status ? field('Statut du compte', status) : null,
        h('div', { class: 'flex flex-wrap gap-2' }, cfg.perms.impersonate ? link('Voir son espace client', '/client?organization_id=' + o.id, 'eye') : null, cfg.perms.wallet ? link('Wallet', '/billing', 'wallet') : null, cfg.perms.team ? link('Utilisateurs', '/team', 'users') : null, cfg.perms.tickets ? link('Tickets', '/tickets', 'headset') : null)),
      actions: [{ label: 'Fermer' }, ...(status ? [{ label: 'Enregistrer le statut', class: 'btn-primary', onclick: async (e, close) => { try { await api('PUT', '/api/organizations/' + o.id, { status: status.value }); close(); toast('Statut mis à jour.'); load(); } catch (x) { toastErr(x); } } }] : [])] });
  }

  function openNew() {
    const f = (n, v = '', a = {}) => textInput(n, v, a);
    const name = f('name'); const email = f('email', '', { type: 'email' }); const phone = f('phone');
    const country = selectInput('country', Object.entries(cfg.countries), 'CD');
    const ofirst = f('owner_first_name'); const olast = f('owner_last_name'); const oemail = f('owner_email', '', { type: 'email' });
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: 'Nouveau client', large: true,
      body: h('div', { class: 'space-y-4' }, field('Nom de l’entreprise', name), h('div', { class: 'grid gap-4 sm:grid-cols-3' }, field('E-mail', email), field('Téléphone', phone), field('Pays', country)),
        h('p', { class: 'pt-2 text-sm font-medium' }, 'Premier utilisateur (Owner)'), h('div', { class: 'grid gap-4 sm:grid-cols-3' }, field('Prénom', ofirst), field('Nom', olast), field('E-mail', oemail)), err),
      actions: [{ label: 'Annuler' }, { label: 'Créer', class: 'btn-primary', onclick: async (e, close) => {
        e.target.disabled = true;
        try {
          const r = await api('POST', '/api/organizations', { name: name.value, email: email.value || null, phone: phone.value || null, country: country.value, owner_email: oemail.value || null, owner_first_name: ofirst.value || null, owner_last_name: olast.value || null });
          close(); load(); toast('Client créé.');
          if (r.temporary_password) modal({ title: 'Mot de passe temporaire de l’Owner', body: h('div', { class: 'space-y-3' }, h('p', { class: 'text-sm' }, 'À transmettre par un canal sûr. Affiché une seule fois.'), h('input', { class: 'input font-mono', readonly: true, value: r.temporary_password, 'aria-label': 'Mot de passe temporaire' })), actions: [{ label: 'Terminé' }] });
        } catch (x) { err.textContent = errText(x); err.classList.remove('hidden'); e.target.disabled = false; }
      } }] });
  }

  $('#cl-q').addEventListener('input', debounce(() => { state.page = 1; load(); }, 300));
  $('#cl-status').addEventListener('change', () => { state.page = 1; load(); });
  $('#cl-new')?.addEventListener('click', openNew);
  load();
})();
