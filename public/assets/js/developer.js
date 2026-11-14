/* TAL Messaging — Developer Center : clés API (création, affichage unique, révocation). */
(() => {
  'use strict';
  const { $, h, icon, clear, modal, confirmBox, field, textInput, selectInput, empty, toastErr } = window.TALUI;
  const { api, toast } = window.TAL;
  const root = $('#dv-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const fmt = (d) => (d ? new Date(d.replace(' ', 'T') + 'Z').toLocaleString('fr-FR') : 'Jamais');

  async function load() {
    try {
      const keys = await api('GET', '/api/api-keys');
      const body = clear($('#dv-rows'));
      clear($('#dv-state'));
      for (const k of keys) {
        const revoked = !!k.revoked_at;
        body.append(h('tr', { dataset: { key: k.id } },
          h('td', { class: 'font-medium' }, k.name),
          cfg.global ? h('td', {}, k.organization_name) : null,
          h('td', { class: 'font-mono text-xs' }, k.prefix),
          h('td', { class: 'hidden sm:table-cell' }, k.scopes.map((s) => cfg.scopes[s] || s).join(', ')),
          h('td', { class: 'hidden md:table-cell' }, fmt(k.last_used_at)),
          h('td', {}, revoked ? h('span', { class: 'badge' }, 'Révoquée') : h('span', { class: 'badge badge-ok' }, 'Active')),
          h('td', { class: 'text-right' }, revoked ? null : h('button', { type: 'button', class: 'btn btn-sm btn-ghost', title: 'Révoquer', onclick: () => revoke(k) }, icon('ban'), 'Révoquer'))));
      }
      if (!keys.length) $('#dv-state').append(empty('Aucune clé API. Créez-en une pour intégrer l’envoi de SMS.', 'key'));
    } catch (e) { toastErr(e); }
  }

  async function revoke(k) {
    if (!(await confirmBox(`Révoquer « ${k.name} » ? Les applications qui l’utilisent cesseront de fonctionner immédiatement.`, { label: 'Révoquer', danger: true }))) return;
    try { await api('DELETE', '/api/api-keys/' + k.id); toast('Clé révoquée.'); load(); } catch (e) { toastErr(e); }
  }

  function showKey(key) {
    const input = h('input', { class: 'input font-mono text-xs', readonly: true, value: key, 'aria-label': 'Clé API' });
    modal({ title: 'Copiez votre clé maintenant',
      body: h('div', { class: 'space-y-3' }, h('p', { class: 'text-sm' }, 'Cette clé ne sera plus jamais affichée. Conservez-la dans un endroit sûr (variable d’environnement, coffre-fort de secrets).'), input),
      actions: [{ label: 'Copier', class: 'btn-primary', keepOpen: true, onclick: () => { input.select(); navigator.clipboard?.writeText(key).then(() => toast('Clé copiée.')); } }, { label: 'J’ai enregistré la clé' }] });
  }

  async function openForm() {
    const name = textInput('name', '', { placeholder: 'Ex. Site e-commerce (production)', maxlength: 80 });
    const checks = Object.entries(cfg.scopes).map(([k, label]) => ({ k, el: h('input', { type: 'checkbox', class: 'check', checked: true }), label }));
    let orgs = null;
    if (cfg.global) { try { orgs = selectInput('organization_id', (await api('GET', '/api/organizations?per_page=100')).map((o) => [o.id, o.name]), ''); } catch (e) { toastErr(e); return; } }
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: 'Nouvelle clé API',
      body: h('div', { class: 'space-y-4' }, orgs ? field('Organisation', orgs) : null, field('Nom de la clé', name),
        h('div', { class: 'space-y-2' }, h('p', { class: 'text-sm font-medium' }, 'Droits'), ...checks.map((c) => h('label', { class: 'flex items-center gap-2 text-sm' }, c.el, c.label))), err),
      actions: [{ label: 'Annuler' }, { label: 'Créer la clé', class: 'btn-primary', onclick: async (e, close) => {
        e.target.disabled = true;
        try {
          const r = await api('POST', '/api/api-keys', { name: name.value, organization_id: orgs?.value, scopes: checks.filter((c) => c.el.checked).map((c) => c.k) });
          close(); load(); showKey(r.key);
        } catch (x) { err.textContent = x.message + (x.fields ? ' ' + Object.values(x.fields).flat().join(' ') : ''); err.classList.remove('hidden'); e.target.disabled = false; }
      } }] });
  }

  $('#dv-new').addEventListener('click', openForm);
  load();
})();
