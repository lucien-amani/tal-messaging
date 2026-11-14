/* TAL Messaging — Sender IDs : demandes du client et file de validation de TAL. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, debounce, dateFr, modal, confirmBox, field, textInput, selectInput, pager, empty, kpi, toastErr } = window.TALUI;
  const { api, toast } = window.TAL;
  const root = $('#sender-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);

  const STATUS = {
    pending: ['En attente', 'badge badge-warn'], approved: ['Approuvé', 'badge badge-ok'],
    rejected: ['Rejeté', 'badge badge-danger'], suspended: ['Suspendu', 'badge badge-danger'],
  };
  const EVENTS = { requested: 'Demande envoyée', resubmitted: 'Demande corrigée et renvoyée', updated: 'Demande modifiée', approved: 'Approuvé par TAL',
    rejected: 'Rejeté par TAL', suspended: 'Suspendu par TAL', reactivated: 'Réactivé par TAL', set_default: 'Défini par défaut', cancelled: 'Retiré' };

  const state = { page: 1, status: cfg.canReview ? 'pending' : '', orgs: [] };
  const badge = (s) => { const [l, c] = STATUS[s] || [s, 'badge']; return h('span', { class: c }, l); };
  const fieldsError = (e) => e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : '');

  /* -------------------------------------------------------------- Liste */
  let seq = 0;
  async function load() {
    const mine = ++seq;
    const q = new URLSearchParams({ page: state.page, per_page: 20 });
    if (state.status) q.set('status', state.status);
    if ($('#si-q').value.trim()) q.set('q', $('#si-q').value.trim());
    if (cfg.global && $('#si-org')?.value) q.set('organization_id', $('#si-org').value);
    try {
      const rows = await api('GET', '/api/sender-ids?' + q);
      if (mine !== seq) return;
      render(rows);
    } catch (err) { toastErr(err); }
  }

  function render(rows) {
    for (const t of document.querySelectorAll('#si-tabs .tab')) t.setAttribute('aria-current', t.dataset.status === state.status ? 'page' : 'false');
    const body = clear($('#si-rows'));
    for (const s of rows) body.append(row(s));
    clear($('#si-state'));
    if (!rows.length) $('#si-state').append(empty(state.status === 'pending' && cfg.canReview ? 'Aucune demande en attente.' : 'Aucun Sender ID.', 'id-badge-2'));
    clear($('#si-pager')).append(pager(rows.meta, (p) => { state.page = p; load(); }));
  }

  function actions(s) {
    const a = [];
    const btn = (label, ic, fn, cls = 'btn-ghost') => h('button', { type: 'button', class: 'btn btn-icon btn-sm ' + cls, title: label, 'aria-label': label, onclick: (e) => { e.stopPropagation(); fn(); } }, icon(ic));
    if (cfg.canReview) {
      if (s.status === 'pending') a.push(btn('Approuver', 'check', () => decide(s, 'approve'), 'btn-soft'), btn('Rejeter', 'x', () => decide(s, 'reject')));
      if (s.status === 'approved') a.push(btn('Suspendre', 'player-pause', () => decide(s, 'suspend')));
      if (s.status === 'suspended') a.push(btn('Réactiver', 'player-play', () => decide(s, 'reactivate'), 'btn-soft'));
    }
    if (cfg.canManage) {
      if (s.status === 'approved' && !s.is_default) a.push(btn('Définir par défaut', 'star', () => makeDefault(s)));
      if (s.status === 'pending' || s.status === 'rejected') a.push(btn(s.status === 'rejected' ? 'Corriger et renvoyer' : 'Modifier', 'pencil', () => openForm(s)));
      if (s.status !== 'suspended' || cfg.global) a.push(btn(s.status === 'pending' ? 'Annuler la demande' : 'Retirer', 'trash', () => remove(s)));
    }
    return a;
  }

  function row(s) {
    return h('tr', { dataset: { row: s.id }, class: 'cursor-pointer', onclick: () => openCard(s.id) },
      h('td', {},
        h('p', { class: 'flex items-center gap-1.5 font-mono text-sm font-semibold' }, s.name, s.is_default ? h('span', { class: 'text-brand', title: 'Sender ID par défaut' }, icon('star-filled', 'text-sm')) : null),
        h('p', { class: 'text-xs text-mute' }, cfg.countries[s.country] || s.country)),
      cfg.global ? h('td', { class: 'hidden md:table-cell' }, s.organization_name) : null,
      h('td', { class: 'hidden text-mute sm:table-cell' }, (cfg.purposes[s.purpose] || s.purpose).split(' (')[0]),
      h('td', {}, badge(s.status), s.status_reason && (s.status === 'rejected' || s.status === 'suspended') ? h('p', { class: 'mt-1 max-w-[14rem] truncate text-xs text-mute', title: s.status_reason }, s.status_reason) : null),
      h('td', { class: 'hidden whitespace-nowrap text-mute lg:table-cell' }, dateFr(s.created_at)),
      h('td', { class: 'whitespace-nowrap text-right' }, actions(s)));
  }

  async function loadStats() {
    try {
      const s = await api('GET', '/api/sender-ids/stats');
      const b = s.by_status;
      const avg = s.avg_review_minutes;
      clear($('#si-kpis')).append(
        kpi('En attente', fmt(b.pending), s.oldest_pending_at ? 'plus ancienne : ' + dateFr(s.oldest_pending_at) : 'aucune'),
        kpi('Approuvés', fmt(b.approved)), kpi('Rejetés / suspendus', fmt(b.rejected) + ' / ' + fmt(b.suspended)),
        kpi('Délai moyen de validation', avg === null ? '—' : avg < 90 ? avg + ' min' : avg < 2880 ? Math.round(avg / 60) + ' h' : Math.round(avg / 1440) + ' j'));
    } catch (_) { /* indicateurs facultatifs */ }
  }

  const refresh = () => Promise.all([load(), loadStats()]);

  /* ----------------------------------------------------------- Décisions */
  async function decide(s, decision) {
    const needsReason = decision === 'reject' || decision === 'suspend';
    const verbs = { approve: ['Approuver', 'Approuver « ' + s.name + ' » pour ' + s.organization_name + ' ?'], reject: ['Rejeter', 'Rejeter « ' + s.name + ' » ?'],
      suspend: ['Suspendre', 'Suspendre « ' + s.name + ' » ? Il ne pourra plus être utilisé pour envoyer.'], reactivate: ['Réactiver', 'Réactiver « ' + s.name + ' » ?'] };
    if (!needsReason) {
      if (!(await confirmBox(verbs[decision][1], { label: verbs[decision][0] }))) return;
      try { await api('POST', '/api/sender-ids/' + s.id + '/review', { decision }); toast('Décision enregistrée.'); refresh(); } catch (err) { toastErr(err); }
      return;
    }
    const reason = h('textarea', { class: 'input !h-24 py-2.5', maxlength: 255, placeholder: 'Ex. Nom trop générique : indiquez le nom de votre entreprise.' });
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: verbs[decision][0] + ' le Sender ID', body: h('div', { class: 'space-y-3' }, h('p', { class: 'text-sm' }, verbs[decision][1]), field('Motif (communiqué au client)', reason), err),
      actions: [{ label: 'Annuler' }, { label: verbs[decision][0], class: 'btn-danger', onclick: async (e, close) => {
        e.target.disabled = true;
        try { await api('POST', '/api/sender-ids/' + s.id + '/review', { decision, reason: reason.value }); close(); toast('Décision enregistrée.'); refresh(); } catch (ex) {
          err.textContent = fieldsError(ex); err.classList.remove('hidden'); e.target.disabled = false;
        }
      } }] });
  }

  async function makeDefault(s) {
    try { await api('POST', '/api/sender-ids/' + s.id + '/default'); toast('« ' + s.name + ' » est maintenant votre Sender ID par défaut.'); refresh(); } catch (err) { toastErr(err); }
  }

  async function remove(s) {
    const msg = s.status === 'pending' ? 'Annuler la demande « ' + s.name + ' » ?' : 'Retirer « ' + s.name + ' » ? Le nom redeviendra disponible pour tous et ne pourra plus être utilisé.';
    if (!(await confirmBox(msg, { label: s.status === 'pending' ? 'Annuler la demande' : 'Retirer', danger: true }))) return;
    try { await api('DELETE', '/api/sender-ids/' + s.id); toast('Sender ID retiré.'); refresh(); } catch (err) { toastErr(err); }
  }

  /* -------------------------------------------------------------- Fiche */
  async function openCard(id) {
    let s; let ev;
    try { [s, ev] = await Promise.all([api('GET', '/api/sender-ids/' + id), api('GET', '/api/sender-ids/' + id + '/events')]); } catch (err) { toastErr(err); return; }
    const info = (l, v) => h('div', {}, h('dt', { class: 'text-xs uppercase tracking-wider text-mute' }, l), h('dd', { class: 'mt-0.5 text-sm font-medium' }, v || '—'));
    const body = h('div', { class: 'space-y-5' },
      s.status === 'rejected' || s.status === 'suspended' ? h('div', { class: 'alert alert-danger' }, icon('alert-circle', 'mt-0.5 text-lg'), h('span', {}, h('strong', {}, s.status === 'rejected' ? 'Motif du rejet : ' : 'Motif de la suspension : '), s.status_reason || '—')) : null,
      h('dl', { class: 'grid grid-cols-2 gap-4' },
        info('Statut', badge(s.status)), info('Pays', cfg.countries[s.country] || s.country),
        info('Usage', cfg.purposes[s.purpose] || s.purpose), info('Organisation', s.organization_name),
        info('Demandé le', dateFr(s.created_at)), info('Décision', s.reviewed_at ? dateFr(s.reviewed_at) + (s.reviewed_by_name ? ' · ' + s.reviewed_by_name : '') : null)),
      s.description ? h('div', {}, h('p', { class: 'label' }, 'Justification'), h('p', { class: 'text-sm' }, s.description)) : null,
      s.sample_message ? h('div', {}, h('p', { class: 'label' }, 'Exemple de message'), h('p', { class: 'rounded-xl bg-surface2 px-3 py-2 text-sm' }, s.sample_message)) : null,
      h('div', {}, h('p', { class: 'label' }, 'Historique'), h('ol', { class: 'space-y-2 border-l border-line pl-4' }, ev.map((e) => h('li', { class: 'text-sm' },
        h('p', { class: 'font-medium' }, EVENTS[e.action] || e.action, h('span', { class: 'ml-2 text-xs font-normal text-mute' }, dateFr(e.created_at) + (Number(e.by_tal) === 1 ? ' · TAL' : ''))),
        e.note ? h('p', { class: 'text-xs text-mute' }, e.note) : null)))));
    modal({ title: s.name, body, large: true, actions: [{ label: 'Fermer' }] });
  }

  /* ------------------------------------------------------------ Demande */
  function openForm(s = null) {
    const org = cfg.global && !s ? selectInput('organization_id', [['', 'Choisir…'], ...state.orgs.map((o) => [o.id, o.name])], $('#si-org')?.value || '') : null;
    const name = textInput('name', s?.name, { maxlength: cfg.limits.max, placeholder: 'Ex. MABOUTIQUE', class: 'input font-mono uppercase-none' });
    const hint = h('p', { class: 'hint' }, cfg.limits.min + ' à ' + cfg.limits.max + ' caractères : lettres et chiffres, sans accent.');
    name.addEventListener('input', () => {
      const v = name.value.trim();
      const ok = v.length >= cfg.limits.min && v.length <= cfg.limits.max && /^[A-Za-z0-9][A-Za-z0-9 ._-]*$/.test(v) && /[A-Za-z]/.test(v);
      hint.textContent = v.length + ' / ' + cfg.limits.max + (v === '' ? '' : ok ? ' · format valide' : ' · format invalide');
    });
    const country = selectInput('country', Object.entries(cfg.countries), s?.country || cfg.defaultCountry);
    const purpose = selectInput('purpose', Object.entries(cfg.purposes), s?.purpose || 'transactional');
    const desc = h('textarea', { class: 'input !h-20 py-2.5', maxlength: 500, placeholder: 'Ex. Nom commercial de notre boutique, utilisé pour confirmer les commandes.' }, s?.description || '');
    const sample = h('textarea', { class: 'input !h-20 py-2.5', maxlength: 320, placeholder: 'Ex. MABOUTIQUE : votre commande #1234 est prête. Merci !' }, s?.sample_message || '');
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    const resubmit = s && s.status === 'rejected';
    const m = modal({ title: s ? (resubmit ? 'Corriger et renvoyer la demande' : 'Modifier la demande') : 'Demander un Sender ID', large: true,
      body: h('form', { class: 'space-y-4', novalidate: true, onsubmit: (e) => { e.preventDefault(); $('.btn-primary', m.foot).click(); } },
        resubmit ? h('div', { class: 'alert alert-danger' }, icon('alert-circle', 'mt-0.5 text-lg'), h('span', {}, h('strong', {}, 'Rejeté : '), s.status_reason || '—')) : null,
        org ? field('Organisation', org) : null,
        field('Nom affiché chez le destinataire', name, null), hint,
        h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('Pays', country), field('Usage prévu', purpose)),
        field('Justification (facultatif, accélère la validation)', desc),
        field('Exemple de message (facultatif)', sample), err),
      actions: [{ label: 'Annuler' }, { label: s ? (resubmit ? 'Renvoyer pour validation' : 'Enregistrer') : 'Envoyer la demande', class: 'btn-primary', onclick: async (e, close) => {
        const body = { name: name.value, country: country.value, purpose: purpose.value, description: desc.value, sample_message: sample.value };
        if (org) body.organization_id = org.value;
        e.target.disabled = true;
        try {
          if (s) await api('PUT', '/api/sender-ids/' + s.id, body); else await api('POST', '/api/sender-ids', body);
          close(); toast(s ? 'Demande mise à jour.' : 'Demande envoyée : TAL la validera prochainement.'); refresh();
        } catch (ex) { err.textContent = fieldsError(ex); err.classList.remove('hidden'); e.target.disabled = false; }
      } }] });
  }

  /* --------------------------------------------------------------- Init */
  for (const t of document.querySelectorAll('#si-tabs .tab')) t.addEventListener('click', () => { state.status = t.dataset.status; state.page = 1; load(); });
  $('#si-q').addEventListener('input', debounce(() => { state.page = 1; load(); }));
  $('#si-org')?.addEventListener('change', () => { state.page = 1; load(); });
  $('#si-new')?.addEventListener('click', () => openForm());
  (cfg.global ? api('GET', '/api/organizations?per_page=100').then((o) => { state.orgs = o; for (const x of o) $('#si-org')?.append(h('option', { value: x.id }, x.name)); }).catch(() => {}) : Promise.resolve()).finally(refresh);
})();
