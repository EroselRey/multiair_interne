/* MULTIAIR — plateforme des demandes clients (demandes, équipe, réglages, autres automatisations) */
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
        const f = filterDefs.find((x) => x.key === k);
        if (v !== '') data = data.filter((r) => (f && f.test ? f.test(r, v) : String(r[k] ?? '') === v));
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
          ${filterDefs.map((f) => `<select data-filter="${h(f.key)}"><option value="">${h(f.label)} : tous</option>${(f.options || uniq(rows, f.key).map((v) => [v, f.map ? f.map(v) : v])).map(([v, t]) =>
            `<option value="${h(v)}" ${state.filter[f.key] === v ? 'selected' : ''}>${h(t)}</option>`).join('')}</select>`).join('')}
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
    : f.type === 'datetime' ? `<input type="datetime-local" name="${h(f.key)}" value="${h(String(obj[f.key] || '').replace(' ', 'T').slice(0, 16))}">`
    : `<input name="${h(f.key)}" value="${h(obj[f.key])}">`}</div>`).join('');
  const readForm = (root, fields) => Object.fromEntries(fields.map((f) => [f.key, $(`[name="${f.key}"]`, root).value]));
  const saveBtn = (label = 'Enregistrer') => `<button class="btn primary" id="drawerSave">${h(label)}</button>`;
  const delBtn = () => `<button class="btn danger" id="drawerDelete">Supprimer</button>`;

  // statut → couleur
  const cls = (s) => {
    s = String(s || '').toLowerCase();
    if (/urgent|escalade|erreur|ecart|perdu|a_valider|a_traiter|à traiter/.test(s)) return 'danger';
    if (/attente|relance|nouveau|draft|en_cours|sans|recu|reponse/.test(s)) return 'warn';
    if (/trait|gagn|auto|valide|converti|ok|envoye|qualifi|accueil/.test(s)) return 'ok';
    return 'muted';
  };
  const lbl = {
    a_traiter: 'À traiter', en_cours: 'En cours', traite: 'Traitée', nouveau: 'Nouveau',
    direct: 'Client direct', distributeur: 'Distributeur', sav: 'SAV', finance: 'Finance', commercial: 'Commercial',
    worthington: 'Worthington', mauguiere: 'Mauguière', abac: 'ABAC', pneumatech: 'Pneumatech',
    rso: 'RSO (terrain)', cta: 'CTA (agent externe)', backoffice: 'Back-office support', compta: 'Compta', piston: 'Compresseur à piston',
    role: 'Tout le rôle', role_departement: 'Le rôle, selon le département', contacts: 'Personnes choisies', contacte: 'Contacté', converti: 'Converti', perdu: 'Perdu',
    qualifie: 'Qualifié', rappel_planifie: 'Rappel planifié', envoye: 'Envoyé', a_valider: 'À valider', valide: 'Validé', recu: 'Reçu, sans réponse',
    'Reponse recue': 'Réponse reçue', Gagne: 'Gagné',
    vapi_direct: 'Appel direct', whatsapp_qualifie: 'Qualifié WhatsApp', sans_reponse_10min: 'Sans réponse WhatsApp', import: 'Import Sheets',
  };
  const L = (v) => lbl[v] || v || '—';

  // Statut d'une fiche d'appel : il décrit où en est Claire (qualification WhatsApp), jamais le
  // rappel du client — celui-ci se suit sur la demande. D'où des libellés et des couleurs neutres.
  const FICHE_STATUTS = {
    'En attente': ['En attente de réponse WhatsApp', 'warn'], Urgent: ['Urgent — en qualification', 'danger'],
    Transmis: ['Transmise au service', 'info'], Traite: ['Qualifiée par WhatsApp, transmise', 'info'],
    'Transmis (sans réponse WhatsApp)': ['Transmise sans réponse WhatsApp', 'muted'],
  };
  const ficheStatut = (s) => pill((FICHE_STATUTS[s] || [s])[0], (FICHE_STATUTS[s] || [0, 'muted'])[1]);

  // ------------------------------------------------------------------ utilisateur, navigation
  const MA = window.MA || {user: {nom: 'Administrateur', acces: 'admin'}, admin: true};
  const moi = MA.user || {nom: '', acces: ''};
  const ADMIN = !!MA.admin;
  const SERVICE_MOI = {sav: 'SAV', commerce: 'COMMERCIAL', finance: 'FINANCE', rh: 'RH'}[moi.acces] || null;
  const PAGES_SERVICE = ['a-traiter', 'demandes', 'demande', 'appel', 'stats', 'clients', 'client'];
  const main = $('#main');
  const app = $('#app');
  let current = 'a-traiter';
  let dernierHash = null;
  const tabs = {};

  async function show(tab, arg = null, push = true) {
    if (!tabs[tab] || (!ADMIN && !PAGES_SERVICE.includes(tab))) tab = 'a-traiter';
    current = tab;
    const hash = '#' + tab + (arg !== null && arg !== undefined ? '/' + arg : '');
    if (push && location.hash !== hash) history.pushState(null, '', hash);
    dernierHash = location.hash;
    const actif = {demande: 'demandes', appel: 'demandes', client: 'clients'}[tab] || tab;
    $$('#side [data-page]').forEach((b) => b.classList.toggle('active', b.dataset.page === actif));
    app.classList.remove('menu');
    closeDrawer();
    main.innerHTML = '<div class="loading">Chargement…</div>';
    try {
      await tabs[tab](arg);
    } catch (e) {
      main.innerHTML = `<div class="msg err">${h(e.message)}</div>`;
    }
    window.scrollTo(0, 0);
  }
  const depuisAdresse = (push) => { const [t, a] = location.hash.replace('#', '').split('/'); show(t || 'a-traiter', a ?? null, push); };
  // Retour arrière et liens internes (#demande/12) : une seule navigation par changement d'adresse.
  const suivreAdresse = () => { if (location.hash !== dernierHash) depuisAdresse(false); };
  window.addEventListener('popstate', suivreAdresse);
  window.addEventListener('hashchange', suivreAdresse);
  $('#side').addEventListener('click', (e) => { const b = e.target.closest('[data-page]'); if (b) show(b.dataset.page); });
  $('#menuBtn').addEventListener('click', (e) => { e.stopPropagation(); app.classList.toggle('menu'); });
  document.addEventListener('click', (e) => { if (app.classList.contains('menu') && !e.target.closest('#side')) app.classList.remove('menu'); });
  $('#logoutBtn').addEventListener('click', async () => { await fetch('api.php?r=auth/logout'); location.href = 'index.php'; });

  // Pastille « À traiter » et bandeau rouge des urgences non prises en charge (72 h), rafraîchis chaque minute.
  async function refreshBadges() {
    try {
      const r = await api('rep/demandes', {query: {statut: 'a_traiter', limit: 2000}});
      const n = r.rows.length;
      const b = $('[data-badge="a-traiter"]');
      if (b) { b.textContent = n; b.classList.toggle('zero', !n); }
      const limite = Date.now() - 72 * 3600 * 1000;
      urgentBar(r.rows.filter((d) => d.priorite === 'URGENT' && new Date(String(d.created_at).replace(' ', 'T')).getTime() >= limite).length);
    } catch (e) { /* silencieux */ }
  }
  function urgentBar(n) {
    const bar = $('#urgentBar');
    if (!bar) return;
    bar.hidden = !n;
    if (!n) return;
    bar.innerHTML = `<span class="pulse" aria-hidden="true"></span>
      <span>${n} urgence${n > 1 ? 's' : ''} — production arrêtée — ${n > 1 ? 'personne ne les a' : "personne ne l'a"} encore prise${n > 1 ? 's' : ''} en charge</span>
      <span class="go">Voir →</span>`;
    bar.onclick = () => show('a-traiter');
  }
  setInterval(() => { if (!document.hidden) refreshBadges(); }, 60000);

  // ------------------------------------------------------------------ icônes, canaux, états
  const PATHS = {
    telephone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
    chat: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    whatsapp: '<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z"/>',
    email: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
    sms: '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    prendre: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
    agenda: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    note: '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    lien: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
    envoi: '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
    oeil: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
    retour: '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
  };
  const ic = (n, cls = '') => `<svg class="ic ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${PATHS[n] || ''}</svg>`;
  const CANAUX = {telephone: 'Téléphone', whatsapp: 'WhatsApp', chat: 'Chat du site', email: 'E-mail'};
  const canalDe = (d) => {
    if (CANAUX[d.canal]) return d.canal;
    const s = String(d.source || '');
    return s.includes('chat') ? 'chat' : (['email', 'adv'].includes(s) ? 'email' : (s === 'whatsapp' ? 'whatsapp' : 'telephone'));
  };
  const canalHtml = (c, court = false) => `<span class="canal">${ic(c, 's')}${h(CANAUX[c] || c)}${court ? '' : ''}</span>`;
  const SERVICES = {SAV: 'SAV', COMMERCIAL: 'Commerce', FINANCE: 'Finance', RH: 'RH', AUTRE: 'À orienter'};
  const ETATS = {qualification: 'Avec Claire', a_traiter: 'À traiter', en_cours: 'En cours', traite: 'Traitée'};
  const etatDe = (d) => (d.fiche ? 'qualification' : d.statut);
  const estUrgente = (d) => !d.fiche && d.priorite === 'URGENT' && d.statut === 'a_traiter';
  const etatPill = (d) => (estUrgente(d) ? '<span class="st urgent">URGENT</span>' : `<span class="st ${etatDe(d)}">${h(ETATS[etatDe(d)] || etatDe(d))}</span>`)
    + (Number(d.nb_relances) && d.statut !== 'traite' ? ` <span class="pill danger" title="Le client a rappelé">Relancée${Number(d.nb_relances) > 1 ? ' ×' + d.nb_relances : ''}</span>` : '');
  const svcPill = (s) => `<span class="pill">${h(SERVICES[s] || s || '—')}</span>`;
  const telFr = (t) => { const m = String(t || '').match(/^33(\d{9})$/); return m ? ('0' + m[1]).replace(/(\d{2})(?=\d)/g, '$1 ') : (t || ''); };
  const sujetDe = (d) => d.type_panne || d.besoin_commercial || (d.nature ? nomDe(R_NATURES, d.nature) : '')
    || (d.reference_facture ? 'Facture ' + d.reference_facture : '') || d.resume || '';
  const materielDe = (d) => [d.marque_norm && d.marque_norm !== 'autre' ? nomDe(R_MARQUES, d.marque_norm) : (d.marque && !/^inconnu/i.test(d.marque) ? d.marque : ''), d.modele].filter(Boolean).join(' ');
  const clientDe = (d) => [d.contact, d.type_client ? L(d.type_client).toLowerCase() : ''].filter(Boolean).join(' · ');
  const prenom = (n) => String(n || '').trim().split(/\s+/)[0] || '';
  // « Julien » (ancien nom) et « Julien Jardin » désignent la même personne.
  const memePersonne = (a, b) => {
    a = String(a || '').trim().toLowerCase(); b = String(b || '').trim().toLowerCase();
    if (!a || !b) return false;
    if (a === b) return true;
    return (!a.includes(' ') || !b.includes(' ')) && a.split(' ')[0] === b.split(' ')[0];
  };
  const mienne = (d) => memePersonne(d.pris_par, moi.nom) || String(d.destinataires || '').split(/\s*,\s*/).some((n) => memePersonne(n, moi.nom));
  const quand = (s) => { const d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return h(s || ''); const auj = new Date();
    const hier = new Date(Date.now() - 86400000); const hh = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    if (d.toDateString() === auj.toDateString()) return hh;
    if (d.toDateString() === hier.toDateString()) return 'Hier ' + hh;
    return fmtDate(s, false).slice(0, 5) + ' ' + hh; };
  const jourFr = (s) => { const d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return ''; return d.toLocaleDateString('fr-FR', {weekday: 'long', day: 'numeric', month: 'long'}) + ' à ' + d.toLocaleTimeString('fr-FR', {hour: '2-digit', minute: '2-digit'}); };

  // Toutes les demandes visibles par l'utilisateur, plus les appels encore en échange avec Claire.
  async function chargerDemandes() {
    const [dem, fiches] = await Promise.all([api('rep/demandes', {query: {limit: 5000}}), api('rep/fiches', {query: {limit: 1000}}), chargerListes().catch(() => {})]);
    const avecDemande = new Set(dem.rows.map((d) => d.fiche_id).filter(Boolean));
    const SVC_FICHE = {technique: 'SAV', commercial: 'COMMERCIAL', finance: 'FINANCE'};
    const enCours = fiches.rows.filter((f) => ['En attente', 'Urgent'].includes(f.statut) && !avecDemande.has(f.id)).map((f) => ({
      fiche: f, id: f.id, fiche_id: f.id, created_at: f.created_at, service: SVC_FICHE[String(f.service || '').trim().toLowerCase()] || 'AUTRE',
      priorite: f.urgence ? 'URGENT' : 'Normal', statut: 'qualification', societe: f.societe, contact: f.contact, tel: f.tel_norm,
      marque: f.marque, modele: f.modele, departement: f.departement, canal: 'telephone', source: 'en_qualification',
      type_panne: f.type_panne, besoin_commercial: f.besoin_commercial, resume: f.resume}));
    return [...dem.rows, ...enCours];
  }
  const ouvrir = (d) => (d.fiche ? show('appel', d.fiche.id) : show('demande', d.id));

  // Faire avancer une demande depuis une liste ou sa fiche. Le client est prévenu par le serveur.
  async function avancer(d, chg, msg) {
    const corps = Object.assign({}, chg);
    if (corps.statut === 'en_cours' && !corps.pris_par) corps.pris_par = moi.nom;
    if (corps.statut === 'traite' && !corps.traite_par) corps.traite_par = moi.nom;
    const r = await patch('rep/demandes/' + d.id, corps);
    toast(msg || 'Enregistré');
    refreshBadges();
    return r.demande;
  }

  // ================================================================== À TRAITER
  const vueATraiter = {portee: 'tout', service: ''};
  tabs['a-traiter'] = async () => {
    const toutes = await chargerDemandes();
    const render = () => {
      let rows = toutes.filter((d) => !d.fiche);
      if (!SERVICE_MOI && vueATraiter.service) rows = rows.filter((d) => d.service === vueATraiter.service);
      if (vueATraiter.portee === 'moi') rows = rows.filter(mienne);
      const attente = (d) => new Date(String(d.created_at).replace(' ', 'T')).getTime();
      const aPrendre = rows.filter((d) => d.statut === 'a_traiter').sort((a, b) => (estUrgente(b) - estUrgente(a)) || (attente(b) - attente(a)));
      const enCours = rows.filter((d) => d.statut === 'en_cours').sort((a, b) => String(a.rappel_prevu || '9').localeCompare(String(b.rappel_prevu || '9')) || (attente(b) - attente(a)));
      const sept = Date.now() - 7 * 86400000;
      const traitees7 = rows.filter((d) => d.statut === 'traite' && d.traite_at && new Date(String(d.traite_at).replace(' ', 'T')).getTime() >= sept).length;
      const urgentes = aPrendre.filter(estUrgente).length;
      const qualif = toutes.filter((d) => d.fiche && (SERVICE_MOI || !vueATraiter.service || d.service === vueATraiter.service)).length;
      const nomSvc = SERVICE_MOI ? SERVICES[SERVICE_MOI] : (vueATraiter.service ? SERVICES[vueATraiter.service] : '');
      const carte = (d) => {
        const act = d.statut === 'a_traiter'
          ? `<button class="btn ${estUrgente(d) ? 'urgent' : 'primary'}" data-prendre="${d.id}">Je la prends</button>`
          : `<button class="btn go" data-traiter="${d.id}">${ic('check', 's')}Traitée</button>`;
        const qui = d.statut === 'en_cours'
          ? `<span>Pris par <b>${h(d.pris_par || '—')}</b></span>${d.rappel_prevu ? `<span>Rappel : ${h(jourFr(d.rappel_prevu))}</span>` : ''}`
          : ((d.destinataires || d.dest_to) ? `<span>Transmise à ${h(d.destinataires || String(d.dest_to || '').replace(/;/g, ', '))}</span>` : '');
        return `<article class="dcard ${estUrgente(d) ? 'urgent' : ''}" data-ouvrir="${d.id}">
          <div class="c1">${etatPill(d)}<span class="quand">${ic(canalDe(d), 's')}${h(CANAUX[canalDe(d)])} · ${h(rel(d.created_at))}</span></div>
          <div class="c2"><b>${h(d.societe || d.contact || 'Client')}${sujetDe(d) ? ' — ' + h(sujetDe(d)) : ''}</b>
            <span>${h([SERVICES[d.service], materielDe(d)].filter(Boolean).join(' · '))}</span></div>
          <div class="c3"><b>${h(clientDe(d) || '—')}</b>${d.code_postal || d.departement ? `<span>Site : ${h(d.code_postal || d.departement)}</span>` : ''}${qui}</div>
          <div class="c4"><button class="btn" data-ouvrir="${d.id}">Ouvrir</button>${act}</div>
        </article>`;
      };
      main.innerHTML = `
        <div class="page-head"><div class="t"><h1>Bonjour ${h(prenom(moi.nom))}</h1>
          <p>${aPrendre.length ? `${aPrendre.length} demande${aPrendre.length > 1 ? 's' : ''}${nomSvc ? ' ' + h(nomSvc) : ''} attend${aPrendre.length > 1 ? 'ent' : ''} un rappel${urgentes ? `, dont ${urgentes} urgente${urgentes > 1 ? 's' : ''}` : ''}.` : 'Aucune demande n\'attend de rappel.'}</p></div>
          ${SERVICE_MOI ? `<div class="seg" role="group" aria-label="Périmètre">
              <button class="${vueATraiter.portee === 'tout' ? 'on' : ''}" data-portee="tout">Tout le ${h(SERVICES[SERVICE_MOI])}</button>
              <button class="${vueATraiter.portee === 'moi' ? 'on' : ''}" data-portee="moi">Mes demandes</button></div>`
            : `<div class="frow">${[['', 'Tous'], ['SAV', 'SAV'], ['COMMERCIAL', 'Commerce'], ['FINANCE', 'Finance'], ['RH', 'RH'], ['AUTRE', 'À orienter']].map(([v, t]) =>
              `<button class="chip ${vueATraiter.service === v ? 'on' : ''}" data-svc="${v}">${t}</button>`).join('')}</div>`}
        </div>
        <div class="tiles">
          <button class="tile ${urgentes ? 'rouge' : ''}" data-aller="urgent"><b>${urgentes}</b><span>Urgence${urgentes > 1 ? 's' : ''} non prise${urgentes > 1 ? 's' : ''} en charge</span></button>
          <button class="tile orange" data-aller="a_traiter"><b>${aPrendre.length}</b><span>À traiter</span></button>
          <button class="tile bleu" data-aller="en_cours"><b>${enCours.length}</b><span>En cours</span></button>
          <button class="tile vert" data-aller="traite"><b>${traitees7}</b><span>Traitées ces 7 derniers jours</span></button>
        </div>
        <section class="section"><h2>À prendre en charge</h2>
          ${aPrendre.length ? `<div class="cards">${aPrendre.map(carte).join('')}</div>` : '<div class="vide">Rien à prendre en charge pour le moment.</div>'}</section>
        <section class="section"><h2>En cours</h2><p class="hint">Demandes prises en charge, pas encore traitées. Le prochain rappel prévu d'abord.</p>
          ${enCours.length ? `<div class="cards">${enCours.map(carte).join('')}</div>` : '<div class="vide">Aucune demande en cours.</div>'}</section>
        ${qualif ? `<p class="hint" style="margin-top:18px">${qualif} appel${qualif > 1 ? 's' : ''} encore en échange avec Claire sur WhatsApp : ${qualif > 1 ? 'ils arriveront' : 'il arrivera'} ici une fois validé${qualif > 1 ? 's' : ''}. <button class="link" data-aller="qualification">Voir</button></p>` : ''}`;
      $$('[data-portee]', main).forEach((b) => b.onclick = () => { vueATraiter.portee = b.dataset.portee; render(); });
      $$('[data-svc]', main).forEach((b) => b.onclick = () => { vueATraiter.service = b.dataset.svc; render(); });
      $$('[data-aller]', main).forEach((b) => b.onclick = () => {
        const v = b.dataset.aller;
        vueDemandes.f = {etat: v === 'urgent' ? 'a_traiter' : v};
        vueDemandes.f.priorite = v === 'urgent' ? 'URGENT' : '';
        if (!SERVICE_MOI && vueATraiter.service) vueDemandes.f.service = vueATraiter.service;
        if (v === 'traite') vueDemandes.f.periode = '7';
        show('demandes');
      });
      $$('[data-ouvrir]', main).forEach((el) => el.addEventListener('click', (e) => {
        if (e.target.closest('[data-prendre],[data-traiter]')) return;
        e.stopPropagation(); show('demande', el.dataset.ouvrir);
      }));
      $$('[data-prendre]', main).forEach((b) => b.onclick = async () => {
        b.disabled = true;
        const d = toutes.find((x) => !x.fiche && String(x.id) === b.dataset.prendre);
        Object.assign(d, await avancer(d, {statut: 'en_cours'}, 'Demande prise en charge — le client est prévenu'));
        render();
      });
      $$('[data-traiter]', main).forEach((b) => b.onclick = async () => {
        b.disabled = true;
        const d = toutes.find((x) => !x.fiche && String(x.id) === b.dataset.traiter);
        Object.assign(d, await avancer(d, {statut: 'traite'}, 'Demande traitée — le client est prévenu'));
        render();
      });
    };
    render();
  };

  // ================================================================== DEMANDES (vue globale)
  const vueDemandes = {f: {}, q: '', page: 0, sel: null};
  tabs.demandes = async () => {
    const toutes = await chargerDemandes();
    const PAGE_D = 50;
    const jours = (d) => (Date.now() - new Date(String(d.created_at).replace(' ', 'T')).getTime()) / 86400000;
    const noms = (d) => String(d.destinataires || '').split(/\s*,\s*/).filter(Boolean);
    const uniques = (fn) => [...new Set(toutes.flatMap(fn).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fr'));
    const FILTRES = {
      etat: (d, v) => etatDe(d) === v,
      canal: (d, v) => canalDe(d) === v,
      service: (d, v) => d.service === v,
      priorite: (d, v) => (d.priorite === 'URGENT') === (v === 'URGENT'),
      periode: (d, v) => jours(d) <= Number(v),
      transmise: (d, v) => noms(d).includes(v),
      pris: (d, v) => d.pris_par === v,
      marque: (d, v) => (d.marque_norm || '') === v,
      client: (d, v) => (d.type_client || '') === v,
    };
    const filtrer = (sauf) => toutes.filter((d) => Object.entries(vueDemandes.f).every(([k, v]) => !v || k === sauf || FILTRES[k](d, v))
      && (!vueDemandes.q || [d.id, d.societe, d.contact, d.tel, telFr(d.tel), d.email, d.resume, d.type_panne, d.besoin_commercial, d.destinataires, d.pris_par, d.commentaire]
        .join(' ').toLowerCase().includes(vueDemandes.q.toLowerCase())));
    const rang = (d) => (estUrgente(d) ? 0 : ({qualification: 1, a_traiter: 2, en_cours: 3}[etatDe(d)] ?? 4));
    const chips = (cle, items, avecNombre = true) => {
      const base = filtrer(cle);
      return items.map(([v, t, cls = '']) => `<button class="chip ${cls} ${(vueDemandes.f[cle] || '') === v ? 'on' : ''}" data-f="${cle}" data-v="${h(v)}">${t}${
        avecNombre ? ' · ' + base.filter((d) => !v || FILTRES[cle](d, v)).length : ''}</button>`).join('');
    };
    const select = (cle, label, options) => `<select data-f="${cle}" aria-label="${h(label)}"><option value="">${h(label)} : tous</option>${options.map(([v, t]) =>
      `<option value="${h(v)}" ${(vueDemandes.f[cle] || '') === v ? 'selected' : ''}>${h(t)}</option>`).join('')}</select>`;
    const qui = (d) => {
      if (d.fiche) return '<span class="qui">Claire pose ses questions</span>';
      if (d.statut === 'traite') return `<span class="qui vert">${h(d.traite_par || d.pris_par || '—')} · traitée</span>`;
      if (d.statut === 'en_cours') return `<span class="qui bleu">${h(d.pris_par || '—')}</span>`;
      return `<span class="qui ${estUrgente(d) ? 'rouge' : ''}">${h(d.destinataires || String(d.dest_to || '').replace(/;/g, ', ') || '—')}${estUrgente(d) ? ' · pas encore pris' : ''}</span>`;
    };
    const render = () => {
      const rows = filtrer().sort((a, b) => rang(a) - rang(b) || String(b.created_at).localeCompare(String(a.created_at)));
      const pages = Math.max(1, Math.ceil(rows.length / PAGE_D));
      vueDemandes.page = Math.min(vueDemandes.page, pages - 1);
      const tranche = rows.slice(vueDemandes.page * PAGE_D, (vueDemandes.page + 1) * PAGE_D);
      const nbFiltres = Object.values(vueDemandes.f).filter(Boolean).length + (vueDemandes.q ? 1 : 0);
      main.innerHTML = `
        <div class="page-head"><div class="t"><h1>Demandes</h1><p>${SERVICE_MOI ? 'Toutes les demandes du service ' + h(SERVICES[SERVICE_MOI]) + '.' : 'Toutes les demandes, tous canaux confondus.'}</p></div>
          <label class="search">${ic('search', 's')}<input type="search" id="dq" placeholder="Nom, société, téléphone, n° de demande…" aria-label="Rechercher" value="${h(vueDemandes.q)}"></label>
          ${ADMIN ? `<button class="btn" id="selMode">${vueDemandes.sel ? 'Fin de la sélection' : 'Sélectionner'}</button><a class="btn" href="api.php?r=export/rep_demandes" download>Exporter (CSV)</a>` : ''}</div>
        <div class="panel filtres">
          <div class="frow"><span class="lab">Suivi</span>${chips('etat', [['', 'Toutes'], ['qualification', 'Avec Claire', 'st-qualification'], ['a_traiter', 'À traiter', 'st-a_traiter'],
            ['en_cours', 'En cours', 'st-en_cours'], ['traite', 'Traitées', 'st-traite']])}</div>
          <div class="frow"><span class="lab">Canal</span>${chips('canal', [['', 'Tous'], ...Object.entries(CANAUX).map(([k, t]) => [k, ic(k, 's') + h(t)])])}</div>
          ${SERVICE_MOI ? '' : `<div class="frow"><span class="lab">Service</span>${chips('service', [['', 'Tous'], ['SAV', 'SAV'], ['COMMERCIAL', 'Commerce'], ['FINANCE', 'Finance'], ['RH', 'RH'], ['AUTRE', 'À orienter']])}</div>`}
          <div class="frow"><span class="lab">Affiner</span>
            ${select('periode', 'Période', [['1', 'Dernières 24 h'], ['7', '7 derniers jours'], ['30', '30 derniers jours'], ['90', '3 derniers mois']]).replace(': tous', ': tout')}
            ${select('priorite', 'Priorité', [['URGENT', 'Urgent'], ['NORMAL', 'Normal']]).replace(': tous', ': toutes')}
            ${select('transmise', 'Transmise à', uniques(noms).map((n) => [n, n]))}
            ${select('pris', 'Pris en charge par', uniques((d) => [d.pris_par]).map((n) => [n, n]))}
            ${select('marque', 'Marque', uniques((d) => [d.marque_norm]).map((m) => [m, nomDe(R_MARQUES, m)])).replace(': tous', ': toutes')}
            ${select('client', 'Client', uniques((d) => [d.type_client]).map((c) => [c, L(c)]))}
            ${nbFiltres ? '<button class="link" id="raz" style="margin-left:auto">Effacer les filtres</button>' : ''}</div>
        </div>
        ${vueDemandes.sel ? `<div class="panel" style="margin-top:16px;padding:12px 18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:var(--info-bg);border-color:#c5d5f5">
          <b>${vueDemandes.sel.size} sélectionnée${vueDemandes.sel.size > 1 ? 's' : ''}</b>
          <button class="btn small" id="selPage">Tout sélectionner sur cette page</button>
          <button class="btn small primary" id="selClore" ${vueDemandes.sel.size ? '' : 'disabled'}>Marquer traitées sans prévenir les clients</button>
          <span class="hint">Pour faire le ménage (anciennes demandes, doublons). Cliquez sur les lignes pour les cocher.</span></div>` : ''}
        <div class="panel dl" style="margin-top:16px">
          <div class="row head"><span>REÇUE</span><span>CANAL</span><span>SUIVI</span><span>SERVICE</span><span>CLIENT</span><span>DEMANDE</span><span>QUI S'EN OCCUPE</span></div>
          ${tranche.length ? tranche.map((d, i) => { const coche = vueDemandes.sel && !d.fiche && vueDemandes.sel.has(d.id); return `<div class="row ${estUrgente(d) ? 'urgent' : ''}" data-i="${i}" role="link" tabindex="0" style="${coche ? 'background:var(--info-bg)' : ''}">
            <span class="two">${vueDemandes.sel && !d.fiche ? `<b>${coche ? '☑' : '☐'} n° ${d.id}</b>` : `<b>${h(quand(d.created_at))}</b>`}<span>${h(rel(d.created_at))}</span></span>
            ${canalHtml(canalDe(d))}
            <span>${etatPill(d)}</span>
            <span>${svcPill(d.service)}</span>
            <span class="two"><b>${h(d.societe || d.contact || '—')}</b><span>${h([d.type_client ? L(d.type_client) : '', d.departement].filter(Boolean).join(' · '))}</span></span>
            <span class="txt">${h([materielDe(d), sujetDe(d)].filter(Boolean).join(' — ') || '—')}</span>
            ${qui(d)}
            <span class="m1"><b>${h(d.societe || d.contact || '—')}</b><span>${h(sujetDe(d))}</span><span>${ic(canalDe(d), 's')} ${h(CANAUX[canalDe(d)])} · ${h(rel(d.created_at))}</span></span>
            <span class="m2">${etatPill(d)}${svcPill(d.service)}</span>
          </div>`; }).join('') : '<div class="vide" style="border:0">Aucune demande ne correspond.</div>'}
          <div class="pied"><span>${rows.length} demande${rows.length > 1 ? 's' : ''}</span>${pages > 1 ? `<span>
            <button class="btn small" data-page="-1" ${vueDemandes.page === 0 ? 'disabled' : ''}>‹ Précédentes</button>
            Page ${vueDemandes.page + 1} / ${pages}
            <button class="btn small" data-page="1" ${vueDemandes.page >= pages - 1 ? 'disabled' : ''}>Suivantes ›</button></span>` : ''}</div>
        </div>`;
      const q = $('#dq', main);
      q.oninput = () => { vueDemandes.q = q.value; vueDemandes.page = 0; clearTimeout(q._t); q._t = setTimeout(() => { render(); const n = $('#dq', main); n.focus(); n.setSelectionRange(n.value.length, n.value.length); }, 200); };
      $$('button[data-f]', main).forEach((b) => b.onclick = () => { vueDemandes.f[b.dataset.f] = b.dataset.v; vueDemandes.page = 0; render(); });
      $$('select[data-f]', main).forEach((s) => s.onchange = () => { vueDemandes.f[s.dataset.f] = s.value; vueDemandes.page = 0; render(); });
      const raz = $('#raz', main); if (raz) raz.onclick = () => { vueDemandes.f = {}; vueDemandes.q = ''; vueDemandes.page = 0; render(); };
      $$('[data-page]', main).forEach((b) => b.onclick = () => { vueDemandes.page += Number(b.dataset.page); render(); window.scrollTo(0, 0); });
      const sm = $('#selMode', main); if (sm) sm.onclick = () => { vueDemandes.sel = vueDemandes.sel ? null : new Set(); render(); };
      const sp = $('#selPage', main); if (sp) sp.onclick = () => { tranche.filter((d) => !d.fiche && d.statut !== 'traite').forEach((d) => vueDemandes.sel.add(d.id)); render(); };
      const sc = $('#selClore', main); if (sc) sc.onclick = async () => {
        const ids = [...vueDemandes.sel];
        if (!confirm(`Marquer ${ids.length} demande${ids.length > 1 ? 's' : ''} comme traitée${ids.length > 1 ? 's' : ''} ? Les clients ne seront pas prévenus.`)) return;
        const r = await api('rep/demandes/cloturer', {method: 'POST', body: {ids}});
        toast(`${r.clotures} demande${r.clotures > 1 ? 's' : ''} clôturée${r.clotures > 1 ? 's' : ''}`); vueDemandes.sel = null; refreshBadges(); tabs.demandes();
      };
      $$('.dl .row[data-i]', main).forEach((r) => {
        const go = () => {
          const d = tranche[Number(r.dataset.i)];
          if (vueDemandes.sel && !d.fiche) { vueDemandes.sel.has(d.id) ? vueDemandes.sel.delete(d.id) : vueDemandes.sel.add(d.id); render(); return; }
          ouvrir(d);
        };
        r.onclick = go; r.onkeydown = (e) => { if (e.key === 'Enter') go(); };
      });
    };
    render();
  };

  // ================================================================== UNE DEMANDE
  const TYPES_HIST = {
    recue: ['Réception', 'inbox'], transmise: ['Transmission', 'envoi'], client_prevenu: ['Message au client', 'envoi'],
    prise_en_charge: ['Prise en charge', 'prendre'], rappel: ['Rappel', 'agenda'], note: ['Note interne', 'note'], traitee: ['Traitée', 'check'],
    rouverte: ['Rouverte', 'retour'], modifiee: ['Modification', 'note'], transferee: ['Transfert', 'envoi'], relance: ['Relance du client', 'retour'], message_client: ['Message du client', 'whatsapp'], reponse_claire: ['Réponse de Claire', 'chat'],
    piece: ['Pièce jointe', 'note'],
  };
  const FAMILLES_HIST = {tout: 'Tout', envois: 'Envois', equipe: 'Équipe', conversation: 'Conversation'};
  const familleHist = (t) => (['transmise', 'client_prevenu', 'recue', 'transferee', 'relance', 'rouverte'].includes(t) ? 'envois' : (['message_client', 'reponse_claire'].includes(t) ? 'conversation' : 'equipe'));
  const vueHist = {f: 'tout'};

  function historiqueHtml(ev) {
    const liste = ev.filter((e) => vueHist.f === 'tout' || familleHist(e.type) === vueHist.f);
    const envois = (d) => Object.values(d.envois || d.canaux || {}).map((x) => `<span class="pill">${ic(x.canal === 'SMS' ? 'sms' : (x.canal === 'WhatsApp' ? 'whatsapp' : 'email'), 's')}${h(x.canal)} → ${h(x.a)}${x.role === 'copie' ? ' (copie)' : ''}</span>`).join(' ');
    return `<div class="frow" style="margin-bottom:4px">${Object.entries(FAMILLES_HIST).map(([k, t]) => `<button class="chip ${vueHist.f === k ? 'on' : ''}" data-hist="${k}">${t}</button>`).join('')}</div>
      <ol class="tl">${liste.length ? liste.map((e) => {
        const d = e.detail || {};
        const [nom, icone] = TYPES_HIST[e.type] || [e.type, 'note'];
        const texte = d.texte ? `<div class="texte" style="margin-top:6px">${h(d.texte)}</div>` : '';
        const extra = [
          envois(d) ? `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">${envois(d)}</div>` : '',
          d.envoi ? `<small>Envoi : ${h(d.envoi)}</small>` : '',
          d.regle && ADMIN ? `<small>Règle : ${h(d.regle)}</small>` : '',
          (d.notes || []).length && ADMIN ? `<small>${h(d.notes.join(' · '))}</small>` : '',
        ].join('');
        return `<li class="fait"><span class="pt" style="background:${e.type === 'traitee' ? 'var(--ok)' : 'var(--primary-2)'};border-color:transparent">${ic(icone, 's')}</span>
          <span style="min-width:0;flex:1"><b style="color:var(--text)">${h(e.resume || nom)}</b>
          <small>${h(jourFr(e.date))}${e.qui ? ' · ' + h(e.qui) : ''}${e.via === 'lien_equipe' ? ' · depuis le lien reçu' : ''}${e.reconstitue ? ' · reconstitué' : ''}</small>
          ${extra}${texte}</span></li>`;
      }).join('') : '<li class="hint">Rien dans cette catégorie.</li>'}</ol>`;
  }

  // Transférer une demande à une personne, une boîte partagée ou un responsable de service.
  async function transferer(d) {
    const eq = await api('equipe');
    const boite = (c) => c.role === 'boite' || /bo[iî]te partag/i.test(c.commentaire || '');
    const joignable = (c) => c.joignable ?? !!c.email;
    const actifs = eq.rows.filter((c) => Number(c.actif ?? 1) && joignable(c));
    const resp = eq.responsables || {};
    const nomsResp = {sav: 'Responsable SAV', finance: 'Responsable compta / finance', commercial: 'Responsable commerce', autre: 'Sujets indéterminés', rh: 'Ressources humaines'};
    const opt = (c, lib) => `<option value="${c.id}">${h(lib || c.nom)}${c.fonction && !lib ? ' — ' + h(c.fonction) : ''}</option>`;
    const groupe = (t, liste) => liste.length ? `<optgroup label="${h(t)}">${liste.join('')}</optgroup>` : '';
    const parSvc = (s) => actifs.filter((c) => !boite(c) && c.service_equipe === s).sort((a, b) => a.nom.localeCompare(b.nom, 'fr')).map((c) => opt(c));
    const choix = `<select name="cible" class="inp"><option value="">— Choisir —</option>
      ${groupe('Responsables de service', Object.entries(nomsResp).map(([k, t]) => { const c = actifs.find((x) => Number(x.id) === Number(resp[k])); return c ? opt(c, t + ' (' + c.nom + ')') : ''; }).filter(Boolean))}
      ${groupe('Finance', parSvc('finance'))}${groupe('SAV', parSvc('sav'))}${groupe('Commerce', parSvc('commerce'))}${groupe('RH', parSvc('rh'))}${groupe('Direction', parSvc('direction'))}
      ${groupe('Boîtes partagées', actifs.filter(boite).map((c) => opt(c, c.nom + (c.competences ? ' — ' + c.competences : ''))))}</select>`;
    openDrawer('Transférer la demande n° ' + d.id, `
      <p class="hint" style="margin:0">La personne choisie reçoit la demande par e-mail (et par SMS pour le SAV), avec le lien pour la prendre en charge.
        La demande repasse « à traiter » chez elle. Le client n'est pas prévenu.</p>
      <label class="field"><span>Transférer à</span>${choix}</label>
      <label class="field"><span>… ou à une autre adresse e-mail</span><input type="email" name="email_libre" class="inp" placeholder="ex. rh@multiairfrance.fr" autocomplete="off"></label>
      <label id="transfClore" style="display:none;gap:6px;align-items:flex-start;font-size:13px"><input type="checkbox" name="cloturer" checked>Marquer la demande traitée ici : la suite se fait chez ce destinataire (le client n'est pas prévenu)</label>
      <label class="field"><span>Message (facultatif)</span><textarea name="message" class="inp" rows="3" placeholder="ex. Double règlement de facture, peux-tu regarder ?"></textarea></label>`,
      '<button class="btn primary" id="transfOk">Transférer</button>');
    const champLibre = $('#drawerBody [name=email_libre]');
    champLibre.oninput = () => {
      $('#transfClore').style.display = champLibre.value.trim() ? 'flex' : 'none';
      if (champLibre.value.trim()) $('#drawerBody [name=cible]').value = '';
    };
    $('#drawerBody [name=cible]').onchange = () => { if ($('#drawerBody [name=cible]').value) { champLibre.value = ''; $('#transfClore').style.display = 'none'; } };
    $('#transfOk').onclick = async () => {
      const cible = $('#drawerBody [name=cible]').value;
      const libre = champLibre.value.trim();
      if (!cible && !libre) return toast('Choisissez à qui transférer, ou saisissez une adresse e-mail', true);
      $('#transfOk').disabled = true;
      try {
        const message = $('#drawerBody [name=message]').value.trim();
        const body = libre ? {email: libre, cloturer: $('#drawerBody [name=cloturer]').checked ? 1 : 0, message} : {contact_id: Number(cible), message};
        const r = await api('rep/demandes/' + d.id + '/transferer', {method: 'POST', body});
        closeDrawer(); toast('Demande transférée — la personne est prévenue'); refreshBadges();
        if (r.visible) show('demande', d.id, false); else show('a-traiter');
      } catch (e) { toast(e.message, true); $('#transfOk').disabled = false; }
    };
  }

  const poids = (o) => (o >= 1048576 ? (o / 1048576).toFixed(1).replace('.', ',') + ' Mo' : Math.max(1, Math.round(o / 1024)) + ' Ko');
  const pieceHtml = (demId) => (p) => {
    const url = 'api.php?r=rep/demandes/' + demId + '/pieces/' + p.id;
    const legende = `<div class="pj-l"><a href="${url}" target="_blank" rel="noopener">${h(p.nom)}</a>
      <small>${h(poids(Number(p.taille) || 0))} · ${h(p.source === 'email' ? 'reçue avec l\'e-mail' : 'ajoutée' + (p.ajoute_par ? ' par ' + p.ajoute_par : ''))} · ${h(fmtDate(p.created_at))}</small>
      ${p.erreur ? `<small style="color:var(--danger)">Non enregistrée : ${h(p.erreur)} — voir l'e-mail d'origine</small>` : `<a href="${url}&telecharger=1" class="hint">Télécharger</a>`}
      ${moi.acces === 'admin' ? `<button class="btn small ghost" data-suppr-piece="${p.id}">Supprimer</button>` : ''}</div>`;
    if (p.erreur) return `<div class="pj">${legende}</div>`;
    if (String(p.type).startsWith('image/') && p.en_ligne) return `<div class="pj"><a href="${url}" target="_blank" rel="noopener"><img src="${url}" alt="${h(p.nom)}" loading="lazy"></a>${legende}</div>`;
    if (String(p.type).startsWith('video/')) return `<div class="pj"><video src="${url}" controls preload="metadata" playsinline></video>${legende}</div>`;
    if (String(p.type).startsWith('audio/')) return `<div class="pj"><audio src="${url}" controls preload="none"></audio>${legende}</div>`;
    return `<div class="pj">${legende}</div>`;
  };

  tabs.demande = async (id) => {
    const [r] = await Promise.all([api('rep/demandes/' + id), chargerListes().catch(() => {})]);
    const d = r.demande, ev = r.historique || [], pieces = r.pieces || [];
    const urgent = estUrgente(d);
    const canal = canalDe(d);
    const via = {whatsapp_qualifie: ', validée sur WhatsApp', sans_reponse_10min: ', sans réponse WhatsApp du client', vapi_direct: ''}[d.source] ?? '';
    const tl = [
      ['Demande reçue', jourFr(d.created_at) + ' · ' + (CANAUX[canal] || '').toLowerCase(), true],
      ['Transmise à ' + (d.destinataires || String(d.dest_to || '').replace(/;/g, ', ') || '—'), [d.dest_to ? 'e-mail' : '', d.dest_sms ? 'SMS' : ''].filter(Boolean).join(' + '), true],
      ['Prise en charge', d.pris_at ? jourFr(d.pris_at) + (d.pris_par ? ' · ' + d.pris_par : '') : 'en attente', !!d.pris_at],
      ...(d.rappel_prevu ? [['Rappel prévu', jourFr(d.rappel_prevu), d.statut === 'traite' || new Date(String(d.rappel_prevu).replace(' ', 'T')) <= new Date()]] : []),
      ['Traitée', d.traite_at ? jourFr(d.traite_at) + (d.traite_par ? ' · ' + d.traite_par : '') : '', d.statut === 'traite'],
    ];
    const champ = (k, v, large = false) => v ? `<div class="${large ? 'large' : ''}"><span>${h(k)}</span><b>${v}</b></div>` : '';
    const notes = String(d.commentaire || '').split('\n').filter((l) => l.trim());
    const actions = d.statut === 'traite'
      ? `<button class="btn big" data-act="rouvrir">${ic('retour')}Rouvrir la demande</button>`
      : `${d.statut === 'a_traiter' ? `<button class="btn big ${urgent ? 'urgent' : 'primary'}" data-act="prendre">${ic('prendre')}Je la prends en charge</button>` : ''}
         <button class="btn big" data-act="rappel">${ic('agenda')}${d.rappel_prevu ? 'Changer le rappel' : 'Planifier un rappel'}</button>
         <button class="btn big go" data-act="traiter">${ic('check')}Marquer traitée</button>
         <button class="btn big" data-act="transferer">${ic('envoi')}Transférer</button>`;
    main.innerHTML = `
      <div class="crumb"><a href="#demandes" data-retour>Demandes</a> › n° ${d.id}</div>
      <div class="dtitle"><h1>${h(d.societe || d.contact || 'Client')}${sujetDe(d) ? ' — ' + h(sujetDe(d)) : ''}</h1>
        <div class="tags">${etatPill(d)}${svcPill(d.service)}${d.priorite === 'URGENT' ? '<span class="pill danger">Production arrêtée</span>' : '<span class="pill">Priorité normale</span>'}
          <span class="pill">${ic(canal, 's')}Reçue ${h({telephone: 'par téléphone', whatsapp: 'sur WhatsApp', chat: 'par le chat du site', email: 'par e-mail'}[canal])}${h(via)}</span>
          <span class="hint">${h(jourFr(d.created_at))}</span></div></div>
      ${urgent ? '<div class="msg err" style="margin-bottom:16px"><b>Urgent — production arrêtée.</b> Personne n\'a encore pris cette demande en charge.</div>' : ''}
      <section class="actions" aria-label="Actions">${actions}
        <span class="note">Chaque action prévient le client ${['chat', 'email'].includes(canal) ? 'par e-mail' : 'par WhatsApp et e-mail'}.</span>
        <div class="rappel-form" id="rappelForm" hidden>
          <label for="rappelDate"><b>Rappel prévu le</b></label>
          <input type="datetime-local" id="rappelDate" value="${h(String(d.rappel_prevu || '').replace(' ', 'T').slice(0, 16))}">
          <button class="btn primary" id="rappelOk">Enregistrer et prévenir le client</button>
          <button class="btn ghost" id="rappelNon">Annuler</button>
        </div>
      </section>
      <div class="dgrid">
        <div class="dcol">
          <section class="box2"><h2>Client à rappeler</h2><div class="kvg">
            ${champ('Contact', h(d.contact || '—'))}
            ${champ('Société', h([d.societe, d.type_client ? L(d.type_client).toLowerCase() : ''].filter(Boolean).join(' · ') || '—'))}
            ${champ('Téléphone', d.tel ? `<a href="tel:+${h(d.tel)}" style="font-weight:800">${h(telFr(d.tel))}</a>` : '—')}
            ${champ('E-mail', d.email ? `<a href="mailto:${h(d.email)}">${h(d.email)}</a>` : '')}
            ${champ('Site', h([d.code_postal, d.departement && !d.code_postal ? 'dpt ' + d.departement : ''].filter(Boolean).join(' ')))}
            ${champ('Compte distributeur', h(d.compte_distributeur || ''))}
          </div>
          ${d.client ? `<a class="clientconnu" href="#client/${d.client.id}">${FIDELITE[d.client.fidelite] ? `<span class="pill ${FIDELITE[d.client.fidelite][1]}">${h(FIDELITE[d.client.fidelite][0])}</span>` : ''}
            ${d.client.nb_demandes > 1 ? `Client connu : ${d.client.nb_demandes} demandes depuis le ${h(fmtDate(d.client.premiere_demande, false))}` : 'Première demande de ce client'} · voir sa fiche →</a>
            ${d.client.notes ? `<p class="texte" style="margin:0">${h(d.client.notes)}</p>` : ''}` : ''}</section>
          <section class="box2"><h2>La demande</h2><div class="kvg">
            ${champ('Marque / modèle', h(materielDe(d)))}
            ${champ('N° de série', h(d.numero_serie || ''))}
            ${champ('Équipement', h(d.type_equipement ? L(d.type_equipement) : ''))}
            ${champ('Nature', h(d.nature ? nomDe(R_NATURES, d.nature) : ''))}
            ${champ('Réf. facture', h(d.reference_facture || ''))}
            ${champ('Production arrêtée', d.service === 'SAV' ? (d.priorite === 'URGENT' ? 'Oui' : 'Non') : '')}
            ${champ('Problème', h(d.type_panne || ''), true)}
            ${champ('Besoin', h(d.besoin_commercial || ''), true)}
            ${champ('Pourquoi urgent', h(d.priorite === 'URGENT' ? d.justification_urgence || '' : ''), true)}
          </div>${d.resume ? `<p class="texte">${h(d.resume)}</p>` : ''}</section>
          <section class="box2"><h2>Pièces jointes${pieces.length ? ' (' + pieces.length + ')' : ''}</h2>
            <div class="pieces">${pieces.length ? pieces.map(pieceHtml(d.id)).join('') : '<p class="hint" style="margin:0">Aucune pièce jointe. Les photos, vidéos et documents envoyés par le client avec son e-mail arrivent ici automatiquement.</p>'}</div>
            <label class="btn" style="align-self:flex-start;cursor:pointer">${ic('plus', 's')}Ajouter une pièce jointe<input type="file" id="pieceFichier" multiple hidden></label>
            <span class="hint" id="pieceEtat"></span></section>
          <section class="box2 hist-box"><h2>Historique complet</h2>
            <p class="hint" style="margin-top:-6px">Tout ce qui s'est passé : à qui la demande a été envoyée, quand, par quel canal, les messages envoyés au client et ses réponses.</p>
            <div id="hist">${historiqueHtml(ev)}</div></section>
        </div>
        <aside class="dcol">
          <section class="box2"><h2>Suivi</h2><ol class="tl">${tl.map(([t, s, fait]) => `<li class="${fait ? 'fait' : ''}"><span class="pt">${fait ? ic('check', 's') : ''}</span>
            <span><b>${h(t)}</b>${s ? `<small>${h(s)}</small>` : ''}</span></li>`).join('')}</ol></section>
          <section class="box2"><h2><label for="noteTxt">Note interne</label></h2>
            <div class="notes">${notes.map((l) => `<div>${h(l)}</div>`).join('')}
              <textarea id="noteTxt" placeholder="Visible uniquement par l'équipe Multiair"></textarea></div>
            <button class="btn primary" id="noteOk" style="align-self:flex-end">Ajouter la note</button></section>
          <section class="box2"><h2>Liens</h2>
            <a href="${h(d.liens.client)}" target="_blank" rel="noopener">${ic('oeil', 's')} Voir ce que voit le client</a>
            <div class="lienbox"><input readonly value="${h(d.liens.interne)}" aria-label="Lien de la demande pour l'équipe"><button class="btn small" data-copier>Copier</button></div>
            <span class="hint">Ce lien ouvre la demande sans mot de passe : il est réservé à l'équipe.</span></section>
          ${ADMIN ? `<section class="box2"><h2>Transmission</h2>${kv([['Règle', h(d.regle_libelle)], ['E-mail à', h(String(d.dest_to || '').replace(/;/g, ', '))],
            ['Copie', h(String(d.dest_cc || '').replace(/;/g, ', '))], ['SMS à', h(String(d.dest_sms || '').replace(/;/g, ', '))], ['Origine', h(L(d.source))]])}
            <button class="link danger" id="suppr" style="align-self:flex-start;color:var(--danger)">Supprimer cette demande</button></section>` : ''}
        </aside>
      </div>`;
    const recharger = () => show('demande', d.id, false);
    $('[data-retour]', main).onclick = (e) => { e.preventDefault(); history.length > 1 ? history.back() : show('demandes'); };
    const act = async (chg, msg) => { await avancer(d, chg, msg); recharger(); };
    $$('[data-act]', main).forEach((b) => b.onclick = async () => {
      const a = b.dataset.act;
      if (a === 'prendre') return act({statut: 'en_cours'}, 'Demande prise en charge — le client est prévenu');
      if (a === 'traiter') return act({statut: 'traite'}, 'Demande traitée — le client est prévenu');
      if (a === 'rouvrir') return act({statut: 'en_cours'}, 'Demande rouverte');
      if (a === 'rappel') { $('#rappelForm').hidden = false; $('#rappelDate').focus(); }
      if (a === 'transferer') transferer(d);
    });
    $('#rappelNon') && ($('#rappelNon').onclick = () => { $('#rappelForm').hidden = true; });
    $('#rappelOk') && ($('#rappelOk').onclick = async () => {
      const v = $('#rappelDate').value;
      if (!v) return toast('Choisissez une date et une heure', true);
      const chg = {rappel_prevu: v};
      if (d.statut === 'a_traiter') chg.statut = 'en_cours';
      await act(chg, 'Rappel enregistré — le client est prévenu');
    });
    const fichierPj = $('#pieceFichier');
    if (fichierPj) fichierPj.onchange = async () => {
      const fichiers = [...fichierPj.files];
      if (!fichiers.length) return;
      const etat = $('#pieceEtat');
      const erreurs = [];
      for (const [i, f] of fichiers.entries()) {
        etat.textContent = `Envoi de ${f.name} (${i + 1}/${fichiers.length}, ${poids(f.size)})…`;
        const fd = new FormData();
        fd.append('fichier', f, f.name);
        try {
          const rep = await fetch('api.php?r=rep/demandes/' + d.id + '/pieces&nom=' + encodeURIComponent(f.name), {method: 'POST', body: fd});
          const j = await rep.json().catch(() => ({ok: false, erreur: 'Réponse invalide'}));
          if (!j.ok) erreurs.push(f.name + ' : ' + (j.erreur || 'erreur'));
          else erreurs.push(...(j.erreurs || []));
        } catch (e) { erreurs.push(f.name + ' : ' + e.message); }
      }
      if (erreurs.length) toast(erreurs.join(' — '), true); else toast(fichiers.length > 1 ? 'Pièces jointes ajoutées' : 'Pièce jointe ajoutée');
      recharger();
    };
    $$('[data-suppr-piece]', main).forEach((b) => b.onclick = async () => {
      if (!confirm('Supprimer cette pièce jointe ?')) return;
      await api('rep/demandes/' + d.id + '/pieces/' + b.dataset.supprPiece, {method: 'DELETE'});
      toast('Pièce jointe supprimée'); recharger();
    });
    $('#noteOk').onclick = async () => {
      const t = $('#noteTxt').value.trim();
      if (!t) return;
      await patch('rep/demandes/' + d.id, {note: t});
      toast('Note ajoutée'); recharger();
    };
    $('[data-copier]', main).onclick = async (e) => {
      const i = e.target.previousElementSibling; i.select();
      try { await navigator.clipboard.writeText(i.value); toast('Lien copié'); } catch (x) { document.execCommand('copy'); toast('Lien copié'); }
    };
    const bindHist = () => $$('[data-hist]', main).forEach((b) => b.onclick = () => { vueHist.f = b.dataset.hist; $('#hist', main).innerHTML = historiqueHtml(ev); bindHist(); });
    bindHist();
    const s = $('#suppr', main);
    if (s) s.onclick = async () => { if (confirm('Supprimer définitivement la demande n° ' + d.id + ' ?')) { await api('rep/demandes/' + d.id, {method: 'DELETE'}); toast('Demande supprimée'); show('demandes'); } };
  };

  // Un appel encore en échange avec Claire : pas encore de demande, seulement la fiche et la conversation.
  tabs.appel = async (id) => {
    const [f, m] = await Promise.all([api('rep/fiches/' + id), api('rep/messages', {query: {fiche_id: id, dir: 'asc'}})]);
    const x = f.fiche;
    main.innerHTML = `
      <div class="crumb"><a href="#demandes" data-retour>Demandes</a> › appel du ${h(quand(x.created_at))}</div>
      <div class="dtitle"><h1>${h(x.societe || x.contact || x.tel_norm || 'Appel')}${x.type_panne || x.besoin_commercial ? ' — ' + h(x.type_panne || x.besoin_commercial) : ''}</h1>
        <div class="tags"><span class="st qualification">Avec Claire</span>${x.urgence ? '<span class="pill danger">Urgent</span>' : ''}<span class="pill">${ic('telephone', 's')}Reçu par téléphone</span>
        <span class="hint">${h(jourFr(x.created_at))}</span></div></div>
      <div class="msg" style="margin-bottom:20px">Claire échange avec le client sur WhatsApp pour valider les informations. La demande sera transmise dès qu'il aura répondu, ou au bout de 10 minutes sans réponse.</div>
      <div class="dgrid"><div class="dcol">
        <section class="box2"><h2>Ce que Claire a noté</h2><div class="kvg">
          <div><span>Contact</span><b>${h(x.contact || '—')}</b></div><div><span>Société</span><b>${h(x.societe || '—')}</b></div>
          <div><span>Téléphone</span><b>${x.tel_norm ? `<a href="tel:+${h(x.tel_norm)}">${h(telFr(x.tel_norm))}</a>` : '—'}</b></div>
          <div><span>Marque / modèle</span><b>${h([x.marque, x.modele].filter(Boolean).join(' ') || '—')}</b></div><div><span>Département</span><b>${h(x.departement || '—')}</b></div>
          <div><span>E-mail</span><b>${h(x.email || '—')}</b></div></div>${x.resume ? `<p class="texte">${h(x.resume)}</p>` : ''}</section>
      </div><aside class="dcol"><section class="box2"><h2>Conversation WhatsApp</h2>
        <div class="chat">${m.rows.length ? m.rows.map((e) => `${e.message ? `<div class="bubble u"><span class="t">Client · ${h(quand(e.date))}</span>${h(e.message)}</div>` : ''}${e.reponse ? `<div class="bubble a"><span class="t">Claire</span>${h(e.reponse)}</div>` : ''}`).join('') : '<span class="hint">Pas encore de réponse du client.</span>'}</div>
      </section></aside></div>`;
    $('[data-retour]', main).onclick = (e) => { e.preventDefault(); history.length > 1 ? history.back() : show('demandes'); };
  };

  // ================================================================== CLIENTS
  const FIDELITE = {vip: ['Client VIP', 'admin'], fidele: ['Client fidèle', 'ok'], recurrent: ['Revient', 'info'], nouveau: ['Nouveau', 'muted'],
    a_surveiller: ['À surveiller', 'danger'], distributeur: ['Distributeur', 'info']};
  const fidPill = (f) => FIDELITE[f] ? `<span class="pill ${FIDELITE[f][1]}">${h(FIDELITE[f][0])}</span>` : '';
  const vueClients = {f: '', q: '', tri: 'recent', page: 0};
  tabs.clients = async () => {
    const r = await api('clients');
    const tous = r.rows;
    const FILTRES = {
      '': () => true,
      fideles: (c) => ['fidele', 'vip'].includes(c.fidelite),
      reviennent: (c) => Number(c.nb_demandes) >= 2,
      nouveaux: (c) => Number(c.nb_demandes) === 1,
      distributeurs: (c) => c.type_client === 'distributeur' || c.type_interlocuteur === 'distributeur',
      directs: (c) => c.type_client !== 'distributeur' && c.type_interlocuteur !== 'distributeur',
      ouvertes: (c) => Number(c.nb_ouvertes) > 0,
    };
    const render = () => {
      let rows = tous.filter(FILTRES[vueClients.f] || FILTRES['']).filter((c) => !vueClients.q
        || [c.contact, c.societe, c.email, c.tel, telFr(c.tel), c.departement, c.code_postal, c.marque].join(' ').toLowerCase().includes(vueClients.q.toLowerCase()));
      rows = rows.sort(vueClients.tri === 'nombre' ? (a, b) => b.nb_demandes - a.nb_demandes || String(b.derniere_demande).localeCompare(String(a.derniere_demande))
        : (a, b) => String(b.derniere_demande).localeCompare(String(a.derniere_demande)));
      const pages = Math.max(1, Math.ceil(rows.length / 50));
      vueClients.page = Math.min(vueClients.page, pages - 1);
      const tranche = rows.slice(vueClients.page * 50, (vueClients.page + 1) * 50);
      const n = (f) => tous.filter(FILTRES[f]).length;
      main.innerHTML = `
        <div class="page-head"><div class="t"><h1>Clients</h1><p>Tous ceux qui nous ont contactés, reconnus par leur numéro ou leur e-mail. Claire les reconnaît quand ils rappellent.</p></div>
          <label class="search">${ic('search', 's')}<input type="search" id="cq" placeholder="Nom, société, téléphone, e-mail…" aria-label="Rechercher" value="${h(vueClients.q)}"></label></div>
        <div class="tiles">
          <button class="tile" data-cf=""><b>${tous.length}</b><span>Clients et contacts</span></button>
          <button class="tile bleu" data-cf="reviennent"><b>${n('reviennent')}</b><span>Nous ont contactés plusieurs fois</span></button>
          <button class="tile vert" data-cf="fideles"><b>${n('fideles')}</b><span>Clients fidèles (3 demandes ou plus)</span></button>
          <button class="tile orange" data-cf="distributeurs"><b>${n('distributeurs')}</b><span>Distributeurs</span></button>
        </div>
        <div class="panel filtres" style="margin-top:16px"><div class="frow"><span class="lab">Afficher</span>
          ${[['', 'Tous'], ['reviennent', 'Qui reviennent'], ['fideles', 'Fidèles'], ['nouveaux', 'Nouveaux'], ['distributeurs', 'Distributeurs'], ['directs', 'Clients directs'], ['ouvertes', 'Avec une demande ouverte']]
            .map(([v, t]) => `<button class="chip ${vueClients.f === v ? 'on' : ''}" data-cf="${v}">${t} · ${n(v)}</button>`).join('')}
          <select id="ctri" aria-label="Trier" style="margin-left:auto"><option value="recent" ${vueClients.tri === 'recent' ? 'selected' : ''}>Contact le plus récent</option>
            <option value="nombre" ${vueClients.tri === 'nombre' ? 'selected' : ''}>Le plus de demandes</option></select></div></div>
        <div class="panel dl cl" style="margin-top:16px">
          <div class="row head"><span>CLIENT</span><span>TYPE</span><span>DEMANDES</span><span>SERVICES</span><span>CANAUX</span><span>SITE · MATÉRIEL</span><span>DERNIER CONTACT</span></div>
          ${tranche.map((c, i) => `<div class="row" data-i="${i}" role="link" tabindex="0">
            <span class="two"><b>${h(c.societe || c.contact || telFr(c.tel) || c.email)}</b><span>${h([c.societe ? c.contact : '', telFr(c.tel) || c.email].filter(Boolean).join(' · '))}</span></span>
            <span>${fidPill(c.fidelite)}${c.type_client === 'distributeur' || c.type_interlocuteur === 'distributeur' ? ' <span class="pill">Distributeur</span>' : ''}</span>
            <span class="two"><b>${c.nb_demandes}</b><span>${Number(c.nb_ouvertes) ? c.nb_ouvertes + ' ouverte' + (c.nb_ouvertes > 1 ? 's' : '') : 'toutes traitées'}${Number(c.nb_urgences) ? ' · ' + c.nb_urgences + ' urgence' + (c.nb_urgences > 1 ? 's' : '') : ''}</span></span>
            <span class="txt">${h(String(c.services || '').split(',').filter(Boolean).map((x) => SERVICES[x] || x).join(', '))}</span>
            <span style="display:flex;gap:6px">${String(c.canaux || '').split(',').filter(Boolean).map((k) => `<span title="${h(CANAUX[k] || k)}">${ic(k, 's')}</span>`).join('')}</span>
            <span class="txt">${h([c.code_postal || c.departement, [c.marque, c.modele].filter(Boolean).join(' ')].filter(Boolean).join(' · ') || '—')}</span>
            <span class="two"><b>${h(quand(c.derniere_demande))}</b><span>${h(rel(c.derniere_demande))}</span></span>
            <span class="m1"><b>${h(c.societe || c.contact || telFr(c.tel))}</b><span>${c.nb_demandes} demande${c.nb_demandes > 1 ? 's' : ''} · ${h(rel(c.derniere_demande))}</span></span>
            <span class="m2">${fidPill(c.fidelite)}</span>
          </div>`).join('') || '<div class="vide" style="border:0">Aucun client ne correspond.</div>'}
          <div class="pied"><span>${rows.length} client${rows.length > 1 ? 's' : ''}</span>${pages > 1 ? `<span>
            <button class="btn small" data-cpage="-1" ${vueClients.page === 0 ? 'disabled' : ''}>‹ Précédents</button> Page ${vueClients.page + 1} / ${pages}
            <button class="btn small" data-cpage="1" ${vueClients.page >= pages - 1 ? 'disabled' : ''}>Suivants ›</button></span>` : ''}</div>
        </div>`;
      const q = $('#cq', main);
      q.oninput = () => { vueClients.q = q.value; vueClients.page = 0; clearTimeout(q._t); q._t = setTimeout(() => { render(); const x = $('#cq', main); x.focus(); x.setSelectionRange(x.value.length, x.value.length); }, 200); };
      $$('[data-cf]', main).forEach((b) => b.onclick = () => { vueClients.f = b.dataset.cf; vueClients.page = 0; render(); });
      $('#ctri', main).onchange = (e) => { vueClients.tri = e.target.value; render(); };
      $$('[data-cpage]', main).forEach((b) => b.onclick = () => { vueClients.page += Number(b.dataset.cpage); render(); window.scrollTo(0, 0); });
      $$('.cl .row[data-i]', main).forEach((el) => { const go = () => show('client', tranche[Number(el.dataset.i)].id); el.onclick = go; el.onkeydown = (e) => { if (e.key === 'Enter') go(); }; });
    };
    render();
  };

  tabs.client = async (id) => {
    const [r] = await Promise.all([api('clients/' + id), chargerListes().catch(() => {})]);
    const c = r.client;
    const distri = c.type_client === 'distributeur' || c.type_interlocuteur === 'distributeur';
    const champ = (k, v) => v ? `<div><span>${h(k)}</span><b>${v}</b></div>` : '';
    main.innerHTML = `
      <div class="crumb"><a href="#clients" data-retour>Clients</a> › ${h(c.societe || c.contact || '')}</div>
      <div class="dtitle"><h1>${h([c.contact, c.societe].filter(Boolean).join(' — ') || telFr(c.tel) || c.email)}</h1>
        <div class="tags">${fidPill(c.fidelite)}${distri ? '<span class="pill">Distributeur</span>' : '<span class="pill">Client direct</span>'}
          <span class="hint">Premier contact ${h(jourFr(c.premiere_demande))}</span></div></div>
      <div class="tiles">
        <div class="tile"><b>${c.nb_demandes}</b><span>Demande${c.nb_demandes > 1 ? 's' : ''}</span></div>
        <div class="tile ${c.demandes.some((x) => x.statut !== 'traite') ? 'orange' : 'vert'}"><b>${c.demandes.filter((x) => x.statut !== 'traite').length}</b><span>En cours ou à traiter</span></div>
        <div class="tile ${Number(c.nb_urgences) ? 'rouge' : ''}"><b>${c.nb_urgences}</b><span>Urgence${c.nb_urgences > 1 ? 's' : ''}</span></div>
        <div class="tile bleu"><b>${h(rel(c.derniere_demande))}</b><span>Dernier contact</span></div>
      </div>
      <div class="dgrid" style="margin-top:20px"><div class="dcol">
        <section class="box2"><div class="frow" style="justify-content:space-between"><h2 style="margin:0;font-size:16px">Coordonnées</h2>
          ${ADMIN ? '<button class="btn small" id="clCorr">Corriger</button>' : ''}</div><div class="kvg">
          ${champ('Contact', h(c.contact || ''))}${champ('Société', h(c.societe || ''))}
          ${champ('Type', distri ? 'Distributeur' : 'Client direct')}
          ${champ('Téléphone', c.tel ? `<a href="tel:+${h(c.tel)}" style="font-weight:800">${h(telFr(c.tel))}</a>` : '')}
          ${champ('E-mail', c.email ? `<a href="mailto:${h(c.email)}">${h(c.email)}</a>` : '')}
          ${champ('Site', h([c.code_postal, c.departement && !c.code_postal ? 'dpt ' + c.departement : ''].filter(Boolean).join(' ')))}
          ${champ('Compte distributeur', h(c.compte_distributeur || c.reconnaissance?.compte_distributeur || ''))}
        </div>${c.champs_corriges ? `<span class="hint">Corrigé à la main (les prochaines demandes ne l'écraseront pas) : ${h(c.champs_corriges.split(',').map((k) => ({contact: 'contact', societe: 'société', tel: 'téléphone', email: 'e-mail', type_client: 'type', type_interlocuteur: 'type', code_postal: 'site', departement: 'site', compte_distributeur: 'compte'})[k] || k).filter((x, i, a) => a.indexOf(x) === i).join(', '))}.</span>` : ''}</section>
        <section class="box2"><div class="frow" style="justify-content:space-between"><h2 style="margin:0;font-size:16px">Parc machines (${c.equipements.filter((e) => Number(e.actif)).length})</h2>
          <button class="btn small" id="eqAjout">${ic('plus', 's')}Ajouter une machine</button></div>
          <p class="hint" style="margin:0">Chaque machine citée dans une demande s'ajoute ici toute seule, sans remplacer les autres. Corrigez ou retirez-la si besoin.</p>
          ${c.equipements.length ? `<div class="panel dl" style="border:0">${c.equipements.map((e) => `<div class="row" data-eq="${e.id}" role="button" tabindex="0" style="grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) 110px 120px;${Number(e.actif) ? '' : 'opacity:.55'}">
            <span class="two"><b>${h(e.modele || e.marque || 'Machine')}</b><span>${h([e.modele ? e.marque : '', e.type_equipement ? L(e.type_equipement) : '', e.commentaire].filter(Boolean).join(' · '))}</span></span>
            <span class="txt">${e.numero_serie ? 'n° ' + h(e.numero_serie) : '<span class="hint">n° de série inconnu</span>'}</span>
            <span class="txt">${h(e.code_postal || (e.departement ? 'dpt ' + e.departement : '—'))}</span>
            <span class="two"><b>${Number(e.nb_demandes)} demande${Number(e.nb_demandes) > 1 ? 's' : ''}</b><span>${Number(e.actif) ? (e.derniere_demande ? h(rel(e.derniere_demande)) : 'ajoutée à la main') : 'plus en service'}</span></span>
            <span class="m1"><b>${h([e.marque, e.modele].filter(Boolean).join(' ') || 'Machine')}</b><span>${h([e.numero_serie ? 'n° ' + e.numero_serie : '', e.code_postal].filter(Boolean).join(' · '))}</span></span><span class="m2"></span>
          </div>`).join('')}</div>` : '<div class="vide" style="border:0;padding:14px">Aucune machine connue pour l\'instant.</div>'}
        </section>
        <section class="box2"><h2>Ses demandes (${c.demandes.length})</h2>
          <div class="panel dl" style="border:0">${c.demandes.map((d, i) => `<div class="row" data-d="${d.id}" role="link" tabindex="0" style="grid-template-columns:110px 130px 120px minmax(0,1fr)">
            <span class="two"><b>n° ${d.id}</b><span>${h(fmtDate(d.created_at, false))}</span></span>${canalHtml(canalDe(d))}<span>${etatPill(d)}</span>
            <span class="txt">${h([SERVICES[d.service], sujetDe(d)].filter(Boolean).join(' — '))}</span>
            <span class="m1"><b>n° ${d.id} — ${h(sujetDe(d))}</b><span>${h(fmtDate(d.created_at, false))}</span></span><span class="m2">${etatPill(d)}</span></div>`).join('') || '<div class="vide" style="border:0">Aucune demande visible pour votre service.</div>'}</div></section>
        ${ADMIN && c.devis && c.devis.length ? `<section class="box2"><h2>Devis CSO (${c.devis.length})</h2>${c.devis.map((x) => `<div class="frow" style="justify-content:space-between;border-top:1px solid #eef1f4;padding-top:8px">
          <span><b>Offre ${h(x.n_offre)}</b> <span class="hint">${h(fmtDate(x.date_offre, false))}${x.commercial ? ' · ' + h(x.commercial) : ''}</span></span>
          <span>${eur(x.montant_ht)} HT ${pill(L(x.statut), cls(x.statut))}</span></div>`).join('')}</section>` : ''}
      </div><aside class="dcol">
        <section class="box2"><h2>Ce que Claire sait quand il appelle</h2>
          ${c.reconnaissance?.accueil ? `<p class="texte" style="margin:0"><b>Accueil : « ${h(c.reconnaissance.accueil)} »</b></p>` : ''}
          <p class="texte" style="margin:0">${h(c.reconnaissance?.contexte || '—')}</p>
          <span class="hint">Claire l'accueille par son nom et fait confirmer ces informations au lieu de les redemander.${c.civilite ? '' : ' Indiquez sa civilité (bouton Corriger) pour « Bonjour Monsieur … ».'}</span></section>
        <section class="box2"><h2>Relation client</h2>
          ${ADMIN ? `<label class="field"><span>Statut</span>${sel('statut_client', [['', 'Automatique (' + (FIDELITE[c.fidelite]?.[0] || '') + ')'], ['fidele', 'Client fidèle'], ['vip', 'Client VIP'], ['a_surveiller', 'À surveiller']], c.statut_client || '')}</label>
            <label class="field"><span>Note pour l'équipe et pour Claire</span><textarea name="notes" class="inp" rows="4" placeholder="ex. Client historique, parc de 3 compresseurs Worthington. Préfère être rappelé le matin.">${h(c.notes || '')}</textarea></label>
            <button class="btn primary" id="clSave" style="align-self:flex-end">Enregistrer</button>` : `<p class="texte" style="margin:0">${h(c.notes || 'Aucune note.')}</p>`}
        </section>
        ${ADMIN ? `<section class="box2"><h2>Doublon ?</h2><p class="hint" style="margin:0">Si cette personne a une autre fiche (autre numéro, autre e-mail), réunissez-les : les demandes et les machines passent sur cette fiche.</p>
          <button class="btn" id="clFus">Fusionner avec une autre fiche</button></section>` : ''}
      </aside></div>`;
    $('[data-retour]', main).onclick = (e) => { e.preventDefault(); history.length > 1 ? history.back() : show('clients'); };
    $$('[data-d]', main).forEach((el) => { const go = () => show('demande', el.dataset.d); el.onclick = go; el.onkeydown = (e) => { if (e.key === 'Enter') go(); }; });
    const b = $('#clSave', main);
    if (b) b.onclick = async () => {
      await patch('clients/' + c.id, {statut_client: $('[name=statut_client]', main).value, notes: $('[name=notes]', main).value.trim()});
      toast('Fiche client enregistrée'); show('client', c.id, false);
    };
    const recharger = () => show('client', c.id, false);
    // Corriger les coordonnées
    const corr = $('#clCorr', main);
    if (corr) corr.onclick = () => {
      openDrawer('Corriger la fiche client', [
        ligne('Civilité', sel('civilite', [['', 'Non renseignée'], ['M', 'Monsieur'], ['Mme', 'Madame']], c.civilite || ''), 'Claire dira « Bonjour Monsieur Mortier ». Sans civilité : « Bonjour Cyril Mortier ».'),
        ligne('Contact (prénom et nom)', `<input name="contact" value="${h(c.contact)}">`),
        ligne('Société', `<input name="societe" value="${h(c.societe)}">`),
        ligne('Type', sel('type_client', [['direct', 'Client direct'], ['distributeur', 'Distributeur']], distri ? 'distributeur' : 'direct')),
        ligne('Téléphone', `<input name="tel" value="${h(telFr(c.tel))}">`, 'C\'est à ce numéro que Claire le reconnaît.'),
        ligne('E-mail', `<input name="email" type="email" value="${h(c.email)}">`),
        ligne('Code postal du site principal', `<input name="code_postal" value="${h(c.code_postal)}" inputmode="numeric">`),
        ligne('Compte distributeur', `<input name="compte_distributeur" value="${h(c.compte_distributeur)}">`),
        '<p class="hint" style="margin:0">Vos corrections ne seront plus écrasées par les demandes suivantes.</p>',
      ].join(''), saveBtn());
      $('#drawerSave').onclick = async () => {
        const body = {};
        ['contact', 'societe', 'tel', 'email', 'code_postal', 'compte_distributeur'].forEach((k) => { body[k] = $(`#drawerBody [name="${k}"]`).value.trim(); });
        body.type_client = $('#drawerBody [name="type_client"]').value;
        body.civilite = $('#drawerBody [name="civilite"]').value;
        body.type_interlocuteur = body.type_client === 'distributeur' ? 'distributeur' : (c.type_interlocuteur === 'distributeur' ? '' : c.type_interlocuteur);
        try { await patch('clients/' + c.id, body); toast('Fiche corrigée'); closeDrawer(); recharger(); } catch (e) { toast(e.message, true); }
      };
    };
    // Parc machines
    const machine = (e) => {
      openDrawer(e.id ? 'Machine — ' + h([e.marque, e.modele].filter(Boolean).join(' ') || 'sans nom') : 'Ajouter une machine', [
        ligne('Marque', `<input name="marque" value="${h(e.marque)}" list="dlMarques"><datalist id="dlMarques">${R_MARQUES.filter(([k]) => k !== 'autre').map(([, t]) => `<option value="${h(t)}">`).join('')}</datalist>`),
        ligne('Modèle', `<input name="modele" value="${h(e.modele)}" placeholder="ex. ROLLAIR 220">`),
        ligne('N° de série', `<input name="numero_serie" value="${h(e.numero_serie)}">`),
        ligne('Type', `<input name="type_equipement" value="${h(e.type_equipement)}" placeholder="compresseur à vis, sécheur, compresseur à piston…">`),
        ligne('Code postal du site', `<input name="code_postal" value="${h(e.code_postal)}" inputmode="numeric">`),
        ligne('Commentaire', `<input name="commentaire" value="${h(e.commentaire)}" placeholder="ex. atelier 2, contrat d'entretien">`),
        ligne('En service', sel('actif', [['1', 'Oui'], ['0', 'Non (vendue, remplacée…)']], String(e.actif ?? 1))),
      ].join(''), (e.id ? delBtn() : '') + saveBtn());
      $('#drawerSave').onclick = async () => {
        const body = {};
        ['marque', 'modele', 'numero_serie', 'type_equipement', 'code_postal', 'commentaire', 'actif'].forEach((k) => { body[k] = $(`#drawerBody [name="${k}"]`).value.trim(); });
        try { await api('clients/' + c.id + '/equipements' + (e.id ? '/' + e.id : ''), {method: e.id ? 'PATCH' : 'POST', body}); toast('Parc mis à jour'); closeDrawer(); recharger(); }
        catch (x) { toast(x.message, true); }
      };
      if (e.id) $('#drawerDelete').onclick = async () => { if (confirm('Retirer cette machine du parc ?')) { await api('clients/' + c.id + '/equipements/' + e.id, {method: 'DELETE'}); closeDrawer(); recharger(); } };
    };
    $('#eqAjout', main).onclick = () => machine({actif: 1, code_postal: c.code_postal});
    $$('[data-eq]', main).forEach((el) => { const go = () => machine(c.equipements.find((x) => String(x.id) === el.dataset.eq)); el.onclick = go; el.onkeydown = (ev) => { if (ev.key === 'Enter') go(); }; });
    // Fusionner deux fiches
    const fus = $('#clFus', main);
    if (fus) fus.onclick = async () => {
      const autres = (await api('clients')).rows.filter((x) => Number(x.id) !== Number(c.id));
      openDrawer('Fusionner avec une autre fiche', `<p class="hint" style="margin:0">Cherchez l'autre fiche de ${h(c.contact || c.societe || 'ce client')}. Elle sera réunie à celle-ci.</p>
        <label class="search" style="width:100%">${ic('search', 's')}<input type="search" id="fq" placeholder="Nom, société, téléphone…" aria-label="Rechercher"></label><div id="fres"></div>`);
      const lister = () => {
        const q = $('#fq').value.toLowerCase();
        const r = autres.filter((x) => !q || [x.contact, x.societe, x.email, x.tel, telFr(x.tel)].join(' ').toLowerCase().includes(q)).slice(0, 15);
        $('#fres').innerHTML = r.map((x) => `<button class="btn block" style="justify-content:space-between;margin-top:6px" data-fus="${x.id}"><span>${h([x.contact, x.societe].filter(Boolean).join(' — ') || telFr(x.tel))}</span>
          <span class="hint">${h(telFr(x.tel) || x.email || '')} · ${x.nb_demandes} dem.</span></button>`).join('') || '<p class="hint">Aucune fiche.</p>';
        $$('[data-fus]').forEach((bt) => bt.onclick = async () => {
          const x = autres.find((y) => String(y.id) === bt.dataset.fus);
          if (!confirm(`Réunir la fiche « ${[x.contact, x.societe].filter(Boolean).join(' — ')} » dans celle-ci ? Ses ${x.nb_demandes} demande(s) et ses machines passent sur cette fiche.`)) return;
          await api('clients/' + c.id + '/fusionner', {method: 'POST', body: {avec: x.id}}); toast('Fiches réunies'); closeDrawer(); recharger();
        });
      };
      $('#fq').oninput = lister; lister(); $('#fq').focus();
    };
  };

  // ================================================================== STATISTIQUES
  tabs.stats = async () => {
    const toutes = (await chargerDemandes()).filter((d) => !d.fiche);
    const t = (s) => new Date(String(s).replace(' ', 'T')).getTime();
    const d30 = Date.now() - 30 * 86400000;
    const recentes = toutes.filter((d) => t(d.created_at) >= d30);
    const delais = recentes.filter((d) => d.pris_at).map((d) => (t(d.pris_at) - t(d.created_at)) / 60000).filter((x) => x >= 0).sort((a, b) => a - b);
    const mediane = delais.length ? delais[Math.floor(delais.length / 2)] : null;
    const duree = (min) => min === null ? '—' : (min < 60 ? Math.round(min) + ' min' : (min < 1440 ? (Math.round(min / 6) / 10).toString().replace('.', ',') + ' h' : Math.round(min / 1440) + ' j'));
    const traitees = recentes.filter((d) => d.statut === 'traite').length;
    const grouper = (fn, vides = true) => Object.entries(recentes.reduce((m, d) => { const k = fn(d) || (vides ? '(non renseigné)' : ''); if (k) m[k] = (m[k] || 0) + 1; return m; }, {}))
      .map(([k, n]) => ({k, n})).sort((a, b) => b.n - a.n).slice(0, 10);
    const jours = [...Array(30)].map((_, i) => { const x = new Date(Date.now() - (29 - i) * 86400000); return x.toISOString().slice(0, 10); });
    main.innerHTML = `
      <div class="page-head"><div class="t"><h1>Statistiques</h1><p>Les 30 derniers jours${SERVICE_MOI ? ', service ' + h(SERVICES[SERVICE_MOI]) : ''}.</p></div></div>
      <div class="tiles">
        <div class="tile"><b>${recentes.length}</b><span>Demandes reçues</span></div>
        <div class="tile bleu"><b>${duree(mediane)}</b><span>Délai habituel avant prise en charge</span></div>
        <div class="tile vert"><b>${recentes.length ? Math.round(100 * traitees / recentes.length) : 0} %</b><span>Déjà traitées</span></div>
        <div class="tile ${recentes.some((d) => d.priorite === 'URGENT') ? 'rouge' : ''}"><b>${recentes.filter((d) => d.priorite === 'URGENT').length}</b><span>Urgences (production arrêtée)</span></div>
      </div>
      <div class="grid2" style="margin-top:20px">
        <div class="card"><h3>Demandes par jour et par canal</h3><div class="chart-wrap"><canvas id="c_jours"></canvas></div></div>
        <div class="grid3" style="grid-template-columns:1fr">${distCard('Par canal', grouper((d) => CANAUX[canalDe(d)]))}${SERVICE_MOI ? '' : distCard('Par service', grouper((d) => SERVICES[d.service]))}</div>
      </div>
      <div class="grid3" style="margin-top:16px">${distCard('Qui nous contacte le plus', grouper((d) => d.client_id ? (d.societe || d.contact || telFr(d.tel)) : '', false))}${distCard('Prises en charge par', grouper((d) => d.pris_par, false))}${distCard('Par marque', grouper((d) => d.marque_norm ? nomDe(R_MARQUES, d.marque_norm) : ''))}${distCard('Type de client', grouper((d) => d.type_client ? L(d.type_client) : ''))}</div>`;
    const couleurs = {telephone: '#0f2f52', chat: '#1d4ed8', whatsapp: '#1f6b47', email: '#e8720c'};
    chart('c_jours', {type: 'bar', data: {labels: jours.map((j) => j.slice(8, 10) + '/' + j.slice(5, 7)),
      datasets: Object.entries(CANAUX).map(([k, l]) => ({label: l, backgroundColor: couleurs[k], borderRadius: 3,
        data: jours.map((j) => recentes.filter((d) => canalDe(d) === k && String(d.created_at).slice(0, 10) === j).length)}))},
      options: {responsive: true, maintainAspectRatio: false, plugins: {legend: {position: 'top', labels: {boxWidth: 10}}},
        scales: {x: {stacked: true, grid: {display: false}}, y: {stacked: true, beginAtZero: true, ticks: {precision: 0}}}}});
  };

  // ================================================================== ÉQUIPE ET ACCÈS
  const ROLES_ROUTAGE = [['membre', 'Membre (reçoit via les responsables)'], ['direction', 'Direction'], ['backoffice', 'Back-office SAV'],
    ['rso', 'RSO (secteur)'], ['cta', 'CTA ABAC (agent externe)'], ['finance', 'Finance (reçoit toute la finance)'], ['direct_projet', 'Direct / Projet'], ['boite', 'Boîte partagée']];
  const vueEquipe = {q: '', service: ''};
  tabs.equipe = async () => {
    const r = await api('equipe');
    const estBoite = (c) => c.role === 'boite' || /bo[iî]te partag/i.test(c.commentaire || '');
    const gens = r.rows.filter((c) => !estBoite(c));
    const boites = r.rows.filter(estBoite);
    const tel = (t) => { const x = String(t || '').replace(/\D/g, '').replace(/^33/, '0'); return /^0\d{9}$/.test(x) ? x.replace(/(\d{2})(?=\d)/g, '$1 ') : (t || ''); };
    const parId = (id) => r.rows.find((c) => Number(c.id) === Number(id));
    const acces = (c) => c.acces === 'admin' ? '<span class="pill admin">Admin</span>' : (c.acces ? `<span class="pill info">${h(MA.acces[c.acces] || c.acces)}</span>` : '<span class="pill muted">Aucun</span>');
    const connexion = (c) => !c.acces ? '<span class="hint">—</span>' : (c.a_mot_de_passe ? `<span class="hint">${c.derniere_connexion ? 'Vu ' + h(rel(c.derniere_connexion)) : 'Mot de passe choisi'}</span>`
      : (c.invitation_en_cours ? '<span class="pill warn">Invitation envoyée</span>' : '<span class="pill warn">À inviter</span>'));
    const RESP = [['sav', 'SAV'], ['finance', 'Compta / finance'], ['commercial', 'Commerce'], ['autre', 'Sujet indéterminé'], ['rh', 'RH (candidatures)']];
    const render = () => {
      const liste = gens.filter((c) => (!vueEquipe.service || c.service_equipe === vueEquipe.service)
        && (!vueEquipe.q || [c.nom, c.email, c.fonction, c.departements].join(' ').toLowerCase().includes(vueEquipe.q.toLowerCase())));
      main.innerHTML = `
        <div class="page-head"><div class="t"><h1>Équipe et accès</h1><p>Qui fait partie de l'équipe, comment le joindre, et ce qu'il voit sur la plateforme.</p></div>
          <button class="btn primary" id="ajout">${ic('plus', 's')}Ajouter une personne</button></div>
        <section class="section" style="margin-top:0"><h2>Responsables de service</h2>
          <p class="hint">Ils reçoivent les demandes que la plateforme ne sait attribuer à personne d'autre.</p>
          <div class="resp">${RESP.map(([k, t]) => { const c = parId(r.responsables[k]); return `<div class="box2"><span class="lab">${h(t.toUpperCase())}</span>
            <span class="nom">${h(c ? c.nom : 'Personne')}</span><span class="coord">${h(c ? c.email || '' : '')}${c && c.mobile ? '<br>' + h(tel(c.mobile)) : ''}</span>
            <label class="sr" for="resp_${k}">Changer le responsable ${h(t)}</label>
            <select id="resp_${k}" data-resp="${k}">${gens.filter((g) => g.email && Number(g.actif)).map((g) => `<option value="${g.id}" ${Number(g.id) === Number(r.responsables[k]) ? 'selected' : ''}>${h(g.nom)}</option>`).join('')}</select></div>`; }).join('')}</div></section>
        <section class="section"><h2>Toute l'équipe</h2>
          <div class="frow"><label class="search">${ic('search', 's')}<input type="search" id="eq_q" placeholder="Nom, e-mail, fonction…" aria-label="Rechercher" value="${h(vueEquipe.q)}"></label>
            ${[['', 'Tous'], ['direction', 'Direction'], ['sav', 'SAV'], ['commerce', 'Commerce'], ['finance', 'Finance'], ['rh', 'RH']].map(([v, t]) =>
              `<button class="chip ${vueEquipe.service === v ? 'on' : ''}" data-eqs="${v}">${t}</button>`).join('')}</div>
          <div class="panel dl eq">
            <div class="row head"><span>NOM</span><span>FONCTION</span><span>E-MAIL</span><span>PORTABLE</span><span>DÉPARTEMENTS</span><span>ACCÈS</span><span>CONNEXION</span></div>
            ${liste.map((c) => `<div class="row" data-c="${c.id}" role="link" tabindex="0" style="${Number(c.actif) ? '' : 'opacity:.55'}">
              <span class="two"><b>${h(c.nom)}</b><span>${h(({sav: 'SAV', commerce: 'Commerce', finance: 'Finance', rh: 'RH', direction: 'Direction'})[c.service_equipe] || '')}${Number(c.actif) ? '' : ' · inactif'}</span></span>
              <span class="txt">${h(c.fonction || nomDe(ROLES_ROUTAGE, c.role))}</span><span class="txt">${h(c.email || '—')}</span><span class="txt">${h(tel(c.mobile) || '—')}</span>
              <span class="txt">${h(c.departements ? c.departements.split(/[,;]/).length + ' dpts · ' + c.departements.split(/[,;]/).slice(0, 3).join(',') + '…' : '—')}</span>
              <span>${acces(c)}</span><span>${connexion(c)}</span>
              <span class="m1"><b>${h(c.nom)}</b><span>${h(c.fonction || '')}</span><span>${h(c.email || '')}</span></span><span class="m2">${acces(c)}${connexion(c)}</span>
            </div>`).join('') || '<div class="vide" style="border:0">Personne ne correspond.</div>'}
          </div>
          <p class="hint">Admin : voit tout et modifie les réglages. Service : voit toutes les demandes de son service et fait avancer leur suivi. Les portables ne sont jamais montrés aux clients.</p></section>
        <section class="section"><h2>Boîtes partagées</h2><p class="hint">Adresses de service qui reçoivent les demandes commerce (qui reçoit quoi : menu « Qui reçoit quoi »). Elles n'ont pas d'accès.</p>
          <div class="panel dl eq"><div class="row head"><span>BOÎTE</span><span>ACTIVITÉ</span><span>E-MAIL</span><span></span><span>LUE PAR</span><span></span><span></span></div>
          ${boites.map((c) => `<div class="row" data-c="${c.id}" role="link" tabindex="0"><span class="two"><b>${h(c.nom)}</b></span><span class="txt">${h(c.competences || '')}</span>
            <span class="txt">${h(c.email || '')}</span><span></span><span class="txt">${h(c.membres || '')}</span><span></span><span></span>
            <span class="m1"><b>${h(c.nom)}</b><span>${h(c.email || '')}</span></span><span class="m2"></span></div>`).join('')}</div></section>`;
      $('#ajout', main).onclick = () => fiche({actif: 1, role: 'membre', service: 'sav'});
      const q = $('#eq_q', main);
      q.oninput = () => { vueEquipe.q = q.value; clearTimeout(q._t); q._t = setTimeout(() => { render(); const n = $('#eq_q', main); n.focus(); n.setSelectionRange(n.value.length, n.value.length); }, 200); };
      $$('[data-eqs]', main).forEach((b) => b.onclick = () => { vueEquipe.service = b.dataset.eqs; render(); });
      $$('[data-c]', main).forEach((el) => { const go = () => fiche(parId(el.dataset.c)); el.onclick = go; el.onkeydown = (e) => { if (e.key === 'Enter') go(); }; });
      $$('[data-resp]', main).forEach((s) => s.onchange = async () => {
        await api('equipe/responsables', {method: 'POST', body: {[s.dataset.resp]: Number(s.value)}});
        r.responsables[s.dataset.resp] = Number(s.value); toast('Responsable enregistré'); render();
      });
    };
    const fiche = (c) => {
      const role = c.role || 'membre';
      const champs = [
        ligne('Nom', `<input name="nom" value="${h(c.nom)}" autocomplete="off">`),
        ligne('Fonction', `<input name="fonction" value="${h(c.fonction)}" placeholder="ex. RSO, Comptabilité, Back-office SAV">`),
        ligne('Service', sel('service', [['sav', 'SAV'], ['commerce', 'Commerce'], ['finance', 'Finance'], ['rh', 'RH'], ['direction', 'Direction']], c.service || c.service_equipe || 'sav')),
        ligne('E-mail', `<input name="email" type="email" value="${h(c.email)}">`, 'Sert aussi d\'identifiant de connexion.'),
        ligne('Portable', `<input name="mobile" value="${h(c.mobile)}" placeholder="06 12 34 56 78">`, 'Reçoit le SMS des demandes SAV qui lui sont transmises. Jamais communiqué aux clients.'),
        ligne('Rôle dans l\'envoi des demandes', sel('role', ROLES_ROUTAGE, role), 'Détermine quelles demandes lui sont envoyées (menu « Qui reçoit quoi »).'),
        ligne('Départements couverts', `<input name="departements" value="${h(c.departements)}" placeholder="69, 01, 38">`, 'Pour un RSO ou un CTA : les demandes de ces départements lui arrivent.'),
        ligne('Accès à la plateforme', sel('acces', [['', 'Aucun'], ...Object.entries(MA.acces)], c.acces || ''), 'Admin : tout, réglages compris. Service : les demandes de son service.'),
        ligne('Actif', sel('actif', [['1', 'Oui'], ['0', 'Non (absent, parti…)']], String(c.actif ?? 1))),
        role === 'boite' ? ligne('Lue par', `<textarea name="membres">${h(c.membres)}</textarea>`) : '',
      ].join('');
      const inviter = c.id && c.acces ? `<button class="btn" id="inviter">${ic('envoi', 's')}${c.a_mot_de_passe ? 'Envoyer un lien de mot de passe' : (c.invitation_en_cours ? 'Renvoyer l\'invitation' : 'Envoyer l\'invitation')}</button>` : '';
      openDrawer(c.id ? h(c.nom) : 'Ajouter une personne', champs + '<div id="invRes"></div>', (c.id ? delBtn() : '') + '<span style="flex:1"></span>' + inviter + saveBtn());
      // Départements : seulement pour ceux qui couvrent un secteur (RSO, CTA).
      const roleSel = $('#drawerBody [name="role"]'), depRow = $('#drawerBody [name="departements"]').closest('.editrow');
      const secteur = () => { depRow.hidden = !['rso', 'cta'].includes(roleSel.value); };
      roleSel.addEventListener('change', secteur); secteur();
      $('#drawerSave').onclick = async () => {
        const b = $('#drawerBody');
        const body = {};
        ['nom', 'fonction', 'service', 'email', 'mobile', 'role', 'departements', 'acces', 'actif', 'membres'].forEach((k) => { const i = $(`[name="${k}"]`, b); if (i) body[k] = i.value.trim(); });
        if (!body.nom) return toast('Le nom est requis', true);
        try {
          const x = c.id ? await patch('equipe/' + c.id, body) : await api('equipe', {method: 'POST', body});
          toast('Enregistré');
          if (!c.id && body.acces) { closeDrawer(); await tabs.equipe(); fiche(x.personne); return; }
          closeDrawer(); tabs.equipe();
        } catch (e) { toast(e.message, true); }
      };
      if (c.id) $('#drawerDelete').onclick = async () => { if (confirm(`Retirer ${c.nom} de l'équipe ?`)) { await api('equipe/' + c.id, {method: 'DELETE'}); closeDrawer(); tabs.equipe(); } };
      const inv = $('#inviter');
      if (inv) inv.onclick = async () => {
        inv.disabled = true;
        try {
          const x = await api('equipe/' + c.id + '/invitation', {method: 'POST'});
          $('#invRes').innerHTML = `<div class="msg ${x.envoi === 'envoyé' ? 'ok' : 'warn'}">${x.envoi === 'envoyé' ? 'Lien envoyé par e-mail à ' + h(c.email) + '.' : 'L\'e-mail n\'a pas pu partir (' + h(x.envoi) + '). Transmettez ce lien vous-même :'}
            <div class="lienbox" style="margin-top:8px"><input readonly value="${h(x.lien)}" aria-label="Lien pour choisir le mot de passe"><button class="btn small" id="copieInv">Copier</button></div>
            <span class="hint">Valable 7 jours, une seule fois.</span></div>`;
          $('#copieInv').onclick = async () => { const i = $('#invRes input'); i.select(); try { await navigator.clipboard.writeText(i.value); } catch (e) { document.execCommand('copy'); } toast('Lien copié'); };
        } catch (e) { toast(e.message, true); inv.disabled = false; }
      };
    };
    render();
  };

  // ================================================================== QUI REÇOIT QUOI
  tabs.routage = async () => {
    main.innerHTML = `<div class="page-head"><div class="t"><h1>Qui reçoit quoi</h1>
      <p>Pour chaque service, à qui partent les demandes. Ce que rien n'attribue part au responsable du service (menu « Équipe et accès »).</p></div></div><div id="routageRoot"></div>`;
    await repRoutageUI($('#routageRoot', main));
  };

  // ================================================================== MESSAGES AUX CLIENTS
  tabs.messages = async () => {
    const [a, eq] = await Promise.all([api('rep/apercu'), api('equipe')]);
    const p = eq.parametres || {};
    main.innerHTML = `
      <div class="page-head"><div class="t"><h1>Messages aux clients</h1><p>Ce que le client reçoit à chaque étape, par WhatsApp et par e-mail. Aucun numéro ni e-mail d'un collaborateur n'y figure.</p></div></div>
      <section class="box2" style="max-width:720px"><h2>Numéro donné aux clients</h2>
        <div class="frow"><input class="inp" id="std" value="${h(a.standard)}" style="max-width:260px" aria-label="Numéro du standard"><button class="btn primary" id="stdOk">Enregistrer</button></div>
        <span class="hint">Le standard figure dans chaque message, pour que le client puisse rappeler en citant son numéro de demande.</span></section>
      <section class="box2" style="max-width:720px;margin-top:16px"><h2>Urgences</h2>
        <label class="hint" for="ccu">Mettre en plus en copie de chaque urgence SAV (facultatif, séparer par ;)</label>
        <div class="frow"><input class="inp" id="ccu" value="${h(p.rep_cc_urgence || '')}" placeholder="ex. direction@airwco.com"><button class="btn primary" id="ccuOk">Enregistrer</button></div></section>
      <section class="section"><h2>Aperçu des messages</h2><p class="hint">Exemple sur la demande n° ${a.demande_id || '—'}. Les e-mails partent de service.clients@multiairfrance.store.</p>
        <div class="frow">${a.messages.map((m, i) => `<button class="chip ${i === 0 ? 'on' : ''}" data-m="${i}">${h(m.nom)}</button>`).join('')}</div>
        <div class="apercus" id="apercu"></div></section>`;
    const montrer = (i) => {
      const m = a.messages[i];
      $$('[data-m]', main).forEach((b) => b.classList.toggle('on', Number(b.dataset.m) === i));
      $('#apercu', main).innerHTML = `<div><h3 style="margin:0 0 8px;font-size:14px">E-mail</h3><iframe title="Aperçu de l'e-mail" sandbox></iframe></div>
        <div><h3 style="margin:0 0 8px;font-size:14px">WhatsApp</h3><div class="wa"><div class="b">${h(m.texte).replace(/\*(.+?)\*/g, '<b>$1</b>')}</div></div></div>`;
      $('#apercu iframe', main).srcdoc = m.html;
    };
    $$('[data-m]', main).forEach((b) => b.onclick = () => montrer(Number(b.dataset.m)));
    montrer(0);
    $('#stdOk', main).onclick = async () => { await api('parametres', {method: 'POST', body: {valeurs: {rep_standard_tel: $('#std').value.trim()}}}); toast('Numéro enregistré'); tabs.messages(); };
    $('#ccuOk', main).onclick = async () => { await api('parametres', {method: 'POST', body: {valeurs: {rep_cc_urgence: $('#ccu').value.trim()}}}); toast('Enregistré'); };
  };


  const journal = (rows) => `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Scénario</th><th>Statut</th><th>Événement</th><th>Détail</th></tr></thead><tbody>${
    rows.length ? rows.map((r) => `<tr style="cursor:default"><td>${fmtDate(r.date)}</td><td>${h(r.scenario)}</td><td>${pill(r.statut, r.statut === 'ok' ? 'ok' : (r.statut === 'erreur' ? 'danger' : 'info'))}</td><td>${h(r.type_evenement)}</td><td>${clip(r.resume, true)}</td></tr>`).join('')
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

  // ------------------------------------------------------------------ Répondeur : routage par service
  // Trois équipes distinctes — Finance, SAV, Commerce — chacune avec ses personnes et un tableau
  // « qui reçoit quoi ». Pas de règles à l'écran : le serveur les déduit des tableaux.
  let R_MARQUES = [['worthington', 'Worthington'], ['mauguiere', 'Mauguière'], ['abac', 'ABAC'], ['pneumatech', 'Pneumatech'], ['autre', 'Autre marque']];
  let R_NATURES = [['autre', 'Autre demande commerciale']];
  async function chargerListes() {
    const l = await api('rep/listes');
    R_MARQUES = [...l.marques.map((x) => [x.code, x.libelle]), ['autre', 'Autre marque']].filter((x, i, a) => a.findIndex((y) => y[0] === x[0]) === i);
    R_NATURES = l.natures.map((x) => [x.code, x.libelle]);
  }
  const nomDe = (liste, v) => (liste.find(([k]) => k === String(v ?? '').toLowerCase()) || [v, v])[1];
  const csvDe = (s) => String(s || '').split(/[,;]/).map((x) => x.trim().toLowerCase()).filter(Boolean);
  const sel = (name, liste, v) => `<select name="${name}">${liste.map(([k, t]) => `<option value="${h(k)}" ${String(v ?? '') === String(k) ? 'selected' : ''}>${h(t)}</option>`).join('')}</select>`;
  const cases = (name, liste, csv) => `<div style="display:flex;flex-wrap:wrap;gap:6px 14px">${liste.map(([k, t]) =>
    `<label style="display:flex;gap:5px;align-items:center;font-size:13px"><input type="checkbox" name="${name}" value="${h(k)}" ${csvDe(csv).includes(String(k)) ? 'checked' : ''}>${h(t)}</label>`).join('')}</div>`;
  const lu = (root, name) => $$(`[name="${name}"]:checked`, root).map((i) => i.value).join(',');
  const ligne = (label, html, aide = '') => `<div class="editrow"><label>${h(label)}</label>${html}${aide ? `<span class="hint">${h(aide)}</span>` : ''}</div>`;

  // Ce que chaque rôle demande de renseigner : rien de plus.
  const ROLES = {
    finance: {titre: 'Finance', champs: ['email', 'mobile']},
    backoffice: {titre: 'Back-office support', champs: ['email', 'mobile']},
    rso: {titre: 'RSO (responsable technique terrain)', champs: ['email', 'mobile', 'departements', 'marques']},
    cta: {titre: 'CTA ABAC (agent externe)', champs: ['email', 'mobile', 'departements']},
    boite: {titre: 'Boîte partagée', champs: ['email', 'membres', 'competences']},
    direct_projet: {titre: 'Direct / Projet', champs: ['email', 'mobile']},
  };
  const MARQUES_SAV = [['worthington', 'Worthington'], ['mauguiere', 'Mauguière'], ['pneumatech', 'Pneumatech'], ['abac', 'ABAC'], ['autre', 'Autre marque (Kaeser, Atlas…)']];
  const MARQUES_COM = [['abac', 'ABAC'], ['worthington', 'Worthington'], ['mauguiere', 'Mauguière'], ['pneumatech', 'Pneumatech'], ['autre', 'Marque non précisée']];
  const NATURES_COM = [['commande_pieces', 'Pièces — commande ou suivi de livraison'], ['devis_pieces', 'Devis pièces détachées ou SAV'],
    ['commande_equipement', 'Équipement — commande ou suivi de livraison'], ['devis_equipement', 'Devis équipement neuf']];
  const CHOIX_SAV = [['rso', 'RSO du secteur'], ['cta', 'CTA du secteur'], ['backoffice', 'Back-office'], ['repli', 'Responsable SAV']];
  const routeSub = {v: 'sav'};

  async function repRoutageUI(root) {
    const [c, g] = await Promise.all([api('rep/contacts'), api('rep/grilles'), chargerListes()]);
    const contacts = c.rows, params = c.parametres || {}, grilles = g.grilles;
    const duRole = (...roles) => contacts.filter((x) => roles.includes(x.role));
    const recharger = () => repRoutageUI(root);
    const etapes = [['finance', '① Finance'], ['sav', '② SAV'], ['commerce', '③ Commerce'], ['verifier', '✓ Vérifier']];

    // Liste de personnes d'un rôle : le tableau n'affiche que les champs utiles à ce rôle.
    const personnes = (role, vide) => {
      const liste = duRole(role), f = ROLES[role].champs;
      return `<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Nom</th>${f.includes('email') ? '<th>Email</th>' : ''}${f.includes('mobile') ? '<th>Portable (SMS)</th>' : ''}
        ${f.includes('departements') ? '<th>Départements</th>' : ''}${f.includes('marques') ? '<th>Marques</th>' : ''}${f.includes('membres') ? '<th>Lue par</th>' : ''}<th>Actif</th></tr></thead><tbody>
        ${liste.length ? liste.map((x) => `<tr data-contact="${x.id}"><td><b>${h(x.nom)}</b>${x.competences && !f.includes('membres') ? `<br><span class="hint">${h(x.competences)}</span>` : ''}</td>
          ${f.includes('email') ? `<td>${x.email ? h(x.email) : pill('à compléter', 'warn')}</td>` : ''}
          ${f.includes('mobile') ? `<td>${x.mobile ? h(x.mobile) : '<span class="hint">—</span>'}</td>` : ''}
          ${f.includes('departements') ? `<td>${x.departements ? h(x.departements) : pill('à compléter', 'warn')}</td>` : ''}
          ${f.includes('marques') ? `<td>${x.marques ? h(csvDe(x.marques).map((m) => nomDe(MARQUES_SAV, m)).join(', ')) : '<span class="hint">toutes</span>'}</td>` : ''}
          ${f.includes('membres') ? `<td>${x.competences ? `<span class="hint">${h(x.competences)}</span><br>` : ''}${clip(x.membres, true)}</td>` : ''}
          <td>${Number(x.actif) ? pill('oui', 'ok') : pill('non', 'muted')}</td></tr>`).join('')
        : `<tr><td class="empty" colspan="7">${h(vide)}</td></tr>`}</tbody></table></div>
        <div style="margin-top:6px"><button class="btn small" data-ajout="${role}">+ Ajouter : ${h(ROLES[role].titre)}</button></div>`;
    };
    const bloc = (titre, sousTitre, corps) => `<div class="card" style="margin-bottom:12px"><h3>${h(titre)}</h3>${sousTitre ? `<p class="hint" style="margin-top:-4px">${sousTitre}</p>` : ''}${corps}</div>`;
    const boites = duRole('boite');
    const choixBoite = (name, v) => sel(name, [['', 'Responsable commerce'], ...boites.map((b) => [String(b.id), b.nom])], v ? String(v) : '');

    const vues = {
      finance: () => bloc('Qui reçoit les demandes finance ?', 'Une seule étape : chaque demande finance part à <b>toutes</b> les personnes actives ci-dessous.',
        personnes('finance', 'Personne : les demandes finance partent au responsable finance.')),

      sav: () => `
        ${bloc('Étape 1 — Les personnes du SAV', 'Renseignez l\'email et le portable : le SMS part à chaque demande. Pour un RSO ou un CTA, les départements qu\'il couvre.', `
          <h4 style="margin:6px 0">Back-office support</h4>${personnes('backoffice', 'Personne')}
          <h4 style="margin:16px 0 6px">RSO — responsables techniques terrain</h4>${personnes('rso', 'Aucun RSO : les demandes « RSO du secteur » partiront au back-office.')}
          <h4 style="margin:16px 0 6px">CTA ABAC — agents externes</h4>${personnes('cta', 'Aucun CTA : les demandes « CTA du secteur » partiront au back-office.')}`)}
        ${bloc('Étape 2 — Qui rappelle le client ?', '« RSO / CTA du secteur » : celui qui couvre le département du site. Si personne ne le couvre, le back-office prend le relais.', `
          <div class="tbl-wrap"><table class="tbl" id="grilleSav"><thead><tr><th>Marque</th><th>Client direct</th><th>Distributeur</th></tr></thead><tbody>
          ${MARQUES_SAV.map(([m, t]) => `<tr style="cursor:default"><td><b>${h(t)}</b></td>${['direct', 'distributeur'].map((cl) =>
            `<td>${sel(`sav.${m}.${cl}`, CHOIX_SAV, grilles.sav[m]?.[cl] || 'repli')}</td>`).join('')}</tr>`).join('')}
          </tbody></table></div>
          <div style="margin-top:10px"><button class="btn primary" id="saveSav">Enregistrer le tableau SAV</button></div>`)}
        ${bloc('Étape 3 — Urgences', 'Une urgence (production arrêtée) part aux mêmes personnes, avec « URGENT » dans l\'objet et le SMS, et allume le bandeau rouge du tableau de bord.', `
          <label class="hint" style="display:block">Mettre en plus en copie de chaque urgence (facultatif, séparer par ;)
          <input name="rep_cc_urgence" value="${h(params.rep_cc_urgence)}" placeholder="ex. responsable.sav@airwco.com" style="width:100%;margin-top:4px"></label>
          <div style="margin-top:10px"><button class="btn small primary" id="saveUrgence">Enregistrer</button></div>`)}`,

      commerce: () => `
        ${bloc('Étape 1 — Les boîtes partagées', 'Le mail part à l\'adresse de la boîte ; « lue par » est pour information.', `
          ${personnes('boite', 'Aucune boîte')}
          <h4 style="margin:16px 0 6px">Direct / Projet</h4>${personnes('direct_projet', 'Personne')}`)}
        ${bloc('Étape 2 — Quelle boîte reçoit quelle demande ?', 'Une case sur « Responsable commerce » : la demande part au responsable du commerce, le temps de désigner la bonne boîte.', `
          <div class="tbl-wrap"><table class="tbl" id="grilleCom"><thead><tr><th>Demande</th>${MARQUES_COM.map(([, t]) => `<th>${h(t)}</th>`).join('')}</tr></thead><tbody>
          ${NATURES_COM.map(([n, t]) => `<tr style="cursor:default"><td><b>${h(t)}</b></td>${MARQUES_COM.map(([m]) =>
            `<td>${choixBoite(`com.${n}.${m}`, grilles.commerce[n]?.[m])}</td>`).join('')}</tr>`).join('')}
          <tr style="cursor:default"><td><b>OVITY / FITEC (acquisitions)</b></td><td colspan="${MARQUES_COM.length}">${choixBoite('acquisitions', grilles.acquisitions)}</td></tr>
          <tr style="cursor:default"><td><b>Toute autre demande commerciale</b></td><td colspan="${MARQUES_COM.length}">${choixBoite('reste', grilles.reste)}</td></tr>
          </tbody></table></div>
          <div style="margin-top:10px"><button class="btn primary" id="saveCom">Enregistrer le tableau Commerce</button></div>`)}`,

      verifier: () => `
        ${bloc('Tester : qui recevrait cet appel ?', 'Rien n\'est envoyé.', `
          <div class="route-grid" id="simForm">
            <label>Service ${sel('service', [['sav', 'SAV'], ['commercial', 'Commerce'], ['finance', 'Finance']], 'sav')}</label>
            <label data-pour="sav commercial">Marque ${sel('marque', MARQUES_SAV, 'worthington')}</label>
            <label data-pour="sav">Client ${sel('type_client', [['direct', 'Client direct'], ['distributeur', 'Distributeur']], 'direct')}</label>
            <label data-pour="sav">Urgence ${sel('urgence', [['false', 'Non urgent'], ['true', 'URGENT — production arrêtée']], 'false')}</label>
            <label data-pour="sav">Code postal du site <input name="code_postal" placeholder="ex. 69003" inputmode="numeric"></label>
            <label data-pour="commercial">Demande ${sel('nature', NATURES_COM, 'commande_pieces')}</label>
          </div>
          <div style="margin-top:10px"><button class="btn primary" id="simGo">Qui reçoit ?</button></div>
          <div class="route-res" id="simRes"></div>`)}
        ${bloc('Dernier recours', 'Ce qu\'aucun tableau ne couvre part au responsable du service (menu « Équipe et accès »). Si même ce responsable est injoignable, la demande part à ces adresses (séparées par ;).', `
          <input name="rep_repli_email" value="${h(params.rep_repli_email)}" placeholder="ex. cyril.mortier@airwco.com; service.clients@multiairfrance.store" style="width:100%">
          <div style="margin-top:10px"><button class="btn small primary" id="saveRepli">Enregistrer</button></div>`)}
        <details><summary class="hint" style="cursor:pointer">WhatsApp non identifiés (aiguilleur)</summary><div id="oldRoutage" style="margin-top:10px"></div></details>`,
    };

    root.innerHTML = `<div class="subtabs" id="routeSteps">${etapes.map(([k, t]) => `<button data-step="${k}" class="${k === routeSub.v ? 'active' : ''}">${h(t)}</button>`).join('')}</div>
      <div id="routeVue">${vues[routeSub.v]()}</div>`;
    $$('[data-step]', root).forEach((b) => b.addEventListener('click', () => { routeSub.v = b.dataset.step; recharger(); }));
    const vue = $('#routeVue', root);

    $$('[data-contact]', vue).forEach((tr) => tr.addEventListener('click', () => fiche(contacts.find((x) => x.id == tr.dataset.contact))));
    $$('[data-ajout]', vue).forEach((b) => b.addEventListener('click', () => fiche({role: b.dataset.ajout, actif: 1})));

    function fiche(x) {
      const f = ROLES[x.role]?.champs || ['email', 'mobile'];
      const champs = [
        ligne('Nom', `<input name="nom" value="${h(x.nom)}">`),
        f.includes('email') ? ligne(x.role === 'boite' ? 'Adresse de la boîte' : 'Email', `<input name="email" value="${h(x.email)}">`) : '',
        f.includes('mobile') ? ligne('Portable', `<input name="mobile" value="${h(x.mobile)}" placeholder="06 12 34 56 78">`, 'Reçoit un SMS à chaque demande.') : '',
        f.includes('departements') ? ligne('Départements couverts', `<input name="departements" value="${h(x.departements)}" placeholder="69, 01, 38, 42">`, 'Numéros séparés par des virgules.') : '',
        f.includes('marques') ? ligne('Marques', cases('marques', MARQUES_SAV.slice(0, 3), x.marques), 'Aucune case = toutes.') : '',
        f.includes('competences') ? ligne('Activité', `<input name="competences" value="${h(x.competences)}">`) : '',
        f.includes('membres') ? ligne('Lue par', `<textarea name="membres">${h(x.membres)}</textarea>`) : '',
        ligne('Actif', sel('actif', [['1', 'Oui'], ['0', 'Non (absent, parti…)']], String(x.actif ?? 1))),
      ].join('');
      openDrawer(`${h(ROLES[x.role]?.titre || x.role)} — ${h(x.nom || 'nouveau')}`, champs, saveBtn() + (x.id ? delBtn() : ''));
      $('#drawerSave').onclick = async () => {
        const b = $('#drawerBody');
        const body = {role: x.role};
        ['nom', 'email', 'mobile', 'departements', 'competences', 'membres', 'actif'].forEach((k) => { const i = $(`[name="${k}"]`, b); if (i) body[k] = i.value.trim(); });
        if (f.includes('marques')) body.marques = lu(b, 'marques');
        if (!body.nom) return toast('Le nom est requis', true);
        if (x.id) await patch('rep/contacts/' + x.id, body); else await api('rep/contacts', {method: 'POST', body});
        toast('Enregistré'); closeDrawer(); recharger();
      };
      if (x.id) $('#drawerDelete').onclick = async () => { if (confirm(`Retirer ${x.nom} ?`)) { await api('rep/contacts/' + x.id, {method: 'DELETE'}); closeDrawer(); recharger(); } };
    }

    const lireGrilles = () => {
      const val = (n) => { const i = $(`[name="${n}"]`, vue); return i ? i.value : null; };
      const out = JSON.parse(JSON.stringify(grilles));
      MARQUES_SAV.forEach(([m]) => ['direct', 'distributeur'].forEach((cl) => { const v = val(`sav.${m}.${cl}`); if (v !== null) (out.sav[m] = out.sav[m] || {})[cl] = v; }));
      NATURES_COM.forEach(([n]) => MARQUES_COM.forEach(([m]) => { const v = val(`com.${n}.${m}`); if (v !== null) (out.commerce[n] = out.commerce[n] || {})[m] = v ? Number(v) : null; }));
      ['acquisitions', 'reste'].forEach((k) => { const v = val(k); if (v !== null) out[k] = v ? Number(v) : null; });
      return out;
    };
    const saveGrilles = async (msg) => { await api('rep/grilles', {method: 'POST', body: lireGrilles()}); toast(msg); recharger(); };
    const on = (id, fn) => { const b = $(id, vue); if (b) b.onclick = fn; };
    on('#saveSav', () => saveGrilles('Tableau SAV enregistré'));
    on('#saveCom', () => saveGrilles('Tableau Commerce enregistré'));
    on('#saveUrgence', async () => { await api('parametres', {method: 'POST', body: {valeurs: {rep_cc_urgence: $('[name=rep_cc_urgence]', vue).value.trim()}}}); toast('Enregistré'); });
    on('#saveRepli', async () => { await api('parametres', {method: 'POST', body: {valeurs: {rep_repli_email: $('[name=rep_repli_email]', vue).value.trim()}}}); toast('Enregistré'); });

    if (routeSub.v === 'verifier') {
      const form = $('#simForm', vue);
      const montrer = () => { const s = $('[name=service]', form).value; $$('[data-pour]', form).forEach((l) => { l.hidden = !l.dataset.pour.split(' ').includes(s); }); };
      $('[name=service]', form).addEventListener('change', montrer); montrer();
      on('#simGo', async () => {
        const body = {};
        $$('select, input', form).forEach((i) => { if (!i.closest('[hidden]')) body[i.name] = i.value; });
        const x = await api('rep/simuler', {method: 'POST', body});
        const liste = (s) => (s || '').split(';').filter(Boolean).map((e) => h(e)).join(', ') || '<span class="hint">—</span>';
        $('#simRes', vue).innerHTML = `
          ${x.urgent ? '<div class="msg err" style="margin-top:0"><b>Urgent :</b> objet du mail et SMS marqués URGENT.</div>' : ''}
          <div class="who">→ ${h(x.personnes.map((p) => p.nom).join(', ') || 'Adresse de dernier recours')}</div>
          ${kv([['Mail à', liste(x.to)], ['Copie', liste(x.cc)], ['SMS à', liste(x.sms)], ['Lu par', h(x.personnes.map((p) => p.membres).filter(Boolean).join(' · '))],
            ['Pourquoi', h(x.regle_libelle)], ['Objet du mail', h(x.objet)]])}
          ${x.notes.length ? `<div class="msg" style="background:#fdefd6;color:var(--warn)">${x.notes.map(h).join('<br>')}</div>` : ''}`;
      });
      routageUI($('#oldRoutage', vue), 'repondeur', {keyLabel: 'Clé', placeholder: 'aiguilleur',
        hint: 'Seule la clé « aiguilleur » sert encore : destinataire des WhatsApp que Claire n\'a pas pu rattacher à un appel.'});
    }
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
        ${t.rep_urgences ? `<div class="todo" data-go="repondeur" style="border-color:var(--danger);color:var(--danger)"><span>🔴 Urgences SAV non prises en charge (72 h)</span><b>${num(t.rep_urgences)}</b></div>` : ''}
        ${todo(t.rep_demandes_a_traiter, 'Demandes SAV / Commercial / Finance à rappeler', 'repondeur')}
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
    const [s, fiches, demandes, conv, dist, logs] = await Promise.all([
      api('stats/repondeur'), api('rep/fiches'), api('rep/demandes'), api('rep/messages', {query: {limit: 2000}}),
      api('distributeurs', {query: {limit: 5000}}), api('log', {query: {scenario: 'repondeur', limit: 50}}),
      chargerListes().catch(() => {}),
    ]);
    // une ligne par numéro : le fil complet des échanges avec Claire
    const fils = [...conv.rows.reduce((m, r) => {
      const cle = r.tel_norm || ('fiche-' + r.fiche_id);
      const f = m.get(cle) || {cle, tel_norm: r.tel_norm, fiche_id: r.fiche_id, societe: r.societe, contact: r.contact,
        service: r.service, fiche_statut: r.fiche_statut, canal: r.canal, nb: 0, debut: r.date, fin: r.date, echanges: []};
      f.nb++; f.echanges.push(r);
      if (r.date < f.debut) f.debut = r.date;
      if (r.date > f.fin) f.fin = r.date;
      f.societe = f.societe || r.societe; f.contact = f.contact || r.contact;
      return m.set(cle, f);
    }, new Map()).values()];
    const k = s.kpi;
    // Vue globale : toutes les demandes transmises, plus les appels encore en qualification WhatsApp (pas encore de demande).
    const SVC_FICHE = {technique: 'SAV', commercial: 'COMMERCIAL', finance: 'FINANCE'};
    const avecDemande = new Set(demandes.rows.map((d) => d.fiche_id).filter(Boolean));
    const toutes = [
      ...demandes.rows.map((d) => ({...d, etape: d.statut})),
      ...fiches.rows.filter((f) => ['En attente', 'Urgent'].includes(f.statut) && !avecDemande.has(f.id)).map((f) => ({
        fiche: f, fiche_id: f.id, etape: 'qualification', created_at: f.created_at, service: SVC_FICHE[String(f.service || '').trim().toLowerCase()] || '',
        priorite: f.urgence ? 'URGENT' : 'NORMAL', societe: f.societe, contact: f.contact, tel: f.tel_norm, marque: f.marque, modele: f.modele,
        departement: f.departement, source: 'en_qualification', type_panne: f.type_panne, resume: f.resume})),
    ];
    const st = subtabs([['fiches', `Fiches d'appel (${fiches.rows.length})`], ['conversations', `Conversations (${fils.length})`], ['distributeurs', `Distributeurs (${dist.rows.length})`], ['journal', 'Journal']], repSub.v, (v) => { repSub.v = v; renderSub(); });
    main.innerHTML = `
      <div class="page-head"><div class="t"><h1>Répondeur (appels)</h1><p>Appels reçus par Claire, échanges WhatsApp et distributeurs. Les demandes se suivent dans « Demandes ».</p></div><span class="hint">scénarios Make 9582857 · 9583172 · 9583010 · 9791097</span></div>
      <div class="kpis">
        ${kpi(num(k.urgences_a_traiter), 'Urgences à traiter', k.urgences_a_traiter ? 'danger' : 'ok')}
        ${kpi(num(k.demandes_a_traiter), 'Demandes à rappeler', k.demandes_a_traiter ? 'warn' : 'ok')}
        ${kpi(num(k.demandes_en_cours), 'Rappels en cours', 'info')}
        ${kpi(num(k.appels_j30), 'Appels sur 30 jours')}
        ${kpi(num(k.en_attente), 'En attente de réponse WhatsApp', k.en_attente ? 'warn' : '')}
        ${kpi(num(k.traites_whatsapp), 'Qualifiés par WhatsApp')}
        ${kpi(num(k.sans_reponse), 'Transmis sans réponse WhatsApp')}
        ${kpi(pct(s.taux_reponse_whatsapp), 'Taux de réponse WhatsApp', 'info')}
      </div>
      <div class="grid2" style="margin-top:12px">
        <div class="card"><h3>Appels et demandes par jour (30 j)</h3><div class="chart-wrap"><canvas id="c_rep"></canvas></div></div>
        <div class="grid3" style="grid-template-columns:1fr">${distCard('Demandes par service', s.par_service)}${distCard('Fiches par étape de qualification', s.par_statut.map((r) => ({k: (FICHE_STATUTS[r.k] || [r.k])[0], n: r.n})))}</div>
      </div>
      <div class="section-title"><h2>Données</h2><div class="tools">${exportBtn('rep_fiches')} ${exportBtn('rep_demandes')} ${exportBtn('rep_messages')}</div></div>
      ${st.html}<div id="rep_sub"></div>`;
    barLine('c_rep', [{label: 'Appels (fiches)', data: s.serie}, {label: 'Demandes transmises', data: s.serie_demandes, type: 'line'}]);
    st.bind(main);

    const bulles = (echanges) => `<div class="chat">${echanges.map((m) => `
      ${m.message ? `<div class="bubble u"><span class="t">Client · ${fmtDate(m.date)}${m.canal ? ' · ' + h(m.canal) : ''}</span>${h(m.message)}</div>` : ''}
      ${m.reponse ? `<div class="bubble a"><span class="t">Claire</span>${h(m.reponse)}</div>` : ''}`).join('')}</div>`;
    const conversation = (f) => {
      const e = [...f.echanges].sort((a, b) => String(a.date).localeCompare(String(b.date)) || (a.id - b.id));
      openDrawer(`Conversation — ${h(f.societe || f.contact || f.tel_norm || '')}`, `
        ${kv([['Téléphone', h(f.tel_norm)], ['Société', h(f.societe)], ['Contact', h(f.contact)],
          ['Service', h(f.service)], ['Statut de la fiche', ficheStatut(f.fiche_statut)],
          ['Premier échange', fmtDate(f.debut)], ['Dernier échange', fmtDate(f.fin)], ['Échanges', f.nb],
          ['Fiche d\'appel', f.fiche_id ? `<button class="link" id="goFiche">#${f.fiche_id}</button>` : '<span class="hint">aucune</span>']])}
        ${bulles(e)}`);
      const g = $('#goFiche');
      if (g) g.onclick = () => { const x = fiches.rows.find((r) => r.id === f.fiche_id); if (x) fiche(x); };
    };
    const fiche = (f) => {
      const fields = [
        {key: 'statut', label: 'Étape de qualification', type: 'select', options: Object.entries(FICHE_STATUTS).map(([v, [t]]) => [v, t])},
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
        <p class="hint">Cette fiche retrace l'appel et la qualification par Claire. Le rappel du client se suit sur la demande ci-dessous.</p>
        <h4>Demandes transmises (${dems.length})</h4>${dems.length ? dems.map((d) => `<div>${pill(d.service, 'info')} ${pill(d.priorite, cls(d.priorite))} ${pill(L(d.statut), cls(d.statut))} ${fmtDate(d.created_at)} — ${h(d.resume || '')}${d.destinataires ? ` <span class="hint">→ ${h(d.destinataires)}</span>` : ''} <span class="hint">(${L(d.source)})</span></div>`).join('') : '<span class="hint">Aucune</span>'}
        <h4>Conversation avec Claire (${conv.rows.filter((m) => m.fiche_id === f.id).length})</h4>${(() => {
          const e = conv.rows.filter((m) => m.fiche_id === f.id).sort((a, b) => String(a.date).localeCompare(String(b.date)) || (a.id - b.id));
          return e.length ? bulles(e) : '<span class="hint">Aucun échange enregistré pour cette fiche</span>';
        })()}`,
        saveBtn() + delBtn());
      $('#drawerSave').onclick = async () => { await patch('rep/fiches/' + f.id, readForm($('#drawerBody'), fields)); toast('Fiche enregistrée'); closeDrawer(); show('repondeur'); refreshBadges(); };
      $('#drawerDelete').onclick = async () => { if (confirm('Supprimer cette fiche ?')) { await api('rep/fiches/' + f.id, {method: 'DELETE'}); closeDrawer(); show('repondeur'); } };
    };
    const suiviBase = location.origin + location.pathname.replace(/[^/]*$/, '');
    const telFr = (t) => { const m = String(t || '').match(/^33(\d{9})$/); return m ? ('0' + m[1]).replace(/(\d{2})(?=\d)/g, '$1 ') : (t || ''); };
    const demande = (d) => {
      const fields = [
        {key: 'statut', label: 'Suivi du rappel', type: 'select', options: [['a_traiter', 'À traiter'], ['en_cours', 'En cours (pris en charge)'], ['traite', 'Traitée (client rappelé)']]},
        {key: 'pris_par', label: 'Pris en charge par'}, {key: 'rappel_prevu', label: 'Rappel / intervention prévu', type: 'datetime'},
        {key: 'commentaire', label: 'Commentaire interne', type: 'textarea'},
      ];
      const urgent = d.priorite === 'URGENT';
      openDrawer(`${urgent ? '🔴 ' : ''}${h(d.service)} — ${h(d.societe || d.contact || '')}`, `
        ${urgent && d.statut === 'a_traiter' ? '<div class="msg err"><b>URGENT — production arrêtée.</b> Personne n\'a encore pris cette demande en charge.</div>' : ''}
        ${kv([['Reçue le', fmtDate(d.created_at)], ['Priorité', pill(d.priorite, cls(d.priorite))], ['Source', L(d.source)],
          ['À rappeler', h(d.contact || '') + (d.tel ? ` — <a href="tel:+${h(d.tel)}">${h(telFr(d.tel))}</a>` : '')], ['Société', h(d.societe)], ['Email', h(d.email)],
          ['Marque / modèle', h([d.marque, d.modele].filter(Boolean).join(' '))], ['N° série', h(d.numero_serie)], ['Équipement', d.type_equipement ? h(L(d.type_equipement)) : ''],
          ['Problème', h(d.type_panne)], ['Nature', d.nature ? h(nomDe(R_NATURES, d.nature)) : ''], ['Besoin commercial', h(d.besoin_commercial)], ['Réf. facture', h(d.reference_facture)], ['Résumé', h(d.resume)],
          ['Justification urgence', h(d.justification_urgence)],
          ['Type de client', d.type_client ? h(L(d.type_client)) : ''], ['Compte distributeur', h(d.compte_distributeur)], ['Département du site', h(d.departement)]])}
        <h4>Transmission</h4>
        ${kv([['Transmise à', h(d.destinataires)], ['Mails', h((d.dest_to || '').replace(/;/g, ', '))], ['Copie', h((d.dest_cc || '').replace(/;/g, ', '))],
          ['SMS', h((d.dest_sms || '').replace(/;/g, ', '))], ['Règle appliquée', h(d.regle_libelle)],
          ['Prise en charge', fmtDate(d.pris_at) + (d.pris_par ? ' — ' + h(d.pris_par) : '')], ['Rappel prévu', fmtDate(d.rappel_prevu)],
          ['Traitée le', fmtDate(d.traite_at) + (d.traite_par ? ' — ' + h(d.traite_par) : '')]])}
        ${d.jeton_interne ? `<h4>Liens de suivi</h4>${kv([
          ['Page équipe', `<a href="${h(suiviBase + 'demande.php?t=' + d.jeton_interne)}" target="_blank" rel="noopener">ouvrir</a> — envoyée dans le mail et le SMS de transmission`],
          ['Page client', `<a href="${h(suiviBase + 'suivi.php?c=' + d.jeton_client)}" target="_blank" rel="noopener">ouvrir</a> — ce que voit le client (sa demande uniquement)`]])}` : ''}
        <h4>Suivi</h4>${editForm(fields, d)}
        <p class="hint">Changer le statut ou la date de rappel prévient le client (WhatsApp / e-mail).</p>
        ${(() => {
          const e = conv.rows.filter((m) => d.fiche_id && m.fiche_id === d.fiche_id).sort((a, b) => String(a.date).localeCompare(String(b.date)) || (a.id - b.id));
          return e.length ? `<h4>Conversation WhatsApp avec Claire (${e.length})</h4>${bulles(e)}` : '';
        })()}`, saveBtn() + delBtn());
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
          {key: 'statut', label: 'Qualification', render: (r) => ficheStatut(r.statut)},
          {key: 'service', label: 'Service'}, {key: 'societe', label: 'Société'}, {key: 'contact', label: 'Contact'}, {key: 'tel_norm', label: 'Téléphone'},
          {key: 'marque', label: 'Marque'}, {key: 'modele', label: 'Modèle'}, {key: 'departement', label: 'Dpt'},
          {key: 'resume', label: 'Résumé', render: (r) => clip(r.resume, true)},
        ], {sort: 'created_at', filters: [{key: 'statut', label: 'Statut'}, {key: 'service', label: 'Service'}], onRow: fiche});
      } else if (repSub.v === 'demandes') {
        // Ordre de travail : urgences non prises, appels en qualification, ce qui reste à rappeler, puis le reste ; récent d'abord.
        const rang = (r) => (r.etape === 'a_traiter' && r.priorite === 'URGENT' ? 0 : ({qualification: 1, a_traiter: 2, en_cours: 3}[r.etape] ?? 4));
        const rows = [...toutes].sort((a, b) => rang(a) - rang(b) || String(b.created_at).localeCompare(String(a.created_at)));
        const urgentNonPris = (r) => r.priorite === 'URGENT' && r.etape === 'a_traiter';
        const ETAPES = [['qualification', 'En qualification WhatsApp'], ['a_traiter', 'À traiter'], ['en_cours', 'En cours'], ['traite', 'Traitée']];
        const nomEtape = (v) => (ETAPES.find((e) => e[0] === v) || [0, v])[1];
        const SERVICES = [['SAV', 'SAV'], ['COMMERCIAL', 'Commerce'], ['FINANCE', 'Finance']];
        const jours = (r) => (Date.now() - new Date(String(r.created_at).replace(' ', 'T')).getTime()) / 86400000;
        const noms = (r) => String(r.destinataires || '').split(/\s*[,;]\s*/).filter(Boolean);
        const tousNoms = [...new Set(toutes.flatMap(noms))].sort((a, b) => a.localeCompare(b, 'fr'));
        const tbl = {};
        const puce = (champ, v, t) => {
          const n = toutes.filter((r) => (!v || String(r[champ]) === v) && (champ === 'etape' || !tbl.t || !tbl.t.state.filter.etape || r.etape === tbl.t.state.filter.etape)).length;
          return `<button class="btn small" data-puce="${champ}" data-v="${h(v)}">${t} · ${n}</button>`;
        };
        tbl.t = table(root, rows, [
          {key: 'created_at', label: 'Reçue', render: (r) => `${fmtDate(r.created_at)}<br><span class="hint">${rel(r.created_at)}</span>`},
          {key: 'priorite', label: 'Priorité', render: (r) => r.priorite === 'URGENT' ? pill('🔴 URGENT', 'danger') : pill('Normal', 'muted')},
          {key: 'etape', label: 'Suivi', sortVal: rang, render: (r) => r.etape === 'qualification' ? pill('En qualification WhatsApp', 'warn')
            : `<select class="inline" data-dem="${r.id}"><option value="a_traiter" ${r.statut === 'a_traiter' ? 'selected' : ''}>À traiter</option><option value="en_cours" ${r.statut === 'en_cours' ? 'selected' : ''}>En cours</option><option value="traite" ${r.statut === 'traite' ? 'selected' : ''}>Traitée</option></select>`},
          {key: 'service', label: 'Service', render: (r) => r.service ? pill(r.service, 'info') : ''},
          {key: 'marque', label: 'Marque', render: (r) => h(r.marque_norm ? nomDe(R_MARQUES, r.marque_norm) : (r.marque || ''))
            + (r.nature ? `<br><span class="hint">${h(nomDe(R_NATURES, r.nature))}</span>` : '')},
          {key: 'type_client', label: 'Client', render: (r) => r.type_client ? h(L(r.type_client)) : ''},
          {key: 'departement', label: 'Dpt'},
          {key: 'societe', label: 'Société', render: (r) => clip(r.societe)},
          {key: 'contact', label: 'À rappeler', search: (r) => `${r.contact || ''} ${r.tel || ''} ${telFr(r.tel)} ${r.email || ''}`,
            render: (r) => `${h(r.contact || '')}${r.tel ? `<br><span class="hint">${h(telFr(r.tel))}</span>` : ''}`},
          {key: 'destinataires', label: 'Transmise à', render: (r) => clip(r.destinataires || (r.dest_to || '').replace(/;/g, ', '))
            + (r.pris_par ? `<br><span class="hint">pris par ${h(r.pris_par)}</span>` : '')},
          {key: 'resume', label: 'Problème', search: (r) => `${r.type_panne || ''} ${r.besoin_commercial || ''} ${r.reference_facture || ''} ${r.resume || ''} ${r.commentaire || ''}`,
            render: (r) => clip(r.type_panne || r.besoin_commercial || r.resume, true)},
        ], {filters: [
          {key: 'etape', label: 'Suivi', options: ETAPES},
          {key: 'service', label: 'Service', options: SERVICES},
          {key: 'priorite', label: 'Priorité', options: [['URGENT', 'Urgent'], ['NORMAL', 'Normal']], test: (r, v) => (r.priorite === 'URGENT') === (v === 'URGENT')},
          {key: 'periode', label: 'Période', options: [['1', 'Dernières 24 h'], ['7', '7 derniers jours'], ['30', '30 derniers jours'], ['90', '3 derniers mois']], test: (r, v) => jours(r) <= Number(v)},
          {key: 'destinataires', label: 'Transmise à', options: tousNoms.map((n) => [n, n]), test: (r, v) => noms(r).includes(v)},
          {key: 'pris_par', label: 'Pris en charge par'},
          {key: 'marque_norm', label: 'Marque', map: (v) => nomDe(R_MARQUES, v)},
          {key: 'type_client', label: 'Client', map: L},
          {key: 'source', label: 'Origine', map: (v) => (v === 'en_qualification' ? 'En qualification WhatsApp' : L(v))},
        ], onRow: (r) => (r.fiche ? fiche(r.fiche) : demande(r)),
          tools: `<div style="flex-basis:100%;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <span class="hint">Suivi :</span> ${puce('etape', '', 'Toutes')} ${ETAPES.map(([v, t]) => puce('etape', v, t)).join(' ')}
            <span class="hint" style="margin-left:12px">Service :</span> ${puce('service', '', 'Tous')} ${SERVICES.map(([v, t]) => puce('service', v, t)).join(' ')}
            <button class="btn small" id="repRaz" style="margin-left:auto">Effacer les filtres</button></div>`,
          afterRender: (el) => {
            const f = tbl.t ? tbl.t.state.filter : {};
            $$('[data-puce]', el).forEach((b) => {
              b.classList.toggle('primary', (f[b.dataset.puce] || '') === b.dataset.v);
              b.onclick = () => { tbl.t.state.filter[b.dataset.puce] = b.dataset.v; tbl.t.state.page = 0; tbl.t.render(); };
            });
            $('#repRaz', el).onclick = () => { tbl.t.state.filter = {}; tbl.t.state.q = ''; tbl.t.state.page = 0; tbl.t.render(); };
            $$('tbody tr[data-i]', el).forEach((tr) => tr.classList.toggle('urgent', urgentNonPris(rows[Number(tr.dataset.i)])));
            $$('[data-dem]', el).forEach((sel) => sel.addEventListener('change', async () => {
              await patch('rep/demandes/' + sel.dataset.dem, {statut: sel.value}); toast('Suivi mis à jour');
              const d = rows.find((x) => !x.fiche && x.id == sel.dataset.dem); if (d) { d.statut = sel.value; d.etape = sel.value; }
              sel.closest('tr').classList.toggle('urgent', !!d && urgentNonPris(d)); refreshBadges();
            }));
          }});
      } else if (repSub.v === 'conversations') {
        table(root, fils, [
          {key: 'fin', label: 'Dernier échange', render: (r) => fmtDate(r.fin)},
          {key: 'tel_norm', label: 'Téléphone'},
          {key: 'societe', label: 'Société', render: (r) => clip(r.societe)},
          {key: 'contact', label: 'Contact'},
          {key: 'service', label: 'Service', render: (r) => r.service ? pill(r.service, 'info') : ''},
          {key: 'fiche_statut', label: 'Qualification', render: (r) => ficheStatut(r.fiche_statut)},
          {key: 'nb', label: 'Échanges', num: true},
          {key: 'debut', label: 'Premier échange', render: (r) => fmtDate(r.debut)},
          {key: 'apercu', label: 'Dernier message', search: (r) => r.echanges.map((e) => `${e.message || ''} ${e.reponse || ''}`).join(' '),
            render: (r) => { const d = [...r.echanges].sort((a, b) => String(b.date).localeCompare(String(a.date)) || (b.id - a.id))[0] || {}; return clip(d.message || d.reponse, true); }},
        ], {sort: 'fin', filters: [{key: 'service', label: 'Service'}, {key: 'fiche_statut', label: 'Statut fiche'}], onRow: conversation});
      } else if (repSub.v === 'distributeurs') {
        table(root, dist.rows, [
          {key: 'raison_sociale', label: 'Raison sociale'}, {key: 'marque', label: 'Marque'}, {key: 'vendeur', label: 'Commercial'}, {key: 'compte', label: 'N° compte'},
          {key: 'nom', label: 'Nom'}, {key: 'prenom', label: 'Prénom'}, {key: 'email', label: 'Email'}, {key: 'telephone', label: 'Téléphone'},
        ], {sort: 'raison_sociale', asc: true, filters: [{key: 'marque', label: 'Marque'}, {key: 'vendeur', label: 'Commercial'}], onRow: distrib,
          tools: `<button class="btn small primary" id="addDist">+ Ajouter</button>`, afterRender: (el) => $('#addDist', el).addEventListener('click', () => distrib({}))});
      } else if (repSub.v === 'routage') {
        root.innerHTML = '<div class="loading">Chargement…</div>';
        repRoutageUI(root).catch((e) => { root.innerHTML = `<div class="msg err">${h(e.message)}</div>`; });
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
          ['Besoin résumé', h(l.besoin_resume)], ['Produits proposés', h(l.produits_proposes)], ['Session', l.session_id ? `<button class="link" id="goSess">${h(l.session_id)}</button>` : ''],
          ['Demande', l.demande_id ? `<a href="#demande/${h(l.demande_id)}">Demande n° ${h(l.demande_id)} — à suivre dans « Demandes »</a>` : '']])}
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
      <div class="section-title"><h2>Boîte service clients — e-mails reçus</h2><span class="hint">scénario Make 9209946 · service.clients@multiairfrance.store · chaque e-mail devient une demande numérotée</span></div>
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
          ['Envoyé le', fmtDate(d.envoye_at)], ['Pièces jointes', h(d.pieces_jointes || '—')], ['Demande', d.demande_id ? `<a href="#demande/${d.demande_id}" data-dem="${d.demande_id}">n° ${d.demande_id}</a>` : '<span class="hint">aucune</span>'],
          ['Message-ID', h(d.message_id)]])}
        ${d.demande_id ? '' : '<div id="advApercu" class="hint">Calcul du routage…</div>'}
        <h4>Question du client</h4><pre class="raw">${h(d.message || '—')}</pre>
        <h4>Réponse de Claire ${d.tag === 'RECU' ? '<span class="hint">(aucune : e-mail écarté par les filtres)</span>' : ''}</h4><pre class="raw">${h(d.mail_envoye || '—')}</pre>
        ${d.analyse_brute ? `<h4>Bloc d'analyse</h4><pre class="raw">${h(d.analyse_brute)}</pre>` : ''}
        <h4>Suivi</h4>${editForm(fields, d)}`, saveBtn() + delBtn());
      $('#drawerSave').onclick = async () => { await patch('adv/demandes/' + d.id, readForm($('#drawerBody'), fields)); toast('Enregistré'); closeDrawer(); show('adv'); refreshBadges(); };
      $('#drawerDelete').onclick = async () => { if (confirm('Supprimer ?')) { await api('adv/demandes/' + d.id, {method: 'DELETE'}); closeDrawer(); show('adv'); } };
      const lienDem = $('#drawerBody [data-dem]');
      if (lienDem) lienDem.onclick = (e) => { e.preventDefault(); closeDrawer(); show('demande', Number(lienDem.dataset.dem)); };
      if ($('#advApercu')) api('adv/demandes/' + d.id + '/apercu').then((a) => {
        const box = $('#advApercu');
        if (!box) return;
        if (!a.possible) { box.textContent = a.raison; return; }
        const svc = {SAV: 'SAV', COMMERCIAL: 'Commerce', FINANCE: 'Compta / finance', RH: 'RH', AUTRE: 'Autre'}[a.service] || a.service;
        box.className = '';
        box.innerHTML = `<h4>Demande à créer</h4>${kv([
          ['Service', h(svc) + (a.urgent ? ' ' + pill('URGENT', 'danger') : '') + (a.nature ? ' — ' + h((NATURES_COM.find((x) => x[0] === a.nature) || [0, a.nature])[1]) : '')],
          ['Envoyée à', h(a.destinataires || '—') + ` <span class="hint">(${h(a.regle)})</span>`],
          ['Client', h([a.contact, a.societe].filter(Boolean).join(' — ') || '—')], ['Téléphone', h(a.tel || '—')], ['Code postal', h(a.code_postal || '—')],
          ...(a.relance_de ? [['Relance', 'de la demande n° ' + a.relance_de + ' (pas de nouvelle demande)']] : []),
          ...(a.notes && a.notes.length ? [['Notes', h(a.notes.join(' ; '))]] : [])])}
          <p style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn primary" data-tk="1">Créer et prévenir l'équipe et le client</button>
          <button class="btn" data-tk="0">Créer et prévenir l'équipe seulement</button></p>
          <p class="hint">Le client reçoit son n° de demande et son lien de suivi. Pour un e-mail ancien, préférez « l'équipe seulement ».</p>`;
        box.querySelectorAll('[data-tk]').forEach((b) => b.onclick = async () => {
          box.querySelectorAll('[data-tk]').forEach((x) => x.disabled = true);
          try {
            const r = await api('adv/demandes/' + d.id + '/demande', {method: 'POST', body: {notifier_client: Number(b.dataset.tk)}});
            toast('Demande n° ' + r.demande_id + (r.nouvelle ? ' créée' : ' relancée')); closeDrawer(); refreshBadges(); show('demande', r.demande_id);
          } catch (e) { toast(e.message, true); box.querySelectorAll('[data-tk]').forEach((x) => x.disabled = false); }
        });
      }).catch((e) => { if ($('#advApercu')) $('#advApercu').textContent = e.message; });
    };
    table('#adv_tbl', dem.rows, [
      {key: 'date', label: 'Date', render: (r) => fmtDate(r.date)},
      {key: 'tag', label: 'Tag', render: (r) => pill(r.tag, cls(r.tag))},
      {key: 'statut_suivi', label: 'Suivi', render: (r) => pill(L(r.statut_suivi), cls(r.statut_suivi))},
      {key: 'famille', label: 'Famille'}, {key: 'cas', label: 'Cas'},
      {key: 'from_email', label: 'Expéditeur', render: (r) => clip(r.from_nom ? `${r.from_nom} <${r.from_email}>` : r.from_email)},
      {key: 'sujet', label: 'Objet', render: (r) => clip(r.sujet, true)},
      {key: 'demande_id', label: 'Demande', render: (r) => r.demande_id ? 'n° ' + r.demande_id : '—'},
      {key: 'techno', label: 'Techno'}, {key: 'critere', label: 'Critère'},
    ], {sort: 'date', filters: [{key: 'tag', label: 'Tag'}, {key: 'statut_suivi', label: 'Suivi', map: L}, {key: 'famille', label: 'Famille'}, {key: 'cas', label: 'Cas'}], onRow: detail});
  };

  // ================================================================== CSO DEVIS
  const CSO_STATUTS = ['En attente', 'Relance 1', 'Relance 2', 'Relance 3', 'Reponse recue', 'Gagne', 'Perdu', 'Sans suite'];
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
        ${kpi(num(k.reponses), 'Réponses client à traiter', k.reponses ? 'danger' : 'ok')}
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
        ${d.reponse_client ? `<h4>Réponses du client</h4><pre class="raw">${h(d.reponse_client)}</pre>` : ''}
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
        ['Envoyée à', (r.email_relance || '').split(';').filter(Boolean).map((e) => h(e)).join('<br>')
          || '<span class="pill danger">aucune adresse client — cette relance ne partira pas</span>'],
        ['En copie', (r.cc_relance || '').split(';').filter(Boolean).map((e) => h(e)).join('<br>') || '<span class="hint">aucune</span>'],
        ['Réponse du client vers', h(r.repondre_a)], ['Objet', h(r.objet_relance)]])}
      <h4>Mail qui sera envoyé</h4><pre class="raw">${h(r.texte_relance)}</pre>`);
    table('#cso_prev', prevues.rows, [
      {key: 'date_prevue', label: 'Date prévue', render: (r) => fmtDate(r.date_prevue, false) + (r.due ? ' ' + pill('à envoyer', 'danger') : '')},
      {key: 'relance_due', label: 'Relance', num: true, render: (r) => pill('n° ' + r.relance_due, r.relance_due >= 3 ? 'danger' : (r.relance_due === 2 ? 'warn' : 'info'))},
      {key: 'n_offre', label: 'N° offre'},
      {key: 'client', label: 'Client', render: (r) => clip(r.client)},
      {key: 'contact_client', label: 'Contact'},
      {key: 'email_relance', label: 'Sera envoyée à', render: (r) => (r.email_relance || '').split(';').filter(Boolean).map((e) => h(e)).join('<br>')
        || pill('aucune adresse client', 'danger')},
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
  depuisAdresse(false);
  refreshBadges();
})();
