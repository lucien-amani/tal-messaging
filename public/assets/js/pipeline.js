/* TAL Messaging — Pipeline commercial : prospects du site public, statut et notes. */
(() => {
  'use strict';
  const { $, h, icon, clear, modal, field, selectInput, empty, toastErr } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#pl-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const CLS = { new: 'badge badge-brand', contacted: 'badge badge-warn', qualified: 'badge badge-ok', won: 'badge badge-ok', lost: 'badge' };
  const date = (d) => new Date(d.replace(' ', 'T') + 'Z').toLocaleDateString('fr-FR');

  async function load() {
    try {
      const s = $('#pl-status').value;
      const rows = await api('GET', '/api/leads' + (s ? '?status=' + s : ''));
      const body = clear($('#pl-rows'));
      for (const l of rows) {
        body.append(h('tr', { class: 'cursor-pointer', dataset: { lead: l.id }, tabindex: 0, onclick: () => open(l), onkeydown: (e) => { if (e.key === 'Enter') open(l); } },
          h('td', {}, h('div', { class: 'font-medium' }, l.name), h('div', { class: 'text-xs text-mute' }, [l.company, l.email].filter(Boolean).join(' · '))),
          h('td', { class: 'hidden sm:table-cell max-w-xs truncate' }, l.message),
          h('td', { class: 'hidden md:table-cell' }, date(l.created_at)),
          h('td', {}, h('span', { class: CLS[l.status] }, cfg.statuses[l.status]))));
      }
      clear($('#pl-state'));
      if (!rows.length) $('#pl-state').append(empty('Aucun prospect pour le moment.', 'briefcase'));
    } catch (e) { toastErr(e); }
  }

  function open(l) {
    const status = selectInput('status', Object.entries(cfg.statuses), l.status);
    const notes = h('textarea', { class: 'input', rows: 4, maxlength: 5000, 'aria-label': 'Notes' }, l.notes || '');
    modal({ title: l.name, large: true,
      body: h('div', { class: 'space-y-4' },
        h('p', { class: 'text-sm' }, [l.company, l.email, l.phone, l.country, l.monthly_volume].filter(Boolean).join(' · ')),
        h('p', { class: 'whitespace-pre-wrap rounded-lg border border-line p-3 text-sm' }, l.message),
        field('Statut', status), field('Notes internes', notes),
        l.owner ? h('p', { class: 'text-xs text-mute' }, 'Suivi par ' + l.owner) : null),
      actions: [{ label: 'Fermer' }, { label: 'Créer le client', onclick: () => { location.href = url('/clients'); } }, { label: 'Enregistrer', class: 'btn-primary', onclick: async (e, close) => {
        try { await api('PUT', '/api/leads/' + l.id, { status: status.value, notes: notes.value }); close(); toast('Prospect mis à jour.'); load(); } catch (x) { toastErr(x); }
      } }] });
  }

  $('#pl-status').addEventListener('change', load);
  load();
})();
