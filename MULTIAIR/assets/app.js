/* MULTIAIR — application (tableau de bord des scénarios Make) */
(() => {
  'use strict';

  // ------------------------------------------------------------------ utilitaires
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const h = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const nf = new Intl.NumberFormat('fr-FR');
  const eur = (v) => v === null || v === undefined || v === '' ? '—' : nf.format(Math.round(Number(v) * 100) / 100) + ' €';
  const eur0 = (v) => v === null || v === undefined || v === '' ? '—' : nf.format(Math.round(Number(v))) + ' €';
  const num = (v) => v === null || v === undefined || v === '' ? '—' : nf.format(Number(v));
  const pct = (v) => v === null || v === undefined ? '—' : nf.format(v) + ' %';
  const fmtDate = (s, withTime = true) => {
    if (!s) return '—';
    const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!m) return h(s);
    return `${m[3]}/${m[2]}/${m[1]}` + (withTime && m[4] ? ` ${m[4]}:${m[5]}` : '');
  };
  const rel = (s) => {
    if (!s) return '—';
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return h(s);
    const diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60) return "à l'instant";
    if (diff < 3600) return `il y a ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `il y a ${Math.floor(diff / 3600)} h`;
    if (diff < 86400 * 30) return `il y a ${Math.floor(diff / 86400)} j`;
    return fmtDate(s, false);
  };
  const pill = (text, cls = '') => `<span class="pill ${cls}">${h(text || '—')}</span>`;
  const clip = (v, w = false) => `<span class="clip${w ? ' w' : ''}" title="${h(v)}">${h(v || '')}</span>`;

  const toastEl = $('#toast');
  let toastTimer;
  const toast = (msg, err = false) => {
    toastEl.textContent = msg;
    toastEl.className = 'toast show' + (err ? ' err' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => (toastEl.className = 'toast'), 2600);
  };

  async function api(route, opts = {}) {
    const url = 'api.php?r=' + route + (opts.query ? '&' + new URLSearchParams(opts.query).toString() : '');
    const init = {method: opts.method || 'GET', headers: {}};
    if (opts.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body);
    }
    const r = await fetch(url, init);
    if (r.status === 401) {
      location.reload();
      throw new Error('Session expirée');
    }
    const j = await r.json().catch(() => ({ok: false, erreur: 'Réponse invalide'}));
    if (!j.ok) throw new Error(j.erreur || 'Erreur API');
    return j;
  }
  const patch = (route, body) => api(route, {method: 'PATCH', body});

  // ------------------------------------------------------------------ graphiques
  const charts = {};
  function chart(id, cfg) {
    const c = document.getElementById(id);
    if (!c || typeof Chart === 'undefined') return;
    if (charts[id]) charts[id].destroy();
    Chart.defaults.font.family = 'system-ui, sans-serif';
    Chart.defaults.color = '#5f6b7a';
    charts[id] = new Chart(c, cfg);
  }
  const serieLabels = (s) => s.map((p) => p.jour.slice(8, 10) + '/' + p.jour.slice(5, 7));
  function barLine(id, series, options = {}) {
    const labels = serieLabels(series[0].data);
    chart(id, {
      data: {
        labels,
        datasets: series.map((s, i) => ({
          type: s.type || 'bar', label: s.label, data: s.data.map((p) => p.n),
          backgroundColor: s.color || ['#1c4f86', '#e8720c', '#1a7f45'][i], borderColor: s.color || ['#1c4f86', '#e8720c', '#1a7f45'][i],
          borderRadius: 3, yAxisID: s.axis || 'y', tension: .3, pointRadius: 2,
        })),
      },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false},
        plugins: {legend: {display: series.length > 1, position: 'top', labels: {boxWidth: 10}}},
        scales: Object.assign({x: {grid: {display: false}}, y: {beginAtZero: true, ticks: {precision: 0}}}, options.scales || {}),
      },
    });
  }
  function spark(id, data) {
    chart(id, {
      type: 'bar',
      data: {labels: data.map((p) => p.jour), datasets: [{data: data.map((p) => p.n), backgroundColor: '#1c4f86', borderRadius: 2}]},
      options: {responsive: true, maintainAspectRatio: false, plugins: {legend: {display: false}, tooltip: {callbacks: {title: (i) => fmtDate(i[0].label, false)}}},
        scales: {x: {display: false}, y: {display: false, beginAtZero: true}}},
    });
  }
  function distCard(title, rows) {
    const max = Math.max(1, ...rows.map((r) => Number(r.n)));
    return `<div class="card"><h3>${h(title)}</h3><div class="dist">${rows.length ? rows.map((r) =>
      `<div class="row"><span class="k" title="${h(r.k)}">${h(r.k)}</span><span class="bar"><i style="width:${(100 * r.n / max).toFixed(1)}%"></i></span><span class="n">${num(r.n)}</span></div>`
    ).join('') : '<span class="hint">Aucune donnée</span>'}</div></div>`;
  }
  const kpi = (v, l, cls = '') => `<div class="kpi ${cls}"><div class="v">${v}</div><div class="l">${h(l)}</div></div>`;

  // ------------------------------------------------------------------ tableau générique
  const PAGE = 50;
  function table(container, rows, cols, opts = {}) {
    const state = {sort: opts.sort || null, asc: opts.asc ?? false, page: 0, q: '', filter: {}};
    const filterDefs = opts.filters || [];
    const el = typeof container === 'string' ? $(container) : container;
    const id = 'tbl' + Math.random().toString(36).slice(2, 8);
    const render = () => {
      let data = rows.slice();
      if (state.q) {
        const q = state.q.toLowerCase();
        data = data.filter((r) => cols.some((c) => String(c.search ? c.search(r) : r[c.key] ?? '').toLowerCase().includes(q)));
      }
      for (const [k, v] of Object.entries(state.filter)) {
        if (v !== '') data = data.filter((r) => String(r[k] ?? '') === v);
      }
      if (state.sort) {
        const c = cols.find((x) => x.key === state.sort);
        data.sort((a, b) => {
          let va = c && c.sortVal ? c.sortVal(a) : a[state.sort], vb = c && c.sortVal ? c.sortVal(b) : b[state.sort];
          if (va === null || va === undefined) va = '';
          if (vb === null || vb === undefined) vb = '';
          if (typeof va === 'number' && typeof vb === 'number') return state.asc ? va - vb : vb - va;
          const cmp = String(va).localeCompare(String(vb), 'fr', {numeric: true});
          return state.asc ? cmp : -cmp;
        });
      }
      const pages = Math.max(1, Math.ceil(data.length / PAGE));
      state.page = Math.min(state.page, pages - 1);
      const slice = data.slice(state.page * PAGE, (state.page + 1) * PAGE);
      el.innerHTML = `
        <div class="filters">
          <input type="search" placeholder="Rechercher…" value="${h(state.q)}" data-role="q">
          ${filterDefs.map((f) => `<select data-filter="${h(f.key)}"><option value="">${h(f.label)} : tous</option>${uniq(rows, f.key).map((v) =>
            `<option value="${h(v)}" ${state.filter[f.key] === v ? 'selected' : ''}>${h(f.map ? f.map(v) : v)}</option>`).join('')}</select>`).join('')}
          <span class="hint">${num(data.length)} ligne${data.length > 1 ? 's' : ''}</span>
          ${opts.tools || ''}
        </div>
        <div class="tbl-wrap"><table class="tbl" id="${id}"><thead><tr>${cols.map((c) =>
          `<th data-key="${h(c.key)}" class="${state.sort === c.key ? 'sorted' + (state.asc ? ' asc' : '') : ''}">${h(c.label)}</th>`).join('')}</tr></thead>
        <tbody>${slice.length ? slice.map((r, i) => `<tr data-i="${rows.indexOf(r)}">${cols.map((c) =>
          `<td class="${c.num ? 'num' : ''}">${c.render ? c.render(r) : h(r[c.key])}</td>`).join('')}</tr>`).join('')
          : `<tr><td class="empty" colspan="${cols.length}">Aucune ligne</td></tr>`}</tbody></table>
        ${pages > 1 ? `<div class="pager"><span>Page ${state.page + 1} / ${pages}</span><span>
          <button class="btn small" data-page="-1" ${state.page === 0 ? 'disabled' : ''}>‹ Précédent</button>
          <button class="btn small" data-page="1" ${state.page >= pages - 1 ? 'disabled' : ''}>Suivant ›</button></span></div>` : ''}
        </div>`;
      $('[data-role=q]', el).addEventListener('input', (e) => { state.q = e.target.value; state.page = 0; render(); focusSearch(); });
      $$('[data-filter]', el).forEach((s) => s.addEventListener('change', (e) => { state.filter[e.target.dataset.filter] = e.target.value; state.page = 0; render(); }));
      $$('th', el).forEach((th) => th.addEventListener('click', () => {
        if (state.sort === th.dataset.key) state.asc = !state.asc; else { state.sort = th.dataset.key; state.asc = false; }
        render();
      }));
      $$('[data-page]', el).forEach((b) => b.addEventListener('click', () => { state.page += Number(b.dataset.page); render(); }));
      $$('tbody tr[data-i]', el).forEach((tr) => tr.addEventListener('click', (e) => {
        if (e.target.closest('select, input, button, a')) return;
        opts.onRow && opts.onRow(rows[Number(tr.dataset.i)], tr);
      }));
      opts.afterRender && opts.afterRender(el);
      let focusSearch = () => {};
      focusSearch = () => { const i = $('[data-role=q]', el); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } };
    };
    render();
    return {render, state};
  }
  const uniq = (rows, key) => Array.from(new Set(rows.map((r) => String(r[key] ?? '')).filter((v) => v !== ''))).sort((a, b) => a.localeCompare(b, 'fr'));

  // ------------------------------------------------------------------ drawer (détail)
  const drawer = $('#drawer'), drawerBg = $('#drawerBg');
  function openDrawer(title, bodyHtml, footHtml = '') {
    $('#drawerTitle').innerHTML = title;
    $('#drawerBody').innerHTML = bodyHtml;
    $('#drawerFoot').innerHTML = footHtml;
    drawer.classList.add('open');
    drawerBg.classList.add('open');
  }
  function closeDrawer() { drawer.classList.remove('open'); drawerBg.classList.remove('open'); }
  $('#drawerClose').addEventListener('click', closeDrawer);
  drawerBg.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });

  const kv = (pairs) => `<div class="kv">${pairs.filter(([, v]) => v !== undefined).map(([k, v]) => `<div class="k">${h(k)}</div><div class="v">${v === null || v === '' ? '<span class="hint">—</span>' : v}</div>`).join('')}</div>`;
  const editForm = (fields, obj) => fields.map((f) => `<div class="editrow"><label>${h(f.label)}</label>${
    f.type === 'select' ? `<select name="${h(f.key)}">${f.options.map((o) => { const v = Array.isArray(o) ? o[0] : o, t = Array.isArray(o) ? o[1] : o; return `<option value="${h(v)}" ${String(obj[f.key] ?? '') === String(v) ? 'selected' : ''}>${h(t)}</option>`; }).join('')}</select>`
    : f.type === 'textarea' ? `<textarea name="${h(f.key)}">${h(obj[f.key])}</textarea>`
    : `<input name="${h(f.key)}" value="${h(obj[f.key])}">`}</div>`).join('');
  const readForm = (root, fields) => Object.fromEntries(fields.map((f) => [f.key, $(`[name="${f.key}"]`, root).value]));
  const saveBtn = (label = 'Enregistrer') => `<button class="btn primary" id="drawerSave">${h(label)}</button>`;
  const delBtn = () => `<button class="btn danger" id="drawerDelete">Supprimer</button>`;

  // statut → couleur
  const cls = (s) => {
    s = String(s || '').toLowerCase();
    if (/urgent|escalade|erreur|ecart|perdu|a_valider|a_traiter|à traiter/.test(s)) return 'danger';
    if (/attente|relance|nouveau|draft|en_cours|sans|recu/.test(s)) return 'warn';
    if (/trait|gagn|auto|valide|converti|ok|envoye|qualifi|accueil/.test(s)) return 'ok';
    return 'muted';
  };
  const lbl = {
    a_traiter: 'À traiter', en_cours: 'En cours', traite: 'Traité', nouveau: 'Nouveau', contacte: 'Contacté', converti: 'Converti', perdu: 'Perdu',
    qualifie: 'Qualifié', rappel_planifie: 'Rappel planifié', envoye: 'Envoyé', a_valider: 'À valider', valide: 'Validé', recu: 'Reçu, sans réponse',
    vapi_direct: 'Appel direct', whatsapp_qualifie: 'Qualifié WhatsApp', sans_reponse_10min: 'Sans réponse WhatsApp', import: 'Import Sheets',
  };
  const L = (v) => lbl[v] || v || '—';

  // ------------------------------------------------------------------ état global
  const main = $('#main');
  let current = 'overview';
  const tabs = {};

  async function show(tab) {
    current = tab;
    $$('#tabs button').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
    main.innerHTML = '<div class="loading">Chargement…</div>';
    try {
      await tabs[tab]();
    } catch (e) {
      main.innerHTML = `<div class="msg err">${h(e.message)}</div>`;
    }
    window.scrollTo(0, 0);
  }
  $('#tabs').addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) show(b.dataset.tab); });
  $('#refreshBtn').addEventListener('click', () => { show(current); refreshBadges(); });
  $('#logoutBtn').addEventListener('click', async () => { await fetch('api.php?r=auth/logout'); location.reload(); });

  async function refreshBadges() {
    try {
      const s = (await api('stats/overview')).a_traiter;
      const set = (k, n) => { const b = $(`[data-badge=${k}]`); if (b) { b.textContent = n; b.classList.toggle('zero', !n); } };
      set('repondeur', s.rep_fiches_en_attente + s.rep_demandes_a_traiter);
      set('chatbot', s.chat_leads_nouveaux);
      set('adv', s.adv_a_valider);
      set('cso', s.cso_en_cours);
      set('cee', s.cee_actions_a_faire);
    } catch (e) { /* silencieux */ }
  }

  const journal = (rows) => `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Scénario</th><th>Statut</th><th>Événement</th><th>Détail</th></tr></thead><tbody>${
    rows.length ? rows.map((r) => `<tr style="cursor:default"><td>${fmtDate(r.date)}</td><td>${h(r.scenario)}</td><td>${pill(r.statut, r.statut === 'ok' ? 'ok' : 'danger')}</td><td>${h(r.type_evenement)}</td><td>${clip(r.resume, true)}</td></tr>`).join('')
    : '<tr><td class="empty" colspan="5">Aucun événement enregistré</td></tr>'}</tbody></table></div>`;
  const exportBtn = (t) => `<a class="btn small" href="api.php?r=export/${t}" download>⬇ Export CSV</a>`;
  const subtabs = (items, active, onChange) => {
    const html = `<div class="subtabs">${items.map(([k, l]) => `<button data-sub="${k}" class="${k === active ? 'active' : ''}">${h(l)}</button>`).join('')}</div>`;
    return {html, bind: (root) => $$('[data-sub]', root).forEach((b) => b.addEventListener('click', () => onChange(b.dataset.sub)))};
  };

  // ------------------------------------------------------------------ table de routage (commune aux 3 scénarios)
  async function routageUI(root, scenario, opts) {
    const rows = (await api('routage', {query: {scenario}})).rows;
    const row = (r) => `<tr style="cursor:default"><td><input class="inline" name="cle" value="${h(r.cle)}" placeholder="${h(opts.placeholder || 'nouvelle clé')}" style="width:150px" ${r.cle ? 'readonly' : ''}></td>
      <td><input class="inline" name="dest_to" value="${h(r.dest_to)}" style="width:100%" placeholder="email1;email2"></td><td><input class="inline" name="dest_cc" value="${h(r.dest_cc)}" style="width:100%"></td>
      <td><input class="inline" name="libelle" value="${h(r.libelle)}" style="width:100%"></td>
      <td style="white-space:nowrap"><button class="btn small primary" data-rsave>Enregistrer</button> ${r.cle ? `<button class="btn small danger" data-rdel="${h(r.cle)}" title="Supprimer">✕</button>` : ''}</td></tr>`;
    root.innerHTML = `<p class="hint">${opts.hint}</p>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>${h(opts.keyLabel)}</th><th>Destinataires (TO, séparés par ;)</th><th>Copie (CC)</th><th>Libellé</th><th></th></tr></thead><tbody>
      ${rows.map(row).join('')}${row({})}</tbody></table></div>
      <p class="hint">Repli si la clé est inconnue : ${h(opts.fallback || 'cyril.mortier@airwco.com')} (paramètre routage_fallback_email).</p>`;
    $$('[data-rsave]', root).forEach((b) => b.addEventListener('click', async () => {
      const tr = b.closest('tr');
      const body = {cle: $('[name=cle]', tr).value.trim(), dest_to: $('[name=dest_to]', tr).value.trim(), dest_cc: $('[name=dest_cc]', tr).value.trim(), libelle: $('[name=libelle]', tr).value.trim()};
      if (!body.cle) return toast(`${opts.keyLabel} requis`, true);
      await api('routage', {method: 'POST', query: {scenario}, body}); toast('Routage enregistré'); routageUI(root, scenario, opts);
    }));
    $$('[data-rdel]', root).forEach((b) => b.addEventListener('click', async () => {
      if (confirm(`Supprimer la ligne « ${b.dataset.rdel} » ?`)) { await api('routage', {method: 'DELETE', query: {scenario}, body: {cle: b.dataset.rdel}}); routageUI(root, scenario, opts); }
    }));
  }

  // ================================================================== VUE D'ENSEMBLE
  tabs.overview = async () => {
    const s = await api('stats/overview');
    const scen = ['repondeur', 'chatbot', 'adv', 'cso', 'cee'];
    const t = s.a_traiter;
    main.innerHTML = `
      <div class="section-title"><h2>État des scénarios</h2><span class="hint">volumes sur 14 jours · mis à jour ${fmtDate(new Date().toISOString().slice(0, 16).replace('T', ' '))}</span></div>
      <div class="scen-cards">${scen.map((k) => {
        const x = s[k];
        const status = x.erreurs_j7 > 0 ? 'err' : (x.j7 > 0 ? 'ok' : 'warn');
        return `<div class="card scen" data-go="${k}">
          <div class="head"><b>${h(x.label)}</b><span><span class="dot ${status}"></span> ${x.erreurs_j7 ? `${x.erreurs_j7} erreur(s) 7 j` : (x.j7 ? 'actif' : 'aucune activité 7 j')}</span></div>
          <div class="nums"><div>7 jours<b>${num(x.j7)}</b></div><div>30 jours<b>${num(x.j30)}</b></div><div>Total<b>${num(x.total)}</b></div></div>
          <div class="chart-wrap small"><canvas id="sp_${k}"></canvas></div>
          <div class="last">Dernier : ${x.dernier ? rel(x.dernier) : 'jamais'}${x.dernier_log ? ` · ${h(x.dernier_log.type_evenement || '')}` : ''}</div>
        </div>`;
      }).join('')}</div>
      <div class="section-title"><h2>À traiter</h2></div>
      <div class="todo-list">
        ${todo(t.rep_fiches_en_attente, 'Fiches Répondeur en attente / urgentes', 'repondeur')}
        ${todo(t.rep_demandes_a_traiter, 'Demandes SAV / Commercial / Finance à traiter', 'repondeur')}
        ${todo(t.adv_a_valider, 'Réponses Claire ADV à valider (escalades)', 'adv')}
        ${todo(t.cso_en_cours, 'Devis CSO en cours de relance', 'cso')}
        ${todo(t.cso_ecart, 'Devis CSO avec écart de cohérence', 'cso')}
        ${todo(t.chat_leads_nouveaux, 'Leads chatbot non suivis', 'chatbot')}
        ${todo(t.cee_actions_a_faire, 'Actions Prime CEE à réaliser', 'cee')}
      </div>
      <div class="section-title"><h2>Journal des dernières exécutions</h2><span class="hint">alimenté par les scénarios Make via l'API</span></div>
      ${journal(s.journal)}`;
    scen.forEach((k) => spark('sp_' + k, s[k].serie));
    $$('[data-go]', main).forEach((c) => c.addEventListener('click', () => show(c.dataset.go)));
  };
  const todo = (n, label, go) => `<div class="todo ${n ? '' : 'zero'}" data-go="${go}"><span>${h(label)}</span><b>${num(n)}</b></div>`;

  // ================================================================== RÉPONDEUR IA
  const repSub = {v: 'fiches'};
  tabs.repondeur = async () => {
    const [s, fiches, demandes, dist, logs] = await Promise.all([
      api('stats/repondeur'), api('rep/fiches'), api('rep/demandes'), api('distributeurs', {query: {limit: 5000}}), api('log', {query: {scenario: 'repondeur', limit: 50}}),
    ]);
    const k = s.kpi;
    const st = subtabs([['fiches', `Fiches d'appel (${fiches.rows.length})`], ['demandes', `Demandes SAV / Commercial / Finance (${demandes.rows.length})`], ['distributeurs', `Distributeurs (${dist.rows.length})`], ['routage', 'Routage des mails'], ['journal', 'Journal']], repSub.v, (v) => { repSub.v = v; renderSub(); });
    main.innerHTML = `
      <div class="section-title"><h2>Répondeur IA — appels VAPI et suivi WhatsApp</h2><span class="hint">scénarios Make 9582857 · 9583172 · 9583010 · 9791097</span></div>
      <div class="kpis">
        ${kpi(num(k.appels_j30), 'Appels sur 30 jours')}
        ${kpi(num(k.appels_total), 'Appels au total')}
        ${kpi(num(k.en_attente), 'En attente de réponse WhatsApp', k.en_attente ? 'warn' : '')}
        ${kpi(num(k.urgents), 'Urgents', k.urgents ? 'danger' : '')}
        ${kpi(num(k.traites_whatsapp), 'Qualifiés via WhatsApp', 'ok')}
        ${kpi(num(k.sans_reponse), 'Transmis sans réponse WhatsApp')}
        ${kpi(pct(s.taux_reponse_whatsapp), 'Taux de réponse WhatsApp', 'info')}
        ${kpi(num(k.demandes_a_traiter), 'Demandes à traiter', k.demandes_a_traiter ? 'danger' : 'ok')}
      </div>
      <div class="grid2" style="margin-top:12px">
        <div class="card"><h3>Appels et demandes par jour (30 j)</h3><div class="chart-wrap"><canvas id="c_rep"></canvas></div></div>
        <div class="grid3" style="grid-template-columns:1fr">${distCard('Demandes par service', s.par_service)}${distCard('Fiches par statut', s.par_statut)}</div>
      </div>
      <div class="section-title"><h2>Données</h2><div class="tools">${exportBtn('rep_fiches')} ${exportBtn('rep_demandes')}</div></div>
      ${st.html}<div id="rep_sub"></div>`;
    barLine('c_rep', [{label: 'Appels (fiches)', data: s.serie}, {label: 'Demandes transmises', data: s.serie_demandes, type: 'line'}]);
    st.bind(main);

    const fiche = (f) => {
      const fields = [
        {key: 'statut', label: 'Statut', type: 'select', options: ['En attente', 'Urgent', 'Transmis', 'Traite', 'Transmis (sans réponse WhatsApp)']},
        {key: 'service', label: 'Service pressenti', type: 'select', options: ['technique', 'commercial', 'finance']},
        {key: 'societe', label: 'Société'}, {key: 'contact', label: 'Contact'}, {key: 'email', label: 'Email'}, {key: 'departement', label: 'Département'},
        {key: 'resume', label: 'Résumé', type: 'textarea'},
      ];
      const dems = demandes.rows.filter((d) => d.fiche_id === f.id);
      openDrawer(`Fiche d'appel #${f.id} — ${h(f.societe || f.contact || f.tel_norm)}`, `
        ${kv([['Date', fmtDate(f.created_at)], ['Téléphone', h(f.tel_norm)], ['Canal', h(f.canal)], ['Urgence', f.urgence ? pill('URGENT', 'danger') : 'Normal'],
          ['Marque / modèle', h([f.marque, f.modele].filter(Boolean).join(' '))], ['N° série', h(f.numero_serie)], ['Type de panne', h(f.type_panne)],
          ['Besoin commercial', h(f.besoin_commercial)], ['Réf. facture', h(f.reference_facture)], ['Justification urgence', h(f.justification_urgence)],
          ['Dernière réponse IA', h(f.derniere_reponse_ia)], ['Mis à jour', fmtDate(f.updated_at)], ['Transmis le', fmtDate(f.transmis_at)]])}
        <h4>Modifier</h4>${editForm(fields, f)}
        <h4>Demandes transmises (${dems.length})</h4>${dems.length ? dems.map((d) => `<div>${pill(d.service, 'info')} ${pill(d.priorite, cls(d.priorite))} ${fmtDate(d.created_at)} — ${h(d.resume || '')} <span class="hint">(${L(d.source)})</span></div>`).join('') : '<span class="hint">Aucune</span>'}`,
        saveBtn() + delBtn());
      $('#drawerSave').onclick = async () => { await patch('rep/fiches/' + f.id, readForm($('#drawerBody'), fields)); toast('Fiche enregistrée'); closeDrawer(); show('repondeur'); refreshBadges(); };
      $('#drawerDelete').onclick = async () => { if (confirm('Supprimer cette fiche ?')) { await api('rep/fiches/' + f.id, {method: 'DELETE'}); closeDrawer(); show('repondeur'); } };
    };
    const demande = (d) => {
      const fields = [
        {key: 'statut', label: 'Statut', type: 'select', options: [['a_traiter', 'À traiter'], ['en_cours', 'En cours'], ['traite', 'Traité']]},
        {key: 'traite_par', label: 'Traité par'}, {key: 'commentaire', label: 'Commentaire', type: 'textarea'},
      ];
      openDrawer(`${h(d.service)} — ${h(d.societe || d.contact || '')}`, `
        ${kv([['Date', fmtDate(d.created_at)], ['Priorité', pill(d.priorite, cls(d.priorite))], ['Source', L(d.source)], ['Société', h(d.societe)], ['Contact', h(d.contact)],
          ['Téléphone', h(d.tel)], ['Email', h(d.email)], ['Marque / modèle', h([d.marque, d.modele].filter(Boolean).join(' '))], ['N° série', h(d.numero_serie)],
          ['Type de panne', h(d.type_panne)], ['Besoin commercial', h(d.besoin_commercial)], ['Réf. facture', h(d.reference_facture)], ['Résumé', h(d.resume)],
          ['Justification urgence', h(d.justification_urgence)], ['Compte distributeur', h(d.compte_distributeur)], ['Commercial', h(d.commercial)], ['Traité le', fmtDate(d.traite_at)]])}
        <h4>Suivi</h4>${editForm(fields, d)}`, saveBtn() + delBtn());
      $('#drawerSave').onclick = async () => { await patch('rep/demandes/' + d.id, readForm($('#drawerBody'), fields)); toast('Demande enregistrée'); closeDrawer(); show('repondeur'); refreshBadges(); };
      $('#drawerDelete').onclick = async () => { if (confirm('Supprimer cette demande ?')) { await api('rep/demandes/' + d.id, {method: 'DELETE'}); closeDrawer(); show('repondeur'); } };
    };
    const distrib = (d) => {
      const fields = [{key: 'raison_sociale', label: 'Raison sociale'}, {key: 'marque', label: 'Marque'}, {key: 'vendeur', label: 'Commercial (vendeur)'}, {key: 'compte', label: 'N° de compte'},
        {key: 'nom', label: 'Nom'}, {key: 'prenom', label: 'Prénom'}, {key: 'email', label: 'Email'}, {key: 'telephone', label: 'Téléphone'}];
      openDrawer(d.id ? `Distributeur — ${h(d.raison_sociale)}` : 'Nouveau distributeur', `<h4>Fiche</h4>${editForm(fields, d)}`, saveBtn() + (d.id ? delBtn() : ''));
      $('#drawerSave').onclick = async () => {
        const body = readForm($('#drawerBody'), fields);
        if (d.id) await patch('distributeurs/' + d.id, body); else await api('distributeurs', {method: 'POST', body});
        toast('Distributeur enregistré'); closeDrawer(); show('repondeur');
      };
      if (d.id) $('#drawerDelete').onclick = async () => { if (confirm('Supprimer ce distributeur ?')) { await api('distributeurs/' + d.id, {method: 'DELETE'}); closeDrawer(); show('repondeur'); } };
    };

    function renderSub() {
      $$('[data-sub]', main).forEach((b) => b.classList.toggle('active', b.dataset.sub === repSub.v));
      const root = $('#rep_sub');
      if (repSub.v === 'fiches') {
        table(root, fiches.rows, [
          {key: 'created_at', label: 'Date', render: (r) => fmtDate(r.created_at)},
          {key: 'statut', label: 'Statut', render: (r) => pill(r.statut, cls(r.statut))},
          {key: 'service', label: 'Service'}, {key: 'societe', label: 'Société'}, {key: 'contact', label: 'Contact'}, {key: 'tel_norm', label: 'Téléphone'},
          {key: 'marque', label: 'Marque'}, {key: 'modele', label: 'Modèle'}, {key: 'departement', label: 'Dpt'},
          {key: 'resume', label: 'Résumé', render: (r) => clip(r.resume, true)},
        ], {sort: 'created_at', filters: [{key: 'statut', label: 'Statut'}, {key: 'service', label: 'Service'}], onRow: fiche});
      } else if (repSub.v === 'demandes') {
        table(root, demandes.rows, [
          {key: 'created_at', label: 'Date', render: (r) => fmtDate(r.created_at)},
          {key: 'service', label: 'Service', render: (r) => pill(r.service, 'info')},
          {key: 'priorite', label: 'Priorité', render: (r) => pill(r.priorite, cls(r.priorite))},
          {key: 'statut', label: 'Suivi', render: (r) => `<select class="inline" data-dem="${r.id}"><option value="a_traiter" ${r.statut === 'a_traiter' ? 'selected' : ''}>À traiter</option><option value="en_cours" ${r.statut === 'en_cours' ? 'selected' : ''}>En cours</option><option value="traite" ${r.statut === 'traite' ? 'selected' : ''}>Traité</option></select>`},
          {key: 'societe', label: 'Société'}, {key: 'contact', label: 'Contact'}, {key: 'tel', label: 'Téléphone'},
          {key: 'marque', label: 'Marque'}, {key: 'modele', label: 'Modèle'},
          {key: 'resume', label: 'Résumé', render: (r) => clip(r.resume, true)},
          {key: 'source', label: 'Source', render: (r) => L(r.source)},
        ], {sort: 'created_at', filters: [{key: 'service', label: 'Service'}, {key: 'statut', label: 'Suivi', map: L}, {key: 'priorite', label: 'Priorité'}], onRow: demande,
          afterRender: (el) => $$('[data-dem]', el).forEach((sel) => sel.addEventListener('change', async () => {
            await patch('rep/demandes/' + sel.dataset.dem, {statut: sel.value}); toast('Suivi mis à jour'); const d = demandes.rows.find((x) => x.id == sel.dataset.dem); if (d) d.statut = sel.value; refreshBadges();
          }))});
      } else if (repSub.v === 'distributeurs') {
        table(root, dist.rows, [
          {key: 'raison_sociale', label: 'Raison sociale'}, {key: 'marque', label: 'Marque'}, {key: 'vendeur', label: 'Commercial'}, {key: 'compte', label: 'N° compte'},
          {key: 'nom', label: 'Nom'}, {key: 'prenom', label: 'Prénom'}, {key: 'email', label: 'Email'}, {key: 'telephone', label: 'Téléphone'},
        ], {sort: 'raison_sociale', asc: true, filters: [{key: 'marque', label: 'Marque'}, {key: 'vendeur', label: 'Commercial'}], onRow: distrib,
          tools: `<button class="btn small primary" id="addDist">+ Ajouter</button>`, afterRender: (el) => $('#addDist', el).addEventListener('click', () => distrib({}))});
      } else if (repSub.v === 'routage') {
        routageUI(root, 'repondeur', {keyLabel: 'Service', placeholder: 'technique, commercial, finance…',
          hint: "Service pressenti par l'IA (technique / commercial / finance / autre) → destinataires du mail de transmission SAV, Commercial ou Finance. La clé « aiguilleur » sert aux WhatsApp non identifiés."});
      } else {
        root.innerHTML = journal(logs.rows);
      }
    }
    renderSub();
  };

  // ================================================================== CHATBOT CLAIRE
  const chatSub = {v: 'leads'};
  tabs.chatbot = async () => {
    const [s, leads, sessions, logs] = await Promise.all([
      api('stats/chatbot'), api('chat/leads'), api('chat/sessions'), api('log', {query: {scenario: 'chatbot', limit: 50}}),
    ]);
    const k = s.kpi;
    const st = subtabs([['leads', `Leads (${leads.rows.length})`], ['conversations', `Conversations (${sessions.rows.length})`], ['routage', 'Table de routage'], ['journal', 'Journal']], chatSub.v, (v) => { chatSub.v = v; renderSub(); });
    main.innerHTML = `
      <div class="section-title"><h2>Chatbot Multiair France — Claire v11</h2><span class="hint">scénario Make 9295374</span></div>
      <div class="kpis">
        ${kpi(num(k.conversations_j30), 'Conversations sur 30 jours')}
        ${kpi(num(k.conversations_total), 'Conversations au total')}
        ${kpi(num(k.messages_total), 'Messages échangés')}
        ${kpi(num(k.msg_par_conversation), 'Messages par conversation', 'info')}
        ${kpi(num(k.leads_j30), 'Leads sur 30 jours', 'ok')}
        ${kpi(num(k.leads_total), 'Leads au total')}
        ${kpi(pct(k.taux_conversion), 'Taux de conversion', 'info')}
        ${kpi(num(k.leads_nouveaux), 'Leads non suivis', k.leads_nouveaux ? 'warn' : 'ok')}
      </div>
      <div class="grid2" style="margin-top:12px">
        <div class="card"><h3>Messages et leads par jour (30 j)</h3><div class="chart-wrap"><canvas id="c_chat"></canvas></div></div>
        <div class="grid3" style="grid-template-columns:1fr">${distCard('Leads par catégorie', s.par_categorie)}${distCard('Leads par marque orientée', s.par_marque)}</div>
      </div>
      <div class="section-title"><h2>Données</h2><div class="tools"><button class="btn small" id="dedupLeads" title="Regroupe les leads identiques reçus à quelques minutes d'intervalle">⧉ Fusionner les doublons</button> ${exportBtn('chat_leads')} ${exportBtn('chat_messages')}</div></div>
      ${st.html}<div id="chat_sub"></div>`;
    barLine('c_chat', [{label: 'Messages', data: s.serie}, {label: 'Leads', data: s.serie_leads, type: 'line'}]);
    st.bind(main);
    $('#dedupLeads').onclick = async (e) => {
      if (!confirm("Regrouper les leads identiques reçus dans la même fenêtre de temps ?\n\nLes doublons sont fusionnés dans le lead le plus ancien (suivi et commentaire conservés). Action définitive.")) return;
      const b = e.currentTarget; b.disabled = true; b.textContent = 'Fusion en cours…';
      try {
        const r = await api('chat/leads/dedup', {method: 'POST'});
        toast(r.fusionnes ? `${r.fusionnes} doublon(s) fusionné(s) — ${r.restants} lead(s)` : 'Aucun doublon à fusionner');
        if (r.fusionnes) { show('chatbot'); refreshBadges(); } else { b.disabled = false; b.textContent = '⧉ Fusionner les doublons'; }
      } catch (err) {
        toast(err.message, true); b.disabled = false; b.textContent = '⧉ Fusionner les doublons';
      }
    };

    const lead = (l) => {
      const fields = [
        {key: 'suivi', label: 'Suivi commercial', type: 'select', options: [['nouveau', 'Nouveau'], ['contacte', 'Contacté'], ['converti', 'Converti'], ['perdu', 'Perdu']]},
        {key: 'commentaire', label: 'Commentaire', type: 'textarea'},
      ];
      openDrawer(`Lead — ${h([l.prenom, l.nom].filter(Boolean).join(' ') || l.societe || '')}`, `
        ${kv([['Premier contact', fmtDate(l.date)], ['Dernière mise à jour', l.nb_mises_a_jour ? `${fmtDate(l.updated_at)} <span class="hint">(${l.nb_mises_a_jour} mise${l.nb_mises_a_jour > 1 ? 's' : ''} à jour regroupée${l.nb_mises_a_jour > 1 ? 's' : ''})</span>` : ''], ['Société', h(l.societe)], ['Email', l.email ? `<a href="mailto:${h(l.email)}">${h(l.email)}</a>` : ''], ['Téléphone', h(l.telephone)],
          ['Département', h(l.departement)], ['Type interlocuteur', h(l.type_interlocuteur)], ['Marque orientée', h(l.marque_orientee)], ['Statut IA', pill(l.statut, cls(l.statut))],
          ['Catégorie', h(l.categorie)], ['Service destinataire', h(l.dest_libelle)], ['Envoyé à', h(l.dest_to)], ['À vérifier', h(l.a_verifier)],
          ['Besoin résumé', h(l.besoin_resume)], ['Produits proposés', h(l.produits_proposes)], ['Session', l.session_id ? `<button class="link" id="goSess">${h(l.session_id)}</button>` : '']])}
        <h4>Suivi</h4>${editForm(fields, l)}`, saveBtn() + delBtn());
      const gs = $('#goSess'); if (gs) gs.onclick = () => conversation({session_id: l.session_id});
      $('#drawerSave').onclick = async () => { await patch('chat/leads/' + l.id, readForm($('#drawerBody'), fields)); toast('Lead enregistré'); closeDrawer(); show('chatbot'); refreshBadges(); };
      $('#drawerDelete').onclick = async () => { if (confirm('Supprimer ce lead ?')) { await api('chat/leads/' + l.id, {method: 'DELETE'}); closeDrawer(); show('chatbot'); } };
    };
    const conversation = async (sess) => {
      const m = await api('chat/messages', {query: {session_id: sess.session_id, dir: 'asc', limit: 500}});
      openDrawer(`Conversation ${h(sess.session_id)}`, `
        ${kv([['Page', m.rows[0] ? `<a href="${h(m.rows[0].page_url)}" target="_blank" rel="noopener">${h(m.rows[0].page_url)}</a>` : ''], ['Messages', m.rows.length]])}
        <div class="chat">${m.rows.map((x) => `<div class="bubble u"><span class="t">Visiteur · ${fmtDate(x.date)}</span>${h(x.message)}</div><div class="bubble a"><span class="t">Claire</span>${h(x.reponse)}</div>`).join('')}</div>`);
    };

    function renderSub() {
      $$('[data-sub]', main).forEach((b) => b.classList.toggle('active', b.dataset.sub === chatSub.v));
      const root = $('#chat_sub');
      if (chatSub.v === 'leads') {
        table(root, leads.rows, [
          {key: 'date', label: 'Date', render: (r) => fmtDate(r.date)},
          {key: 'suivi', label: 'Suivi', render: (r) => `<select class="inline" data-lead="${r.id}">${[['nouveau', 'Nouveau'], ['contacte', 'Contacté'], ['converti', 'Converti'], ['perdu', 'Perdu']].map(([v, t]) => `<option value="${v}" ${r.suivi === v ? 'selected' : ''}>${t}</option>`).join('')}</select>`},
          {key: 'societe', label: 'Société'}, {key: 'nom', label: 'Nom', render: (r) => h([r.prenom, r.nom].filter(Boolean).join(' '))},
          {key: 'email', label: 'Email'}, {key: 'telephone', label: 'Téléphone'}, {key: 'departement', label: 'Dpt'},
          {key: 'categorie', label: 'Catégorie'}, {key: 'marque_orientee', label: 'Marque'}, {key: 'statut', label: 'Statut IA', render: (r) => pill(r.statut, cls(r.statut))},
          {key: 'besoin_resume', label: 'Besoin', render: (r) => clip(r.besoin_resume, true)},
        ], {sort: 'date', filters: [{key: 'suivi', label: 'Suivi', map: L}, {key: 'categorie', label: 'Catégorie'}, {key: 'marque_orientee', label: 'Marque'}], onRow: lead,
          afterRender: (el) => $$('[data-lead]', el).forEach((sel) => sel.addEventListener('change', async () => {
            await patch('chat/leads/' + sel.dataset.lead, {suivi: sel.value}); toast('Suivi mis à jour'); const l = leads.rows.find((x) => x.id == sel.dataset.lead); if (l) l.suivi = sel.value; refreshBadges();
          }))});
      } else if (chatSub.v === 'conversations') {
        table(root, sessions.rows, [
          {key: 'fin', label: 'Dernier message', render: (r) => fmtDate(r.fin)},
          {key: 'nb_messages', label: 'Messages', num: true},
          {key: 'nb_leads', label: 'Lead', render: (r) => r.nb_leads ? pill('oui', 'ok') : pill('non', 'muted')},
          {key: 'premier_message', label: 'Premier message', render: (r) => clip(r.premier_message, true)},
          {key: 'page_url', label: 'Page', render: (r) => clip((r.page_url || '').replace(/^https?:\/\/[^/]+/, '') || r.page_url)},
          {key: 'session_id', label: 'Session'},
        ], {sort: 'fin', onRow: conversation});
      } else if (chatSub.v === 'routage') {
        routageUI(root, 'chatbot', {keyLabel: 'Catégorie', placeholder: 'equipement, pieces, sav…',
          hint: "Catégorie détectée par Claire (equipement / pieces / sav / finance / autre) → destinataires du mail interne « Nouveau lead ». Le scénario Make lit cette table à chaque lead."});
      } else {
        root.innerHTML = journal(logs.rows);
      }
    }
    renderSub();
  };

  // ================================================================== CLAIRE ADV
  tabs.adv = async () => {
    const [s, dem, logs] = await Promise.all([api('stats/adv'), api('adv/demandes'), api('log', {query: {scenario: 'adv', limit: 50}})]);
    const k = s.kpi;
    main.innerHTML = `
      <div class="section-title"><h2>Claire ADV — e-mails équipements et maintenance</h2><span class="hint">scénario Make 9209946 · boîte service.clients@multiairfrance.store</span></div>
      <div class="kpis">
        ${kpi(num(k.mails_recus), 'E-mails reçus au total')}
        ${kpi(num(k.mails_j30), 'E-mails traités sur 30 jours')}
        ${kpi(num(k.mails_total), 'E-mails traités au total')}
        ${kpi(num(k.non_traites), 'Reçus sans réponse', k.non_traites ? 'warn' : 'ok')}
        ${kpi(num(k.auto), 'Réponses automatiques [AUTO]', 'ok')}
        ${kpi(num(k.escalade), 'Escalades', k.escalade ? 'warn' : '')}
        ${kpi(num(k.a_valider), 'À valider', k.a_valider ? 'danger' : 'ok')}
        ${kpi(num(k.equipements), 'Équipements et prix', 'info')}
        ${kpi(num(k.maintenance), 'Plans de maintenance', 'info')}
        ${kpi(num(k.erreurs), 'Erreurs agent', k.erreurs ? 'danger' : '')}
      </div>
      <div class="grid2" style="margin-top:12px">
        <div class="card"><h3>E-mails reçus par jour (30 j)</h3><div class="chart-wrap"><canvas id="c_adv"></canvas></div></div>
        <div class="grid3" style="grid-template-columns:1fr">${distCard('Par cas', s.par_cas)}${distCard('Par technologie', s.par_techno)}</div>
      </div>
      <div class="section-title"><h2>E-mails reçus et réponses</h2><div class="tools">${exportBtn('adv_demandes')}</div></div>
      <div id="adv_tbl"></div>
      <div class="section-title"><h2>Routage des mails</h2></div>
      <div id="adv_routage"></div>
      <div class="section-title"><h2>Journal</h2></div>${journal(logs.rows)}`;
    barLine('c_adv', [{label: 'Mails', data: s.serie}]);
    routageUI($('#adv_routage'), 'adv', {keyLabel: 'Cas', placeholder: 'DEVIS DIRECT, LEAD…',
      hint: "Cas détecté par Claire (DEVIS DIRECT / STANDARD / LEAD / MAINTENANCE) → destinataires en copie de la réponse. ESCALADE et ERREUR → personnes qui reçoivent la demande à valider au lieu du client."});
    const detail = (d) => {
      const fields = [
        {key: 'statut_suivi', label: 'Suivi', type: 'select', options: [['recu', 'Reçu, sans réponse'], ['envoye', 'Envoyé au client'], ['a_valider', 'À valider'], ['valide', 'Validé'], ['traite', 'Traité']]},
        {key: 'traite_par', label: 'Traité par'}, {key: 'commentaire', label: 'Commentaire', type: 'textarea'},
      ];
      openDrawer(`${h(d.sujet || '(sans objet)')}`, `
        ${kv([['Date', fmtDate(d.date)], ['Expéditeur', h([d.from_nom, d.from_email].filter(Boolean).join(' — '))], ['Tag', pill(d.tag, cls(d.tag))], ['Famille', h(d.famille)], ['Cas', pill(d.cas, cls(d.cas))],
          ['Techno', h(d.techno)], ['Critère', h(d.critere)], ['Pression', h(d.pression)], ['Configuration', h(d.configuration)], ['Options retenues', h(d.options_retenues)],
          ['Envoyé le', fmtDate(d.envoye_at)], ['Message-ID', h(d.message_id)]])}
        <h4>Question du client</h4><pre class="raw">${h(d.message || '—')}</pre>
        <h4>Réponse de Claire ${d.tag === 'RECU' ? '<span class="hint">(aucune : e-mail écarté par les filtres)</span>' : ''}</h4><pre class="raw">${h(d.mail_envoye || '—')}</pre>
        ${d.analyse_brute ? `<h4>Bloc d'analyse</h4><pre class="raw">${h(d.analyse_brute)}</pre>` : ''}
        <h4>Suivi</h4>${editForm(fields, d)}`, saveBtn() + delBtn());
      $('#drawerSave').onclick = async () => { await patch('adv/demandes/' + d.id, readForm($('#drawerBody'), fields)); toast('Enregistré'); closeDrawer(); show('adv'); refreshBadges(); };
      $('#drawerDelete').onclick = async () => { if (confirm('Supprimer ?')) { await api('adv/demandes/' + d.id, {method: 'DELETE'}); closeDrawer(); show('adv'); } };
    };
    table('#adv_tbl', dem.rows, [
      {key: 'date', label: 'Date', render: (r) => fmtDate(r.date)},
      {key: 'tag', label: 'Tag', render: (r) => pill(r.tag, cls(r.tag))},
      {key: 'statut_suivi', label: 'Suivi', render: (r) => pill(L(r.statut_suivi), cls(r.statut_suivi))},
      {key: 'famille', label: 'Famille'}, {key: 'cas', label: 'Cas'},
      {key: 'from_email', label: 'Expéditeur', render: (r) => clip(r.from_nom ? `${r.from_nom} <${r.from_email}>` : r.from_email)},
      {key: 'sujet', label: 'Objet', render: (r) => clip(r.sujet, true)},
      {key: 'techno', label: 'Techno'}, {key: 'critere', label: 'Critère'},
    ], {sort: 'date', filters: [{key: 'tag', label: 'Tag'}, {key: 'statut_suivi', label: 'Suivi', map: L}, {key: 'famille', label: 'Famille'}, {key: 'cas', label: 'Cas'}], onRow: detail});
  };

  // ================================================================== CSO DEVIS
  const CSO_STATUTS = ['En attente', 'Relance 1', 'Relance 2', 'Relance 3', 'Gagne', 'Perdu', 'Sans suite'];
  tabs.cso = async () => {
    const [s, devis, relances, prevues, modeles, logs] = await Promise.all([
      api('stats/cso'), api('cso/devis'), api('cso/relances'), api('cso/devis/relances_prevues'),
      api('cso/devis/modeles_relance'), api('log', {query: {scenario: 'cso', limit: 50}}),
    ]);
    const k = s.kpi;
    main.innerHTML = `
      <div class="section-title"><h2>CSO — analyse des devis et suivi commercial</h2><span class="hint">scénarios Make 9775498 · 9776471 · boîte cso@multiairfrance.store</span></div>
      <div class="kpis">
        ${kpi(num(k.devis_mois), 'Devis ce mois')}
        ${kpi(eur0(k.montant_mois), 'Montant HT ce mois', 'info')}
        ${kpi(num(k.en_cours), 'Devis en cours', 'warn')}
        ${kpi(eur0(k.montant_en_cours), 'Montant HT en cours', 'warn')}
        ${kpi(num(k.gagnes), 'Gagnés', 'ok')}
        ${kpi(eur0(k.montant_gagne), 'Montant HT gagné', 'ok')}
        ${kpi(num(k.perdus), 'Perdus', k.perdus ? 'danger' : '')}
        ${kpi(pct(k.taux_transformation), 'Taux de transformation', 'info')}
        ${kpi(num(k.relances), 'Relances envoyées')}
        ${kpi(eur0(k.panier_moyen), 'Panier moyen HT')}
        ${kpi(num(k.ecarts), 'Écarts de cohérence', k.ecarts ? 'danger' : 'ok')}
        ${kpi(num(k.devis_total), 'Devis au total')}
      </div>
      <div class="grid2" style="margin-top:12px">
        <div class="card"><h3>Devis et montant HT par jour (30 j)</h3><div class="chart-wrap"><canvas id="c_cso"></canvas></div></div>
        <div class="grid3" style="grid-template-columns:1fr">${distCard('Par statut', s.par_statut)}${distCard('Par commercial', s.par_commercial)}</div>
      </div>
      <div class="section-title"><h2>Devis</h2><div class="tools"><span class="hint">Statut modifiable directement dans la liste</span> ${exportBtn('cso_devis')} ${exportBtn('cso_lignes')}</div></div>
      <div id="cso_tbl"></div>
      <div class="section-title"><h2>Relances programmées (${prevues.rows.length})</h2><div class="tools">
        <span class="hint">Cliquez une ligne pour lire le mail qui partira</span>
        <button class="btn small" id="editModeles">✎ Modifier les textes</button></div></div>
      <div id="cso_prev"></div>
      <div class="section-title"><h2>Relances envoyées au client</h2><div class="tools"><span class="hint">Envoyées automatiquement à J+3, J+7 et J+15 par le scénario 9776471</span> ${exportBtn('cso_relances')}</div></div>
      <div id="cso_rel"></div>
      <div class="section-title"><h2>Journal</h2></div>${journal(logs.rows)}`;
    barLine('c_cso', [{label: 'Devis', data: s.serie}, {label: 'Montant HT (€)', data: s.serie_montant, type: 'line', axis: 'y2'}],
      {scales: {y2: {position: 'right', beginAtZero: true, grid: {display: false}}}});
    const detail = async (row) => {
      const d = (await api('cso/devis/' + row.id)).devis;
      const fields = [
        {key: 'statut', label: 'Statut', type: 'select', options: CSO_STATUTS},
        {key: 'reponse_client', label: 'Réponse client', type: 'textarea'}, {key: 'commentaire', label: 'Commentaire interne', type: 'textarea'},
      ];
      const cur = d.lignes.filter((l) => l.version == d.version);
      const old = d.lignes.filter((l) => l.version != d.version);
      openDrawer(`Devis n° ${h(d.n_offre)} — ${h(d.client || '')}`, `
        ${kv([['Statut', pill(d.statut, cls(d.statut))], ['Traité le', fmtDate(d.date_traitement)], ['Date offre', fmtDate(d.date_offre, false)], ['Validité', fmtDate(d.validite_offre, false)],
          ['Client', h(d.client) + (d.n_client ? ` <span class="hint">(n° ${h(d.n_client)})</span>` : '')], ['Contact', h(d.contact_client)],
          ['Email client', d.email_client ? `<a href="mailto:${h(d.email_client)}">${h(d.email_client)}</a>` : ''], ['Téléphone', h(d.tel_client)],
          ['Destinataire mail', h(d.destinataire_email)], ['Copies', h(d.copies_email)], ['Commercial', h(d.commercial)], ['Contact interne', h(d.contact_interne)],
          ['Réf. demande client', h(d.ref_demande_client)], ['Montant HT', eur(d.montant_ht)], ['Transport', eur(d.transport)], ['Montant TTC', eur(d.montant_ttc)],
          ['Contrôle cohérence', pill(d.controle_coherence, d.controle_coherence === 'OK' ? 'ok' : 'danger')], ['Version', d.version],
          ['Relances prévues', `J+3 : ${fmtDate(d.relance_1_j3, false)} · J+7 : ${fmtDate(d.relance_2_j7, false)} · J+15 : ${fmtDate(d.relance_3_j15, false)}`],
          ['Relances envoyées', d.relances_envoyees], ['Fichier source', h(d.fichier_source)]])}
        <h4>Lignes du devis — version ${d.version} · ${cur.length} ligne${cur.length > 1 ? 's' : ''}</h4>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Poste</th><th>Référence</th><th>Désignation</th><th>Qté</th><th>PU</th><th>Total HT</th><th>Origine</th></tr></thead><tbody>
          ${cur.map((l) => `<tr style="cursor:default"><td>${h(l.poste)}</td><td>${h(l.reference)}</td><td>${h(l.designation)}</td><td class="num">${num(l.quantite)}</td><td class="num">${eur(l.prix_unitaire)}</td><td class="num">${eur(l.prix_total_ht)}</td><td>${h(l.pays_origine)}</td></tr>`).join('') || '<tr><td colspan="7" class="empty">Aucune ligne</td></tr>'}
        </tbody></table></div>
        ${old.length ? `<h4>Versions précédentes (${old.length} lignes)</h4><div class="hint">${old.map((l) => `v${l.version} · ${h(l.reference)} · ${h(l.designation)} · ${eur(l.prix_total_ht)}`).join('<br>')}</div>` : ''}
        <h4>Relances envoyées (${d.relances.length})</h4>${d.relances.length ? d.relances.map((r) => `<div>Relance ${r.numero} · ${fmtDate(r.date_envoi)} · à ${h(r.destinataire) || '—'}${r.cc ? ` · copie : ${h(r.cc)}` : ''}</div>`).join('') : '<span class="hint">Aucune</span>'}
        <h4>Suivi commercial</h4>${editForm(fields, d)}`, saveBtn() + delBtn());
      $('#drawerSave').onclick = async () => { await patch('cso/devis/' + d.id, readForm($('#drawerBody'), fields)); toast('Devis enregistré'); closeDrawer(); show('cso'); refreshBadges(); };
      $('#drawerDelete').onclick = async () => { if (confirm('Supprimer ce devis et ses lignes ?')) { await api('cso/devis/' + d.id, {method: 'DELETE'}); closeDrawer(); show('cso'); } };
    };
    table('#cso_tbl', devis.rows, [
      {key: 'date_traitement', label: 'Traité le', render: (r) => fmtDate(r.date_traitement)},
      {key: 'n_offre', label: 'N° offre'},
      {key: 'statut', label: 'Statut', render: (r) => `<select class="inline" data-devis="${r.id}">${CSO_STATUTS.map((v) => `<option ${r.statut === v ? 'selected' : ''}>${v}</option>`).join('')}</select>`},
      {key: 'client', label: 'Client', render: (r) => clip(r.client)}, {key: 'contact_client', label: 'Contact'},
      {key: 'commercial', label: 'Commercial', render: (r) => clip((r.commercial || '').split('@')[0])},
      {key: 'montant_ht', label: 'Montant HT', num: true, render: (r) => eur(r.montant_ht), sortVal: (r) => Number(r.montant_ht || 0)},
      {key: 'nb_lignes', label: 'Lignes', num: true},
      {key: 'relances_envoyees', label: 'Relances', num: true},
      {key: 'validite_offre', label: 'Validité', render: (r) => fmtDate(r.validite_offre, false)},
      {key: 'controle_coherence', label: 'Contrôle', render: (r) => pill(r.controle_coherence, r.controle_coherence === 'OK' ? 'ok' : 'danger')},
      {key: 'version', label: 'V', num: true},
    ], {sort: 'date_traitement', filters: [{key: 'statut', label: 'Statut'}, {key: 'commercial', label: 'Commercial'}, {key: 'controle_coherence', label: 'Contrôle'}], onRow: detail,
      afterRender: (el) => $$('[data-devis]', el).forEach((sel) => sel.addEventListener('change', async () => {
        await patch('cso/devis/' + sel.dataset.devis, {statut: sel.value}); toast('Statut mis à jour'); const d = devis.rows.find((x) => x.id == sel.dataset.devis); if (d) d.statut = sel.value; refreshBadges();
      }))});
    const apercu = (r) => openDrawer(`Relance ${r.relance_due} — devis n° ${h(r.n_offre)}`, `
      ${kv([['Date prévue', fmtDate(r.date_prevue, false) + (r.due ? ' <span class="pill danger">à envoyer</span>' : '')],
        ['Client', h(r.client)], ['Montant HT', eur(r.montant_ht)],
        ['Envoyée à', (r.email_relance || '').split(';').map((e) => h(e)).join('<br>') || '<span class="hint">—</span>'],
        ['En copie', (r.cc_relance || '').split(';').filter(Boolean).map((e) => h(e)).join('<br>') || '<span class="hint">aucune</span>'],
        ['Réponse du client vers', h(r.repondre_a)], ['Objet', h(r.objet_relance)]])}
      <h4>Mail qui sera envoyé</h4><pre class="raw">${h(r.texte_relance)}</pre>`);
    table('#cso_prev', prevues.rows, [
      {key: 'date_prevue', label: 'Date prévue', render: (r) => fmtDate(r.date_prevue, false) + (r.due ? ' ' + pill('à envoyer', 'danger') : '')},
      {key: 'relance_due', label: 'Relance', num: true, render: (r) => pill('n° ' + r.relance_due, r.relance_due >= 3 ? 'danger' : (r.relance_due === 2 ? 'warn' : 'info'))},
      {key: 'n_offre', label: 'N° offre'},
      {key: 'client', label: 'Client', render: (r) => clip(r.client)},
      {key: 'contact_client', label: 'Contact'},
      {key: 'email_relance', label: 'Sera envoyée à', render: (r) => (r.email_relance || '').split(';').filter(Boolean).map((e) => h(e)).join('<br>')},
      {key: 'cc_relance', label: 'En copie', render: (r) => (r.cc_relance || '').split(';').filter(Boolean).map((e) => h(e)).join('<br>') || '<span class="hint">aucune</span>'},
      {key: 'montant_ht', label: 'Montant HT', num: true, render: (r) => eur(r.montant_ht), sortVal: (r) => Number(r.montant_ht || 0)},
    ], {sort: 'date_prevue', asc: true, filters: [{key: 'relance_due', label: 'Relance'}, {key: 'commercial', label: 'Commercial'}], onRow: apercu});
    $('#editModeles').onclick = () => {
      const champs = [];
      [1, 2, 3].forEach((n) => {
        champs.push({key: `relance_${n}_objet`, label: `Relance ${n} — objet`});
        champs.push({key: `relance_${n}_texte`, label: `Relance ${n} — texte`, type: 'textarea'});
      });
      const vals = {};
      [1, 2, 3].forEach((n) => { vals[`relance_${n}_objet`] = modeles.modeles[n].objet; vals[`relance_${n}_texte`] = modeles.modeles[n].texte; });
      openDrawer('Textes des relances', `
        <p class="hint">Champs remplacés automatiquement : <code>{contact}</code> <code>{client}</code>
        <code>{n_offre}</code> <code>{date_offre}</code> <code>{validite_offre}</code>
        <code>{montant_ht}</code> <code>{commercial}</code>. Les relances déjà envoyées ne sont pas modifiées.</p>
        ${editForm(champs, vals)}`, saveBtn());
      $('#drawerSave').onclick = async () => {
        await api('parametres', {method: 'POST', body: readForm($('#drawerBody'), champs)});
        toast('Textes enregistrés'); closeDrawer(); show('cso');
      };
    };
    table('#cso_rel', relances.rows, [
      {key: 'date_envoi', label: 'Envoyée le', render: (r) => fmtDate(r.date_envoi)},
      {key: 'numero', label: 'Relance', num: true, render: (r) => pill('n° ' + r.numero, r.numero >= 3 ? 'danger' : (r.numero === 2 ? 'warn' : 'info'))},
      {key: 'n_offre', label: 'N° offre'},
      {key: 'client', label: 'Client', render: (r) => clip(r.client)},
      {key: 'destinataire', label: 'Envoyée à', render: (r) => r.destinataire ? `<a href="mailto:${h(r.destinataire)}">${h(r.destinataire)}</a>` : '<span class="hint">—</span>'},
      {key: 'cc', label: 'En copie', render: (r) => r.cc ? clip(r.cc) : '<span class="hint">aucune</span>'},
      {key: 'commercial', label: 'Commercial', render: (r) => clip((r.commercial || '').split('@')[0])},
      {key: 'montant_ht', label: 'Montant HT', num: true, render: (r) => eur(r.montant_ht), sortVal: (r) => Number(r.montant_ht || 0)},
      {key: 'statut', label: 'Statut du devis', render: (r) => pill(r.statut, cls(r.statut))},
    ], {sort: 'date_envoi', filters: [{key: 'numero', label: 'Relance'}, {key: 'commercial', label: 'Commercial'}, {key: 'statut', label: 'Statut'}],
      onRow: (r) => { const d = devis.rows.find((x) => x.id == r.devis_id); if (d) detail(d); }});
  };

  // ================================================================== PRIME CEE
  const ceeSub = {v: 'leads'};
  tabs.cee = async () => {
    const [s, leads, actions, logs] = await Promise.all([api('stats/cee'), api('cee/leads'), api('cee/actions'), api('log', {query: {scenario: 'cee', limit: 50}})]);
    const k = s.kpi;
    const st = subtabs([['leads', `Leads (${leads.rows.length})`], ['actions', `Actions à mener (${actions.rows.filter((a) => !a.fait).length})`], ['journal', 'Journal']], ceeSub.v, (v) => { ceeSub.v = v; renderSub(); });
    main.innerHTML = `
      <div class="section-title"><h2>Prime CEE — calculateur Worthington Creyssensac + qualification WhatsApp</h2><span class="hint">scénarios Make 9324836 (A) · 9339470 (B)</span></div>
      <div class="kpis">
        ${kpi(num(k.leads_j30), 'Leads sur 30 jours')}
        ${kpi(num(k.leads_total), 'Leads au total')}
        ${kpi(num(k.avec_telephone), 'Avec téléphone', 'info')}
        ${kpi(num(k.qualifies), 'Qualifiés', 'ok')}
        ${kpi(num(k.conversations), 'Messages WhatsApp')}
        ${kpi(num(k.actions_a_faire), 'Actions à réaliser', k.actions_a_faire ? 'danger' : 'ok')}
        ${kpi(eur0(k.prime_moyenne), 'Prime CEE moyenne estimée', 'info')}
        ${kpi(eur0(k.economies_cumulees), 'Économies annuelles cumulées')}
        ${kpi(num(k.co2_cumule) + ' t', 'CO₂ évité cumulé')}
      </div>
      <div class="grid2" style="margin-top:12px">
        <div class="card"><h3>Leads et messages WhatsApp par jour (30 j)</h3><div class="chart-wrap"><canvas id="c_cee"></canvas></div></div>
        <div class="grid3" style="grid-template-columns:1fr">${distCard('Par profil', s.par_profil)}${distCard('Par suivi', s.par_suivi.map((r) => ({k: L(r.k), n: r.n})))}</div>
      </div>
      <div class="section-title"><h2>Données</h2><div class="tools">${exportBtn('cee_leads')} ${exportBtn('cee_actions')}</div></div>
      ${st.html}<div id="cee_sub"></div>`;
    barLine('c_cee', [{label: 'Leads', data: s.serie}, {label: 'Messages WhatsApp', data: s.serie_conversations, type: 'line'}]);
    st.bind(main);
    const lead = async (row) => {
      const l = (await api('cee/leads/' + row.id)).lead;
      const fields = [
        {key: 'suivi', label: 'Suivi commercial', type: 'select', options: [['nouveau', 'Nouveau'], ['qualifie', 'Qualifié'], ['rappel_planifie', 'Rappel planifié'], ['converti', 'Converti'], ['perdu', 'Perdu']]},
        {key: 'commentaire', label: 'Commentaire', type: 'textarea'},
      ];
      openDrawer(`Lead CEE — ${h([l.prenom, l.nom].filter(Boolean).join(' '))} ${l.societe ? '· ' + h(l.societe) : ''}`, `
        ${kv([['Date', fmtDate(l.date)], ['Statut', pill(l.statut, cls(l.statut))], ['Profil', h(l.profil)], ['Email', l.email ? `<a href="mailto:${h(l.email)}">${h(l.email)}</a>` : ''],
          ['Téléphone', h(l.telephone_brut) + (l.telephone_intl ? ` <span class="hint">(${h(l.telephone_intl)})</span>` : '')], ['Message initial', h(l.message_initial)],
          ['Page', l.page_url ? `<a href="${h(l.page_url)}" target="_blank" rel="noopener">${h(l.page_url)}</a>` : ''], ['Simulations', l.nb_simulations]])}
        <h4>Estimation du calculateur</h4>
        ${kv([['Économies / an', eur(l.eco_an)], ['Économies 5 ans', eur(l.eco_5ans)], ['Prime CEE estimée', `${eur(l.cee_min)} à ${eur(l.cee_max)}`], ['ROI avec CEE', h(l.roi_avec_cee)],
          ['Régime', h(l.regime)], ['Usage chaleur', h(l.usage)], ['Nb compresseurs', h(l.nb_compresseurs)], ['Solutions', h(l.solutions)], ['CO₂ évité', l.co2_tonnes ? num(l.co2_tonnes) + ' t' : '']])}
        <h4>Fil WhatsApp (${l.conversations.length})</h4>
        <div class="chat">${l.conversations.map((c) => `<div class="bubble u"><span class="t">Client · ${fmtDate(c.date)}</span>${h(c.message || '(bouton)')}</div><div class="bubble a"><span class="t">Claire${c.qualifie === 'true' ? ' · qualifié' : ''}${c.profil ? ' · ' + h(c.profil) : ''}</span>${h(c.reponse)}</div>`).join('') || '<span class="hint">Aucun échange</span>'}</div>
        <h4>Actions (${l.actions.length})</h4>${l.actions.map((a) => `<div>${a.fait ? '✅' : '⬜'} ${pill(a.type_profil, 'info')} ${h(a.action)} — <span class="hint">${h(a.detail)}</span></div>`).join('') || '<span class="hint">Aucune</span>'}
        <h4>Suivi</h4>${editForm(fields, l)}`, saveBtn() + delBtn());
      $('#drawerSave').onclick = async () => { await patch('cee/leads/' + l.id, readForm($('#drawerBody'), fields)); toast('Lead enregistré'); closeDrawer(); show('cee'); refreshBadges(); };
      $('#drawerDelete').onclick = async () => { if (confirm('Supprimer ce lead ?')) { await api('cee/leads/' + l.id, {method: 'DELETE'}); closeDrawer(); show('cee'); } };
    };
    function renderSub() {
      $$('[data-sub]', main).forEach((b) => b.classList.toggle('active', b.dataset.sub === ceeSub.v));
      const root = $('#cee_sub');
      if (ceeSub.v === 'leads') {
        table(root, leads.rows, [
          {key: 'date', label: 'Date', render: (r) => fmtDate(r.date)},
          {key: 'suivi', label: 'Suivi', render: (r) => pill(L(r.suivi), cls(r.suivi))},
          {key: 'statut', label: 'Statut', render: (r) => pill(r.statut, cls(r.statut))},
          {key: 'nom', label: 'Nom', render: (r) => h([r.prenom, r.nom].filter(Boolean).join(' '))}, {key: 'societe', label: 'Société'},
          {key: 'email', label: 'Email'}, {key: 'telephone_brut', label: 'Téléphone'}, {key: 'profil', label: 'Profil'},
          {key: 'cee_max', label: 'Prime CEE max', num: true, render: (r) => eur(r.cee_max), sortVal: (r) => Number(r.cee_max || 0)},
          {key: 'eco_an', label: 'Éco / an', num: true, render: (r) => eur(r.eco_an), sortVal: (r) => Number(r.eco_an || 0)},
          {key: 'solutions', label: 'Solutions', render: (r) => clip(r.solutions)}, {key: 'nb_simulations', label: 'Simul.', num: true},
        ], {sort: 'date', filters: [{key: 'suivi', label: 'Suivi', map: L}, {key: 'statut', label: 'Statut'}, {key: 'profil', label: 'Profil'}], onRow: lead});
      } else if (ceeSub.v === 'actions') {
        table(root, actions.rows, [
          {key: 'fait', label: 'Fait', render: (r) => `<input type="checkbox" class="inline-check" data-act="${r.id}" ${r.fait ? 'checked' : ''}>`},
          {key: 'date', label: 'Date', render: (r) => fmtDate(r.date)},
          {key: 'type_profil', label: 'Profil', render: (r) => pill(r.type_profil, 'info')},
          {key: 'societe', label: 'Société'}, {key: 'telephone', label: 'Téléphone'},
          {key: 'action', label: 'Action à mener'}, {key: 'detail', label: 'Détail', render: (r) => clip(r.detail, true)},
          {key: 'fait_at', label: 'Fait le', render: (r) => fmtDate(r.fait_at)},
        ], {sort: 'date', filters: [{key: 'type_profil', label: 'Profil'}, {key: 'fait', label: 'Fait', map: (v) => v === '1' ? 'oui' : 'non'}],
          onRow: (a) => { const l = leads.rows.find((x) => x.id === a.lead_id) || leads.rows.find((x) => x.telephone_intl === a.telephone); if (l) lead(l); },
          afterRender: (el) => $$('[data-act]', el).forEach((cb) => cb.addEventListener('change', async () => {
            await patch('cee/actions/' + cb.dataset.act, {fait: cb.checked ? 1 : 0}); toast(cb.checked ? 'Action marquée faite' : 'Action rouverte'); const a = actions.rows.find((x) => x.id == cb.dataset.act); if (a) a.fait = cb.checked ? 1 : 0; refreshBadges();
          }))});
      } else {
        root.innerHTML = journal(logs.rows);
      }
    }
    renderSub();
  };

  // ------------------------------------------------------------------ démarrage
  const hash = location.hash.replace('#', '');
  show(tabs[hash] ? hash : 'overview');
  refreshBadges();
  $('#tabs').addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) history.replaceState(null, '', '#' + b.dataset.tab); });
})();
