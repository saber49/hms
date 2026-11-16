/* ============================================================
   HMS / Clood-X — Bestelleingang (Incoming Orders) Module v2
   Provides:
     - List view with Bento KPIs + filter pills + month picker
     - 5-step Wizard (Kunde → Objekt → Artikel → Details → Tage)
     - Modern stepper with animated transitions
     - Sticky breadcrumb showing current selection
     - Step 5 Tage: weekday pills + presets + clean compact table
     - Backend logic UNCHANGED — only UX/visual revamp
   ============================================================ */

const _BE_TOTAL_STEPS = 4;
const _BE_PAGE_SIZE = 100;
let _wizDayCount = 0;

// ============================================================
// TIME HELPERS — overnight-aware (handles shifts crossing midnight)
// ============================================================
// Returns total minutes between von and bis. If bis <= von, treats as overnight (+24h).
function beShiftTotalMinutes(von, bis) {
    if (!von || !bis) return 0;
    const s = von.split(':').map(Number);
    const e = bis.split(':').map(Number);
    const sMin = (s[0] || 0) * 60 + (s[1] || 0);
    const eMin = (e[0] || 0) * 60 + (e[1] || 0);
    let total = eMin - sMin;
    if (total < 0) total += 1440; // overnight shift (e.g. 20:00 → 04:00)
    return total;
}
// Net working minutes after subtracting pause; never negative.
function beShiftNetMinutes(von, bis, pauseMin) {
    const total = beShiftTotalMinutes(von, bis);
    const net = total - (parseInt(pauseMin) || 0);
    return Math.max(0, net);
}
// True if the shift crosses midnight (bis time falls on the next day).
function beIsOvernight(von, bis) {
    if (!von || !bis) return false;
    const s = von.split(':').map(Number);
    const e = bis.split(':').map(Number);
    const sMin = (s[0] || 0) * 60 + (s[1] || 0);
    const eMin = (e[0] || 0) * 60 + (e[1] || 0);
    return eMin < sMin;
}

// ---------- MA-Pause auto-fill (tenant Pausenregelung) ----------
// Configured under Konto & Firma → Unternehmensdaten → Pausenregelung and
// stored on the tenant as settings.pause_rules = [{von, bis, min}, …] (old: [{ab_h, min}]).
// Highest reached threshold wins; a shift shorter than every threshold
// gets 0. Returns null when nothing is configured — the pause field then
// stays fully manual (no behaviour change for tenants without rules).
function bePauseRules() {
    try {
        const t = JSON.parse(localStorage.getItem('hms_tenant') || '{}');
        return Array.isArray(t.pause_rules) ? t.pause_rules : [];
    } catch (e) { return []; }
}

// True once the user typed into the header MA-Pause — from then on the
// header value (even 0 = "keine Pause") beats the Pausenregelung until
// the shift times change again.
function wizHeaderPauseManual() {
    return document.getElementById('wiz_shift_pause')?.dataset.manual === '1';
}

// Range rules {von, bis, min} (Schichtdauer HH:MM, bounds inclusive): the
// range containing the shift duration wins; outside every range = 0.
// Old threshold rules {ab_h, min} (highest reached wins) still work.
function beAutoPauseMin(von, bis) {
    const rules = bePauseRules();
    const total = beShiftTotalMinutes(von, bis);
    if (!rules.length || total <= 0) return null;
    const hm = s => { const m = /^(\d{1,2}):(\d{2})$/.exec(String(s || '').trim()); return m ? +m[1] * 60 + +m[2] : null; };
    if (rules.some(r => r && r.von)) {
        const hit = rules.find(r => { const a = hm(r.von), b = hm(r.bis); return a !== null && b !== null && total >= a && total <= b; });
        return hit ? (parseInt(hit.min) || 0) : 0;
    }
    let pause = 0, bestAb = -1;
    rules.forEach(r => {
        const ab = Math.round((parseFloat(r.ab_h) || 0) * 60);
        if (total >= ab && ab > bestAb) { bestAb = ab; pause = parseInt(r.min) || 0; }
    });
    return pause;
}

// Header Von/Bis changed → derive MA-Pause from the tenant rules and push
// it onto every day row that has no manual pause override. A pause the
// user typed by hand stays editable — the next time change simply
// re-applies the rule (predictable: Zeiten ändern = Regel neu anwenden).
function wizAutoPauseFromTimes() {
    const von = document.getElementById('wiz_shift_von')?.value;
    const bis = document.getElementById('wiz_shift_bis')?.value;
    const p = beAutoPauseMin(von, bis);
    if (p !== null) {
        const el = document.getElementById('wiz_shift_pause');
        if (el) { el.value = p; delete el.dataset.manual; }
        wizSyncPausenToRows();
    }
    wizCalcShiftDuration();
}

const _beState = {
    items: [],
    filterStatus: 'alle',  // 'alle' | 'unbearbeitet' | 'bearbeitet' | 'abgesagt'
    filterMonth: '',       // '' or 'YYYY-MM'
    search: '',
    page: 1,
    perPage: _BE_PAGE_SIZE,
    lastPage: 1,
    total: 0,
    counts: null,
    months: [],
    selKunde: null,
    selObj: null,
    selArt: null,          // primary artikel object (selArts[0]) — kept for legacy readers
    selArts: [],           // ALL selected artikel objects (multi-service orders)
    artikelPreise: {},     // {artikel_id: {modell, kundenpreis}} — per-service step-4 panels
    activeArtTab: null,    // artikel_id of the active Tage tab in step 5
    isEdit: false,         // true while the wizard edits an existing order
};

// ---------- MD3 helpers (official <md-dialog> + Material Symbols) ----------
// The Bestelleingang module no longer uses the legacy openModal() shell.
// Confirms come from mdConfirm() (users.js) — global at runtime.
function beIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}

// All artikel_ids of an order/eingang row. The API returns `artikel_ids`
// as a JSON string (raw be.* column) — parse it; legacy rows only carry
// the single `artikel_id`.
function beArtikelIdsOf(row) {
    try {
        const p = typeof row?.artikel_ids === 'string' ? JSON.parse(row.artikel_ids) : row?.artikel_ids;
        if (Array.isArray(p)) return p.map(String).filter(Boolean);
    } catch (e) { /* malformed JSON → fall back to single artikel_id */ }
    return row?.artikel_id ? [String(row.artikel_id)] : [];
}

function openBeDialog(title, bodyHtml, footerHtml = '', wide = false) {
    _beCalReadOnly = false;   // viewIncomingOrder sets it right after opening
    wizCalClose();
    beCloseHistory();
    document.getElementById('beMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'beMdDialog';
    dlg.classList.add('be-context');
    if (wide) dlg.classList.add('be-dialog-xl');
    dlg.innerHTML = `
        <div slot="headline">${title}</div>
        <div slot="content" class="be-dialog-content" id="beDialogBody">${bodyHtml}</div>
        ${footerHtml ? `<div slot="actions">${footerHtml}</div>` : ''}`;
    document.body.appendChild(dlg);
    const fitMobileContent = () => {
        const content = dlg.querySelector('[slot="content"]');
        if (!content) return;
        if (!window.matchMedia('(max-width: 768px)').matches) {
            content.style.removeProperty('max-height');
            content.style.removeProperty('overflow-y');
            content.style.removeProperty('-webkit-overflow-scrolling');
            content.style.removeProperty('touch-action');
            return;
        }
        const actions = dlg.querySelector('[slot="actions"]');
        const viewportHeight = window.visualViewport?.height || window.innerHeight;
        const top = content.getBoundingClientRect().top;
        const bottom = Math.min(actions?.getBoundingClientRect().top || viewportHeight, viewportHeight - 12);
        content.style.maxHeight = `${Math.max(180, Math.floor(bottom - top - 8))}px`;
        content.style.overflowY = 'auto';
        content.style.webkitOverflowScrolling = 'touch';
        content.style.touchAction = 'pan-y';
    };
    const scheduleMobileFit = () => requestAnimationFrame(() => requestAnimationFrame(fitMobileContent));
    window.addEventListener('resize', scheduleMobileFit);
    window.visualViewport?.addEventListener('resize', scheduleMobileFit);
    dlg.addEventListener('closed', () => {
        window.removeEventListener('resize', scheduleMobileFit);
        window.visualViewport?.removeEventListener('resize', scheduleMobileFit);
        dlg.remove();
    });
    dlg.show();
    Promise.resolve(dlg.updateComplete).then(scheduleMobileFit);
    return dlg;
}
function closeBeDialog() { document.getElementById('beMdDialog')?.close(); }
const beSpinner = (size = 32) => `<md-circular-progress indeterminate style="--md-circular-progress-size:${size}px"></md-circular-progress>`;

// ============================================================
// LIST VIEW
// ============================================================
async function renderIncomingOrders() {
    const content = document.getElementById('content');
    _beListEnsureStyle();
    content.innerHTML = `
        <div id="beBento">${beBentoHtml(_beState.counts)}</div>
        ${beToolbarHtml()}
        <div class="be-table-card" id="beTableCard">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Eingang-ID</th>
                            <th>Kunde</th>
                            <th>Bereich</th>
                            <th>Objekt / Artikel</th>
                            <th>Periode</th>
                            <th>Modell</th>
                            <th>Status</th>
                            <th>Geändert</th>
                            <th style="text-align:right">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody id="beTbody"><tr><td colspan="9" style="text-align:center;padding:40px;color:var(--be-text-muted)">${beSpinner()}<div style="margin-top:10px">Lade Bestelleingänge...</div></td></tr></tbody>
                </table>
            </div>
            <div id="beEmptyMsg" style="display:none"></div>
            <div id="bePager" class="be-pager"></div>
        </div>
    `;

    beBindToolbar();
    const ok = await beFetchPage();
    if (!ok && !_beState.items.length) {
        const tb = document.getElementById('beTbody');
        if (tb) tb.innerHTML = '';
        const empty = document.getElementById('beEmptyMsg');
        if (empty) { empty.style.display = ''; empty.innerHTML = `<div class="be-empty">${beIcon('warning')}<p>Fehler beim Laden</p><md-outlined-button onclick="renderIncomingOrders()">Erneut versuchen</md-outlined-button></div>`; }
    }

    // Deep-link (notification, Planung → "Im Bestelleingang öffnen"): open
    // the order directly — it need not be on the current page.
    try {
        const focusId = sessionStorage.getItem('be_focus_order');
        if (focusId) {
            sessionStorage.removeItem('be_focus_order');
            viewIncomingOrder(focusId);
        }
    } catch (e) { /* sessionStorage unavailable — ignore */ }
}

// Server-side paging: filters, search, KPI counts and the month list all
// come from the API (`paged=1`), so every number on the page is the real
// total — not just what happens to be loaded.
let _beFetchSeq = 0;
async function beFetchPage() {
    const seq = ++_beFetchSeq;
    const s = _beState;
    const p = new URLSearchParams({ paged: '1', page: String(s.page || 1), per_page: String(s.perPage || _BE_PAGE_SIZE) });
    if (s.filterStatus && s.filterStatus !== 'alle') p.set('status', s.filterStatus);
    if (s.filterMonth) { const [y, m] = s.filterMonth.split('-'); p.set('jahr', y); p.set('monat', String(parseInt(m))); }
    if ((s.search || '').trim()) p.set('q', s.search.trim());
    document.getElementById('beTableCard')?.classList.add('is-loading');
    let res;
    try {
        res = await apiGet(API.incoming_orders + '?' + p.toString());
    } catch (e) {
        res = null;
    }
    if (seq !== _beFetchSeq) return true; // a newer request superseded this one
    document.getElementById('beTableCard')?.classList.remove('is-loading');
    if (!res || res.error || (!Array.isArray(res) && !Array.isArray(res.data))) {
        showToast((res && res.error) || 'Fehler beim Laden der Bestelleingänge', 'error');
        return false;
    }
    if (Array.isArray(res)) res = beLocalPage(res); // older backend without paging
    s.items = res.data;
    s.total = res.total;
    s.page = res.page;
    s.lastPage = res.last_page;
    s.perPage = res.per_page;
    s.counts = res.counts || null;
    s.months = Array.isArray(res.months) ? res.months : [];
    const bento = document.getElementById('beBento');
    if (bento) bento.innerHTML = beBentoHtml(s.counts);
    beRenderMonthOptions();
    beRenderRows();
    beRenderPager();
    return true;
}

// Fallback for a backend that still returns a plain array: same paging,
// computed in the browser.
function beLocalPage(all) {
    const s = _beState;
    const q = (s.search || '').toLowerCase().trim();
    const now = new Date();
    const counts = { alle: all.length, unbearbeitet: 0, bearbeitet: 0, abgeschlossen: 0, abgesagt: 0, diesen_monat: 0 };
    all.forEach(i => {
        if (counts[i.status] !== undefined && i.status !== 'alle') counts[i.status]++;
        if (parseInt(i.monat) === now.getMonth() + 1 && parseInt(i.jahr) === now.getFullYear()) counts.diesen_monat++;
    });
    const months = [...new Set(all.map(i => `${i.jahr}-${String(i.monat).padStart(2, '0')}`))].sort().reverse();
    const rows = all.filter(i => {
        if (s.filterStatus !== 'alle' && i.status !== s.filterStatus) return false;
        if (s.filterMonth) {
            const [y, m] = s.filterMonth.split('-');
            if (parseInt(i.jahr) !== parseInt(y) || parseInt(i.monat) !== parseInt(m)) return false;
        }
        if (q) {
            const hay = [i.eingang_id, i.bestell_id, i.kundenname, i.kunden_id, i.objektname, i.obj_id, i.bemerkung, i.kunden_bereich].filter(Boolean).join(' ').toLowerCase();
            if (!q.split(/\s+/).every(t => hay.includes(t))) return false;
        }
        return true;
    });
    const per = s.perPage || _BE_PAGE_SIZE;
    const last = Math.max(1, Math.ceil(rows.length / per));
    const page = Math.min(Math.max(1, s.page || 1), last);
    return { data: rows.slice((page - 1) * per, page * per), total: rows.length, page, per_page: per, last_page: last, counts, months };
}

function beBentoHtml(c) {
    const n = k => (c ? (c[k] || 0) : '…');
    const now = new Date();
    const curMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    const f = _beState;
    const on = (cond) => cond ? ' be-kpi-on' : '';
    return `
        <div class="be-stats-bento">
            <div class="be-kpi${on(f.filterStatus === 'alle' && !f.filterMonth && !f.search)}" data-clickable="1" onclick="beResetFilters()" title="Alle anzeigen (Filter zurücksetzen)">
                <div class="be-kpi-head">
                    <div class="be-kpi-icon">${beIcon('inbox')}</div>
                    <span class="be-kpi-label">Total</span>
                </div>
                <div class="be-kpi-value">${n('alle')}</div>
                <div class="be-kpi-sub">erfasste Bestellungen</div>
            </div>
            <div class="be-kpi be-kpi-warn${on(f.filterStatus === 'unbearbeitet')}" data-clickable="1" onclick="beSetFilter('unbearbeitet')" title="Unbearbeitete anzeigen">
                <div class="be-kpi-head">
                    <div class="be-kpi-icon">${beIcon('hourglass_top')}</div>
                    <span class="be-kpi-label">Unbearbeitet</span>
                </div>
                <div class="be-kpi-value">${n('unbearbeitet')}</div>
                <div class="be-kpi-sub">benötigen Aufmerksamkeit</div>
            </div>
            <div class="be-kpi be-kpi-mint${on(f.filterStatus === 'bearbeitet')}" data-clickable="1" onclick="beSetFilter('bearbeitet')" title="Bearbeitete anzeigen">
                <div class="be-kpi-head">
                    <div class="be-kpi-icon">${beIcon('check_circle')}</div>
                    <span class="be-kpi-label">Bearbeitet</span>
                </div>
                <div class="be-kpi-value">${n('bearbeitet')}</div>
                <div class="be-kpi-sub">in Bestellung umgewandelt${c && c.abgeschlossen ? ` · ${c.abgeschlossen} abgeschlossen` : ''}</div>
            </div>
            <div class="be-kpi${on(f.filterMonth === curMonth)}" data-clickable="1" onclick="beSetMonth('${curMonth}')" title="Bestellungen für diesen Monat anzeigen">
                <div class="be-kpi-head">
                    <div class="be-kpi-icon">${beIcon('calendar_today')}</div>
                    <span class="be-kpi-label">Diesen Monat</span>
                </div>
                <div class="be-kpi-value">${n('diesen_monat')}</div>
                <div class="be-kpi-sub">${MONTHS_DE[now.getMonth() + 1]} ${now.getFullYear()}</div>
            </div>
            <div class="be-kpi ${c && c.abgesagt > 0 ? 'be-kpi-danger' : ''}${on(f.filterStatus === 'abgesagt')}" data-clickable="1" onclick="beSetFilter('abgesagt')" title="Abgesagte anzeigen">
                <div class="be-kpi-head">
                    <div class="be-kpi-icon">${beIcon('block')}</div>
                    <span class="be-kpi-label">Abgesagt</span>
                </div>
                <div class="be-kpi-value">${n('abgesagt')}</div>
                <div class="be-kpi-sub">storniert</div>
            </div>
        </div>
    `;
}

function beToolbarHtml() {
    const f = _beState;
    const showAbgeschlossen = f.filterStatus === 'abgeschlossen' || (f.counts && f.counts.abgeschlossen > 0);
    return `
        <div class="be-toolbar">
            <div class="be-toolbar-left">
                <md-outlined-text-field class="be-search" id="beSearch" label="Bestelleingänge suchen" placeholder="Eingang-ID, Bestell-Nr. (B-…), Kunde, Objekt, Bemerkung..." value="${escapeHtml(f.search)}">
                    <md-icon slot="leading-icon">search</md-icon>
                </md-outlined-text-field>
                <md-chip-set id="beFilterPills">
                    <md-filter-chip label="Alle" data-status="alle" ${f.filterStatus === 'alle' ? 'selected' : ''} onclick="beSetFilter('alle')"></md-filter-chip>
                    <md-filter-chip label="Unbearbeitet" data-status="unbearbeitet" ${f.filterStatus === 'unbearbeitet' ? 'selected' : ''} onclick="beSetFilter('unbearbeitet')"></md-filter-chip>
                    <md-filter-chip label="Bearbeitet" data-status="bearbeitet" ${f.filterStatus === 'bearbeitet' ? 'selected' : ''} onclick="beSetFilter('bearbeitet')"></md-filter-chip>
                    <md-filter-chip label="Abgeschlossen" data-status="abgeschlossen" ${f.filterStatus === 'abgeschlossen' ? 'selected' : ''} ${showAbgeschlossen ? '' : 'hidden'} onclick="beSetFilter('abgeschlossen')"></md-filter-chip>
                    <md-filter-chip label="Abgesagt" data-status="abgesagt" ${f.filterStatus === 'abgesagt' ? 'selected' : ''} onclick="beSetFilter('abgesagt')"></md-filter-chip>
                </md-chip-set>
                <md-outlined-select id="beFilterMonth" class="be-filter-month" label="Monat">
                    ${beMonthOptionsHtml()}
                </md-outlined-select>
            </div>
            <div class="be-toolbar-right">
                <md-filled-button onclick="openIncomingOrderWizard()" style="--md-filled-button-container-color:var(--be-accent)">
                    <md-icon slot="icon">add</md-icon>Neue Bestellung erfassen
                </md-filled-button>
            </div>
        </div>
    `;
}

function beMonthOptionsHtml() {
    const f = _beState;
    const now = new Date();
    const list = [...new Set([...(f.months || []), `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`, ...(f.filterMonth ? [f.filterMonth] : [])])].sort().reverse();
    return `<md-select-option value="" ${!f.filterMonth ? 'selected' : ''}><div slot="headline">Alle Monate</div></md-select-option>`
        + list.map(m => {
            const [y, mm] = m.split('-');
            return `<md-select-option value="${m}" ${f.filterMonth === m ? 'selected' : ''}><div slot="headline">${MONTHS_DE[parseInt(mm)]} ${y}</div></md-select-option>`;
        }).join('');
}

// Rebuild the month list only when it changed (keeps the open state calm).
function beRenderMonthOptions() {
    const sel = document.getElementById('beFilterMonth');
    if (!sel) return;
    const key = (_beState.months || []).join(',') + '|' + _beState.filterMonth;
    if (sel._beKey === key) return;
    sel._beKey = key;
    sel.innerHTML = beMonthOptionsHtml();
    Promise.resolve(sel.updateComplete).then(() => { sel.value = _beState.filterMonth || ''; });
    const chip = document.querySelector('#beFilterPills md-filter-chip[data-status="abgeschlossen"]');
    if (chip) chip.hidden = !(_beState.filterStatus === 'abgeschlossen' || (_beState.counts && _beState.counts.abgeschlossen > 0));
}

let _beSearchTimer = null;
function beBindToolbar() {
    document.getElementById('beSearch')?.addEventListener('input', (e) => {
        _beState.search = e.target.value || '';
        clearTimeout(_beSearchTimer);
        _beSearchTimer = setTimeout(() => { _beState.page = 1; beFetchPage(); }, 300);
    });
    document.getElementById('beFilterMonth')?.addEventListener('change', (e) => {
        const v = e.target.value || '';
        if (v === _beState.filterMonth) return;
        _beState.filterMonth = v;
        _beState.page = 1;
        beFetchPage();
    });
}

function beSetFilter(status) {
    _beState.filterStatus = status;
    _beState.page = 1;
    // md-filter-chip: drive selection via the .selected property (exactly one active).
    document.querySelectorAll('#beFilterPills md-filter-chip').forEach(c => { c.selected = (c.dataset.status === status); });
    beFetchPage();
}

function beSetMonth(month) {
    _beState.filterMonth = month;
    _beState.page = 1;
    const sel = document.getElementById('beFilterMonth');
    if (sel) sel.value = month;
    beFetchPage();
}

function beResetFilters() {
    _beState.filterStatus = 'alle';
    _beState.filterMonth = '';
    _beState.search = '';
    _beState.page = 1;
    const s = document.getElementById('beSearch');
    if (s) s.value = '';
    const sel = document.getElementById('beFilterMonth');
    if (sel) sel.value = '';
    document.querySelectorAll('#beFilterPills md-filter-chip').forEach(c => { c.selected = (c.dataset.status === 'alle'); });
    beFetchPage();
}

// Kept for callers that still trigger a list refresh by this name.
function beApplyFilters() { return beFetchPage(); }

function beGoPage(page) {
    const p = Math.min(Math.max(1, parseInt(page) || 1), _beState.lastPage || 1);
    if (p === _beState.page) return;
    _beState.page = p;
    beFetchPage().then(() => {
        const card = document.getElementById('beTableCard');
        if (card && card.getBoundingClientRect().top < 0) card.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });
}

function beRenderRows() {
    const tbody = document.getElementById('beTbody');
    const empty = document.getElementById('beEmptyMsg');
    if (!tbody) return;
    const s = _beState;
    if (!s.items.length) {
        tbody.innerHTML = '';
        empty.style.display = '';
        const noData = !s.counts || !s.counts.alle;
        empty.innerHTML = `
            <div class="be-empty">
                ${beIcon('inbox')}
                <p>${noData ? 'Noch keine Bestelleingänge erfasst' : 'Keine Treffer mit diesen Filtern'}</p>
                ${noData ? '' : '<md-text-button onclick="beResetFilters()">Filter zurücksetzen</md-text-button>'}
            </div>`;
        return;
    }
    empty.style.display = 'none';
    tbody.innerHTML = s.items.map(beRowHtml).join('');
}

function bePageList(page, last) {
    const set = new Set([1, last, page - 1, page, page + 1].filter(p => p >= 1 && p <= last));
    const arr = [...set].sort((a, b) => a - b);
    const out = [];
    arr.forEach((p, i) => { if (i && p - arr[i - 1] > 1) out.push('…'); out.push(p); });
    return out;
}

function beRenderPager() {
    const el = document.getElementById('bePager');
    if (!el) return;
    const s = _beState;
    if (!s.total) { el.innerHTML = ''; return; }
    const from = (s.page - 1) * s.perPage + 1;
    const to = Math.min(s.total, s.page * s.perPage);
    const btn = (p, icon, title, dis) => uiIconButton(icon, { cls: 'be-pg-btn', title, disabled: dis, onclick: `beGoPage(${p})` });
    el.innerHTML = `
        <div class="be-pg-info"><strong>${from}–${to}</strong> von <strong>${s.total}</strong> Bestellungen · ${s.perPage} pro Seite</div>
        ${s.lastPage > 1 ? `<div class="be-pg-nav">
            ${btn(1, 'first_page', 'Erste Seite', s.page <= 1)}
            ${btn(s.page - 1, 'chevron_left', 'Vorherige Seite', s.page <= 1)}
            ${bePageList(s.page, s.lastPage).map(p => p === '…'
                ? '<span class="be-pg-gap">…</span>'
                : (p === s.page ? `<md-filled-tonal-button class="be-pg-num is-on" aria-current="page" onclick="beGoPage(${p})">${p}</md-filled-tonal-button>` : `<md-text-button class="be-pg-num" onclick="beGoPage(${p})">${p}</md-text-button>`)).join('')}
            ${btn(s.page + 1, 'chevron_right', 'Nächste Seite', s.page >= s.lastPage)}
            ${btn(s.lastPage, 'last_page', 'Letzte Seite', s.page >= s.lastPage)}
        </div>` : ''}`;
}

function _beListEnsureStyle() {
    if (document.getElementById('beListStyle')) return;
    const st = document.createElement('style');
    st.id = 'beListStyle';
    st.textContent = `
        .be-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--be-border,rgba(0,0,0,.08))}
        .be-pager:empty{display:none}
        .be-pg-info{font-size:.82rem;color:var(--be-text-muted)}
        .be-pg-info strong{color:var(--be-text,inherit)}
        .be-pg-nav{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
        .be-pg-gap{padding:0 4px;color:var(--be-text-muted)}
        .be-pg-num{min-width:40px;--md-text-button-container-height:36px;--md-filled-tonal-button-container-height:36px;--md-text-button-leading-space:8px;--md-text-button-trailing-space:8px;--md-filled-tonal-button-leading-space:8px;--md-filled-tonal-button-trailing-space:8px}
        .be-table-card.is-loading tbody{opacity:.45;transition:opacity .15s}
        .be-kpi.be-kpi-on{outline:2px solid var(--be-accent,#2563eb);outline-offset:-2px}
        @media (max-width:640px){.be-pager{justify-content:center}.be-pg-info{width:100%;text-align:center}}
    `;
    document.head.appendChild(st);
}

// ---------- Bestelleingang ↔ Planung ----------
function beCanPlanung() {
    return typeof hasPermission !== 'function' || typeof PAGE_PERMS === 'undefined' || hasPermission(PAGE_PERMS.tasks);
}

// Open the Planung: a single shift (week of its date + Einsatz detail) or
// the whole order (month view, search = Eingang-ID → only its shifts).
async function beOpenInPlanung(opts = {}) {
    if (!beCanPlanung()) { showToast('Keine Berechtigung für die Planung', 'error'); return; }
    closeBeDialog();
    try { if (typeof ensurePageScripts === 'function') await ensurePageScripts('tasks'); } catch (e) { /* loadPage retries */ }
    if (typeof plannerState !== 'undefined') {
        const ymd = opts.datum || `${opts.jahr}-${String(opts.monat).padStart(2, '0')}-01`;
        const d = new Date(String(ymd).substring(0, 10) + 'T12:00:00');
        if (!isNaN(d.getTime())) {
            plannerState.viewMode = opts.einsatzId ? 'week' : 'month';
            plannerState.month = d.getMonth() + 1;
            plannerState.year = d.getFullYear();
            plannerState.weekStart = plannerToIsoDate(plannerGetWeekStart(d));
        }
        plannerState.filters.search = opts.einsatzId ? '' : String(opts.eingangId || '').toLowerCase();
    }
    // A concrete target was asked for: skip the "Bereich auswählen" gate.
    if (typeof plannerRecallBereich === 'function' && plannerRecallBereich() === null) plannerRememberBereich('');
    if (opts.einsatzId) { try { sessionStorage.setItem('hms_focus_tasks', 'einsatz=' + encodeURIComponent(opts.einsatzId)); } catch (e) { /* ignore */ } }
    loadPage('tasks');
}

function beOpenOrderInPlanung(eingangId) {
    const o = _beState.items.find(i => String(i.eingang_id) === String(eingangId)) || window._beViewOrder;
    if (!o) return;
    beOpenInPlanung({ eingangId: o.eingang_id, monat: o.monat, jahr: o.jahr });
}

function beRowHtml(i) {
    const edited = i.updated_at && i.created_at && i.updated_at !== i.created_at;
    const editDate = edited ? formatDateTimeLocal(i.updated_at) : '';
    const monthLabel = MONTHS_DE[i.monat] || i.monat;
    const statusKey = (i.status || 'default').toLowerCase().replace(/\s+/g, '_');

    const dispoStorno = parseInt(i.dispo_storno_count) || 0;
    const stornoBadge = dispoStorno > 0
        ? `<md-text-button onclick="event.stopPropagation();beShowDispoStorno('${escapeHtml(i.eingang_id)}')" title="Klicken, um die gelöschten Schichten anzuzeigen"><md-icon slot="icon">warning</md-icon>Aus Dispo gelöscht${dispoStorno > 1 ? ' (' + dispoStorno + ')' : ''}</md-text-button>`
        : '';

    return `<tr class="${dispoStorno > 0 ? 'be-row-storno' : ''}">
        <td><span class="be-id-cell">${escapeHtml(i.eingang_id)}</span>${i.bestell_id ? `<div style="font-size:0.68rem;color:var(--be-text-muted);font-family:ui-monospace,'SF Mono',monospace" title="Bestell-Nr.">${escapeHtml(i.bestell_id)}</div>` : ''}${stornoBadge}</td>
        <td>
            <div style="font-weight:600">${escapeHtml(i.kundenname || i.kunden_id || '–')}</div>
            <div style="font-size:0.7rem;color:var(--be-text-muted);font-family:ui-monospace,'SF Mono',monospace">${escapeHtml(i.kunden_id || '')}</div>
        </td>
        <td>${i.kunden_bereich ? bereichBadgeHtml(i.kunden_bereich) : '<span style="color:var(--be-text-soft)">–</span>'}</td>
        <td>
            <div style="font-weight:600">${escapeHtml(i.objektname || i.obj_id || '–')}</div>
            <div style="font-size:0.7rem;color:var(--be-text-muted)">${i.artikel_id ? `${beIcon('sell', 'font-size:11px;vertical-align:-2px')} ${escapeHtml(i.artikel_id)}${beArtikelIdsOf(i).length > 1 ? ` <span style="font-weight:700;color:var(--be-accent)" title="${escapeHtml(beArtikelIdsOf(i).join(', '))}">+${beArtikelIdsOf(i).length - 1}</span>` : ''}` : (i.obj_nr_intern ? escapeHtml(i.obj_nr_intern) : '')}</div>
        </td>
        <td><span class="be-period-cell">${monthLabel} ${i.jahr}</span></td>
        <td>${i.modell ? `<span class="be-modell-chip">${escapeHtml(i.modell)}</span>` : '<span style="color:var(--be-text-soft)">–</span>'}</td>
        <td>${beStatusBadge(i.status)}</td>
        <td>${edited ? `<span style="font-size:0.72rem;color:var(--be-warn)" title="${i.updated_at}">${beIcon('edit', 'font-size:11px;vertical-align:-2px;margin-right:2px')}${editDate}</span>` : '<span style="color:var(--be-text-soft)">–</span>'}</td>
        <td style="text-align:right">
            <div class="btn-group" style="justify-content:flex-end">
                <md-icon-button onclick="viewIncomingOrder('${i.eingang_id}')" title="Ansehen"><md-icon>visibility</md-icon></md-icon-button>
                <md-icon-button onclick="editIncomingOrder('${i.eingang_id}')" title="Bearbeiten"><md-icon>edit</md-icon></md-icon-button>
                ${i.bestell_id && beCanPlanung() ? `<md-icon-button onclick="beOpenOrderInPlanung('${escapeHtml(i.eingang_id)}')" title="In der Planung anzeigen"><md-icon>calendar_month</md-icon></md-icon-button>` : ''}
                <md-icon-button onclick="copyIncomingOrder('${i.eingang_id}')" title="Kopieren (z. B. in den nächsten Monat)"><md-icon>content_copy</md-icon></md-icon-button>
                <md-icon-button onclick="deleteIncomingOrder('${i.eingang_id}')" title="Löschen" style="--md-icon-button-icon-color:var(--danger,#ef4444)"><md-icon>delete</md-icon></md-icon-button>
            </div>
        </td>
    </tr>`;
}

function beStatusBadge(status) {
    const s = (status || '').toLowerCase();
    const map = {
        unbearbeitet: { cls: 'be-status-unbearbeitet', label: 'Unbearbeitet' },
        bearbeitet:   { cls: 'be-status-bearbeitet',   label: 'Bearbeitet' },
        abgesagt:     { cls: 'be-status-abgesagt',     label: 'Abgesagt' },
        abgeschlossen:{ cls: 'be-status-bearbeitet',   label: 'Abgeschlossen' },
    };
    const m = map[s] || { cls: 'be-status-default', label: status || '–' };
    return `<span class="be-status ${m.cls}">${m.label}</span>`;
}

// Popup listing the shifts that were deleted from Dispo for this order.
function beShowDispoStorno(eingangId) {
    const item = _beState.items.find(it => String(it.eingang_id) === String(eingangId));
    if (!item) { showToast('Bestellung nicht gefunden', 'error'); return; }

    let log = [];
    try {
        const raw = item.dispo_storno_log;
        log = Array.isArray(raw) ? raw : (raw ? JSON.parse(raw) : []);
    } catch (e) { log = []; }

    const count = parseInt(item.dispo_storno_count) || log.length;

    const fmtDt = (s) => {
        if (!s) return '';
        // Stored UTC (naive) — show as local time.
        let v = String(s).replace(' ', 'T');
        if (!/[zZ]|[+\-]\d{2}:?\d{2}$/.test(v)) v += 'Z';
        const d = new Date(v);
        return isNaN(d.getTime()) ? escapeHtml(s) : d.toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });
    };

    const rows = log.length
        ? log.slice().reverse().map((e, idx) => `
            <tr>
                <td style="text-align:center;color:var(--be-text-muted)">${log.length - idx}</td>
                <td style="font-weight:600">${e.datum ? escapeHtml(formatDate(String(e.datum).substring(0, 10))) : '–'}</td>
                <td>${(e.von || e.bis) ? escapeHtml((e.von || '–') + ' – ' + (e.bis || '–')) : '<span style="color:var(--be-text-soft)">–</span>'}</td>
                <td style="font-family:ui-monospace,'SF Mono',monospace;font-size:0.78rem">${escapeHtml(e.einsatz_id || '–')}</td>
                <td>${e.beschreibung ? escapeHtml(e.beschreibung) : '<span style="color:var(--be-text-soft)">–</span>'}</td>
                <td style="font-size:0.78rem;color:var(--be-text-muted)">${fmtDt(e.deleted_at)}</td>
            </tr>`).join('')
        : `<tr><td colspan="6" style="text-align:center;color:var(--be-text-muted);padding:18px">
                Keine Detaildaten gespeichert (älterer Eintrag) — Anzahl: ${count}
           </td></tr>`;

    const body = `
        <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px">
            <span class="be-dispo-storno" style="cursor:default">${beIcon('warning', 'font-size:12px')} ${count} Schicht(en) aus Dispo gelöscht</span>
            <span style="color:var(--be-text-muted);font-size:0.85rem">Bestellung: ${escapeHtml(item.eingang_id)} — ${escapeHtml(item.kundenname || item.kunden_id || '')}</span>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th style="text-align:center">#</th>
                        <th>Datum</th>
                        <th>Zeit</th>
                        <th>Einsatz-ID</th>
                        <th>Beschreibung</th>
                        <th>Gelöscht am</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        <div style="margin-top:12px;font-size:0.8rem;color:var(--be-text-muted)">
            ${beIcon('info', 'font-size:14px;vertical-align:-2px')} Die Markierung wird automatisch entfernt, sobald Sie diese Bestellung öffnen (Ansehen/Bearbeiten).
        </div>
    `;
    const footer = `
        <md-text-button onclick="closeBeDialog()">Schließen</md-text-button>
        <md-filled-button onclick="closeBeDialog();viewIncomingOrder('${escapeHtml(item.eingang_id)}')" style="--md-filled-button-container-color:var(--be-accent)"><md-icon slot="icon">visibility</md-icon>Bestellung öffnen</md-filled-button>
    `;
    openBeDialog('Aus Dispo gelöschte Schichten', body, footer, true);
}

// ============================================================
// WIZARD — Open
// ============================================================
async function openIncomingOrderWizard() {
    _beState.selKunde = null;
    _beState.selObj = null;
    _beState.selArt = null;
    _beState.selArts = [];
    _beState.artikelPreise = {};
    _beState.activeArtTab = null;
    _beState.isEdit = false;
    _wizDayCount = 0;

    const [customerOpts, allCustomers] = await Promise.all([
        getCustomerOptions(),
        apiGet(API.customers + '?aktiv=1')
    ]);

    const body = beWizardBodyHtml(customerOpts);
    const footer = beWizardFooterHtml(false);

    openBeDialog('Bestelleingang — Neue Bestellung', body, footer, true);
    window._wizStep = 1;
    // Apply the tenant Pausenregelung to the default Von/Bis right away so
    // a new order starts with the configured MA-Pause already filled in.
    wizAutoPauseFromTimes();
}

// Edit mode: Kunde + Objekt of an existing order are fixed (its Einsätze,
// Auftragsbestätigung and billing hang on them — the server rejects a
// change too). A different customer/object = copy or new order.
function wizLockKundeObjekt() {
    const hint = `<div class="be-info-box" style="margin-top:10px">${beIcon('lock', 'font-size:16px')} Bei einer bestehenden Bestellung sind Kunde und Objekt fest. Für einen anderen Kunden oder ein anderes Objekt die Bestellung kopieren oder neu anlegen.</div>`;
    ['wiz_kunde', 'wiz_objekt'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.disabled = true;
            el.title = 'Bei bestehenden Bestellungen nicht änderbar';
            el.style.background = 'var(--be-surface-dim, #f1f5f9)';
            el.style.cursor = 'not-allowed';
            el.style.opacity = '0.85';
        }
    });
    const grid = document.getElementById('wizObjPreview')?.parentElement;
    if (grid && !grid.parentElement.querySelector('.be-lock-hint')) {
        grid.insertAdjacentHTML('afterend', hint.replace('class="be-info-box"', 'class="be-info-box be-lock-hint"'));
    }
}

function beWizardBodyHtml(customerOpts, prefill = {}) {
    const now = new Date();
    _beCalEnsureStyle();
    return `
        ${beStepperHtml(1)}
        ${beBreadcrumbHtml()}

        <!-- STEP 1: Kunde + Objekt -->
        <div class="be-step-pane active" id="wizStep1">
            <div class="be-step-title">${beIcon('person')} Kunde &amp; Objekt auswählen</div>
            <div class="be-step-hint">Wählen Sie den Kunden, der die Bestellung erteilt hat, und das Objekt (den Standort), an dem der Dienst stattfindet.</div>
            <div class="form-grid be-ko-grid" style="grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label>Kunde *</label>
                    <md-outlined-select class="form-control" id="wiz_kunde" onchange="wizLoadObjects()">
                        <md-select-option value="">— Kunde wählen —</md-select-option>
                        ${customerOpts}
                    </md-outlined-select>
                </div>
                <div class="form-group">
                    <label>Objekt *</label>
                    <md-outlined-select class="form-control" id="wiz_objekt" onchange="wizLoadArtikel()">
                        <md-select-option value="">— Objekt wählen —</md-select-option>
                    </md-outlined-select>
                </div>
            </div>
            <div class="form-grid be-ko-grid" style="grid-template-columns:1fr 1fr;gap:12px">
                <div class="be-kunde-preview" id="wizKundePreview">
                    <div class="be-preview-icon">${beIcon('person')}</div>
                    <div class="be-preview-body">
                        <div class="be-preview-empty">Wählen Sie einen Kunden aus, um dessen Daten anzuzeigen.</div>
                    </div>
                </div>
                <div class="be-objekt-preview" id="wizObjPreview">
                    <div class="be-preview-icon">${beIcon('apartment')}</div>
                    <div class="be-preview-body">
                        <div class="be-preview-empty">Wählen Sie zuerst einen Kunden, dann ein Objekt aus.</div>
                    </div>
                </div>
            </div>
            <style>@media (max-width:640px){ .be-ko-grid{grid-template-columns:1fr !important} }</style>
        </div>

        <!-- STEP 2: Artikel -->
        <div class="be-step-pane" id="wizStep2">
            <div class="be-step-title">${beIcon('sell')} Artikel / Leistung auswählen</div>
            <div class="be-step-hint">Ein Objekt kann mehrere Leistungen haben (z.B. Objektschutz, Empfangsdienst, Revierdienst). <strong>Mehrfachauswahl möglich</strong> — jede gewählte Leistung erhält in Schritt 3 ihr eigenes Preis-Panel und in Schritt 4 ihren eigenen Tage-Tab.</div>
            <div id="wizArtikelArea">
                <div class="be-info-box">${beIcon('info', 'font-size:16px')} Wählen Sie zuerst ein Objekt aus, um dessen Artikel anzuzeigen.</div>
            </div>
        </div>

        <!-- STEP 3: Details -->
        <div class="be-step-pane" id="wizStep3">
            <div class="be-step-title">${beIcon('payments')} Preisgestaltung & Bemerkung</div>
            <div class="be-step-hint">Jede gewählte Leistung hat ihr eigenes Panel — Modell und Kundenpreis werden vom Artikel vorausgefüllt und können hier pro Leistung angepasst werden.</div>

            <div id="wizPreisPanels">
                <div class="be-info-box">${beIcon('info', 'font-size:16px')} Wählen Sie zuerst in Schritt 2 eine oder mehrere Leistungen aus.</div>
            </div>

            <div class="be-form-section">
                <div class="be-form-section-title">${beIcon('notes', 'font-size:14px')} Bemerkung <span class="be-anf-hint">— gilt für die gesamte Bestellung</span></div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Bemerkung</label>
                    <md-outlined-text-field type="textarea" class="form-control" id="wiz_bemerkung" rows="2" placeholder="Optional — Hinweise zu dieser spezifischen Bestellung" value="${escapeHtml(prefill.bemerkung || '')}"></md-outlined-text-field>
                </div>
            </div>
        </div>

        <!-- STEP 4: Tage -->
        <div class="be-step-pane" id="wizStep4">
            <div class="be-step-title">${beIcon('calendar_month')} Einsatztage planen</div>
            <div class="be-vl-flow">
                <span><b>1</b>Vorlage ausfüllen</span>${beIcon('chevron_right', 'font-size:16px')}
                <span><b>2</b>Anforderungen</span>${beIcon('chevron_right', 'font-size:16px')}
                <span><b>3</b>Tage erzeugen</span>${beIcon('chevron_right', 'font-size:16px')}
                <span><b>4</b>Im Kalender prüfen</span>
            </div>

            <section class="be-vl-card" id="wizVorlage">
                <header class="be-vl-head">
                    <span class="be-vl-num">1</span>
                    <div class="be-vl-head-text"><strong>Schicht-Vorlage</strong><small>Gilt für jede neu erzeugte Schicht — einzelne Schichten ändern Sie später im Kalender.</small></div>
                    <md-text-button onclick="wizApplyTemplateToRows()" title="Zeiten, Pause, Menge, Ort, Beschreibung und Anforderungen auf alle offenen Schichten übernehmen (eingeplante bleiben unverändert)"><md-icon slot="icon">sync</md-icon>Auf alle Tage übernehmen</md-text-button>
                </header>

                <div class="be-vl-group">
                    <div class="be-vl-label">${beIcon('schedule', 'font-size:16px')} Arbeitszeit</div>
                    <div class="be-vl-grid be-vl-time">
                        <div class="be-vl-field">
                            <label for="wiz_shift_von">Von</label>
                            <md-outlined-text-field type="text" lang="de-DE" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="form-control t24" id="wiz_shift_von" value="08:00" oninput="wizAutoPauseFromTimes()" onchange="wizAutoPauseFromTimes()"></md-outlined-text-field>
                        </div>
                        <div class="be-vl-field">
                            <label for="wiz_shift_bis">Bis</label>
                            <md-outlined-text-field type="text" lang="de-DE" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="form-control t24" id="wiz_shift_bis" value="16:00" oninput="wizAutoPauseFromTimes()" onchange="wizAutoPauseFromTimes()"></md-outlined-text-field>
                        </div>
                        <div class="be-vl-field">
                            <label for="wiz_shift_pause" title="Eine Pause für Mitarbeiter und Kunde">Pause (Min)</label>
                            <md-outlined-text-field type="number" class="form-control" id="wiz_shift_pause" value="0" min="0" step="5" title="Gilt für Mitarbeiter-Stunden und Kunden-Abrechnung" oninput="this.dataset.manual = '1'; wizCalcShiftDuration(); wizSyncPausenToRows()" onchange="this.dataset.manual = '1'; wizCalcShiftDuration(); wizSyncPausenToRows()"></md-outlined-text-field>
                            <input type="hidden" id="wizGesetzlPause" value="0">
                        </div>
                        <div class="be-vl-duration" title="Netto-Arbeitszeit pro Person">
                            <span class="be-vl-dur-main" id="wizShiftDuration">8h 00m</span>
                            <span class="be-vl-dur-sub" id="wizShiftDurationSub"></span>
                        </div>
                    </div>
                    <div class="be-vl-help">Die Pause gilt für Mitarbeiter-Stunden und Kunden-Abrechnung. Endet die Schicht nach Mitternacht, zählt sie zum Starttag.</div>
                </div>

                <div class="be-vl-group">
                    <div class="be-vl-label">${beIcon('group', 'font-size:16px')} Personal</div>
                    <div class="be-vl-grid be-vl-staff">
                        <div class="be-vl-field">
                            <label for="wiz_shift_menge">Menge (Personen)</label>
                            <md-outlined-text-field type="number" class="form-control" id="wiz_shift_menge" value="1" min="1" step="1" onchange="wizAnfMixChanged(null)"></md-outlined-text-field>
                        </div>
                        <div class="be-vl-field be-vl-m">
                            <label for="wiz_anf_m">M davon Männer</label>
                            <md-outlined-text-field type="number" class="form-control" id="wiz_anf_m" min="0" step="1" placeholder="egal" title="Anzahl Männer" onchange="wizAnfMixChanged(this)"></md-outlined-text-field>
                        </div>
                        <div class="be-vl-field be-vl-w">
                            <label for="wiz_anf_w">W davon Frauen</label>
                            <md-outlined-text-field type="number" class="form-control" id="wiz_anf_w" min="0" step="1" placeholder="egal" title="Anzahl Frauen" onchange="wizAnfMixChanged(this)"></md-outlined-text-field>
                        </div>
                    </div>
                    <div class="be-vl-help">M + W dürfen die Menge nicht überschreiten — leer = Geschlecht egal.</div>
                </div>

                <div class="be-vl-group">
                    <div class="be-vl-label">${beIcon('location_on', 'font-size:16px')} Einsatzort &amp; Hinweis</div>
                    <div class="be-vl-grid be-vl-place">
                        <div class="be-vl-field be-vl-ort">
                            <label for="wiz_shift_ort">Ort / Treffpunkt <span class="be-vl-req">*</span> <small>Pflichtfeld · ein eigener Tag-Ort hat Vorrang</small></label>
                            <md-outlined-text-field type="text" class="form-control" id="wiz_shift_ort" required autocomplete="off" placeholder="z.B. Haupteingang, Musterstr. 1, 04109 Leipzig" oninput="beOrtInput(event); wizRefreshOrtHints()" onfocusout="beOrtHide()"></md-outlined-text-field>
                            <div id="wiz_ort_suggest" class="be-ort-suggest" style="display:none"></div>
                            <style>
                              .be-ort-suggest{position:absolute;left:0;right:0;top:100%;z-index:60;background:var(--white);border:1px solid var(--gray-200);border-radius:8px;box-shadow: 0 10px 28px color-mix(in srgb, var(--md-sys-color-primary) 14%, transparent);max-height:240px;overflow:auto;margin-top:4px}
                              .be-ort-item{padding:8px 10px;cursor:pointer;border-bottom:1px solid var(--gray-100);font-size:.85rem;display:flex;align-items:flex-start;gap:8px;color:var(--gray-700);line-height:1.3}
                              .be-ort-item:last-child{border-bottom:0}
                              .be-ort-item:hover{background: var(--be-accent-soft, var(--md-sys-color-primary-container))}
                              .be-ort-item .material-symbols-outlined{color: var(--md-sys-color-primary);font-size:15px;margin-top:1px}
                              .be-ort-empty{padding:8px 10px;font-size:.8rem;color:var(--gray-400)}
                            </style>
                        </div>
                        <div class="be-vl-field">
                            <label for="wiz_shift_beschreibung">Beschreibung <span class="be-vl-req">*</span> <small>Pflichtfeld · gilt für alle Tage ohne eigene Beschreibung</small></label>
                            <md-outlined-text-field type="text" class="form-control" id="wiz_shift_beschreibung" placeholder="z.B. Dienstausweis mitbringen, 15 Min vor Dienstbeginn da sein" onchange="wizApplyMetaToRows()" oninput="wizUpdateObjBeschreibungHint()"></md-outlined-text-field>
                            <div id="wizObjBeschreibungHint" class="be-obj-beschr-hint" style="display:none"></div>
                        </div>
                    </div>
                </div>

                <div class="be-vl-check" id="wizVorlageCheck" style="display:none"></div>
            </section>

            <section class="be-vl-card" id="wizAnf">
                <header class="be-vl-head">
                    <span class="be-vl-num">2</span>
                    <div class="be-vl-head-text"><strong>Anforderungen</strong><small>Für die Planung sichtbar · gilt für alle Schichten dieser Bestellung</small></div>
                </header>
                <div class="be-vl-grid be-vl-reqs">
                    <div class="be-vl-field">
                        <label for="wiz_anf_qualifikation">${beIcon('workspace_premium', 'font-size:14px')} Qualifikation</label>
                        <md-outlined-text-field type="text" class="form-control" id="wiz_anf_qualifikation" placeholder="z.B. Sachkunde §34a, Erste Hilfe" onchange="wizApplyMetaToRows()"></md-outlined-text-field>
                    </div>
                    <div class="be-vl-field">
                        <label for="wiz_anf_kleidung">${beIcon('apparel', 'font-size:14px')} Kleidung</label>
                        <md-outlined-text-field type="text" class="form-control" id="wiz_anf_kleidung" placeholder="z.B. neutrale DK, schwarze Hose" onchange="wizApplyMetaToRows()"></md-outlined-text-field>
                    </div>
                    <div class="be-vl-field">
                        <label for="wiz_anf_skill">${beIcon('translate', 'font-size:14px')} Skill / Sprache</label>
                        <md-outlined-text-field type="text" class="form-control" id="wiz_anf_skill" placeholder="z.B. Deutsch B1" onchange="wizApplyMetaToRows()"></md-outlined-text-field>
                    </div>
                </div>
                <div class="be-anf-positions be-vl-pos">
                    <div class="be-anf-positions-head">
                        <span>${beIcon('format_list_numbered', 'font-size:15px;vertical-align:-3px')} Positionen <small>optional — Personen nach Funktion aufteilen</small> <span class="be-vl-pos-sum" id="wizPosSum"></span></span>
                        <md-text-button onclick="wizAddPosition(); wizVorlageCheck()"><md-icon slot="icon">add</md-icon>Position</md-text-button>
                    </div>
                    <div class="be-anf-pos-head-row">
                        <span>Anzahl</span><span>Funktion</span><span>Geschlecht</span><span>Qualifikation</span><span>Kleidung</span><span></span>
                    </div>
                    <div id="wizPositionsBody"></div>
                    <div class="be-vl-pos-empty">Keine Positionen — alle Personen haben dieselben Anforderungen.</div>
                </div>
            </section>

            <section class="be-vl-card" id="wizGen" data-mode="monat">
                <header class="be-vl-head">
                    <span class="be-vl-num">3</span>
                    <div class="be-vl-head-text"><strong>Tage erzeugen</strong><small>Neue Schichten übernehmen die Vorlage · bereits geplante Tage bleiben erhalten</small></div>
                </header>
                <div class="be-vl-grid be-vl-period">
                    <div class="be-vl-field">
                        <label for="wiz_monat">Bestellmonat</label>
                        <md-outlined-select class="form-control" id="wiz_monat">
                            ${MONTHS_DE.map((m, i) => i > 0 ? `<md-select-option value="${i}" ${i === (prefill.monat || now.getMonth() + 1) ? 'selected' : ''}>${m}</md-select-option>` : '').join('')}
                        </md-outlined-select>
                    </div>
                    <div class="be-vl-field">
                        <label for="wiz_jahr">Jahr</label>
                        <md-outlined-text-field type="number" class="form-control" id="wiz_jahr" min="2020" max="2100" value="${prefill.jahr || now.getFullYear()}"></md-outlined-text-field>
                    </div>
                </div>
                ${uiChips(uiChip('Ganzer Monat', { kind: 'filter', icon: 'event_repeat', selected: true, cls: 'active', attrs: 'data-mode="monat"', onclick: "wizGenMode('monat')" })
                    + uiChip('Zeitraum', { kind: 'filter', icon: 'date_range', attrs: 'data-mode="zeitraum"', onclick: "wizGenMode('zeitraum')" })
                    + uiChip('Einzeltag', { kind: 'filter', icon: 'event', attrs: 'data-mode="einzel"', onclick: "wizGenMode('einzel')" }), { cls: 'be-vl-seg' })}
                <div class="be-vl-wd">
                    <div class="be-vl-label">${beIcon('repeat', 'font-size:16px')} Wochentage</div>
                    <div class="be-weekday-pills" id="wizWeekdayToggles">
                        <md-filter-chip class="be-weekday-pill" label="Mo" data-day="1" onclick="wizToggleDay(this)"></md-filter-chip>
                        <md-filter-chip class="be-weekday-pill" label="Di" data-day="2" onclick="wizToggleDay(this)"></md-filter-chip>
                        <md-filter-chip class="be-weekday-pill" label="Mi" data-day="3" onclick="wizToggleDay(this)"></md-filter-chip>
                        <md-filter-chip class="be-weekday-pill" label="Do" data-day="4" onclick="wizToggleDay(this)"></md-filter-chip>
                        <md-filter-chip class="be-weekday-pill" label="Fr" data-day="5" onclick="wizToggleDay(this)"></md-filter-chip>
                        <md-filter-chip class="be-weekday-pill weekend" label="Sa" data-day="6" onclick="wizToggleDay(this)"></md-filter-chip>
                        <md-filter-chip class="be-weekday-pill weekend" label="So" data-day="0" onclick="wizToggleDay(this)"></md-filter-chip>
                    </div>
                    <div class="be-day-presets">
                        <md-assist-chip class="be-day-preset" label="Werktage Mo–Fr" onclick="wizPreset('werktage')"><md-icon slot="icon">work</md-icon></md-assist-chip>
                        <md-assist-chip class="be-day-preset" label="Ganze Woche" onclick="wizPreset('alle')"><md-icon slot="icon">date_range</md-icon></md-assist-chip>
                        <md-assist-chip class="be-day-preset" label="Wochenende" onclick="wizPreset('wochenende')"><md-icon slot="icon">beach_access</md-icon></md-assist-chip>
                        <md-assist-chip class="be-day-preset" label="Leeren" onclick="wizPreset('clear')"><md-icon slot="icon">ink_eraser</md-icon></md-assist-chip>
                    </div>
                </div>
                <div class="be-vl-panel" data-panel="monat">
                    <div class="be-vl-panel-info">Erzeugt jeden gewählten Wochentag im Bestellmonat.</div>
                    <md-filled-button onclick="wizGenerateDays()" style="--md-filled-button-container-color:var(--be-accent)"><md-icon slot="icon">event_repeat</md-icon>Tage generieren</md-filled-button>
                </div>
                <div class="be-vl-panel" data-panel="zeitraum">
                    <div class="be-vl-field">
                        <label for="wiz_range_von">Von</label>
                        <md-outlined-text-field type="date" class="form-control" id="wiz_range_von"></md-outlined-text-field>
                    </div>
                    <div class="be-vl-field">
                        <label for="wiz_range_bis">Bis</label>
                        <md-outlined-text-field type="date" class="form-control" id="wiz_range_bis" onkeydown="if (event.key === 'Enter') { event.preventDefault(); wizGenerateRange(); }"></md-outlined-text-field>
                    </div>
                    <md-filled-button onclick="wizGenerateRange()" style="--md-filled-button-container-color:var(--be-accent)"><md-icon slot="icon">event_repeat</md-icon>Zeitraum generieren</md-filled-button>
                    <div class="be-vl-panel-info">Ohne gewählte Wochentage wird jeder Tag im Zeitraum erzeugt.</div>
                </div>
                <div class="be-vl-panel" data-panel="einzel">
                    <div class="be-vl-field">
                        <label for="wiz_single_date">Datum</label>
                        <md-outlined-text-field type="date" class="form-control" id="wiz_single_date" onkeydown="if (event.key === 'Enter') { event.preventDefault(); wizAddSingleDay(); }"></md-outlined-text-field>
                    </div>
                    <md-filled-button onclick="wizAddSingleDay()" style="--md-filled-button-container-color:var(--be-accent)"><md-icon slot="icon">add</md-icon>Einzeltag hinzufügen</md-filled-button>
                    <div class="be-vl-panel-info">Tipp: Ein Klick auf einen Tag im Kalender fügt ebenfalls eine Schicht hinzu.</div>
                </div>
            </section>

            <section class="be-vl-card be-vl-calcard">
                <header class="be-vl-head">
                    <span class="be-vl-num">4</span>
                    <div class="be-vl-head-text"><strong>Kalender</strong><small>Jede Schicht wird ein Einsatz in der Planung · Schicht anklicken für Details</small></div>
                    <span class="be-days-search" title="Blendet nur aus — gespeichert werden weiterhin alle Tage">
                        ${uiTextField({ id: 'wizDaysSearch', type: 'search', icon: 'search', placeholder: 'Schicht suchen', attrs: 'autocomplete="off" oninput="wizFilterDays(this.value)"' })}
                        <span id="wizDaysSearchCount" style="display:none"></span>
                    </span>
                </header>
                <div id="wizDaysLockLegend" class="be-days-lock-legend" style="display:none"></div>
                <md-tabs id="wizArtTabs" class="be-art-tabs"></md-tabs>
                <div id="wizPauseNote" class="be-cal-note" style="display:none"></div>
                <div id="wizDaysCal" class="be-cal"></div>
            <div class="be-days-table-wrap" style="display:none">
                <div id="wizDaysTopScroll" style="display:none;overflow-x:auto;overflow-y:hidden;height:14px;margin-bottom:2px" onscroll="wizTopScrollSync('top')"><div id="wizDaysTopScrollInner" style="height:1px"></div></div>
                <md-icon-button class="be-days-nav-left is-hidden" id="wizDaysNavL" onclick="wizDaysScrollStep(-1)" title="Nach links scrollen" aria-label="Nach links scrollen"><md-icon slot="icon">chevron_left</md-icon></md-icon-button>
                <md-icon-button class="be-days-nav-right is-hidden" id="wizDaysNavR" onclick="wizDaysScrollStep(1)" title="Nach rechts scrollen" aria-label="Nach rechts scrollen"><md-icon slot="icon">chevron_right</md-icon></md-icon-button>
                <div class="table-container" id="wizDaysScroll" onscroll="wizTopScrollSync('body')">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Datum</th>
                                <th>Wochentag</th>
                                <th>Von</th>
                                <th>Bis</th>
                                <th class="text-right">Std</th>
                                <th class="text-right" title="Mitarbeiter-Pause">MA-Pause</th>
                                <th class="text-right" title="Kunden-Pause / gesetzlich">K-Pause</th>
                                <th class="text-right">Menge</th>
                                <th>Beschreibung</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="wizDaysBody"></tbody>
                        <tfoot id="wizDaysFooter" style="display:none">
                            <tr>
                                <td colspan="5" style="text-align:right">Summe:</td>
                                <td class="text-right" id="wizTotalStd">0,00</td>
                                <td colspan="5"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="be-days-empty" id="wizDaysEmpty">
                    ${beIcon('event_busy')}
                    Noch keine Tage hinzugefügt — wählen Sie Wochentage und klicken auf "Tage generieren".
                </div>
            </div>
            </section>
        </div>
    `;
}

function beWizardFooterHtml(isEdit, eingangId, originalStatus) {
    const saveAction = isEdit
        ? `wizSaveEditIncoming('${eingangId}', '${originalStatus}')`
        : 'wizSaveIncoming()';
    return `
        <md-outlined-button id="wizPrev" onclick="wizPrevStep()" style="display:none"><md-icon slot="icon">chevron_left</md-icon>Zurück</md-outlined-button>
        <md-filled-button id="wizNext" onclick="wizNextStep()" style="--md-filled-button-container-color:var(--be-accent)">Weiter<md-icon slot="icon">chevron_right</md-icon></md-filled-button>
        <md-filled-button id="wizSave" onclick="${saveAction}" style="display:none;--md-filled-button-container-color:var(--be-mint,#10b981)"><md-icon slot="icon">save</md-icon>${isEdit ? 'Änderungen speichern' : 'Bestellung speichern'}</md-filled-button>
    `;
}

function beStepperHtml(currentStep) {
    const steps = [
        { num: 1, label: 'Kunde & Objekt' },
        { num: 2, label: 'Artikel' },
        { num: 3, label: 'Details' },
        { num: 4, label: 'Tage'    },
    ];
    return `
        <div class="be-stepper">
            ${steps.map(s => {
                const cls = s.num < currentStep ? 'completed' : (s.num === currentStep ? 'active' : '');
                return `
                    <div class="be-stepper-step ${cls}" data-step="${s.num}" onclick="wizGoToStep(${s.num})" title="${s.label}">
                        <div class="be-stepper-circle">
                            <span class="be-stepper-num">${s.num}</span>
                        </div>
                        <span class="be-stepper-label">${s.label}</span>
                    </div>
                `;
            }).join('')}
        </div>
    `;
}

function beBreadcrumbHtml() {
    const k = _beState.selKunde;
    const o = _beState.selObj;
    const arts = _beState.selArts && _beState.selArts.length ? _beState.selArts : (_beState.selArt ? [_beState.selArt] : []);
    // On step 5 the artikel chips double as the per-service Tage tabs —
    // clicking one switches which service's day table is shown.
    const onDaysStep = window._wizStep === _BE_TOTAL_STEPS;
    const artikelChip = arts.length === 0
        ? `<span class="be-bc-chip be-bc-chip-empty">${beIcon('sell', 'font-size:13px')}<em>Kein Artikel</em></span>`
        : arts.map(a => {
            const active = onDaysStep && String(_beState.activeArtTab) === String(a.artikel_id);
            const clickable = onDaysStep && arts.length > 1;
            return `<span class="be-bc-chip ${clickable ? 'be-bc-chip-tab' : ''} ${active ? 'be-bc-chip-active' : ''}" ${clickable ? `onclick="wizSwitchArtTab('${escapeHtml(String(a.artikel_id))}')" title="Tage dieser Leistung anzeigen"` : ''}>
                ${beIcon('sell', 'font-size:13px')}
                <strong>${escapeHtml(a.artikel_name || a.artikel_id)}</strong>
            </span>`;
        }).join('');
    return `
        <div class="be-breadcrumb" id="beBreadcrumb">
            <span class="be-bc-chip ${!k ? 'be-bc-chip-empty' : ''}">
                ${beIcon('person', 'font-size:13px')}
                ${k ? `<strong>${escapeHtml(k.kundenname || k.kunde_id)}</strong>` : '<em>Kein Kunde</em>'}
            </span>
            ${beIcon('chevron_right', 'font-size:14px;color:var(--be-text-soft)')}
            <span class="be-bc-chip ${!o ? 'be-bc-chip-empty' : ''}">
                ${beIcon('apartment', 'font-size:13px')}
                ${o ? `<strong>${escapeHtml(o.objektname || o.obj_id)}</strong>` : '<em>Kein Objekt</em>'}
            </span>
            ${beIcon('chevron_right', 'font-size:14px;color:var(--be-text-soft)')}
            ${artikelChip}
        </div>
    `;
}

function beUpdateBreadcrumb() {
    const wrap = document.getElementById('beBreadcrumb');
    if (!wrap) return;
    wrap.outerHTML = beBreadcrumbHtml();
}

// ============================================================
// WIZARD — Stepper navigation
// ============================================================
function wizNextStep() {
    if (window._wizStep === 1 && !document.getElementById('wiz_kunde').value) {
        showToast('Bitte Kunde auswählen', 'error'); return;
    }
    if (window._wizStep === 1 && !document.getElementById('wiz_objekt').value) {
        showToast('Bitte Objekt auswählen', 'error'); return;
    }
    if (window._wizStep === 2) {
        const artVal = (_beState.selArts || []).length > 0 || ((document.getElementById('wiz_artikel') || {}).value || _beState.selArt?.artikel_id);
        if (!artVal) { showToast('Bitte mindestens einen Artikel / eine Leistung auswählen', 'error'); return; }
    }
    if (window._wizStep >= _BE_TOTAL_STEPS) return;

    const stepEl = document.querySelector(`.be-stepper-step[data-step="${window._wizStep}"]`);
    if (stepEl) {
        stepEl.classList.remove('active');
        stepEl.classList.add('completed');
    }
    document.getElementById(`wizStep${window._wizStep}`).classList.remove('active');

    window._wizStep++;

    const nextEl = document.querySelector(`.be-stepper-step[data-step="${window._wizStep}"]`);
    if (nextEl) nextEl.classList.add('active');
    document.getElementById(`wizStep${window._wizStep}`).classList.add('active');

    document.getElementById('wizPrev').style.display = window._wizStep > 1 ? '' : 'none';
    document.getElementById('wizNext').style.display = window._wizStep < _BE_TOTAL_STEPS ? '' : 'none';
    document.getElementById('wizSave').style.display = window._wizStep === _BE_TOTAL_STEPS ? '' : 'none';
    if (window._wizStep === _BE_TOTAL_STEPS) wizRenderDaysCal();
}

function wizPrevStep() {
    if (window._wizStep <= 1) return;
    document.querySelector(`.be-stepper-step[data-step="${window._wizStep}"]`)?.classList.remove('active');
    document.getElementById(`wizStep${window._wizStep}`).classList.remove('active');

    window._wizStep--;
    const prevEl = document.querySelector(`.be-stepper-step[data-step="${window._wizStep}"]`);
    if (prevEl) {
        prevEl.classList.remove('completed');
        prevEl.classList.add('active');
    }
    document.getElementById(`wizStep${window._wizStep}`).classList.add('active');

    document.getElementById('wizPrev').style.display = window._wizStep > 1 ? '' : 'none';
    document.getElementById('wizNext').style.display = window._wizStep < _BE_TOTAL_STEPS ? '' : 'none';
    document.getElementById('wizSave').style.display = window._wizStep === _BE_TOTAL_STEPS ? '' : 'none';
}

// Jump to a step via the regular Weiter/Zurück handlers, so every step
// validation still applies; stops at the first step that refuses to advance.
function wizGoToStep(target) {
    target = Math.max(1, Math.min(_BE_TOTAL_STEPS, parseInt(target) || 1));
    while (window._wizStep < target) {
        const before = window._wizStep;
        wizNextStep();
        if (window._wizStep === before) break;
    }
    while (window._wizStep > target) wizPrevStep();
    // On the Tage step the artikel chips double as tabs.
    beUpdateBreadcrumb();
}

// ============================================================
// WIZARD — Cascade loaders
// ============================================================
async function wizLoadObjects() {
    const kundenId = document.getElementById('wiz_kunde').value;
    if (!kundenId) {
        _beState.selKunde = null;
        wizUpdateKundePreview(null);
        beUpdateBreadcrumb();
        return;
    }
    const customer = await apiGet(API.customers + '?id=' + kundenId);
    _beState.selKunde = customer;
    wizUpdateKundePreview(customer);

    const opts = await getObjectOptions(kundenId);
    document.getElementById('wiz_objekt').innerHTML = '<md-select-option value="">— Objekt wählen —</md-select-option>' + opts;

    _beState.selObj = null;
    _beState.selArt = null;
    _beState.selArts = [];
    _beState.artikelPreise = {};
    _beState.activeArtTab = null;
    wizUpdateObjPreview(null);
    beUpdateBreadcrumb();
    wizRenderPreisPanels();
    wizRenderArtTabs();
}

function wizUpdateKundePreview(c) {
    const wrap = document.getElementById('wizKundePreview');
    if (!wrap) return;
    if (!c || !c.kunde_id) {
        wrap.classList.remove('has-data');
        wrap.innerHTML = `
            <div class="be-preview-icon">${beIcon('person')}</div>
            <div class="be-preview-body"><div class="be-preview-empty">Wählen Sie einen Kunden aus, um dessen Daten anzuzeigen.</div></div>
        `;
        return;
    }
    wrap.classList.add('has-data');
    const adresse = [c.strasse, [c.plz, c.ort].filter(Boolean).join(' ')].filter(Boolean).join(', ');
    wrap.innerHTML = `
        <div class="be-preview-icon">${beIcon('person')}</div>
        <div class="be-preview-body">
            <div class="be-preview-title">${escapeHtml(c.kundenname || c.kunde_id)}</div>
            <div class="be-preview-meta">
                <span>${beIcon('tag', 'font-size:12px')}<span style="font-family:ui-monospace,monospace">${escapeHtml(c.kunde_id)}</span></span>
                ${c.bereich ? `<span>${beIcon('sell', 'font-size:12px')}${escapeHtml(c.bereich)}</span>` : ''}
                ${adresse ? `<span>${beIcon('location_on', 'font-size:12px')}${escapeHtml(adresse)}</span>` : ''}
                ${c.email ? `<span>${beIcon('mail', 'font-size:12px')}${escapeHtml(c.email)}</span>` : ''}
                ${c.telefon ? `<span>${beIcon('call', 'font-size:12px')}${escapeHtml(c.telefon)}</span>` : ''}
            </div>
        </div>
    `;
}

async function wizLoadArtikel() {
    const objId = document.getElementById('wiz_objekt').value;
    if (!objId) {
        _beState.selObj = null;
        _beState.selArt = null;
        _beState.selArts = [];
        _beState.artikelPreise = {};
        _beState.activeArtTab = null;
        wizUpdateObjPreview(null);
        wizRenderArtikelArea([]);
        beUpdateBreadcrumb();
        wizRenderPreisPanels();
        wizRenderArtTabs();
        return;
    }

    const obj = await apiGet(API.objects + '?id=' + objId);
    _beState.selObj = obj;
    wizUpdateObjPreview(obj);

    if (typeof API.artikel === 'undefined') {
        API.artikel = (typeof API_BASE !== 'undefined' ? API_BASE : window.location.origin + '/api') + '/artikel';
    }
    const articles = await apiGet(API.artikel + '?obj_id=' + encodeURIComponent(objId) + '&aktiv=1');
    const arr = Array.isArray(articles) ? articles : [];

    wizRenderArtikelArea(arr);

    // Auto-select if only 1 — new orders only. In edit mode the stored
    // selection is restored afterwards (wizSelectArtikel toggles, so an
    // auto-pick here would be undone by that restore → "Kein Artikel").
    if (arr.length === 1 && !_beState.isEdit) {
        wizSelectArtikel(arr[0].artikel_id);
    } else {
        _beState.selArt = null;
        _beState.selArts = [];
        _beState.artikelPreise = {};
        _beState.activeArtTab = null;
        beUpdateBreadcrumb();
        wizRenderPreisPanels();
        wizRenderArtTabs();
    }
}

function wizUpdateObjPreview(o) {
    const wrap = document.getElementById('wizObjPreview');
    if (!wrap) return;
    if (!o || !o.obj_id) {
        wrap.classList.remove('has-data');
        wrap.innerHTML = `
            <div class="be-preview-icon">${beIcon('apartment')}</div>
            <div class="be-preview-body"><div class="be-preview-empty">Wählen Sie zuerst einen Kunden, dann ein Objekt aus.</div></div>
        `;
        return;
    }
    wrap.classList.add('has-data');
    const adresse = [o.adresse, [o.plz, o.stadt].filter(Boolean).join(' ')].filter(Boolean).join(', ');
    wrap.innerHTML = `
        <div class="be-preview-icon">${beIcon('apartment')}</div>
        <div class="be-preview-body">
            <div class="be-preview-title">${escapeHtml(o.objektname)}</div>
            <div class="be-preview-meta">
                <span>${beIcon('tag', 'font-size:12px')}<span style="font-family:ui-monospace,monospace">${escapeHtml(o.obj_id)}</span></span>
                ${o.obj_nr_intern ? `<span>${beIcon('sell', 'font-size:12px')}Obj-Nr.: ${escapeHtml(o.obj_nr_intern)}</span>` : ''}
                ${adresse ? `<span>${beIcon('location_on', 'font-size:12px')}${escapeHtml(adresse)}</span>` : ''}
                ${o.ansprechpartner ? `<span>${beIcon('person', 'font-size:12px')}${escapeHtml(o.ansprechpartner)}</span>` : ''}
            </div>
        </div>
    `;
}

function wizRenderArtikelArea(articles) {
    const wrap = document.getElementById('wizArtikelArea');
    if (!wrap) return;

    if (articles.length === 0) {
        wrap.innerHTML = `
            <div class="be-info-box" style="background:var(--be-warn-soft);border-color: var(--md-ext-color-warning-container);border-left-color:var(--be-warn)">
                ${beIcon('warning', 'color:var(--be-warn);font-size:16px')}
                <div>Für dieses Objekt sind <strong>keine aktiven Artikel</strong> hinterlegt. Bitte legen Sie zuerst einen Artikel im Bereich "Objekte" an.</div>
            </div>
            <input type="hidden" id="wiz_artikel" value="">
        `;
        return;
    }

    if (articles.length <= 6) {
        // Show as radio cards
        wrap.innerHTML = `
            <div class="be-art-grid">
                ${articles.map(a => `
                    <div class="be-art-card" data-id="${a.artikel_id}" onclick="wizSelectArtikel('${a.artikel_id}')">
                        <div class="be-art-card-head">
                            <span class="be-art-id">${escapeHtml(a.artikel_id)}</span>
                            ${a.bereich ? bereichBadgeHtml(a.bereich) : ''}
                        </div>
                        <div class="be-art-name">${escapeHtml(a.artikel_name)}</div>
                        <div class="be-art-meta">
                            ${a.modell ? `<span>${beIcon('speed', 'font-size:11px')} ${escapeHtml(a.modell)}</span>` : ''}
                            ${a.einheit ? `<span>${beIcon('tag', 'font-size:11px')} ${escapeHtml(a.einheit)}</span>` : ''}
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px">
                            <span class="be-art-price-pill">${formatCurrency(a.preis_kunde)}</span>
                            <span style="font-size:0.7rem;color:var(--be-text-muted)">NU: ${formatCurrency(a.nu_preis)}</span>
                        </div>
                    </div>
                `).join('')}
            </div>
            <input type="hidden" id="wiz_artikel" value="">
        `;
    } else {
        // Many articles → dropdown + selected card
        wrap.innerHTML = `
            <div class="form-group">
                <label>Artikel / Leistung *</label>
                <md-outlined-select class="form-control" id="wiz_artikel" onchange="wizSelectArtikel(this.value)">
                    <md-select-option value="">— Artikel wählen —</md-select-option>
                    ${articles.map(a => `<md-select-option value="${a.artikel_id}">${a.artikel_id} – ${escapeHtml(a.artikel_name)}${a.bereich ? ' (' + escapeHtml(a.bereich) + ')' : ''}</md-select-option>`).join('')}
                </md-outlined-select>
            </div>
            <div class="be-artikel-preview" id="wizArtPreview">
                <div class="be-preview-icon">${beIcon('sell')}</div>
                <div class="be-preview-body"><div class="be-preview-empty">Wählen Sie einen Artikel aus.</div></div>
            </div>
        `;
    }

    // Cache the articles array for selection
    window._wizArticles = articles;
}

async function wizSelectArtikel(artikelId) {
    if (!artikelId) {
        _beState.selArts = [];
        _beState.selArt = null;
        _beState.artikelPreise = {};
        _beState.activeArtTab = null;
        wizRenderPreisPanels();
        wizRenderArtTabs();
        beUpdateBreadcrumb();
        return;
    }

    const art = (window._wizArticles || []).find(a => String(a.artikel_id) === String(artikelId));
    if (!art) return;

    // Multi-select: clicking toggles membership. selArts[0] is the primary
    // artikel (mirrored into be.artikel_id / legacy readers).
    const idx = _beState.selArts.findIndex(a => String(a.artikel_id) === String(artikelId));
    // A stored service outside the caller's Bereich cannot be deselected —
    // the backend re-merges it on save regardless, so refusing here keeps
    // the UI honest instead of pretending the removal worked.
    if (idx >= 0 && art._scopeLocked) {
        showToast('Diese Leistung liegt außerhalb Ihres Bereichs und kann nicht entfernt werden', 'error');
        return;
    }
    const added = idx < 0;
    if (added) _beState.selArts.push(art); else _beState.selArts.splice(idx, 1);
    _beState.selArt = _beState.selArts[0] || null;

    // A newly selected artikel gets its own price panel seeded from the
    // artikel master data (modell + preis_kunde).
    if (added && !_beState.artikelPreise[art.artikel_id]) {
        _beState.artikelPreise[art.artikel_id] = {
            modell: art.modell || 'Pro Einsatz',
            kundenpreis: (art.preis_kunde != null && art.preis_kunde !== '') ? parseFloat(art.preis_kunde) : null,
        };
    }

    // Hidden input keeps the PRIMARY artikel id (legacy save-path readers).
    // In dropdown mode wiz_artikel IS the visible picker — a single <md-outlined-select>
    // cannot show a multi-selection, so after each pick it resets to the
    // placeholder and the preview chips below carry the real selection.
    const hidden = document.getElementById('wiz_artikel');
    if (hidden) {
        if (hidden.tagName === 'SELECT' || hidden.tagName === 'MD-OUTLINED-SELECT') {
            hidden.value = '';
        } else {
            hidden.value = _beState.selArt ? _beState.selArt.artikel_id : '';
        }
    }

    // Card highlight = membership (not exclusive anymore)
    document.querySelectorAll('.be-art-card').forEach(c => {
        c.classList.toggle('selected', _beState.selArts.some(a => String(a.artikel_id) === c.dataset.id));
    });

    // Preview (dropdown mode) lists every selected artikel
    const prev = document.getElementById('wizArtPreview');
    if (prev) {
        if (_beState.selArts.length === 0) {
            prev.classList.remove('has-data');
            prev.innerHTML = `<div class="be-preview-icon">${beIcon('sell')}</div><div class="be-preview-body"><div class="be-preview-empty">Wählen Sie einen Artikel aus.</div></div>`;
        } else {
            prev.classList.add('has-data');
            prev.innerHTML = `
                <div class="be-preview-icon">${beIcon('sell')}</div>
                <div class="be-preview-body">
                    <div class="be-preview-title">${_beState.selArts.length} Leistung(en) gewählt</div>
                    <div class="be-preview-meta">${_beState.selArts.map(a =>
                        `<span class="be-art-sel-chip" onclick="wizSelectArtikel('${escapeHtml(String(a.artikel_id))}')" title="Klicken zum Entfernen" style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">${beIcon('tag', 'font-size:12px')}<span style="font-family:ui-monospace,monospace">${escapeHtml(a.artikel_id)}</span> ${escapeHtml(a.artikel_name)} — ${formatCurrency(a.preis_kunde)} ${beIcon('close', 'font-size:13px;color:var(--be-warn)')}</span>`
                    ).join('')}</div>
                </div>`;
        }
    }

    wizRenderPreisPanels();
    wizRenderArtTabs();
    beUpdateBreadcrumb();

    // Object-based prefills (Bemerkung, Ort) are for NEW orders only. In
    // edit mode an empty field is the stored state of the order — filling
    // it here would silently add an Ort/Bemerkung on the next save (and
    // push it down to every Einsatz in Dispo).
    if (added && !_beState.isEdit) {
        setTimeout(() => {
            const bmEl = document.getElementById('wiz_bemerkung');
            const objHinweise = (_beState.selObj?.hinweise || '').trim();
            if (bmEl && !bmEl.value.trim() && objHinweise) bmEl.value = objHinweise;
            // Bestellbeschreibung of the Objekt → Schicht-Vorlage Beschreibung
            // (only into an empty field; days created afterwards inherit it).
            const bsEl = document.getElementById('wiz_shift_beschreibung');
            const objBeschr = wizObjBestellbeschreibung();
            if (bsEl && !bsEl.value.trim() && objBeschr) bsEl.value = objBeschr;
            const ortEl = document.getElementById('wiz_shift_ort');
            if (ortEl && !ortEl.value.trim() && _beState.selObj) {
                const o = _beState.selObj;
                const parts = [o.adresse, [o.plz, o.stadt].filter(Boolean).join(' ')].filter(s => s && String(s).trim() !== '');
                if (parts.length) ortEl.value = parts.join(', ');
            }
        }, 100);
    }
    setTimeout(wizUpdateObjBeschreibungHint, 150);
}

// Objekt "Bestellbeschreibung" as one line (the Beschreibung input is single-line).
function wizObjBestellbeschreibung() {
    return String(_beState.selObj?.bestellbeschreibung || '').replace(/\s+/g, ' ').trim();
}

// Existing orders are never filled automatically — an empty Beschreibung is
// their stored state and the text would reach every day/Einsatz without its
// own description on save. Offer it as an explicit one-click takeover instead.
function wizUpdateObjBeschreibungHint() {
    const box = document.getElementById('wizObjBeschreibungHint');
    const el = document.getElementById('wiz_shift_beschreibung');
    if (!box || !el) return;
    const objBeschr = wizObjBestellbeschreibung();
    if (!objBeschr || el.value.trim() === objBeschr) { box.style.display = 'none'; box.innerHTML = ''; return; }
    box.innerHTML = `${beIcon('description', 'font-size:14px;vertical-align:-3px')} Bestellbeschreibung des Objekts: <span class="be-obj-beschr-text" title="${escapeHtml(objBeschr)}">${escapeHtml(objBeschr)}</span>`
        + ` <md-text-button onclick="wizTakeObjBeschreibung()">Übernehmen</md-text-button>`;
    box.style.display = '';
}

function wizTakeObjBeschreibung() {
    const el = document.getElementById('wiz_shift_beschreibung');
    const objBeschr = wizObjBestellbeschreibung();
    if (!el || !objBeschr) return;
    const prev = el.value;
    el.value = objBeschr;
    // Only days that follow the shared Beschreibung (none of their own, or the
    // previous shared text) take it over — a day's own description stays.
    let n = 0;
    document.querySelectorAll('#wizDaysBody tr').forEach(row => {
        const own = row.dataset.beschreibung || '';
        if (own !== '' && own !== prev) return;
        row.dataset.beschreibung = objBeschr;
        if (row.classList.contains('be-day-editing')) {
            const bEl = row.querySelector('.be-day-ort-beschr');
            if (bEl) bEl.textContent = objBeschr;
        } else {
            wizRedrawRowKeepIndex(row);
        }
        n++;
    });
    wizUpdateObjBeschreibungHint();
    showToast('Bestellbeschreibung übernommen' + (n ? ' (' + n + ' Tag' + (n === 1 ? '' : 'e') + ')' : '') + ' — mit „Speichern“ übernehmen');
}

// Step 4 — one pricing panel per selected artikel.
const _WIZ_MODELLE = ['Standard', 'Pro Einsatz', 'Pro Stunde', 'Monatspauschale'];
function wizRenderPreisPanels() {
    const wrap = document.getElementById('wizPreisPanels');
    if (!wrap) return;
    const arts = _beState.selArts || [];
    if (arts.length === 0) {
        wrap.innerHTML = `<div class="be-info-box">${beIcon('info', 'font-size:16px')} Wählen Sie zuerst in Schritt 2 eine oder mehrere Leistungen aus.</div>`;
        return;
    }
    wrap.innerHTML = arts.map(a => {
        const p = _beState.artikelPreise[a.artikel_id] || {};
        const modell = p.modell || a.modell || 'Pro Einsatz';
        const preis = (p.kundenpreis != null && p.kundenpreis !== '') ? parseFloat(p.kundenpreis).toFixed(2) : (a.preis_kunde != null && a.preis_kunde !== '' ? parseFloat(a.preis_kunde).toFixed(2) : '');
        const ro = a._scopeLocked ? ' disabled' : '';
        const roTag = a._scopeLocked ? ` <span class="be-anf-hint" title="Außerhalb Ihres Bereichs — schreibgeschützt">${beIcon('lock', 'font-size:12px;vertical-align:-2px')} schreibgeschützt</span>` : '';
        return `
        <div class="be-form-section be-art-preis-panel" data-art="${escapeHtml(String(a.artikel_id))}">
            <div class="be-form-section-title">${beIcon('sell', 'font-size:14px')} ${escapeHtml(a.artikel_name || a.artikel_id)} <span class="be-anf-hint">${escapeHtml(a.artikel_id)}${a.bereich ? ' · ' + escapeHtml(a.bereich) : ''}</span>${roTag}</div>
            <div class="form-grid" style="grid-template-columns:1fr 200px;gap:14px;align-items:start">
                <div class="form-group" style="margin-bottom:0">
                    <label>Abrechnungsmodell</label>
                    <div class="be-modell-chips">
                        ${_WIZ_MODELLE.map(m => `<md-filter-chip class="be-modell-chip-btn ${modell === m ? 'active' : ''}" label="${m}" ${modell === m ? 'selected' : ''} data-val="${m}" data-art="${escapeHtml(String(a.artikel_id))}"${ro}></md-filter-chip>`).join('')}
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label>Kundenpreis</label>
                    <div class="be-input-with-icon">
                        <span class="be-input-icon">€</span>
                        <md-outlined-text-field type="number" step="0.01" class="form-control be-art-preis" data-art="${escapeHtml(String(a.artikel_id))}" value="${preis}"${ro}></md-outlined-text-field>
                    </div>
                </div>
            </div>
            <div class="form-group" style="margin-top:10px;margin-bottom:0">
                <label>Bemerkung (nur diese Leistung)</label>
                <md-outlined-text-field type="text" class="form-control be-art-bemerkung" data-art="${escapeHtml(String(a.artikel_id))}" value="${escapeHtml(p.bemerkung || '')}" placeholder="z. B. besondere Hinweise zu dieser Leistung"${ro}></md-outlined-text-field>
            </div>
        </div>`;
    }).join('');
}

// Read the step-4 panels (or state fallback) into the save payload.
function wizCollectPreise() {
    const out = {};
    (_beState.selArts || []).forEach(a => {
        const id = String(a.artikel_id);
        const modellBtn = document.querySelector(`.be-modell-chip-btn.active[data-art="${CSS.escape(id)}"]`);
        const preisEl = document.querySelector(`.be-art-preis[data-art="${CSS.escape(id)}"]`);
        const bemEl = document.querySelector(`.be-art-bemerkung[data-art="${CSS.escape(id)}"]`);
        const st = _beState.artikelPreise[id] || {};
        out[id] = {
            modell: modellBtn ? modellBtn.dataset.val : (st.modell || a.modell || null),
            kundenpreis: preisEl ? (preisEl.value !== '' ? parseFloat(preisEl.value) : null) : (st.kundenpreis ?? null),
            bemerkung: bemEl ? (bemEl.value.trim() || null) : (st.bemerkung ?? null),
        };
    });
    return out;
}

// Step 5 — tab bar switching which artikel's day rows are shown.
function wizRenderArtTabs() {
    const wrap = document.getElementById('wizArtTabs');
    if (!wrap) return;
    const arts = _beState.selArts || [];
    if (arts.length === 0) { wrap.innerHTML = ''; return; }
    if (!_beState.activeArtTab || !arts.some(a => String(a.artikel_id) === String(_beState.activeArtTab))) {
        _beState.activeArtTab = arts[0].artikel_id;
    }
    wrap.innerHTML = arts.map(a => {
        const id = String(a.artikel_id);
        const cnt = [...document.querySelectorAll('#wizDaysBody tr')]
            .filter(r => (r.dataset.artikel || String(arts[0].artikel_id)) === id).length;
        return `<md-secondary-tab inline-icon class="be-art-tab ${id === String(_beState.activeArtTab) ? 'active' : ''}"${id === String(_beState.activeArtTab) ? ' active' : ''} onclick="wizSwitchArtTab('${escapeHtml(id)}')">
            <md-icon slot="icon">sell</md-icon>${escapeHtml(a.artikel_name || id)} <span class="be-art-tab-count">${cnt}</span>
        </md-secondary-tab>`;
    }).join('');
    wizApplyArtTabFilter();
}

function wizSwitchArtTab(id) {
    _beState.activeArtTab = id;
    wizRenderArtTabs();
    beUpdateBreadcrumb();
}

// Hide rows of other artikel-tabs. Rows without an artikel tag belong to the
// primary artikel (legacy data and rows added before a 2nd artikel was picked).
function wizApplyArtTabFilter() {
    const arts = _beState.selArts || [];
    const primary = arts.length ? String(arts[0].artikel_id) : '';
    const act = _beState.activeArtTab || primary;
    document.querySelectorAll('#wizDaysBody tr').forEach(tr => {
        tr.style.display = (arts.length <= 1 || (tr.dataset.artikel || primary) === String(act)) ? '' : 'none';
    });
    // An active day-search refines on top of the tab filter.
    const sq = document.getElementById('wizDaysSearch');
    if (sq && sq.value.trim()) wizFilterDays(sq.value);
}

// Modell chip click handler (delegated). Per-artikel panels scope their chips
// via data-art — toggling one artikel's model never touches the others.
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.be-modell-chip-btn');
    if (!btn) return;
    const art = btn.dataset.art;
    const scope = art
        ? `.be-modell-chip-btn[data-art="${CSS.escape(art)}"]`
        : '.be-modell-chip-btn:not([data-art])';
    document.querySelectorAll(scope).forEach(b => { b.classList.toggle('active', b === btn); b.selected = b === btn; });
    if (art) {
        (_beState.artikelPreise[art] = _beState.artikelPreise[art] || {}).modell = btn.dataset.val;
    } else {
        const hid = document.getElementById('wiz_modell');
        if (hid) hid.value = btn.dataset.val;
    }
});

// Per-artikel Kundenpreis inputs keep state in sync live — a later panel
// re-render (artikel (de)selected) restores them from _beState.artikelPreise.
document.addEventListener('input', (e) => {
    const inp = e.target.closest('.be-art-preis');
    if (inp) {
        const art = inp.dataset.art;
        if (!art) return;
        (_beState.artikelPreise[art] = _beState.artikelPreise[art] || {}).kundenpreis =
            inp.value !== '' ? parseFloat(inp.value) : null;
        return;
    }
    const bem = e.target.closest('.be-art-bemerkung');
    if (!bem) return;
    const bArt = bem.dataset.art;
    if (!bArt) return;
    (_beState.artikelPreise[bArt] = _beState.artikelPreise[bArt] || {}).bemerkung = bem.value;
});

// ============================================================
// WIZARD — Step 5 (Tage) helpers
// ============================================================
function wizCalcShiftDuration() {
    const von = document.getElementById('wiz_shift_von')?.value;
    const bis = document.getElementById('wiz_shift_bis')?.value;
    const el = document.getElementById('wizShiftDuration');
    const sub = document.getElementById('wizShiftDurationSub');
    const pause = parseInt(document.getElementById('wiz_shift_pause')?.value) || 0;
    const gpEl = document.getElementById('wizGesetzlPause');
    if (gpEl) gpEl.value = pause;
    if (!el) return;
    const fmt = mins => `${Math.floor(mins / 60)}h ${String(mins % 60).padStart(2, '0')}m`;
    const ok = !!(von && bis);
    const net = ok ? beShiftNetMinutes(von, bis, pause) : 0;
    el.textContent = ok ? fmt(net) : '–';
    if (sub) sub.textContent = ok ? `${fmt(beShiftTotalMinutes(von, bis))} − ${pause} Min Pause${beIsOvernight(von, bis) ? ' · endet am Folgetag' : ''}` : 'Von / Bis fehlt';
    el.closest('.be-vl-duration')?.classList.toggle('is-bad', !ok || net <= 0);
    wizVorlageCheck();
}

function wizGenMode(mode) {
    const card = document.getElementById('wizGen');
    if (!card) return;
    card.dataset.mode = mode;
    card.querySelectorAll('.be-vl-seg md-filter-chip').forEach(b => { const on = b.dataset.mode === mode; b.classList.toggle('active', on); b.selected = on; });
    const focus = { zeitraum: 'wiz_range_von', einzel: 'wiz_single_date' }[mode];
    if (focus) document.getElementById(focus)?.focus();
}

function wizVorlageCheck() {
    const box = document.getElementById('wizVorlageCheck');
    if (!box) return [];
    const el = id => document.getElementById(id);
    const val = id => String(el(id)?.value ?? '').trim();
    const timeOk = t => /^([01]\d|2[0-3]):[0-5]\d$/.test(t);
    const von = val('wiz_shift_von');
    const bis = val('wiz_shift_bis');
    const pause = parseInt(val('wiz_shift_pause')) || 0;
    const menge = parseInt(val('wiz_shift_menge')) || 0;
    const m = parseInt(val('wiz_anf_m')) || 0;
    const w = parseInt(val('wiz_anf_w')) || 0;
    const posRows = [...document.querySelectorAll('#wizPositionsBody .be-anf-pos-row')];
    const posSum = posRows.reduce((s, r) => s + (parseInt(r.querySelector('.anf-anzahl')?.value) || 0), 0);
    const issues = [];
    const bad = new Set();
    const add = (lvl, text, ids = []) => { issues.push([lvl, text]); ids.forEach(i => bad.add(i)); };
    if (!timeOk(von) || !timeOk(bis)) {
        add('err', 'Von und Bis im Format HH:MM angeben.', [!timeOk(von) && 'wiz_shift_von', !timeOk(bis) && 'wiz_shift_bis'].filter(Boolean));
    } else if (von === bis) {
        add('err', 'Von und Bis sind gleich — die Schicht hätte 0 Stunden.', ['wiz_shift_von', 'wiz_shift_bis']);
    } else if (pause >= beShiftTotalMinutes(von, bis)) {
        add('err', 'Die Pause ist so lang wie die Schicht — die Schicht hätte 0 Stunden.', ['wiz_shift_pause']);
    }
    if (menge < 1) add('err', 'Menge muss mindestens 1 Person sein.', ['wiz_shift_menge']);
    else if (m + w > menge) add('err', `M ${m} + W ${w} = ${m + w} ist mehr als die Menge ${menge}.`, ['wiz_anf_m', 'wiz_anf_w']);
    if (posRows.length && menge >= 1) {
        if (posSum > menge) add('warn', `Die Positionen ergeben ${posSum} Personen — die Menge ist ${menge}.`);
        else if (posSum < menge) add('info', `Die Positionen decken ${posSum} von ${menge} Personen ab.`);
    }
    if (!val('wiz_shift_ort')) add('warn', 'Ort / Treffpunkt fehlt — Pflichtfeld beim Speichern.', ['wiz_shift_ort']);
    if (!val('wiz_shift_beschreibung')) add('warn', 'Beschreibung fehlt — Pflichtfeld beim Speichern.', ['wiz_shift_beschreibung']);
    ['wiz_shift_von', 'wiz_shift_bis', 'wiz_shift_pause', 'wiz_shift_menge', 'wiz_anf_m', 'wiz_anf_w', 'wiz_shift_ort', 'wiz_shift_beschreibung']
        .forEach(id => el(id)?.classList.toggle('be-vl-invalid', bad.has(id)));
    const sum = el('wizPosSum');
    if (sum) {
        sum.textContent = posRows.length ? `${posSum} / ${menge} Personen` : '';
        sum.classList.toggle('is-off', posRows.length > 0 && posSum !== menge);
    }
    const icons = { err: 'error', warn: 'warning', info: 'info' };
    box.innerHTML = issues.map(([l, t]) => `<div class="is-${l}">${beIcon(icons[l], 'font-size:16px')}<span>${escapeHtml(t)}</span></div>`).join('');
    box.style.display = issues.length ? '' : 'none';
    return issues;
}

function wizDateInOrder(iso) {
    const m = parseInt(document.getElementById('wiz_monat')?.value);
    const y = parseInt(document.getElementById('wiz_jahr')?.value);
    if (!m || !y || !iso) return true;
    if (parseInt(iso.substring(5, 7)) === m && parseInt(iso.substring(0, 4)) === y) return true;
    return !!(_beState.isEdit && window._wizStoredDates && window._wizStoredDates.has(iso));
}

function wizOrderMonthLabel() {
    const m = parseInt(document.getElementById('wiz_monat')?.value);
    const y = parseInt(document.getElementById('wiz_jahr')?.value);
    return (m && y) ? `${MONTHS_DE[m]} ${y}` : 'Bestellmonat';
}

function wizSyncDateBounds() {
    const m = parseInt(document.getElementById('wiz_monat')?.value);
    const y = parseInt(document.getElementById('wiz_jahr')?.value);
    if (!m || !y) return;
    const min = _beCalIso(y, m - 1, 1);
    const max = _beCalIso(y, m - 1, new Date(y, m, 0).getDate());
    ['wiz_single_date', 'wiz_range_von', 'wiz_range_bis'].forEach(id => {
        const el = document.getElementById(id);
        if (el && el.type === 'date') { el.min = min; el.max = max; }
    });
}

function wizVorlageGuard() {
    if (!document.getElementById('wizVorlageCheck')) return true;
    const err = wizVorlageCheck().find(i => i[0] === 'err');
    if (!err) return true;
    showToast('Schicht-Vorlage prüfen: ' + err[1], 'error');
    document.getElementById('wizVorlage')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return false;
}

if (!window._wizVorlageBound) {
    window._wizVorlageBound = true;
    ['input', 'change'].forEach(t => document.addEventListener(t, (e) => {
        if (e.target && e.target.closest && e.target.closest('#wizVorlage, #wizAnf')) wizVorlageCheck();
    }));
}

function wizToggleDay(btn) {
    // md-filter-chip has already toggled `selected` itself
    btn.classList.toggle('active', !!btn.selected);
}
function wizSetDay(b, on) { b.classList.toggle('active', on); b.selected = on; }

function wizPreset(kind) {
    const btns = document.querySelectorAll('.be-weekday-pill');
    btns.forEach(b => wizSetDay(b, false));
    if (kind === 'werktage') {
        btns.forEach(b => { if (['1','2','3','4','5'].includes(b.dataset.day)) wizSetDay(b, true); });
    } else if (kind === 'alle') {
        btns.forEach(b => wizSetDay(b, true));
    } else if (kind === 'wochenende') {
        btns.forEach(b => { if (['6','0'].includes(b.dataset.day)) wizSetDay(b, true); });
    }
    // 'clear' = leave all off
}

function wizGenerateDays() {
    if (!wizVorlageGuard()) return;
    const monat = parseInt(document.getElementById('wiz_monat').value);
    const jahr = parseInt(document.getElementById('wiz_jahr').value);
    const von = document.getElementById('wiz_shift_von').value;
    const bis = document.getElementById('wiz_shift_bis').value;
    const menge = document.getElementById('wiz_shift_menge').value || '1';
    const pause = parseInt(document.getElementById('wiz_shift_pause').value) || 0;
    const beschreibung = document.getElementById('wiz_shift_beschreibung').value || '';
    const gesetzlPause = parseInt(document.getElementById('wizGesetzlPause')?.value) || 0;
    const anf = wizCollectAnforderungen();

    const selectedDays = [...document.querySelectorAll('.be-weekday-pill.active')].map(b => parseInt(b.dataset.day));
    if (selectedDays.length === 0) {
        showToast('Bitte mindestens einen Wochentag auswählen', 'error');
        return;
    }

    const stdMins = (von && bis) ? beShiftNetMinutes(von, bis, pause) : 0;
    if (von && bis && stdMins <= 0) showToast('Achtung: Pause ≥ Schichtdauer — Tage ergeben 0 Std', 'error');
    const sollStd = (stdMins / 60).toFixed(2);

    const daysInMonth = new Date(jahr, monat, 0).getDate();
    const tbody = document.getElementById('wizDaysBody');

    // APPEND mode: keep any Einzeltage / previous generations the user already
    // added. The dedupe key is `datum|von|bis|artikel` so the same slot under
    // a DIFFERENT selected Leistung is legal (Bewachung + Bau on one day),
    // while identical rows of the SAME artikel are still skipped.
    const curArt = _beState.activeArtTab || (_beState.selArt?.artikel_id) || '';
    const existingKeys = new Set();
    tbody.querySelectorAll('tr').forEach(r => {
        if (r.dataset.datum) {
            const rArt = r.dataset.artikel || ((_beState.selArts || [])[0]?.artikel_id) || '';
            existingKeys.add(`${r.dataset.datum}|${r.dataset.von || ''}|${r.dataset.bis || ''}|${rArt}`);
        }
    });

    let added = 0;
    let skipped = 0;
    for (let d = 1; d <= daysInMonth; d++) {
        const date = new Date(jahr, monat - 1, d);
        const dayOfWeek = date.getDay();
        if (!selectedDays.includes(dayOfWeek)) continue;
        const dateStr = `${jahr}-${String(monat).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const key = `${dateStr}|${von || ''}|${bis || ''}|${curArt}`;
        if (existingKeys.has(key)) { skipped++; continue; }
        _wizDayCount++;
        wizAppendDayRow(tbody, _wizDayCount, dateStr, dayOfWeek, von, bis, sollStd, gesetzlPause, menge, beschreibung, pause, anf, document.getElementById('wiz_shift_ort')?.value || '');
        added++;
    }

    // Sort the table rows chronologically and renumber the index column so the
    // result looks coherent regardless of insertion order.
    wizSortAndRenumberDays();

    if (added === 0 && skipped === 0) {
        showToast('Keine Tage für diese Wochentage in diesem Monat', 'error');
    } else if (added === 0) {
        showToast(`Alle ${skipped} passenden Tage waren bereits vorhanden`, 'error');
    } else if (skipped > 0) {
        showToast(`${added} Tage hinzugefügt (${skipped} bereits vorhanden)`);
    } else {
        showToast(`${added} Tage generiert`);
    }
    wizUpdateDaysFooter();
}

// Generate every matching day in an explicit date range (Von … Bis). If any
// weekday pills are active, only those weekdays are added; otherwise EVERY day
// in the range is added. Shares the shift template + dedupe behaviour with
// wizGenerateDays (key = datum|von|bis, so multi-shift days remain possible).
function wizGenerateRange() {
    if (!wizVorlageGuard()) return;
    const vonDate = wizParseDateInput(document.getElementById('wiz_range_von')?.value);
    const bisDate = wizParseDateInput(document.getElementById('wiz_range_bis')?.value);
    if (!vonDate || !bisDate) {
        showToast('Bitte Von- und Bis-Datum im Format TT.MM.JJJJ eingeben', 'error');
        return;
    }
    const start = new Date(vonDate + 'T00:00:00');
    const end = new Date(bisDate + 'T00:00:00');
    if (end < start) {
        showToast('Das Bis-Datum liegt vor dem Von-Datum', 'error');
        return;
    }
    const spanDays = Math.round((end - start) / 86400000) + 1;
    if (spanDays > 366) {
        showToast('Zeitraum zu groß (max. 366 Tage)', 'error');
        return;
    }

    const von = document.getElementById('wiz_shift_von').value;
    const bis = document.getElementById('wiz_shift_bis').value;
    const menge = document.getElementById('wiz_shift_menge').value || '1';
    const pause = parseInt(document.getElementById('wiz_shift_pause').value) || 0;
    const beschreibung = document.getElementById('wiz_shift_beschreibung').value || '';
    const gesetzlPause = parseInt(document.getElementById('wizGesetzlPause')?.value) || 0;
    const anf = wizCollectAnforderungen();

    const selectedDays = [...document.querySelectorAll('.be-weekday-pill.active')].map(b => parseInt(b.dataset.day));
    const filterByWeekday = selectedDays.length > 0;

    const stdMins = (von && bis) ? beShiftNetMinutes(von, bis, pause) : 0;
    if (von && bis && stdMins <= 0) showToast('Achtung: Pause ≥ Schichtdauer — Tage ergeben 0 Std', 'error');
    const sollStd = (stdMins / 60).toFixed(2);

    const tbody = document.getElementById('wizDaysBody');
    const curArt = _beState.activeArtTab || (_beState.selArt?.artikel_id) || '';
    const existingKeys = new Set();
    tbody.querySelectorAll('tr').forEach(r => {
        if (r.dataset.datum) {
            const rArt = r.dataset.artikel || ((_beState.selArts || [])[0]?.artikel_id) || '';
            existingKeys.add(`${r.dataset.datum}|${r.dataset.von || ''}|${r.dataset.bis || ''}|${rArt}`);
        }
    });

    let added = 0;
    let skipped = 0;
    let outside = 0;
    const cursor = new Date(start);
    while (cursor <= end) {
        const dayOfWeek = cursor.getDay();
        if (!filterByWeekday || selectedDays.includes(dayOfWeek)) {
            const dateStr = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}-${String(cursor.getDate()).padStart(2, '0')}`;
            const key = `${dateStr}|${von || ''}|${bis || ''}|${curArt}`;
            if (!wizDateInOrder(dateStr)) {
                outside++;
            } else if (existingKeys.has(key)) {
                skipped++;
            } else {
                _wizDayCount++;
                wizAppendDayRow(tbody, _wizDayCount, dateStr, dayOfWeek, von, bis, sollStd, gesetzlPause, menge, beschreibung, pause, anf, document.getElementById('wiz_shift_ort')?.value || '');
                existingKeys.add(key);
                added++;
            }
        }
        cursor.setDate(cursor.getDate() + 1);
    }

    wizSortAndRenumberDays();

    if (outside) showToast(`${outside} Tag(e) außerhalb des Bestellmonats ${wizOrderMonthLabel()} übersprungen`, 'warning');
    if (added === 0 && skipped === 0) {
        if (!outside) showToast('Keine passenden Tage im Zeitraum', 'error');
    } else if (added === 0) {
        showToast(`Alle ${skipped} passenden Tage waren bereits vorhanden`, 'error');
    } else if (skipped > 0) {
        showToast(`${added} Tage hinzugefügt (${skipped} bereits vorhanden)`);
    } else {
        showToast(`${added} Tage generiert (${formatDate(vonDate)} – ${formatDate(bisDate)})`);
    }
    wizUpdateDaysFooter();
}

// Sort all day rows by `datum` ascending and re-set the running index in the
// first column. Keeps the table coherent after mixed Einzeltag/generate input.
function wizSortAndRenumberDays() {
    const tbody = document.getElementById('wizDaysBody');
    if (!tbody) return;
    const rows = [...tbody.querySelectorAll('tr')];
    // Sort by date first, then by start time so day-shift rows come before
    // night-shift rows on the same calendar day.
    rows.sort((a, b) => {
        const dateCmp = (a.dataset.datum || '').localeCompare(b.dataset.datum || '');
        if (dateCmp !== 0) return dateCmp;
        return (a.dataset.von || '').localeCompare(b.dataset.von || '');
    });
    rows.forEach((r, i) => {
        const firstTd = r.querySelector('td');
        if (firstTd) firstTd.innerHTML = `<strong>${i + 1}</strong>`;
        tbody.appendChild(r); // re-attach in sorted order
    });
    _wizDayCount = rows.length;
}

// Rows whose Einsatz is booked in Dispo (planned with crew / confirmed) or
// whose service is outside the caller's Bereich are frozen: no header
// template / pause / Beschreibung sync may touch them (the server pins
// them to the Einsatz and refuses changed times).
function wizRowLocked(row) {
    const ds = row?.dataset?.dispoStatus || '';
    return ds === 'planned' || ds === 'confirmed' || row?.dataset?.scopeLocked === '1';
}

function wizAppendDayRow(tbody, idx, dateStr, dayOfWeek, von, bis, sollStd, gesetzlPause, menge, beschreibung, pauseMinuten, anforderungen, ort = '', dispoStatus = null, artikelId = null) {
    const anf = wizParseAnf(anforderungen);
    const tr = document.createElement('tr');
    tr.dataset.datum = dateStr;
    // Which service this day belongs to. Default = the active Tage tab
    // (multi-service wizard); falls back to the primary artikel.
    tr.dataset.artikel = artikelId || _beState.activeArtTab || (_beState.selArt?.artikel_id) || '';
    tr.dataset.von = von;
    tr.dataset.bis = bis;
    tr.dataset.std = sollStd;
    tr.dataset.pauseMinuten = parseInt(pauseMinuten) || 0;
    tr.dataset.gesetzlPause = dispoStatus ? (parseInt(gesetzlPause) || 0) : tr.dataset.pauseMinuten;
    tr.dataset.menge = menge;
    tr.dataset.beschreibung = beschreibung;
    tr.dataset.ort = ort || '';
    tr.dataset.anforderungen = anf ? JSON.stringify(anf) : '';
    tr.dataset.wochentag = DAYS_DE[dayOfWeek];
    tr.dataset.dispoStatus = dispoStatus || '';
    // Scope lock: the row's Leistung is outside the caller's Bereich
    // whitelist (restored artikel flagged _scopeLocked) → read-only row.
    const _rowArtObj = (window._wizArticles || []).find(a => String(a.artikel_id) === String(tr.dataset.artikel));
    tr.dataset.scopeLocked = (_rowArtObj && _rowArtObj._scopeLocked) ? '1' : '';
    wizRedrawDayRow(tr, idx);
    tbody.appendChild(tr);
}

// Render (or re-render) a day row's display cells from its dataset.
function wizRedrawDayRow(tr, idx) {
    const dateStr = tr.dataset.datum;
    const dayOfWeek = new Date(dateStr + 'T00:00:00').getDay();
    const von = tr.dataset.von || '';
    const bis = tr.dataset.bis || '';
    const sollStd = tr.dataset.std;
    const pmin = parseInt(tr.dataset.pauseMinuten) || 0;
    const gp = parseInt(tr.dataset.gesetzlPause) || 0;
    const beschreibung = tr.dataset.beschreibung || '';
    const ort = tr.dataset.ort || '';
    const anf = wizParseAnf(tr.dataset.anforderungen);
    const isWeekend = (dayOfWeek === 0 || dayOfWeek === 6);
    const isOvernight = beIsOvernight(von, bis);
    const anfPersons = (anf && Array.isArray(anf.positionen)) ? anf.positionen.reduce((s, p) => s + (parseInt(p.anzahl) || 0), 0) : 0;
    const hasAnf = !!(anf && (anf.qualifikation || anf.kleidung || anf.skill || anf.geschlecht || anf.geschlecht_mix || (anf.positionen && anf.positionen.length)));
    const anfChip = hasAnf ? ` <span class="be-anf-chip" title="Anforderungen für Dispo hinterlegt">${beIcon('assignment', 'font-size:11px')}${anfPersons > 0 ? ' ' + anfPersons : ''}</span>` : '';
    // Ort line = what reaches Dispo: Tag-Ort first, else the header Ort
    // (shown greyed as inherited), else nothing.
    const headerOrtNow = String(document.getElementById('wiz_shift_ort')?.value ?? '').trim();
    const ortLine = ort
        ? `<div style="font-size:.72rem;color:var(--gray-500);margin-top:2px">${beIcon('location_on', 'color:#3b82f6;font-size:11px;vertical-align:-2px;margin-right:3px')}${escapeHtml(ort)} <span style="color: var(--md-ext-color-warning);font-size:.68rem;font-weight:600" title="Eigener Einsatzort für diesen Tag — hat Vorrang vor Ort / Treffpunkt">· Tag-Ort</span></div>`
        : (headerOrtNow
            ? `<div class="be-day-ort-inherit" style="font-size:.72rem;color:var(--gray-400);margin-top:2px" title="Kein Tag-Ort — der Einsatz übernimmt Ort / Treffpunkt der Schicht-Vorlage">${beIcon('location_on', 'font-size:11px;vertical-align:-2px;margin-right:3px')}${escapeHtml(headerOrtNow)} <span style="font-size:.68rem">· Standard</span></div>`
            : '');
    // Dispo status badge: warn the user before removing a day that has a
    // planned or confirmed Einsatz in Dispo.
    const dispoStatus = tr.dataset.dispoStatus || '';
    const dispoBadge = dispoStatus === 'confirmed'
        ? `<span class="be-dispo-status-badge be-dispo-confirmed" title="Bestätigter Einsatz in Dispo — Entfernung blockiert">${beIcon('block', 'font-size:11px;vertical-align:-2px')} Bestätigt</span>`
        : (dispoStatus === 'planned'
            ? `<span class="be-dispo-status-badge be-dispo-planned" title="Eingeplanter Einsatz in Dispo — gesperrt (erst Plan in der Dispo aufheben)">${beIcon('warning', 'font-size:11px;vertical-align:-2px')} Geplant</span>`
            : '');
    // Multi-service orders: mark which Leistung this day belongs to, so a row
    // keeps its identity visible even outside the filtered tab view.
    const selArts = _beState.selArts || [];
    const rowArtId = tr.dataset.artikel || (selArts[0]?.artikel_id ?? '');
    const rowArtObj = selArts.find(a => String(a.artikel_id) === String(rowArtId));
    const artChip = selArts.length > 1 && rowArtId
        ? `<div style="margin-top:2px"><span class="be-art-chip-mini" style="display:inline-flex;align-items:center;gap:3px;font-size:.68rem;font-weight:600;color: var(--be-accent,var(--md-sys-color-primary));background: color-mix(in srgb, var(--md-sys-color-primary) 8%, transparent);padding:1px 7px;border-radius:10px" title="Leistung dieses Tages">${beIcon('sell', 'font-size:10px')}${escapeHtml(rowArtObj?.artikel_name || rowArtId)}</span></div>`
        : '';
    // Scope lock: the row's Leistung sits outside the caller's Bereich —
    // the backend re-merges it on save, so editing/removing would be a no-op
    // that pretends to work. Render it read-only instead.
    const scopeLocked = tr.dataset.scopeLocked === '1';
    const scopeBadge = scopeLocked
        ? `<span class="be-dispo-status-badge" style="background: var(--gray-100, var(--md-sys-color-primary-container));color: var(--gray-500, var(--md-sys-color-primary))" title="Leistung außerhalb Ihres Bereichs — schreibgeschützt">${beIcon('lock', 'font-size:11px;vertical-align:-2px')} Bereich</span>`
        : '';
    tr.dataset.wochentag = DAYS_DE[dayOfWeek];
    tr.innerHTML = `
        <td><strong>${idx}</strong></td>
        <td>${formatDate(dateStr)}${dispoBadge ? `<div style="margin-top:2px">${dispoBadge}</div>` : ''}${scopeBadge ? `<div style="margin-top:2px">${scopeBadge}</div>` : ''}${artChip}</td>
        <td><span class="be-wochentag-chip ${isWeekend ? 'weekend' : ''}">${DAYS_DE[dayOfWeek]}</span></td>
        <td>${von || '–'}</td>
        <td>${bis || '–'}${isOvernight ? '<span class="be-overnight-badge" title="Endet am Folgetag">+1</span>' : ''}</td>
        <td class="text-right">${(sollStd !== '' && sollStd != null) ? sollStd : '–'}</td>
        <td class="text-right">${pmin > 0 ? pmin + ' Min' : '–'}</td>
        <td class="text-right">${gp > 0 ? gp + ' Min' : '–'}</td>
        <td class="text-right">${wizDayMengeCellHtml(tr)}</td>
        <td class="be-day-desc-cell"><div class="be-day-desc${beschreibung.length > 60 ? ' is-long' : ''}"${beschreibung ? ` title="${escapeHtml(beschreibung)}"` : ''}${beschreibung.length > 60 ? ' onclick="this.classList.toggle(\'is-open\')"' : ''}>${escapeHtml(beschreibung) || '–'}</div>${ortLine}${anfChip}</td>
        <td><div style="display:flex;gap:6px;justify-content:flex-end">
            ${scopeLocked
                ? `<span style="color: var(--md-sys-color-primary)" title="Außerhalb Ihres Bereichs — schreibgeschützt">${beIcon('lock', 'font-size:14px')}</span>`
                : `<md-icon-button onclick="wizEditDay(this)" title="Tag bearbeiten"><md-icon slot="icon">edit</md-icon></md-icon-button>
            <md-icon-button onclick="wizDuplicateDay(this)" title="2. Schicht am selben Tag hinzufügen"><md-icon slot="icon">content_copy</md-icon></md-icon-button>
            <md-icon-button onclick="wizRemoveDay(this)" title="Entfernen"><md-icon slot="icon">close</md-icon></md-icon-button>`}
        </div></td>
    `;
}

// Resolve the row's effective Menge + M/W mix. Per-day Geschlecht-Mix: an
// override in dataset.geschlechtMix wins over the mix from the row's
// Anforderungen, so Bestellung + Dispo always know WHICH gender changes.
function wizDayMengeInfo(tr) {
    const anf = wizParseAnf(tr.dataset.anforderungen);
    const mix = wizParseAnf(tr.dataset.geschlechtMix) || (anf && anf.geschlecht_mix) || null;
    const menge = parseInt(tr.dataset.menge) || 1;
    const mixM = mix ? (parseInt(mix.maennlich) || 0) : 0;
    const mixW = mix ? (parseInt(mix.weiblich) || 0) : 0;
    return { menge, mixM, mixW, mixMismatch: (mixM + mixW) > menge };
}

// Menge cell in DISPLAY mode: read-only like every other per-day field.
// All per-day values are changed through the pencil → edit → ✓ flow only.
function wizDayMengeCellHtml(tr) {
    const { menge, mixM, mixW, mixMismatch } = wizDayMengeInfo(tr);
    const mixLine = (mixM > 0 || mixW > 0)
        ? `<div style="font-size:.72rem;margin-top:2px;font-weight:700" title="Geschlecht-Mix für diesen Tag"><span style="color: var(--md-sys-color-primary)">M ${mixM}</span> <span style="color: var(--md-sys-color-error);margin-left:6px">W ${mixW}</span></div>`
        : '';
    return `<strong${mixMismatch ? ' style="color: var(--md-sys-color-error)"' : ''}>${menge}</strong>${mixLine}${mixMismatch ? `<div style="font-size:.68rem;color: var(--md-sys-color-error);margin-top:2px">⚠ Mix > Menge</div>` : ''}`;
}

// Menge cell in EDIT mode: Menge input + M/W inputs. No onchange handlers —
// the values are read once in wizSaveDayEdit() (a blur-triggered re-render
// here would destroy the ✓ button under the user's click).
function wizDayMengeEditHtml(tr) {
    const { menge, mixM, mixW } = wizDayMengeInfo(tr);
    return `<md-outlined-text-field type="number" class="form-control be-day-menge" value="${menge}" min="1" style="width:64px;text-align:right;padding:2px 6px"></md-outlined-text-field>
        <div class="be-day-mix" style="display:flex;gap:4px;justify-content:flex-end;margin-top:3px" title="Geschlecht-Mix für diesen Tag (Summe ≤ Menge; 0/0 = egal)">
            <span style="display:inline-flex;align-items:center;gap:2px;font-size:.72rem;color: var(--md-sys-color-primary);font-weight:700">M<md-outlined-text-field type="number" class="form-control be-day-mix-m" value="${mixM}" min="0" style="width:44px;text-align:right;padding:1px 4px;font-size:.75rem"></md-outlined-text-field></span>
            <span style="display:inline-flex;align-items:center;gap:2px;font-size:.72rem;color: var(--md-sys-color-error);font-weight:700">W<md-outlined-text-field type="number" class="form-control be-day-mix-w" value="${mixW}" min="0" style="width:44px;text-align:right;padding:1px 4px;font-size:.75rem"></md-outlined-text-field></span>
        </div>`;
}

// Switch a day row into inline edit mode: Datum, Von, Bis, MA-Pause,
// K-Pause, Menge (+ M/W mix) and the per-day Ort become inputs; the actions
// column shows save/cancel. Std is recalculated automatically on save.
// Outside edit mode every per-day field is read-only.
async function wizEditDay(btn) {
    const row = btn.closest('tr');
    if (!row || row.classList.contains('be-day-editing')) return;
    // A planned/confirmed day feeds a live Dispo Einsatz that cannot take the
    // edit anyway (planned+workers keeps its times; confirmed is fully frozen)
    // — editing here would silently diverge. Block it: the plan must be lifted
    // in the Dispo first, then the day becomes editable again.
    if (row.dataset.scopeLocked === '1') {
        await mdAlert('Dieser Tag gehört zu einer Leistung <strong>außerhalb Ihres Bereichs</strong> und ist für Sie schreibgeschützt.', 'Tag gesperrt');
        return;
    }
    const ds = row.dataset.dispoStatus || '';
    if (ds === 'planned' || ds === 'confirmed') {
        const msg = ds === 'confirmed'
            ? 'Dieser Tag gehört zu einem <strong>bestätigten</strong> Einsatz und kann nicht bearbeitet werden — Änderungen erreichen den Einsatz nicht mehr (bitte in der Dispo ändern).'
            : 'Dieser Tag gehört zu einem <strong>geplanten</strong> Einsatz und kann nicht bearbeitet werden.<br><br>' +
              'Heben Sie den Plan zuerst in der Disposition auf (Einsatz öffnen → <strong>„Plan aufheben"</strong>), danach kann der Tag hier bearbeitet werden.';
        await mdAlert(msg, 'Tag gesperrt');
        return;
    }
    row.dataset.editSnapshot = JSON.stringify({ ...row.dataset });
    row.classList.add('be-day-editing');
    const cells = row.querySelectorAll('td');
    const inp = (type, value, extra) => `<md-outlined-text-field type="${type}" class="form-control" value="${escapeHtml(String(value))}" style="padding:2px 6px;font-size:.8rem;${extra || ''}"></md-outlined-text-field>`;
    const tInp = (value) => `<md-outlined-text-field type="text" class="form-control t24" inputmode="numeric" maxlength="5" placeholder="HH:MM" value="${escapeHtml(String(value))}" style="padding:2px 6px;font-size:.8rem;width:86px"></md-outlined-text-field>`;
    cells[1].innerHTML = inp('date', row.dataset.datum || '', 'width:130px');
    cells[3].innerHTML = tInp(row.dataset.von || '');
    cells[4].innerHTML = tInp(row.dataset.bis || '');
    cells[6].innerHTML = inp('number', parseInt(row.dataset.pauseMinuten) || 0, 'width:64px;text-align:right') ;
    cells[7].innerHTML = inp('number', parseInt(row.dataset.gesetzlPause) || 0, 'width:64px;text-align:right');
    cells[6].querySelector('input, md-outlined-text-field').min = '0';
    cells[7].querySelector('input, md-outlined-text-field').min = '0';
    // Pausenregelung: editing the day's Von/Bis re-derives its pause from
    // the tenant rules — until the user types a pause by hand (override).
    const rowAutoPause = () => {
        if (row._pauseTouched) return;
        const p = beAutoPauseMin(cells[3].querySelector('input, md-outlined-text-field')?.value, cells[4].querySelector('input, md-outlined-text-field')?.value);
        if (p !== null) cells[6].querySelector('input, md-outlined-text-field').value = p;
    };
    cells[3].querySelector('input, md-outlined-text-field')?.addEventListener('input', rowAutoPause);
    cells[4].querySelector('input, md-outlined-text-field')?.addEventListener('input', rowAutoPause);
    cells[6].querySelector('input, md-outlined-text-field')?.addEventListener('input', () => { row._pauseTouched = true; });
    cells[8].innerHTML = wizDayMengeEditHtml(row);
    // Beschreibung stays display-only (header-synced); the Ort gets its own
    // input + suggestion box so a single day can deviate from the order's
    // default meeting point (the worker app geo-check reads it per Einsatz).
    const anfEdit = wizParseAnf(row.dataset.anforderungen);
    const anfPersonsEdit = (anfEdit && Array.isArray(anfEdit.positionen)) ? anfEdit.positionen.reduce((s, p) => s + (parseInt(p.anzahl) || 0), 0) : 0;
    const hasAnfEdit = !!(anfEdit && (anfEdit.qualifikation || anfEdit.kleidung || anfEdit.skill || anfEdit.geschlecht || anfEdit.geschlecht_mix || (anfEdit.positionen && anfEdit.positionen.length)));
    const anfChipEdit = hasAnfEdit ? ` <span class="be-anf-chip" title="Anforderungen für Dispo hinterlegt">${beIcon('assignment', 'font-size:11px')}${anfPersonsEdit > 0 ? ' ' + anfPersonsEdit : ''}</span>` : '';
    // Confirmed einsätze are frozen server-side — an Ort change here is saved
    // on the order day but never reaches the worker app for that Einsatz.
    // Surface that on the input itself so the dispatcher isn't surprised.
    const confirmedOrtHint = row.dataset.dispoStatus === 'confirmed'
        ? ' title="Einsatz bestätigt — der Ort wird am Bestellungstag gespeichert, erreicht die Mitarbeiter-App für diesen Einsatz aber nicht mehr (in Dispo ändern)"'
        : '';
    cells[9].innerHTML = `
        <div class="be-day-ort-beschr" style="font-size:.78rem">${escapeHtml(row.dataset.beschreibung || '') || '–'}${anfChipEdit}</div>
        <div style="position:relative;margin-top:3px">
            <md-outlined-text-field type="text" class="form-control be-day-ort-edit" value="${escapeHtml(row.dataset.ort || '')}"
                   placeholder="Tag-Ort (geht in die Dispo) — leer = kein Ort" autocomplete="off"${confirmedOrtHint}
                   oninput="beOrtInput(event)" onfocusout="beOrtHide(event)"
                   style="padding:2px 6px;font-size:.78rem;min-width:190px"></md-outlined-text-field>
            <div class="be-ort-suggest be-day-ort-suggest" style="display:none"></div>
        </div>`;
    cells[10].innerHTML = `<div style="display:flex;gap:6px;justify-content:flex-end">
        <md-icon-button onclick="wizSaveDayEdit(this)" title="Übernehmen" style="color: var(--md-ext-color-success)"><md-icon slot="icon">check</md-icon></md-icon-button>
        <md-icon-button onclick="wizCancelDayEdit(this)" title="Abbrechen"><md-icon slot="icon">undo</md-icon></md-icon-button>
    </div>`;
}

// Accepts the ✓ button or the <tr> itself (used by wizCommitOpenDayEdits).
// Returns true when the row values were applied, false on validation error.
function wizSaveDayEdit(btnOrRow) {
    const row = btnOrRow && btnOrRow.tagName === 'TR' ? btnOrRow : btnOrRow.closest('tr');
    if (!row) return false;
    const cells = row.querySelectorAll('td');
    const datum = cells[1].querySelector('input, md-outlined-text-field')?.value || '';
    const von = cells[3].querySelector('input, md-outlined-text-field')?.value || '';
    const bis = cells[4].querySelector('input, md-outlined-text-field')?.value || '';
    let pause = parseInt(cells[6].querySelector('input, md-outlined-text-field')?.value) || 0;
    // Rules configured + pause untouched → the tier for THIS day's duration
    // wins over whatever the input happened to show — applied BEFORE the
    // net-hours check so soll_std matches the stored pause.
    const autoP = beAutoPauseMin(von, bis);
    // A deliberate per-day pause (override) is only re-derived when this
    // day's times actually changed — editing Ort/Menge/Datum must not wipe it.
    const timesChanged = von !== (row.dataset.von || '') || bis !== (row.dataset.bis || '');
    if (autoP !== null && !row._pauseTouched && (timesChanged || !row.dataset.pauseOverride)) pause = autoP;
    if (!datum) { showToast('Bitte ein Datum angeben', 'error'); return false; }
    if (!von || !bis) { showToast('Bitte Von- und Bis-Zeit angeben', 'error'); return false; }
    // Prevent an exact duplicate of another row — same datum + von + bis
    // UNDER THE SAME artikel. The same slot under another selected Leistung
    // is a legitimate parallel service (Bewachung + Bau on one day).
    const tbody = row.closest('tbody');
    const primaryArt = ((_beState.selArts || [])[0]?.artikel_id) || '';
    const rowArt = row.dataset.artikel || primaryArt;
    const key = `${datum}|${von}|${bis}|${rowArt}`;
    const dupe = [...tbody.querySelectorAll('tr')].some(r =>
        r !== row && `${r.dataset.datum}|${r.dataset.von}|${r.dataset.bis}|${r.dataset.artikel || primaryArt}` === key);
    if (dupe) { showToast('Ein identischer Tag (Datum + Zeiten + Leistung) existiert bereits', 'error'); return false; }
    // Menge is the hard cap for the M/W mix — never guess which gender to
    // drop, make the user fix it.
    const mengeInput = cells[8].querySelector('.be-day-menge');
    const menge = mengeInput ? Math.max(1, parseInt(mengeInput.value) || 1) : (parseInt(row.dataset.menge) || 1);
    const mixM = Math.max(0, parseInt(cells[8].querySelector('.be-day-mix-m')?.value) || 0);
    const mixW = Math.max(0, parseInt(cells[8].querySelector('.be-day-mix-w')?.value) || 0);
    if (mixM + mixW > menge) { showToast(`M${mixM} + W${mixW} = ${mixM + mixW} überschreitet die Menge ${menge} — bitte M/W reduzieren`, 'error'); return false; }
    const stdMins = beShiftNetMinutes(von, bis, pause);
    if (stdMins <= 0) showToast('Achtung: Pause ≥ Schichtdauer — Tag ergibt 0 Std', 'error');
    // Only record a per-day mix override when the user actually changed the
    // M/W split (or one already exists) — otherwise the template mix keeps
    // flowing through wizApplyMetaToRows() as before.
    const cur = wizDayMengeInfo(row);
    row.dataset.menge = menge;
    if (mengeInput && (row.dataset.geschlechtMix || mixM !== cur.mixM || mixW !== cur.mixW)) {
        row.dataset.geschlechtMix = JSON.stringify({ maennlich: mixM, weiblich: mixW });
    }
    row.dataset.datum = datum;
    row.dataset.von = von;
    row.dataset.bis = bis;
    delete row._pauseTouched;
    row.dataset.pauseMinuten = pause;
    row.dataset.gesetzlPause = pause;
    row.dataset.std = (stdMins / 60).toFixed(2);
    // Per-day pause that differs from the rule-derived (or, without rules,
    // the header) value = deliberate override — sync must not wipe it.
    const hPause = String(parseInt(document.getElementById('wiz_shift_pause')?.value) || 0);
    const refPause = (autoP !== null) ? String(autoP) : hPause;
    if (String(pause) !== refPause) row.dataset.pauseOverride = '1'; else delete row.dataset.pauseOverride;
    delete row.dataset.gesetzlPauseOverride;
    // Per-day Ort: any value that differs from the order-level header Ort is
    // a deliberate override — INCLUDING an emptied field ("kein Ort für
    // diesen Tag"). The flag stops wizApplyMetaToRows() from re-pushing the
    // header Ort and wizCollectDays() from falling back to it on save.
    const ortInput = cells[9] ? cells[9].querySelector('.be-day-ort-edit') : null;
    if (ortInput) {
        const newOrt = String(ortInput.value ?? '').trim();
        const headerOrt = String(document.getElementById('wiz_shift_ort')?.value ?? '').trim();
        row.dataset.ort = newOrt;
        if (newOrt !== headerOrt) row.dataset.ortOverride = '1';
        else delete row.dataset.ortOverride;
    }
    delete row.dataset.editSnapshot;
    row.classList.remove('be-day-editing');
    wizRedrawDayRow(row, 0);
    wizSortAndRenumberDays();
    wizUpdateDaysFooter();
    return true;
}

// Rows still in inline edit mode (pencil clicked, ✓ not yet pressed) hold
// their new values only in the inputs — wizCollectDays() reads the row
// dataset, so those edits would be silently lost on "Speichern". Commit
// them first; abort the save when one of them fails validation.
function wizCommitOpenDayEdits() {
    const tbody = document.getElementById('wizDaysBody');
    if (!tbody) return true;
    const editing = [...tbody.querySelectorAll('tr.be-day-editing')];
    for (const row of editing) {
        if (!wizSaveDayEdit(row)) return false;
    }
    return true;
}

function wizCancelDayEdit(btn) {
    const row = btn.closest('tr');
    if (!row) return;
    let snapshot = null;
    try { snapshot = JSON.parse(row.dataset.editSnapshot || 'null'); } catch (_) {}
    if (snapshot) {
        Object.keys(row.dataset).forEach(key => delete row.dataset[key]);
        Object.entries(snapshot).forEach(([key, value]) => { row.dataset[key] = value; });
    }
    delete row.dataset.editSnapshot;
    row.classList.remove('be-day-editing');
    wizRedrawDayRow(row, 0);
    wizSortAndRenumberDays();
    wizUpdateDaysFooter();
}

// Duplicate a day row → creates a SECOND shift on the SAME calendar day.
// The backend fully supports multiple Einsätze per day: IdGenerator::einsatzId()
// produces a unique id per shift and `tag_nr` distinguishes them, so two
// identical shifts (same datum/von/bis) become two separate cards in the Dispo
// planner — each assignable/confirmable on its own.
function wizDuplicateDay(btn) {
    const row = btn.closest('tr');
    if (!row) return;
    if (row.dataset.scopeLocked === '1') {
        showToast('Tag gehört zu einer Leistung außerhalb Ihres Bereichs', 'error');
        return;
    }
    const tbody = document.getElementById('wizDaysBody');
    const dateStr = row.dataset.datum;
    const dayOfWeek = new Date(dateStr + 'T00:00:00').getDay();
    _wizDayCount++;
    wizAppendDayRow(
        tbody, _wizDayCount, dateStr, dayOfWeek,
        row.dataset.von, row.dataset.bis, row.dataset.std,
        row.dataset.gesetzlPause, row.dataset.menge,
        row.dataset.beschreibung, row.dataset.pauseMinuten,
        wizParseAnf(row.dataset.anforderungen), row.dataset.ort || '',
        null, row.dataset.artikel || ''
    );
    const newRow = tbody.lastElementChild;
    if (newRow && row.dataset.ortOverride) newRow.dataset.ortOverride = row.dataset.ortOverride;
    if (newRow && row.dataset.geschlechtMix) {
        newRow.dataset.geschlechtMix = row.dataset.geschlechtMix;
        wizRedrawRowKeepIndex(newRow);
    }
    wizSortAndRenumberDays();
    showToast(`2. Schicht für ${formatDate(dateStr)} hinzugefügt`);
    wizUpdateDaysFooter();
}

function wizParseDateInput(raw) {
    if (!raw) return null;
    raw = raw.trim();
    const deParts = raw.match(/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/);
    if (deParts) {
        const d = deParts[1].padStart(2, '0');
        const m = deParts[2].padStart(2, '0');
        return `${deParts[3]}-${m}-${d}`;
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
    return null;
}

async function wizAddSingleDay() {
    if (!wizVorlageGuard()) return;
    const dateInput = document.getElementById('wiz_single_date');
    const dateStr = wizParseDateInput(dateInput.value);
    if (!dateStr) {
        showToast('Bitte Datum im Format TT.MM.JJJJ eingeben', 'error');
        return;
    }
    if (!wizDateInOrder(dateStr)) {
        showToast(`${formatDate(dateStr)} liegt außerhalb des Bestellmonats ${wizOrderMonthLabel()}`, 'error');
        return;
    }
    const von = document.getElementById('wiz_shift_von').value;
    const bis = document.getElementById('wiz_shift_bis').value;
    const menge = document.getElementById('wiz_shift_menge').value || '1';
    const pause = parseInt(document.getElementById('wiz_shift_pause').value) || 0;
    const beschreibung = document.getElementById('wiz_shift_beschreibung').value || '';
    const gesetzlPause = parseInt(document.getElementById('wizGesetzlPause')?.value) || 0;
    const anf = wizCollectAnforderungen();

    const tbody = document.getElementById('wizDaysBody');
    // Only reject if there is already an EXACT (datum, von, bis) match UNDER
    // THE SAME artikel — the same slot under another selected Leistung is a
    // legitimate parallel service (Bewachung + Bau on one day).
    const curArt = _beState.activeArtTab || (_beState.selArt?.artikel_id) || '';
    const primaryArt = ((_beState.selArts || [])[0]?.artikel_id) || '';
    const dup = [...tbody.querySelectorAll(`tr[data-datum="${dateStr}"]`)].find(r =>
        (r.dataset.von || '') === (von || '') &&
        (r.dataset.bis || '') === (bis || '') &&
        (r.dataset.artikel || primaryArt) === curArt
    );
    if (dup) {
        // Same day + same time already exists. This IS allowed (a 2nd shift /
        // 2nd team on the same slot becomes a separate Einsatz in Dispo), but
        // confirm first so it isn't an accidental double-add.
        const ok = await mdConfirm(`${formatDate(dateStr)} ${von}–${bis} ist bereits vorhanden.<br>Als zweite Schicht (separater Einsatz) trotzdem hinzufügen?`, 'Doppelter Tag');
        if (!ok) return;
    }

    const stdMins = (von && bis) ? beShiftNetMinutes(von, bis, pause) : 0;
    if (von && bis && stdMins <= 0) showToast('Achtung: Pause ≥ Schichtdauer — Tag ergibt 0 Std', 'error');
    const sollStd = (stdMins / 60).toFixed(2);
    const date = new Date(dateStr + 'T00:00:00');
    const dayOfWeek = date.getDay();

    _wizDayCount++;
    wizAppendDayRow(tbody, _wizDayCount, dateStr, dayOfWeek, von, bis, sollStd, gesetzlPause, menge, beschreibung, pause, anf, document.getElementById('wiz_shift_ort')?.value || '');
    // Keep the table sorted chronologically + renumber the index column
    wizSortAndRenumberDays();
    dateInput.value = '';
    showToast(`${formatDate(dateStr)} (${DAYS_DE[dayOfWeek]}) hinzugefügt`);
    wizUpdateDaysFooter();
}

// Live-filter for the days table: hides non-matching rows ONLY. The dataset
// is untouched — wizCollectDays() still saves every day, hidden or not.
// Matches ISO date, dd.mm.yyyy, weekday name, times, Beschreibung and Ort.
function wizFilterDays(q) {
    q = (q || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#wizDaysBody tr');
    // The artikel tab is the base layer — a row is only ever visible when it
    // belongs to the active artikel tab; the search then refines within it.
    const arts = _beState.selArts || [];
    const primary = arts.length ? String(arts[0].artikel_id) : '';
    const act = _beState.activeArtTab || primary;
    const tabOk = (r) => arts.length <= 1 || (r.dataset.artikel || primary) === String(act);
    let shown = 0;
    rows.forEach(r => {
        const hay = [
            r.dataset.datum || '',
            formatDate(r.dataset.datum || ''),
            r.dataset.wochentag || '',
            r.dataset.von || '',
            r.dataset.bis || '',
            r.dataset.beschreibung || '',
            r.dataset.ort || '',
        ].join(' ').toLowerCase();
        const match = (!q || hay.includes(q)) && tabOk(r);
        r.style.display = match ? '' : 'none';
        if (match) shown++;
    });
    const cnt = document.getElementById('wizDaysSearchCount');
    if (cnt) {
        cnt.style.display = q ? '' : 'none';
        cnt.textContent = q ? `${shown}/${rows.length}` : '';
    }
}

async function wizRemoveDay(btn) {
    const row = btn.closest('tr');
    if (row && row.dataset.scopeLocked === '1') {
        showToast('Tag gehört zu einer Leistung außerhalb Ihres Bereichs', 'error');
        return;
    }
    // A confirmed Einsatz is a finished record — its day is locked, the
    // dispatcher must un-confirm the shift in Dispo first (the server
    // double-checks this with a 409).
    if (row && row.dataset.dispoStatus === 'confirmed') {
        await mdAlert('Dieser Tag gehört zu einem <strong>bestätigten</strong> Einsatz und kann nicht entfernt werden.<br><br>' +
            'Heben Sie die Bestätigung zuerst in der Disposition auf.', 'Tag gesperrt');
        return;
    }
    // A planned Einsatz WITH workers blocks removal as well (no silent grey
    // "Vom Bestelleingang entfernt" card): the plan is withdrawn in Dispo
    // first. The server double-checks this with a 409.
    if (row && row.dataset.dispoStatus === 'planned') {
        const dateStr = row.dataset.datum || '';
        const shift = (row.dataset.von && row.dataset.bis) ? ' ' + row.dataset.von + '–' + row.dataset.bis : '';
        await mdAlert('Für <strong>' + (dateStr ? formatDate(dateStr) : 'diesen Tag') + shift + '</strong> gibt es einen <strong>geplanten Einsatz mit Mitarbeitern</strong> in der Disposition.<br><br>' +
            'Bitte heben Sie zuerst den Plan in der Disposition auf (Mitarbeiter entfernen). Danach kann der Tag hier entfernt werden.', 'Tag gesperrt');
        return;
    }
    const removedLabel = row.dataset.datum
        ? formatDate(row.dataset.datum) + ((row.dataset.von && row.dataset.bis) ? ' ' + row.dataset.von + '–' + row.dataset.bis : '')
        : 'Tag';
    row.remove();
    const rows = document.getElementById('wizDaysBody').querySelectorAll('tr');
    rows.forEach((r, i) => { r.querySelector('td').innerHTML = `<strong>${i + 1}</strong>`; });
    _wizDayCount = rows.length;
    wizUpdateDaysFooter();
    showToast(removedLabel + ' entfernt — wird mit „Speichern“ übernommen');
}

function wizUpdateDaysFooter() {
    const rows = document.getElementById('wizDaysBody').querySelectorAll('tr');
    const empty = document.getElementById('wizDaysEmpty');
    const footer = document.getElementById('wizDaysFooter');
    const totalEl = document.getElementById('wizTotalStd');
    wizUpdateLockLegend(rows);
    wizRenderDaysCal();

    if (rows.length === 0) {
        if (empty) empty.style.display = '';
        if (footer) footer.style.display = 'none';
        return;
    }
    if (empty) empty.style.display = 'none';
    if (footer) footer.style.display = '';

    let totalStd = 0;
    rows.forEach(r => {
        const std = parseFloat(r.dataset.std) || 0;
        const menge = parseFloat(r.dataset.menge) || 1;
        totalStd += std * menge;
    });
    if (totalEl) totalEl.textContent = totalStd.toFixed(2).replace('.', ',') + ' Std';
    wizSyncTopScrollWidth();
    // Refresh the per-artikel tab counts + re-apply the active filter.
    wizRenderArtTabs();
    // Keep an active day-search in sync after add/remove/regenerate —
    // otherwise freshly appended rows would ignore the typed filter.
    const sq = document.getElementById('wizDaysSearch');
    if (sq && sq.value.trim()) wizFilterDays(sq.value);
}

// Explains the lock badges BEFORE the user clicks a locked row: one line
// per lock type present in the table (Geplant / Bestätigt / Bereich).
function wizUpdateLockLegend(rows) {
    const box = document.getElementById('wizDaysLockLegend');
    if (!box) return;
    const list = [...(rows || [])];
    const planned = list.filter(r => r.dataset.dispoStatus === 'planned').length;
    const confirmed = list.filter(r => r.dataset.dispoStatus === 'confirmed').length;
    const scope = list.filter(r => r.dataset.scopeLocked === '1').length;
    const lines = [];
    if (confirmed) lines.push(`<div><span class="be-dispo-status-badge be-dispo-confirmed">${beIcon('block', 'font-size:11px;vertical-align:-2px')} Bestätigt</span> <strong>${confirmed}</strong> Tag(e) — Einsatz bestätigt, weder änderbar noch entfernbar.</div>`);
    if (planned) lines.push(`<div><span class="be-dispo-status-badge be-dispo-planned">${beIcon('warning', 'font-size:11px;vertical-align:-2px')} Geplant</span> <strong>${planned}</strong> Tag(e) — erst in der Disposition „Plan aufheben“, dann hier bearbeitbar.</div>`);
    if (scope) lines.push(`<div><span class="be-dispo-status-badge" style="background: var(--gray-100, var(--md-sys-color-primary-container));color: var(--gray-500, var(--md-sys-color-primary))">${beIcon('lock', 'font-size:11px;vertical-align:-2px')} Bereich</span> <strong>${scope}</strong> Tag(e) — Leistung außerhalb Ihres Bereichs, schreibgeschützt.</div>`);
    box.innerHTML = lines.join('');
    box.style.display = lines.length ? '' : 'none';
}

const BE_CAL_DAY_MAX = 3;
const BE_CAL_WD = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
const BE_CAL_STATUS = {
    open:      { label: 'Offen',     icon: 'event' },
    planned:   { label: 'Geplant',   icon: 'groups' },
    confirmed: { label: 'Bestätigt', icon: 'verified' },
    scope:     { label: 'Bereich',   icon: 'lock' },
};
let _beCalRows = [];
let _beCalPop = null;
let _beCalPopIso = null;
let _beCalPopFocus = null;
let _beCalPopOnly = null;   // set by a chip click → sheet shows only that Schicht
let _beCalReadOnly = false; // viewIncomingOrder renders the same calendar read-only

function _beCalIso(y, m, d) { return `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`; }
function _beCalFmtStd(v) { return (Math.round(v * 100) / 100).toFixed(2).replace('.', ','); }
function _beCalVisibleRows() {
    return [...document.querySelectorAll('#wizDaysBody tr')].filter(r => r.dataset.datum && r.style.display !== 'none');
}
function _beCalStatus(row) {
    if (row.dataset.scopeLocked === '1') return 'scope';
    const ds = row.dataset.dispoStatus || '';
    return ds === 'confirmed' ? 'confirmed' : (ds === 'planned' ? 'planned' : 'open');
}
function _beCalByTime(a, b) { return (a.dataset.von || '').localeCompare(b.dataset.von || ''); }

function _beCalEnsureStyle() {
    if (document.getElementById('beCalStyle')) return;
    const st = document.createElement('style');
    st.id = 'beCalStyle';
    st.textContent = `
        .be-cal,.be-cal-pop{--cal-line:var(--md-sys-color-outline-variant,#e2e8f0);--cal-open:var(--be-accent,#2563eb);--cal-planned:var(--be-warn,#d97706);--cal-confirmed:var(--be-mint,#16a34a);--cal-scope:var(--be-text-soft,#94a3b8);--cal-text:var(--be-text,var(--md-sys-color-on-surface,#1f2937))}
        .be-cal{margin-top:6px}
        .be-cal-head{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:10px}
        .be-cal-nav{width:34px;height:34px;border-radius:50%;border:1px solid var(--cal-line);background:transparent;color:var(--cal-text,#1f2937);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;flex-shrink:0}
        .be-cal-nav:hover{background:var(--gray-50,#f8fafc)}
        .be-cal-title{font-size:1rem;font-weight:700;min-width:150px;text-align:center;color:var(--cal-text,#1f2937)}
        .be-cal-sum{margin-left:auto;display:flex;gap:12px;flex-wrap:wrap;font-size:.8rem;color:var(--be-text-muted,#64748b)}
        .be-cal-sum span{display:inline-flex;align-items:center;gap:4px}
        .be-cal-sum strong{color:var(--cal-text,#1f2937)}
        .be-cal-sum-all{padding:2px 9px;border-radius:999px;background:var(--gray-100,#f1f5f9)}
        .be-cal-months{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
        .be-cal-month-pill{border:1px solid var(--cal-line);background:transparent;border-radius:999px;padding:3px 10px;font:inherit;font-size:.76rem;font-weight:600;color:var(--be-text-muted,#64748b);cursor:pointer}
        .be-cal-month-pill span{margin-left:4px;padding:0 6px;border-radius:999px;background:var(--gray-100,#f1f5f9)}
        .be-cal-month-pill.active{background:var(--md-sys-color-secondary-container,#dbeafe);color:var(--md-sys-color-on-secondary-container,#1e3a8a);border-color:transparent}
        .be-cal-wd,.be-cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}
        .be-cal-wd>div{padding:6px 8px;font-size:.7rem;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--be-text-muted,#64748b)}
        .be-cal-grid{border-top:1px solid var(--cal-line);border-left:1px solid var(--cal-line);border-radius:10px;overflow:hidden}
        .be-cal-day{min-height:104px;padding:6px;border-right:1px solid var(--cal-line);border-bottom:1px solid var(--cal-line);display:flex;flex-direction:column;gap:4px;min-width:0;cursor:pointer;transition:background .12s}
        .be-cal-day:hover{background:color-mix(in srgb,var(--cal-open) 5%,transparent)}
        .be-cal-day.is-out{background:var(--gray-50,#f8fafc);opacity:.55;cursor:default}
        .be-cal-day.is-we{background:var(--gray-50,#f8fafc)}
        .be-cal-day.is-off{cursor:default;background:repeating-linear-gradient(45deg,transparent 0 6px,color-mix(in srgb,var(--be-text-soft,#94a3b8) 10%,transparent) 6px 12px)}
        .be-cal-day.is-off:hover{background:repeating-linear-gradient(45deg,transparent 0 6px,color-mix(in srgb,var(--be-text-soft,#94a3b8) 10%,transparent) 6px 12px)}
        .be-cal-day.is-off .be-cal-num{color:var(--be-text-soft,#94a3b8)}
        .be-cal-day.is-today{box-shadow:inset 0 0 0 2px var(--md-sys-color-primary,#2563eb)}
        .be-cal-day-head{display:flex;align-items:center;justify-content:space-between}
        .be-cal-num{min-width:24px;height:24px;padding:0 6px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:var(--cal-text,#1f2937)}
        .be-cal-day.is-today .be-cal-num{background:var(--md-sys-color-primary,#2563eb);color:var(--md-sys-color-on-primary,#fff)}
        .be-cal-count{font-size:.66rem;font-weight:700;padding:1px 7px;border-radius:999px;background:var(--md-sys-color-surface-container-high,var(--gray-100,#f1f5f9));color:var(--be-text-muted,#64748b)}
        .be-cal-add{display:inline-flex;color:var(--be-text-muted,#94a3b8);opacity:0;transition:opacity .12s}
        .be-cal-day:hover .be-cal-add{opacity:1}
        .be-cal-list{display:flex;flex-direction:column;gap:3px;min-width:0}
        .be-cal-chip{--c:var(--cal-open);display:flex;align-items:center;justify-content:space-between;gap:4px;min-width:0;width:100%;padding:3px 6px;border:0;border-left:3px solid var(--c);border-radius:7px;background:color-mix(in srgb,var(--c) 13%,transparent);font:inherit;font-size:.72rem;font-weight:600;color:var(--cal-text,#1f2937);line-height:1.2;cursor:pointer;text-align:left}
        .be-cal-chip:hover{background:color-mix(in srgb,var(--c) 22%,transparent)}
        .be-cal-chip.st-planned{--c:var(--cal-planned)}
        .be-cal-chip.st-confirmed{--c:var(--cal-confirmed)}
        .be-cal-chip.st-scope{--c:var(--cal-scope)}
        .be-cal-chip-time{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .be-cal-chip-time sup{font-size:.6rem;margin-left:1px}
        .be-cal-chip-qty{display:inline-flex;align-items:center;gap:1px;flex-shrink:0;color:var(--be-text-muted,#475569)}
        .be-cal-more{font-size:.7rem;font-weight:700;color:var(--md-sys-color-primary,#2563eb);padding:1px 4px}
        .be-cal-legend{display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-top:8px;font-size:.74rem;color:var(--be-text-muted,#64748b)}
        .be-cal-legend span{display:inline-flex;align-items:center;gap:5px}
        .be-cal-legend i{width:10px;height:10px;border-radius:3px;background:var(--cal-open)}
        .be-cal-legend .st-planned i{background:var(--cal-planned)}
        .be-cal-legend .st-confirmed i{background:var(--cal-confirmed)}
        .be-cal-legend-hint{margin-left:auto}
        .be-cal-agenda{display:none}
        .be-cal-empty{display:flex;flex-direction:column;align-items:center;gap:6px;padding:22px 10px;color:var(--be-text-muted,#64748b);font-size:.85rem;text-align:center}
        .be-cal-note{display:flex;gap:8px;align-items:flex-start;padding:9px 12px;margin:6px 0;border-radius:10px;background:var(--be-warn-soft,#fffbeb);color:#92400e;border:1px solid color-mix(in srgb,var(--be-warn,#d97706) 35%,transparent);font-size:.8rem;line-height:1.4}
        [data-theme="dark"] .be-cal-note{color:var(--be-warn,#fbbf24)}
        @media (max-width:760px){
            .be-cal-month{display:none}
            .be-cal-agenda{display:flex;flex-direction:column;gap:8px}
            .be-cal-agenda-day{border:1px solid var(--cal-line);border-radius:12px;padding:10px;cursor:pointer}
            .be-cal-agenda-day.is-today{box-shadow:inset 0 0 0 2px var(--md-sys-color-primary,#2563eb)}
            .be-cal-agenda-date{display:flex;align-items:center;justify-content:space-between;font-size:.82rem;font-weight:700;margin-bottom:6px;color:var(--cal-text,#1f2937)}
            .be-cal-agenda-list{display:flex;flex-wrap:wrap;gap:5px}
            .be-cal-agenda-list .be-cal-chip{width:auto}
            .be-cal-sum{margin-left:0;width:100%}
            .be-cal-legend-hint{margin-left:0}
        }
        .be-cal-pop{position:fixed;inset:0;width:100vw;height:100dvh;max-width:none;max-height:none;margin:0;border:0;padding:16px;box-sizing:border-box;background:rgba(15,23,42,.38);display:flex;align-items:center;justify-content:center;z-index:100000;overflow:hidden;color:var(--cal-text,#1f2937)}
        .be-cal-sheet{width:min(640px,100%);max-height:min(86dvh,820px);display:flex;flex-direction:column;background:var(--white,#fff);border-radius:16px;box-shadow:0 24px 60px rgba(15,23,42,.28);overflow:hidden}
        .be-cal-sheet-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:16px 18px 10px;border-bottom:1px solid var(--cal-line,#e2e8f0)}
        .be-cal-sheet-title{font-size:1.02rem;font-weight:700}
        .be-cal-sheet-sub{font-size:.78rem;color:var(--be-text-muted,#64748b);margin-top:2px}
        .be-cal-all{border:0;background:none;padding:0;font:inherit;font-weight:700;color:var(--be-accent,#2563eb);cursor:pointer;text-decoration:underline;text-underline-offset:2px}
        .be-cal-x{width:34px;height:34px;border-radius:50%;border:0;background:transparent;color:inherit;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
        .be-cal-x:hover{background:var(--gray-100,#f1f5f9)}
        .be-cal-sheet-body{padding:12px 18px;overflow:auto;display:flex;flex-direction:column;gap:10px}
        .be-cal-sheet-foot{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 18px 14px;border-top:1px solid var(--cal-line,#e2e8f0);font-size:.76rem;color:var(--be-text-muted,#64748b)}
        .be-cal-card{--c:var(--be-accent,#2563eb);border:1px solid var(--cal-line,#e2e8f0);border-left:4px solid var(--c);border-radius:12px;padding:12px 14px;background:color-mix(in srgb,var(--c) 4%,var(--white,#fff))}
        .be-cal-card.st-planned{--c:var(--cal-planned)}.be-cal-card.st-confirmed{--c:var(--cal-confirmed)}.be-cal-card.st-scope{--c:var(--cal-scope)}
        .be-cal-card.is-focus{box-shadow:0 0 0 2px color-mix(in srgb,var(--c) 45%,transparent)}
        .be-cal-card-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px}
        .be-cal-card-time{font-size:1.05rem;font-weight:700}
        .be-cal-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:999px;font-size:.7rem;font-weight:700;color:var(--c);background:color-mix(in srgb,var(--c) 14%,transparent)}
        .be-cal-art{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:999px;font-size:.7rem;font-weight:600;color:var(--be-accent,#2563eb);background:color-mix(in srgb,var(--be-accent,#2563eb) 12%,transparent)}
        .be-cal-facts{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px 14px}
        .be-cal-fact{display:flex;gap:8px;align-items:flex-start;min-width:0}
        .be-cal-fact>.material-symbols-outlined{color:var(--be-text-muted,#64748b);margin-top:2px}
        .be-cal-fact>div>span{display:block;font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.3px;color:var(--be-text-muted,#64748b)}
        .be-cal-fact strong{display:block;font-size:.85rem;font-weight:600;overflow-wrap:anywhere}
        .be-cal-fact.wide{grid-column:1/-1}
        .be-cal-fact small{font-weight:600;font-size:.7rem;color:var(--be-text-muted,#64748b)}
        .be-cal-m{color:#2563eb}.be-cal-w{color:#db2777}
        .be-cal-pos{margin:0;padding-left:18px;font-size:.82rem}
        .be-cal-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center}
        .be-cal-lockinfo{font-size:.76rem;color:var(--be-text-muted,#64748b);display:inline-flex;align-items:center;gap:4px}
        .be-cal-btn{display:inline-flex;align-items:center;gap:5px;height:32px;padding:0 12px;border-radius:999px;border:1px solid var(--cal-line,#cbd5e1);background:transparent;color:inherit;font:inherit;font-size:.78rem;font-weight:600;cursor:pointer}
        .be-cal-btn:hover{background:var(--gray-50,#f8fafc)}
        .be-cal-btn.primary{background:var(--be-accent,#2563eb);border-color:transparent;color:#fff}
        .be-cal-btn.primary:hover{filter:brightness(1.07)}
        .be-cal-btn.danger{color:var(--be-danger,#dc2626)}
        .be-cal-edit{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .be-cal-edit label{display:flex;flex-direction:column;gap:4px;font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.3px;color:var(--be-text-muted,#64748b);min-width:0}
        .be-cal-edit label.wide{grid-column:1/-1;position:relative}
        .be-cal-edit label.m{color:#2563eb}.be-cal-edit label.w{color:#db2777}
        .be-cal-edit .form-control{width:100%;box-sizing:border-box;text-transform:none;letter-spacing:0}
        .be-cal-edit .be-cal-actions{grid-column:1/-1;margin-top:0}
        .be-cal-edit .be-cal-anf{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding-top:10px;border-top:1px dashed var(--cal-line,#e2e8f0)}
        .be-cal-edit-pos{grid-column:1/-1;font-size:.72rem;color:var(--be-text-muted,#64748b);text-transform:none;letter-spacing:0;font-weight:500;line-height:1.45}
        .be-cal-desc{display:block;white-space:pre-wrap;max-height:6.4em;overflow:auto;padding-right:6px}
        [data-theme="dark"] .be-cal-pop{background:rgba(2,6,23,.62)}
        [data-theme="dark"] .be-cal-m,[data-theme="dark"] .be-cal-edit label.m,[data-theme="dark"] .be-vl-m>label,[data-theme="dark"] .be-vl-m .form-control{color:#93c5fd}
        [data-theme="dark"] .be-cal-w,[data-theme="dark"] .be-cal-edit label.w,[data-theme="dark"] .be-vl-w>label,[data-theme="dark"] .be-vl-w .form-control{color:#f9a8d4}
        @media (max-width:560px){.be-cal-edit .be-cal-anf{grid-template-columns:1fr}}
        .be-vl-flow{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:2px 0 14px;font-size:.78rem;color:var(--be-text-muted,#64748b)}
        .be-vl-flow span{display:inline-flex;align-items:center;gap:6px;font-weight:600}
        .be-vl-flow b{width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;background:var(--be-accent-soft,#ede9fe);color:var(--be-accent,#7c3aed)}
        .be-vl-card{background:var(--be-surface,#fff);border:1px solid var(--be-border,#e5e7eb);border-radius:14px;padding:16px 18px;margin-bottom:14px}
        .be-vl-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
        .be-vl-num{width:30px;height:30px;flex-shrink:0;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:.9rem;background:var(--be-accent,#7c3aed);color:var(--be-surface,#fff)}
        .be-vl-head-text{flex:1;min-width:200px;display:flex;flex-direction:column;gap:2px}
        .be-vl-head-text strong{font-size:.98rem;color:var(--be-text,#111827)}
        .be-vl-head-text small{font-size:.76rem;color:var(--be-text-muted,#64748b);line-height:1.35}
        .be-vl-apply{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 14px;border-radius:999px;border:1px solid var(--be-accent-line,#ddd6fe);background:var(--be-accent-soft,#ede9fe);color:var(--be-accent,#7c3aed);font:inherit;font-size:.78rem;font-weight:700;cursor:pointer;white-space:nowrap}
        .be-vl-apply:hover{background:var(--be-accent,#7c3aed);color:#fff}
        .be-vl-group{padding:12px 0;border-top:1px solid var(--be-bg-strip,#f1f5f9)}
        .be-vl-group:first-of-type{border-top:0;padding-top:0}
        .be-vl-label{display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:.72rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--be-text-muted,#64748b)}
        .be-vl-label .material-symbols-outlined{color:var(--be-accent,#7c3aed)}
        .be-vl-grid{display:grid;gap:12px;align-items:end}
        .be-vl-time{grid-template-columns:repeat(3,minmax(0,1fr)) minmax(0,1.25fr)}
        .be-vl-staff{grid-template-columns:repeat(3,minmax(0,1fr))}
        .be-vl-place{grid-template-columns:1fr 1fr}
        .be-vl-reqs{grid-template-columns:repeat(3,minmax(0,1fr))}
        .be-vl-period{grid-template-columns:2fr 1fr;max-width:460px;margin-bottom:14px}
        .be-vl-field{display:flex;flex-direction:column;gap:5px;min-width:0;position:relative}
        .be-vl-field>label{font-size:.76rem;font-weight:600;color:var(--be-text,#111827);display:flex;align-items:center;gap:4px;flex-wrap:wrap}
        .be-vl-field>label small{font-weight:400;color:var(--be-text-muted,#64748b);font-size:.7rem}
        .be-vl-field>label .material-symbols-outlined{color:var(--be-text-muted,#64748b)}
        .be-vl-field .form-control{width:100%;box-sizing:border-box;height:40px}
        .be-vl-m>label{color:#2563eb}.be-vl-w>label{color:#db2777}
        .be-vl-m .form-control{color:#2563eb;font-weight:700}.be-vl-w .form-control{color:#db2777;font-weight:700}
        .be-vl-req{color:var(--be-danger,#dc2626)}
        .be-vl-help{margin-top:6px;font-size:.72rem;color:var(--be-text-muted,#64748b)}
        .be-vl-duration{height:40px;box-sizing:border-box;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:2px 10px;border-radius:10px;background:var(--be-mint-soft,#d1fae5);border:1.5px solid var(--be-mint-line,#a7f3d0);color:var(--be-mint-hover,#059669);min-width:0}
        .be-vl-dur-main{font-weight:800;font-size:1rem;font-variant-numeric:tabular-nums;line-height:1.1}
        .be-vl-dur-sub{font-size:.66rem;font-weight:600;opacity:.85;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
        .be-vl-duration.is-bad{background:var(--be-danger-soft,#fee2e2);border-color:color-mix(in srgb,var(--be-danger,#dc2626) 35%,transparent);color:var(--be-danger,#dc2626)}
        .be-vl-invalid{border-color:var(--be-danger,#dc2626)!important;box-shadow:0 0 0 3px color-mix(in srgb,var(--be-danger,#dc2626) 14%,transparent)!important}
        .be-vl-check{display:flex;flex-direction:column;gap:6px;margin-top:4px}
        .be-vl-check>div{display:flex;align-items:flex-start;gap:8px;padding:8px 11px;border-radius:9px;font-size:.78rem;line-height:1.35}
        .be-vl-check .is-err{background:var(--be-danger-soft,#fee2e2);color:var(--be-danger,#b91c1c)}
        .be-vl-check .is-warn{background:var(--be-warn-soft,#fef3c7);color:#92400e}
        .be-vl-check .is-info{background:var(--be-accent-tint,rgba(124,58,237,.06));color:var(--be-text-muted,#475569)}
        [data-theme="dark"] .be-vl-check .is-warn{color:var(--be-warn,#fbbf24)}
        .be-vl-pos{margin-top:16px}
        .be-vl-pos-sum{margin-left:6px;padding:1px 8px;border-radius:999px;font-size:.7rem;font-weight:700;background:var(--be-mint-soft,#d1fae5);color:var(--be-mint-hover,#059669)}
        .be-vl-pos-sum:empty{display:none}
        .be-vl-pos-sum.is-off{background:var(--be-warn-soft,#fef3c7);color:#b45309}
        .be-vl-pos-empty{display:none;font-size:.76rem;color:var(--be-text-muted,#64748b);padding:6px 2px}
        #wizPositionsBody:empty+.be-vl-pos-empty{display:block}
        .be-vl-pos:has(#wizPositionsBody:empty) .be-anf-pos-head-row{display:none}
        .be-vl-seg{display:inline-flex;gap:4px;padding:4px;border-radius:999px;background:var(--be-bg-strip,#f1f5f9);margin-bottom:14px;flex-wrap:wrap}
        .be-vl-seg button{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 14px;border:0;border-radius:999px;background:transparent;color:var(--be-text-muted,#64748b);font:inherit;font-size:.8rem;font-weight:700;cursor:pointer}
        .be-vl-seg button.active{background:var(--be-surface,#fff);color:var(--be-accent,#7c3aed);box-shadow:0 1px 4px rgba(15,23,42,.12)}
        .be-vl-wd{margin-bottom:14px}
        #wizGen[data-mode="einzel"] .be-vl-wd{display:none}
        .be-vl-panel{display:none;align-items:flex-end;gap:12px;flex-wrap:wrap;padding:12px 14px;border-radius:12px;background:var(--be-bg-soft,#f8fafc);border:1px dashed var(--be-border-strong,#cbd5e1)}
        #wizGen[data-mode="monat"] [data-panel="monat"],#wizGen[data-mode="zeitraum"] [data-panel="zeitraum"],#wizGen[data-mode="einzel"] [data-panel="einzel"]{display:flex}
        .be-vl-panel .be-vl-field{width:180px}
        .be-vl-panel-info{flex:1 1 220px;font-size:.76rem;color:var(--be-text-muted,#64748b);align-self:center}
        .be-vl-panel md-filled-button{--md-filled-button-container-height:40px;white-space:nowrap}
        .be-vl-calcard .be-days-search{margin-left:auto}
        @media (max-width:760px){
            .be-vl-card{padding:14px 12px}
            .be-vl-time{grid-template-columns:repeat(3,minmax(0,1fr))}
            .be-vl-time .be-vl-duration{grid-column:1/-1}
            .be-vl-staff{grid-template-columns:1fr 1fr}
            .be-vl-staff .be-vl-field:first-child{grid-column:1/-1}
            .be-vl-place,.be-vl-reqs{grid-template-columns:1fr}
            .be-vl-period{max-width:none}
            .be-vl-panel .be-vl-field{width:100%}
            .be-vl-seg{display:flex;width:100%;box-sizing:border-box}
            .be-vl-seg button{flex:1;justify-content:center;padding:0 6px;font-size:.74rem}
            .be-vl-apply{width:100%;justify-content:center}
            .be-vl-calcard .be-days-search{margin-left:0;flex:1 1 100%}
            .be-vl-flow{display:none}
        }
        @media (max-width:560px){.be-cal-edit{grid-template-columns:repeat(2,minmax(0,1fr))}.be-cal-pop{padding:8px}}`;
    document.head.appendChild(st);
}

function _beCalView(cal, rows) {
    if (cal.dataset.y && cal.dataset.m) return { y: +cal.dataset.y, m: +cal.dataset.m };
    const now = new Date();
    let y = parseInt(document.getElementById('wiz_jahr')?.value) || now.getFullYear();
    let m = (parseInt(document.getElementById('wiz_monat')?.value) || (now.getMonth() + 1)) - 1;
    const prefix = _beCalIso(y, m, 1).substring(0, 7);
    if (rows.length && !rows.some(r => r.dataset.datum.startsWith(prefix))) {
        const [ry, rm] = rows.map(r => r.dataset.datum).sort()[0].split('-').map(Number);
        y = ry; m = rm - 1;
    }
    cal.dataset.y = y;
    cal.dataset.m = m;
    return { y, m };
}

function wizCalGoMonth(y, m) {
    const cal = document.getElementById('wizDaysCal');
    if (!cal) return;
    const d = new Date(y, m, 1);
    cal.dataset.y = d.getFullYear();
    cal.dataset.m = d.getMonth();
    wizRenderDaysCal();
}

function wizCalGo(delta) {
    const cal = document.getElementById('wizDaysCal');
    if (!cal) return;
    const { y, m } = _beCalView(cal, _beCalVisibleRows());
    wizCalGoMonth(y, m + delta);
}

function _beCalChipHtml(i) {
    const r = _beCalRows[i];
    const st = _beCalStatus(r);
    const { menge, mixM, mixW } = wizDayMengeInfo(r);
    const von = r.dataset.von || '–';
    const bis = r.dataset.bis || '–';
    const tip = `${von}–${bis} · ${menge} Person(en)${mixM || mixW ? ` (M ${mixM} / W ${mixW})` : ''} · ${BE_CAL_STATUS[st].label}`;
    return `<button type="button" class="be-cal-chip st-${st}" onclick="event.stopPropagation(); wizCalOpenDay('${r.dataset.datum}', ${i})" title="${escapeHtml(tip)}">
        <span class="be-cal-chip-time">${escapeHtml(von)}–${escapeHtml(bis)}${beIsOvernight(r.dataset.von, r.dataset.bis) ? '<sup>+1</sup>' : ''}</span>
        <span class="be-cal-chip-qty">${beIcon('group', 'font-size:12px')}${menge}</span>
    </button>`;
}

function wizRenderDaysCal() {
    const cal = document.getElementById('wizDaysCal');
    const tbody = document.getElementById('wizDaysBody');
    if (!cal || !tbody) return;
    _beCalEnsureStyle();
    if (!tbody._beCalMO) {
        tbody._beCalMO = new MutationObserver(() => {
            if (tbody._beCalRaf) return;
            tbody._beCalRaf = requestAnimationFrame(() => { tbody._beCalRaf = 0; wizRenderDaysCal(); });
        });
        tbody._beCalMO.observe(tbody, { childList: true, subtree: true, attributes: true });
        const syncPeriod = () => {
            const y = parseInt(document.getElementById('wiz_jahr')?.value);
            const m = parseInt(document.getElementById('wiz_monat')?.value);
            if (y && m) wizCalGoMonth(y, m - 1);
        };
        ['wiz_monat', 'wiz_jahr'].forEach(id => document.getElementById(id)?.addEventListener('change', syncPeriod));
    }
    wizSyncDateBounds();
    const rows = _beCalVisibleRows();
    _beCalRows = rows;
    const { y, m } = _beCalView(cal, rows);
    const prefix = _beCalIso(y, m, 1).substring(0, 7);
    const byDate = {};
    rows.forEach((r, i) => (byDate[r.dataset.datum] = byDate[r.dataset.datum] || []).push(i));
    Object.values(byDate).forEach(list => list.sort((a, b) => _beCalByTime(rows[a], rows[b])));
    const lastDay = new Date(y, m + 1, 0).getDate();
    const lead = (new Date(y, m, 1).getDay() + 6) % 7;
    const total = Math.ceil((lead + lastDay) / 7) * 7;
    const now = new Date();
    const todayIso = _beCalIso(now.getFullYear(), now.getMonth(), now.getDate());
    const sumStd = list => list.reduce((s, r) => s + (parseFloat(r.dataset.std) || 0) * (parseFloat(r.dataset.menge) || 1), 0);
    const monthRows = rows.filter(r => r.dataset.datum.startsWith(prefix));

    let cells = '';
    let agenda = '';
    for (let i = 0; i < total; i++) {
        const d = i - lead + 1;
        if (d < 1 || d > lastDay) { cells += '<div class="be-cal-day is-out"></div>'; continue; }
        const iso = _beCalIso(y, m, d);
        const list = byDate[iso] || [];
        const addable = !_beCalReadOnly && wizDateInOrder(iso);
        const cls = ['be-cal-day', i % 7 >= 5 ? 'is-we' : '', iso === todayIso ? 'is-today' : '', list.length ? 'has-items' : '', !addable && !list.length && !_beCalReadOnly ? 'is-off' : ''].join(' ');
        const more = list.length > BE_CAL_DAY_MAX ? `<span class="be-cal-more">+${list.length - BE_CAL_DAY_MAX} weitere</span>` : '';
        cells += `<div class="${cls}" ${list.length || addable ? `onclick="wizCalOpenDay('${iso}')" role="button" tabindex="0"` : ''} title="${list.length ? list.length + ' Schicht(en) — Details anzeigen' : (addable ? 'Schicht an diesem Tag hinzufügen' : (_beCalReadOnly ? '' : 'Außerhalb des Bestellmonats'))}">
            <div class="be-cal-day-head"><span class="be-cal-num">${d}</span>${list.length ? `<span class="be-cal-count">${list.length}</span>` : (addable ? `<span class="be-cal-add">${beIcon('add', 'font-size:15px')}</span>` : '')}</div>
            <div class="be-cal-list">${list.slice(0, BE_CAL_DAY_MAX).map(_beCalChipHtml).join('')}${more}</div>
        </div>`;
        if (list.length) {
            const wd = new Date(y, m, d).toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' });
            agenda += `<div class="be-cal-agenda-day ${iso === todayIso ? 'is-today' : ''}" onclick="wizCalOpenDay('${iso}')">
                <div class="be-cal-agenda-date">${wd}<span class="be-cal-count">${list.length}</span></div>
                <div class="be-cal-agenda-list">${list.map(_beCalChipHtml).join('')}</div>
            </div>`;
        }
    }

    const months = [...new Set(rows.map(r => r.dataset.datum.substring(0, 7)))].sort();
    const monthPills = (months.length > 1 || (months.length && !months.includes(prefix)))
        ? `<div class="be-cal-months">${months.map(p => {
            const [py, pm] = p.split('-').map(Number);
            const n = rows.filter(r => r.dataset.datum.startsWith(p)).length;
            return `<button type="button" class="be-cal-month-pill ${p === prefix ? 'active' : ''}" onclick="wizCalGoMonth(${py}, ${pm - 1})">${MONTHS_DE[pm].substring(0, 3)} ${py}<span>${n}</span></button>`;
        }).join('')}</div>`
        : '';

    cal.innerHTML = `
        <div class="be-cal-head">
            <md-icon-button onclick="wizCalGo(-1)" title="Vorheriger Monat" aria-label="Vorheriger Monat"><md-icon slot="icon">chevron_left</md-icon></md-icon-button>
            <div class="be-cal-title">${MONTHS_DE[m + 1]} ${y}</div>
            <md-icon-button onclick="wizCalGo(1)" title="Nächster Monat" aria-label="Nächster Monat"><md-icon slot="icon">chevron_right</md-icon></md-icon-button>
            <div class="be-cal-sum">
                <span>${beIcon('event_available', 'font-size:15px')}<strong>${monthRows.length}</strong> ${monthRows.length === 1 ? 'Schicht' : 'Schichten'}</span>
                <span title="Summe Std × Menge">${beIcon('schedule', 'font-size:15px')}<strong>${_beCalFmtStd(sumStd(monthRows))}</strong> Std</span>
                ${months.length > 1 ? `<span class="be-cal-sum-all">Gesamt: ${rows.length} Schichten · ${_beCalFmtStd(sumStd(rows))} Std</span>` : ''}
            </div>
        </div>
        ${monthPills}
        <div class="be-cal-month">
            <div class="be-cal-wd">${BE_CAL_WD.map((w, i) => `<div class="${i >= 5 ? 'is-we' : ''}">${w}</div>`).join('')}</div>
            <div class="be-cal-grid">${cells}</div>
        </div>
        <div class="be-cal-agenda">${agenda || `<div class="be-cal-empty">${beIcon('event_busy')}Keine Schichten in ${MONTHS_DE[m + 1]} ${y}</div>`}</div>
        <div class="be-cal-legend">
            ${['open', 'planned', 'confirmed'].map(k => `<span class="st-${k}"><i></i>${BE_CAL_STATUS[k].label}</span>`).join('')}
            ${_beCalReadOnly ? '' : '<span class="be-cal-legend-hint">Leeren Tag anklicken = Schicht hinzufügen</span>'}
        </div>`;

    if (_beCalPop && _beCalPop.isConnected && !_beCalPop.querySelector('.be-cal-edit')) _beCalRenderPop();
}

function wizCalOpenDay(iso, idx) {
    _beCalPopIso = iso;
    _beCalPopFocus = (idx !== undefined && _beCalRows[idx]) || null;
    _beCalPopOnly = (idx !== undefined) ? (_beCalRows[idx] || null) : null;
    _beCalOpenPop();
    _beCalRenderPop();
}

function _beCalOpenPop() {
    if (_beCalPop && _beCalPop.isConnected) return;
    const pop = document.createElement('div');
    pop.className = 'be-cal-pop';
    const canPop = typeof pop.showPopover === 'function';
    if (canPop) pop.setAttribute('popover', 'manual');
    (document.getElementById('beDialogBody') || document.body).appendChild(pop);
    if (canPop) pop.showPopover();
    pop.addEventListener('click', _beCalPopClick);
    pop.addEventListener('input', _beCalPopInput);
    _beCalPop = pop;
    document.addEventListener('keydown', _beCalKey, true);
}

function wizCalClose() {
    document.removeEventListener('keydown', _beCalKey, true);
    beKlClose();
    if (_beCalPop) _beCalPop.remove();
    _beCalPop = null;
    _beCalPopFocus = null;
    _beCalPopOnly = null;
}

function _beCalKey(e) {
    if (!_beCalPop || !_beCalPop.isConnected) { wizCalClose(); return; }
    if (e.key !== 'Escape') return;
    e.preventDefault();
    e.stopPropagation();
    // A floating catalog picker (Qualifikation/Kleidung) gets Escape first —
    // the sheet only closes when nothing is layered above it.
    if (typeof _beKlPop !== 'undefined' && _beKlPop) { beKlClose(); return; }
    wizCalClose();
}

// Field-wise compare of two Anforderungen objects — the key order differs
// between a collected template object and a merged per-day object, so a plain
// JSON.stringify comparison would produce false "different" results.
function _beCalSameAnf(a, b) {
    const A = a || {}, B = b || {};
    for (const k of ['qualifikation', 'kleidung', 'skill']) {
        if ((A[k] || '') !== (B[k] || '')) return false;
    }
    return JSON.stringify(A.geschlecht_mix || null) === JSON.stringify(B.geschlecht_mix || null)
        && JSON.stringify(A.positionen || null) === JSON.stringify(B.positionen || null);
}

function _beCalFact(icon, label, value, wide = false) {
    return `<div class="be-cal-fact${wide ? ' wide' : ''}">${beIcon(icon, 'font-size:17px')}<div><span>${label}</span><strong>${value}</strong></div></div>`;
}

function _beCalCardHtml(r, n) {
    const st = _beCalStatus(r);
    const { menge, mixM, mixW, mixMismatch } = wizDayMengeInfo(r);
    const anf = wizParseAnf(r.dataset.anforderungen) || wizCollectAnforderungen() || {};
    const anfOver = r.dataset.anfOverride === '1';
    const von = r.dataset.von || '';
    const bis = r.dataset.bis || '';
    const pause = parseInt(r.dataset.pauseMinuten) || 0;
    const gp = parseInt(r.dataset.gesetzlPause) || 0;
    const std = parseFloat(r.dataset.std);
    const headerOrt = String(document.getElementById('wiz_shift_ort')?.value ?? '').trim();
    const ort = (r.dataset.ort || '').trim();
    const arts = _beState.selArts || [];
    const art = arts.length > 1 ? arts.find(a => String(a.artikel_id) === String(r.dataset.artikel || arts[0].artikel_id)) : null;
    const dow = new Date(r.dataset.datum + 'T00:00:00').getDay();
    const pauseTxt = (st !== 'open' && gp !== pause)
        ? `${pause} Min <small>(Kunde ${gp} Min)</small>`
        : (pause > 0 ? `${pause} Min` : 'keine');
    const mix = (mixM || mixW) ? ` · <span class="be-cal-m">M ${mixM}</span> <span class="be-cal-w">W ${mixW}</span>` : ' <small>(M/W egal)</small>';
    const ortTxt = ort
        ? `${escapeHtml(ort)}${ort !== headerOrt && !_beCalReadOnly ? ' <small>· Tag-Ort</small>' : ''}`
        : (headerOrt ? `${escapeHtml(headerOrt)} <small>· Standard</small>` : '–');
    const pos = Array.isArray(anf.positionen) ? anf.positionen : [];
    const posHtml = pos.length
        ? `<ul class="be-cal-pos">${pos.map(p => `<li>${p.anzahl ? `<strong>${escapeHtml(String(p.anzahl))}×</strong> ` : ''}${escapeHtml([p.funktion, geschlechtLabelText(p.geschlecht), p.qualifikation, p.kleidung].filter(Boolean).join(' · ')) || '–'}</li>`).join('')}</ul>`
        : '';
    const facts = [
        _beCalFact('calendar_today', 'Wochentag', DAYS_DE[dow]),
        _beCalFact('schedule', 'Zeit', `${escapeHtml(von || '–')} – ${escapeHtml(bis || '–')}${beIsOvernight(von, bis) ? ' <small>(+1 Tag)</small>' : ''}`),
        _beCalFact('timelapse', 'Stunden', isNaN(std) ? '–' : `${_beCalFmtStd(std)} Std`),
        _beCalFact('free_breakfast', 'Pause', pauseTxt),
        _beCalFact('group', 'Personen', `<span${mixMismatch ? ' style="color: var(--be-danger,var(--md-sys-color-error))"' : ''}>${menge}</span>${mix}`),
        _beCalFact('location_on', 'Ort / Treffpunkt', ortTxt, true),
        anf.qualifikation ? _beCalFact('workspace_premium', anfOver ? 'Qualifikation · Tag' : 'Qualifikation', escapeHtml(anf.qualifikation)) : '',
        anf.kleidung ? _beCalFact('apparel', anfOver ? 'Kleidung · Tag' : 'Kleidung', escapeHtml(anf.kleidung)) : '',
        anf.skill ? _beCalFact('star', anfOver ? 'Skill / Sprache · Tag' : 'Skill / Sprache', escapeHtml(anf.skill)) : '',
        posHtml ? _beCalFact('format_list_numbered', 'Positionen', posHtml, true) : '',
        r.dataset.beschreibung ? _beCalFact('description', 'Beschreibung', `<span class="be-cal-desc">${escapeHtml(r.dataset.beschreibung)}</span>`, true) : '',
    ].join('');
    let actions;
    if (_beCalReadOnly) {
        const planBtn = (r.dataset.einsatzId && beCanPlanung())
            ? `<md-outlined-button data-act="planung" data-n="${n}"><md-icon slot="icon">calendar_month</md-icon>In der Planung öffnen</md-outlined-button>` : '';
        actions = planBtn + (st === 'open' ? ''
            : `<span class="be-cal-lockinfo">${beIcon('lock', 'font-size:15px')} ${st === 'confirmed' ? 'Einsatz bestätigt — nicht änderbar' : (st === 'scope' ? 'Leistung außerhalb Ihres Bereichs — schreibgeschützt' : 'In der Planung eingeplant')}</span>`);
    } else if (st === 'open') {
        actions = `<md-filled-button data-act="edit" data-n="${n}"><md-icon slot="icon">edit</md-icon>Bearbeiten</md-filled-button>
            <md-outlined-button data-act="dup" data-n="${n}"><md-icon slot="icon">content_copy</md-icon>2. Schicht</md-outlined-button>
            <md-text-button class="md-btn-danger" data-act="del" data-n="${n}"><md-icon slot="icon">delete</md-icon>Entfernen</md-text-button>`;
    } else if (st === 'scope') {
        actions = `<span class="be-cal-lockinfo">${beIcon('lock', 'font-size:15px')} Leistung außerhalb Ihres Bereichs — schreibgeschützt</span>`;
    } else {
        actions = `<md-outlined-button data-act="dup" data-n="${n}"><md-icon slot="icon">content_copy</md-icon>2. Schicht</md-outlined-button>
            <span class="be-cal-lockinfo">${beIcon('lock', 'font-size:15px')} ${st === 'confirmed' ? 'Einsatz bestätigt — nicht änderbar' : 'In der Planung eingeplant — erst „Plan aufheben“, dann änderbar'}</span>`;
    }
    return `<div class="be-cal-card st-${st}${r === _beCalPopFocus ? ' is-focus' : ''}" data-n="${n}">
        <div class="be-cal-card-head">
            <span class="be-cal-card-time">${escapeHtml(von || '–')} – ${escapeHtml(bis || '–')}</span>
            <span class="be-cal-badge">${beIcon(BE_CAL_STATUS[st].icon, 'font-size:13px')} ${BE_CAL_STATUS[st].label}</span>
            ${art ? `<span class="be-cal-art">${beIcon('sell', 'font-size:12px')} ${escapeHtml(art.artikel_name || art.artikel_id)}</span>` : ''}
        </div>
        <div class="be-cal-card-main"><div class="be-cal-facts">${facts}</div><div class="be-cal-actions">${actions}</div></div>
    </div>`;
}

function _beCalEditHtml(r, n) {
    const { menge, mixM, mixW } = wizDayMengeInfo(r);
    // Effective requirements: the day's own override, else the Vorlage — so
    // the fields never look empty while values are still inherited.
    const anfEff = wizParseAnf(r.dataset.anforderungen) || wizCollectAnforderungen() || {};
    const pos = Array.isArray(anfEff.positionen) ? anfEff.positionen : [];
    const posTxt = pos.map(p => `${p.anzahl ? p.anzahl + '× ' : ''}${escapeHtml([p.funktion, geschlechtLabelText(p.geschlecht), p.qualifikation].filter(Boolean).join(' · ')) || '–'}`).join(', ');
    const val = v => escapeHtml(String(v ?? ''));
    return `<div class="be-cal-edit" data-n="${n}">
        <label>Datum<md-outlined-text-field type="date" class="form-control" data-f="datum" value="${val(r.dataset.datum)}" min="${val(document.getElementById('wiz_single_date')?.min)}" max="${val(document.getElementById('wiz_single_date')?.max)}"></md-outlined-text-field></label>
        <label>Von<md-outlined-text-field type="text" class="form-control t24" inputmode="numeric" maxlength="5" placeholder="HH:MM" data-f="von" value="${val(r.dataset.von)}"></md-outlined-text-field></label>
        <label>Bis<md-outlined-text-field type="text" class="form-control t24" inputmode="numeric" maxlength="5" placeholder="HH:MM" data-f="bis" value="${val(r.dataset.bis)}"></md-outlined-text-field></label>
        <label>Pause (Min)<md-outlined-text-field type="number" class="form-control" min="0" data-f="pause" value="${parseInt(r.dataset.pauseMinuten) || 0}"></md-outlined-text-field></label>
        <label>Menge<md-outlined-text-field type="number" class="form-control" min="1" data-f="menge" value="${menge}"></md-outlined-text-field></label>
        <label class="m">M Männer<md-outlined-text-field type="number" class="form-control" min="0" data-f="m" value="${mixM}"></md-outlined-text-field></label>
        <label class="w">W Frauen<md-outlined-text-field type="number" class="form-control" min="0" data-f="w" value="${mixW}"></md-outlined-text-field></label>
        <label></label>
        <label class="wide">Tag-Ort<md-outlined-text-field type="text" class="form-control be-day-ort-edit" data-f="ort" autocomplete="off" value="${val(r.dataset.ort)}" placeholder="leer = Ort / Treffpunkt der Vorlage" oninput="beOrtInput(event)" onfocusout="beOrtHide(event)"></md-outlined-text-field><div class="be-ort-suggest be-day-ort-suggest" style="display:none"></div></label>
        <div class="be-cal-anf">
            <label>Qualifikation<md-outlined-text-field type="text" class="form-control anf-qual" data-f="qual" value="${val(anfEff.qualifikation)}" placeholder="wie Vorlage"></md-outlined-text-field></label>
            <label>Kleidung<md-outlined-text-field type="text" class="form-control anf-kleidung" data-f="kleid" value="${val(anfEff.kleidung)}" placeholder="wie Vorlage"></md-outlined-text-field></label>
            <label>Skill / Sprache<md-outlined-text-field type="text" class="form-control" data-f="skill" value="${val(anfEff.skill)}" placeholder="wie Vorlage"></md-outlined-text-field></label>
            ${pos.length ? `<div class="be-cal-edit-pos">${beIcon('format_list_numbered', 'font-size:13px;vertical-align:-2px')} Positionen (${pos.length}) kommen aus der Vorlage: ${posTxt}</div>` : ''}
        </div>
        <div class="be-cal-actions">
            <md-filled-button data-act="save" data-n="${n}"><md-icon slot="icon">check</md-icon>Übernehmen</md-filled-button>
            <md-outlined-button data-act="cancel" data-n="${n}">Abbrechen</md-outlined-button>
        </div>
    </div>`;
}

function _beCalRenderPop() {
    if (!_beCalPop) return;
    const iso = _beCalPopIso;
    const allRows = _beCalVisibleRows().filter(r => r.dataset.datum === iso).sort(_beCalByTime);
    // Clicked a specific Schicht chip → only that card (with a way back to
    // the whole day); day cell / alle anzeigen → the full list. A stale
    // only-row (deleted or moved) falls back to the day view.
    if (_beCalPopOnly && !allRows.includes(_beCalPopOnly)) _beCalPopOnly = null;
    const rows = _beCalPopOnly ? [_beCalPopOnly] : allRows;
    const onlyN = _beCalPopOnly ? allRows.indexOf(_beCalPopOnly) + 1 : 0;
    const prevScroll = _beCalPop.querySelector('.be-cal-sheet-body')?.scrollTop || 0;
    _beCalPop._rows = rows;
    const title = new Date(iso + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    const tVon = document.getElementById('wiz_shift_von')?.value || '–';
    const tBis = document.getElementById('wiz_shift_bis')?.value || '–';
    const tPause = parseInt(document.getElementById('wiz_shift_pause')?.value) || 0;
    _beCalPop.innerHTML = `<div class="be-cal-sheet" role="dialog" aria-label="${escapeHtml(title)}">
        <div class="be-cal-sheet-head">
            <div>
                <div class="be-cal-sheet-title">${escapeHtml(title)}</div>
                <div class="be-cal-sheet-sub">${_beCalPopOnly
                    ? `Schicht ${onlyN} von ${allRows.length} · jede Schicht ist ein Einsatz in der Planung · <md-text-button data-act="all">alle ${allRows.length} anzeigen</md-text-button>`
                    : (rows.length ? `${rows.length} ${rows.length === 1 ? 'Schicht' : 'Schichten'} · jede Schicht ist ein Einsatz in der Planung` : 'Noch keine Schicht an diesem Tag')}</div>
            </div>
            <md-icon-button data-act="close" title="Schließen" aria-label="Schließen"><md-icon slot="icon">close</md-icon></md-icon-button>
        </div>
        <div class="be-cal-sheet-body">${rows.length
            ? rows.map((r, n) => _beCalCardHtml(r, n)).join('')
            : `<div class="be-cal-empty">${beIcon('event_busy', 'font-size:28px')}${_beCalReadOnly ? 'Keine Schicht an diesem Tag.' : 'Fügen Sie eine Schicht mit den Werten der Schicht-Vorlage hinzu.'}</div>`}</div>
        ${_beCalReadOnly ? '' : `<div class="be-cal-sheet-foot">
            ${wizDateInOrder(iso)
                ? `<md-filled-button data-act="add"><md-icon slot="icon">add</md-icon>Schicht hinzufügen</md-filled-button>
            <span>Vorlage: ${escapeHtml(tVon)}–${escapeHtml(tBis)} · Pause ${tPause} Min</span>`
                : `<span>${beIcon('block', 'font-size:15px;vertical-align:-3px')} Außerhalb des Bestellmonats ${escapeHtml(wizOrderMonthLabel())} — hier können keine neuen Schichten angelegt werden.</span>`}
        </div>`}
    </div>`;
    const body = _beCalPop.querySelector('.be-cal-sheet-body');
    if (body) body.scrollTop = prevScroll;
    const focus = _beCalPop.querySelector('.be-cal-card.is-focus');
    if (focus && !prevScroll) focus.scrollIntoView({ block: 'nearest' });
}

function _beCalPopInput(e) {
    const f = e.target.dataset?.f;
    const form = e.target.closest('.be-cal-edit');
    if (!form || !f) return;
    if (f === 'pause') { form._pauseTouched = true; return; }
    if ((f === 'von' || f === 'bis') && !form._pauseTouched) {
        const p = beAutoPauseMin(form.querySelector('[data-f="von"]').value, form.querySelector('[data-f="bis"]').value);
        if (p !== null) form.querySelector('[data-f="pause"]').value = p;
    }
}

async function _beCalPopClick(e) {
    if (e.target === _beCalPop) { wizCalClose(); return; }
    const btn = e.target.closest('[data-act]');
    if (!btn || !_beCalPop) return;
    const act = btn.dataset.act;
    const row = (_beCalPop._rows || [])[parseInt(btn.dataset.n)];
    const rowBtn = (fn) => row ? row.querySelector(`button[onclick^="${fn}"]`) : null;
    if (act === 'close') return wizCalClose();
    if (act === 'all') { _beCalPopOnly = null; _beCalRenderPop(); return; }
    if (act === 'add') return _beCalAddShift(_beCalPopIso);
    if (!row) return;
    if (act === 'planung') {
        const id = row.dataset.einsatzId, datum = row.dataset.datum;
        wizCalClose();
        return beOpenInPlanung({ einsatzId: id, datum });
    }
    if (act === 'edit') {
        const card = _beCalPop.querySelector(`.be-cal-card[data-n="${btn.dataset.n}"] .be-cal-card-main`);
        if (card) card.innerHTML = _beCalEditHtml(row, btn.dataset.n);
        card?.querySelector('[data-f="von"]')?.focus();
    } else if (act === 'cancel') {
        _beCalRenderPop();
    } else if (act === 'save') {
        await _beCalSaveEdit(row, btn.closest('.be-cal-edit'));
    } else if (act === 'dup') {
        const b = rowBtn('wizDuplicateDay');
        if (!b) return;
        wizDuplicateDay(b);
        _beCalPopFocus = null;
        _beCalPopOnly = null;
        _beCalRenderPop();
    } else if (act === 'del') {
        const b = rowBtn('wizRemoveDay');
        if (!b) return;
        await wizRemoveDay(b);
        _beCalRenderPop();
    }
}

async function _beCalSaveEdit(row, form) {
    if (!form) return;
    const v = f => form.querySelector(`[data-f="${f}"]`)?.value ?? '';
    const newDatum = v('datum');
    if (newDatum && newDatum !== row.dataset.datum && !wizDateInOrder(newDatum)) {
        showToast(`${formatDate(newDatum)} liegt außerhalb des Bestellmonats ${wizOrderMonthLabel()}`, 'error');
        return;
    }
    const editBtn = row.querySelector('button[onclick^="wizEditDay"]');
    if (!editBtn) return;
    await wizEditDay(editBtn);
    if (!row.classList.contains('be-day-editing')) return;
    const cells = row.querySelectorAll('td');
    const set = (el, value) => { if (el) el.value = value; };
    set(cells[1]?.querySelector('input, md-outlined-text-field'), v('datum'));
    set(cells[3]?.querySelector('input, md-outlined-text-field'), v('von').trim());
    set(cells[4]?.querySelector('input, md-outlined-text-field'), v('bis').trim());
    set(cells[6]?.querySelector('input, md-outlined-text-field'), v('pause'));
    set(cells[7]?.querySelector('input, md-outlined-text-field'), v('pause'));
    set(cells[8]?.querySelector('.be-day-menge'), v('menge'));
    set(cells[8]?.querySelector('.be-day-mix-m'), v('m'));
    set(cells[8]?.querySelector('.be-day-mix-w'), v('w'));
    set(cells[9]?.querySelector('.be-day-ort-edit'), v('ort').trim());
    // Per-day Anforderungen: merge the edited fields into the day's effective
    // object (own override or the Vorlage) — a partial edit never wipes the
    // rest (Positionen, Geschlecht-Mix). Result == Vorlage → the override flag
    // is dropped and the day follows the template again.
    const prevAnf = wizParseAnf(row.dataset.anforderungen) || wizCollectAnforderungen() || {};
    const nextAnf = { ...prevAnf };
    [['qualifikation', v('qual')], ['kleidung', v('kleid')], ['skill', v('skill')]].forEach(([kf, kv]) => {
        const t = String(kv || '').trim();
        if (t) nextAnf[kf] = t; else delete nextAnf[kf];
    });
    if (Object.keys(nextAnf).length) {
        row.dataset.anforderungen = JSON.stringify(nextAnf);
        if (_beCalSameAnf(nextAnf, wizCollectAnforderungen())) delete row.dataset.anfOverride;
        else row.dataset.anfOverride = '1';
    } else {
        row.dataset.anforderungen = '';
        delete row.dataset.anfOverride;
    }
    if (form._pauseTouched) row._pauseTouched = true;
    if (!wizSaveDayEdit(row)) {
        const cancel = row.querySelector('button[onclick^="wizCancelDayEdit"]');
        if (cancel) wizCancelDayEdit(cancel);
        return;
    }
    _beCalPopIso = row.dataset.datum;
    _beCalPopFocus = row;
    const [ny, nm] = row.dataset.datum.split('-').map(Number);
    wizCalGoMonth(ny, nm - 1);
    _beCalRenderPop();
    showToast('Schicht übernommen — wird mit „Speichern“ gespeichert');
}

async function _beCalAddShift(iso) {
    const inp = document.getElementById('wiz_single_date');
    const tbody = document.getElementById('wizDaysBody');
    if (!inp || !tbody || !iso) return;
    const before = new Set(tbody.querySelectorAll('tr'));
    const prev = inp.value;
    inp.value = inp.type === 'date' ? iso : formatDate(iso);
    await wizAddSingleDay();
    if (inp.value) inp.value = prev;
    const added = [...tbody.querySelectorAll('tr')].find(r => !before.has(r));
    if (!added) return;
    _beCalPopFocus = added;
    _beCalPopOnly = null;
    if (_beCalPop && _beCalPop.isConnected) _beCalRenderPop();
}

// Top horizontal scrollbar (mirror of the table's own): keep the dummy
// inner div as wide as the table so both scrollbars stay in sync.
function wizSyncTopScrollWidth() {
    const top = document.getElementById('wizDaysTopScroll');
    const inner = document.getElementById('wizDaysTopScrollInner');
    const cont = document.getElementById('wizDaysScroll');
    if (!top || !inner || !cont) return;
    inner.style.width = cont.scrollWidth + 'px';
    top.style.display = cont.scrollWidth > cont.clientWidth ? 'block' : 'none';
    wizUpdateDaysNav();
}

// Side arrows of the days table (same look as the Dispo arrows). They live
// INSIDE the table frame (absolute, not fixed — the dialog may be
// transformed) and stay vertically centred on the part of the table that
// is visible in the scrolling dialog body.
function wizDaysScrollStep(dir) {
    const cont = document.getElementById('wizDaysScroll');
    if (!cont) return;
    const max = cont.scrollWidth - cont.clientWidth;
    const step = Math.max(240, Math.round(cont.clientWidth * 0.6));
    cont.scrollTo({ left: Math.max(0, Math.min(max, cont.scrollLeft + dir * step)), behavior: 'smooth' });
}
function wizUpdateDaysNav() {
    const cont = document.getElementById('wizDaysScroll');
    const l = document.getElementById('wizDaysNavL');
    const r = document.getElementById('wizDaysNavR');
    if (!cont || !l || !r) return;
    if (!cont._navRO && typeof ResizeObserver !== 'undefined') {
        cont._navRO = new ResizeObserver(() => wizUpdateDaysNav());
        cont._navRO.observe(cont);
        const tbl = cont.querySelector('table');
        if (tbl) cont._navRO.observe(tbl);
    }
    const wrap = cont.closest('.be-days-table-wrap');
    const max = cont.scrollWidth - cont.clientWidth;
    if (!wrap || max <= 1 || !cont.offsetParent) { l.classList.add('is-hidden'); r.classList.add('is-hidden'); return; }
    // Nearest vertically scrolling ancestor = the dialog body. In <md-dialog>
    // that is the shadow-DOM `.scroller`, which a plain parentElement walk
    // never reaches — and its scroll events (not composed) never reach the
    // document listener, so listen on it directly.
    const vp = wizFindVScroller(wrap);
    if (vp && cont._navScroller !== vp) {
        if (cont._navScroller) cont._navScroller.removeEventListener('scroll', wizScheduleDaysNav);
        vp.addEventListener('scroll', wizScheduleDaysNav, { passive: true });
        cont._navScroller = vp;
    }
    const vr = vp ? vp.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
    const wr = wrap.getBoundingClientRect();
    const headH = (cont.querySelector('thead') || {}).offsetHeight || 0;
    const visTop = Math.max(wr.top + headH, vr.top);
    const visBottom = Math.min(wr.bottom, vr.bottom);
    const show = visBottom - visTop > 56;
    const y = Math.round((visTop + visBottom) / 2 - wr.top - 20);
    [l, r].forEach(b => { b.style.top = y + 'px'; });
    l.classList.toggle('is-hidden', !show || cont.scrollLeft <= 1);
    r.classList.toggle('is-hidden', !show || cont.scrollLeft >= max - 1);
}
function wizFindVScroller(el) {
    let n = el.parentElement;
    while (n && n !== document.body) {
        const oy = getComputedStyle(n).overflowY;
        if ((oy === 'auto' || oy === 'scroll') && n.scrollHeight > n.clientHeight) return n;
        if (n.tagName === 'MD-DIALOG' && n.shadowRoot) {
            const sc = n.shadowRoot.querySelector('.scroller');
            if (sc && sc.scrollHeight > sc.clientHeight) return sc;
        }
        n = n.parentElement || (n.getRootNode && n.getRootNode().host) || null;
    }
    return null;
}
let _wizDaysNavRaf = 0;
function wizScheduleDaysNav() {
    if (!_wizDaysNavRaf) _wizDaysNavRaf = requestAnimationFrame(() => { _wizDaysNavRaf = 0; wizUpdateDaysNav(); });
}
if (!window._wizDaysNavBound) {
    window._wizDaysNavBound = true;
    document.addEventListener('scroll', wizScheduleDaysNav, true);
    window.addEventListener('resize', wizScheduleDaysNav);
}

function wizTopScrollSync(src) {
    const top = document.getElementById('wizDaysTopScroll');
    const cont = document.getElementById('wizDaysScroll');
    if (!top || !cont) return;
    // Ignore the echo of our own write — writing it back would cancel a
    // running smooth scroll (side arrows) after a few pixels.
    if (window._wizScrollEcho === src) { window._wizScrollEcho = null; return; }
    const from = src === 'top' ? top : cont;
    const to = src === 'top' ? cont : top;
    if (Math.abs(to.scrollLeft - from.scrollLeft) < 1) return;
    window._wizScrollEcho = src === 'top' ? 'body' : 'top';
    to.scrollLeft = from.scrollLeft;
}

// Collect day rows for save.
//
// IMPORTANT: per-row values take precedence over the header. The header is only
// used as a fallback for rows that have not yet captured their own values
// (e.g. legacy rows). This allows a single Bestelleingang to contain rows with
// different shift times — a Tag with 08:00–20:00 next to a Tag with 20:00–08:00.
function wizCollectDays() {
    const tage = [];
    const rows = document.getElementById('wizDaysBody').querySelectorAll('tr');
    const headerVon = document.getElementById('wiz_shift_von')?.value || null;
    const headerBis = document.getElementById('wiz_shift_bis')?.value || null;
    const headerBeschreibung = document.getElementById('wiz_shift_beschreibung')?.value || null;
    const headerPause = parseInt(document.getElementById('wiz_shift_pause')?.value) || 0;
    const headerMenge = document.getElementById('wiz_shift_menge')?.value || null;
    const headerGesetzlPause = parseInt(document.getElementById('wizGesetzlPause')?.value) || 0;
    const headerAnf = wizCollectAnforderungen();

    rows.forEach((row, idx) => {
        const von = row.dataset.von || headerVon;
        const bis = row.dataset.bis || headerBis;
        const rowPauseRaw = row.dataset.pauseMinuten;
        let pause = (rowPauseRaw !== undefined && rowPauseRaw !== '') ? (parseInt(rowPauseRaw) || 0) : headerPause;
        const autoPause = beAutoPauseMin(von, bis);
        if (!row.dataset.pauseOverride && !wizHeaderPauseManual() && autoPause !== null) {
            pause = autoPause;
            row.dataset.pauseMinuten = autoPause;
        }
        const rowGpRaw = row.dataset.gesetzlPause;
        const gesetzlPause = !wizRowLocked(row) ? pause
            : ((rowGpRaw !== undefined && rowGpRaw !== '') ? (parseInt(rowGpRaw) || 0) : headerGesetzlPause);

        let sollStd = parseFloat(row.dataset.std) || null;
        if (von && bis) {
            const mins = beShiftNetMinutes(von, bis, pause);
            sollStd = parseFloat((mins / 60).toFixed(2));
        }
        tage.push({
            tag_nr: idx + 1,
            // Stable key between this day row and its Einsatz in Dispo —
            // kept across saves (new rows get one from the server).
            tag_uid: row.dataset.tagUid || null,
            datum: (row.dataset.datum || '').substring(0, 10),
            wochentag: row.dataset.wochentag || null,
            zeit_von: von || null,
            zeit_bis: bis || null,
            soll_std: sollStd,
            pause_minuten: pause || null,
            gesetzl_pause: gesetzlPause || null,
            menge: row.dataset.menge || headerMenge || null,
            artikel_id: row.dataset.artikel || (_beState.selArt?.artikel_id) || null,
            beschreibung: row.dataset.beschreibung || headerBeschreibung || null,
            // Tag-Ort only — the header Ort is a default for NEW days, never
            // a fallback at save time (it must not leak into Dispo).
            ort: row.dataset.ort || null,
            anforderungen: (() => {
                let a = (row.dataset.anforderungen ? wizParseAnf(row.dataset.anforderungen) : null) || headerAnf || null;
                // Per-day Geschlecht-Mix override wins over the template mix so
                // Dispo sees the exact M/W split for THIS day.
                const mixOverride = wizParseAnf(row.dataset.geschlechtMix);
                if (mixOverride) {
                    a = a ? { ...a } : {};
                    if ((parseInt(mixOverride.maennlich) || 0) > 0 || (parseInt(mixOverride.weiblich) || 0) > 0) {
                        a.geschlecht_mix = mixOverride;
                    } else {
                        delete a.geschlecht_mix;
                    }
                    if (Object.keys(a).length === 0) a = null;
                }
                return a;
            })(),
            bemerkung: null
        });
    });
    return tage;
}

// ============================================================
// WIZARD — Step 5 Anforderungen (structured staffing requirements)
// ============================================================
// Parse a stored anforderungen value (JSON string or object) into an object,
// or null when empty/invalid. Used wherever we hydrate a row/template.
function wizParseAnf(raw) {
    if (!raw) return null;
    if (typeof raw === 'object') return raw;
    try { const o = JSON.parse(raw); return (o && typeof o === 'object') ? o : null; }
    catch (e) { return null; }
}

// Read the Anforderungen UI (general fields + Positionen rows) into a clean
// object, or null when nothing is filled. This is the template applied to all
// generated days and the per-row fallback in wizCollectDays.
function wizCollectAnforderungen() {
    const val = (id) => String(document.getElementById(id)?.value ?? '').trim();
    const q = val('wiz_anf_qualifikation');
    const k = val('wiz_anf_kleidung');
    const s = val('wiz_anf_skill');
    const gm = parseInt(val('wiz_anf_m')) || 0;
    const gw = parseInt(val('wiz_anf_w')) || 0;
    const positionen = [];
    document.querySelectorAll('#wizPositionsBody .be-anf-pos-row').forEach(r => {
        const anzahl = parseInt(r.querySelector('.anf-anzahl')?.value) || 0;
        const funktion = String(r.querySelector('.anf-funktion')?.value ?? '').trim();
        const geschlecht = String(r.querySelector('.anf-geschlecht')?.value ?? '').trim();
        const qual = String(r.querySelector('.anf-qual')?.value ?? '').trim();
        const kleidung = String(r.querySelector('.anf-kleidung')?.value ?? '').trim();
        if (anzahl > 0 || funktion || geschlecht || qual || kleidung) {
            positionen.push({ anzahl: anzahl || null, funktion, geschlecht: geschlecht || null, qualifikation: qual, kleidung });
        }
    });
    if (!q && !k && !s && gm === 0 && gw === 0 && positionen.length === 0) return null;
    const anf = {};
    if (q) anf.qualifikation = q;
    if (k) anf.kleidung = k;
    if (s) anf.skill = s;
    if (gm > 0 || gw > 0) anf.geschlecht_mix = { maennlich: gm || 0, weiblich: gw || 0 };
    if (positionen.length) anf.positionen = positionen;
    return anf;
}

// Append one Positionen row to the template; optionally pre-filled (edit mode).
function wizAddPosition(values) {
    const body = document.getElementById('wizPositionsBody');
    if (!body) return;
    const v = values || {};
    const row = document.createElement('div');
    row.className = 'be-anf-pos-row';
    row.innerHTML = `
        <md-outlined-text-field type="number" class="form-control anf-anzahl" min="1" placeholder="Anz." value="${v.anzahl != null ? v.anzahl : ''}" onchange="wizApplyMetaToRows()"></md-outlined-text-field>
        <md-outlined-text-field type="text" class="form-control anf-funktion" placeholder="z.B. Checker" value="${escapeHtml(v.funktion || '')}" onchange="wizApplyMetaToRows()"></md-outlined-text-field>
        <md-outlined-select class="form-control anf-geschlecht" onchange="wizApplyMetaToRows()">
            <md-select-option value="">Egal</md-select-option>
            <md-select-option value="maennlich" ${v.geschlecht === 'maennlich' ? 'selected' : ''}>Männlich</md-select-option>
            <md-select-option value="weiblich" ${v.geschlecht === 'weiblich' ? 'selected' : ''}>Weiblich</md-select-option>
        </md-outlined-select>
        <md-outlined-text-field type="text" class="form-control anf-qual" placeholder="z.B. Erste Hilfe, Staplerschein" value="${escapeHtml(v.qualifikation || '')}" onchange="wizApplyMetaToRows()"></md-outlined-text-field>
        <md-outlined-text-field type="text" class="form-control anf-kleidung" placeholder="z.B. schwarze Hose" value="${escapeHtml(v.kleidung || '')}" onchange="wizApplyMetaToRows()"></md-outlined-text-field>
        <md-icon-button onclick="wizRemovePosition(this)" title="Entfernen"><md-icon slot="icon">close</md-icon></md-icon-button>
    `;
    body.appendChild(row);
}

function wizRemovePosition(btn) {
    btn.closest('.be-anf-pos-row')?.remove();
    wizApplyMetaToRows();
}

// Fill the Anforderungen UI from a stored object (edit mode).
function wizHydrateAnforderungen(anf) {
    const body = document.getElementById('wizPositionsBody');
    if (body) body.innerHTML = '';
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
    setVal('wiz_anf_qualifikation', anf && anf.qualifikation);
    setVal('wiz_anf_kleidung', anf && anf.kleidung);
    setVal('wiz_anf_skill', anf && anf.skill);
    const mix = (anf && anf.geschlecht_mix) || null;
    setVal('wiz_anf_m', mix && mix.maennlich > 0 ? mix.maennlich : '');
    setVal('wiz_anf_w', mix && mix.weiblich > 0 ? mix.weiblich : '');
    if (anf && Array.isArray(anf.positionen)) anf.positionen.forEach(p => wizAddPosition(p));
    wizVorlageCheck();
}

/* ── Katalog picker (Konto & Firma → Bereiche): Dienstkleidung for the
 * Kleidung fields, Qualifikationen & Nachweise for the Qualifikation fields.
 * Floating multi-select under the field. The stored value stays the same
 * comma-separated text, so Dispo / NU-Portal read it unchanged and free
 * text keeps working. */
const _BE_KL_FIELDS = '#wiz_anf_kleidung, .anf-kleidung, #wiz_anf_qualifikation, .anf-qual';
// Catalog list key of the field: 'quals' (Qualifikation) or 'kleidung'.
const _beKlKind = (el) => (el && el.matches('#wiz_anf_qualifikation, .anf-qual')) ? 'quals' : 'kleidung';
let _beKlCache = null, _beKlAt = 0, _beKlInput = null, _beKlPop = null, _beKlScroller = null;

async function _beKlGroups(kind) {
    if (!_beKlCache || Date.now() - _beKlAt > 60000) {
        _beKlAt = Date.now();
        _beKlCache = apiGet(API.bereich_catalog).catch(() => null);
    }
    const data = await _beKlCache;
    const pref = (document.getElementById('wiz_bereich')?.value || '').toLowerCase();
    const groups = [];
    for (const m of ((data && data.mains) || [])) {
        if (!m.aktiv) continue;
        for (const l of (m[kind] || [])) {
            const opts = (l.options || []).filter(o => o.aktiv).map(o => o.name);
            if (l.aktiv && opts.length) groups.push({ bereich: m.name, farbe: m.farbe, list: l.name, opts, pref: m.name.toLowerCase() === pref });
        }
    }
    return groups.sort((a, b) => b.pref - a.pref);
}

const _beKlTokens = (v) => String(v || '').split(',').map(s => s.trim()).filter(Boolean);

function _beKlEnsureStyle() {
    if (document.getElementById('beKlStyle')) return;
    const st = document.createElement('style');
    st.id = 'beKlStyle';
    st.textContent = `
        .be-kl-pop{position:fixed;z-index:100000;inset:auto;margin:0;background:var(--white,#fff);color:var(--gray-800,#1f2937);border:1px solid var(--gray-200,#e5e7eb);border-radius:10px;box-shadow:0 12px 32px rgba(15,23,42,.18);max-height:300px;overflow:auto;padding:6px;font-size:.85rem}
        .be-kl-grp{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--gray-500,#6b7280);padding:8px 8px 4px;display:flex;align-items:center;gap:6px;pointer-events:none}
        .be-kl-grp i{width:8px;height:8px;border-radius:50%;display:inline-block}
        .be-kl-grp span{font-weight:400;text-transform:none;letter-spacing:0}
        .be-kl-opt{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:7px;cursor:pointer;user-select:none}
        .be-kl-opt:hover{background:var(--gray-50,#f8fafc)}
        .be-kl-opt .material-symbols-outlined{font-size:18px;color:var(--gray-400,#9ca3af)}
        .be-kl-opt.on .material-symbols-outlined{color:var(--be-accent,#2563eb)}
        .be-kl-opt.on{font-weight:600}
        .be-kl-foot{font-size:.72rem;color:var(--gray-400,#9ca3af);padding:6px 8px 2px;border-top:1px solid var(--gray-100,#f1f5f9);margin-top:4px;pointer-events:none}`;
    document.head.appendChild(st);
}

function _beKlPlace() {
    if (!_beKlPop || !_beKlInput) return;
    const r = _beKlInput.getBoundingClientRect();
    if (!r.width) return beKlClose();
    // Field scrolled out of the dialog body → hide the list (it lives in the
    // top layer and would otherwise float over header/footer).
    const vr = _beKlScroller ? _beKlScroller.getBoundingClientRect() : { top: 0, bottom: window.innerHeight };
    _beKlPop.style.visibility = (r.bottom < vr.top || r.top > vr.bottom) ? 'hidden' : '';
    const w = Math.max(r.width, 240);
    _beKlPop.style.width = w + 'px';
    _beKlPop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
    const below = window.innerHeight - r.bottom;
    if (below < 220 && r.top > below) {
        _beKlPop.style.top = '';
        _beKlPop.style.bottom = (window.innerHeight - r.top + 4) + 'px';
    } else {
        _beKlPop.style.bottom = '';
        _beKlPop.style.top = (r.bottom + 4) + 'px';
    }
}

async function _beKlRender() {
    const input = _beKlInput;
    const groups = await _beKlGroups(_beKlKind(input));
    if (input !== _beKlInput) return;
    if (!groups.length) return beKlClose();
    _beKlEnsureStyle();
    if (!_beKlPop) {
        _beKlPop = document.createElement('div');
        _beKlPop.className = 'be-kl-pop';
        _beKlPop.addEventListener('mousedown', (e) => {
            const opt = e.target.closest('.be-kl-opt');
            // No option hit (padding/gaps): the list can cover the form's
            // action row — act like an outside click so the press is not
            // swallowed by the overlay.
            if (!opt) { beKlClose(); return; }
            e.preventDefault();
            e.stopPropagation();
            beKlToggle(opt.dataset.name);
        });
        // The wizard is a modal md-dialog: everything outside it is inert and
        // drawn below it. So the picker lives next to the field (inside the
        // dialog) and joins the top layer as a popover (out of grid flow).
        const canPop = typeof _beKlPop.showPopover === 'function';
        if (canPop) _beKlPop.setAttribute('popover', 'manual');
        (input.parentElement || document.body).appendChild(_beKlPop);
        if (canPop) _beKlPop.showPopover();
        // <md-dialog> scrolls its shadow-DOM `.scroller`; those scroll events
        // never reach the window listener — follow the field from there.
        _beKlScroller = wizFindVScroller(input);
        if (_beKlScroller) _beKlScroller.addEventListener('scroll', _beKlPlace, { passive: true });
    }
    const sel = new Set(_beKlTokens(input.value).map(s => s.toLowerCase()));
    _beKlPop.innerHTML = groups.map(g => `
        <div class="be-kl-grp"><i style="background:${escapeHtml(g.farbe || '#94a3b8')}"></i>${escapeHtml(g.bereich)} <span>· ${escapeHtml(g.list)}</span></div>
        ${g.opts.map(o => {
            const on = sel.has(o.toLowerCase());
            return `<div class="be-kl-opt ${on ? 'on' : ''}" data-name="${escapeHtml(o)}"><span class="material-symbols-outlined">${on ? 'check_box' : 'check_box_outline_blank'}</span>${escapeHtml(o)}</div>`;
        }).join('')}`).join('')
        + '<div class="be-kl-foot">Mehrfachauswahl · freie Eingabe weiterhin möglich</div>';
    _beKlPlace();
}

function beKlToggle(name) {
    if (!_beKlInput || !name) return;
    const toks = _beKlTokens(_beKlInput.value);
    const i = toks.findIndex(t => t.toLowerCase() === name.toLowerCase());
    if (i >= 0) toks.splice(i, 1); else toks.push(name);
    _beKlInput.value = toks.join(', ');
    _beKlInput.dispatchEvent(new Event('change', { bubbles: true }));
    _beKlRender();
}

function beKlClose() {
    if (_beKlScroller) _beKlScroller.removeEventListener('scroll', _beKlPlace);
    _beKlScroller = null;
    if (_beKlPop) _beKlPop.remove();
    _beKlPop = null;
    _beKlInput = null;
}

document.addEventListener('focusin', (e) => {
    const t = e.target;
    if (t && t.matches && t.matches(_BE_KL_FIELDS)) {
        if (t !== _beKlInput) beKlClose();
        _beKlInput = t;
        _beKlRender();
    } else if (_beKlPop && !(t && t.closest && t.closest('.be-kl-pop'))) {
        beKlClose();
    }
});
document.addEventListener('input', (e) => { if (e.target === _beKlInput) _beKlRender(); });
document.addEventListener('mousedown', (e) => {
    if (_beKlPop && e.target !== _beKlInput && !e.target.closest('.be-kl-pop')) beKlClose();
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && _beKlPop) beKlClose(); });
window.addEventListener('scroll', _beKlPlace, true);
window.addEventListener('resize', _beKlPlace);

// Schicht-Vorlage M/W edit (Anforderungen header). Same hard cap against the
// template Menge; also re-run when the Menge itself changes (input = null).
function wizAnfMixChanged(input) {
    const mEl = document.getElementById('wiz_anf_m');
    const wEl = document.getElementById('wiz_anf_w');
    if (!mEl || !wEl) return;
    const menge = parseInt(document.getElementById('wiz_shift_menge')?.value) || 1;
    let m = Math.max(0, parseInt(mEl.value) || 0);
    let w = Math.max(0, parseInt(wEl.value) || 0);
    if (m + w > menge) {
        if (input === wEl) {
            m = Math.min(m, menge);
            w = Math.max(0, menge - m);
        } else {
            m = Math.min(m, menge);
            w = Math.min(w, Math.max(0, menge - m));
        }
        mEl.value = m || '';
        wEl.value = w || '';
        showToast(`⚠ M + W darf die Menge (${menge}) nicht überschreiten — Wert angepasst`, 'warning');
    }
    wizApplyMetaToRows();
}

// Header Ort typed/picked → refresh the grey "· Standard" hint on days that
// have no Tag-Ort. Display only: no dataset is touched. Rows in edit mode are
// skipped (re-rendering would destroy their inputs).
function wizRefreshOrtHints() {
    const tbody = document.getElementById('wizDaysBody');
    if (!tbody) return;
    tbody.querySelectorAll('tr').forEach(row => {
        if (!row.classList.contains('be-day-editing') && !row.dataset.ort) wizRedrawRowKeepIndex(row);
    });
}

// Re-render a row without losing its # index (first cell).
function wizRedrawRowKeepIndex(row) {
    const idx = parseInt(row.querySelector('td strong')?.textContent) || 0;
    wizRedrawDayRow(row, idx);
}

// Apply the current Beschreibung + Anforderungen template to EVERY listed
// day row. These are uniform-by-nature, so we sync them automatically whenever
// the user edits them — otherwise an edit on an already-listed order (edit mode)
// would be silently dropped, because wizCollectDays() reads each row's own
// dataset first. Von/Bis/Pause/Menge/Ort stay per-day; use
// wizApplyTemplateToRows() to push the full template onto all days explicitly.
// The header Ort is ONLY a default for newly generated days — the Tag-Ort is
// what gets saved and shown in Dispo, so it is never touched here.
function wizApplyMetaToRows() {
    const tbody = document.getElementById('wizDaysBody');
    if (!tbody) return;
    const beschreibung = document.getElementById('wiz_shift_beschreibung')?.value || '';
    const anf = wizCollectAnforderungen();
    const anfPersons = (anf && Array.isArray(anf.positionen)) ? anf.positionen.reduce((s, p) => s + (parseInt(p.anzahl) || 0), 0) : 0;
    const hasAnf = !!(anf && (anf.qualifikation || anf.kleidung || anf.skill || anf.geschlecht || anf.geschlecht_mix || (anf.positionen && anf.positionen.length)));
    const anfChip = hasAnf ? ` <span class="be-anf-chip" title="Anforderungen für Dispo hinterlegt">${beIcon('assignment', 'font-size:11px')}${anfPersons > 0 ? ' ' + anfPersons : ''}</span>` : '';
    tbody.querySelectorAll('tr').forEach(row => {
        if (wizRowLocked(row)) return;
        // Preserve a per-day Geschlecht-Mix that differs from the template —
        // otherwise editing e.g. the Beschreibung would silently wipe it.
        if (!row.dataset.geschlechtMix) {
            const rowAnf = wizParseAnf(row.dataset.anforderungen);
            const tmplMix = (anf && anf.geschlecht_mix) || null;
            if (rowAnf && rowAnf.geschlecht_mix && JSON.stringify(rowAnf.geschlecht_mix) !== JSON.stringify(tmplMix)) {
                row.dataset.geschlechtMix = JSON.stringify(rowAnf.geschlecht_mix);
            }
        }
        row.dataset.beschreibung = beschreibung;
        // Days whose Anforderungen were edited in the Kalender sheet carry an
        // explicit override — the template sync only reaches days that still
        // follow the Vorlage (same contract as pauseOverride / ortOverride).
        if (row.dataset.anfOverride !== '1') row.dataset.anforderungen = anf ? JSON.stringify(anf) : '';
        if (row.classList.contains('be-day-editing')) {
            // Row is mid-edit: update only the Beschreibung line, never the
            // cell markup (which would destroy the Ort input + suggest box).
            const bEl = row.querySelector('.be-day-ort-beschr');
            if (bEl) bEl.innerHTML = `${escapeHtml(beschreibung) || '–'}${anfChip}`;
        } else {
            wizRedrawRowKeepIndex(row);
        }
    });
    wizVorlageCheck();
}

// Header MA-Pause / Kunden-Pause changed → push the new values onto every
// day that carries no per-day override (same contract as the Ort sync:
// a pause deliberately set on a single day via row edit wins). Std is
// recalculated because net hours depend on the pause.
function wizSyncPausenToRows() {
    const tbody = document.getElementById('wizDaysBody');
    if (!tbody) return;
    const pause = parseInt(document.getElementById('wiz_shift_pause')?.value) || 0;
    const headerVon = document.getElementById('wiz_shift_von')?.value || '';
    const headerBis = document.getElementById('wiz_shift_bis')?.value || '';
    tbody.querySelectorAll('tr').forEach(row => {
        if (row.classList.contains('be-day-editing') || wizRowLocked(row)) return;
        let dirty = false;
        // Rows without a manual override follow the Pausenregelung of THEIR
        // OWN duration — a day with different times gets its own auto pause;
        // without configured rules they keep inheriting the header value.
        const autoP = beAutoPauseMin(row.dataset.von || headerVon, row.dataset.bis || headerBis);
        const targetPause = (autoP !== null && !wizHeaderPauseManual()) ? autoP : pause;
        if (!row.dataset.pauseOverride && String(parseInt(row.dataset.pauseMinuten) || 0) !== String(targetPause)) {
            row.dataset.pauseMinuten = targetPause; dirty = true;
        }
        if (String(parseInt(row.dataset.gesetzlPause) || 0) !== String(parseInt(row.dataset.pauseMinuten) || 0)) {
            row.dataset.gesetzlPause = parseInt(row.dataset.pauseMinuten) || 0; dirty = true;
        }
        if (dirty) {
            const pmin = parseInt(row.dataset.pauseMinuten) || 0;
            row.dataset.std = (row.dataset.von && row.dataset.bis)
                ? (beShiftNetMinutes(row.dataset.von, row.dataset.bis, pmin) / 60).toFixed(2) : '0.00';
            wizRedrawRowKeepIndex(row);
        }
    });
    wizUpdateDaysFooter();
}

// Re-apply the FULL Schicht-Vorlage (Von/Bis, Pause, Kunden-Pause, Menge,
// Beschreibung, Anforderungen) to every listed day, keeping each day's date.
// Triggered by the "Auf alle Tage übernehmen" button so the user can push
// template edits (incl. times) onto all days before saving.
function wizApplyTemplateToRows() {
    if (!wizVorlageGuard()) return;
    const tbody = document.getElementById('wizDaysBody');
    if (!tbody) return;
    const allRows = [...tbody.querySelectorAll('tr')].filter(r => r.dataset.datum);
    const lockedRows = allRows.filter(wizRowLocked);
    const freeRows = allRows.filter(r => !wizRowLocked(r))
        .map(r => ({ datum: r.dataset.datum, uid: r.dataset.tagUid || '', artikel: r.dataset.artikel || '' }));
    const dates = freeRows.map(r => r.datum);
    if (dates.length === 0) { showToast(lockedRows.length ? 'Alle Tage sind in der Dispo eingeplant/gesperrt — nichts zu übernehmen' : 'Keine Tage zum Übernehmen vorhanden', 'error'); return; }
    const von = document.getElementById('wiz_shift_von').value || '';
    const bis = document.getElementById('wiz_shift_bis').value || '';
    const menge = document.getElementById('wiz_shift_menge').value || '';
    const pause = parseInt(document.getElementById('wiz_shift_pause').value) || 0;
    const beschreibung = document.getElementById('wiz_shift_beschreibung').value || '';
    const ort = document.getElementById('wiz_shift_ort').value || '';
    const gp = parseInt(document.getElementById('wizGesetzlPause')?.value) || 0;
    const anf = wizCollectAnforderungen();
    const stdMins = (von && bis) ? beShiftNetMinutes(von, bis, pause) : 0;
    if (von && bis && stdMins <= 0) showToast('Achtung: Pause ≥ Schichtdauer — Tage ergeben 0 Std', 'error');
    const sollStd = (stdMins / 60).toFixed(2);
    tbody.innerHTML = '';
    _wizDayCount = 0;
    lockedRows.forEach(r => { _wizDayCount++; tbody.appendChild(r); });
    freeRows.forEach(fr => {
        _wizDayCount++;
        const dow = new Date(fr.datum + 'T00:00:00').getDay();
        wizAppendDayRow(tbody, _wizDayCount, fr.datum, dow, von, bis, sollStd, gp, menge, beschreibung, pause, anf, ort, null, fr.artikel || null);
        if (fr.uid) tbody.lastElementChild.dataset.tagUid = fr.uid;
    });
    wizSortAndRenumberDays();
    wizApplyArtTabFilter();
    wizUpdateDaysFooter();
    showToast(`Vorlage auf ${dates.length} Tage übernommen` + (lockedRows.length ? ` (${lockedRows.length} eingeplante Tage unverändert)` : ''));
}

// ============================================================
// ORT / TREFFPUNKT — Autocomplete (Photon / Komoot — free, OSM-based)
// The provider is isolated in beOrtFetchSuggestions(); swap that one function
// to switch to Google Places / Geoapify / HERE etc.
// ============================================================
let _beOrtTimer = null;
let _beOrtAbort = null;
let _beOrtTarget = null; // the input currently driving suggestions (header or a per-day row input)

// Resolve the suggestion container for an ort input: the header field owns
// #wiz_ort_suggest; per-day edit inputs carry their own .be-day-ort-suggest
// inside the table cell.
function beOrtBoxFor(input) {
    if (input && input.classList && input.classList.contains('be-day-ort-edit')) {
        return input.parentElement ? input.parentElement.querySelector('.be-day-ort-suggest') : null;
    }
    return document.getElementById('wiz_ort_suggest');
}

// Mirror a per-day ort input into its row's dataset the moment it changes —
// typing or picking a suggestion already counts as an override, so a header
// Ort edit can never clobber an in-progress per-day value.
function beOrtSyncRowFlag(input) {
    if (!input || !input.classList || !input.classList.contains('be-day-ort-edit')) return;
    const row = input.closest('tr');
    if (!row) return;
    const v = String(input.value ?? '').trim();
    const hOrt = String(document.getElementById('wiz_shift_ort')?.value ?? '').trim();
    row.dataset.ort = v;
    if (v && v !== hOrt) row.dataset.ortOverride = '1';
    else delete row.dataset.ortOverride;
}

function beOrtInput(e) {
    const q = String(e.target.value ?? '').trim();
    _beOrtTarget = e.target;
    beOrtSyncRowFlag(e.target);
    const box = beOrtBoxFor(e.target);
    if (!box) return;
    if (_beOrtTimer) clearTimeout(_beOrtTimer);
    if (q.length < 3) { box.style.display = 'none'; box.innerHTML = ''; return; }
    box.innerHTML = `<div class="be-ort-empty">${beSpinner(14)} Suche\u2026</div>`;
    box.style.display = 'block';
    _beOrtTimer = setTimeout(() => beOrtFetch(q, box), 280);
}

function beOrtHide(e) {
    const box = beOrtBoxFor(e && e.target ? e.target : null);
    // Delay so a mousedown on a suggestion registers before we hide the list.
    setTimeout(() => { if (box) box.style.display = 'none'; }, 160);
}

async function beOrtFetch(q, box) {
    box = box || document.getElementById('wiz_ort_suggest');
    if (!box) return;
    try {
        // Coordinates? ALWAYS offer the exact point as the first choice —
        // the reverse hits below are only the nearest NAMED places and can
        // be hundreds of metres away in rural areas.
        const coords = beParseCoords(q);
        const exact = coords ? `${coords.lat.toFixed(6)}, ${coords.lng.toFixed(6)}` : null;
        const items = await beOrtFetchSuggestions(q);
        if (!items.length && !exact) { box.innerHTML = '<div class="be-ort-empty">Keine Treffer</div>'; box.style.display = 'block'; return; }
        const exactHtml = exact
            ? `<div class="be-ort-item" data-label="${escapeHtml(exact)}" onmousedown="beOrtPick(this)">${beIcon('my_location')}<span><strong>Exakter Punkt:</strong> ${escapeHtml(exact)}</span></div>`
            : '';
        box.innerHTML = exactHtml + items.map(label =>
            `<div class="be-ort-item" data-label="${escapeHtml(label)}" onmousedown="beOrtPick(this)">${beIcon('location_on')}<span>${escapeHtml(label)}${exact ? ' <span style="color:var(--gray-400)">(in der Nähe)</span>' : ''}</span></div>`
        ).join('');
        box.style.display = 'block';
    } catch (err) {
        if (err && err.name === 'AbortError') return;
        box.style.display = 'none'; box.innerHTML = '';
    }
}

// Parse coordinate input to {lat, lng} — or null if it is a normal address.
// Supports DMS (52°08'20.3"N 11°31'53.3"E, German O=Ost, comma decimals)
// and decimal pairs (51.34, 12.36 / 51,34, 12,36).
function beParseCoords(s) {
    s = (s || '').trim();
    const dmsPart = `(\\d{1,3})\\s*[°:d]\\s*(\\d{1,2})\\s*['′:\\s]\\s*(\\d{1,2}(?:[.,]\\d+)?)\\s*["″”'']*`;
    const dmsRe = new RegExp(`^${dmsPart}\\s*([NSns])[,;\\s]+${dmsPart}\\s*([EWOewo])$`);
    const m = s.match(dmsRe);
    if (m) {
        const toDec = (d, min, sec) => parseFloat(d) + parseFloat(min) / 60 + parseFloat(String(sec).replace(',', '.')) / 3600;
        let lat = toDec(m[1], m[2], m[3]);
        let lng = toDec(m[5], m[6], m[7]);
        if (/s/i.test(m[4])) lat = -lat;
        if (/w/i.test(m[8])) lng = -lng;
        if (isFinite(lat) && isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) return { lat, lng };
    }
    if (/^[\d\s.,;+-]+$/.test(s)) {
        const nums = s.match(/-?\d+(?:[.,]\d+)?/g) || [];
        if (nums.length === 2) {
            const lat = parseFloat(nums[0].replace(',', '.'));
            const lng = parseFloat(nums[1].replace(',', '.'));
            if (isFinite(lat) && isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) return { lat, lng };
        }
    }
    return null;
}

// --- Provider: Photon (https://photon.komoot.io) — no API key required. ---
async function beOrtFetchSuggestions(q) {
    if (_beOrtAbort) _beOrtAbort.abort();
    _beOrtAbort = new AbortController();
    // Coordinates? A text search would match the digits against street
    // numbers anywhere in the world (e.g. "31st Street, New York") —
    // reverse-geocode the point instead and suggest the real address.
    const coords = beParseCoords(q);
    const url = coords
        ? `https://photon.komoot.io/reverse?lang=de&limit=3&lat=${coords.lat.toFixed(6)}&lon=${coords.lng.toFixed(6)}`
        : 'https://photon.komoot.io/api/?lang=de&limit=6&q=' + encodeURIComponent(q);
    const res = await fetch(url, { signal: _beOrtAbort.signal });
    if (!res.ok) throw new Error('geocode http ' + res.status);
    const data = await res.json();
    const feats = (data && Array.isArray(data.features)) ? data.features : [];
    const seen = new Set();
    const labels = [];
    for (const f of feats) {
        const label = bePhotonLabel(f.properties || {});
        if (label && !seen.has(label)) { seen.add(label); labels.push(label); }
    }
    return labels;
}

function bePhotonLabel(p) {
    const name = p.name || '';
    const street = [p.street, p.housenumber].filter(Boolean).join(' ');
    const cityLine = [p.postcode, (p.city || p.town || p.village || p.county)].filter(Boolean).join(' ');
    const parts = [];
    if (name) parts.push(name);
    if (street && street !== name) parts.push(street);
    if (cityLine) parts.push(cityLine);
    if (!cityLine && p.state) parts.push(p.state);
    if (!parts.length && p.country) parts.push(p.country);
    return parts.join(', ');
}

function beOrtPick(el) {
    const input = _beOrtTarget || document.getElementById('wiz_shift_ort');
    if (input) {
        input.value = el.getAttribute('data-label') || '';
        // Header picks still push the value onto all non-overridden day rows;
        // row-level picks land on the row dataset immediately.
        if (input.id === 'wiz_shift_ort') wizRefreshOrtHints();
        else beOrtSyncRowFlag(input);
    }
    const box = el.closest('.be-ort-suggest') || document.getElementById('wiz_ort_suggest');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
}

// ============================================================
// WIZARD — Save (Create / Update)
// ============================================================
// Ort / Treffpunkt is mandatory: the worker app shows it as the meeting
// point for every day without its own Tag-Ort, so an empty header value
// would leave those shifts without any location. Highlights + focuses the
// field (scrolling the dialog to it) when missing.
function wizRequireField(id, label) {
    const el = document.getElementById(id);
    if (!el) return true;
    if (el.value.trim()) { el.classList.remove('is-invalid'); return true; }
    el.classList.add('is-invalid');
    el.addEventListener('input', () => el.classList.remove('is-invalid'), { once: true });
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.focus({ preventScroll: true });
    showToast('Bitte ' + label + ' angeben — Pflichtfeld', 'error');
    return false;
}
function wizRequireOrt() { return wizRequireField('wiz_shift_ort', 'Ort / Treffpunkt'); }
function wizRequireBeschreibung() { return wizRequireField('wiz_shift_beschreibung', 'eine Beschreibung'); }

// Two shifts on one date (e.g. 06–18 + 18–06) are normal — only rows with
// the same date, service AND start/end time are real duplicates.
// Days outside the selected Bestellmonat are allowed (orders may span
// months) but must be deliberate: warn for NEW dates outside the month.
async function wizConfirmOutOfMonth(tage, storedDates) {
    // Days belong to the order month — the server refuses NEW days outside
    // it (already stored ones of older multi-month orders stay valid).
    const monat = parseInt(document.getElementById('wiz_monat').value);
    const jahr = parseInt(document.getElementById('wiz_jahr').value);
    if (!monat || !jahr) return true;
    const outside = [...new Set(tage.map(t => t.datum).filter(d => {
        if (!d || (storedDates && storedDates.has(d))) return false;
        return parseInt(d.substring(5, 7)) !== monat || parseInt(d.substring(0, 4)) !== jahr;
    }))].sort();
    if (!outside.length) return true;
    const monthLabel = new Date(jahr, monat - 1, 1).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' });
    await mdAlert('Folgende Tage liegen <strong>außerhalb des Bestellmonats ' + monthLabel + '</strong>:<br><br>&bull; ' +
        outside.map(formatDate).join('<br>&bull; ') + '<br><br>Bitte diese Tage entfernen oder für den anderen Monat eine eigene Bestellung anlegen (Kopieren).',
        'Tage außerhalb des Monats');
    return false;
}

async function wizConfirmDuplicateDays(tage) {
    const key = t => `${t.datum}|${t.artikel_id || ''}|${t.zeit_von || ''}|${t.zeit_bis || ''}`;
    const keys = tage.map(key);
    const dups = [...new Set(keys.filter((k, i) => keys.indexOf(k) !== i))];
    if (!dups.length) return true;
    const list = dups.map(k => {
        const [d, , von, bis] = k.split('|');
        return formatDate(d) + (von || bis ? ' ' + (von || '–') + '–' + (bis || '–') : '');
    }).join('<br>&bull; ');
    return mdConfirm('Folgende Schichten sind <strong>doppelt</strong> eingetragen (gleicher Tag und gleiche Uhrzeit):<br><br>&bull; ' + list + '<br><br>Trotzdem speichern?',
        { title: 'Doppelte Schichten', confirmLabel: 'Trotzdem speichern' });
}

async function wizSaveIncoming() {
    const menge = parseInt(document.getElementById('wiz_shift_menge').value) || 0;
    if (menge <= 0) { showToast('Bitte Menge angeben (mindestens 1)', 'error'); return; }
    if (!wizRequireOrt() || !wizRequireBeschreibung()) return;
    if (!wizCommitOpenDayEdits()) return;

    const tage = wizCollectDays();
    if (tage.length === 0) { showToast('Bitte mindestens einen Einsatztag hinzufügen', 'error'); return; }

    if (!(await wizConfirmDuplicateDays(tage))) return;
    if (!(await wizConfirmOutOfMonth(tage, null))) return;

    const preise = wizCollectPreise();
    const primaryPreis = _beState.selArt ? (preise[_beState.selArt.artikel_id] || {}) : {};
    const data = {
        kunden_id: document.getElementById('wiz_kunde').value,
        obj_id: document.getElementById('wiz_objekt').value,
        artikel_id: _beState.selArt?.artikel_id || document.getElementById('wiz_artikel')?.value || null,
        artikel_ids: (_beState.selArts || []).map(a => a.artikel_id),
        artikel_preise: preise,
        monat: parseInt(document.getElementById('wiz_monat').value),
        jahr: parseInt(document.getElementById('wiz_jahr').value),
        zeit_von: document.getElementById('wiz_shift_von').value || null,
        zeit_bis: document.getElementById('wiz_shift_bis').value || null,
        ort: String(document.getElementById('wiz_shift_ort')?.value ?? '').trim() || null,
        modell: primaryPreis.modell || null,
        kundenpreis: primaryPreis.kundenpreis ?? null,
        bemerkung: document.getElementById('wiz_bemerkung').value || null,
        tage: tage
    };
    if (!data.artikel_id) { showToast('Bitte Artikel / Leistung auswählen', 'error'); return; }

    const result = await apiPost(API.incoming_orders, data);
    if (result.success) {
        closeBeDialog();
        showToast(`Bestelleingang ${result.eingang_id} erstellt — Bestellung ${result.bestell_id} + ${result.tasks_created} Einsätze angelegt`);
        renderIncomingOrders();
        _wizDayCount = 0;
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function wizSaveEditIncoming(eingangId, originalStatus) {
    const menge = parseInt(document.getElementById('wiz_shift_menge').value) || 0;
    if (menge <= 0) { showToast('Bitte Menge angeben (mindestens 1)', 'error'); return; }
    if (!wizRequireOrt() || !wizRequireBeschreibung()) return;
    if (!wizCommitOpenDayEdits()) return;

    const tage = wizCollectDays();
    if (tage.length === 0) { showToast('Bitte mindestens einen Einsatztag hinzufügen', 'error'); return; }

    if (!(await wizConfirmDuplicateDays(tage))) return;
    if (!(await wizConfirmOutOfMonth(tage, window._wizStoredDates || null))) return;

    const preise = wizCollectPreise();
    const primaryPreis = _beState.selArt ? (preise[_beState.selArt.artikel_id] || {}) : {};
    const data = {
        eingang_id: eingangId,
        kunden_id: document.getElementById('wiz_kunde').value,
        obj_id: document.getElementById('wiz_objekt').value,
        artikel_id: _beState.selArt?.artikel_id || document.getElementById('wiz_artikel')?.value || null,
        artikel_ids: (_beState.selArts || []).map(a => a.artikel_id),
        artikel_preise: preise,
        monat: parseInt(document.getElementById('wiz_monat').value),
        jahr: parseInt(document.getElementById('wiz_jahr').value),
        zeit_von: document.getElementById('wiz_shift_von').value || null,
        zeit_bis: document.getElementById('wiz_shift_bis').value || null,
        ort: String(document.getElementById('wiz_shift_ort')?.value ?? '').trim() || null,
        modell: primaryPreis.modell || null,
        kundenpreis: primaryPreis.kundenpreis ?? null,
        bemerkung: document.getElementById('wiz_bemerkung').value || null,
        // Optimistic lock — the server refuses the save when someone else
        // changed the order since it was opened.
        expected_updated_at: window._wizOrderUpdatedAt ?? null,
        tage: tage
    };
    if (!data.artikel_id) { showToast('Bitte Artikel / Leistung auswählen', 'error'); return; }

    const result = await apiPut(API.incoming_orders + '/' + eingangId, data);
    if (result.success) {
        closeBeDialog();
        let msg = `Bestelleingang ${eingangId} aktualisiert — ${result.tasks_created || 0} neue + ${result.tasks_updated || 0} aktualisierte Einsätze`;
        if (result.tasks_protected > 0) msg += ` (${result.tasks_protected} eingeplante/bestätigte unverändert)`;
        showToast(msg);
        renderIncomingOrders();
        _wizDayCount = 0;
    } else if (result.code === 'version_conflict') {
        await mdAlert(escapeHtml(result.error || 'Die Bestellung wurde inzwischen geändert.'), 'Bestellung wurde geändert');
    } else if (Array.isArray(result.blocked_shifts) && result.blocked_shifts.length) {
        // Server refused: booked days cannot be removed or re-timed.
        await mdAlert(escapeHtml(result.error || 'Eingeplante Einsätze sind gesperrt.') + '<br><br>&bull; ' +
            result.blocked_shifts.map(escapeHtml).join('<br>&bull; ') +
            '<br><br>Es wurde nichts gespeichert.', 'Speichern nicht möglich');
    } else {
        showToast(result.error || 'Fehler beim Speichern', 'error');
        console.error('[BE-EDIT] Save failed:', result);
    }
}

// Human label for a stored geschlecht value — badge HTML and plain text.
function geschlechtLabelText(g) {
    return g === 'maennlich' ? 'Männlich' : (g === 'weiblich' ? 'Weiblich' : (g === 'gemischt' ? 'Gemischt' : ''));
}
function geschlechtLabel(g) {
    const txt = geschlechtLabelText(g);
    if (!txt) return '–';
    const color = g === 'weiblich' ? '#db2777' : (g === 'maennlich' ? '#2563eb' : '#7c3aed');
    return `<span style="font-weight:700;color:${color}">${g === 'weiblich' ? 'W' : (g === 'maennlich' ? 'M' : 'M/W')} ${txt}</span>`;
}

// "M 2 · W 1" HTML for a geschlecht_mix object (or legacy anf.geschlecht).
function geschlechtMixLabel(anf) {
    if (!anf) return '';
    const mix = anf.geschlecht_mix;
    if (mix && (mix.maennlich > 0 || mix.weiblich > 0)) {
        const parts = [];
        if (mix.maennlich > 0) parts.push(`<span style="font-weight:700;color: var(--md-sys-color-primary)">M ${mix.maennlich}</span>`);
        if (mix.weiblich > 0) parts.push(`<span style="font-weight:700;color: var(--md-sys-color-error)">W ${mix.weiblich}</span>`);
        return parts.join(' · ');
    }
    return anf.geschlecht ? geschlechtLabel(anf.geschlecht) : '';
}

// Read-only Anforderungen panel for the order "eye" view. Anforderungen are
// stored per day but uniform in practice; viewIncomingOrder passes the first
// day that carries requirements. Reuses existing modal section/table styles.
function beAnforderungenViewHtml(anf) {
    if (!anf || typeof anf !== 'object') return '';
    const positionen = Array.isArray(anf.positionen) ? anf.positionen : [];
    const hasGeneral = anf.qualifikation || anf.kleidung || anf.skill || anf.geschlecht || anf.geschlecht_mix;
    if (!hasGeneral && positionen.length === 0) return '';
    const total = positionen.reduce((s, p) => s + (parseInt(p.anzahl) || 0), 0);
    const field = (label, val) => val ? `<div class="form-group"><label>${label}</label><div style="font-weight:600">${escapeHtml(val)}</div></div>` : '';
    const posTable = positionen.length ? `
        <div class="be-days-table-wrap" style="margin-top:10px">
            <div class="table-container">
                <table>
                    <thead><tr><th class="text-right">Anzahl</th><th>Funktion</th><th>Geschlecht</th><th>Qualifikation</th><th>Kleidung</th></tr></thead>
                    <tbody>
                        ${positionen.map(p => `<tr>
                            <td class="text-right" style="font-weight:700;color:var(--be-accent)">${(p.anzahl != null && p.anzahl !== '') ? escapeHtml(String(p.anzahl)) : '–'}</td>
                            <td>${p.funktion ? escapeHtml(p.funktion) : '–'}</td>
                            <td>${p.geschlecht ? geschlechtLabel(p.geschlecht) : '–'}</td>
                            <td>${p.qualifikation ? escapeHtml(p.qualifikation) : '–'}</td>
                            <td>${p.kleidung ? escapeHtml(p.kleidung) : '–'}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>
            </div>
        </div>` : '';
    return `
        <div class="be-form-section">
            <div class="be-form-section-title">${beIcon('assignment', 'font-size:14px')} Anforderungen${total > 0 ? ` <span class="be-anf-chip">${total} Personen</span>` : ''}</div>
            ${hasGeneral ? `<div class="form-grid" style="grid-template-columns:repeat(3,1fr);gap:12px">
                ${field('Qualifikation', anf.qualifikation)}
                ${field('Kleidung', anf.kleidung)}
                ${field('Skill / Sprache', anf.skill)}
                ${geschlechtMixLabel(anf) ? `<div class="form-group"><label>Geschlecht</label><div>${geschlechtMixLabel(anf)}</div></div>` : ''}
            </div>` : ''}
            ${posTable}
        </div>
    `;
}

// Änderungshistorie block in the view dialog — one row per log entry
// (bestelleingang_log, newest first; absent before the migration or when
// nothing was ever logged).
function beHistoryViewHtml(history) {
    const rows = Array.isArray(history) ? history : [];
    if (!rows.length) return '';
    const ACT = { erstellt: 'Erstellt', bearbeitet: 'Bearbeitet', abgesagt: 'Abgesagt', wieder_geoeffnet: 'Wieder geöffnet', abgeschlossen: 'Abgeschlossen' };
    return `
        <div class="be-hist">${rows.map(h => {
                const act = escapeHtml(ACT[h.aktion] || h.aktion || '–');
                const cls = h.aktion === 'abgesagt' ? ' abg' : (h.aktion === 'abgeschlossen' ? ' done' : '');
                const chg = Array.isArray(h.aenderungen) ? h.aenderungen : [];
                return `<div class="be-hist-row">
                    <div class="be-hist-head">
                        <span class="be-hist-act${cls}">${act}</span>
                        <span class="be-hist-meta">${h.datum ? formatDateTimeLocal(h.datum) : '–'}${h.user ? ` · ${escapeHtml(h.user)}` : ''}</span>
                    </div>
                    ${chg.length ? `<ul class="be-hist-changes">${chg.map(c => `<li><strong>${escapeHtml(c.feld || '')}</strong> · <span class="v">${escapeHtml(String(c.von ?? '–'))}</span> → <span class="n">${escapeHtml(String(c.nach ?? '–'))}</span></li>`).join('')}</ul>` : ''}
                </div>`;
            }).join('')}</div>
        <style>
            .be-hist{display:flex;flex-direction:column;gap:8px;max-height:260px;overflow:auto}
            .be-hist-row{border: 1px solid var(--be-border,var(--md-sys-color-primary-container));border-radius:10px;padding:8px 12px}
            .be-hist-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
            .be-hist-act{font-size:.72rem;font-weight:700;padding:2px 9px;border-radius:999px;background: var(--md-sys-color-secondary-container,var(--md-sys-color-primary-container));color: var(--md-sys-color-on-secondary-container,var(--md-sys-color-primary));white-space:nowrap}
            .be-hist-act.abg{background: var(--be-danger-light,var(--md-sys-color-error-container));color: var(--be-danger,var(--md-sys-color-error))}
            .be-hist-act.done{background: var(--md-ext-color-success-container);color: var(--md-ext-color-on-success-container)}
            [data-theme="dark"] .be-hist-act.done{background: var(--md-ext-color-success);color: var(--md-ext-color-success-container)}
            .be-hist-meta{margin-left:auto;font-size:.78rem;color: var(--be-text-muted,var(--md-sys-color-primary));white-space:nowrap}
            .be-hist-changes{margin:6px 0 0;padding-left:18px;font-size:.8rem;color: var(--be-text,var(--md-sys-color-on-primary-container))}
            .be-hist-changes li{margin:2px 0}
            .be-hist-changes .v{color: var(--be-text-muted,var(--md-sys-color-primary))}
            .be-hist-changes .n{font-weight:600}
        </style>`;
}

// Verlauf popup — same overlay + sheet design as the calendar shift sheet
// (.be-cal-pop/.be-cal-sheet), so it looks identical to the shift popup.
// window._beViewHist is set by viewIncomingOrder each time the view opens.
function beShowHistory() {
    const rows = Array.isArray(window._beViewHist) ? window._beViewHist : [];
    if (!rows.length) { showToast('Kein Verlauf vorhanden'); return; }
    _beCalEnsureStyle();   // the sheet reuses .be-cal-* classes — inject their CSS even when no calendar was rendered
    beCloseHistory();
    const pop = document.createElement('div');
    pop.className = 'be-cal-pop';
    pop.id = 'beHistPop';
    // same mechanics as _beCalOpenPop: inside the dialog + popover top layer,
    // otherwise a plain fixed/z-index div renders UNDER the open md-dialog.
    const canPop = typeof pop.showPopover === 'function';
    if (canPop) pop.setAttribute('popover', 'manual');
    pop.innerHTML = `<div class="be-cal-sheet" role="dialog" aria-label="Änderungshistorie" style="width:min(560px,100%)">
        <div class="be-cal-sheet-head">
            <div><div class="be-cal-sheet-title">Änderungshistorie</div>
            <div class="be-cal-sheet-sub">${rows.length} ${rows.length === 1 ? 'Eintrag' : 'Einträge'}</div></div>
            <md-icon-button onclick="beCloseHistory()" aria-label="Schließen"><md-icon slot="icon">close</md-icon></md-icon-button>
        </div>
        <div class="be-cal-sheet-body">${beHistoryViewHtml(rows)}</div>
    </div>`;
    pop.addEventListener('click', e => { if (e.target === pop) beCloseHistory(); });
    (document.getElementById('beDialogBody') || document.body).appendChild(pop);
    if (canPop) pop.showPopover();
    window._beHistPop = pop;
    document.addEventListener('keydown', _beHistKey, true);
}

function beCloseHistory() {
    document.removeEventListener('keydown', _beHistKey, true);
    if (window._beHistPop) { window._beHistPop.remove(); window._beHistPop = null; }
}

// Capture-phase Escape (like _beCalKey) — the popup closes first and the
// underlying md-dialog never sees the key.
function _beHistKey(e) {
    if (!window._beHistPop || !window._beHistPop.isConnected) { document.removeEventListener('keydown', _beHistKey, true); return; }
    if (e.key !== 'Escape') return;
    e.preventDefault();
    e.stopPropagation();
    beCloseHistory();
}

// ============================================================
// VIEW (read-only)
// ============================================================
async function viewIncomingOrder(id) {
    const order = await apiGet(API.incoming_orders + '?id=' + id + '&ack=1');
    if (!order || order.error) { showToast('Nicht gefunden', 'error'); return; }
    const edited = order.updated_at && order.created_at && order.updated_at !== order.created_at;
    window._beViewHist = Array.isArray(order.history) ? order.history : [];
    window._beViewOrder = order;

    const totalSollStd = (order.tage || []).reduce((sum, t) => sum + ((parseFloat(t.soll_std) || 0) * (parseFloat(t.menge) || 1)), 0);

    let viewAnf = null;
    for (const t of (order.tage || [])) {
        const a = wizParseAnf(t.anforderungen);
        if (a && (a.qualifikation || a.kleidung || a.skill || (Array.isArray(a.positionen) && a.positionen.length))) { viewAnf = a; break; }
    }

    const body = `
        <div class="be-form-section">
            <div class="be-form-section-title">${beIcon('info', 'font-size:14px')} Bestelleingang Übersicht</div>
            <div class="form-grid" style="grid-template-columns:repeat(3,1fr);gap:12px">
                <div class="form-group"><label>Eingang-ID</label><div style="font-weight:700;font-family:ui-monospace,monospace;color:var(--be-accent)">${escapeHtml(order.eingang_id)}</div></div>
                <div class="form-group"><label>Status</label><div>${beStatusBadge(order.status)}</div></div>
                <div class="form-group"><label>Periode</label><div><span class="be-period-cell">${MONTHS_DE[order.monat]} ${order.jahr}</span></div></div>
                <div class="form-group"><label>Kunde</label><div>${escapeHtml(order.kundenname || order.kunden_id)}</div></div>
                <div class="form-group"><label>Objekt</label><div>${escapeHtml(order.objektname || order.obj_id)}</div></div>
                <div class="form-group"><label>Artikel</label><div>${(() => {
                    const liste = Array.isArray(order.artikel_liste) ? order.artikel_liste : [];
                    if (liste.length) {
                        return liste.map(a => `<span title="${escapeHtml(String(a.artikel_id))}">${escapeHtml(a.artikel_name || a.artikel_id)} <span style="color:var(--be-text-soft);font-size:.78rem;font-family:ui-monospace,monospace">${escapeHtml(String(a.artikel_id))}</span></span>`).join('<span style="color:var(--be-text-soft)"> · </span>');
                    }
                    const ids = beArtikelIdsOf(order);
                    return ids.length ? escapeHtml(ids.join(' · ')) : '<span style="color:var(--be-text-soft)">–</span>';
                })()}</div></div>
                <div class="form-group"><label>Modell</label><div>${(() => {
                    const preise = (order.artikel_preise && typeof order.artikel_preise === 'object') ? order.artikel_preise : {};
                    const ids = beArtikelIdsOf(order);
                    if (ids.length > 1) {
                        return ids.map(aid => {
                            const m = (preise[aid] && preise[aid].modell) || null;
                            return `<div>${escapeHtml(String(aid))}: ${m ? `<span class="be-modell-chip">${escapeHtml(m)}</span>` : '–'}</div>`;
                        }).join('');
                    }
                    return order.modell ? `<span class="be-modell-chip">${escapeHtml(order.modell)}</span>` : '–';
                })()}</div></div>
                <div class="form-group"><label>Kundenpreis</label><div style="font-weight:700">${(() => {
                    const preise = (order.artikel_preise && typeof order.artikel_preise === 'object') ? order.artikel_preise : {};
                    const ids = beArtikelIdsOf(order);
                    if (ids.length > 1) {
                        return ids.map(aid => {
                            const p = (preise[aid] && preise[aid].kundenpreis != null) ? preise[aid].kundenpreis : null;
                            return `<div>${escapeHtml(String(aid))}: ${p != null ? formatCurrency(p) : '–'}</div>`;
                        }).join('');
                    }
                    return formatCurrency(order.kundenpreis);
                })()}</div></div>
                <div class="form-group"><label>Zeit</label><div>${formatTime(order.zeit_von)} – ${formatTime(order.zeit_bis)}</div></div>
                <div class="form-group" style="grid-column:span 3"><label>Bemerkung</label>${order.bemerkung ? `<div style="display:flex;gap:10px;align-items:flex-start;background: var(--warning-light, var(--md-ext-color-warning-container));border: 1px solid var(--md-ext-color-warning-container);border-left: 4px solid var(--warning, var(--md-ext-color-warning));border-radius:8px;padding:10px 14px;margin-top:4px;font-size:0.86rem;line-height:1.55;color: var(--md-ext-color-on-warning-container, var(--md-ext-color-on-warning-container));white-space:pre-wrap">${beIcon('info', 'color:var(--warning, #f59e0b);font-size:15px;margin-top:3px;flex-shrink:0')}<span>${escapeHtml(order.bemerkung)}</span></div>` : '<div style="color:var(--be-text-muted)">–</div>'}</div>
                <div class="form-group"><label>Erstellt am</label><div>${order.created_at ? formatDateTimeLocal(order.created_at) : '–'}${order.created_by_name ? `<div style="margin-top:2px;color:var(--be-text-muted);font-size:0.8rem">${beIcon('person', 'font-size:11px;vertical-align:-1px;margin-right:4px')}${escapeHtml(order.created_by_name)}</div>` : ''}</div></div>
                <div class="form-group"><label>Geändert am</label><div>${edited ? `<span style="color:var(--be-warn)">${beIcon('edit', 'font-size:11px;vertical-align:-1px;margin-right:4px')}${formatDateTimeLocal(order.updated_at)}</span>${order.updated_by_name ? `<div style="margin-top:2px;color:var(--be-text-muted);font-size:0.8rem">${beIcon('person', 'font-size:11px;vertical-align:-1px;margin-right:4px')}${escapeHtml(order.updated_by_name)}</div>` : ''}` : '–'}${window._beViewHist.length ? `<div style="margin-top:6px"><md-outlined-button onclick="beShowHistory()" style="--md-outlined-button-container-height:30px"><md-icon slot="icon">history</md-icon>Verlauf (${window._beViewHist.length})</md-outlined-button></div>` : ''}</div></div>
            </div>
        </div>

        ${order.tage && order.tage.length > 0 ? `
            <div class="be-form-section">
                <div class="be-form-section-title">${beIcon('calendar_month', 'font-size:14px')} Einsatztage (${order.tage.length})</div>
                <div class="be-cal" id="wizDaysCal" style="margin-top:8px"></div>
                <div style="display:none"><table><tbody id="wizDaysBody"></tbody></table></div>
            </div>
        ` : ''}

        ${beAnforderungenViewHtml(viewAnf)}
    `;

    const footer = `
        <md-text-button onclick="closeBeDialog()">Schließen</md-text-button>
        ${order.bestell_id && beCanPlanung() ? `<md-outlined-button onclick="beOpenInPlanung({eingangId: window._beViewOrder.eingang_id, monat: window._beViewOrder.monat, jahr: window._beViewOrder.jahr})"><md-icon slot="icon">calendar_month</md-icon>In der Planung anzeigen</md-outlined-button>` : ''}
        <md-filled-button onclick="editIncomingOrder('${order.eingang_id}')" style="--md-filled-button-container-color:var(--be-accent)"><md-icon slot="icon">edit</md-icon>Bearbeiten</md-filled-button>
    `;
    openBeDialog('Bestelleingang: ' + order.eingang_id, body, footer, true);
    // Stored Einsatztage rendered with the wizard's calendar — read-only:
    // same chips + detail sheet, but no add/edit/duplicate actions.
    _beCalReadOnly = true;
    _beState.selArts = Array.isArray(order.artikel_liste) ? order.artikel_liste : [];
    _beState.selArt = _beState.selArts[0] || null;
    _beState.activeArtTab = null;
    window._wizArticles = _beState.selArts;
    const vbody = document.getElementById('wizDaysBody');
    (order.tage || []).forEach((t, i) => {
        const dateStr = (t.datum || '').substring(0, 10);
        const dow = dateStr ? new Date(dateStr + 'T00:00:00').getDay() : 0;
        const von = t.zeit_von ? t.zeit_von.substring(0, 5) : '';
        const bis = t.zeit_bis ? t.zeit_bis.substring(0, 5) : '';
        let pmin;
        if (t.pause_minuten !== undefined && t.pause_minuten !== null) {
            pmin = parseInt(t.pause_minuten) || 0;
        } else if (t.zeit_von && t.zeit_bis && t.soll_std) {
            pmin = Math.max(0, beShiftTotalMinutes(t.zeit_von, t.zeit_bis) - Math.round(parseFloat(t.soll_std) * 60));
        } else pmin = 0;
        wizAppendDayRow(vbody, i + 1, dateStr, dow, von, bis, t.soll_std || '',
            parseInt(t.gesetzl_pause) || 0, t.menge || '', t.beschreibung || '', pmin,
            wizParseAnf(t.anforderungen), t.ort || '', t.dispo_status || null, t.artikel_id || '');
        if (vbody.lastElementChild) vbody.lastElementChild.dataset.einsatzId = t.einsatz_id || '';
    });
    wizRenderDaysCal();
}

// ============================================================
// EDIT (re-uses wizard structure with prefilled data)
// ============================================================
async function editIncomingOrder(id) {
    const order = await apiGet(API.incoming_orders + '?id=' + id + '&ack=1');
    if (!order || order.error) { showToast('Nicht gefunden', 'error'); return; }

    if (order.status === 'abgesagt' || order.status === 'abgeschlossen') {
        await mdAlert(`Diese Bestellung ist <strong>${order.status === 'abgesagt' ? 'abgesagt (storniert)' : 'abgeschlossen'}</strong> und schreibgeschützt.<br><br>`
            + 'Zum Bearbeiten zuerst unter <strong>Bestellungen</strong> wieder öffnen.', 'Bestellung schreibgeschützt');
        return;
    }
    // Optimistic lock baseline for the save.
    window._wizOrderUpdatedAt = order.updated_at || '';

    if (order.has_planned_tasks) {
        // Confirmed shifts freeze only THEIR days — the rest of the order
        // stays editable. Warn once (like the planned-shifts warning), then
        // open the wizard; the per-day dispo_status badges show which days
        // are locked.
        if (order.has_confirmed_tasks) {
            const proceedConfirmed = await mdConfirm(
                'Diese Bestellung enthält bereits <strong>bestätigte Einsätze</strong> in der Disposition.<br><br>' +
                '<div style="text-align:left;background: var(--warning-light, var(--md-ext-color-warning-container));border: 1px solid var(--md-ext-color-warning-container);border-radius:10px;padding:12px 16px">' +
                `<strong style="color: var(--warning, var(--md-ext-color-warning))">${beIcon('warning', 'font-size:14px;vertical-align:-2px')} Bitte beachten:</strong><br>` +
                '&bull; Tage mit <strong>bestätigten</strong> Einsätzen sind gesperrt — weder änderbar noch entfernbar.<br>' +
                '&bull; Tage mit <strong>geplanten</strong> Einsätzen sind geschützt — den Plan zuerst in der Dispo aufheben, dann wird der Tag hier bearbeitbar.<br>' +
                '&bull; Alle übrigen Tage sowie neue Tage können Sie normal bearbeiten.' +
                '</div>',
                { title: 'Bestätigte Einsätze vorhanden', confirmLabel: 'Trotzdem bearbeiten' });
            if (!proceedConfirmed) return;
        } else {
            // Planned shifts stay partially protected on save (times/hours don't
            // reach shifts with assigned workers, a removed day storno-locks its
            // planned shift / deletes unplanned ones). Warn once, then let the
            // dispatcher in anyway — the warning replaces a full hard lock.
            const proceed = await mdConfirm(
                'Diese Bestellung hat bereits <strong>geplante Einsätze</strong> in der Disposition.<br><br>' +
                '<div style="text-align:left;background: var(--warning-light, var(--md-ext-color-warning-container));border: 1px solid var(--md-ext-color-warning-container);border-radius:10px;padding:12px 16px">' +
                `<strong style="color: var(--warning, var(--md-ext-color-warning))">${beIcon('warning', 'font-size:14px;vertical-align:-2px')} Bitte beachten:</strong><br>` +
                '&bull; Tage mit eingeplanten Mitarbeitern sind <strong>gesperrt</strong> — weder änderbar noch entfernbar (Zeiten, Pausen, Ort bleiben wie in der Dispo).<br>' +
                '&bull; Alle übrigen Tage sowie neue Tage können Sie normal bearbeiten.<br>' +
                '&bull; Für einen gesperrten Tag: den Plan zuerst in der Dispo aufheben.' +
                '</div>',
                { title: 'Geplante Einsätze vorhanden', confirmLabel: 'Trotzdem bearbeiten' });
            if (!proceed) return;
        }
    }

    _beState.selKunde = null;
    _beState.selObj = null;
    _beState.selArt = null;
    _beState.selArts = [];
    _beState.artikelPreise = {};
    _beState.activeArtTab = null;
    _beState.isEdit = true;
    _wizDayCount = 0;

    const [objOpts, allCustomers] = await Promise.all([
        getObjectOptions(order.kunden_id),
        apiGet(API.customers)
    ]);
    const allArr = Array.isArray(allCustomers) ? allCustomers : [];
    const activeCustomers = allArr.filter(c => c.aktiv == 1);
    let customerOpts = activeCustomers.map(c => `<md-select-option value="${c.kunde_id}">${c.kunde_id} – ${escapeHtml(c.kundenname)}</md-select-option>`).join('');
    if (order.kunden_id && !activeCustomers.find(c => String(c.kunde_id) === String(order.kunden_id))) {
        const inactiveCust = allArr.find(c => String(c.kunde_id) === String(order.kunden_id));
        if (inactiveCust) customerOpts += `<md-select-option value="${inactiveCust.kunde_id}">${inactiveCust.kunde_id} – ${escapeHtml(inactiveCust.kundenname)} (inaktiv)</md-select-option>`;
    }
    const customerBereich = order.kunden_bereich || (allArr.find(c => String(c.kunde_id) === String(order.kunden_id))?.bereich) || '';

    const body = beWizardBodyHtml(customerOpts, {
        kundenpreis: order.kundenpreis,
        bemerkung: order.bemerkung,
        monat: parseInt(order.monat),
        jahr: parseInt(order.jahr),
    });
    const footer = beWizardFooterHtml(true, order.eingang_id, order.status);

    openBeDialog('Bestelleingang bearbeiten — ' + order.eingang_id, body, footer, true);
    window._wizStep = 1;

    // Inject Step 2 dropdown options (already loaded)
    const objSel = document.getElementById('wiz_objekt');
    if (objSel) objSel.innerHTML = '<md-select-option value="">— Objekt wählen —</md-select-option>' + objOpts;

    // Pre-select bereich, kunde, objekt
    document.getElementById('wiz_kunde').value = order.kunden_id;
    await wizLoadObjects();
    document.getElementById('wiz_objekt').value = order.obj_id;
    await wizLoadArtikel();
    wizLockKundeObjekt();
    // Stored services missing from the loaded catalog (deactivated artikel
    // or outside this user's Bereich) are injected from artikel_liste so the
    // wizard still renders their chips/tabs/price panels — otherwise saving
    // would silently drop a hidden service and its days.
    window._wizArticles = window._wizArticles || [];
    const _restoreListe = Array.isArray(order.artikel_liste) ? order.artikel_liste : [];
    const _missingStored = _restoreListe.filter(st => st && st.artikel_id
        && !window._wizArticles.some(a => String(a.artikel_id) === String(st.artikel_id)));
    if (_missingStored.length) {
        // A stored service outside the caller's Bereich whitelist is
        // read-only for him (the backend re-merges it on save anyway):
        // mark it so the wizard locks its day rows instead of letting him
        // edit rows he is not allowed to write.
        if (window._beScopeMe === undefined) {
            try { window._beScopeMe = await apiGet(API.users + '/me'); } catch (e) { window._beScopeMe = null; }
        }
        const _me = window._beScopeMe || {};
        const _allowedB = Array.isArray(_me.allowed_bereiche) ? _me.allowed_bereiche.map(s => String(s).toLowerCase()) : [];
        const _restricted = _allowedB.length > 0 && !_me.is_owner
            && !['admin', 'tenant_admin', 'super_admin', 'owner'].includes(String(_me.role || '').toLowerCase());
        for (const st of _missingStored) {
            const bArt = String(st.bereich || '').toLowerCase();
            const derived = bArt !== '' ? bArt : String(customerBereich || '').toLowerCase();
            st._scopeLocked = !!(_restricted && derived !== '' && !_allowedB.includes(derived));
            window._wizArticles.push(st);
        }
    }
    // Multi-service restore: artikel_ids carries every selected Leistung
    // (JSON string from the API). Single-service/legacy orders fall back to
    // artikel_id alone.
    let storedArtIds = [];
    try {
        const parsed = typeof order.artikel_ids === 'string' ? JSON.parse(order.artikel_ids) : order.artikel_ids;
        if (Array.isArray(parsed)) storedArtIds = parsed.map(String).filter(Boolean);
    } catch (e) { /* malformed JSON → fall back to primary */ }
    if (storedArtIds.length === 0 && order.artikel_id) storedArtIds = [String(order.artikel_id)];
    for (const aid of storedArtIds) {
        // wizSelectArtikel toggles — never "re-select" an already selected one.
        if (!_beState.selArts.some(a => String(a.artikel_id) === aid)) await wizSelectArtikel(aid);
    }

    // Per-artikel pricing from the stored map; the legacy single modell /
    // kundenpreis columns seed the PRIMARY artikel's panel for old orders.
    let storedPreise = {};
    try {
        const parsed = typeof order.artikel_preise === 'string' ? JSON.parse(order.artikel_preise) : order.artikel_preise;
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) storedPreise = parsed;
    } catch (e) { /* ignore */ }
    Object.keys(storedPreise).forEach(aid => {
        if (storedPreise[aid] && typeof storedPreise[aid] === 'object') {
            _beState.artikelPreise[aid] = {
                modell: storedPreise[aid].modell || null,
                kundenpreis: storedPreise[aid].kundenpreis ?? null,
                bemerkung: storedPreise[aid].bemerkung ?? null,
            };
        }
    });
    if (_beState.selArt && !_beState.artikelPreise[_beState.selArt.artikel_id]) {
        _beState.artikelPreise[_beState.selArt.artikel_id] = {
            modell: order.modell || null,
            kundenpreis: order.kundenpreis ?? null,
            bemerkung: null,
        };
    }
    wizRenderPreisPanels();
    wizRenderArtTabs();

    // Pre-fill days from existing
    if (order.tage && order.tage.length > 0) {
        const tbody = document.getElementById('wizDaysBody');
        const firstDay = order.tage[0];
        if (firstDay.zeit_von) document.getElementById('wiz_shift_von').value = firstDay.zeit_von.substring(0, 5);
        if (firstDay.zeit_bis) document.getElementById('wiz_shift_bis').value = firstDay.zeit_bis.substring(0, 5);
        if (firstDay.menge) document.getElementById('wiz_shift_menge').value = Math.max(1, parseInt(firstDay.menge) || 1);
        if (firstDay.beschreibung != null && firstDay.beschreibung !== '') document.getElementById('wiz_shift_beschreibung').value = firstDay.beschreibung;
        setTimeout(wizUpdateObjBeschreibungHint, 200);
        // Header Ort is stored on the order itself (independent of the
        // per-day Tag-Ort). Legacy orders without the column/value fall back
        // to the first day's Ort so the template isn't blank.
        const headerOrtStored = (order.ort != null && String(order.ort).trim() !== '') ? String(order.ort).trim() : null;
        const anyDayOrt = (order.tage || []).map(t => (t.ort != null ? String(t.ort).trim() : '')).find(Boolean) || '';
        const einsatzOrt = (order.einsatz_ort != null ? String(order.einsatz_ort).trim() : '');
        // Fallback chain for legacy orders: first day with an Ort → Ort of
        // the generated Einsätze (einsatz_ort from the API). The field is
        // mandatory on save, so leaving it blank would block the edit.
        document.getElementById('wiz_shift_ort').value = headerOrtStored || anyDayOrt || einsatzOrt || '';
        // Use stored pause_minuten directly. Fallback to legacy derivation for old records
        // that pre-date the dedicated column on bestelleingang_tage.
        if (firstDay.pause_minuten !== undefined && firstDay.pause_minuten !== null) {
            document.getElementById('wiz_shift_pause').value = parseInt(firstDay.pause_minuten) || 0;
        } else if (firstDay.zeit_von && firstDay.zeit_bis && firstDay.soll_std) {
            const totalMin = beShiftTotalMinutes(firstDay.zeit_von, firstDay.zeit_bis);
            const pauseMin = Math.max(0, totalMin - Math.round(parseFloat(firstDay.soll_std) * 60));
            document.getElementById('wiz_shift_pause').value = pauseMin;
        }
        wizCalcShiftDuration();
        wizHydrateAnforderungen(wizParseAnf(firstDay.anforderungen));

        // Dates already stored on this order (multi-month orders exist) — the
        // out-of-month warning on save only concerns newly added dates.
        window._wizStoredDates = new Set(order.tage.map(t => (t.datum || '').substring(0, 10)));
        order.tage.forEach((t, idx) => {
            _wizDayCount++;
            const von = t.zeit_von ? t.zeit_von.substring(0, 5) : '';
            const bis = t.zeit_bis ? t.zeit_bis.substring(0, 5) : '';
            const dateStr = (t.datum || '').substring(0, 10);
            const dow = dateStr ? new Date(dateStr + 'T00:00:00').getDay() : 0;
            const gp = parseInt(t.gesetzl_pause) || 0;
            const pmin = (t.pause_minuten !== undefined && t.pause_minuten !== null) ? (parseInt(t.pause_minuten) || 0) : 0;
            wizAppendDayRow(tbody, _wizDayCount, dateStr, dow, von, bis,
                t.soll_std || '', gp, t.menge || '', t.beschreibung || '', pmin, wizParseAnf(t.anforderungen), t.ort || '', t.dispo_status || null, t.artikel_id || '');
            if (t.tag_uid) tbody.lastElementChild.dataset.tagUid = t.tag_uid;
        });
        // Days whose stored Ort differs from the header default already carry
        // a per-day location — flag them so a later header-Ort change does
        // not silently reset them (wizApplyMetaToRows respects the flag).
        const headerOrt = String(document.getElementById('wiz_shift_ort')?.value ?? '').trim();
        const headerPauseV = String(parseInt(document.getElementById('wiz_shift_pause')?.value) || 0);
        const headerAnfHydr = wizParseAnf(firstDay.anforderungen) || null;
        tbody.querySelectorAll('tr').forEach(r => {
            const rOrt = (r.dataset.ort || '').trim();
            // (an empty day-Ort while the header has one is also an override:
            // the user deliberately removed the Ort for that day)
            if (rOrt !== headerOrt) r.dataset.ortOverride = '1';
            // Days whose stored pause deviates from the header template are
            // deliberate per-day values — mark them so wizSyncPausenToRows()
            // leaves them alone on later header edits.
            // With a Pausenregelung the reference is the rule value of THIS
            // day — a stored pause that differs from it (e.g. a removed
            // pause = 0) was set on purpose and must survive the next save.
            const ruleP = beAutoPauseMin(r.dataset.von, r.dataset.bis);
            const refPauseV = ruleP !== null ? String(ruleP) : headerPauseV;
            if (String(parseInt(r.dataset.pauseMinuten) || 0) !== refPauseV) r.dataset.pauseOverride = '1';
            // A stored per-day Anforderungen that differs from the Vorlage is
            // deliberate — protect it from the template sync. Days with no
            // stored anf keep following the Vorlage (as before).
            const rAnf = wizParseAnf(r.dataset.anforderungen);
            if (rAnf && !_beCalSameAnf(rAnf, headerAnfHydr)) r.dataset.anfOverride = '1';
        });
        const pauseDiff = order.tage.filter(t => !['planned', 'confirmed'].includes(t.dispo_status || '')
            && (parseInt(t.pause_minuten) || 0) !== (parseInt(t.gesetzl_pause) || 0)).length;
        const pauseNote = document.getElementById('wizPauseNote');
        if (pauseNote && pauseDiff) {
            pauseNote.innerHTML = `${beIcon('info', 'font-size:15px')}<span><strong>${pauseDiff}</strong> Schicht(en) hatten bisher unterschiedliche MA- und Kunden-Pause. Ab jetzt gilt <strong>eine Pause</strong> für beide — beim Speichern wird die Kunden-Pause dieser Schichten auf die Pause gesetzt.</span>`;
            pauseNote.style.display = '';
        }
        wizUpdateDaysFooter();
    }
    // Editing is almost always about the days — open on "Tage". Kunde /
    // Objekt are locked anyway; earlier steps stay reachable via Zurück or
    // the stepper.
    wizGoToStep(_BE_TOTAL_STEPS);
}

// ============================================================
// COPY — duplicate an order into another month
// ============================================================
// Shift 'YYYY-MM-DD' by a whole number of months, keeping the day-of-month
// (clamped to the target month's last day, e.g. 31.01. -> 28.02.).
function beShiftDateByMonths(dateStr, monthOffset) {
    const [y, m, d] = dateStr.split('-').map(Number);
    if (!y || !m || !d) return dateStr;
    const total = (y * 12 + (m - 1)) + monthOffset;
    const ny = Math.floor(total / 12);
    const nm = (total % 12 + 12) % 12 + 1;
    const lastDay = new Date(ny, nm, 0).getDate();
    const nd = Math.min(d, lastDay);
    return `${ny}-${String(nm).padStart(2, '0')}-${String(nd).padStart(2, '0')}`;
}

// True when 'YYYY-MM-DD' lies in month m (1-12) of year y.
function beInMonth(dateStr, m, y) {
    return parseInt(String(dateStr).substring(5, 7)) === m && parseInt(String(dateStr).substring(0, 4)) === y;
}

// Add a whole number of days to 'YYYY-MM-DD'.
function beAddDays(dateStr, days) {
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() + days);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// Compute the shifted dates for ALL source days according to the selected
// mode. 'wochentag' shifts everything by whole weeks so each date keeps its
// weekday (Sa bleibt Sa) — essential for weekend-based shift patterns.
// 'kalendertag' keeps the day-of-month instead (08.08. -> 08.10.).
function beCopyShiftedDates(order, tm, tj, mode) {
    const offset = (tj * 12 + tm) - (parseInt(order.jahr) * 12 + parseInt(order.monat));
    const srcDates = (order.tage || []).map(t => (t.datum || '').substring(0, 10));
    if (mode === 'wochentag') {
        const minDate = srcDates.slice().sort()[0];
        // Land the first day near the same calendar position, then snap to
        // the closest date with the SAME weekday (±3 days). Shifting all days
        // by this whole-week delta preserves every weekday and every gap.
        const roughTarget = beShiftDateByMonths(minDate, offset);
        const wdSrc = new Date(minDate + 'T00:00:00').getDay();
        let delta = new Date(roughTarget + 'T00:00:00').getDay() - wdSrc;
        if (delta > 3) delta -= 7;
        if (delta < -3) delta += 7;
        const anchor = beAddDays(roughTarget, -delta);
        const dayShift = Math.round((new Date(anchor + 'T00:00:00') - new Date(minDate + 'T00:00:00')) / 86400000);
        return srcDates.map(d => beAddDays(d, dayShift));
    }
    return srcDates.map(d => beShiftDateByMonths(d, offset));
}

// Preview inside the copy dialog: recompute the shifted date range live.
function beCopyUpdatePreview() {
    const order = window._beCopySource;
    if (!order) return;
    const tm = parseInt(document.getElementById('beCopyMonat').value);
    const tj = parseInt(document.getElementById('beCopyJahr').value);
    const mode = document.querySelector('md-radio[name="beCopyMode"][checked], input[name="beCopyMode"]:checked')?.value || 'wochentag';
    const all = beCopyShiftedDates(order, tm, tj, mode);
    const dates = all.filter(d => beInMonth(d, tm, tj)).sort();
    const dropped = all.length - dates.length;
    const prev = document.getElementById('beCopyPreview');
    if (!prev) return;
    if (!dates.length) { prev.innerHTML = `${beIcon('warning', 'font-size:13px;vertical-align:-2px')} Kein Tag liegt im Zielmonat.`; return; }
    const wdShort = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    const wd = (s) => wdShort[new Date(s + 'T00:00:00').getDay()];
    prev.innerHTML = `${beIcon('event', 'font-size:13px;vertical-align:-2px')} ${dates.length} Tag(e): <strong>${wd(dates[0])} ${formatDate(dates[0])}</strong> bis <strong>${wd(dates[dates.length - 1])} ${formatDate(dates[dates.length - 1])}</strong>`
        + (dropped ? `<br>${beIcon('info', 'font-size:13px;vertical-align:-2px')} ${dropped} Tag(e) fallen außerhalb des Zielmonats und werden nicht übernommen.` : '');
}

async function copyIncomingOrder(id) {
    const order = await apiGet(API.incoming_orders + '?id=' + id);
    if (!order || order.error) { showToast('Bestellung nicht gefunden', 'error'); return; }
    if (!order.tage || order.tage.length === 0) { showToast('Diese Bestellung hat keine Einsatztage', 'error'); return; }

    window._beCopySource = order;

    // Object address as Ort fallback for days without a stored Ort
    // (older orders pre-date the Ort column) and the object's Hinweise as
    // Bemerkung fallback when the source order has none. Best-effort fetch.
    window._beCopyObjOrt = '';
    window._beCopyObjHinweise = '';
    try {
        const obj = await apiGet(API.objects + '?id=' + order.obj_id);
        if (obj && !obj.error) {
            const parts = [obj.adresse, [obj.plz, obj.stadt].filter(Boolean).join(' ')].filter(s => s && String(s).trim() !== '');
            window._beCopyObjOrt = parts.join(', ');
            window._beCopyObjHinweise = (obj.hinweise || '').trim();
        }
    } catch (e) { /* optional */ }

    // Default target: source month + 1
    const srcM = parseInt(order.monat), srcJ = parseInt(order.jahr);
    const defM = srcM === 12 ? 1 : srcM + 1;
    const defJ = srcM === 12 ? srcJ + 1 : srcJ;

    const monthOpts = Array.from({ length: 12 }, (_, i) => i + 1).map(m =>
        `<md-select-option value="${m}" ${m === defM ? 'selected' : ''}>${MONTHS_DE[m]}</md-select-option>`).join('');
    const yearOpts = [srcJ - 1, srcJ, srcJ + 1, srcJ + 2].map(y =>
        `<md-select-option value="${y}" ${y === defJ ? 'selected' : ''}>${y}</md-select-option>`).join('');

    const body = `
        <div style="margin-bottom:16px;background: var(--gray-50,var(--md-sys-color-primary-container));border: 1px solid var(--gray-200,var(--md-sys-color-primary-container));border-radius:10px;padding:12px 16px">
            <div style="font-weight:600;margin-bottom:4px">${beIcon('content_copy', 'font-size:14px;vertical-align:-2px')} Vorlage: ${escapeHtml(order.eingang_id)}</div>
            <div style="font-size:0.85rem;color:var(--be-text-muted)">
                ${escapeHtml(order.kundenname || order.kunden_id || '–')} — ${escapeHtml(order.objektname || order.obj_id || '–')}<br>
                Zeitraum: <strong>${MONTHS_DE[srcM] || srcM} ${srcJ}</strong> · ${order.tage.length} Einsatztag(e)<br>
                ${beIcon('location_on', 'font-size:12px;vertical-align:-2px')} Ort / Treffpunkt: <strong id="beCopyOrtPreview">${escapeHtml(beCopyResolveOrt(order) || '–')}</strong>
            </div>
        </div>
        <div class="form-group">
            <label style="font-weight:600">Neuer Zeitraum</label>
            <div style="display:flex;gap:10px;margin-top:6px">
                <md-outlined-select id="beCopyMonat" class="form-control" style="flex:1" onchange="beCopyUpdatePreview()">${monthOpts}</md-outlined-select>
                <md-outlined-select id="beCopyJahr" class="form-control" style="width:110px" onchange="beCopyUpdatePreview()">${yearOpts}</md-outlined-select>
            </div>
        </div>
        <div class="form-group" style="margin-top:14px">
            <label style="font-weight:600">Verschiebung der Tage</label>
            <label style="display:flex;gap:10px;align-items:flex-start;margin-top:8px;cursor:pointer">
                <md-radio name="beCopyMode" value="wochentag" checked onchange="beCopyUpdatePreview()" style="margin-top:3px"></md-radio>
                <span><strong>Gleicher Wochentag</strong> (empfohlen)<br>
                <span style="font-size:0.8rem;color:var(--be-text-muted)">Verschiebt um ganze Wochen — Samstag bleibt Samstag, Wochenend-Muster (z. B. 24h-Abdeckung) bleiben erhalten.</span></span>
            </label>
            <label style="display:flex;gap:10px;align-items:flex-start;margin-top:8px;cursor:pointer">
                <md-radio name="beCopyMode" value="kalendertag" onchange="beCopyUpdatePreview()" style="margin-top:3px"></md-radio>
                <span><strong>Gleicher Kalendertag</strong><br>
                <span style="font-size:0.8rem;color:var(--be-text-muted)">08.08. wird 08.10. — Wochentage können dadurch abweichen.</span></span>
            </label>
        </div>
        <div id="beCopyPreview" style="margin-top:10px;font-size:0.85rem;color:var(--be-text-muted)"></div>
        <div style="margin-top:14px;font-size:0.8rem;color:var(--be-text-muted)">
            ${beIcon('info', 'font-size:14px;vertical-align:-2px')} Alle Zeiten, Pausen, Mengen und Anforderungen werden 1:1 übernommen.
            Prüfen Sie die Tage anschließend über <strong>Bearbeiten</strong>.
        </div>`;

    const footer = `
        <md-text-button onclick="closeBeDialog()">Abbrechen</md-text-button>
        <md-filled-button onclick="beExecuteCopy()" style="--md-filled-button-container-color:var(--be-accent)"><md-icon slot="icon">content_copy</md-icon>Kopieren</md-filled-button>`;

    openBeDialog('Bestellung kopieren — ' + order.eingang_id, body, footer);
    beCopyUpdatePreview();
}

// Header Ort / Treffpunkt that a copy inherits from its source. Older orders
// (pre header-`ort` column) only carry it on the days or on the generated
// Einsätze (einsatz_ort from the API), so fall back through those before
// using the Objekt address. The field is mandatory on save.
function beCopyResolveOrt(order) {
    const s = v => (v != null ? String(v).trim() : '');
    return s(order.ort)
        || (order.tage || []).map(t => s(t.ort)).find(Boolean)
        || s(order.einsatz_ort)
        || s(window._beCopyObjOrt)
        || '';
}

async function beExecuteCopy() {
    const order = window._beCopySource;
    if (!order) return;

    const tm = parseInt(document.getElementById('beCopyMonat').value);
    const tj = parseInt(document.getElementById('beCopyJahr').value);
    if (tm === parseInt(order.monat) && tj === parseInt(order.jahr)) {
        if (!(await mdConfirm('Der neue Zeitraum ist identisch mit dem Original. Trotzdem kopieren?', 'Gleicher Zeitraum'))) return;
    }
    const mode = document.querySelector('md-radio[name="beCopyMode"][checked], input[name="beCopyMode"]:checked')?.value || 'wochentag';
    const shifted = beCopyShiftedDates(order, tm, tj, mode);

    const headerOrt = beCopyResolveOrt(order);
    const tage = order.tage.map((t, idx) => {
        const dateStr = shifted[idx];
        if (!beInMonth(dateStr, tm, tj)) return null; // belongs to another month
        const dow = new Date(dateStr + 'T00:00:00').getDay();
        return {
            tag_nr: idx + 1,
            datum: dateStr,
            wochentag: ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][dow],
            zeit_von: t.zeit_von ? t.zeit_von.substring(0, 5) : null,
            zeit_bis: t.zeit_bis ? t.zeit_bis.substring(0, 5) : null,
            soll_std: t.soll_std != null ? parseFloat(t.soll_std) : null,
            pause_minuten: (t.pause_minuten !== undefined && t.pause_minuten !== null) ? (parseInt(t.pause_minuten) || 0) : null,
            gesetzl_pause: (t.gesetzl_pause !== undefined && t.gesetzl_pause !== null) ? (parseInt(t.gesetzl_pause) || 0) : null,
            menge: t.menge || null,
            beschreibung: t.beschreibung || null,
            // Keep a day's OWN Tag-Ort; days without one inherit the header
            // Ort on the server (resolveEinsatzOrt) — don't bake the Objekt
            // address into every day here, that would hide later header edits.
            ort: (t.ort && String(t.ort).trim() !== '') ? t.ort : null,
            anforderungen: wizParseAnf(t.anforderungen),
            // Multi-service orders: each day keeps ITS service.
            artikel_id: t.artikel_id || null,
            bemerkung: null
        };
    }).filter(Boolean).map((t, i) => ({ ...t, tag_nr: i + 1 }));
    if (!tage.length) { showToast('Kein Tag liegt im Zielmonat — nichts zu kopieren', 'error'); return; }

    if (!headerOrt) {
        showToast('Die Vorlage hat keinen Ort / Treffpunkt — bitte die Original-Bestellung zuerst bearbeiten und einen Ort eintragen', 'error');
        return;
    }

    const data = {
        kunden_id: order.kunden_id,
        obj_id: order.obj_id,
        artikel_id: order.artikel_id || null,
        artikel_ids: beArtikelIdsOf(order),
        artikel_preise: (order.artikel_preise && typeof order.artikel_preise === 'object' && !Array.isArray(order.artikel_preise)) ? order.artikel_preise : null,
        monat: tm,
        jahr: tj,
        zeit_von: tage[0]?.zeit_von || null,
        zeit_bis: tage[0]?.zeit_bis || null,
        ort: headerOrt,
        modell: order.modell || null,
        kundenpreis: order.kundenpreis != null ? parseFloat(order.kundenpreis) : null,
        bemerkung: (order.bemerkung && String(order.bemerkung).trim() !== '')
            ? order.bemerkung
            : (window._beCopyObjHinweise || null),
        tage: tage
    };

    const result = await apiPost(API.incoming_orders, data);
    if (result.success) {
        closeBeDialog();
        window._beCopySource = null;
        showToast(`Bestellung kopiert — ${result.eingang_id} für ${MONTHS_DE[tm] || tm} ${tj} erstellt (${result.tasks_created} Einsätze)`);
        renderIncomingOrders();
    } else {
        showToast(result.error || 'Fehler beim Kopieren', 'error');
    }
}

async function deleteIncomingOrder(id) {
    const ok = await mdConfirm('Bestelleingang "' + escapeHtml(id) + '" endgültig löschen?<br><br><strong>Die zugehörige Bestellung und alle offenen Einsätze werden ebenfalls gelöscht!</strong><br>Bestätigte Einsätze bleiben als stornierte Historie (grau) in der Planung erhalten.', 'Bestelleingang löschen');
    if (!ok) return;
    const result = await apiDelete(API.incoming_orders + '/' + id);
    if (result.success) {
        showToast('Bestelleingang gelöscht');
        renderIncomingOrders();
    } else if (result.blocked_shifts || result.eingang_id) {
        mdAlert(escapeHtml(result.error || 'Fehler beim Löschen') + (Array.isArray(result.blocked_shifts) && result.blocked_shifts.length ? '<br><br>&bull; ' + result.blocked_shifts.map(escapeHtml).join('<br>&bull; ') : ''), 'Löschen nicht möglich');
    } else {
        showToast(result.error || 'Fehler beim Löschen', 'error');
    }
}

/* ---- Realtime hook: refresh the list when orders change elsewhere.
   Only re-renders while the LIST view is on screen — an open edit
   dialog or a detail view keeps its state untouched. ---- */
if (window.HMSRealtime) {
    HMSRealtime.on('eingang', () => {
        try {
            if (document.getElementById('beMdDialog') && document.getElementById('beMdDialog').open) return;
            if (document.querySelector('#content .be-table-card')) beFetchPage();
        } catch (e) { /* best-effort */ }
    });
}

