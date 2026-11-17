/* ============================================================
   HMS / Clood-X — Mitarbeiter Module (v2)
   Provides:
     - Stats Bento (5 KPIs)
     - Warning banner (expired/expiring docs alert)
     - Toolbar (search + filter pills + Beschäftigung/Bereich filter + App-Link + CSV)
     - Cards grid with mini-badges (compliance hints)
     - Profile view (sidebar + 5 tabs incl. Compliance)
     - Modal Create/Edit with 5 tabs
     - Show generated initial password ONCE on create
     - CSV export
   Backend: GET  /api/workers (filterable)
            GET  /api/workers/stats
            GET  /api/workers/expiring-docs
            GET  /api/workers/{id}
            GET  /api/workers/{id}/checklist
            GET  /api/workers/export
            POST /api/workers (returns initial_password)
            PUT  /api/workers/{id}
            POST /api/workers/reset-password
            POST /api/workers/upload-photo
   ============================================================ */

const WK_API_BASE = (typeof API_BASE !== 'undefined') ? API_BASE : (window.location.origin + '/api');
const WK_API = {
    list:          WK_API_BASE + '/workers',
    stats:         WK_API_BASE + '/workers/stats',
    expiringDocs:  WK_API_BASE + '/workers/expiring-docs',
    export:        WK_API_BASE + '/workers/export',
    show:          (id) => WK_API_BASE + '/workers?id=' + encodeURIComponent(id),
    checklist:     (id) => WK_API_BASE + '/workers/' + encodeURIComponent(id) + '/checklist',
    activity:      (id) => WK_API_BASE + '/workers/' + encodeURIComponent(id) + '/activity',
    create:        WK_API_BASE + '/workers',
    update:        (id) => WK_API_BASE + '/workers/' + encodeURIComponent(id),
    delete:        (id) => WK_API_BASE + '/workers/' + encodeURIComponent(id),
    resetPassword: WK_API_BASE + '/workers/reset-password',
    uploadPhoto:   WK_API_BASE + '/workers/upload-photo',
    docs:          WK_API_BASE + '/worker-documents',
    absences:      WK_API_BASE + '/worker-absences',
    renewals:      WK_API_BASE + '/worker-doc-renewals',
    renewalsResend: (id) => WK_API_BASE + '/worker-doc-renewals/' + encodeURIComponent(id) + '/resend',
    renewalsLog:   (id) => WK_API_BASE + '/worker-doc-renewals/' + encodeURIComponent(id) + '/log',
};

// ---------- MD3 helpers (official <md-dialog> + Material Symbols) ----------
// The workers module no longer uses the legacy openModal() shell.
// Confirms come from mdConfirm() (users.js) — global at runtime.
function wkIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}
function openWorkersDialog(title, bodyHtml, footerHtml = '', wide = false) {
    document.getElementById('workersMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'workersMdDialog';
    dlg.classList.add('wk-context');
    if (wide) dlg.classList.add('workers-dialog-xl');
    dlg.innerHTML = `
        <div slot="headline">${title}</div>
        <div slot="content" class="workers-dialog-content" id="workersDialogBody">${bodyHtml}</div>
        ${footerHtml ? `<div slot="actions">${footerHtml}</div>` : ''}`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => {
        document.documentElement.classList.remove('modal-open');
        dlg.remove();
    });
    document.documentElement.classList.add('modal-open');
    dlg.show();
    return dlg;
}
function closeWorkersDialog() { document.getElementById('workersMdDialog')?.close(); }
const wkSpinner = (size = 32) => `<md-circular-progress indeterminate style="--md-circular-progress-size:${size}px"></md-circular-progress>`;

const AVATAR_COLORS = [
    '#0891b2','#0d9488','#0ea5e9','#6366f1','#8b5cf6',
    '#ec4899','#f59e0b','#10b981','#06b6d4','#3b82f6',
];

const ABSENCE_TYPES = [
    { name: 'Arzt Termin',          bezahlt: 1 },
    { name: 'Berufsschule',         bezahlt: 0 },
    { name: 'Fehlt unentschuldigt', bezahlt: 0 },
    { name: 'Feiertag',             bezahlt: 1 },
    { name: 'Frei aus Überstunden', bezahlt: 1 },
    { name: 'Krank Mit AU',         bezahlt: 1 },
    { name: 'Krank Ohne AU',        bezahlt: 0 },
    { name: 'Kunden Außendienst',   bezahlt: 1 },
    { name: 'Kurierdienst',         bezahlt: 1 },
    { name: 'Unbezahlte Urlaub',    bezahlt: 0 },
    { name: 'Urlaub',               bezahlt: 1 },
    { name: 'Wunschfrei',           bezahlt: 0 },
];

const ART_DER_BESCHAEFTIGUNG = [
    'Vollzeit','Vollzeit 160Std','Vollzeit 140Std',
    'Teilzeit','Teilzeit 80 Std.','Teilzeit 100 Std',
    'Minijob','Werkstudent','Werkstudientenvertrag','Auf Abruf',
];

const _wkState = {
    workers: [],
    stats: null,
    filterMode: 'aktiv',  // 'aktiv' | 'inaktiv' | 'alle'
    search: '',
    bereich: '',
    beschaeftigung: '',
};

// ============================================================
// Helpers
// ============================================================
function wkAvatarColor(seed) {
    if (!seed) return AVATAR_COLORS[0];
    let hash = 0;
    for (let i = 0; i < seed.length; i++) hash = seed.charCodeAt(i) + ((hash << 5) - hash);
    return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
}

function wkInitials(vorname, name) {
    return ((vorname || '')[0] || '').toUpperCase() + ((name || '')[0] || '').toUpperCase();
}

function wkDocExpiryStatus(ablaufdatum) {
    if (!ablaufdatum) return null;
    const today = new Date(); today.setHours(0,0,0,0);
    const exp = new Date(ablaufdatum); exp.setHours(0,0,0,0);
    const diff = Math.ceil((exp - today) / (1000*60*60*24));
    if (diff < 0)  return { status: 'expired',       label: 'Abgelaufen',                  icon: 'error',         days: diff };
    if (diff <= 60) return { status: 'expiring-soon', label: `Läuft in ${diff} Tagen ab`,   icon: 'schedule',      days: diff };
    return { status: 'valid', label: `Gültig bis ${formatDate(ablaufdatum)}`, icon: 'check_circle', days: diff };
}

function wkBirthdayThisMonth(geburtstag) {
    if (!geburtstag) return false;
    const d = new Date(geburtstag);
    const now = new Date();
    return d.getMonth() === now.getMonth();
}

/**
 * Profile completeness — checks the HR-critical fields that we want every
 * active Mitarbeiter to have filled in. Returns:
 *   { pct: 0-100, missing: [{ key, label }], severity: 'critical'|'warn'|null }
 *
 * "critical" means at least one of the absolutely-required fields
 * (email for app login, stundenlohn for Controlling, steuer_id, sv_nummer,
 * krankenkasse, bank_verbindung) is missing — the card gets a red badge.
 * "warn" means the profile is < 80% complete but the critical fields are
 * present — amber badge.
 *
 * Inactive workers (austritt set / aktiv = 0) are skipped: nothing to fix.
 */
/**
 * "Vorschlag" button next to the Barcode input in the worker edit modal.
 *
 * Calls GET /workers/barcode/suggest, which returns a unique 7-character
 * code (uppercase letters + digits, ambiguous chars stripped). The value
 * is dropped into the #f_w_barcode input, leaving it editable so the
 * admin can override before saving. The same uniqueness check runs on
 * the backend at save time, so even a manually edited value is verified.
 *
 * The button briefly switches to a spinner while the request is in
 * flight to give visual feedback on slow networks. Errors surface via
 * the standard showToast() helper.
 */
window.wkSuggestBarcode = async function(btn) {
    const input = document.getElementById('f_w_barcode');
    if (!input) return;
    const original = btn ? btn.innerHTML : null;
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<md-circular-progress indeterminate style="--md-circular-progress-size:18px"></md-circular-progress>';
    }
    try {
        const res = await apiGet(WK_API_BASE + '/workers/barcode/suggest');
        if (res && res.barcode) {
            input.value = res.barcode;
            input.focus();
            input.select();
        } else {
            showToast(res?.error || 'Konnte keinen Vorschlag erzeugen', 'warning');
        }
    } catch (err) {
        showToast('Fehler beim Erzeugen: ' + (err?.message || err), 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    }
};

const WK_REQUIRED_FIELDS = [
    // HR-critical (red if missing on any active worker)
    { key: 'email',                 label: 'E-Mail',         critical: true  },
    { key: 'stundenlohn',           label: 'Stundenlohn',    critical: true, isMissing: (v) => v === null || v === undefined || Number(v) <= 0 },
    { key: 'steuer_id',             label: 'Steuer-ID',      critical: true  },
    { key: 'sv_nummer',             label: 'SV-Nummer',      critical: true  },
    { key: 'krankenkasse',          label: 'Krankenkasse',   critical: true  },
    { key: 'bank_verbindung',       label: 'IBAN',           critical: true  },
    // Operational (amber)
    { key: 'telefon',               label: 'Telefon'                         },
    { key: 'bereich',               label: 'Bereich'                         },
    { key: 'art_der_beschaeftigung',label: 'Beschäftigung'                   },
    { key: 'eintritt',              label: 'Eintritt'                        },
    { key: 'urlaubstage',           label: 'Urlaubstage', isMissing: (v) => v === null || v === undefined || v === '' || Number(v) <= 0 },
    { key: 'arbeitsstunden',        label: 'Arbeitsstunden', isMissing: (v) => v === null || v === undefined || v === '' || Number(v) <= 0 },
];

function wkProfileCompleteness(w) {
    if (!w || w.aktiv != 1) return { pct: 100, missing: [], severity: null };

    const missing = [];
    let critMissing = 0;

    for (const f of WK_REQUIRED_FIELDS) {
        const v = w[f.key];
        const empty = f.isMissing
            ? f.isMissing(v)
            : (v === null || v === undefined || String(v).trim() === '');
        if (empty) {
            missing.push({ key: f.key, label: f.label, critical: !!f.critical });
            if (f.critical) critMissing++;
        }
    }

    const filled = WK_REQUIRED_FIELDS.length - missing.length;
    const pct = Math.round((filled / WK_REQUIRED_FIELDS.length) * 100);

    let severity = null;
    if (critMissing > 0)      severity = 'critical';
    else if (pct < 80)        severity = 'warn';

    return { pct, missing, severity };
}

// ============================================================
// MAIN RENDER
// ============================================================
async function renderWorkers() {
    const content = document.getElementById('content');
    // List already on screen (after a save / delete): no spinner, patch in place.
    const soft = !!document.getElementById('wkGrid') && typeof hmsMorph === 'function';
    if (!soft) content.innerHTML = `<div class="ph-loading" style="padding:40px;text-align:center;color:var(--wk-text-muted)">${wkSpinner()} Lade Mitarbeiter...</div>`;

    try {
        const [workersRaw, stats, pendingResp] = await Promise.all([
            apiGet(WK_API.list),
            apiGet(WK_API.stats),
            // Map of { dienst_nr -> pending count } so each card can render
            // its own "X offene Anträge" red badge.
            apiGet(window.location.origin + '/api/worker-absences/pending-counts'),
        ]);
        _wkState.workers = Array.isArray(workersRaw) ? workersRaw : [];
        _wkState.stats = stats || null;
        _wkState.pendingByWorker = (pendingResp && pendingResp.pending) || {};
    } catch (e) {
        content.innerHTML = `<div class="wk-empty">${wkIcon('warning')}<p>Fehler beim Laden</p></div>`;
        return;
    }

    const html = `
        ${wkBentoHtml(_wkState.stats)}
        ${wkWarningBannerHtml(_wkState.stats)}
        ${wkAppLinkBarHtml()}
        ${wkToolbarHtml()}
        <div class="wk-grid" id="wkGrid"></div>
        <div id="wkPager" class="be-pager"></div>
    `;
    if (soft && document.getElementById('wkGrid')) {
        // Toolbar keeps its listeners + typed search; the link bar its open state.
        hmsMorph(content, html, { keep: el => el.id === 'wkGrid' || el.id === 'workerPortalLinkBar' || el.classList.contains('wk-toolbar') });
    } else {
        content.innerHTML = html;
        wkBindToolbar();
    }
    wkApplyFilters();

    // Deep-link from a notification: open the worker's profile, optionally on
    // a specific tab — e.g. '#workers?dienst_nr=MA019&tab=abwesenheiten'.
    try {
        const fq = sessionStorage.getItem('hms_focus_workers');
        if (fq) {
            sessionStorage.removeItem('hms_focus_workers');
            const p = new URLSearchParams(fq);
            const nr = p.get('dienst_nr');
            const tab = p.get('tab');
            if (nr) {
                wkOpenProfile(nr);
                if (tab) setTimeout(() => {
                    const t = document.querySelector('.wk-tab[data-tab="' + tab + '"]');
                    if (t) wkSwitchTab(t);
                }, 250);
            }
        }
    } catch (e) { /* sessionStorage unavailable — ignore */ }
}

function wkBentoHtml(s) {
    if (!s) {
        return `<div class="wk-stats-bento">${[1,2,3,4,5].map(()=>`<div class="wk-kpi"></div>`).join('')}</div>`;
    }

    const totalDocs = (s.docs_expired || 0) + (s.docs_expiring || 0);
    const docsClass = (s.docs_expired || 0) > 0 ? 'wk-kpi-danger' : (totalDocs > 0 ? 'wk-kpi-warn' : 'wk-kpi-ok');
    const docsIcon = (s.docs_expired || 0) > 0 ? 'warning' : 'shield';

    // Beschäftigung distribution as compact list
    const beschTop = (s.beschaeftigung || []).slice(0, 3).map(b => `${b.anzahl} ${b.typ || '–'}`).join(' · ');

    return `
        <div class="wk-stats-bento">
            <div class="wk-kpi" data-clickable="1" onclick="wkOpenTotalDetail()" title="Details zu allen Mitarbeitern">
                <div class="wk-kpi-head">
                    <div class="wk-kpi-icon">${wkIcon('group')}</div>
                    <span class="wk-kpi-label">Total</span>
                </div>
                <div class="wk-kpi-value">${s.total ?? 0}<small>/ ${s.aktiv ?? 0} aktiv</small></div>
                <div class="wk-kpi-sub">${s.inaktiv ?? 0} inaktiv${s.new_hires_30d ? ` · <strong>${s.new_hires_30d}</strong> neu (30 T.)` : ''}</div>
            </div>

            <div class="wk-kpi wk-kpi-teal" data-clickable="1" onclick="wkOpenBeschaeftigungDetail()" title="Verteilung anzeigen — Klick filtert die Liste">
                <div class="wk-kpi-head">
                    <div class="wk-kpi-icon">${wkIcon('work')}</div>
                    <span class="wk-kpi-label">Beschäftigung</span>
                </div>
                <div class="wk-kpi-value" style="font-size:1rem;font-weight:700;line-height:1.3">${beschTop || '–'}</div>
                <div class="wk-kpi-sub">aktive Verteilung</div>
            </div>

            <div class="wk-kpi ${(s.birthdays_this_month || 0) > 0 ? '' : ''}" data-clickable="1" onclick="wkOpenBirthdays()" title="Geburtstage diesen Monat anzeigen" style="${(s.birthdays_this_month || 0) > 0 ? 'background:linear-gradient(135deg,#fdf2f8 0%,#fce7f3 100%);border-color:#fbcfe8' : ''}">
                <div class="wk-kpi-head">
                    <div class="wk-kpi-icon" style="background: var(--md-sys-color-error-container);color: var(--md-sys-color-error)">${wkIcon('cake')}</div>
                    <span class="wk-kpi-label">Geburtstage</span>
                </div>
                <div class="wk-kpi-value" style="${(s.birthdays_this_month || 0) > 0 ? 'color:#be185d' : ''}">${s.birthdays_this_month ?? 0}</div>
                <div class="wk-kpi-sub">diesen Monat</div>
            </div>

            <div class="wk-kpi ${docsClass}" data-clickable="1" onclick="wkOpenExpiringDocs()" title="Ablaufende Dokumente anzeigen">
                <div class="wk-kpi-head">
                    <div class="wk-kpi-icon">${wkIcon(docsIcon)}</div>
                    <span class="wk-kpi-label">Dokumente</span>
                </div>
                <div class="wk-kpi-value">${totalDocs}</div>
                <div class="wk-kpi-sub">${(s.docs_expired || 0) ? `<strong>${s.docs_expired}</strong> abgelaufen · ` : ''}${(s.docs_expiring || 0)} laufen ab</div>
            </div>

            <div class="wk-kpi" data-clickable="1" onclick="wkOpenUrlaubDetail()" title="Urlaubs-Auslastung pro Mitarbeiter anzeigen">
                <div class="wk-kpi-head">
                    <div class="wk-kpi-icon">${wkIcon('beach_access')}</div>
                    <span class="wk-kpi-label">Ø Urlaub</span>
                </div>
                <div class="wk-kpi-value">${s.avg_urlaub_usage_pct !== null && s.avg_urlaub_usage_pct !== undefined ? Math.round(s.avg_urlaub_usage_pct) + ' %' : '–'}</div>
                <div class="wk-kpi-sub">Auslastung dieses Jahr</div>
            </div>
        </div>
    `;
}

function wkWarningBannerHtml(s) {
    if (!s) return '';
    const expired = s.docs_expired || 0;
    const expiring = s.docs_expiring || 0;
    if (expired === 0 && expiring === 0) return '';

    const cls = expired > 0 ? 'danger' : '';
    const icon = expired > 0 ? 'warning' : 'hourglass_top';
    const text = expired > 0
        ? `<strong>${expired}</strong> Dokument${expired === 1 ? '' : 'e'} abgelaufen${expiring ? ` · <strong>${expiring}</strong> laufen demnächst ab` : ''}`
        : `<strong>${expiring}</strong> Dokument${expiring === 1 ? '' : 'e'} laufen in den nächsten 60 Tagen ab`;

    return `
        <div class="wk-warn-banner ${cls}" onclick="wkOpenExpiringDocs()">
            ${wkIcon(icon)}
            <div class="wk-warn-banner-text">${text}</div>
            <md-text-button class="wk-warn-banner-action">Ansehen ${wkIcon('arrow_forward', 'font-size:14px')}</md-text-button>
        </div>
    `;
}

function wkAppLinkBarHtml() {
    return `
        <div id="workerPortalLinkBar" style="display:none">
            <div class="wk-app-link-bar">
                <div class="wk-app-link-info">
                    ${wkIcon('smartphone', 'color:var(--wk-accent);font-size:1.15rem;flex-shrink:0')}
                    <div style="min-width:0">
                        <div class="wk-app-link-label">Mitarbeiter-App Link</div>
                        <div id="workerPortalLinkUrl" class="wk-app-link-url"></div>
                    </div>
                </div>
                <md-filled-tonal-button onclick="copyPortalLink('worker')"><md-icon slot="icon">content_copy</md-icon>Kopieren</md-filled-tonal-button>
            </div>
        </div>
    `;
}

function wkToolbarHtml() {
    const f = _wkState;
    const bereiche = [...new Set(_wkState.workers.flatMap(w =>
        (typeof bereichTagsOf === 'function') ? bereichTagsOf(w) : [w.bereich]
    ).filter(Boolean))].sort();
    // Layout (search | filters | actions) is a grid driven by the toolbar's
    // own width (container queries in workers.css) — see "Toolbar layout".
    // Fields carry no floating label so every control shares one height;
    // the leading icon + "Alle …" option name the filter instead.
    return `
        <div class="wk-toolbar">
            <div class="wk-tb">
                <md-outlined-text-field class="wk-search wk-tb-search" id="wkSearch" aria-label="Mitarbeiter suchen" placeholder="Mitarbeiter suchen…" value="${escapeHtml(f.search)}">
                    <md-icon slot="leading-icon">search</md-icon>
                </md-outlined-text-field>
                <div class="wk-tb-filters">
                    <md-chip-set id="wkFilterPills" class="wk-tb-status" aria-label="Status">
                        <md-filter-chip label="Aktiv" data-mode="aktiv" ${f.filterMode === 'aktiv' ? 'selected' : ''} onclick="wkSetFilter('aktiv')"></md-filter-chip>
                        <md-filter-chip label="Inaktiv" data-mode="inaktiv" ${f.filterMode === 'inaktiv' ? 'selected' : ''} onclick="wkSetFilter('inaktiv')"></md-filter-chip>
                        <md-filter-chip label="Alle" data-mode="alle" ${f.filterMode === 'alle' ? 'selected' : ''} onclick="wkSetFilter('alle')"></md-filter-chip>
                    </md-chip-set>
                    <div class="wk-tb-selects">
                        <md-outlined-select class="wk-extra-filter" id="wkFilterBeschaeftigung" aria-label="Beschäftigung" title="Beschäftigung">
                            <md-icon slot="leading-icon">work</md-icon>
                            <md-select-option value="" ${!f.beschaeftigung ? 'selected' : ''}><div slot="headline">Alle Beschäftigungen</div></md-select-option>
                            ${ART_DER_BESCHAEFTIGUNG.map(a => `<md-select-option value="${a}" ${f.beschaeftigung === a ? 'selected' : ''}><div slot="headline">${a}</div></md-select-option>`).join('')}
                        </md-outlined-select>
                        <md-outlined-select class="wk-extra-filter" id="wkFilterBereich" aria-label="Bereich" title="Bereich">
                            <md-icon slot="leading-icon">domain</md-icon>
                            <md-select-option value="" ${!f.bereich ? 'selected' : ''}><div slot="headline">Alle Bereiche</div></md-select-option>
                            ${bereiche.map(b => `<md-select-option value="${escapeHtml(b)}" ${f.bereich === b ? 'selected' : ''}><div slot="headline">${escapeHtml(b)}</div></md-select-option>`).join('')}
                        </md-outlined-select>
                    </div>
                </div>
                <div class="wk-tb-actions">
                    <md-outlined-button class="wk-tb-btn" onclick="togglePortalLink('worker')" title="Mitarbeiter-App Link"><md-icon slot="icon">smartphone</md-icon>App-Link</md-outlined-button>
                    <md-outlined-button class="wk-tb-btn" onclick="wkExportCsv()" title="CSV exportieren"><md-icon slot="icon">download</md-icon>CSV</md-outlined-button>
                    <md-filled-button class="wk-tb-btn wk-tb-primary" onclick="openWorkerModal()" style="--md-filled-button-container-color:var(--wk-accent)"><md-icon slot="icon">add</md-icon>Mitarbeiter anlegen</md-filled-button>
                </div>
            </div>
        </div>
    `;
}

function wkBindToolbar() {
    document.getElementById('wkSearch')?.addEventListener('input', (e) => {
        _wkState.search = e.target.value || '';
        wkApplyFilters();
    });
    document.getElementById('wkFilterBeschaeftigung')?.addEventListener('change', (e) => {
        _wkState.beschaeftigung = e.target.value || '';
        wkApplyFilters();
    });
    document.getElementById('wkFilterBereich')?.addEventListener('change', (e) => {
        _wkState.bereich = e.target.value || '';
        wkApplyFilters();
    });
}

function wkSetFilter(mode) {
    _wkState.filterMode = mode;
    document.querySelectorAll('#wkFilterPills md-filter-chip').forEach(c => { c.selected = c.dataset.mode === mode; });
    wkApplyFilters();
}

function wkApplyFilters() {
    const grid = document.getElementById('wkGrid');
    if (!grid) return;
    const q = (_wkState.search || '').toLowerCase().trim();
    const filtered = _wkState.workers.filter(w => {
        if (_wkState.filterMode === 'aktiv'   && w.aktiv != 1) return false;
        if (_wkState.filterMode === 'inaktiv' && w.aktiv == 1) return false;
        if (_wkState.beschaeftigung && (w.art_der_beschaeftigung || '') !== _wkState.beschaeftigung && !(w.art_der_beschaeftigung || '').startsWith(_wkState.beschaeftigung)) return false;
        if (_wkState.bereich) {
            const tags = (typeof bereichTagsOf === 'function') ? bereichTagsOf(w) : [w.bereich].filter(Boolean);
            if (!tags.some(t => t.toLowerCase() === _wkState.bereich.toLowerCase())) return false;
        }
        if (q) {
            const hay = [w.vorname, w.name, w.dienst_nr, w.email, w.telefon, w.bereich].filter(Boolean).join(' ').toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        hmsPagerClear('wkPager');
        grid.innerHTML = `<div class="wk-empty" style="grid-column:1/-1">${wkIcon('badge')}<p>${_wkState.workers.length === 0 ? 'Noch keine Mitarbeiter vorhanden' : 'Keine Treffer mit diesen Filtern'}</p></div>`;
        return;
    }
    const _p = hmsPagerDef('wkPager', 'Mitarbeiter', () => wkApplyFilters());
    const pg = hmsPage(filtered, _p.st);
    hmsPagerFill('wkPager', pg);
    const cards = pg.rows.map(w => wkCardHtml(w)).join('');
    if (typeof hmsMorph === 'function') hmsMorph(grid, cards); else grid.innerHTML = cards;
}

// ============================================================
// CARD
// ============================================================
function wkCardHtml(w) {
    const color = wkAvatarColor(w.vorname + w.name);
    const initials = wkInitials(w.vorname, w.name);
    const beschaeftigung = w.art_der_beschaeftigung || 'Mitarbeiter';
    const bereichTags = (typeof bereichTagsOf === 'function') ? bereichTagsOf(w)
        : (w.bereich ? w.bereich.split(',').map(b => b.trim()).filter(Boolean) : []);
    const isActive = w.aktiv == 1;

    // Mini badges
    const badges = [];
    if (wkBirthdayThisMonth(w.geburtstag)) {
        badges.push(`<span class="wk-card-badge birthday">${wkIcon('cake')} Geburtstag</span>`);
    }
    // (DFB-Schulung badge removed 2026-05-19 — column retained in DB
    // for historical data, but the field is no longer surfaced in the UI.)
    if (w.app_zugang == 1) {
        // Green only once the worker has actually logged in to the app;
        // red = access enabled but never used (Stammdaten check hint).
        if (w.app_last_login) {
            const last = formatDate(String(w.app_last_login).substring(0, 10));
            badges.push(`<span class="wk-card-badge ok" title="Zuletzt in der App angemeldet: ${escapeHtml(last)}">${wkIcon('smartphone')} App</span>`);
        } else {
            badges.push(`<span class="wk-card-badge danger" title="App-Zugang aktiv, aber noch kein App-Login">${wkIcon('phonelink_erase')} Noch kein App-Login</span>`);
        }
    }
    if (w.stadion_gesperrt == 1) {
        badges.unshift(`<span class="wk-card-badge danger" title="Für Stadion-/DFB-Einsätze gesperrt">${wkIcon('block')} Gesperrt</span>`);
    }
    if (w.eintritt) {
        const days = Math.floor((Date.now() - new Date(w.eintritt).getTime()) / (1000*60*60*24));
        if (days >= 0 && days <= 30) {
            badges.push(`<span class="wk-card-badge ok">${wkIcon('star')} Neu (${days} T.)</span>`);
        }
    }

    // Profile completeness — flag active workers whose HR-critical fields
    // (email, stundenlohn, steuer_id, sv_nummer, krankenkasse, IBAN) or
    // operational fields (bereich, beschaeftigung, eintritt, urlaubstage,
    // arbeitsstunden, telefon) are still empty. Tooltip lists the gaps so
    // the manager can click → Bearbeiten and fill them in.
    const cmpl = wkProfileCompleteness(w);
    if (cmpl.severity) {
        const labelList = cmpl.missing.map(m => (m.critical ? '★ ' : '') + m.label).join(' · ');
        const tooltip = `Profil zu ${cmpl.pct}% vollständig — fehlt: ${labelList}`;
        const cls = cmpl.severity === 'critical' ? 'danger' : 'warn';
        const text = cmpl.severity === 'critical'
            ? `Profil unvollständig (${cmpl.missing.length})`
            : `Profil ${cmpl.pct}%`;
        badges.push(`<span class="wk-card-badge ${cls}" title="${escapeHtml(tooltip)}">${wkIcon('error')} ${text}</span>`);
    }

    // Pending Wunschfrei requests for this worker → small pulsing red
    // pill on the card so the manager spots which workers need attention
    // without opening every profile.
    const pendingCount = (_wkState.pendingByWorker || {})[w.dienst_nr] || 0;
    const pendingBadgeHtml = pendingCount > 0
        ? `<span class="wk-card-pending-badge" title="${pendingCount} offene${pendingCount === 1 ? 'r' : ''} Antrag${pendingCount === 1 ? '' : 'e'}">${wkIcon('hourglass_top')} ${pendingCount}</span>`
        : '';

    return `
        <div data-mk="${escapeHtml(w.dienst_nr)}" class="wk-card ${isActive ? '' : 'inactive'}" onclick="wkOpenProfile('${w.dienst_nr}')">
            ${pendingBadgeHtml}
            <md-icon-button class="wk-card-menu" onclick="event.stopPropagation();wkToggleDropdown(this)" title="Menü"><md-icon>more_horiz</md-icon></md-icon-button>
            <div class="wk-card-dropdown">
                <md-text-button onclick="event.stopPropagation();openWorkerModal('${w.dienst_nr}')"><md-icon slot="icon">edit</md-icon>Bearbeiten</md-text-button>
                <md-text-button onclick="event.stopPropagation();openWorkerDocs('${w.dienst_nr}')"><md-icon slot="icon">folder_open</md-icon>Dokumente</md-text-button>
                <md-text-button onclick="event.stopPropagation();openWorkerAbsences('${w.dienst_nr}')"><md-icon slot="icon">event_busy</md-icon>Abwesenheiten</md-text-button>
                <md-text-button onclick="event.stopPropagation();wkResetPasswordModal('${w.dienst_nr}')"><md-icon slot="icon">key</md-icon>Passwort ändern</md-text-button>
                ${isActive
                    ? `<md-text-button class="md-btn-danger text-danger" onclick="event.stopPropagation();deactivateWorker('${w.dienst_nr}')"><md-icon slot="icon">block</md-icon>Deaktivieren</md-text-button>`
                    : `<md-text-button onclick="event.stopPropagation();reactivateWorker('${w.dienst_nr}')"><md-icon slot="icon">undo</md-icon>Reaktivieren</md-text-button>`}
                <md-text-button class="md-btn-danger text-danger" onclick="event.stopPropagation();deleteWorker('${w.dienst_nr}')" style="border-top: 1px solid var(--md-sys-color-error-container)"><md-icon slot="icon">delete</md-icon>Endgültig löschen</md-text-button>
            </div>
            <div class="wk-avatar" style="background:${color}">
                ${w.foto ? `<img src="${w.foto}" alt="">` : escapeHtml(initials)}
                <span class="wk-avatar-status ${isActive ? 'active' : 'inactive'}"></span>
            </div>
            <div class="wk-card-name">${escapeHtml(w.vorname)} ${escapeHtml(w.name)}</div>
            <div class="wk-card-role">${escapeHtml(beschaeftigung)}</div>
            <div class="wk-card-tags">
                ${bereichTags.map(t => `<span class="wk-tag bc-chip" style="${bereichColorVars(t)}">${escapeHtml(t)}</span>`).join('')}
            </div>
            ${w.email ? `<a class="wk-card-email" href="mailto:${escapeHtml(w.email)}" onclick="event.stopPropagation()">${escapeHtml(w.email)}</a>` : ''}
            ${w.telefon ? `<div class="wk-card-phone">${wkIcon('call', 'font-size:12px')}${escapeHtml(w.telefon)}</div>` : ''}
            ${badges.length ? `<div class="wk-card-badges">${badges.join('')}</div>` : ''}
        </div>
    `;
}

function wkToggleDropdown(btn) {
    const dd = btn.nextElementSibling;
    document.querySelectorAll('.wk-card-dropdown.open').forEach(d => { if (d !== dd) d.classList.remove('open'); });
    dd.classList.toggle('open');
}
document.addEventListener('click', () => {
    document.querySelectorAll('.wk-card-dropdown.open').forEach(d => d.classList.remove('open'));
});

// ============================================================
// EXPIRING DOCS LIST (modal)
// ============================================================
async function wkOpenExpiringDocs() {
    openWorkersDialog('Ablaufende Dokumente',
        `<div class="ph-loading" style="padding:30px;text-align:center;color:var(--wk-text-muted)">${wkSpinner()} Laden...</div>`,
        '<md-text-button onclick="closeWorkersDialog()">Schließen</md-text-button>', true);

    let rows = [], renewals = [];
    try {
        [rows, renewals] = await Promise.all([
            apiGet(WK_API.expiringDocs + '?within_days=60'),
            apiGet(WK_API.renewals + '?status=angefordert,hochgeladen,abgelehnt').catch(() => []),
        ]);
        rows = rows || []; renewals = renewals || [];
    } catch (e) {
        document.getElementById('workersDialogBody').innerHTML = `<div class="wk-empty">${wkIcon('warning')}<p>Fehler beim Laden</p></div>`;
        return;
    }

    if (!rows.length && !renewals.length) {
        document.getElementById('workersDialogBody').innerHTML = `<div class="wk-empty">${wkIcon('shield')}<p>Keine ablaufenden Dokumente</p></div>`;
        return;
    }

    // Map open renewal requests by document id / dienst_nr+titel so each
    // expiring row can show its request state instead of the button.
    const renewalByDoc = {};
    renewals.forEach(a => {
        if (a.dokument_id) renewalByDoc['d' + a.dokument_id] = a;
        renewalByDoc['t' + a.dienst_nr + '|' + a.titel] = a;
    });

    const statusChip = (a, withResend = false) => {
        const requestedAt = a.created_at ? 'Angefordert am ' + formatDate(a.created_at) : 'Angefordert';
        const anfrageVon  = a.angefordert_von ? 'Anforderung von: ' + escapeHtml(a.angefordert_von) : '';
        const resendCount = parseInt(a.resend_count || 0);
        const resendInfo  = resendCount > 0
            ? `\nErneut gesendet: ${resendCount}x` +
              (a.last_resend_at ? ` (zuletzt ${formatDate(a.last_resend_at)}` + (a.last_resend_von ? ` von ${escapeHtml(a.last_resend_von)}` : '') + ')' : '')
            : '';
        const logTitle = 'Protokoll ansehen — wer und wann wurde benachrichtigt';
        if (a.status === 'hochgeladen') return `<span style="background: var(--md-sys-color-primary-container);color: var(--md-sys-color-primary);border-radius:8px;padding:2px 8px;font-size:0.72rem;font-weight:600;white-space:nowrap" title="Vom Mitarbeiter hochgeladen, wartet auf Freigabe">${wkIcon('upload_file', 'font-size:13px;vertical-align:-2px')} Zur Prüfung</span>`;
        if (a.status === 'abgelehnt')   return `<span style="background: var(--md-sys-color-error-container);color: var(--md-sys-color-error);border-radius:8px;padding:2px 8px;font-size:0.72rem;font-weight:600;white-space:nowrap" title="Das hochgeladene Dokument wurde abgelehnt — Mitarbeiter muss erneut hochladen\nZuletzt erneut gesendet: ${resendCount}x">${wkIcon('replay', 'font-size:13px;vertical-align:-2px')} Abgelehnt</span>`;
        return `
            <span style="display:inline-flex;align-items:center;gap:4px;background: var(--md-ext-color-warning-container);color: var(--md-ext-color-warning);border-radius:8px;padding:2px 8px;font-size:0.72rem;font-weight:600;white-space:nowrap" title="Mitarbeiter wurde per E-Mail, App-Push und In-App-Benachrichtigung informiert\n${anfrageVon}\n${requestedAt}${resendInfo}">${wkIcon('send', 'font-size:13px;vertical-align:-2px')} Angefordert${resendCount > 0 ? ' · ' + resendCount + 'x erneut' : ''}</span>
            ${withResend ? `<md-icon-button style="--md-icon-button-icon-size:18px;--md-icon-button-icon-color:#b45309" onclick="event.stopPropagation();wkResendDocRenewal(${a.id})" title="Erinnerung erneut senden"><md-icon style="font-size:18px">send</md-icon></md-icon-button>` : ''}
            <md-icon-button style="--md-icon-button-icon-size:18px;--md-icon-button-icon-color:var(--wk-text-muted)" onclick="event.stopPropagation();wkOpenRenewalLog(${a.id})" title="${logTitle}"><md-icon style="font-size:18px">history</md-icon></md-icon-button>`;
    };

    const reviewSection = renewals.filter(a => a.status === 'hochgeladen').map(a => `
        <div class="wk-cl-item" style="border-left: 3px solid var(--md-sys-color-primary)">
            ${wkIcon('picture_as_pdf', 'color:#3b82f6')}
            <div class="wk-cl-body">
                <div class="wk-cl-title">${escapeHtml(a.titel)} — ${escapeHtml(a.vorname || '')} ${escapeHtml(a.name || '')}</div>
                <div class="wk-cl-meta">
                    <span>${wkIcon('tag', 'font-size:12px;vertical-align:-2px')} ${escapeHtml(a.dienst_nr)}</span> ·
                    <span>Hochgeladen: ${formatDate(a.hochgeladen_am)}</span>
                    ${a.ablaufdatum_neu ? ` · <span>Neues Ablaufdatum: ${formatDate(a.ablaufdatum_neu)}</span>` : ''}
                </div>
            </div>
            <div class="wk-cl-actions" style="display:flex;gap:6px">
                <md-icon-button onclick="hmsOpenFile('${hmsFileArg(a.dateipfad)}')" title="PDF ansehen"><md-icon>visibility</md-icon></md-icon-button>
                <md-icon-button style="--md-icon-button-icon-color:#16a34a" onclick="wkApproveDocRenewal(${a.id},'${a.ablaufdatum_neu || ''}')" title="Genehmigen"><md-icon>check</md-icon></md-icon-button>
                <md-icon-button style="--md-icon-button-icon-color:#dc2626" onclick="wkRejectDocRenewal(${a.id})" title="Ablehnen"><md-icon>close</md-icon></md-icon-button>
            </div>
        </div>
    `).join('');

    const html = `
        ${reviewSection ? `
            <div style="font-weight:700;font-size:0.85rem;color:var(--wk-accent);margin-bottom:8px">${wkIcon('fact_check', 'font-size:16px;vertical-align:-3px')} Zur Prüfung eingereicht</div>
            <div class="wk-cl-list" style="margin-bottom:18px">${reviewSection}</div>
            ${rows.length ? `<div style="font-weight:700;font-size:0.85rem;color:var(--wk-warn);margin-bottom:8px">${wkIcon('hourglass_top', 'font-size:16px;vertical-align:-3px')} Ablaufend / Abgelaufen</div>` : ''}
        ` : ''}
        <div class="wk-cl-list">
            ${rows.map(r => {
                const cls = r.status === 'expired' ? 'expired' : (r.status === 'expiring-30d' ? 'expiring' : 'expiring');
                const statusLbl = r.status === 'expired' ? 'Abgelaufen' : (r.days_remaining <= 30 ? `Läuft in ${r.days_remaining} T.` : `Läuft in ${r.days_remaining} T.`);
                const icon = r.status === 'expired' ? 'error' : 'hourglass_top';
                const req = renewalByDoc['d' + r.id] || renewalByDoc['t' + r.dienst_nr + '|' + r.titel];
                return `
                    <div class="wk-cl-item ${cls}" style="cursor:pointer" onclick="wkOpenProfile('${r.dienst_nr}');closeWorkersDialog();">
                        ${wkIcon(icon, r.status === 'expired' ? 'color:var(--wk-danger)' : 'color:var(--wk-warn)')}
                        <div class="wk-cl-body">
                            <div class="wk-cl-title">${escapeHtml(r.titel)} — ${escapeHtml(r.vorname || '')} ${escapeHtml(r.name || '')}</div>
                            <div class="wk-cl-meta">
                                <span>${wkIcon('tag', 'font-size:12px;vertical-align:-2px')} ${escapeHtml(r.dienst_nr)}</span> ·
                                <span>Ablauf: ${formatDate(r.ablaufdatum)}</span> ·
                                <span>${statusLbl}</span>
                            </div>
                        </div>
                        <div class="wk-cl-actions" style="display:flex;gap:6px;align-items:center">
                            ${req ? statusChip(req, req.status === 'angefordert') : `<md-outlined-button onclick="event.stopPropagation();wkRequestDocRenewal('${r.dienst_nr}',${r.id},'${escapeHtml(r.titel).replace(/'/g, '&#39;')}')" title="Mitarbeiter per E-Mail + App + Push auffordern, das Dokument zu erneuern"><md-icon slot="icon">send</md-icon>Erneuerung anfordern</md-outlined-button>`}
                            <md-icon-button onclick="event.stopPropagation();hmsOpenFile('${hmsFileArg(r.dateipfad)}')" title="PDF ansehen"><md-icon>visibility</md-icon></md-icon-button>
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
    document.getElementById('workersDialogBody').innerHTML = html;
}

// ============================================================
// Birthdays dialog — opened from the „Geburtstage“ KPI card.
// Lists all active workers whose birthday falls in the current
// month, sorted by day, in the same wk-cl-list design as the
// expiring-documents dialog.
// ============================================================
function wkOpenBirthdays() {
    const now = new Date();
    const today = now.getDate();
    const monthName = now.toLocaleDateString('de-DE', { month: 'long' });

    const rows = (_wkState.workers || [])
        .filter(w => w.aktiv == 1 && wkBirthdayThisMonth(w.geburtstag))
        .map(w => {
            const d = new Date(w.geburtstag);
            const age = now.getFullYear() - d.getFullYear();
            return { ...w, _day: d.getDate(), _age: age };
        })
        .sort((a, b) => a._day - b._day);

    if (!rows.length) {
        openWorkersDialog('Geburtstage — ' + monthName,
            `<div class="wk-empty">${wkIcon('cake')}<p>Keine Geburtstage in diesem Monat</p></div>`,
            '<md-text-button onclick="closeWorkersDialog()">Schließen</md-text-button>', true);
        return;
    }

    const chip = (r) => {
        if (r._day === today) {
            return `<span style="background: var(--md-sys-color-error-container);color: var(--md-sys-color-error);border-radius:8px;padding:2px 8px;font-size:0.72rem;font-weight:700;white-space:nowrap">${wkIcon('celebration', 'font-size:13px;vertical-align:-2px')} Heute!</span>`;
        }
        if (r._day > today) {
            const diff = r._day - today;
            return `<span style="background: var(--md-sys-color-error-container);color: var(--md-sys-color-error);border-radius:8px;padding:2px 8px;font-size:0.72rem;font-weight:600;white-space:nowrap">${wkIcon('schedule', 'font-size:13px;vertical-align:-2px')} in ${diff} ${diff === 1 ? 'Tag' : 'Tagen'}</span>`;
        }
        return `<span style="background:var(--wk-bg-soft);color:var(--wk-text-muted);border-radius:8px;padding:2px 8px;font-size:0.72rem;font-weight:600;white-space:nowrap">${wkIcon('check', 'font-size:13px;vertical-align:-2px')} war am ${r._day}.</span>`;
    };

    const html = `
        <div class="wk-cl-list">
            ${rows.map(r => `
                <div class="wk-cl-item" style="cursor:pointer;border-left: 3px solid var(--md-sys-color-error-container)" onclick="wkOpenProfile('${r.dienst_nr}');closeWorkersDialog();">
                    <div style="width:36px;height:36px;border-radius:50%;flex:0 0 36px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;color:white;background:${wkAvatarColor(r.dienst_nr)};overflow:hidden">
                        ${r.foto ? `<img src="${r.foto}" alt="" style="width:100%;height:100%;object-fit:cover">` : wkInitials(r.vorname, r.name)}
                    </div>
                    <div class="wk-cl-body">
                        <div class="wk-cl-title">${escapeHtml(r.vorname || '')} ${escapeHtml(r.name || '')}</div>
                        <div class="wk-cl-meta">
                            <span>${wkIcon('tag', 'font-size:12px;vertical-align:-2px')} ${escapeHtml(r.dienst_nr)}</span> ·
                            <span>${wkIcon('cake', 'font-size:12px;vertical-align:-2px')} ${formatDate(r.geburtstag)}</span> ·
                            <span>wird <strong>${r._age}</strong></span>
                            ${r.bereich ? ` · <span>${escapeHtml(r.bereich)}</span>` : ''}
                        </div>
                    </div>
                    <div class="wk-cl-actions" style="display:flex;align-items:center">${chip(r)}</div>
                </div>
            `).join('')}
        </div>
    `;
    openWorkersDialog(`Geburtstage — ${monthName} (${rows.length})`, html,
        '<md-text-button onclick="closeWorkersDialog()">Schließen</md-text-button>', true);
}

// ============================================================
// KPI DRILL-DOWNS — Total / Beschäftigung / Ø Urlaub details.
// Same wk-cl-list design as birthdays / expiring docs.
// ============================================================
function wkPersonRow(w, metaHtml, accent) {
    return `
        <div class="wk-cl-item" style="cursor:pointer;border-left:3px solid ${accent}" onclick="wkOpenProfile('${w.dienst_nr}');closeWorkersDialog();">
            <div style="width:36px;height:36px;border-radius:50%;flex:0 0 36px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;color:white;background:${wkAvatarColor(w.dienst_nr)};overflow:hidden">
                ${w.foto ? `<img src="${w.foto}" alt="" style="width:100%;height:100%;object-fit:cover">` : wkInitials(w.vorname, w.name)}
            </div>
            <div class="wk-cl-body">
                <div class="wk-cl-title">${escapeHtml(w.vorname || '')} ${escapeHtml(w.name || '')}</div>
                <div class="wk-cl-meta">${metaHtml}</div>
            </div>
        </div>`;
}

function wkSectionHead(icon, text) {
    return `<div style="display:flex;align-items:center;gap:8px;margin:14px 0 6px;font-weight:600;color:var(--wk-text-muted);font-size:0.8rem;text-transform:uppercase;letter-spacing:.4px">${wkIcon(icon, 'font-size:16px')} ${escapeHtml(text)}</div>`;
}

// ---- Total → breakdown: aktiv/inaktiv/neu + Bereich + clickable lists ----
function wkOpenTotalDetail() {
    const ws = _wkState.workers || [];
    const s = _wkState.stats || {};
    const cutoff = Date.now() - 30 * 86400000;
    const aktiv   = ws.filter(w => w.aktiv == 1);
    const inaktiv = ws.filter(w => w.aktiv != 1);
    const neu     = ws.filter(w => w.eintritt && new Date(w.eintritt).getTime() >= cutoff)
                      .sort((a, b) => new Date(b.eintritt) - new Date(a.eintritt));

    const byBereich = {};
    aktiv.forEach(w => {
        const tags = (typeof bereichTagsOf === 'function') ? bereichTagsOf(w) : [w.bereich].filter(Boolean);
        if (!tags.length) tags.push('Ohne Bereich');
        tags.forEach(t => { byBereich[t] = (byBereich[t] || 0) + 1; });
    });
    const bereichChips = Object.entries(byBereich).sort((a, b) => b[1] - a[1])
        .map(([b, n]) => b === 'Ohne Bereich'
            ? `<span style="background:var(--wk-bg-soft);border: 1px solid var(--wk-border,var(--md-sys-color-primary-container));border-radius:12px;padding:3px 10px;font-size:0.78rem;font-weight:600;color:var(--wk-text-muted)">${escapeHtml(b)} <strong style="color:var(--wk-text)">${n}</strong></span>`
            : `<span class="bc-chip bc-pill" style="${bereichColorVars(b)}">${escapeHtml(b)} <strong>${n}</strong></span>`).join(' ');

    const html = `
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:4px">
            <span style="background: var(--md-ext-color-success-container);color: var(--md-ext-color-on-success-container);border-radius:12px;padding:4px 12px;font-size:0.8rem;font-weight:700">${aktiv.length} aktiv</span>
            <span style="background: var(--md-sys-color-error-container);color: var(--md-sys-color-error);border-radius:12px;padding:4px 12px;font-size:0.8rem;font-weight:700">${inaktiv.length} inaktiv</span>
            <span style="background: var(--md-sys-color-primary-container);color: var(--md-sys-color-primary);border-radius:12px;padding:4px 12px;font-size:0.8rem;font-weight:700">${neu.length} neu (30 T.)</span>
        </div>
        ${bereichChips ? `${wkSectionHead('domain', 'Aktiv nach Bereich')}<div style="display:flex;gap:6px;flex-wrap:wrap">${bereichChips}</div>` : ''}
        ${wkSectionHead('person_add', 'Neu eingetreten — letzte 30 Tage')}
        ${neu.length ? `<div class="wk-cl-list">${neu.map(w => wkPersonRow(w,
            `<span>${wkIcon('tag', 'font-size:12px;vertical-align:-2px')} ${escapeHtml(w.dienst_nr)}</span> ·
             <span>${wkIcon('event', 'font-size:12px;vertical-align:-2px')} Eintritt ${formatDate(w.eintritt)}</span>
             ${w.bereich ? ` · <span>${escapeHtml(w.bereich)}</span>` : ''}`,
            '#3b82f6')).join('')}</div>` : `<div class="wk-empty" style="padding:12px">${wkIcon('person_add')}<p>Keine Neueintritte in den letzten 30 Tagen</p></div>`}
        ${wkSectionHead('person_off', 'Inaktiv')}
        ${inaktiv.length ? `<div class="wk-cl-list">${inaktiv.map(w => wkPersonRow(w,
            `<span>${wkIcon('tag', 'font-size:12px;vertical-align:-2px')} ${escapeHtml(w.dienst_nr)}</span>
             ${w.austritt ? ` · <span>${wkIcon('event_busy', 'font-size:12px;vertical-align:-2px')} Austritt ${formatDate(w.austritt)}</span>` : ''}
             ${w.bereich ? ` · <span>${escapeHtml(w.bereich)}</span>` : ''}`,
            '#ef4444')).join('')}</div>` : `<div class="wk-empty" style="padding:12px">${wkIcon('check_circle')}<p>Alle Mitarbeiter sind aktiv</p></div>`}
    `;
    openWorkersDialog(`Mitarbeiter — Gesamt ${ws.length}`, html,
        '<md-text-button onclick="closeWorkersDialog()">Schließen</md-text-button>', true);
}

// ---- Beschäftigung → full distribution; click a type to filter the grid ----
function wkOpenBeschaeftigungDetail() {
    const s = _wkState.stats || {};
    const dist = s.beschaeftigung || [];
    const total = dist.reduce((a, d) => a + (d.anzahl || 0), 0) || 1;

    if (!dist.length) {
        openWorkersDialog('Beschäftigung — Verteilung',
            `<div class="wk-empty">${wkIcon('work')}<p>Keine Daten</p></div>`,
            '<md-text-button onclick="closeWorkersDialog()">Schließen</md-text-button>', true);
        return;
    }

    const rows = dist.map(d => {
        const typ = d.typ || 'Ohne Angabe';
        const pct = Math.round((d.anzahl || 0) / total * 100);
        return `
            <div class="wk-cl-item" style="cursor:pointer" onclick="wkFilterByBeschaeftigung('${String(typ).replace(/'/g, "\\'")}')">
                <div class="wk-cl-body" style="flex:1">
                    <div class="wk-cl-title" style="display:flex;justify-content:space-between;align-items:center">
                        <span>${escapeHtml(typ)}</span>
                        <span style="font-weight:700;color: var(--wk-accent,var(--md-ext-color-on-info-container))">${d.anzahl}</span>
                    </div>
                    <div style="height:6px;background:var(--wk-bg-soft);border-radius:3px;margin-top:6px;overflow:hidden">
                        <div style="height:100%;width:${pct}%;background: var(--wk-accent,var(--md-ext-color-info));border-radius:3px"></div>
                    </div>
                    <div class="wk-cl-meta" style="margin-top:4px">${pct} % der aktiven Mitarbeiter</div>
                </div>
                <div class="wk-cl-actions">${wkIcon('filter_alt', 'color:var(--wk-text-muted)')}</div>
            </div>`;
    }).join('');

    openWorkersDialog(`Beschäftigung — ${total} aktiv`, `<div class="wk-cl-list">${rows}</div>
        <div style="margin-top:8px;font-size:0.75rem;color:var(--wk-text-muted)">${wkIcon('info', 'font-size:14px;vertical-align:-2px')} Klick auf einen Typ filtert die Mitarbeiterliste.</div>`,
        '<md-text-button onclick="closeWorkersDialog()">Schließen</md-text-button>', true);
}

function wkFilterByBeschaeftigung(typ) {
    closeWorkersDialog();
    _wkState.beschaeftigung = typ === 'Ohne Angabe' ? '' : typ;
    const sel = document.getElementById('wkFilterBeschaeftigung');
    if (sel) sel.value = _wkState.beschaeftigung;
    wkApplyFilters();
}

// ---- Ø Urlaub → per-worker usage for the current year ----
async function wkOpenUrlaubDetail() {
    const year = new Date().getFullYear();
    openWorkersDialog(`Urlaubs-Auslastung ${year}`,
        `<div class="ph-loading" style="padding:30px;text-align:center;color:var(--wk-text-muted)">${wkSpinner()} Laden...</div>`,
        '<md-text-button onclick="closeWorkersDialog()">Schließen</md-text-button>', true);

    const data = await apiGet(window.location.origin + `/api/worker-absences/calendar?von=${year}-01-01&bis=${year}-12-31`).catch(() => null);
    const eintraege = (data && data.eintraege) || [];

    // Sum Urlaub days per worker — same rule as the KPI (pending counts too,
    // only 'abgelehnt' rows are already excluded by the calendar endpoint).
    const usedBy = {};
    eintraege.forEach(e => {
        if (e.typ === 'Urlaub') usedBy[e.dienst_nr] = (usedBy[e.dienst_nr] || 0) + (parseFloat(e.tage) || 0);
    });

    const rows = (_wkState.workers || [])
        .filter(w => w.aktiv == 1)
        .map(w => {
            const used = usedBy[w.dienst_nr] || 0;
            const ent  = parseFloat(w.urlaubstage) || 0;
            const pct  = ent > 0 ? Math.round(used / ent * 100) : null;
            return { w, used, ent, pct };
        })
        .sort((a, b) => (b.pct ?? -1) - (a.pct ?? -1));

    const withEnt = rows.filter(r => r.ent > 0);
    const avg = withEnt.length ? Math.round(withEnt.reduce((a, r) => a + r.pct, 0) / withEnt.length) : null;

    const bar = (pct) => {
        const col = pct === null ? '#9ca3af' : pct > 100 ? '#dc2626' : pct > 80 ? '#f59e0b' : '#10b981';
        const w = pct === null ? 0 : Math.min(pct, 100);
        return `<div style="height:6px;background:var(--wk-bg-soft);border-radius:3px;margin-top:6px;overflow:hidden">
            <div style="height:100%;width:${w}%;background:${col};border-radius:3px"></div></div>`;
    };

    const html = `
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:4px">
            <span style="background: var(--md-sys-color-primary-container);color: var(--md-sys-color-primary);border-radius:12px;padding:4px 12px;font-size:0.8rem;font-weight:700">Ø ${avg !== null ? avg + ' %' : '–'} Auslastung</span>
            <span style="background:var(--wk-bg-soft);color:var(--wk-text-muted);border-radius:12px;padding:4px 12px;font-size:0.8rem;font-weight:600">${withEnt.length} mit Urlaubsanspruch</span>
        </div>
        ${rows.length ? `<div class="wk-cl-list">${rows.map(r => `
            <div class="wk-cl-item" style="cursor:pointer" onclick="wkOpenProfile('${r.w.dienst_nr}');closeWorkersDialog();">
                <div class="wk-cl-body" style="flex:1">
                    <div class="wk-cl-title" style="display:flex;justify-content:space-between;align-items:center">
                        <span>${escapeHtml(r.w.vorname || '')} ${escapeHtml(r.w.name || '')}</span>
                        <span style="font-weight:700;color:${r.pct !== null && r.pct > 100 ? '#dc2626' : 'var(--wk-text)'}">${r.used} / ${r.ent} T.</span>
                    </div>
                    ${bar(r.pct)}
                    <div class="wk-cl-meta" style="margin-top:4px">
                        <span>${wkIcon('tag', 'font-size:12px;vertical-align:-2px')} ${escapeHtml(r.w.dienst_nr)}</span>
                        ${r.pct !== null ? ` · <span><strong>${r.pct} %</strong> genutzt</span>` : ' · <span>kein Urlaubsanspruch</span>'}
                        ${r.w.bereich ? ` · <span>${escapeHtml(r.w.bereich)}</span>` : ''}
                    </div>
                </div>
            </div>`).join('')}</div>` : `<div class="wk-empty">${wkIcon('beach_access')}<p>Keine Mitarbeiter</p></div>`}
    `;
    document.getElementById('workersDialogBody').innerHTML = html;
}

// ============================================================
// DOC RENEWALS — request / approve / reject
// ============================================================
async function wkRequestDocRenewal(dienstNr, docId, titel) {
    const result = await apiPost(WK_API.renewals + '/request', {
        dienst_nr: dienstNr,
        dokument_id: docId,
        titel: titel,
    });
    if (result && result.success) {
        showToast('Erneuerung angefordert — Mitarbeiter wurde benachrichtigt');
        wkOpenExpiringDocs();
    } else {
        showToast((result && result.error) || 'Fehler beim Anfordern', 'error');
    }
}

async function wkResendDocRenewal(id) {
    const result = await apiPost(WK_API.renewalsResend(id));
    if (result && result.success) {
        showToast('Erinnerung erneut gesendet — E-Mail + App + Push');
        wkOpenExpiringDocs();
    } else {
        showToast((result && result.error) || 'Fehler beim Erneuten Senden', 'error');
    }
}

async function wkOpenRenewalLog(id) {
    openWorkersDialog('Benachrichtigungs-Protokoll',
        `<div class="ph-loading" style="padding:30px;text-align:center;color:var(--wk-text-muted)">${wkSpinner()} Laden...</div>`,
        '<md-text-button onclick="closeWorkersDialog()">Schließen</md-text-button>', true);

    const data = await apiGet(WK_API.renewalsLog(id)).catch(() => null);
    const rows = (data && data.log) || [];

    if (!rows.length) {
        document.getElementById('workersDialogBody').innerHTML = `<div class="wk-empty">${wkIcon('history')}<p>Kein Protokoll vorhanden</p></div>`;
        return;
    }

    const html = `
        <div style="font-weight:600;margin-bottom:10px;color:var(--wk-text-muted);font-size:0.85rem">${escapeHtml(data.titel)} · ${escapeHtml(data.dienst_nr)}</div>
        <div class="wk-cl-list">
            ${rows.map(l => {
                const isResend = l.aktion === 'resend';
                const icon = isResend ? 'send' : 'send';
                const color = isResend ? '#f59e0b' : '#3b82f6';
                const label = isResend ? 'Erinnerung gesendet' : 'Erstmalig angefordert';
                return `
                    <div class="wk-cl-item" style="border-left:3px solid ${color};cursor:default">
                        ${wkIcon(icon, 'color:' + color)}
                        <div class="wk-cl-body">
                            <div class="wk-cl-title">${label}</div>
                            <div class="wk-cl-meta">
                                <span>${wkIcon('person', 'font-size:12px;vertical-align:-2px')} Gesendet von: <strong>${escapeHtml(l.sender || '—')}</strong></span> ·
                                <span>${wkIcon('schedule', 'font-size:12px;vertical-align:-2px')} ${new Date(l.created_at).toLocaleString('de-DE')}</span>
                            </div>
                        </div>
                    </div>`;
            }).join('')}
        </div>
    `;
    document.getElementById('workersDialogBody').innerHTML = html;
}

function wkApproveDocRenewal(id, ablaufNeu) {
    const body = `
        <div style="padding:4px 2px">
            <p style="margin:0 0 12px">Das hochgeladene Dokument wird das bisherige ersetzen.</p>
            <md-outlined-text-field type="date" id="wkRenewalAblauf" label="Neues Ablaufdatum" value="${ablaufNeu || ''}" style="width:100%"></md-outlined-text-field>
            <div style="font-size:0.75rem;color:var(--wk-text-muted);margin-top:4px">Leer lassen, wenn das Dokument kein Ablaufdatum hat.</div>
        </div>`;
    const footer = `
        <md-text-button onclick="closeWorkersDialog()">Abbrechen</md-text-button>
        <md-filled-button id="wkRenewalApproveBtn" style="--md-filled-button-container-color:#16a34a"><md-icon slot="icon">check</md-icon>Genehmigen</md-filled-button>`;
    openWorkersDialog('Dokument genehmigen', body, footer);
    document.getElementById('wkRenewalApproveBtn').onclick = async () => {
        const ablaufdatum = document.getElementById('wkRenewalAblauf').value || null;
        closeWorkersDialog();
        const r = await apiPost(WK_API.renewals + '/' + id + '/approve', { ablaufdatum });
        if (r && r.success) {
            showToast('Dokument genehmigt und übernommen');
            wkOpenExpiringDocs();
            if (typeof wkLoadStats === 'function') { try { wkLoadStats(); } catch (e) {} }
        } else {
            showToast((r && r.error) || 'Fehler bei der Genehmigung', 'error');
        }
    };
}

function wkRejectDocRenewal(id) {
    const body = `
        <div style="padding:4px 2px">
            <md-outlined-text-field type="textarea" rows="3" id="wkRenewalGrund" label="Grund der Ablehnung *" style="width:100%" placeholder="z. B. Dokument unleserlich, falsches Dokument …"></md-outlined-text-field>
            <div style="font-size:0.75rem;color:var(--wk-text-muted);margin-top:4px">Der Mitarbeiter wird benachrichtigt und kann erneut hochladen.</div>
        </div>`;
    const footer = `
        <md-text-button onclick="closeWorkersDialog()">Abbrechen</md-text-button>
        <md-filled-button id="wkRenewalRejectBtn" style="--md-filled-button-container-color:#dc2626"><md-icon slot="icon">close</md-icon>Ablehnen</md-filled-button>`;
    openWorkersDialog('Dokument ablehnen', body, footer);
    document.getElementById('wkRenewalRejectBtn').onclick = async () => {
        const grund = document.getElementById('wkRenewalGrund').value.trim();
        if (!grund) { showToast('Grund erforderlich', 'error'); return; }
        closeWorkersDialog();
        const r = await apiPost(WK_API.renewals + '/' + id + '/reject', { grund });
        if (r && r.success) {
            showToast('Dokument abgelehnt — Mitarbeiter wurde benachrichtigt');
            wkOpenExpiringDocs();
        } else {
            showToast((r && r.error) || 'Fehler beim Ablehnen', 'error');
        }
    };
}

// ============================================================
// PROFILE VIEW
// ============================================================
async function wkOpenProfile(dienstNr) {
    const content = document.getElementById('content');
    // Same profile already open (after a save): keep it on screen, patch in
    // place and stay on the current tab — no spinner, no jump to the top.
    const soft = document.querySelector('#content > .wk-profile')?.dataset.mk === String(dienstNr) && typeof hmsMorph === 'function';
    const tabNow = soft ? (document.querySelector('.wk-tab.active')?.dataset.tab || 'stammdaten') : 'stammdaten';
    if (!soft) content.innerHTML = `<div style="text-align:center;padding:60px;color:var(--wk-text-muted)">${wkSpinner(44)}</div>`;

    const [w, cat] = await Promise.all([
        apiGet(WK_API.show(dienstNr)),
        // Bereich switches decide the security sections (cached per page).
        _wkQualCatalogCache ? Promise.resolve(_wkQualCatalogCache) : wkLoadQualCatalog(),
    ]);
    if (!w || w.error) { showToast('Mitarbeiter nicht gefunden', 'error'); renderWorkers(); return; }
    _wkQualCatalogCache = cat;

    const color = wkAvatarColor(w.vorname + w.name);
    const initials = wkInitials(w.vorname, w.name);

    const html = `
        <div class="wk-profile" data-mk="${escapeHtml(dienstNr)}">
            <div class="wk-profile-sidebar">
                <div class="wk-profile-avatar" style="background:${color}" onclick="document.getElementById('wkPhotoInput').click()" title="Foto ändern">
                    ${w.foto ? `<img src="${w.foto}" alt="" id="wkProfileImg">` : escapeHtml(initials)}
                </div>
                <input type="file" id="wkPhotoInput" accept=".jpg,.jpeg,.png,.webp" style="display:none" onchange="wkUploadPhoto('${w.dienst_nr}')">
                <div class="wk-profile-name">${escapeHtml(w.vorname)} ${escapeHtml(w.name)}</div>
                <div class="wk-profile-role">${escapeHtml(w.art_der_beschaeftigung || 'Mitarbeiter')}</div>
                <div class="wk-profile-dienst">${escapeHtml(w.dienst_nr)}</div>
                ${w.stadion_gesperrt == 1 ? `<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin:10px 0 4px;padding:8px 10px;border-radius:10px;font-size:0.8rem;font-weight:700;color: var(--md-sys-color-error);background: color-mix(in srgb, var(--md-sys-color-error) 12%, transparent);border: 1px solid color-mix(in srgb, var(--md-sys-color-error) 35%, transparent)">${wkIcon('block')} Gesperrt — keine Stadion-/DFB-Einsätze</div>` : ''}
                ${w.eintritt ? `<div class="wk-profile-eintritt">${wkIcon('work', 'font-size:13px')} Eintritt: ${formatDate(w.eintritt)}</div>` : ''}
                <div class="wk-profile-actions">
                    <md-filled-tonal-icon-button class="wk-profile-action-btn" onclick="openWorkerModal('${w.dienst_nr}')" title="Bearbeiten" aria-label="Bearbeiten"><md-icon>edit</md-icon></md-filled-tonal-icon-button>
                    <md-filled-tonal-icon-button class="wk-profile-action-btn" onclick="document.getElementById('wkPhotoInput').click()" title="Foto hochladen" aria-label="Foto hochladen"><md-icon>photo_camera</md-icon></md-filled-tonal-icon-button>
                    <md-filled-tonal-icon-button class="wk-profile-action-btn is-warning" onclick="wkResetPasswordModal('${w.dienst_nr}')" title="Passwort ändern" aria-label="Passwort ändern"><md-icon>key</md-icon></md-filled-tonal-icon-button>
                    <md-filled-tonal-icon-button class="wk-profile-action-btn is-danger" onclick="${w.aktiv == 1 ? `deactivateWorker('${w.dienst_nr}')` : `reactivateWorker('${w.dienst_nr}')`}" title="${w.aktiv == 1 ? 'Deaktivieren' : 'Reaktivieren'}" aria-label="${w.aktiv == 1 ? 'Deaktivieren' : 'Reaktivieren'}"><md-icon>${w.aktiv == 1 ? 'block' : 'undo'}</md-icon></md-filled-tonal-icon-button>
                    <md-filled-tonal-icon-button class="wk-profile-action-btn is-danger" onclick="deleteWorker('${w.dienst_nr}')" title="Endgültig löschen" aria-label="Endgültig löschen"><md-icon>delete</md-icon></md-filled-tonal-icon-button>
                </div>
                <md-outlined-button class="wk-profile-back" onclick="renderWorkers()"><md-icon slot="icon">arrow_back</md-icon>Zurück zur Übersicht</md-outlined-button>
            </div>
            <div class="wk-profile-main">
                <md-tabs class="wk-tabs">
                    <md-secondary-tab data-seg inline-icon class="wk-tab active"  data-tab="stammdaten"   onclick="wkSwitchTab(this)"><md-icon slot="icon">person</md-icon>Stammdaten</md-secondary-tab>
                    <md-secondary-tab data-seg inline-icon class="wk-tab"         data-tab="analyse"      onclick="wkSwitchTab(this)"><md-icon slot="icon">monitoring</md-icon>Analyse</md-secondary-tab>
                    <md-secondary-tab data-seg inline-icon class="wk-tab"         data-tab="dokumente"    onclick="wkSwitchTab(this)"><md-icon slot="icon">folder_open</md-icon>Dokumente <span class="wk-tab-count" id="wkTabCountDocs">${(w.dokumente || []).length}</span></md-secondary-tab>
                    <md-secondary-tab data-seg inline-icon class="wk-tab"         data-tab="compliance"   onclick="wkSwitchTab(this)"><md-icon slot="icon">fact_check</md-icon>Compliance</md-secondary-tab>
                    <md-secondary-tab data-seg inline-icon class="wk-tab"         data-tab="abwesenheiten" onclick="wkSwitchTab(this)"><md-icon slot="icon">event_busy</md-icon>Abwesenheiten</md-secondary-tab>
                    <md-secondary-tab data-seg inline-icon class="wk-tab"         data-tab="urlaub"        onclick="wkSwitchTab(this)"><md-icon slot="icon">beach_access</md-icon>Urlaub</md-secondary-tab>
                </md-tabs>
                <div class="wk-tab-content active" id="tab-stammdaten">${wkStammdatenTabHtml(w)}</div>
                <div class="wk-tab-content" id="tab-analyse"><div class="ph-loading" style="padding:40px;text-align:center">${wkSpinner()}</div></div>
                <div class="wk-tab-content" id="tab-dokumente"><div class="ph-loading" style="padding:40px;text-align:center">${wkSpinner()}</div></div>
                <div class="wk-tab-content" id="tab-compliance"><div class="ph-loading" style="padding:40px;text-align:center">${wkSpinner()}</div></div>
                <div class="wk-tab-content" id="tab-abwesenheiten"><div class="ph-loading" style="padding:40px;text-align:center">${wkSpinner()}</div></div>
                <div class="wk-tab-content" id="tab-urlaub"><div class="ph-loading" style="padding:40px;text-align:center">${wkSpinner()}</div></div>
            </div>
        </div>
    `;
    if (soft) {
        // Lazy tabs keep their content (the loaders below refresh it) instead
        // of falling back to the spinner.
        hmsMorph(content, html, { keep: el => el.classList.contains('wk-tab-content') && el.id !== 'tab-stammdaten' });
        const btn = document.querySelector(`.wk-tab[data-tab="${tabNow}"]`);
        if (btn) wkSwitchTab(btn);
    } else {
        content.innerHTML = html;
    }

    window._wkCurrentWorker = w;
    wkLoadDocs(dienstNr, w);
    wkLoadChecklist(dienstNr);
    wkLoadAbsences(dienstNr);
    wkLoadActivity(dienstNr);
    wkLoadLager(dienstNr);
}

function wkSwitchTab(btn) {
    document.querySelectorAll('.wk-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.wk-tab-content').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
}

// ============================================================
// Stammdaten Tab
// ============================================================
/**
 * One Stammdaten section card. fields = [label, valueHtml, {wide, mono, info}].
 * Empty values render a soft "Nicht hinterlegt"; the header pill counts the
 * filled data fields (info fields such as Status are not counted).
 */
function wkSdSection(icon, title, fields) {
    const isEmpty = (html) => String(html ?? '').replace(/<[^>]*>/g, '').trim() === '';
    const counted = fields.filter(x => !(x[2] && x[2].info));
    const filled = counted.filter(x => !isEmpty(x[1])).length;
    const pill = counted.length
        ? `<span class="wk-sd-meta ${filled === counted.length ? 'is-done' : ''}" title="${filled} von ${counted.length} Feldern ausgefüllt">${filled === counted.length ? wkIcon('check_circle') : ''}${filled}/${counted.length}</span>`
        : '';
    return `
        <section class="wk-sd-sec">
            <header class="wk-sd-head">
                <span class="wk-sd-ic">${wkIcon(icon)}</span>
                <h4 class="wk-sd-title">${escapeHtml(title)}</h4>
                ${pill}
            </header>
            <div class="wk-sd-fields">
                ${fields.map(([label, html, o = {}]) => {
                    const empty = isEmpty(html);
                    return `<div class="wk-sd-field${o.wide ? ' is-wide' : ''}${empty ? ' is-empty' : ''}">
                        <span class="wk-sd-label">${escapeHtml(label)}</span>
                        <span class="wk-sd-value${o.mono && !empty ? ' is-mono' : ''}">${empty ? 'Nicht hinterlegt' : html}</span>
                    </div>`;
                }).join('')}
            </div>
        </section>`;
}

function wkStammdatenTabHtml(w) {
    const f = (v) => v || '';
    const adresse = [w.strasse, [w.plz, w.ort].filter(Boolean).join(' '), w.bundesland, w.land].filter(Boolean).join(', ');
    const geschlechtLabel = { m: 'männlich', w: 'weiblich', d: 'divers', k: 'keine Angabe' }[w.geschlecht] || '';
    // Bewachung / DFB block per Bereich switches — same rule as the edit
    // modal's Sicherheits-Tab (wkSecMode).
    const sec = wkSecMode((typeof bereichTagsOf === 'function') ? bereichTagsOf(w) : [w.bereich], _wkQualCatalogCache || [], w);
    const e = (v) => escapeHtml(f(v));
    const lohnHtml = (w.stundenlohn !== null && w.stundenlohn !== undefined && w.stundenlohn !== '' && Number(w.stundenlohn) > 0)
        ? formatCurrency(w.stundenlohn) + (w.lohn_typ === 'gehalt' ? ' / Monat' : ' / Std') : '';
    const appLoginHtml = w.app_last_login
        ? formatDate(String(w.app_last_login).substring(0, 10)) + (w.app_first_login ? ` <small style="color:var(--gray-500);font-weight:400">(erstmals ${formatDate(String(w.app_first_login).substring(0, 10))})</small>` : '')
        : '<span class="status status-nein">Noch kein App-Login</span>';

    const sections = [
        wkSdSection('person', 'Persönliche Daten', [
            ['Anrede', e(w.anrede)],
            ['Geschlecht', escapeHtml(geschlechtLabel)],
            ['Vorname', e(w.vorname)],
            ['Nachname', e(w.name)],
            ['Geburtsname', e(w.geburtsname)],
            ['Geburtstag', w.geburtstag ? formatDate(w.geburtstag) : ''],
            ['Geburtsort', e(w.geburtsort)],
            ['Geburtsland', e(w.geburtsland)],
            ['Staatsangehörigkeit', e(w.staatsangehoerigkeit)],
            ['Familienstand', e(w.familienstand)],
            ['Kinder / Freibetrag', f(w.kinderzahl_freibetrag)],
        ]),
        wkSdSection('call', 'Kontakt', [
            ['E-Mail', w.email ? `<a href="mailto:${escapeHtml(w.email)}" style="color:var(--wk-accent)">${escapeHtml(w.email)}</a>` : '', { wide: true }],
            ['Telefon (Festnetz)', e(w.telefon)],
            ['Mobil', e(w.mobil)],
            ['Adresse', escapeHtml(adresse), { wide: true }],
        ]),
        wkSdSection('work', 'Beschäftigung', [
            ['Art', e(w.art_der_beschaeftigung)],
            ['Bereich', e(((typeof bereichTagsOf === 'function') ? bereichTagsOf(w) : [w.bereich].filter(Boolean)).join(', '))],
            ['Eintritt', w.eintritt ? formatDate(w.eintritt) : ''],
            ['Austritt', w.austritt ? formatDate(w.austritt) : ''],
            ['Std-Typ', e(w.arbeitsstunden_typ)],
            ['Std', w.arbeitsstunden ? w.arbeitsstunden + ' Std' : ''],
            [w.lohn_typ === 'gehalt' ? 'Gehalt' : 'Lohn', lohnHtml],
            ['Urlaubstage', f(w.urlaubstage)],
            ['Status', w.aktiv == 1 ? '<span class="status status-ja">Aktiv</span>' : '<span class="status status-nein">Inaktiv</span>', { info: true }],
            ['App-Zugang', w.app_zugang == 1 ? '<span class="status status-ja">Ja</span>' : '<span class="status status-nein">Nein</span>', { info: true }],
            ...(w.app_zugang == 1 ? [['App zuletzt genutzt', appLoginHtml, { info: true }]] : []),
        ]),
        wkSdSection('badge', 'Personalausweis', [
            ['Personalausweis-Nr.', e(w.personalausweis_nr), { mono: true, wide: true }],
            ['Gültig ab', w.personalausweis_gueltig_ab ? formatDate(w.personalausweis_gueltig_ab) : ''],
            ['Gültig bis', w.personalausweis_gueltig_bis ? formatDate(w.personalausweis_gueltig_bis) : ''],
        ]),
        // Security-specific block — only for workers whose Bereich contains
        // "Sicherheit" (same rule as the edit modal's Sicherheits-Tab).
        sec.bew ? wkSdSection('shield', sec.std ? 'Bewachung & DFB' : 'Bewachung', [
            ['Bewacherregister-Nr.', e(w.bewacherregister_nr)],
            ['Funkrufname', e(w.funkrufname)],
            ...(sec.std ? [
            ['DFB-Pin', e(w.dfb_pin), { mono: true }],
            ['Barcode', e(w.barcode), { mono: true }],
            ['Ordnernummer', e(w.ordnernummer)],
            ['Stadionverbot', w.stadion_gesperrt == 1 ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:999px;font-weight:700;color: var(--md-sys-color-error);background: color-mix(in srgb, var(--md-sys-color-error) 12%, transparent)">${wkIcon('block', 'font-size:14px')} Gesperrt</span>` : 'Nein', { info: true }],
            ] : []),
        ]) : '',
        wkQualViewHtml(w),
        // Issued equipment (Lager & Ausrüstung) — filled async by wkLoadLager().
        '<div id="wkLagerBox"></div>',
        wkSdSection('account_balance', 'Steuer & Sozialversicherung', [
            ['Steuer-ID', e(w.steuer_id), { mono: true }],
            ['SV-Nummer', e(w.sv_nummer), { mono: true }],
            ['Steuerklasse', f(w.steuerklasse)],
            ['Krankenkasse', e(w.krankenkasse)],
        ]),
        wkSdSection('account_balance_wallet', 'Bankverbindung', [
            ['IBAN', e(w.bank_verbindung), { mono: true, wide: true }],
        ]),
    ];
    return `
        <div class="wk-data-card">
            <div class="wk-sd">${sections.join('')}</div>
            <div class="wk-data-actions">
                <md-filled-button onclick="openWorkerModal('${w.dienst_nr}')" style="--md-filled-button-container-color:var(--wk-accent)"><md-icon slot="icon">edit</md-icon>Bearbeiten</md-filled-button>
            </div>
        </div>
    `;
}

// ============================================================
// Lager — equipment currently issued to the worker (Stammdaten tab)
// ============================================================
async function wkLoadLager(dienstNr) {
    const box = document.getElementById('wkLagerBox');
    if (!box || typeof hasPermission !== 'function' || !hasPermission('stammdaten.lager.view')) return;
    let rows = [];
    try {
        const r = await apiGet(API_BASE + '/lager/ausgaben?status=offen&mitarbeiter=' + encodeURIComponent(dienstNr));
        rows = (r && Array.isArray(r.data)) ? r.data : [];
    } catch (e) { return; }
    if (!document.getElementById('wkLagerBox')) return;
    const eur = v => (Number(v) || 0).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
    const wert = rows.reduce((s, r) => s + (r.einzelpreis != null ? r.menge * r.einzelpreis : 0), 0);
    const fields = rows.map(r => [
        r.artikel_name + (r.art_nr ? ' · ' + r.art_nr : ''),
        `<strong>${r.menge}×</strong> <small style="color:var(--gray-500);font-weight:400">seit ${formatDate(String(r.ausgegeben_am).substring(0, 10))}${r.einzelpreis != null ? ' · ' + eur(r.menge * r.einzelpreis) : ''}</small>`,
        { info: true },
    ]);
    const html = rows.length
        ? wkSdSection('inventory_2', 'Ausrüstung (Lager)' + (wert > 0 ? ' · ' + eur(wert) : ''), fields)
        : wkSdSection('inventory_2', 'Ausrüstung (Lager)', [['Ausgegeben', 'Keine Ausrüstung ausgegeben', { info: true, wide: true }]]);
    box.outerHTML = html;
}

// ============================================================
// Documents Tab
// ============================================================
async function wkLoadDocs(dienstNr, loaded) {
    const container = document.getElementById('tab-dokumente');
    if (!container) return;

    // The profile passes the worker it just fetched; reloads fetch fresh.
    const w = loaded || await apiGet(WK_API.show(dienstNr));
    const docs = w?.dokumente || [];
    document.getElementById('wkTabCountDocs').textContent = docs.length;

    container.innerHTML = `
        <div class="wk-docs-upload">
            <div class="wk-docs-upload-grid">
                <div class="form-group">
                    <md-outlined-text-field id="wkDocTitel" label="Titel *" placeholder="z.B. Arbeitsvertrag, ID..." style="width:100%"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <md-outlined-select id="wkDocTyp" label="Typ" style="width:100%">
                        <md-select-option value="" selected><div slot="headline">— allgemein —</div></md-select-option>
                        ${['Arbeitsvertrag','Personalausweis','Steuer-ID','SV-Nummer','Krankenkassen-Bescheinigung','Bankverbindung','Lohnabrechnung','Sonstiges'].map(t => `<md-select-option value="${t}"><div slot="headline">${t}</div></md-select-option>`).join('')}
                    </md-outlined-select>
                </div>
                <div class="form-group">
                    <md-outlined-text-field type="date" id="wkDocAblauf" label="Ablaufdatum" style="width:100%"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label class="cust-file-label">PDF-Datei *</label>
                    <input type="file" class="form-control" id="wkDocFile" accept=".pdf">
                </div>
                <div class="wk-docs-upload-submit">
                    <md-filled-tonal-button onclick="wkUploadDoc('${dienstNr}')"><md-icon slot="icon">cloud_upload</md-icon>Hochladen (max. 10 MB)</md-filled-tonal-button>
                </div>
            </div>
        </div>
        <div class="wk-docs-grid">
            ${docs.length ? docs.map(d => wkDocCardHtml(d, dienstNr)).join('') : `<div class="wk-empty" style="grid-column:1/-1">${wkIcon('picture_as_pdf')}<p>Noch keine Dokumente</p></div>`}
        </div>
    `;

    // Lazy-load PDF/image thumbnails for the freshly rendered cards.
    if (typeof hydrateSecureThumbs === 'function') hydrateSecureThumbs(container);
}

function wkDocCardHtml(doc, dienstNr) {
    const sizeKB = doc.dateigroesse ? (doc.dateigroesse / 1024).toFixed(0) : '?';
    const date = doc.hochgeladen_am ? doc.hochgeladen_am.substring(0, 10) : '';
    const expiry = wkDocExpiryStatus(doc.ablaufdatum);

    let expiryBadge = '', expiryText = '';
    if (expiry) {
        expiryBadge = `<div class="wk-doc-expiry-badge ${expiry.status}" title="${expiry.label}">${wkIcon(expiry.icon, 'font-size:14px')}</div>`;
        expiryText = `<span class="wk-doc-expiry-text ${expiry.status}">${wkIcon(expiry.icon, 'font-size:11px;vertical-align:-2px')} ${expiry.label}</span>`;
    }

    // Thumbnail endpoint is auth-gated, so the card carries
    // data-thumb-url and gets lazy-loaded via hydrateSecureThumbs().
    const thumbUrl = WK_API_BASE + '/worker-documents/' + doc.id + '/thumb';

    return `
        <div class="wk-doc-card">
            <div class="wk-doc-thumb doc-thumb" data-thumb-url="${escapeHtml(thumbUrl)}" onclick="wkOpenDoc(${doc.id})">
                ${wkIcon('picture_as_pdf')}
                <span>PDF</span>
                ${expiryBadge}
            </div>
            <div class="wk-doc-info">
                <div class="wk-doc-title" title="${escapeHtml(doc.titel)}">${escapeHtml(doc.titel)}</div>
                <div class="wk-doc-meta">
                    ${doc.dok_typ ? `<span style="color:var(--wk-accent);font-weight:600">${escapeHtml(doc.dok_typ)}</span>` : ''}
                    <span>${sizeKB} KB · ${formatDate(date)}</span>
                    ${expiryText}
                </div>
            </div>
            <div class="wk-doc-actions">
                <md-icon-button onclick="wkEditDoc(${doc.id},'${escapeHtml(doc.titel)}','${doc.ablaufdatum || ''}','${dienstNr}','${escapeHtml(doc.dok_typ || '')}')" title="Bearbeiten"><md-icon>edit</md-icon></md-icon-button>
                <md-icon-button onclick="hmsOpenFile('${hmsFileArg(doc.dateipfad)}')" title="Anzeigen"><md-icon>visibility</md-icon></md-icon-button>
                <md-icon-button onclick="hmsOpenFile('${hmsFileArg(doc.dateipfad)}', true)" title="Herunterladen"><md-icon>download</md-icon></md-icon-button>
                <md-icon-button style="--md-icon-button-icon-color:var(--wk-danger)" onclick="wkDeleteDoc(${doc.id},'${dienstNr}')" title="Löschen"><md-icon>delete</md-icon></md-icon-button>
            </div>
        </div>
    `;
}

async function wkUploadDoc(dienstNr) {
    const titel = document.getElementById('wkDocTitel').value.trim();
    const dokTyp = document.getElementById('wkDocTyp').value;
    const ablauf = document.getElementById('wkDocAblauf').value;
    const fileInput = document.getElementById('wkDocFile');
    const file = fileInput.files[0];

    if (!titel) return showToast('Bitte Titel eingeben', 'error');
    if (!file) return showToast('Bitte PDF-Datei wählen', 'error');
    if (!file.name.toLowerCase().endsWith('.pdf')) return showToast('Nur PDF erlaubt', 'error');
    if (file.size > 10 * 1024 * 1024) return showToast('Maximal 10 MB', 'error');

    const formData = new FormData();
    formData.append('dienst_nr', dienstNr);
    formData.append('titel', titel);
    formData.append('dokument', file);
    if (dokTyp) formData.append('dok_typ', dokTyp);
    if (ablauf) formData.append('ablaufdatum', ablauf);

    try {
        const result = await apiUpload(WK_API.docs + '/upload', formData);
        if (result.success) {
            showToast('Dokument hochgeladen');
            wkLoadDocs(dienstNr);
            wkLoadChecklist(dienstNr);
        } else {
            showToast(result.error || 'Upload fehlgeschlagen', 'error');
        }
    } catch (e) {
        showToast('Upload fehlgeschlagen', 'error');
    }
}

function wkEditDoc(docId, titel, ablauf, dienstNr, dokTyp) {
    const body = `
        <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:16px">
            <div class="form-group" style="grid-column:1/-1">
                <md-outlined-text-field id="wkEditDocTitel" label="Titel *" value="${escapeHtml(titel)}" style="width:100%"></md-outlined-text-field>
            </div>
            <div class="form-group">
                <md-outlined-select id="wkEditDocTyp" label="Typ" style="width:100%">
                    <md-select-option value="" ${!dokTyp ? 'selected' : ''}><div slot="headline">— allgemein —</div></md-select-option>
                    ${['Arbeitsvertrag','Personalausweis','Steuer-ID','SV-Nummer','Krankenkassen-Bescheinigung','Bankverbindung','Lohnabrechnung','Sonstiges'].map(t => `<md-select-option value="${t}" ${dokTyp === t ? 'selected' : ''}><div slot="headline">${t}</div></md-select-option>`).join('')}
                </md-outlined-select>
            </div>
            <div class="form-group">
                <md-outlined-text-field type="date" id="wkEditDocAblauf" label="Ablaufdatum" value="${ablauf}" style="width:100%"></md-outlined-text-field>
            </div>
        </div>
    `;
    const footer = `<md-text-button onclick="closeWorkersDialog()">Abbrechen</md-text-button><md-filled-button onclick="wkSaveDocEdit(${docId},'${dienstNr}')" style="--md-filled-button-container-color:var(--wk-accent)">Speichern</md-filled-button>`;
    openWorkersDialog('Dokument bearbeiten', body, footer);
}

async function wkSaveDocEdit(docId, dienstNr) {
    const titel = document.getElementById('wkEditDocTitel').value.trim();
    const dokTyp = document.getElementById('wkEditDocTyp').value;
    const ablauf = document.getElementById('wkEditDocAblauf').value || null;
    if (!titel) return showToast('Titel erforderlich', 'error');
    const result = await apiPost(WK_API.docs + '/update', { id: docId, titel, dok_typ: dokTyp, ablaufdatum: ablauf });
    if (result.success) {
        closeWorkersDialog();
        showToast('Aktualisiert');
        wkLoadDocs(dienstNr);
        wkLoadChecklist(dienstNr);
    } else showToast(result.error || 'Fehler', 'error');
}

// Open the worker doc in a new tab via the legacy /serve endpoint
// (kept for backward compat — the eye/download buttons still use it).
function wkOpenDoc(docId) {
    const card = document.querySelector('.wk-doc-card [data-thumb-url*="/' + docId + '/thumb"]');
    const link = card?.parentElement?.querySelector('.wk-doc-actions md-icon-button[title="Anzeigen"]');
    if (link) link.click();
}
window.wkOpenDoc = wkOpenDoc;

async function wkDeleteDoc(docId, dienstNr) {
    if (!await mdConfirm('Dokument wirklich löschen?', { title: 'Dokument löschen', confirmLabel: 'Löschen' })) return;
    const result = await apiPost(WK_API.docs + '/delete', { id: docId });
    if (result.success) {
        showToast('Gelöscht');
        wkLoadDocs(dienstNr);
        wkLoadChecklist(dienstNr);
    } else showToast(result.error || 'Fehler', 'error');
}

// ============================================================
// Compliance Checklist Tab
// ============================================================
async function wkLoadChecklist(dienstNr) {
    const container = document.getElementById('tab-compliance');
    if (!container) return;

    let data;
    try {
        data = await apiGet(WK_API.checklist(dienstNr));
    } catch (e) {
        container.innerHTML = `<div class="wk-empty">${wkIcon('warning')}<p>Fehler beim Laden</p></div>`;
        return;
    }

    if (!data || !data.checklist) {
        container.innerHTML = `<div class="wk-empty">${wkIcon('warning')}<p>Keine Daten</p></div>`;
        return;
    }

    const sm = data.summary;
    const items = data.checklist;

    const summaryHtml = `
        <div class="wk-checklist-summary">
            <span class="wk-cl-pill wk-cl-ok">${wkIcon('check_circle', 'font-size:13px')} ${sm.ok} OK</span>
            ${sm.expiring > 0 ? `<span class="wk-cl-pill wk-cl-expiring">${wkIcon('hourglass_top', 'font-size:13px')} ${sm.expiring} läuft ab</span>` : ''}
            ${sm.expired > 0 ? `<span class="wk-cl-pill wk-cl-expired">${wkIcon('error', 'font-size:13px')} ${sm.expired} abgelaufen</span>` : ''}
            ${sm.missing > 0 ? `<span class="wk-cl-pill wk-cl-missing">${wkIcon('radio_button_unchecked', 'font-size:13px')} ${sm.missing} fehlt</span>` : ''}
            <span class="wk-cl-pill" style="margin-left:auto;background:var(--wk-bg-strip);color:var(--wk-text-muted)">${wkIcon('list', 'font-size:13px')} ${sm.ok} / ${sm.total_required} erfüllt</span>
        </div>
    `;

    const itemsHtml = items.map(item => {
        const stMap = {
            ok:       { icon: 'check_circle', label: 'Gültig' },
            expiring: { icon: 'hourglass_top', label: 'Läuft bald ab' },
            expired:  { icon: 'error', label: 'Abgelaufen' },
            missing:  { icon: 'radio_button_unchecked', label: 'Fehlt' },
        }[item.status] || { icon: 'radio_button_unchecked', label: '–' };

        const validUntil = item.doc?.ablaufdatum ? new Date(item.doc.ablaufdatum).toLocaleDateString('de-DE') : null;

        return `
            <div class="wk-cl-item ${item.status}">
                <span class="material-symbols-outlined wk-cl-icon ${item.status}">${stMap.icon}</span>
                <div class="wk-cl-body">
                    <div class="wk-cl-title">${escapeHtml(item.dok_typ)}</div>
                    <div class="wk-cl-meta">
                        ${stMap.label}${validUntil ? ` · Gültig bis: <strong>${validUntil}</strong>` : ''}
                        ${item.doc ? ` · ${escapeHtml(item.doc.titel)}` : ''}
                    </div>
                </div>
                <div class="wk-cl-actions">
                    ${item.doc
                        ? `<md-icon-button onclick="hmsOpenFile('${hmsFileArg(item.doc.dateipfad)}')"><md-icon>visibility</md-icon></md-icon-button>`
                        : `<md-outlined-button onclick="wkSwitchToDocsAndPrefill('${escapeHtml(item.dok_typ)}')"><md-icon slot="icon">upload</md-icon>Hochladen</md-outlined-button>`}
                </div>
            </div>
        `;
    }).join('');

    container.innerHTML = summaryHtml + '<div class="wk-cl-list">' + itemsHtml + '</div>';
}

function wkSwitchToDocsAndPrefill(dokTyp) {
    const tab = document.querySelector('.wk-tab[data-tab="dokumente"]');
    if (tab) wkSwitchTab(tab);
    setTimeout(() => {
        const sel = document.getElementById('wkDocTyp');
        const titel = document.getElementById('wkDocTitel');
        if (sel) sel.value = dokTyp;
        if (titel && !titel.value) titel.value = dokTyp;
    }, 100);
}

// ============================================================
// Absences Tab
// ============================================================
async function wkLoadAbsences(dienstNr, year) {
    const container = document.getElementById('tab-abwesenheiten');
    if (!container) return;

    const currentYear = new Date().getFullYear();
    const selectedYear = year || currentYear;

    const res = await apiGet(WK_API.absences + '?dienst_nr=' + dienstNr + '&jahr=' + selectedYear);
    const absences = res.abwesenheiten || [];
    // Same response feeds the Urlaub tab (current year) — one request, and
    // approvals / edits refresh both tabs.
    if (selectedYear == currentYear) wkLoadUrlaub(dienstNr, res);

    // Update the pending-count badge on the Abwesenheiten tab button.
    // Decisions are still required, regardless of year, so we also probe a
    // wider window via the API once if the year filter hid pending rows.
    wkUpdateAbsenceTabBadge(absences);

    // Single source of truth — the backend Urlaubskonto (same numbers the
    // worker sees in the app). No client-side re-computation.
    const konto = res.urlaubskonto || { anspruch: 0, genommen: 0, beantragt: 0, rest: 0 };

    container.innerHTML = `
        <div class="wk-absence-stats">
            <div class="wk-absence-stat">
                <div class="wk-absence-stat-value">${res.total_tage || 0}</div>
                <div class="wk-absence-stat-label">Tage gesamt</div>
            </div>
            <div class="wk-absence-stat paid">
                <div class="wk-absence-stat-value">${res.total_bezahlt || 0}</div>
                <div class="wk-absence-stat-label">Bezahlt</div>
            </div>
            <div class="wk-absence-stat unpaid">
                <div class="wk-absence-stat-value">${res.total_unbezahlt || 0}</div>
                <div class="wk-absence-stat-label">Unbezahlt</div>
            </div>
            <div class="wk-absence-stat vacation">
                <div class="wk-absence-stat-value">${konto.anspruch}</div>
                <div class="wk-absence-stat-label">Urlaubsanspruch</div>
            </div>
            <div class="wk-absence-stat">
                <div class="wk-absence-stat-value" style="color:var(--wk-warn)">${konto.beantragt}</div>
                <div class="wk-absence-stat-label">Beantragt</div>
            </div>
            <div class="wk-absence-stat remaining">
                <div class="wk-absence-stat-value" style="${konto.rest <= 0 ? 'color:var(--wk-danger)' : ''}">${konto.rest}</div>
                <div class="wk-absence-stat-label">Resturlaub</div>
            </div>
        </div>
        <div class="wk-absence-toolbar">
            <md-outlined-select id="wkAbsYear" label="Jahr" onchange="wkLoadAbsences('${dienstNr}', this.value)">
                ${[currentYear - 1, currentYear, currentYear + 1].map(y => `<md-select-option value="${y}" ${y == selectedYear ? 'selected' : ''}><div slot="headline">${y}</div></md-select-option>`).join('')}
            </md-outlined-select>
            <md-filled-button onclick="wkShowAbsenceForm('${dienstNr}')" style="--md-filled-button-container-color:var(--wk-accent)"><md-icon slot="icon">add</md-icon>Abwesenheit hinzufügen</md-filled-button>
        </div>
        <div id="wkAbsFormContainer"></div>
        <div id="wkAbsList">
            ${absences.length > 0 ? absences.map(a => wkAbsenceRowHtml(a, dienstNr)).join('') : `<div class="wk-empty" style="padding:40px">${wkIcon('event_available')}<p>Keine Abwesenheiten im Jahr ${selectedYear}</p></div>`}
        </div>
    `;
}

function wkAbsenceTypeBadge(typ, bezahlt) {
    const colors = {
        'Arzt Termin': '#6366f1', 'Berufsschule': '#8b5cf6', 'Fehlt unentschuldigt': '#ef4444',
        'Feiertag': '#f59e0b', 'Frei aus Überstunden': '#10b981', 'Krank Mit AU': '#f97316',
        'Krank Ohne AU': '#dc2626', 'Kunden Außendienst': '#3b82f6', 'Kurierdienst': '#06b6d4',
        'Unbezahlte Urlaub': '#a855f7', 'Urlaub': '#22c55e', 'Wunschfrei': '#ec4899'
    };
    const color = colors[typ] || '#64748b';
    return `<span class="absence-type-badge" style="--badge-color:${color}">${typ}</span>`;
}

/**
 * Inject (or remove) a small red counter on the Abwesenheiten tab button
 * inside the open worker profile. The counter reflects how many of the
 * currently-loaded absences are still in `pending` state — i.e. how many
 * the manager still needs to genehmigen/ablehnen.
 */
function wkUpdateAbsenceTabBadge(absences) {
    const tab = document.querySelector('.wk-tab[data-tab="abwesenheiten"]');
    if (!tab) return;
    const pending = (absences || []).filter(a => (a.status || 'genehmigt') === 'pending').length;
    let badge = tab.querySelector('.wk-tab-pending-badge');
    if (pending > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'wk-tab-pending-badge';
            tab.appendChild(badge);
        }
        badge.textContent = pending > 9 ? '9+' : pending;
        badge.style.display = '';
    } else if (badge) {
        badge.style.display = 'none';
    }
}

function wkAbsenceStatusBadge(a) {
    // Default to 'genehmigt' for legacy rows that pre-date the workflow column.
    const s = (a.status || 'genehmigt');
    const map = {
        pending:    { label: 'Wartet auf Entscheidung', cls: 'absence-status-pending',    icon: 'hourglass_top' },
        genehmigt:  { label: 'Genehmigt',                cls: 'absence-status-genehmigt',  icon: 'check_circle'  },
        abgelehnt:  { label: 'Abgelehnt',                cls: 'absence-status-abgelehnt',  icon: 'cancel'        },
    };
    const m = map[s] || map.genehmigt;
    return `<span class="absence-status-badge ${m.cls}">${wkIcon(m.icon, 'font-size:12px')} ${m.label}</span>`;
}

function wkAbsenceRowHtml(a, dienstNr) {
    const start = formatDate(a.startdatum);
    const end = a.enddatum && a.enddatum !== a.startdatum ? ' – ' + formatDate(a.enddatum) : '';
    const zeit = !a.ganzer_tag && a.startzeit ? ` (${a.startzeit.substring(0,5)} – ${a.endzeit ? a.endzeit.substring(0,5) : '?'})` : '';
    const status = (a.status || 'genehmigt');
    const pending = status === 'pending';
    const rejected = status === 'abgelehnt';
    return `
        <div class="absence-card ${pending ? 'absence-card-pending' : ''} ${rejected ? 'absence-card-rejected' : ''}">
            <div class="absence-card-left">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    ${wkAbsenceTypeBadge(a.typ, a.bezahlt)}
                    ${wkAbsenceStatusBadge(a)}
                </div>
                <div class="absence-card-details">
                    <span class="absence-date">${wkIcon('calendar_today', 'font-size:12px')} ${start}${end}${zeit}</span>
                    <span class="absence-days">${a.tage} ${a.tage == 1 ? 'Tag' : 'Tage'} · ${a.bezahlt == 1 ? '<span style="color:var(--wk-ok)">Bezahlt</span>' : '<span style="color:var(--wk-danger)">Unbezahlt</span>'}</span>
                    ${a.beschreibung ? `<span class="absence-note">${escapeHtml(a.beschreibung)}</span>` : ''}
                    ${rejected && a.ablehnungsgrund ? `<span class="absence-note" style="color:var(--wk-danger)">${wkIcon('comment', 'font-size:12px')} ${escapeHtml(a.ablehnungsgrund)}</span>` : ''}
                </div>
            </div>
            <div class="btn-group">
                ${pending ? `
                    <md-filled-button style="--md-filled-button-container-color:#10b981" onclick="wkApproveAbsence(${a.id},'${dienstNr}')" title="Genehmigen"><md-icon slot="icon">check</md-icon>Genehmigen</md-filled-button>
                    <md-filled-button style="--md-filled-button-container-color:#ef4444" onclick="wkRejectAbsence(${a.id},'${dienstNr}')" title="Ablehnen"><md-icon slot="icon">close</md-icon>Ablehnen</md-filled-button>
                ` : ''}
                <md-icon-button onclick='wkEditAbsence(${JSON.stringify(a).replace(/'/g, "&#39;")},"${dienstNr}")' title="Bearbeiten"><md-icon>edit</md-icon></md-icon-button>
                <md-icon-button style="--md-icon-button-icon-color:var(--wk-danger)" onclick="wkDeleteAbsence(${a.id},'${dienstNr}')" title="Löschen"><md-icon>delete</md-icon></md-icon-button>
            </div>
        </div>
    `;
}

function wkApproveAbsence(id, dienstNr) {
    const body = `
        <div style="padding:8px 4px 12px;font-size:0.95rem;color: var(--wk-text,var(--md-sys-color-on-primary-container));line-height:1.5">
            <div style="display:flex;align-items:flex-start;gap:12px">
                <div style="width:44px;height:44px;border-radius:50%;background: var(--md-ext-color-success-container);color: var(--md-ext-color-on-success-container);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    ${wkIcon('check_circle')}
                </div>
                <div>
                    <div style="font-weight:600;margin-bottom:4px">Antrag genehmigen?</div>
                    <div style="color: var(--wk-text-muted,var(--md-sys-color-on-surface-variant));font-size:0.85rem">
                        Der Mitarbeiter erhält eine Benachrichtigung in der App und per E-Mail.
                    </div>
                </div>
            </div>
        </div>
    `;
    const footer = `
        <md-text-button onclick="closeWorkersDialog()">Abbrechen</md-text-button>
        <md-filled-button style="--md-filled-button-container-color:#10b981" id="wkApproveConfirmBtn">
            <md-icon slot="icon">check</md-icon>Genehmigen
        </md-filled-button>
    `;
    openWorkersDialog('Antrag genehmigen', body, footer);
    document.getElementById('wkApproveConfirmBtn').onclick = async () => {
        closeWorkersDialog();
        const r = await apiPost(WK_API.absences + '/' + id + '/approve', {});
        if (r && r.success) {
            showToast('Antrag genehmigt');
            wkLoadAbsences(dienstNr);
            if (window.HMSNotifications) window.HMSNotifications.refresh();
        } else {
            showToast((r && r.error) || 'Fehler', 'error');
        }
    };
}

function wkRejectAbsence(id, dienstNr) {
    const body = `
        <div style="padding:4px 4px 12px;font-size:0.95rem;color: var(--wk-text,var(--md-sys-color-on-primary-container));line-height:1.5">
            <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:14px">
                <div style="width:44px;height:44px;border-radius:50%;background: var(--md-sys-color-error-container);color: var(--md-sys-color-error);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    ${wkIcon('cancel')}
                </div>
                <div>
                    <div style="font-weight:600;margin-bottom:4px">Antrag ablehnen?</div>
                    <div style="color: var(--wk-text-muted,var(--md-sys-color-on-surface-variant));font-size:0.85rem">
                        Der Mitarbeiter erhält Ihre Begründung in der App und per E-Mail.
                    </div>
                </div>
            </div>
            <md-outlined-text-field type="textarea" rows="4" maxlength="500" id="wkRejectGrund"
                label="Begründung (optional)" supporting-text="max. 500 Zeichen"
                placeholder="z. B. Personalengpass an diesem Tag, bitte einen Ersatztermin vorschlagen."
                style="width:100%"></md-outlined-text-field>
        </div>
    `;
    const footer = `
        <md-text-button onclick="closeWorkersDialog()">Abbrechen</md-text-button>
        <md-filled-button style="--md-filled-button-container-color:#ef4444" id="wkRejectConfirmBtn">
            <md-icon slot="icon">close</md-icon>Ablehnen
        </md-filled-button>
    `;
    openWorkersDialog('Antrag ablehnen', body, footer);
    const ta = document.getElementById('wkRejectGrund');
    setTimeout(() => ta.focus(), 60);
    document.getElementById('wkRejectConfirmBtn').onclick = async () => {
        const grund = ta.value.trim();
        closeWorkersDialog();
        const r = await apiPost(WK_API.absences + '/' + id + '/reject', { grund });
        if (r && r.success) {
            showToast('Antrag abgelehnt');
            wkLoadAbsences(dienstNr);
            if (window.HMSNotifications) window.HMSNotifications.refresh();
        } else {
            showToast((r && r.error) || 'Fehler', 'error');
        }
    };
}

function wkShowAbsenceForm(dienstNr, a) {
    const isEdit = !!a;
    const ganzerTag = a ? a.ganzer_tag == 1 : true;
    const container = document.getElementById('wkAbsFormContainer');
    container.innerHTML = `
        <div class="wk-absence-form ${isEdit ? 'editing' : ''}">
            ${isEdit ? `<div style="font-weight:700;margin-bottom:10px;color:var(--wk-warn)">${wkIcon('edit', 'font-size:14px')} Abwesenheit bearbeiten (ID: ${a.id})</div>` : ''}
            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
                <div class="form-group">
                    <md-outlined-select id="wkAbsTyp" label="Typ *" onchange="wkOnAbsTypeChange()" style="width:100%">
                        ${isEdit ? '' : '<md-select-option value="" selected><div slot="headline">– Bitte wählen –</div></md-select-option>'}
                        ${ABSENCE_TYPES.map(t => `<md-select-option value="${t.name}" data-bezahlt="${t.bezahlt}" ${a && t.name === a.typ ? 'selected' : ''}><div slot="headline">${t.name}</div></md-select-option>`).join('')}
                    </md-outlined-select>
                </div>
                <div class="form-group">
                    <md-outlined-select id="wkAbsBezahlt" label="Bezahlt" style="width:100%">
                        <md-select-option value="1" ${!a || a.bezahlt == 1 ? 'selected' : ''}><div slot="headline">Ja</div></md-select-option>
                        <md-select-option value="0" ${a && a.bezahlt == 0 ? 'selected' : ''}><div slot="headline">Nein</div></md-select-option>
                    </md-outlined-select>
                </div>
            </div>
            <div class="form-grid" style="grid-template-columns:auto 1fr 1fr;gap:14px;align-items:center;margin-bottom:14px">
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
                        <md-checkbox id="wkAbsGanzerTag" ${ganzerTag ? 'checked' : ''} onchange="document.getElementById('wkAbsTimeRow').style.display=this.checked?'none':'grid'"></md-checkbox> Ganzer Tag
                    </label>
                </div>
                <div class="form-group">
                    <md-outlined-text-field type="date" id="wkAbsStart" label="Startdatum *" value="${a?.startdatum || ''}" onchange="wkCalcDays()" style="width:100%"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <md-outlined-text-field type="date" id="wkAbsEnd" label="Enddatum" value="${a?.enddatum || ''}" onchange="wkCalcDays()" style="width:100%"></md-outlined-text-field>
                </div>
            </div>
            <div class="form-grid" id="wkAbsTimeRow" style="grid-template-columns:1fr 1fr;gap:14px;display:${ganzerTag ? 'none' : 'grid'};margin-bottom:14px">
                <div class="form-group">
                    <md-outlined-text-field type="text" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="t24" id="wkAbsStartzeit" label="Von" value="${a?.startzeit ? a.startzeit.substring(0,5) : '08:00'}" style="width:100%"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <md-outlined-text-field type="text" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="t24" id="wkAbsEndzeit" label="Bis" value="${a?.endzeit ? a.endzeit.substring(0,5) : '17:00'}" style="width:100%"></md-outlined-text-field>
                </div>
            </div>
            <div class="form-grid" style="grid-template-columns:140px 1fr;gap:14px;margin-bottom:14px">
                <div class="form-group">
                    <md-outlined-text-field type="number" id="wkAbsTage" label="Tage" value="${a?.tage || 1}" min="0.5" step="0.5" style="width:100%"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <md-outlined-text-field id="wkAbsBeschreibung" label="Beschreibung" value="${a?.beschreibung || ''}" placeholder="Optional..." style="width:100%"></md-outlined-text-field>
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <md-text-button onclick="document.getElementById('wkAbsFormContainer').innerHTML=''">Abbrechen</md-text-button>
                <md-filled-button onclick="${isEdit ? `wkUpdateAbsence(${a.id},'${dienstNr}')` : `wkSaveAbsence('${dienstNr}')`}" style="--md-filled-button-container-color:var(--wk-accent)"><md-icon slot="icon">${isEdit ? 'save' : 'add'}</md-icon>${isEdit ? 'Speichern' : 'Hinzufügen'}</md-filled-button>
            </div>
        </div>
    `;
    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function wkOnAbsTypeChange() {
    // md-outlined-select: read data-bezahlt off the selected md-select-option.
    const sel = document.getElementById('wkAbsTyp');
    const opt = sel?.selectedOptions?.[0];
    const bezahlt = opt ? opt.getAttribute('data-bezahlt') : null;
    if (bezahlt !== null && bezahlt !== undefined) {
        const bz = document.getElementById('wkAbsBezahlt');
        if (bz) bz.value = bezahlt;
    }
}

function wkCalcDays() {
    const start = document.getElementById('wkAbsStart').value;
    const end = document.getElementById('wkAbsEnd').value;
    if (start) {
        const s = new Date(start);
        const e = end ? new Date(end) : s;
        const diff = Math.round((e - s) / (1000 * 60 * 60 * 24)) + 1;
        if (diff > 0) document.getElementById('wkAbsTage').value = diff;
    }
}

function wkGetAbsData(dienstNr) {
    const typ = document.getElementById('wkAbsTyp').value;
    const startdatum = document.getElementById('wkAbsStart').value;
    if (!typ) { showToast('Typ wählen', 'error'); return null; }
    if (!startdatum) { showToast('Startdatum eingeben', 'error'); return null; }
    const ganzerTag = document.getElementById('wkAbsGanzerTag').checked;
    return {
        dienst_nr: dienstNr,
        typ, bezahlt: parseInt(document.getElementById('wkAbsBezahlt').value),
        ganzer_tag: ganzerTag ? 1 : 0,
        startdatum,
        enddatum: document.getElementById('wkAbsEnd').value || startdatum,
        startzeit: ganzerTag ? null : document.getElementById('wkAbsStartzeit').value,
        endzeit: ganzerTag ? null : document.getElementById('wkAbsEndzeit').value,
        tage: parseFloat(document.getElementById('wkAbsTage').value) || 1,
        beschreibung: document.getElementById('wkAbsBeschreibung').value || null,
    };
}

async function wkSaveAbsence(dienstNr) {
    const data = wkGetAbsData(dienstNr);
    if (!data) return;
    const result = await apiPost(WK_API.absences, data);
    if (result.success) { showToast('Gespeichert'); wkLoadAbsences(dienstNr); }
    else showToast(result.error || 'Fehler', 'error');
}

async function wkUpdateAbsence(id, dienstNr) {
    const data = wkGetAbsData(dienstNr);
    if (!data) return;
    data.id = id;
    const result = await apiPut(WK_API.absences + '/' + id, data);
    if (result.success) { showToast('Aktualisiert'); wkLoadAbsences(dienstNr); }
    else showToast(result.error || 'Fehler', 'error');
}

function wkEditAbsence(a, dienstNr) {
    wkShowAbsenceForm(dienstNr, a);
}

function wkDeleteAbsence(id, dienstNr) {
    // Use the system modal instead of confirm() and send DELETE to the
    // correct RESTful URL (/worker-absences/{id} — the legacy body-only
    // call was hitting a 405 because no route matches DELETE /worker-absences).
    const body = `
        <div style="padding:8px 4px 12px;font-size:0.95rem;color: var(--wk-text,var(--md-sys-color-on-primary-container));line-height:1.5">
            <div style="display:flex;align-items:flex-start;gap:12px">
                <div style="width:44px;height:44px;border-radius:50%;background: var(--md-sys-color-error-container);color: var(--md-sys-color-error);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    ${wkIcon('delete')}
                </div>
                <div>
                    <div style="font-weight:600;margin-bottom:4px">Diese Abwesenheit löschen?</div>
                    <div style="color: var(--wk-text-muted,var(--md-sys-color-on-surface-variant));font-size:0.85rem">
                        Der Eintrag wird unwiderruflich entfernt. Bereits versendete Benachrichtigungen bleiben in der App des Mitarbeiters sichtbar.
                    </div>
                </div>
            </div>
        </div>
    `;
    const footer = `
        <md-text-button onclick="closeWorkersDialog()">Abbrechen</md-text-button>
        <md-filled-button style="--md-filled-button-container-color:#ef4444" id="wkDeleteConfirmBtn">
            <md-icon slot="icon">delete</md-icon>Löschen
        </md-filled-button>
    `;
    openWorkersDialog('Abwesenheit löschen', body, footer);
    document.getElementById('wkDeleteConfirmBtn').onclick = async () => {
        closeWorkersDialog();
        const result = await apiDelete(WK_API.absences + '/' + id);
        if (result && result.success) {
            showToast('Gelöscht');
            wkLoadAbsences(dienstNr);
        } else {
            showToast((result && result.error) || 'Fehler', 'error');
        }
    };
}

// ============================================================
// Urlaub Tab
// ============================================================
async function wkLoadUrlaub(dienstNr, loaded) {
    const container = document.getElementById('tab-urlaub');
    if (!container) return;
    const currentYear = new Date().getFullYear();
    const res = loaded || await apiGet(WK_API.absences + '?dienst_nr=' + dienstNr + '&jahr=' + currentYear);

    // Single source of truth — backend Urlaubskonto.
    const konto = res.urlaubskonto || { anspruch: 0, genommen: 0, beantragt: 0, rest: 0 };
    const pct = konto.anspruch > 0 ? Math.min(100, Math.round(((konto.genommen + konto.beantragt) / konto.anspruch) * 100)) : 0;
    const barColor = pct > 90 ? 'var(--wk-danger)' : pct > 70 ? 'var(--wk-warn)' : 'var(--wk-ok)';

    container.innerHTML = `
        <div class="wk-data-card" style="padding:20px">
            <div style="display:flex;gap:32px;flex-wrap:wrap;margin-bottom:24px">
                <div style="flex:1;min-width:200px">
                    <div style="font-size:0.7rem;font-weight:700;color:var(--wk-text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px">Urlaubskonto ${currentYear}</div>
                    <div style="display:flex;gap:24px;flex-wrap:wrap">
                        <div>
                            <div style="font-size:2.2rem;font-weight:800;color:var(--wk-text);line-height:1">${konto.anspruch}</div>
                            <div style="font-size:0.72rem;color:var(--wk-text-muted);text-transform:uppercase;font-weight:700;letter-spacing:0.4px;margin-top:4px">Anspruch</div>
                        </div>
                        <div>
                            <div style="font-size:2.2rem;font-weight:800;color:var(--wk-ok);line-height:1">${konto.genommen}</div>
                            <div style="font-size:0.72rem;color:var(--wk-text-muted);text-transform:uppercase;font-weight:700;letter-spacing:0.4px;margin-top:4px">Genommen</div>
                        </div>
                        <div>
                            <div style="font-size:2.2rem;font-weight:800;color:var(--wk-warn);line-height:1">${konto.beantragt}</div>
                            <div style="font-size:0.72rem;color:var(--wk-text-muted);text-transform:uppercase;font-weight:700;letter-spacing:0.4px;margin-top:4px">Beantragt</div>
                        </div>
                        <div>
                            <div style="font-size:2.2rem;font-weight:800;color:${konto.rest > 0 ? 'var(--wk-accent)' : 'var(--wk-danger)'};line-height:1">${konto.rest}</div>
                            <div style="font-size:0.72rem;color:var(--wk-text-muted);text-transform:uppercase;font-weight:700;letter-spacing:0.4px;margin-top:4px">Verbleibend</div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="background:var(--wk-bg-strip);border-radius:8px;height:14px;overflow:hidden;margin-bottom:6px">
                <div style="height:100%;width:${pct}%;background:${barColor};border-radius:8px;transition:width .5s ease"></div>
            </div>
            <div style="font-size:0.74rem;color:var(--wk-text-muted);text-align:right;font-weight:700">${pct}% genutzt</div>
            <div class="wk-data-actions">
                <md-filled-button onclick="openWorkerModal('${dienstNr}')" style="--md-filled-button-container-color:var(--wk-accent)"><md-icon slot="icon">edit</md-icon>Urlaubstage bearbeiten</md-filled-button>
            </div>
        </div>
    `;
}

// ============================================================
// Analyse / Activity Tab — per-worker shift & hours analytics
// ============================================================
async function wkLoadActivity(dienstNr) {
    const container = document.getElementById('tab-analyse');
    if (!container) return;

    let data;
    try {
        data = await apiGet(WK_API.activity(dienstNr));
    } catch (e) {
        container.innerHTML = `<div class="wk-empty">${wkIcon('warning')}<p>Fehler beim Laden der Analyse</p></div>`;
        return;
    }
    if (!data || data.available === false || !data.kpis) {
        container.innerHTML = `<div class="wk-empty" style="padding:50px">${wkIcon('monitoring')}<p>Noch keine Einsatzdaten für diesen Mitarbeiter</p></div>`;
        return;
    }

    const k        = data.kpis || {};
    const monthly  = data.monthly || [];
    const recent   = data.recent || [];
    const upcoming = data.upcoming_list || [];
    const topK     = data.top_kunden || [];

    const num = (n) => (Number(n) || 0).toLocaleString('de-DE');
    const maxHours = Math.max(1, ...monthly.map(m => m.hours || 0));
    const monthLabel = (ym) => { const [y, m] = String(ym).split('-'); return new Date(y, m - 1, 1).toLocaleDateString('de-DE', { month: 'short' }); };
    const fmtD = (d) => d ? new Date(d).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '–';
    const statusPill = (s) => {
        const map = { 'bestätigt': ['var(--wk-ok)', 'rgba(16,185,129,.12)'], 'geplant': ['var(--wk-accent)', 'rgba(59,130,246,.12)'] };
        const [c, bg] = map[s] || ['var(--wk-text-muted)', 'var(--wk-bg-strip)'];
        return `<span style="font-size:.66rem;font-weight:700;padding:2px 8px;border-radius:20px;color:${c};background:${bg};white-space:nowrap">${escapeHtml(s || '–')}</span>`;
    };
    const shiftRow = (r, accentDate) => `
        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom: 1px solid var(--wk-border,var(--md-sys-color-primary-container))">
            <div style="width:42px;text-align:center;flex-shrink:0">
                <div style="font-size:1.1rem;font-weight:800;color:${accentDate ? 'var(--wk-accent)' : 'var(--wk-text)'};line-height:1">${new Date(r.datum).getDate()}</div>
                <div style="font-size:.6rem;color:var(--wk-text-muted);text-transform:uppercase;font-weight:700">${new Date(r.datum).toLocaleDateString('de-DE', { month: 'short' })}</div>
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-size:.85rem;font-weight:600;color:var(--wk-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(r.kunde || r.objekt || 'Einsatz')}</div>
                <div style="font-size:.72rem;color:var(--wk-text-muted)">${r.objekt && r.kunde ? escapeHtml(r.objekt) + ' · ' : ''}${r.start ? r.start + (r.ende ? '–' + r.ende : '') + ' Uhr' : ''}</div>
            </div>
            <div style="text-align:right;flex-shrink:0;display:flex;flex-direction:column;align-items:flex-end;gap:3px">
                ${r.stunden != null ? `<div style="font-size:.82rem;font-weight:700;color:var(--wk-text)">${num(r.stunden)} Std.</div>` : ''}
                ${r.status ? statusPill(r.status) : ''}
            </div>
        </div>`;

    const kpiHtml = `
        <div class="wk-absence-stats">
            <div class="wk-absence-stat"><div class="wk-absence-stat-value">${num(k.shifts_total)}</div><div class="wk-absence-stat-label">Einsätze gesamt</div></div>
            <div class="wk-absence-stat paid"><div class="wk-absence-stat-value">${num(k.hours_total)}</div><div class="wk-absence-stat-label">Stunden geleistet</div></div>
            <div class="wk-absence-stat vacation"><div class="wk-absence-stat-value">${num(k.month_shifts)}</div><div class="wk-absence-stat-label">Einsätze diesen Monat</div></div>
            <div class="wk-absence-stat remaining"><div class="wk-absence-stat-value">${num(k.month_hours)}</div><div class="wk-absence-stat-label">Std. diesen Monat</div></div>
            <div class="wk-absence-stat"><div class="wk-absence-stat-value" style="color:var(--wk-accent)">${num(k.upcoming)}</div><div class="wk-absence-stat-label">Kommende Einsätze</div></div>
        </div>`;

    const tenureHtml = k.first_datum
        ? `<div style="font-size:.74rem;color:var(--wk-text-muted);margin:4px 2px 16px">${wkIcon('info', 'color:var(--wk-accent);font-size:14px;vertical-align:-2px')} Erster Einsatz am <strong>${fmtD(k.first_datum)}</strong>${k.last_datum ? ` · zuletzt im Einsatz am <strong>${fmtD(k.last_datum)}</strong>` : ''} · <strong>${num(k.confirmed)}</strong> bestätigte Einsätze</div>`
        : '';

    const barsHtml = monthly.map(m => {
        const h = Math.round(((m.hours || 0) / maxHours) * 100);
        return `
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;min-width:0">
                <div style="font-size:.68rem;font-weight:700;color:var(--wk-text)">${m.hours ? num(m.hours) : ''}</div>
                <div style="width:100%;max-width:44px;height:120px;display:flex;align-items:flex-end">
                    <div title="${m.shifts} Einsätze · ${num(m.hours)} Std." style="width:100%;height:${Math.max(2, h)}%;background: linear-gradient(180deg,var(--wk-accent),color-mix(in srgb, var(--md-sys-color-primary) 50%, transparent));border-radius:6px 6px 0 0;transition:height .5s ease"></div>
                </div>
                <div style="font-size:.66rem;color:var(--wk-text-muted);font-weight:600;text-transform:capitalize">${monthLabel(m.ym)}</div>
            </div>`;
    }).join('');
    const chartCard = `
        <div class="wk-data-card" style="padding:20px;margin-bottom:16px">
            <div style="font-size:.72rem;font-weight:700;color:var(--wk-text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:18px">${wkIcon('bar_chart', 'color:var(--wk-accent);font-size:15px;vertical-align:-3px')} Geleistete Stunden – letzte ${monthly.length} Monate</div>
            <div style="display:flex;gap:8px;align-items:flex-end">${barsHtml || '<div style="color:var(--wk-text-muted);font-size:.8rem">Keine Daten</div>'}</div>
        </div>`;

    const topCard = topK.length ? `
        <div class="wk-data-card" style="padding:20px;margin-bottom:16px">
            <div style="font-size:.72rem;font-weight:700;color:var(--wk-text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px">${wkIcon('domain', 'color:var(--wk-accent);font-size:15px;vertical-align:-3px')} Häufigste Einsatzorte</div>
            ${topK.map(t => {
                const pct = Math.round((t.shifts / topK[0].shifts) * 100);
                return `
                <div style="margin-bottom:12px">
                    <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:4px;gap:10px">
                        <span style="font-weight:600;color:var(--wk-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(t.name)}</span>
                        <span style="color:var(--wk-text-muted);font-weight:600;white-space:nowrap">${t.shifts} Einsätze · ${num(t.hours)} Std.</span>
                    </div>
                    <div style="background:var(--wk-bg-strip);border-radius:6px;height:8px;overflow:hidden"><div style="height:100%;width:${pct}%;background:var(--wk-accent);border-radius:6px"></div></div>
                </div>`;
            }).join('')}
        </div>` : '';

    const recentRows = recent.length ? recent.map(r => shiftRow(r, false)).join('')
        : `<div class="wk-empty" style="padding:30px">${wkIcon('history')}<p>Keine vergangenen Einsätze</p></div>`;
    const upcomingRows = upcoming.length ? upcoming.map(r => shiftRow(r, true)).join('')
        : `<div class="wk-empty" style="padding:30px">${wkIcon('calendar_today')}<p>Keine kommenden Einsätze</p></div>`;

    const listsHtml = `
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">
            <div class="wk-data-card" style="padding:20px">
                <div style="font-size:.72rem;font-weight:700;color:var(--wk-text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">${wkIcon('history', 'color:var(--wk-accent);font-size:15px;vertical-align:-3px')} Letzte Aktivität</div>
                ${recentRows}
            </div>
            <div class="wk-data-card" style="padding:20px">
                <div style="font-size:.72rem;font-weight:700;color:var(--wk-text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">${wkIcon('calendar_today', 'color:var(--wk-accent);font-size:15px;vertical-align:-3px')} Kommende Einsätze</div>
                ${upcomingRows}
            </div>
        </div>`;

    container.innerHTML = kpiHtml + tenureHtml + chartCard + topCard + listsHtml;
}

// ============================================================
// PHOTO UPLOAD
// ============================================================
async function wkUploadPhoto(dienstNr) {
    const fileInput = document.getElementById('wkPhotoInput');
    const file = fileInput.files[0];
    if (!file) return;

    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type)) { showToast('Nur JPG, PNG oder WebP erlaubt', 'error'); fileInput.value = ''; return; }
    if (file.size > 5 * 1024 * 1024) { showToast('Max 5 MB', 'error'); fileInput.value = ''; return; }

    const formData = new FormData();
    formData.append('dienst_nr', dienstNr);
    formData.append('foto', file);

    try {
        const result = await apiUpload(WK_API.uploadPhoto, formData);
        if (result.success) {
            showToast('Foto hochgeladen');
            wkOpenProfile(dienstNr);
        } else {
            showToast(result.error || 'Upload fehlgeschlagen', 'error');
        }
    } catch (e) {
        showToast('Upload fehlgeschlagen', 'error');
    }
    fileInput.value = '';
}

// ============================================================
// WORKER MODAL — Create / Edit (with TABS)
// ============================================================
async function openWorkerModal(id = null) {
    let w = {};
    if (id) w = await apiGet(WK_API.show(id));

    const body = `
        <md-tabs class="cust-modal-tabs" id="wkModalTabs">
            <md-primary-tab data-pane="persoenlich" active><md-icon slot="icon">person</md-icon>Persönlich</md-primary-tab>
            <md-primary-tab data-pane="kontakt"><md-icon slot="icon">call</md-icon>Kontakt</md-primary-tab>
            <md-primary-tab data-pane="ausweis" id="wk_tab_sicherheit" style="display:none"><md-icon slot="icon">badge</md-icon>Sicherheit</md-primary-tab>
            <md-primary-tab data-pane="steuer"><md-icon slot="icon">account_balance</md-icon>Steuer</md-primary-tab>
            <md-primary-tab data-pane="bank"><md-icon slot="icon">account_balance_wallet</md-icon>Bank &amp; App</md-primary-tab>
        </md-tabs>

        <div class="wk-modal-pane active" id="pane-persoenlich">
            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <md-outlined-select id="f_w_anrede" label="Anrede" style="width:100%">
                        <md-select-option value="" ${!w.anrede ? 'selected' : ''}><div slot="headline">–</div></md-select-option>
                        ${['Herr','Frau','Divers','neutral'].map(a => `<md-select-option value="${a}" ${w.anrede === a ? 'selected' : ''}><div slot="headline">${a}</div></md-select-option>`).join('')}
                    </md-outlined-select>
                </div>
                <div class="form-group">
                    <md-outlined-select id="f_w_geschlecht" label="Geschlecht" style="width:100%">
                        <md-select-option value="" ${!w.geschlecht ? 'selected' : ''}><div slot="headline">–</div></md-select-option>
                        <md-select-option value="m" ${w.geschlecht === 'm' ? 'selected' : ''}><div slot="headline">männlich</div></md-select-option>
                        <md-select-option value="w" ${w.geschlecht === 'w' ? 'selected' : ''}><div slot="headline">weiblich</div></md-select-option>
                        <md-select-option value="d" ${w.geschlecht === 'd' ? 'selected' : ''}><div slot="headline">divers</div></md-select-option>
                        <md-select-option value="k" ${w.geschlecht === 'k' ? 'selected' : ''}><div slot="headline">keine Angabe</div></md-select-option>
                    </md-outlined-select>
                </div>
                <div class="form-group"><md-outlined-text-field id="f_w_vorname" label="Vorname *" value="${escapeHtml(w.vorname || '')}" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_w_name" label="Nachname *" value="${escapeHtml(w.name || '')}" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_w_geburtsname" label="Geburtsname" value="${escapeHtml(w.geburtsname || '')}" placeholder="Mädchenname / Geburtsname" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field type="date" id="f_w_geburtstag" label="Geburtstag" value="${w.geburtstag || ''}" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_w_geburtsort" label="Geburtsort" value="${escapeHtml(w.geburtsort || '')}" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_w_geburtsland" label="Geburtsland" value="${escapeHtml(w.geburtsland || '')}" placeholder="z.B. Deutschland" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_w_staat" label="Staatsangehörigkeit" value="${escapeHtml(w.staatsangehoerigkeit || '')}" placeholder="z.B. Deutsch" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group">
                    <md-outlined-select id="f_w_famstand" label="Familienstand" style="width:100%">
                        <md-select-option value="" ${!w.familienstand ? 'selected' : ''}><div slot="headline">–</div></md-select-option>
                        ${['Ledig','Verheiratet','Geschieden','Verwitwet'].map(f => `<md-select-option value="${f}" ${w.familienstand === f ? 'selected' : ''}><div slot="headline">${f}</div></md-select-option>`).join('')}
                    </md-outlined-select>
                </div>
                <div class="form-group"><md-outlined-text-field type="number" step="0.5" min="0" id="f_w_kinder" label="Kinderzahl / Freibetrag" value="${w.kinderzahl_freibetrag || ''}" style="width:100%"></md-outlined-text-field></div>
            </div>

            <div class="customer-form-section-title" style="margin:20px 0 12px">${wkIcon('work')} Beschäftigung</div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <md-outlined-select id="f_w_beschaeftigung" label="Art der Beschäftigung" style="width:100%">
                        <md-select-option value="" ${!w.art_der_beschaeftigung ? 'selected' : ''}><div slot="headline">–</div></md-select-option>
                        ${ART_DER_BESCHAEFTIGUNG.map(a => `<md-select-option value="${a}" ${w.art_der_beschaeftigung === a ? 'selected' : ''}><div slot="headline">${a}</div></md-select-option>`).join('')}
                    </md-outlined-select>
                </div>
                <div class="form-group">
                    <label style="display:block;font-size:0.8rem;color:var(--gray-600);margin-bottom:4px">Dienstleistungen (Mehrfachauswahl) — Bereiche mit „Bewachungsbereich“ aktivieren den Sicherheits-Tab</label>
                    <div id="f_w_bereiche" style="padding:4px 0" onchange="wkUpdateSicherheitTabVisibility();wkRenderQualFields()"></div>
                </div>
                <div class="form-group" id="f_w_quals_box" style="grid-column:1/-1;display:none"></div>
                <div class="form-group"><md-outlined-text-field type="date" id="f_w_eintritt" label="Eintritt" value="${w.eintritt || ''}" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field type="date" id="f_w_austritt" label="Austritt" value="${w.austritt || ''}" supporting-text="Austritt setzt den Status automatisch auf Inaktiv" onchange="wkAustrittChanged()" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field type="number" min="0" id="f_w_urlaub" label="Urlaubstage" value="${w.urlaubstage || ''}" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group">
                    <md-outlined-select id="f_w_std_typ" label="Arbeitsstunden-Typ" onchange="document.getElementById('f_w_std_box').style.display=this.value?'block':'none'" style="width:100%">
                        <md-select-option value="" ${!w.arbeitsstunden_typ ? 'selected' : ''}><div slot="headline">–</div></md-select-option>
                        <md-select-option value="wöchentlich" ${w.arbeitsstunden_typ === 'wöchentlich' ? 'selected' : ''}><div slot="headline">Wöchentlich</div></md-select-option>
                        <md-select-option value="monatlich" ${w.arbeitsstunden_typ === 'monatlich' ? 'selected' : ''}><div slot="headline">Monatlich</div></md-select-option>
                    </md-outlined-select>
                </div>
                <div class="form-group" id="f_w_std_box" style="display:${w.arbeitsstunden_typ ? 'block' : 'none'}">
                    <md-outlined-text-field type="number" min="0" step="0.5" id="f_w_std" label="Arbeitsstunden" value="${w.arbeitsstunden || ''}" style="width:100%"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <md-outlined-select id="f_w_lohn_typ" label="Gehalt &amp; Lohn" supporting-text="Wird im Controlling als interne Kosten verrechnet" onchange="wkUpdateLohnInput()" style="width:100%">
                        <md-select-option value=""${!w.lohn_typ ? ' selected' : ''}><div slot="headline">– auswählen –</div></md-select-option>
                        <md-select-option value="gehalt"${w.lohn_typ === 'gehalt' ? ' selected' : ''}><div slot="headline">Gehalt (€/Monat)</div></md-select-option>
                        <md-select-option value="lohn"${w.lohn_typ === 'lohn' ? ' selected' : ''}><div slot="headline">Lohn (€/Std)</div></md-select-option>
                    </md-outlined-select>
                </div>
                <div class="form-group" id="f_w_lohn_box" style="display:${w.lohn_typ ? 'block' : 'none'}">
                    <md-outlined-text-field type="number" min="0" step="0.01" id="f_w_stundenlohn" label="${w.lohn_typ === 'gehalt' ? 'Gehalt (€/Monat)' : 'Lohn (€/Std)'}" value="${w.stundenlohn !== null && w.stundenlohn !== undefined ? w.stundenlohn : ''}" placeholder="${w.lohn_typ === 'gehalt' ? 'z. B. 2.800,00' : 'z. B. 15,00'}" style="width:100%"></md-outlined-text-field>
                </div>
            </div>
            <div class="customer-form-section-title" style="margin:18px 0 12px">${wkIcon('badge')} Personalausweis</div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;gap:16px">
                <div class="form-group" style="grid-column:1/-1"><md-outlined-text-field id="f_w_pa_nr" label="Personalausweisnummer" value="${escapeHtml(w.personalausweis_nr || '')}" maxlength="30" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field type="date" id="f_w_pa_ab" label="Gültig ab" value="${w.personalausweis_gueltig_ab || ''}" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field type="date" id="f_w_pa_bis" label="Gültig bis" value="${w.personalausweis_gueltig_bis || ''}" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"></div>
            </div>
        </div>

        <div class="wk-modal-pane" id="pane-kontakt">
            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group"><md-outlined-text-field type="tel" id="f_w_telefon" label="Telefon (Festnetz)" value="${escapeHtml(w.telefon || '')}" placeholder="+49 30 1234567" style="width:100%"><md-icon slot="trailing-icon">call</md-icon></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field type="tel" id="f_w_mobil" label="Mobil" value="${escapeHtml(w.mobil || '')}" placeholder="+49 170 1234567" style="width:100%"><md-icon slot="trailing-icon">smartphone</md-icon></md-outlined-text-field></div>
                <div class="form-group" style="grid-column:1/-1"><md-outlined-text-field type="email" id="f_w_email" label="E-Mail" value="${escapeHtml(w.email || '')}" placeholder="name@beispiel.de" style="width:100%"><md-icon slot="trailing-icon">alternate_email</md-icon></md-outlined-text-field></div>
                <div class="form-group" style="grid-column:1/-1"><md-outlined-text-field id="f_w_strasse" label="Straße" value="${escapeHtml(w.strasse || '')}" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_w_plz" label="PLZ" value="${escapeHtml(w.plz || '')}" maxlength="10" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_w_ort" label="Ort" value="${escapeHtml(w.ort || '')}" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group">
                    <md-outlined-select id="f_w_land" label="Land" style="width:100%">
                        ${[
                            ['DE','Deutschland'],['AT','Österreich'],['CH','Schweiz'],
                            ['PL','Polen'],['CZ','Tschechien'],['NL','Niederlande'],
                            ['FR','Frankreich'],['IT','Italien'],['BE','Belgien'],
                            ['LU','Luxemburg'],['DK','Dänemark'],
                        ].map(([code,name]) => `<md-select-option value="${code}" ${(w.land || 'DE') === code ? 'selected' : ''}><div slot="headline">${name} (${code})</div></md-select-option>`).join('')}
                    </md-outlined-select>
                </div>
                <div class="form-group">
                    <md-outlined-select id="f_w_bundesland" label="Bundesland" style="width:100%">
                        <md-select-option value="" ${!w.bundesland ? 'selected' : ''}><div slot="headline">–</div></md-select-option>
                        ${[
                            'Baden-Württemberg','Bayern','Berlin','Brandenburg','Bremen',
                            'Hamburg','Hessen','Mecklenburg-Vorpommern','Niedersachsen',
                            'Nordrhein-Westfalen','Rheinland-Pfalz','Saarland',
                            'Sachsen','Sachsen-Anhalt','Schleswig-Holstein','Thüringen',
                        ].map(b => `<md-select-option value="${b}" ${w.bundesland === b ? 'selected' : ''}><div slot="headline">${b}</div></md-select-option>`).join('')}
                    </md-outlined-select>
                </div>
            </div>
        </div>

        <div class="wk-modal-pane" id="pane-ausweis">
            <div style="font-size:0.78rem;color:var(--wk-text-muted);margin-bottom:12px;line-height:1.5">
                ${wkIcon('shield', 'font-size:14px;vertical-align:-2px')} Sicherheitsrelevante Felder gemäß §34a GewO und BewachV — sichtbar für Bereiche mit „Bewachungsbereich“ (Konto &amp; Firma → Bereiche).
            </div>
            <div class="customer-form-section-title" style="margin:12px 0 12px">${wkIcon('shield')} Bewachung</div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <md-outlined-text-field id="f_w_bewreg" label="Bewacherregisternummer" supporting-text="BWR-Nr." value="${escapeHtml(w.bewacherregister_nr || '')}" maxlength="30" style="width:100%"></md-outlined-text-field>
                </div>
                <div class="form-group"><md-outlined-text-field id="f_w_funk" label="Funkrufname" value="${escapeHtml(w.funkrufname || '')}" maxlength="50" style="width:100%"></md-outlined-text-field></div>
            </div>
            <div id="wk_stadium_fields">
            <div class="customer-form-section-title" style="margin:18px 0 12px">${wkIcon('stadium')} Stadion- &amp; Veranstaltungsdienst</div>
            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group">
                    <md-outlined-text-field id="f_w_dfbpin" label="DFB-Pin" supporting-text="4-8 Zeichen (Ziffern/Buchstaben)" value="${escapeHtml(w.dfb_pin || '')}" maxlength="8" pattern="[A-Za-z0-9]{4,8}" style="width:100%"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <div style="display:flex;gap:6px;align-items:flex-start">
                        <md-outlined-text-field id="f_w_barcode" label="Barcode" supporting-text="Optional. 7 Zeichen empfohlen — muss eindeutig sein." value="${escapeHtml(w.barcode || '')}" maxlength="50" style="flex:1;font-family:ui-monospace,monospace;text-transform:uppercase"></md-outlined-text-field>
                        <md-outlined-button onclick="wkSuggestBarcode(this)" title="Eindeutigen 7-stelligen Barcode vorschlagen" data-perm="edit" style="white-space:nowrap;margin-top:8px"><md-icon slot="icon">casino</md-icon>Vorschlag</md-outlined-button>
                    </div>
                </div>
                <div class="form-group"><md-outlined-text-field id="f_w_ordner" label="Ordnernummer" value="${escapeHtml(w.ordnernummer || '')}" maxlength="30" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group" style="grid-column:1/-1">
                    <md-outlined-select id="f_w_gesperrt" label="Stadionverbot" supporting-text="Ja = für Stadion-/DFB-Einsätze gesperrt — rote Warnung im Profil und im Einsatz-Briefing" style="width:100%">
                        <md-select-option value="0"${w.stadion_gesperrt == 1 ? '' : ' selected'}><div slot="headline">Nein</div></md-select-option>
                        <md-select-option value="1"${w.stadion_gesperrt == 1 ? ' selected' : ''}><div slot="headline">Ja — gesperrt</div></md-select-option>
                    </md-outlined-select>
                </div>
            </div>
            </div>
        </div>

        <div class="wk-modal-pane" id="pane-steuer">
            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group"><md-outlined-text-field id="f_w_steuer" label="Steuer-ID (11 Ziffern)" value="${escapeHtml(w.steuer_id || '')}" maxlength="11" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_w_sv" label="SV-Nummer" value="${escapeHtml(w.sv_nummer || '')}" placeholder="12345678A123" style="width:100%"></md-outlined-text-field></div>
                <div class="form-group">
                    <md-outlined-select id="f_w_stklasse" label="Steuerklasse" style="width:100%">
                        <md-select-option value="" ${!w.steuerklasse ? 'selected' : ''}><div slot="headline">–</div></md-select-option>
                        ${[1,2,3,4,5,6].map(n => `<md-select-option value="${n}" ${w.steuerklasse == n ? 'selected' : ''}><div slot="headline">${n}</div></md-select-option>`).join('')}
                    </md-outlined-select>
                </div>
                <div class="form-group"><md-outlined-text-field id="f_w_kk" label="Krankenkasse" value="${escapeHtml(w.krankenkasse || '')}" style="width:100%"></md-outlined-text-field></div>
            </div>
        </div>

        <div class="wk-modal-pane" id="pane-bank">
            <div class="form-grid" style="grid-template-columns:1fr;gap:16px">
                <div class="form-group">
                    <md-outlined-text-field id="f_w_bank" label="IBAN" value="${escapeHtml(w.bank_verbindung || '')}" placeholder="DE12 3456 7890 1234 5678 90" style="width:100%;font-family:ui-monospace,monospace"></md-outlined-text-field>
                </div>
                <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:16px">
                    <div class="form-group">
                        <md-outlined-select id="f_w_aktiv" label="Aktiv" style="width:100%">
                            <md-select-option value="1" ${w.aktiv == 1 || !id ? 'selected' : ''}><div slot="headline">Ja</div></md-select-option>
                            <md-select-option value="0" ${w.aktiv == 0 ? 'selected' : ''}><div slot="headline">Nein</div></md-select-option>
                        </md-outlined-select>
                    </div>
                    <div class="form-group">
                        <md-outlined-select id="f_w_app" label="App-Zugang" style="width:100%">
                            <md-select-option value="1" ${(w.app_zugang == 1 || !id) ? 'selected' : ''}><div slot="headline">Ja</div></md-select-option>
                            <md-select-option value="0" ${w.app_zugang == 0 ? 'selected' : ''}><div slot="headline">Nein</div></md-select-option>
                        </md-outlined-select>
                    </div>
                </div>
                ${!id ? `
                    <div style="background:var(--wk-accent-soft);border:1px solid var(--wk-accent-line);border-radius:8px;padding:11px 14px;font-size:0.82rem;color:var(--wk-accent-hover)">
                        ${wkIcon('info', 'font-size:14px;vertical-align:-2px')}
                        Beim Anlegen wird ein zufälliges <strong>Initial-Passwort</strong> generiert und einmalig angezeigt.
                    </div>
                ` : ''}
            </div>
        </div>
    `;

    const footer = `
        <md-text-button onclick="closeWorkersDialog()">Abbrechen</md-text-button>
        <md-filled-button onclick="saveWorker('${id || ''}')" style="--md-filled-button-container-color:var(--wk-accent)">${id ? 'Aktualisieren' : 'Erstellen'}</md-filled-button>
    `;
    openWorkersDialog(id ? 'Mitarbeiter bearbeiten — ' + id : 'Neuen Mitarbeiter anlegen', body, footer, true);
    // md-tabs drives the panes: sync on user tab change.
    const tabsEl = document.getElementById('wkModalTabs');
    tabsEl?.addEventListener('change', () => {
        const t = tabsEl.activeTab;
        if (t) wkSwitchModalPane(t.dataset.pane);
    });
    // Multi-service tags — the checkbox group is filled async from the
    // tenant's Bereich catalog.
    if (typeof renderBereicheChecks === 'function') {
        Promise.resolve(renderBereicheChecks('f_w_bereiche', w.bereiche || (w.bereich ? [w.bereich] : []), w.bereich || null))
            .then(async () => { await wkRenderQualFields(w); wkUpdateSicherheitTabVisibility(); });
    }
    // The Sicherheits-Tab is opt-in (Bereich switches in Konto & Firma).
    // The checkboxes arrive async — until then the stored tags decide.
    _wkModalWorker = w;
    wkUpdateSicherheitTabVisibility((typeof bereichTagsOf === 'function') ? bereichTagsOf(w) : [w.bereich]);
}

/* ── Qualifikationen (lists maintained per Hauptbereich in Konto & Firma) ──
   Every chosen Dienstleistung reveals its lists (e.g. Sicherheit → „§34a“)
   as multi-selects. The worker stores option IDS; selections of a Bereich
   that is unticked stay stored (hidden) so nothing is lost by accident. */
const _wkQual = { ready: false, mains: [], selected: new Set() };

async function wkLoadQualCatalog() {
    try {
        const d = await apiGet(API.bereich_catalog);
        return (d && Array.isArray(d.mains)) ? d.mains : [];
    } catch (e) { return []; }
}

function _wkParseIds(v) {
    let a = v;
    if (typeof a === 'string') { try { a = JSON.parse(a); } catch { a = []; } }
    return Array.isArray(a) ? a.map(Number).filter(n => n > 0) : [];
}

// Lists shown in the form right now: [{main, list, opts:[{id,name,aktiv}]}]
function _wkVisibleQualLists() {
    const chosen = new Set((typeof collectBereicheChecks === 'function' ? collectBereicheChecks('f_w_bereiche') : [])
        .map(s => String(s).trim().toLowerCase()));
    const out = [];
    for (const m of _wkQual.mains) {
        if (!chosen.has(String(m.name).trim().toLowerCase())) continue;
        for (const l of (m.quals || [])) {
            const opts = (l.options || []).filter(o => o.aktiv || _wkQual.selected.has(o.id));
            if ((l.aktiv || opts.some(o => _wkQual.selected.has(o.id))) && opts.length) out.push({ main: m, list: l, opts });
        }
    }
    return out;
}

// Read the rendered multi-selects back into _wkQual.selected.
function _wkSyncQualSelection() {
    document.querySelectorAll('#f_w_quals_box [data-qual-list]').forEach(box => {
        const listId = Number(box.dataset.qualList);
        const main = _wkQual.mains.find(m => (m.quals || []).some(l => l.id === listId));
        const list = main && main.quals.find(l => l.id === listId);
        if (!list) return;
        const checked = new Set(collectBereicheChecks(box.id).map(s => s.toLowerCase()));
        for (const o of list.options || []) {
            checked.has(String(o.name).toLowerCase()) ? _wkQual.selected.add(o.id) : _wkQual.selected.delete(o.id);
        }
    });
}

async function wkRenderQualFields(w) {
    const box = document.getElementById('f_w_quals_box');
    if (!box) return;
    if (w) {
        _wkQual.ready = false;
        _wkQual.selected = new Set(_wkParseIds(w.qualifikationen));
        _wkQual.mains = await wkLoadQualCatalog();
        _wkQual.ready = true;
    } else {
        if (!_wkQual.ready) return;
        _wkSyncQualSelection();
    }
    const lists = _wkVisibleQualLists();
    box.style.display = lists.length ? '' : 'none';
    if (!lists.length) { box.innerHTML = ''; return; }
    box.innerHTML = `
        <label style="display:block;font-size:0.8rem;color:var(--gray-600);margin-bottom:6px">Qualifikationen &amp; Nachweise</label>
        <div class="wk-qual-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
            ${lists.map(({ main, list }) => `
            <div>
                <div style="display:flex;align-items:center;gap:6px;font-size:0.78rem;font-weight:600;color:var(--wk-text);margin-bottom:4px">
                    ${typeof bereichDotHtml === 'function' ? bereichDotHtml(main.name, false) : ''}${escapeHtml(list.name)}
                    <span style="font-weight:400;color:var(--wk-text-muted)">· ${escapeHtml(main.name)}</span>
                </div>
                <div id="f_w_q_${list.id}" data-qual-list="${list.id}"></div>
            </div>`).join('')}
        </div>`;
    for (const { list, opts } of lists) {
        const selectedNames = opts.filter(o => _wkQual.selected.has(o.id)).map(o => o.name);
        _mselBuild('f_w_q_' + list.id, opts.map(o => o.name), selectedNames, 'Auswählen…', 'Keine Einträge');
    }
}

function wkCollectQuals() {
    _wkSyncQualSelection();
    return [..._wkQual.selected];
}

/**
 * Which security fields a worker gets — from the Hauptbereich switches in
 * Konto & Firma → Bereiche ("Bewachungsbereich", "Stadion-/Veranstaltungs-
 * dienst"). Catalog without switches (not migrated / no catalog) → the old
 * name rule "Sicherheit". Data already stored keeps its fields visible, so
 * nothing disappears (e.g. a Bereich that is not in the catalog).
 */
function wkSecMode(tags, mains, w) {
    const set = new Set((tags || []).filter(Boolean).map(t => String(t).trim().toLowerCase()));
    let bew = false, std = false;
    if ((mains || []).some(m => 'bewachung' in m)) {
        const known = new Set(mains.map(m => String(m.name).trim().toLowerCase()));
        for (const m of mains) {
            if (!set.has(String(m.name).trim().toLowerCase())) continue;
            if (m.bewachung) bew = true;
            if (m.stadiondienst) { bew = true; std = true; }
        }
        // Legacy tags outside the catalog ("Verwaltung & Sicherheit") keep the old name rule.
        if ([...set].some(t => !known.has(t) && t.includes('sicherheit'))) bew = std = true;
    } else {
        bew = std = [...set].some(t => t.includes('sicherheit'));
    }
    if (w) {
        if (w.dfb_pin || w.ordnernummer || w.barcode || w.stadion_gesperrt == 1) { bew = true; std = true; }
        if (w.bewacherregister_nr || w.funkrufname) bew = true;
    }
    return { bew, std };
}

// Profile → Stammdaten: "§34a: Sachkunde, Unterrichtung" per list.
let _wkQualCatalogCache = null;
function wkQualViewHtml(w) {
    const ids = _wkParseIds(w.qualifikationen);
    if (!ids.length) return '';
    // Each chosen option as a chip, grouped per list ("§34a · Sicherheit").
    const render = (mains) => {
        const rows = [];
        for (const m of mains) for (const l of (m.quals || [])) {
            const names = (l.options || []).filter(o => ids.includes(o.id)).map(o => o.name);
            if (names.length) rows.push([`${l.name} · ${m.name}`, names.map(n => `<span class="wk-sd-chip">${escapeHtml(n)}</span>`).join(''), { wide: true, info: true }]);
        }
        return rows.length ? wkSdSection('workspace_premium', 'Qualifikationen & Nachweise', rows) : '';
    };
    const slot = 'wkQualView_' + Math.random().toString(36).slice(2);
    const fill = (mains) => { const el = document.getElementById(slot); if (el) el.outerHTML = render(mains); };
    if (_wkQualCatalogCache) return render(_wkQualCatalogCache);
    wkLoadQualCatalog().then(m => { _wkQualCatalogCache = m; setTimeout(() => fill(m), 0); });
    return `<section id="${slot}" class="wk-sd-sec"><header class="wk-sd-head"><span class="wk-sd-ic">${wkIcon('workspace_premium')}</span><h4 class="wk-sd-title">Qualifikationen &amp; Nachweise</h4></header><div class="wk-sd-fields"><div class="wk-sd-field is-wide is-empty"><span class="wk-sd-value">Lade…</span></div></div></section>`;
}
if (window.HMSRealtime && typeof HMSRealtime.on === 'function') {
    HMSRealtime.on('bereiche', () => { _wkQualCatalogCache = null; });
}

function wkSwitchModalPane(pane) {
    document.querySelectorAll('.wk-modal-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + pane));
}

/**
 * Austritt ↔ Status coupling: entering an exit date flips the Aktiv
 * select to „Nein“ immediately (and clearing it flips back to „Ja“),
 * so Dispo never has to remember the second field. saveWorker() also
 * enforces this server-bound, in case the select is touched afterwards.
 */
function wkAustrittChanged() {
    const austritt = document.getElementById('f_w_austritt')?.value;
    const aktivSel = document.getElementById('f_w_aktiv');
    if (!aktivSel) return;
    if (austritt) {
        if (aktivSel.value !== '0') {
            aktivSel.value = '0';
            showToast('Austritt gesetzt — Status automatisch auf Inaktiv gestellt', 'info');
        }
    } else if (aktivSel.value === '0') {
        aktivSel.value = '1';
        showToast('Austritt entfernt — Status wieder Aktiv', 'info');
    }
}

/**
 * Show / hide the Gehalt-/Lohn-Betrag input depending on the Lohn-Typ
 * select. When „Gehalt" is chosen the field is interpreted as a monthly
 * salary (€/Monat); when „Lohn" is chosen it's an hourly wage (€/Std).
 * The numeric value lives in the existing `stundenlohn` column either way.
 */
function wkUpdateLohnInput() {
    const sel  = document.getElementById('f_w_lohn_typ');
    const box  = document.getElementById('f_w_lohn_box');
    const inp  = document.getElementById('f_w_stundenlohn');
    if (!sel || !box) return;
    const v = sel.value;
    box.style.display = v ? 'block' : 'none';
    if (v === 'gehalt') {
        if (inp) { inp.label = 'Gehalt (€/Monat)'; inp.placeholder = 'z. B. 2.800,00'; }
    } else if (v === 'lohn') {
        if (inp) { inp.label = 'Lohn (€/Std)'; inp.placeholder = 'z. B. 15,00'; }
    } else if (inp) {
        inp.value = '';
    }
}

/**
 * Show / hide the „Sicherheit" tab depending on the Bereich field.
 *
 * Stadium- and event-security companies care about §34a GewO data;
 * cleaning / building-services companies don't. We keep the pane in
 * the DOM so any pre-existing security-guard data is still posted on
 * save — only the tab button is hidden.
 */
let _wkModalWorker = null;
function wkUpdateSicherheitTabVisibility(fallbackTags) {
    const tab = document.getElementById('wk_tab_sicherheit');
    if (!tab) return;
    const rendered = !!document.querySelector('#f_w_bereiche input[type=checkbox], #f_w_bereiche md-checkbox');
    const tags = rendered && typeof collectBereicheChecks === 'function'
        ? collectBereicheChecks('f_w_bereiche') : (fallbackTags || []);
    const sec = wkSecMode(tags, _wkQual.mains.length ? _wkQual.mains : (_wkQualCatalogCache || []), _wkModalWorker);
    const isSecurity = sec.bew;
    tab.style.display = isSecurity ? '' : 'none';
    const st = document.getElementById('wk_stadium_fields');
    if (st) st.style.display = sec.std ? '' : 'none';
    if (!isSecurity && tab.active) {
        const tabsEl = document.getElementById('wkModalTabs');
        const fallback = tabsEl?.querySelector('[data-pane="persoenlich"]');
        if (tabsEl && fallback) { tabsEl.activeTab = fallback; wkSwitchModalPane('persoenlich'); }
    }
}

async function saveWorker(id) {
    // Pull a value off an optional input (returns null when empty/missing).
    // Used so the modal can be safely opened on tenants that haven't yet
    // applied the 2026_05_19 security-guard migration.
    const v = (elId) => {
        const el = document.getElementById(elId);
        if (!el) return null;
        const val = String(el.value ?? '').trim();
        return val === '' ? null : val;
    };

    const data = {
        // ---- Persönlich ----
        name:                   document.getElementById('f_w_name').value,
        vorname:                document.getElementById('f_w_vorname').value,
        anrede:                 v('f_w_anrede'),
        geschlecht:             v('f_w_geschlecht'),
        geburtstag:             v('f_w_geburtstag'),
        geburtsort:             v('f_w_geburtsort'),
        geburtsname:            v('f_w_geburtsname'),
        geburtsland:            v('f_w_geburtsland'),
        staatsangehoerigkeit:   v('f_w_staat'),
        familienstand:          v('f_w_famstand'),
        kinderzahl_freibetrag:  v('f_w_kinder'),

        // ---- Kontakt + Adresse ----
        telefon:                v('f_w_telefon'),
        mobil:                  v('f_w_mobil'),
        email:                  v('f_w_email'),
        strasse:                v('f_w_strasse'),
        plz:                    v('f_w_plz'),
        ort:                    v('f_w_ort'),
        land:                   v('f_w_land'),
        bundesland:             v('f_w_bundesland'),

        // ---- Ausweis & Sicherheit ----
        personalausweis_nr:          v('f_w_pa_nr'),
        personalausweis_gueltig_ab:  v('f_w_pa_ab'),
        personalausweis_gueltig_bis: v('f_w_pa_bis'),
        bewacherregister_nr:    v('f_w_bewreg'),
        dfb_pin:                v('f_w_dfbpin'),
        stadion_gesperrt:       v('f_w_gesperrt') || '0',
        funkrufname:            v('f_w_funk'),
        barcode:                v('f_w_barcode'),
        ordnernummer:           v('f_w_ordner'),

        // ---- Beschäftigung (now part of the merged „Persönlich“ tab) ----
        bereich:                (typeof mselPrimary === 'function') ? mselPrimary('f_w_bereiche') : null,
        bereiche:               (typeof collectBereicheChecks === 'function') ? collectBereicheChecks('f_w_bereiche') : [],
        eintritt:               v('f_w_eintritt'),
        austritt:               v('f_w_austritt'),
        art_der_beschaeftigung: v('f_w_beschaeftigung'),
        urlaubstage:            v('f_w_urlaub'),
        arbeitsstunden_typ:     v('f_w_std_typ'),
        arbeitsstunden:         v('f_w_std'),
        stundenlohn:            v('f_w_stundenlohn'),
        lohn_typ:               v('f_w_lohn_typ'),

        // ---- Steuer & Bank ----
        steuer_id:              v('f_w_steuer'),
        sv_nummer:              v('f_w_sv'),
        krankenkasse:           v('f_w_kk'),
        bank_verbindung:        v('f_w_bank'),
        steuerklasse:           v('f_w_stklasse'),

        // ---- Status ----
        aktiv:                  document.getElementById('f_w_aktiv').value,
        app_zugang:             document.getElementById('f_w_app').value,
    };
    // Qualifikationen — only once the catalog loaded, never clear by accident.
    if (_wkQual.ready) data.qualifikationen = wkCollectQuals();

    // An Austritt date always means the worker is inactive — enforce it
    // regardless of what the Aktiv select currently shows.
    if (data.austritt) data.aktiv = '0';

    // Client-side quick checks
    if (!data.name?.trim() || !data.vorname?.trim()) {
        showToast('Vor- und Nachname sind erforderlich', 'error');
        const tabsEl = document.getElementById('wkModalTabs');
        const first = tabsEl?.querySelector('[data-pane="persoenlich"]');
        if (tabsEl && first) { tabsEl.activeTab = first; wkSwitchModalPane('persoenlich'); }
        return;
    }

    let result;
    if (id) {
        result = await apiPut(WK_API.update(id), data);
    } else {
        result = await apiPost(WK_API.create, data);
    }

    if (!result || result.error) {
        showToast(result?.error || 'Fehler beim Speichern', 'error');
        return;
    }

    closeWorkersDialog();

    if (typeof invalidateBereichCache === 'function') invalidateBereichCache();
    if (!id && result.initial_password) {
        // Show generated password ONCE
        wkShowInitialPassword(result.dienst_nr, result.initial_password);
    } else {
        showToast(id ? 'Aktualisiert' : 'Erstellt');
    }

    if (id && document.querySelector('.wk-profile')) {
        wkOpenProfile(id);
    } else {
        renderWorkers();
    }
}

function wkShowInitialPassword(dienstNr, password) {
    const body = `
        <div class="wk-pw-show-card">
            <div class="wk-pw-show-label">Initial-Passwort für ${escapeHtml(dienstNr)}</div>
            <div class="wk-pw-show-value" id="wkInitPw" onclick="wkCopyInitialPw()">${escapeHtml(password)}</div>
            <div class="wk-pw-show-hint">${wkIcon('warning', 'font-size:14px;vertical-align:-2px')} Bitte kopieren — wird nicht erneut angezeigt!</div>
        </div>
        <div style="background:var(--wk-bg-soft);border:1px solid var(--wk-border);border-radius:8px;padding:12px;font-size:0.83rem;color:var(--wk-text);line-height:1.5">
            <strong>Login-Daten für die Mitarbeiter-App:</strong><br>
            <span style="color:var(--wk-text-muted)">Dienst-Nr.:</span> <code style="background: var(--white, var(--md-sys-color-surface-container-lowest));padding:1px 6px;border-radius:4px;border:1px solid var(--wk-border)">${escapeHtml(dienstNr)}</code><br>
            <span style="color:var(--wk-text-muted)">Passwort:</span> <code style="background: var(--white, var(--md-sys-color-surface-container-lowest));padding:1px 6px;border-radius:4px;border:1px solid var(--wk-border)">${escapeHtml(password)}</code>
        </div>
    `;
    const footer = `
        <md-outlined-button onclick="wkCopyInitialPw()"><md-icon slot="icon">content_copy</md-icon>Kopieren</md-outlined-button>
        <md-filled-button onclick="closeWorkersDialog()" style="--md-filled-button-container-color:var(--wk-accent)">Verstanden</md-filled-button>
    `;
    openWorkersDialog('Mitarbeiter erstellt — Initial-Passwort', body, footer);
}

function wkCopyInitialPw() {
    const el = document.getElementById('wkInitPw');
    if (!el) return;
    const text = el.textContent;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => showToast('Passwort kopiert', 'success'));
    } else {
        const r = document.createRange();
        r.selectNode(el);
        const sel = window.getSelection();
        sel.removeAllRanges(); sel.addRange(r);
        document.execCommand('copy');
        sel.removeAllRanges();
        showToast('Passwort kopiert', 'success');
    }
}

// ============================================================
// Lifecycle: Deactivate / Reactivate / Delete
// ============================================================
async function deactivateWorker(id) {
    if (!await mdConfirm('Mitarbeiter deaktivieren?\n(Austrittsdatum wird auf heute gesetzt, falls leer)', { title: 'Deaktivieren', confirmLabel: 'Deaktivieren' })) return;
    const result = await apiDelete(WK_API.delete(id));
    if (result.success) {
        const lg = result.lager_offen || 0;
        if (lg > 0) showToast(`Achtung: ${lg} Ausrüstungsteil${lg === 1 ? '' : 'e'} noch ausgegeben — bitte Rückgabe im Lager erfassen.`, 'warning');
        const n = result.future_shifts || 0;
        if (n > 0) showToast(`Deaktiviert — noch in ${n} kommende${n === 1 ? 'm' : 'n'} ${n === 1 ? 'Einsatz' : 'Einsätzen'} eingeplant. Bitte in der Disposition umplanen.`, 'warning');
        else showToast('Deaktiviert');
        if (document.querySelector('.wk-profile')) wkOpenProfile(id);
        else renderWorkers();
    } else showToast(result.error || 'Fehler', 'error');
}

async function reactivateWorker(id) {
    const result = await apiPut(WK_API.update(id), { aktiv: 1, austritt: null });
    if (result.success) {
        showToast('Reaktiviert');
        if (document.querySelector('.wk-profile')) wkOpenProfile(id);
        else renderWorkers();
    } else showToast(result.error || 'Fehler', 'error');
}

async function deleteWorker(id) {
    if (!await mdConfirm('„' + id + '“ endgültig löschen?\n\nAlle Dokumente, Abwesenheiten und Fotos werden unwiderruflich gelöscht!', { title: 'Endgültig löschen', confirmLabel: 'Löschen' })) return;
    const result = await apiDelete(WK_API.delete(id), { permanent: true });
    if (result.success) {
        showToast('Endgültig gelöscht');
        renderWorkers();
    } else showToast(result.error || 'Fehler', 'error');
}

// ============================================================
// Reset Password modal
// ============================================================
function wkResetPasswordModal(dienstNr) {
    const body = `
        <div style="background:var(--wk-warn-soft);border: 1px solid var(--md-ext-color-warning-container);border-left:3px solid var(--wk-warn);border-radius:8px;padding:11px 14px;font-size:0.82rem;color: var(--md-ext-color-on-warning-container);margin-bottom:18px">
            ${wkIcon('shield', 'font-size:14px;vertical-align:-2px')}
            <strong>Hinweis:</strong> Aus Sicherheitsgründen wird das aktuelle Passwort nicht angezeigt. Setzen Sie ein neues Passwort.
        </div>
        <div class="form-grid" style="grid-template-columns:1fr;gap:18px">
            <div class="form-group" style="display:flex;gap:6px;align-items:center">
                <md-outlined-text-field type="password" id="wkNewPassword" label="Neues Passwort *" placeholder="Mind. 6 Zeichen" autocomplete="new-password" style="flex:1">
                    <md-icon-button toggle slot="trailing-icon" onclick="wkTogglePwVisibility('wkNewPassword',this)" title="Anzeigen"><md-icon>visibility</md-icon><md-icon slot="selected">visibility_off</md-icon></md-icon-button>
                </md-outlined-text-field>
                <md-icon-button onclick="wkGenRandomPw()" title="Zufällig generieren"><md-icon>casino</md-icon></md-icon-button>
            </div>
            <div class="form-group">
                <md-outlined-text-field type="password" id="wkConfirmPassword" label="Passwort bestätigen *" placeholder="Wiederholen" autocomplete="new-password" style="width:100%">
                    <md-icon-button toggle slot="trailing-icon" onclick="wkTogglePwVisibility('wkConfirmPassword',this)" title="Anzeigen"><md-icon>visibility</md-icon><md-icon slot="selected">visibility_off</md-icon></md-icon-button>
                </md-outlined-text-field>
            </div>
        </div>
    `;
    const footer = `
        <md-text-button onclick="closeWorkersDialog()">Abbrechen</md-text-button>
        <md-filled-button onclick="wkSavePassword('${dienstNr}')" style="--md-filled-button-container-color:var(--wk-accent)"><md-icon slot="icon">key</md-icon>Passwort setzen</md-filled-button>
    `;
    openWorkersDialog('Passwort ändern — ' + dienstNr, body, footer);
}

function wkGenRandomPw() {
    // 10-char: 2 upper, 4 lower, 2 digits, 2 symbols
    const u = 'ABCDEFGHJKLMNPQRSTUVWXYZ', l = 'abcdefghjkmnpqrstuvwxyz', d = '23456789', s = '!@#$%&*';
    const pick = (chars, n) => Array.from({length: n}, () => chars[Math.floor(Math.random() * chars.length)]).join('');
    const pw = (pick(u, 2) + pick(l, 4) + pick(d, 2) + pick(s, 2)).split('').sort(() => Math.random() - 0.5).join('');
    document.getElementById('wkNewPassword').value = pw;
    document.getElementById('wkConfirmPassword').value = pw;
    document.getElementById('wkNewPassword').type = 'text';
    document.getElementById('wkConfirmPassword').type = 'text';
    showToast('Zufälliges Passwort generiert', 'success');
}

function wkTogglePwVisibility(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
}

async function wkSavePassword(dienstNr) {
    const pw = document.getElementById('wkNewPassword').value;
    const pw2 = document.getElementById('wkConfirmPassword').value;
    if (!pw || pw.length < 6) return showToast('Min. 6 Zeichen', 'error');
    if (pw !== pw2) return showToast('Passwörter stimmen nicht überein', 'error');
    const result = await apiPost(WK_API.resetPassword, { dienst_nr: dienstNr, password: pw });
    if (result?.success) {
        closeWorkersDialog();
        showToast('Passwort wurde geändert');
    } else showToast(result?.error || 'Fehler', 'error');
}

// ============================================================
// CSV Export
// ============================================================
function wkExportCsv() {
    const url = WK_API.export;
    const token = (typeof getAuthToken === 'function') ? getAuthToken() : null;
    if (!token) {
        window.location.href = url;
        return;
    }
    fetch(url, { headers: { 'Authorization': 'Bearer ' + token } })
        .then(r => r.blob())
        .then(blob => {
            const a = document.createElement('a');
            const objUrl = URL.createObjectURL(blob);
            a.href = objUrl;
            a.download = 'mitarbeiter_' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(objUrl);
            showToast('Export gestartet', 'success');
        })
        .catch(() => showToast('Export fehlgeschlagen', 'error'));
}

// ============================================================
// Legacy wrappers (called from app.js / nav)
// ============================================================
function openWorkerDocs(dienstNr) {
    wkOpenProfile(dienstNr);
    setTimeout(() => {
        const tab = document.querySelector('.wk-tab[data-tab="dokumente"]');
        if (tab) wkSwitchTab(tab);
    }, 250);
}

function openWorkerAbsences(dienstNr) {
    wkOpenProfile(dienstNr);
    setTimeout(() => {
        const tab = document.querySelector('.wk-tab[data-tab="abwesenheiten"]');
        if (tab) wkSwitchTab(tab);
    }, 250);
}

function uploadWorkerPhoto(dienstNr) { wkUploadPhoto(dienstNr); }
