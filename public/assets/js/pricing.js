/* TAL Messaging — Tarifs : prix par SMS publics et négociés (équipe TAL). */
(() => {
  'use strict';
  const { $, h, icon, clear, money, modal, confirmBox, field, textInput, selectInput, empty, opLabel, OPERATORS, toastErr } = window.TALUI;
  const { api, toast } = window.TAL;
  const root = $('#pr-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const state = { orgs: [] };
  const errText = (e) => e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : '');

  async function load() {
    try {
      const org = $('#pr-org').value;
      const rules = await api('GET', '/api/pricing-rules' + (org ? '?organization_id=' + org : ''));
      const body = clear($('#pr-rows'));
      for (const r of rules) body.append(row(r));
      clear($('#pr-state'));
      if (!rules.length) $('#pr-state').append(empty('Aucun tarif défini.', 'currency-dollar'));
    } catch (e) { toastErr(e); }
  }

  function row(r) {
    const btn = (t, ic, fn) => h('button', { type: 'button', class: 'btn btn-icon btn-sm btn-ghost', title: t, 'aria-label': t, onclick: fn }, icon(ic));
    return h('tr', { dataset: { rule: r.id } },
      h('td', {}, r.organization_id ? h('span', { class: 'badge badge-brand' }, r.organization_name) : h('span', { class: 'badge' }, 'Tarif public')),
      h('td', {}, r.country ? (cfg.countries[r.country] || r.country) : 'Tous les pays'),
      h('td', { class: 'hidden sm:table-cell' }, r.operator ? opLabel(r.operator) : 'Tous'),
      h('td', { class: 'text-right font-medium tabular-nums' }, money(r.price_micro, r.currency)),
      h('td', {}, r.active ? h('span', { class: 'badge badge-ok' }, 'Actif') : h('span', { class: 'badge' }, 'Désactivé')),
      h('td', { class: 'whitespace-nowrap text-right' }, btn('Modifier', 'pencil', () => openForm(r)), btn('Supprimer', 'trash', () => remove(r))));
  }

  function openForm(r = null) {
    const org = selectInput('organization_id', [['', 'Tarif public (tous les clients)'], ...state.orgs.map((o) => [o.id, o.name])], r?.organization_id || $('#pr-org').value || '');
    const country = selectInput('country', [['', 'Tous les pays'], ...Object.entries(cfg.countries)], r?.country || '');
    const operator = selectInput('operator', [['', 'Tous les opérateurs'], ...Object.entries(OPERATORS)], r?.operator || '');
    const currency = selectInput('currency', Object.entries(cfg.currencies), r?.currency || 'USD');
    const price = textInput('price', r ? String(r.price_micro / 1e6) : '', { inputmode: 'decimal', placeholder: 'Ex. 0.035' });
    const active = h('input', { type: 'checkbox', class: 'check', checked: r ? r.active : true });
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: r ? 'Modifier le tarif' : 'Nouveau tarif', large: true,
      body: h('div', { class: 'space-y-4' }, field('Portée', org), h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('Pays', country), field('Opérateur (nécessite un pays)', operator)),
        h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('Prix par SMS', price), field('Devise', currency)),
        h('label', { class: 'flex items-center gap-2 text-sm' }, active, 'Tarif actif'), err),
      actions: [{ label: 'Annuler' }, { label: 'Enregistrer', class: 'btn-primary', onclick: async (e, close) => {
        e.target.disabled = true;
        const body = { organization_id: org.value, country: country.value, operator: operator.value, currency: currency.value, price: price.value, active: active.checked };
        try { if (r) await api('PUT', '/api/pricing-rules/' + r.id, body); else await api('POST', '/api/pricing-rules', body); close(); toast('Tarif enregistré.'); load(); }
        catch (x) { err.textContent = errText(x); err.classList.remove('hidden'); e.target.disabled = false; }
      } }] });
  }

  async function remove(r) {
    if (!(await confirmBox('Supprimer ce tarif ? Les envois suivants utiliseront le tarif suivant le plus précis.', { label: 'Supprimer', danger: true }))) return;
    try { await api('DELETE', '/api/pricing-rules/' + r.id); toast('Tarif supprimé.'); load(); } catch (e) { toastErr(e); }
  }

  $('#pr-new').addEventListener('click', () => openForm());
  $('#pr-org').addEventListener('change', load);
  api('GET', '/api/organizations?per_page=100').then((o) => { state.orgs = o; for (const x of o) $('#pr-org').append(h('option', { value: x.id }, x.name)); }).catch(() => {}).finally(load);
})();
