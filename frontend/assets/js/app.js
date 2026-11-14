/* ============================================
   HMS Gruppe GmbH & Co. KG — Main Application
   ============================================ */

const API_BASE = window.location.origin + '/api';
const API = {
    customers: API_BASE + '/customers',
    objects: API_BASE + '/objects',
    subcontractors: API_BASE + '/subcontractors',
    incoming_orders: API_BASE + '/incoming-orders',
    order_confirmations: API_BASE + '/order-confirmations',
    orders: API_BASE + '/orders',
    tasks: API_BASE + '/tasks',
    price_history: API_BASE + '/price-history',
    reports: API_BASE + '/reports',
    disposition: API_BASE + '/disposition',
    users: API_BASE + '/users',
    auth: API_BASE + '/auth',
    messages: API_BASE + '/messages',
    workers: API_BASE + '/workers',
    worker_documents: API_BASE + '/worker-documents',
    worker_absences: API_BASE + '/worker-absences',
    customer_documents: API_BASE + '/customer-documents',
    nu_documents: API_BASE + '/nu-documents',
    worker_messages: API_BASE + '/worker-messages',
    bereiche: API_BASE + '/customers/bereiche',
    bereich_catalog: API_BASE + '/bereich-catalog',
    interessenten: API_BASE + '/crm',
    lager: API_BASE + '/lager',
    export_xlsx: API_BASE + '/export/xlsx'
};

/**
 * Download a table as a real .xlsx workbook via POST /api/export/xlsx.
 * Sends the already-rendered (filtered/sorted) rows, so the file matches
 * what the user sees on screen. Numbers should be passed as numbers —
 * the workbook stores them typed, so every Excel/Numbers locale displays
 * and sums them correctly (unlike locale-fragile CSV).
 */
async function downloadXlsx(filename, title, headers, rows, sheets, layout) {
    const body = { filename, title, headers, rows };
    if (Array.isArray(sheets) && sheets.length) body.sheets = sheets; // optional extra sheets [{title, headers, rows, bold_rows, layout}]
    if (layout) body.layout = layout; // optional presentation of the first sheet (see XlsxTable::fill)
    const res = await fetch(API.export_xlsx, {
        method: 'POST',
        headers: getAuthHeaders(),
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        let msg = 'Export fehlgeschlagen';
        try { const j = await res.json(); if (j && j.error) msg = j.error; } catch (_) {}
        throw new Error(msg);
    }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
}

function getAuthToken() { return localStorage.getItem('hms_token'); }
function setAuthToken(token) { localStorage.setItem('hms_token', token); }
function clearAuthToken() { localStorage.removeItem('hms_token'); localStorage.removeItem('hms_user'); localStorage.removeItem('hms_tenant'); }
function getAuthHeaders() {
    const token = getAuthToken();
    const h = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (token) h['Authorization'] = 'Bearer ' + token;
    return h;
}
function getAuthHeadersNoContent() {
    const token = getAuthToken();
    const h = { 'Accept': 'application/json' };
    if (token) h['Authorization'] = 'Bearer ' + token;
    return h;
}

const MONTHS_DE = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
const DAYS_DE = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

// ============================================
// 24h TIME MASK — native type="time" follows the
// OS locale (shows AM/PM on 12h systems). Instead
// use text inputs with class "t24": digits only,
// auto-colon, normalized to HH:MM on blur.
// Works via delegation so dynamically rendered
// modals and md-outlined-text-field are covered.
// ============================================
document.addEventListener('input', function (e) {
    const el = e.target;
    if (!(el instanceof HTMLElement) || !el.classList || !el.classList.contains('t24')) return;
    const d = String(el.value || '').replace(/\D/g, '').slice(0, 4);
    const del = e.inputType && e.inputType.indexOf('delete') === 0;
    let out;
    if (d.length > 2) out = d.slice(0, 2) + ':' + d.slice(2);
    else if (d.length === 2 && !del) out = d + ':';
    else out = d;
    if (out !== el.value) el.value = out;
}, true);
document.addEventListener('focusout', function (e) {
    const el = e.target;
    if (!(el instanceof HTMLElement) || !el.classList || !el.classList.contains('t24')) return;
    const v = String(el.value || '').trim();
    if (!v) return;
    const m = v.match(/^(\d{1,2}):?(\d{0,2})$/);
    if (!m) { el.value = ''; return; }
    const h = Math.min(23, parseInt(m[1], 10) || 0);
    const mi = Math.min(59, parseInt(m[2] || '0', 10) || 0);
    el.value = String(h).padStart(2, '0') + ':' + String(mi).padStart(2, '0');
}, true);

let currentPage = 'dashboard';
let cachedData = {};
let _pageLoadId = 0;
let _currentAbortController = null;
let _apiErrorCount = 0;

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', () => {
    initNavigation();
    initModal();
    setCurrentDate();
    // Keep --topbar-height in sync with the real topbar height: on tablets
    // the topbar wraps to two rows, and sticky day/month headers (Dispo)
    // offset from this variable — a stale 60px would leave a gap or overlap.
    // IMPORTANT: set it on .content, NOT on <html>. The app-shell CSS sizes
    // the topbar itself from --topbar-height (+ safe-area inset); writing
    // the measured value to the root would feed back into that calc and
    // grow the topbar until it covers the whole screen (white screen bug
    // in the Mitarbeiter-App admin SSO).
    const topbar = document.querySelector('.topbar');
    const contentEl = document.querySelector('.content');
    if (topbar && contentEl && 'ResizeObserver' in window) {
        const sync = () => contentEl.style.setProperty('--topbar-height', topbar.offsetHeight + 'px');
        new ResizeObserver(sync).observe(topbar);
        sync();
    }
    // Debounce initial load — prevents wasted API calls during rapid F5/reload
    setTimeout(() => {
        const initialPage = window.location.hash.replace('#', '') || 'dashboard';
        loadPage(initialPage);
        updateIncomingBadge();
        initChat();
        updateWmBadge();
    }, 150);

    // Browser / Android hardware BACK: loadPage() pushes `#page` into the
    // history but nothing ever listened for the hash coming back, so Back
    // changed the URL and rendered nothing (web AND app WebView). Now:
    //  1. an open dialog / mobile sidebar is closed first (Back = "close"),
    //  2. otherwise the page in the hash is rendered.
    window.addEventListener('hashchange', () => {
        const openDlg = document.querySelector('md-dialog[open]');
        const sidebar = document.getElementById('sidebar');
        if (openDlg || (sidebar && sidebar.classList.contains('open'))) {
            if (openDlg && typeof openDlg.close === 'function') openDlg.close();
            if (sidebar) sidebar.classList.remove('open');
            const ov = document.getElementById('sidebarOverlay');
            if (ov) ov.classList.remove('active');
            // Stay on the current page: re-push its hash so the next Back
            // navigates pages instead of skipping one.
            if (currentPage && window.location.hash.replace('#', '').split(/[?&]/)[0] !== currentPage) {
                window.location.hash = currentPage;
            }
            return;
        }
        const full = window.location.hash.replace('#', '');
        const page = full.split(/[?&]/)[0];
        if (!page || page === 'app_sso') return;
        if (page === currentPage) {
            // Same page, new deep-link params (QM notifications).
            const params = full.includes('?') ? new URLSearchParams(full.slice(full.indexOf('?') + 1)) : null;
            if (params && typeof window.qmOnPageParams === 'function' && page.startsWith('qm')) window.qmOnPageParams(page, params);
            if (params && page === 'einstellungen' && typeof window.estOnPageParams === 'function') window.estOnPageParams(params);
            return;
        }
        loadPage(full);
    });
});

function setCurrentDate() {
    const now = new Date();
    document.getElementById('currentDate').textContent = now.toLocaleDateString('de-DE', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
    });
}

// ============================================
// NAVIGATION + PERMISSION GATING
// ============================================
/**
 * Map sidebar pages → permission key required to OPEN them.
 * A page without a key is always visible (e.g. dashboard fallback).
 * If a user lacks the permission, the nav-item is hidden and direct
 * navigation falls back to dashboard.
 *
 * Server still enforces every endpoint independently — this is just UX.
 */
const PAGE_PERMS = {
    dashboard:       'dashboard.view',
    interessenten:   'stammdaten.interessenten.view|stammdaten.interessenten.uebergabe',
    customers:       'stammdaten.kunden.view',
    objects:         'stammdaten.objekte.view',
    subcontractors:  'stammdaten.nu.view',
    price_history:   'stammdaten.preise.view',
    lager:           'stammdaten.lager.view',
    workers:         'stammdaten.mitarbeiter.view',
    absences:        'stammdaten.mitarbeiter.view',
    aueg:            'stammdaten.mitarbeiter.view',
    incoming_orders: 'auftraege.eingang.view',
    auftragsbestaetigung: 'auftraege.bestaetigung.view',
    tasks:           'auftraege.dispo.view',
    disposition:     'auftraege.dispo.view',
    monatsabschluss: 'auftraege.monatsabschluss.view',
    orders:          'auftraege.bestellungen.view',
    worker_messages: 'auftraege.nachrichten.view',
    reports:         'controlling.view',
    users:           'users.manage',
    pdf_design:      'system.dokument_design.manage',
    einstellungen:   'system.dokument_design.manage|stammdaten.interessenten.admin|qm.view',
    qm_wachbuch:     'qm.view',
    qm:              'qm.view',
    // company_documents ("Konto & Firma") has NO page permission: the
    // profile tab is for every user. Admin-only tabs (Unternehmensdaten,
    // Rechtliches, Bank, Dokumente) are gated inside the module via
    // hasPermission('users.manage') — same as the backend routes.
};

/**
 * Map each sidebar page → the permission key required to MUTATE its data
 * (POST/PUT/DELETE). Used by `canEdit(page)` + `applyEditGating(page)` to
 * hide "Neu / Bearbeiten / Löschen" controls for viewer-only users.
 *
 * The backend enforces the same matrix in routes/api.php — this map is
 * purely UX so view-only users don't see buttons that would 403 on click.
 * Pages without an entry are either read-only by nature (reports) or
 * already self-gated (users.js checks users.manage internally).
 */
const PAGE_EDIT_PERMS = {
    interessenten:   'stammdaten.interessenten.edit',
    customers:       'stammdaten.kunden.edit',
    objects:         'stammdaten.objekte.edit',
    subcontractors:  'stammdaten.nu.edit',
    workers:         'stammdaten.mitarbeiter.edit',
    absences:        'stammdaten.mitarbeiter.edit',
    incoming_orders: 'auftraege.eingang.edit',
    auftragsbestaetigung: 'auftraege.bestaetigung.edit',
    tasks:           'auftraege.dispo.edit',
    disposition:     'auftraege.dispo.edit',
    monatsabschluss: 'auftraege.monatsabschluss.edit',
    orders:          'auftraege.bestellungen.edit',
    worker_messages: 'auftraege.nachrichten.send',
    // price_history has no dedicated edit permission — gated by objekte.edit
    price_history:   'stammdaten.objekte.edit',
    lager:           'stammdaten.lager.edit',
};

/** Returns true if the logged-in user holds the given permission key.
 *
 *  Owner and tenant_admin always resolve to TRUE here even when their
 *  `permissions` array is empty/missing — the backend grants them
 *  everything implicitly, and we mirror that on the client so a stale
 *  or partial localStorage payload (e.g. from registration responses
 *  predating the permissions backfill) doesn't lock the company owner
 *  out of their own dashboard.
 */
function hasPermission(key) {
    if (!key) return true;
    const u = window.CURRENT_USER;
    if (!u) return false;
    if (u.is_owner === true || u.is_owner === 1) return true;
    if (u.role === 'tenant_admin' || u.role === 'super_admin' || u.role === 'admin') return true;
    // "a|b" = any of the listed keys — same OR syntax the backend's
    // EnsurePermission middleware accepts (e.g. view OR uebergabe).
    const perms = Array.isArray(u.permissions) ? u.permissions : [];
    return key.split('|').some(k => perms.includes(k));
}

/**
 * Can the current user mutate (`create / edit / delete`) data on the
 * given page? Resolves via PAGE_EDIT_PERMS; pages without an entry are
 * treated as "no edit gating needed at this layer".
 *
 *   canEdit('customers')  // → true / false
 */
function canEdit(page) {
    const need = PAGE_EDIT_PERMS[page];
    if (!need) return true;
    return hasPermission(need);
}

/**
 * Toggle a body-level class — `viewer-mode-<page>` — whenever the current
 * page is loaded for a user who lacks the page's edit permission. Module
 * stylesheets use this class to hide "Neu / Bearbeiten / Löschen" buttons,
 * and module render functions can also branch on `canEdit(page)` directly
 * for fine-grained decisions.
 *
 * The class is cleared on every page navigation so stale state from a
 * previous page (or a permission change after re-login) cannot leak.
 */
function applyEditGating(page) {
    const body = document.body;
    if (!body) return;
    // Clear any previous viewer-mode-* class first
    [...body.classList].forEach(c => {
        if (c.startsWith('viewer-mode-')) body.classList.remove(c);
    });
    if (PAGE_EDIT_PERMS[page] && !canEdit(page)) {
        body.classList.add('viewer-mode-' + page);
        body.classList.add('viewer-mode');
    } else {
        body.classList.remove('viewer-mode');
    }
}

// Expose for module files (customers.js, workers.js, …) loaded after app.js
window.hasPermission   = hasPermission;
window.canEdit         = canEdit;
window.applyEditGating = applyEditGating;

/* ----------------------------------------------------------------
 |  Viewer-mode auto-tagger
 | ----------------------------------------------------------------
 |
 |  Legacy module markup (customers.js, workers.js, …) doesn't carry
 |  any per-button permission class. Rather than retro-fitting every
 |  render function, this helper walks the freshly rendered subtree
 |  whenever body.viewer-mode is active and stamps the standard
 |  .perm-needs-edit class onto buttons that obviously perform a
 |  mutation:
 |
 |    - "Neu / Anlegen / Erstellen / Hinzufügen / Hochladen / Senden /
 |       Speichern / Aktualisieren" primary buttons in topbars + modals
 |    - destructive icon buttons (.fa-trash, .fa-ban, .fa-undo,
 |      .fa-times-circle) anywhere
 |    - any [data-perm="edit"] explicitly tagged by a module
 |
 |  Edit-pencil buttons (.fa-pen / .fa-edit) are LEFT VISIBLE on
 |  purpose because in this UI they routinely double as "open details"
 |  buttons. The save-button inside the modal is hidden separately, so
 |  a viewer can still inspect a record but cannot persist changes.
 |
 |  The backend's permission middleware is the actual security boundary
 |  — this is UX polish so the user doesn't see buttons that would 403.
 */
const _MUTATION_TEXT_RE = /(^|\s)(neu|anlegen|erstell|hinzu|speich|aktualisier|hochladen|hochlade|hoch laden|senden|importier|exportier|kopier|reset|zurücksetz|löschen|deaktivier|reaktivier|aktivier|freigeben|ablehnen|genehmig|bestätig|verwerfen|verschieben|verteil|verplant)/i;
const _MUTATION_ICON_RE = /\b(fa-plus|fa-save|fa-cloud-upload-alt|fa-paper-plane|fa-upload|fa-download|fa-trash|fa-trash-alt|fa-ban|fa-undo|fa-times-circle|fa-power-off|fa-check-circle)\b/;
// Material Symbols ligature names of the same mutation icons (post-MD3:
// <i class="material-symbols-outlined">delete</i> / <md-icon>delete</md-icon>).
const _MUTATION_ICON_NAME_RE = /^(add|save|cloud_upload|upload|file_upload|send|download|delete|block|undo|cancel|power_settings_new|check_circle)$/;

function _tagViewerActions(root) {
    if (!root || !document.body.classList.contains('viewer-mode')) return;
    if (root.nodeType !== 1 && root.nodeType !== 9) return;

    // Buttons (and <a class="btn">) that look like mutations — incl. the
    // md-*-button custom elements used since the MD3 conversion.
    const candidates = root.querySelectorAll(
        'button, a.btn, a.btn-icon, a.btn-primary, md-filled-button, md-outlined-button, md-text-button, md-icon-button, [data-perm="edit"]'
    );
    candidates.forEach(el => {
        if (el.classList.contains('perm-needs-edit')) return;
        // Don't gate elements explicitly marked as safe to keep
        if (el.dataset.permKeep === '1') return;
        // Always gate explicit opt-ins
        if (el.dataset.perm === 'edit') {
            el.classList.add('perm-needs-edit');
            return;
        }
        const icons = el.querySelector('i.material-symbols-outlined, md-icon, i, span.icon');
        const iconClass = icons ? icons.className : '';
        const iconName = icons ? (icons.textContent || '').trim() : '';
        const text = (el.textContent || '').trim();

        // Icon-only destructive buttons (fa-trash, fa-ban, fa-undo, ...)
        if (_MUTATION_ICON_RE.test(iconClass) || _MUTATION_ICON_NAME_RE.test(iconName)) {
            // Whitelist: fa-download for read-only PDF/export downloads is
            // sometimes safe, but viewer-mode users don't get destructive
            // exports either way — keep it gated for now.
            el.classList.add('perm-needs-edit');
            return;
        }
        // Text-bearing primary buttons that imply a mutation
        if (text && (el.classList.contains('btn-primary') || el.tagName === 'MD-FILLED-BUTTON') && _MUTATION_TEXT_RE.test(text)) {
            el.classList.add('perm-needs-edit');
            return;
        }
        // Modal footer "Speichern / Aktualisieren / Erstellen" buttons
        if (text && el.closest('.modal-footer, .modal-actions') && _MUTATION_TEXT_RE.test(text)) {
            el.classList.add('perm-needs-edit');
        }
    });
}

/**
 * Watch #content + .modal-overlay for inserted markup and gate the new
 * subtree. One observer covers every module's render path because they
 * all swap innerHTML on #content (or open a modal through openModal()).
 */
function _installViewerObserver() {
    const content = document.getElementById('content');
    if (content) {
        const obs = new MutationObserver(muts => {
            for (const m of muts) {
                m.addedNodes.forEach(n => _tagViewerActions(n));
            }
        });
        obs.observe(content, { childList: true, subtree: true });
        // First-pass tag for whatever is already there
        _tagViewerActions(content);
    }
    // Modal lives outside #content; observe the body for modal mounts
    const bodyObs = new MutationObserver(muts => {
        for (const m of muts) {
            m.addedNodes.forEach(n => {
                if (n.nodeType !== 1) return;
                if (n.id === 'modalOverlay' || n.classList?.contains('modal-overlay') || n.querySelector?.('.modal-overlay, .modal')) {
                    _tagViewerActions(n);
                }
            });
        }
    });
    bodyObs.observe(document.body, { childList: true, subtree: false });
}
// Defer until DOM is ready (this script runs in <head> on some pages)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _installViewerObserver);
} else {
    _installViewerObserver();
}

/** AÜG module switched on for this company (Konto & Firma). */
function auegAktiv() {
    try {
        const cur = (typeof CURRENT_TENANT !== 'undefined' && CURRENT_TENANT) || {};
        if (typeof cur.aueg_aktiv === 'boolean') return cur.aueg_aktiv;
        return !!JSON.parse(localStorage.getItem('hms_tenant') || '{}').aueg_aktiv;
    } catch (e) { return false; }
}

/**
 * UI wording of the company's main industry (payload `terms`, Konto & Firma →
 * Unternehmensdaten → Branche & Bezeichnungen): Kunde/Klient, Objekt/Haushalt …
 * term('kunden') → "Klienten". Unknown keys return the neutral default.
 */
const TERM_DEFAULTS = { kunde: 'Kunde', kunden: 'Kunden', objekt: 'Objekt', objekte: 'Objekte' };
function term(key) {
    let t = null;
    try {
        const cur = (typeof CURRENT_TENANT !== 'undefined' && CURRENT_TENANT) || {};
        t = cur.terms || JSON.parse(localStorage.getItem('hms_tenant') || '{}').terms || null;
    } catch (e) { /* ignore */ }
    return (t && typeof t[key] === 'string' && t[key]) || TERM_DEFAULTS[key] || key;
}
window.term = term;

// Sidebar labels that follow the industry wording.
function applyTermLabels() {
    const set = (page, text) => document.querySelectorAll(`.nav-item[data-page="${page}"] span:not(.badge)`).forEach(sp => {
        if (sp.textContent !== text) sp.textContent = text;
    });
    set('customers', term('kunden'));
    set('objects', term('objekte') + ' & Artikel');
}

/**
 * Apply permission-based UI gating to the sidebar.
 * - Hides nav items the user is not allowed to see.
 * - Hides section headers whose items are all hidden.
 */
function applyPermissionGating() {
    applyTermLabels();
    document.querySelectorAll('.nav-item').forEach(item => {
        const page = item.dataset.page;
        const need = PAGE_PERMS[page];
        if ((need && !hasPermission(need)) || (page === 'aueg' && !auegAktiv())) {
            item.style.display = 'none';
        } else {
            item.style.display = '';
        }
    });

    // Hide any nav-section whose visible nav-items count is 0
    document.querySelectorAll('.nav-section').forEach(sec => {
        const visible = [...sec.querySelectorAll('.nav-item')]
            .some(i => i.style.display !== 'none');
        sec.style.display = visible ? '' : 'none';
    });
    // Tenant name of the shift log (Wachbuch / Objektbuch / Schichtbuch).
    const wbLabel = document.getElementById('navQmWachbuchLabel');
    if (wbLabel) wbLabel.textContent = objektbuchLabel();
    // Favoriten mirrors only pages that are still visible.
    if (typeof renderNavFavorites === 'function' && document.querySelector('.nav-fav-star')) renderNavFavorites();
}

function initNavigation() {
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const page = item.dataset.page;
            loadPage(page);
        });
    });

    document.getElementById('menuToggle').addEventListener('click', () => {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    });

    applyPermissionGating();
    initNavFavorites();
}

// ============================================================
// SIDEBAR FAVORITES
// ------------------------------------------------------------
// Every .nav-item gets a star (visible on hover, always visible when
// starred). Starred pages are listed in the "Favoriten" section as
// lightweight copies that carry data-fav-page (NOT data-page), so
// badges, permission gating and `.nav-item[data-page=…]` lookups keep
// targeting the originals. Stored per user + browser in localStorage.
// The old drag & drop reordering was removed on purpose.
// ============================================================
function _navFavKey() {
    const u = window.CURRENT_USER || {};
    const t = typeof CURRENT_TENANT !== 'undefined' ? CURRENT_TENANT : {};
    return 'hms_nav_favs_' + (t.slug || '') + '_' + (u.id || 0);
}

function getNavFavorites() {
    try {
        const v = JSON.parse(localStorage.getItem(_navFavKey()) || '[]');
        return Array.isArray(v) ? v.filter(p => typeof p === 'string') : [];
    } catch (e) { return []; }
}

function toggleNavFavorite(page) {
    const favs = getNavFavorites();
    const i = favs.indexOf(page);
    if (i >= 0) favs.splice(i, 1); else favs.push(page);
    try { localStorage.setItem(_navFavKey(), JSON.stringify(favs)); } catch (e) {}
    renderNavFavorites();
}

function renderNavFavorites() {
    const list = document.getElementById('navFavList');
    const section = document.getElementById('navFavSection');
    if (!list || !section) return;
    const favs = getNavFavorites();
    document.querySelectorAll('.sidebar-nav .nav-item[data-page]').forEach(item => {
        const on = favs.includes(item.dataset.page);
        item.classList.toggle('is-fav', on);
        const star = item.querySelector('.nav-fav-star');
        if (star) {
            star.innerHTML = `<i class="material-symbols-outlined" style="font-variation-settings:'FILL' ${on ? 1 : 0}">star</i>`;
            star.title = on ? 'Aus Favoriten entfernen' : 'Zu Favoriten hinzufügen';
        }
    });
    list.innerHTML = '';
    favs.forEach(page => {
        const orig = document.querySelector(`.sidebar-nav .nav-item[data-page="${page}"]`);
        // Only pages the user may actually open (gating hid the rest).
        if (!orig || orig.style.display === 'none') return;
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'nav-item nav-fav-item' + (orig.classList.contains('active') ? ' active' : '');
        a.dataset.favPage = page;
        const icon = orig.querySelector('i');
        const label = orig.querySelector('span:not(.badge)');
        a.innerHTML = `${icon ? icon.outerHTML : ''}<span>${label ? label.innerHTML : page}</span>`
            + `<button type="button" class="nav-fav-star" title="Aus Favoriten entfernen"><i class="material-symbols-outlined">star</i></button>`;
        a.addEventListener('click', (e) => { e.preventDefault(); orig.click(); });
        a.querySelector('.nav-fav-star').addEventListener('click', (e) => {
            e.preventDefault(); e.stopPropagation(); toggleNavFavorite(page);
        });
        list.appendChild(a);
    });
    document.getElementById('navFavHint').style.display = list.children.length ? 'none' : '';
    section.style.display = '';
}

function initNavFavorites() {
    // Drop the stored order of the removed drag & drop feature.
    try { localStorage.removeItem('hms_nav_order_v2'); } catch (e) {}
    document.querySelectorAll('.sidebar-nav .nav-item[data-page]').forEach(item => {
        if (item.querySelector('.nav-fav-star')) return;
        const star = document.createElement('button');
        star.type = 'button';
        star.className = 'nav-fav-star';
        star.addEventListener('click', (e) => {
            e.preventDefault(); e.stopPropagation(); toggleNavFavorite(item.dataset.page);
        });
        item.appendChild(star);
    });
    renderNavFavorites();
}

// tasks.js was split into ordered parts sharing one global scope — the ORDER
// matters and all three pages load the SAME list (scripts are cached by src).
const TASKS_MODULE_SCRIPTS = [
    '01-tasks-core', '02-dispo-board', '03-dispo-detail', '04-worker-panels',
    '05-ma-overview', '06-ma-actions', '07-ma-analytics', '08-ma-pdf',
    '09-dispo-nu', '10-dispo-live',
].map(f => 'assets/js/tasks/' + f + '.js?v=20261114md3g');

// Qualitätsmanagement & Wachbuch: ordered parts, ONE global scope (like
// tasks/). Both QM pages load the SAME list.
const QM_MODULE_SCRIPTS = [
    '01-qm-core', '02-qm-wachbuch', '03-qm-uebersicht', '04-qm-objekte',
    '05-qm-reklamationen', '06-qm-massnahmen', '07-qm-einstellungen', '99-qm-live',
].map(f => 'assets/js/qm/' + f + '.js?v=20261114md3g');

const PAGE_MODULE_SCRIPTS = {
    qm_wachbuch: QM_MODULE_SCRIPTS,
    qm: QM_MODULE_SCRIPTS,
    einstellungen: ['assets/js/einstellungen.js?v=20261114md3g'],
    interessenten: ['assets/js/crm.js?v=20261114md3g'],
    customers: ['assets/js/customers.js?v=20261114md3g'],
    objects: ['assets/js/objects.js?v=20261114md3g'],
    subcontractors: ['assets/js/subcontractors.js?v=20261114md3g'],
    price_history: ['assets/js/price_history.js?v=20261114md3g'],
    lager: ['assets/js/lager.js?v=20261114md3g'],
    orders: ['assets/js/orders.js?v=20261114md3g'],
    auftragsbestaetigung: ['assets/js/order_confirmations.js?v=20261114md3g'],
    tasks: TASKS_MODULE_SCRIPTS,
    disposition: TASKS_MODULE_SCRIPTS,
    monatsabschluss: TASKS_MODULE_SCRIPTS,
    reports: [
        'assets/js/reports.js?v=20261114md3g',
        'assets/js/controlling/controlling-core.js?v=20261114md3g',
        'assets/js/controlling/controlling-charts.js?v=20261114md3g',
        'assets/js/controlling/controlling-widgets.js?v=20261114md3g',
        'assets/js/controlling/controlling-api.js?v=20261114md3g',
        'assets/js/controlling/controlling-worker.js?v=20261114md3g',
        'assets/js/controlling/controlling-drilldown.js?v=20261114md3g',
        'assets/js/controlling/controlling-snapshots.js?v=20261114md3g',
        'assets/js/controlling/controlling-cockpit.js?v=20261114md3g',
        'assets/js/controlling.js?v=20261114md3g'
    ],
    workers: ['assets/js/workers.js?v=20261114md3h'],
    absences: ['assets/js/absences.js?v=20261114md3g'],
    users: ['assets/js/users.js?v=20261114md3g'],
    pdf_design: ['assets/js/pdf_design.js?v=20261114md3g'],
    company_documents: ['assets/js/company_documents.js?v=20261114md3g'],
    aufgaben: ['assets/js/aufgaben.js?v=20261114md3g'],
    aueg: ['assets/js/aueg.js?v=20261114md3g']
};
// Opens the shared PDF editor (order_form.php) in a fullscreen in-app
// <dialog> + iframe. Same element contract as orders.js
// openOrderFormModal (#orFormModal / #orFormFrame, closeOrderFormModal for
// the editor's back arrow) so both can coexist; used by the
// Auftragsbestätigung and Monatsabschluss (Stundennachweis) modules.
function openPdfEditorModal(url) {
    if (typeof openOrderFormModal === 'function') return openOrderFormModal(url);
    if (document.documentElement.classList.contains('app-shell')) {
        const cs = getComputedStyle(document.documentElement);
        const px = v => Math.max(0, Math.round(parseFloat(v) || 0));
        url += (url.includes('?') ? '&' : '?') + 'sat=' + px(cs.getPropertyValue('--sa-top')) + '&sab=' + px(cs.getPropertyValue('--sa-bottom'));
    }
    let dlg = document.getElementById('orFormModal');
    if (!dlg) {
        dlg = document.createElement('dialog');
        dlg.id = 'orFormModal';
        dlg.style.cssText = 'position:fixed;padding:0;border:0;margin:0;inset:0;width:100vw;height:100vh;max-width:none;max-height:none;background:var(--gray-100,#f1f5f9)';
        dlg.innerHTML = '<iframe id="orFormFrame" title="PDF-Editor" style="width:100%;height:100%;border:0;display:block;background:transparent"></iframe>';
        dlg.addEventListener('close', () => {
            const f = document.getElementById('orFormFrame');
            if (f) f.src = 'about:blank';
        });
        document.body.appendChild(dlg);
    }
    document.getElementById('orFormFrame').src = url;
    if (!dlg.open) dlg.showModal();
}
window.openPdfEditorModal = openPdfEditorModal;

// Runs the PDF editor (order_form.php) off-screen in headless mode and hands
// its render API to `fn` — so every PDF the system produces (Auftrags-
// bestätigung list, Monatsabschluss send / print-all) uses the SAME renderer
// and company layout template as the editor. `fn(api)` receives:
//   api.pdfBlob()            → Blob of the rendered document
//   api.fileName()           → suggested download name
//   api.renderWorker(doc)    → re-render with another Stundennachweis
//   api.sheetHtml()          → self-contained markup of the current sheet
//   api.documentHtml(sheets, title) → full standalone HTML document
// Calls are serialised: one hidden editor at a time.
let _hmsHeadlessQueue = Promise.resolve();
function hmsWithHeadlessEditor(url, fn) {
    const run = async () => {
        const frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.tabIndex = -1;
        // Real layout size (html2canvas needs a laid-out sheet) but off-screen.
        frame.style.cssText = 'position:fixed;left:-12000px;top:0;width:1000px;height:1500px;border:0;pointer-events:none;';
        frame.src = url + (url.includes('?') ? '&' : '?') + 'headless=1';
        document.body.appendChild(frame);
        try {
            const api = await new Promise((resolve, reject) => {
                const started = Date.now();
                const timer = setInterval(() => {
                    let doc = null;
                    try { doc = frame.contentWindow && frame.contentWindow.__hmsDoc; } catch (e) { /* not ready */ }
                    if (doc && doc.ready) { clearInterval(timer); doc.ready.then(resolve, reject); }
                    else if (Date.now() - started > 30000) { clearInterval(timer); reject(new Error('Dokument konnte nicht geladen werden (Zeitüberschreitung)')); }
                }, 100);
            });
            let out = await fn(api);
            // A Blob created inside the iframe belongs to ITS realm — once the
            // frame is removed, reading it (arrayBuffer / FormData upload /
            // object URL) hangs forever. Copy it into this window first.
            if (out && typeof out.arrayBuffer === 'function' && typeof out.size === 'number') {
                out = new Blob([await out.arrayBuffer()], { type: out.type || 'application/pdf' });
            }
            return out;
        } finally {
            frame.remove();
        }
    };
    const p = _hmsHeadlessQueue.then(run, run);
    _hmsHeadlessQueue = p.catch(() => {});
    return p;
}
window.hmsWithHeadlessEditor = hmsWithHeadlessEditor;
if (typeof window.closeOrderFormModal !== 'function') {
    window.closeOrderFormModal = () => document.getElementById('orFormModal')?.close();
}

const _pageScriptPromises = new Map();
/* Set whenever a page render fails — subsequent module loads get a ?_r=
   cache-buster so a corrupted/truncated disk-cache entry can't poison
   every retry. */
let _scriptCacheBust = '';

function loadPageScript(src) {
    if (_pageScriptPromises.has(src)) return _pageScriptPromises.get(src);
    const promise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        // A stalled (not errored) script fetch fires neither handler — cap it
        // so the page shows the retry UI instead of an endless spinner.
        const t = setTimeout(() => {
            script.onload = script.onerror = null;
            reject(new Error('Modul-Ladezeit überschritten: ' + src));
        }, _API_SCRIPT_TIMEOUT_MS);
        script.src = _scriptCacheBust
            ? src + (src.indexOf('?') >= 0 ? '&' : '?') + '_r=' + _scriptCacheBust
            : src;
        script.onload = () => { clearTimeout(t); resolve(); };
        script.onerror = () => { clearTimeout(t); reject(new Error('Modul konnte nicht geladen werden: ' + src)); };
        document.head.appendChild(script);
    });
    // A rejected load must not stay cached — "Erneut versuchen" has to
    // actually re-request the file.
    promise.catch(() => { _pageScriptPromises.delete(src); });
    _pageScriptPromises.set(src, promise);
    return promise;
}
async function ensurePageScripts(page) {
    for (const src of PAGE_MODULE_SCRIPTS[page] || []) await loadPageScript(src);
}

async function loadPage(page) {
    // Deep links "#page?a=b" (e.g. from notifications): the module reads
    // window._pageParams once; the page key itself never carries them.
    if (typeof page === 'string' && page.includes('?')) {
        const i = page.indexOf('?');
        window._pageParams = new URLSearchParams(page.slice(i + 1));
        page = page.slice(0, i);
    }
    // Dokumentvorlagen moved into the central page System › Einstellungen.
    if (page === 'pdf_design') return loadPage('einstellungen?tab=dokumente');
    // The E-Mail section was removed (old #mail bookmarks land on the overview).
    if (page === 'mail') return loadPage('dashboard');
    // Page switch: any dialog belonging to the previous page is gone (or
    // about to be) — re-evaluate the body scroll lock so it can't stay
    // frozen with the page-level scroll disabled.
    syncBodyScrollLock();
    // Defensive: if the user lacks permission for the requested page,
    // route them to the first page they CAN see. Without this, a user
    // with only e.g. `stammdaten.kunden.view` would still land on the
    // dashboard (because the old code special-cased `page === 'dashboard'`
    // and fell through, exposing Übersicht and triggering 10+ forbidden
    // KPI API calls that visibly slowed login).
    const need = PAGE_PERMS[page];
    if (need && !hasPermission(need)) {
        const firstAllowed = Object.keys(PAGE_PERMS).find(p => hasPermission(PAGE_PERMS[p]));
        if (firstAllowed && firstAllowed !== page) {
            return loadPage(firstAllowed);
        }
        // User has zero usable permissions — render an explanatory empty
        // state and stop. Better than firing a barrage of 403s.
        const c = document.getElementById('content');
        if (c) {
            c.innerHTML = '<div class="empty-state" style="padding:48px;text-align:center;color:var(--gray-500)">'
                + '<i class="material-symbols-outlined" style="font-size:2em" style="margin-bottom:12px;color:var(--gray-400)">lock</i>'
                + '<p style="font-weight:600;margin-bottom:4px">Keine Berechtigungen zugewiesen</p>'
                + '<p style="font-size:0.9rem">Bitte wenden Sie sich an Ihren Administrator.</p>'
                + '</div>';
        }
        const title = document.getElementById('pageTitle');
        if (title) title.textContent = 'Keine Berechtigung';
        return;
    }

    // Cancel previous in-flight API requests
    if (_currentAbortController) _currentAbortController.abort();
    _currentAbortController = new AbortController();
    _apiErrorCount = 0;
    const loadId = ++_pageLoadId;

    // Remove retry banner if present
    const retryBanner = document.getElementById('retryBanner');
    if (retryBanner) retryBanner.remove();

    currentPage = page;
    window.location.hash = page;
    // Toggle the body.viewer-mode-<page> class BEFORE the module renders,
    // so CSS rules hide "Neu / Bearbeiten / Löschen" the moment the new
    // markup appears (no flash of editable controls).
    applyEditGating(page);
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
    const active = document.querySelector(`.sidebar-nav [data-page="${page}"]`) || document.querySelector(`[data-page="${page}"]`);
    if (active) active.classList.add('active');
    document.querySelectorAll(`.nav-fav-item[data-fav-page="${page}"]`).forEach(i => i.classList.add('active'));

    const titles = {
        dashboard: 'Übersicht',
        interessenten: 'Vertrieb — Interessenten & Leads',
        customers: 'Stammdaten — ' + term('kunden'),
        objects: 'Stammdaten — ' + term('objekte') + ' & Artikel',
        subcontractors: 'Personal — Nachunternehmer',
        price_history: 'Preishistorie',
        lager: 'Stammdaten — Lager & Ausrüstung',
        incoming_orders: 'Bestelleingang',
        auftragsbestaetigung: 'Auftragsbestätigungen',
        orders: 'Bestellungen',
        tasks: 'Planung',
        disposition: 'Planung',
        monatsabschluss: 'Zeiterfassung',
        reports: 'Auswertung & Controlling',
        workers: 'Personal — Mitarbeiter',
        absences: 'Anträge & Urlaub',
        worker_messages: 'MA-Nachrichten',
        lists: 'Listen & Konfiguration',
        users: 'Benutzerverwaltung',
        pdf_design: 'Dokumentvorlagen',
        company_documents: 'Konto & Firma',
        aufgaben: 'Aufgaben',
        aueg: 'Personal — AÜG-Fristen',
        qm_wachbuch: objektbuchLabel(),
        qm: 'Qualitätsmanagement',
        einstellungen: 'Einstellungen'
    };
    document.getElementById('pageTitle').textContent = titles[page] || page;

    // Cleanup: remove the Monatsabschluss topbar nav injector when leaving the
    // page. It is re-mounted by renderMonatsabschlussContent() when needed.
    if (page !== 'monatsabschluss') {
        const maInj = document.getElementById('maNavInjector');
        if (maInj) maInj.remove();
    }

    const content = document.getElementById('content');
    content.innerHTML = '<div class="text-center mt-3"><md-circular-progress indeterminate></md-circular-progress></div>';

    try {
        await ensurePageScripts(page);
        // Bereich colours must be known before the page paints its chips
        // (cached after the first load; never blocks longer than 1.5 s).
        await Promise.race([ensureBereichColors(), new Promise(r => setTimeout(r, 1500))]);
    } catch (error) {
        _scriptCacheBust = Date.now().toString(36);
        content.innerHTML = '<div class="empty-state"><i class="material-symbols-outlined">wifi</i><p>Bereich konnte nicht geladen werden.</p><md-filled-button  onclick="loadPage(\'' + page + '\')">Erneut versuchen</md-filled-button></div>';
        showToast(error.message || 'Bereich konnte nicht geladen werden', 'error');
        if (!window.__hmsBootReady) {
            window.__hmsBootReady = true;
            document.dispatchEvent(new CustomEvent('hms:page-ready'));
        }
        return;
    }

    let pageRender;
    try {
        switch (page) {
            case 'dashboard': pageRender = renderDashboard(); break;
            case 'interessenten': pageRender = renderInteressenten(); break;
            case 'customers': pageRender = renderCustomers(); break;
            case 'objects': pageRender = renderObjects(); break;
            case 'subcontractors': pageRender = renderSubcontractors(); break;
            case 'price_history': pageRender = renderPriceHistory(); break;
            case 'lager': pageRender = renderLager(); break;
            case 'incoming_orders': pageRender = renderIncomingOrders(); break;
            case 'auftragsbestaetigung': pageRender = renderOrderConfirmations(); break;
            case 'orders': pageRender = renderOrders(); break;
            case 'tasks': pageRender = renderTasks(); break;
            case 'disposition': pageRender = renderTasks(); break;
            case 'monatsabschluss': pageRender = renderMonatsabschlussPage(); break;
            case 'reports': pageRender = (typeof renderControlling === 'function' ? renderControlling() : renderReports()); break;
            case 'absences': pageRender = renderAbsencesPage(); break;
            case 'workers':
                pageRender = renderWorkers();
                // Clear the red sidebar badge as soon as the user opens the
                // Mitarbeiter section — they have now "seen" all pending
                // worker.* notifications. The list inside the page still shows
                // which requests need a decision.
                if (window.HMSNotifications && typeof window.HMSNotifications.markWorkersRead === 'function') {
                    window.HMSNotifications.markWorkersRead();
                }
                break;
            case 'worker_messages': pageRender = renderWorkerMessages(); break;
            case 'users': pageRender = renderUsers(); break;
            case 'pdf_design': pageRender = renderPdfDesign(); break;
            case 'company_documents': pageRender = renderCompanyDocuments(); break;
            case 'aufgaben': pageRender = renderAufgaben(); break;
            case 'aueg': pageRender = renderAueg(); break;
            case 'qm_wachbuch': pageRender = renderQmWachbuch(); break;
            case 'qm': pageRender = renderQm(); break;
            case 'einstellungen': pageRender = renderEinstellungen(); break;
            default: content.innerHTML = '<div class="empty-state"><i class="material-symbols-outlined">engineering</i><p>Seite wird entwickelt...</p></div>';
        }
    } catch (e) {
        // A renderer may be missing if its module script arrived corrupted
        // (truncated cache entry, parse error — onload still fired). Without
        // this guard the synchronous throw left the spinner running forever.
        // The cache-buster makes "Erneut versuchen" bypass the bad entry.
        console.error('Page render failed:', e);
        _scriptCacheBust = Date.now().toString(36);
        content.innerHTML = '<div class="empty-state"><i class="material-symbols-outlined">warning</i><p>Bereich konnte nicht gestartet werden.</p><md-filled-button  onclick="loadPage(\'' + page + '\')">Erneut versuchen</md-filled-button></div>';
        if (!window.__hmsBootReady) {
            window.__hmsBootReady = true;
            document.dispatchEvent(new CustomEvent('hms:page-ready'));
        }
        return;
    }
    // Async renderers that reject AFTER the entry call must not leave the
    // spinner up either — show the same retry state when the page never
    // painted (a painted page is left untouched).
    if (pageRender && typeof pageRender.catch === 'function') {
        pageRender.catch(e => {
            if (!content.querySelector('.data-toolbar, table, .nu-stats-grid, .stats-grid')) {
                console.error('Page render rejected:', e);
                _scriptCacheBust = Date.now().toString(36);
                content.innerHTML = '<div class="empty-state"><i class="material-symbols-outlined">warning</i><p>Bereich konnte nicht geladen werden.</p><md-filled-button  onclick="loadPage(\'' + page + '\')">Erneut versuchen</md-filled-button></div>';
            }
        });
    }

    if (!window.__hmsBootReady) {
        Promise.resolve(pageRender).catch(() => {}).finally(() => {
            window.__hmsBootReady = true;
            document.dispatchEvent(new CustomEvent('hms:page-ready'));
        });
    }

    document.getElementById('sidebar').classList.remove('open');
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) overlay.classList.remove('active');
}

// ============================================
// API HELPERS
// ============================================

/**
 * Sleep helper.
 */
function _apiSleep(ms) {
    return new Promise(r => setTimeout(r, ms));
}

/**
 * Compute the delay (ms) we should wait before retrying after a 429 response.
 * Uses the server-provided Retry-After header when present; otherwise falls
 * back to exponential backoff with full jitter.
 *
 * Retry-After can be either a delta-seconds value or an HTTP-date — we honour
 * both, but cap at 10s so the UI never blocks for longer than that.
 */
function _retryAfterMs(res, attempt) {
    const raw = res.headers.get('Retry-After');
    if (raw) {
        const secs = Number(raw);
        if (Number.isFinite(secs) && secs >= 0) {
            return Math.min(secs * 1000, 10000);
        }
        const when = Date.parse(raw);
        if (!Number.isNaN(when)) {
            return Math.min(Math.max(0, when - Date.now()), 10000);
        }
    }
    // Exponential backoff: 250ms, 500ms, 1000ms (+ up to 250ms jitter)
    const base = Math.min(250 * Math.pow(2, attempt), 2000);
    return base + Math.floor(Math.random() * 250);
}

/** Max automatic retries on HTTP 429 before giving up. */
const _API_MAX_429_RETRIES = 3;

/* Stalled-socket guard. All API traffic shares ONE HTTP/2 connection to
   the edge — when that socket silently dies (mobile NAT drop, cell
   handover, a black-holed hop) every fetch queued on it hangs until the
   OS's TCP timeout (~15-60s): the "Laden…" spinner that never ends.
   A GET that takes longer than this is almost always that case — abort
   it and retry ONCE on a fresh socket, which typically answers in ms. */
const _API_GET_TIMEOUT_MS = 12000;
/* Writes/uploads get no auto-retry (not safely idempotent) — a stalled
   socket aborts and returns an error the caller can show. */
const _API_WRITE_TIMEOUT_MS = 20000;
const _API_UPLOAD_TIMEOUT_MS = 90000;
const _API_SCRIPT_TIMEOUT_MS = 20000;

function _getFetchCtrl(outerSignal, ms, mark) {
    const ctrl = new AbortController();
    const t = setTimeout(() => { mark.timedOut = true; ctrl.abort(); }, ms);
    if (outerSignal) {
        if (outerSignal.aborted) ctrl.abort();
        else outerSignal.addEventListener('abort', () => ctrl.abort(), { once: true });
    }
    return { ctrl, clear: () => clearTimeout(t) };
}

async function apiGet(url) {
    let attempt = 0;
    let timedOutOnce = false;
    while (true) {
        const outer = _currentAbortController ? _currentAbortController.signal : null;
        if (outer && outer.aborted) return [];
        const mark = { timedOut: false };
        const { ctrl, clear } = _getFetchCtrl(outer, _API_GET_TIMEOUT_MS, mark);
        try {
            const res = await fetch(url, { headers: getAuthHeadersNoContent(), signal: ctrl.signal });
            // Keep the timer armed through res.json() — a socket that
            // delivers headers then stalls mid-body must still abort,
            // otherwise the page spinner waits for the OS TCP timeout.
            if (res.status === 401) { clear(); clearAuthToken(); window.location.href = 'login.php'; return []; }
            if (res.status === 429 && attempt < _API_MAX_429_RETRIES) {
                clear();
                await _apiSleep(_retryAfterMs(res, attempt));
                attempt++;
                continue;
            }
            const body = await res.json();
            clear();
            return body;
        } catch (e) {
            clear();
            if (e.name === 'AbortError') {
                // Navigation abort → silent return. Timeout → one retry on
                // a fresh socket (only if the nav signal hasn't fired too).
                if (mark.timedOut && !timedOutOnce && !(outer && outer.aborted)) {
                    timedOutOnce = true;
                    continue;
                }
                if (mark.timedOut) {
                    _apiErrorCount += 2;
                    showRetryBanner();
                }
                return [];
            }
            console.error('API GET Error:', e);
            _apiErrorCount++;
            if (_apiErrorCount >= 2) showRetryBanner();
            return [];
        }
    }
}

/**
 * Like apiGet() but does NOT attach the shared AbortController signal.
 * Use this for pages whose requests must NOT be cancelled by a later
 * loadPage() (e.g. Controlling dashboard, which fans out 10 parallel
 * requests that would otherwise be killed by any concurrent navigation).
 * Returns null on hard failure so the caller can distinguish "aborted/[]"
 * from "really failed".
 *
 * Also transparently retries up to 3 times on HTTP 429 honouring
 * Retry-After — Controlling fans out 10 parallel requests and is the most
 * likely caller to bump into the rate limiter.
 */
async function apiGetNoCancel(url) {
    let attempt = 0;
    let timedOutOnce = false;
    while (true) {
        const mark = { timedOut: false };
        const { ctrl, clear } = _getFetchCtrl(null, _API_GET_TIMEOUT_MS, mark);
        try {
            const res = await fetch(url, { headers: getAuthHeadersNoContent(), signal: ctrl.signal });
            if (res.status === 401) { clear(); clearAuthToken(); window.location.href = 'login.php'; return null; }
            if (res.status === 429 && attempt < _API_MAX_429_RETRIES) {
                clear();
                await _apiSleep(_retryAfterMs(res, attempt));
                attempt++;
                continue;
            }
            const body = await res.json();
            clear();
            return body;
        } catch (e) {
            clear();
            if (e.name === 'AbortError') {
                if (mark.timedOut && !timedOutOnce) { timedOutOnce = true; continue; }
                if (mark.timedOut) { _apiErrorCount += 2; showRetryBanner(); }
                return null;
            }
            console.error('API GET (NoCancel) Error:', e);
            return null;
        }
    }
}

async function apiPost(url, data) {
    const outer = _currentAbortController ? _currentAbortController.signal : null;
    const mark = { timedOut: false };
    const { ctrl, clear } = _getFetchCtrl(outer, _API_WRITE_TIMEOUT_MS, mark);
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify(data),
            signal: ctrl.signal
        });
        if (res.status === 401) { clear(); clearAuthToken(); window.location.href = 'login.php'; return {}; }
        const text = await res.text();
        clear();
        try { return JSON.parse(text); } catch (e) { console.error('API POST parse error:', text); return { error: 'Server-Fehler: ' + text.substring(0, 200) }; }
    } catch (e) {
        clear();
        if (e.name === 'AbortError') return mark.timedOut ? { error: 'Zeitüberschreitung — Verbindung zu langsam. Bitte erneut versuchen.' } : {};
        console.error('API POST Error:', e);
        return { error: e.message };
    }
}

async function apiPut(url, data) {
    const outer = _currentAbortController ? _currentAbortController.signal : null;
    const mark = { timedOut: false };
    const { ctrl, clear } = _getFetchCtrl(outer, _API_WRITE_TIMEOUT_MS, mark);
    try {
        const res = await fetch(url, {
            method: 'PUT',
            headers: getAuthHeaders(),
            body: JSON.stringify(data),
            signal: ctrl.signal
        });
        if (res.status === 401) { clear(); clearAuthToken(); window.location.href = 'login.php'; return {}; }
        const text = await res.text();
        clear();
        try { return JSON.parse(text); } catch (e) { console.error('API PUT parse error:', text); return { error: 'Server-Fehler: ' + text.substring(0, 200) }; }
    } catch (e) {
        clear();
        if (e.name === 'AbortError') return mark.timedOut ? { error: 'Zeitüberschreitung — Verbindung zu langsam. Bitte erneut versuchen.' } : {};
        console.error('API PUT Error:', e);
        return { error: e.message };
    }
}

async function apiDelete(url, data) {
    const outer = _currentAbortController ? _currentAbortController.signal : null;
    const mark = { timedOut: false };
    const { ctrl, clear } = _getFetchCtrl(outer, _API_WRITE_TIMEOUT_MS, mark);
    try {
        const res = await fetch(url, {
            method: 'DELETE',
            headers: getAuthHeaders(),
            body: JSON.stringify(data),
            signal: ctrl.signal
        });
        if (res.status === 401) { clear(); clearAuthToken(); window.location.href = 'login.php'; return {}; }
        const body = await res.json();
        clear();
        return body;
    } catch (e) {
        clear();
        if (e.name === 'AbortError') return mark.timedOut ? { error: 'Zeitüberschreitung — Verbindung zu langsam. Bitte erneut versuchen.' } : {};
        console.error('API DELETE Error:', e);
        return { error: e.message };
    }
}

async function apiUpload(url, formData) {
    const outer = _currentAbortController ? _currentAbortController.signal : null;
    const mark = { timedOut: false };
    const { ctrl, clear } = _getFetchCtrl(outer, _API_UPLOAD_TIMEOUT_MS, mark);
    try {
        const token = getAuthToken();
        const headers = { 'Accept': 'application/json' };
        if (token) headers['Authorization'] = 'Bearer ' + token;
        const res = await fetch(url, { method: 'POST', headers: headers, body: formData, signal: ctrl.signal });
        if (res.status === 401) { clear(); clearAuthToken(); window.location.href = 'login.php'; return {}; }
        const body = await res.json();
        clear();
        return body;
    } catch (e) {
        clear();
        if (e.name === 'AbortError') return mark.timedOut ? { error: 'Upload-Zeitüberschreitung — Verbindung zu langsam.' } : {};
        console.error('API Upload Error:', e);
        return { error: e.message };
    }
}

// ============================================
// ACCOUNT / PROFILE
// ============================================
const RECHTSFORMEN = [
    'GmbH', 'UG (haftungsbeschränkt)', 'AG', 'GmbH & Co. KG',
    'OHG', 'KG', 'GbR', 'e.K.', 'Einzelunternehmen', 'e.V.', 'Genossenschaft (eG)',
];

const ACCOUNT_DEFAULT_TAB = 'profile';

async function openAccountModal(activeTab) {
    // The merged "Konto & Firma" page (company_documents.js) replaces this
    // modal, but it is lazy-loaded — on a cold session its override of this
    // function does not exist yet and the legacy modal would open without the
    // Benachrichtigungen/Bereiche tabs. Pull the module in first and delegate
    // to its override; keep the legacy modal as a load-failure fallback.
    try { await ensurePageScripts('company_documents'); }
    catch (e) { console.error('Konto-Modul konnte nicht geladen werden:', e); }
    if (typeof renderCompanyDocuments === 'function') {
        // Same routing the module's own override applies — the self-reference
        // check can't be used here because a global function name always
        // resolves through `window` (it would equal the override).
        _cdocsTab = activeTab || 'profile';
        return loadPage('company_documents');
    }

    activeTab = activeTab || ACCOUNT_DEFAULT_TAB;
    const u = typeof CURRENT_USER !== 'undefined' ? CURRENT_USER : {};
    const t = typeof CURRENT_TENANT !== 'undefined' ? CURRENT_TENANT : {};
    const isAdmin = u.role === 'admin' || u.role === 'tenant_admin';
    const roleLabel = u.role === 'tenant_admin' ? 'Tenant Admin'
        : (u.role || 'viewer').charAt(0).toUpperCase() + (u.role || 'viewer').slice(1);

    // Tab definitions — only admins see the company tab
    const tabs = [
        { id: 'profile', icon: 'person', label: 'Mein Profil' },
    ];
    if (isAdmin) {
        tabs.push({ id: 'company',   icon: 'apartment',      label: 'Unternehmensdaten' });
        tabs.push({ id: 'legal',     icon: 'balance', label: 'Rechtliches' });
        tabs.push({ id: 'bank',      icon: 'account_balance',    label: 'Bankverbindung' });
        tabs.push({ id: 'documents', icon: 'folder_open',   label: 'Dokumente' });
    }

    const tabsHtml = tabs.map(tab => `
        <button class="acc-tab ${tab.id === activeTab ? 'active' : ''}" data-tab="${tab.id}" onclick="switchAccountTab('${tab.id}')">
            <i class="material-symbols-outlined">${tab.icon}</i> ${tab.label}
        </button>
    `).join('');

    const body = `
        <div class="acc-modal">
            <div class="acc-tabs">${tabsHtml}</div>
            <div class="acc-tab-content" id="accTabContent">
                <div class="acc-loading"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Laden...</div>
            </div>
        </div>
    `;

    const footer = `
        <md-outlined-button  onclick="closeModal()">Schließen</md-outlined-button>
        <md-filled-button  id="accSaveBtn" style="display:none" onclick="saveAccountActiveTab()"><i slot="icon" class="material-symbols-outlined">save</i> Speichern</md-filled-button>
    `;

    openModal('Mein Konto', body, footer, 'modal-lg');

    // Load tab content
    await renderAccountTab(activeTab, { user: u, tenant: t, isAdmin, roleLabel });
}

let _accountCompanyData = null;
let _accountActiveTab = 'profile';
let _accountCtx = null;

// Fetch the company payload for the account modal. apiGetNoCancel is used
// on purpose: loadPage() aborts the shared controller on EVERY navigation
// and realtime-triggered reload, which previously killed this fetch and —
// because apiGet() returns [] on failure — cached a truthy empty value that
// left the section stuck on "Nicht gesetzt" for the whole session.
async function _loadAccountCompanyData() {
    const data = await apiGetNoCancel(API_BASE + '/dashboard/company');
    if (data && typeof data === 'object' && !Array.isArray(data) && !data.error) {
        _accountCompanyData = data;
    }
    return _accountCompanyData;
}

async function switchAccountTab(tabId) {
    document.querySelectorAll('.acc-tab').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabId);
    });
    const u = typeof CURRENT_USER !== 'undefined' ? CURRENT_USER : {};
    const t = typeof CURRENT_TENANT !== 'undefined' ? CURRENT_TENANT : {};
    const isAdmin = u.role === 'admin' || u.role === 'tenant_admin';
    const roleLabel = u.role === 'tenant_admin' ? 'Tenant Admin'
        : (u.role || 'viewer').charAt(0).toUpperCase() + (u.role || 'viewer').slice(1);
    await renderAccountTab(tabId, { user: u, tenant: t, isAdmin, roleLabel });
}

async function renderAccountTab(tabId, ctx) {
    _accountActiveTab = tabId;
    _accountCtx = ctx;
    const container = document.getElementById('accTabContent');
    const saveBtn = document.getElementById('accSaveBtn');
    if (!container) return;

    // Profile tab — always available
    if (tabId === 'profile') {
        if (saveBtn) saveBtn.style.display = 'none';
        // The "Signatur & Stempel" uploaders (Bestellungen editors only)
        // need the company payload for the current asset paths.
        if (hasPermission('auftraege.bestellungen.edit') && !_accountCompanyData) {
            container.innerHTML = '<div class="acc-loading"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Laden...</div>';
            await _loadAccountCompanyData();
        }
        container.innerHTML = renderProfileTab(ctx, _accountCompanyData || {});
        return;
    }

    // Admin-only tabs need the company data fetched once
    if (!_accountCompanyData) {
        container.innerHTML = '<div class="acc-loading"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Laden...</div>';
        await _loadAccountCompanyData();
    }
    const c = _accountCompanyData || {};

    if (tabId === 'company') {
        container.innerHTML = renderCompanyTab(c);
        cfInitPauseRules(c.pause_rules);
        if (saveBtn) saveBtn.style.display = '';
    } else if (tabId === 'legal') {
        container.innerHTML = renderLegalTab(c);
        if (saveBtn) saveBtn.style.display = '';
    } else if (tabId === 'bank') {
        container.innerHTML = renderBankTab(c);
        if (saveBtn) saveBtn.style.display = '';
    } else if (tabId === 'documents') {
        // Documents tab manages its own per-row save/delete buttons,
        // so the global modal Save button stays hidden.
        if (saveBtn) saveBtn.style.display = 'none';
        await renderDocumentsTab(container);
    }
}

function renderProfileTab(ctx, c) {
    const u = ctx.user;
    c = c || {};
    return `
        <div class="acc-section">
            <div class="account-profile">
                <div class="account-avatar"><i class="material-symbols-outlined">account_circle</i></div>
                <div class="account-details">
                    <div class="account-detail-row">
                        <span class="account-label"><i class="material-symbols-outlined">person</i> Name</span>
                        <span class="account-value">${escapeHtml(u.full_name || 'Benutzer')}</span>
                    </div>
                    <div class="account-detail-row">
                        <span class="account-label"><i class="material-symbols-outlined">alternate_email</i> Benutzername</span>
                        <span class="account-value">${escapeHtml(u.username || '–')}</span>
                    </div>
                    <div class="account-detail-row">
                        <span class="account-label"><i class="material-symbols-outlined">mail</i> E-Mail</span>
                        <span class="account-value">${escapeHtml(u.email || '–')}</span>
                    </div>
                    <div class="account-detail-row">
                        <span class="account-label"><i class="material-symbols-outlined">shield</i> Rolle</span>
                        <span class="account-value"><span class="role-badge role-${u.role || 'viewer'}">${ctx.roleLabel}</span></span>
                    </div>
                    <div class="account-detail-row">
                        <span class="account-label"><i class="material-symbols-outlined">apartment</i> Unternehmen</span>
                        <span class="account-value">${escapeHtml(ctx.tenant.name || '–')}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">vpn_key</i> Passwort ändern</div>
            <div class="acc-grid acc-grid-3">
                <div class="form-group">
                    <label>Aktuelles Passwort</label>
                    <md-outlined-text-field type="password" class="form-control" id="acc_old_pw" placeholder="Aktuelles Passwort"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>Neues Passwort</label>
                    <md-outlined-text-field type="password" class="form-control" id="acc_new_pw" placeholder="Mind. 8 Zeichen"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>Bestätigen</label>
                    <md-outlined-text-field type="password" class="form-control" id="acc_new_pw2" placeholder="Wiederholen"></md-outlined-text-field>
                </div>
            </div>
            <div style="text-align:right;margin-top:8px">
                <md-filled-button  onclick="saveAccountPassword()"><i slot="icon" class="material-symbols-outlined">save</i> Passwort ändern</md-filled-button>
            </div>
        </div>

        ${hasPermission('auftraege.bestellungen.edit') ? renderDocAssetsSection(c, ctx) : ''}

    `;
}

// ── Signatur & Stempel (Bestellung-PDF document assets) ───────────────
// Company-wide signature + stamp images uploaded once here and stamped
// onto the Auftragsbestätigung from the PDF viewer via two topbar buttons.
// Storage mirrors the company logo: public disk under tenants/{slug}/,
// paths live on the `tenants` row (signature_path / stamp_path) and are
// streamed back through the existing /api/serve endpoint.
function renderDocAssetsSection(c, ctx) {
    const t = (ctx && ctx.tenant) || {};
    const u = (ctx && ctx.user) || {};
    const isAdmin = (u.is_owner === true || u.is_owner === 1)
        || ['tenant_admin', 'admin', 'super_admin'].includes(u.role);
    const card = (kind, title, icon) => {
        const own  = c[kind + '_own'] || '';
        const dflt = c[kind + '_default'] || '';
        const eff  = own || dflt || c[kind + '_path'] || t[kind + '_path'] || '';
        const url  = eff ? tenantLogoUrl(eff) : '';
        const [status, cls] = own ? ['Eigene Datei', 'asset-own']
            : dflt ? ['Firmen-Standard', 'asset-default']
            : eff ? ['Gesetzt', 'asset-default'] : ['Nicht gesetzt', 'asset-none'];
        const canRemove = !!own || (isAdmin && !!eff);
        return `
        <div class="doc-asset-card">
            <div class="doc-asset-preview" id="${kind}PreviewBox">
                ${url ? `<img src="${escapeHtml(url)}" alt="${title}">` : `<i class="material-symbols-outlined">${icon}</i>`}
            </div>
            <div class="doc-asset-title"><i class="material-symbols-outlined">${icon}</i> ${title}</div>
            <div class="doc-asset-status ${cls}" id="${kind}Status">${status}</div>
            <div class="logo-buttons" style="justify-content:center">
                <input type="file" id="${kind}File" accept="image/png,image/webp,image/svg+xml,image/jpeg" style="display:none" onchange="uploadDocAsset(this,'${kind}')">
                <md-filled-button type="button"  class="md-btn-sm" onclick="document.getElementById('${kind}File').click()">
                    <i class="material-symbols-outlined">upload</i> ${own ? 'Ersetzen' : 'Hochladen'}
                </button>
                <md-outlined-button type="button"  class="md-btn-sm" id="${kind}RemoveBtn" onclick="removeDocAsset('${kind}')" ${canRemove ? '' : 'style="display:none"'}>
                    <i class="material-symbols-outlined">delete</i>
                </button>
            </div>
            ${isAdmin ? `<label class="doc-asset-scope" title="Für alle Benutzer der Firma als Standard speichern">
                <md-checkbox id="${kind}Scope"></md-checkbox> Firmen-Standard
            </label>` : ''}
        </div>`;
    };
    return `
        <div class="acc-section" id="docAssetsSection">
            <div class="acc-section-header"><i class="material-symbols-outlined">approval</i> Signatur &amp; Stempel</div>
            <p style="font-size:0.85rem;color:var(--gray-500);margin:0 0 12px">
                Deine persönliche Unterschrift und dein Stempel für die Bestellung-PDFs — am besten als
                <strong>PNG mit transparentem Hintergrund</strong>. Ohne eigene Datei gilt der Firmen-Standard.
                Im PDF-Viewer werden sie per Knopfdruck auf das Dokument gesetzt.
            </p>
            <div class="doc-assets-grid">
                ${card('signature', 'Unterschrift', 'signature')}
                ${card('stamp', 'Stempel', 'approval')}
            </div>
        </div>`;
}

function refreshDocAssetsSection() {
    const el = document.getElementById('docAssetsSection');
    if (el) el.outerHTML = renderDocAssetsSection(_accountCompanyData || {}, _accountCtx || {});
}

async function uploadDocAsset(input, kind) {
    const file = input.files && input.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        showToast('Datei zu groß (max. 2 MB)', 'error');
        input.value = '';
        return;
    }
    const scopeCb = document.getElementById(kind + 'Scope');
    const fd = new FormData();
    fd.append(kind, file);
    fd.append('scope', (scopeCb && scopeCb.checked) ? 'tenant' : 'user');
    try {
        const res = await fetch(API_BASE + '/pdf/upload-' + kind, {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || ''), 'Accept': 'application/json' },
            body: fd,
        });
        const data = await res.json();
        const col = kind + '_path';
        if (!res.ok || !data.success) {
            showToast(data.error || 'Upload fehlgeschlagen', 'error');
            return;
        }
        showToast(data.scope === 'tenant' ? 'Als Firmen-Standard gespeichert' : 'Gespeichert');
        if (_accountCompanyData) {
            if (data.scope === 'tenant') _accountCompanyData[kind + '_default'] = data[col];
            else _accountCompanyData[kind + '_own'] = data[col];
            _accountCompanyData[col] = _accountCompanyData[kind + '_own'] || _accountCompanyData[kind + '_default'] || null;
        }
        const merged = { ...JSON.parse(localStorage.getItem('hms_tenant') || '{}'), [col]: _accountCompanyData ? _accountCompanyData[col] : data[col] };
        localStorage.setItem('hms_tenant', JSON.stringify(merged));
        refreshDocAssetsSection();
    } catch (e) {
        showToast('Upload fehlgeschlagen', 'error');
    } finally {
        input.value = '';
    }
}
window.uploadDocAsset = uploadDocAsset;

async function removeDocAsset(kind) {
    const own  = _accountCompanyData && _accountCompanyData[kind + '_own'];
    const scope = own ? 'user' : 'tenant';
    const msg = own ? 'Eigene Datei entfernen? Danach gilt wieder der Firmen-Standard.'
        : 'Firmen-Standard für alle Benutzer entfernen?';
    if (!confirm(msg)) return;
    const result = await apiDelete(API_BASE + '/pdf/' + kind + '?scope=' + scope);
    if (!result || !result.success) {
        showToast((result && result.error) || 'Fehler beim Entfernen', 'error');
        return;
    }
    showToast('Entfernt');
    const col = kind + '_path';
    if (_accountCompanyData) {
        if (own) _accountCompanyData[kind + '_own'] = null;
        else _accountCompanyData[kind + '_default'] = null;
        _accountCompanyData[col] = _accountCompanyData[kind + '_own'] || _accountCompanyData[kind + '_default'] || null;
    }
    const merged = { ...JSON.parse(localStorage.getItem('hms_tenant') || '{}'), [col]: _accountCompanyData ? _accountCompanyData[col] : null };
    localStorage.setItem('hms_tenant', JSON.stringify(merged));
    refreshDocAssetsSection();
}
window.removeDocAsset = removeDocAsset;

function tenantLogoUrl(path) {
    if (!path) return '';
    // Backend stores the logo on the public disk; we use the existing
    // /api/serve endpoint which already handles path safety and tenant
    // scoping (paths are unguessable). Bypassing the storage symlink
    // means we don't depend on extra web-server config.
    return API_BASE + '/serve?path=' + encodeURIComponent(path) + '&v=' + Date.now();
}

function applyTenantBranding(tenant) {
    const nameEl = document.getElementById('sidebarLogoName');
    const markEl = document.getElementById('sidebarLogoMark');
    if (!nameEl || !markEl) return;

    // Tenant isolation guard: refuse to render a logo whose path does
    // not start with one of our known tenant-scoped prefixes for THIS
    // tenant's slug. Two legacy schemes are in production:
    //   - `tenants/<slug>/logo/…`  (new, TenantDashboardController)
    //   - `logos/<slug>/…`          (older, PdfController)
    // Both encode the slug, so we accept either as long as the slug
    // matches the currently logged-in tenant. Anything else would mean
    // a corrupted localStorage or a cross-tenant leak — log loudly and
    // fall back to the generic building icon.
    const currentSlug = (typeof CURRENT_TENANT !== 'undefined' && CURRENT_TENANT.slug) ? CURRENT_TENANT.slug : null;
    if (tenant && tenant.logo_path && currentSlug) {
        const path = String(tenant.logo_path);
        const ok = path.startsWith('tenants/' + currentSlug + '/')
                || path.startsWith('logos/'   + currentSlug + '/');
        if (!ok) {
            console.error('[branding] logo_path scope mismatch — refusing to render', {
                slug: currentSlug,
                got: tenant.logo_path,
            });
            tenant = { ...tenant, logo_path: null };
        }
    }

    // Auto-shrink the company name based on character length so very long
    // names like "HMS Gruppe GmbH & Co. KG" still fit nicely without
    // truncation. The thresholds were picked against the actual sidebar
    // width (220px) at common DPI.
    const name = tenant.name || 'Clood-X';
    nameEl.textContent = name;
    nameEl.classList.remove('logo-name-md', 'logo-name-sm', 'logo-name-xs');
    const len = name.length;
    if (len > 26)      nameEl.classList.add('logo-name-xs');
    else if (len > 20) nameEl.classList.add('logo-name-sm');
    else if (len > 14) nameEl.classList.add('logo-name-md');

    // Logo image (or fall back to FA building icon).
    if (tenant.logo_path) {
        markEl.innerHTML = '<img src="' + tenantLogoUrl(tenant.logo_path) + '" alt="Logo">';
    } else {
        markEl.innerHTML = '<img src="assets/img/clood-x-logo.png" alt="Clood-X">';
    }
}
window.applyTenantBranding = applyTenantBranding;

function renderLogoUploader(c) {
    const hasLogo = !!c.logo_path;
    const previewSrc = hasLogo ? tenantLogoUrl(c.logo_path) : '';
    return `
        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">image</i> Firmenlogo</div>
            <div class="company-logo-uploader">
                <div class="logo-preview" id="logoPreviewBox">
                    ${hasLogo
                        ? `<img src="${escapeHtml(previewSrc)}" alt="Logo">`
                        : `<i class="material-symbols-outlined">image</i>`}
                </div>
                <div class="logo-actions">
                    <div class="logo-buttons">
                        <input type="file" id="companyLogoFile" accept="image/png,image/jpeg,image/svg+xml" style="display:none" onchange="uploadCompanyLogo(this)">
                        <md-filled-button type="button"  class="md-btn-sm" onclick="document.getElementById('companyLogoFile').click()">
                            <i class="material-symbols-outlined">upload</i> Logo hochladen
                        </button>
                        <md-outlined-button type="button"  class="md-btn-sm" id="logoRemoveBtn" onclick="removeCompanyLogo()" ${hasLogo ? '' : 'style="display:none"'}>
                            <i class="material-symbols-outlined">delete</i> Entfernen
                        </button>
                    </div>
                    <div class="hint">PNG, JPG oder SVG · max. 2 MB · empfohlen quadratisch (z.B. 512×512 px)</div>
                </div>
            </div>
        </div>
    `;
}

async function uploadCompanyLogo(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        showToast('Datei zu groß (max. 2 MB)', 'error');
        input.value = '';
        return;
    }
    const fd = new FormData();
    fd.append('logo', file);
    try {
        const res = await fetch(API_BASE + '/dashboard/logo', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || ''), 'Accept': 'application/json' },
            body: fd,
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showToast(data.error || 'Logo-Upload fehlgeschlagen', 'error');
            return;
        }
        showToast('Logo gespeichert');
        // Update preview + sidebar + localStorage
        if (_accountCompanyData) _accountCompanyData.logo_path = data.logo_path;
        const box = document.getElementById('logoPreviewBox');
        if (box) box.innerHTML = '<img src="' + tenantLogoUrl(data.logo_path) + '" alt="Logo">';
        const removeBtn = document.getElementById('logoRemoveBtn');
        if (removeBtn) removeBtn.style.display = '';
        const merged = { ...JSON.parse(localStorage.getItem('hms_tenant') || '{}'), logo_path: data.logo_path };
        localStorage.setItem('hms_tenant', JSON.stringify(merged));
        if (typeof CURRENT_TENANT !== 'undefined') CURRENT_TENANT.logo_path = data.logo_path;
        applyTenantBranding({ name: merged.name, logo_path: data.logo_path });
    } catch (e) {
        showToast('Logo-Upload fehlgeschlagen', 'error');
    } finally {
        input.value = '';
    }
}
window.uploadCompanyLogo = uploadCompanyLogo;

async function removeCompanyLogo() {
    if (!confirm('Logo wirklich entfernen?')) return;
    const result = await apiDelete(API_BASE + '/dashboard/logo');
    if (!result || !result.success) {
        showToast((result && result.error) || 'Fehler beim Entfernen', 'error');
        return;
    }
    showToast('Logo entfernt');
    if (_accountCompanyData) _accountCompanyData.logo_path = null;
    const box = document.getElementById('logoPreviewBox');
    if (box) box.innerHTML = '<i class="material-symbols-outlined">image</i>';
    const removeBtn = document.getElementById('logoRemoveBtn');
    if (removeBtn) removeBtn.style.display = 'none';
    const merged = { ...JSON.parse(localStorage.getItem('hms_tenant') || '{}'), logo_path: null };
    localStorage.setItem('hms_tenant', JSON.stringify(merged));
    if (typeof CURRENT_TENANT !== 'undefined') CURRENT_TENANT.logo_path = null;
    applyTenantBranding({ name: merged.name, logo_path: null });
}
window.removeCompanyLogo = removeCompanyLogo;

function renderCompanyTab(c) {
    return `
        ${renderLogoUploader(c)}
        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">apartment</i> Unternehmensdaten</div>
            <div class="acc-grid acc-grid-2">
                <div class="form-group acc-span-2">
                    <label>Firmenname *</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_name" value="${escapeHtml(c.name || '')}" placeholder="Mustermann GmbH"></md-outlined-text-field>
                </div>
                <div class="form-group acc-span-2">
                    <label>Zusatz</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_firmen_zusatz" maxlength="150" value="${escapeHtml(c.firmen_zusatz || '')}" placeholder="z.B. Sicherheitsdienst · Bewachung · Service"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>Rechtsform</label>
                    <md-outlined-select class="form-control" id="cf_rechtsform">
                        <md-select-option value="">–</md-select-option>
                        ${RECHTSFORMEN.map(r => `<md-select-option value="${r}" ${c.rechtsform === r ? 'selected' : ''}>${r}</md-select-option>`).join('')}
                    </md-outlined-select>
                </div>
                <div class="form-group">
                    <label>Geschäftsführer / Vertretungsberechtigt</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_geschaeftsfuehrer" value="${escapeHtml(c.geschaeftsfuehrer || '')}" placeholder="Max Mustermann"></md-outlined-text-field>
                </div>
            </div>
        </div>

        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">contact_page</i> Kontakt & Anschrift</div>
            <div class="acc-grid acc-grid-2">
                <div class="form-group">
                    <label>E-Mail</label>
                    <md-outlined-text-field type="email" class="form-control" id="cf_company_email" value="${escapeHtml(c.company_email || '')}" placeholder="info@firma.de"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>Telefon</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_company_phone" value="${escapeHtml(c.company_phone || '')}" placeholder="+49 30 123456"></md-outlined-text-field>
                </div>
                <div class="form-group acc-span-2">
                    <label>Webseite</label>
                    <md-outlined-text-field type="url" class="form-control" id="cf_website" value="${escapeHtml(c.website || '')}" placeholder="https://www.firma.de"></md-outlined-text-field>
                </div>
                <div class="form-group acc-span-2">
                    <label>Straße & Hausnummer</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_company_address" value="${escapeHtml(c.company_address || '')}" placeholder="Musterstraße 12"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>PLZ</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_company_zip" value="${escapeHtml(c.company_zip || '')}" placeholder="10115" maxlength="20"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>Stadt</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_company_city" value="${escapeHtml(c.company_city || '')}" placeholder="Berlin"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>Land</label>
                    <md-outlined-select class="form-control" id="cf_company_country">
                        <md-select-option value="DE" ${(c.company_country || 'DE') === 'DE' ? 'selected' : ''}>Deutschland</md-select-option>
                        <md-select-option value="AT" ${c.company_country === 'AT' ? 'selected' : ''}>Österreich</md-select-option>
                        <md-select-option value="CH" ${c.company_country === 'CH' ? 'selected' : ''}>Schweiz</md-select-option>
                    </md-outlined-select>
                </div>
            </div>
        </div>

        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">local_cafe</i> Pausenregelung (MA-Pause)</div>
            <div class="acc-info-box">
                <i class="material-symbols-outlined">info</i>
                <span>Füllt die <strong>MA-Pause</strong> im Bestelleingang automatisch aus der Schichtdauer (Von–Bis der Schicht).
                Jede Regel gilt für einen Bereich der Schichtdauer, Grenzen eingeschlossen — z. B. „06:01 bis 09:00 → 30 Min“
                (gesetzliches ArbZG-Minimum: über 6 Std 30 Min, über 9 Std 45 Min). Dauer außerhalb aller Bereiche = keine Pause.
                Leer lassen = keine Automatik, Pause bleibt manuell.</span>
            </div>
            <div class="acc-grid" style="grid-template-columns:1fr 1fr 1fr 36px;gap:12px;margin-bottom:4px">
                <label style="font-size:.78rem;font-weight:600;color:var(--gray-500)">Schichtdauer von (Std:Min)</label>
                <label style="font-size:.78rem;font-weight:600;color:var(--gray-500)">bis (Std:Min)</label>
                <label style="font-size:.78rem;font-weight:600;color:var(--gray-500)">→ MA-Pause (Min)</label>
                <span></span>
            </div>
            <div id="cfPauseRules"></div>
            <md-outlined-button type="button"  class="md-btn-sm" onclick="cfAddPauseRule()" style="margin-top:4px">
                <i class="material-symbols-outlined">add</i> Regel hinzufügen
            </button>
        </div>

        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">menu_book</i> ${Array.isArray(c.branchen) ? 'Branche &amp; Bezeichnungen' : 'Bezeichnungen'}</div>
            ${Array.isArray(c.branchen) ? `
            <div class="acc-info-box">
                <i class="material-symbols-outlined">info</i>
                Die Hauptbranche bestimmt die Begriffe im System (z.B. „Klienten“ statt „Kunden“ in der Hauswirtschaft).
                Branchen je Dienstleistung (Objektarten, Qualifikationen, Zusatzfelder) stellen Sie unter Konto &amp; Firma → Bereiche ein.
            </div>
            <div class="acc-grid acc-grid-2">
                <div class="form-group acc-span-2">
                    <label>Hauptbranche des Unternehmens</label>
                    <md-outlined-select class="form-control" id="cf_branche" onchange="cfBrancheChanged(this.value)">
                        <md-select-option value="">— keine (neutrale Begriffe) —</md-select-option>
                        ${c.branchen.map(b => `<md-select-option value="${escapeHtml(b.key)}" ${b.key === c.branche ? 'selected' : ''}>${escapeHtml(b.label)}</md-select-option>`).join('')}
                    </md-outlined-select>
                </div>
                ${[['kunde', 'Begriff für Kunde (Einzahl)'], ['kunden', 'Begriff für Kunden (Mehrzahl)'], ['objekt', 'Begriff für Objekt (Einzahl)'], ['objekte', 'Begriff für Objekte (Mehrzahl)']].map(([k, l]) => `
                <div class="form-group">
                    <label>${l}</label>
                    <md-outlined-text-field type="text" class="form-control cf-term" data-term="${k}" maxlength="40" value="${escapeHtml((c.terms_custom || {})[k] || '')}" placeholder="${escapeHtml((c.terms_default || {})[k] || TERM_DEFAULTS[k])}"></md-outlined-text-field>
                </div>`).join('')}
                <div class="form-group acc-span-2"><small style="color:var(--gray-500)">Leer = Begriff der gewählten Branche (grau angezeigt).</small></div>
            </div>` : ''}
            <div class="acc-grid acc-grid-2">
                <div class="form-group">
                    <label>Name des Schicht-Protokolls</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_objektbuch_label" maxlength="40" value="${escapeHtml(c.objektbuch_label_custom || '')}" placeholder="${escapeHtml(c.objektbuch_label_default || 'Wachbuch')}"></md-outlined-text-field>
                    <small style="color:var(--gray-500)">Wird in der Mitarbeiter-App, bei den Objekten und im Kundenportal angezeigt (z.B. Wachbuch, Objektbuch, Schichtbuch). Leer = „${escapeHtml(c.objektbuch_label_default || 'Wachbuch')}“.</small>
                </div>
            </div>
        </div>

        ${c.arbzg ? `
        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">work_history</i> Arbeitszeitgesetz (Hinweise in der Planung)</div>
            <div class="acc-info-box">
                <i class="material-symbols-outlined">info</i>
                Zeigt in der Disposition einen Hinweis, wenn die Ruhezeit zwischen zwei Schichten oder die Arbeitszeit pro Tag
                (ohne Pausen) die Grenze überschreitet. Nur ein Hinweis — die Zuweisung wird nie blockiert.
                Abweichende Grenzen per Tarifvertrag (§ 7 ArbZG, z.B. 12 Std) hier einstellen.
            </div>
            <div class="acc-grid acc-grid-2">
                <div class="form-group acc-span-2">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <md-checkbox id="cf_arbzg_aktiv" ${c.arbzg.aktiv ? 'checked' : ''}></md-checkbox> Hinweise aktivieren
                    </label>
                </div>
                <div class="form-group">
                    <label>Höchstarbeitszeit pro Tag (Std)</label>
                    <md-outlined-text-field type="number" class="form-control" id="cf_arbzg_max_tag" min="6" max="24" step="0.5" value="${escapeHtml(String(c.arbzg.max_tag))}"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>Mindestruhezeit zwischen Schichten (Std)</label>
                    <md-outlined-text-field type="number" class="form-control" id="cf_arbzg_ruhezeit" min="5" max="24" step="0.5" value="${escapeHtml(String(c.arbzg.ruhezeit))}"></md-outlined-text-field>
                </div>
            </div>
        </div>` : ''}

        ${c.aueg ? `
        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">handshake</i> Arbeitnehmerüberlassung (AÜG)</div>
            <div class="acc-info-box">
                <i class="material-symbols-outlined">info</i>
                Für Personaldienstleister: überwacht je Mitarbeiter und Entleiher die Überlassungshöchstdauer (18 Monate)
                und den Equal-Pay-Anspruch (9 Monate) — als Seite „AÜG-Fristen“ unter Personal und als Hinweis in der Planung.
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <md-checkbox id="cf_aueg_aktiv" ${c.aueg.aktiv ? 'checked' : ''}></md-checkbox> AÜG-Modul aktivieren
            </label>
        </div>` : ''}
    `;
}

/**
 * Stored documents are no longer public: ask the server for a short-lived
 * signed link (tenant + permission checked) and open it.
 * `enc` = encodeURIComponent(path) (use hmsFileArg() when building onclick).
 */
function hmsFileArg(path) { return encodeURIComponent(path || '').replace(/'/g, '%27'); }
async function hmsOpenFile(enc, download = false) {
    const win = download ? null : window.open('', '_blank');
    try {
        const d = await apiGet(API_BASE + '/files/link?path=' + enc + (download ? '&download=1' : ''));
        if (!d || !d.url) throw new Error((d && d.error) || 'Datei nicht gefunden');
        if (win) win.location.href = d.url;
        else window.location.href = d.url;
    } catch (e) {
        if (win) win.close();
        showToast(e.message || 'Datei konnte nicht geöffnet werden', 'error');
    }
}
window.hmsFileArg = hmsFileArg;
window.hmsOpenFile = hmsOpenFile;

/** Tenant-wide name of the shift log (Wachbuch / Objektbuch / …). */
function objektbuchLabel() {
    try {
        const cur = (typeof CURRENT_TENANT !== 'undefined' && CURRENT_TENANT) || {};
        return cur.objektbuch_label || JSON.parse(localStorage.getItem('hms_tenant') || '{}').objektbuch_label || 'Wachbuch';
    } catch (e) { return 'Wachbuch'; }
}
window.objektbuchLabel = objektbuchLabel;

function cfPauseRuleRowHtml(von, bis, min) {
    const v = x => x !== null && x !== undefined ? escapeHtml(String(x)) : '';
    return `
    <div class="cf-pause-rule acc-grid" style="grid-template-columns:1fr 1fr 1fr 36px;gap:12px;align-items:center;margin-bottom:8px">
        <md-outlined-text-field type="text" class="form-control cf-pause-von t24" inputmode="numeric" maxlength="5" value="${v(von)}" placeholder="z.B. 06:01"></md-outlined-text-field>
        <md-outlined-text-field type="text" class="form-control cf-pause-bis t24" inputmode="numeric" maxlength="5" value="${v(bis)}" placeholder="z.B. 09:00"></md-outlined-text-field>
        <md-outlined-text-field type="number" class="form-control cf-pause-min" value="${v(min)}" min="0" max="480" step="1" placeholder="z.B. 30"></md-outlined-text-field>
        <md-outlined-button type="button"  class="md-btn-sm" onclick="this.closest('.cf-pause-rule').remove()" title="Regel entfernen" style="padding:6px 8px;color: var(--danger,var(--md-sys-color-error))">
            <i class="material-symbols-outlined">delete</i>
        </button>
    </div>`;
}

// Add a pause-rule row to the Pausenregelung editor (Unternehmensdaten).
function cfAddPauseRule(von, bis, min) {
    const wrap = document.getElementById('cfPauseRules');
    if (!wrap) return;
    wrap.insertAdjacentHTML('beforeend', cfPauseRuleRowHtml(von, bis, min));
}

// Stored rules → ranges [{von, bis, min}] (HH:MM). Old threshold rules
// [{ab_h, min}] become "ab_h up to 1 Min before the next threshold".
function cfPauseRuleRanges(rules) {
    const fmt = t => String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0');
    const list = Array.isArray(rules) ? rules : [];
    if (list.some(r => r && r.von)) return list.filter(r => r && r.von).map(r => ({ von: r.von, bis: r.bis, min: r.min }));
    const th = list.filter(r => r && r.ab_h != null).map(r => ({ v: Math.round(parseFloat(r.ab_h) * 60), min: r.min })).sort((a, b) => a.v - b.v);
    return th.map((r, i) => ({ von: fmt(r.v), bis: fmt(Math.min(1440, (th[i + 1] ? th[i + 1].v : 1441) - 1)), min: r.min }));
}

// Populate the dynamic pause-rule rows after the company tab renders —
// at least one empty row so the editor is never a blank box.
function cfInitPauseRules(rules) {
    const wrap = document.getElementById('cfPauseRules');
    if (!wrap) return;
    wrap.innerHTML = '';
    const list = Array.isArray(rules) ? rules : [];
    if (!list.length) { cfAddPauseRule(); return; }
    cfPauseRuleRanges(list).forEach(r => cfAddPauseRule(r.von, r.bis, r.min));
}

function renderLegalTab(c) {
    return `
        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">balance</i> Handelsregister</div>
            <div class="acc-info-box">
                <i class="material-symbols-outlined">info</i>
                Diese Angaben sind gesetzlich vorgeschrieben für die Impressumspflicht (§ 5 TMG) und Geschäftsbriefe (§ 37a HGB).
            </div>
            <div class="acc-grid acc-grid-2">
                <div class="form-group">
                    <label>Handelsregister-Nr.</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_handelsregister_nr" value="${escapeHtml(c.handelsregister_nr || '')}" placeholder="HRB 123456"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>Registergericht</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_registergericht" value="${escapeHtml(c.registergericht || '')}" placeholder="Amtsgericht Berlin (Charlottenburg)"></md-outlined-text-field>
                </div>
            </div>
        </div>

        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">receipt</i> Steuerliche Angaben</div>
            <div class="acc-grid acc-grid-2">
                <div class="form-group">
                    <label>Umsatzsteuer-ID (USt-IdNr.)</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_ust_id" value="${escapeHtml(c.ust_id || '')}" placeholder="DE123456789" maxlength="30"></md-outlined-text-field>
                    <small class="acc-hint">Format: DE + 9 Ziffern</small>
                </div>
                <div class="form-group">
                    <label>Steuernummer</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_steuernummer" value="${escapeHtml(c.steuernummer || '')}" placeholder="12/345/67890"></md-outlined-text-field>
                    <small class="acc-hint">Vom Finanzamt vergeben</small>
                </div>
            </div>
        </div>
    `;
}

function renderBankTab(c) {
    return `
        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">account_balance</i> Bankverbindung</div>
            <div class="acc-info-box">
                <i class="material-symbols-outlined">info</i>
                Wird auf Rechnungen und Geschäftsdokumenten angezeigt.
            </div>
            <div class="acc-grid acc-grid-2">
                <div class="form-group acc-span-2">
                    <label>Name der Bank</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_bank_name" value="${escapeHtml(c.bank_name || '')}" placeholder="Sparkasse Musterstadt"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>IBAN</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_iban" value="${escapeHtml(c.iban || '')}" placeholder="DE89 3704 0044 0532 0130 00" maxlength="34" style="font-family:monospace;letter-spacing:1px"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <label>BIC / SWIFT</label>
                    <md-outlined-text-field type="text" class="form-control" id="cf_bic" value="${escapeHtml(c.bic || '')}" placeholder="COBADEFFXXX" maxlength="11" style="font-family:monospace"></md-outlined-text-field>
                </div>
            </div>
        </div>
    `;
}

// ============================================
// DOCUMENTS TAB — company legal paperwork
// (Gewerbeschein, Handelsregisterauszug, …)
// ============================================
let _accountDocs = null;       // cached doc list for the current modal session
let _accountDocsCats = null;   // category enum from the API

async function renderDocumentsTab(container) {
    container.innerHTML = '<div class="acc-loading"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Laden...</div>';
    try {
        const res = await apiGet(API_BASE + '/dashboard/documents');
        _accountDocs = Array.isArray(res.items) ? res.items : [];
        _accountDocsCats = res.categories || {};
    } catch (e) {
        _accountDocs = [];
        _accountDocsCats = {};
    }
    container.innerHTML = renderDocumentsTabHtml(_accountDocs, _accountDocsCats);
    bindDocumentsTab();
}

function renderDocumentsTabHtml(items, categories) {
    const catOptions = Object.entries(categories || {})
        .map(([k, label]) => `<md-select-option value="${escapeHtml(k)}">${escapeHtml(label)}</md-select-option>`)
        .join('');

    const listHtml = items.length === 0
        ? `<div class="docs-empty"><i class="material-symbols-outlined">folder_open</i><div>Noch keine Dokumente hochgeladen.</div></div>`
        : items.map(d => docCardHtml(d)).join('');

    return `
        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">folder_open</i> Firmendokumente</div>
            <div class="acc-info-box">
                <i class="material-symbols-outlined">info</i>
                Hier können Sie offizielle Unternehmensunterlagen hinterlegen
                (Gewerbeschein, Handelsregisterauszug, Steuerbescheid, Versicherungsnachweise &hellip;).
                Erlaubt: PDF, JPG, PNG, WEBP &middot; max. 10&nbsp;MB pro Datei.
            </div>

            <div class="docs-uploader">
                <div class="docs-uploader-row">
                    <div class="form-group">
                        <label>Kategorie *</label>
                        <md-outlined-select class="form-control" id="docUploadCategory">
                            <md-select-option value="">– bitte wählen –</md-select-option>
                            ${catOptions}
                        </md-outlined-select>
                    </div>
                    <div class="form-group">
                        <label>Bezeichnung *</label>
                        <md-outlined-text-field type="text" class="form-control" id="docUploadTitle" placeholder="z.&nbsp;B. Gewerbeschein 2026" maxlength="200"></md-outlined-text-field>
                    </div>
                    <div class="form-group">
                        <label>Datei *</label>
                        <input type="file" class="form-control" id="docUploadFile" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
                    </div>
                </div>
                <div class="docs-uploader-actions">
                    <md-filled-button type="button"  id="docUploadBtn">
                        <i class="material-symbols-outlined">upload</i> Hochladen
                    </button>
                </div>
            </div>
        </div>

        <div class="acc-section">
            <div class="acc-section-header"><i class="material-symbols-outlined">list</i> Hinterlegte Dokumente <span class="docs-count">${items.length}</span></div>
            <div class="docs-list" id="docsList">
                ${listHtml}
            </div>
        </div>
    `;
}

function docCardHtml(d) {
    const isPdf = (d.mime_type || '').includes('pdf');
    const icon  = isPdf ? 'picture_as_pdf' : 'image';
    const iconColor = isPdf ? '#dc2626' : '#2563eb';
    const sizeKb = Math.round((d.size_bytes || 0) / 1024);
    const sizeStr = sizeKb >= 1024
        ? (sizeKb / 1024).toFixed(1) + '\u00a0MB'
        : sizeKb + '\u00a0KB';
    const dateStr = d.created_at
        ? new Date(d.created_at).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        : '';

    // The thumbnail endpoint sits behind the same auth wall as /view, so we
    // can't use it directly in <img src=""> (no Bearer header on a plain
    // image request). secureThumbImg() lazy-loads the JPG via fetch and
    // injects it as a blob: URL once the card scrolls into view.
    const thumbUrl = API_BASE + '/dashboard/documents/' + d.id + '/thumb';

    return `
        <div class="doc-card" data-id="${d.id}">
            <div class="doc-thumb" data-thumb-url="${escapeHtml(thumbUrl)}" data-fallback-icon="${icon}" data-fallback-color="${iconColor}">
                <i class="material-symbols-outlined" style="color:${iconColor}">${icon}</i>
            </div>
            <div class="doc-meta">
                <div class="doc-title" title="${escapeHtml(d.original_filename || '')}">${escapeHtml(d.title)}</div>
                <div class="doc-sub">
                    <span class="doc-cat">${escapeHtml(d.category_label || d.category)}</span>
                    <span class="doc-dot">&middot;</span>
                    <span>${sizeStr}</span>
                    <span class="doc-dot">&middot;</span>
                    <span>${escapeHtml(dateStr)}</span>
                    ${d.uploaded_by_name ? `<span class="doc-dot">&middot;</span><span><i class="material-symbols-outlined">person</i> ${escapeHtml(d.uploaded_by_name)}</span>` : ''}
                </div>
            </div>
            <div class="doc-actions">
                <md-icon-button type="button"  title="Anzeigen" onclick="openTenantDocument(${d.id})"><i class="material-symbols-outlined">visibility</i></md-icon-button>
                <md-icon-button type="button"  title="Herunterladen" onclick="downloadTenantDocument(${d.id})"><i class="material-symbols-outlined">download</i></md-icon-button>
                <md-icon-button type="button"  class="md-icon-danger" title="L\u00f6schen" onclick="deleteTenantDocument(${d.id})"><i class="material-symbols-outlined">delete</i></md-icon-button>
            </div>
        </div>
    `;
}

function bindDocumentsTab() {
    const btn = document.getElementById('docUploadBtn');
    if (btn) btn.addEventListener('click', uploadTenantDocument);
    // Lazy-load thumbnails inside this modal.
    hydrateSecureThumbs(document.getElementById('accTabContent'));
}

// ============================================================
// SHARED secure-thumbnail loader
//
// Thumb endpoints sit behind auth:sanctum + tenant.boundary, so we cannot
// just set <img src="...">. Instead, every card renders a placeholder
// element (`.doc-thumb[data-thumb-url]`) and we fetch+blob-inject as it
// scrolls into view via IntersectionObserver. Used by:
//   • app.js          (tenant_documents)
//   • workers.js      (mitarbeiter_dokumente)
//   • customers.js    (kunden_dokumente)
//   • subcontractors  (nu_dokumente)
// ============================================================
let _secureThumbObserver = null;
function getSecureThumbObserver() {
    if (_secureThumbObserver) return _secureThumbObserver;
    if (typeof IntersectionObserver === 'undefined') return null;
    _secureThumbObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                loadSecureThumb(entry.target);
                _secureThumbObserver.unobserve(entry.target);
            }
        });
    }, { rootMargin: '200px 0px' });
    return _secureThumbObserver;
}

async function loadSecureThumb(el) {
    const url = el.dataset.thumbUrl;
    if (!url || el.dataset.thumbLoaded === '1') return;
    el.dataset.thumbLoaded = '1';
    try {
        const res = await fetch(url, {
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || '') },
        });
        if (!res.ok) return; // Keep the fallback icon.
        const blob = await res.blob();
        const blobUrl = URL.createObjectURL(blob);
        const img = document.createElement('img');
        img.src = blobUrl;
        img.alt = '';
        img.loading = 'lazy';
        img.onload = () => {
            // Free the blob URL once the browser has decoded the bytes —
            // the <img> keeps an internal reference to the decoded bitmap.
            setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
        };
        el.innerHTML = '';
        el.appendChild(img);
        el.classList.add('doc-thumb-loaded');
    } catch (e) {
        // Silent fallback — leave the icon in place.
    }
}

function hydrateSecureThumbs(root) {
    if (!root) return;
    const obs = getSecureThumbObserver();
    root.querySelectorAll('.doc-thumb[data-thumb-url]').forEach(el => {
        if (el.dataset.thumbLoaded === '1') return;
        if (obs) obs.observe(el);
        else loadSecureThumb(el); // graceful fallback for old browsers
    });
}
window.hydrateSecureThumbs = hydrateSecureThumbs;

async function uploadTenantDocument() {
    const category = document.getElementById('docUploadCategory').value;
    const title    = document.getElementById('docUploadTitle').value.trim();
    const fileEl   = document.getElementById('docUploadFile');
    const file     = fileEl.files && fileEl.files[0];

    if (!category) { showToast('Bitte Kategorie ausw\u00e4hlen', 'error'); return; }
    if (!title)    { showToast('Bitte Bezeichnung eingeben', 'error'); return; }
    if (!file)     { showToast('Bitte Datei ausw\u00e4hlen', 'error'); return; }
    if (file.size > 10 * 1024 * 1024) {
        showToast('Datei zu gro\u00df (max. 10 MB)', 'error');
        return;
    }

    const fd = new FormData();
    fd.append('file', file);
    fd.append('category', category);
    fd.append('title', title);

    const btn = document.getElementById('docUploadBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Hochladen \u2026'; }

    try {
        const res = await fetch(API_BASE + '/dashboard/documents', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || ''), 'Accept': 'application/json' },
            body: fd,
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showToast(data.error || (data.errors && Object.values(data.errors)[0]?.[0]) || 'Upload fehlgeschlagen', 'error');
            return;
        }
        showToast('Dokument hochgeladen');
        // Reload the list
        const container = document.getElementById('accTabContent');
        if (container) await renderDocumentsTab(container);
    } catch (e) {
        showToast('Upload fehlgeschlagen', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="material-symbols-outlined">upload</i> Hochladen'; }
    }
}
window.uploadTenantDocument = uploadTenantDocument;

function _docById(id) {
    return (_accountDocs || []).find(d => Number(d.id) === Number(id));
}

// Open the document inline in a new tab.
//
// SECURITY: The bytes are streamed through /api/dashboard/documents/{id}/view
// which requires auth:sanctum + tenant.boundary + permission:users.manage and
// re-verifies tenant_id in the DB. The on-disk path never crosses the wire.
// We fetch the bytes here and hand the browser an in-memory blob URL so the
// new tab still works even though the upstream URL is bearer-protected.
async function openTenantDocument(id) {
    const d = _docById(id);
    if (!d) return;
    try {
        const res = await fetch(API_BASE + '/dashboard/documents/' + id + '/view', {
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || '') },
        });
        if (!res.ok) { showToast('Datei konnte nicht geladen werden', 'error'); return; }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        window.open(url, '_blank');
        // Revoke after a minute — the new tab will have already cached the bytes.
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) {
        showToast('Datei konnte nicht geladen werden', 'error');
    }
}
window.openTenantDocument = openTenantDocument;

async function downloadTenantDocument(id) {
    const d = _docById(id);
    if (!d) return;
    try {
        const res = await fetch(API_BASE + '/dashboard/documents/' + id + '/download', {
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || '') },
        });
        if (!res.ok) { showToast('Download fehlgeschlagen', 'error'); return; }
        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url;
        a.download = d.original_filename || d.title || 'dokument';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 5000);
    } catch (e) {
        showToast('Download fehlgeschlagen', 'error');
    }
}
window.downloadTenantDocument = downloadTenantDocument;

async function deleteTenantDocument(id) {
    if (!confirm('Dokument wirklich l\u00f6schen?')) return;
    const result = await apiDelete(API_BASE + '/dashboard/documents/' + id);
    if (!result || !result.success) {
        showToast((result && result.error) || 'L\u00f6schen fehlgeschlagen', 'error');
        return;
    }
    showToast('Dokument gel\u00f6scht');
    const container = document.getElementById('accTabContent');
    if (container) await renderDocumentsTab(container);
}
window.deleteTenantDocument = deleteTenantDocument;

function _readCompanyForm() {
    const fields = [
        'name', 'rechtsform', 'geschaeftsfuehrer',
        'company_email', 'company_phone', 'website',
        'company_address', 'company_zip', 'company_city', 'company_country',
        'handelsregister_nr', 'registergericht', 'ust_id', 'steuernummer',
        'bank_name', 'iban', 'bic',
    ];
    const out = {};
    fields.forEach(f => {
        const el = document.getElementById('cf_' + f);
        if (el) out[f] = el.value.trim();
    });
    // Break tiers only exist while the Unternehmensdaten tab is rendered —
    // other tabs save without the key so the stored rules stay untouched.
    // An all-empty editor still sends [] so the admin can clear the automatik.
    if (document.getElementById('cfPauseRules')) {
        const rules = [];
        document.querySelectorAll('#cfPauseRules .cf-pause-rule').forEach(row => {
            const von = (row.querySelector('.cf-pause-von')?.value || '').trim();
            const bis = (row.querySelector('.cf-pause-bis')?.value || '').trim();
            const min = parseInt(row.querySelector('.cf-pause-min')?.value);
            // Empty rows are dropped; half-filled ones go to the server, which
            // answers with a clear message (format / overlap).
            if (von || bis) rules.push({ von, bis, min: isFinite(min) ? min : 0 });
        });
        out.pause_rules = rules;
    }
    const fzEl = document.getElementById('cf_firmen_zusatz');
    if (fzEl) out.firmen_zusatz = fzEl.value.trim();
    const obEl = document.getElementById('cf_objektbuch_label');
    if (obEl) out.objektbuch_label = obEl.value.trim();
    const brEl = document.getElementById('cf_branche');
    if (brEl) {
        out.branche = brEl.value || null;
        out.terms = {};
        document.querySelectorAll('.cf-term').forEach(i => { out.terms[i.dataset.term] = i.value.trim(); });
    }
    if (document.getElementById('cf_aueg_aktiv')) out.aueg = { aktiv: document.getElementById('cf_aueg_aktiv').checked };
    if (document.getElementById('cf_arbzg_aktiv')) {
        out.arbzg = {
            aktiv: document.getElementById('cf_arbzg_aktiv').checked,
            max_tag: document.getElementById('cf_arbzg_max_tag').value,
            ruhezeit: document.getElementById('cf_arbzg_ruhezeit').value,
        };
    }
    return out;
}

// Placeholders of the wording fields follow the selected main industry.
function cfBrancheChanged(key) {
    const b = ((_accountCompanyData && _accountCompanyData.branchen) || []).find(x => x.key === key);
    document.querySelectorAll('.cf-term').forEach(i => { i.placeholder = (b && b.terms[i.dataset.term]) || TERM_DEFAULTS[i.dataset.term]; });
}
window.cfBrancheChanged = cfBrancheChanged;

async function saveAccountActiveTab() {
    if (_accountActiveTab === 'profile') return; // password has its own button

    const data = _readCompanyForm();
    // Validate IBAN format if provided
    if (data.iban && !/^[A-Z]{2}\d{2}[A-Z0-9 ]{11,30}$/.test(data.iban.replace(/\s/g, ''))) {
        showToast('Ungültiges IBAN-Format', 'error');
        return;
    }
    // Validate USt-IdNr (DE + 9 digits) if provided
    if (data.ust_id && !/^[A-Z]{2}\d{8,12}$/.test(data.ust_id.replace(/\s/g, ''))) {
        showToast('Ungültiges Format für USt-IdNr. (z.B. DE123456789)', 'error');
        return;
    }
    // Break tiers: a half-filled row (threshold without minutes or vice
    // versa) would be silently dropped — flag it instead of guessing.
    if (document.getElementById('cfPauseRules')) {
        let halfFilled = false;
        document.querySelectorAll('#cfPauseRules .cf-pause-rule').forEach(row => {
            const ab = row.querySelector('.cf-pause-ab')?.value;
            const min = row.querySelector('.cf-pause-min')?.value;
            if ((ab !== '' && min === '') || (ab === '' && min !== '')) halfFilled = true;
        });
        if (halfFilled) {
            showToast('Pausenregelung: bitte Schwelle UND Minuten ausfüllen (oder die Zeile entfernen)', 'error');
            return;
        }
    }

    const result = await apiPut(API_BASE + '/dashboard/settings', data);
    if (result.success) {
        showToast('Unternehmensdaten gespeichert');
        // Merge, don't replace: the tenant payload lacks the signature_own/
        // signature_default breakdown that /dashboard/company provides —
        // overwriting wiped it and hid the asset remove-buttons ("Gesetzt"
        // with no way to delete) until the next fresh load.
        _accountCompanyData = { ...(_accountCompanyData || {}), ...(result.tenant || data) };
        // The backend now returns the FULL tenant payload (same shape as
        // /auth/check), so we can replace localStorage in one go. This
        // makes the new fields (geschaeftsfuehrer, ust_id, iban, bic, …)
        // immediately visible in the PDF editor without a re-login.
        if (result.tenant) {
            const merged = { ...JSON.parse(localStorage.getItem('hms_tenant') || '{}'), ...result.tenant };
            localStorage.setItem('hms_tenant', JSON.stringify(merged));
            if (typeof CURRENT_TENANT !== 'undefined') Object.assign(CURRENT_TENANT, result.tenant);
            applyTenantBranding(result.tenant);
            if (typeof applyPermissionGating === 'function') applyPermissionGating();
        }
    } else {
        showToast(result.error || 'Fehler beim Speichern', 'error');
    }
}

async function saveAccountPassword() {
    const oldPw = document.getElementById('acc_old_pw').value;
    const newPw = document.getElementById('acc_new_pw').value;
    const newPw2 = document.getElementById('acc_new_pw2').value;

    if (!oldPw) { showToast('Bitte aktuelles Passwort eingeben', 'error'); return; }
    if (!newPw || newPw.length < 8) { showToast('Neues Passwort muss mindestens 8 Zeichen haben', 'error'); return; }
    if (newPw !== newPw2) { showToast('Passwörter stimmen nicht überein', 'error'); return; }

    const result = await apiPost(API.auth + '/change-password', {
        old_password: oldPw,
        new_password: newPw
    });

    if (result.success) {
        showToast('Passwort erfolgreich geändert');
        document.getElementById('acc_old_pw').value = '';
        document.getElementById('acc_new_pw').value = '';
        document.getElementById('acc_new_pw2').value = '';
    } else {
        showToast(result.error || 'Fehler beim Ändern des Passworts', 'error');
    }
}

// ============================================
// THEME — MD3 light / dark scheme
// md3-tokens.css ships both schemes; switching is just flipping the
// data-theme attribute on <html>. The inline bootstrap in index.php
// applies the saved choice pre-CSS to avoid a light-mode flash.
// ============================================
function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    const btn = document.getElementById('themeToggleBtn');
    if (btn) {
        btn.innerHTML = theme === 'dark' ? '<i class="material-symbols-outlined">light_mode</i>' : '<i class="material-symbols-outlined">dark_mode</i>';
        btn.title = theme === 'dark' ? 'Helles Design' : 'Dunkles Design';
    }
}
window.applyTheme = applyTheme;

function toggleTheme() {
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    localStorage.setItem('hms_theme', next);
    applyTheme(next);
}
window.toggleTheme = toggleTheme;

document.addEventListener('DOMContentLoaded', () => {
    // Sync the toggle icon with whatever the bootstrap script applied.
    // Light is the default — dark applies only via the explicit toggle.
    applyTheme(document.documentElement.getAttribute('data-theme') || 'light');
});

// ============================================
// LOGOUT
// ============================================
async function handleLogout() {
    if (!confirm('Möchten Sie sich abmelden?')) return;
    try {
        await fetch(API.auth + '/logout', {
            method: 'POST',
            headers: getAuthHeaders(),
        });
    } catch (e) { /* ignore logout errors */ }
    clearAuthToken();
    // Inside the Mitarbeiter-App WebView the admin web login is a dead end
    // for worker accounts — return to the worker login instead.
    if (localStorage.getItem('hms_app_shell') === '1') {
        localStorage.removeItem('hms_app_shell');
        window.location.href = '/worker/login.php';
        return;
    }
    window.location.href = 'login.php';
}

// ============================================
// MODAL
// ============================================
function initModal() {
    document.getElementById('modalClose').addEventListener('click', closeModal);
    // md-dialog closes itself on Escape/scrim — run the same cleanup.
    document.getElementById('modalOverlay').addEventListener('close', closeModal);
}

function openModal(title, bodyHtml, footerHtml = '', sizeClass = '') {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = bodyHtml;
    document.getElementById('modalFooter').innerHTML = footerHtml;
    const modalHeader = document.querySelector('#modalOverlay .modal-header');
    if (modalHeader) {
        modalHeader.classList.remove('planner-modal-header');
        const plannerAction = modalHeader.querySelector('.planner-header-action');
        if (plannerAction) plannerAction.remove();
        ensureLegacyModalMaxBtn(modalHeader);
    }
    const dialog = document.querySelector('.modal-dialog') || document.querySelector('.modal');
    if (dialog) {
        dialog.classList.remove('modal-xl', 'modal-max');
        if (sizeClass) dialog.classList.add(sizeClass);
    }
    const modalOv = document.getElementById('modalOverlay');
    if (typeof modalOv.show === 'function') modalOv.show();
    else modalOv.setAttribute('open', '');
    syncBodyScrollLock();
}

/**
 * Maximize/restore button for the legacy #modalOverlay modal — same
 * behaviour as the md-dialog .dlg-max button. Created once, icon reset
 * on every openModal() so a new modal always starts restored.
 */
function ensureLegacyModalMaxBtn(header) {
    let btn = header.querySelector('.modal-max-btn');
    if (!btn) {
        btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'modal-close modal-max-btn';
        btn.setAttribute('aria-label', 'Vollbild umschalten');
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const modal = document.getElementById('modal');
            if (!modal) return;
            const max = modal.classList.toggle('modal-max');
            btn.innerHTML = `<span class="material-symbols-outlined">${max ? 'fullscreen_exit' : 'fullscreen'}</span>`;
            btn.title = max ? 'Verkleinern' : 'Vollbild';
        });
        const closeBtn = header.querySelector('#modalClose');
        header.insertBefore(btn, closeBtn || null);
    }
    btn.innerHTML = '<span class="material-symbols-outlined">fullscreen</span>';
    btn.title = 'Vollbild';
}

/**
 * Focus guard: while a modal is open, the sidebar must not steal the
 * user's flow. Any click on the sidebar is swallowed and the open
 * modal "blinks" (shake + ring flash) to tell the user: finish or
 * close this dialog first. Uses capture phase so it wins over the
 * nav-item handlers installed in initNavigation().
 */
const MODAL_GUARD_OVERLAYS = [
    'md-dialog#modalOverlay[open]',   // shared #modalOverlay (openModal)
    '.wp-overlay.active',             // worker slide-in panels
    '.system-confirm-overlay.active', // system confirm dialog
    '.planner-nu-select-overlay.active', // NU selection popup (body-level)
    '.chat-panel.open'                // Nachrichten side sheet
];

function _activeDialogOverlay() {
    for (const sel of MODAL_GUARD_OVERLAYS) {
        const el = document.querySelector(sel);
        if (el) return el;
    }
    // MD3 dialogs (the main dialog type now) and native <dialog> sheets
    // (e.g. the fullscreen PDF viewer) must lock the page behind them too —
    // otherwise scrolling past the end of the dialog content chains into
    // the page and the background moves under the modal. Only count one
    // that is actually rendered: a stale `open` attribute on a detached or
    // display:none element must never keep the page frozen.
    for (const d of document.querySelectorAll('md-dialog[open], dialog[open]')) {
        if (!d.isConnected) continue;
        // md-dialog's host is display:contents — its rendered box is the
        // shadow <dialog> on the top layer, so getClientRects() is ALWAYS
        // empty for it. Native <dialog> keeps its own box; only there does
        // the rect check filter stale/display:none elements.
        if (d.tagName === 'MD-DIALOG' || d.getClientRects().length > 0) return d;
    }
    return null;
}

function blinkActiveModal() {
    const overlay = _activeDialogOverlay();
    if (!overlay) return false;
    const dialog = overlay.querySelector('.modal, .modal-dialog, .wp-panel, .system-confirm-box')
        || (overlay.classList.contains('chat-panel') ? overlay : null)
        || overlay.lastElementChild;
    if (!dialog) return true;
    // Restart the animation even if it is already running
    dialog.classList.remove('modal-attention');
    void dialog.offsetWidth;
    dialog.classList.add('modal-attention');
    dialog.addEventListener('animationend', () => dialog.classList.remove('modal-attention'), { once: true });
    return true;
}

document.addEventListener('click', (e) => {
    if (!_activeDialogOverlay()) return;
    if (e.target.closest('.sidebar')) {
        e.preventDefault();
        e.stopPropagation();
        blinkActiveModal();
    }
}, true);

/**
 * Background scroll lock. While any dialog overlay is open, the page
 * behind it must not scroll when the pointer leaves the dialog. We
 * toggle .modal-open on <html> (see style.css) and keep it in sync via
 * a MutationObserver, so it works regardless of which overlay system
 * opened or closed the dialog. rAF-debounced to coalesce bursts.
 */
let _scrollLockRaf = null;
function syncBodyScrollLock() {
    if (_scrollLockRaf) return;
    _scrollLockRaf = requestAnimationFrame(() => {
        _scrollLockRaf = null;
        document.documentElement.classList.toggle('modal-open', !!_activeDialogOverlay());
    });
}
if (document.body) {
    new MutationObserver(syncBodyScrollLock).observe(document.body, {
        subtree: true,
        childList: true,               // md-dialogs are appended/removed
        attributes: true,
        attributeFilter: ['class', 'open'],
    });
}

/* Touch/WebView scroll lock: while a dialog/overlay is open, a gesture
   may only proceed when the finger/pointer actually sits over a
   scrollable element (the dialog's own scroller, an overlay body, a
   dropdown list, …) — every other path is the locked background.
   composedPath() looks inside shadow DOM, so md-dialog's inner .scroller
   counts while a swipe on its scrim does NOT. No per-overlay class list
   needed: "can this element scroll?" is the whole criterion. Desktop
   additionally gets overflow:hidden from style.css (@media pointer:fine)
   covering keyboard keys and scrollbar drags. */
function _gestureOverScroller(e) {
    const path = e.composedPath ? e.composedPath() : [];
    for (const n of path) {
        if (!(n instanceof Element)) continue;
        if (n === document.body || n === document.documentElement) return false;
        const st = getComputedStyle(n);
        if (((st.overflowY === 'auto' || st.overflowY === 'scroll') && n.scrollHeight > n.clientHeight)
            || ((st.overflowX === 'auto' || st.overflowX === 'scroll') && n.scrollWidth > n.clientWidth)) {
            return true;
        }
    }
    return false;
}
function _swallowOutsideScroll(e) {
    if (!document.documentElement.classList.contains('modal-open')) return;
    if (!_gestureOverScroller(e)) e.preventDefault();
}
document.addEventListener('touchmove', _swallowOutsideScroll, { passive: false });
document.addEventListener('wheel', _swallowOutsideScroll, { passive: false });

// ============================================================
// GLOBAL <md-dialog> BEHAVIOUR
// 1) No light-dismiss: clicking the scrim (or pressing ESC) must
//    NOT close a dialog — only explicit buttons (Abbrechen/Schließen
//    or the X) do. md-dialog fires a cancelable 'cancel' event for
//    scrim clicks and ESC; preventing it keeps the dialog open.
// 2) Every dialog gets a maximize/restore button and an explicit
//    close (X) button in its headline, since light-dismiss is gone.
// ============================================================
(function () {
    document.addEventListener('cancel', (e) => {
        if (e.target && e.target.tagName === 'MD-DIALOG') e.preventDefault();
    }, true);

    function enhanceDialog(dlg) {
        // Some WebViews never flip Material's internal `.scrollable` class
        // (IntersectionObserver quirk) — the shadow `.scroller` then stays
        // `overflow:hidden`, the dialog clips its content, and the global
        // touch-lock treats the dead scroller as "background" so every
        // gesture is swallowed: the sheet freezes solid. Force overflow:auto
        // directly on the shadow scroller; it is identical to `.scrollable`
        // when content overflows and a no-op when it fits.
        const unlockScroller = () => {
            const sc = dlg.shadowRoot && dlg.shadowRoot.querySelector('.scroller');
            if (sc) { sc.style.overflowY = 'auto'; sc.style.overscrollBehaviorY = 'contain'; return true; }
            return false;
        };
        if (!unlockScroller()) {
            // Element may not be upgraded/rendered yet — wait for the custom
            // element definition (module scripts load deferred) and for Lit's
            // first render, then force the scroller once the shadow DOM exists.
            const afterRender = () => Promise.resolve(dlg.updateComplete).then(unlockScroller);
            if (customElements.get('md-dialog')) afterRender();
            else customElements.whenDefined('md-dialog').then(afterRender);
        }
        if (dlg.dataset.maxBtn === '1') return;
        const headline = dlg.querySelector('[slot="headline"]');
        if (!headline) return;
        dlg.dataset.maxBtn = '1';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dlg-max-btn';
        btn.title = 'Vollbild';
        btn.setAttribute('aria-label', 'Vollbild umschalten');
        btn.innerHTML = '<span class="material-symbols-outlined">fullscreen</span>';
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const max = dlg.classList.toggle('dlg-max');
            btn.innerHTML = `<span class="material-symbols-outlined">${max ? 'fullscreen_exit' : 'fullscreen'}</span>`;
            btn.title = max ? 'Verkleinern' : 'Vollbild';
        });
        headline.appendChild(btn);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'dlg-max-btn dlg-close-btn';
        closeBtn.title = 'Schließen';
        closeBtn.setAttribute('aria-label', 'Dialog schließen');
        closeBtn.innerHTML = '<span class="material-symbols-outlined">close</span>';
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dlg.close('close-button');
        });
        headline.appendChild(closeBtn);
    }

    function scanDialogs() {
        document.querySelectorAll('md-dialog').forEach(enhanceDialog);
    }
    if (document.body) {
        new MutationObserver(scanDialogs).observe(document.body, { childList: true, subtree: true });
        scanDialogs();
    }
})();

function closeModal() {
    if (typeof window._plannerViewportCleanup === 'function') window._plannerViewportCleanup();
    const modalOv = document.getElementById('modalOverlay');
    if (typeof modalOv.close === 'function') modalOv.close();
    else modalOv.removeAttribute('open');
    // The NU selection overlay lives at <body> level (outside the modal
    // DOM) — close it too so it can't linger above a closed modal.
    if (typeof plannerCloseNuAddOverlay === 'function') plannerCloseNuAddOverlay();
    syncBodyScrollLock();
    const modalHeader = document.querySelector('#modalOverlay .modal-header');
    if (modalHeader) {
        modalHeader.classList.remove('planner-modal-header');
        const plannerAction = modalHeader.querySelector('.planner-header-action');
        if (plannerAction) plannerAction.remove();
    }
    const dialog = document.querySelector('.modal-dialog') || document.querySelector('.modal');
    if (dialog) { dialog.classList.remove('modal-xl', 'modal-wide', 'nu-context', 'modal-max'); }
}

// ============================================
// SYSTEM CONFIRM DIALOG
// ============================================
function systemConfirm({ title = 'Bestätigung', message = '', confirmText = 'Bestätigen', cancelText = 'Abbrechen', icon = 'warning', type = 'warning' }) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        Object.assign(overlay.style, {
            position: 'fixed', top: '0', left: '0', right: '0', bottom: '0',
            zIndex: '10000', background: 'rgba(15,23,42,0.55)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            opacity: '0', transition: 'opacity 0.2s ease', backdropFilter: 'blur(4px)'
        });
        const colorMap = { warning: 'var(--md-ext-color-warning)', danger: 'var(--md-sys-color-error)', info: 'var(--md-ext-color-info)', success: 'var(--md-ext-color-success)' };
        const accent = colorMap[type] || colorMap.warning;
        overlay.innerHTML = `
            <div style="background:var(--white);border-radius:16px;padding:36px 32px 28px;box-shadow: 0 25px 60px color-mix(in srgb, var(--md-sys-color-scrim) 30%, transparent);max-width:440px;width:90%;text-align:center;transform:scale(0.92);transition:transform 0.2s ease">
                <div style="font-size:2.8rem;margin-bottom:16px;color:${accent}"><i class="material-symbols-outlined">${icon}</i></div>
                <div style="font-size:1.15rem;font-weight:700;color:var(--gray-900);margin-bottom:10px">${title}</div>
                <div style="font-size:0.9rem;color:var(--gray-500);line-height:1.6;margin-bottom:28px">${message}</div>
                <div style="display:flex;gap:12px;justify-content:center">
                    <md-outlined-button style="min-width:130px" data-action="cancel">${cancelText}</md-outlined-button>
                    <md-filled-button style="min-width:130px;--md-filled-button-container-color:${accent}" data-action="ok">${confirmText}</md-filled-button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        requestAnimationFrame(() => {
            overlay.style.opacity = '1';
            overlay.firstElementChild.style.transform = 'scale(1)';
        });
        const cleanup = (result) => {
            overlay.style.opacity = '0';
            overlay.firstElementChild.style.transform = 'scale(0.92)';
            setTimeout(() => overlay.remove(), 200);
            resolve(result);
        };
        overlay.querySelector('[data-action="cancel"]').onclick = () => cleanup(false);
        overlay.querySelector('[data-action="ok"]').onclick = () => cleanup(true);
        overlay.addEventListener('click', e => { if (e.target === overlay) cleanup(false); });
    });
}

// ============================================
// RETRY BANNER (shown when API calls fail)
// ============================================
function showRetryBanner() {
    if (document.getElementById('retryBanner')) return;
    const banner = document.createElement('div');
    banner.id = 'retryBanner';
    banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:9999;background:var(--danger-light, #fef2f2);color:var(--danger, #dc2626);text-align:center;padding:10px 16px;font-size:0.85rem;font-weight:600;border-bottom:2px solid #fecaca;display:flex;align-items:center;justify-content:center;gap:12px';
    banner.innerHTML = '<i class="material-symbols-outlined">warning</i> <span>Verbindungsfehler — Daten konnten nicht geladen werden.</span> <button onclick="document.getElementById(\'retryBanner\').remove();loadPage(currentPage)" style="background: var(--danger, var(--md-sys-color-error));color: var(--md-sys-color-on-error, #fff);border:none;padding:5px 16px;border-radius:6px;font-weight:600;cursor:pointer;font-size:0.8rem"><i class="material-symbols-outlined">refresh</i> Erneut versuchen</button> <button onclick="this.parentElement.remove()" style="background:none;border:none;color: var(--danger, var(--md-sys-color-error));cursor:pointer;font-size:1.1rem;padding:0 4px">&times;</button>';
    document.body.prepend(banner);
}

// ============================================
// SHARED UI HELPERS (always loaded — page modules may rely on these)
// ============================================
// Page modules are lazy-loaded (PAGE_MODULE_SCRIPTS), so any helper they
// use must live HERE, not inside another lazy module. custIcon/mdConfirm/
// mdAlert/mdPrompt used to live in customers.js/users.js only — opening a
// page that borrowed them (e.g. Nachunternehmer, Objekte) before visiting
// Kunden/Benutzer crashed with ReferenceError and left the spinner running
// forever.
function custIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}

// ---------- shared list pager (same look as the Bestelleingang pager) ----------
// Modules: const P = hmsPagerDef('<id>', 'Kunden', () => repaintFn());
//          const pg = hmsPage(filteredRows, P.st);   // slice + reset on change
//          markup:  <div id="<id>" class="be-pager"></div>   then
//          hmsPagerFill('<id>', pg)                    (or inline hmsPagerHtml)
// Page resets to 1 automatically when the filtered list's identity changes
// (first row + total), so no manual resets on search/filter are needed.
const HMS_PAGE_SIZE = 100;
const HMS_PAGERS = {};

function hmsPagerEnsureStyle() {
    if (document.getElementById('hmsPagerStyle')) return;
    const st = document.createElement('style');
    st.id = 'hmsPagerStyle';
    st.textContent = `
        .be-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--be-border,rgba(0,0,0,.08))}
        .be-pager:empty{display:none}
        .be-pg-info{font-size:.82rem;color:var(--be-text-muted,var(--gray-500))}
        .be-pg-info strong{color:var(--be-text,inherit)}
        .be-pg-nav{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
        .be-pg-btn{min-width:34px;height:34px;padding:0 8px;border-radius:17px;border:1px solid var(--be-border,rgba(0,0,0,.14));background:transparent;color:inherit;font:600 .82rem/1 inherit;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}
        .be-pg-btn:hover:not([disabled]){background:color-mix(in srgb,var(--be-accent,#2563eb) 10%,transparent)}
        .be-pg-btn[disabled]{opacity:.35;cursor:default}
        .be-pg-btn.is-on{background:var(--be-accent,#2563eb);border-color:var(--be-accent,#2563eb);color:#fff}
        .be-pg-gap{padding:0 4px;color:var(--be-text-muted,var(--gray-500))}
        @media (max-width:640px){.be-pager{justify-content:center}.be-pg-info{width:100%;text-align:center}}
    `;
    document.head.appendChild(st);
}

function hmsPagerDef(id, unit, redraw) {
    const P = HMS_PAGERS[id] || (HMS_PAGERS[id] = { st: { page: 1, per: HMS_PAGE_SIZE, sig: null } });
    P.unit = unit;
    P.redraw = redraw;
    return P;
}

function hmsPageList(page, last) {
    const set = new Set([1, last, page - 1, page, page + 1].filter(p => p >= 1 && p <= last));
    const arr = [...set].sort((a, b) => a - b);
    const out = [];
    arr.forEach((p, i) => { if (i && p - arr[i - 1] > 1) out.push('…'); out.push(p); });
    return out;
}

// Slice `items` to the current page. `sig` identifies the list; when it
// changes (filter/sort/data change) the page resets to 1. Default signature:
// first row's common id field + row count.
function hmsPage(items, st, sig) {
    const total = items.length;
    const key = (sig !== undefined ? String(sig)
        : (total ? String(items[0].id ?? items[0].eingang_id ?? items[0].dienst_nr ?? items[0].kunden_id
            ?? items[0].obj_id ?? items[0].bestell_id ?? items[0].artikel_id ?? items[0].dataset?.mk ?? '') : '')) + '|' + total;
    if (key !== st.sig) { st.sig = key; st.page = 1; }
    const last = Math.max(1, Math.ceil(total / st.per));
    st.page = Math.min(Math.max(1, st.page | 0), last);
    const start = (st.page - 1) * st.per;
    return { rows: items.slice(start, start + st.per), page: st.page, last, total, per: st.per, start };
}

function hmsPagerHtml(id, pg, unit) {
    if (!pg || !pg.total) return '';
    hmsPagerEnsureStyle();
    const from = (pg.page - 1) * pg.per + 1;
    const to = Math.min(pg.total, pg.page * pg.per);
    const ic = n => custIcon(n, 'font-size:18px');
    const btn = (p, label, title, dis) => `<button type="button" class="be-pg-btn" ${dis ? 'disabled' : ''} onclick="hmsPagerClick('${id}',${p})" title="${title}" aria-label="${title}">${label}</button>`;
    return `
        <div class="be-pg-info"><strong>${from}–${to}</strong> von <strong>${pg.total}</strong> ${escapeHtml(unit)} · ${pg.per} pro Seite</div>
        ${pg.last > 1 ? `<div class="be-pg-nav">
            ${btn(1, ic('first_page'), 'Erste Seite', pg.page <= 1)}
            ${btn(pg.page - 1, ic('chevron_left'), 'Vorherige Seite', pg.page <= 1)}
            ${hmsPageList(pg.page, pg.last).map(p => p === '…'
                ? '<span class="be-pg-gap">…</span>'
                : `<button type="button" class="be-pg-btn be-pg-num${p === pg.page ? ' is-on' : ''}" onclick="hmsPagerClick('${id}',${p})" ${p === pg.page ? 'aria-current="page"' : ''}>${p}</button>`).join('')}
            ${btn(pg.page + 1, ic('chevron_right'), 'Nächste Seite', pg.page >= pg.last)}
            ${btn(pg.last, ic('last_page'), 'Letzte Seite', pg.page >= pg.last)}
        </div>` : ''}`;
}

function hmsPagerFill(id, pg) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = hmsPagerHtml(id, pg, HMS_PAGERS[id]?.unit || 'Einträge');
}

function hmsPagerClear(id) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = '';
}

function hmsPagerClick(id, p) {
    const P = HMS_PAGERS[id];
    if (!P) return;
    P.st.page = p;
    P.redraw();
    const el = document.getElementById(id);
    if (el && el.getBoundingClientRect().top < 0) el.scrollIntoView({ block: 'start', behavior: 'smooth' });
}

// Promise-based replacements for window.confirm / window.alert / prompt.
function mdConfirm(message, { title = 'Bestätigen', confirmLabel = 'OK' } = {}) {
    return new Promise(resolve => {
        const dlg = document.createElement('md-dialog');
        dlg.innerHTML = `
          <div slot="headline">${title}</div>
          <div slot="content" style="white-space:pre-line">${message}</div>
          <div slot="actions">
            <md-text-button data-v="cancel">Abbrechen</md-text-button>
            <md-filled-button data-v="ok">${confirmLabel}</md-filled-button>
          </div>`;
        document.body.appendChild(dlg);
        dlg.querySelector('[data-v="cancel"]').onclick = () => dlg.close('cancel');
        dlg.querySelector('[data-v="ok"]').onclick = () => dlg.close('ok');
        dlg.addEventListener('closed', () => { const ok = dlg.returnValue === 'ok'; dlg.remove(); resolve(ok); });
        dlg.show();
    });
}
function mdAlert(message, title = 'Hinweis') {
    return new Promise(resolve => {
        const dlg = document.createElement('md-dialog');
        dlg.innerHTML = `
          <div slot="headline">${title}</div>
          <div slot="content" style="white-space:pre-line">${message}</div>
          <div slot="actions">
            <md-filled-button data-v="ok">Verstanden</md-filled-button>
          </div>`;
        document.body.appendChild(dlg);
        dlg.querySelector('[data-v="ok"]').onclick = () => dlg.close('ok');
        dlg.addEventListener('closed', () => { dlg.remove(); resolve(); });
        dlg.show();
    });
}
function mdPrompt(message, { title = 'Eingabe', type = 'password', label = '', value = '' } = {}) {
    return new Promise(resolve => {
        const dlg = document.createElement('md-dialog');
        dlg.innerHTML = `
          <div slot="headline">${title}</div>
          <div slot="content">
            <p style="margin:0 0 12px;white-space:pre-line">${message}</p>
            <md-outlined-text-field class="md-prompt-field" type="${type}" label="${label}" value="${escapeHtml(value)}" style="width:100%"></md-outlined-text-field>
          </div>
          <div slot="actions">
            <md-text-button data-v="cancel">Abbrechen</md-text-button>
            <md-filled-button data-v="ok">OK</md-filled-button>
          </div>`;
        document.body.appendChild(dlg);
        const field = dlg.querySelector('.md-prompt-field');
        dlg.querySelector('[data-v="cancel"]').onclick = () => dlg.close('cancel');
        dlg.querySelector('[data-v="ok"]').onclick = () => dlg.close('ok');
        dlg.addEventListener('closed', () => {
            const v = dlg.returnValue === 'ok' ? field.value : null;
            dlg.remove(); resolve(v);
        });
        dlg.show();
    });
}

// ============================================
// TOAST
// ============================================
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const icons = { success: 'check_circle', error: 'error', warning: 'warning' };
    toast.innerHTML = `<i class="material-symbols-outlined">${icons[type] || icons.success}</i><span class="toast-message"></span>`;
    const toastMsg = toast.querySelector('.toast-message');
    if (toastMsg) toastMsg.textContent = message;
    container.appendChild(toast);
    // md-dialog modals live in the browser TOP LAYER, which covers any
    // z-index. Promote the toast container into the top layer as a manual
    // popover so notifications always render ABOVE open dialogs.
    if (container.showPopover) {
        if (!container.hasAttribute('popover')) container.setAttribute('popover', 'manual');
        if (!container.matches(':popover-open')) { try { container.showPopover(); } catch (_) {} }
    }
    setTimeout(() => {
        toast.remove();
        // Hide the (invisible) popover again once no toast is left, so the
        // empty container can never sit above the UI.
        if (container.matches && container.matches(':popover-open') && !container.querySelector('.toast')) {
            try { container.hidePopover(); } catch (_) {}
        }
    }, 3500);
}

// ============================================
// BADGE
// ============================================
async function updateIncomingBadge() {
    // No-op: badge removed — BEs are now auto-processed on creation
}

// ============================================
// HELPERS
// ============================================
function formatCurrency(val) {
    if (val === null || val === undefined || val === '') return '–';
    return parseFloat(val).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
}

function formatDate(d) {
    if (!d) return '–';
    const parts = d.split('-');
    return `${parts[2]}.${parts[1]}.${parts[0]}`;
}

// Server timestamps (created_at/updated_at) are stored in UTC as
// 'YYYY-MM-DD HH:MM:SS' → 'TT.MM.JJJJ HH:MM' in German local time.
function formatDateTimeLocal(ts) {
    if (!ts) return '–';
    const d = new Date(String(ts).replace(' ', 'T').substring(0, 19) + 'Z');
    if (isNaN(d)) return formatDate(String(ts).substring(0, 10)) + ' ' + String(ts).substring(11, 16);
    return d.toLocaleString('de-DE', { timeZone: 'Europe/Berlin', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).replace(',', '');
}

function formatTime(t) {
    if (!t) return '–';
    return t.substring(0, 5);
}

function statusBadge(status) {
    if (!status) return '';
    const s = String(status);
    return `<span class="status status-${escapeHtml(s)}">${escapeHtml(s.replace('_', ' '))}</span>`;
}

function taskStatusBadge(task) {
    if (!task) return '';
    const effectiveStatus = task.hms_bestaetigt === 'ja'
        ? 'ja'
        : ((task.pruefung || '') === 'geplant' ? 'geplant' : task.hms_bestaetigt);
    return statusBadge(effectiveStatus);
}

/**
 * Render a small badge for a Schichtart (German wage-surcharge category).
 * Returns '' for plain Tagdienst with 0 % so the UI stays clutter-free
 * on the common case.
 *
 * @param {string} schichtart  one of: tagdienst, nachtdienst, sonntag,
 *                             feiertag, nacht_sonntag, nacht_feiertag
 * @param {number|null} pct    surcharge percentage (e.g. 25). When null
 *                             or 0 only the icon + label is shown.
 * @param {object} [opts]      { showPct: boolean, compact: boolean }
 */
function schichtartBadge(schichtart, pct = null, opts = {}) {
    if (!schichtart) return '';
    const code = String(schichtart).toLowerCase();
    if (code === 'tagdienst' && (!pct || pct === 0)) return '';

    const meta = {
        tagdienst:      { icon: 'light_mode',          label: 'Tag' },
        nachtdienst:    { icon: 'dark_mode',         label: 'Nacht' },
        sonntag:        { icon: 'calendar_today', label: 'Sonntag' },
        feiertag:       { icon: 'star',         label: 'Feiertag' },
        nacht_sonntag:  { icon: 'dark_mode',         label: 'Nacht+So' },
        nacht_feiertag: { icon: 'dark_mode',         label: 'Nacht+Feiertag' },
    };
    const m = meta[code] || { icon: 'schedule', label: code };
    const showPct = opts.showPct !== false && pct != null && pct > 0;
    const pctStr  = showPct ? ` +${parseFloat(pct).toLocaleString('de-DE', { maximumFractionDigits: 1 })}%` : '';
    const lbl     = opts.compact ? '' : ` ${m.label}`;
    return `<span class="schichtart-badge schichtart-${code}" title="${m.label}${pctStr}"><i class="material-symbols-outlined">${m.icon}</i>${lbl}${pctStr}</span>`;
}

let _cachedBereiche = null;
async function getBereichSuggestions() {
    if (_cachedBereiche) return _cachedBereiche;
    const data = await apiGet(API.bereiche);
    _cachedBereiche = Array.isArray(data) ? data : [];
    return _cachedBereiche;
}
function invalidateBereichCache() { _cachedBereiche = null; }
function bereichDatalistHtml(id) {
    return `<datalist id="${id}"></datalist>`;
}
/* ── Multi-select dropdown (system-styled) ─────────────────────────
   Outlined field showing selected values as chips; opens a checkbox
   panel on click. collect*Checks() still reads the checked inputs so
   existing callers stay unchanged. */
let _mselCssDone = false;
function _mselEnsureCss() {
    if (_mselCssDone) return;
    _mselCssDone = true;
    const st = document.createElement('style');
    st.textContent = `
.msel{position:relative;font:inherit}
.msel-field{display:flex;align-items:center;gap:5px;flex-wrap:wrap;width:100%;min-height:50px;padding:7px 38px 7px 12px;border:1px solid var(--gray-300,#cbd5e1);border-radius:8px;background:var(--white,#fff);cursor:pointer;position:relative;box-sizing:border-box}
.msel-field:hover{border-color:var(--gray-400,#94a3b8)}
.msel.open .msel-field{border-color:var(--primary,#1a73e8);box-shadow:0 0 0 1px var(--primary,#1a73e8)}
.msel-caret{position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--gray-500);pointer-events:none;font-size:22px}
.msel-chips{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.msel-ph{color:var(--gray-500,#64748b);font-size:.9rem}
.msel-chip{display:inline-flex;align-items:center;gap:2px;background:var(--accent-soft,#e8f0fe);color:var(--primary,#1a73e8);border-radius:999px;padding:3px 6px 3px 10px;font-size:.8rem;font-weight:500;white-space:nowrap}
.msel-chip button{border:0;background:none;color:inherit;cursor:pointer;font-size:15px;line-height:1;padding:0 1px;font-family:'Material Symbols Outlined';border-radius:50%;display:inline-flex;align-items:center}
.msel-panel{display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:90;background:var(--white,#fff);border:1px solid var(--gray-200,#e2e8f0);border-radius:8px;box-shadow:0 10px 28px rgba(15,23,42,.14);max-height:220px;overflow:auto}
.msel.open .msel-panel{display:block}
.msel-opt{display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;font-size:.88rem;color:var(--gray-700);margin:0;text-transform:none;letter-spacing:normal;font-weight:400}
.msel-opt:hover{background:var(--gray-50,#f8fafc)}
.msel-opt input{accent-color:var(--primary,#1a73e8);width:16px;height:16px;cursor:pointer;flex:none}
.msel-empty{padding:12px;color:var(--gray-500);font-size:.85rem}
.msel .msel-opt{text-transform:none;letter-spacing:normal;font-weight:400;font-size:.88rem;color:var(--gray-700)}`;
    document.head.appendChild(st);
}

function _mselBuild(containerId, options, selected, placeholder, emptyText, primary = null, colored = false) {
    const el = document.getElementById(containerId);
    if (!el) return;
    _mselEnsureCss();
    const selLower = new Set(selected.map(v => String(v).trim().toLowerCase()));
    const primLower = primary ? String(primary).trim().toLowerCase() : '';
    // Stored values missing from the option list stay visible & checked.
    const opts = [...options];
    for (const s of selected) {
        if (!opts.some(o => String(o).trim().toLowerCase() === String(s).trim().toLowerCase())) opts.push(String(s));
    }
    el.innerHTML = `
      <div class="msel" id="${containerId}_msel">
        <div class="msel-field" onclick="mselToggle('${containerId}')">
          <span class="msel-chips" id="${containerId}_chips"></span>
          <span class="material-symbols-outlined msel-caret">expand_more</span>
        </div>
        <div class="msel-panel">${opts.map(v => `
          <label class="msel-opt"><md-checkbox value="${escapeHtml(v)}" ${selLower.has(String(v).trim().toLowerCase()) ? 'checked' : ''} ${primLower && String(v).trim().toLowerCase() === primLower ? 'data-msel-primary="1"' : ''} onchange="_mselRefresh('${containerId}')"></md-checkbox>${colored ? bereichDotHtml(v, false) : ''}<span>${escapeHtml(v)}</span></label>`).join('')
          || `<div class="msel-empty">${emptyText}</div>`}</div>
      </div>`;
    el.dataset.mselPh = placeholder;
    el.dataset.mselColored = colored ? '1' : '';
    _mselRefresh(containerId);
}

// Effective primary tag of a multi-select: the stored primary when still
// checked, else the first checked option. Keeps `bereich` aligned with
// the visible list without a separate free-text field.
function mselPrimary(containerId) {
    const prim = document.querySelector('#' + containerId + ' input[data-msel-primary]:checked, #' + containerId + ' md-checkbox[data-msel-primary][checked]');
    if (prim) return prim.value;
    const first = document.querySelector('#' + containerId + ' input[type=checkbox]:checked, #' + containerId + ' md-checkbox[checked]');
    return first ? first.value : null;
}

function _mselRefresh(containerId) {
    const chips = document.getElementById(containerId + '_chips');
    if (!chips) return;
    const boxes = [...document.querySelectorAll('#' + containerId + ' input[type=checkbox], #' + containerId + ' md-checkbox')];
    const vals = boxes.map((b, i) => b.checked ? i : -1).filter(i => i >= 0);
    const colored = document.getElementById(containerId).dataset.mselColored === '1';
    chips.innerHTML = vals.length
        ? vals.map(i => `<span class="msel-chip${colored ? ' bc-chip' : ''}"${colored ? ` style="${bereichColorVars(boxes[i].value)}"` : ''}>${escapeHtml(boxes[i].value)}<button type="button" onclick="event.stopPropagation();_mselUncheck('${containerId}',${i})">close</button></span>`).join('')
        : `<span class="msel-ph">${escapeHtml(document.getElementById(containerId).dataset.mselPh || 'Auswählen…')}</span>`;
}
function _mselUncheck(containerId, idx) {
    const boxes = document.querySelectorAll('#' + containerId + ' input[type=checkbox], #' + containerId + ' md-checkbox');
    if (boxes[idx]) { boxes[idx].checked = false; boxes[idx].dispatchEvent(new Event('change', { bubbles: true })); }
}
function mselToggle(containerId) {
    const m = document.getElementById(containerId + '_msel');
    if (!m) return;
    const wasOpen = m.classList.contains('open');
    document.querySelectorAll('.msel.open').forEach(x => x.classList.remove('open'));
    if (!wasOpen) m.classList.add('open');
}
document.addEventListener('click', e => {
    document.querySelectorAll('.msel.open').forEach(m => {
        if (!m.contains(e.target)) m.classList.remove('open');
    });
});

// Multi-Bereich picker: a customer/object/worker/NU can carry several
// services (Reinigung + Sicherheit + …). `selected` accepts array or JSON.
// `primary` marks the stored Hauptbereich so it stays primary while checked.
async function renderBereicheChecks(containerId, selected = [], primary = null) {
    let sel = selected;
    if (typeof sel === 'string') { try { sel = JSON.parse(sel) || []; } catch { sel = []; } }
    if (!Array.isArray(sel)) sel = [];
    const bereiche = await getBereichSuggestions();
    await ensureBereichColors();
    _mselBuild(containerId, bereiche, sel, 'Dienstleistungen wählen…', 'Keine Bereiche vorhanden', primary, true);
}
function collectBereicheChecks(containerId) {
    return [...document.querySelectorAll('#' + containerId + ' input[type=checkbox]:checked, #' + containerId + ' md-checkbox[checked]')].map(i => i.value);
}
// All service tags of a customer/object: primary bereich + bereiche list,
// comma-split for legacy values, de-duplicated, canonical order.
function bereichTagsOf(entity) {
    if (!entity) return [];
    let vals = entity.bereiche;
    if (typeof vals === 'string') { try { vals = JSON.parse(vals) || []; } catch { vals = []; } }
    if (!Array.isArray(vals)) vals = [];
    if (entity.bereich) vals = [entity.bereich, ...vals];
    return [...new Set(vals.flatMap(v => String(v).split(',')).map(s => s.trim()).filter(Boolean))];
}
function bereicheBadgesHtml(primary, list) {
    let vals = list;
    if (typeof vals === 'string') { try { vals = JSON.parse(vals) || []; } catch { vals = []; } }
    if (!Array.isArray(vals) || !vals.length) vals = primary ? [primary] : [];
    if (primary && !vals.some(v => String(v).trim().toLowerCase() === String(primary).trim().toLowerCase())) vals = [primary, ...vals];
    if (!vals.length) return '–';
    return vals.map(b => bereichBadgeHtml(b)).join(' ');
}

/* ── Bereich colours ────────────────────────────────────────────────
   One colour per service area, chosen under Konto & Firma → Bereiche
   (bereiche_katalog.farbe; subs inherit their main). EVERY Bereich chip,
   badge, dot and chart in the app takes its colour from bereichColor(),
   so a change there is reflected everywhere. Without a chosen colour the
   palette colour of the catalog ID is used (distinct per Hauptbereich and
   the same for every user — not the list position, which differs for
   restricted users). Names outside the catalog get a name-hash colour. */
const BEREICH_PALETTE = ['#2563eb', '#16a34a', '#d97706', '#7c3aed', '#db2777', '#0891b2',
    '#dc2626', '#65a30d', '#9333ea', '#0d9488', '#ea580c', '#4f46e5'];
let _bereichColors = null;          // lower(name) → '#rrggbb'
let _bereichColorsPromise = null;
let _bereichColorsRt = false;

function ensureBereichColors(force = false) {
    // Realtime may connect after the first page load → (re)try the hook
    // on every call, before the cache short-circuit.
    if (!_bereichColorsRt && window.HMSRealtime && typeof HMSRealtime.on === 'function') {
        HMSRealtime.on('bereiche', () => ensureBereichColors(true));
        _bereichColorsRt = true;
    }
    if (_bereichColors && !force) return Promise.resolve(_bereichColors);
    if (_bereichColorsPromise && !force) return _bereichColorsPromise;
    _bereichColorsPromise = (async () => {
        const map = {};
        let loaded = false;
        try {
            const d = await apiGet(API.bereich_catalog);
            loaded = !!(d && Array.isArray(d.mains));
            (loaded ? d.mains : []).forEach(m => {
                // Automatic colour from the catalog id: distinct for every
                // Hauptbereich (no hash collisions) and the same for all users.
                const auto = Number.isInteger(m.id) && m.id > 0 ? BEREICH_PALETTE[(m.id - 1) % BEREICH_PALETTE.length] : _bereichHashColor(m.name);
                const c = /^#[0-9a-f]{6}$/i.test(m.farbe || '') ? m.farbe.toLowerCase() : auto;
                map[String(m.name).trim().toLowerCase()] = c;
                (m.subs || []).forEach(s => {
                    const k = String(s.name).trim().toLowerCase();
                    if (!map[k]) map[k] = c;
                });
            });
        } catch (e) { /* offline → name-based palette */ }
        // Failed load: don't cache — the next page load retries, otherwise
        // the session would stay on fallback colours until a reload.
        _bereichColors = loaded ? map : null;
        _bereichColorsPromise = null;
        return map;
    })();
    return _bereichColorsPromise;
}
window.ensureBereichColors = ensureBereichColors;

function _bereichHashColor(name) {
    const s = String(name || '').trim().toLowerCase();
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return BEREICH_PALETTE[h % BEREICH_PALETTE.length];
}

/** '#rrggbb' of a Bereich (main or sub name). */
function bereichColor(name) {
    const k = String(name || '').trim().toLowerCase();
    if (!k || k === '–' || k === '-') return '#94a3b8';
    return (_bereichColors && _bereichColors[k]) || _bereichHashColor(k);
}

// Mix a hex colour towards white (t>0) or black (t<0), t in [-1, 1].
function _bereichMix(hex, t) {
    const n = parseInt(hex.slice(1), 16);
    const ch = [n >> 16, (n >> 8) & 255, n & 255].map(v => Math.round(t >= 0 ? v + (255 - v) * t : v * (1 + t)));
    return '#' + ch.map(v => v.toString(16).padStart(2, '0')).join('');
}

/** Inline CSS custom properties for a Bereich: --bc, --bc-bg, --bc-bd, --bc-fg, --bc-fg-dk. */
function bereichColorVars(name) {
    const c = bereichColor(name);
    return `--bc:${c};--bc-bg:${c}1f;--bc-bd:${c}59;--bc-fg:${_bereichMix(c, -0.35)};--bc-fg-dk:${_bereichMix(c, 0.45)}`;
}

/** Light-mode {bg, fg, bd} triple (theme-aware) for code that styles directly. */
function bereichPalette(name) {
    const c = bereichColor(name);
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    return { c, bg: c + (dark ? '29' : '1f'), fg: dark ? _bereichMix(c, 0.45) : _bereichMix(c, -0.35), bd: c + '59' };
}

function bereichBadgeHtml(name, extraClass = '') {
    if (!name) return '';
    return `<span class="bereich-badge bc-chip ${extraClass}" style="${bereichColorVars(name)}">${escapeHtml(name)}</span>`;
}
function bereichDotHtml(name, title = true) {
    if (!name) return '';
    return `<span class="bc-dot" style="${bereichColorVars(name)}"${title ? ` title="${escapeHtml(name)}"` : ''}></span>`;
}
window.bereichColor = bereichColor;
window.bereichColorVars = bereichColorVars;
window.bereichPalette = bereichPalette;
window.bereichBadgeHtml = bereichBadgeHtml;
window.bereichDotHtml = bereichDotHtml;

// Shared chip / dot styling (works on top of any existing badge class).
(function () {
    const st = document.createElement('style');
    st.id = 'hmsBereichColorCss';
    st.textContent = `
.bc-chip{background:var(--bc-bg)!important;color:var(--bc-fg)!important;border:1px solid var(--bc-bd)!important}
[data-theme="dark"] .bc-chip{color:var(--bc-fg-dk)!important}
.bc-chip i,.bc-chip .material-symbols-outlined{color:inherit!important}
.bc-pill{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;font-size:.75rem;font-weight:600;line-height:1.5;white-space:nowrap}
.bc-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--bc);flex:none;vertical-align:middle;box-shadow:0 0 0 2px var(--bc-bg)}`;
    (document.head || document.documentElement).appendChild(st);
})();
async function populateBereichDatalist(datalistId) {
    const bereiche = await getBereichSuggestions();
    const dl = document.getElementById(datalistId);
    if (dl) dl.innerHTML = bereiche.map(b => `<md-select-option value="${b}">`).join('');
}

// Single-select dropdown fed by the managed catalog — used where a field
// must offer exactly one Bereich and free typing is not allowed
// (e.g. artikel.bereich, which drives the Einsatz scope). A stored value
// missing from the catalog is still offered so saves keep passing.
async function populateBereichSelect(selectId, current = null, options = null) {
    const el = document.getElementById(selectId);
    if (!el) return;
    const bereiche = Array.isArray(options) && options.length ? options : await getBereichSuggestions();
    const vals = [...bereiche];
    const cur = current ? String(current).trim() : '';
    if (cur && !vals.some(v => String(v).trim().toLowerCase() === cur.toLowerCase())) vals.unshift(cur);
    el.innerHTML = vals.map(v =>
        `<md-select-option value="${escapeHtml(v)}" ${cur && String(v).trim().toLowerCase() === cur.toLowerCase() ? 'selected' : ''}><div slot="headline">${escapeHtml(v)}</div></md-select-option>`
    ).join('');
    if (cur) el.value = cur;
}

// Managed catalog: Unterbereiche (subs of mains) feed the Objektart
// multi-select — scope-filtered server-side for restricted users.
let _cachedBereichSubs = null;
async function getBereichSubSuggestions() {
    if (_cachedBereichSubs) return _cachedBereichSubs;
    try {
        const data = await apiGet(API.bereich_catalog + '?subs=1');
        _cachedBereichSubs = Array.isArray(data) ? data : [];
    } catch { _cachedBereichSubs = []; }
    return _cachedBereichSubs;
}
async function renderArtenChecks(containerId, selected = [], primary = null, options = null) {
    let sel = selected;
    if (typeof sel === 'string') { try { sel = JSON.parse(sel) || []; } catch { sel = []; } }
    if (!Array.isArray(sel)) sel = [];
    const subs = Array.isArray(options) ? options : await getBereichSubSuggestions();
    await ensureBereichColors();
    _mselBuild(containerId, subs, sel, 'Einsatzarten wählen…',
        'Noch keine Einsatzarten angelegt — Konto &amp; Firma → Sicherheitsbereich → Einsatzarten', primary, true);
}
function collectArtenChecks(containerId) {
    return [...document.querySelectorAll('#' + containerId + ' input[type=checkbox]:checked, #' + containerId + ' md-checkbox[checked]')].map(i => i.value);
}
function invalidateBereichSubsCache() { _cachedBereichSubs = null; }

async function getCustomerOptions(bereich) {
    let url = API.customers + '?aktiv=1';
    if (bereich) url += '&bereich=' + encodeURIComponent(bereich);
    const data = await apiGet(url);
    return data.map(c => `<md-select-option value="${escapeHtml(c.kunde_id)}">${escapeHtml(c.kunde_id)} – ${escapeHtml(c.kundenname)}</md-select-option>`).join('');
}

async function getObjectOptions(kundenId) {
    const url = kundenId ? API.objects + `?kunden_id=${kundenId}&aktiv=1` : API.objects + '?aktiv=1';
    const data = await apiGet(url);
    return data.map(o => `<md-select-option value="${escapeHtml(o.obj_id)}">${escapeHtml(o.obj_id)} – ${escapeHtml(o.objektname)}</md-select-option>`).join('');
}

async function getSubcontractorOptions() {
    const data = await apiGet(API.subcontractors + '?aktiv=1');
    return data.map(n => `<md-select-option value="${escapeHtml(n.nu_id)}">${escapeHtml(n.nu_id)} – ${escapeHtml(n.firmenname)}</md-select-option>`).join('');
}

async function getOrderOptions() {
    const data = await apiGet(API.orders);
    return data.map(o => `<md-select-option value="${escapeHtml(o.bestell_id)}">${escapeHtml(o.bestell_id)} – ${escapeHtml(o.objektname || o.obj_id)}</md-select-option>`).join('');
}

// ============================================
// DASHBOARD — moved to assets/js/dashboard.js
// renderDashboard() is now defined in dashboard.js.
// ============================================

// ============================================
// GENERIC TABLE FILTER
// ============================================
function filterTable(inputId, tableId) {
    const q = document.getElementById(inputId).value.toLowerCase();
    const rows = document.getElementById(tableId).querySelectorAll('tr');
    rows.forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// ============================================
// PORTAL LINK GENERATOR
// ============================================
function togglePortalLink(type) {
    const slug = (typeof CURRENT_TENANT !== 'undefined' && CURRENT_TENANT.slug) ? CURRENT_TENANT.slug : '';
    if (!slug) { showToast('Kein Firmen-Slug vorhanden', 'error'); return; }

    const base = window.location.origin;
    const barId = type === 'nu' ? 'nuPortalLinkBar' : 'workerPortalLinkBar';
    const urlId = type === 'nu' ? 'nuPortalLinkUrl' : 'workerPortalLinkUrl';
    const link = type === 'nu'
        ? base + '/' + slug + '/nu_login.php'
        : base + '/' + slug + '/worker/login.php';

    const bar = document.getElementById(barId);
    const urlEl = document.getElementById(urlId);
    if (bar && urlEl) {
        urlEl.textContent = link;
        bar.style.display = bar.style.display === 'none' ? 'block' : 'none';
    }
}

function copyPortalLink(type) {
    const slug = (typeof CURRENT_TENANT !== 'undefined' && CURRENT_TENANT.slug) ? CURRENT_TENANT.slug : '';
    if (!slug) return;

    const base = window.location.origin;
    const barId = type === 'nu' ? 'nuPortalLinkBar' : 'workerPortalLinkBar';
    const link = type === 'nu'
        ? base + '/' + slug + '/nu_login.php'
        : base + '/' + slug + '/worker/login.php';

    navigator.clipboard.writeText(link).then(() => {
        showToast('Link kopiert!', 'success');
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = link;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('Link kopiert!', 'success');
    });

    // Hide the bar
    const bar = document.getElementById(barId);
    if (bar) bar.style.display = 'none';
}

// ============================================================
// TOP HORIZONTAL SCROLLBAR for wide list tables
// --------------------------------------------
// Injects a synced scrollbar ABOVE every scrollable .table-container
// inside #content, so users can scroll right to reach hidden columns
// / action buttons without scrolling to the bottom of a long table.
// Pure enhancement: no per-module markup changes required.
// ============================================================
(function () {
    function syncWidth(container, bar) {
        const inner = bar.firstElementChild;
        if (!inner) return;
        const sw = container.scrollWidth;
        const cw = container.clientWidth;
        // Pin the bar to the container's visible width so both have the
        // SAME scroll range — otherwise the sync below can push the table
        // beyond its last column (WebKit doesn't clamp a too-large
        // scrollLeft during momentum scrolling).
        bar.style.width = cw + 'px';
        inner.style.width = sw + 'px';
        const overflows = sw - cw > 1;
        bar.style.display = overflows ? 'block' : 'none';
        container.classList.toggle('has-top-scroll', overflows);
        if (overflows) bar.scrollLeft = container.scrollLeft;
    }
    const clampScroll = (el, x) => Math.max(0, Math.min(x, el.scrollWidth - el.clientWidth));

    // Floating left/right buttons for each scrollable container.
    // The buttons are position:fixed (viewport-anchored) and follow the
    // VISIBLE part of their table, so they stay reachable no matter how
    // deep the user has scrolled inside a long table.
    // (position:sticky can't do this: the container's own overflow-x
    // scrollport would swallow the vertical stickiness.)
    const hnavContainers = [];

    function positionNav(container) {
        const h = container._hnav;
        if (!h) return;
        const max = container.scrollWidth - container.clientWidth;
        const rect = container.getBoundingClientRect();
        const vh = window.innerHeight;
        // Visible vertical slice of the container inside the viewport
        const visTop = Math.max(rect.top, 70);
        const visBottom = Math.min(rect.bottom, vh - 10);
        const visible = container.isConnected
            && container.offsetParent !== null
            && max > 1
            && (visBottom - visTop) > 90
            // Never float arrows over modal/dialog content — they are meant
            // for wide tables on the main page only.
            && !container.closest('.modal-overlay, md-dialog, .wp-panel');
        h.nav.style.display = visible ? '' : 'none';
        if (!visible) return;
        const y = Math.round((visTop + visBottom) / 2 - 20);
        h.btnL.style.top = y + 'px';
        h.btnR.style.top = y + 'px';
        h.btnL.style.left = Math.round(rect.left + 6) + 'px';
        h.btnR.style.left = Math.round(rect.right - 46) + 'px';
        h.btnL.classList.toggle('is-hidden', container.scrollLeft <= 1);
        h.btnR.classList.toggle('is-hidden', container.scrollLeft >= max - 1);
    }

    function positionAllNavs() {
        for (let i = hnavContainers.length - 1; i >= 0; i--) {
            const c = hnavContainers[i];
            if (!c.isConnected) {
                // Page switched — container is gone, drop its orphaned buttons.
                if (c._hnav) c._hnav.nav.remove();
                c._hnav = null;
                hnavContainers.splice(i, 1);
                continue;
            }
            positionNav(c);
        }
    }

    function addNavButtons(container) {
        if (!container || container.dataset.hnav === '1') return;
        container.dataset.hnav = '1';

        const nav = document.createElement('div');
        nav.className = 'hscroll-nav';
        nav.innerHTML = `
            <button type="button" class="hscroll-btn hscroll-left" aria-label="Nach links scrollen" title="Nach links scrollen"><span class="material-symbols-outlined">chevron_left</span></button>
            <button type="button" class="hscroll-btn hscroll-right" aria-label="Nach rechts scrollen" title="Nach rechts scrollen"><span class="material-symbols-outlined">chevron_right</span></button>`;
        document.body.appendChild(nav);

        const btnL = nav.querySelector('.hscroll-left');
        const btnR = nav.querySelector('.hscroll-right');
        container._hnav = { nav, btnL, btnR };
        hnavContainers.push(container);

        const step = () => Math.max(240, Math.round(container.clientWidth * 0.6));
        // scrollTo with a clamped target instead of scrollBy: on WebKit,
        // repeated smooth scrollBy calls can overshoot past the last
        // column and stay there (iPad showed half an empty card).
        btnL.addEventListener('click', (e) => {
            e.stopPropagation();
            container.scrollTo({ left: clampScroll(container, container.scrollLeft - step()), behavior: 'smooth' });
        });
        btnR.addEventListener('click', (e) => {
            e.stopPropagation();
            container.scrollTo({ left: clampScroll(container, container.scrollLeft + step()), behavior: 'smooth' });
        });

        const update = () => positionNav(container);
        container.addEventListener('scroll', update, { passive: true });
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(update);
            ro.observe(container);
            const tbl = container.querySelector('table');
            if (tbl) ro.observe(tbl);
        }
        update();
    }

    // Any scroll anywhere (window or an inner scroller) moves the tables
    // relative to the viewport — reposition the fixed buttons.
    let hnavRaf = 0;
    function scheduleNavReposition() {
        if (hnavRaf) return;
        hnavRaf = requestAnimationFrame(() => {
            hnavRaf = 0;
            positionAllNavs();
        });
    }
    document.addEventListener('scroll', scheduleNavReposition, true);
    window.addEventListener('resize', scheduleNavReposition);

    function enhance(container) {
        if (!container || container.dataset.topScroll === '1') return;
        const parent = container.parentNode;
        if (!parent) return;
        const ownBar = container.previousElementSibling;
        if (ownBar && /TopScroll$/.test(ownBar.id || '')) {
            container.dataset.topScroll = 'native';
            return;
        }
        while (container.previousElementSibling && container.previousElementSibling.classList.contains('hms-topscroll')) {
            container.previousElementSibling.remove();
        }
        container.dataset.topScroll = '1';

        const bar = document.createElement('div');
        bar.className = 'table-top-scroll';
        const inner = document.createElement('div');
        inner.className = 'table-top-scroll-inner';
        bar.appendChild(inner);
        parent.insertBefore(bar, container);

        let lock = false;
        bar.addEventListener('scroll', () => {
            if (lock) { lock = false; return; }
            lock = true;
            container.scrollLeft = clampScroll(container, bar.scrollLeft);
        });
        container.addEventListener('scroll', () => {
            if (lock) { lock = false; return; }
            lock = true;
            bar.scrollLeft = clampScroll(bar, container.scrollLeft);
        });

        syncWidth(container, bar);
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(() => syncWidth(container, bar));
            ro.observe(container);
            const tbl = container.querySelector('table');
            if (tbl) ro.observe(tbl);
        }
    }

    function initTopScrollbars() {
        const root = document.getElementById('content');
        if (!root) return;
        root.querySelectorAll('.table-container, .or-table-wrap, .or-sub-table-wrap').forEach((c) => {
            enhance(c);
            addNavButtons(c);
        });
        // Horizontally scrollable non-table layouts (e.g. the Dispo week
        // grid) get the floating nav buttons too, but no top scrollbar.
        root.querySelectorAll('.planner-grid').forEach(addNavButtons);
        // Re-measure already-enhanced bars (row content can change width).
        root.querySelectorAll('.table-container[data-top-scroll="1"], .or-table-wrap[data-top-scroll="1"], .or-sub-table-wrap[data-top-scroll="1"]').forEach((c) => {
            while (c.previousElementSibling && c.previousElementSibling.classList.contains('hms-topscroll')) {
                c.previousElementSibling.remove();
            }
            const bar = c.previousElementSibling;
            if (bar && bar.classList.contains('table-top-scroll')) syncWidth(c, bar);
        });
    }
    window.initTopScrollbars = initTopScrollbars;

    function boot() {
        const root = document.getElementById('content');
        if (!root) return;
        initTopScrollbars();

        let t;
        const schedule = () => { clearTimeout(t); t = setTimeout(initTopScrollbars, 120); };
        new MutationObserver(schedule).observe(root, { childList: true, subtree: true });
        window.addEventListener('resize', schedule);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

// No browser autofill/history suggestions in the admin app (user decision):
// every text-like field without an explicit autocomplete gets "off" — also
// fields rendered later (dialogs, wizards). Material text fields forward the
// attribute to their inner input. Password fields are left to the browser.
(function () {
    const SEL = 'input:not([autocomplete]):not([type=password]):not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]):not([type=color]):not([type=range]),'
        + 'textarea:not([autocomplete]),md-outlined-text-field:not([autocomplete]):not([type=password]),md-filled-text-field:not([autocomplete]):not([type=password])';
    const apply = (root) => {
        if (root.nodeType !== 1) return;
        if (root.matches(SEL)) root.setAttribute('autocomplete', 'off');
        root.querySelectorAll(SEL).forEach(el => el.setAttribute('autocomplete', 'off'));
    };
    const start = () => {
        apply(document.body);
        new MutationObserver(muts => muts.forEach(m => m.addedNodes.forEach(apply)))
            .observe(document.body, { childList: true, subtree: true });
    };
    document.body ? start() : document.addEventListener('DOMContentLoaded', start);
})();
