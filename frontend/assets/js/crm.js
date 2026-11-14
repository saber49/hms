/* ============================================
   HMS — Lead-Modul (Interessentenmanagement)
   Spezifikation 27.10.2026, Phase 1:
   - Lead-Arbeitsbereich (Tabs Meine / Heute fällig / Überfällig / Alle)
   - Lead-Detail: Stage-Leiste, Checkliste, Zeitstrahl, Aufgaben, Protokoll
   - Dialoge: Anruf protokollieren, Aktivität, Aufgabe, Qualifizieren, Disqualifizieren
   - Deals: Liste mit Summen je Phase, Detail, Phasenwechsel mit Pflichtfeldern
   - Firmen & Kontakte (5.5), Einstellungen (Listen + Fristen, Admin)
   Data loading is separated from rendering: filters/sorting/realtime only
   re-render the affected part, so typed input is never thrown away.
   ============================================ */

const CRM_API = API_BASE + '/crm';
const CRM_LEAD_OFFEN = ['neu', 'kontaktversuch', 'verbunden'];
const crm = {
    opt: null, view: 'leads', tab: 'meine',
    lf: { stage: 'offen', sparte: '', quelle: '', owner: '', prioritaet: '', plz: '', q: '' },
    df: { status: 'offen', phase: '', sparte: '', owner: '', von: '', bis: '', q: '' }, kpi: {},
    modus: { leads: localStorage.getItem('crm_modus_leads') || 'liste', deals: localStorage.getItem('crm_modus_deals') || 'liste' }, ctx: null,
    sf: { art: 'firmen', q: '', lifecycle: '' },
    leads: [], zaehler: {}, deals: [], summen: [], stamm: [],
    detail: null,          // {typ, id, data}
    dirty: false,          // a form inside the detail has unsaved input
    loadSeq: 0,
    cache: {},             // last list response per view → instant repaint (back from detail, tab switch)
    listY: 0,              // list scroll position, restored by "Zur Liste"
};

// Re-renders of an area that is already on screen are PATCHED (hmsMorph,
// dom-morph.js) instead of rebuilt — no flash, scroll / open sections / typed
// input stay. Fallback for a cached old index.php without dom-morph.js.
function crmPaint(el, html, opts) {
    if (!el) return;
    if (typeof hmsMorph === 'function') hmsMorph(el, html, opts);
    else el.innerHTML = html;
}

// ---------- small helpers ----------
function crmIcon(n, st = '') { return `<span class="material-symbols-outlined"${st ? ` style="${st}"` : ''}>${n}</span>`; }
const crmE = v => escapeHtml(v == null ? '' : String(v));
function crmName(typ, key) {
    const l = (crm.opt?.listen?.[typ] || []).find(x => x.key === key);
    return l ? l.name : (key || '–');
}
function crmList(typ, nurAktiv = true) { return (crm.opt?.listen?.[typ] || []).filter(x => !nurAktiv || x.aktiv); }
function crmOptions(list, cur, placeholder) {
    return (placeholder !== undefined ? `<option value="">${crmE(placeholder)}</option>` : '') +
        list.map(o => {
            const v = typeof o === 'string' ? o : (o.key ?? o.id);
            const n = typeof o === 'string' ? o : o.name;
            return `<option value="${crmE(v)}" ${String(cur ?? '') === String(v) ? 'selected' : ''}>${crmE(n)}</option>`;
        }).join('');
}
function crmDate(d) { return d ? formatDate(String(d).slice(0, 10)) : '–'; }
function crmLocalDT(s) { return s ? formatDate(String(s).slice(0, 10)) + (String(s).length > 10 ? ' ' + String(s).slice(11, 16) : '') : '–'; }
function crmUtcDT(s) { return s ? formatDateTimeLocal(s) : '–'; }
function crmMoney(v) { return v == null || v === '' ? '–' : formatCurrency(v); }
function crmToday() { return crm.opt?.heute || new Date().toISOString().slice(0, 10); }
function crmAddDays(n) { const d = new Date(crmToday() + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); }
function crmNowLocal() { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 16); }
function crmSparte(s) { return s ? (typeof bereichBadgeHtml === 'function' ? bereichBadgeHtml(s) : `<span class="crm-chip">${crmE(s)}</span>`) : '<span class="crm-muted">Sparte fehlt</span>'; }
function crmStageBadge(key) { return `<span class="crm-stage crm-stage-${crmE(key)}">${crmE(crmName('lead_stage', key))}</span>`; }
function crmPhaseBadge(key) { return `<span class="crm-phase crm-phase-${crmE(key)}">${crmE(crmName('deal_phase', key))}</span>`; }
function crmV(id) { const el = document.getElementById(id); if (!el) return undefined; return el.type === 'checkbox' ? el.checked : String(el.value ?? '').trim(); }
function crmCan(r) {
    if (r === 'deals_any') return crmCan('deals') || crmCan('uebergabe');
    return !!crm.opt?.ich?.[r];
}

// ---------- HTTP (errors are NOT masked as empty lists) ----------
async function crmReq(method, url, body) {
    const isForm = body instanceof FormData;
    if (method !== 'GET') crm.lastWrite = Date.now();
    try {
        const res = await fetch(url, {
            method,
            headers: isForm || method === 'GET' ? getAuthHeadersNoContent() : getAuthHeaders(),
            body: method === 'GET' ? undefined : (isForm ? body : JSON.stringify(body || {})),
        });
        if (res.status === 401) { clearAuthToken(); window.location.href = 'login.php'; return { ok: false, status: 401, data: {} }; }
        const data = await res.json().catch(() => ({}));
        return { ok: res.ok, status: res.status, data };
    } catch (e) {
        return { ok: false, status: 0, data: { error: 'Netzwerkfehler: ' + e.message } };
    }
}
const crmGet = url => crmReq('GET', url);
function crmQs(o) { const p = new URLSearchParams(); Object.entries(o).forEach(([k, v]) => { if (v !== '' && v != null) p.set(k, v); }); const s = p.toString(); return s ? '?' + s : ''; }

/** Toast + mark fields (data-f="key") for a failed response. Returns true if it was an error. */
function crmFehler(r, fallback = 'Speichern fehlgeschlagen') {
    if (r.ok) return false;
    document.querySelectorAll('.crm-invalid').forEach(el => el.classList.remove('crm-invalid'));
    document.querySelectorAll('.crm-err').forEach(el => el.remove());
    const errs = r.data?.errors || {};
    Object.entries(errs).forEach(([k, m]) => {
        const el = document.querySelector(`[data-f="${CSS.escape(k)}"]`);
        if (!el) return;
        el.classList.add('crm-invalid');
        el.insertAdjacentHTML('afterend', `<div class="crm-err">${crmE([].concat(m).join(' '))}</div>`);
    });
    const msg = r.data?.error || Object.values(errs).flat()[0] || fallback;
    showToast(msg, 'error');
    return true;
}

async function crmLoadOpt(force = false) {
    if (crm.opt && !force) return crm.opt;
    const r = await crmGet(CRM_API + '/optionen');
    if (!r.ok) throw new Error(r.data?.error || 'Optionen konnten nicht geladen werden');
    crm.opt = r.data;
    return crm.opt;
}

// Live updates: never wipe a form the user is typing in.
if (window.HMSRealtime && typeof HMSRealtime.on === 'function') {
    HMSRealtime.on('crm', () => {
        if (typeof currentPage === 'undefined' || currentPage !== 'interessenten') return;
        if (Date.now() - (crm.lastWrite || 0) < 4000) return; // echo of my own save — already reloaded
        if (crm.detail) {
            if (crm.dirty || document.getElementById('modalOverlay')?.open) {
                document.getElementById('crmStaleBar')?.classList.add('show');
            } else crmOpenDetail(crm.detail.typ, crm.detail.id, true);
        } else crmLoadView();
    });
}

// ============================================
// PAGE SHELL — same building blocks as Kunden / Objekte / Konto & Firma:
// md-tabs, .data-bento tiles, .data-toolbar (MD3 search + selects + chips),
// .data-table-card. No module-specific form controls on list pages.
// ============================================
const CRM_VIEWS = [
    ['leads', 'person_search', 'Leads', 'leads'],
    ['deals', 'handshake', 'Deals', 'deals_any'],
    ['stamm', 'domain', 'Firmen & Kontakte', 'kontakte'],
    // Einstellungen (Inhaber / Administrator) live on the central page System › Einstellungen (einstellungen.js).
];

async function renderInteressenten() {
    const content = document.getElementById('content');
    try { await crmLoadOpt(true); } catch (e) {
        content.innerHTML = `<div class="empty-state">${crmIcon('error')}<p>${crmE(e.message)}</p><md-filled-button onclick="renderInteressenten()">Erneut versuchen</md-filled-button></div>`;
        return;
    }
    const preset = window._crmPreset; window._crmPreset = null;
    if (!crmCan('view')) { crm.view = 'deals'; crm.df.status = 'gewonnen'; } // Objektleitung: won deals of own Sparte
    if (preset?.tab && crmCan('leads')) { crm.view = 'leads'; crm.tab = preset.tab; }
    crm.detail = null; crm.dirty = false;

    // Only the sections this user was granted (Benutzerverwaltung → Vertrieb).
    const views = CRM_VIEWS.filter(v => !v[3] || crmCan(v[3]));
    if (!views.some(v => v[0] === crm.view)) crm.view = views[0]?.[0] || 'leads';
    if (!views.length) {
        content.innerHTML = `<div class="empty-state">${crmIcon('lock')}<p>Kein Vertriebsbereich freigegeben. Bitte wenden Sie sich an den Inhaber oder Administrator.</p></div>`;
        return;
    }
    content.innerHTML = `
        <div class="crm-page">
            <md-tabs id="crmTabs" class="crm-tabs-bar">${views.map(([k, i, l]) => `
                <md-primary-tab data-view="${k}" ${crm.view === k ? 'active' : ''}>
                    <span class="material-symbols-outlined" slot="icon">${i}</span>${l}
                </md-primary-tab>`).join('')}
            </md-tabs>
            <div id="crmStaleBar" class="crm-stale">${crmIcon('sync')} Daten wurden von einem Kollegen geändert. <md-text-button onclick="crmReloadDetail()">Neu laden</md-text-button></div>
            <div id="crmBody"></div>
        </div>`;
    document.getElementById('crmTabs')?.addEventListener('change', e => {
        const v = e.target.activeTab?.dataset?.view;
        if (v && (v !== crm.view || crm.detail)) crmSetView(v, true);
    });

    if (preset?.open) return crmOpenDetail(preset.open.typ, preset.open.id);
    crmRenderView();
}

function crmSetView(v, fromTabs = false) {
    crm.view = v; crm.detail = null; crm.dirty = false;
    if (!fromTabs) document.querySelectorAll('#crmTabs md-primary-tab').forEach(t => { t.active = t.dataset.view === v; });
    crmRenderView();
}

function crmBackToList() {
    crm.detail = null; crm.dirty = false;
    document.getElementById('crmStaleBar')?.classList.remove('show');
    crmRenderView();
    if (document.getElementById('crmRows')?.dataset.loaded) window.scrollTo(0, crm.listY || 0);
}
function crmReloadDetail() { document.getElementById('crmStaleBar')?.classList.remove('show'); crm.dirty = false; if (crm.detail) crmOpenDetail(crm.detail.typ, crm.detail.id, true); else crmLoadView(); }

/** Page-level buttons on the right of every toolbar (like "Neuer Kunde"). */
function crmToolbarActions(extra = '') {
    return `<div class="crm-toolbar-actions">${extra}
        ${crmCan('admin') ? `<md-outlined-button onclick="crmExport()"><md-icon slot="icon">download</md-icon>Export</md-outlined-button>` : ''}
        ${crmCan('kontakte_edit') ? `<md-filled-button onclick="crmNeuerKontakt()"><md-icon slot="icon">person_add</md-icon>${crmCan('leads_edit') ? 'Neuer Kontakt / Lead' : 'Neuer Kontakt'}</md-filled-button>` : ''}
    </div>`;
}

/** MD3 select options (selected state via attribute — read back with .value). */
function crmMdOpts(list, cur, placeholder) {
    const opt = (v, n) => `<md-select-option value="${crmE(v)}" ${String(cur ?? '') === String(v) ? 'selected' : ''}><div slot="headline">${crmE(n)}</div></md-select-option>`;
    return (placeholder !== undefined ? opt('', placeholder) : '') +
        list.map(o => typeof o === 'string' ? opt(o, o) : opt(o.key ?? o.id, o.name)).join('');
}
function crmMdFilter(scope, key, label, list, placeholder) {
    return `<md-outlined-select class="cust-bereich-select crm-filter" label="${crmE(label)}" data-scope="${scope}" data-key="${key}">${crmMdOpts(list, crm[scope][key], placeholder)}</md-outlined-select>`;
}
function crmMdSearch(scope, label, placeholder) {
    return `<md-outlined-text-field class="cust-search" label="${crmE(label)}" placeholder="${crmE(placeholder)}" value="${crmE(crm[scope].q)}" data-search="${scope}">
        <md-icon slot="leading-icon">search</md-icon></md-outlined-text-field>`;
}
/** MD3 components re-dispatch input/change from their shadow DOM — wire once per frame. */
function crmWire(root) {
    // Patched frames keep their elements — wire each element only once.
    const on = (sel, ev, fn) => root.querySelectorAll(sel).forEach(el => {
        if (el._crmWired) return;
        el._crmWired = true;
        el.addEventListener(ev, () => fn(el));
    });
    on('md-outlined-select[data-scope]', 'change', el => crmFilter(el.dataset.scope, el.dataset.key, el.value));
    on('md-outlined-text-field[data-search]', 'input', el => crmSearch(el.dataset.search, el.value, el.dataset.key || 'q'));
    on('md-outlined-text-field[data-scope]', 'change', el => crmFilter(el.dataset.scope, el.dataset.key, el.value));
}

/** Cache slot of the current list (Firmen and Kontakte have different rows). */
function crmCk() { return crm.view === 'stamm' ? 'stamm:' + crm.sf.art : crm.view; }

/** Frame (tiles + toolbar + table card) is patched in place; data fills #crmBento / #crmRows. */
function crmRenderView() {
    const body = document.getElementById('crmBody');
    if (!body) return;
    if (crm.view === 'settings') return crmRenderSettings();
    delete body.dataset.detail;
    body.dataset.frame = crm.view;
    crmPaint(body, crm.view === 'leads' ? crmLeadsFrame() : crm.view === 'deals' ? crmDealsFrame() : crmStammFrame(),
        { keep: el => el.id === 'crmRows' });
    crmWire(body);
    // Another list than the one on screen: show its last known rows at once
    // (refreshed right below) instead of an empty "Laden…" table.
    const box = document.getElementById('crmRows');
    if (box && box.dataset.ck !== crmCk()) {
        if (crm.cache[crmCk()]) crmApply(crm.cache[crmCk()]);
        else { delete box.dataset.loaded; delete box.dataset.key; }
    }
    crmLoadView();
}

let _crmSearchT = null;
function crmSearch(scope, val, key = 'q') {
    clearTimeout(_crmSearchT);
    _crmSearchT = setTimeout(() => { crm[scope][key] = val; crmLoadView(); }, 300);
}
function crmFilter(scope, key, val) { crm[scope][key] = val; crmLoadView(); }
function crmSetTab(tab) {
    crm.tab = tab;
    if (tab !== 'meine' || crm.lf.stage === 'neu') crm.lf.stage = 'offen';
    crmRenderView();
}

function crmViewUrl() {
    const kanban = crm.modus[crm.view] === 'kanban';
    if (crm.view === 'leads') return CRM_API + '/leads' + crmQs({ tab: crm.tab, ...crm.lf, ...(kanban ? { stage: 'offen' } : {}) });
    if (crm.view === 'deals') return CRM_API + '/deals' + crmQs(kanban ? { ...crm.df, status: 'alle', phase: '' } : crm.df);
    if (crm.view === 'stamm') return CRM_API + '/' + (crm.sf.art === 'firmen' ? 'firmen' : 'kontakte') + crmQs({ q: crm.sf.q, lifecycle: crm.sf.art === 'kontakte' ? crm.sf.lifecycle : '' });
    return null;
}

/** Refresh the current list. Rows already on screen are patched, never blanked. */
async function crmLoadView() {
    const url = crmViewUrl();
    if (!url) return;
    const seq = ++crm.loadSeq;
    const box = document.getElementById('crmRows');
    if (box && !box.dataset.loaded) box.innerHTML = `<div class="crm-loading">${crmIcon('progress_activity')} Laden…</div>`;
    // Other filter than the rows on screen: dim them only if the answer is slow.
    const dim = box?.dataset.loaded && box.dataset.key !== url
        ? setTimeout(() => { box.style.transition = 'opacity .15s'; box.style.opacity = '.55'; }, 150) : null;
    const r = await crmGet(url);
    clearTimeout(dim);
    if (seq !== crm.loadSeq || crm.detail) return;
    const target = document.getElementById('crmRows');
    if (!target) return;
    target.style.opacity = '';
    if (!r.ok) {
        if (target.dataset.loaded) return showToast(r.data?.error || 'Daten konnten nicht aktualisiert werden', 'error');
        target.innerHTML = `<div class="empty-state">${crmIcon('error')}<p>${crmE(r.data?.error || 'Daten konnten nicht geladen werden')}</p><md-filled-button onclick="crmLoadView()">Erneut versuchen</md-filled-button></div>`;
        return;
    }
    crm.cache[crmCk()] = { url, data: r.data };
    crmApply(crm.cache[crmCk()]);
}

/** Paint a list response {url, data} (fresh or cached) into #crmBento / #crmRows. */
function crmApply({ url, data }) {
    const target = document.getElementById('crmRows');
    if (!target) return;
    const kanban = crm.modus[crm.view] === 'kanban';
    const bento = document.getElementById('crmBento');
    let html;
    if (crm.view === 'leads') {
        crm.leads = data.data || []; crm.zaehler = data.zaehler || {}; crm.mehr = !!data.mehr;
        crmPaint(bento, crmLeadBento());
        html = (kanban ? crmLeadKanban() : crmLeadRows()) + crmMehrHinweis();
    } else if (crm.view === 'deals') {
        crm.deals = data.data || []; crm.summen = data.summen || []; crm.kpi = data.kpi || {}; crm.mehr = !!data.mehr;
        crmPaint(bento, crmDealBento());
        html = (kanban ? crmDealKanban() : crmDealRows()) + crmMehrHinweis();
    } else {
        crm.stamm = Array.isArray(data) ? data : [];
        html = crmStammRows();
    }
    crmPaint(target, html);
    target.dataset.loaded = '1';
    target.dataset.ck = crmCk();
    target.dataset.key = url;
}

/** Drop a deleted record from every cached list (no ghost row on the way back). */
function crmCacheDrop(id) {
    Object.values(crm.cache).forEach(c => {
        if (Array.isArray(c.data)) c.data = c.data.filter(x => x.id !== id);
        else if (Array.isArray(c.data?.data)) c.data.data = c.data.data.filter(x => x.id !== id);
    });
}

const crmMehrHinweis = () => crm.mehr ? `<div class="crm-more">${crmIcon('filter_alt')} Es werden die ersten 500 Einträge angezeigt — bitte über Suche oder Filter eingrenzen.</div>` : '';

/** One bento tile in the shared .data-bento style. */
function crmTile({ hero = false, cls = '', on = false, icon, label, n, foot = '', meta = '', click = '', title = '' }) {
    if (hero) return `
        <div class="bento-tile bento-tile-hero ${cls} ${on ? 'crm-tile-on' : ''}" onclick="${click}" title="${crmE(title)}">
            <div class="bento-tile-head">${crmIcon(icon)} ${crmE(label)}</div>
            <div class="bento-tile-number">${n}</div>
            ${foot ? `<div class="bento-tile-foot">${foot}</div>` : ''}
        </div>`;
    return `
        <div class="bento-tile ${cls} ${on ? 'crm-tile-on' : ''}" onclick="${click}" title="${crmE(title)}">
            <div class="bento-tile-icon">${crmIcon(icon)}</div>
            <div class="bento-tile-body">
                <div class="bento-tile-number-sm">${n}</div>
                <div class="bento-tile-label">${crmE(label)}</div>
            </div>
            ${meta ? `<div class="bento-tile-meta">${meta}</div>` : ''}
        </div>`;
}
const crmPill = (icon, text) => `<span class="bento-foot-pill">${crmIcon(icon, 'font-size:14px')} ${text}</span>`;

// ============================================
// LEAD-ARBEITSBEREICH (5.1)
// ============================================
function crmLeadsFrame() {
    const all = crmCan('all');
    const stageOpts = [{ key: 'offen', name: 'Offene Leads' }, { key: 'abgeschlossen', name: 'Abgeschlossene' }, { key: 'alle', name: 'Alle Stages' }, ...crmList('lead_stage', false)];
    return `
        <div class="data-bento" id="crmBento">${crmLeadBento()}</div>
        <div class="data-toolbar">
            <div class="data-toolbar-left">
                ${crmMdSearch('lf', 'Leads suchen', 'Firma, Kontakt, Ort, E-Mail…')}
                ${crmMdFilter('lf', 'stage', 'Stage', stageOpts)}
                ${crmMdFilter('lf', 'sparte', 'Sparte', crm.opt.sparten || [], 'Alle Sparten')}
                ${crmMdFilter('lf', 'quelle', 'Quelle', crmList('quelle', false), 'Alle Quellen')}
                ${crmMdFilter('lf', 'prioritaet', 'Priorität', crmList('prioritaet', false), 'Alle')}
                ${all ? crmMdFilter('lf', 'owner', 'Owner', crm.opt.owners || [], 'Alle Owner') : ''}
                <md-outlined-text-field class="crm-plz" label="PLZ / Region" maxlength="5" inputmode="numeric" value="${crmE(crm.lf.plz)}" data-search="lf" data-key="plz"></md-outlined-text-field>
            </div>
            ${crmToolbarActions(crmModusToggle('leads'))}
        </div>
        <div class="data-table-card"><div id="crmRows"></div></div>`;
}

function crmLeadBento() {
    const z = crm.zaehler || {}, all = crmCan('all'), t = crm.tab;
    const tiles = [
        crmTile({ hero: true, cls: 'bento-tile-total', on: t === 'meine', icon: 'person_search', label: 'Meine offenen Leads', n: z.meine ?? '–', click: "crmSetTab('meine')", title: 'Leads, für die ich Owner bin',
            foot: crmPill('fiber_new', `${z.neu ?? 0} ohne Kontakt`) + crmPill('call', `${z.verbunden ?? 0} verbunden`) }),
        crmTile({ cls: 'crm-tile-amber', on: t === 'heute', icon: 'today', label: 'Heute fällig', n: z.heute ?? '–', click: "crmSetTab('heute')", title: 'Nächste Aufgabe heute fällig' }),
        crmTile({ cls: z.ueberfaellig ? 'bento-tile-warn crm-tile-red' : 'crm-tile-red', on: t === 'ueberfaellig', icon: 'alarm', label: 'Überfällig', n: z.ueberfaellig ?? '–', click: "crmSetTab('ueberfaellig')",
            title: `Aufgabe überfällig oder seit ${crm.opt.einstellungen?.a8_inaktiv_tage || 7} Tagen ohne Aktivität (A8)` }),
        all ? crmTile({ cls: 'bento-tile-active', on: t === 'alle', icon: 'groups', label: 'Alle offenen Leads', n: z.alle ?? '–', click: "crmSetTab('alle')", title: 'Vertriebsleitung: alle Owner' })
            : crmTile({ cls: 'bento-tile-active', on: t === 'meine' && crm.lf.stage === 'neu', icon: 'fiber_new', label: 'Noch kein Kontakt', n: z.neu ?? '–', click: "crm.tab='meine';crm.lf.stage='neu';crmRenderView()", title: 'Stage „Neu“' }),
    ];
    return tiles.join('');
}

function crmLeadRows() {
    if (!crm.leads.length) return `<div class="empty-state">${crmIcon('person_search')}<p>Keine Leads in dieser Ansicht</p></div>`;
    const all = crmCan('all');
    const _p = hmsPagerDef('crmPagerL', 'Leads', () => crmApply(crm.cache[crmCk()]));
    const pg = hmsPage(crm.leads, _p.st);
    return `<div class="table-container"><table class="crm-table">
        <thead><tr><th>Firma</th><th>Kontakt</th><th>Sparte</th><th>Stage</th><th class="crm-num">Tage in Stage</th><th>Letzte Aktivität</th><th>Nächste Aufgabe</th><th class="crm-num">Versuche</th>${all ? '<th>Owner</th>' : ''}</tr></thead>
        <tbody>${pg.rows.map(r => `
            <tr data-mk="${r.id}" class="crm-row ${r.ueberfaellig ? 'crm-overdue' : ''}" onclick="crmOpenDetail('lead','${r.id}')">
                <td data-label="Firma"><strong>${crmE(r.firma_name || 'Privatkontakt')}</strong>${r.ort ? `<div class="crm-sub">${crmE([r.plz, r.ort].filter(Boolean).join(' '))}</div>` : ''}</td>
                <td data-label="Kontakt">${crmE(r.kontakt_name)}${r.position ? `<div class="crm-sub">${crmE(r.position)}</div>` : ''}</td>
                <td data-label="Sparte">${crmSparte(r.sparte)}</td>
                <td data-label="Stage">${crmStageBadge(r.stage)}${r.prioritaet === 'hoch' ? ` <span class="crm-prio-hoch" title="Priorität hoch">${crmIcon('priority_high', 'font-size:16px')}</span>` : ''}</td>
                <td data-label="Tage in Stage" class="crm-num">${r.tage_in_stage}</td>
                <td data-label="Letzte Aktivität">${r.letzte_aktivitaet_am ? crmUtcDT(r.letzte_aktivitaet_am) : '<span class="crm-muted">keine</span>'}${r.inaktiv ? ` <span class="crm-flag" title="Länger als ${crmE(crm.opt.einstellungen?.a8_inaktiv_tage || 7)} Tage ohne Aktivität (A8)">${crmIcon('alarm', 'font-size:15px')}</span>` : ''}</td>
                <td data-label="Nächste Aufgabe">${r.naechste_aufgabe_am ? `<span class="${r.aufgabe_ueberfaellig ? 'crm-red' : (r.faellig_heute ? 'crm-amber' : '')}">${crmLocalDT(String(r.naechste_aufgabe_am).replace(' 00:00:00', ''))}</span>` : (r.offen ? '<span class="crm-red">keine Aufgabe</span>' : '–')}</td>
                <td data-label="Versuche" class="crm-num">${r.anzahl_kontaktversuche}</td>
                ${all ? `<td data-label="Owner">${crmE(r.owner_name)}</td>` : ''}
            </tr>`).join('')}</tbody></table></div><div id="crmPagerL" class="be-pager">${hmsPagerHtml('crmPagerL', pg, _p.unit)}</div>`;
}

// ============================================
// DETAIL ROUTER
// ============================================
/**
 * Open (or refresh) a detail page. A refresh of the record on screen — after
 * a save, a dialog, a realtime event — patches only what changed (new
 * timeline entry, task, badge …): no blank page, scroll and open sections stay.
 * Opening another record keeps the current view until the data is there
 * (loader only on a slow answer).
 */
async function crmOpenDetail(typ, id, keepScroll = false) {
    const body = document.getElementById('crmBody');
    if (!body) return;
    const key = typ + ':' + id;
    const same = body.dataset.detail === key && crm.detail?.id === id;
    if (!crm.detail) crm.listY = window.scrollY;
    crm.detail = { typ, id, data: same ? crm.detail.data : null }; crm.dirty = false;
    document.getElementById('crmStaleBar')?.classList.remove('show');
    const loader = same ? null : setTimeout(() => {
        if (crm.detail?.id !== id || crm.detail.data) return;
        delete body.dataset.detail; delete body.dataset.frame;
        body.innerHTML = `<div class="crm-loading">${crmIcon('progress_activity')} Laden…</div>`;
    }, 350);
    const url = { lead: '/leads/', deal: '/deals/', kontakt: '/kontakte/', firma: '/firmen/' }[typ];
    const r = await crmGet(CRM_API + url + encodeURIComponent(id));
    clearTimeout(loader);
    if (!crm.detail || crm.detail.id !== id) return;
    if (!r.ok) { showToast(r.data?.error || 'Nicht gefunden', 'error'); return crmBackToList(); }
    crm.detail.data = r.data;
    delete body.dataset.frame;
    crmPaint(body, typ === 'lead' ? crmLeadDetail(r.data) : typ === 'deal' ? crmDealDetail(r.data) : crmStammDetail(typ, r.data));
    body.dataset.detail = key;
    if (!same && !keepScroll) window.scrollTo(0, 0);
}

function crmDetailTop(backLabel, actions = '') {
    return `<div class="crm-detail-top"><md-outlined-button onclick="crmBackToList()"><md-icon slot="icon">arrow_back</md-icon>${backLabel}</md-outlined-button><div class="crm-actions">${actions}</div></div>`;
}

function crmTimeline(list) {
    const ic = { anruf: 'call', email: 'mail', termin: 'event', besichtigung: 'home_work', notiz: 'sticky_note_2' };
    if (!list?.length) return '<div class="crm-muted crm-pad">Noch keine Aktivitäten</div>';
    return `<div class="crm-timeline">${list.map(a => `
        <div class="crm-tl-item" data-mk="a${crmE(a.id)}">
            <div class="crm-tl-icon crm-tl-${crmE(a.typ)}">${crmIcon(ic[a.typ] || 'sticky_note_2')}</div>
            <div class="crm-tl-body">
                <div class="crm-tl-head">
                    <strong>${crmE({ anruf: 'Anruf', email: 'E-Mail', termin: 'Termin', besichtigung: 'Besichtigung', notiz: 'Notiz' }[a.typ] || a.typ)}</strong>
                    ${a.richtung ? `<span class="crm-chip">${a.richtung === 'eingehend' ? '↙ eingehend' : '↗ ausgehend'}</span>` : ''}
                    ${a.ergebnis ? `<span class="crm-chip crm-erg-${crmE(a.ergebnis)}">${crmE(crmName('anruf_ergebnis', a.ergebnis))}</span>` : ''}
                    ${a.deal_id ? '<span class="crm-chip">Deal</span>' : (a.lead_id ? '' : '<span class="crm-chip">Kontakt</span>')}
                    <span class="crm-tl-meta">${crmUtcDT(a.zeitpunkt)} · ${crmE(a.user_name)}</span>
                </div>
                ${a.notiz ? `<div class="crm-tl-text">${crmE(a.notiz).replace(/\n/g, '<br>')}</div>` : ''}
                ${a.hat_datei ? `<a href="#" class="crm-file" onclick="crmDownload('${CRM_API}/aktivitaeten/${a.id}/datei');return false">${crmIcon('attach_file')} ${crmE(a.datei_name || 'Anhang')}</a>` : ''}
            </div>
        </div>`).join('')}</div>`;
}

function crmAufgaben(list, canEdit) {
    if (!list?.length) return '<div class="crm-muted crm-pad">Keine Aufgaben</div>';
    return list.map(t => `
        <div data-mk="t${crmE(t.empfaenger_id ?? t.id)}" class="crm-task ${t.status === 'erledigt' ? 'done' : ''} ${t.ueberfaellig ? 'over' : ''} ${t.heute ? 'today' : ''}">
            ${t.status !== 'erledigt' && (canEdit || t.user_id === crm.opt.ich.id)
                ? `<button class="crm-task-check" title="Erledigt" onclick="crmTaskDone(${t.empfaenger_id})">${crmIcon('radio_button_unchecked')}</button>`
                : `<span class="crm-task-check">${crmIcon(t.status === 'erledigt' ? 'check_circle' : 'radio_button_unchecked')}</span>`}
            <div class="crm-task-main">
                <div><strong>${crmE(t.titel)}</strong> <span class="crm-chip">${crmE(crmName('aufgabe_typ', t.typ))}</span></div>
                <div class="crm-sub">${t.faellig_am ? 'fällig ' + crmDate(t.faellig_am) + (t.faellig_zeit ? ' ' + String(t.faellig_zeit).slice(0, 5) : '') : ''} · ${crmE(t.zugewiesen_name || '')}${t.status === 'erledigt' && t.erledigt_am ? ' · erledigt ' + crmUtcDT(t.erledigt_am) : ''}</div>
            </div>
        </div>`).join('');
}

async function crmTaskDone(empId) {
    const r = await crmReq('PUT', API_BASE + '/aufgaben/empfaenger/' + empId, { status: 'erledigt' });
    if (crmFehler(r)) return;
    showToast('Aufgabe erledigt');
    crmReloadDetail();
}

function crmProtokoll(list) {
    if (!list?.length) return '<div class="crm-muted crm-pad">Keine Einträge</div>';
    const feldName = { stage: 'Lead-Stage', lifecycle_stage: 'Lifecycle', phase: 'Deal-Phase', owner_id: 'Owner', sparte: 'Sparte', disq_grund: 'Disqualifizierungsgrund', kunde_id: 'Kunde', deleted_at: 'Gelöscht' };
    const wert = (feld, v) => {
        if (v == null || v === '') return '–';
        if (feld === 'stage') return crmName('lead_stage', v);
        if (feld === 'lifecycle_stage') return crmName('lifecycle', v);
        if (feld === 'phase') return crmName('deal_phase', v);
        if (feld === 'disq_grund') return crmName('disq_grund', v);
        if (feld === 'owner_id') return (crm.opt.owners || []).find(o => String(o.id) === String(v))?.name || '#' + v;
        return v;
    };
    return `<table class="crm-proto"><tbody>${list.map(p => `<tr>
        <td>${crmUtcDT(p.created_at)}</td><td>${crmE(feldName[p.feld] || p.feld)}</td>
        <td>${crmE(wert(p.feld, p.alt))} → <strong>${crmE(wert(p.feld, p.neu))}</strong></td>
        <td>${crmE(p.user_name || '')}${p.regel ? ` <span class="crm-chip">${crmE(p.regel)}</span>` : ''}</td></tr>`).join('')}</tbody></table>`;
}

// ---------- dialog context: detail page OR a Kanban card (crm.ctx) ----------
function crmC() { return crm.ctx || crm.detail; }
/** Kanban: load the full record for a dialog without leaving the board. */
async function crmCtxLaden(typ, id) {
    const r = await crmGet(CRM_API + (typ === 'lead' ? '/leads/' : '/deals/') + encodeURIComponent(id));
    if (!r.ok) { showToast(r.data?.error || 'Nicht gefunden', 'error'); crm.ctx = null; return false; }
    crm.ctx = { typ, id, data: r.data };
    return true;
}
/** After a dialog: back to the board (Kanban) or reload the detail page. */
function crmNachDialog() {
    if (crm.ctx) { crm.ctx = null; crmLoadView(); } else crmReloadDetail();
}

async function crmDownload(url) {
    const res = await fetch(url, { headers: getAuthHeadersNoContent() });
    if (!res.ok) return showToast('Datei konnte nicht geladen werden', 'error');
    const blob = await res.blob();
    const cd = res.headers.get('content-disposition') || '';
    const m = cd.match(/filename\*=UTF-8''([^;]+)/i) || cd.match(/filename="?([^";]+)"?/i);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = m ? decodeURIComponent(m[1]) : 'Anhang';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 10000);
}

// ============================================
// LEAD-DETAIL (5.2)
// ============================================
function crmLeadDetail(l) {
    const ed = l.darf_bearbeiten, offen = l.offen, k = l.kontakt || {}, f = l.firma;
    const stages = crmList('lead_stage', false).filter(s => s.key !== 'disqualifiziert' || l.stage === 'disqualifiziert');
    const idx = stages.findIndex(s => s.key === l.stage);
    const c = l.checkliste || {};
    const tel = k.telefon || k.mobil;
    const actions = ed && offen ? `
        <md-filled-button onclick="crmAnrufDialog()"><md-icon slot="icon">call</md-icon>Anruf protokollieren</md-filled-button>
        <md-outlined-button onclick="crmAktivitaetDialog('lead','${l.id}','email')"><md-icon slot="icon">mail</md-icon>E-Mail</md-outlined-button>
        <md-outlined-button onclick="crmAktivitaetDialog('lead','${l.id}','notiz')"><md-icon slot="icon">edit_note</md-icon>Aktivität</md-outlined-button>
        <md-outlined-button onclick="crmAufgabeDialog('lead','${l.id}')"><md-icon slot="icon">add_task</md-icon>Aufgabe</md-outlined-button>
        <md-filled-button onclick="crmQualifizierenDialog()"><md-icon slot="icon">verified</md-icon>Qualifizieren</md-filled-button>
        <md-outlined-button class="crm-btn-danger" onclick="crmDisqualifizierenDialog()"><md-icon slot="icon">block</md-icon>Disqualifizieren</md-outlined-button>` : '';
    const checks = [
        ['bedarf', 'Bedarf für eine konkrete Leistung bestätigt'],
        ['entscheider', 'Entscheider oder Weg zum Entscheider bekannt'],
        ['gebiet', `Objekt liegt im Einsatzgebiet (${crmE(crm.opt.einstellungen?.einsatzgebiet || '')})`],
        ['termin', 'Termin für Besichtigung oder Folgegespräch vereinbart'],
    ];
    return `
        ${crmDetailTop('Zur Liste', actions + (crmCan('admin') ? `<md-icon-button class="crm-icon-danger" title="Löschen (Soft-Delete)" onclick="crmDelete('leads','${l.id}')"><md-icon>delete</md-icon></md-icon-button>` : ''))}
        <div class="crm-head">
            <div>
                <div class="crm-head-firma">${f ? `<a href="#" onclick="crmOpenDetail('firma','${f.id}');return false">${crmE(f.name)}</a>` : 'Privatkontakt'}</div>
                <h2 class="crm-head-title"><a href="#" onclick="crmOpenDetail('kontakt','${k.id}');return false">${crmE([k.anrede, k.vorname, k.nachname].filter(Boolean).join(' '))}</a>${k.entscheider ? ' <span class="crm-chip" title="Entscheider">Entscheider</span>' : ''}</h2>
                <div class="crm-head-meta">
                    ${k.position ? `<span>${crmIcon('badge')} ${crmE(k.position)}</span>` : ''}
                    ${tel ? `<a href="tel:${crmE(tel)}">${crmIcon('call')} ${crmE(tel)}</a>` : ''}
                    ${k.mobil && k.telefon ? `<a href="tel:${crmE(k.mobil)}">${crmIcon('smartphone')} ${crmE(k.mobil)}</a>` : ''}
                    ${k.email ? `<a href="mailto:${crmE(k.email)}">${crmIcon('mail')} ${crmE(k.email)}</a>` : ''}
                </div>
            </div>
            <div class="crm-head-side">
                ${crmSparte(l.sparte)}
                <div class="crm-sub">Owner: <strong>${crmE(l.owner_name)}</strong>${crmCan('all') && offen ? ` <a class="crm-link" href="#" onclick="crmOwnerDialog('leads','${l.id}',${l.owner_id});return false">ändern</a>` : ''}</div>
                ${ed && offen ? `<label class="crm-prio-sel"><span>Priorität</span><select class="crm-input crm-input-sm" onchange="crmLeadPatch({prioritaet:this.value})">${crmOptions(crmList('prioritaet'), l.prioritaet || 'normal')}</select></label>` : `<div class="crm-sub">Priorität: ${crmE(crmName('prioritaet', l.prioritaet))}</div>`}
            </div>
        </div>

        <div class="crm-stagebar">${stages.map((s, i) => `<div class="crm-stagebar-step ${i < idx ? 'done' : ''} ${i === idx ? 'current' : ''} ${s.key === 'disqualifiziert' ? 'disq' : ''}">${crmE(s.name)}</div>`).join('')}</div>

        ${l.ueberfaellig ? `<div class="crm-banner crm-banner-red">${crmIcon('alarm')} ${l.inaktiv ? `Seit über ${crmE(crm.opt.einstellungen?.a8_inaktiv_tage || 7)} Tagen keine Aktivität (A8).` : ''} ${l.aufgabe_ueberfaellig ? 'Nächste Aufgabe ist überfällig.' : ''}</div>` : ''}
        ${l.a9_hinweis ? `<div class="crm-banner crm-banner-amber">${crmIcon('phone_disabled')} ${crmE(l.anzahl_kontaktversuche)} Kontaktversuche ohne Erfolg — Disqualifizieren: „nicht erreichbar“? (A9)</div>` : ''}
        ${!offen ? `<div class="crm-banner">${crmIcon('info')} Lead ${crmE(crmName('lead_stage', l.stage))} am ${crmUtcDT(l.stage_changed_at)}${l.disq_grund ? ' — Grund: ' + crmE(crmName('disq_grund', l.disq_grund)) + (l.disq_notiz ? ' (' + crmE(l.disq_notiz) + ')' : '') : ''}${l.wiedervorlage_am ? ' · Wiedervorlage ' + crmDate(l.wiedervorlage_am) : ''}</div>` : ''}

        <div class="crm-grid">
            <div class="crm-main">
                ${l.deal ? `<div class="crm-card crm-deal-link" onclick="crmOpenDetail('deal','${l.deal.id}')">${crmIcon('handshake')} <div><strong>${crmE(l.deal.titel)}</strong><div class="crm-sub">${crmPhaseBadge(l.deal.phase)} · erwarteter Abschluss ${crmDate(l.deal.erwarteter_abschluss)}</div></div>${crmIcon('chevron_right')}</div>` : ''}
                <div class="crm-card">
                    <div class="crm-card-title">${crmIcon('checklist')} Qualifizierungs-Checkliste</div>
                    ${checks.map(([key, label]) => `<label class="crm-check"><md-checkbox ${c[key] ? 'checked' : ''} ${ed && offen ? `onchange="crmLeadPatch({checkliste:{${key}:this.checked}})"` : 'disabled'}></md-checkbox> ${label}</label>`).join('')}
                    <div class="crm-check-date">Termin am <input type="date" class="crm-input crm-input-sm" value="${crmE(c.termin_am || '')}" ${ed && offen ? `onchange="crmLeadPatch({checkliste:{termin_am:this.value||null}})"` : 'disabled'}>
                        <input type="time" class="crm-input crm-input-sm" value="${crmE(c.termin_zeit || '')}" ${ed && offen ? `onchange="crmLeadPatch({checkliste:{termin_zeit:this.value||null}})"` : 'disabled'}></div>
                </div>
                <div class="crm-card"><div class="crm-card-title">${crmIcon('history')} Zeitstrahl <span class="crm-muted">(${l.aktivitaeten.length})</span></div>${crmTimeline(l.aktivitaeten)}</div>
            </div>
            <div class="crm-side">
                <div class="crm-card"><div class="crm-card-title">${crmIcon('task_alt')} Aufgaben ${ed ? `<button class="crm-mini" onclick="crmAufgabeDialog('lead','${l.id}')">${crmIcon('add')}</button>` : ''}</div>${crmAufgaben(l.aufgaben, ed)}</div>
                <div class="crm-card"><div class="crm-card-title">${crmIcon('insights')} Kennzahlen</div>
                    <div class="crm-kv"><span>Stage seit</span><strong>${l.tage_in_stage} Tagen</strong></div>
                    <div class="crm-kv"><span>Kontaktversuche</span><strong>${l.anzahl_kontaktversuche}</strong></div>
                    <div class="crm-kv"><span>Letzte Aktivität</span><strong>${crmUtcDT(l.letzte_aktivitaet_am)}</strong></div>
                    <div class="crm-kv"><span>Quelle</span><strong>${crmE(crmName('quelle', l.quelle))}</strong></div>
                    <div class="crm-kv"><span>Lifecycle</span><strong>${crmE(crmName('lifecycle', l.lifecycle_stage))}</strong></div>
                    <div class="crm-kv"><span>Angelegt</span><strong>${crmUtcDT(l.created_at)}</strong></div>
                </div>
                <div class="crm-card"><div class="crm-card-title">${crmIcon('lan')} Weitere Leads & Deals ${f ? 'der Firma' : ''}</div>
                    ${(l.weitere_leads || []).map(x => `<div class="crm-link-row" onclick="crmOpenDetail('lead','${x.id}')">${crmSparte(x.sparte)} ${crmStageBadge(x.stage)} <span>${crmE(x.kontakt_name)}</span></div>`).join('')}
                    ${(l.weitere_deals || []).filter(d => d.id !== l.deal_id).map(d => `<div class="crm-link-row" onclick="crmOpenDetail('deal','${d.id}')">${crmPhaseBadge(d.phase)} <span>${crmE(d.titel)}</span></div>`).join('')}
                    ${!(l.weitere_leads || []).length && !(l.weitere_deals || []).filter(d => d.id !== l.deal_id).length ? '<div class="crm-muted crm-pad">Keine</div>' : ''}
                </div>
                <details class="crm-card"><summary class="crm-card-title">${crmIcon('receipt_long')} Änderungsprotokoll</summary>${crmProtokoll(l.protokoll)}</details>
            </div>
        </div>`;
}

async function crmLeadPatch(patch) {
    const id = crm.detail.id;
    const r = await crmReq('PUT', CRM_API + '/leads/' + id, patch);
    if (crmFehler(r)) return crmReloadDetail();
    showToast('Gespeichert');
    if (patch.prioritaet) crmReloadDetail();
}

// ---------- Anruf protokollieren (5.3) ----------
function crmFolgeFelder(defTitel) {
    return `
        <div class="crm-sec">Nächster Schritt</div>
        <div class="crm-radio-row" data-f="folge">
            <label><md-radio name="crm_folge" value="aufgabe" checked onchange="crmFolgeToggle()"></md-radio> Aufgabe anlegen</label>
            <label><md-radio name="crm_folge" value="qualifizieren" onchange="crmFolgeToggle()"></md-radio> Danach qualifizieren</label>
            <label><md-radio name="crm_folge" value="disqualifizieren" onchange="crmFolgeToggle()"></md-radio> Danach disqualifizieren</label>
        </div>
        <div id="crmFolgeAufgabe" class="crm-form-grid">
            <label class="crm-field">Typ<select id="cf_a_typ" class="crm-input">${crmOptions(crmList('aufgabe_typ'), 'anruf')}</select></label>
            <label class="crm-field crm-span2">Titel<input id="cf_a_titel" class="crm-input" data-f="aufgabe.titel" maxlength="200" value="${crmE(defTitel)}"></label>
            <label class="crm-field">Fällig am<input id="cf_a_datum" type="date" class="crm-input" data-f="aufgabe.faellig_am" value="${crmAddDays(2)}"></label>
            <label class="crm-field">Uhrzeit<input id="cf_a_zeit" type="time" class="crm-input"></label>
        </div>`;
}
function crmFolgeToggle() {
    const v = document.querySelector('md-radio[name="crm_folge"][checked], input[name="crm_folge"]:checked')?.value;
    const box = document.getElementById('crmFolgeAufgabe');
    if (box) box.style.display = v === 'aufgabe' ? '' : 'none';
}
function crmFolgeDaten(fd) {
    const folge = document.querySelector('md-radio[name="crm_folge"][checked], input[name="crm_folge"]:checked')?.value || 'keine';
    fd.append('folge', folge);
    if (folge === 'aufgabe') fd.append('aufgabe', JSON.stringify({ typ: crmV('cf_a_typ'), titel: crmV('cf_a_titel'), faellig_am: crmV('cf_a_datum'), faellig_zeit: crmV('cf_a_zeit') || null }));
    return folge;
}

function crmAnrufDialog(vorErg = '') {
    const l = crmC().data, k = l.kontakt || {};
    const erg = crmList('anruf_ergebnis');
    openModal('Anruf protokollieren — ' + [k.vorname, k.nachname].filter(Boolean).join(' '), `
        <div class="crm-form">
            <div class="crm-radio-row"><label><md-radio name="crm_richtung" value="ausgehend" checked></md-radio> ↗ ausgehend</label><label><md-radio name="crm_richtung" value="eingehend"></md-radio> ↙ eingehend</label></div>
            <div class="crm-sec">Ergebnis *</div>
            <div class="crm-erg-row" data-f="ergebnis">${erg.map(e => `<label class="crm-erg-pill"><md-radio name="crm_erg" value="${crmE(e.key)}" ${e.key === vorErg ? 'checked' : ''}></md-radio> ${crmE(e.name)}</label>`).join('')}</div>
            <label class="crm-field">Notiz<textarea id="cf_notiz" class="crm-input" rows="3" maxlength="5000"></textarea></label>
            <label class="crm-field">Zeitpunkt<input id="cf_zeit" type="datetime-local" class="crm-input" value="${crmNowLocal()}"></label>
            ${crmFolgeFelder('Nachfassen: ' + [k.vorname, k.nachname].filter(Boolean).join(' '))}
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  id="crmSaveBtn" onclick="crmAnrufSpeichern()">${crmIcon('save')} Speichern</md-filled-button>`);
}

async function crmAnrufSpeichern() {
    const l = crmC().data;
    const fd = new FormData();
    fd.append('bezug_typ', 'lead'); fd.append('bezug_id', l.id); fd.append('typ', 'anruf');
    fd.append('richtung', document.querySelector('md-radio[name="crm_richtung"][checked], input[name="crm_richtung"]:checked')?.value || 'ausgehend');
    const erg = document.querySelector('md-radio[name="crm_erg"][checked], input[name="crm_erg"]:checked')?.value;
    if (!erg) return showToast('Ergebnis ist Pflicht', 'error');
    fd.append('ergebnis', erg);
    fd.append('notiz', crmV('cf_notiz'));
    fd.append('zeitpunkt', crmV('cf_zeit'));
    const folge = crmFolgeDaten(fd);
    const btn = document.getElementById('crmSaveBtn'); if (btn) btn.disabled = true;
    const r = await crmReq('POST', CRM_API + '/aktivitaeten', fd);
    if (btn) btn.disabled = false;
    if (crmFehler(r)) return;
    closeModal();
    const st = r.data.stage && r.data.stage !== l.stage ? ' — Stage: ' + crmName('lead_stage', r.data.stage) : '';
    showToast('Anruf gespeichert' + st);
    if (crm.ctx) await crmCtxLaden('lead', l.id); else await crmOpenDetail('lead', l.id, true);
    if (folge === 'qualifizieren') crmQualifizierenDialog();
    else if (folge === 'disqualifizieren') crmDisqualifizierenDialog();
    else crmNachDialog();
}

// ---------- Aktivität (E-Mail, Termin, Besichtigung, Notiz) ----------
function crmAktivitaetDialog(bezugTyp, bezugId, typ = 'notiz') {
    const istLead = bezugTyp === 'lead';
    openModal('Aktivität erfassen', `
        <div class="crm-form">
            <div class="crm-form-grid">
                <label class="crm-field">Typ<select id="cf_typ" class="crm-input" onchange="document.getElementById('cfRichtung').style.display=this.value==='email'?'':'none'">
                    ${crmOptions([{ key: 'email', name: 'E-Mail' }, { key: 'termin', name: 'Termin' }, { key: 'besichtigung', name: 'Besichtigung' }, { key: 'notiz', name: 'Notiz' }], typ)}</select></label>
                <label class="crm-field">Zeitpunkt<input id="cf_zeit" type="datetime-local" class="crm-input" value="${crmNowLocal()}" data-f="zeitpunkt"></label>
            </div>
            <div class="crm-radio-row" id="cfRichtung" style="${typ === 'email' ? '' : 'display:none'}" data-f="richtung"><label><md-radio name="crm_richtung" value="ausgehend" checked></md-radio> ↗ ausgehend</label><label><md-radio name="crm_richtung" value="eingehend"></md-radio> ↙ eingehend (Antwort)</label></div>
            <label class="crm-field">Notiz<textarea id="cf_notiz" class="crm-input" rows="4" maxlength="5000" data-f="notiz"></textarea></label>
            <label class="crm-field">Anhang (optional, max. 10 MB)<input id="cf_datei" type="file" class="crm-input" data-f="datei" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx"></label>
            <label class="crm-check"><md-checkbox id="cf_mit_aufgabe" onchange="document.getElementById('crmFolgeAufgabe').style.display=this.checked?'':'none'"></md-checkbox> Folgeaufgabe anlegen</label>
            <div id="crmFolgeAufgabe" class="crm-form-grid" style="display:none">
                <label class="crm-field">Typ<select id="cf_a_typ" class="crm-input">${crmOptions(crmList('aufgabe_typ'), 'anruf')}</select></label>
                <label class="crm-field crm-span2">Titel<input id="cf_a_titel" class="crm-input" data-f="aufgabe.titel" maxlength="200" value="Nachfassen"></label>
                <label class="crm-field">Fällig am<input id="cf_a_datum" type="date" class="crm-input" data-f="aufgabe.faellig_am" value="${crmAddDays(2)}"></label>
                <label class="crm-field">Uhrzeit<input id="cf_a_zeit" type="time" class="crm-input"></label>
            </div>
            ${istLead ? '<div class="crm-muted">Ausgehende E-Mails zählen als Kontaktversuch (A4), eingehende setzen den Lead auf „Verbunden“ (A5).</div>' : ''}
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  id="crmSaveBtn" onclick="crmAktivitaetSpeichern('${bezugTyp}','${bezugId}')">${crmIcon('save')} Speichern</md-filled-button>`);
}

async function crmAktivitaetSpeichern(bezugTyp, bezugId) {
    const fd = new FormData();
    const typ = crmV('cf_typ');
    fd.append('bezug_typ', bezugTyp); fd.append('bezug_id', bezugId); fd.append('typ', typ);
    if (typ === 'email') fd.append('richtung', document.querySelector('md-radio[name="crm_richtung"][checked], input[name="crm_richtung"]:checked')?.value || 'ausgehend');
    fd.append('notiz', crmV('cf_notiz')); fd.append('zeitpunkt', crmV('cf_zeit'));
    const file = document.getElementById('cf_datei')?.files?.[0];
    if (file) fd.append('datei', file);
    if (crmV('cf_mit_aufgabe')) {
        fd.append('folge', 'aufgabe');
        fd.append('aufgabe', JSON.stringify({ typ: crmV('cf_a_typ'), titel: crmV('cf_a_titel'), faellig_am: crmV('cf_a_datum'), faellig_zeit: crmV('cf_a_zeit') || null }));
    }
    const btn = document.getElementById('crmSaveBtn'); if (btn) btn.disabled = true;
    const r = await crmReq('POST', CRM_API + '/aktivitaeten', fd);
    if (btn) btn.disabled = false;
    if (crmFehler(r)) return;
    closeModal(); showToast('Aktivität gespeichert');
    crmReloadDetail();
}

// ---------- Aufgabe ----------
function crmAufgabeDialog(bezugTyp, bezugId) {
    const d = crm.detail?.data || {};
    openModal('Aufgabe anlegen', `
        <div class="crm-form crm-form-grid">
            <label class="crm-field">Typ<select id="cf_a_typ" class="crm-input" data-f="typ">${crmOptions(crmList('aufgabe_typ'), 'anruf')}</select></label>
            <label class="crm-field crm-span2">Titel *<input id="cf_a_titel" class="crm-input" data-f="titel" maxlength="200"></label>
            <label class="crm-field">Fällig am *<input id="cf_a_datum" type="date" class="crm-input" data-f="faellig_am" value="${crmToday()}"></label>
            <label class="crm-field">Uhrzeit<input id="cf_a_zeit" type="time" class="crm-input" data-f="faellig_zeit"></label>
            <label class="crm-field">Zugewiesen an<select id="cf_a_an" class="crm-input" data-f="zugewiesen_an">${crmOptions(crm.opt.owners || [], d.owner_id || crm.opt.ich.id)}</select></label>
            <label class="crm-field crm-span3">Beschreibung<textarea id="cf_a_text" class="crm-input" rows="3" maxlength="5000"></textarea></label>
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  onclick="crmAufgabeSpeichern('${bezugTyp}','${bezugId}')">${crmIcon('save')} Anlegen</md-filled-button>`);
}
async function crmAufgabeSpeichern(bezugTyp, bezugId) {
    const r = await crmReq('POST', CRM_API + '/aufgaben', {
        bezug_typ: bezugTyp, bezug_id: bezugId, typ: crmV('cf_a_typ'), titel: crmV('cf_a_titel'),
        faellig_am: crmV('cf_a_datum'), faellig_zeit: crmV('cf_a_zeit') || null, zugewiesen_an: crmV('cf_a_an') || null, beschreibung: crmV('cf_a_text') || null,
    });
    if (crmFehler(r)) return;
    closeModal(); showToast('Aufgabe angelegt'); crmReloadDetail();
}

// ---------- Qualifizieren (A6) ----------
function crmQualifizierenDialog() {
    const l = crmC().data, c = l.checkliste || {}, f = l.firma || {};
    if (!l.sparte) return showToast('Vor dem Qualifizieren eine Sparte setzen.', 'error');
    const adr = [f.strasse, [f.plz, f.ort].filter(Boolean).join(' ')].filter(Boolean).join(', ');
    const termin = c.termin_am ? c.termin_am + 'T' + (c.termin_zeit || '10:00') : '';
    const checks = [['bedarf', 'Bedarf für eine konkrete Leistung bestätigt'], ['entscheider', 'Entscheider oder Weg zum Entscheider bekannt'],
        ['gebiet', `Objekt liegt im Einsatzgebiet (${crmE(crm.opt.einstellungen?.einsatzgebiet || '')})`], ['termin', 'Termin für Besichtigung oder Folgegespräch vereinbart']];
    openModal('Qualifizieren → Deal anlegen', `
        <div class="crm-form">
            <div class="crm-sec">Checkliste (alle Pflicht)</div>
            ${checks.map(([k2, lab]) => `<label class="crm-check" data-f="checkliste.${k2}"><md-checkbox id="cq_${k2}" ${c[k2] ? 'checked' : ''}></md-checkbox> ${lab}</label>`).join('')}
            <div class="crm-form-grid">
                <label class="crm-field">Termin am *<input id="cq_termin_am" type="date" class="crm-input" data-f="checkliste.termin_am" value="${crmE(c.termin_am || '')}" onchange="crmQSync()"></label>
                <label class="crm-field">Uhrzeit<input id="cq_termin_zeit" type="time" class="crm-input" value="${crmE(c.termin_zeit || '')}" onchange="crmQSync()"></label>
            </div>
            <div class="crm-sec">Deal (Phase „Besichtigung geplant“)</div>
            <div class="crm-form-grid">
                <label class="crm-field crm-span3">Titel *<input id="cq_titel" class="crm-input" data-f="deal.titel" maxlength="250" value="${crmE([f.name || [l.vorname, l.nachname].filter(Boolean).join(' '), l.sparte, f.ort].filter(Boolean).join(' – '))}"></label>
                <label class="crm-field crm-span3">Objektadresse *<input id="cq_adresse" class="crm-input" data-f="deal.objektadresse" maxlength="300" value="${crmE(adr)}"></label>
                <label class="crm-field">Besichtigung am *<input id="cq_besichtigung" type="datetime-local" class="crm-input" data-f="deal.besichtigung_am" value="${crmE(termin)}"></label>
                <label class="crm-field">Erwarteter Abschluss *<input id="cq_abschluss" type="date" class="crm-input" data-f="deal.erwarteter_abschluss" value="${crmAddDays(30)}"></label>
            </div>
            <div class="crm-muted">Lead → Qualifiziert, Lifecycle → Opportunity, der Termin wird als Aufgabe angelegt. In der Lead-Phase keine Preise/Angebote.</div>
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  class="md-btn-success" id="crmSaveBtn" onclick="crmQualifizieren()">${crmIcon('verified')} Qualifizieren</md-filled-button>`);
}
function crmQSync() {
    const d = crmV('cq_termin_am'), t = crmV('cq_termin_zeit') || '10:00';
    const b = document.getElementById('cq_besichtigung');
    if (b && d) b.value = d + 'T' + t;
}
async function crmQualifizieren() {
    const l = crmC().data;
    const btn = document.getElementById('crmSaveBtn'); if (btn) btn.disabled = true;
    const r = await crmReq('POST', CRM_API + '/leads/' + l.id + '/qualifizieren', {
        checkliste: { bedarf: crmV('cq_bedarf'), entscheider: crmV('cq_entscheider'), gebiet: crmV('cq_gebiet'), termin: crmV('cq_termin'), termin_am: crmV('cq_termin_am'), termin_zeit: crmV('cq_termin_zeit') || null },
        deal: { titel: crmV('cq_titel'), objektadresse: crmV('cq_adresse'), besichtigung_am: crmV('cq_besichtigung'), erwarteter_abschluss: crmV('cq_abschluss') },
    });
    if (btn) btn.disabled = false;
    if (crmFehler(r)) return;
    closeModal(); showToast('Qualifiziert — Deal angelegt');
    if (crm.ctx) crmNachDialog(); else crmOpenDetail('deal', r.data.deal_id);
}

// ---------- Disqualifizieren (A7 / A9) ----------
function crmDisqualifizierenDialog() {
    const l = crmC().data;
    const gruende = crmList('disq_grund');
    const n = crm.opt.einstellungen?.a9_versuche || 6;
    openModal('Disqualifizieren', `
        <div class="crm-form">
            <label class="crm-field">Grund *<select id="cd_grund" class="crm-input" data-f="grund" onchange="crmDisqToggle()">
                <option value="">— wählen —</option>
                ${gruende.map(g => {
                    const gesperrt = g.zusatz?.pflicht === 'min_versuche' && !l.nicht_erreichbar_erlaubt;
                    return `<option value="${crmE(g.key)}" data-pflicht="${crmE(g.zusatz?.pflicht || '')}" ${gesperrt ? 'disabled' : ''}>${crmE(g.name)}${gesperrt ? ` (erst ab ${n} Versuchen)` : ''}</option>`;
                }).join('')}</select></label>
            <label class="crm-field" id="cdWv" style="display:none">Wiedervorlage (Vertragsende) *<input id="cd_wv" type="date" class="crm-input" data-f="wiedervorlage_am" min="${crmAddDays(1)}">
                <span class="crm-muted">Aufgabe „Wiedervorlage“ wird ${crmE(crm.opt.einstellungen?.a7_vorlauf_tage || 90)} Tage vorher fällig (A7).</span></label>
            <div id="cdDup" style="display:none">
                <label class="crm-field">Original-Lead suchen *<input class="crm-input" placeholder="Firma oder Name…" oninput="crmDupSuche(this.value)"></label>
                <select id="cd_dup" class="crm-input" data-f="duplikat_von" size="5">${(l.weitere_leads || []).map(x => `<option value="${x.id}">${crmE((x.firma_name || '') + ' · ' + x.kontakt_name + ' · ' + (x.sparte || ''))}</option>`).join('')}</select>
            </div>
            <label class="crm-field">Notiz <span id="cdNotizPflicht" class="crm-muted"></span><textarea id="cd_notiz" class="crm-input" rows="3" maxlength="5000" data-f="notiz"></textarea></label>
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  class="md-btn-danger" id="crmSaveBtn" onclick="crmDisqualifizieren()">${crmIcon('block')} Disqualifizieren</md-filled-button>`);
}
function crmDisqToggle() {
    const p = document.getElementById('cd_grund')?.selectedOptions?.[0]?.dataset?.pflicht || '';
    document.getElementById('cdWv').style.display = p === 'wiedervorlage' ? '' : 'none';
    document.getElementById('cdDup').style.display = p === 'original' ? '' : 'none';
    document.getElementById('cdNotizPflicht').textContent = p === 'notiz' ? '(Pflicht)' : '';
}
let _crmDupT = null;
function crmDupSuche(q) {
    clearTimeout(_crmDupT);
    _crmDupT = setTimeout(async () => {
        if (q.trim().length < 2) return;
        const r = await crmGet(CRM_API + '/leads' + crmQs({ tab: 'alle', stage: 'alle', q }));
        const sel = document.getElementById('cd_dup');
        if (!r.ok || !sel) return;
        sel.innerHTML = (r.data.data || []).filter(x => x.id !== crmC().id).map(x => `<option value="${x.id}">${crmE((x.firma_name || '') + ' · ' + x.kontakt_name + ' · ' + (x.sparte || '') + ' · ' + crmName('lead_stage', x.stage))}</option>`).join('');
    }, 300);
}
async function crmDisqualifizieren() {
    const btn = document.getElementById('crmSaveBtn'); if (btn) btn.disabled = true;
    const r = await crmReq('POST', CRM_API + '/leads/' + crmC().id + '/disqualifizieren', {
        grund: crmV('cd_grund'), notiz: crmV('cd_notiz') || null, wiedervorlage_am: crmV('cd_wv') || null, duplikat_von: crmV('cd_dup') || null,
    });
    if (btn) btn.disabled = false;
    if (crmFehler(r)) return;
    closeModal(); showToast('Lead disqualifiziert'); crmNachDialog();
}

// ---------- Owner umverteilen (Vertriebsleitung) ----------
function crmOwnerDialog(art, id, cur) {
    openModal('Owner umverteilen', `<label class="crm-field">Neuer Owner<select id="co_owner" class="crm-input" data-f="owner_id">${crmOptions(crm.opt.owners || [], cur)}</select></label>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  onclick="crmOwnerSpeichern('${art}','${id}')">Speichern</md-filled-button>`);
}
async function crmOwnerSpeichern(art, id) {
    const r = await crmReq('PUT', CRM_API + '/' + art + '/' + id, { owner_id: crmV('co_owner') });
    if (crmFehler(r)) return;
    closeModal(); showToast('Owner geändert'); crmReloadDetail();
}

async function crmDelete(art, id) {
    if (!await mdConfirm('Datensatz löschen? (Soft-Delete — bleibt für Reporting/Protokoll erhalten)', { title: 'Löschen', confirmLabel: 'Löschen' })) return;
    const r = await crmReq('DELETE', CRM_API + '/' + art + '/' + id);
    if (crmFehler(r)) return;
    showToast('Gelöscht'); crmCacheDrop(id); crmBackToList();
}

// ============================================
// DEALS (2.4, 3.3, 5.4)
// ============================================
function crmDealsFrame() {
    const f = crm.df;
    const chips = [['offen', 'Offen'], ['gewonnen', 'Gewonnen'], ['verloren', 'Verloren'], ['alle', 'Alle']];
    return `
        <div class="data-bento" id="crmBento">${crmDealBento()}</div>
        <div class="data-toolbar">
            <div class="data-toolbar-left">
                ${crmMdSearch('df', 'Deals suchen', 'Titel, Firma, Kontakt…')}
                <md-chip-set>${chips.map(([k, l]) => `<md-filter-chip label="${l}" ${f.status === k && !f.phase ? 'selected' : ''} onclick="crmDealStatus('${k}')"></md-filter-chip>`).join('')}</md-chip-set>
                ${crmMdFilter('df', 'sparte', 'Sparte', crm.opt.sparten || [], 'Alle Sparten')}
                ${crmCan('all') ? crmMdFilter('df', 'owner', 'Owner', crm.opt.owners || [], 'Alle Owner') : ''}
                <md-outlined-text-field class="crm-date" type="date" label="Abschluss ab" value="${crmE(f.von)}" data-scope="df" data-key="von"></md-outlined-text-field>
                <md-outlined-text-field class="crm-date" type="date" label="Abschluss bis" value="${crmE(f.bis)}" data-scope="df" data-key="bis"></md-outlined-text-field>
            </div>
            ${crmToolbarActions(crmModusToggle('deals'))}
        </div>
        <div class="data-table-card"><div id="crmRows"></div></div>`;
}
function crmDealStatus(status, phase = '') { crm.df.status = status; crm.df.phase = phase; crmRenderView(); }

function crmDealBento() {
    const k = crm.kpi || {}, f = crm.df;
    return [
        crmTile({ hero: true, cls: 'bento-tile-total', on: f.status === 'offen' && !f.phase, icon: 'handshake', label: 'Offene Pipeline', n: k.offen ?? '–', click: "crmDealStatus('offen')",
            foot: crmPill('payments', 'Σ ' + crmMoney(k.offen_eur ?? 0)) + crmPill('balance', 'gewichtet ' + crmMoney(k.gewichtet_eur ?? 0)) }),
        crmTile({ cls: 'bento-tile-active', on: f.phase === 'besichtigung_geplant,besichtigung_erfolgt', icon: 'home_work', label: 'Besichtigung', n: k.besichtigung ?? '–',
            click: "crmDealStatus('alle','besichtigung_geplant,besichtigung_erfolgt')", title: 'Besichtigung geplant / erfolgt' }),
        crmTile({ cls: 'crm-tile-amber', on: f.phase === 'angebot_versendet,verhandlung', icon: 'request_quote', label: 'Angebot & Verhandlung', n: k.angebot ?? '–',
            meta: 'Σ ' + crmMoney(k.angebot_eur ?? 0), click: "crmDealStatus('alle','angebot_versendet,verhandlung')" }),
        crmTile({ cls: 'crm-tile-green', on: f.status === 'gewonnen' && !f.phase, icon: 'emoji_events', label: 'Gewonnen', n: k.gewonnen ?? '–',
            meta: 'Σ ' + crmMoney(k.gewonnen_eur ?? 0) + ` · ${k.verloren ?? 0} verloren`, click: "crmDealStatus('gewonnen')" }),
    ].join('');
}

function crmDealRows() {
    const _p = hmsPagerDef('crmPagerD', 'Deals', () => crmApply(crm.cache[crmCk()]));
    const pg = hmsPage(crm.deals, _p.st);
    const sums = crm.summen.filter(s => s.anzahl > 0);
    const strip = sums.length > 1 ? `<div class="crm-phase-strip">${sums.map(s => `
        <div class="crm-phase-sum">${crmPhaseBadge(s.phase)}<span><strong>${s.anzahl}</strong> · ${crmMoney(s.gesamtwert_eur)}</span><span class="crm-sub">gewichtet ${crmMoney(s.gewichtet_eur)}</span></div>`).join('')}</div>` : '';
    if (!crm.deals.length) return `<div class="empty-state">${crmIcon('handshake')}<p>Keine Deals in dieser Ansicht</p></div>`;
    return strip + `<div class="table-container"><table class="crm-table">
        <thead><tr><th>Titel</th><th>Firma / Kontakt</th><th>Sparte</th><th>Phase</th><th class="crm-num">Monatlich</th><th class="crm-num">Gesamtwert</th><th class="crm-num">%</th><th>Erw. Abschluss</th><th>Owner</th></tr></thead>
        <tbody>${pg.rows.map(d => `
            <tr data-mk="${d.id}" class="crm-row ${d.kategorie === 'offen' && d.erwarteter_abschluss && d.erwarteter_abschluss < crmToday() ? 'crm-overdue' : ''}" onclick="crmOpenDetail('deal','${d.id}')">
                <td data-label="Titel"><strong>${crmE(d.titel)}</strong></td>
                <td data-label="Firma">${crmE(d.firma_name || 'Privatkontakt')}<div class="crm-sub">${crmE(d.kontakt_name)}</div></td>
                <td data-label="Sparte">${crmSparte(d.sparte)}</td>
                <td data-label="Phase">${crmPhaseBadge(d.phase)}</td>
                <td data-label="Monatlich" class="crm-num">${crmMoney(d.wert_monatlich_eur)}</td>
                <td data-label="Gesamtwert" class="crm-num">${crmMoney(d.gesamtwert_eur)}</td>
                <td data-label="%" class="crm-num">${d.wahrscheinlichkeit}%</td>
                <td data-label="Erw. Abschluss">${crmDate(d.erwarteter_abschluss)}</td>
                <td data-label="Owner">${crmE(d.owner_name)}</td>
            </tr>`).join('')}</tbody></table></div><div id="crmPagerD" class="be-pager">${hmsPagerHtml('crmPagerD', pg, _p.unit)}</div>`;
}

function crmDealDetail(d) {
    const ed = d.darf_bearbeiten, offen = d.kategorie === 'offen';
    const phasen = crmList('deal_phase');
    const idx = phasen.findIndex(p => p.key === d.phase);
    const k = d.kontakt || {}, f = d.firma;
    const kannPhase = ed && (offen || d.darf_wiedereroeffnen);
    const fe = (id, label, val, type = 'text', extra = '') => `<label class="crm-field">${label}<input id="${id}" type="${type}" class="crm-input" data-f="${id.replace('dd_', '')}" value="${crmE(val ?? '')}" ${ed && offen ? 'oninput="crm.dirty=true"' : 'disabled'} ${extra}></label>`;
    return `
        ${crmDetailTop('Zur Liste', (ed ? `<md-outlined-button onclick="crmAktivitaetDialog('deal','${d.id}','notiz')"><md-icon slot="icon">edit_note</md-icon>Aktivität</md-outlined-button>
            <md-outlined-button onclick="crmAufgabeDialog('deal','${d.id}')"><md-icon slot="icon">add_task</md-icon>Aufgabe</md-outlined-button>` : '') +
            (crmCan('admin') ? `<md-icon-button class="crm-icon-danger" title="Löschen (Soft-Delete)" onclick="crmDelete('deals','${d.id}')"><md-icon>delete</md-icon></md-icon-button>` : ''))}
        <div class="crm-head">
            <div>
                <div class="crm-head-firma">${f ? `<a href="#" onclick="crmOpenDetail('firma','${f.id}');return false">${crmE(f.name)}</a>` : 'Privatkontakt'} · <a href="#" onclick="crmOpenDetail('kontakt','${k.id}');return false">${crmE([k.vorname, k.nachname].filter(Boolean).join(' '))}</a>${d.lead_id ? ` · <a href="#" onclick="crmOpenDetail('lead','${d.lead_id}');return false">Lead</a>` : ' · Zusatzauftrag'}</div>
                <h2 class="crm-head-title">${crmE(d.titel)}</h2>
                <div class="crm-head-meta"><span>${crmSparte(d.sparte)}</span><span>Owner: <strong>${crmE(d.owner_name)}</strong>${crmCan('all') && offen ? ` <a class="crm-link" href="#" onclick="crmOwnerDialog('deals','${d.id}',${d.owner_id});return false">ändern</a>` : ''}</span>
                ${d.kunde_id ? `<span>${crmIcon('verified_user')} Kunde ${crmE(d.kunde_id)}</span>` : ''}</div>
            </div>
            <div class="crm-head-side crm-money">
                <div><span class="crm-sub">Gesamtwert</span><strong>${crmMoney(d.gesamtwert_eur)}</strong></div>
                <div><span class="crm-sub">gewichtet (${d.wahrscheinlichkeit}%)</span><strong>${crmMoney(d.gewichtet_eur)}</strong></div>
            </div>
        </div>
        <div class="crm-stagebar crm-phasebar">${phasen.map((p, i) => `<button class="crm-stagebar-step ${i < idx && p.kategorie === 'offen' ? 'done' : ''} ${i === idx ? 'current' : ''} ${p.key === 'verloren' ? 'disq' : ''} ${p.key === 'gewonnen' ? 'won' : ''}" ${kannPhase && i !== idx ? `onclick="crmPhaseDialog('${p.key}')"` : 'disabled'}>${crmE(p.name)}<small>${p.wahrscheinlichkeit ?? ''}%</small></button>`).join('')}</div>
        ${!offen ? `<div class="crm-banner">${crmIcon(d.phase === 'gewonnen' ? 'emoji_events' : 'info')} ${crmE(d.phase_name)} am ${crmUtcDT(d.phase_changed_at)}${d.verlust_grund ? ' — ' + crmE(crmName('verlust_grund', d.verlust_grund)) + (d.verlust_notiz ? ' (' + crmE(d.verlust_notiz) + ')' : '') : ''}${d.vertragsbeginn ? ' · Vertragsbeginn ' + crmDate(d.vertragsbeginn) : ''}</div>` : ''}
        <div class="crm-grid">
            <div class="crm-main">
                <div class="crm-card"><div class="crm-card-title">${crmIcon('description')} Deal-Daten</div>
                    <div class="crm-form-grid">
                        ${fe('dd_titel', 'Titel', d.titel, 'text', 'maxlength="250"')}
                        ${fe('dd_objektadresse', 'Objektadresse', d.objektadresse, 'text', 'maxlength="300"')}
                        ${fe('dd_besichtigung_am', 'Besichtigung am', d.besichtigung_am ? String(d.besichtigung_am).slice(0, 16).replace(' ', 'T') : '', 'datetime-local')}
                        ${fe('dd_erwarteter_abschluss', 'Erwarteter Abschluss', d.erwarteter_abschluss, 'date')}
                        ${fe('dd_wert_monatlich_eur', 'Wert monatlich (€)', d.wert_monatlich_eur, 'number', 'min="0" step="0.01"')}
                        ${fe('dd_laufzeit_monate', 'Laufzeit (Monate)', d.laufzeit_monate, 'number', 'min="1" step="1"')}
                        ${fe('dd_vertragsbeginn', 'Vertragsbeginn', d.vertragsbeginn, 'date')}
                        <label class="crm-field crm-span3">Besichtigungsnotiz<textarea id="dd_besichtigung_notiz" class="crm-input" rows="3" data-f="besichtigung_notiz" ${ed && offen ? 'oninput="crm.dirty=true"' : 'disabled'}>${crmE(d.besichtigung_notiz || '')}</textarea></label>
                    </div>
                    <div class="crm-angebot">${crmIcon('request_quote')} Angebot:
                        ${d.hat_angebot ? `<a href="#" onclick="crmDownload('${CRM_API}/deals/${d.id}/angebot');return false">${crmE(d.angebot_datei_name || 'Dokument')}</a>` : '<span class="crm-muted">kein Dokument</span>'}
                        ${ed && offen ? `<md-text-button onclick="this.nextElementSibling.click()"><md-icon slot="icon">upload</md-icon>${d.hat_angebot ? 'Ersetzen' : 'Hochladen'}</md-text-button><input type="file" hidden accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" onchange="crmAngebotUpload(this)">` : ''}
                    </div>
                    ${ed && offen ? `<div class="crm-form-actions"><md-filled-button onclick="crmDealSpeichern()"><md-icon slot="icon">save</md-icon>Speichern</md-filled-button></div>` : ''}
                </div>
                <div class="crm-card"><div class="crm-card-title">${crmIcon('history')} Zeitstrahl</div>${crmTimeline(d.aktivitaeten)}</div>
            </div>
            <div class="crm-side">
                <div class="crm-card"><div class="crm-card-title">${crmIcon('task_alt')} Aufgaben ${ed ? `<button class="crm-mini" onclick="crmAufgabeDialog('deal','${d.id}')">${crmIcon('add')}</button>` : ''}</div>${crmAufgaben(d.aufgaben, ed)}</div>
                <div class="crm-card"><div class="crm-card-title">${crmIcon('contact_phone')} Kontakt</div>
                    <div class="crm-kv"><span>Name</span><strong>${crmE([k.anrede, k.vorname, k.nachname].filter(Boolean).join(' '))}</strong></div>
                    ${k.telefon ? `<div class="crm-kv"><span>Telefon</span><a href="tel:${crmE(k.telefon)}">${crmE(k.telefon)}</a></div>` : ''}
                    ${k.email ? `<div class="crm-kv"><span>E-Mail</span><a href="mailto:${crmE(k.email)}">${crmE(k.email)}</a></div>` : ''}
                </div>
                <details class="crm-card"><summary class="crm-card-title">${crmIcon('receipt_long')} Änderungsprotokoll</summary>${crmProtokoll(d.protokoll)}</details>
            </div>
        </div>`;
}

function crmDealFelder() {
    const o = {};
    ['titel', 'objektadresse', 'besichtigung_am', 'erwarteter_abschluss', 'wert_monatlich_eur', 'laufzeit_monate', 'vertragsbeginn', 'besichtigung_notiz']
        .forEach(k => { const v = crmV('dd_' + k); if (v !== undefined) o[k] = v === '' ? null : v; });
    return o;
}
async function crmDealSpeichern() {
    const r = await crmReq('PUT', CRM_API + '/deals/' + crm.detail.id, crmDealFelder());
    if (crmFehler(r)) return;
    crm.dirty = false; showToast('Deal gespeichert'); crmReloadDetail();
}
async function crmAngebotUpload(input) {
    const file = input.files?.[0]; if (!file) return;
    const fd = new FormData(); fd.append('datei', file);
    const r = await crmReq('POST', CRM_API + '/deals/' + crm.detail.id + '/angebot', fd);
    if (crmFehler(r, 'Upload fehlgeschlagen')) return;
    showToast('Angebot hochgeladen'); crmReloadDetail();
}

/** Phase change: ask exactly for the required fields of every phase up to the target (3.3). */
function crmPhaseDialog(ziel) {
    const d = crmC().data;
    const order = crmList('deal_phase').map(p => p.key);
    const phasen = ziel === 'verloren' ? ['verloren'] : order.slice(0, order.indexOf(ziel) + 1).filter(p => p !== 'verloren');
    const pf = crm.opt.pflicht || {};
    const cur = { ...d, ...crmDealFelder(), angebot_datei_pfad: d.hat_angebot ? 'x' : null };
    const need = [...new Set(phasen.flatMap(p => pf[p] || []))].filter(f => cur[f] == null || cur[f] === '' || (f === 'wert_monatlich_eur' && !(parseFloat(cur[f]) > 0)));
    const inp = {
        objektadresse: () => `<label class="crm-field crm-span3">Objektadresse *<input id="cp_objektadresse" class="crm-input" data-f="objektadresse" maxlength="300"></label>`,
        besichtigung_am: () => `<label class="crm-field">Besichtigung am *<input id="cp_besichtigung_am" type="datetime-local" class="crm-input" data-f="besichtigung_am"></label>`,
        besichtigung_notiz: () => `<label class="crm-field crm-span3">Besichtigungsnotiz *<textarea id="cp_besichtigung_notiz" class="crm-input" rows="3" data-f="besichtigung_notiz"></textarea></label>`,
        wert_monatlich_eur: () => `<label class="crm-field">Wert monatlich (€) *<input id="cp_wert_monatlich_eur" type="number" min="0" step="0.01" class="crm-input" data-f="wert_monatlich_eur"></label>`,
        laufzeit_monate: () => `<label class="crm-field">Laufzeit (Monate) *<input id="cp_laufzeit_monate" type="number" min="1" step="1" class="crm-input" data-f="laufzeit_monate"></label>`,
        angebot_datei_pfad: () => `<label class="crm-field crm-span3">Angebotsdokument *<input id="cp_angebot" type="file" class="crm-input" data-f="angebot_datei_pfad" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"></label>`,
        vertragsbeginn: () => `<label class="crm-field">Vertragsbeginn *<input id="cp_vertragsbeginn" type="date" class="crm-input" data-f="vertragsbeginn"></label>`,
        verlust_grund: () => `<label class="crm-field">Verlustgrund *<select id="cp_verlust_grund" class="crm-input" data-f="verlust_grund">${crmOptions(crmList('verlust_grund'), '', '— wählen —')}</select></label>
            <label class="crm-field crm-span2">Notiz (bei „Sonstiges“ Pflicht)<textarea id="cp_verlust_notiz" class="crm-input" rows="2" data-f="verlust_notiz"></textarea></label>
            <label class="crm-field">Wiedervorlage (optional)<input id="cp_wv" type="date" class="crm-input" min="${crmAddDays(1)}"></label>`,
    };
    const firmaKunde = d.firma?.kunde_id;
    const extra = ziel === 'gewonnen' && crmCan('kunden_edit')
        ? `<label class="crm-check crm-span3"><md-checkbox id="cp_kunde" checked></md-checkbox> ${firmaKunde ? 'Mit bestehendem Kunden ' + crmE(firmaKunde) + ' verknüpfen' : 'Als Kunde in Stammdaten anlegen (Bereich = Sparte ' + crmE(d.sparte || '') + ')'}</label>` : '';
    const hint = ziel === 'gewonnen' ? 'Lifecycle → Kunde, Übergabe-Aufgabe an die Objektleitung der Sparte (A10).'
        : ziel === 'verloren' ? 'Lifecycle bleibt Opportunity; die Vertriebsleitung erhält einen Hinweis (A11).' : '';
    openModal('Phase → ' + crmName('deal_phase', ziel), `
        <div class="crm-form">
            ${need.length ? `<div class="crm-muted">Pflichtfelder${phasen.length > 1 ? ' (inkl. übersprungener Phasen)' : ''}:</div>` : '<div class="crm-muted">Keine weiteren Pflichtfelder.</div>'}
            <div class="crm-form-grid">${need.map(f => inp[f] ? inp[f]() : '').join('')}${extra}</div>
            ${hint ? `<div class="crm-banner">${crmIcon('info')} ${hint}</div>` : ''}
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button class="${ziel === 'verloren' ? 'md-btn-danger' : ''}" id="crmSaveBtn" onclick="crmPhaseSpeichern('${ziel}')">Phase setzen</md-filled-button>`);
}
async function crmPhaseSpeichern(ziel) {
    const id = crmC().id;
    const btn = document.getElementById('crmSaveBtn'); if (btn) btn.disabled = true;
    const file = document.getElementById('cp_angebot')?.files?.[0];
    if (file) {
        const fd = new FormData(); fd.append('datei', file);
        const up = await crmReq('POST', CRM_API + '/deals/' + id + '/angebot', fd);
        if (crmFehler(up, 'Upload fehlgeschlagen')) { if (btn) btn.disabled = false; return; }
    }
    const body = { ...crmDealFelder(), phase: ziel };
    ['objektadresse', 'besichtigung_am', 'besichtigung_notiz', 'wert_monatlich_eur', 'laufzeit_monate', 'vertragsbeginn', 'verlust_grund', 'verlust_notiz']
        .forEach(k => { const v = crmV('cp_' + k); if (v !== undefined && v !== '') body[k] = v; });
    if (crmV('cp_wv')) body.wiedervorlage_am = crmV('cp_wv');
    if (document.getElementById('cp_kunde')) body.kunde_anlegen = crmV('cp_kunde');
    const r = await crmReq('POST', CRM_API + '/deals/' + id + '/phase', body);
    if (btn) btn.disabled = false;
    if (crmFehler(r)) return;
    closeModal(); crm.dirty = false;
    showToast('Phase: ' + crmName('deal_phase', ziel) + (r.data.kunde_id ? ' — Kunde ' + r.data.kunde_id : ''));
    crmNachDialog();
}

// ============================================
// KANBAN (5.1 / 5.4) — drag & drop; every drop runs through the same
// dialog as in the detail view, so rules (A4–A7) and required fields hold.
// ============================================
function crmModusToggle(view) {
    return `<md-chip-set class="crm-modus">${[['liste', 'Liste', 'table_rows'], ['kanban', 'Kanban', 'view_kanban']].map(([k, l, i]) => `
        <md-filter-chip label="${l}" ${crm.modus[view] === k ? 'selected' : ''} onclick="crmSetModus('${view}','${k}')"><md-icon slot="icon">${i}</md-icon></md-filter-chip>`).join('')}</md-chip-set>`;
}
function crmSetModus(view, m) {
    crm.modus[view] = m;
    try { localStorage.setItem('crm_modus_' + view, m); } catch (e) {}
    crmRenderView();
}
let _crmDrag = null;
function crmDragStart(ev, typ, id, von) {
    _crmDrag = { typ, id, von };
    ev.dataTransfer.effectAllowed = 'move';
    ev.dataTransfer.setData('text/plain', id);
    ev.currentTarget.classList.add('dragging');
}
function crmDragEnd(ev) { ev.currentTarget.classList.remove('dragging'); document.querySelectorAll('.crm-kb-col.over').forEach(c => c.classList.remove('over')); }
function crmDragOver(ev) { ev.preventDefault(); ev.currentTarget.classList.add('over'); }
function crmDragLeave(ev) { ev.currentTarget.classList.remove('over'); }

async function crmDrop(ev, typ, ziel) {
    ev.preventDefault();
    ev.currentTarget.classList.remove('over');
    const d = _crmDrag; _crmDrag = null;
    if (!d || d.typ !== typ || d.von === ziel) return;
    if (!await crmCtxLaden(typ, d.id)) return;
    const x = crm.ctx.data;
    if (!x.darf_bearbeiten) { crm.ctx = null; return showToast('Nur der Owner oder die Vertriebsleitung kann diesen Datensatz ändern.', 'error'); }
    if (typ === 'deal') {
        if (x.kategorie !== 'offen' && !x.darf_wiedereroeffnen) { crm.ctx = null; return showToast('Abgeschlossene Deals kann nur die Vertriebsleitung wieder öffnen.', 'error'); }
        return crmPhaseDialog(ziel); // asks for the required fields incl. skipped phases (3.3)
    }
    if (ziel === 'qualifiziert') return crmQualifizierenDialog();
    if (ziel === 'disqualifiziert') return crmDisqualifizierenDialog();
    if (ziel === 'verbunden') return crmAnrufDialog('erreicht');                                  // A5
    if (ziel === 'kontaktversuch' && d.von === 'neu') return crmAnrufDialog('nicht_erreicht');     // A4
    crm.ctx = null;
    showToast('Diese Stage wechselt automatisch über Aktivitäten (A4/A5).', 'error');
}

function crmKbCard(typ, x, von, body) {
    return `<div data-mk="${x.id}" class="crm-kb-card ${x.ueberfaellig ? 'over' : ''}" draggable="true" ondragstart="crmDragStart(event,'${typ}','${x.id}','${von}')" ondragend="crmDragEnd(event)" onclick="crmOpenDetail('${typ}','${x.id}')">${body}</div>`;
}

function crmLeadKanban() {
    const spalten = crmList('lead_stage', false).filter(s => CRM_LEAD_OFFEN.includes(s.key));
    const cols = spalten.map(s => {
        const rows = crm.leads.filter(r => r.stage === s.key);
        return `<div class="crm-kb-col" ondragover="crmDragOver(event)" ondragleave="crmDragLeave(event)" ondrop="crmDrop(event,'lead','${s.key}')">
            <div class="crm-kb-head">${crmStageBadge(s.key)}<span class="crm-kb-n">${rows.length}</span></div>
            <div class="crm-kb-list">${rows.map(r => crmKbCard('lead', r, r.stage, `
                <div class="crm-kb-title">${crmE(r.firma_name || r.kontakt_name)}</div>
                <div class="crm-sub">${crmE(r.firma_name ? r.kontakt_name : (r.position || ''))}</div>
                <div class="crm-kb-meta">${crmSparte(r.sparte)}${r.prioritaet === 'hoch' ? `<span class="crm-prio-hoch">${crmIcon('priority_high', 'font-size:15px')}</span>` : ''}</div>
                <div class="crm-kb-foot"><span title="Tage in Stage">${crmIcon('schedule', 'font-size:14px')} ${r.tage_in_stage} T</span>
                    <span title="Kontaktversuche">${crmIcon('call', 'font-size:14px')} ${r.anzahl_kontaktversuche}</span>
                    ${r.naechste_aufgabe_am ? `<span class="${r.aufgabe_ueberfaellig ? 'crm-red' : (r.faellig_heute ? 'crm-amber' : '')}">${crmIcon('task_alt', 'font-size:14px')} ${crmDate(r.naechste_aufgabe_am)}</span>` : '<span class="crm-red">keine Aufgabe</span>'}
                    ${crmCan('all') ? `<span class="crm-kb-owner">${crmE(r.owner_name)}</span>` : ''}</div>`)).join('') || '<div class="crm-kb-empty">—</div>'}</div>
        </div>`;
    }).join('');
    const ziele = [['qualifiziert', 'verified', 'Qualifizieren', 'Checkliste + Deal anlegen (A6)'], ['disqualifiziert', 'block', 'Disqualifizieren', 'Grund ist Pflicht (A7)']]
        .map(([k, i, l, h]) => `<div class="crm-kb-col crm-kb-target crm-kb-${k}" ondragover="crmDragOver(event)" ondragleave="crmDragLeave(event)" ondrop="crmDrop(event,'lead','${k}')">
            <div class="crm-kb-drop">${crmIcon(i)}<strong>${l}</strong><span class="crm-sub">${h}</span><span class="crm-sub">Karte hierher ziehen</span></div></div>`).join('');
    return `<div class="crm-kanban">${cols}${ziele}</div>`;
}

function crmDealKanban() {
    const sum = Object.fromEntries((crm.summen || []).map(s => [s.phase, s]));
    return `<div class="crm-kanban">${crmList('deal_phase').map(p => {
        const rows = crm.deals.filter(d => d.phase === p.key), s = sum[p.key] || {};
        return `<div class="crm-kb-col crm-kb-phase-${crmE(p.key)}" ondragover="crmDragOver(event)" ondragleave="crmDragLeave(event)" ondrop="crmDrop(event,'deal','${p.key}')">
            <div class="crm-kb-head">${crmPhaseBadge(p.key)}<span class="crm-kb-n">${rows.length}</span></div>
            <div class="crm-kb-sum"><span>Σ ${crmMoney(s.gesamtwert_eur ?? 0)}</span><span class="crm-sub">gewichtet ${crmMoney(s.gewichtet_eur ?? 0)} · ${p.wahrscheinlichkeit ?? 0}%</span></div>
            <div class="crm-kb-list">${rows.map(d => crmKbCard('deal', d, d.phase, `
                <div class="crm-kb-title">${crmE(d.titel)}</div>
                <div class="crm-sub">${crmE(d.firma_name || d.kontakt_name)}</div>
                <div class="crm-kb-meta">${crmSparte(d.sparte)}</div>
                <div class="crm-kb-foot"><span>${crmMoney(d.wert_monatlich_eur)}/Monat</span>
                    <span class="${d.kategorie === 'offen' && d.erwarteter_abschluss && d.erwarteter_abschluss < crmToday() ? 'crm-red' : ''}">${crmIcon('event', 'font-size:14px')} ${crmDate(d.erwarteter_abschluss)}</span>
                    ${crmCan('all') ? `<span class="crm-kb-owner">${crmE(d.owner_name)}</span>` : ''}</div>`)).join('') || '<div class="crm-kb-empty">—</div>'}</div>
        </div>`;
    }).join('')}</div>`;
}

// ============================================
// FIRMEN & KONTAKTE (5.5)
// ============================================
function crmStammFrame() {
    const firmen = crm.sf.art === 'firmen';
    return `
        <div class="data-toolbar">
            <div class="data-toolbar-left">
                <md-chip-set>${[['firmen', 'Firmen', 'domain'], ['kontakte', 'Kontakte', 'group']].map(([k, l, i]) => `
                    <md-filter-chip label="${l}" ${crm.sf.art === k ? 'selected' : ''} onclick="crm.sf.art='${k}';crmRenderView()"><md-icon slot="icon">${i}</md-icon></md-filter-chip>`).join('')}</md-chip-set>
                ${crmMdSearch('sf', firmen ? 'Firmen suchen' : 'Kontakte suchen', firmen ? 'Name, PLZ, Ort…' : 'Name, Firma, E-Mail, Telefon…')}
                ${firmen ? '' : crmMdFilter('sf', 'lifecycle', 'Lifecycle', crmList('lifecycle', false), 'Alle Stages')}
            </div>
            ${crmToolbarActions((crmWerkzeug() ? `<md-outlined-button onclick="crmImportDialog()"><md-icon slot="icon">upload_file</md-icon>Import CSV/Excel</md-outlined-button>` : '')
                + (firmen && crmCan('kontakte_edit') ? `<md-outlined-button onclick="crmFirmaDialog()"><md-icon slot="icon">add_business</md-icon>Neue Firma</md-outlined-button>` : ''))}
        </div>
        <div class="data-table-card"><div id="crmRows"></div></div>`;
}
function crmStammRows() {
    if (!crm.stamm.length) return `<div class="empty-state">${crmIcon(crm.sf.art === 'firmen' ? 'domain' : 'group')}<p>Keine Einträge</p></div>`;
    const _p = hmsPagerDef('crmPagerS', crm.sf.art === 'firmen' ? 'Firmen' : 'Kontakte', () => crmApply(crm.cache[crmCk()]));
    const pg = hmsPage(crm.stamm, _p.st);
    const pager = `<div id="crmPagerS" class="be-pager">${hmsPagerHtml('crmPagerS', pg, _p.unit)}</div>`;
    const dash = '<span class="crm-muted">–</span>';
    if (crm.sf.art === 'firmen') return `<div class="table-container"><table class="crm-table"><thead><tr><th>Firma</th><th>Ort</th><th>Branche</th><th class="crm-num">Kontakte</th><th class="crm-num">Offene Leads</th><th class="crm-num">Offene Deals</th><th>Kunde</th><th>Owner</th></tr></thead><tbody>
        ${pg.rows.map(f => `<tr data-mk="${f.id}" class="crm-row" onclick="crmOpenDetail('firma','${f.id}')"><td data-label="Firma"><strong>${crmE(f.name)}</strong></td>
            <td data-label="Ort">${[f.plz, f.ort].filter(Boolean).length ? crmE([f.plz, f.ort].filter(Boolean).join(' ')) : dash}</td>
            <td data-label="Branche">${f.branche ? crmE(crmName('branche', f.branche)) : dash}</td>
            <td data-label="Kontakte" class="crm-num">${f.kontakte}</td><td data-label="Offene Leads" class="crm-num">${f.offene_leads || dash}</td><td data-label="Offene Deals" class="crm-num">${f.offene_deals || dash}</td>
            <td data-label="Kunde">${f.kunde_id ? `<span class="crm-life crm-life-kunde">${crmE(f.kunde_id)}</span>` : dash}</td><td data-label="Owner">${crmE(f.owner_name || '')}</td></tr>`).join('')}</tbody></table></div>${pager}`;
    return `<div class="table-container"><table class="crm-table"><thead><tr><th>Name</th><th>Firma</th><th>Position</th><th>E-Mail / Telefon</th><th>Lifecycle</th><th>Quelle</th><th>Owner</th></tr></thead><tbody>
        ${pg.rows.map(k => `<tr data-mk="${k.id}" class="crm-row" onclick="crmOpenDetail('kontakt','${k.id}')"><td data-label="Name"><strong>${crmE([k.vorname, k.nachname].filter(Boolean).join(' '))}</strong></td>
            <td data-label="Firma">${k.firma_name ? crmE(k.firma_name) : '<span class="crm-muted">Privat</span>'}</td><td data-label="Position">${k.position ? crmE(k.position) : dash}</td>
            <td data-label="Kontakt">${crmE(k.email || k.telefon || k.mobil || '') || dash}</td>
            <td data-label="Lifecycle"><span class="crm-life crm-life-${crmE(k.lifecycle_stage)}">${crmE(crmName('lifecycle', k.lifecycle_stage))}</span></td>
            <td data-label="Quelle">${crmE(crmName('quelle', k.quelle))}</td><td data-label="Owner">${crmE(k.owner_name || '')}</td></tr>`).join('')}</tbody></table></div>${pager}`;
}

function crmStammDetail(typ, x) {
    const ed = x.darf_bearbeiten;
    const leads = (x.leads || []).map(l => `<div class="crm-link-row" onclick="crmOpenDetail('lead','${l.id}')">${crmSparte(l.sparte)} ${crmStageBadge(l.stage)} <span>${crmE(l.kontakt_name)}</span><span class="crm-sub">${crmE(l.owner_name)}</span></div>`).join('') || '<div class="crm-muted crm-pad">Keine (sichtbaren) Leads</div>';
    const deals = (x.deals || []).map(d => `<div class="crm-link-row" onclick="crmOpenDetail('deal','${d.id}')">${crmPhaseBadge(d.phase)} <span>${crmE(d.titel)}</span></div>`).join('') || '<div class="crm-muted crm-pad">Keine (sichtbaren) Deals</div>';
    if (typ === 'firma') {
        return `
            ${crmDetailTop('Zur Liste', (ed ? `<md-outlined-button onclick="crmFirmaDialog(crm.detail.data)"><md-icon slot="icon">edit</md-icon>Bearbeiten</md-outlined-button>` : '') +
                (crmCan('kontakte_edit') ? `<md-filled-button onclick="crmNeuerKontakt({firma_id:'${x.id}', firma_name:${crmE(JSON.stringify(x.name))}})"><md-icon slot="icon">person_add</md-icon>Kontakt</md-filled-button>` : '') +
                (crmWerkzeug() ? `<md-outlined-button onclick="crmMergeDialog('firma','${x.id}')"><md-icon slot="icon">merge</md-icon>Zusammenführen</md-outlined-button>` : '') +
                (crmCan('admin') ? `<md-icon-button class="crm-icon-danger" onclick="crmDelete('firmen','${x.id}')"><md-icon>delete</md-icon></md-icon-button>` : ''))}
            <div class="crm-head"><div><div class="crm-head-firma">Firma${x.kunde_id ? ' · Kunde ' + crmE(x.kunde_id) : ''}</div><h2 class="crm-head-title">${crmE(x.name)}</h2>
                <div class="crm-head-meta">${x.strasse || x.ort ? `<span>${crmIcon('location_on')} ${crmE([x.strasse, [x.plz, x.ort].filter(Boolean).join(' '), x.bundesland].filter(Boolean).join(', '))}</span>` : ''}
                ${x.telefon ? `<a href="tel:${crmE(x.telefon)}">${crmIcon('call')} ${crmE(x.telefon)}</a>` : ''}${x.website ? `<span>${crmIcon('language')} ${crmE(x.website)}</span>` : ''}
                ${x.branche ? `<span>${crmE(crmName('branche', x.branche))}</span>` : ''}${x.mitarbeiterzahl ? `<span>${crmE(x.mitarbeiterzahl)} MA</span>` : ''}</div></div>
                <div class="crm-head-side"><div class="crm-sub">Owner: <strong>${crmE(x.owner_name || '')}</strong>${crmCan('all') ? ` <a class="crm-link" href="#" onclick="crmOwnerDialog('firmen','${x.id}',${x.owner_id});return false">ändern</a>` : ''}</div></div></div>
            <div class="crm-grid"><div class="crm-main">
                <div class="crm-card"><div class="crm-card-title">${crmIcon('group')} Kontakte</div>${(x.kontakte || []).map(k => `<div class="crm-link-row" onclick="crmOpenDetail('kontakt','${k.id}')"><strong>${crmE([k.vorname, k.nachname].filter(Boolean).join(' '))}</strong><span>${crmE(k.position || '')}</span><span class="crm-life crm-life-${crmE(k.lifecycle_stage)}">${crmE(crmName('lifecycle', k.lifecycle_stage))}</span></div>`).join('') || '<div class="crm-muted crm-pad">Keine</div>'}</div>
                <div class="crm-card"><div class="crm-card-title">${crmIcon('history')} Aktivitäten</div>${crmTimeline(x.aktivitaeten)}</div>
            </div><div class="crm-side">
                <div class="crm-card"><div class="crm-card-title">${crmIcon('person_search')} Leads</div>${leads}</div>
                <div class="crm-card"><div class="crm-card-title">${crmIcon('handshake')} Deals</div>${deals}</div>
            </div></div>`;
    }
    const manuell = ['interessent', 'mql', 'sql', 'ehemaliger_kunde'];
    const lifeOpts = crmList('lifecycle', false).filter(s => crmCan('all') || manuell.includes(s.key));
    const bestand = ['kunde', 'ehemaliger_kunde'].includes(x.lifecycle_stage);
    return `
        ${crmDetailTop('Zur Liste', (ed ? `<md-outlined-button onclick="crmKontaktDialog(crm.detail.data)"><md-icon slot="icon">edit</md-icon>Bearbeiten</md-outlined-button>` : '') +
            (crmCan('kontakte_edit') ? `<md-outlined-button onclick="crmAktivitaetDialog('kontakt','${x.id}','notiz')"><md-icon slot="icon">edit_note</md-icon>Aktivität</md-outlined-button>` : '') +
            (crmCan('leads_edit') ? `<md-filled-button onclick="crmLeadDialog('${x.id}')"><md-icon slot="icon">add</md-icon>Lead anlegen</md-filled-button>` : '') +
            (crmCan('deals_edit') && bestand ? `<md-outlined-button onclick="crmZusatzDealDialog('${x.id}')"><md-icon slot="icon">handshake</md-icon>Zusatzauftrag</md-outlined-button>` : '') +
            (crmWerkzeug() ? `<md-outlined-button onclick="crmMergeDialog('kontakt','${x.id}')"><md-icon slot="icon">merge</md-icon>Zusammenführen</md-outlined-button>` : '') +
            (crmCan('admin') ? `<md-outlined-button onclick="crmDownload(CRM_API + '/kontakte/${x.id}/dsgvo-export')"><md-icon slot="icon">privacy_tip</md-icon>DSGVO-Export</md-outlined-button>` : '') +
            (crmCan('admin') ? `<md-icon-button class="crm-icon-danger" onclick="crmDelete('kontakte','${x.id}')"><md-icon>delete</md-icon></md-icon-button>` : ''))}
        <div class="crm-head"><div>
            <div class="crm-head-firma">${x.firma_id ? `<a href="#" onclick="crmOpenDetail('firma','${x.firma_id}');return false">${crmE(x.firma_name)}</a>` : 'Privatkontakt'}</div>
            <h2 class="crm-head-title">${crmE([x.anrede, x.vorname, x.nachname].filter(Boolean).join(' '))}${x.entscheider ? ' <span class="crm-chip">Entscheider</span>' : ''}</h2>
            <div class="crm-head-meta">${x.position ? `<span>${crmIcon('badge')} ${crmE(x.position)}</span>` : ''}
                ${x.telefon ? `<a href="tel:${crmE(x.telefon)}">${crmIcon('call')} ${crmE(x.telefon)}</a>` : ''}${x.mobil ? `<a href="tel:${crmE(x.mobil)}">${crmIcon('smartphone')} ${crmE(x.mobil)}</a>` : ''}
                ${x.email ? `<a href="mailto:${crmE(x.email)}">${crmIcon('mail')} ${crmE(x.email)}</a>` : ''}
                <span>${crmIcon('campaign')} ${crmE(crmName('quelle', x.quelle))}</span>
                <span>${crmIcon(x.dsgvo_einwilligung ? 'mark_email_read' : 'unsubscribe')} ${x.dsgvo_einwilligung ? 'Werbe-Einwilligung seit ' + crmDate(x.dsgvo_datum) : 'keine Werbe-Einwilligung'}</span></div></div>
            <div class="crm-head-side">
                <div class="crm-sub">Lifecycle Stage</div>
                ${ed || crmCan('all') ? `<select class="crm-input crm-input-sm" onchange="crmLifecycle(this.value, this)">${lifeOpts.map(s => `<option value="${s.key}" ${s.key === x.lifecycle_stage ? 'selected' : ''}>${crmE(s.name)}</option>`).join('')}${!lifeOpts.some(s => s.key === x.lifecycle_stage) ? `<option selected disabled>${crmE(crmName('lifecycle', x.lifecycle_stage))}</option>` : ''}</select>`
                    : `<span class="crm-life crm-life-${crmE(x.lifecycle_stage)}">${crmE(crmName('lifecycle', x.lifecycle_stage))}</span>`}
                <div class="crm-sub">seit ${crmUtcDT(x.lifecycle_changed_at)} · Owner: <strong>${crmE(x.owner_name || '')}</strong>${crmCan('all') ? ` <a class="crm-link" href="#" onclick="crmOwnerDialog('kontakte','${x.id}',${x.owner_id});return false">ändern</a>` : ''}</div>
            </div></div>
        <div class="crm-grid"><div class="crm-main">
            <div class="crm-card"><div class="crm-card-title">${crmIcon('history')} Aktivitäten</div>${crmTimeline(x.aktivitaeten)}</div>
        </div><div class="crm-side">
            <div class="crm-card"><div class="crm-card-title">${crmIcon('person_search')} Leads</div>${leads}</div>
            <div class="crm-card"><div class="crm-card-title">${crmIcon('handshake')} Deals</div>${deals}</div>
            <details class="crm-card"><summary class="crm-card-title">${crmIcon('receipt_long')} Änderungsprotokoll</summary>${crmProtokoll(x.protokoll)}</details>
        </div></div>`;
}

async function crmLifecycle(stage, sel) {
    const x = crm.detail.data;
    let sparte = null;
    if (stage === 'sql') {
        sparte = await crmSparteWahl('Lifecycle → SQL erzeugt einen Lead (A3). Sparte wählen:');
        if (!sparte) { sel.value = x.lifecycle_stage; return; }
    }
    const r = await crmReq('PUT', CRM_API + '/kontakte/' + x.id + '/lifecycle', { stage, sparte });
    if (crmFehler(r)) { sel.value = x.lifecycle_stage; return; }
    showToast('Lifecycle geändert' + (r.data.lead_id ? ' — Lead angelegt' : ''));
    crmReloadDetail();
}

function crmSparteWahl(text) {
    return new Promise(resolve => {
        openModal('Sparte', `<p>${crmE(text)}</p><select id="cs_sparte" class="crm-input">${crmOptions(crm.opt.sparten || [], '', '— wählen —')}</select>`,
            `<md-outlined-button  id="csCancel">Abbrechen</md-outlined-button><md-filled-button  id="csOk">OK</md-filled-button>`);
        document.getElementById('csCancel').onclick = () => { closeModal(); resolve(null); };
        document.getElementById('csOk').onclick = () => { const v = crmV('cs_sparte'); if (!v) return showToast('Sparte wählen', 'error'); closeModal(); resolve(v); };
    });
}

function crmLeadDialog(kontaktId) {
    openModal('Lead anlegen', `
        <div class="crm-form crm-form-grid">
            <label class="crm-field">Sparte *<select id="cl_sparte" class="crm-input" data-f="sparte">${crmOptions(crm.opt.sparten || [], '', '— wählen —')}</select></label>
            <label class="crm-field">Priorität<select id="cl_prio" class="crm-input">${crmOptions(crmList('prioritaet'), 'normal')}</select></label>
            ${crmCan('all') ? `<label class="crm-field">Owner<select id="cl_owner" class="crm-input" data-f="owner_id"><option value="auto" selected>Automatisch (Zuweisungsregel)</option>${crmOptions(crm.opt.owners || [], '')}</select></label>` : ''}
            <div class="crm-muted crm-span3">Pro Sparte gibt es höchstens einen offenen Lead je Kontakt (A3). Aufgabe „Erstkontakt“ wird heute fällig.</div>
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  onclick="crmLeadSpeichern('${kontaktId}')">Anlegen</md-filled-button>`);
}
async function crmLeadSpeichern(kontaktId) {
    const r = await crmReq('POST', CRM_API + '/leads', { kontakt_id: kontaktId, sparte: crmV('cl_sparte'), prioritaet: crmV('cl_prio'), owner_id: crmV('cl_owner') || null });
    if (r.status === 409 && r.data.lead_id) { closeModal(); showToast(r.data.error, 'error'); return crmOpenDetail('lead', r.data.lead_id); }
    if (crmFehler(r)) return;
    closeModal(); showToast('Lead angelegt'); crmOpenDetail('lead', r.data.id);
}

function crmZusatzDealDialog(kontaktId) {
    openModal('Zusatzauftrag (Deal für Bestandskunde)', `
        <div class="crm-form crm-form-grid">
            <label class="crm-field crm-span3">Titel *<input id="cz_titel" class="crm-input" data-f="titel" maxlength="250"></label>
            <label class="crm-field">Sparte *<select id="cz_sparte" class="crm-input" data-f="sparte">${crmOptions(crm.opt.sparten || [], '', '— wählen —')}</select></label>
            <label class="crm-field crm-span2">Objektadresse *<input id="cz_adresse" class="crm-input" data-f="objektadresse" maxlength="300"></label>
            <label class="crm-field">Besichtigung am *<input id="cz_besichtigung" type="datetime-local" class="crm-input" data-f="besichtigung_am"></label>
            <label class="crm-field">Erwarteter Abschluss *<input id="cz_abschluss" type="date" class="crm-input" data-f="erwarteter_abschluss" value="${crmAddDays(30)}"></label>
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  onclick="crmZusatzDealSpeichern('${kontaktId}')">Anlegen</md-filled-button>`);
}
async function crmZusatzDealSpeichern(kontaktId) {
    const r = await crmReq('POST', CRM_API + '/deals', { kontakt_id: kontaktId, titel: crmV('cz_titel'), sparte: crmV('cz_sparte'), objektadresse: crmV('cz_adresse'), besichtigung_am: crmV('cz_besichtigung'), erwarteter_abschluss: crmV('cz_abschluss') });
    if (crmFehler(r)) return;
    closeModal(); showToast('Deal angelegt'); crmOpenDetail('deal', r.data.id);
}

// ---------- Firma / Kontakt forms ----------
function crmFirmaFelder(p, f = {}) {
    return `
        <label class="crm-field crm-span3">Firmenname *<input id="${p}name" class="crm-input" data-f="${p === 'cn_f_' ? 'firma.' : ''}name" maxlength="200" value="${crmE(f.name || '')}"></label>
        <label class="crm-field crm-span2">Straße<input id="${p}strasse" class="crm-input" maxlength="200" value="${crmE(f.strasse || '')}"></label>
        <label class="crm-field">PLZ<input id="${p}plz" class="crm-input" maxlength="10" inputmode="numeric" value="${crmE(f.plz || '')}"></label>
        <label class="crm-field">Ort<input id="${p}ort" class="crm-input" maxlength="120" value="${crmE(f.ort || '')}"></label>
        <label class="crm-field">Bundesland<select id="${p}bundesland" class="crm-input">${crmOptions(['Sachsen-Anhalt', 'Sachsen', 'Thüringen', 'Brandenburg', 'Berlin', 'Niedersachsen', 'Bayern', 'Hessen', 'Mecklenburg-Vorpommern', 'Nordrhein-Westfalen', 'Baden-Württemberg', 'Rheinland-Pfalz', 'Saarland', 'Schleswig-Holstein', 'Hamburg', 'Bremen'], f.bundesland || '', '—')}</select></label>
        <label class="crm-field">Branche<select id="${p}branche" class="crm-input">${crmOptions(crmList('branche'), f.branche || '', '—')}</select></label>
        <label class="crm-field">Mitarbeiterzahl<input id="${p}mitarbeiterzahl" type="number" min="0" class="crm-input" data-f="${p === 'cn_f_' ? 'firma.' : ''}mitarbeiterzahl" value="${crmE(f.mitarbeiterzahl ?? '')}"></label>
        <label class="crm-field">Telefon<input id="${p}telefon" class="crm-input" maxlength="60" value="${crmE(f.telefon || '')}"></label>
        <label class="crm-field">Website<input id="${p}website" class="crm-input" maxlength="200" value="${crmE(f.website || '')}"></label>`;
}
function crmFirmaDaten(p) {
    const o = {};
    ['name', 'strasse', 'plz', 'ort', 'bundesland', 'branche', 'mitarbeiterzahl', 'telefon', 'website'].forEach(k => { const v = crmV(p + k); o[k] = v === '' ? null : v; });
    return o;
}
function crmFirmaDialog(f = null) {
    openModal(f ? 'Firma bearbeiten' : 'Neue Firma', `<div class="crm-form crm-form-grid">${crmFirmaFelder('cf_f_', f || {})}</div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  onclick="crmFirmaSpeichern(${f ? `'${f.id}'` : 'null'})">Speichern</md-filled-button>`);
}
async function crmFirmaSpeichern(id, trotzdem = false) {
    const body = { ...crmFirmaDaten('cf_f_'), trotzdem };
    const r = await crmReq(id ? 'PUT' : 'POST', CRM_API + '/firmen' + (id ? '/' + id : ''), body);
    if (r.status === 409 && r.data.dubletten) return crmDublettenHinweis(r.data.dubletten, () => crmFirmaSpeichern(id, true));
    if (crmFehler(r)) return;
    closeModal(); showToast('Firma gespeichert');
    id ? crmReloadDetail() : crmOpenDetail('firma', r.data.id);
}

function crmKontaktFelder(k = {}) {
    return `
        <label class="crm-field">Anrede<select id="ck_anrede" class="crm-input">${crmOptions(['Herr', 'Frau', 'Divers'], k.anrede || '', '—')}</select></label>
        <label class="crm-field">Vorname<input id="ck_vorname" class="crm-input" maxlength="100" value="${crmE(k.vorname || '')}"></label>
        <label class="crm-field">Nachname *<input id="ck_nachname" class="crm-input" data-f="nachname" maxlength="100" value="${crmE(k.nachname || '')}"></label>
        <label class="crm-field crm-span2">Position<input id="ck_position" class="crm-input" maxlength="150" placeholder="z. B. Hausverwaltung, Einkauf, Geschäftsführung" value="${crmE(k.position || '')}"></label>
        <label class="crm-check"><md-checkbox id="ck_entscheider" ${k.entscheider ? 'checked' : ''}></md-checkbox> Entscheider</label>
        <label class="crm-field">E-Mail<input id="ck_email" type="email" class="crm-input" data-f="email" maxlength="200" value="${crmE(k.email || '')}"></label>
        <label class="crm-field">Telefon<input id="ck_telefon" class="crm-input" data-f="telefon" maxlength="60" value="${crmE(k.telefon || '')}"></label>
        <label class="crm-field">Mobil<input id="ck_mobil" class="crm-input" data-f="mobil" maxlength="60" value="${crmE(k.mobil || '')}"></label>
        <label class="crm-field">Quelle *<select id="ck_quelle" class="crm-input" data-f="quelle">${crmOptions(crmList('quelle'), k.quelle || '', '— wählen —')}</select></label>
        <label class="crm-check"><md-checkbox id="ck_dsgvo" ${k.dsgvo_einwilligung ? 'checked' : ''}></md-checkbox> Einwilligung Werbe-E-Mails</label>
        <label class="crm-field">Einwilligung am<input id="ck_dsgvo_datum" type="date" class="crm-input" data-f="dsgvo_datum" value="${crmE(k.dsgvo_datum || '')}"></label>`;
}
function crmKontaktDaten() {
    const o = {};
    ['anrede', 'vorname', 'nachname', 'position', 'email', 'telefon', 'mobil', 'quelle', 'dsgvo_datum'].forEach(k => { const v = crmV('ck_' + k); o[k] = v === '' ? null : v; });
    o.entscheider = crmV('ck_entscheider'); o.dsgvo_einwilligung = crmV('ck_dsgvo');
    return o;
}
function crmKontaktDialog(k) {
    openModal('Kontakt bearbeiten', `<div class="crm-form crm-form-grid">${crmKontaktFelder(k)}</div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  onclick="crmKontaktUpdate('${k.id}')">Speichern</md-filled-button>`);
}
async function crmKontaktUpdate(id, trotzdem = false) {
    const k = crm.detail.data;
    const r = await crmReq('PUT', CRM_API + '/kontakte/' + id, { ...crmKontaktDaten(), firma_id: k.firma_id || null, trotzdem });
    if (r.status === 409 && r.data.dubletten) return crmDublettenHinweis(r.data.dubletten, () => crmKontaktUpdate(id, true));
    if (crmFehler(r)) return;
    closeModal(); showToast('Kontakt gespeichert'); crmReloadDetail();
}

/** New contact (+ firm) (+ lead). A1 duplicate warning before saving. */
function crmNeuerKontakt(pre = {}) {
    openModal(crmCan('leads_edit') ? 'Neuer Kontakt / Lead' : 'Neuer Kontakt', `
        <div class="crm-form">
            <div class="crm-sec">Firma</div>
            <div class="crm-radio-row">
                <label><md-radio name="cn_fmodus" value="vorhanden" ${pre.firma_id ? 'checked' : ''} onchange="crmFModus()"></md-radio> Vorhandene Firma</label>
                <label><md-radio name="cn_fmodus" value="neu" ${pre.firma_id ? '' : 'checked'} onchange="crmFModus()"></md-radio> Neue Firma</label>
                <label><md-radio name="cn_fmodus" value="privat" onchange="crmFModus()"></md-radio> Privatkunde (ohne Firma)</label>
            </div>
            <div id="cnFVorhanden" style="${pre.firma_id ? '' : 'display:none'}">
                <input class="crm-input" placeholder="Firma suchen…" oninput="crmFirmaSuche(this.value)" value="${crmE(pre.firma_name || '')}">
                <select id="cn_firma_id" class="crm-input" data-f="firma_id" size="4">${pre.firma_id ? `<option value="${pre.firma_id}" selected>${crmE(pre.firma_name || '')}</option>` : ''}</select>
            </div>
            <div id="cnFNeu" class="crm-form-grid" style="${pre.firma_id ? 'display:none' : ''}">${crmFirmaFelder('cn_f_')}</div>
            <div class="crm-sec">Kontakt</div>
            <div class="crm-form-grid">${crmKontaktFelder({})}</div>
            ${crmCan('leads_edit') ? `<div class="crm-sec">Lead</div>
            <label class="crm-check"><md-checkbox id="cn_lead" checked onchange="document.getElementById('cnLead').style.display=this.checked?'':'none'"></md-checkbox> Direkt als SQL übernehmen und Lead anlegen (A3)</label>
            <div id="cnLead" class="crm-form-grid">
                <label class="crm-field">Sparte *<select id="cn_sparte" class="crm-input" data-f="lead.sparte">${crmOptions(crm.opt.sparten || [], '', '— wählen —')}</select></label>
                <label class="crm-field">Priorität<select id="cn_prio" class="crm-input">${crmOptions(crmList('prioritaet'), 'normal')}</select></label>
                ${crmCan('all') ? `<label class="crm-field">Owner<select id="cn_owner" class="crm-input" data-f="owner_id"><option value="auto" selected>Automatisch (Zuweisungsregel)</option>${crmOptions(crm.opt.owners || [], '')}</select></label>` : ''}
            </div>` : ''}
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  id="crmSaveBtn" onclick="crmKontaktAnlegen()">${crmIcon('save')} Anlegen</md-filled-button>`, 'modal-xl');
}
function crmFModus() {
    const m = document.querySelector('md-radio[name="cn_fmodus"][checked], input[name="cn_fmodus"]:checked')?.value;
    document.getElementById('cnFVorhanden').style.display = m === 'vorhanden' ? '' : 'none';
    document.getElementById('cnFNeu').style.display = m === 'neu' ? '' : 'none';
}
let _crmFirmaT = null;
function crmFirmaSuche(q) {
    clearTimeout(_crmFirmaT);
    _crmFirmaT = setTimeout(async () => {
        const r = await crmGet(CRM_API + '/firmen' + crmQs({ q }));
        const sel = document.getElementById('cn_firma_id');
        if (r.ok && sel) sel.innerHTML = (r.data || []).slice(0, 50).map(f => `<option value="${f.id}">${crmE(f.name + (f.ort ? ' · ' + [f.plz, f.ort].filter(Boolean).join(' ') : ''))}</option>`).join('');
    }, 300);
}
async function crmKontaktAnlegen(trotzdem = false) {
    const m = document.querySelector('md-radio[name="cn_fmodus"][checked], input[name="cn_fmodus"]:checked')?.value;
    const body = { ...crmKontaktDaten(), trotzdem, owner_id: crmV('cn_owner') || null };
    if (m === 'vorhanden') { body.firma_id = crmV('cn_firma_id') || null; if (!body.firma_id) return showToast('Firma auswählen', 'error'); }
    if (m === 'neu') body.firma = crmFirmaDaten('cn_f_');
    if (crmV('cn_lead')) body.lead = { sparte: crmV('cn_sparte'), prioritaet: crmV('cn_prio') };
    if (body.lead && !body.lead.sparte) return showToast('Sparte für den Lead wählen', 'error');
    const btn = document.getElementById('crmSaveBtn'); if (btn) btn.disabled = true;
    const r = await crmReq('POST', CRM_API + '/kontakte', body);
    if (btn) btn.disabled = false;
    if (r.status === 409 && r.data.dubletten) return crmDublettenHinweis(r.data.dubletten, () => crmKontaktAnlegen(true), true);
    if (crmFehler(r)) return;
    closeModal(); showToast(r.data.lead_id ? 'Kontakt + Lead angelegt' : 'Kontakt angelegt');
    if (r.data.lead_id) crmOpenDetail('lead', r.data.lead_id); else crmOpenDetail('kontakt', r.data.id);
}

/** A1 — show possible duplicates inline in the open dialog; "trotzdem" re-submits. */
function crmDublettenHinweis(list, weiter, imDialog = true) {
    document.getElementById('crmDupBox')?.remove();
    const html = `<div id="crmDupBox" class="crm-banner crm-banner-amber"><div><strong>${crmIcon('content_copy')} Mögliche Dublette (A1):</strong>
        ${list.map(t => `<div class="crm-dup">${crmE(t.art === 'firma' ? 'Firma' : 'Kontakt')}: <a href="#" onclick="closeModal();crmOpenDetail('${t.art}','${t.id}');return false">${crmE(t.name)}</a> ${t.firma ? '· ' + crmE(t.firma) : ''} <span class="crm-chip">${crmE(t.grund)}</span></div>`).join('')}
        <div class="crm-muted">Zusammenführen folgt in Phase 2 — vorhandenen Datensatz öffnen oder bewusst neu anlegen.</div>
        <md-outlined-button  class="md-btn-sm" id="crmDupGo">Trotzdem speichern</md-outlined-button></div></div>`;
    const body = document.getElementById('modalBody');
    if (imDialog && body) { body.insertAdjacentHTML('afterbegin', html); body.scrollTop = 0; document.getElementById('crmDupGo').onclick = weiter; }
}

// ============================================
// EINSTELLUNGEN (Admin) — MD3 fields, lists as .data-table-card
// ============================================
const CRM_LISTEN_LABEL = {
    lifecycle: ['Lifecycle Stages', 'Kontakt — bewegt sich nur vorwärts'], lead_stage: ['Lead-Stages', 'Lead-Arbeitsbereich, Regeln A4/A5'],
    deal_phase: ['Deal-Phasen', 'Pipeline — Wahrscheinlichkeit für den gewichteten Wert'], disq_grund: ['Disqualifizierungsgründe', 'Dialog „Disqualifizieren“'],
    verlust_grund: ['Verlustgründe', 'Deal → Verloren'], quelle: ['Quellen', 'Kontakt'], branche: ['Branchen', 'Firma'],
    prioritaet: ['Prioritäten', 'Lead'], anruf_ergebnis: ['Anruf-Ergebnisse', 'Dialog „Anruf protokollieren“'], aufgabe_typ: ['Aufgabentypen', 'Aufgaben am Lead/Deal'],
};
const CRM_FRISTEN = [
    ['a8_inaktiv_tage', 'Lead ohne Aktivität', 'Tage', 'A8 — danach „überfällig“'],
    ['a9_versuche', 'Kontaktversuche', 'Anzahl', 'A9 — dann „nicht erreichbar“ wählbar'],
    ['a9_tage', 'Zeitraum der Versuche', 'Tage', 'A9'],
    ['a7_vorlauf_tage', 'Wiedervorlage-Vorlauf', 'Tage', 'A7 — vor Vertragsende'],
    ['a2_rueckruf_arbeitstage', 'Rückruf Webformular', 'Arbeitstage', 'A2 — ab Phase 3'],
    ['dsgvo_pruefung_monate', 'DSGVO-Prüfung', 'Monate', 'disqualifizierte Kontakte ohne Aktivität'],
];

async function crmRenderSettings() {
    const body = document.getElementById('crmBody');
    // Already on screen (after adding / changing an entry): patch in place.
    const patch = body.dataset.frame === 'settings';
    delete body.dataset.detail;
    if (!patch) body.innerHTML = `<div class="crm-loading">${crmIcon('progress_activity')} Laden…</div>`;
    const r = await crmGet(CRM_API + '/einstellungen');
    if (crm.view !== 'settings' || crm.detail) return;
    if (!r.ok) { delete body.dataset.frame; body.innerHTML = `<div class="empty-state">${crmIcon('lock')}<p>${crmE(r.data?.error || 'Keine Berechtigung')}</p></div>`; return; }
    const s = r.data.einstellungen || {};
    crmZwInit(r.data);
    const groups = {};
    (r.data.listen || []).forEach(x => (groups[x.typ] = groups[x.typ] || []).push(x));
    body.dataset.frame = 'settings';
    crmPaint(body, `
        <div class="data-table-card crm-settings-card">
            <div class="crm-settings-head"><div>${crmIcon('timer')} <strong>Fristen & Schwellen</strong><div class="crm-sub">Gelten sofort für Listen, Markierungen und Dialoge — ohne Code-Änderung.</div></div>
                <md-filled-button onclick="crmSettingsSpeichern()"><md-icon slot="icon">save</md-icon>Speichern</md-filled-button></div>
            <div class="crm-settings-grid">
                ${CRM_FRISTEN.map(([k, l, unit, hint]) => `<md-outlined-text-field id="cs_${k}" type="number" min="0" label="${crmE(l)}" suffix-text="${crmE(unit)}" supporting-text="${crmE(hint)}" value="${crmE(s[k] ?? '')}"></md-outlined-text-field>`).join('')}
                <label class="crm-switch-row crm-span-all"><md-switch id="cs_automatik_aktiv" ${s.automatik_aktiv === '0' ? '' : 'selected'}></md-switch>
                    <span><strong>Automatik aktiv</strong><span class="crm-sub"> — täglich 06:45: A8 Nachfass-Aufgaben, A9 Hinweise, A12 Wiedervorlage-Leads, Aufgaben-Übersicht, DSGVO-Erinnerung</span></span></label>
                <md-outlined-text-field id="cs_einsatzgebiet" class="crm-span-all" label="Einsatzgebiet" supporting-text="Checkliste vor „Qualifiziert“, Punkt 3" maxlength="500" value="${crmE(s.einsatzgebiet || '')}"></md-outlined-text-field>
            </div>
        </div>
        ${crmZwCard()}
        ${crmWebformCard(r.data.webform, s)}
        <div class="crm-settings-lists">
        ${Object.entries(CRM_LISTEN_LABEL).map(([typ, [title, used]]) => `
            <div class="data-table-card crm-settings-card">
                <div class="crm-settings-head"><div><strong>${crmE(title)}</strong><div class="crm-sub">Verwendet in: ${crmE(used)}</div></div></div>
                <div class="crm-settings-tablewrap"><table class="crm-table crm-settings-table">
                    <thead><tr><th>Bezeichnung</th><th class="crm-num">Reihenfolge</th>${typ === 'deal_phase' ? '<th class="crm-num">%</th>' : ''}<th class="crm-num">Aktiv</th></tr></thead>
                    <tbody>${(groups[typ] || []).map(x => `<tr data-mk="l${x.id}">
                        <td><div class="crm-cell-name"><input class="crm-cell-input" value="${crmE(x.name)}" maxlength="120" onchange="crmListeUpdate(${x.id},{name:this.value})" title="Schlüssel: ${crmE(x.schluessel)}">
                            ${x.system ? `<span class="crm-chip" title="Wird von den Regeln benötigt — Schlüssel ${crmE(x.schluessel)}">System</span>` : ''}</div></td>
                        <td class="crm-num"><input type="number" class="crm-cell-input crm-cell-num" value="${x.reihenfolge}" onchange="crmListeUpdate(${x.id},{reihenfolge:+this.value})"></td>
                        ${typ === 'deal_phase' ? `<td class="crm-num"><input type="number" min="0" max="100" class="crm-cell-input crm-cell-num" value="${x.wahrscheinlichkeit ?? ''}" onchange="crmListeUpdate(${x.id},{wahrscheinlichkeit:this.value===''?null:+this.value})"></td>` : ''}
                        <td class="crm-num"><md-switch ${x.aktiv ? 'selected' : ''} ${x.system ? 'disabled' : ''} onchange="crmListeUpdate(${x.id},{aktiv:this.selected})"></md-switch></td></tr>`).join('')}
                    </tbody></table></div>
                ${(r.data.erweiterbar || []).includes(typ) ? `<div class="crm-add-row"><input class="crm-cell-input" id="cs_neu_${typ}" placeholder="Neuer Eintrag…" maxlength="120" onkeydown="if(event.key==='Enter')crmListeNeu('${typ}')">
                    <md-text-button onclick="crmListeNeu('${typ}')"><md-icon slot="icon">add</md-icon>Hinzufügen</md-text-button></div>` : ''}
            </div>`).join('')}
        </div>`);
}
// ---------- Zuweisungsregeln (§6) — one rule per Sparte ----------
let _crmZw = {}, _crmZwOwners = [];
function crmZwInit(data) {
    _crmZwOwners = data.owners || [];
    _crmZw = {};
    (data.sparten || []).forEach(sp => {
        const r = (data.zuweisung || {})[sp] || {};
        _crmZw[sp] = { modus: r.modus || '', user_ids: (r.user_ids || []).map(Number), plz_regeln: (r.plz_regeln || []).map(x => ({ ...x })), fest_user_id: r.fest_user_id || '' };
    });
}
function crmZwCard() {
    const sparten = Object.keys(_crmZw);
    return `
        <div class="data-table-card crm-settings-card">
            <div class="crm-settings-head"><div>${crmIcon('alt_route')} <strong>Zuweisung neuer Leads</strong>
                <div class="crm-sub">Gilt für Leads der Vertriebsleitung, Import und Automatik (A12). Vertriebler behalten selbst angelegte Leads. Ohne Regel: Vertriebsleitung.</div></div></div>
            ${sparten.length ? sparten.map((sp, i) => `<div class="crm-zw-row" id="crmZw${i}">${crmZwRow(sp, i)}</div>`).join('') : '<div class="crm-pad crm-muted" style="padding:16px">Keine Sparten (Bereiche) angelegt.</div>'}
        </div>`;
}
function crmZwRow(sp, i) {
    const z = _crmZw[sp], o = _crmZwOwners;
    const modi = [['', 'Keine Regel'], ['rotation', 'Rotation (Round-Robin)'], ['region', 'Region (PLZ)'], ['fest', 'Feste Person']];
    let body = '';
    if (z.modus === 'rotation') body = `<div class="crm-zw-pool">${o.map(u => `<label class="crm-check"><md-checkbox ${z.user_ids.includes(u.id) ? 'checked' : ''}
        onchange="crmZwPool(${i},${u.id},this.checked)"></md-checkbox> ${crmE(u.name)}</label>`).join('')}</div>`;
    if (z.modus === 'region') body = `<div class="crm-zw-regionen">${z.plz_regeln.map((r, j) => `<div class="crm-zw-region">
            PLZ <input class="crm-input crm-input-sm crm-zw-plz" maxlength="5" inputmode="numeric" value="${crmE(r.von)}" onchange="crmZwReg(${i},${j},'von',this.value)">
            bis <input class="crm-input crm-input-sm crm-zw-plz" maxlength="5" inputmode="numeric" value="${crmE(r.bis)}" onchange="crmZwReg(${i},${j},'bis',this.value)">
            → <select class="crm-input crm-input-sm" onchange="crmZwReg(${i},${j},'user_id',+this.value)">${crmOptions(o, r.user_id, '— Person —')}</select>
            <md-icon-button onclick="crmZwRegDel(${i},${j})" title="Entfernen"><md-icon>close</md-icon></md-icon-button></div>`).join('')}
        <md-text-button onclick="crmZwRegAdd(${i})"><md-icon slot="icon">add</md-icon>PLZ-Bereich</md-text-button></div>`;
    if (z.modus === 'fest') body = `<select class="crm-input crm-input-sm" onchange="crmZwSet(${i},'fest_user_id',+this.value)">${crmOptions(o, z.fest_user_id, '— Person wählen —')}</select>`;
    return `<div class="crm-zw-head">${crmSparte(sp)}
            <select class="crm-input crm-input-sm" onchange="crmZwSet(${i},'modus',this.value,true)">${modi.map(([k, l]) => `<option value="${k}" ${z.modus === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
            <md-text-button onclick="crmZwSpeichern(${i})"><md-icon slot="icon">save</md-icon>Speichern</md-text-button></div>
        ${body ? `<div class="crm-zw-body">${body}</div>` : ''}`;
}
const crmZwSp = i => Object.keys(_crmZw)[i];
function crmZwRedraw(i) { const el = document.getElementById('crmZw' + i); if (el) el.innerHTML = crmZwRow(crmZwSp(i), i); }
function crmZwSet(i, k, v, redraw = false) { _crmZw[crmZwSp(i)][k] = v; if (redraw) crmZwRedraw(i); }
function crmZwPool(i, id, on) { const z = _crmZw[crmZwSp(i)]; z.user_ids = on ? [...new Set([...z.user_ids, id])] : z.user_ids.filter(x => x !== id); }
function crmZwReg(i, j, k, v) { _crmZw[crmZwSp(i)].plz_regeln[j][k] = v; }
function crmZwRegAdd(i) { _crmZw[crmZwSp(i)].plz_regeln.push({ von: '', bis: '', user_id: '' }); crmZwRedraw(i); }
function crmZwRegDel(i, j) { _crmZw[crmZwSp(i)].plz_regeln.splice(j, 1); crmZwRedraw(i); }
async function crmZwSpeichern(i) {
    const sp = crmZwSp(i), z = _crmZw[sp];
    const r = await crmReq('PUT', CRM_API + '/zuweisung', { sparte: sp, modus: z.modus || null, user_ids: z.user_ids,
        plz_regeln: z.plz_regeln.filter(x => x.von || x.bis || x.user_id), fest_user_id: z.fest_user_id || null });
    if (crmFehler(r)) return;
    showToast(z.modus ? 'Zuweisung für ' + sp + ' gespeichert' : 'Regel für ' + sp + ' entfernt');
}

// ---------- Website-Formular (A2) — embed link + settings ----------
function crmWebformCard(w, s) {
    if (!w) return '';
    const aktiv = s.webform_aktiv === '1';
    return `
        <div class="data-table-card crm-settings-card">
            <div class="crm-settings-head"><div>${crmIcon('language')} <strong>Website-Formular (A2)</strong>
                <div class="crm-sub">Anfragen von der Website landen als Lead (Quelle „Website-Formular“, Dublettenprüfung A1, Zuweisungsregel §6). Einbetten per iframe oder Link — ein eigenes Formular braucht es nicht.</div></div>
                <md-switch id="cw_aktiv" ${aktiv ? 'selected' : ''}></md-switch></div>
            <div class="crm-form-grid" style="padding:0 16px 4px">
                <md-outlined-text-field id="cw_name" class="crm-span-2" label="Überschrift auf dem Formular" value="${crmE(s.webform_name || '')}" placeholder="z. B. Firmenname"></md-outlined-text-field>
                <label class="crm-field">Sparte für neue Leads<select id="cw_sparte" class="crm-input">${crmOptions(crm.opt.sparten || [], s.webform_sparte || '', '— automatisch —')}</select></label>
            </div>
            <div class="crm-pad" style="padding:10px 16px 14px">
                <div class="crm-sub">Formular-URL (geheim — nur an die Webagentur geben):</div>
                <div class="crm-webform-url"><input class="crm-input" readonly value="${crmE(w.url)}" onclick="this.select()">
                    <md-outlined-button onclick="crmCopy('${w.url}')"><md-icon slot="icon">content_copy</md-icon>Kopieren</md-outlined-button>
                    <md-outlined-button onclick="window.open('${w.url}','_blank')"><md-icon slot="icon">open_in_new</md-icon>Vorschau</md-outlined-button>
                    <md-outlined-button onclick="crmWebformRotate()"><md-icon slot="icon">autorenew</md-icon>Neuer Schlüssel</md-outlined-button></div>
                <div class="crm-sub" style="margin-top:8px">iframe-Snippet:</div>
                <div class="crm-webform-url"><input class="crm-input" readonly value="${crmE(w.iframe)}" onclick="this.select()"></div>
                ${aktiv ? '' : `<div class="crm-banner" style="margin-top:8px">${crmIcon('info')} Das Formular ist deaktiviert — die URL antwortet „nicht verfügbar“, bis der Schalter oben gesetzt und gespeichert wird.</div>`}
            </div>
        </div>`;
}
function crmCopy(t) {
    (navigator.clipboard?.writeText(t) || Promise.reject()).then(() => showToast('Kopiert')).catch(() => {
        const i = document.createElement('input'); i.value = t; document.body.appendChild(i); i.select(); document.execCommand('copy'); i.remove(); showToast('Kopiert');
    });
}
async function crmWebformRotate() {
    if (!await mdConfirm('Neuen Schlüssel erzeugen? Die bisherige Formular-URL funktioniert danach NICHT mehr.', { title: 'Schlüssel rotieren', confirmLabel: 'Rotieren' })) return;
    const r = await crmReq('POST', CRM_API + '/webform/rotate', {});
    if (crmFehler(r)) return;
    showToast('Neuer Schlüssel — bitte URL erneut kopieren'); crmRenderSettings();
}

async function crmSettingsSpeichern() {
    const body = {};
    [...CRM_FRISTEN.map(f => f[0]), 'einsatzgebiet'].forEach(k => body[k] = crmV('cs_' + k));
    body.automatik_aktiv = !!document.getElementById('cs_automatik_aktiv')?.selected;
    body.webform_aktiv = !!document.getElementById('cw_aktiv')?.selected;
    body.webform_name = crmV('cw_name');
    body.webform_sparte = crmV('cw_sparte');
    const r = await crmReq('PUT', CRM_API + '/einstellungen', body);
    if (r.ok) document.querySelectorAll('.crm-settings-grid md-outlined-text-field').forEach(el => { el.error = false; el.errorText = ''; });
    else Object.entries(r.data?.errors || {}).forEach(([k, m]) => { const el = document.getElementById('cs_' + k); if (el) { el.error = true; el.errorText = [].concat(m).join(' '); } });
    if (crmFehler(r)) return;
    showToast('Einstellungen gespeichert'); crmLoadOpt(true);
}
async function crmListeUpdate(id, patch) {
    const r = await crmReq('PUT', CRM_API + '/listen/' + id, patch);
    if (crmFehler(r)) { document.activeElement?.blur?.(); return crmRenderSettings(); }
    showToast('Gespeichert'); crmLoadOpt(true);
}
async function crmListeNeu(typ) {
    const name = crmV('cs_neu_' + typ);
    if (!name) return;
    const r = await crmReq('POST', CRM_API + '/listen', { typ, name });
    if (crmFehler(r)) return;
    const inp = document.getElementById('cs_neu_' + typ); if (inp) inp.value = '';
    showToast('Eintrag angelegt'); await crmLoadOpt(true); crmRenderSettings();
}

// ============================================
// PHASE 2 TOOLS — A1 Zusammenführen, CSV/Excel-Import (Vertriebsleitung / Admin)
// ============================================
function crmWerkzeug() { return crmCan('all') || crmCan('admin'); }

/** Merge a duplicate INTO the record that is open (it stays, the duplicate is soft-deleted). */
function crmMergeDialog(typ, zielId) {
    const istKontakt = typ === 'kontakt';
    openModal(istKontakt ? 'Kontakte zusammenführen (A1)' : 'Firmen zusammenführen (A1)', `
        <div class="crm-form">
            <div class="crm-banner">${crmIcon('info')} Die gewählte Dublette wird in <strong>diesen</strong> Datensatz übernommen: ${istKontakt
                ? 'Leads, Deals, Aktivitäten und Aufgaben wandern mit; leere Felder werden ergänzt. Ein zweiter offener Lead derselben Sparte wird als „Dublette“ geschlossen.'
                : 'Kontakte, Leads und Deals wandern mit; leere Felder werden ergänzt.'} Die Dublette bleibt im Protokoll erhalten.</div>
            <label class="crm-field">Dublette suchen<input class="crm-input" placeholder="${istKontakt ? 'Name, E-Mail, Telefon…' : 'Firmenname, PLZ, Ort…'}" oninput="crmMergeSuche('${typ}','${zielId}',this.value)"></label>
            <select id="cm_quelle" class="crm-input" size="7" data-f="quelle_id"></select>
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  id="crmSaveBtn" onclick="crmMergeAusfuehren('${typ}','${zielId}')">${crmIcon('merge')} Zusammenführen</md-filled-button>`);
}
let _crmMergeT = null;
function crmMergeSuche(typ, zielId, q) {
    clearTimeout(_crmMergeT);
    _crmMergeT = setTimeout(async () => {
        if (q.trim().length < 2) return;
        const r = await crmGet(CRM_API + (typ === 'kontakt' ? '/kontakte' : '/firmen') + crmQs({ q }));
        const sel = document.getElementById('cm_quelle');
        if (!r.ok || !sel) return;
        sel.innerHTML = (r.data || []).filter(x => x.id !== zielId).slice(0, 50).map(x => typ === 'kontakt'
            ? `<option value="${x.id}">${crmE([x.vorname, x.nachname].filter(Boolean).join(' ') + ' · ' + (x.firma_name || 'privat') + ' · ' + (x.email || x.telefon || x.mobil || ''))}</option>`
            : `<option value="${x.id}">${crmE(x.name + ' · ' + [x.plz, x.ort].filter(Boolean).join(' ') + ' · ' + x.kontakte + ' Kontakte')}</option>`).join('');
    }, 300);
}
async function crmMergeAusfuehren(typ, zielId) {
    const qid = crmV('cm_quelle');
    if (!qid) return showToast('Dublette auswählen', 'error');
    if (!await mdConfirm('Dublette jetzt in diesen Datensatz übernehmen? Das lässt sich nicht automatisch rückgängig machen.', { title: 'Zusammenführen', confirmLabel: 'Zusammenführen' })) return;
    const btn = document.getElementById('crmSaveBtn'); if (btn) btn.disabled = true;
    const r = await crmReq('POST', CRM_API + (typ === 'kontakt' ? '/kontakte/' : '/firmen/') + zielId + '/zusammenfuehren', { quelle_id: qid });
    if (btn) btn.disabled = false;
    if (crmFehler(r)) return;
    closeModal();
    showToast('Zusammengeführt' + (r.data.leads_dublette ? ` — ${r.data.leads_dublette} doppelter Lead als Dublette geschlossen` : ''));
    crmReloadDetail();
}

// ---------- Import wizard: 1 upload → 2 mapping + options → 3 result ----------
let _crmImport = null;
function crmImportDialog() {
    _crmImport = null;
    openModal('Kontakte importieren (CSV / Excel)', `
        <div class="crm-form">
            <div class="crm-banner">${crmIcon('info')} Erste Zeile = Spaltenüberschriften. Erlaubt: .csv (Trennzeichen ; oder ,), .xlsx, .xls — max. 10 MB,
                ${crmE(crm.opt.einstellungen?.import_max_zeilen || 2000)} Zeilen je Import. Jede Zeile wird auf Dubletten geprüft (A1, auch innerhalb der Datei).</div>
            <label class="crm-field">Datei<input id="ci_datei" type="file" class="crm-input" data-f="datei" accept=".csv,.txt,.xlsx,.xls"></label>
        </div>`,
        `<md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button  id="crmSaveBtn" onclick="crmImportVorschau()">${crmIcon('upload')} Weiter</md-filled-button>`, 'modal-xl');
}
async function crmImportVorschau() {
    const f = document.getElementById('ci_datei')?.files?.[0];
    if (!f) return showToast('Datei wählen', 'error');
    const fd = new FormData(); fd.append('datei', f);
    const btn = document.getElementById('crmSaveBtn'); if (btn) btn.disabled = true;
    const r = await crmReq('POST', CRM_API + '/import/vorschau', fd);
    if (btn) btn.disabled = false;
    if (crmFehler(r, 'Datei konnte nicht gelesen werden')) return;
    _crmImport = r.data;
    const v = r.data, colOpts = cur => `<option value="">— nicht importieren —</option>` + v.header.map((h, i) => `<option value="${i}" ${String(cur) === String(i) ? 'selected' : ''}>${crmE(h || 'Spalte ' + (i + 1))}</option>`).join('');
    const pflicht = { nachname: true };
    openModal('Import: Spalten zuordnen', `
        <div class="crm-form">
            <div class="crm-muted">${crmE(v.datei_name)} · ${v.zeilen} Datenzeilen${v.zu_viele ? ` — <strong class="crm-red">nur die ersten ${v.max_zeilen} werden importiert</strong>` : ''}</div>
            <div class="crm-import-grid">${Object.entries(v.felder).map(([k, l]) => `
                <label class="crm-field">${crmE(l)}${pflicht[k] ? ' *' : ''}<select class="crm-input" data-map="${k}" data-f="mapping.${k}">${colOpts(v.vorschlag[k] ?? '')}</select></label>`).join('')}
            </div>
            <div class="crm-sec">Vorschau</div>
            <div class="crm-import-preview"><table class="crm-proto"><thead><tr>${v.header.map(h => `<th>${crmE(h)}</th>`).join('')}</tr></thead>
                <tbody>${v.beispiel.map(row => `<tr>${v.header.map((_, i) => `<td>${crmE(row[i] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>
            <div class="crm-sec">Optionen</div>
            <div class="crm-form-grid">
                <label class="crm-field">Dubletten<select id="ci_dub" class="crm-input"><option value="ueberspringen" selected>überspringen (empfohlen)</option><option value="anlegen">trotzdem anlegen</option></select></label>
                <label class="crm-field">Quelle (wenn Spalte leer)<select id="ci_quelle" class="crm-input">${crmOptions(crmList('quelle'), 'import')}</select></label>
                <label class="crm-field">Owner<select id="ci_owner" class="crm-input"><option value="auto" selected>Automatisch (Zuweisungsregel)</option><option value="ich">Ich selbst</option>${crmOptions(crm.opt.owners || [], '')}</select></label>
            </div>
            <label class="crm-check"><md-checkbox id="ci_lead" onchange="document.getElementById('ciLead').style.display=this.checked?'':'none'"></md-checkbox> Für jeden Kontakt direkt einen Lead anlegen (Lifecycle SQL, Aufgabe „Erstkontakt“ — A3)</label>
            <div id="ciLead" class="crm-form-grid" style="display:none">
                <label class="crm-field">Sparte *<select id="ci_sparte" class="crm-input" data-f="lead.sparte">${crmOptions(crm.opt.sparten || [], '', '— wählen —')}</select></label>
                <label class="crm-field">Priorität<select id="ci_prio" class="crm-input">${crmOptions(crmList('prioritaet'), 'normal')}</select></label>
            </div>
        </div>`,
        `<md-outlined-button  onclick="crmImportDialog()">Zurück</md-outlined-button><md-filled-button  id="crmSaveBtn" onclick="crmImportAusfuehren()">${crmIcon('play_arrow')} Import starten</md-filled-button>`, 'modal-xl');
}
async function crmImportAusfuehren() {
    const v = _crmImport; if (!v) return;
    const mapping = {};
    document.querySelectorAll('[data-map]').forEach(el => { if (el.value !== '') mapping[el.dataset.map] = +el.value; });
    if (mapping.nachname === undefined) return showToast('Die Spalte „Nachname“ zuordnen', 'error');
    const body = { token: v.token, datei_name: v.datei_name, mapping, dubletten: crmV('ci_dub'), quelle: crmV('ci_quelle'), owner: crmV('ci_owner'),
        lead: crmV('ci_lead') ? { anlegen: true, sparte: crmV('ci_sparte'), prioritaet: crmV('ci_prio') } : { anlegen: false } };
    if (body.lead.anlegen && !body.lead.sparte) return showToast('Sparte für die Leads wählen', 'error');
    const btn = document.getElementById('crmSaveBtn'); if (btn) { btn.disabled = true; btn.innerHTML = `${crmIcon('progress_activity')} Import läuft…`; }
    const r = await crmReq('POST', CRM_API + '/import/ausfuehren', body);
    if (btn) { btn.disabled = false; btn.innerHTML = `${crmIcon('play_arrow')} Import starten`; }
    if (crmFehler(r, 'Import fehlgeschlagen')) return;
    const d = r.data;
    openModal('Import abgeschlossen', `
        <div class="crm-form">
            <div class="crm-import-sum">
                <div><strong>${d.angelegt}</strong><span>Kontakte angelegt</span></div>
                <div><strong>${d.firmen_neu}</strong><span>neue Firmen</span></div>
                <div><strong>${d.leads}</strong><span>Leads</span></div>
                <div><strong>${d.dubletten}</strong><span>Dubletten übersprungen</span></div>
                <div class="${d.fehler ? 'crm-red' : ''}"><strong>${d.fehler}</strong><span>Fehler</span></div>
            </div>
            ${d.fehler_liste?.length ? `<div class="crm-sec">Fehlerhafte Zeilen</div><div class="crm-import-preview"><table class="crm-proto"><tbody>
                ${d.fehler_liste.map(f => `<tr><td>Zeile ${f.zeile}</td><td>${crmE(f.grund)}</td></tr>`).join('')}</tbody></table></div>` : ''}
        </div>`,
        `<md-filled-button  onclick="closeModal();crmSetView('stamm')">Fertig</md-filled-button>`);
}

// ============================================
// EXPORT (Admin) — exactly the rows on screen
// ============================================
function crmExport() {
    if (typeof downloadXlsx !== 'function') return showToast('Export nicht verfügbar', 'error');
    const stamp = crmToday();
    if (crm.view === 'deals') {
        return downloadXlsx('Deals_' + stamp, 'Deals', ['Titel', 'Firma', 'Kontakt', 'Sparte', 'Phase', 'Monatlich €', 'Laufzeit', 'Gesamtwert €', 'Wahrscheinlichkeit %', 'Gewichtet €', 'Erw. Abschluss', 'Owner'],
            crm.deals.map(d => [d.titel, d.firma_name || '', d.kontakt_name, d.sparte || '', d.phase_name, d.wert_monatlich_eur != null ? +d.wert_monatlich_eur : '', d.laufzeit_monate ?? '', d.gesamtwert_eur ?? '', d.wahrscheinlichkeit, d.gewichtet_eur ?? '', d.erwarteter_abschluss || '', d.owner_name]));
    }
    if (crm.view === 'stamm') {
        return crm.sf.art === 'firmen'
            ? downloadXlsx('Firmen_' + stamp, 'Firmen', ['Firma', 'Straße', 'PLZ', 'Ort', 'Branche', 'Kontakte', 'Offene Leads', 'Offene Deals', 'Kunde', 'Owner'],
                crm.stamm.map(f => [f.name, f.strasse || '', f.plz || '', f.ort || '', crmName('branche', f.branche), f.kontakte, f.offene_leads, f.offene_deals, f.kunde_id || '', f.owner_name || '']))
            : downloadXlsx('Kontakte_' + stamp, 'Kontakte', ['Anrede', 'Vorname', 'Nachname', 'Firma', 'Position', 'E-Mail', 'Telefon', 'Mobil', 'Lifecycle', 'Quelle', 'Werbe-Einwilligung', 'Owner'],
                crm.stamm.map(k => [k.anrede || '', k.vorname || '', k.nachname, k.firma_name || '', k.position || '', k.email || '', k.telefon || '', k.mobil || '', crmName('lifecycle', k.lifecycle_stage), crmName('quelle', k.quelle), k.dsgvo_einwilligung ? 'ja' : 'nein', k.owner_name || '']));
    }
    downloadXlsx('Leads_' + stamp, 'Leads', ['Firma', 'Kontakt', 'Sparte', 'Stage', 'Tage in Stage', 'Letzte Aktivität', 'Nächste Aufgabe', 'Kontaktversuche', 'Priorität', 'Owner', 'Überfällig'],
        crm.leads.map(r => [r.firma_name || '', r.kontakt_name, r.sparte || '', r.stage_name, r.tage_in_stage, r.letzte_aktivitaet_am || '', r.naechste_aufgabe_am || '', r.anzahl_kontaktversuche, crmName('prioritaet', r.prioritaet), r.owner_name, r.ueberfaellig ? 'ja' : '']));
}
