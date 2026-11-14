/* TAL Messaging — Fournisseurs : configuration des API d'envoi, router et accusés de réception (CEO/CTO). */
(() => {
  'use strict';
  const { $, h, icon, clear, field, textInput, empty, toastErr, confirmBox } = window.TALUI;
  const { api, toast } = window.TAL;
  const root = $('#pv-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const STATE = { active: ['badge-ok', 'Actif'], ready: ['badge-warn', 'Configuré, désactivé'], unconfigured: ['badge', 'Non configuré'] };
  const fmt = (d) => (d ? new Date(d.replace(' ', 'T') + 'Z').toLocaleString('fr-FR') : '—');

  function banner(mode) {
    const sim = mode.simulation;
    clear($('#pv-banner')).append(h('div', { class: 'alert ' + (sim ? 'alert-warn' : 'alert-ok'), role: 'status' },
      icon(sim ? 'flask' : 'plug-connected'),
      h('div', {}, h('strong', {}, sim ? 'Mode simulation' : 'Envoi réel actif'),
        h('p', { class: 'text-sm' }, mode.forced
          ? 'MESSAGING_PROVIDER=simulator est défini dans .env : l’envoi réel est coupé.'
          : sim ? 'Aucun fournisseur n’est activé et configuré : les messages sont simulés.'
            : 'Ordre d’essai : ' + mode.active.join(' → ') + '. En cas de panne, le fournisseur suivant prend le relais.'))));
  }

  function card(p) {
    const inputs = {};
    const fields = p.fields.map((f) => {
      let el;
      if (f.type === 'bool') el = h('input', { type: 'checkbox', class: 'check', checked: !!f.value });
      else el = textInput(f.key, f.secret ? '' : (f.value || ''), { type: f.secret ? 'password' : 'text', autocomplete: 'off', placeholder: f.secret && f.has_value ? '•••••••• (conservé)' : '' });
      inputs[f.key] = el;
      return f.type === 'bool' ? h('label', { class: 'flex items-center gap-2 text-sm' }, el, f.label) : field(f.label + (f.required ? ' *' : ''), el);
    });
    const priority = textInput('priority', String(p.priority), { inputmode: 'numeric' });
    const countries = h('select', { class: 'input', multiple: true, size: 4, 'aria-label': 'Pays desservis' },
      Object.entries(cfg.countries).map(([c, n]) => h('option', { value: c, selected: p.countries.includes(c) }, n)));
    const enabled = h('input', { type: 'checkbox', class: 'check', checked: p.enabled });
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    const [cls, label] = STATE[p.state];
    const tested = p.last_test_at
      ? h('p', { class: 'text-xs ' + (p.last_test_ok ? 'text-green-700' : 'text-red-600') }, (p.last_test_ok ? '✓ ' : '✗ ') + p.last_test_message + ' · ' + fmt(p.last_test_at))
      : h('p', { class: 'text-xs text-mute' }, 'Connexion jamais testée.');
    const build = () => ({ enabled: enabled.checked, priority: priority.value, countries: [...countries.selectedOptions].map((o) => o.value),
      fields: Object.fromEntries(Object.entries(inputs).map(([k, el]) => [k, el.type === 'checkbox' ? el.checked : el.value])) });
    const run = (btn, fn) => async () => {
      btn.disabled = true; err.classList.add('hidden');
      try { await fn(); } catch (e) { err.textContent = e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : ''); err.classList.remove('hidden'); }
      btn.disabled = false;
    };
    const save = h('button', { type: 'button', class: 'btn btn-primary' }, 'Enregistrer');
    const test = h('button', { type: 'button', class: 'btn' }, icon('plug-connected'), 'Tester la connexion');
    save.onclick = run(save, async () => { await api('PUT', '/api/providers/' + p.code, build()); toast('Fournisseur enregistré.'); load(); });
    test.onclick = run(test, async () => { const r = await api('POST', '/api/providers/' + p.code + '/test'); toast(r.last_test_ok ? 'Connexion réussie.' : 'Échec : ' + r.last_test_message); load(); });
    const rotate = async () => {
      if (!(await confirmBox('Régénérer l’URL ? L’ancienne cessera de fonctionner : mettez-la à jour chez le fournisseur.', { label: 'Régénérer' }))) return;
      try { await api('POST', '/api/providers/' + p.code + '/rotate-webhook'); load(); } catch (e) { toastErr(e); }
    };
    const hook = p.webhook_url ? h('div', { class: 'space-y-1' },
      h('p', { class: 'text-xs font-medium' }, 'URL des accusés de réception'),
      h('div', { class: 'flex gap-2' },
        h('input', { class: 'input font-mono text-xs', readonly: true, value: p.webhook_url, 'aria-label': 'URL webhook' }),
        h('button', { type: 'button', class: 'btn btn-icon', title: 'Copier', 'aria-label': 'Copier', onclick: () => navigator.clipboard?.writeText(p.webhook_url).then(() => toast('URL copiée.')) }, icon('copy')),
        h('button', { type: 'button', class: 'btn btn-icon', title: 'Régénérer', 'aria-label': 'Régénérer', onclick: rotate }, icon('refresh'))),
      h('p', { class: 'text-xs text-mute' }, p.dlr)) : null;
    return h('section', { class: 'card space-y-4 p-5', dataset: { provider: p.code } },
      h('div', { class: 'flex items-start justify-between gap-3' },
        h('div', {}, h('h3', { class: 'text-lg font-semibold' }, p.label), h('p', { class: 'text-sm text-mute' }, p.description)),
        h('span', { class: 'badge ' + cls }, label)),
      ...fields,
      h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('Priorité (1 = en premier)', priority), field('Pays desservis (aucun = tous)', countries)),
      h('label', { class: 'flex items-center gap-2 text-sm font-medium' }, enabled, 'Activer ce fournisseur dans le router'),
      hook, tested,
      h('p', { class: 'text-xs text-mute' }, `Envoyés : ${p.sent_count} · Échecs : ${p.failed_count} · Dernier succès : ${fmt(p.last_success_at)}`),
      err, h('div', { class: 'flex flex-wrap gap-2' }, save, p.configured ? test : null));
  }

  async function load() {
    try {
      const r = await api('GET', '/api/providers');
      banner(r.mode);
      const list = clear($('#pv-list'));
      clear($('#pv-state'));
      for (const p of r.providers) list.append(card(p));
      if (!r.providers.length) $('#pv-state').append(empty('Aucun fournisseur disponible. Lancez php database/migrate.php.', 'plug'));
    } catch (e) { toastErr(e); }
  }
  load();
})();
