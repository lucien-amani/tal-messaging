/* TAL Messaging — Équipe : liste, création (mot de passe temporaire), rôle, suspension, suppression. */
(() => {
  'use strict';
  const { $, h, icon, clear, modal, confirmBox, field, textInput, selectInput, empty, pager, debounce, toastErr } = window.TALUI;
  const { api, toast } = window.TAL;
  const root = $('#tm-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const state = { page: 1, roles: [], orgs: [] };
  const errText = (e) => e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : '');
  const fmt = (d) => (d ? new Date(d.replace(' ', 'T') + 'Z').toLocaleString('fr-FR') : 'Jamais');

  async function load() {
    try {
      const q = new URLSearchParams({ page: state.page, per_page: 20, sort: 'last_name', dir: 'asc' });
      if ($('#tm-q').value.trim()) q.set('q', $('#tm-q').value.trim());
      if (cfg.global && $('#tm-scope').value) q.set('scope', $('#tm-scope').value);
      const rows = await api('GET', '/api/users?' + q);
      const body = clear($('#tm-rows'));
      for (const u of rows) body.append(row(u));
      clear($('#tm-state'));
      if (!rows.length) $('#tm-state').append(empty('Aucun utilisateur.', 'users'));
      clear($('#tm-pager'));
      if (rows.meta && rows.meta.total > rows.meta.per_page) $('#tm-pager').append(pager(rows.meta, (p) => { state.page = p; load(); }));
    } catch (e) { toastErr(e); }
  }

  function row(u) {
    const self = u.id === cfg.me;
    const btn = (t, ic, fn) => h('button', { type: 'button', class: 'btn btn-icon btn-sm btn-ghost', title: t, 'aria-label': t, onclick: fn }, icon(ic));
    return h('tr', { dataset: { user: u.id } },
      h('td', {}, h('div', { class: 'font-medium' }, u.first_name + ' ' + u.last_name + (self ? ' (vous)' : '')), h('div', { class: 'text-xs text-mute' }, u.email)),
      h('td', { class: 'hidden sm:table-cell' }, u.role_name, u.organization_id ? '' : h('span', { class: 'badge ml-2' }, 'TAL')),
      h('td', { class: 'hidden md:table-cell text-xs' }, fmt(u.last_login_at)),
      h('td', {}, u.status === 'active' ? h('span', { class: 'badge badge-ok' }, 'Actif') : h('span', { class: 'badge badge-danger' }, 'Suspendu')),
      h('td', { class: 'whitespace-nowrap text-right' }, self ? null : [
        btn('Modifier le rôle', 'pencil', () => openRole(u)),
        btn(u.status === 'active' ? 'Suspendre' : 'Réactiver', u.status === 'active' ? 'player-pause' : 'player-play', () => toggle(u)),
        btn('Supprimer', 'trash', () => remove(u))]));
  }

  async function toggle(u) {
    const to = u.status === 'active' ? 'suspended' : 'active';
    if (to === 'suspended' && !(await confirmBox(`Suspendre ${u.first_name} ${u.last_name} ? Ses sessions seront fermées.`, { label: 'Suspendre', danger: true }))) return;
    try { await api('PUT', '/api/users/' + u.id, { status: to }); toast('Statut mis à jour.'); load(); } catch (e) { toastErr(e); }
  }
  async function remove(u) {
    if (!(await confirmBox(`Supprimer ${u.first_name} ${u.last_name} ? Cette action est définitive.`, { label: 'Supprimer', danger: true }))) return;
    try { await api('DELETE', '/api/users/' + u.id); toast('Utilisateur supprimé.'); load(); } catch (e) { toastErr(e); }
  }

  function openRole(u) {
    const roles = state.roles.filter((r) => r.scope === u.role_scope);
    const role = selectInput('role', roles.map((r) => [r.code, r.name]), u.role);
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: 'Rôle de ' + u.first_name, body: h('div', { class: 'space-y-4' }, field('Rôle', role), err),
      actions: [{ label: 'Annuler' }, { label: 'Enregistrer', class: 'btn-primary', onclick: async (e, close) => {
        try { await api('PUT', '/api/users/' + u.id, { role: role.value }); close(); toast('Rôle mis à jour.'); load(); } catch (x) { err.textContent = errText(x); err.classList.remove('hidden'); }
      } }] });
  }

  function openNew() {
    const first = textInput('first_name'); const last = textInput('last_name'); const email = textInput('email', '', { type: 'email', autocomplete: 'off' });
    const role = selectInput('role', state.roles.map((r) => [r.code, r.name + (r.scope === 'internal' ? ' (équipe TAL)' : '')]), (state.roles.find((r) => r.scope === 'client') || state.roles[0] || {}).code);
    const org = cfg.global ? selectInput('organization_id', [['', 'Aucune (compte interne)'], ...state.orgs.map((o) => [o.id, o.name])], '') : null;
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: 'Nouvel utilisateur', large: true,
      body: h('div', { class: 'space-y-4' }, h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('Prénom', first), field('Nom', last)), field('E-mail', email), h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('Rôle', role), org ? field('Organisation', org) : null), err),
      actions: [{ label: 'Annuler' }, { label: 'Créer', class: 'btn-primary', onclick: async (e, close) => {
        e.target.disabled = true;
        try {
          const r = await api('POST', '/api/users', { first_name: first.value, last_name: last.value, email: email.value, role: role.value, organization_id: org && org.value ? Number(org.value) : undefined });
          close(); load();
          if (r.temporary_password) modal({ title: 'Mot de passe temporaire', body: h('div', { class: 'space-y-3' }, h('p', { class: 'text-sm' }, 'Communiquez-le à l’utilisateur par un canal sûr. Il devra le changer à la première connexion et ne sera plus affiché.'), h('input', { class: 'input font-mono', readonly: true, value: r.temporary_password, 'aria-label': 'Mot de passe temporaire' })), actions: [{ label: 'Terminé' }] });
        } catch (x) { err.textContent = errText(x); err.classList.remove('hidden'); e.target.disabled = false; }
      } }] });
  }

  $('#tm-q').addEventListener('input', debounce(() => { state.page = 1; load(); }, 300));
  $('#tm-scope')?.addEventListener('change', () => { state.page = 1; load(); });
  $('#tm-new').addEventListener('click', openNew);
  Promise.all([api('GET', '/api/roles').then((r) => { state.roles = r; }), cfg.global ? api('GET', '/api/organizations?per_page=100').then((o) => { state.orgs = o; }) : null]).catch(toastErr).finally(load);
})();
