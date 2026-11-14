/* TAL Messaging — Rapport d'une campagne : résultats, opérateurs, échecs, export. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, dateFr, opLabel, confirmBox, bars, empty, kpi, toastErr } = window.TALUI;
  const { api, toast, url } = window.TAL;
  const root = $('#cr-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const STATUS = { draft: 'Brouillon', launching: 'Lancement…', scheduled: 'Programmée', running: 'En cours', completed: 'Terminée', cancelled: 'Annulée' };
  let active = false;

  async function load(silent = false) {
    try { render(await api('GET', '/api/campaigns/' + cfg.id + '/report')); } catch (e) { if (!silent) toastErr(e); }
  }

  function render(r) {
    const c = r.campaign;
    active = c.status === 'running' || c.status === 'scheduled';
    $('#cr-title').textContent = c.name;
    $('#cr-sub').textContent = STATUS[c.status] + ' · ' + (c.sender_name || 'Sans Sender ID') + (cfg.global ? ' · ' + c.organization_name : '') + ' · ' +
      (c.scheduled_at && c.status === 'scheduled' ? 'départ ' + dateFr(c.scheduled_at) : c.launched_at ? 'lancée ' + dateFr(c.launched_at) : 'créée ' + dateFr(c.created_at));
    $('#cr-body').textContent = c.body;

    const act = clear($('#cr-actions'));
    if (c.batch_id) {
      act.append(h('a', { class: 'btn btn-outline', href: url('/messages?batch=' + c.batch_id) }, icon('list-details'), 'Voir les messages'),
        h('a', { class: 'btn btn-outline', href: url('/api/campaigns/' + c.id + '/export') }, icon('download'), 'Exporter (CSV)'));
    }
    if (cfg.canManage) {
      act.append(h('button', { type: 'button', class: 'btn btn-outline', onclick: duplicate }, icon('copy'), 'Dupliquer'));
      if (c.status === 'draft') act.append(h('a', { class: 'btn btn-primary', href: url('/campaigns/' + c.id + '/edit') }, icon('pencil'), 'Modifier'));
      if (c.status === 'scheduled' || c.status === 'running') act.append(h('button', { type: 'button', class: 'btn btn-danger', onclick: () => cancel(c) }, icon('player-stop'), 'Annuler'));
    }

    clear($('#cr-kpis')).append(kpi('Destinataires', fmt(c.total_recipients), fmt(c.total_segments) + ' SMS'), kpi('Livrés', fmt(c.n_delivered), c.delivery_rate === null ? null : c.delivery_rate + ' % de réussite'),
      kpi('Envoyés sans accusé', fmt(c.n_sent - c.n_delivered)), kpi('Échecs', fmt(c.n_failed)), kpi('Pas encore partis', fmt(c.n_queued + c.n_sending), c.n_cancelled ? fmt(c.n_cancelled) + ' annulé(s)' : null));

    const bar = h('div', { class: 'progress-bar' }); bar.style.width = c.progress + '%';
    clear($('#cr-progress')).append(h('div', { class: 'mb-2 flex items-center justify-between text-sm' }, h('span', { class: 'font-semibold' }, 'Avancement'), h('span', { class: 'tabular-nums text-mute' }, c.progress + ' %')), h('div', { class: 'progress' }, bar));

    const ops = clear($('#cr-operators'));
    ops.append(r.by_operator.length ? bars(r.by_operator.map((o) => ({ label: opLabel(o.operator === 'inconnu' ? null : o.operator), count: o.total })), c.total_recipients) : empty('Pas encore de données.', 'chart-bar'));
    const errs = clear($('#cr-errors'));
    errs.append(r.errors.length ? h('ul', { class: 'space-y-2 text-sm' }, r.errors.map((e) => h('li', { class: 'flex items-baseline justify-between gap-3' }, h('span', {}, e.message || e.code), h('span', { class: 'font-semibold tabular-nums' }, fmt(e.count)))))
      : empty('Aucun échec.', 'circle-check'));
  }

  async function duplicate() {
    try { const n = await api('POST', '/api/campaigns/' + cfg.id + '/duplicate'); toast('Copie créée en brouillon.'); location.href = url('/campaigns/' + n.id + '/edit'); } catch (e) { toastErr(e); }
  }
  async function cancel(c) {
    if (!(await confirmBox('Annuler « ' + c.name + ' » ? Les messages pas encore partis ne seront pas envoyés.', { label: 'Annuler la campagne', danger: true }))) return;
    try { await api('POST', '/api/campaigns/' + cfg.id + '/cancel'); toast('Campagne annulée.'); load(); } catch (e) { toastErr(e); }
  }

  setInterval(() => { if (active && !document.hidden) load(true); }, 8000);
  load();
})();
