/* ============================================================
   HMS / Clood-X — Preishistorie Module (v3)
   Provides:
     - Zeitraum-Navigation in der Topbar (Monat / Jahr / Zeitraum / Gesamt)
       — same look & behaviour as Zeiterfassung
     - KPI-Bento (follows period + filters)
     - Filter toolbar (Suche, Kunde, Objekt, Artikel, Typ) — applies live
     - 3 tabs:  Verlauf  |  Pro Artikel  |  Top Änderungen
     - Drill-down modal with line chart per artikel
     - CSV export of exactly the selected period + filters
   Backend: GET /api/price-history        (filters + from/to + limit)
            GET /api/price-history/stats  (same filters)
            GET /api/price-history/article/{artikel_id}
   ============================================================ */

const PH_API_BASE = (typeof API_BASE !== 'undefined') ? API_BASE : (window.location.origin + '/api');
const PH_API = {
    list:    PH_API_BASE + '/price-history',
    stats:   PH_API_BASE + '/price-history/stats',
    article: (id) => PH_API_BASE + '/price-history/article/' + encodeURIComponent(id),
    customers: PH_API_BASE + '/customers',
    objects:   PH_API_BASE + '/objects',
    artikel:   PH_API_BASE + '/artikel',
};
const PH_LIMIT = 5000;
const PH_MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
const PH_TAB_KEY = 'hms_ph_tab';

// ---------- MD3 helpers (official <md-dialog> + Material Symbols) ----------
function phIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}
const phSpinner = (size = 32) => `<md-circular-progress indeterminate style="--md-circular-progress-size:${size}px"></md-circular-progress>`;
function openPhDialog(title, bodyHtml, footerHtml = '', wide = false) {
    document.getElementById('phMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'phMdDialog';
    dlg.classList.add('ph-context');
    if (wide) dlg.classList.add('ph-dialog-xl');
    dlg.innerHTML = `
        <div slot="headline" id="phDialogTitle">${title}</div>
        <div slot="content" class="ph-dialog-content" id="phDialogBody">${bodyHtml}</div>
        ${footerHtml ? `<div slot="actions">${footerHtml}</div>` : ''}`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();
    return dlg;
}
function closePhDialog() { document.getElementById('phMdDialog')?.close(); }

const _phNow = new Date();
const _phState = {
    currentTab: (() => { try { return ['verlauf', 'artikel', 'top'].includes(localStorage.getItem(PH_TAB_KEY)) ? localStorage.getItem(PH_TAB_KEY) : 'verlauf'; } catch (e) { return 'verlauf'; } })(),
    customers: [],
    objects: [],
    artikel: [],
    history: [],
    stats: null,
    truncated: false,
    loadSeq: 0,
    // Zeitraum: 'month' | 'year' | 'range' | 'all'
    period: { mode: 'year', year: _phNow.getFullYear(), month: _phNow.getMonth(), from: '', to: '' },
    filters: {
        kunden_id: '',
        obj_id: '',
        artikel_id: '',
        change_type: '',  // '' | 'kunde' | 'nu' | 'increase' | 'decrease'
        search: '',
    },
};

// ============================================================
// Zeitraum (period)
// ============================================================
function phPad(n) { return String(n).padStart(2, '0'); }

/** {from, to, label, file} for the selected period ('' = open end). */
function phPeriodBounds() {
    const p = _phState.period;
    if (p.mode === 'month') {
        const last = new Date(p.year, p.month + 1, 0).getDate();
        return { from: `${p.year}-${phPad(p.month + 1)}-01`, to: `${p.year}-${phPad(p.month + 1)}-${phPad(last)}`,
                 label: `${PH_MONTHS[p.month]} ${p.year}`, file: `${p.year}-${phPad(p.month + 1)}` };
    }
    if (p.mode === 'year') {
        return { from: `${p.year}-01-01`, to: `${p.year}-12-31`, label: `Jahr ${p.year}`, file: `${p.year}` };
    }
    if (p.mode === 'range' && (p.from || p.to)) {
        const fmt = (d) => d ? formatDate(d) : '…';
        return { from: p.from, to: p.to, label: `${fmt(p.from)} – ${fmt(p.to)}`,
                 file: `${p.from || 'anfang'}_bis_${p.to || 'heute'}` };
    }
    return { from: '', to: '', label: 'Gesamter Zeitraum', file: 'gesamt' };
}

function phSetPeriodMode(mode) {
    const p = _phState.period;
    if (mode === 'range' && !p.from && !p.to) {
        const b = phPeriodBounds();
        p.from = b.from; p.to = b.to;
    }
    p.mode = mode;
    if (mode !== 'range') phLoad();
    else { phMountPeriodNav(); if (p.from || p.to) phLoad(); }
}

function phShiftPeriod(delta) {
    const p = _phState.period;
    if (p.mode === 'month') {
        const d = new Date(p.year, p.month + delta, 1);
        p.year = d.getFullYear(); p.month = d.getMonth();
    } else if (p.mode === 'year') {
        p.year += delta;
    } else return;
    phLoad();
}

function phPeriodToday() {
    const p = _phState.period;
    p.year = new Date().getFullYear();
    p.month = new Date().getMonth();
    if (p.mode !== 'month' && p.mode !== 'year') p.mode = 'month';
    phLoad();
}

function phSetRange() {
    const p = _phState.period;
    p.from = document.getElementById('phRangeFrom')?.value || '';
    p.to   = document.getElementById('phRangeTo')?.value || '';
    if (p.from && p.to && p.from > p.to) { const t = p.from; p.from = p.to; p.to = t; }
    phLoad();
}

/* Period navigation lives in the global topbar next to #pageTitle, reusing
   the Zeiterfassung look (.ma-month-nav). The element id is shared with
   Zeiterfassung on purpose: app.js loadPage() removes #maNavInjector on every
   navigation away, so the bar never leaks onto other pages. */
function phMountPeriodNav() {
    const pageTitle = document.getElementById('pageTitle');
    if (!pageTitle) return;
    let inj = document.getElementById('maNavInjector');
    if (!inj) {
        inj = document.createElement('div');
        inj.id = 'maNavInjector';
        inj.className = 'ma-nav-injector';
        pageTitle.insertAdjacentElement('afterend', inj);
    }
    const p = _phState.period;
    const b = phPeriodBounds();
    const stepper = p.mode === 'month' || p.mode === 'year';
    const seg = (mode, label) => `<button type="button" class="ph-seg-btn ${p.mode === mode ? 'active' : ''}" onclick="phSetPeriodMode('${mode}')">${label}</button>`;
    const center = p.mode === 'range'
        ? `<span class="ph-range-inline">
               <input type="date" id="phRangeFrom" value="${p.from}" onchange="phSetRange()" aria-label="Von">
               <span>–</span>
               <input type="date" id="phRangeTo" value="${p.to}" onchange="phSetRange()" aria-label="Bis">
           </span>`
        : `<button onclick="phShiftPeriod(-1)" title="${p.mode === 'year' ? 'Vorheriges Jahr' : 'Vorheriger Monat'}" ${stepper ? '' : 'disabled'}>${phIcon('chevron_left', 'font-size:16px')}</button>
           <span class="ma-month-label">${escapeHtml(b.label)}</span>
           <button onclick="phShiftPeriod(1)" title="${p.mode === 'year' ? 'Nächstes Jahr' : 'Nächster Monat'}" ${stepper ? '' : 'disabled'}>${phIcon('chevron_right', 'font-size:16px')}</button>`;
    inj.innerHTML = `
        <div class="ma-month-nav ph-period-nav">
            <span class="ph-seg">${seg('month', 'Monat')}${seg('year', 'Jahr')}${seg('range', 'Zeitraum')}${seg('all', 'Gesamt')}</span>
            ${center}
            ${stepper ? `<button class="ma-today-btn" onclick="phPeriodToday()" title="Zum aktuellen ${p.mode === 'year' ? 'Jahr' : 'Monat'} springen">${phIcon('today', 'font-size:14px')} <span class="ph-today-txt">Heute</span></button>` : ''}
        </div>`;
}

// ============================================================
// Main render
// ============================================================
async function renderPriceHistory() {
    const content = document.getElementById('content');
    content.innerHTML = `<div class="ph-loading">${phSpinner(28)} Lade Preishistorie...</div>`;

    const customers = await apiGet(PH_API.customers);
    _phState.customers = Array.isArray(customers) ? customers : [];

    content.innerHTML = phLayoutHtml();
    phWatchStickyHeight();
    phMountPeriodNav();
    phBindFilterEvents();
    document.getElementById('phTabs')?.addEventListener('change', e => {
        const tab = e.target.activeTab?.dataset?.tab;
        if (tab && tab !== _phState.currentTab) phSwitchTab(tab);
    });
    phReloadObjectOptions();
    phReloadArtikelOptions();
    await phLoad();
}

// The filter/tab block is sticky; month headers pin right below it.
// Its height changes (wrapping chips, reset button), so it is measured live.
let _phStickyRO = null;
function phWatchStickyHeight() {
    const sticky = document.getElementById('phSticky');
    const card = document.getElementById('phCard');
    if (!sticky || !card || typeof ResizeObserver !== 'function') return;
    if (_phStickyRO) _phStickyRO.disconnect();
    _phStickyRO = new ResizeObserver(() => {
        card.style.setProperty('--ph-sticky-h', sticky.offsetHeight + 'px');
    });
    _phStickyRO.observe(sticky);
    if (!window._phStuckBound) {
        window._phStuckBound = true;
        window.addEventListener('scroll', () => {
            const s = document.getElementById('phSticky');
            if (!s) return;
            const top = parseFloat(getComputedStyle(s).top) || 0;
            s.classList.toggle('is-stuck', getComputedStyle(s).position === 'sticky' && s.getBoundingClientRect().top <= top + 0.5);
        }, { passive: true });
    }
}

// ============================================================
// Layout (bento + toolbar + tabs + pane container)
// ============================================================
function phLayoutHtml() {
    return `
        <div id="phBento">${phBentoHtml(null)}</div>
        <div class="ph-card" id="phCard">
            <div class="ph-sticky" id="phSticky">
            ${phFilterBarHtml()}
            <div class="ph-tabbar">
                <md-tabs id="phTabs" class="ph-tabs">${phTabsHtml()}</md-tabs>
                <div class="ph-tabbar-side">
                    <span class="ph-result-info" id="phResultInfo"></span>
                    ${uiButton('CSV', { variant: 'outlined', size: 'sm', icon: 'download', id: 'phCsvBtn', onclick: 'phExportCsv()', title: 'Gewählten Zeitraum mit allen Filtern als CSV herunterladen' })}
                </div>
            </div>
            </div>
            <div class="ph-tab-hint" id="phTabHint"></div>
            <div id="phPane" class="ph-panes">
                <div class="ph-pane ${_phState.currentTab === 'verlauf' ? 'active' : ''}" id="phPane-verlauf"></div>
                <div class="ph-pane ${_phState.currentTab === 'artikel' ? 'active' : ''}" id="phPane-artikel"></div>
                <div class="ph-pane ${_phState.currentTab === 'top' ? 'active' : ''}" id="phPane-top"></div>
            </div>
        </div>
    `;
}

/** Query params for period + filters (shared by list, stats, CSV). */
function phQueryParams() {
    const params = new URLSearchParams();
    const b = phPeriodBounds();
    if (b.from) params.append('from', b.from);
    if (b.to) params.append('to', b.to);
    Object.entries(_phState.filters).forEach(([k, v]) => { if (v) params.append(k, v); });
    return params;
}

async function phLoad() {
    const seq = ++_phState.loadSeq;
    phMountPeriodNav();
    const pane = document.getElementById('phPane-' + _phState.currentTab);
    if (pane) pane.innerHTML = `<div class="ph-loading">${phSpinner(28)} Lade…</div>`;

    const params = phQueryParams();
    const listParams = new URLSearchParams(params);
    listParams.append('limit', String(PH_LIMIT));
    let history, stats;
    try {
        [history, stats] = await Promise.all([
            apiGet(PH_API.list + '?' + listParams.toString()),
            apiGet(PH_API.stats + '?' + params.toString()),
        ]);
    } catch (e) {
        if (seq !== _phState.loadSeq) return;
        if (pane) pane.innerHTML = uiEmptyState('warning', e.message || '', { title: 'Fehler beim Laden' });
        return;
    }
    if (seq !== _phState.loadSeq) return; // a newer request superseded this one
    if (history && history.error) {
        const c = document.getElementById('content');
        if (c) c.innerHTML = uiEmptyState('lock', 'Preishistorie erfordert die Berechtigung „Preishistorie ansehen".', { title: history.error });
        return;
    }
    _phState.history = Array.isArray(history) ? history : [];
    _phState.truncated = _phState.history.length >= PH_LIMIT;
    _phState.stats = (stats && !stats.error) ? stats : null;

    const bento = document.getElementById('phBento');
    if (bento) bento.innerHTML = phBentoHtml(_phState.stats);
    phRefreshTabs();
    phRefreshFilterState();
    phRenderActivePane();
}

// ============================================================
// Stats Bento
// ============================================================
function phFmtPct(v, signed = true) {
    if (v === null || v === undefined) return '–';
    const n = Number(v);
    return (signed && n > 0 ? '+' : '') + n.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' %';
}

function phBentoHtml(s) {
    if (!s) {
        return `
            <div class="ph-stats-bento">
                ${[1,2,3,4,5].map(() => `<div class="ph-kpi"><div class="ph-loading" style="padding:12px">${phSpinner(20)}</div></div>`).join('')}
            </div>`;
    }
    const b = phPeriodBounds();
    const inc = s.biggest_increase;
    const dec = s.biggest_decrease;
    const avgClass = (s.avg_delta_pct ?? 0) >= 0 ? 'ph-kpi-positive' : 'ph-kpi-negative';
    const who = (x) => x ? escapeHtml(x.artikel_name || x.objektname || x.obj_id || '–') + (x.kundenname ? ` · ${escapeHtml(x.kundenname)}` : '') : 'Keine im Zeitraum';
    const click = (x) => x && x.artikel_id ? `data-clickable="1" onclick="phOpenArticleDetail('${escapeHtml(x.artikel_id)}')" style="cursor:pointer" title="Preisverlauf öffnen"` : '';

    return `
        <div class="ph-stats-bento">
            <div class="ph-kpi ph-kpi-neutral">
                <div class="ph-kpi-head">
                    <div class="ph-kpi-icon">${phIcon('history')}</div>
                    <span class="ph-kpi-label">Änderungen</span>
                </div>
                <div class="ph-kpi-value">${(s.total ?? 0).toLocaleString('de-DE')}</div>
                <div class="ph-kpi-sub">${escapeHtml(b.label)} · <strong>${s.increases ?? 0}</strong> ↑ · <strong>${s.decreases ?? 0}</strong> ↓</div>
            </div>

            <div class="ph-kpi">
                <div class="ph-kpi-head">
                    <div class="ph-kpi-icon">${phIcon('group')}</div>
                    <span class="ph-kpi-label">Kunden betroffen</span>
                </div>
                <div class="ph-kpi-value">${s.kunden_affected ?? 0}</div>
                <div class="ph-kpi-sub"><strong>${s.objekte_affected ?? 0}</strong> Objekte · <strong>${s.artikel_affected ?? 0}</strong> Artikel</div>
            </div>

            <div class="ph-kpi ${avgClass}">
                <div class="ph-kpi-head">
                    <div class="ph-kpi-icon">${phIcon('percent')}</div>
                    <span class="ph-kpi-label">Ø Preisänderung</span>
                </div>
                <div class="ph-kpi-value">${phFmtPct(s.avg_delta_pct)}</div>
                <div class="ph-kpi-sub">Kundenpreise im Zeitraum</div>
            </div>

            <div class="ph-kpi ph-kpi-positive" ${click(inc)}>
                <div class="ph-kpi-head">
                    <div class="ph-kpi-icon">${phIcon('trending_up')}</div>
                    <span class="ph-kpi-label">Größte Erhöhung</span>
                </div>
                <div class="ph-kpi-value">${inc ? phFmtPct(inc.pct) : '–'}</div>
                <div class="ph-kpi-sub">${who(inc)}</div>
            </div>

            <div class="ph-kpi ph-kpi-negative" ${click(dec)}>
                <div class="ph-kpi-head">
                    <div class="ph-kpi-icon">${phIcon('trending_down')}</div>
                    <span class="ph-kpi-label">Größte Senkung</span>
                </div>
                <div class="ph-kpi-value">${dec ? phFmtPct(dec.pct) : '–'}</div>
                <div class="ph-kpi-sub">${who(dec)}</div>
            </div>
        </div>
    `;
}

// ============================================================
// Filter toolbar — every change applies immediately
// ============================================================
const PH_TYPES = [
    ['', 'Alle'],
    ['kunde', 'Kundenpreis'],
    ['nu', 'NU-Preis'],
    ['increase', 'Erhöhungen'],
    ['decrease', 'Senkungen'],
];

function phFilterBarHtml() {
    const f = _phState.filters;
    const customerOpts = _phState.customers
        .map(c => uiOption(c.kunde_id, c.kundenname ? `${c.kundenname} (${c.kunde_id})` : c.kunde_id, String(f.kunden_id) === String(c.kunde_id)))
        .join('');
    return `
        <div class="ph-toolbar">
            ${uiTextField({ id: 'phFltSearch', type: 'search', icon: 'search', cls: 'ph-search', value: f.search, placeholder: 'Suche: Artikel, Objekt, Kunde, Grund…', attrs: 'autocomplete="off"' })}
            ${uiSelect({ id: 'phFltKunde', label: 'Kunde', optionsHtml: uiOption('', 'Alle Kunden', !f.kunden_id) + customerOpts })}
            ${uiSelect({ id: 'phFltObj', label: 'Objekt', optionsHtml: uiOption('', 'Alle Objekte', true) })}
            ${uiSelect({ id: 'phFltArtikel', label: 'Artikel', optionsHtml: uiOption('', 'Alle Artikel', true) })}
        </div>
        <div class="ph-toolbar-row2">
            ${uiChips(PH_TYPES.map(([v, l]) => uiChip(l, { kind: 'filter', selected: f.change_type === v, attrs: `data-type="${v}"`, onclick: `phSetType('${v}')` })).join(''), { id: 'phTypeChips' })}
            ${uiButton('Filter zurücksetzen', { variant: 'text', icon: 'filter_alt_off', id: 'phResetBtn', onclick: 'phResetFilters()', attrs: 'style="display:none"' })}
        </div>
    `;
}

function phBindFilterEvents() {
    const kundeSel = document.getElementById('phFltKunde');
    const objSel = document.getElementById('phFltObj');
    const artSel = document.getElementById('phFltArtikel');
    const search = document.getElementById('phFltSearch');
    if (!kundeSel || !objSel || !artSel) return;

    kundeSel.addEventListener('change', () => {
        _phState.filters.kunden_id = kundeSel.value;
        _phState.filters.obj_id = '';
        _phState.filters.artikel_id = '';
        phReloadObjectOptions();
        phReloadArtikelOptions();
        phLoad();
    });
    objSel.addEventListener('change', () => {
        _phState.filters.obj_id = objSel.value;
        _phState.filters.artikel_id = '';
        phReloadArtikelOptions();
        phLoad();
    });
    artSel.addEventListener('change', () => {
        _phState.filters.artikel_id = artSel.value;
        phLoad();
    });
    if (search) {
        let t = null;
        const apply = () => {
            const v = search.value.trim();
            if (v === _phState.filters.search) return;
            _phState.filters.search = v;
            phLoad();
        };
        search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(apply, 350); });
        search.addEventListener('keydown', (e) => { if (e.key === 'Enter') { clearTimeout(t); apply(); } });
    }
}

function phSetType(v) {
    _phState.filters.change_type = v;
    document.querySelectorAll('#phTypeChips md-filter-chip').forEach(c => { c.selected = c.dataset.type === v; });
    phLoad();
}

async function phReloadObjectOptions() {
    const objSel = document.getElementById('phFltObj');
    if (!objSel) return;
    const kundeId = _phState.filters.kunden_id;
    const data = await apiGet(kundeId ? PH_API.objects + '?kunden_id=' + encodeURIComponent(kundeId) : PH_API.objects);
    _phState.objects = Array.isArray(data) ? data : [];
    objSel.innerHTML = uiOption('', 'Alle Objekte', !_phState.filters.obj_id)
        + _phState.objects.map(o => uiOption(o.obj_id, (o.objektname || o.obj_id) + (o.objektname ? ' (' + o.obj_id + ')' : ''), String(_phState.filters.obj_id) === String(o.obj_id))).join('');
    objSel.value = _phState.filters.obj_id || '';
}

async function phReloadArtikelOptions() {
    const artSel = document.getElementById('phFltArtikel');
    if (!artSel) return;
    const { obj_id: objId, kunden_id: kundeId } = _phState.filters;
    const qs = objId ? '?obj_id=' + encodeURIComponent(objId) : (kundeId ? '?kunden_id=' + encodeURIComponent(kundeId) : '');
    const data = await apiGet(PH_API.artikel + qs);
    _phState.artikel = Array.isArray(data) ? data : [];
    artSel.innerHTML = uiOption('', 'Alle Artikel', !_phState.filters.artikel_id)
        + _phState.artikel.map(a => uiOption(a.artikel_id, (a.artikel_name || a.artikel_id) + (a.artikel_name ? ' (' + a.artikel_id + ')' : ''), String(_phState.filters.artikel_id) === String(a.artikel_id))).join('');
    artSel.value = _phState.filters.artikel_id || '';
}

function phActiveFilterCount() {
    return Object.values(_phState.filters).filter(Boolean).length;
}

function phRefreshFilterState() {
    const n = phActiveFilterCount();
    const btn = document.getElementById('phResetBtn');
    if (btn) {
        btn.style.display = n ? '' : 'none';
        btn.lastChild.textContent = n ? `Filter zurücksetzen (${n})` : 'Filter zurücksetzen';
    }
}

function phResetFilters() {
    _phState.filters = { kunden_id: '', obj_id: '', artikel_id: '', change_type: '', search: '' };
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    set('phFltKunde', '');
    set('phFltSearch', '');
    document.querySelectorAll('#phTypeChips md-filter-chip').forEach(c => { c.selected = c.dataset.type === ''; });
    phReloadObjectOptions();
    phReloadArtikelOptions();
    phLoad();
}

// ============================================================
// Tabs
// ============================================================
const PH_TABS = [
    ['verlauf', 'history', 'Verlauf', 'Alle Preisänderungen chronologisch, nach Monat gruppiert.'],
    ['artikel', 'sell', 'Pro Artikel', 'Je Artikel: Preis zu Beginn und am Ende des Zeitraums. Zeile anklicken für den vollständigen Verlauf.'],
    ['top', 'leaderboard', 'Top Änderungen', 'Die 10 größten Erhöhungen und Senkungen des Kundenpreises im Zeitraum.'],
];

function phTabCounts() {
    const h = _phState.history;
    const withPct = h.filter(x => x.delta_kunde_pct !== null && x.delta_kunde_pct !== undefined && Number(x.delta_kunde_pct) !== 0);
    return {
        verlauf: h.length,
        artikel: new Set(h.map(x => x.artikel_id).filter(Boolean)).size,
        top: withPct.length,
    };
}

function phTabsHtml() {
    const counts = phTabCounts();
    return PH_TABS.map(([id, icon, label]) => `
        <md-primary-tab inline-icon data-tab="${id}"${_phState.currentTab === id ? ' active' : ''}>
            ${uiIcon(icon, { slot: 'icon' })}${label}<span class="ph-tab-count">${counts[id]}</span>
        </md-primary-tab>`).join('');
}

function phRefreshTabs() {
    const counts = phTabCounts();
    document.querySelectorAll('#phTabs md-primary-tab').forEach(t => {
        const c = t.querySelector('.ph-tab-count');
        if (c) c.textContent = counts[t.dataset.tab];
    });
    const hint = document.getElementById('phTabHint');
    const def = PH_TABS.find(t => t[0] === _phState.currentTab);
    if (hint) hint.textContent = def ? def[3] : '';
    const info = document.getElementById('phResultInfo');
    if (info) {
        const n = _phState.history.length;
        info.innerHTML = `<strong>${n.toLocaleString('de-DE')}</strong> ${n === 1 ? 'Eintrag' : 'Einträge'} · ${escapeHtml(phPeriodBounds().label)}`
            + (_phState.truncated ? ` <span class="ph-trunc" title="Zeitraum oder Filter eingrenzen">· nur die neuesten ${PH_LIMIT.toLocaleString('de-DE')}</span>` : '');
    }
    const csv = document.getElementById('phCsvBtn');
    if (csv) csv.disabled = _phState.history.length === 0;
}

function phSwitchTab(tab) {
    _phState.currentTab = tab;
    try { localStorage.setItem(PH_TAB_KEY, tab); } catch (e) {}
    document.querySelectorAll('#phTabs md-primary-tab').forEach(t => { t.active = t.dataset.tab === tab; });
    document.querySelectorAll('.ph-pane').forEach(p => p.classList.toggle('active', p.id === 'phPane-' + tab));
    phRefreshTabs();
    phRenderActivePane();
}

function phRenderActivePane() {
    if (_phState.currentTab === 'verlauf') phRenderVerlauf();
    else if (_phState.currentTab === 'artikel') phRenderProArtikel();
    else if (_phState.currentTab === 'top') phRenderTopChanges();
}

function phEmptyHtml(icon, title) {
    const hasFilter = phActiveFilterCount() > 0;
    const actions = (hasFilter ? uiButton('Filter zurücksetzen', { variant: 'text', icon: 'filter_alt_off', onclick: 'phResetFilters()' }) : '')
        + (_phState.period.mode !== 'all' ? uiButton('Gesamten Zeitraum anzeigen', { variant: 'text', icon: 'all_inclusive', onclick: "phSetPeriodMode('all')" }) : '');
    return uiEmptyState(icon, '', {
        title,
        actionHtml: `<p class="ui-empty-text">Im Zeitraum <strong>${escapeHtml(phPeriodBounds().label)}</strong>${hasFilter ? ' mit den gewählten Filtern' : ''} gibt es keine Einträge.</p>${actions ? uiActions(actions) : ''}`,
    });
}

// ============================================================
// Tab 1 — Verlauf (timeline, grouped by month)
// ============================================================
function phRenderVerlauf() {
    const pane = document.getElementById('phPane-verlauf');
    if (!pane) return;
    const rows = _phState.history;
    if (rows.length === 0) { pane.innerHTML = phEmptyHtml('history', 'Keine Preisänderungen gefunden'); return; }

    const groups = [];
    rows.forEach(h => {
        const key = String(h.aenderungsdatum || '').slice(0, 7);
        let g = groups[groups.length - 1];
        if (!g || g.key !== key) { g = { key, rows: [] }; groups.push(g); }
        g.rows.push(h);
    });
    pane.innerHTML = groups.map(g => {
        const [y, m] = g.key.split('-');
        const label = m ? `${PH_MONTHS[parseInt(m, 10) - 1]} ${y}` : 'Ohne Datum';
        return `
            <div class="ph-month">
                <div class="ph-month-head"><span>${label}</span><span class="ph-month-count">${g.rows.length} ${g.rows.length === 1 ? 'Änderung' : 'Änderungen'}</span></div>
                <div class="ph-timeline">${g.rows.map(phTimelineRowHtml).join('')}</div>
            </div>`;
    }).join('');
}

// A row without any previous price is the article's starting price,
// even when older writers stored it as 'update'.
function phTypInfo(h) {
    const isStart = h.aenderung_typ === 'create'
        || ((h.alter_preis_kunde === null || h.alter_preis_kunde === undefined)
            && (h.alter_nu_preis === null || h.alter_nu_preis === undefined));
    if (isStart) return { label: 'Neu', cls: 'create' };
    if (h.aenderung_typ === 'manual') return { label: 'Manuell', cls: 'manual' };
    return { label: 'Änderung', cls: '' };
}

function phTimelineRowHtml(h) {
    const date = new Date(h.aenderungsdatum + 'T00:00:00');
    const dayShort = date.toLocaleDateString('de-DE', { day: '2-digit', month: 'short' });
    const rel = phRelativeDate(h.aenderungsdatum);

    const { label: typLabel, cls: typClass } = phTypInfo(h);
    const artName = h.artikel_name || h.objektname || h.objektname_resolved || h.obj_id;

    const meta = [];
    if (h.kunden_id)  meta.push(`<span class="ph-tl-badge">${phIcon('person', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(h.kundenname || h.kunden_id)}</span>`);
    if (h.obj_id)     meta.push(`<span class="ph-tl-badge">${phIcon('apartment', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(h.objektname_resolved || h.obj_id)}</span>`);
    if (h.bereich)    meta.push(`<span class="ph-tl-badge bc-chip" style="${bereichColorVars(h.bereich)}">${phIcon('sell', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(h.bereich)}</span>`);
    if (h.modell)     meta.push(`<span class="ph-tl-badge">${phIcon('speed', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(h.modell)}</span>`);

    const priceRows = [];
    if (h.neuer_preis_kunde !== null && h.neuer_preis_kunde !== undefined) {
        priceRows.push(phPriceRowHtml('Kunde', h.alter_preis_kunde, h.neuer_preis_kunde, h.delta_kunde_pct));
    }
    if (h.neuer_nu_preis !== null && h.neuer_nu_preis !== undefined) {
        priceRows.push(phPriceRowHtml('NU', h.alter_nu_preis, h.neuer_nu_preis, h.delta_nu_pct));
    }
    const open = h.artikel_id ? ` data-clickable="1" onclick="phOpenArticleDetail('${escapeHtml(h.artikel_id)}')" title="Vollständigen Preisverlauf öffnen"` : '';

    return `
        <div class="ph-timeline-row"${open}>
            <div class="ph-tl-date">
                <span class="ph-tl-date-day">${dayShort}</span>
                <span class="ph-tl-date-rel">${rel}</span>
                <span class="ph-tl-typ ${typClass}">${typLabel}</span>
            </div>
            <div class="ph-tl-body">
                <div class="ph-tl-title">
                    <span class="ph-tl-art-name">${escapeHtml(artName)}</span>
                    ${h.artikel_id ? `<span class="ph-tl-badge ph-mono">${phIcon('tag', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(h.artikel_id)}</span>` : ''}
                </div>
                <div class="ph-tl-meta">${meta.join('')}</div>
                ${h.grund ? `<div class="ph-tl-grund">„${escapeHtml(h.grund)}"</div>` : ''}
            </div>
            <div class="ph-tl-prices">${priceRows.join('')}</div>
            <div class="ph-tl-go">${h.artikel_id ? phIcon('chevron_right', 'font-size:20px') : ''}</div>
        </div>
    `;
}

function phPriceRowHtml(label, oldVal, newVal, deltaPct) {
    const oldStr = (oldVal !== null && oldVal !== undefined) ? formatCurrency(oldVal) : '–';
    const newStr = (newVal !== null && newVal !== undefined) ? formatCurrency(newVal) : '–';

    let pillCls = 'flat', pillIcon = 'drag_handle', pillTxt = '0,0 %';
    if (deltaPct !== null && deltaPct !== undefined) {
        if (deltaPct > 0) { pillCls = 'up';   pillIcon = 'arrow_upward';   pillTxt = '+' + Number(deltaPct).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' %'; }
        else if (deltaPct < 0) { pillCls = 'down'; pillIcon = 'arrow_downward'; pillTxt = Number(deltaPct).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' %'; }
    }

    const showDelta = (oldVal !== null && oldVal !== undefined && deltaPct !== null && deltaPct !== undefined);

    return `
        <div class="ph-price-row">
            <span class="ph-price-label">${label}</span>
            ${oldVal !== null && oldVal !== undefined ? `<span class="ph-price-old">${oldStr}</span><span class="ph-price-arrow">${phIcon('arrow_forward', 'font-size:11px')}</span>` : ''}
            <span class="ph-price-new">${newStr}</span>
            ${showDelta ? `<span class="ph-delta-pill ${pillCls}">${phIcon(pillIcon, 'font-size:11px;vertical-align:-2px')} ${pillTxt}</span>` : ''}
        </div>
    `;
}

function phRelativeDate(ymd) {
    if (!ymd) return '';
    const d = new Date(ymd + 'T00:00:00');
    const today = new Date();
    today.setHours(0,0,0,0);
    const diffDays = Math.round((today - d) / (1000 * 60 * 60 * 24));
    if (diffDays === 0) return 'heute';
    if (diffDays === 1) return 'gestern';
    if (diffDays < 7) return `vor ${diffDays} Tagen`;
    if (diffDays < 30) return `vor ${Math.floor(diffDays / 7)} Wo.`;
    if (diffDays < 365) return `vor ${Math.floor(diffDays / 30)} Mon.`;
    return `vor ${Math.floor(diffDays / 365)} J.`;
}

// ============================================================
// Tab 2 — Pro Artikel (grouped by artikel_id within the period)
// ============================================================
function phRenderProArtikel() {
    const pane = document.getElementById('phPane-artikel');
    if (!pane) return;

    // Oldest → newest so "Beginn" / "Ende" are well-defined per artikel.
    const asc = _phState.history.filter(h => h.artikel_id)
        .slice().sort((a, b) => String(a.aenderungsdatum).localeCompare(String(b.aenderungsdatum)) || (a.id - b.id));
    const has = (v) => v !== null && v !== undefined && v !== '';
    const groups = {};
    asc.forEach(h => {
        let g = groups[h.artikel_id];
        if (!g) {
            g = groups[h.artikel_id] = {
                artikel_id: h.artikel_id,
                artikel_name: h.artikel_name || h.objektname || h.obj_id,
                kunden_id: h.kunden_id, kundenname: h.kundenname,
                obj_id: h.obj_id, objektname: h.objektname_resolved || h.objektname,
                bereich: h.bereich,
                changes: 0,
                start: has(h.alter_preis_kunde) ? parseFloat(h.alter_preis_kunde) : (has(h.neuer_preis_kunde) ? parseFloat(h.neuer_preis_kunde) : null),
                end: null,
                current: has(h.current_preis_kunde) ? parseFloat(h.current_preis_kunde) : null,
                last_change: h.aenderungsdatum,
            };
        }
        g.changes++;
        g.last_change = h.aenderungsdatum;
        if (has(h.neuer_preis_kunde)) g.end = parseFloat(h.neuer_preis_kunde);
        if (g.start === null && has(h.neuer_preis_kunde)) g.start = parseFloat(h.neuer_preis_kunde);
    });

    const list = Object.values(groups).sort((a, b) => String(b.last_change).localeCompare(String(a.last_change)));
    if (list.length === 0) {
        pane.innerHTML = phEmptyHtml('sell', 'Keine Artikel-Änderungen gefunden');
        return;
    }

    pane.innerHTML = `
        <div class="ph-art-table">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Artikel</th>
                            <th>Kunde / Objekt</th>
                            <th class="text-right">Änderungen</th>
                            <th class="text-right">Preis Beginn</th>
                            <th class="text-right">Preis Ende</th>
                            <th class="text-right">Δ Zeitraum</th>
                            <th class="text-right">Heute</th>
                            <th>Letzte Änderung</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        ${list.map(g => phArtikelRowHtml(g)).join('')}
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

function phArtikelRowHtml(g) {
    let pillHtml = '';
    if (g.start !== null && g.end !== null && g.start > 0) {
        const totalPct = ((g.end - g.start) / g.start) * 100;
        const cls = totalPct > 0 ? 'up' : totalPct < 0 ? 'down' : 'flat';
        const icon = totalPct > 0 ? 'arrow_upward' : totalPct < 0 ? 'arrow_downward' : 'drag_handle';
        pillHtml = `<span class="ph-delta-pill ${cls}">${phIcon(icon, 'font-size:11px;vertical-align:-2px')} ${phFmtPct(totalPct)}</span>`;
    }
    const cur = g.current !== null ? g.current : g.end;
    return `
        <tr onclick="phOpenArticleDetail('${escapeHtml(g.artikel_id)}')" title="Vollständigen Preisverlauf öffnen">
            <td>
                <div style="font-weight:700">${escapeHtml(g.artikel_name || '')}</div>
                <div class="ph-cell-sub ph-mono">${escapeHtml(g.artikel_id)}${g.bereich ? ' · ' + escapeHtml(g.bereich) : ''}</div>
            </td>
            <td>
                <div>${escapeHtml(g.kundenname || g.kunden_id || '–')}</div>
                <div class="ph-cell-sub">${escapeHtml(g.objektname || g.obj_id || '–')}</div>
            </td>
            <td class="text-right"><strong>${g.changes}</strong></td>
            <td class="text-right">${g.start !== null ? formatCurrency(g.start) : '–'}</td>
            <td class="text-right"><strong>${g.end !== null ? formatCurrency(g.end) : '–'}</strong></td>
            <td class="text-right">${pillHtml || '<span style="color:var(--ph-text-soft)">–</span>'}</td>
            <td class="text-right">${cur !== null ? formatCurrency(cur) : '–'}</td>
            <td>${formatDate(g.last_change)}<br><span class="ph-cell-sub">${phRelativeDate(g.last_change)}</span></td>
            <td class="ph-cell-go">${phIcon('chevron_right', 'font-size:20px')}</td>
        </tr>
    `;
}

// ============================================================
// Tab 3 — Top Änderungen (biggest increases / decreases)
// ============================================================
function phRenderTopChanges() {
    const pane = document.getElementById('phPane-top');
    if (!pane) return;

    const withPct = _phState.history
        .filter(h => h.delta_kunde_pct !== null && h.delta_kunde_pct !== undefined)
        .map(h => ({ ...h, pct: parseFloat(h.delta_kunde_pct) }));
    // Increases list holds only increases, decreases only decreases.
    const top10Up   = withPct.filter(h => h.pct > 0).sort((a, b) => b.pct - a.pct).slice(0, 10);
    const top10Down = withPct.filter(h => h.pct < 0).sort((a, b) => a.pct - b.pct).slice(0, 10);

    if (!top10Up.length && !top10Down.length) {
        pane.innerHTML = phEmptyHtml('leaderboard', 'Keine Kundenpreis-Änderungen im Zeitraum');
        return;
    }

    const renderRow = (h, rank) => {
        const cls = h.pct > 0 ? 'up' : 'down';
        const icon = h.pct > 0 ? 'arrow_upward' : 'arrow_downward';
        return `
            <li class="ph-top-row" ${h.artikel_id ? `onclick="phOpenArticleDetail('${escapeHtml(h.artikel_id)}')" style="cursor:pointer" title="Preisverlauf öffnen"` : ''}>
                <div class="ph-top-rank">#${rank}</div>
                <div class="ph-top-info">
                    <div class="ph-top-info-name">${escapeHtml(h.artikel_name || h.objektname_resolved || h.obj_id)}</div>
                    <div class="ph-top-info-meta">
                        <span>${escapeHtml(h.kundenname || h.kunden_id || '–')}</span> ·
                        <span>${formatDate(h.aenderungsdatum)}</span> ·
                        <span>${formatCurrency(h.alter_preis_kunde)} → ${formatCurrency(h.neuer_preis_kunde)}</span>
                    </div>
                </div>
                <span class="ph-delta-pill ${cls}">${phIcon(icon, 'font-size:11px;vertical-align:-2px')} ${phFmtPct(h.pct)}</span>
            </li>
        `;
    };
    const none = (icon, txt) => `<li class="ph-top-none">${phIcon(icon, 'font-size:18px')} ${txt}</li>`;

    pane.innerHTML = `
        <div class="ph-top-grid">
            <div class="ph-top-card up">
                <div class="ph-top-card-header">${phIcon('trending_up', 'font-size:15px;vertical-align:-3px')} Größte Erhöhungen <span class="ph-tab-count">${withPct.filter(h => h.pct > 0).length}</span></div>
                <ul class="ph-top-list">${top10Up.length ? top10Up.map((h, i) => renderRow(h, i + 1)).join('') : none('trending_flat', 'Keine Erhöhungen im Zeitraum')}</ul>
            </div>
            <div class="ph-top-card down">
                <div class="ph-top-card-header">${phIcon('trending_down', 'font-size:15px;vertical-align:-3px')} Größte Senkungen <span class="ph-tab-count">${withPct.filter(h => h.pct < 0).length}</span></div>
                <ul class="ph-top-list">${top10Down.length ? top10Down.map((h, i) => renderRow(h, i + 1)).join('') : none('trending_flat', 'Keine Senkungen im Zeitraum')}</ul>
            </div>
        </div>
    `;
}

// ============================================================
// Drill-down modal: full price history of one article + chart
// ============================================================
async function phOpenArticleDetail(artikelId) {
    if (!artikelId) return;
    openPhDialog('Preisverlauf — Lade...', `<div class="ph-loading">${phSpinner(28)} Lade Verlauf...</div>`, '<md-text-button onclick="closePhDialog()">Schließen</md-text-button>', true);

    let data;
    try {
        data = await apiGet(PH_API.article(artikelId));
    } catch (e) {
        document.getElementById('phDialogBody').innerHTML = uiEmptyState('warning', '', { title: 'Fehler beim Laden' });
        return;
    }

    if (!data || !data.artikel) {
        document.getElementById('phDialogBody').innerHTML = uiEmptyState('help', '', { title: 'Artikel nicht gefunden' });
        return;
    }

    const a = data.artikel;
    const history = data.history || [];

    // Compute summary
    const firstK = history.find(h => h.alter_preis_kunde !== null && h.alter_preis_kunde !== undefined)?.alter_preis_kunde
                ?? history[0]?.neuer_preis_kunde ?? null;
    const currentK = a.preis_kunde ?? history[history.length - 1]?.neuer_preis_kunde ?? null;
    let totalPct = null;
    if (firstK !== null && currentK !== null && parseFloat(firstK) > 0) {
        totalPct = ((parseFloat(currentK) - parseFloat(firstK)) / parseFloat(firstK)) * 100;
    }
    const totalCls = totalPct === null ? '' : totalPct > 0 ? 'up' : totalPct < 0 ? 'down' : '';
    const totalTxt = totalPct === null ? '–' : ((totalPct > 0 ? '+' : '') + totalPct.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' %');

    const phTitleEl = document.getElementById('phDialogTitle');
    if (phTitleEl) phTitleEl.textContent = `Preisverlauf — ${a.artikel_id} · ${a.artikel_name || ''}`;

    const summary = `
        <div class="ph-detail-summary">
            <div class="ph-detail-stat">
                <span class="ph-detail-stat-lbl">Aktueller Preis Kunde</span>
                <span class="ph-detail-stat-val">${formatCurrency(currentK)}</span>
            </div>
            <div class="ph-detail-stat">
                <span class="ph-detail-stat-lbl">Aktueller NU-Preis</span>
                <span class="ph-detail-stat-val">${formatCurrency(a.nu_preis)}</span>
            </div>
            <div class="ph-detail-stat">
                <span class="ph-detail-stat-lbl">Aktueller Gewinn</span>
                <span class="ph-detail-stat-val">${formatCurrency(a.gewinn)}</span>
            </div>
            <div class="ph-detail-stat">
                <span class="ph-detail-stat-lbl">Δ Seit Start (Kunde)</span>
                <span class="ph-detail-stat-val ${totalCls}">${totalTxt}</span>
            </div>
        </div>
    `;

    const chartHtml = phChartHtml(history);

    const detailRows = history.length === 0
        ? `<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--ph-text-soft)">Keine Einträge.</td></tr>`
        : history.map(h => {
            const dK = h.delta_kunde_pct;
            const dN = h.delta_nu_pct;
            const cls = dK > 0 ? 'up' : dK < 0 ? 'down' : '';
            const sign = dK > 0 ? '+' : '';
            return `
                <tr>
                    <td>${formatDate(h.aenderungsdatum)}</td>
                    <td><span class="ph-tl-typ ${phTypInfo(h).cls}">${phTypInfo(h).label}</span></td>
                    <td class="text-right">${h.alter_preis_kunde !== null ? formatCurrency(h.alter_preis_kunde) : '–'} → <strong>${formatCurrency(h.neuer_preis_kunde)}</strong></td>
                    <td class="text-right">${dK !== null && dK !== undefined ? `<span class="ph-delta-pill ${cls}">${sign}${Number(dK).toLocaleString('de-DE', { maximumFractionDigits: 1 })} %</span>` : '–'}</td>
                    <td class="text-right">${h.alter_nu_preis !== null ? formatCurrency(h.alter_nu_preis) : '–'} → <strong>${formatCurrency(h.neuer_nu_preis)}</strong></td>
                    <td>${escapeHtml(h.grund || '–')}</td>
                </tr>
            `;
        }).join('');

    document.getElementById('phDialogBody').innerHTML = `
        ${summary}
        ${chartHtml}
        <div class="ph-detail-table-wrap">
            <table class="ph-detail-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Typ</th>
                        <th class="text-right">Kundenpreis</th>
                        <th class="text-right">Δ %</th>
                        <th class="text-right">NU-Preis</th>
                        <th>Grund</th>
                    </tr>
                </thead>
                <tbody>${detailRows}</tbody>
            </table>
        </div>
    `;
}

// ============================================================
// SVG line chart (no library)
// ============================================================
function phChartHtml(history) {
    const points = history
        .filter(h => h.neuer_preis_kunde !== null && h.neuer_preis_kunde !== undefined)
        .map(h => ({
            d: h.aenderungsdatum,
            kunde: parseFloat(h.neuer_preis_kunde),
            nu: (h.neuer_nu_preis !== null && h.neuer_nu_preis !== undefined) ? parseFloat(h.neuer_nu_preis) : null,
        }));

    if (points.length === 0) {
        return '';
    }
    if (points.length === 1) {
        return `
            <div class="ph-chart-wrap">
                <div class="ph-chart-title">${phIcon('show_chart', 'font-size:14px;vertical-align:-2px')} Preisverlauf</div>
                <div style="text-align:center;padding:30px;color:var(--ph-text-soft)">Nur ein Datenpunkt — Verlaufskurve nicht möglich.</div>
            </div>
        `;
    }

    const w = 800, h = 180, padL = 56, padR = 16, padT = 12, padB = 28;
    const allValues = [];
    points.forEach(p => { allValues.push(p.kunde); if (p.nu !== null) allValues.push(p.nu); });
    let yMin = Math.min(...allValues);
    let yMax = Math.max(...allValues);
    if (yMin === yMax) { yMin = yMin * 0.9; yMax = yMax * 1.1 + 1; }
    const ySpan = yMax - yMin;
    yMin -= ySpan * 0.1;
    yMax += ySpan * 0.1;

    const xFor = (i) => padL + (i / (points.length - 1)) * (w - padL - padR);
    const yFor = (v) => padT + (1 - (v - yMin) / (yMax - yMin)) * (h - padT - padB);

    // Y grid lines (5 levels)
    const yLevels = [0, 0.25, 0.5, 0.75, 1];
    const gridLines = yLevels.map(t => {
        const y = padT + t * (h - padT - padB);
        const val = yMax - t * (yMax - yMin);
        return `
            <line class="grid-line" x1="${padL}" y1="${y}" x2="${w - padR}" y2="${y}"/>
            <text class="axis-label" x="${padL - 6}" y="${y + 3}" text-anchor="end">${val.toFixed(2)} €</text>
        `;
    }).join('');

    // X labels — show first, middle, last
    const xLabelIndices = points.length <= 4 ? points.map((_, i) => i) : [0, Math.floor(points.length / 2), points.length - 1];
    const xLabels = xLabelIndices.map(i => {
        const p = points[i];
        const date = new Date(p.d + 'T00:00:00');
        const lbl = date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' });
        return `<text class="axis-label" x="${xFor(i)}" y="${h - 8}" text-anchor="middle">${lbl}</text>`;
    }).join('');

    const linePath = (key) => points.map((p, i) => {
        const v = p[key];
        if (v === null) return '';
        return (i === 0 ? 'M' : 'L') + xFor(i) + ',' + yFor(v);
    }).filter(Boolean).join(' ');

    const dotsKunde = points.map((p, i) => `<circle class="dot-kunde" cx="${xFor(i)}" cy="${yFor(p.kunde)}" r="4"><title>${formatDate(p.d)}: ${formatCurrency(p.kunde)} (Kunde)</title></circle>`).join('');
    const dotsNu = points.map((p, i) => p.nu !== null ? `<circle class="dot-nu" cx="${xFor(i)}" cy="${yFor(p.nu)}" r="4"><title>${formatDate(p.d)}: ${formatCurrency(p.nu)} (NU)</title></circle>` : '').join('');

    return `
        <div class="ph-chart-wrap">
            <div class="ph-chart-title">${phIcon('show_chart', 'font-size:14px;vertical-align:-2px')} Preisverlauf</div>
            <svg class="ph-chart-svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
                ${gridLines}
                <path class="line-kunde" d="${linePath('kunde')}"/>
                <path class="line-nu" d="${linePath('nu')}"/>
                ${dotsKunde}
                ${dotsNu}
                ${xLabels}
            </svg>
            <div class="ph-chart-legend">
                <span class="ph-chart-legend-item"><span class="ph-chart-legend-swatch kunde"></span> Kundenpreis</span>
                <span class="ph-chart-legend-item"><span class="ph-chart-legend-swatch nu"></span> NU-Preis</span>
            </div>
        </div>
    `;
}

// ============================================================
// CSV Export — exactly the selected Zeitraum + filters (same rows as the
// list, which is loaded with the export limit). File name carries the period:
//   preishistorie_2026-10.csv · preishistorie_2026.csv ·
//   preishistorie_2026-01-01_bis_2026-03-31.csv · preishistorie_gesamt.csv
// ============================================================
// ---------- CSV export dialog: own Zeitraum + Bereich ----------
let _phCsvBereiche = null;
function phIsoDate(d) { return `${d.getFullYear()}-${phPad(d.getMonth() + 1)}-${phPad(d.getDate())}`; }

async function phExportCsv() {
    if (_phCsvBereiche === null) {
        try {
            const d = await apiGet(PH_API_BASE + '/bereich-catalog');
            _phCsvBereiche = (d && Array.isArray(d.mains)) ? d.mains.map(m => String(m.name)).filter(Boolean) : [];
        } catch (e) { _phCsvBereiche = []; }
    }
    const b = phPeriodBounds();
    const filterNote = phActiveFilterCount() > 0
        ? `<div class="ph-csv-note">${phIcon('filter_alt', 'font-size:15px')} Die aktiven Filter (Kunde, Objekt, Artikel, Typ, Suche) werden übernommen.</div>` : '';
    const presets = [
        ['view', `Aktuelle Ansicht (${b.label})`], ['7', 'Letzte 7 Tage'], ['30', 'Letzte 30 Tage'],
        ['90', 'Letzte 90 Tage'], ['year', 'Dieses Jahr'], ['all', 'Gesamter Zeitraum'],
    ];
    const bereichChips = _phCsvBereiche.length
        ? uiChips(uiChip('Alle Bereiche', { kind: 'filter', selected: true, attrs: 'data-b=""', onclick: 'phCsvPickBereich(this)' })
          + _phCsvBereiche.map(n => uiChip(n, { kind: 'filter', attrs: `data-b="${escapeHtml(n)}"`, onclick: 'phCsvPickBereich(this)' })).join(''), { id: 'phCsvBereiche' })
        : '<span class="ph-csv-muted">Keine Bereiche angelegt — es werden alle exportiert.</span>';
    openPhDialog(`${phIcon('download', 'vertical-align:-4px')} CSV exportieren`, `
        <div class="ph-csv-label">Zeitraum</div>
        ${uiChips(presets.map(([v, l], i) => uiChip(l, { kind: 'filter', selected: i === 0, attrs: `data-p="${v}"`, onclick: 'phCsvPreset(this)' })).join(''), { id: 'phCsvPresets' })}
        <div class="ph-csv-range">
            ${uiTextField({ id: 'phCsvFrom', type: 'date', label: 'Von', value: b.from || '', attrs: 'onchange="phCsvCustom()"' })}
            ${uiTextField({ id: 'phCsvTo', type: 'date', label: 'Bis', value: b.to || '', attrs: 'onchange="phCsvCustom()"' })}
        </div>
        <div class="ph-csv-label">Bereich</div>
        ${bereichChips}
        ${filterNote}`,
        uiButton('Abbrechen', { variant: 'text', onclick: 'closePhDialog()' })
        + uiButton('Exportieren', { icon: 'download', id: 'phCsvGo', onclick: 'phCsvRun()' }));
}

function phCsvPreset(btn) {
    document.querySelectorAll('#phCsvPresets md-filter-chip').forEach(c => { c.selected = c === btn; });
    const p = btn.dataset.p, now = new Date();
    let from = '', to = '';
    if (p === 'view') { const b = phPeriodBounds(); from = b.from || ''; to = b.to || ''; }
    else if (p === 'year') { from = `${now.getFullYear()}-01-01`; to = phIsoDate(now); }
    else if (p !== 'all') { const f = new Date(now); f.setDate(f.getDate() - (+p)); from = phIsoDate(f); to = phIsoDate(now); }
    document.getElementById('phCsvFrom').value = from;
    document.getElementById('phCsvTo').value = to;
}
function phCsvCustom() {
    document.querySelectorAll('#phCsvPresets md-filter-chip').forEach(c => { c.selected = false; });
}
function phCsvPickBereich(btn) {
    // md-filter-chip has already toggled itself when this click handler runs
    const all = document.querySelectorAll('#phCsvBereiche md-filter-chip');
    if (btn.dataset.b === '') {
        all.forEach(c => { c.selected = c === btn; });
        return;
    }
    const any = [...all].some(c => c.dataset.b !== '' && c.selected);
    all.forEach(c => { if (c.dataset.b === '') c.selected = !any; });
}

async function phCsvRun() {
    const from = document.getElementById('phCsvFrom')?.value || '';
    const to = document.getElementById('phCsvTo')?.value || '';
    if (from && to && from > to) { showToast('„Von" liegt nach „Bis"', 'error'); return; }
    const bereiche = [...document.querySelectorAll('#phCsvBereiche md-filter-chip')].filter(c => c.selected).map(c => c.dataset.b).filter(Boolean);
    const params = new URLSearchParams();
    if (from) params.append('from', from);
    if (to) params.append('to', to);
    Object.entries(_phState.filters).forEach(([k, v]) => { if (v) params.append(k, v); });
    if (bereiche.length) params.append('bereich', bereiche.join(','));
    params.append('limit', String(PH_LIMIT));
    const go = document.getElementById('phCsvGo');
    if (go) go.disabled = true;
    let data;
    try { data = await apiGet(PH_API.list + '?' + params.toString()); }
    catch (e) { if (go) go.disabled = false; showToast('Export fehlgeschlagen', 'error'); return; }
    const rows = Array.isArray(data) ? data : [];
    if (go) go.disabled = false;
    if (!rows.length) { showToast('Keine Einträge für Zeitraum/Bereich', 'warning'); return; }
    const fmt = d => d ? formatDate(d) : '…';
    const label = (from || to) ? `${fmt(from)} – ${fmt(to)}` : 'Gesamter Zeitraum';
    const fileRange = (from || to) ? `${from || 'anfang'}_bis_${to || 'heute'}` : 'gesamt';
    const fileBereich = bereiche.length ? '_' + bereiche.join('-').toLowerCase().replace(/[^a-z0-9äöüß-]+/g, '') : '';
    phWriteCsv(rows, `preishistorie_${fileRange}${fileBereich}.csv`);
    closePhDialog();
    showToast(`${rows.length} Einträge exportiert (${label}${bereiche.length ? ' · ' + bereiche.join(', ') : ''})`
        + (rows.length >= PH_LIMIT ? ` — nur die neuesten ${PH_LIMIT.toLocaleString('de-DE')}` : ''), 'success');
}

function phWriteCsv(rows, filename) {
    const headers = [
        'Datum', 'Typ',
        'Kunde', 'Kunden-ID',
        'Objekt', 'Obj-ID',
        'Artikel', 'Artikel-ID', 'Bereich', 'Modell',
        'Alter Kundenpreis', 'Neuer Kundenpreis', 'Δ Kunde €', 'Δ Kunde %',
        'Alter NU-Preis', 'Neuer NU-Preis', 'Δ NU €', 'Δ NU %',
        'Alter Gewinn', 'Neuer Gewinn', 'Δ Gewinn €',
        'Grund'
    ];

    const escape = (v) => {
        if (v === null || v === undefined) return '';
        const s = String(v).replace(/"/g, '""');
        return /[";\n\r]/.test(s) ? `"${s}"` : s;
    };

    const lines = [headers.join(';')];
    rows.forEach(h => {
        lines.push([
            h.aenderungsdatum ? formatDate(h.aenderungsdatum) : '', phTypInfo(h).label,
            h.kundenname || '', h.kunden_id || '',
            h.objektname_resolved || h.objektname || '', h.obj_id || '',
            h.artikel_name || '', h.artikel_id || '', h.bereich || '', h.modell || '',
            phNum(h.alter_preis_kunde), phNum(h.neuer_preis_kunde), phNum(h.delta_kunde), phNum(h.delta_kunde_pct),
            phNum(h.alter_nu_preis), phNum(h.neuer_nu_preis), phNum(h.delta_nu), phNum(h.delta_nu_pct),
            phNum(h.alter_gewinn), phNum(h.neuer_gewinn), phNum(h.delta_gewinn),
            h.grund || ''
        ].map(escape).join(';'));
    });

    // BOM for proper Excel UTF-8 handling
    const csv = '\ufeff' + lines.join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function phNum(v) {
    if (v === null || v === undefined || v === '') return '';
    return String(v).replace('.', ',');
}
