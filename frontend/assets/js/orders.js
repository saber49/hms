/* ============================================================
   HMS / Clood-X — Bestellungen (Orders) Module v2
   Identity: Slate-900 + Coral-500
   Phase A+B+C: Bento KPIs + Filters + Analytics + CSV Export
   Backend logic UNCHANGED
   ============================================================ */

const _orState = {
    orders: [],
    tab: 'overview',          // 'overview' | 'analytics'
    filterStatus: 'alle',     // 'alle' | 'offen' | 'abgeschlossen' | 'storniert' | 'ueberfaellig'
    filterMonth: '',          // 'YYYY-MM' or ''
    filterKunde: '',
    sort: 'newest',           // 'newest' | 'oldest' | 'amount-desc' | 'amount-asc' | 'progress'
    search: '',
};
let _orCharts = {};

// ---------- MD3 helpers (official <md-dialog> + Material Symbols) ----------
// Confirms come from mdConfirm() (users.js) — global at runtime.
function orIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}
const orSpinner = (size = 32) => `<md-circular-progress indeterminate style="--md-circular-progress-size:${size}px"></md-circular-progress>`;

// Task description shared by all planned days — shown once, collapsed to a
// few lines. "•"-separated texts (typical for pasted customer briefs)
// render as a bullet list; the leading sentence stays the intro line.
function orDescBoxHtml(text, dayCount) {
    const parts = text.split(/\s*•\s*/).map(s => s.trim()).filter(Boolean);
    const body = parts.length >= 3
        ? `${text.trim().startsWith('•') ? '' : `<p>${escapeHtml(parts.shift())}</p>`}<ul>${parts.map(p => `<li>${escapeHtml(p)}</li>`).join('')}</ul>`
        : `<p>${escapeHtml(text)}</p>`;
    const long = text.length > 260;
    return `<div class="or-desc-box${long ? ' collapsed' : ''}">
        <div class="or-desc-head">${orIcon('assignment', 'font-size:15px')} Aufgabenbeschreibung <span class="or-desc-hint">gilt für alle ${dayCount} Tage</span></div>
        <div class="or-desc-body">${body}</div>
        ${long ? `<md-text-button class="or-desc-toggle" onclick="const b=this.closest('.or-desc-box');b.classList.toggle('collapsed');this.textContent=b.classList.contains('collapsed')?'Mehr anzeigen':'Weniger anzeigen'">Mehr anzeigen</md-text-button>` : ''}
    </div>`;
}
function openOrDialog(title, bodyHtml, footerHtml = '', wide = false) {
    document.getElementById('orMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'orMdDialog';
    dlg.classList.add('or-context');
    if (wide) dlg.classList.add('or-dialog-xl');
    dlg.innerHTML = `
        <div slot="headline">${title}</div>
        <div slot="content" class="or-dialog-content" id="orDialogBody">${bodyHtml}</div>
        ${footerHtml ? `<div slot="actions">${footerHtml}</div>` : ''}`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();
    return dlg;
}
function closeOrDialog() { document.getElementById('orMdDialog')?.close(); }

// Jump to Monatsabschluss in Modus "Kunde", on the order's month and customer.
function orOpenKundenFreigabe(monat, jahr, kunde) {
    window._maPreset = { mode: 'kunde', monat, jahr, kunde };
    closeOrDialog();
    if (typeof loadPage === 'function') loadPage('monatsabschluss');
}

// ============================================================
// ENTRY POINT
// ============================================================
async function renderOrders() {
    const content = document.getElementById('content');
    if (!content) return;
    // Page already on screen (after a save): keep it, patch when data is there.
    const soft = !!document.querySelector('#content > .or-page') && typeof hmsMorph === 'function';

    if (!soft) content.innerHTML = `
        <div class="or-page">
            <div id="orAlertWrap"></div>
            <div id="orBentoWrap"></div>
            ${orBuildToolbar()}
            <div id="orMainBody">
                <div class="or-loading">${orSpinner(28)} Lade Bestellungen...</div>
            </div>
        </div>
    `;

    let orders = [];
    try {
        orders = await apiGet(API.orders);
    } catch (e) {
        if (soft) { showToast('Bestellungen konnten nicht aktualisiert werden', 'error'); return; }
        document.getElementById('orMainBody').innerHTML = `<div class="or-empty">${orIcon('warning')}<p>Fehler beim Laden</p></div>`;
        return;
    }

    _orState.orders = Array.isArray(orders) ? orders : [];

    // The toolbar was rendered before the data arrived — rebuild it so the
    // Monat/Kunde selects get their real options (search/sort state is kept
    // in _orState, so nothing is lost).
    const orTb = document.querySelector('.or-toolbar');
    if (soft) {
        // Patch toolbar (keeps listeners + typed search), tiles, rows.
        if (orTb) hmsMorph(orTb, new DOMParser().parseFromString(orBuildToolbar(), 'text/html').body.firstElementChild?.innerHTML || '');
        hmsMorph(document.getElementById('orBentoWrap'), orBuildBento());
        orRenderAlert();
        if (_orState.tab !== 'analytics' && document.getElementById('orTbody')) { orRenderTable(); return; }
    } else {
        if (orTb) orTb.outerHTML = orBuildToolbar();
        // Render bento + alert + body
        document.getElementById('orBentoWrap').innerHTML = orBuildBento();
        orRenderAlert();
        orBindToolbar();
    }

    if (_orState.tab === 'analytics') {
        orRenderAnalytics();
    } else {
        orRenderOverview();
    }
}

// ============================================================
// HEADER
// ============================================================
function orBuildHeader() {
    const total = _orState.orders.length;
    const lateCount = _orState.orders.filter(o => o.ueberfaellig).length;
    return `
        <div class="or-header">
            <div class="or-header-title">
                <div class="or-header-icon">${orIcon('receipt_long')}</div>
                <div>
                    <div class="or-header-title-text">Bestellungen</div>
                    <div class="or-header-title-sub">Auftr&auml;ge &amp; Vertr&auml;ge</div>
                </div>
            </div>
            <div class="or-header-badges">
                <div class="or-header-badge" id="orHeaderTotal">${orIcon('receipt_long', 'font-size:12px;vertical-align:-2px')} ${total} Bestellungen</div>
                ${lateCount > 0 ? `<div class="or-header-badge danger">${orIcon('warning', 'font-size:12px;vertical-align:-2px')} ${lateCount} &uuml;berf&auml;llig</div>` : ''}
            </div>
        </div>
    `;
}

// ============================================================
// BENTO KPIs (6 tiles)
// ============================================================
function orEstimatedRevenue(o) {
    const price = parseFloat(o.obj_preis_kunde) || 0;
    const model = o.abrechnungsmodell || 'Pro Stunde';
    if (model === 'Pro Einsatz') return price * (parseInt(o.einsatz_total) || 0);
    if (model === 'Monatspauschale') return price;
    return price * (parseFloat(o.soll_std_betrag) || 0);
}

function orBuildBento() {
    const orders = _orState.filterMonth
        ? _orState.orders.filter(o => {
            const [y, m] = _orState.filterMonth.split('-');
            return parseInt(o.jahr) === parseInt(y) && parseInt(o.monat) === parseInt(m);
        })
        : _orState.orders;
    const total = orders.length;
    const offen = orders.filter(o => o.status === 'offen').length;
    const abgeschlossen = orders.filter(o => o.status === 'abgeschlossen').length;
    const storniert = orders.filter(o => o.status === 'storniert').length;
    const late = orders.filter(o => o.ueberfaellig).length;

    // Î£ Soll-Std (only for active and completed, exclude canceled)
    const totalSoll = orders
        .filter(o => o.status !== 'storniert')
        .reduce((sum, o) => sum + (parseFloat(o.soll_std_betrag) || 0), 0);

    // Estimated revenue: soll_std * obj_preis_kunde (only for non-canceled)
    const estRevenue = orders
        .filter(o => o.status !== 'storniert')
        .reduce((sum, o) => sum + orEstimatedRevenue(o), 0);

    // Completion rate
    const closedRate = total > 0 ? Math.round((abgeschlossen / total) * 100) : 0;

    // Overall Einsätze progress (across all non-canceled orders)
    const totalEinsatz = orders
        .filter(o => o.status !== 'storniert')
        .reduce((sum, o) => sum + (parseInt(o.einsatz_total) || 0), 0);
    const totalConfirmed = orders
        .filter(o => o.status !== 'storniert')
        .reduce((sum, o) => sum + (parseInt(o.einsatz_bestaetigt) || 0), 0);
    const confirmationRate = totalEinsatz > 0 ? Math.round((totalConfirmed / totalEinsatz) * 100) : 0;

    return `
        <div class="or-bento">
            <div class="or-kpi" data-clickable="1" onclick="orSetFilter('alle')">
                <div class="or-kpi-head">
                    <div class="or-kpi-icon">${orIcon('receipt_long')}</div>
                    <span class="or-kpi-label">Total</span>
                </div>
                <div class="or-kpi-value">${total}</div>
                <div class="or-kpi-sub">${offen} offen &middot; ${abgeschlossen} geschlossen</div>
            </div>

            <div class="or-kpi open" data-clickable="1" onclick="orSetFilter('offen')">
                <div class="or-kpi-head">
                    <div class="or-kpi-icon">${orIcon('progress_activity')}</div>
                    <span class="or-kpi-label">Offen</span>
                </div>
                <div class="or-kpi-value">${offen}</div>
                <div class="or-kpi-sub">in Bearbeitung</div>
            </div>

            <div class="or-kpi done" data-clickable="1" onclick="orSetFilter('abgeschlossen')">
                <div class="or-kpi-head">
                    <div class="or-kpi-icon">${orIcon('check_circle')}</div>
                    <span class="or-kpi-label">Abgeschlossen</span>
                </div>
                <div class="or-kpi-value">${closedRate}<span class="or-kpi-value-suffix">%</span></div>
                <div class="or-progress"><div class="or-progress-fill done" style="width:${closedRate}%"></div></div>
                <div class="or-kpi-sub">${abgeschlossen} von ${total}</div>
            </div>

            <div class="or-kpi coral">
                <div class="or-kpi-head">
                    <div class="or-kpi-icon">${orIcon('schedule')}</div>
                    <span class="or-kpi-label">Î£ Soll-Std</span>
                </div>
                <div class="or-kpi-value">${totalSoll.toFixed(1)}<span class="or-kpi-value-suffix">h</span></div>
                <div class="or-kpi-sub">geplante Stunden</div>
            </div>

            <div class="or-kpi coral">
                <div class="or-kpi-head">
                    <div class="or-kpi-icon">${orIcon('euro')}</div>
                    <span class="or-kpi-label">Umsatz (gesch&auml;tzt)</span>
                </div>
                <div class="or-kpi-value">${(estRevenue/1000).toFixed(1)}<span class="or-kpi-value-suffix">k €</span></div>
                <div class="or-kpi-sub">${estRevenue.toLocaleString('de-DE', {maximumFractionDigits: 0})} €</div>
            </div>

            <div class="or-kpi ${late > 0 ? 'late' : 'done'}" ${late > 0 ? 'data-clickable="1" onclick="orSetFilter(\'ueberfaellig\')"' : ''}>
                <div class="or-kpi-head">
                    <div class="or-kpi-icon">${orIcon('warning')}</div>
                    <span class="or-kpi-label">&Uuml;berf&auml;llig</span>
                </div>
                <div class="or-kpi-value">${late}</div>
                <div class="or-kpi-sub">${late === 0 ? 'Alles im Zeitplan' : 'datum_bis &uuml;berschritten'}</div>
            </div>
        </div>
    `;
}

// ============================================================
// LATE ORDERS ALERT
// ============================================================
function orRenderAlert() {
    const wrap = document.getElementById('orAlertWrap');
    if (!wrap) return;
    const lateOrders = _orState.orders.filter(o => o.ueberfaellig);
    if (lateOrders.length === 0) { wrap.innerHTML = ''; return; }

    wrap.innerHTML = `
        <div class="or-alert">
            <div class="or-alert-icon">${orIcon('warning')}</div>
            <div class="or-alert-text">
                <div class="or-alert-title">${lateOrders.length} überfällige Bestellung${lateOrders.length > 1 ? 'en' : ''}</div>
                <div class="or-alert-sub">datum_bis bereits überschritten — Status noch "offen". Bitte abschließen oder verlängern.</div>
            </div>
            <md-text-button class="or-alert-action" onclick="orSetFilter('ueberfaellig')"><md-icon slot="icon">visibility</md-icon>Anzeigen</md-text-button>
        </div>
    `;
}

// ============================================================
// TOOLBAR
// ============================================================
function orBuildToolbar() {
    // Unique kundens for filter
    const kundens = [..._orState.orders.reduce((map, o) => {
        if (o.kunden_id && !map.has(o.kunden_id)) map.set(o.kunden_id, o.kundenname || o.kunden_id);
        return map;
    }, new Map())].map(([id, name]) => ({ id, name }))
      .sort((a, b) => a.name.localeCompare(b.name));

    // Months list
    const monthSet = new Set();
    _orState.orders.forEach(o => {
        if (o.monat && o.jahr) monthSet.add(`${o.jahr}-${String(o.monat).padStart(2, '0')}`);
    });
    const monthList = [...monthSet].sort().reverse();

    return `
        <div class="or-toolbar">
            <md-tabs class="or-tabs">
                <md-secondary-tab data-seg inline-icon class="or-tab ${_orState.tab === 'overview' ? 'active' : ''}" onclick="orSetTab('overview')"><md-icon slot="icon">checklist</md-icon>&Uuml;bersicht</md-secondary-tab>
                <md-secondary-tab data-seg inline-icon class="or-tab ${_orState.tab === 'analytics' ? 'active' : ''}" onclick="orSetTab('analytics')"><md-icon slot="icon">bar_chart</md-icon>Analytics</md-secondary-tab>
            </md-tabs>
            <div class="or-toolbar-row">
                <md-outlined-text-field class="or-search" id="orSearch" placeholder="Bestell-ID, Kunde, Objekt..." value="${escapeHtml(_orState.search)}">
                    <md-icon slot="leading-icon">search</md-icon>
                </md-outlined-text-field>
                <md-chip-set id="orFilterPills">
                    <md-filter-chip label="Alle" data-status="alle" ${_orState.filterStatus === 'alle' ? 'selected' : ''} onclick="orSetFilter('alle')"></md-filter-chip>
                    <md-filter-chip label="Offen" data-status="offen" ${_orState.filterStatus === 'offen' ? 'selected' : ''} onclick="orSetFilter('offen')"></md-filter-chip>
                    <md-filter-chip label="Abgeschlossen" data-status="abgeschlossen" ${_orState.filterStatus === 'abgeschlossen' ? 'selected' : ''} onclick="orSetFilter('abgeschlossen')"></md-filter-chip>
                    <md-filter-chip label="Storniert" data-status="storniert" ${_orState.filterStatus === 'storniert' ? 'selected' : ''} onclick="orSetFilter('storniert')"></md-filter-chip>
                    <md-filter-chip class="coral" label="&Uuml;berf&auml;llig" data-status="ueberfaellig" ${_orState.filterStatus === 'ueberfaellig' ? 'selected' : ''} onclick="orSetFilter('ueberfaellig')"></md-filter-chip>
                </md-chip-set>
                <md-outlined-select class="or-select" id="orFilterMonth" label="Monat">
                    <md-select-option value="" ${_orState.filterMonth === '' ? 'selected' : ''}><div slot="headline">Alle Monate</div></md-select-option>
                    ${monthList.map(m => {
                        const [y, mm] = m.split('-');
                        return `<md-select-option value="${m}" ${_orState.filterMonth === m ? 'selected' : ''}><div slot="headline">${MONTHS_DE[parseInt(mm)]} ${y}</div></md-select-option>`;
                    }).join('')}
                </md-outlined-select>
                <md-outlined-select class="or-select" id="orFilterKunde" label="Kunde">
                    <md-select-option value="" ${_orState.filterKunde === '' ? 'selected' : ''}><div slot="headline">Alle Kunden</div></md-select-option>
                    ${kundens.map(k => `<md-select-option value="${escapeHtml(k.id)}" ${_orState.filterKunde === k.id ? 'selected' : ''}><div slot="headline">${escapeHtml(k.name)}</div></md-select-option>`).join('')}
                </md-outlined-select>
                <md-outlined-select class="or-select" id="orSort" label="Sortieren">
                    <md-select-option value="newest" ${_orState.sort === 'newest' ? 'selected' : ''}><div slot="headline">Neueste</div></md-select-option>
                    <md-select-option value="oldest" ${_orState.sort === 'oldest' ? 'selected' : ''}><div slot="headline">&Auml;lteste</div></md-select-option>
                    <md-select-option value="amount-desc" ${_orState.sort === 'amount-desc' ? 'selected' : ''}><div slot="headline">Std (hoch)</div></md-select-option>
                    <md-select-option value="amount-asc" ${_orState.sort === 'amount-asc' ? 'selected' : ''}><div slot="headline">Std (niedrig)</div></md-select-option>
                    <md-select-option value="progress" ${_orState.sort === 'progress' ? 'selected' : ''}><div slot="headline">Fortschritt</div></md-select-option>
                </md-outlined-select>
                <div class="or-toolbar-actions">
                    <md-outlined-button onclick="orExportCSV()"><md-icon slot="icon">csv</md-icon>Excel Export</md-outlined-button>
                </div>
            </div>
        </div>
    `;
}

function orBindToolbar() {
    document.getElementById('orSearch')?.addEventListener('input', (e) => {
        _orState.search = e.target.value || '';
        if (_orState.tab === 'overview') orRenderTable();
    });
    document.getElementById('orFilterMonth')?.addEventListener('change', (e) => {
        _orState.filterMonth = e.target.value || '';
        const bentoWrap = document.getElementById('orBentoWrap');
        if (bentoWrap) bentoWrap.innerHTML = orBuildBento();
        if (_orState.tab === 'overview') orRenderTable();
    });
    document.getElementById('orFilterKunde')?.addEventListener('change', (e) => {
        _orState.filterKunde = e.target.value || '';
        if (_orState.tab === 'overview') orRenderTable();
    });
    document.getElementById('orSort')?.addEventListener('change', (e) => {
        _orState.sort = e.target.value;
        if (_orState.tab === 'overview') orRenderTable();
    });
}

function orSetFilter(status) {
    _orState.filterStatus = status;
    // md-filter-chip: drive selection via the .selected property (exactly one active).
    document.querySelectorAll('#orFilterPills md-filter-chip').forEach(c => { c.selected = (c.dataset.status === status); });
    if (_orState.tab === 'analytics') { _orState.tab = 'overview'; renderOrders(); return; }
    orRenderTable();
}

function orSetTab(tab) {
    _orState.tab = tab;
    renderOrders();
}

// ============================================================
// OVERVIEW — Filtered Table
// ============================================================
function orRenderOverview() {
    const mainBody = document.getElementById('orMainBody');
    if (!mainBody) return;
    if (_orState.orders.length === 0) {
        mainBody.innerHTML = `
            <div class="or-table-card">
                <div class="or-empty">
                    ${orIcon('receipt_long')}
                    <p>Keine Bestellungen vorhanden</p>
                    <div class="or-empty-sub">Bestellungen werden automatisch aus Bestelleing&auml;ngen erstellt.</div>
                </div>
            </div>`;
        return;
    }

    mainBody.innerHTML = `
        <div class="or-table-card">
            <div class="or-table-wrap">
                <table class="or-table">
                    <thead>
                        <tr>
                            <th>Bestell-ID</th>
                            <th>Eingang</th>
                            <th>Kunde</th>
                            <th>Objekt</th>
                            <th>Periode</th>
                            <th class="or-text-right">Soll-Std</th>
                            <th>Fortschritt</th>
                            <th>Status</th>
                            <th class="or-text-right">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody id="orTbody"></tbody>
                </table>
            </div>
            <div id="orTableEmpty" style="display:none"></div>
            <div id="orPager" class="be-pager"></div>
        </div>
    `;
    orRenderTable();
}

function orRenderTable() {
    const tbody = document.getElementById('orTbody');
    const emptyEl = document.getElementById('orTableEmpty');
    if (!tbody) return;

    const filtered = orFilterAndSort(_orState.orders);

    if (filtered.length === 0) {
        hmsPagerClear('orPager');
        tbody.innerHTML = '';
        if (emptyEl) {
            emptyEl.style.display = '';
            emptyEl.innerHTML = `
                <div class="or-empty">
                    ${orIcon('filter_alt')}
                    <p>Keine Treffer mit diesen Filtern</p>
                    <div class="or-empty-sub">${_orState.orders.length} Bestellungen insgesamt — Filter anpassen.</div>
                </div>`;
        }
        return;
    }
    if (emptyEl) emptyEl.style.display = 'none';

    const _p = hmsPagerDef('orPager', 'Bestellungen', () => orRenderTable());
    const pg = hmsPage(filtered, _p.st);
    hmsPagerFill('orPager', pg);
    const rowsHtml = pg.rows.map(orBuildRow).join('');
    if (typeof hmsMorph === 'function') hmsMorph(tbody, rowsHtml); else tbody.innerHTML = rowsHtml;
}

function orFilterAndSort(orders) {
    const q = (_orState.search || '').toLowerCase().trim();
    let filtered = orders.filter(o => {
        // Status filter
        if (_orState.filterStatus === 'ueberfaellig') {
            if (!o.ueberfaellig) return false;
        } else if (_orState.filterStatus !== 'alle') {
            if (o.status !== _orState.filterStatus) return false;
        }
        // Month filter
        if (_orState.filterMonth) {
            const [y, m] = _orState.filterMonth.split('-');
            if (parseInt(o.jahr) !== parseInt(y) || parseInt(o.monat) !== parseInt(m)) return false;
        }
        // Kunde filter
        if (_orState.filterKunde && o.kunden_id !== _orState.filterKunde) return false;
        // Search
        if (q) {
            const hay = [o.bestell_id, o.eingang_id, o.kundenname, o.objektname, o.kunden_id, o.obj_id, o.kunden_obj_nr, o.nu_name]
                .filter(Boolean).join(' ').toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    });

    // Sort
    const sorted = [...filtered];
    sorted.sort((a, b) => {
        if (_orState.sort === 'oldest') return (a.id || 0) - (b.id || 0);
        if (_orState.sort === 'amount-desc') return (parseFloat(b.soll_std_betrag) || 0) - (parseFloat(a.soll_std_betrag) || 0);
        if (_orState.sort === 'amount-asc') return (parseFloat(a.soll_std_betrag) || 0) - (parseFloat(b.soll_std_betrag) || 0);
        if (_orState.sort === 'progress') {
            const pa = (a.einsatz_total > 0) ? (a.einsatz_bestaetigt / a.einsatz_total) : 0;
            const pb = (b.einsatz_total > 0) ? (b.einsatz_bestaetigt / b.einsatz_total) : 0;
            return pb - pa;
        }
        return (b.id || 0) - (a.id || 0); // newest
    });
    return sorted;
}

function orBuildRow(o) {
    const total = parseInt(o.einsatz_total) || 0;
    const bestaetigt = parseInt(o.einsatz_bestaetigt) || 0;
    const geplant = parseInt(o.einsatz_geplant) || 0;
    const ausstehend = parseInt(o.einsatz_ausstehend) || 0;
    const abgelehnt = parseInt(o.einsatz_abgelehnt) || 0;

    const pctB = total > 0 ? (bestaetigt / total * 100) : 0;
    const pctG = total > 0 ? (geplant / total * 100) : 0;
    const pctA = total > 0 ? (ausstehend / total * 100) : 0;
    const pctX = total > 0 ? (abgelehnt / total * 100) : 0;

    const progressBar = total > 0
        ? `<div class="or-row-progress" title="${bestaetigt} bestätigt · ${geplant} geplant · ${ausstehend} offen${abgelehnt > 0 ? ' · ' + abgelehnt + ' abgelehnt' : ''} von ${total}">
            <div class="or-row-progress-bar">
                <div class="or-row-progress-seg bestaetigt" style="width:${pctB}%"></div>
                <div class="or-row-progress-seg geplant" style="width:${pctG}%"></div>
                <div class="or-row-progress-seg ausstehend" style="width:${pctA}%"></div>
                ${pctX > 0 ? `<div class="or-row-progress-seg abgelehnt" style="width:${pctX}%"></div>` : ''}
            </div>
            <div class="or-row-progress-label">
                <span><strong>${bestaetigt}</strong>/${total}</span>
                <span>${Math.round(pctB)}%</span>
            </div>
        </div>`
        : '<span style="color:var(--or-text-soft);font-size:0.78rem">— Keine Einsätze</span>';

    const monthLabel = o.monat ? (MONTHS_DE[o.monat] + ' ' + (o.jahr || '')) : '–';
    const rowClass = o.ueberfaellig ? 'is-late' : (o.status === 'abgeschlossen' ? 'is-done' : '');

    const actions = `
        <div class="or-row-actions">
            <md-icon-button class="view" onclick="viewOrderDetails('${o.bestell_id}')" title="Details"><md-icon slot="icon">visibility</md-icon></md-icon-button>
            <md-icon-button class="pdf" onclick="openOrderForm('${o.bestell_id}')" title="Auftragsblatt ansehen"><md-icon slot="icon">picture_as_pdf</md-icon></md-icon-button>
            <md-icon-button class="pdfs" onclick="openSavedPdfsList('${o.bestell_id}')" title="Gespeicherte PDFs anzeigen"><md-icon slot="icon">folder_open</md-icon></md-icon-button>
            ${o.status === 'offen' ? `<md-icon-button class="close" onclick="closeOrder('${o.bestell_id}')" title="Abschließen"><md-icon slot="icon">check</md-icon></md-icon-button>` : ''}
            ${o.status !== 'storniert' && o.status !== 'abgeschlossen' ? `<md-icon-button class="md-icon-danger cancel" onclick="cancelOrder('${o.bestell_id}')" title="Stornieren"><md-icon slot="icon">block</md-icon></md-icon-button>` : ''}
            ${o.status === 'storniert' || o.status === 'abgeschlossen' ? `<md-icon-button class="close" onclick="reopenOrder('${o.bestell_id}')" title="Wieder öffnen"><md-icon slot="icon">undo</md-icon></md-icon-button>` : ''}
            <md-icon-button class="md-icon-danger delete" onclick="deleteOrder('${o.bestell_id}')" title="Endgültig löschen"><md-icon slot="icon">delete</md-icon></md-icon-button>
        </div>`;

    return `<tr data-mk="${escapeHtml(o.bestell_id)}" class="${rowClass}">
        <td><span class="or-id-cell">${escapeHtml(o.bestell_id)}</span></td>
        <td>${o.eingang_id ? `<span class="or-eingang-link">${escapeHtml(o.eingang_id)}</span>` : '<span style="color:var(--or-text-soft)">–</span>'}</td>
        <td>
            <div class="or-kunde-cell">
                <span class="or-kunde-name">${escapeHtml(o.kundenname || o.kunden_id)}</span>
                <span class="or-kunde-meta">${escapeHtml(o.kunden_id || '')}${o.kunden_bereich ? ' · ' + escapeHtml(o.kunden_bereich) : ''}</span>
            </div>
        </td>
        <td>
            <div class="or-kunde-cell">
                <span class="or-kunde-name">${escapeHtml(o.objektname || o.obj_id)}</span>
                ${o.kunden_obj_nr ? `<span class="or-kunde-meta">Obj-Nr: ${escapeHtml(o.kunden_obj_nr)}</span>` : ''}
            </div>
        </td>
        <td><span class="or-period-chip">${orIcon('calendar_month', 'font-size:11px;vertical-align:-2px')} ${monthLabel}</span></td>
        <td class="or-text-right">
            ${o.soll_std_betrag ? `<span class="or-amount">${parseFloat(o.soll_std_betrag).toFixed(2)}<span class="or-amount-suffix">h</span></span>` : '<span style="color:var(--or-text-soft)">–</span>'}
        </td>
        <td>${progressBar}</td>
        <td>
            <span class="or-status ${o.status}">${o.status}</span>
            ${o.ueberfaellig ? `<span class="or-late-pill">${orIcon('warning', 'font-size:10px;vertical-align:-1px')} ÜBERFÄLLIG</span>` : ''}
        </td>
        <td>${actions}</td>
    </tr>`;
}

// ============================================================
// DETAILS MODAL
// ============================================================
async function viewOrderDetails(id) {
    const order = await apiGet(API.orders + '?id=' + id);
    if (!order || order.error) { showToast('Nicht gefunden', 'error'); return; }

    // Fetch planned days from Bestelleingang
    let incomingDays = [];
    if (order.eingang_id) {
        const incoming = await apiGet(API.incoming_orders + '?id=' + order.eingang_id);
        if (incoming && incoming.tage) incomingDays = incoming.tage;
    }

    const prog = order.progress || {};
    const progTotal = parseInt(prog.total) || 0;
    const progBest = parseInt(prog.bestaetigt) || 0;
    const progGepl = parseInt(prog.geplant) || 0;
    const progAus = parseInt(prog.ausstehend) || 0;
    const progPct = progTotal > 0 ? Math.round((progBest / progTotal) * 100) : 0;
    const pctB = progTotal > 0 ? (progBest / progTotal * 100) : 0;
    const pctG = progTotal > 0 ? (progGepl / progTotal * 100) : 0;
    const pctA = progTotal > 0 ? (progAus / progTotal * 100) : 0;

    const confirmedWorkers = order.confirmed_workers || [];
    const confirmedNu = order.confirmed_nu_workers || [];
    // ist_std <= 0 counts as "not recorded" → planned fallback
    // (same convention as the Controlling billing side).
    const entryStd = (w) => (w.ist_std !== null && w.ist_std !== '' && parseFloat(w.ist_std) > 0
        ? parseFloat(w.ist_std) : (parseFloat(w.ist_std_menge) || 0));
    const totalConfirmedStd = confirmedWorkers.reduce((s, w) => s + entryStd(w), 0)
        + confirmedNu.reduce((s, w) => s + entryStd(w), 0);
    const totalConfirmedEntries = confirmedWorkers.length + confirmedNu.length;

    // Build planned days table
    let plannedDaysHtml = '';
    if (incomingDays.length > 0) {
        const totalSollStd = incomingDays.reduce((sum, t) => sum + ((parseFloat(t.soll_std) || 0) * (parseFloat(t.menge) || 1)), 0);
        // The day description is usually the SAME task text on every day
        // (often 1–2k chars) — show it once above the table instead of
        // repeating it per row; only differing texts stay in the column.
        const descs = [...new Set(incomingDays.map(t => (t.beschreibung || '').trim()).filter(Boolean))];
        const sharedDesc = descs.length === 1 && incomingDays.every(t => (t.beschreibung || '').trim() === descs[0]) ? descs[0] : '';
        const descCol = !sharedDesc && descs.length > 0;
        plannedDaysHtml = `
            <div class="or-section">
                <div class="or-section-head">
                    <span class="or-section-icon">${orIcon('calendar_month', 'font-size:14px')}</span>
                    <span class="or-section-title">Geplante Tage</span>
                    <span class="or-section-badge">${incomingDays.length} Tage · ${totalSollStd.toFixed(2)} Std</span>
                </div>
                ${sharedDesc ? orDescBoxHtml(sharedDesc, incomingDays.length) : ''}
                <div class="or-sub-table-wrap">
                    <table class="or-sub-table${descCol ? '' : ' no-desc'}">
                        <thead><tr>
                            <th>#</th><th>Datum</th><th>Wochentag</th><th>Von</th><th>Bis</th>
                            <th>Pause</th><th>Soll-Std</th><th>Menge</th>${descCol ? '<th>Beschreibung</th>' : ''}
                        </tr></thead>
                        <tbody>
                            ${incomingDays.map(t => {
                                // ONE pause (Planung / Bestelleingang) — no separate Kunden-Pause.
                                const hasP = t.pause_minuten != null && t.pause_minuten !== '';
                                let pauseMin = hasP ? (parseInt(t.pause_minuten) || 0) : 0;
                                if (!hasP && t.zeit_von && t.zeit_bis && t.soll_std) {
                                    const sv = t.zeit_von.split(':').map(Number);
                                    const ev = t.zeit_bis.split(':').map(Number);
                                    let totalMin = (ev[0]*60+ev[1]) - (sv[0]*60+sv[1]);
                                    if (totalMin < 0) totalMin += 1440; // overnight shift
                                    pauseMin = Math.max(0, totalMin - Math.round(parseFloat(t.soll_std)*60));
                                }
                                return `<tr>
                                    <td><strong>${t.tag_nr}</strong></td>
                                    <td>${formatDate(t.datum)}</td>
                                    <td>${t.wochentag || '–'}</td>
                                    <td>${formatTime(t.zeit_von)}</td>
                                    <td>${formatTime(t.zeit_bis)}</td>
                                    <td>${pauseMin > 0 ? pauseMin + ' Min' : '–'}</td>
                                    <td><strong>${t.soll_std ? parseFloat(t.soll_std) : '–'}</strong></td>
                                    <td>${t.menge ? parseFloat(t.menge) : '–'}</td>
                                    ${descCol ? `<td>${(t.beschreibung || '').trim()
                                        ? `<div class="or-desc-clamp" title="Klicken zum Auf-/Zuklappen" onclick="this.classList.toggle('open')">${escapeHtml(t.beschreibung.trim())}</div>`
                                        : '–'}</td>` : ''}
                                </tr>`;
                            }).join('')}
                        </tbody>
                        <tfoot><tr>
                            <td colspan="6" style="text-align:right">Gesamt:</td>
                            <td>${totalSollStd.toFixed(2)}</td>
                            <td colspan="${descCol ? 2 : 1}"></td>
                        </tr></tfoot>
                    </table>
                </div>
            </div>`;
    }

    // Build confirmed workers table — chronological (datum, then name).
    // Internal + NU rows are merged so the whole list reads as a timeline.
    let workersHtml = '';
    if (totalConfirmedEntries > 0) {
        const rows = [];
        const pushRow = (w, isNu) => rows.push({
            isNu,
            name: isNu
                ? (((w.vorname || '') + ' ' + (w.name || '')).trim() || ('NU-' + w.nu_worker_id))
                : (((w.vorname || '') + ' ' + (w.name || '')).trim() || w.dienst_nr),
            nuFirma: w.nu_firmenname || '',
            datum: w.datum || '',
            start: formatTime(w.ist_start),
            end: formatTime(w.ist_ende),
            pauseMin: parseInt(w.pause_minuten) || 0,
            std: (w.ist_std !== null && w.ist_std !== '' && parseFloat(w.ist_std) > 0)
                ? parseFloat(w.ist_std) : (parseFloat(w.ist_std_menge) || 0),
        });
        confirmedWorkers.forEach(w => pushRow(w, false));
        confirmedNu.forEach(w => pushRow(w, true));
        rows.sort((a, b) => a.datum === b.datum
            ? a.name.localeCompare(b.name, 'de')
            : (a.datum < b.datum ? -1 : 1));

        const workerRows = rows.map((r, i) => {
            const datumObj = r.datum ? new Date(r.datum + 'T00:00:00') : null;
            const wochentag = datumObj ? DAYS_DE[datumObj.getDay()] : '–';
            return `<tr>
                <td><strong>${i + 1}</strong></td>
                <td><strong>${escapeHtml(r.name)}</strong>${r.isNu ? `<span class="or-nu-badge">${escapeHtml(r.nuFirma || 'NU')}</span>` : ''}</td>
                <td>${formatDate(r.datum)}</td>
                <td>${wochentag}</td>
                <td>${r.start}</td>
                <td>${r.end}</td>
                <td>${r.pauseMin > 0 ? r.pauseMin + ' Min' : '–'}</td>
                <td><strong>${r.std ? r.std.toFixed(2) : '–'}</strong></td>
            </tr>`;
        });

        workersHtml = `
            <div class="or-section">
                <div class="or-section-head">
                    <span class="or-section-icon done">${orIcon('how_to_reg', 'font-size:14px')}</span>
                    <span class="or-section-title">Freigegebene Mitarbeiter</span><span class="or-section-badge" title="Nur Einsätze mit Kunden-Freigabe (Monatsabschluss, Modus „Kunde“) — genau diese stehen im Leistungsnachweis">Leistungsnachweis</span>
                    <span class="or-section-badge done">${totalConfirmedEntries} Einträge · ${totalConfirmedStd.toFixed(2)} Std</span>
                </div>
                <div class="or-sub-table-wrap">
                    <table class="or-sub-table done">
                        <thead><tr>
                            <th>#</th><th>Mitarbeiter</th><th>Datum</th><th>Tag</th>
                            <th>Von</th><th>Bis</th><th>Pause</th><th>Std</th>
                        </tr></thead>
                        <tbody>${workerRows.join('')}</tbody>
                        <tfoot><tr>
                            <td colspan="7" style="text-align:right">Gesamt:</td>
                            <td>${totalConfirmedStd.toFixed(2)}</td>
                        </tr></tfoot>
                    </table>
                </div>
            </div>`;
    } else {
        workersHtml = `
            <div class="or-section">
                <div class="or-section-head">
                    <span class="or-section-icon done">${orIcon('how_to_reg', 'font-size:14px')}</span>
                    <span class="or-section-title">Freigegebene Mitarbeiter</span><span class="or-section-badge" title="Nur Einsätze mit Kunden-Freigabe (Monatsabschluss, Modus „Kunde“) — genau diese stehen im Leistungsnachweis">Leistungsnachweis</span>
                </div>
                ${progBest > 0
                    // Confirmed in Dispo, but nothing approved for the customer
                    // yet — only Kunden-Freigabe rows reach this list and the PDF.
                    ? `<div class="or-empty or-await-freigabe" style="padding:26px 20px;border:1px dashed var(--or-border);border-radius:var(--or-radius)">
                        ${orIcon('pending_actions')}
                        <p>${progBest} bestätigte${progBest === 1 ? 'r Einsatz wartet' : ' Einsätze warten'} auf die Kunden-Freigabe</p>
                        <div class="or-empty-sub">Erst nach der Freigabe im Monatsabschluss (Modus „Kunde") erscheinen sie hier und im Leistungsnachweis (PDF).</div>
                        <md-filled-tonal-button style="margin-top:12px" onclick="orOpenKundenFreigabe(${parseInt(order.monat) || 0}, ${parseInt(order.jahr) || 0}, '${escapeHtml(String(order.kundenname || '').replace(/'/g, "\\'"))}')">
                            <md-icon slot="icon">task_alt</md-icon>Zur Kunden-Freigabe</md-filled-tonal-button>
                    </div>`
                    : `<div class="or-empty" style="padding:30px 20px;border:1px dashed var(--or-border);border-radius:var(--or-radius)">
                        ${orIcon('group')}
                        <p>Noch keine freigegebenen Einsätze</p>
                        <div class="or-empty-sub">Einsätze erscheinen hier und im Leistungsnachweis (PDF), sobald sie in Dispo bestätigt und im Monatsabschluss (Modus „Kunde“) freigegeben sind.</div>
                    </div>`}
            </div>`;
    }

    const body = `
        <div class="or-detail">
            <div class="or-detail-cards">
                <div class="or-detail-card">
                    <div class="or-detail-card-label">Bestell-ID</div>
                    <div class="or-detail-card-value" style="font-family:ui-monospace,monospace;color:var(--or-accent-dark)">${escapeHtml(order.bestell_id)}</div>
                </div>
                <div class="or-detail-card">
                    <div class="or-detail-card-label">Status</div>
                    <div class="or-detail-card-value"><span class="or-status ${order.status}">${order.status}</span></div>
                </div>
                <div class="or-detail-card">
                    <div class="or-detail-card-label">Periode</div>
                    <div class="or-detail-card-value">${MONTHS_DE[order.monat] || ''} ${order.jahr || ''}</div>
                </div>
                <div class="or-detail-card">
                    <div class="or-detail-card-label">Kunde</div>
                    <div class="or-detail-card-value">${escapeHtml(order.kundenname || order.kunden_id)}</div>
                    <div class="or-detail-card-sub">${escapeHtml(order.kunden_id || '')}</div>
                </div>
                <div class="or-detail-card">
                    <div class="or-detail-card-label">Objekt</div>
                    <div class="or-detail-card-value">${escapeHtml(order.objektname || order.obj_id)}</div>
                    ${order.kunden_obj_nr ? `<div class="or-detail-card-sub">Obj-Nr: ${escapeHtml(order.kunden_obj_nr)}</div>` : ''}
                </div>
                <div class="or-detail-card">
                    <div class="or-detail-card-label">Zeit</div>
                    <div class="or-detail-card-value">${formatTime(order.zeit_von)} – ${formatTime(order.zeit_bis)}</div>
                    ${order.soll_std_betrag ? `<div class="or-detail-card-sub"><strong>${parseFloat(order.soll_std_betrag).toFixed(2)} Std</strong> geplant</div>` : ''}
                </div>
            </div>

            <div class="or-detail-progress">
                <div class="or-detail-progress-head">
                    <div class="or-detail-progress-title">${orIcon('checklist', 'font-size:14px;vertical-align:-2px')} Dispo-Fortschritt</div>
                    <div class="or-detail-progress-pct">${progPct}%</div>
                </div>
                <div class="or-detail-progress-bar">
                    <div style="width:${pctB}%;background:var(--or-done)"></div>
                    <div style="width:${pctG}%;background:var(--or-planned)"></div>
                    <div style="width:${pctA}%;background:var(--or-pending)"></div>
                </div>
                <div class="or-detail-progress-legend">
                    <div class="or-detail-progress-legend-item"><span class="or-detail-progress-legend-dot" style="background:var(--or-done)"></span> ${progBest} Bestätigt</div>
                    <div class="or-detail-progress-legend-item"><span class="or-detail-progress-legend-dot" style="background:var(--or-planned)"></span> ${progGepl} Geplant</div>
                    <div class="or-detail-progress-legend-item"><span class="or-detail-progress-legend-dot" style="background:var(--or-pending)"></span> ${progAus} Offen</div>
                    <div class="or-detail-progress-legend-item" style="color:var(--or-text-muted)">${progTotal} gesamt</div>
                </div>
            </div>

            ${plannedDaysHtml}
            ${workersHtml}
        </div>
    `;

    let footerBtns = `<md-text-button onclick="closeOrDialog()">Schließen</md-text-button>`;
    footerBtns += `<md-outlined-button onclick="openOrderForm('${order.bestell_id}')"><md-icon slot="icon">picture_as_pdf</md-icon>PDF</md-outlined-button>`;
    if (order.status === 'offen') {
        footerBtns += `<md-filled-button onclick="closeOrder('${order.bestell_id}')"><md-icon slot="icon">check</md-icon>Abschließen</md-filled-button>`;
    }
    openOrDialog('Bestellung: ' + order.bestell_id, body, footerBtns, true);
}

// ============================================================
// EDIT
// ============================================================
async function editOrder(id) {
    const order = await apiGet(API.orders + '?id=' + id);
    if (!order || order.error) { showToast('Nicht gefunden', 'error'); return; }
    const [customerOpts, objOpts] = await Promise.all([
        getCustomerOptions(),
        getObjectOptions(order.kunden_id)
    ]);

    const body = `
        <div class="or-form-grid">
            <div class="or-form-group">
                <label>Kunde *</label>
                <md-outlined-select class="crm-fld" id="f_ord_kunde" onchange="orderEditLoadObjects()">
                    <md-select-option value=""><div slot="headline">— Kunde wählen —</div></md-select-option>
                    ${customerOpts}
                </md-outlined-select>
            </div>
            <div class="or-form-group">
                <label>Objekt *</label>
                <md-outlined-select class="crm-fld" id="f_ord_objekt">
                    <md-select-option value=""><div slot="headline">— Objekt wählen —</div></md-select-option>
                    ${objOpts}
                </md-outlined-select>
            </div>
            <div class="or-form-group">
                <label>Monat</label>
                <md-outlined-select class="crm-fld" id="f_ord_monat">
                    ${MONTHS_DE.map((m, i) => i > 0 ? `<md-select-option value="${i}" ${i == order.monat ? 'selected' : ''}><div slot="headline">${m}</div></md-select-option>` : '').join('')}
                </md-outlined-select>
            </div>
            <div class="or-form-group">
                <label>Jahr</label>
                <md-outlined-text-field class="crm-fld" type="number" id="f_ord_jahr" value="${order.jahr || new Date().getFullYear()}"></md-outlined-text-field>
            </div>
            <div class="or-form-group">
                <label>Zeit von</label>
                <md-outlined-text-field class="crm-fld t24" type="text" lang="de-DE" inputmode="numeric" data-max="5" placeholder="HH:MM" id="f_ord_zeit_von" value="${order.zeit_von ? order.zeit_von.substring(0, 5) : ''}"></md-outlined-text-field>
            </div>
            <div class="or-form-group">
                <label>Zeit bis</label>
                <md-outlined-text-field class="crm-fld t24" type="text" lang="de-DE" inputmode="numeric" data-max="5" placeholder="HH:MM" id="f_ord_zeit_bis" value="${order.zeit_bis ? order.zeit_bis.substring(0, 5) : ''}"></md-outlined-text-field>
            </div>
            <div class="or-form-group">
                <label>Soll-Std / Betrag</label>
                <md-outlined-text-field class="crm-fld" type="number" step="0.01" id="f_ord_soll" value="${order.soll_std_betrag || ''}"></md-outlined-text-field>
            </div>
        </div>
    `;
    const footer = `
        <md-text-button onclick="closeOrDialog()">Abbrechen</md-text-button>
        <md-filled-button onclick="saveOrderEdit('${order.bestell_id}')"><md-icon slot="icon">save</md-icon>Speichern</md-filled-button>
    `;
    openOrDialog('Bestellung bearbeiten — ' + order.bestell_id, body, footer);
    document.getElementById('f_ord_kunde').value = order.kunden_id;
    document.getElementById('f_ord_objekt').value = order.obj_id;
}

async function orderEditLoadObjects() {
    const kundenId = document.getElementById('f_ord_kunde').value;
    const opts = await getObjectOptions(kundenId);
    document.getElementById('f_ord_objekt').innerHTML = '<md-select-option value=""><div slot="headline">— Objekt wählen —</div></md-select-option>' + opts;
}

async function saveOrderEdit(bestellId) {
    const data = {
        bestell_id: bestellId,
        kunden_id: document.getElementById('f_ord_kunde').value,
        obj_id: document.getElementById('f_ord_objekt').value,
        monat: parseInt(document.getElementById('f_ord_monat').value),
        jahr: parseInt(document.getElementById('f_ord_jahr').value),
        zeit_von: document.getElementById('f_ord_zeit_von').value || null,
        zeit_bis: document.getElementById('f_ord_zeit_bis').value || null,
        soll_std_betrag: parseFloat(document.getElementById('f_ord_soll').value) || null,
        status: 'offen'
    };
    if (!data.kunden_id || !data.obj_id) { showToast('Kunde und Objekt erforderlich', 'error'); return; }
    const result = await apiPut(API.orders + '/' + bestellId, data);
    if (result.success) {
        closeOrDialog();
        showToast('Bestellung aktualisiert');
        renderOrders();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

// ============================================================
// ACTIONS (Close / Cancel / Delete)
// ============================================================
async function closeOrder(id) {
    const ok = await mdConfirm(`Bestellung <strong>${escapeHtml(id)}</strong> abschließen?<br><br>Der Status wird auf "abgeschlossen" gesetzt.`, 'Bestellung abschließen');
    if (!ok) return;
    const result = await apiPut(API.orders + '/' + id, { bestell_id: id, status: 'abgeschlossen', status_only: true });
    if (result.success) {
        closeOrDialog();
        showToast('Bestellung abgeschlossen');
        renderOrders();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function cancelOrder(id) {
    const ok = await mdConfirm(`Bestellung <strong>${escapeHtml(id)}</strong> wirklich stornieren?<br><br>Offene Einsätze werden aus der Planung entfernt, der Bestelleingang wird auf „Abgesagt“ gesetzt. Eingeplante oder bestätigte Einsätze verhindern die Stornierung.`, 'Bestellung stornieren');
    if (!ok) return;
    const result = await apiPut(API.orders + '/' + id, { status: 'storniert', status_only: true });
    if (result.success) {
        closeOrDialog();
        showToast('Bestellung storniert' + (result.deleted_tasks ? ` — ${result.deleted_tasks} Einsätze aus der Planung entfernt` : ''));
        renderOrders();
    } else if (Array.isArray(result.blocked_shifts) && result.blocked_shifts.length) {
        mdAlert('Folgende Einsätze sind in der Planung <strong>eingeplant oder bestätigt</strong>:<br><br>&bull; '
            + result.blocked_shifts.map(escapeHtml).join('<br>&bull; ')
            + '<br><br>Bitte zuerst den Plan in der Disposition aufheben. Es wurde nichts storniert.', 'Stornierung nicht möglich');
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function reopenOrder(id) {
    const ok = await mdConfirm(`Bestellung <strong>${escapeHtml(id)}</strong> wieder öffnen?<br><br>Bei einer stornierten Bestellung werden die Einsätze aus dem Bestelleingang neu in der Planung angelegt.`, 'Bestellung wieder öffnen');
    if (!ok) return;
    const result = await apiPut(API.orders + '/' + id, { status: 'offen', status_only: true });
    if (result.success) {
        closeOrDialog();
        showToast('Bestellung wieder geöffnet' + (result.tasks_created ? ` — ${result.tasks_created} Einsätze angelegt` : ''));
        renderOrders();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function deleteOrder(id) {
    const ok = await mdConfirm(`Bestellung <strong>${escapeHtml(id)}</strong> endgültig löschen?<br><br><strong>Alle zugehörigen Einsätze werden unwiderruflich gelöscht!</strong>`, 'Bestellung löschen');
    if (!ok) return;
    const result = await apiDelete(API.orders + '/' + id);
    if (result.success) {
        closeOrDialog();
        showToast('Bestellung endgültig gelöscht');
        renderOrders();
    } else if (result.blocked_shifts || result.eingang_id) {
        mdAlert(escapeHtml(result.error || 'Fehler beim Löschen') + (Array.isArray(result.blocked_shifts) && result.blocked_shifts.length ? '<br><br>&bull; ' + result.blocked_shifts.map(escapeHtml).join('<br>&bull; ') : ''), 'Löschen nicht möglich');
    } else {
        showToast(result.error || 'Fehler beim Löschen', 'error');
    }
}

function openOrderForm(id) {
    // Editor temporarily disabled: open the Auftragsblatt in view-only mode
    // (?view=1) so only the rendered PDF is shown, without the editor UI.
    // Drop the &view=1 below to re-enable the full editor.
    openOrderFormModal('/order_form.php?id=' + encodeURIComponent(id) + '&view=1&embed=1');
}

// Opens order_form.php inside a fullscreen in-app dialog (iframe) instead
// of a window.open popup: in the Capacitor shell window.open is handed to
// the OS (external browser → no hms_token → login redirect), and desktop
// popup blockers can eat it. The iframe keeps auth + page context.
// Also used by pdf_design.js for the sample-design editor.
function openOrderFormModal(url) {
    const dlg = ensureOrderFormModal();
    // App shell (edge-to-edge WebView): the iframe fills the whole screen,
    // so the editor's own topbar would sit under the status bar / Dynamic
    // Island. Hand the resolved insets over (an iframe can't read the
    // parent's CSS vars) — order_form.php pads its chrome by them.
    if (document.documentElement.classList.contains('app-shell')) {
        const cs = getComputedStyle(document.documentElement);
        const px = (v) => Math.max(0, Math.round(parseFloat(v) || 0));
        const top = px(cs.getPropertyValue('--safe-top')) || px(safeAreaEnv('top'));
        const bottom = px(cs.getPropertyValue('--safe-bottom')) || px(safeAreaEnv('bottom'));
        url += (url.includes('?') ? '&' : '?') + 'sat=' + top + '&sab=' + bottom;
    }
    document.getElementById('orFormFrame').src = url;
    if (!dlg.open) dlg.showModal();
}

// Resolves env(safe-area-inset-*) to a px number (iOS; Android reports 0
// and uses the natively pushed --safe-* vars instead).
function safeAreaEnv(side) {
    const probe = document.createElement('div');
    probe.style.cssText = 'position:fixed;visibility:hidden;pointer-events:none;top:0;left:0;height:env(safe-area-inset-' + side + ',0px)';
    document.body.appendChild(probe);
    const h = probe.getBoundingClientRect().height;
    probe.remove();
    return h + 'px';
}

function ensureOrderFormModal() {
    let dlg = document.getElementById('orFormModal');
    if (dlg) return dlg;
    // A real <dialog> puts the viewer in the top layer — required so it
    // sits ABOVE the md-dialog order-details modal (top layer ignores
    // z-index; last shown wins). showModal() also gives ESC-close and
    // scroll-lock for free.
    dlg = document.createElement('dialog');
    dlg.id = 'orFormModal';
    dlg.style.cssText = 'position:fixed;padding:0;border:0;margin:0;inset:0;width:100vw;height:100vh;max-width:none;max-height:none;background:var(--gray-100,#f1f5f9)';
    dlg.innerHTML = '<iframe id="orFormFrame" title="Auftragsblatt" style="width:100%;height:100%;border:0;display:block;background:transparent"></iframe>';
    dlg.addEventListener('close', () => {
        const f = document.getElementById('orFormFrame');
        if (f) f.src = 'about:blank';
    });
    document.body.appendChild(dlg);

    if (!document.getElementById('orFormModalStyles')) {
        const s = document.createElement('style');
        s.id = 'orFormModalStyles';
        s.textContent = '#orFormModal::backdrop { background: rgba(15,23,42,0.5); }';
        document.head.appendChild(s);
    }
    return dlg;
}

function closeOrderFormModal() {
    document.getElementById('orFormModal')?.close();
}

// ============================================================
// SAVED PDFs LIST (per Bestellung)
// ============================================================
async function openSavedPdfsList(bestellId) {
    const apiBase = window.location.origin + '/api';
    const token = localStorage.getItem('hms_token');
    const headers = { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' };

    const modal = ensureSavedPdfsModal();
    const body  = document.getElementById('savedPdfsBody');
    document.getElementById('savedPdfsTitle').textContent = 'Gespeicherte PDFs — ' + bestellId;
    body.innerHTML = `<div style="text-align:center;padding:40px;color:var(--gray-400)">${orSpinner(28)}<div style="margin-top:8px">Lade…</div></div>`;
    modal.style.display = 'flex';

    try {
        const res = await fetch(apiBase + '/orders/' + bestellId + '/pdfs', { headers });
        const data = await res.json();
        const pdfs = data.pdfs || [];

        if (!pdfs.length) {
            body.innerHTML = `
                <div style="text-align:center;padding:40px 20px;color:var(--gray-400)">
                    ${orIcon('picture_as_pdf', 'font-size:3rem;color:#cbd5e1')}
                    <h3 style="margin:12px 0 4px;color:var(--gray-600)">Noch keine PDFs gespeichert</h3>
                    <p style="font-size:0.85rem">Öffne das Auftragsblatt und klicke „Speichern" oder „Teilen", um ein PDF zu erzeugen.</p>
                    <md-filled-button onclick="openOrderForm('${bestellId}');closeSavedPdfsModal()" style="margin-top:14px">
                        <md-icon slot="icon">picture_as_pdf</md-icon>Auftragsblatt öffnen
                    </md-filled-button>
                </div>`;
            return;
        }

        body.innerHTML = pdfs.map(p => {
            const dt = new Date(p.generated_at.replace(' ', 'T')).toLocaleString('de-DE');
            const size = p.file_size < 1024 ? p.file_size + ' B'
                : p.file_size < 1024*1024 ? (p.file_size/1024).toFixed(1) + ' KB'
                : (p.file_size/1024/1024).toFixed(2) + ' MB';
            return `
                <div class="or-pdf-row">
                    ${orIcon('picture_as_pdf', 'font-size:1.6rem;color:#dc2626')}
                    <div class="or-pdf-info">
                        <div class="or-pdf-name">${escapeHtml(p.name)}</div>
                        <div class="or-pdf-meta">
                            ${dt} · ${size}
                            ${p.anonymize_workers ? ' · 🔒 Anonymisiert' : ''}
                            ${p.share_count > 0 ? ' · 🔗 ' + p.share_count + ' Share-Links' : ''}
                        </div>
                    </div>
                    <div class="or-pdf-actions">
                        <md-filled-button class="or-pdf-btn primary" title="Anzeigen / Herunterladen" onclick="downloadSavedPdfFromList(${p.id}, ${JSON.stringify(p.name).replace(/"/g,'&quot;')})"><md-icon slot="icon">download</md-icon></md-filled-button>
                        <md-outlined-button class="or-pdf-btn" title="Teilen" onclick="shareSavedPdfFromList(${p.id})"><md-icon slot="icon">share</md-icon></md-outlined-button>
                        <md-text-button class="md-btn-danger or-pdf-btn danger" title="Löschen" onclick="deleteSavedPdfFromList(${p.id}, '${bestellId}')"><md-icon slot="icon">delete</md-icon></md-text-button>
                    </div>
                </div>`;
        }).join('');
    } catch(e) {
        body.innerHTML = `<div style="text-align:center;padding:30px;color: var(--md-sys-color-error)">${orIcon('error', 'font-size:15px;vertical-align:-2px')} ` + e.message + '</div>';
    }
}

function ensureSavedPdfsModal() {
    let modal = document.getElementById('savedPdfsModal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'savedPdfsModal';
    modal.style.cssText = 'display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.55);backdrop-filter:blur(2px);align-items:center;justify-content:center';
    modal.innerHTML = `
        <div style="background:var(--white);border-radius:14px;box-shadow: 0 25px 60px color-mix(in srgb, var(--md-sys-color-scrim) 35%, transparent);max-width:620px;width:92vw;max-height:85vh;display:flex;flex-direction:column">
            <div style="padding:18px 22px;border-bottom:1px solid var(--gray-200);display:flex;justify-content:space-between;align-items:center">
                <div>
                    <div id="savedPdfsTitle" style="font-size:1.05rem;font-weight:700;color:var(--gray-800)">Gespeicherte PDFs</div>
                    <div style="font-size:0.78rem;color:var(--gray-500);margin-top:2px">Verlauf aller PDFs für diese Bestellung</div>
                </div>
                <md-icon-button onclick="closeSavedPdfsModal()" title="Schließen" aria-label="Schließen"><md-icon>close</md-icon></md-icon-button>
            </div>
            <div id="savedPdfsBody" style="padding:18px 22px;overflow-y:auto;flex:1"></div>
        </div>`;
    document.body.appendChild(modal);

    // Inline styles for the PDF rows
    if (!document.getElementById('savedPdfsStyles')) {
        const s = document.createElement('style');
        s.id = 'savedPdfsStyles';
        s.textContent = `
            .or-pdf-row { display:flex; align-items:center; gap:12px; padding:12px; border:1px solid var(--gray-200); border-radius:10px; margin-bottom:8px; transition:background 0.15s }
            .or-pdf-row:hover { background:var(--gray-50) }
            .or-pdf-icon { font-size:1.6rem; color:#dc2626 }
            .or-pdf-info { flex:1; min-width:0 }
            .or-pdf-name { font-weight:600; font-size:0.9rem; color:var(--gray-800) }
            .or-pdf-meta { font-size:0.75rem; color:var(--gray-500); margin-top:2px }
            .or-pdf-actions { display:flex; gap:4px }
            .or-pdf-btn { width:32px; height:32px; border-radius:7px; border:1px solid var(--gray-200); background:var(--white); color:var(--gray-500); cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.15s }
            .or-pdf-btn:hover { background:var(--gray-50); color:var(--gray-800) }
            .or-pdf-btn.primary { background:#3b82f6; color:#fff; border-color:#3b82f6 }
            .or-pdf-btn.primary:hover { background:#2563eb }
            .or-pdf-btn.danger:hover { background:#fee2e2; color:#dc2626; border-color:#fecaca }
            .or-row-actions button.pdfs { background:#fef3c7; color:#92400e; border-color:#fde68a }
            .or-row-actions button.pdfs:hover { background:#fde68a }
        `;
        document.head.appendChild(s);
    }
    return modal;
}

function closeSavedPdfsModal() {
    const m = document.getElementById('savedPdfsModal');
    if (m) m.style.display = 'none';
}

async function downloadSavedPdfFromList(pdfId, name) {
    const token = localStorage.getItem('hms_token');
    try {
        const res = await fetch(window.location.origin + '/api/pdfs/' + pdfId + '/download',
            { headers: { 'Authorization': 'Bearer ' + token } });
        if (!res.ok) throw new Error('Server-Fehler');
        const blob = await res.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = (name || ('PDF_' + pdfId)) + '.pdf';
        a.click();
        URL.revokeObjectURL(a.href);
    } catch(e) { alert('Fehler: ' + e.message); }
}

async function shareSavedPdfFromList(pdfId) {
    const token = localStorage.getItem('hms_token');
    try {
        const res = await fetch(window.location.origin + '/api/pdfs/' + pdfId + '/share', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
            body: JSON.stringify({ expires_in_days: 30 }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Fehler');
        await navigator.clipboard.writeText(data.share_url);
        alert('Share-Link in die Zwischenablage kopiert!\n\n' + data.share_url + '\n\nGültig 30 Tage.');
    } catch(e) { alert('Fehler: ' + e.message); }
}

async function deleteSavedPdfFromList(pdfId, bestellId) {
    const ok = await mdConfirm('PDF wirklich löschen?', 'PDF löschen');
    if (!ok) return;
    const token = localStorage.getItem('hms_token');
    try {
        const res = await fetch(window.location.origin + '/api/pdfs/' + pdfId,
            { method: 'DELETE', headers: { 'Authorization': 'Bearer ' + token } });
        const data = await res.json();
        if (data.success) {
            openSavedPdfsList(bestellId); // refresh
        } else alert(data.error || 'Fehler');
    } catch(e) { alert('Fehler: ' + e.message); }
}

// ============================================================
// CSV EXPORT
// ============================================================
async function orExportCSV() {
    const filtered = orFilterAndSort(_orState.orders);
    if (filtered.length === 0) { showToast('Keine Bestellungen zum Export', 'error'); return; }

    const header = ['Bestell-ID', 'Eingang-ID', 'Kunde-ID', 'Kunde', 'Bereich', 'Objekt-ID', 'Objekt',
        'Kunden-Obj-Nr', 'Monat', 'Jahr', 'Datum von', 'Datum bis', 'Zeit von', 'Zeit bis',
        'Soll-Std', 'Preis Kunde €', 'Umsatz (geschätzt) €',
        'Einsätze total', 'Bestätigt', 'Geplant', 'Offen', 'Abgelehnt', 'Fortschritt %',
        'Status', 'Überfällig', 'NU'];

    const r2 = n => Math.round(n * 100) / 100;
    const rows = filtered.map(o => {
        const sollStd = parseFloat(o.soll_std_betrag) || 0;
        const preis = parseFloat(o.obj_preis_kunde) || 0;
        const revenue = orEstimatedRevenue(o);
        const total = parseInt(o.einsatz_total) || 0;
        const bestaetigt = parseInt(o.einsatz_bestaetigt) || 0;
        const pct = total > 0 ? Math.round((bestaetigt / total) * 100) : 0;
        return [
            o.bestell_id || '',
            o.eingang_id || '',
            o.kunden_id || '',
            o.kundenname || '',
            o.kunden_bereich || '',
            o.obj_id || '',
            o.objektname || '',
            o.kunden_obj_nr || '',
            o.monat || '',
            o.jahr || '',
            o.datum_von || '',
            o.datum_bis || '',
            o.zeit_von || '',
            o.zeit_bis || '',
            r2(sollStd),
            r2(preis),
            r2(revenue),
            total,
            bestaetigt,
            o.einsatz_geplant || 0,
            o.einsatz_ausstehend || 0,
            o.einsatz_abgelehnt || 0,
            pct,
            o.status || '',
            o.ueberfaellig ? 'JA' : '',
            o.nu_name || ''
        ];
    });

    const dateStr = new Date().toISOString().substring(0, 10);
    try {
        await downloadXlsx(`Bestellungen_${dateStr}.xlsx`, 'Bestellungen', header, rows);
        showToast(`${rows.length} Bestellungen exportiert`);
    } catch (err) {
        showToast(err.message || 'Export fehlgeschlagen', 'error');
    }
}

// ============================================================
// ANALYTICS TAB
// ============================================================
function orEnsureChartJs(callback) {
    if (typeof Chart !== 'undefined') { callback(); return; }
    if (document.getElementById('maChartJsScript')) {
        const check = setInterval(() => {
            if (typeof Chart !== 'undefined') { clearInterval(check); callback(); }
        }, 100);
        return;
    }
    const script = document.createElement('script');
    script.id = 'maChartJsScript';
    script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    script.onload = () => callback();
    script.onerror = () => showToast('Chart.js konnte nicht geladen werden', 'error');
    document.head.appendChild(script);
}

function orDestroyCharts() {
    Object.values(_orCharts).forEach(c => { try { c.destroy(); } catch (e) {} });
    _orCharts = {};
}

function orRenderAnalytics() {
    const mainBody = document.getElementById('orMainBody');
    if (!mainBody) return;

    if (_orState.orders.length === 0) {
        mainBody.innerHTML = `<div class="or-empty">${orIcon('pie_chart')}<p>Keine Daten für Analytics</p></div>`;
        return;
    }

    mainBody.innerHTML = `
        <div class="or-analytics-grid">
            <div class="or-chart-card">
                <div class="or-chart-card-title">${orIcon('bar_chart', 'font-size:15px')} Bestellungen pro Monat</div>
                <div class="or-chart-canvas"><canvas id="orChartMonthly"></canvas></div>
            </div>
            <div class="or-chart-card">
                <div class="or-chart-card-title">${orIcon('pie_chart', 'font-size:15px')} Status-Verteilung</div>
                <div class="or-chart-canvas-small"><canvas id="orChartStatus"></canvas></div>
            </div>
        </div>
        <div class="or-analytics-grid">
            <div class="or-chart-card">
                <div class="or-chart-card-title">${orIcon('trophy', 'font-size:15px')} Top 10 Kunden (nach Umsatz)</div>
                <div id="orTopKunden" class="or-top-list"></div>
            </div>
            <div class="or-chart-card">
                <div class="or-chart-card-title">${orIcon('domain', 'font-size:15px')} Top 10 Objekte (nach Std)</div>
                <div id="orTopObjekte" class="or-top-list"></div>
            </div>
        </div>
        <div class="or-analytics-grid">
            <div class="or-chart-card">
                <div class="or-chart-card-title">${orIcon('show_chart', 'font-size:15px')} Dispo-Fortschritt — Kumulativ</div>
                <div class="or-chart-canvas"><canvas id="orChartProgress"></canvas></div>
            </div>
            <div class="or-chart-card">
                <div class="or-chart-card-title">${orIcon('handshake', 'font-size:15px')} Top NU</div>
                <div id="orTopNu" class="or-top-list"></div>
            </div>
        </div>
    `;

    orRenderTopLists();
    orEnsureChartJs(() => {
        orDestroyCharts();
        orRenderMonthlyChart();
        orRenderStatusChart();
        orRenderProgressChart();
    });
}

function orRenderMonthlyChart() {
    const canvas = document.getElementById('orChartMonthly');
    if (!canvas) return;

    // Group orders by monat+jahr
    const byMonth = {};
    _orState.orders.forEach(o => {
        if (!o.monat || !o.jahr) return;
        const key = `${o.jahr}-${String(o.monat).padStart(2, '0')}`;
        if (!byMonth[key]) byMonth[key] = { offen: 0, abgeschlossen: 0, storniert: 0 };
        byMonth[key][o.status] = (byMonth[key][o.status] || 0) + 1;
    });
    const sortedKeys = Object.keys(byMonth).sort();
    const labels = sortedKeys.map(k => {
        const [y, m] = k.split('-');
        return MONTHS_DE[parseInt(m)].substring(0, 3) + ' ' + y.substring(2);
    });

    _orCharts.monthly = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'Offen',         data: sortedKeys.map(k => byMonth[k].offen),         backgroundColor: '#2563eb', borderRadius: 4 },
                { label: 'Abgeschlossen', data: sortedKeys.map(k => byMonth[k].abgeschlossen), backgroundColor: '#16a34a', borderRadius: 4 },
                { label: 'Storniert',     data: sortedKeys.map(k => byMonth[k].storniert),     backgroundColor: '#dc2626', borderRadius: 4 },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: { stacked: true, ticks: { precision: 0 } }
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
            }
        }
    });
}

function orRenderStatusChart() {
    const canvas = document.getElementById('orChartStatus');
    if (!canvas) return;
    const offen = _orState.orders.filter(o => o.status === 'offen').length;
    const done = _orState.orders.filter(o => o.status === 'abgeschlossen').length;
    const cancel = _orState.orders.filter(o => o.status === 'storniert').length;
    const total = offen + done + cancel;

    if (total === 0) {
        canvas.parentElement.innerHTML = '<div style="text-align:center;color:var(--or-text-muted);padding:60px 20px;font-size:0.85rem">Keine Daten</div>';
        return;
    }

    _orCharts.status = new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Offen', 'Abgeschlossen', 'Storniert'],
            datasets: [{
                data: [offen, done, cancel],
                backgroundColor: ['#2563eb', '#16a34a', '#dc2626'],
                borderColor: '#fff',
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed + ' (' + Math.round((ctx.parsed / total) * 100) + '%)' } }
            }
        }
    });
}

function orRenderProgressChart() {
    const canvas = document.getElementById('orChartProgress');
    if (!canvas) return;
    // Cumulative confirmations per month
    const byMonth = {};
    _orState.orders.forEach(o => {
        if (!o.monat || !o.jahr) return;
        const key = `${o.jahr}-${String(o.monat).padStart(2, '0')}`;
        if (!byMonth[key]) byMonth[key] = { total: 0, bestaetigt: 0 };
        byMonth[key].total += parseInt(o.einsatz_total) || 0;
        byMonth[key].bestaetigt += parseInt(o.einsatz_bestaetigt) || 0;
    });
    const sortedKeys = Object.keys(byMonth).sort();
    const labels = sortedKeys.map(k => {
        const [y, m] = k.split('-');
        return MONTHS_DE[parseInt(m)].substring(0, 3) + ' ' + y.substring(2);
    });

    _orCharts.progress = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'Total Einsätze',  data: sortedKeys.map(k => byMonth[k].total),      borderColor: '#1e293b', backgroundColor: 'rgba(30,41,59,.1)', tension: 0.3, fill: true },
                { label: 'Bestätigt',       data: sortedKeys.map(k => byMonth[k].bestaetigt), borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.1)',  tension: 0.3, fill: true },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { ticks: { precision: 0 } } },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }
        }
    });
}

function orRenderTopLists() {
    // Top Kunden (by estimated revenue)
    const byKunde = {};
    _orState.orders.forEach(o => {
        if (o.status === 'storniert') return;
        const k = o.kundenname || o.kunden_id || 'Unbekannt';
        const sollStd = parseFloat(o.soll_std_betrag) || 0;
        const preis = parseFloat(o.obj_preis_kunde) || 0;
        const revenue = orEstimatedRevenue(o);
        if (!byKunde[k]) byKunde[k] = { orders: 0, std: 0, revenue: 0 };
        byKunde[k].orders++;
        byKunde[k].std += sollStd;
        byKunde[k].revenue += revenue;
    });
    const topKunden = Object.entries(byKunde).sort((a, b) => b[1].revenue - a[1].revenue).slice(0, 10);
    const k1 = document.getElementById('orTopKunden');
    if (k1) {
        k1.innerHTML = topKunden.length === 0
            ? '<div style="color:var(--or-text-muted);font-size:0.85rem;padding:14px;text-align:center">Keine Daten</div>'
            : topKunden.map(([name, info], i) => {
                const cls = i === 0 ? 'gold' : (i === 1 ? 'silver' : (i === 2 ? 'bronze' : ''));
                return `<div class="or-top-item">
                    <span class="or-top-rank ${cls}">${i + 1}</span>
                    <span class="or-top-name">${escapeHtml(name)} <small style="color:var(--or-text-muted);font-weight:500"> · ${info.orders} Best.</small></span>
                    <span class="or-top-value">${info.revenue.toLocaleString('de-DE', {maximumFractionDigits: 0})} €</span>
                </div>`;
            }).join('');
    }

    // Top Objekte (by soll std)
    const byObjekt = {};
    _orState.orders.forEach(o => {
        if (o.status === 'storniert') return;
        const ob = o.objektname || o.obj_id || 'Unbekannt';
        const sollStd = parseFloat(o.soll_std_betrag) || 0;
        if (!byObjekt[ob]) byObjekt[ob] = { orders: 0, std: 0 };
        byObjekt[ob].orders++;
        byObjekt[ob].std += sollStd;
    });
    const topObjekte = Object.entries(byObjekt).sort((a, b) => b[1].std - a[1].std).slice(0, 10);
    const o1 = document.getElementById('orTopObjekte');
    if (o1) {
        o1.innerHTML = topObjekte.length === 0
            ? '<div style="color:var(--or-text-muted);font-size:0.85rem;padding:14px;text-align:center">Keine Daten</div>'
            : topObjekte.map(([name, info], i) => {
                const cls = i === 0 ? 'gold' : (i === 1 ? 'silver' : (i === 2 ? 'bronze' : ''));
                return `<div class="or-top-item">
                    <span class="or-top-rank ${cls}">${i + 1}</span>
                    <span class="or-top-name">${escapeHtml(name)} <small style="color:var(--or-text-muted);font-weight:500"> · ${info.orders} Best.</small></span>
                    <span class="or-top-value">${info.std.toFixed(0)}h</span>
                </div>`;
            }).join('');
    }

    // Top NU
    const byNu = {};
    _orState.orders.forEach(o => {
        if (!o.nu_name) return;
        const std = parseFloat(o.soll_std_betrag) || 0;
        if (!byNu[o.nu_name]) byNu[o.nu_name] = { orders: 0, std: 0 };
        byNu[o.nu_name].orders++;
        byNu[o.nu_name].std += std;
    });
    const topNu = Object.entries(byNu).sort((a, b) => b[1].std - a[1].std).slice(0, 10);
    const n1 = document.getElementById('orTopNu');
    if (n1) {
        n1.innerHTML = topNu.length === 0
            ? '<div style="color:var(--or-text-muted);font-size:0.85rem;padding:14px;text-align:center">Keine NU-Zuweisungen</div>'
            : topNu.map(([name, info], i) => {
                const cls = i === 0 ? 'gold' : (i === 1 ? 'silver' : (i === 2 ? 'bronze' : ''));
                return `<div class="or-top-item">
                    <span class="or-top-rank ${cls}">${i + 1}</span>
                    <span class="or-top-name">${escapeHtml(name)} <small style="color:var(--or-text-muted);font-weight:500"> · ${info.orders} Best.</small></span>
                    <span class="or-top-value">${info.std.toFixed(0)}h</span>
                </div>`;
            }).join('');
    }
}
