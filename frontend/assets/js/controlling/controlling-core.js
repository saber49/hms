/* ============================================
   Clood-X — Auswertung & Controlling · Core
   Shared state, constants, small helpers and the Chart.js loader.
   Must be loaded BEFORE every other controlling-*.js file.
   ============================================ */

const CTRL = {
    api: API_BASE + '/controlling',
    state: {
        period: 'month',                  // today | week | month | quarter | year | ytd | custom | last_month
        from: null,
        to: null,
        compare: 'prev',                  // prev | year
        granularity: 'auto',              // auto | day | week | month | quarter
        filters: { kunden_id: '', obj_id: '', nu_id: '', bereich: '', dienst_nr: '' },
        sort: { col: 'datum', dir: 'desc' },
        page: 1,
        perPage: 25,
    },
    charts: {},                            // chart instances by id
    cache: {},                             // combined-dashboard payload slices
    snapshotMode: null,                    // {id,title,date_from,date_bis} while viewing a snapshot
    palette: {
        primary:  '#1a2744',
        accent:   '#3b82f6',
        success:  '#10b981',
        warning:  '#f59e0b',
        danger:   '#ef4444',
        info:     '#06b6d4',
        orange:   '#f97316',
        gray:     '#94a3b8',
    },
};

const WEEKDAYS_DE = ['Mo','Di','Mi','Do','Fr','Sa','So'];
const MONTHS_SHORT_DE = ['','Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];

// ---------- MD3 helpers (Material Symbols) for the Controlling page ----------
// Confirms/prompts come from mdConfirm()/mdPrompt() (users.js) — global at runtime.
function ctrlIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}
const ctrlSpinner = (size = 32) => `<md-circular-progress indeterminate style="--md-circular-progress-size:${size}px"></md-circular-progress>`;

// Convert legacy '<option value="x">Label</option>' HTML strings (shared
// getCustomerOptions()/getObjectOptions()/getSubcontractorOptions() helpers)
// into <md-select-option> markup for md-outlined-select.
// `display-text` keeps the collapsed field single-line: long names (e.g.
// "O026 – Absicherung Durchfahrtsverbot / Kleine Ulrichstraße / Halle") are
// shortened in the field only — the dropdown menu still shows the full label.
const CTRL_DISPLAY_MAX = 34;
function ctrlDisplayText(label) {
    const s = String(label || '').trim();
    return s.length > CTRL_DISPLAY_MAX ? s.slice(0, CTRL_DISPLAY_MAX - 1).trimEnd() + '…' : s;
}
function ctrlToMdOptions(optHtml, emptyLabel, selectedValue = '') {
    const sel = document.createElement('select');
    sel.innerHTML = optHtml || '';
    let out = `<md-select-option value=""${selectedValue ? '' : ' selected'}><div slot="headline">${emptyLabel}</div></md-select-option>`;
    for (const o of sel.options) {
        if (o.value === '') continue;
        const isSel = selectedValue && o.value === selectedValue;
        out += `<md-select-option value="${escapeHtml(o.value)}" display-text="${escapeHtml(ctrlDisplayText(o.textContent))}"${isSel ? ' selected' : ''}>` +
               `<div slot="headline">${escapeHtml(o.textContent)}</div></md-select-option>`;
    }
    return out;
}

// ---------- Skeleton helpers ----------
function ctrlKpiSkeleton() {
    return Array.from({ length: 6 }, () =>
        '<div class="ctrl-kpi-card" style="min-height:96px">' +
            '<div class="ctrl-skeleton" style="height:14px;width:55%;margin-bottom:12px"></div>' +
            '<div class="ctrl-skeleton" style="height:26px;width:75%;margin-bottom:8px"></div>' +
            '<div class="ctrl-skeleton" style="height:10px;width:40%"></div>' +
        '</div>'
    ).join('');
}
function ctrlBdSkeleton() {
    return '<div style="padding:14px">' +
        Array.from({ length: 4 }, () =>
            '<div class="ctrl-skeleton" style="height:14px;margin-bottom:10px"></div>'
        ).join('') +
    '</div>';
}
function ctrlHeatmapSkeleton() {
    return '<div class="ctrl-skeleton" style="height:240px;margin:14px"></div>';
}

// ---------- Empty / error states ----------
function ctrlEmptyHtml(icon, msg) {
    return `<div class="ctrl-bd-empty">${ctrlIcon(icon)}<div>${msg}</div></div>`;
}

// ---------- Request params ----------
function ctrlBuildParams(extra = {}) {
    const p = new URLSearchParams();
    if (CTRL.state.period === 'custom' && CTRL.state.from && CTRL.state.to) {
        p.set('from', CTRL.state.from);
        p.set('to',   CTRL.state.to);
    } else {
        p.set('period', CTRL.state.period);
    }
    if (CTRL.state.compare) p.set('compare', CTRL.state.compare);
    // Operativ / Abrechnung (cockpit toggle) — drill-down, details, exports
    // and the worker card calculate exactly like the cockpit.
    if (typeof CTRLC !== 'undefined' && CTRLC.basis) p.set('basis', CTRLC.basis);
    Object.entries(CTRL.state.filters).forEach(([k, v]) => { if (v) p.set(k, v); });
    Object.entries(extra).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') p.set(k, v); });
    return p.toString();
}

/** Active "Verteilung" tab id (customers|objects|subcontractors|bereich). */
function currentBdTab() {
    return document.querySelector('.bd-tab.active')?.dataset.d || 'customers';
}

/**
 * Map a Verteilung tab id to the matching slice of the combined dashboard payload.
 */
function pickBreakdownForTab(breakdown, tab) {
    if (!breakdown) return null;
    switch (tab) {
        case 'customers':      return breakdown.customers;
        case 'objects':        return breakdown.objects;
        case 'subcontractors': return breakdown.subcontractors;
        case 'bereich':        return breakdown.bereich;
        default:               return breakdown.customers;
    }
}

// ---------- Utilities ----------
function isoDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/**
 * Robustly resolve when Chart.js is available, regardless of how it was loaded.
 *  - If Chart is already defined → resolve immediately
 *  - If a <script> tag for Chart.js already exists (defer-loaded in index.php)
 *    → poll until it finishes (no duplicate inject)
 *  - Otherwise inject a new script tag
 *  - Hard timeout of 8 seconds so chart loaders fail gracefully instead of
 *    hanging the entire dashboard forever
 */
let _chartJsPromise = null;
function loadChartJs() {
    if (_chartJsPromise) return _chartJsPromise;
    _chartJsPromise = new Promise((resolve, reject) => {
        if (typeof Chart !== 'undefined') return resolve();

        const TIMEOUT_MS = 8000;
        let done = false;
        const finish = (ok, err) => {
            if (done) return;
            done = true;
            ok ? resolve() : reject(err || new Error('Chart.js load failed'));
        };

        // Poll — covers both "already loading" and "just loaded" cases.
        const startedAt = Date.now();
        const tick = setInterval(() => {
            if (typeof Chart !== 'undefined') {
                clearInterval(tick);
                finish(true);
            } else if (Date.now() - startedAt > TIMEOUT_MS) {
                clearInterval(tick);
                finish(false, new Error('Chart.js timeout after ' + TIMEOUT_MS + 'ms'));
            }
        }, 100);

        // If no <script> tag for chart.js exists at all, inject one.
        const existing = document.querySelector('script[src*="chart.js"],script[src*="chart.umd"],script[src*="chart.min"]');
        if (!existing) {
            const s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
            s.onload = () => { clearInterval(tick); finish(true); };
            s.onerror = () => { clearInterval(tick); finish(false, new Error('CDN failed')); };
            document.head.appendChild(s);
        }
    });
    // Reset promise on failure so a retry can be triggered
    _chartJsPromise.catch(() => { _chartJsPromise = null; });
    return _chartJsPromise;
}

/** Helper: ensure Chart.js is ready before drawing. Returns false if it failed
 *  so the caller can show a graceful error in the chart card. */
async function ensureChartReady() {
    if (typeof Chart !== 'undefined') return true;
    try { await loadChartJs(); return true; }
    catch (e) { console.warn('[Controlling] Chart.js not available:', e); return false; }
}
