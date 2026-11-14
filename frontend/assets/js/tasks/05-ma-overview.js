// tasks/05-ma-overview.js — Monatsabschluss: State, Laden, Bento, Toolbar, Übersicht, Soll/Ist-Tabelle
// Teil 5/10 des früheren tasks.js. Alle Teile teilen den globalen Scope und MÜSSEN in dieser Reihenfolge geladen werden (PAGE_MODULE_SCRIPTS in app.js).

/* ============================================================
   MONATSABSCHLUSS PAGE — Phase A + B + Analytics v2
   Identity: Forest Green (#15803d) + Warm Gold (#ca8a04)
   Backend logic UNCHANGED — only UX/visual revamp + new features
   ============================================================ */

let _maPageMonth = new Date().getMonth() + 1;
let _maPageYear = new Date().getFullYear();
let _maPageWorkers = {};      // Internal workers cache (keyed by dienst_nr) — used for PDFs
let _maPageNuWorkers = {};    // NU workers cache (grouped by firma) — used for PDFs
let _maData = null;           // Last fetched API response (for Analytics + Export)
let _maTab = 'overview';      // 'overview' | 'analytics'
let _maMode = 'ma';           // 'ma' = Mitarbeiter (worker app) | 'kunde' = Leistungsnachweis (Bestellungen)
let _maFilter = 'all';        // 'all' | 'internal' | 'nu' | 'anomaly'
let _maBereich = '';          // Bereich filter
let _maSort = 'name';         // 'name' | 'std' | 'entries' | 'bereich'
let _maSearch = '';
let _maFilterKunde = '';      // Kunde scope filter — worker worked for this customer
let _maFilterObjekt = '';     // Objekt scope filter — worker worked at this object
let _maLinkedSrc = null;      // 'kunde' | 'objekt' — suppresses auto-fill on the field the user just cleared
let _maCharts = {};           // Chart.js instances
// Date range mode: when active, the page queries a custom ?von=&bis= window
// instead of the single-month default (?monat=&jahr=).
let _maRangeMode = false;
let _maRangeStart = '';       // ISO YYYY-MM-DD
let _maRangeEnd = '';         // ISO YYYY-MM-DD

// Anomaly detection rules
const MA_ARBZG_MAX_HRS = 10;                     // § 3 ArbZG: max. 10 h Arbeitszeit am Tag
const MA_ARBZG_PAUSE = [[9, 45], [6, 30]];       // § 4 ArbZG: > 9 h → 45 Min, > 6 h → 30 Min

// ---------- MD3 helpers (Material Symbols) for the Monatsabschluss page ----------
function maIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}
const maSpinner = (size = 32) => `<md-circular-progress indeterminate style="--md-circular-progress-size:${size}px"></md-circular-progress>`;

// ---------- Flicker-free updates: morph the live DOM to new markup ----------
// Instead of innerHTML/outerHTML (which recreates every node — images,
// web components, scroll anchors — and visibly blinks), only the nodes that
// actually differ are touched. Keyed children (worker blocks: data-wkey,
// else id) are matched by key so a re-sort moves nodes instead of rebuilding.
// opts.skipEditing keeps blocks the user is editing untouched (no lost input).
function maMorphKey(n) {
    return n.nodeType === 1 ? (n.getAttribute('data-wkey') || n.id || null) : null;
}
function maMorphNode(from, to, opts) {
    if (from.nodeType !== to.nodeType || from.nodeName !== to.nodeName || maMorphKey(from) !== maMorphKey(to)) {
        from.replaceWith(to);
        return;
    }
    if (from.nodeType === 3 || from.nodeType === 8) {
        if (from.nodeValue !== to.nodeValue) from.nodeValue = to.nodeValue;
        return;
    }
    if (from.nodeType !== 1) return;
    if (opts && opts.skipEditing && (from.classList.contains('editing') || from.classList.contains('ma-row-editing'))) {
        const fb = from.querySelector('.ma-freigabe-btn'), tb = to.querySelector('.ma-freigabe-btn');
        if (fb && tb) maMorphNode(fb, tb);
        return;
    }
    // Attributes
    for (const a of [...from.attributes]) if (!to.hasAttribute(a.name)) from.removeAttribute(a.name);
    for (const a of [...to.attributes]) if (from.getAttribute(a.name) !== a.value) from.setAttribute(a.name, a.value);
    const tag = from.nodeName;
    if ((tag === 'INPUT' || tag === 'TEXTAREA') && from !== document.activeElement) {
        const v = to.getAttribute('value') ?? (tag === 'TEXTAREA' ? to.textContent : '');
        if (from.value !== v) from.value = v;
    }
    const wantSelect = tag === 'SELECT' ? to.value : null;
    maMorphChildren(from, to, opts);
    if (tag === 'SELECT' && from !== document.activeElement && from.value !== wantSelect) from.value = wantSelect;
}
function maMorphChildren(from, to, opts) {
    const oldKids = [...from.childNodes];
    const keyed = new Map();
    oldKids.forEach(n => { const k = maMorphKey(n); if (k) keyed.set(k, n); });
    const used = new Set();
    let cursor = 0;
    let pos = 0;   // children 0..pos-1 of `from` are already final
    for (const nn of [...to.childNodes]) {
        const k = maMorphKey(nn);
        let match = null;
        if (k && keyed.has(k) && !used.has(keyed.get(k))) {
            match = keyed.get(k);
        } else if (!k) {
            while (cursor < oldKids.length && (used.has(oldKids[cursor]) || maMorphKey(oldKids[cursor]))) cursor++;
            if (cursor < oldKids.length) match = oldKids[cursor];
        }
        const ref = from.childNodes[pos] || null;
        if (match) {
            used.add(match);
            if (match !== ref) from.insertBefore(match, ref);
            maMorphNode(match, nn, opts);   // may replace `match` in place — position stays
        } else {
            from.insertBefore(nn, ref);
        }
        pos++;
    }
    oldKids.forEach(n => { if (!used.has(n) && n.parentNode === from) n.remove(); });
}
/** Replace the CONTENT of `container` with `html`, touching only what changed. */
function maMorphInner(container, html, opts) {
    if (!container) return;
    if (!container.firstChild) { container.innerHTML = html; return; }
    const tpl = document.createElement('template');
    tpl.innerHTML = html;
    maMorphChildren(container, tpl.content, opts);
}
/** Re-render the toolbar in place (keeps focus, listeners and scroll). */
function maRefreshToolbar() {
    maRefreshModeTabs();
    const tb = document.querySelector('.ma-page .ma-toolbar');
    if (!tb) return;
    const tpl = document.createElement('template');
    tpl.innerHTML = maBuildToolbar().trim();
    maMorphNode(tb, tpl.content.firstElementChild);
    maBindToolbar();
    maTrackToolbarHeight();
}

function renderMonatsabschlussPage() {
    _maPageMonth = new Date().getMonth() + 1;
    _maPageYear = new Date().getFullYear();
    _maTab = 'overview';
    _maMode = 'ma';
    _maFilter = 'all';
    _maBereich = '';
    _maSort = 'name';
    _maSearch = '';
    _maFilterKunde = '';
    _maFilterObjekt = '';
    _maLinkedSrc = null;
    _maRangeMode = false;
    _maRangeStart = '';
    _maRangeEnd = '';
    // One-shot preset from Bestellungen ("Zur Kunden-Freigabe").
    const preset = window._maPreset; window._maPreset = null;
    if (preset) {
        if (preset.mode === 'kunde') _maMode = 'kunde';
        if (preset.monat >= 1 && preset.monat <= 12 && preset.jahr > 2000) { _maPageMonth = preset.monat; _maPageYear = preset.jahr; }
        if (preset.kunde) { _maFilterKunde = preset.kunde; _maLinkedSrc = 'kunde'; }
    }
    renderMonatsabschlussContent();
}

async function renderMonatsabschlussContent(opts) {
    const silent = !!(opts && opts.silent);   // background refresh (realtime): no dimming
    const content = document.getElementById('content');
    if (!content) return;

    // Mount/refresh the month-range navigator in the global topbar.
    maMountTopbarNav();

    // Soft refresh: the page is already on screen (month/range change etc.)
    // → keep it visible, just dim the body while the new data loads, then
    // morph to the result. Only the very first paint shows the spinner.
    const soft = !!(content.querySelector('.ma-page') && document.getElementById('maMainBody'));
    if (!soft) {
        content.innerHTML = `
        <div class="ma-page ma-theme-${_maMode}">
            <div id="maBentoWrap"></div>
            ${maBuildToolbar()}
            <div id="maMainBody">
                <div class="ma-loading">${maSpinner(28)} Lade Daten...</div>
            </div>
        </div>`;
    } else if (!silent) {
        maRefreshToolbar();
        const mb = document.getElementById('maMainBody');
        mb.style.transition = 'opacity .15s';
        mb.style.opacity = '.55';
        mb.style.pointerEvents = 'none';
    }
    const seq = (renderMonatsabschlussContent._seq = (renderMonatsabschlussContent._seq || 0) + 1);

    // Fetch data — use ?von=&bis= when range mode is active, otherwise monthly defaults.
    const apiUrl = _maRangeMode && _maRangeStart && _maRangeEnd
        ? API.disposition + '/completed-workers?von=' + encodeURIComponent(_maRangeStart) + '&bis=' + encodeURIComponent(_maRangeEnd)
        : API.disposition + '/completed-workers?monat=' + _maPageMonth + '&jahr=' + _maPageYear;
    const [data] = await Promise.all([apiGet(apiUrl), maLoadBranding()]);
    if (seq !== renderMonatsabschlussContent._seq) return;   // a newer navigation superseded this one
    const mainBody = document.getElementById('maMainBody');
    if (!mainBody) return;
    mainBody.style.opacity = '';
    mainBody.style.pointerEvents = '';

    // A failed background refresh keeps the page as it is.
    if (silent && (!data || data.error || Array.isArray(data))) return;
    // Someone started editing while the background refresh was loading.
    if (silent && maHasOpenEdit()) { maRealtimeSchedule(); return; }

    if (!data || data.error) {
        document.getElementById('maBentoWrap').innerHTML = '';
        mainBody.innerHTML = `<div class="ma-empty">${maIcon('warning')}<p>Fehler beim Laden</p><div class="ma-empty-sub">Bitte später erneut versuchen.</div></div>`;
        return;
    }
    _maData = data;

    // Cache workers for PDFs (kept for backward compatibility with PDF builders)
    const entries = data.entries || [];
    const nuEntries = data.nu_entries || [];
    _maPageWorkers = maGroupInternalWorkers(entries);
    _maPageNuWorkers = maGroupNuWorkers(nuEntries);

    // Render bento
    maMorphInner(document.getElementById('maBentoWrap'), maBuildBento(data));
    // Toolbar was rendered in the skeleton before data arrived — refresh it
    // now so the Kunde/Objekt scope selects carry real options.
    maRefreshToolbar();

    maShowActiveTab();
}

// ============================================================
// REALTIME — approvals / edits by other office users and worker
// clock-outs ('dispo' events) refresh the page in the background, so
// "Alle freigeben / zurücknehmen" never acts on a stale state. Deferred
// while a block is being edited (unsaved input is never discarded).
// ============================================================
let _maRealtimeTimer = null;

function maHasOpenEdit() {
    return !!document.querySelector('tr.ma-row-editing');
}

function maRealtimeSchedule() {
    clearTimeout(_maRealtimeTimer);
    _maRealtimeTimer = setTimeout(() => {
        if (typeof currentPage === 'undefined' || currentPage !== 'monatsabschluss' || !_maData) return;
        if (maHasOpenEdit() || document.hidden) { maRealtimeSchedule(); return; }
        renderMonatsabschlussContent({ silent: true });
    }, 1500);
}

if (window.HMSRealtime) {
    HMSRealtime.on('dispo', () => {
        if (typeof currentPage !== 'undefined' && currentPage === 'monatsabschluss') maRealtimeSchedule();
    });
}

/** Render the active tab from the already-loaded _maData (no refetch). */
function maShowActiveTab() {
    if (_maTab === 'analytics') {
        maRenderAnalytics();
    } else {
        maDestroyCharts();
        maRenderOverview();
    }
}

// ============================================================
// DATA GROUPING
// ============================================================
function maGroupInternalWorkers(entries) {
    const byWorker = {};
    entries.forEach(e => {
        if (!byWorker[e.dienst_nr]) {
            byWorker[e.dienst_nr] = {
                vorname: e.vorname, name: e.name, foto: e.foto,
                bereich: e.bereich, dienst_nr: e.dienst_nr,
                aktiv: e.mitarbeiter_aktiv,
                entries: []
            };
        }
        byWorker[e.dienst_nr].entries.push(e);
    });
    return byWorker;
}

function maGroupNuWorkers(nuEntries) {
    const nuByCompany = {};
    nuEntries.forEach(e => {
        const firma = e.nu_firmenname || 'Unbekannt';
        const wKey = e.nu_worker_id;
        if (!nuByCompany[firma]) nuByCompany[firma] = {};
        if (!nuByCompany[firma][wKey]) {
            nuByCompany[firma][wKey] = {
                vorname: e.vorname, name: e.name, foto: e.foto,
                personal_nr: e.personal_nr, nu_id: e.nu_id,
                nu_firmenname: firma, nu_worker_id: e.nu_worker_id,
                entries: []
            };
        }
        nuByCompany[firma][wKey].entries.push(e);
    });
    return nuByCompany;
}

// ============================================================
// HEADER + BENTO + TOOLBAR
// ============================================================
// The Monatsabschluss page used to render its own green hero header inside the
// content area. The month/range controls now live in the global topbar beside
// #pageTitle (see maMountTopbarNav), so this function returns an empty string
// and the in-page header has been removed entirely.
function maBuildHeader() {
    return '';
}

// Inject the month/range navigation into the global topbar right next to
// "Monatsabschluss — Mitarbeiter". The injector is removed on page leave by
// app.js showPage() so it does not bleed into other pages.
function maMountTopbarNav() {
    const topbar = document.querySelector('.topbar');
    const pageTitle = document.getElementById('pageTitle');
    if (!topbar || !pageTitle) return;

    let inj = document.getElementById('maNavInjector');
    if (!inj) {
        inj = document.createElement('div');
        inj.id = 'maNavInjector';
        inj.className = 'ma-nav-injector';
        pageTitle.insertAdjacentElement('afterend', inj);
    }

    const periodLabel = _maRangeMode && _maRangeStart && _maRangeEnd
        ? wpFormatDate(_maRangeStart) + ' – ' + wpFormatDate(_maRangeEnd)
        : MONTHS_FULL[_maPageMonth] + ' ' + _maPageYear;
    const navDisabled = _maRangeMode ? 'disabled' : '';
    const todayLabel = _maRangeMode ? 'Aktueller Monat' : 'Heute';
    const todayTitle = _maRangeMode ? 'Auf aktuellen Monat zurücksetzen' : 'Zum aktuellen Monat springen';

    inj.innerHTML = `
        <div class="ma-topbar-stack">
        <div class="ma-tabs ma-mode-switch${_maMode === 'kunde' ? ' is-kunde' : ''}" id="maModeTabs" role="tablist" aria-label="Freigabe für">${maBuildModeTabs()}</div>
        <div class="ma-month-nav">
            <button onclick="maPageChangeMonth(-1)" title="Vorheriger Monat" ${navDisabled}>${maIcon('chevron_left', 'font-size:16px')}</button>
            <span class="ma-month-label">${periodLabel}</span>
            <button onclick="maPageChangeMonth(1)" title="Nächster Monat" ${navDisabled}>${maIcon('chevron_right', 'font-size:16px')}</button>
            <button class="ma-today-btn" onclick="maPageGoCurrentMonth()" title="${todayTitle}">${maIcon('today', 'font-size:14px')} ${todayLabel}</button>
            <button class="ma-range-btn ${_maRangeMode ? 'active' : ''}" onclick="maToggleRangePicker(event)" title="Zeitraum wählen">${maIcon('date_range', 'font-size:14px')} Zeitraum</button>
            <div id="maRangePopover" class="ma-range-popover" style="display:none"></div>
        </div>
        </div>`;
}

// Remove the topbar injector. Called from app.js showPage() before leaving the
// Monatsabschluss page so the nav doesn't show up on other pages.
function maUnmountTopbarNav() {
    const inj = document.getElementById('maNavInjector');
    if (inj) inj.remove();
}

// The toolbar is position:sticky under the topbar; the quick-jump sidebar
// must stick below it. Its height changes when rows wrap (tablet), so keep
// --ma-toolbar-height in sync via ResizeObserver.
let _maToolbarRO = null;
function maTrackToolbarHeight() {
    const tb = document.querySelector('.ma-toolbar');
    if (!tb) return;
    if (_maToolbarRO) _maToolbarRO.disconnect();
    const sync = () => document.documentElement.style.setProperty('--ma-toolbar-height', tb.offsetHeight + 'px');
    if ('ResizeObserver' in window) {
        _maToolbarRO = new ResizeObserver(sync);
        _maToolbarRO.observe(tb);
    }
    sync();
}

function maBuildBento(data) {
    const totalWorkers = (data.total_workers || 0) + (data.nu_total_workers || 0);
    const internalWorkers = data.total_workers || 0;
    const nuWorkers = data.nu_total_workers || 0;
    const totalEntries = (data.total_entries || 0) + (data.nu_total_entries || 0);
    const totalStd = (data.total_std || 0) + (data.nu_total_std || 0);
    const internalStd = data.total_std || 0;
    const nuStd = data.nu_total_std || 0;

    // Anomaly counter
    let anomalyCount = 0;
    [...(data.entries || []), ...(data.nu_entries || [])].forEach(e => {
        if (maDetectAnomalies(e).length > 0) anomalyCount++;
    });

    // The header badge ("X MA · Y Einsätze") used to live in the in-page hero
    // header which has been removed. The Bento KPIs below already convey the
    // same information, so the badge update is no longer needed.

    const allRows = [...(data.entries || []), ...(data.nu_entries || [])];
    const sollAll = allRows.reduce((s, e) => s + maSollStd(e), 0);
    const istAll = allRows.reduce((s, e) => s + (maIstStd(e) || 0), 0);
    const istMissing = allRows.filter(e => maIstStd(e) == null).length;

    // Average per worker
    const avgStdPerWorker = totalWorkers > 0 ? (totalStd / totalWorkers).toFixed(1) : '0';
    // NU share
    const nuSharePct = totalStd > 0 ? Math.round((nuStd / totalStd) * 100) : 0;
    // Kunde mode: no PDF export here — customer documents (Leistungsnachweis)
    // are produced in Bestellungen. The tile slot shows the approval progress
    // instead, which is what this mode is for.
    const freiCount = allRows.filter(e => parseInt(e.kunden_freigabe) === 1).length;
    const freiMissing = totalEntries - freiCount;

    return `
        <div class="ma-bento">
            <div class="ma-kpi" data-clickable="1" onclick="maSetFilter('all')">
                <div class="ma-kpi-head">
                    <div class="ma-kpi-icon">${maIcon('group')}</div>
                    <span class="ma-kpi-label">Mitarbeiter</span>
                </div>
                <div class="ma-kpi-value">${totalWorkers}</div>
                <div class="ma-kpi-sub">${internalWorkers} intern${nuWorkers > 0 ? ` · ${nuWorkers} NU` : ''}</div>
            </div>
            <div class="ma-kpi gold">
                <div class="ma-kpi-head">
                    <div class="ma-kpi-icon">${maIcon('done_all')}</div>
                    <span class="ma-kpi-label">Bestätigte Einsätze</span>
                </div>
                <div class="ma-kpi-value">${totalEntries}</div>
                <div class="ma-kpi-sub">Einsätze in ${MONTHS_FULL[_maPageMonth]}</div>
            </div>
            <div class="ma-kpi">
                <div class="ma-kpi-head">
                    <div class="ma-kpi-icon">${maIcon('schedule')}</div>
                    <span class="ma-kpi-label">Gesamt Stunden</span>
                </div>
                <div class="ma-kpi-value">${totalStd.toFixed(1)}<span class="ma-kpi-value-suffix">h</span></div>
                <div class="ma-kpi-sub">Ø ${avgStdPerWorker} h / MA</div>
            </div>
            <div class="ma-kpi" title="Ist = in der App erfasst · Soll = Bestelleingang${istMissing ? ` · ${istMissing} Einsätze ohne Ist` : ''}">
                <div class="ma-kpi-head">
                    <div class="ma-kpi-icon">${maIcon('compare_arrows')}</div>
                    <span class="ma-kpi-label">Ist-Stunden</span>
                </div>
                <div class="ma-kpi-value">${istAll.toFixed(1)}<span class="ma-kpi-value-suffix">h</span></div>
                <div class="ma-kpi-sub">Soll ${sollAll.toFixed(1)} h</div>
            </div>
            <div class="ma-kpi nu" data-clickable="1" onclick="maSetFilter('nu')">
                <div class="ma-kpi-head">
                    <div class="ma-kpi-icon">${maIcon('apartment')}</div>
                    <span class="ma-kpi-label">NU-Anteil</span>
                </div>
                <div class="ma-kpi-value">${nuSharePct}<span class="ma-kpi-value-suffix">%</span></div>
                <div class="ma-progress"><div class="ma-progress-fill" style="width:${nuSharePct}%;background:var(--ma-nu)"></div></div>
                <div class="ma-kpi-sub">${nuStd.toFixed(1)} h von ${totalStd.toFixed(1)} h</div>
            </div>
            <div class="ma-kpi ${anomalyCount > 0 ? 'warn' : ''}" ${anomalyCount > 0 ? 'data-clickable="1" onclick="maSetFilter(\'anomaly\')"' : ''}>
                <div class="ma-kpi-head">
                    <div class="ma-kpi-icon">${maIcon('warning')}</div>
                    <span class="ma-kpi-label">Hinweise</span>
                </div>
                <div class="ma-kpi-value">${anomalyCount}</div>
                <div class="ma-kpi-sub">${anomalyCount === 0 ? 'Keine Auffälligkeiten' : 'benötigen Prüfung'}</div>
            </div>
            ${_maMode === 'kunde' ? `
            <div class="ma-kpi${freiMissing > 0 ? ' warn' : ' gold'}" title="Für den Leistungsnachweis freigegebene Einsätze">
                <div class="ma-kpi-head">
                    <div class="ma-kpi-icon">${maIcon('receipt_long')}</div>
                    <span class="ma-kpi-label">Freigabe Kunde</span>
                </div>
                <div class="ma-kpi-value">${freiCount}<span class="ma-kpi-value-suffix">/${totalEntries}</span></div>
                <div class="ma-kpi-sub">${freiMissing > 0 ? freiMissing + ' offen' : 'Alle freigegeben'}</div>
            </div>` : ''}
        </div>`;
}

function maBuildToolbar() {
    const scope = maResolveScopeLists();
    const scopeOpts = (list, cur) => list.map(v =>
        `<option value="${escapeHtml(v)}"${v === cur ? ' selected' : ''}>${escapeHtml(v)}</option>`
    ).join('');
    return `
        <div class="ma-toolbar${_maMode === 'kunde' ? ' is-kunde' : ''}">
            <div class="ma-toolbar-top">
                <div class="ma-tabs">
                    <button class="ma-tab ${_maTab === 'overview' ? 'active' : ''}" onclick="maSetTab('overview')">${maIcon('checklist', 'font-size:14px')} Übersicht</button>
                    <button class="ma-tab ${_maTab === 'analytics' ? 'active' : ''}" onclick="maSetTab('analytics')">${maIcon('bar_chart', 'font-size:14px')} Analytics</button>
                </div>
                ${_maMode === 'kunde' ? '' : `
                <div class="ma-toolbar-actions">
                    <md-outlined-button onclick="maOpenExportDialog('xlsx')"><md-icon slot="icon">csv</md-icon>Excel Export</md-outlined-button>
                    <md-filled-button onclick="maOpenExportDialog('pdf')" style="--md-filled-button-container-color:var(--ma-accent,#ca8a04);--md-filled-button-label-text-color:var(--ma-on-primary,#fff);--md-filled-button-icon-color:var(--ma-on-primary,#fff)"><md-icon slot="icon">picture_as_pdf</md-icon>PDFs</md-filled-button>
                </div>`}
            </div>
            <div class="ma-toolbar-row" id="maToolbarFilters">
                <md-outlined-text-field class="ma-search" id="maSearch" placeholder="Mitarbeiter suchen..." value="${escapeHtml(_maSearch)}">
                    <md-icon slot="leading-icon">search</md-icon>
                </md-outlined-text-field>
                <md-chip-set id="maFilterPills">
                    <md-filter-chip label="Alle" data-filter="all" ${_maFilter === 'all' ? 'selected' : ''} onclick="maSetFilter('all')"></md-filter-chip>
                    <md-filter-chip label="Intern" data-filter="internal" ${_maFilter === 'internal' ? 'selected' : ''} onclick="maSetFilter('internal')"></md-filter-chip>
                    <md-filter-chip label="NU" data-filter="nu" ${_maFilter === 'nu' ? 'selected' : ''} onclick="maSetFilter('nu')"></md-filter-chip>
                    <md-filter-chip label="Hinweise" data-filter="anomaly" ${_maFilter === 'anomaly' ? 'selected' : ''} onclick="maSetFilter('anomaly')"></md-filter-chip>
                </md-chip-set>
                <select class="ma-scope-select${_maFilterKunde ? ' active' : ''}" id="maScopeKunde" onchange="maScopeChange('kunde', this.value)" title="Nach Kunde filtern">
                    <option value="">Alle Kunden</option>
                    ${scopeOpts(scope.kunden, _maFilterKunde)}
                </select>
                <select class="ma-scope-select${_maFilterObjekt ? ' active' : ''}" id="maScopeObjekt" onchange="maScopeChange('objekt', this.value)" title="Nach Objekt filtern">
                    <option value="">Alle Objekte</option>
                    ${scopeOpts(scope.objekte, _maFilterObjekt)}
                </select>
            </div>
        </div>`;
}

// Mitarbeiter | Kunde switch — lives in the global topbar above the month
// navigation (maMountTopbarNav).
function maBuildModeTabs() {
    const tab = (mode, icon, label, title) => {
        const on = _maMode === mode;
        return `<button type="button" class="ma-tab ${mode}${on ? ' active' : ''}" role="tab" aria-selected="${on}" onclick="maSetMode('${mode}')" title="${title}">${maIcon(icon, 'font-size:14px')} ${label}</button>`;
    };
    return tab('ma', 'badge', 'Mitarbeiter', 'Zeiten der Mitarbeiter — Freigabe geht in die Mitarbeiter-App')
        + tab('kunde', 'apartment', 'Kunde', 'Werte für den Kunden — Freigabe geht in den Leistungsnachweis (Bestellungen)');
}

// Page-wide colour theme of the active mode (green = Mitarbeiter, blue = Kunde),
// see "MODE THEME" in monatsabschluss.css.
function maApplyModeTheme() {
    const page = document.querySelector('.ma-page');
    if (!page) return;
    page.classList.toggle('ma-theme-ma', _maMode !== 'kunde');
    page.classList.toggle('ma-theme-kunde', _maMode === 'kunde');
}

function maRefreshModeTabs() {
    maApplyModeTheme();
    const sw = document.getElementById('maModeTabs');
    if (!sw) return;
    sw.classList.toggle('is-kunde', _maMode === 'kunde');
    maMorphInner(sw, maBuildModeTabs());
}

function maBindToolbar() {
    // Idempotent: the toolbar is morphed in place now, so the same elements
    // survive refreshes — bind each one exactly once (JS property, not an
    // attribute, so a morph never strips the marker).
    const searchEl = document.getElementById('maSearch');
    if (searchEl && !searchEl._maBound) {
        searchEl._maBound = true;
        searchEl.addEventListener('input', (e) => {
            _maSearch = e.target.value || '';
            maApplyFilters();
        });
    }
}

// ============================================================
// OVERVIEW TAB — Quick Jump + Worker Blocks
// ============================================================
function maRenderOverview() {
    const mainBody = document.getElementById('maMainBody');
    if (!mainBody || !_maData) return;

    const totalCount = Object.keys(_maPageWorkers).length
        + Object.values(_maPageNuWorkers).reduce((sum, w) => sum + Object.keys(w).length, 0);

    if (totalCount === 0) {
        mainBody.innerHTML = `
            <div class="ma-empty">
                ${maIcon('event_busy')}
                <p>Keine bestätigten Einsätze in diesem Monat</p>
                <div class="ma-empty-sub">Wechseln Sie zu einem anderen Monat oder bestätigen Sie Einsätze in der Dispo.</div>
            </div>`;
        return;
    }

    if (!document.getElementById('maBlocksBody')) {
        mainBody.innerHTML = `
        <div class="ma-layout">
            <div id="maBlocksBody"></div>
        </div>`;
    }

    maRenderQuickJump();
    maRenderBlocks();
}

function maRenderQuickJump() {
    const wrap = document.getElementById('maQuickJump');
    if (!wrap) return;

    const sortedInternal = maSortWorkers(Object.values(_maPageWorkers));
    let html = '';

    const showInternal = (_maFilter === 'all' || _maFilter === 'internal' || _maFilter === 'anomaly');
    const showNu = (_maFilter === 'all' || _maFilter === 'nu' || _maFilter === 'anomaly');

    if (sortedInternal.length > 0 && showInternal) {
        const items = sortedInternal.map((w, idx) => {
            const std = maCalcWorkerTotal(w.entries);
            const name = ((w.vorname || '') + ' ' + (w.name || '')).trim() || w.dienst_nr;
            return `<button class="ma-quickjump-item" onclick="maJumpTo('maBlock_${idx}')">
                <span class="ma-quickjump-item-name">${escapeHtml(name)}</span>
                <span class="ma-quickjump-item-std">${std.toFixed(1)}h</span>
            </button>`;
        }).filter((_, i) => maWorkerScopeMatch(sortedInternal[i]));
        if (items.length) {
            html += `<div class="ma-quickjump-title">Interne Mitarbeiter</div>` + items.join('');
        }
    }

    let nuIdx = 0;
    Object.entries(_maPageNuWorkers).forEach(([firma, workers]) => {
        if (Object.keys(workers).length === 0 || !showNu) return;
        let section = '';
        Object.values(workers).forEach(w => {
            const bid = 'maNuBlock_' + (nuIdx++);
            if (!maWorkerScopeMatch(w)) return;
            const std = maCalcWorkerTotal(w.entries);
            const name = ((w.vorname || '') + ' ' + (w.name || '')).trim() || w.personal_nr || ('NU-' + w.nu_worker_id);
            section += `<button class="ma-quickjump-item" onclick="maJumpTo('${bid}')">
                <span class="ma-quickjump-item-name">${escapeHtml(name)}</span>
                <span class="ma-quickjump-item-std">${std.toFixed(1)}h</span>
            </button>`;
        });
        if (section) {
            html += `<div class="ma-quickjump-divider"></div>`;
            html += `<div class="ma-quickjump-section-title">${maIcon('apartment', 'font-size:12px;vertical-align:-2px')} ${escapeHtml(firma)}</div>`;
            html += section;
        }
    });

    maMorphInner(wrap, html || '<div class="ma-quickjump-title">Keine Treffer</div>');
}

function maRenderBlocks(opts) {
    const body = document.getElementById('maBlocksBody');
    if (!body) return;

    let html = '';

    // Modus Kunde: one block per OBJEKT (customer view), the workers are
    // the rows. Mitarbeiter mode keeps one block per worker.
    if (_maMode === 'kunde') {
        maGroupByObjekt().forEach((g, idx) => { html += maBuildObjektBlock(g, 'maObjBlock_' + idx); });
        maMorphInner(body, maModeBannerHtml() + html, { skipEditing: !!(opts && opts.keepEditing) });
        maApplyFilters();
        return;
    }

    // Internal workers
    const sortedInternal = maSortWorkers(Object.values(_maPageWorkers));
    const showInternal = (_maFilter === 'all' || _maFilter === 'internal' || _maFilter === 'anomaly');

    if (showInternal && sortedInternal.length > 0) {
        sortedInternal.forEach((worker, idx) => {
            html += maBuildWorkerBlock(worker, false, 'maBlock_' + idx);
        });
    }

    // NU workers grouped by company
    const showNu = (_maFilter === 'all' || _maFilter === 'nu' || _maFilter === 'anomaly');
    if (showNu) {
        let nuIdx = 0;
        Object.entries(_maPageNuWorkers).forEach(([firma, workers]) => {
            const companyWorkers = Object.values(workers);
            if (companyWorkers.length === 0) return;
            const companyStd = companyWorkers.reduce((sum, w) => sum + maCalcWorkerTotal(w.entries), 0);
            const companyEntries = companyWorkers.reduce((sum, w) => sum + w.entries.length, 0);

            html += `
            <div class="ma-nu-divider">
                <div class="ma-nu-divider-icon">${maIcon('apartment')}</div>
                <div class="ma-nu-divider-info">
                    <div class="ma-nu-divider-name">${escapeHtml(firma)}</div>
                    <div class="ma-nu-divider-meta">
                        <span>${maIcon('group', 'font-size:11px;vertical-align:-2px')} ${companyWorkers.length} Mitarbeiter</span>
                        <span>${maIcon('done_all', 'font-size:11px;vertical-align:-2px')} ${companyEntries} Einsätze</span>
                        <span>${maIcon('schedule', 'font-size:11px;vertical-align:-2px')} ${companyStd.toFixed(2)} Std</span>
                    </div>
                </div>
            </div>`;

            companyWorkers.forEach(worker => {
                worker._firma = firma;
                html += maBuildWorkerBlock(worker, true, 'maNuBlock_' + (nuIdx++));
            });
        });
    }

    // Morph instead of innerHTML: an approval changes one badge/button, so
    // only that changes on screen; blocks open in edit mode keep their input.
    maMorphInner(body, maModeBannerHtml() + html, { skipEditing: !!(opts && opts.keepEditing) });
    maApplyFilters();
}

// Modus "Kunde": the customer values (kunden_* — edited, or snapshotted by
// the Kunden-Freigabe) replace the Ist; unset → the worker's Ist is shown.
// Returns a copy (the worker row stays on _ma for the "MA …" hints);
// _maData itself is never altered by the projection.
// ONE customer pause: the value edited in Kunde mode (kunden_pause), else
// the Planung pause (maPlanPause). A worker Ist taken over is re-based onto
// the Planung pause (same Von–Bis), so a changed WORKER pause never changes
// what the customer sees.
function maPlanPause(e) {
    const v = e.plan_pause != null && e.plan_pause !== '' ? e.plan_pause : e.soll_pause;
    return parseInt(v) || 0;
}
function maKundeEntry(e) {
    const has = v => v != null && v !== '';
    const P = maPlanPause(e);
    let std = e.ist_std;
    if (has(e.kunden_std)) std = e.kunden_std;
    else if (has(e.ist_std) && parseFloat(e.ist_std) > 0) {
        const wp = has(e.em_pause) ? parseInt(e.em_pause) : P;
        std = Math.max(0, Math.round((parseFloat(e.ist_std) + (wp - P) / 60) * 100) / 100);
    }
    return Object.assign({}, e, {
        _ma: e,
        em_ist_start:  has(e.kunden_start) ? e.kunden_start : e.em_ist_start,
        em_ist_ende:   has(e.kunden_ende)  ? e.kunden_ende  : e.em_ist_ende,
        ist_start:     has(e.kunden_start) ? e.kunden_start : e.ist_start,
        ist_ende:      has(e.kunden_ende)  ? e.kunden_ende  : e.ist_ende,
        em_pause:      has(e.kunden_pause) ? parseInt(e.kunden_pause) : P,
        pause_minuten: has(e.kunden_pause) ? parseInt(e.kunden_pause) : P,
        ist_std:       std,
    });
}

// Banner above the worker blocks: which Freigabe the buttons below set.
function maModeBannerHtml() {
    return _maMode === 'kunde'
        ? `<div class="ma-mode-banner kunde">${maIcon('apartment', 'font-size:18px')}<div><strong>Modus Kunde</strong> — Freigabe geht in den <strong>Leistungsnachweis</strong> (Bestellungen / PDF für den Kunden). Die Mitarbeiter-App bleibt unverändert.</div></div>`
        : `<div class="ma-mode-banner ma">${maIcon('badge', 'font-size:18px')}<div><strong>Modus Mitarbeiter</strong> — Freigabe geht in die <strong>Mitarbeiter-App</strong> (grüner Haken / Stundennachweis). Der Leistungsnachweis bleibt unverändert.</div></div>`;
}

function maEntryWorkerName(e) {
    return ((e.vorname || '') + ' ' + (e.name || '')).trim() || e.dienst_nr || e.personal_nr || ('NU-' + e.nu_worker_id);
}

// Kunde mode: all entries (internal + NU) grouped by Objekt, each projected
// onto the customer values; rows sorted by date, start, worker name.
function maGroupByObjekt() {
    const groups = {};
    const add = (e, isNu) => {
        const k = (e.objektname || '').trim() || '–';
        (groups[k] = groups[k] || { objektname: k, entries: [] }).entries.push(Object.assign(maKundeEntry(e), { _isNu: isNu }));
    };
    Object.values(_maPageWorkers || {}).forEach(w => (w.entries || []).forEach(e => add(e, false)));
    Object.values(_maPageNuWorkers || {}).forEach(g => Object.values(g).forEach(w => (w.entries || []).forEach(e => add(e, true))));
    Object.values(groups).forEach(g => g.entries.sort((a, b) =>
        String(a.datum || '').localeCompare(String(b.datum || ''))
        || String(a.ist_start || '').localeCompare(String(b.ist_start || ''))
        || maEntryWorkerName(a).localeCompare(maEntryWorkerName(b), 'de')));
    const list = Object.values(groups);
    if (_maSort === 'std') list.sort((a, b) => maCalcWorkerTotal(b.entries) - maCalcWorkerTotal(a.entries));
    else if (_maSort === 'entries') list.sort((a, b) => b.entries.length - a.entries.length);
    else list.sort((a, b) => a.objektname.localeCompare(b.objektname, 'de'));
    return list;
}

function maBuildObjektBlock(g, blockId) {
    const entries = g.entries;
    const sollTotal = entries.reduce((s, e) => s + maSollStd(e), 0);
    const istTotal = entries.reduce((s, e) => s + (maIstStd(e) || 0), 0);
    const maIst = entries.reduce((s, e) => s + (maIstStd(e._ma) || 0), 0);
    const abr = entries.reduce((s, e) => s + maKundeAbrStd(e), 0);
    const anomalies = entries.flatMap(e => maDetectAnomalies(e));
    const kunden = [...new Set(entries.map(e => (e.kundenname || '').trim()).filter(Boolean))];
    const workers = [...new Set(entries.map(maEntryWorkerName))];
    const offen = entries.filter(e => parseInt(e.kunden_freigabe) !== 1).length;
    const cls = ['ma-worker-block', 'is-kunde', 'is-objekt'];
    if (anomalies.length) cls.push('has-anomaly');
    let chips = kunden.map(k => `<span class="ma-worker-meta-chip">${maIcon('apartment', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(k)}</span>`).join('');
    chips += `<span class="ma-worker-meta-chip">${maIcon('group', 'font-size:11px;vertical-align:-2px')} ${workers.length} Mitarbeiter</span>`;
    chips += `<span class="ma-worker-meta-chip">${maIcon('done_all', 'font-size:11px;vertical-align:-2px')} ${entries.length} Einsätze</span>`;
    chips += `<span class="ma-worker-meta-chip">${maIcon('receipt_long', 'font-size:11px;vertical-align:-2px')} ${offen ? offen + ' offen' : 'alle freigegeben'}</span>`;
    if (anomalies.length) chips += `<span class="ma-anomaly-chip">${maIcon('warning', 'font-size:11px;vertical-align:-2px')} ${anomalies.length} Hinweis${anomalies.length > 1 ? 'e' : ''}</span>`;
    return `
    <div class="${cls.join(' ')}" id="${blockId}" data-wkey="${escapeHtml('obj-' + g.objektname)}" data-by-obj="1" data-objekt="${escapeHtml(g.objektname)}" data-has-anomaly="${anomalies.length ? '1' : '0'}" data-is-nu="0" data-worker-name="${escapeHtml((g.objektname + ' ' + workers.join(' ')).toLowerCase())}" data-total-std="${istTotal.toFixed(2)}" data-entries-count="${entries.length}">
        <div class="ma-worker-head">
            <div class="ma-worker-avatar">${maIcon('location_on', 'font-size:22px')}</div>
            <div class="ma-worker-info">
                <div class="ma-worker-name">${escapeHtml(g.objektname)}</div>
                <div class="ma-worker-meta">${chips}</div>
            </div>
            <div class="ma-worker-std-display">
                <div class="ma-worker-std-label">Kunde</div>
                <div class="ma-worker-std-value"><span class="ma-worker-std-text">${istTotal.toFixed(2)}</span><span class="ma-worker-std-value-suffix">h</span></div>
                <div class="ma-worker-std-sub" title="Soll = Bestelleingang · MA-Ist = Zeiten der Mitarbeiter · Abr. = im Leistungsnachweis abgerechnet (Std + Pause, vom Kunden bezahlt)">Soll ${maFmtHrs(sollTotal)} h · MA-Ist ${maFmtHrs(maIst)} h · Abr. ${maFmtHrs(abr)} h</div>
            </div>
            <div class="ma-worker-actions"></div>
        </div>
        ${maBuildWorkerTable(entries, false, true)}
    </div>`;
}

function maBuildWorkerBlock(worker, isNu, blockId) {
    const kunde = _maMode === 'kunde';
    if (kunde) worker = Object.assign({}, worker, { entries: worker.entries.map(maKundeEntry) });
    const name = ((worker.vorname || '') + ' ' + (worker.name || '')).trim()
        || worker.dienst_nr || worker.personal_nr || ('NU-' + worker.nu_worker_id);
    const initials = wpWorkerInitials(worker.vorname, worker.name);
    const totalStd = maCalcWorkerTotal(worker.entries);
    const sollTotal = worker.entries.reduce((s, e) => s + maSollStd(e), 0);
    const istTotal = worker.entries.reduce((s, e) => s + (maIstStd(e) || 0), 0);

    // Anomaly detection
    const anomalies = worker.entries.flatMap(e => maDetectAnomalies(e));
    const hasAnomaly = anomalies.length > 0;
    const blockClass = ['ma-worker-block'];
    if (isNu) blockClass.push('is-nu');
    blockClass.push(kunde ? 'is-kunde' : 'is-ma');
    if (hasAnomaly) blockClass.push('has-anomaly');

    // Build action buttons
    let actions = '';
    if (kunde) {
        if (isNu) actions += `<span class="ma-nu-firma-chip">${maIcon('apartment', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(worker._firma || '')}</span>`;
    } else if (!isNu) {
        actions += `<button class="ma-worker-action-btn pdf" onclick="maOpenWorkerPDF('${escapeHtml(worker.dienst_nr)}')" title="PDF Stundennachweis">${maIcon('picture_as_pdf', 'font-size:15px')}</button>`;
        actions += `<button class="ma-worker-action-btn send" onclick="maSendToWorker('${escapeHtml(worker.dienst_nr)}')" title="An Mitarbeiter senden">${maIcon('send', 'font-size:15px')}</button>`;
    } else {
        actions += `<span class="ma-nu-firma-chip">${maIcon('apartment', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(worker._firma || '')}</span>`;
    }


    // Meta chips
    let metaChips = '';
    if (isNu) {
        if (worker.personal_nr) metaChips += `<span class="ma-worker-meta-chip">${maIcon('tag', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(worker.personal_nr)}</span>`;
    } else {
        metaChips += `<span class="ma-worker-meta-chip">${maIcon('badge', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(worker.dienst_nr)}</span>`;
        if (worker.bereich) metaChips += `<span class="ma-worker-meta-chip bereich bc-chip" style="${bereichColorVars(worker.bereich)}">${maIcon('sell', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(worker.bereich)}</span>`;
        if (worker.aktiv !== null && worker.aktiv !== undefined && parseInt(worker.aktiv) !== 1) metaChips += `<span class="ma-worker-meta-chip">${maIcon('person_off', 'font-size:11px;vertical-align:-2px')} Inaktiv</span>`;
    }
    metaChips += `<span class="ma-worker-meta-chip">${maIcon('done_all', 'font-size:11px;vertical-align:-2px')} ${worker.entries.length} Einsätze</span>`;
    if (hasAnomaly) metaChips += `<span class="ma-anomaly-chip">${maIcon('warning', 'font-size:11px;vertical-align:-2px')} ${anomalies.length} Hinweis${anomalies.length > 1 ? 'e' : ''}</span>`;

    // Kunde/Objekt scope attributes — the block matches a scope filter when
    // ANY of the worker's entries carries the selected name.
    const wKunden  = [...new Set(worker.entries.map(e => (e.kundenname || '').trim()).filter(Boolean))];
    const wObjekte = [...new Set(worker.entries.map(e => (e.objektname || '').trim()).filter(Boolean))];

    return `
    <div class="${blockClass.join(' ')}" id="${blockId}" data-wkey="${escapeHtml(isNu ? 'nu-' + worker.nu_worker_id : 'ma-' + worker.dienst_nr)}" data-worker-name="${escapeHtml(name.toLowerCase())}" data-bereich="${escapeHtml((worker.bereich || '').toLowerCase())}" data-has-anomaly="${hasAnomaly ? '1' : '0'}" data-is-nu="${isNu ? '1' : '0'}" data-total-std="${totalStd.toFixed(2)}" data-entries-count="${worker.entries.length}" data-kunden='${escapeHtml(JSON.stringify(wKunden)).replace(/'/g, '&#039;')}' data-objekte='${escapeHtml(JSON.stringify(wObjekte)).replace(/'/g, '&#039;')}'>
        <div class="ma-worker-head">
            <div class="ma-worker-avatar">${worker.foto ? `<img src="${escapeHtml(worker.foto)}" alt="">` : initials}</div>
            <div class="ma-worker-info">
                <div class="ma-worker-name">${escapeHtml(name)}</div>
                <div class="ma-worker-meta">${metaChips}</div>
            </div>
            <div class="ma-worker-std-display">
                <div class="ma-worker-std-label">${kunde ? 'Kunde' : 'Ist'}</div>
                <div class="ma-worker-std-value"><span class="ma-worker-std-text">${istTotal.toFixed(2)}</span><span class="ma-worker-std-value-suffix">h</span></div>
                ${kunde
                    ? `<div class="ma-worker-std-sub" title="Soll = Bestelleingang · MA-Ist = Zeiten des Mitarbeiters · Abr. = im Leistungsnachweis abgerechnet (Std + Pause aus der Planung, vom Kunden bezahlt)">Soll ${maFmtHrs(sollTotal)} h · MA-Ist ${maFmtHrs(worker.entries.reduce((s, e) => s + (maIstStd(e._ma) || 0), 0))} h · Abr. ${maFmtHrs(worker.entries.reduce((s, e) => s + maKundeAbrStd(e), 0))} h</div>`
                    : `<div class="ma-worker-std-sub" title="Soll = Bestelleingang · Abrechnung = Ist, ohne Erfassung Plan-Stunden">Soll ${maFmtHrs(sollTotal)} h · Abr. ${maFmtHrs(totalStd)} h</div>`}
            </div>
            <div class="ma-worker-actions">${actions}</div>
        </div>
        ${maBuildWorkerTable(worker.entries, isNu)}
    </div>`;
}

// Soll = original plan from the Bestelleingang day (soll_std, else span − pause).
function maSollStd(e) {
    if (e.soll_std != null && e.soll_std !== '' && parseFloat(e.soll_std) > 0) return parseFloat(e.soll_std);
    if (!e.soll_start || !e.soll_ende) return 0;
    const [sh, sm] = e.soll_start.substring(0, 5).split(':').map(Number);
    const [eh, em] = e.soll_ende.substring(0, 5).split(':').map(Number);
    let span = (eh * 60 + em) - (sh * 60 + sm);
    if (span <= 0) span += 1440;
    return Math.max(0, span - (parseInt(e.soll_pause) || 0)) / 60;
}

// Ist = only what was really recorded for THIS worker (app clock or office
// correction) — never the plan fallback. null = nothing recorded.
function maIstStd(e) {
    return (e.ist_std != null && e.ist_std !== '' && parseFloat(e.ist_std) > 0) ? parseFloat(e.ist_std) : null;
}

// Billed customer hours of a Kunde-projected row (maKundeEntry) — same rule
// as the Leistungsnachweis (AbrechnungDataService::customerHoursExpr):
// Std + the Planung pause (the customer pays the pause).
function maKundeAbrStd(e) {
    return Math.max(0, maCalcEntryStd(e) + (parseInt(e.pause_minuten) || 0) / 60);
}

function maFmtHrs(h) {
    return h.toFixed(2).replace('.', ',');
}

// UTC 'Y-m-d H:i:s' from the server → local HH:MM (Europe/Berlin).
function maUtcToLocalHm(ts) {
    if (!ts) return '';
    const d = new Date(String(ts).replace(' ', 'T') + 'Z');
    if (isNaN(d)) return '';
    return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Berlin' });
}

// "Verlängert" chip under Ist-Bis: count + the worker's reasons (tooltip),
// from zeiterfassung.verlaengerung_gruende written by the app's extend sheet.
function maExtensionHint(e) {
    const n = parseInt(e.app_verlaengerungen) || 0;
    if (n <= 0) return '';
    let list = [];
    try { list = JSON.parse(e.app_verlaengerung_gruende || '[]') || []; } catch (err) { list = []; }
    const lines = list.map(g => `${maUtcToLocalHm(g.am)} → bis ${maUtcToLocalHm(g.bis)}: ${g.grund || ''}`);
    const title = 'Verlängert (' + n + '×)' + (lines.length ? '\n' + lines.join('\n') : '\nohne Begründung (ältere App)');
    return `<div class="ma-ist-ext" title="${escapeHtml(title)}">${maIcon('more_time', 'font-size:12px')} ${n}× verl.</div>`;
}

function maSubHint(txt, title) {
    return `<div class="ma-ist-early" title="${escapeHtml(title || '')}">${txt}</div>`;
}

// Real app stamps of the worker, ALWAYS shown under the Soll values:
// "gest." = real Start tap, "beendet" = real Beenden tap ("autom. beendet"
// for a forgotten shift closed by the system), "App X Min" = app pause.
// Records older than clock_*_real fall back to the counted clock value.
function maAppStamps(e) {
    if (!e.app_clock_in && !e.app_clock_out) return { in: '', out: '', pause: null, auto: false };
    return {
        in: maUtcToLocalHm(e.app_clock_in_real || e.app_clock_in),
        out: e.app_clock_out ? maUtcToLocalHm(e.app_clock_out_real || e.app_clock_out) : '',
        pause: e.app_clock_out ? (parseInt(e.app_pause) || 0) : null,
        auto: !!e.app_clock_out && !e.app_clock_out_real && /^Automatisch beendet/.test(e.app_notiz || ''),
    };
}

const MA_IST_QUELLE = {
    app:        { label: 'App',     title: 'Exakt aus der Mitarbeiter-App gestempelt' },
    korrigiert: { label: 'Korr.',   title: 'In der App gestempelt, danach vom Büro korrigiert' },
    manuell:    { label: 'Manuell', title: 'Vom Büro eingetragen (keine App-Stempelung)' },
};

function maBuildWorkerTable(entries, isNu, byObj = false) {
    const kunde = _maMode === 'kunde';
    // Freigabe column: Mitarbeiter mode internal only (NU has no app);
    // Kunde mode for everyone (NU rows need a Kunden-Freigabe too).
    const hasFreigabe = kunde || !isNu;
    let html = `<div class="ma-table-wrap"><table class="ma-table ma-table-sollist"><thead>
    <tr class="ma-th-group">
        <th colspan="${isNu ? 4 : 3}"></th>
        <th class="ma-th-soll">Soll <span class="ma-th-hint">Bestelleingang</span></th>
        <th class="ma-th-ist" colspan="4">${kunde ? 'Kunde <span class="ma-th-hint">Leistungsnachweis</span>' : `Ist <span class="ma-th-hint">${isNu ? 'erfasst' : 'Mitarbeiter-App'}</span>`}</th>
        <th colspan="${hasFreigabe ? 4 : 3}"></th>
    </tr>
    <tr>
        <th>Datum</th>
        <th>Tag</th>
        <th>${byObj ? 'Mitarbeiter' : 'Objekt'}</th>
        ${isNu ? '<th>Position</th>' : ''}
        <th class="ma-th-soll">Zeit · Std</th>
        <th class="ma-th-ist">Von</th>
        <th class="ma-th-ist">Bis</th>
        <th class="ma-th-ist ma-text-center">Pause</th>
        <th class="ma-th-ist ma-text-right">Std</th>
        <th class="ma-text-right" title="${kunde ? 'Kunde − Soll' : 'Ist − Soll'}">Diff</th>
        <th>Status</th>
        ${hasFreigabe ? (kunde ? '<th class="ma-text-center ma-th-frei kunde">Freigabe Kunde</th>' : '<th class="ma-text-center ma-th-frei">Freigabe MA</th>') : ''}
        <th class="ma-text-center ma-th-edit" title="Einsatz bearbeiten"></th>
    </tr></thead><tbody>`;

    entries.forEach(e => {
        const d = e.datum ? new Date(e.datum + 'T00:00:00') : null;
        const dayName = d ? DAYS_SHORT[d.getDay()] : '–';
        const isWeekend = d ? (d.getDay() === 0 || d.getDay() === 6) : false;
        const statusClass = e.hms_bestaetigt === 'ja' ? 'confirmed' : (e.pruefung === 'geplant' ? 'planned' : 'pending');
        const statusLabel = e.hms_bestaetigt === 'ja' ? 'Bestätigt' : (e.pruefung === 'geplant' ? 'Geplant' : 'Offen');

        const sollStd = maSollStd(e);
        const sollPause = parseInt(e.soll_pause) || 0;
        const sollTime = e.soll_start || e.soll_ende
            ? `${wpFormatTime(e.soll_start)}–${wpFormatTime(e.soll_ende)}` : '–';

        const istStd = maIstStd(e);
        const istStart = e.em_ist_start ? e.em_ist_start.substring(0, 5) : '';
        const istEnde = e.em_ist_ende ? e.em_ist_ende.substring(0, 5) : '';
        const hasIst = !!(istStart || istEnde || istStd != null);
        const istPause = e.em_pause != null && e.em_pause !== '' ? parseInt(e.em_pause) : (parseInt(e.pause_minuten) || 0);
        // Edit inputs start from the Ist, else the Soll — untouched rows are never saved.
        const vonVal = istStart || (e.soll_start ? e.soll_start.substring(0, 5) : '');
        const bisVal = istEnde || (e.soll_ende ? e.soll_ende.substring(0, 5) : '');
        const pauseVal = hasIst ? istPause : sollPause;
        const diff = istStd != null && sollStd > 0 ? istStd - sollStd : null;
        const diffClass = diff == null || Math.abs(diff) < 0.005 ? '' : (diff > 0 ? 'pos' : 'neg');
        const diffText = diff == null ? '–' : (Math.abs(diff) < 0.005 ? '±0' : (diff > 0 ? '+' : '−') + maFmtHrs(Math.abs(diff)));

        const q = MA_IST_QUELLE[e.ist_quelle];
        // Korrekturbuchung: who corrected and when (audit trail on the server).
        const korrBy = e.zeit_korrektur_von && e.ist_quelle !== 'app'
            ? ` — korrigiert von ${e.zeit_korrektur_von}${e.zeit_korrektur_am ? ' am ' + new Date(String(e.zeit_korrektur_am).replace(' ', 'T') + 'Z').toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''}`
            : '';
        let quelleChip = q ? `<span class="ma-ist-src ${e.ist_quelle}" title="${escapeHtml(q.title + korrBy)}">${q.label}</span>` : '';
        // App stamps (real tap in/out, extension, app pause) — shown in BOTH
        // modes; internal only, never part of the customer PDF.
        const m = e._ma || e;
        const hm = v => v ? String(v).substring(0, 5) : '';
        // Big values = the counted Ist (quarter-rounded app stamp, or the
        // office/LN value) — the plan stays visible in its own Soll column.
        // Without a recorded time the plan fills the cell.
        const hasSoll = !!(e.soll_start || e.soll_ende);
        const showVon = istStart || hm(e.soll_start);
        const showBis = istEnde || hm(e.soll_ende);
        const showPause = kunde ? istPause : (hasIst ? istPause : (hasSoll ? sollPause : null));
        // Small lines = the real app stamps, always.
        const app = maAppStamps(m);
        // A recorded value (office correction / Leistungsnachweis) that differs
        // from both the Soll shown and the app stamp gets its own line.
        const recLabel = kunde ? 'LN' : 'Ist';
        const recTitle = kunde ? 'Wert im Leistungsnachweis' : 'Erfasste Ist (abgerechnet)';
        const rec = (val, shown, appVal, txt) => val !== '' && val != null && val !== shown && val !== appVal
            ? maSubHint(`${recLabel} ${txt}`, recTitle) : '';
        const recVon = kunde ? hm(e.kunden_start) : istStart;
        const recBis = kunde ? hm(e.kunden_ende) : istEnde;
        const recPause = kunde
            ? (e.kunden_pause != null && e.kunden_pause !== '' ? parseInt(e.kunden_pause) : null)
            : (hasIst ? istPause : null);
        let earlyHint = (app.in ? maSubHint('gest. ' + app.in, 'In der App gestempelt um ' + app.in) : '')
            + rec(recVon, showVon, app.in, recVon);
        let lateHint = (app.auto ? maSubHint('autom. beendet', m.app_notiz)
            : (app.out ? maSubHint('beendet ' + app.out, 'In der App beendet um ' + app.out) : ''))
            + rec(recBis, showBis, app.out, recBis);
        const extHint = maExtensionHint(m);
        // Kunde mode: ONE customer pause (edited here, default = Planung
        // pause, paid by the customer) — the worker's own pause only shows
        // as a hint when it differs.
        const maPause = m.em_pause != null && m.em_pause !== '' ? parseInt(m.em_pause) : null;
        let pauseHint = (app.pause != null ? maSubHint(`App ${app.pause} Min`, `In der App erfasste Pause: ${app.pause} Min`) : '')
            + (kunde
                ? (maPause != null && maPause !== showPause ? maSubHint(`MA ${maPause} Min`, 'Pause des Mitarbeiters (Modus Mitarbeiter) — für den Kunden gilt die Pause aus der Planung') : '')
                : rec(recPause, showPause, app.pause, recPause + ' Min'));
        let abrHint = '';
        if (kunde) {
            const abr = maKundeAbrStd(e);
            const shownStd = istStd != null ? istStd : maCalcEntryStd(e);
            if (Math.abs(abr - shownStd) >= 0.005) {
                abrHint = maSubHint(`Abr. ${maFmtHrs(abr)} h`, `Im Leistungsnachweis abgerechnet: Std + Pause (${istPause} Min, vom Kunden bezahlt)`);
            }
        }
        if (kunde) {
            const maStd = maIstStd(m);
            const stdDiffers = (maStd == null) !== (istStd == null) || (maStd != null && Math.abs(maStd - istStd) >= 0.005);
            quelleChip = stdDiffers
                ? `<span class="ma-ist-src korrigiert" title="Abweichend von der Mitarbeiter-Ist (${maStd != null ? maFmtHrs(maStd) + ' h' : '–'})">MA ${maStd != null ? maFmtHrs(maStd) : '–'}</span>` : '';
        }

        const rowAnomalies = maDetectAnomalies(e);
        const rowAnomalyClass = rowAnomalies.length > 0 ? 'has-anomaly' : '';
        const anomalyTooltip = rowAnomalies.length > 0 ? `title="${escapeHtml(rowAnomalies.join(' · '))}"` : '';

        const rowNu = byObj ? !!e._isNu : isNu;
        const rowFilter = byObj ? ` data-row-nu="${rowNu ? 1 : 0}" data-row-anomaly="${rowAnomalies.length ? 1 : 0}" data-row-name="${escapeHtml(maEntryWorkerName(e).toLowerCase())}" data-row-kunde="${escapeHtml((e.kundenname || '').trim())}"` : '';
        const dataAttrs = rowFilter + (rowNu
            ? `data-einsatz-id="${escapeHtml(e.einsatz_id)}" data-nu-worker-id="${escapeHtml(e.nu_worker_id)}" data-position-id="${escapeHtml(e.position_id || '')}"`
            : `data-einsatz-id="${escapeHtml(e.einsatz_id)}" data-dienst-nr="${escapeHtml(e.dienst_nr || '')}"`);

        html += `<tr class="${rowAnomalyClass}" ${dataAttrs} ${anomalyTooltip}>
            <td data-label="Datum">${wpFormatDate(e.datum)}</td>
            <td data-label="Tag"><span class="ma-day-chip ${isWeekend ? 'weekend' : ''}">${dayName}</span></td>
            ${byObj
                ? `<td data-label="Mitarbeiter"><strong>${escapeHtml(maEntryWorkerName(e))}</strong><div class="ma-soll-sub">${rowNu ? `${maIcon('apartment', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(e.nu_firmenname || 'NU')}${e.position_name ? ' · ' + escapeHtml(e.position_name) : ''}` : escapeHtml(e.dienst_nr || '')}</div></td>`
                : `<td data-label="Objekt">${escapeHtml(e.objektname || '–')}</td>`}
            ${isNu ? `<td data-label="Position">${escapeHtml(e.position_name || '–')}</td>` : ''}
            <td data-label="Soll" class="ma-cell-soll">
                <div class="ma-soll-time">${sollTime}</div>
                <div class="ma-soll-sub">${sollStd ? maFmtHrs(sollStd) + ' h' : '–'}${sollPause > 0 ? ' · ' + sollPause + ' Min' : ''}</div>
            </td>
            <td data-label="Ist von" class="ma-cell-von ma-cell-ist">
                <span class="ma-display">${showVon || '–'}${earlyHint}</span>
                <input type="text" lang="de-DE" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="ma-cell-input ma-cell-input-time ma-input t24" data-field="von" value="${escapeHtml(vonVal)}" style="display:none">
            </td>
            <td data-label="Ist bis" class="ma-cell-bis ma-cell-ist">
                <span class="ma-display">${showBis || '–'}${lateHint}${extHint}</span>
                <input type="text" lang="de-DE" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="ma-cell-input ma-cell-input-time ma-input t24" data-field="bis" value="${escapeHtml(bisVal)}" style="display:none">
            </td>
            <td data-label="Pause" class="ma-cell-pause ma-cell-ist ma-text-center">
                <span class="ma-display">${showPause != null ? showPause + ' Min' : '–'}${pauseHint}</span>
                <input type="number" step="1" min="0" class="ma-cell-input ma-cell-input-num ma-input" data-field="pause" value="${escapeHtml(String(kunde ? istPause : pauseVal))}" style="display:none"${kunde ? ` title="Pause für den Kunden (Vorgabe aus der Planung: ${maPlanPause(e)} Min)"` : ''}>
            </td>
            <td data-label="Ist Std" class="ma-cell-std ma-cell-ist ma-text-right">
                <span class="ma-display"><strong>${istStd != null ? maFmtHrs(istStd) : '–'}</strong>${quelleChip}${abrHint}</span>
                <input type="number" step="0.01" min="0" class="ma-cell-input ma-cell-input-num ma-input" data-field="std" value="${istStd != null ? escapeHtml(istStd.toFixed(2)) : ''}" style="display:none" readonly tabindex="-1">
            </td>
            <td data-label="Diff" class="ma-cell-diff ma-text-right"><span class="ma-diff ${diffClass}">${diffText}</span></td>
            <td data-label="Status"><span class="ma-status-pill ${statusClass}">${statusLabel}</span></td>
            ${kunde ? `<td data-label="Freigabe" class="ma-text-center">
                <button class="ma-freigabe-btn kunde ${parseInt(e.kunden_freigabe) === 1 ? 'on' : ''}" onclick="maToggleKundenFreigabe(this)" title="${parseInt(e.kunden_freigabe) === 1 ? 'Für den Kunden freigegeben — klicken zum Zurücknehmen' : 'Für den Leistungsnachweis (Bestellungen) freigeben'}">${maIcon('receipt_long', 'font-size:19px')}</button>
            </td>` : (isNu ? '' : `<td data-label="Freigabe" class="ma-text-center">
                <button class="ma-freigabe-btn ${parseInt(e.zeit_freigabe) === 1 ? 'on' : ''}" onclick="maToggleFreigabe('${escapeHtml(e.einsatz_id)}', '${escapeHtml(e.dienst_nr || '')}')" title="${parseInt(e.zeit_freigabe) === 1 ? 'Freigegeben — klicken zum Zurücknehmen' : 'Zeit freigeben (grüner Haken in der Mitarbeiter-App)'}">${maIcon('check_circle', 'font-size:20px')}</button>
            </td>`)}
            <td data-label="Bearbeiten" class="ma-text-center ma-cell-edit">
                <button class="ma-row-edit-btn" onclick="maToggleRowEdit(this)" title="Diesen Einsatz bearbeiten">${maIcon('edit', 'font-size:17px')}</button>
                <button class="ma-row-save-btn" onclick="maSaveRowEdit(this)" style="display:none" title="Speichern">${maIcon('save', 'font-size:17px')}</button>
                <button class="ma-row-cancel-btn" onclick="maCancelRowEdit(this)" style="display:none" title="Abbrechen">${maIcon('close', 'font-size:17px')}</button>
            </td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    return html;
}
