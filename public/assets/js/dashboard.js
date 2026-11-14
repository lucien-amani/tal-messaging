/* TAL Messaging — Dashboards par rôle : KPIs, alertes, listes et accès rapides alimentés par /api/dashboards/{slug}. */
(() => {
  'use strict';
  const { $, h, icon, clear, fmt, empty, toastErr } = window.TALUI;
  const { api, url } = window.TAL;
  const root = $('#db-app');
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const TONE = { info: 'alert-info', warn: 'alert-warn', danger: 'alert-danger' };
  const show = (v, unit) => (v === null || v === undefined ? '—' : (typeof v === 'number' ? fmt(v) : String(v).replace(/^(\d+)\.(\d+)$/, '$1,$2')) + (unit ? ' ' + unit : ''));

  function wrap(href, node) { return href ? h('a', { href: url(href), class: 'block rounded-xl transition hover:ring-2 hover:ring-brand/40 focus-visible:ring-2' }, node) : node; }

  async function load() {
    try {
      const q = cfg.slug === 'client' && cfg.global && cfg.org ? '?organization_id=' + encodeURIComponent(cfg.org) : '';
      const d = await api('GET', '/api/dashboards/' + cfg.slug + q);
      if (d.mode.simulation && cfg.slug !== 'client') {
        clear($('#db-mode')).append(h('div', { class: 'alert alert-warn', role: 'status' }, icon('flask'), h('div', {}, h('strong', {}, 'Mode simulation. '), d.mode.forced ? 'Envoi réel coupé (MESSAGING_PROVIDER=simulator).' : 'Aucun fournisseur réel n’est configuré : aucun SMS réel n’est envoyé.')));
      }
      const al = clear($('#db-alerts'));
      for (const a of d.alerts) al.append(h('div', { class: 'alert ' + (TONE[a.level] || 'alert-info'), role: 'status', dataset: { alert: a.level } }, icon(a.level === 'danger' ? 'alert-triangle' : 'info-circle'), h('span', { class: 'flex-1' }, a.text), a.href ? h('a', { class: 'link font-medium', href: url(a.href) }, 'Voir') : null));
      const k = clear($('#db-kpis'));
      for (const x of d.kpis) k.append(wrap(x.href, h('div', { class: 'kpi h-full', dataset: { kpi: x.label } }, h('p', { class: 'text-xs font-semibold uppercase tracking-wider text-mute' }, x.label), h('p', { class: 'mt-2 text-2xl font-bold tabular-nums' }, show(x.value, x.unit)), x.sub ? h('p', { class: 'mt-1 text-xs text-mute' }, x.sub) : null)));
      const t = clear($('#db-tables'));
      for (const tb of d.tables || []) {
        t.append(h('section', { class: 'card p-4' }, h('h3', { class: 'mb-3 font-semibold' }, tb.title),
          tb.rows.length ? h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl' }, h('thead', {}, h('tr', {}, tb.columns.map((c) => h('th', {}, c)))), h('tbody', {}, tb.rows.map((r) => h('tr', {}, r.map((c) => h('td', {}, typeof c === 'number' ? fmt(c) : String(c)))))))) : empty('Rien à afficher pour le moment.')));
      }
      const l = clear($('#db-links'));
      for (const x of d.links) l.append(h('a', { class: 'card flex items-start gap-3 p-4 transition hover:ring-2 hover:ring-brand/40', href: url(x.path), dataset: { link: x.path } }, icon(x.icon, 'text-2xl text-brand'), h('div', {}, h('p', { class: 'font-medium' }, x.label), h('p', { class: 'text-xs text-mute' }, x.desc))));
    } catch (e) {
      clear($('#db-state')).append(empty(e.message, 'alert-circle'));
      if (e.status !== 422) toastErr(e);
    }
  }
  load();
})();
