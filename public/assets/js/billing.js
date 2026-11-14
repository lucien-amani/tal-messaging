/* TAL Messaging — Wallet : solde, transactions, recharges, factures, tarifs ; vue d'ensemble et validation pour l'équipe TAL. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, debounce, dateFr, money, modal, confirmBox, field, textInput, selectInput, pager, empty, kpi, opLabel, toastErr } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#bl-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);

  const PAY = { pending: ['En attente', 'badge badge-warn'], confirmed: ['Confirmée', 'badge badge-ok'], rejected: ['Rejetée', 'badge badge-danger'], cancelled: ['Annulée', 'badge'] };
  const TYPE = { topup: 'Recharge', adjustment: 'Ajustement', charge: 'Messages envoyés' };
  const state = { orgId: '', tab: 'transactions', page: 1, wallet: null, ovPage: 1 };
  const orgQ = () => (cfg.global && state.orgId ? '&organization_id=' + state.orgId : '');
  const errText = (e) => e.message + (e.fields ? ' ' + Object.values(e.fields).flat().join(' ') : '');
  const th = (...l) => h('tr', {}, l.map((x) => (Array.isArray(x) ? h('th', { class: x[1] }, x[0]) : h('th', {}, x))));

  /* --------------------------------------------------------------- Détail */
  async function loadWallet() {
    try {
      const w = await api('GET', '/api/wallet?x=1' + orgQ());
      state.wallet = w;
      clear($('#bl-kpis')).append(
        kpi('Disponible', money(w.available_micro, w.currency), w.held_micro > 0 ? money(w.held_micro, w.currency) + ' réservés pour des messages en cours' : null),
        kpi('Solde', money(w.balance_micro, w.currency)), kpi('Dépensé (30 j)', money(w.spent_30d_micro, w.currency)), kpi('Rechargé (30 j)', money(w.topups_30d_micro, w.currency)));
      const alert = clear($('#bl-alert'));
      if (w.available_micro <= 0) alert.append(h('div', { class: 'alert alert-danger mb-6' }, icon('alert-circle', 'mt-0.5 text-lg'), h('span', {}, h('strong', {}, 'Solde épuisé. '), 'Rechargez votre wallet pour pouvoir envoyer.')));
      else if (w.low) alert.append(h('div', { class: 'alert alert-warn mb-6' }, icon('alert-triangle', 'mt-0.5 text-lg'), h('span', {}, h('strong', {}, 'Solde bas. '), 'Pensez à recharger.')));
    } catch (e) { toastErr(e); }
  }

  let seq = 0;
  async function loadTab() {
    const mine = ++seq;
    for (const t of document.querySelectorAll('#bl-tabs .tab')) t.setAttribute('aria-current', t.dataset.tab === state.tab ? 'page' : 'false');
    const head = clear($('#bl-head')); const body = clear($('#bl-rows')); clear($('#bl-state')); clear($('#bl-pager'));
    try {
      if (state.tab === 'pricing') {
        const r = await api('GET', '/api/pricing?x=1' + orgQ());
        if (mine !== seq) return;
        head.append(th('Pays', ['Opérateur', 'hidden sm:table-cell'], ['Prix par SMS', 'text-right']));
        const names = cfg.countries || {};
        for (const x of r.rules) body.append(h('tr', {}, h('td', {}, x.country ? (names[x.country] || x.country) : 'Tous les pays (par défaut)', x.custom ? h('span', { class: 'badge badge-brand ml-2' }, 'Tarif négocié') : null),
          h('td', { class: 'hidden sm:table-cell' }, x.operator ? opLabel(x.operator) : 'Tous'), h('td', { class: 'text-right font-medium tabular-nums' }, money(x.price_micro, x.currency))));
        if (!r.rules.length) $('#bl-state').append(empty('Aucun tarif défini : contactez TAL.', 'currency-dollar'));
        return;
      }
      const path = { transactions: '/api/wallet/transactions', payments: '/api/payments', invoices: '/api/invoices' }[state.tab];
      const rows = await api('GET', path + '?page=' + state.page + '&per_page=20' + orgQ());
      if (mine !== seq) return;
      if (state.tab === 'transactions') {
        head.append(th('Date', 'Description', ['Montant', 'text-right'], ['Solde après', 'hidden text-right sm:table-cell']));
        for (const t of rows) body.append(h('tr', {}, h('td', { class: 'whitespace-nowrap text-mute' }, dateFr(t.created_at)),
          h('td', {}, h('p', { class: 'font-medium' }, TYPE[t.type] || t.type), h('p', { class: 'text-xs text-mute' }, (t.description || '') + (t.message_count ? ' · ' + fmt(t.message_count) + ' msg' : '') + (cfg.global ? ' · ' + t.organization_name : ''))),
          h('td', { class: 'text-right font-medium tabular-nums ' + (t.amount_micro < 0 ? 'text-red-600' : 'text-emerald-600') }, (t.amount_micro > 0 ? '+' : '') + money(t.amount_micro, t.currency)),
          h('td', { class: 'hidden text-right tabular-nums text-mute sm:table-cell' }, money(t.balance_after_micro, t.currency))));
      } else if (state.tab === 'payments') {
        head.append(th('Date', 'Référence', ['Montant', 'text-right'], 'Statut', ['', 'w-40 text-right']));
        for (const p of rows) body.append(paymentRow(p));
      } else {
        head.append(th('Facture', ['Date', 'hidden sm:table-cell'], ['Montant', 'text-right'], ['', 'w-24 text-right']));
        for (const i of rows) body.append(h('tr', {}, h('td', {}, h('p', { class: 'font-mono font-medium' }, i.number), h('p', { class: 'text-xs text-mute' }, i.description)),
          h('td', { class: 'hidden whitespace-nowrap text-mute sm:table-cell' }, dateFr(i.issued_at)), h('td', { class: 'text-right font-medium tabular-nums' }, money(i.amount_micro, i.currency)),
          h('td', { class: 'text-right' }, h('a', { class: 'btn btn-outline btn-sm', href: url('/billing/invoices/' + i.id) }, icon('file-text'), 'Voir'))));
      }
      if (!rows.length) $('#bl-state').append(empty(state.tab === 'transactions' ? 'Aucune transaction.' : state.tab === 'payments' ? 'Aucune demande de recharge.' : 'Aucune facture.', 'wallet'));
      $('#bl-pager').append(pager(rows.meta, (p) => { state.page = p; loadTab(); }));
    } catch (e) { toastErr(e); }
  }

  function paymentRow(p) {
    const [label, cls] = PAY[p.status] || [p.status, 'badge'];
    const acts = [];
    const btn = (t, ic, fn, c = 'btn-ghost') => h('button', { type: 'button', class: 'btn btn-icon btn-sm ' + c, title: t, 'aria-label': t, onclick: fn }, icon(ic));
    if (p.status === 'pending' && cfg.canApprove) acts.push(btn('Confirmer', 'check', () => confirmPay(p), 'btn-soft'), btn('Rejeter', 'x', () => rejectPay(p)));
    if (p.status === 'pending' && cfg.canTopup && !cfg.global) acts.push(btn('Annuler ma demande', 'trash', () => cancelPay(p)));
    if (p.invoice_id) acts.push(h('a', { class: 'btn btn-outline btn-sm', href: url('/billing/invoices/' + p.invoice_id) }, icon('file-text'), 'Facture'));
    return h('tr', {}, h('td', { class: 'whitespace-nowrap text-mute' }, dateFr(p.created_at)),
      h('td', {}, h('p', { class: 'font-mono text-sm font-medium' }, p.reference), h('p', { class: 'text-xs text-mute' }, (cfg.methods[p.method] || p.method) + (cfg.global ? ' · ' + p.organization_name : ''))),
      h('td', { class: 'text-right font-medium tabular-nums' }, money(p.amount_micro, p.currency)),
      h('td', {}, h('span', { class: cls }, label), p.reject_reason ? h('p', { class: 'mt-1 max-w-[14rem] truncate text-xs text-mute', title: p.reject_reason }, p.reject_reason) : null),
      h('td', { class: 'whitespace-nowrap text-right' }, acts));
  }

  /* -------------------------------------------------------------- Actions */
  async function confirmPay(p) {
    if (!(await confirmBox('Confirmer la réception de ' + money(p.amount_micro, p.currency) + ' (réf. ' + p.reference + ') pour ' + p.organization_name + ' ? Le wallet sera crédité et la facture émise.', { label: 'Confirmer et créditer', title: 'Confirmer le paiement' }))) return;
    try { await api('POST', '/api/payments/' + p.id + '/confirm'); toast('Paiement confirmé : wallet crédité.'); refreshAll(); } catch (e) { toastErr(e); }
  }
  function rejectPay(p) {
    const reason = h('textarea', { class: 'input !h-24 py-2.5', maxlength: 255, placeholder: 'Ex. Référence introuvable chez l’opérateur.' }); const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: 'Rejeter la demande', body: h('div', { class: 'space-y-3' }, h('p', { class: 'text-sm' }, money(p.amount_micro, p.currency) + ' · réf. ' + p.reference), field('Motif (communiqué au client)', reason), err),
      actions: [{ label: 'Annuler' }, { label: 'Rejeter', class: 'btn-danger', onclick: async (e, close) => {
        e.target.disabled = true;
        try { await api('POST', '/api/payments/' + p.id + '/reject', { reason: reason.value }); close(); toast('Demande rejetée.'); refreshAll(); } catch (x) { err.textContent = errText(x); err.classList.remove('hidden'); e.target.disabled = false; }
      } }] });
  }
  async function cancelPay(p) {
    if (!(await confirmBox('Annuler votre demande de recharge (réf. ' + p.reference + ') ?', { label: 'Annuler la demande', danger: true }))) return;
    try { await api('POST', '/api/payments/' + p.id + '/cancel'); toast('Demande annulée.'); loadTab(); } catch (e) { toastErr(e); }
  }

  function openTopup() {
    const cur = state.wallet?.currency || 'USD';
    const amount = textInput('amount', '', { inputmode: 'decimal', placeholder: 'Ex. 50', autocomplete: 'off' });
    const method = selectInput('method', Object.entries(cfg.methods), 'mobile_money');
    const ref = textInput('reference', '', { maxlength: 80, placeholder: 'Numéro de transaction Mobile Money ou du virement' });
    const note = textInput('note', '', { maxlength: 255, placeholder: 'Facultatif' });
    const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: 'Recharger mon wallet', large: true,
      body: h('form', { class: 'space-y-4', novalidate: true, onsubmit: (e) => e.preventDefault() },
        h('p', { class: 'text-sm text-mute' }, 'Effectuez votre paiement auprès de TAL (Mobile Money, virement…), puis déclarez-le ici avec sa référence. Votre solde est crédité dès que TAL a confirmé la réception.'),
        h('div', { class: 'grid gap-4 sm:grid-cols-2' }, field('Montant payé (' + cur + ')', amount), field('Mode de paiement', method)),
        field('Référence de la transaction', ref), field('Note', note), err),
      actions: [{ label: 'Annuler' }, { label: 'Envoyer la demande', class: 'btn-primary', onclick: async (e, close) => {
        e.target.disabled = true;
        try { await api('POST', '/api/payments', { amount: amount.value, method: method.value, reference: ref.value, note: note.value }); close(); toast('Demande envoyée : TAL la confirmera rapidement.'); state.tab = 'payments'; state.page = 1; refreshAll(); }
        catch (x) { err.textContent = errText(x); err.classList.remove('hidden'); e.target.disabled = false; }
      } }] });
  }

  function openAdjust() {
    if (!state.orgId) return;
    const amount = textInput('amount', '', { inputmode: 'decimal', placeholder: 'Ex. 25 pour créditer, -5.50 pour corriger' });
    const note = textInput('note', '', { maxlength: 200, placeholder: 'Motif (obligatoire) : geste commercial, correction…' }); const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
    modal({ title: 'Ajuster le solde', body: h('div', { class: 'space-y-4' }, h('p', { class: 'text-sm text-mute' }, 'Opération manuelle, tracée dans le journal d’audit et visible par le client dans ses transactions.'), field('Montant (' + (state.wallet?.currency || '') + ')', amount), field('Motif', note), err),
      actions: [{ label: 'Annuler' }, { label: 'Appliquer', class: 'btn-primary', onclick: async (e, close) => {
        e.target.disabled = true;
        try { await api('POST', '/api/wallet/adjust', { organization_id: state.orgId, amount: amount.value, note: note.value }); close(); toast('Solde ajusté.'); refreshAll(); }
        catch (x) { err.textContent = errText(x); err.classList.remove('hidden'); e.target.disabled = false; }
      } }] });
  }

  /* ------------------------------------------------- Vue d'ensemble (TAL) */
  async function loadOverview() {
    const q = new URLSearchParams({ page: state.ovPage, per_page: 25 }); if ($('#bl-q').value.trim()) q.set('q', $('#bl-q').value.trim());
    try {
      const rows = await api('GET', '/api/wallets?' + q);
      const body = clear($('#bl-ov-rows'));
      for (const w of rows) body.append(h('tr', { class: 'cursor-pointer', onclick: () => { $('#bl-org').value = w.organization_id; selectOrg(String(w.organization_id)); } },
        h('td', { class: 'font-medium' }, w.organization_name, w.low ? h('span', { class: 'badge badge-warn ml-2' }, 'Solde bas') : null),
        h('td', { class: 'text-right tabular-nums' }, money(w.balance_micro, w.currency)), h('td', { class: 'hidden text-right tabular-nums text-mute sm:table-cell' }, money(w.held_micro, w.currency)),
        h('td', { class: 'text-right font-medium tabular-nums' }, money(w.available_micro, w.currency))));
      clear($('#bl-ov-state')); if (!rows.length) $('#bl-ov-state').append(empty('Aucun client.', 'building'));
      clear($('#bl-ov-pager')).append(pager(rows.meta, (p) => { state.ovPage = p; loadOverview(); }));
    } catch (e) { toastErr(e); }
    loadPending();
  }
  async function loadPending() {
    if (!cfg.canApprove) return;
    let box = $('#bl-pending');
    if (!box) { box = h('div', { id: 'bl-pending', class: 'mb-6' }); $('#bl-overview').prepend(box); }
    try {
      const rows = await api('GET', '/api/payments?status=pending&per_page=50'); clear(box);
      if (!rows.length) return;
      box.append(h('h3', { class: 'mb-3 text-base font-semibold' }, 'Recharges à confirmer (' + rows.length + ')'),
        h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' }, h('thead', {}, th('Date', 'Référence', ['Montant', 'text-right'], 'Statut', ['', 'w-40 text-right'])), h('tbody', {}, rows.map(paymentRow)))));
    } catch (e) { toastErr(e); }
  }

  function selectOrg(id) {
    state.orgId = id; state.page = 1;
    $('#bl-overview').classList.toggle('hidden', !!id); $('#bl-detail').classList.toggle('hidden', !id); $('#bl-search-wrap').classList.toggle('hidden', !!id);
    $('#bl-adjust')?.classList.toggle('hidden', !id);
    if (id) { loadWallet(); loadTab(); } else loadOverview();
  }
  const refreshAll = () => { if (cfg.global && !state.orgId) { loadOverview(); return; } loadWallet(); loadTab(); };

  /* ---------------------------------------------------------------- Init */
  for (const t of document.querySelectorAll('#bl-tabs .tab')) t.addEventListener('click', () => { state.tab = t.dataset.tab; state.page = 1; loadTab(); });
  $('#bl-topup')?.addEventListener('click', openTopup); $('#bl-adjust')?.addEventListener('click', openAdjust);
  if (cfg.global) {
    $('#bl-org').addEventListener('change', (e) => selectOrg(e.target.value)); $('#bl-q').addEventListener('input', debounce(() => { state.ovPage = 1; loadOverview(); }));
    api('GET', '/api/organizations?per_page=100').then((o) => { for (const x of o) $('#bl-org').append(h('option', { value: x.id }, x.name)); }).catch(() => {});
    $('#bl-overview').classList.remove('hidden'); loadOverview();
  } else { loadWallet(); loadTab(); }
})();
