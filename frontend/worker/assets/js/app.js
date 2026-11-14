// Tenant name of the shift log (Wachbuch / Objektbuch / …) from the shifts API.
let W_OB_LABEL = (() => { try { return localStorage.getItem('w_ob_label') || 'Wachbuch'; } catch (e) { return 'Wachbuch'; } })();
/* ============================================
   Clood-X Mitarbeiter App — Main SPA Logic
   Universal: runs identically in the PWA (clood-x.com/worker/)
   and inside the Capacitor wrapper (Android/iOS). All native
   differences are isolated to the IS_CAPACITOR / ASSETS_BASE /
   LOGIN_URL constants below.
   ============================================ */

// Set synchronously the moment this file executes — the boot watchdog in
// index.php uses it to tell "script never loaded" (hung fetch) apart from
// a still-running bootstrap.
window.__wAppLoaded = true;

// Detect Capacitor at runtime — the global is injected by the
// Capacitor runtime before our scripts execute on native.
const IS_CAPACITOR = (typeof window.Capacitor !== 'undefined'
    && typeof window.Capacitor.isNativePlatform === 'function'
    && window.Capacitor.isNativePlatform());

// Backend base URL: relative on the web (works on any subdomain),
// hard-coded to the production host inside the native shell.
const W_API_BASE = IS_CAPACITOR
    ? 'https://clood-x.com/api'
    : (window.location.origin + '/api');

// Asset base for worker photos etc. On the web these live one
// directory up from /worker/ (e.g. /uploads/workers/...). The
// native shell has no local filesystem for those, so it loads
// them from the production CDN.
const ASSETS_BASE = IS_CAPACITOR ? 'https://clood-x.com/' : '../';

// ============================================
// 24h TIME MASK — native type="time" follows the
// OS locale (shows AM/PM on 12h systems). Instead
// use text inputs with class "t24": digits only,
// auto-colon, normalized to HH:MM on blur.
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

// Login page filename. Decide by WHERE THIS PAGE WAS LOADED FROM, not by the
// presence of Capacitor: the native shell may load the UI either from its
// bundled assets (capacitor://localhost, http://localhost → login.html
// exists there) or remotely from clood-x.com/worker/ (only login.php exists
// on the server — requesting login.html there ends in an nginx 404 on iOS).
const IS_BUNDLED_UI = /^(capacitor|ionic|file):$/.test(window.location.protocol)
    || window.location.hostname === 'localhost'
    || window.location.hostname === '127.0.0.1';
const LOGIN_URL = IS_BUNDLED_UI ? 'login.html' : 'login.php';

const API = {
    auth: W_API_BASE + '/worker-app/auth',
    shifts: W_API_BASE + '/worker-app/shifts',
    feed: W_API_BASE + '/worker-app/feed',
    zeit: W_API_BASE + '/worker-app/zeit',
    absences: W_API_BASE + '/worker-app/absences',
    messages: W_API_BASE + '/worker-app/messages',
    qm: W_API_BASE + '/worker-app/qm',
    docRequests: W_API_BASE + '/worker-app/doc-requests',
    boerse: W_API_BASE + '/worker-app/schichtboerse',
    signaturen: W_API_BASE + '/worker-app/signaturen',
    push: W_API_BASE + '/worker-app/push'
};

// Token access is backed by WStore (Keychain/Keystore on native, localStorage
// on web). WStore.init() is awaited in wBootstrap before any token is read,
// so these stay synchronous for the rest of the app.
function wGetToken() { return window.WStore ? WStore.getToken() : localStorage.getItem('w_token'); }
function wSetToken(token) { if (window.WStore) WStore.setSession(token, WStore.getUser()); else localStorage.setItem('w_token', token); }
function wClearToken() { localStorage.removeItem('w_is_admin'); localStorage.removeItem('w_ob_label'); W_OB_LABEL = 'Wachbuch'; if (window.WStore) WStore.clear(); else { localStorage.removeItem('w_token'); localStorage.removeItem('w_user'); } }
function wAuthHeaders() {
    const h = { 'Accept': 'application/json' };
    const t = wGetToken();
    if (t) h['Authorization'] = 'Bearer ' + t;
    return h;
}
function wAuthHeadersJson() {
    const h = wAuthHeaders();
    h['Content-Type'] = 'application/json';
    return h;
}

const DAYS_SHORT = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

let currentTab = 'feed';
let worker = null;
let planState = { weekStart: null, shifts: [], days: [] };
let zeitTimer = null;
let zeitPauseTimer = null;
// Zeit tab sub-view: 'erfassen' (live clock-in) or 'zeiten' (day time sheet).
let zeitView = 'erfassen';
let zeitRangeVon = '';
let zeitRangeBis = '';

// ============================================
// INIT
// ============================================
async function wBootstrap() {
    // Load the (securely stored) session into memory before anything reads it.
    if (window.WStore) { await WStore.init(); }
    if (!wGetToken()) { window.location.href = LOGIN_URL; return; }
    worker = (window.WStore ? WStore.getUser() : (typeof WORKER_INFO !== 'undefined' ? WORKER_INFO : {})) || {};
    // Admin accounts (main system users) get a dashboard instead of the
    // worker screens — their token has no dienst_nr for the worker APIs.
    // Keep the pre-paint hint in sync (index.html hides the worker UI for admins).
    if (worker && worker.is_admin) localStorage.setItem('w_is_admin', '1');
    else { localStorage.removeItem('w_is_admin'); document.documentElement.classList.remove('admin-boot'); }
    if (worker && worker.is_admin) {
        // Validate the admin token BEFORE handing the WebView over to the
        // web system. A dead/expired token would strand the user on the
        // web login page (clood-x.com/login.php) with no way back to the
        // app's own worker login screen.
        try {
            const res = await fetch(W_API_BASE + '/auth/check', { headers: wAuthHeaders() });
            const d = await res.json();
            if (!d || !d.authenticated) {
                wClearToken();
                window.location.href = LOGIN_URL;
                return;
            }
        } catch (err) { /* offline — proceed, the web side boots with the token */ }
        if (localStorage.getItem('w_fcm_token')) requestNotifPermission();
        else await requestNotifPermission();
        initAdminMode(); return;
    }
    initNavigation();
    setTimeout(() => { if (window.WQm) { WQm.konfig(wQmCfg()); WQm.flush(); } }, 4000);
    switchTab('feed');
    window.__wBooted = true;   // boot watchdog in index.php/index.html
    // Voice calls: WS signaling + pending-invite check (no-op without WCall).
    if (window.WCall) setTimeout(WCall.init, 800);
    // Defer a tick so worker auth state is settled.
    setTimeout(startNotifPolling, 1500);
    setTimeout(wUpdateDienstBadge, 2500);
    // QM live updates (Reverb): Dienst tab + badge refresh on Wachbuch / DA changes.
    setTimeout(() => { if (window.CxQmLive) CxQmLive.start({ base: API.qm, headers: j => (j ? wAuthHeadersJson() : wAuthHeaders()),
        host: IS_CAPACITOR ? 'clood-x.com' : location.hostname,
        onChange: () => { wUpdateDienstBadge(); if (currentTab === 'dienst' && window.WQm) WQm.refresh(); } }); }, 3000);
    // Ask for system-notification permission (WhatsApp-style banners).
    setTimeout(requestNotifPermission, 3000);
}
document.addEventListener('DOMContentLoaded', wBootstrap);

// Tab switch requested by the service worker after a notification tap.
if (navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener('message', (e) => {
        if (e.data && e.data.action === 'switch-tab' && e.data.tab) {
            // 'call' is a call-invite hint, not a real tab — the call overlay
            // appears via WCall.checkPending; an unknown tab would spin forever.
            if (e.data.tab !== 'call') switchTab(e.data.tab);
            if (window.WCall) WCall.checkPending();
            if (sigIsPushTitle(e.data.title)) sigOpenLatest();
        }
    });
}

// ============================================
// NAVIGATION
// ============================================
function initNavigation() {
    document.querySelectorAll('.nav-tab').forEach(tab => {
        tab.addEventListener('click', () => switchTab(tab.dataset.tab));
    });
}

// iOS has no system BACK key (Android's goes to the feed via capacitor-bridge),
// and the bottom nav is hidden while the keyboard is open — give the chat an
// explicit way out. Blur first so the keyboard closes and the nav comes back.
function chatGoBack() {
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
    switchTab('feed');
}

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.nav-tab').forEach(t =>
        t.classList.toggle('active', t.dataset.tab === tab)
    );

    const content = document.getElementById('content');
    content.scrollTop = 0;
    content.classList.toggle('chat-active', tab === 'chat');
    content.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i></div>';

    switch (tab) {
        case 'feed':   renderFeed(); break;
        case 'plan':   renderPlan(); break;
        case 'zeit':   renderZeit(); break;
        case 'frei':   renderFrei(); break;
        case 'chat':   renderChat(); break;
        case 'profil': renderProfil(); break;
        case 'dienst': renderDienst(); break;
    }
}

// ============================================
// ADMIN MODE — full web system for main-system accounts
// ============================================
function initAdminMode() {
    // Admin accounts get the COMPLETE web system — exactly as if they had
    // opened clood-x.com in Chrome on the phone. The token is handed over
    // in the URL fragment (never sent to the server / never logged);
    // index.php stores it, hydrates the profile via /api/auth/check and
    // boots the full dashboard.
    const base = IS_CAPACITOR ? 'https://clood-x.com' : window.location.origin;
    const pushToken = localStorage.getItem('w_fcm_token') || '';
    const pushPermission = localStorage.getItem('w_push_permission') || '';
    const pushProvider = IS_CAPACITOR && Capacitor.getPlatform() === 'ios' ? 'apns' : 'fcm';
    const push = (pushToken
        ? '&push_provider=' + pushProvider + '&push_token=' + encodeURIComponent(pushToken)
        : '') + (pushPermission ? '&push_permission=' + pushPermission : '');
    const go = () => window.location.replace(base + '/index.php#app_sso=1&t=' + encodeURIComponent(wGetToken()) + push);
    // Android BACK: release the Capacitor backButton listener so native
    // WebView history handles Back inside the web system; `replace` keeps
    // this page out of the history (no bounce at the dashboard).
    if (typeof window.wReleaseBackButton === 'function') {
        window.wReleaseBackButton().catch(() => {}).finally(go);
    } else {
        go();
    }
}

// ============================================
// API HELPERS
// ============================================
/* Stalled-socket guard: a request whose socket silently dies (NAT drop,
   cell handover) hangs until the OS TCP timeout — the "never-ending
   spinner". Abort at 12s and retry ONCE on a fresh socket; the timer
   stays armed through res.json() so a mid-body stall aborts too. */
const _API_GET_TIMEOUT_MS = 12000;
const _API_WRITE_TIMEOUT_MS = 20000;

async function apiGet(url) {
    let timedOutOnce = false;
    while (true) {
        const ctrl = new AbortController();
        let timedOut = false;
        const t = setTimeout(() => { timedOut = true; ctrl.abort(); }, _API_GET_TIMEOUT_MS);
        try {
            const res = await fetch(url, { headers: wAuthHeaders(), signal: ctrl.signal });
            if (res.status === 401) { clearTimeout(t); wClearToken(); window.location.href = LOGIN_URL; return null; }
            const body = await res.json();
            clearTimeout(t);
            return body;
        } catch (e) {
            clearTimeout(t);
            if (e.name === 'AbortError' && timedOut && !timedOutOnce) { timedOutOnce = true; continue; }
            if (e.name !== 'AbortError') console.error('API GET Error:', e);
            return null;
        }
    }
}

async function apiPost(url, data) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), _API_WRITE_TIMEOUT_MS);
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: wAuthHeadersJson(),
            body: JSON.stringify(data),
            signal: ctrl.signal
        });
        if (res.status === 401) { clearTimeout(t); wClearToken(); window.location.href = LOGIN_URL; return null; }
        const body = await res.json();
        clearTimeout(t);
        return body;
    } catch (e) {
        clearTimeout(t);
        if (e.name !== 'AbortError') console.error('API POST Error:', e);
        return null;
    }
}

// ============================================
// TOAST
// ============================================
function showToast(message, type = 'success', onTap = null) {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    if (onTap) { toast.style.cursor = 'pointer'; toast.onclick = () => { toast.remove(); onTap(); }; }
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
}

// ============================================
// FEED SCREEN
// ============================================
async function renderFeed() {
    const content = document.getElementById('content');
    const firstName = (worker.vorname || worker.name || 'Mitarbeiter').split(' ')[0];
    const initials = getInitials(worker.vorname, worker.name);
    const greeting = getGreeting();

    content.innerHTML = `
        <div class="feed-header">
            ${notifBellHtml()}
            <div class="feed-avatar">${worker.foto ? `<img src="${ASSETS_BASE}${esc(worker.foto)}" alt="">` : esc(initials)}</div>
            <div class="feed-greeting">${greeting}, ${esc(firstName)} 👋</div>
        </div>
        <div class="feed-date-nav">
            <button onclick="switchTab('plan')"><i class="fas fa-chevron-left"></i></button>
            <span class="feed-date-label">Heute</span>
            <button onclick="switchTab('plan')"><i class="fas fa-chevron-right"></i></button>
        </div>
        <div id="feedBody"><div class="loading"><i class="fas fa-spinner fa-spin"></i></div></div>
    `;
    updateNotifBell();

    const data = await apiGet(API.feed);
    const body = document.getElementById('feedBody');
    if (!data || !body) return;

    // Sync worker profile (photo, name) from server
    if (data.worker) {
        const changed = worker.foto !== data.worker.foto || worker.vorname !== data.worker.vorname || worker.name !== data.worker.name;
        if (changed) {
            worker.foto = data.worker.foto;
            worker.vorname = data.worker.vorname;
            worker.name = data.worker.name;
            worker.bereich = data.worker.bereich;
            if (window.WStore) WStore.setUser(worker); else localStorage.setItem('w_user', JSON.stringify(worker));
            const avatarEl = document.querySelector('.feed-avatar');
            if (avatarEl) {
                const newInitials = getInitials(worker.vorname, worker.name);
                avatarEl.innerHTML = worker.foto ? `<img src="${ASSETS_BASE}${esc(worker.foto)}" alt="">` : esc(newInitials);
            }
        }
    }

    let html = '';
    const today = data.today_shifts || [];
    const nextShift = data.next_shift;

    if (today.length === 0 && !nextShift) {
        html += `
            <div class="feed-empty">
                <div class="feed-empty-icon"><i class="far fa-calendar"></i></div>
                <p>Keine Einträge vorhanden</p>
            </div>
        `;
    }

    if (today.length > 0) {
        html += '<div class="feed-today-shifts">';
        today.forEach(s => { html += shiftCardHtml(s); });
        html += '</div>';
    } else if (nextShift) {
        html += `
            <div class="next-shift-card">
                <div class="next-shift-label"><i class="fas fa-arrow-right"></i> Nächste Schicht</div>
                <div class="shift-card-location">${esc(nextShift.objektname || '–')}</div>
                <div class="shift-card-time">${fmtTime(nextShift.display_start)} - ${fmtTime(nextShift.display_end)}</div>
                <div class="shift-card-context">${fmtDate(nextShift.datum)}</div>
                ${ortLineHtml(nextShift.ort)}
                ${nextShift.aufgabe ? `<div class="shift-card-task"><i class="fas fa-clipboard-list"></i> <span>${esc(nextShift.aufgabe)}</span></div>` : ''}
                ${nextShift.beschreibung ? `<div class="shift-card-note">${esc(nextShift.beschreibung)}</div>` : ''}
            </div>
        `;
    }

    // Open document renewal requests — prominent banner on top of the feed.
    try {
        const docs = await apiGet(API.docRequests);
        if (docs && docs.open_count > 0) {
            html = `
                <div class="feed-item" onclick="switchTab('profil')" style="background:#fef3c7;border:1px solid #fbbf24;border-radius:12px;margin-bottom:12px">
                    <div class="feed-item-icon" style="color:#b45309"><i class="fas fa-file-circle-exclamation"></i></div>
                    <div class="feed-item-content">
                        <div class="feed-item-title" style="color:#92400e">${docs.open_count === 1 ? '1 Dokument wird benötigt' : docs.open_count + ' Dokumente werden benötigt'}</div>
                        <div class="feed-item-sub" style="color:#b45309">Bitte im Profil als PDF hochladen</div>
                    </div>
                    <div class="feed-item-date"><i class="fas fa-chevron-right"></i></div>
                </div>
            ` + html;
        }
    } catch (e) { /* non-fatal */ }

    // Open signature requests from the office — on top of everything.
    try { html = (await sigFeedBannersHtml()) + html; } catch (e) { /* non-fatal */ }

    const feedItems = data.feed_items || [];
    if (feedItems.length > 0) {
        html += '<div class="feed-section-title">Feed</div>';
        feedItems.forEach(item => {
            html += `
                <div class="feed-item" onclick="openWeekPlan('${esc(item.week_start || '')}')">
                    <div class="feed-item-icon"><i class="fas fa-calendar-week"></i></div>
                    <div class="feed-item-content">
                        <div class="feed-item-title">${esc(item.title)}</div>
                        <div class="feed-item-sub">${esc(item.subtitle)}</div>
                    </div>
                    <div class="feed-item-date">${esc(item.date)}</div>
                </div>
            `;
        });
    }

    body.innerHTML = html;
}

function openWeekPlan(weekStart) {
    if (weekStart) planState.weekStart = weekStart;
    switchTab('plan');
}

// ============================================
// PLAN SCREEN
// ============================================
async function renderPlan() {
    const content = document.getElementById('content');
    if (!planState.weekStart) planState.weekStart = getWeekStart();

    content.innerHTML = `
        <div class="plan-sticky-head">
            <div class="plan-toolbar">
                <button class="plan-toolbar-btn" onclick="planChangeWeek(-1)"><i class="fas fa-chevron-left"></i></button>
                <span class="plan-toolbar-title" id="planTitle">Mein Plan</span>
                <button class="plan-toolbar-btn" onclick="planChangeWeek(1)"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="plan-week-selector" id="planWeekSel"></div>
        </div>
        <div id="planBoerse"></div>
        <div class="plan-content" id="planContent">
            <div class="loading"><i class="fas fa-spinner fa-spin"></i></div>
        </div>
    `;

    loadBoerseW();
    await loadPlan();
}

async function loadPlan() {
    const data = await apiGet(API.shifts + '?week_start=' + planState.weekStart);
    if (!data) return;

    planState.shifts = data.shifts || [];
    if (data.objektbuch_label) { W_OB_LABEL = data.objektbuch_label; try { localStorage.setItem('w_ob_label', W_OB_LABEL); } catch (e) {} }
    planState.days = data.days || [];
    planState.weekStart = data.week_start;

    const titleEl = document.getElementById('planTitle');
    if (titleEl) titleEl.textContent = 'Mein Plan';

    renderWeekSelector();
    renderPlanContent();
}

function renderWeekSelector() {
    const sel = document.getElementById('planWeekSel');
    if (!sel) return;
    const today = new Date().toISOString().split('T')[0];
    const shiftDates = new Set(planState.shifts.map(s => s.datum));
    const lastIdx = planState.days.length - 1;

    sel.innerHTML = planState.days.map((day, idx) => {
        const isToday = day.date === today;
        const hasShifts = shiftDates.has(day.date);
        const action = idx === 0 ? 'planChangeWeek(-1)'
                     : idx === lastIdx ? 'planChangeWeek(1)'
                     : `scrollToPlanDay('${day.date}')`;
        return `
            <button class="plan-day-btn ${isToday ? 'today' : ''} ${hasShifts ? 'has-shifts' : ''}"
                    onclick="${action}">
                <span class="day-label">${day.day_name}</span>
                <span class="day-num">${day.day_number}</span>
            </button>
        `;
    }).join('');
}

function renderPlanContent() {
    const el = document.getElementById('planContent');
    if (!el) return;

    const shiftsByDate = {};
    planState.shifts.forEach(s => {
        if (!shiftsByDate[s.datum]) shiftsByDate[s.datum] = [];
        shiftsByDate[s.datum].push(s);
    });

    if (planState.days.length === 0) {
        el.innerHTML = '<div class="plan-empty"><i class="far fa-calendar"></i><p>Keine Daten</p></div>';
        return;
    }

    // Week header (KW + date range)
    const firstDay = planState.days[0];
    const lastDay = planState.days[planState.days.length - 1];
    const kw = getCalendarWeek(new Date(firstDay.date + 'T00:00:00'));
    const MONTHS_SHORT = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    const fd = new Date(firstDay.date + 'T00:00:00');
    const ld = new Date(lastDay.date + 'T00:00:00');
    const rangeStr = fd.getDate() + ' ' + MONTHS_SHORT[fd.getMonth()]
        + ' - ' + ld.getDate() + ' ' + MONTHS_SHORT[ld.getMonth()];

    let html = `
        <div class="plan-week-header">
            <span class="plan-kw">KW${kw}</span>
            <span class="plan-kw-range">${rangeStr}</span>
        </div>
    `;
    planState.days.forEach(day => {
        const dayShifts = shiftsByDate[day.date] || [];
        html += `
            <div class="plan-day-section" id="plan-day-${day.date}">
                <div class="plan-day-info">
                    <div class="plan-day-num">${day.day_number}</div>
                    <div class="plan-day-name">${day.day_name}.</div>
                </div>
                <div class="plan-day-shifts">
                    ${dayShifts.map(s => shiftCardHtml(s)).join('')}
                </div>
            </div>
        `;
    });

    el.innerHTML = html || '<div class="plan-empty">Keine Schichten diese Woche</div>';
}

function scrollToPlanDay(date) {
    const el = document.getElementById('plan-day-' + date);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function planChangeWeek(offset) {
    const d = new Date(planState.weekStart + 'T00:00:00');
    d.setDate(d.getDate() + (offset * 7));
    planState.weekStart = toIsoDate(d);
    loadPlan();
}

// ============================================
// SHIFT CARD HTML
// ============================================
// Worker-facing location line: shows the precise Treffpunkt / address (einsatz.ort)
// and opens it in maps on tap. The customer name (Kunde) is intentionally NOT shown.
function ortLineHtml(ort) {
    if (!ort) return '';
    // Pure coordinate input (incl. German decimal commas like "51,34, 12,36")
    // is normalised to "lat,lng" so Google Maps can resolve it.
    let query = ort.trim();
    if (/^[\d\s.,;+-]+$/.test(query)) {
        const nums = query.match(/-?\d+(?:[.,]\d+)?/g) || [];
        if (nums.length === 2) {
            const lat = parseFloat(nums[0].replace(',', '.'));
            const lng = parseFloat(nums[1].replace(',', '.'));
            if (isFinite(lat) && isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) {
                query = lat + ',' + lng;
            }
        }
    }
    const mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(query);
    return `<div class="shift-card-address" style="display:flex;align-items:flex-start;gap:6px;margin-top:4px;color:#2563eb;cursor:pointer;font-size:0.85rem;font-weight:600;line-height:1.3" onclick="event.stopPropagation();openDocument('${mapsUrl}')"><i class="fas fa-location-dot" style="margin-top:2px"></i><span>${esc(ort)}</span></div>`;
}

function shiftCardHtml(shift) {
    const start = fmtTime(shift.display_start || shift.ist_start);
    const end = fmtTime(shift.display_end || shift.ist_ende);
    const pauseMin = shift.pause_minuten || 0;
    const aufgabe = shift.aufgabe || '';
    const note = shift.beschreibung || '';
    const confirmed = shift.hms_bestaetigt === 'ja';

    return `
        <div class="shift-card ${confirmed ? 'confirmed' : 'pending'}">
            <div class="shift-card-location">${esc(shift.objektname || shift.obj_id || '–')}</div>
            <div class="shift-card-time">
                ${start}-${end}
                ${pauseMin > 0 ? `<span class="pause-badge"><i class="far fa-clock"></i> ${pauseMin}Min.</span>` : ''}
            </div>
            ${ortLineHtml(shift.ort)}
            ${aufgabe ? `<div class="shift-card-task"><i class="fas fa-clipboard-list"></i> <span>${esc(aufgabe)}</span></div>` : ''}
            ${note ? `<div class="shift-card-note">${esc(note)}</div>` : ''}
            ${boerseShiftActionHtml(shift)}
        </div>
    `;
}

// ============================================
// SCHICHTBÖRSE (shift exchange) — worker side
// ============================================
// Only shifts that fit the worker are listed (server-side check: Bereich,
// Anforderungen, gender, no overlapping shift/absence). A request or a
// hand-over changes nothing until the office approves it.
let _wBoerse = { angebote: [], meine_abgaben: [] };

async function loadBoerseW() {
    const data = await apiGet(API.boerse);
    if (!data || data.error) return;
    _wBoerse = { angebote: data.angebote || [], meine_abgaben: data.meine_abgaben || [] };
    renderBoerseW();
    if (document.getElementById('planContent') && planState.days && planState.days.length) renderPlanContent();
}

function boerseShiftLine(sch) {
    return `${fmtDate(sch.datum)} · ${fmtTime(sch.ist_start)}-${fmtTime(sch.ist_ende)}`;
}

function renderBoerseW() {
    const el = document.getElementById('planBoerse');
    if (!el) return;
    const offers = _wBoerse.angebote, mine = _wBoerse.meine_abgaben;
    if (!offers.length && !mine.length) { el.innerHTML = ''; return; }
    let html = '<div class="boerse-w">';
    if (offers.length) {
        html += `<div class="boerse-w-title"><i class="fas fa-right-left"></i> Schichtbörse <span class="boerse-w-count">${offers.length}</span><small>passend für dich</small></div>`;
        html += offers.map(o => `
            <div class="boerse-w-card ${o.angefragt ? 'is-requested' : ''}">
                <div class="boerse-w-top">
                    <span class="boerse-w-pill ${o.typ === 'abgabe' ? 'is-abgabe' : ''}">${o.typ === 'abgabe' ? 'Kollege gibt ab' : 'Offene Schicht'}</span>
                    ${o.schicht.bereich ? `<span class="boerse-w-pill is-muted">${esc(o.schicht.bereich)}</span>` : ''}
                </div>
                <div class="boerse-w-loc">${esc(o.schicht.objektname || o.schicht.kundenname || '–')}</div>
                <div class="boerse-w-time"><i class="far fa-clock"></i> ${boerseShiftLine(o.schicht)}</div>
                ${o.schicht.ort ? `<div class="boerse-w-sub"><i class="fas fa-location-dot"></i> ${esc(o.schicht.ort)}</div>` : ''}
                ${o.notiz ? `<div class="boerse-w-sub"><i class="far fa-comment"></i> ${esc(o.notiz)}</div>` : ''}
                ${o.angefragt
                    ? `<div class="boerse-w-actions"><span class="boerse-w-state"><i class="fas fa-hourglass-half"></i> Angefragt — das Büro entscheidet</span>
                        <button type="button" class="boerse-w-btn" onclick="boerseCancelRequest(${parseInt(o.angebot_id, 10)}, this)">Zurückziehen</button></div>`
                    : `<div class="boerse-w-actions"><button type="button" class="boerse-w-btn is-primary" onclick="boerseRequest(${parseInt(o.angebot_id, 10)}, this)"><i class="fas fa-hand"></i> Schicht anfragen</button></div>`}
            </div>`).join('');
    }
    if (mine.length) {
        html += `<div class="boerse-w-title"><i class="fas fa-share-from-square"></i> Du gibst ab</div>`;
        html += mine.map(m => `
            <div class="boerse-w-card is-own">
                <div class="boerse-w-loc">${esc(m.schicht.objektname || m.schicht.kundenname || '–')}</div>
                <div class="boerse-w-time"><i class="far fa-clock"></i> ${boerseShiftLine(m.schicht)}</div>
                <div class="boerse-w-actions"><span class="boerse-w-state"><i class="fas fa-users"></i> ${m.anfragen ? m.anfragen + (m.anfragen === 1 ? ' Kollege hat' : ' Kollegen haben') + ' angefragt' : 'Noch keine Anfrage'} · bleibt bei dir bis zur Genehmigung</span>
                    <button type="button" class="boerse-w-btn" onclick="boerseKeepShift(${parseInt(m.angebot_id, 10)}, this)">Behalten</button></div>
            </div>`).join('');
    }
    el.innerHTML = html + '</div>';
}

// "Abgeben" on own future, unconfirmed shifts (plan tab only).
function boerseShiftActionHtml(shift) {
    if (currentTab !== 'plan' || !shift || !shift.einsatz_id || shift.hms_bestaetigt === 'ja') return '';
    const start = new Date(String(shift.datum).substring(0, 10) + 'T' + (fmtTime(shift.display_start || shift.ist_start) || '00:00') + ':00');
    if (!(start > new Date())) return '';
    const offered = (_wBoerse.meine_abgaben || []).some(m => m.einsatz_id === shift.einsatz_id);
    return offered
        ? '<span class="boerse-w-flag"><i class="fas fa-share-from-square"></i> Wird abgegeben</span>'
        : `<button type="button" class="boerse-w-give" onclick="event.stopPropagation();boerseOpenHandOver('${esc(shift.einsatz_id)}')"><i class="fas fa-share-from-square"></i> Abgeben</button>`;
}

function boerseOpenHandOver(einsatzId) {
    const sh = (planState.shifts || []).find(x => x.einsatz_id === einsatzId);
    document.querySelectorAll('#boerseWSheet').forEach(o => o.remove());
    const ov = document.createElement('div');
    ov.className = 'modal-overlay';
    ov.id = 'boerseWSheet';
    ov.innerHTML = `<div class="modal-sheet">
        <div class="modal-handle"></div>
        <div class="modal-title-row">
            <div class="modal-title">Schicht abgeben</div>
            <button type="button" class="modal-close-btn" onclick="boerseCloseSheet()" aria-label="Schließen">&times;</button>
        </div>
        ${sh ? `<div class="boerse-w-loc">${esc(sh.objektname || sh.obj_id || '–')}</div>
            <div class="boerse-w-time" style="margin-bottom:12px"><i class="far fa-clock"></i> ${fmtDate(sh.datum)} · ${fmtTime(sh.display_start || sh.ist_start)}-${fmtTime(sh.display_end || sh.ist_ende)}</div>` : ''}
        <div class="boerse-w-hint"><i class="fas fa-circle-info"></i> Passende Kollegen sehen die Schicht in der Schichtbörse. Sie bleibt in deinem Plan, bis das Büro eine Übernahme genehmigt.</div>
        <label class="modal-input-label">Grund (optional)</label>
        <textarea id="boerseWNotiz" class="modal-input" rows="2" maxlength="500" placeholder="z.B. Arzttermin"></textarea>
        <div class="boerse-w-sheet-btns">
            <button type="button" class="boerse-w-btn" onclick="boerseCloseSheet()">Abbrechen</button>
            <button type="button" class="boerse-w-btn is-primary" onclick="boerseHandOver('${esc(einsatzId)}', this)"><i class="fas fa-share-from-square"></i> Abgeben</button>
        </div>
    </div>`;
    ov.addEventListener('click', e => { if (e.target === ov) boerseCloseSheet(); });
    document.body.appendChild(ov);
    requestAnimationFrame(() => ov.classList.add('active'));
}

function boerseCloseSheet() {
    const ov = document.getElementById('boerseWSheet');
    if (!ov) return;
    ov.classList.remove('active');
    setTimeout(() => ov.remove(), 250);
}

async function boerseApi(method, url, body) {
    try {
        const res = await fetch(url, { method, headers: wAuthHeadersJson(), body: body ? JSON.stringify(body) : undefined });
        if (res.status === 401) { wClearToken(); window.location.href = LOGIN_URL; return null; }
        return await res.json();
    } catch (e) { return null; }
}

async function boerseHandOver(einsatzId, btn) {
    if (btn) btn.disabled = true;
    const r = await boerseApi('POST', API.boerse + '/abgeben', { einsatz_id: einsatzId, notiz: document.getElementById('boerseWNotiz')?.value || '' });
    if (btn) btn.disabled = false;
    if (!r || !r.ok) { showToast((r && (r.error || r.message)) || 'Konnte nicht abgegeben werden', 'error'); return; }
    showToast('Schicht in der Schichtbörse angeboten');
    boerseCloseSheet();
    loadBoerseW();
}

async function boerseRequest(angebotId, btn) {
    if (btn) btn.disabled = true;
    const r = await boerseApi('POST', API.boerse + '/angebote/' + angebotId + '/anfragen');
    if (!r || !r.ok) { if (btn) btn.disabled = false; showToast((r && r.error) || 'Anfrage fehlgeschlagen', 'error'); loadBoerseW(); return; }
    showToast('Angefragt — du bekommst Bescheid, sobald das Büro entscheidet');
    loadBoerseW();
}

async function boerseCancelRequest(angebotId, btn) {
    if (btn) btn.disabled = true;
    await boerseApi('DELETE', API.boerse + '/angebote/' + angebotId + '/anfragen');
    showToast('Anfrage zurückgezogen');
    loadBoerseW();
}

async function boerseKeepShift(angebotId, btn) {
    if (btn) btn.disabled = true;
    const r = await boerseApi('POST', API.boerse + '/angebote/' + angebotId + '/zurueckziehen');
    if (!r || !r.ok) { if (btn) btn.disabled = false; showToast((r && r.error) || 'Fehler', 'error'); return; }
    showToast('Du behältst die Schicht');
    loadBoerseW();
}

// ============================================
// DIENST — Wachbuch + Dienstanweisungen (QM module, assets/js/qm-wachbuch.js)
// ============================================
function wQmCfg(view) {
    return {
        base: API.qm, label: W_OB_LABEL, view, uebergabe: true,
        headers: json => (json ? wAuthHeadersJson() : wAuthHeaders()),
        onAuthLost: () => { wClearToken(); window.location.href = LOGIN_URL; },
        hinweisOhneDienst: 'Nach dem Check-in im Reiter „Zeit" führst du hier dein ' + W_OB_LABEL + '.',
        // After confirming the Dienstanweisung that blocked the check-in.
        nachBestaetigung: () => { if (window._wDaFuerCheckin) { window._wDaFuerCheckin = false; showToast('Bestätigt — weiter zum Check-in'); switchTab('zeit'); } else WQm.zeige('dienstanweisungen'); },
    };
}
function renderDienst(view, fokusId) {
    if (!window.WQm) { document.getElementById('content').innerHTML = '<div class="loading">Modul nicht geladen</div>'; return; }
    const box = document.getElementById('content');
    box.innerHTML = '<div class="wqm-root" id="wqmRoot"></div>';
    WQm.mount(document.getElementById('wqmRoot'), wQmCfg(view || 'wachbuch')).then(() => { if (fokusId) WQm.zeige('dienstanweisungen', fokusId); });
}
async function wUpdateDienstBadge() {
    if (!window.WQm) return;
    WQm.konfig(wQmCfg());
    const n = await WQm.offeneDa();
    const tab = document.querySelector('.nav-tab[data-tab="dienst"]');
    if (!tab) return;
    let b = tab.querySelector('.tab-badge');
    if (!b) { b = document.createElement('span'); b.className = 'tab-badge'; tab.appendChild(b); }
    b.textContent = n; b.style.display = n ? '' : 'none';
}

// ============================================
// ZEIT (CLOCK-IN) — Phase 2
// ============================================
let zeitData = null;

function zeitToggleHtml() {
    return `
        <div class="zeit-toggle">
            <button class="zeit-toggle-btn ${zeitView === 'erfassen' ? 'active' : ''}" onclick="zeitSwitchView('erfassen')">Erfassen</button>
            <button class="zeit-toggle-btn ${zeitView === 'zeiten' ? 'active' : ''}" onclick="zeitSwitchView('zeiten')">Zeiten</button>
        </div>`;
}

function zeitSwitchView(view) {
    zeitView = view;
    renderZeit();
}

function fmtHm(minutes) {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h + 'h ' + String(m).padStart(2, '0') + 'm';
}

async function renderZeit() {
    const content = document.getElementById('content');
    content.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i></div>';
    clearInterval(zeitTimer);
    clearInterval(zeitPauseTimer);

    if (zeitView === 'zeiten') { renderZeiten(); return; }

    const data = await apiGet(API.zeit);
    if (!data) {
        content.innerHTML = '<div class="zeit-screen"><div class="zeit-empty"><i class="fas fa-wifi"></i><p>Keine Verbindung</p>'
            + '<button class="zeit-btn zeit-btn-start" onclick="renderZeit()"><i class="fas fa-redo"></i> Erneut versuchen</button></div></div>';
        return;
    }
    zeitData = data;

    let html = '<div class="zeit-screen">';
    html += '<div class="zeit-header"><div class="zeit-title">Zeiterfassung</div><div class="zeit-date">' + fmtDateLong(new Date()) + '</div></div>';
    html += zeitToggleHtml();

    // Active shift
    if (data.active) {
        html += renderActiveShift(data.active);
    }
    // Upcoming shifts
    else if (data.upcoming && data.upcoming.length > 0) {
        html += renderUpcomingShifts(data.upcoming);
    } else if (!data.completed || data.completed.length === 0) {
        html += '<div class="zeit-empty"><i class="far fa-clock"></i><p>Keine Schichten heute</p></div>';
    }

    // Completed shifts
    if (data.completed && data.completed.length > 0) {
        html += '<div class="zeit-section-title">Abgeschlossen</div>';
        data.completed.forEach(c => {
            const hrs = Math.floor(c.worked_minutes / 60);
            const mins = c.worked_minutes % 60;
            html += `
                <div class="zeit-completed-card">
                    <div class="zeit-completed-location">${esc(c.objektname || '–')}</div>
                    <div class="zeit-completed-times">${c.clock_in_time} — ${c.clock_out_time}</div>
                    <div class="zeit-completed-hours">${hrs}h ${mins}m${c.pause_total > 0 ? ' · ' + c.pause_total + ' Min. Pause' : ''}</div>
                </div>
            `;
        });
    }

    html += '</div>';
    content.innerHTML = html;

    // Start live timer if active
    zeitScheduleExtendSheet(data.active);
    if (data.active) {
        startElapsedTimer(data.active.elapsed_seconds, data.active.pause_total || 0, data.active.status);
    }
    // Start countdown timers for upcoming
    else if (data.upcoming && data.upcoming.length > 0) {
        startCountdownTimers(data.upcoming);
    }
}

// ============================================
// ZEITEN — day-by-day time sheet with admin confirmation
// ============================================
async function renderZeiten() {
    const content = document.getElementById('content');

    // Default range: 1st of the current month → today.
    if (!zeitRangeVon || !zeitRangeBis) {
        const now = new Date();
        zeitRangeVon = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-01';
        zeitRangeBis = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    }

    content.innerHTML = `
        <div class="zeit-screen">
            <div class="zeit-header"><div class="zeit-title">Zeiterfassung</div><div class="zeit-date">${fmtDateLong(new Date())}</div></div>
            ${zeitToggleHtml()}
            <div class="zeiten-range">
                <input type="date" id="zeitenVon" value="${zeitRangeVon}" onchange="zeitenRangeChanged()">
                <span class="zeiten-range-sep">–</span>
                <input type="date" id="zeitenBis" value="${zeitRangeBis}" onchange="zeitenRangeChanged()">
            </div>
            <div id="zeitenBody"><div class="loading"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>`;

    loadZeitenList();
}

function zeitenRangeChanged() {
    const von = document.getElementById('zeitenVon');
    const bis = document.getElementById('zeitenBis');
    if (von && von.value) zeitRangeVon = von.value;
    if (bis && bis.value) zeitRangeBis = bis.value;
    loadZeitenList();
}

async function loadZeitenList() {
    const body = document.getElementById('zeitenBody');
    if (!body) return;
    body.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i></div>';

    const data = await apiGet(API.zeit + '/zeiten?von=' + encodeURIComponent(zeitRangeVon) + '&bis=' + encodeURIComponent(zeitRangeBis));
    if (!body.isConnected) return;
    if (!data) {
        body.innerHTML = '<div class="zeit-empty"><i class="fas fa-wifi"></i><p>Keine Verbindung</p></div>';
        return;
    }

    const days = data.days || [];
    if (days.length === 0) {
        body.innerHTML = '<div class="zeit-empty"><i class="far fa-calendar"></i><p>Keine Einsätze im Zeitraum</p></div>';
        return;
    }

    const MONTHS_SHORT = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    const hm = t => (t || '').slice(0, 5) || '–';
    // One shift: "Plan 18:00–01:00 / Ist 18:00–00:57 (gest. 17:54) / Pause 30 Min → 6:28".
    const itemHtml = it => {
        const lines = [];
        if (it.plan_start && it.plan_end) {
            lines.push(`<div class="zs-line plan"><span class="zs-label">Plan</span>${hm(it.plan_start)} – ${hm(it.plan_end)}</div>`);
        }
        const istT = it.ist_start && it.ist_ende ? `${hm(it.ist_start)} – ${hm(it.ist_ende)}` : '–';
        lines.push(`<div class="zs-line ist"><span class="zs-label">Ist</span>${istT}</div>`);
        if (it.ist_minutes > 0 || it.pause > 0) {
            const net = it.ist_minutes > 0 ? ` → <b>${fmtHm(it.ist_minutes)}</b>` : '';
            lines.push(`<div class="zs-net">Pause ${it.pause > 0 ? it.pause + ' Min' : '0 Min'}${net}</div>`);
        }
        const gest = [];
        if (it.gest_in) gest.push(it.gest_in);
        if (it.gest_out) gest.push(it.gest_out === 'autom.' ? 'autom. beendet' : it.gest_out);
        if (gest.length) lines.push(`<div class="zs-gest">gest. ${gest.join(' – ')}</div>`);
        return `<div class="zeiten-shift">${lines.join('')}</div>`;
    };
    let html = '';
    days.forEach(d => {
        const dt = new Date(d.datum + 'T00:00:00');
        const hasIst = d.ist_minutes > 0;
        const items = (d.items || []).map(itemHtml).join('');
        html += `
            <div class="zeiten-day-card">
                <div class="zeiten-day-date">
                    <span class="zeiten-day-month">${MONTHS_SHORT[dt.getMonth()]}</span>
                    <span class="zeiten-day-num">${dt.getDate()}</span>
                </div>
                <div class="zeiten-day-body">
                    <div class="zeiten-day-lines">
                        ${hasIst ? `<div class="zeiten-line ist"><span class="zeiten-line-label">Ist:</span><span class="zeiten-line-val">${fmtHm(d.ist_minutes)}</span></div>` : ''}
                        ${d.plan_minutes > 0 ? `<div class="zeiten-line"><span class="zeiten-line-label">Soll:</span><span class="zeiten-line-val">${fmtHm(d.plan_minutes)}</span></div>` : ''}
                        ${!hasIst && d.plan_minutes === 0 ? '<div class="zeiten-line"><span class="zeiten-line-label">–</span></div>' : ''}
                    </div>
                    ${items ? `<div class="zeiten-shifts">${items}</div>` : ''}
                </div>
                ${d.confirmed ? '<div class="zeiten-day-check" title="Vom Büro bestätigt"><i class="fas fa-check-circle"></i></div>' : ''}
            </div>`;
    });
    body.innerHTML = html;
}

function renderActiveShift(active) {
    const isPaused = active.status === 'pause';
    const note = active.display_note || active.beschreibung || '';
    // The planned pause is a per-shift budget: once pause_total reaches it,
    // the Pause button is disabled (the API also rejects with 409).
    const planPause = parseInt(active.plan_pause || 0, 10);
    const pauseUsed = parseInt(active.pause_total || 0, 10);
    const pauseUsedUp = planPause > 0 && pauseUsed >= planPause;
    return `
        <div class="zeit-active-card ${isPaused ? 'paused' : ''}">
            <div class="zeit-active-status">
                <span class="zeit-status-dot ${isPaused ? 'paused' : 'active'}"></span>
                ${isPaused ? 'Pausiert' : 'Aktiv'}
            </div>
            <div class="zeit-active-location">${esc(active.objektname || '–')}</div>
            ${ortLineHtml(active.ort)}
            <div class="zeit-timer" id="zeitElapsed">00:00:00</div>
            <div class="zeit-timer-label">Arbeitszeit</div>
            ${active.counting_from ? '<div class="zeit-active-note" id="zeitCountingFrom"><i class="fas fa-clock"></i> ' + (active.gestempelt ? 'Gestempelt um ' + esc(active.gestempelt) + ' Uhr · ' : '') + 'Arbeitszeit zählt ab Schichtbeginn ' + esc(active.counting_from) + ' Uhr</div>' : ''}
            ${note ? '<div class="zeit-active-note">' + esc(note) + '</div>' : ''}
            ${isPaused ? pauseAutoResumeHtml(active) : ''}
            ${renderShiftEndRow(active)}
            <div class="zeit-active-actions">
                ${isPaused
                    ? '<button class="zeit-btn zeit-btn-resume" onclick="zeitPauseEnd()"><i class="fas fa-play"></i> Fortsetzen</button>'
                    : (pauseUsedUp
                        ? `<button class="zeit-btn zeit-btn-pause" disabled title="Geplante Pause (${planPause} Min) aufgebraucht"><i class="fas fa-pause"></i> Pause aufgebraucht</button>`
                        : '<button class="zeit-btn zeit-btn-pause" onclick="zeitPauseStart()"><i class="fas fa-pause"></i> Pause</button>')
                }
                <button class="zeit-btn zeit-btn-stop" onclick="zeitClockOut()"><i class="fas fa-stop"></i> Beenden</button>
            </div>
        </div>
    `;
}

function renderUpcomingShifts(shifts) {
    let html = '<div class="zeit-section-title">Heutige Schichten</div>';
    const today = toIsoDate(new Date());
    shifts.forEach(s => {
        const note = s.beschreibung || '';
        // The server also lists yesterday's still-running night shift and a
        // shift starting right after midnight — name the day so two cards
        // with the same times (e.g. 22:00 — 06:30) can't be mixed up.
        const day = String(s.datum || '').substring(0, 10);
        const dayLabel = day && day < today ? 'Nachtschicht von gestern (' + fmtDate(day) + ')'
            : (day && day > today ? 'Morgen (' + fmtDate(day) + ')' : '');
        const canStart = s.can_clock_in;
        const minUntil = s.minutes_until;

        let countdownHtml = '';
        if (minUntil !== null && minUntil > 0 && !canStart) {
            countdownHtml = `<div class="zeit-countdown" id="countdown-${s.einsatz_id}">Startet in ${minUntil} Min.</div>`;
        } else if (minUntil !== null && minUntil <= 0) {
            countdownHtml = '<div class="zeit-countdown ready">Bereit zum Starten</div>';
        }

        html += `
            <div class="zeit-upcoming-card ${canStart ? 'ready' : ''}">
                ${dayLabel ? '<div class="zeit-upcoming-note"><i class="far fa-calendar"></i> ' + esc(dayLabel) + '</div>' : ''}
                <div class="zeit-upcoming-location">${esc(s.objektname || '–')}</div>
                <div class="zeit-upcoming-time">${s.display_start ? fmtTime(s.display_start) + ' — ' + fmtTime(s.display_end) : 'Ohne feste Startzeit'}</div>
                ${ortLineHtml(s.ort)}
                ${note ? '<div class="zeit-upcoming-note">' + esc(note) + '</div>' : ''}
                ${countdownHtml}
                <button class="zeit-btn zeit-btn-start ${canStart ? '' : 'disabled'}" 
                        onclick="zeitClockIn('${esc(s.einsatz_id)}')" ${canStart ? '' : 'disabled'}>
                    <i class="fas fa-play"></i> ${canStart ? 'Schicht starten' : 'Noch nicht verfügbar'}
                </button>
            </div>
        `;
    });
    return html;
}

function startElapsedTimer(initialSeconds, pauseMinutes, status) {
    let elapsed = Math.floor(Number(initialSeconds) || 0);
    const pauseSec = pauseMinutes * 60;
    clearInterval(zeitTimer);

    function update() {
        const workSec = Math.max(0, elapsed - pauseSec);
        const h = String(Math.floor(workSec / 3600)).padStart(2, '0');
        const m = String(Math.floor((workSec % 3600) / 60)).padStart(2, '0');
        const s = String(workSec % 60).padStart(2, '0');
        const el = document.getElementById('zeitElapsed');
        if (el) el.textContent = h + ':' + m + ':' + s;
    }

    update();
    if (status !== 'pause') {
        zeitTimer = setInterval(() => { elapsed++; update(); }, 1000);
    }
}

// Phones suspend timers in the background: a list loaded before midnight or
// before a shift ended would stay stale. Reload the Erfassen view whenever
// the app comes back to the foreground (at most every 30 s).
let zeitLastVisibleRefresh = 0;
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible' || currentTab !== 'zeit' || zeitView !== 'erfassen') return;
    if (Date.now() - zeitLastVisibleRefresh < 30000) return;
    zeitLastVisibleRefresh = Date.now();
    renderZeit();
});

function startCountdownTimers(shifts) {
    clearInterval(zeitPauseTimer);
    zeitPauseTimer = setInterval(() => {
        shifts.forEach(s => {
            if (s.minutes_until === null) return;
            s.minutes_until--;
            const el = document.getElementById('countdown-' + s.einsatz_id);
            if (el && s.minutes_until > 0 && !s.can_clock_in) {
                el.textContent = 'Startet in ' + s.minutes_until + ' Min.';
            }
            if (s.minutes_until <= 15 && !s.can_clock_in) {
                s.can_clock_in = true;
                renderZeit();
            }
        });
    }, 60000);
}

// Resolve current GPS position as "lat,lon". On native it uses the Capacitor
// Geolocation plugin so the runtime location permission is actually requested;
// on web it falls back to the browser geolocation API. Returns null on any
// failure/denial (the server decides whether GPS is mandatory for the shift).
// Hard cap: the iOS Capacitor Geolocation plugin ignores `timeout` and can
// wait forever for a fix (fresh permission, indoors, no GPS) — the clock-in
// button then spins on "Standort wird geprüft…" and the request never
// reaches the server. After the cap we go on without a position; the server
// decides whether GPS is mandatory (needs_gps error instead of a hang).
const GEO_HARD_TIMEOUT_MS = 20000;
function getGeo() {
    return Promise.race([
        getGeoInner(),
        new Promise(res => setTimeout(() => res(null), GEO_HARD_TIMEOUT_MS)),
    ]);
}

async function getGeoInner() {
    const toResult = (pos) => ({
        geo: pos.coords.latitude.toFixed(6) + ',' + pos.coords.longitude.toFixed(6),
        accuracy: (pos.coords.accuracy != null) ? Math.round(pos.coords.accuracy) : null,
    });
    try {
        const isNative = window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform();
        const Geo = isNative && Capacitor.Plugins ? Capacitor.Plugins.Geolocation : null;
        if (Geo) {
            try {
                let perm = await Geo.checkPermissions();
                if (perm.location !== 'granted' && perm.coarseLocation !== 'granted') {
                    perm = await Geo.requestPermissions();
                }
                if (perm.location !== 'granted' && perm.coarseLocation !== 'granted') return null;
                const pos = await Geo.getCurrentPosition({ enableHighAccuracy: true, timeout: 10000 });
                return toResult(pos);
            } catch (e) { return null; }
        }
        const pos = await new Promise((res, rej) => navigator.geolocation.getCurrentPosition(res, rej, { enableHighAccuracy: true, timeout: 10000 }));
        return toResult(pos);
    } catch (e) { return null; }
}

async function zeitClockIn(einsatzId) {
    if (!confirm('Schicht jetzt starten?')) return;

    // Show loading state
    const btn = event && event.target ? event.target.closest('.zeit-btn') : null;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Standort wird geprüft...'; }

    const loc = await getGeo();

    const result = await apiPost(API.zeit + '/clock-in', { einsatz_id: einsatzId, geo: loc ? loc.geo : null, geo_accuracy: loc ? loc.accuracy : null, qm_client: 1 });
    if (result && result.da_sperre) {
        // QM: the current Dienstanweisung of this Objekt is not confirmed yet.
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-play"></i> Schicht starten'; }
        if (confirm('Check-in noch nicht möglich.\n\nBitte zuerst die Dienstanweisung dieses Objekts lesen und bestätigen.\n\nDienstanweisung jetzt lesen?')) {
            window._wDaFuerCheckin = true;
            currentTab = 'dienst';
            document.querySelectorAll('.nav-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === 'dienst'));
            renderDienst('dienstanweisungen', result.da_sperre.id);
        }
        return;
    }
    if (result && result.success) {
        if (window.CxQmLive) CxQmLive.restart();
        showToast('Schicht gestartet');
        renderZeit();
    } else {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-play"></i> Schicht starten'; }
        showToast((result && result.error) || 'Fehler', 'error');
    }
}

async function zeitClockOut() {
    if (!confirm('Schicht jetzt beenden?')) return;
    const btn = event && event.target ? event.target.closest('.zeit-btn') : null;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Standort wird geprüft...'; }
    const loc = await getGeo();
    const result = await apiPost(API.zeit + '/clock-out', { geo: loc ? loc.geo : null, geo_accuracy: loc ? loc.accuracy : null, uebergabe: localStorage.getItem('cx_qm_uebergabe') || null });
    if (result && result.success) {
        localStorage.removeItem('cx_qm_uebergabe');
        clearInterval(zeitTimer);
        showToast('Schicht beendet — ' + result.worked_hours + 'h gearbeitet');
        renderZeit();
    } else {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-stop"></i> Beenden'; }
        showToast((result && result.error) || 'Fehler', 'error');
    }
}

// While paused, show when the planned pause ends and the shift auto-resumes
// (server-side `shifts:auto-resume-pauses` flips the record back to aktiv).
function pauseAutoResumeHtml(active) {
    const planned = parseInt(active.plan_pause || 0, 10);
    if (!planned || !active.pause_start) return '';
    // Budget semantics: this pause ends after the minutes still left
    // (planned − already used), matching shifts:auto-resume-pauses.
    const remaining = Math.max(0, planned - parseInt(active.pause_total || 0, 10));
    const end = new Date(new Date(active.pause_start.replace(' ', 'T') + 'Z').getTime() + remaining * 60000);
    const hhmm = end.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    return '<div class="zeit-active-note"><i class="far fa-clock"></i> Automatisches Fortsetzen um ' + hhmm + ' Uhr (' + remaining + ' von ' + planned + ' Min)</div>';
}

// "Endet um HH:MM" + Verlängern button. Turns urgent (orange) when ≤10 min
// remain, red once the (extended) end has passed — mirrors the push
// reminders the server sends at the same moments.
function renderShiftEndRow(active) {
    if (!active.display_end) return '';
    const left = active.minutes_to_end;
    const ext = active.extend_minutes || 30;
    const n = active.verlaengerungen || 0;
    let cls = '', txt = 'Endet um ' + fmtTime(active.display_end);
    if (left !== null && left !== undefined) {
        if (left <= 0)       { cls = 'over';   txt = 'Schichtende ' + fmtTime(active.display_end) + ' erreicht — beenden oder verlängern'; }
        else if (left <= 10) { cls = 'soon';   txt = 'Endet in ' + left + ' Min (' + fmtTime(active.display_end) + ')'; }
    }
    return `
        <div class="zeit-end-row ${cls}" id="zeitEndRow">
            <div class="zeit-end-text"><i class="far fa-clock"></i> ${txt}${n > 0 ? ` <span class="zeit-end-badge">${n}× verlängert</span>` : ''}</div>
            <button class="zeit-btn zeit-btn-extend" onclick="zeitOpenExtendSheet()"><i class="fas fa-plus"></i> ${ext} Min</button>
        </div>`;
}

// ---- Extend sheet: reason (mandatory) + Verlängern / Beenden ----
// Opens by itself in the last minute before the (extended) shift end and from
// the "+30 Min" button. Verlängern stays disabled until a reason is typed;
// Beenden always works. Not dismissable — the worker has to decide.
let zeitExtendTimer = null;
let zeitExtendActive = null;

function zeitScheduleExtendSheet(active) {
    clearTimeout(zeitExtendTimer);
    zeitExtendActive = active || null;
    if (!active || active.seconds_to_end == null) { zeitCloseExtendSheet(); return; }
    const msToLastMinute = (Number(active.seconds_to_end) - 60) * 1000;
    if (msToLastMinute <= 0) zeitOpenExtendSheet();
    else zeitExtendTimer = setTimeout(() => zeitOpenExtendSheet(), msToLastMinute);
}

function zeitOpenExtendSheet() {
    const active = zeitExtendActive;
    if (!active) return;
    const ext = active.extend_minutes || 30;
    let overlay = document.getElementById('extendSheet');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'extendSheet';
        overlay.dataset.noSwipe = '1';
        overlay.innerHTML = `
            <div class="modal-sheet">
                <div class="modal-handle"></div>
                <div class="modal-title" id="extendSheetTitle"></div>
                <p class="notif-ask-text" id="extendSheetText"></p>
                <label class="modal-input-label" for="extendReason">Grund für die Verlängerung</label>
                <textarea class="modal-input extend-reason" id="extendReason" rows="3" maxlength="500"
                    placeholder="z.B. Ablösung kommt später, Kunde hat verlängert …" oninput="zeitExtendReasonInput()"></textarea>
                <div class="extend-sheet-actions">
                    <button type="button" class="modal-btn modal-btn-primary" id="extendSheetBtn" disabled onclick="zeitExtendSubmit()"></button>
                    <button type="button" class="modal-btn extend-btn-finish" id="extendFinishBtn" onclick="zeitExtendFinish()"><i class="fas fa-stop"></i> Beenden</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
    }
    const end = active.display_end ? fmtTime(active.display_end) : '';
    document.getElementById('extendSheetTitle').textContent = end ? 'Schicht endet um ' + end + ' Uhr' : 'Schichtende';
    document.getElementById('extendSheetText').textContent = 'Musst du länger bleiben? Schreib kurz den Grund und verlängere um '
        + ext + ' Min — sonst beende die Schicht.';
    document.getElementById('extendSheetBtn').innerHTML = '<i class="fas fa-plus"></i> Verlängern';
    zeitExtendReasonInput();
    requestAnimationFrame(() => overlay.classList.add('active'));
}

function zeitCloseExtendSheet() {
    const overlay = document.getElementById('extendSheet');
    if (overlay) overlay.remove();
}

function zeitExtendReasonOk() {
    const el = document.getElementById('extendReason');
    const min = (zeitExtendActive && zeitExtendActive.extend_reason_min) || 3;
    return !!el && el.value.trim().length >= min;
}

function zeitExtendReasonInput() {
    const btn = document.getElementById('extendSheetBtn');
    if (btn) btn.disabled = !zeitExtendReasonOk();
}

async function zeitExtendSubmit() {
    if (!zeitExtendReasonOk()) return;
    const btn = document.getElementById('extendSheetBtn');
    btn.disabled = true;
    const result = await apiPost(API.zeit + '/extend', { grund: document.getElementById('extendReason').value.trim() });
    if (result && result.success) {
        zeitCloseExtendSheet();
        showToast('Schicht verlängert bis ' + result.display_end);
        renderZeit();
    } else {
        btn.disabled = false;
        showToast((result && result.error) || 'Fehler', 'error');
    }
}

async function zeitExtendFinish() {
    const btn = document.getElementById('extendFinishBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Standort wird geprüft...';
    const loc = await getGeo();
    const result = await apiPost(API.zeit + '/clock-out', { geo: loc ? loc.geo : null, geo_accuracy: loc ? loc.accuracy : null, uebergabe: localStorage.getItem('cx_qm_uebergabe') || null });
    if (result && result.success) {
        localStorage.removeItem('cx_qm_uebergabe');
        clearInterval(zeitTimer);
        zeitCloseExtendSheet();
        showToast('Schicht beendet — ' + result.worked_hours + 'h gearbeitet');
        renderZeit();
    } else {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-stop"></i> Beenden';
        showToast((result && result.error) || 'Fehler', 'error');
    }
}

async function zeitPauseStart() {
    const result = await apiPost(API.zeit + '/pause-start', {});
    if (result && result.success) {
        clearInterval(zeitTimer);
        showToast('Pause gestartet');
        renderZeit();
    } else {
        showToast((result && result.error) || 'Fehler', 'error');
    }
}

async function zeitPauseEnd() {
    const result = await apiPost(API.zeit + '/pause-end', {});
    if (result && result.success) {
        showToast('Pause beendet (' + result.pause_total + ' Min. gesamt)');
        renderZeit();
    } else {
        showToast((result && result.error) || 'Fehler', 'error');
    }
}

// ============================================
// FREI (AVAILABILITY / ABSENCES)
// ============================================
let freiData = null;
let freiEditId = null;

async function renderFrei() {
    const content = document.getElementById('content');
    const data = await apiGet(API.absences + '?jahr=' + new Date().getFullYear());
    if (!data) { content.innerHTML = '<div class="coming-soon"><i class="fas fa-exclamation-circle"></i><h2>Fehler</h2><p>Daten konnten nicht geladen werden.</p></div>'; return; }
    freiData = data;

    const upcoming = (data.abwesenheiten || []).filter(a => a.startdatum >= toIsoDate(new Date()));
    const past = (data.abwesenheiten || []).filter(a => a.startdatum < toIsoDate(new Date()));

    content.innerHTML = `
        <div class="frei-header">
            <div class="frei-header-top">
                <h2 class="frei-title">Abwesenheiten</h2>
                <button class="frei-add-btn" onclick="openFreiForm()"><i class="fas fa-plus"></i></button>
            </div>
            ${(data.urlaubskonto && data.urlaubskonto.anspruch > 0) ? (() => {
                const k = data.urlaubskonto;
                const usedPct = Math.min(100, (k.genommen / k.anspruch) * 100);
                const pendPct = Math.min(100 - usedPct, (k.beantragt / k.anspruch) * 100);
                return `
            <div class="frei-vacation-bar">
                <div class="frei-vac-info">
                    <span class="frei-vac-label">Urlaubskonto ${k.jahr}</span>
                    <span class="frei-vac-count">${k.genommen} / ${k.anspruch} Tage</span>
                </div>
                <div class="frei-vac-track">
                    <div class="frei-vac-fill" style="width:${usedPct}%"></div>
                    <div class="frei-vac-fill frei-vac-fill-pending" style="width:${pendPct}%"></div>
                </div>
                <div class="frei-vac-rest">${k.beantragt > 0 ? `${k.beantragt} beantragt · ` : ''}${k.rest} Tage übrig</div>
            </div>`;
            })() : ''}
        </div>

        ${upcoming.length > 0 ? `
        <div class="frei-section">
            <div class="frei-section-title">Geplant</div>
            ${upcoming.map(a => freiCard(a, true)).join('')}
        </div>` : ''}

        ${past.length > 0 ? `
        <div class="frei-section">
            <div class="frei-section-title">Vergangen</div>
            ${past.map(a => freiCard(a, false)).join('')}
        </div>` : ''}

        ${data.abwesenheiten.length === 0 ? `
        <div class="coming-soon" style="padding-top:40px">
            <i class="fas fa-calendar-check"></i>
            <h2>Keine Abwesenheiten</h2>
            <p>Tippen Sie auf <strong>+</strong> um eine neue Abwesenheit einzutragen.</p>
        </div>` : ''}

        <div class="modal-overlay" id="freiModal">
            <div class="modal-sheet">
                <div class="modal-handle"></div>
                <div class="modal-title-row">
                    <div class="modal-title" id="freiModalTitle">Neue Abwesenheit</div>
                    <button type="button" class="modal-close-btn" onclick="closeFreiForm()" aria-label="Schließen">&times;</button>
                </div>

                <label class="modal-input-label">Art der Abwesenheit</label>
                <div class="frei-kat-row" id="freiKatRow">
                    <button type="button" class="frei-kat-btn" data-kat="urlaub" onclick="freiSelectKat('urlaub')">
                        <i class="fas fa-umbrella-beach"></i><span>Urlaub</span>
                    </button>
                    <button type="button" class="frei-kat-btn" data-kat="krank" onclick="freiSelectKat('krank')">
                        <i class="fas fa-briefcase-medical"></i><span>Krank</span>
                    </button>
                    <button type="button" class="frei-kat-btn active" data-kat="sonstiges" onclick="freiSelectKat('sonstiges')">
                        <i class="fas fa-calendar-minus"></i><span>Sonstiges</span>
                    </button>
                </div>
                <div class="frei-kat-hint" id="freiKatHint"></div>

                <label class="modal-input-label">Startdatum</label>
                <div class="dt-wrap"><i class="fas fa-calendar"></i><input type="date" class="modal-input" id="frei_start" min="${toIsoDate(new Date())}" onchange="freiSyncDayMode()"></div>

                <label class="modal-input-label">Enddatum <span style="font-weight:400;color:var(--text-muted)">(optional, bei mehrtägig)</span></label>
                <div class="dt-wrap"><i class="fas fa-calendar"></i><input type="date" class="modal-input" id="frei_end" min="${toIsoDate(new Date())}" onchange="freiSyncDayMode()"></div>

                <div class="frei-multiday-note" id="freiMultidayNote" style="display:none">
                    <i class="fas fa-info-circle"></i> Mehrtägige Abwesenheiten gelten immer als ganze Tage.
                </div>

                <div class="frei-toggle-row" id="freiToggleRow">
                    <label class="frei-toggle-label">Ganzer Tag</label>
                    <label class="frei-switch">
                        <input type="checkbox" id="frei_ganzer_tag" onchange="freiToggleGanzerTag()">
                        <span class="frei-switch-slider"></span>
                    </label>
                </div>

                <div id="freiTimeFields">
                    <label class="modal-input-label">Von</label>
                    <div class="dt-wrap"><i class="fas fa-clock"></i><input type="text" lang="de-DE" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="modal-input t24" id="frei_startzeit"></div>
                    <label class="modal-input-label">Bis</label>
                    <div class="dt-wrap"><i class="fas fa-clock"></i><input type="text" lang="de-DE" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="modal-input t24" id="frei_endzeit"></div>
                </div>

                <label class="modal-input-label">Bemerkung <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                <input type="text" class="modal-input" id="frei_beschreibung" placeholder="z.B. Zahnarzt um 14 Uhr">

                <button class="modal-btn modal-btn-primary" id="freiSubmitBtn" onclick="submitFreiForm()">Absenden</button>
                <button class="modal-btn modal-btn-outline" onclick="closeFreiForm()">Abbrechen</button>
            </div>
        </div>
    `;
}

function freiStatusBadge(status, grund) {
    // Defaults to 'genehmigt' so legacy rows (pre-workflow) keep their look.
    const s = status || 'genehmigt';
    const map = {
        pending:   { label: 'Wartet auf Antwort', cls: 'frei-status-pending',   icon: 'hourglass-half' },
        genehmigt: { label: 'Genehmigt',          cls: 'frei-status-genehmigt', icon: 'check-circle'   },
        abgelehnt: { label: 'Abgelehnt',          cls: 'frei-status-abgelehnt', icon: 'times-circle'   },
    };
    const m = map[s] || map.genehmigt;
    return `<div class="frei-status-badge ${m.cls}"><i class="fas fa-${m.icon}"></i> ${m.label}</div>`;
}

function freiCard(absence, canDelete) {
    const isFuture = absence.startdatum > toIsoDate(new Date());
    const dateLabel = absence.startdatum === absence.enddatum || !absence.enddatum
        ? fmtDate(absence.startdatum)
        : fmtDate(absence.startdatum) + ' – ' + fmtDate(absence.enddatum);
    const timeLabel = absence.ganzer_tag
        ? 'Ganzer Tag'
        : (fmtTime(absence.startzeit) + ' – ' + fmtTime(absence.endzeit));
    const typeClass = freiTypeClass(absence.typ);
    const status = absence.status || 'genehmigt';
    const pending = status === 'pending';
    const rejected = status === 'abgelehnt';
    // Worker can only modify a card that is still in the future AND not yet
    // decided by a manager. Once approved/rejected the antrag is locked.
    const showActions = isFuture && canDelete && pending;

    return `
        <div class="frei-card ${pending ? 'frei-card-pending' : ''} ${rejected ? 'frei-card-rejected' : ''}">
            <div class="frei-card-left">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                    <div class="frei-card-badge ${typeClass}">${esc(absence.typ)}</div>
                    ${freiStatusBadge(status)}
                </div>
                <div class="frei-card-date"><i class="fas fa-calendar-day"></i> ${dateLabel}</div>
                <div class="frei-card-time"><i class="fas fa-clock"></i> ${timeLabel} · ${absence.tage} Tag${absence.tage != 1 ? 'e' : ''}</div>
                ${absence.beschreibung ? `<div class="frei-card-note">${esc(absence.beschreibung)}</div>` : ''}
                ${rejected && absence.ablehnungsgrund ? `<div class="frei-card-note frei-card-rejected-reason"><i class="fas fa-comment-alt"></i> ${esc(absence.ablehnungsgrund)}</div>` : ''}
            </div>
            ${showActions ? `<div class="frei-card-actions">
                <button class="frei-card-edit" onclick="editAbsence(${absence.id})"><i class="fas fa-pen"></i></button>
                <button class="frei-card-delete" onclick="deleteAbsence(${absence.id})"><i class="fas fa-trash-alt"></i></button>
            </div>` : ''}
        </div>
    `;
}

function freiTypeClass(typ) {
    switch (typ) {
        case 'Wunschfrei': return 'badge-wunschfrei';
        case 'Urlaub': return 'badge-urlaub';
        case 'Krankmeldung': return 'badge-krank';
        case 'Unbezahlte Urlaub': return 'badge-unbezahlt';
        case 'Frei aus Überstunden': return 'badge-ueberstunden';
        case 'Arzt Termin': return 'badge-arzt';
        default: return 'badge-default';
    }
}

let freiKat = 'sonstiges';

const FREI_KAT_INFO = {
    urlaub:    { hint: 'Wird vom Urlaubskonto abgezogen. Jeder Kalendertag zählt (auch Wochenende). Benötigt Genehmigung.', minToday: true },
    krank:     { hint: 'Wird sofort gemeldet — keine Genehmigung nötig. Bis zu 14 Tage rückwirkend möglich.', minToday: false },
    sonstiges: { hint: 'Unbezahlter Wunschfrei-Antrag. Benötigt Genehmigung.', minToday: true },
};

function freiSelectKat(kat) {
    freiKat = kat;
    document.querySelectorAll('#freiKatRow .frei-kat-btn').forEach(b =>
        b.classList.toggle('active', b.dataset.kat === kat));
    const info = FREI_KAT_INFO[kat];
    const hintEl = document.getElementById('freiKatHint');
    if (hintEl) hintEl.textContent = info.hint;
    // Krank may be reported retroactively — relax the min date.
    const minDate = info.minToday ? toIsoDate(new Date()) : toIsoDate(new Date(Date.now() - 14 * 864e5));
    const startEl = document.getElementById('frei_start');
    const endEl = document.getElementById('frei_end');
    if (startEl) startEl.min = minDate;
    if (endEl) endEl.min = minDate;
}

function openFreiForm(absence) {
    freiEditId = absence ? absence.id : null;
    document.getElementById('freiModalTitle').textContent = absence ? 'Abwesenheit bearbeiten' : 'Neue Abwesenheit';
    document.getElementById('freiSubmitBtn').textContent = absence ? 'Speichern' : 'Absenden';
    // Category can only be chosen for NEW requests; editing keeps the original.
    document.getElementById('freiKatRow').style.display = absence ? 'none' : 'flex';
    if (!absence) freiSelectKat(absence ? (absence.kategorie || 'sonstiges') : 'sonstiges');
    document.getElementById('frei_start').value = absence ? absence.startdatum : '';
    document.getElementById('frei_end').value = absence && absence.enddatum !== absence.startdatum ? absence.enddatum : '';
    const ganzerTag = absence ? !!parseInt(absence.ganzer_tag) : false;
    document.getElementById('frei_ganzer_tag').checked = ganzerTag;
    document.getElementById('freiTimeFields').style.display = ganzerTag ? 'none' : 'block';
    if (!ganzerTag && absence) {
        document.getElementById('frei_startzeit').value = absence.startzeit ? absence.startzeit.substring(0,5) : '';
        document.getElementById('frei_endzeit').value = absence.endzeit ? absence.endzeit.substring(0,5) : '';
    } else {
        document.getElementById('frei_startzeit').value = '';
        document.getElementById('frei_endzeit').value = '';
    }
    document.getElementById('frei_beschreibung').value = absence ? (absence.beschreibung || '') : '';
    freiSyncDayMode();
    wOpenSheet('freiModal');
}

// Bottom sheets are rendered inside #content (a scroll container that forms
// its own stacking context on iOS), so `position: fixed` + z-index alone
// cannot lift them above #bottomNav. Hoist the overlay to <body> on open.
function wOpenSheet(id) {
    const overlay = document.getElementById(id);
    if (!overlay) return;
    if (overlay.parentElement !== document.body) {
        document.querySelectorAll('body > #' + id).forEach(old => old.remove());
        document.body.appendChild(overlay);
    }
    overlay.classList.add('active');
}

function editAbsence(id) {
    if (!freiData || !freiData.abwesenheiten) return;
    const absence = freiData.abwesenheiten.find(a => a.id == id);
    if (!absence) return;
    openFreiForm(absence);
}

function closeFreiForm() {
    document.getElementById('freiModal').classList.remove('active');
}

function freiToggleGanzerTag() {
    const checked = document.getElementById('frei_ganzer_tag').checked;
    document.getElementById('freiTimeFields').style.display = checked ? 'none' : 'block';
}

// Hourly (Von–Bis) absences only make sense within a SINGLE day.
// As soon as the range spans multiple days, force "Ganzer Tag" and
// hide both the toggle and the time fields.
function freiSyncDayMode() {
    const start = document.getElementById('frei_start').value;
    const end = document.getElementById('frei_end').value;
    const multiDay = !!(start && end && end !== start);
    document.getElementById('freiToggleRow').style.display = multiDay ? 'none' : 'flex';
    document.getElementById('freiMultidayNote').style.display = multiDay ? 'block' : 'none';
    if (multiDay) {
        document.getElementById('frei_ganzer_tag').checked = true;
        document.getElementById('freiTimeFields').style.display = 'none';
    } else {
        freiToggleGanzerTag();
    }
}

async function submitFreiForm() {
    const startdatum = document.getElementById('frei_start').value;
    const enddatum = document.getElementById('frei_end').value;
    const ganzerTag = document.getElementById('frei_ganzer_tag').checked;
    const beschreibung = document.getElementById('frei_beschreibung').value.trim();

    if (!startdatum) { showToast('Bitte Startdatum auswählen', 'error'); return; }
    if (enddatum && enddatum < startdatum) { showToast('Enddatum muss nach Startdatum liegen', 'error'); return; }

    const payload = {
        kategorie: freiKat,
        startdatum,
        enddatum: enddatum || startdatum,
        ganzer_tag: ganzerTag ? 1 : 0,
        beschreibung: beschreibung || null
    };

    if (!ganzerTag) {
        payload.startzeit = document.getElementById('frei_startzeit').value;
        payload.endzeit = document.getElementById('frei_endzeit').value;
        if (!payload.startzeit || !payload.endzeit) { showToast('Start- und Endzeit ausfüllen', 'error'); return; }
    }

    let result;
    if (freiEditId) {
        payload.id = freiEditId;
        try {
            const res = await fetch(API.absences, {
                method: 'PUT',
                headers: wAuthHeadersJson(),
                body: JSON.stringify(payload)
            });
            if (res.status === 401) { wClearToken(); window.location.href = LOGIN_URL; return; }
            result = await res.json();
        } catch (e) { result = null; }
    } else {
        result = await apiPost(API.absences, payload);
    }
    if (result && result.success) {
        closeFreiForm();
        showToast(freiEditId ? 'Abwesenheit aktualisiert'
            : (freiKat === 'krank' ? 'Krankmeldung übermittelt — gute Besserung!' : 'Antrag eingereicht — wartet auf Genehmigung'));
        freiEditId = null;
        renderFrei();
    } else {
        showToast((result && result.error) || 'Fehler beim Speichern', 'error');
    }
}

async function deleteAbsence(id) {
    if (!confirm('Abwesenheit wirklich löschen?')) return;
    try {
        const res = await fetch(API.absences, {
            method: 'DELETE',
            headers: wAuthHeadersJson(),
            body: JSON.stringify({ id })
        });
        if (res.status === 401) { wClearToken(); window.location.href = LOGIN_URL; return; }
        const result = await res.json();
        if (result && result.success) {
            showToast('Abwesenheit gelöscht');
            renderFrei();
        } else {
            showToast((result && result.error) || 'Fehler', 'error');
        }
    } catch (e) {
        showToast('Fehler beim Löschen', 'error');
    }
}

// ============================================
// CHAT (Worker Messages)
// ============================================
let chatMessages = [];
let chatPollTimer = null;

async function renderChat() {
    const content = document.getElementById('content');
    const data = await apiGet(API.messages);
    if (!data) { content.innerHTML = '<div class="coming-soon"><i class="fas fa-exclamation-circle"></i><h2>Fehler</h2><p>Nachrichten konnten nicht geladen werden.</p></div>'; return; }
    chatMessages = data.nachrichten || [];

    content.innerHTML = `
        <div class="chat-container">
            <div class="chat-header">
                ${IS_CAPACITOR && Capacitor.getPlatform() === 'android' ? '' : `<button type="button" class="chat-back-btn" onclick="chatGoBack()" aria-label="Zurück"><i class="fas fa-chevron-left"></i></button>`}
                <div class="chat-header-text">
                    <h2 class="chat-title">Nachrichten</h2>
                    <span class="chat-subtitle">an die Verwaltung</span>
                </div>
            </div>
            <div class="chat-messages" id="chatMessages">
                ${chatMessages.length === 0 ? `
                    <div class="chat-empty">
                        <i class="fas fa-comment-dots"></i>
                        <p>Noch keine Nachrichten.<br>Schreiben Sie Ihre erste Nachricht.</p>
                    </div>
                ` : chatMessages.map(m => chatBubble(m)).join('')}
            </div>
            <div class="chat-input-bar">
                <input type="file" id="chatPhotoInput" accept="image/*" capture="environment" style="display:none" onchange="chatPhotoPicked(this)">
                <div class="chat-input-wrap">
                    <input type="text" class="chat-input" id="chatInput" placeholder="Nachricht schreiben..." maxlength="2000"
                        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendChatMessage()}">
                    <button type="button" class="chat-cam-btn" onclick="chatTakePhoto()" aria-label="Foto aufnehmen" title="Foto vom Einsatzort">${CHAT_CAM_ICON}</button>
                </div>
                <button class="chat-send-btn" onclick="sendChatMessage()"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>
    `;
    chatScrollBottom();
    startChatPoll();
    chatLoadPhotoContext();
    setTabBadge('chat', 0); // GET /messages just marked the thread read
}

// Fallback for the PWA where capacitor-bridge.js isn't loaded.
// The native shell overrides this with a richer version that opens
// the document in an in-app viewer instead of an external browser.
if (typeof window.openDocument !== 'function') {
    window.openDocument = function (url) { window.open(url, '_blank'); };
}

// DB timestamps are UTC ('YYYY-MM-DD HH:MM:SS'); new Date() on that
// format parses as LOCAL time — force UTC, render Europe/Berlin.
function parseUtcTs(ts) {
    if (!ts) return null;
    let s = String(ts).trim();
    if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) s = s.replace(' ', 'T') + 'Z';
    const d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
}

// "📞 Verpasster Anruf von … um HH:MM Uhr" — written by CallService::recordMissed.
const MISSED_CALL_RE = /^📞\s*Verpasster Anruf/;
const PHONE_MISSED_SVG = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25c1.1.37 2.3.57 3.6.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1zM15.3 2.3 18 5l2.7-2.7 1.4 1.4L19.4 6.4l2.7 2.7-1.4 1.4L18 7.8l-2.7 2.7-1.4-1.4L16.6 6.4 13.9 3.7z"/></svg>';

function chatBubble(msg) {
    const isWorker = msg.richtung === 'worker';
    const _cd = parseUtcTs(msg.created_at);
    const time = _cd ? _cd.toLocaleString('de-DE', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit', timeZone:'Europe/Berlin'}) : '';
    if (!isWorker && MISSED_CALL_RE.test(msg.nachricht || '')) {
        return `
        <div class="chat-missed-call">
            <span class="chat-missed-call-pill">${PHONE_MISSED_SVG}<span>${esc(String(msg.nachricht).replace(/^📞\s*/, ''))}</span></span>
        </div>`;
    }
    // Authenticated per-message route (verifies the doc belongs to THIS worker
    // via the token) — never the public /serve?path= endpoint.
    const docUrl = msg.datei_pfad ? `${W_API_BASE}/worker-app/messages/document/${msg.id}?token=${encodeURIComponent(wGetToken() || '')}` : '';
    const isPhoto = !!msg.datei_pfad && /\.(jpe?g|png|webp)$/i.test(msg.datei_pfad);
    const photoSrc = msg._localSrc || docUrl;
    const attachment = isPhoto ? `
        <img class="chat-photo" src="${esc(photoSrc)}" alt="Foto" loading="lazy" onclick="chatPhotoView(this.src)">`
        : msg.datei_pfad ? `
        <a href="${docUrl}" onclick="event.preventDefault();openDocument('${docUrl.replace(/'/g, "\\'")}', ${JSON.stringify(esc(msg.datei_name || 'Dokument'))});return false;" style="display:flex;align-items:center;gap:8px;padding:10px 12px;margin-top:6px;background:rgba(255,255,255,.9);border-radius:10px;text-decoration:none;color:#1a1a1a;border:1px solid rgba(0,0,0,.1)">
            <i class="fas fa-file-alt" style="font-size:1.4rem;color:#dc2626"></i>
            <div style="flex:1;min-width:0">
                <div style="font-size:0.82rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(msg.datei_name || 'Dokument')}</div>
                <div style="font-size:0.7rem;color:#666">Tippen zum Öffnen</div>
            </div>
            <i class="fas fa-download" style="font-size:0.85rem;color:#2563eb"></i>
        </a>` : '';
    return `
        <div class="chat-bubble ${isWorker ? 'chat-bubble-out' : 'chat-bubble-in'}">
            ${!isWorker && msg.admin_name ? `<div class="chat-sender">${esc(msg.admin_name)}</div>` : ''}
            <div class="chat-text">${esc(msg.nachricht)}</div>
            ${attachment}
            <div class="chat-time">${time}</div>
        </div>
    `;
}

// ============================================
// CHAT — site photo (camera) with date/time/place stamp
// ============================================
const CHAT_CAM_ICON = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>';
let _chatPhotoCtx = { ort: null, offsetMs: 0 };
let _chatPhotoBlob = null;

async function chatLoadPhotoContext() {
    const t0 = Date.now();
    const res = await apiGet(API.messages + '/photo-context');
    if (res && res.server_time) {
        const srv = new Date(res.server_time).getTime();
        _chatPhotoCtx = { ort: res.ort || null, offsetMs: isFinite(srv) ? srv - Math.round((t0 + Date.now()) / 2) : 0 };
    }
}

function chatTakePhoto() {
    const input = document.getElementById('chatPhotoInput');
    if (!input) return;
    input.value = '';
    chatLoadPhotoContext();
    input.click();
}

function chatStampText(d) {
    const p = n => String(n).padStart(2, '0');
    const date = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Berlin', day: '2-digit', month: '2-digit', year: 'numeric' }).format(d);
    const time = new Intl.DateTimeFormat('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit', hour12: false }).format(d);
    return { date, time: time + ' Uhr' };
}

function chatImgFrom(src) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Bild konnte nicht geladen werden'));
        img.src = src;
    });
}

// Camera files from some WebViews (large Samsung shots, content:// URIs)
// fail as a blob: <img>. Try the fastest decoder first, then fall back.
async function chatDecodeImage(file) {
    if (window.createImageBitmap) {
        try {
            const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
            return { src: bmp, w: bmp.width, h: bmp.height, done: () => bmp.close && bmp.close() };
        } catch (e) { /* fall through */ }
    }
    const url = URL.createObjectURL(file);
    try {
        const img = await chatImgFrom(url);
        return { src: img, w: img.naturalWidth, h: img.naturalHeight, done: () => URL.revokeObjectURL(url) };
    } catch (e) { URL.revokeObjectURL(url); }
    const dataUrl = await new Promise((resolve, reject) => {
        const fr = new FileReader();
        fr.onload = () => resolve(fr.result);
        fr.onerror = () => reject(new Error('Bild konnte nicht gelesen werden'));
        fr.readAsDataURL(file);
    });
    const img = await chatImgFrom(dataUrl);
    return { src: img, w: img.naturalWidth, h: img.naturalHeight, done: () => {} };
}

// Downscale to max 1600px and burn date, time and place into the picture.
async function chatStampPhoto(file, takenAt, ort) {
    const pic = await chatDecodeImage(file);
    if (!pic.w || !pic.h) { pic.done(); throw new Error('Bild konnte nicht geladen werden'); }
    const max = 1600;
    const scale = Math.min(1, max / Math.max(pic.w, pic.h));
    const w = Math.round(pic.w * scale), h = Math.round(pic.h * scale);
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const ctx = c.getContext('2d');
    ctx.drawImage(pic.src, 0, 0, w, h);
    pic.done();

    const s = chatStampText(takenAt);
    const fs = Math.max(18, Math.round(Math.min(w, h) * 0.042));
    const lines = [s.date + '   ' + s.time];
    if (ort) lines.push(ort);
    const pad = Math.round(fs * 0.7);
    const bandH = pad * 2 + lines.length * fs * 1.25;
    ctx.fillStyle = 'rgba(0, 0, 0, 0.55)';
    ctx.fillRect(0, h - bandH, w, bandH);
    ctx.fillStyle = '#ffffff';
    ctx.textBaseline = 'top';
    lines.forEach((line, i) => {
        ctx.font = (i === 0 ? '700 ' : '600 ') + fs + 'px -apple-system, Roboto, "Segoe UI", Arial, sans-serif';
        let text = line;
        while (ctx.measureText(text).width > w - pad * 2 && text.length > 4) text = text.slice(0, -2) + '…';
        ctx.fillText(text, pad, h - bandH + pad + i * fs * 1.25);
    });
    return new Promise(r => c.toBlob(b => r(b), 'image/jpeg', 0.82));
}

async function chatPhotoPicked(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    if (!file.size) { showToast('Das Foto ist leer — bitte erneut aufnehmen', 'error'); return; }
    const takenAt = new Date(Date.now() + (_chatPhotoCtx.offsetMs || 0));
    let blob = null;
    try { blob = await chatStampPhoto(file, takenAt, _chatPhotoCtx.ort); }
    catch (e) { console.warn('Wachfoto: stamp failed', e, file.type, file.size); }
    // Could not decode on this device → send the original; the server still
    // adds date, time and place as the caption.
    if (!blob) {
        if (file.size > 10 * 1024 * 1024) { showToast('Foto ist zu groß (max. 10 MB)', 'error'); return; }
        blob = file;
    }
    _chatPhotoBlob = { blob, takenAt };
    const src = URL.createObjectURL(blob);
    const s = chatStampText(takenAt);
    document.querySelectorAll('#chatPhotoModal').forEach(m => m.remove());
    document.body.insertAdjacentHTML('beforeend', `
        <div class="modal-overlay" id="chatPhotoModal" onclick="if(event.target===this)chatPhotoClose()">
            <div class="modal-sheet">
                <div class="modal-handle"></div>
                <div class="modal-title-row">
                    <div class="modal-title">Foto senden</div>
                    <button type="button" class="modal-close-btn" onclick="chatPhotoClose()" aria-label="Schließen">&times;</button>
                </div>
                <img class="chat-photo-preview" src="${src}" alt="Vorschau">
                <div class="chat-photo-meta">${esc(s.date)} · ${esc(s.time)}${_chatPhotoCtx.ort ? ' · ' + esc(_chatPhotoCtx.ort) : ''}</div>
                <button class="modal-btn modal-btn-primary" id="chatPhotoSendBtn" onclick="chatPhotoSend()">Senden</button>
                <button class="modal-btn modal-btn-outline" onclick="chatPhotoClose(); chatTakePhoto();">Neu aufnehmen</button>
            </div>
        </div>`);
    requestAnimationFrame(() => document.getElementById('chatPhotoModal')?.classList.add('active'));
}

// Full-screen photo view inside the app — no print/save/share bar.
function chatPhotoView(src) {
    if (!src) return;
    document.querySelectorAll('#chatPhotoViewer').forEach(v => v.remove());
    document.body.insertAdjacentHTML('beforeend', `
        <div class="chat-photo-viewer" id="chatPhotoViewer" onclick="if(event.target===this||event.target.tagName==='IMG')this.remove()">
            <button type="button" class="chat-photo-viewer-close" onclick="document.getElementById('chatPhotoViewer')?.remove()" aria-label="Schließen">&times;</button>
            <img src="${esc(src)}" alt="Foto">
        </div>`);
}

function chatPhotoClose() {
    const m = document.getElementById('chatPhotoModal');
    if (!m) return;
    m.classList.remove('active');
    setTimeout(() => m.remove(), 350);
}

async function chatPhotoSend() {
    if (!_chatPhotoBlob) return;
    const btn = document.getElementById('chatPhotoSendBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Wird gesendet …'; }
    const fd = new FormData();
    fd.append('foto', _chatPhotoBlob.blob, 'foto.jpg');
    fd.append('aufgenommen_am', _chatPhotoBlob.takenAt.toISOString());
    let res = null;
    try {
        const r = await fetch(API.messages + '/photo', { method: 'POST', headers: wAuthHeaders(), body: fd });
        if (r.status === 401) { wClearToken(); window.location.href = LOGIN_URL; return; }
        res = await r.json();
    } catch (e) { res = null; }
    if (!res || !res.success) {
        if (btn) { btn.disabled = false; btn.textContent = 'Senden'; }
        showToast((res && res.error) || 'Foto konnte nicht gesendet werden — bitte Verbindung prüfen', 'error');
        return;
    }
    const localSrc = URL.createObjectURL(_chatPhotoBlob.blob);
    _chatPhotoBlob = null;
    chatPhotoClose();
    const el = document.getElementById('chatMessages');
    if (el) {
        el.querySelector('.chat-empty')?.remove();
        const msg = { id: res.id, richtung: 'worker', nachricht: res.nachricht, datei_pfad: res.datei_pfad, created_at: new Date().toISOString(), admin_name: null };
        chatMessages.push(msg);
        el.insertAdjacentHTML('beforeend', chatBubble({ ...msg, _localSrc: localSrc }));
        chatScrollBottom();
    }
    showToast('Foto gesendet');
}

function chatScrollBottom() {
    const el = document.getElementById('chatMessages');
    if (el) setTimeout(() => el.scrollTop = el.scrollHeight, 50);
}

async function sendChatMessage() {
    const input = document.getElementById('chatInput');
    if (!input) return;
    const text = input.value.trim();
    if (!text) return;

    input.disabled = true;
    const result = await apiPost(API.messages, { nachricht: text });
    input.disabled = false;

    if (result && result.success) {
        input.value = '';
        // Append locally for instant feedback
        const el = document.getElementById('chatMessages');
        const empty = el.querySelector('.chat-empty');
        if (empty) empty.remove();
        el.insertAdjacentHTML('beforeend', chatBubble({
            richtung: 'worker', nachricht: text, created_at: new Date().toISOString(), admin_name: null
        }));
        chatScrollBottom();
    } else {
        showToast((result && result.error) || 'Fehler beim Senden', 'error');
    }
}

function startChatPoll() {
    stopChatPoll();
    chatPollTimer = setInterval(async () => {
        if (currentTab !== 'chat') { stopChatPoll(); return; }
        const data = await apiGet(API.messages);
        if (data && data.nachrichten && data.nachrichten.length !== chatMessages.length) {
            chatMessages = data.nachrichten;
            const el = document.getElementById('chatMessages');
            if (el) {
                el.innerHTML = chatMessages.map(m => chatBubble(m)).join('');
                chatScrollBottom();
            }
        }
    }, 10000);
}

function stopChatPoll() {
    if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null; }
}

// ============================================
// PROFILE
// ============================================
function renderProfil() {
    const content = document.getElementById('content');
    const initials = getInitials(worker.vorname, worker.name);
    const fullName = ((worker.vorname || '') + ' ' + (worker.name || '')).trim() || 'Mitarbeiter';

    content.innerHTML = `
        <div class="profile-header">
            ${notifBellHtml()}
            <div class="profile-avatar">${worker.foto ? `<img src="${ASSETS_BASE}${esc(worker.foto)}" alt="">` : esc(initials)}</div>
            <div class="profile-name">${esc(fullName)}</div>
            <div class="profile-sub">${esc(worker.dienst_nr || '')}${worker.bereich ? ' · ' + esc(worker.bereich) : ''}</div>
        </div>
        <div class="profile-section">
            <div class="profile-section-title">Dokumente</div>
            <div id="docRequestsBox"><div class="loading" style="padding:16px"><i class="fas fa-spinner fa-spin"></i></div></div>
        </div>
        <input type="file" id="docUploadInput" accept="application/pdf,.pdf" style="display:none" onchange="submitDocUpload()">
        <div class="profile-section">
            <div class="profile-section-title">Konto</div>
            <div class="profile-item" onclick="openChangePassword()">
                <i class="fas fa-key pi-icon"></i>
                <span class="pi-label">Passwort ändern</span>
                <i class="fas fa-chevron-right pi-chevron"></i>
            </div>
        </div>
        <div class="profile-section">
            <div class="profile-section-title">Darstellung</div>
            <div class="profile-item" onclick="toggleTheme()" style="cursor:pointer">
                <i class="fas fa-moon pi-icon" id="themeIcon"></i>
                <span class="pi-label">Dunkles Design</span>
                <label class="frei-switch" style="pointer-events:none">
                    <input type="checkbox" id="themeSwitch" ${document.documentElement.getAttribute('data-theme') === 'dark' ? 'checked' : ''}>
                    <span class="frei-switch-slider"></span>
                </label>
            </div>
        </div>
        <div class="btn-logout-wrap">
            <button class="btn-logout" onclick="handleLogout()">
                <i class="fas fa-sign-out-alt"></i> Abmelden
            </button>
        </div>

        <div class="modal-overlay" id="pwModal">
            <div class="modal-sheet">
                <div class="modal-handle"></div>
                <div class="modal-title">Passwort ändern</div>
                <label class="modal-input-label">Aktuelles Passwort</label>
                <input type="password" class="modal-input" id="pw_old" placeholder="Altes Passwort">
                <label class="modal-input-label">Neues Passwort</label>
                <input type="password" class="modal-input" id="pw_new" placeholder="Mind. 6 Zeichen">
                <label class="modal-input-label">Neues Passwort bestätigen</label>
                <input type="password" class="modal-input" id="pw_confirm" placeholder="Wiederholen">
                <button class="modal-btn modal-btn-primary" onclick="submitChangePassword()">Passwort ändern</button>
                <button class="modal-btn modal-btn-outline" onclick="closeChangePassword()">Abbrechen</button>
            </div>
        </div>
    `;

    updateNotifBell();
    loadDocRequests();
}

// Dark mode — flips the MD3 scheme (md3-tokens.css) and persists the choice.
function toggleTheme() {
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('worker_theme', next); } catch (e) { /* ignore */ }
    const sw = document.getElementById('themeSwitch');
    if (sw) sw.checked = next === 'dark';
    // Android: keep status/nav bar icons readable over the new background.
    if (window.wSyncSystemBarIcons) window.wSyncSystemBarIcons();
}

// ============================================
// DOCUMENT RENEWAL REQUESTS (profile section)
// ============================================
let docUploadRequestId = null;

async function loadDocRequests() {
    const box = document.getElementById('docRequestsBox');
    if (!box) return;
    const data = await apiGet(API.docRequests);
    const items = (data && data.items) || [];

    if (!items.length) {
        box.innerHTML = '<div style="padding:14px 16px;color:#94a3b8;font-size:0.85rem"><i class="fas fa-circle-check" style="color:#16a34a"></i> Keine offenen Dokument-Anfragen</div>';
        return;
    }

    const badge = (s) => {
        if (s === 'angefordert') return '<span style="background:#fef3c7;color:#b45309;border-radius:8px;padding:2px 8px;font-size:0.7rem;font-weight:700">Benötigt</span>';
        if (s === 'hochgeladen') return '<span style="background:#dbeafe;color:#1d4ed8;border-radius:8px;padding:2px 8px;font-size:0.7rem;font-weight:700">Wird geprüft</span>';
        if (s === 'abgelehnt')   return '<span style="background:#fee2e2;color:#dc2626;border-radius:8px;padding:2px 8px;font-size:0.7rem;font-weight:700">Abgelehnt</span>';
        return '<span style="background:#dcfce7;color:#15803d;border-radius:8px;padding:2px 8px;font-size:0.7rem;font-weight:700">Genehmigt</span>';
    };

    box.innerHTML = items.map(r => `
        <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9">
            <div style="display:flex;align-items:center;gap:8px;justify-content:space-between">
                <div style="font-weight:600;font-size:0.9rem;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis"><i class="fas fa-file-pdf" style="color:#dc2626"></i> ${esc(r.titel)}</div>
                ${badge(r.status)}
            </div>
            ${r.nachricht && r.status !== 'genehmigt' ? `<div style="font-size:0.8rem;color:#64748b;margin-top:4px">${esc(r.nachricht)}</div>` : ''}
            ${r.status === 'abgelehnt' && r.ablehnungsgrund ? `<div style="font-size:0.8rem;color:#dc2626;margin-top:4px"><i class="fas fa-circle-info"></i> ${esc(r.ablehnungsgrund)}</div>` : ''}
            ${(r.status === 'angefordert' || r.status === 'abgelehnt') ? `
                <button onclick="startDocUpload(${r.id})" style="margin-top:8px;background:#2563eb;color:#fff;border:none;border-radius:10px;padding:8px 14px;font-size:0.85rem;font-weight:600;width:100%">
                    <i class="fas fa-file-arrow-up"></i> ${r.status === 'abgelehnt' ? 'Erneut hochladen (PDF)' : 'PDF hochladen'}
                </button>` : ''}
            ${r.status === 'hochgeladen' ? `<div style="font-size:0.78rem;color:#1d4ed8;margin-top:6px"><i class="fas fa-hourglass-half"></i> Ihr Dokument wird von der Personalabteilung geprüft.</div>` : ''}
        </div>
    `).join('');
}

function startDocUpload(requestId) {
    docUploadRequestId = requestId;
    const input = document.getElementById('docUploadInput');
    input.value = '';
    input.click();
}

async function submitDocUpload() {
    const input = document.getElementById('docUploadInput');
    const file = input.files && input.files[0];
    if (!file || !docUploadRequestId) return;

    if (!/\.pdf$/i.test(file.name) && file.type !== 'application/pdf') {
        showToast('Nur PDF-Dateien erlaubt', 'error');
        return;
    }
    if (file.size > 10 * 1024 * 1024) {
        showToast('Datei zu groß (max. 10 MB)', 'error');
        return;
    }

    const fd = new FormData();
    fd.append('dokument', file);

    showToast('Wird hochgeladen …');
    try {
        const res = await fetch(API.docRequests + '/' + docUploadRequestId + '/upload', {
            method: 'POST',
            headers: wAuthHeaders(),
            body: fd
        });
        if (res.status === 401) { wClearToken(); window.location.href = LOGIN_URL; return; }
        const result = await res.json();
        if (result && result.success) {
            showToast('Dokument hochgeladen — wird geprüft');
            loadDocRequests();
        } else {
            showToast((result && result.error) || 'Upload fehlgeschlagen', 'error');
        }
    } catch (e) {
        showToast('Upload fehlgeschlagen', 'error');
    }
    docUploadRequestId = null;
}

function openChangePassword() {
    wOpenSheet('pwModal');
}

function closeChangePassword() {
    document.getElementById('pwModal').classList.remove('active');
    document.getElementById('pw_old').value = '';
    document.getElementById('pw_new').value = '';
    document.getElementById('pw_confirm').value = '';
}

async function submitChangePassword() {
    const oldPw = document.getElementById('pw_old').value;
    const newPw = document.getElementById('pw_new').value;
    const confirmPw = document.getElementById('pw_confirm').value;

    if (!oldPw || !newPw) { showToast('Alle Felder ausfüllen', 'error'); return; }
    if (newPw.length < 6) { showToast('Mind. 6 Zeichen erforderlich', 'error'); return; }
    if (newPw !== confirmPw) { showToast('Passwörter stimmen nicht überein', 'error'); return; }

    const result = await apiPost(API.auth + '/change-password', {
        old_password: oldPw,
        new_password: newPw
    });

    if (result && result.success) {
        closeChangePassword();
        showToast('Passwort geändert');
    } else {
        showToast((result && result.error) || 'Fehler beim Ändern', 'error');
    }
}

async function handleLogout() {
    if (!confirm('Möchten Sie sich abmelden?')) return;
    // Stop push delivery to this device — a logged-out phone must not keep
    // receiving company notifications. Best-effort.
    try {
        const fcmToken = localStorage.getItem('w_fcm_token');
        if (IS_CAPACITOR && fcmToken) {
            await apiPost(API.push + '/unsubscribe', { endpoint: fcmToken });
            localStorage.removeItem('w_fcm_token');
        } else if (navigator.serviceWorker && 'PushManager' in window) {
            const reg = await navigator.serviceWorker.getRegistration();
            const sub = reg && await reg.pushManager.getSubscription();
            if (sub) {
                await apiPost(API.push + '/unsubscribe', { endpoint: sub.endpoint });
                await sub.unsubscribe();
            }
        }
    } catch (e) { /* ignore */ }
    // Revoke the token server-side first so it can never be reused, even if
    // a copy lingers on the device. Best-effort: a network failure must not
    // trap the user in a logged-in state, so we clear locally regardless.
    try {
        const t = wGetToken();
        if (t) {
            await fetch(API.auth + '/logout', {
                method: 'POST',
                headers: wAuthHeadersJson(),
                body: '{}',
                keepalive: true
            });
        }
    } catch (e) { /* ignore — clear locally anyway */ }
    wClearToken();
    window.location.href = LOGIN_URL;
}

// ============================================
// HELPERS
// ============================================
function getGreeting() {
    const h = new Date().getHours();
    if (h < 12) return 'Guten Morgen';
    if (h < 18) return 'Guten Tag';
    return 'Guten Abend';
}

function getInitials(vorname, name) {
    const v = (vorname || '').charAt(0).toUpperCase();
    const n = (name || '').charAt(0).toUpperCase();
    return (v + n) || 'MA';
}

function getWeekStart(base) {
    const d = base ? new Date(base) : new Date();
    const day = d.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + diff);
    return toIsoDate(d);
}

function toIsoDate(d) {
    return d.getFullYear() + '-' +
        String(d.getMonth() + 1).padStart(2, '0') + '-' +
        String(d.getDate()).padStart(2, '0');
}

function fmtDateLong(d) {
    const days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    const months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    return days[d.getDay()] + ', ' + d.getDate() + '. ' + months[d.getMonth()] + ' ' + d.getFullYear();
}

function fmtDate(dateStr) {
    if (!dateStr) return '–';
    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    return parts[2] + '.' + parts[1] + '.' + parts[0];
}

function fmtTime(timeStr) {
    if (!timeStr) return '–';
    return timeStr.substring(0, 5);
}

function getCalendarWeek(d) {
    const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    date.setUTCDate(date.getUTCDate() + 4 - (date.getUTCDay() || 7));
    const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
    return Math.ceil(((date - yearStart) / 86400000 + 1) / 7);
}

function esc(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}

// ============================================
// NOTIFICATIONS (approve/reject responses from manager)
// ============================================
const NOTIF_API = W_API_BASE + '/worker-app/notifications';
let notifPollTimer = null;

async function refreshFreiBadge() {
    try {
        const data = await apiGet(NOTIF_API + '/unread-count');
        if (!data) return;
        const tab = document.querySelector('.nav-tab[data-tab="frei"]');
        if (!tab) return;
        let badge = tab.querySelector('.tab-badge');
        if ((data.unread_count | 0) > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'tab-badge';
                tab.appendChild(badge);
            }
            badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
            badge.style.display = '';
        } else if (badge) {
            badge.style.display = 'none';
        }
    } catch (e) { /* ignore */ }
}

function setTabBadge(tabName, count) {
    const tab = document.querySelector('.nav-tab[data-tab="' + tabName + '"]');
    if (!tab) return;
    let badge = tab.querySelector('.tab-badge');
    if ((count | 0) > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'tab-badge';
            tab.appendChild(badge);
        }
        badge.textContent = count > 9 ? '9+' : count;
        badge.style.display = '';
    } else if (badge) {
        badge.style.display = 'none';
    }
}

// Unread office messages (incl. missed calls) on the Chat tab. While the
// chat is open its own GET marks everything read, so the badge stays off.
async function refreshChatBadge() {
    if (currentTab === 'chat') { setTabBadge('chat', 0); return; }
    try {
        const data = await apiGet(API.messages + '/unread-count');
        if (data && typeof data.unread === 'number') setTabBadge('chat', data.unread);
    } catch (e) { /* ignore */ }
}

// ---- System notifications (WhatsApp-style banners) ----
async function requestNotifPermission() {
    // Native shell (Android/iOS APK): register with FCM/APNs via the
    // Capacitor plugin — the WebView has no Web-Push support.
    if (IS_CAPACITOR) return registerNativePush();
    try {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'granted') {
            return subscribeToPush();
        } else if (Notification.permission === 'default') {
            const p = await Notification.requestPermission();
            if (p === 'granted') return subscribeToPush();
        }
    } catch (e) { /* unsupported */ }
}

// ---- Native push (Capacitor: FCM on Android, APNs on iOS) ----
let nativePushRegistration = null;
async function registerNativePush() {
    try {
        const Push = window.Capacitor && Capacitor.Plugins ? Capacitor.Plugins.PushNotifications : null;
        if (!Push) return;

        let perm = await Push.checkPermissions();
        if (perm.receive === 'prompt' || perm.receive === 'prompt-with-rationale') {
            // Ask with our own sheet first — the OS grants the real dialog
            // only ~once per install, so we never burn it on "maybe later".
            if (!await showNotifAskSheet()) return;
            perm = await Push.requestPermissions();
        }
        localStorage.setItem('w_push_permission', perm.receive);
        if (perm.receive === 'denied') { showNotifOffBanner(); return; }
        if (perm.receive !== 'granted') return;
        if (nativePushRegistration) return nativePushRegistration;

        nativePushRegistration = new Promise(resolve => {
            const provider = Capacitor.getPlatform() === 'ios' ? 'apns' : 'fcm';
            let finished = false;
            const finish = () => {
                if (finished) return;
                finished = true;
                resolve();
            };

            (async () => {
                await Push.addListener('registration', async (token) => {
                    if (token && token.value) {
                        localStorage.setItem('w_fcm_token', token.value);
                        try {
                            await fetch(API.push + '/subscribe', {
                                method: 'POST',
                                headers: wAuthHeadersJson(),
                                body: JSON.stringify({ provider: provider, endpoint: token.value })
                            });
                        } catch (e) { /* remote admin can subscribe from the SSO fragment */ }
                    }
                    finish();
                });
                await Push.addListener('registrationError', (err) => {
                    console.warn('Native push registration error:', JSON.stringify(err));
                    finish();
                });

                // Notification received while the app is in the FOREGROUND — the OS
                // shows nothing, so surface it as toast + sound like the web poll.
                await Push.addListener('pushNotificationReceived', (n) => {
                    // Incoming-call invites open the call screen, not a toast.
                    const nd = n && (n.data || {});
                    const payload = (nd.data && typeof nd.data === 'object') ? nd.data : nd;
                    if (payload.type === 'call.invite' && window.WCall) { WCall.checkPending(); return; }
                    showToast((n.title || 'HMS') + (n.body ? ' — ' + n.body : ''), 'success', sigIsPushTitle(n.title) ? sigOpenLatest : null);
                    playNotifSound();
                });

                // Tap on a system notification (app was background/closed).
                await Push.addListener('pushNotificationActionPerformed', (action) => {
                    const nd = action.notification && action.notification.data || {};
                    // Some plugin versions nest the FCM data payload once more.
                    const payload = (nd.data && typeof nd.data === 'object') ? nd.data : nd;
                    if (payload.type === 'call.invite' && window.WCall) { WCall.checkPending(); return; }
                    const tab = payload.tab;
                    // 'call' is not a real tab — an unhandled value would leave
                    // the spinner running forever (unknown tab renders nothing).
                    if (tab && tab !== 'call') switchTab(tab);
                    if (sigIsPushTitle(action.notification && action.notification.title)) sigOpenLatest();
                });

                await Push.register();
                setTimeout(finish, 6000);
            })().catch(err => {
                console.warn('Native push registration failed:', err);
                finish();
            });
        });
        return nativePushRegistration;
    } catch (e) {
        console.warn('Native push setup failed:', e);
    }
}

// ---- Notification permission UX ----
// Own bottom sheet shown BEFORE the OS dialog — the system prompt can only
// appear ~once per install, so we only trigger it when the worker taps
// "Aktivieren". "Später" simply asks again on the next app start.
function showNotifAskSheet() {
    return new Promise(resolve => {
        let overlay = document.getElementById('notifAskModal');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'notifAskModal';
            overlay.innerHTML = `
                <div class="modal-sheet">
                    <div class="modal-handle"></div>
                    <div class="modal-title">Mitteilungen aktivieren</div>
                    <p class="notif-ask-text">Erhalte eine Erinnerung 15 Min. vor Schichtbeginn, neue Nachrichten und Einsatz-Updates direkt aufs Handy.</p>
                    <button class="modal-btn modal-btn-primary" id="notifAskYes"><i class="fas fa-bell"></i> Aktivieren</button>
                    <button class="modal-btn modal-btn-outline" id="notifAskNo">Später</button>
                </div>`;
            document.body.appendChild(overlay);
        }
        const done = (v) => { overlay.classList.remove('active'); resolve(v); };
        overlay.querySelector('#notifAskYes').onclick = () => done(true);
        overlay.querySelector('#notifAskNo').onclick = () => done(false);
        overlay.onclick = (e) => { if (e.target === overlay) done(false); };
        requestAnimationFrame(() => overlay.classList.add('active'));
    });
}

// Once the OS reports "denied" the system dialog can never re-appear — so a
// persistent banner above the tab bar points to the app's settings page on
// every launch. The × only hides it until the next cold start.
let notifBannerDismissed = false;
function showNotifOffBanner() {
    if (notifBannerDismissed || document.getElementById('notifOffBanner')) return;
    const b = document.createElement('div');
    b.id = 'notifOffBanner';
    b.className = 'notif-off-banner';
    b.innerHTML = `
        <i class="fas fa-bell-slash"></i>
        <span>Mitteilungen sind deaktiviert — aktiviere sie in den Einstellungen.</span>
        <button type="button" class="notif-off-settings" id="notifOffSettings">Einstellungen</button>
        <button type="button" class="notif-off-close" id="notifOffClose"><i class="fas fa-times"></i></button>`;
    document.body.appendChild(b);
    b.querySelector('#notifOffSettings').onclick = openNotificationSettings;
    b.querySelector('#notifOffClose').onclick = () => { notifBannerDismissed = true; b.remove(); };
}

async function openNotificationSettings() {
    try {
        if (Capacitor.getPlatform() === 'android') {
            const DV = Capacitor.Plugins && Capacitor.Plugins.DocumentViewer;
            if (DV && DV.openNotificationSettings) { await DV.openNotificationSettings(); return; }
        }
        const App = Capacitor.Plugins && Capacitor.Plugins.App;
        if (App && App.openUrl) await App.openUrl({ url: 'app-settings:' });
    } catch (e) { /* best-effort */ }
}

// Re-check the permission every time the app returns to the foreground:
// granted -> drop the banner and finish FCM/APNs registration; still denied
// -> make sure the banner is visible again (per-launch reminder).
if (IS_CAPACITOR) {
    const CapApp = window.Capacitor && Capacitor.Plugins ? Capacitor.Plugins.App : null;
    if (CapApp && CapApp.addListener) {
        CapApp.addListener('appStateChange', async ({ isActive }) => {
            if (!isActive) return;
            // App resumed — a call invite may have arrived while backgrounded.
            if (window.WCall) WCall.checkPending();
            try {
                const Push = Capacitor.Plugins.PushNotifications;
                if (!Push) return;
                const perm = await Push.checkPermissions();
                if (perm.receive === 'granted') {
                    const b = document.getElementById('notifOffBanner');
                    if (b) b.remove();
                    registerNativePush();
                } else if (perm.receive === 'denied') {
                    showNotifOffBanner();
                }
            } catch (e) { /* best-effort */ }
        });
    }
}

// ---- Real Web-Push (VAPID): notifications even when the app is closed ----
function urlB64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const arr = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
}

async function subscribeToPush() {
    try {
        if (!navigator.serviceWorker || !('PushManager' in window)) return;
        const reg = await navigator.serviceWorker.ready;

        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            const keyResp = await apiGet(API.push + '/vapid-key');
            if (!keyResp || !keyResp.key) return;
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlB64ToUint8Array(keyResp.key)
            });
        }

        // (Re-)register on the backend — idempotent upsert keyed by endpoint.
        const s = sub.toJSON();
        await apiPost(API.push + '/subscribe', { endpoint: s.endpoint, keys: s.keys });
    } catch (e) {
        console.warn('Push subscribe failed:', e);
    }
}

// Route a notification type to the tab that shows its content.
function notifTargetTab(type) {
    if (type.startsWith('shift.'))    return 'plan';
    if (type.startsWith('message.'))  return 'chat';
    if (type.startsWith('call.'))     return 'chat';
    if (type.startsWith('dokument.')) return 'profil';
    if (type.startsWith('absence.'))  return 'frei';
    return 'feed';
}

async function showSystemNotification(title, body, tab) {
    try {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        const opts = {
            body: body || '',
            icon: 'assets/icons/icon-192.png',
            badge: 'assets/icons/icon-192.png',
            tag: 'hms-' + tab + '-' + Date.now(),
            data: { tab: tab }
        };
        // Prefer the service worker (required on Android); fall back to the
        // plain constructor on desktop browsers without an active SW.
        if (navigator.serviceWorker) {
            const reg = await navigator.serviceWorker.getRegistration();
            if (reg) { await reg.showNotification(title, opts); return; }
        }
        const n = new Notification(title, opts);
        n.onclick = () => { window.focus(); switchTab(tab); n.close(); if (sigIsPushTitle(title)) sigOpenLatest(); };
    } catch (e) { /* best-effort */ }
}

function playNotifSound() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.connect(g); g.connect(ctx.destination);
        o.type = 'sine';
        o.frequency.value = 880;
        g.gain.setValueAtTime(0.12, ctx.currentTime);
        g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
        o.start(); o.stop(ctx.currentTime + 0.35);
        setTimeout(() => ctx.close().catch(() => {}), 600);
    } catch (e) { /* autoplay policy — ignore */ }
}

async function notifLoadAndToast() {
    // Surface every unread notification (new shift, office message, document
    // request/decision, absence decision) as toast + system banner + sound —
    // the worker never has to hunt through tabs to learn something happened.
    try {
        const data = await apiGet(NOTIF_API + '?limit=10');
        if (!data || !data.notifications) return;
        const unread = data.notifications.filter(n => n.gelesen == 0);
        if (unread.length === 0) {
            if ((data.unread_count | 0) > 0) { await apiPost(NOTIF_API + '/read-all', {}); refreshFreiBadge(); }
            return;
        }

        let played = false;
        for (const n of unread) {
            const type = n.type || '';
            const tab = notifTargetTab(type);
            const bodyLine = (n.body || '').split('\n')[0];

            // Chat message while the chat is open: the poll there renders it
            // live — no banner needed, just mark read.
            const silent = (type === 'message.neu' && currentTab === 'chat');

            if (!silent) {
                const toastType = type === 'absence.abgelehnt' || type === 'dokument.abgelehnt' ? 'error' : 'success';
                const sigId = type === 'signatur.anfrage' ? (parseInt(notifPayload(n).signatur_id, 10) || 0) : 0;
                showToast(n.title + ' — ' + bodyLine, toastType, sigId ? () => openSignatur(sigId) : null);
                showSystemNotification(n.title, bodyLine, tab);
                if (!played) { playNotifSound(); played = true; }
            }

            // Mark as read so we don't fire it again next poll.
            await apiPost(NOTIF_API + '/' + n.id + '/read', {});

            // New shift: refresh the plan if the worker is looking at it.
            if (type === 'shift.geplant' && currentTab === 'plan') renderPlan();
            // Pause auto-resumed server-side: refresh the Zeit view if open.
            if (type === 'pause.auto_ended' && currentTab === 'zeit') renderZeit();
            // Shift time changed or cancelled in Dispo: refresh Zeit (running
            // "Endet um" / auto-ended timer) and Plan (upcoming times) if the
            // worker is looking at them.
            if (type === 'shift.zeit_geaendert' || type === 'shift.storniert') {
                if (currentTab === 'zeit') renderZeit();
                if (currentTab === 'plan') renderPlan();
            }
            // Document decisions: refresh the profile doc list if visible.
            if (type.startsWith('dokument.') && currentTab === 'profil') loadDocRequests();
            // Signature request: show its banner on the feed right away.
            if (type === 'signatur.anfrage' && currentTab === 'feed') renderFeed();
        }
        // Unread rows older than the 10 just handled (many arrived while the
        // app was closed) were never shown here and never marked — they kept
        // the Frei badge on "9+" forever. They are in the bell list; clear them.
        if ((data.unread_count | 0) > unread.length) await apiPost(NOTIF_API + '/read-all', {});
        refreshFreiBadge();
    } catch (e) { /* ignore */ }
}

function startNotifPolling() {
    refreshFreiBadge();
    notifLoadAndToast();
    refreshNotifCenter();
    refreshChatBadge();
    if (notifPollTimer) clearInterval(notifPollTimer);
    // 30 s keeps banners snappy without hammering the API.
    notifPollTimer = setInterval(() => {
        refreshFreiBadge();
        notifLoadAndToast();
        refreshNotifCenter();
        refreshChatBadge();
    }, 30000);
}

// ---- Notification centre (bell in the blue header) ----
// notifLoadAndToast() marks notifications read on the server right after it
// has shown them (toast dedup), so "new" for the bell is tracked locally:
// every notification above the highest id last opened in the centre counts.
const NOTIF_BELL_SVG = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 22a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4a1.5 1.5 0 0 0-3 0v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>';
let notifCache = [];

function notifSeenKey() { return 'w_notif_seen_' + ((worker && (worker.id || worker.dienst_nr)) || ''); }
function notifSeenId() { return parseInt(localStorage.getItem(notifSeenKey()) || '0', 10) || 0; }

function notifBellHtml() {
    return `<button type="button" class="notif-bell" onclick="openNotifCenter()" aria-label="Benachrichtigungen" title="Benachrichtigungen">${NOTIF_BELL_SVG}<span class="notif-bell-badge" hidden></span></button>`;
}

function updateNotifBell() {
    const seen = notifSeenId();
    const n = notifCache.filter(x => x.id > seen).length;
    document.querySelectorAll('.notif-bell-badge').forEach(b => {
        b.hidden = n === 0;
        b.textContent = n > 9 ? '9+' : String(n);
    });
}

async function refreshNotifCenter() {
    try {
        const data = await apiGet(NOTIF_API + '?limit=50');
        if (data && Array.isArray(data.notifications)) {
            notifCache = data.notifications;
            // First run on this device: start counting from now instead of
            // flagging the whole history as new.
            if (localStorage.getItem(notifSeenKey()) === null && notifCache.length) {
                localStorage.setItem(notifSeenKey(), String(Math.max(...notifCache.map(n => n.id))));
            }
            updateNotifBell();
        }
    } catch (e) { /* ignore */ }
    return notifCache;
}

function notifIcon(type) {
    if (type === 'shift.geplant') return 'fa-calendar-check';
    if (type === 'shift.storniert') return 'fa-calendar-minus';
    if (/^shift\.(reminder|ending_soon|end_reached|auto_closed|zeit_geaendert)/.test(type)) return 'fa-clock';
    if (type.startsWith('shift.')) return 'fa-calendar-alt';
    if (type.startsWith('message.')) return 'fa-comment-dots';
    if (type.startsWith('dokument.')) return 'fa-file-alt';
    if (type.startsWith('signatur.')) return 'fa-pen';
    if (type === 'absence.abgelehnt') return 'fa-times-circle';
    if (type.startsWith('absence.')) return 'fa-umbrella-beach';
    if (type.startsWith('pause.')) return 'fa-pause';
    if (type === 'app.update') return 'fa-download';
    return 'fa-info-circle';
}

// created_at is stored in UTC ("YYYY-MM-DD HH:MM:SS").
function notifTime(ts) {
    if (!ts) return '';
    const d = new Date(String(ts).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(ts) ? '' : 'Z'));
    if (isNaN(d)) return '';
    const min = Math.round((Date.now() - d.getTime()) / 60000);
    if (min < 1) return 'gerade eben';
    if (min < 60) return 'vor ' + min + ' Min.';
    if (min < 360) return 'vor ' + Math.round(min / 60) + ' Std.';
    const hm = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const day = new Date(d); day.setHours(0, 0, 0, 0);
    const diff = Math.round((today - day) / 86400000);
    if (diff === 0) return 'Heute, ' + hm;
    if (diff === 1) return 'Gestern, ' + hm;
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' }) + ', ' + hm;
}

// payload arrives as a JSON string (or already decoded).
function notifPayload(n) {
    try { return (typeof n.payload === 'string' ? JSON.parse(n.payload) : n.payload) || {}; } catch (e) { return {}; }
}

function closeNotifDrawer() {
    const ov = document.getElementById('notifDrawer');
    if (!ov || !ov.classList.contains('active')) return false;
    ov.classList.remove('active');
    return true;
}
window.closeNotifDrawer = closeNotifDrawer;

async function openNotifCenter() {
    let ov = document.getElementById('notifDrawer');
    if (!ov) {
        ov = document.createElement('div');
        ov.className = 'notif-drawer-overlay';
        ov.id = 'notifDrawer';
        ov.innerHTML = `
            <aside class="notif-drawer" role="dialog" aria-label="Benachrichtigungen">
                <div class="notif-drawer-head">
                    <span class="notif-drawer-title">Benachrichtigungen</span>
                    <button type="button" class="notif-drawer-close" aria-label="Schließen"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z"/></svg></button>
                </div>
                <div class="notif-drawer-list" id="notifCenterList"></div>
            </aside>`;
        document.body.appendChild(ov);
        ov.addEventListener('click', (e) => { if (e.target === ov) closeNotifDrawer(); });
        ov.querySelector('.notif-drawer-close').addEventListener('click', closeNotifDrawer);
        // Swipe the drawer back to the right to close it.
        const drawer = ov.querySelector('.notif-drawer');
        let x0 = null, dx = 0;
        drawer.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; dx = 0; }, { passive: true });
        drawer.addEventListener('touchmove', (e) => {
            if (x0 === null) return;
            dx = Math.max(0, e.touches[0].clientX - x0);
            if (dx > 6) { drawer.style.transition = 'none'; drawer.style.transform = 'translateX(' + dx + 'px)'; }
        }, { passive: true });
        drawer.addEventListener('touchend', () => {
            drawer.style.transition = ''; drawer.style.transform = '';
            if (dx > 80) closeNotifDrawer();
            x0 = null; dx = 0;
        });
    }
    const box = ov.querySelector('#notifCenterList');
    box.innerHTML = '<div class="loading" style="padding:24px"><i class="fas fa-spinner fa-spin"></i></div>';
    requestAnimationFrame(() => ov.classList.add('active'));

    const prevSeen = notifSeenId();
    const list = await refreshNotifCenter();
    if (!list.length) {
        box.innerHTML = `<div class="notif-empty">${NOTIF_BELL_SVG}<p>Noch keine Benachrichtigungen</p></div>`;
        return;
    }
    box.innerHTML = list.map(n => {
        const type = n.type || '';
        const icon = type === 'call.missed' ? PHONE_MISSED_SVG : `<i class="fas ${notifIcon(type)}"></i>`;
        return `
            <button type="button" class="notif-item${n.id > prevSeen ? ' is-new' : ''}${type === 'call.missed' ? ' is-missed-call' : ''}" data-tab="${notifTargetTab(type)}" data-sig="${type.startsWith('signatur.') ? (parseInt(notifPayload(n).signatur_id, 10) || '') : ''}">
                <span class="notif-item-icon">${icon}</span>
                <span class="notif-item-body">
                    <span class="notif-item-title">${esc(n.title || 'Mitteilung')}</span>
                    ${n.body ? `<span class="notif-item-text">${esc(n.body)}</span>` : ''}
                    <span class="notif-item-time">${notifTime(n.created_at)}</span>
                </span>
            </button>`;
    }).join('');
    box.querySelectorAll('.notif-item').forEach(el => {
        el.onclick = () => {
            closeNotifDrawer();
            if (el.dataset.sig) openSignatur(el.dataset.sig); else switchTab(el.dataset.tab);
        };
    });
    localStorage.setItem(notifSeenKey(), String(Math.max(...list.map(n => n.id))));
    updateNotifBell();
}


// ============================================
// UNTERSCHRIFT — signature requests from the office
// The office asks the worker to confirm HIS OWN shifts of a document
// (Leistungsnachweis): summary sheet → "Stimmt" → signature pad → send.
// Reached from the notification centre and the banner on the feed.
// ============================================
let sigState = null;   // { id, d } of the request shown in the sheet
let sigPad = null;     // { c, ctx, strokes, cur, len }
const SIG_DAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
const SIG_INK = '#0b1f5c';
const SIG_MIN_LEN = 60;   // px of drawn line before "Senden" unlocks

function sigNum(v) { return (parseFloat(v) || 0).toFixed(2).replace('.', ','); }

async function sigFeedBannersHtml() {
    const d = await apiGet(API.signaturen);
    const list = (d && Array.isArray(d.offen)) ? d.offen : [];
    return list.map(s => `
        <div class="feed-item sig-banner" onclick="openSignatur(${parseInt(s.id, 10) || 0})">
            <div class="feed-item-icon"><i class="fas fa-pen"></i></div>
            <div class="feed-item-content">
                <div class="feed-item-title">Unterschrift erforderlich</div>
                <div class="feed-item-sub">${esc(s.titel || '')}</div>
            </div>
            <div class="feed-item-date"><i class="fas fa-chevron-right"></i></div>
        </div>`).join('');
}

// A tapped push / banner only carries title + tab: a signature push opens
// the newest open request right away (same sheet as from the bell list).
function sigIsPushTitle(title) { return /Unterschrift erforderlich/.test(String(title || '')); }
async function sigOpenLatest() {
    const d = await apiGet(API.signaturen);
    const list = (d && Array.isArray(d.offen)) ? d.offen : [];
    if (list.length) openSignatur(list[0].id);
}

function sigSheet() {
    let ov = document.getElementById('sigSheet');
    if (!ov) {
        ov = document.createElement('div');
        ov.className = 'modal-overlay';
        ov.id = 'sigSheet';
        ov.innerHTML = '<div class="modal-sheet sig-sheet"></div>';
        document.body.appendChild(ov);
        ov.addEventListener('click', (e) => { if (e.target === ov) closeSignaturSheet(); });
    }
    return ov.firstElementChild;
}

function closeSignaturSheet() {
    const ov = document.getElementById('sigSheet');
    if (!ov || !ov.classList.contains('active')) return false;
    ov.classList.remove('active');
    sigPad = null;
    return true;
}
window.closeSignaturSheet = closeSignaturSheet;

function sigHead(title) {
    return `<div class="modal-handle"></div>
        <div class="modal-title-row">
            <div class="modal-title">${esc(title)}</div>
            <button type="button" class="modal-close-btn" onclick="closeSignaturSheet()" aria-label="Schließen">&times;</button>
        </div>`;
}

async function openSignatur(id) {
    id = parseInt(id, 10);
    if (!id) return;
    const sheet = sigSheet();
    sheet.innerHTML = sigHead('Unterschrift') + '<div class="loading" style="padding:32px"><i class="fas fa-spinner fa-spin"></i></div>';
    requestAnimationFrame(() => sheet.parentNode.classList.add('active'));
    const d = await apiGet(API.signaturen + '/' + id);
    if (!d || d.error || !d.daten) {
        sheet.innerHTML = sigHead('Unterschrift')
            + `<p class="notif-ask-text">${esc((d && d.error) || 'Die Anfrage konnte nicht geladen werden. Bitte später erneut versuchen.')}</p>
               <button type="button" class="modal-btn modal-btn-outline" onclick="closeSignaturSheet()">Schließen</button>`;
        return;
    }
    sigState = { id: id, d: d };
    sigRenderDetail();
}
window.openSignatur = openSignatur;

function sigSummary(x) {
    return (x.tage === 1 ? '1 Tag' : (x.tage || 0) + ' Tage') + ' · ' + (x.einsaetze === 1 ? '1 Einsatz' : (x.einsaetze || 0) + ' Einsätze');
}

// Step 1 — what the worker is asked to confirm: only his own shifts.
function sigRenderDetail() {
    if (!sigState) return;
    sigPad = null;
    delete sigSheet().parentNode.dataset.noSwipe;   // summary: swipe down closes like every sheet
    const d = sigState.d, x = d.daten || {};
    // Short dates keep the table inside narrow phones; the year is in "Zeitraum".
    const oneYear = new Set((x.rows || []).map(r => String(r.datum || '').slice(0, 4))).size <= 1;
    const rows = (x.rows || []).map(r => {
        const dt = r.datum ? new Date(r.datum + 'T00:00:00') : null;
        const p = String(r.datum || '').split('-');
        const day = p.length === 3 ? p[2] + '.' + p[1] + '.' + (oneYear ? '' : p[0].slice(2)) : fmtDate(r.datum);
        return `<tr>
            <td>${dt && !isNaN(dt) ? SIG_DAYS[dt.getDay()] + ' ' : ''}${esc(day)}</td>
            <td>${esc(fmtTime(r.von))}–${esc(fmtTime(r.bis))}</td>
            <td>${r.pause > 0 ? parseInt(r.pause, 10) + ' Min' : '–'}</td>
            <td class="sig-num">${sigNum(r.std)}</td>
        </tr>`;
    }).join('');
    const meta = [['Objekt', x.objekt], ['Zeitraum', x.zeitraum]]
        .filter(m => m[1]).map(m => `<div><span>${m[0]}</span><b>${esc(m[1])}</b></div>`).join('');
    let actions;
    if (d.status === 'offen') {
        actions = `<p class="sig-legal">Mit deiner Unterschrift bestätigst du, dass diese Angaben stimmen.</p>
            <button type="button" class="modal-btn modal-btn-primary" onclick="sigShowPad()"><i class="fas fa-pen"></i> Stimmt – unterschreiben</button>
            <button type="button" class="modal-btn modal-btn-outline" onclick="sigShowReject()">Stimmt nicht</button>`;
    } else {
        const txt = d.status === 'signiert' ? 'Du hast diese Angaben bereits unterschrieben.'
            : d.status === 'abgelehnt' ? 'Du hast gemeldet, dass diese Angaben nicht stimmen. Das Büro meldet sich bei dir.'
            : 'Diese Anfrage ist nicht mehr aktuell.';
        actions = `<div class="sig-state${d.status === 'signiert' ? ' is-ok' : ''}"><i class="fas ${d.status === 'signiert' ? 'fa-check-circle' : 'fa-info-circle'}"></i> ${txt}</div>
            <button type="button" class="modal-btn modal-btn-outline" onclick="closeSignaturSheet()">Schließen</button>`;
    }
    sigSheet().innerHTML = sigHead('Bitte bestätigen') + `
        <p class="notif-ask-text">${esc(x.firma || 'Das Büro')} bittet dich, deine Einsätze für den ${esc(x.dokument || 'Nachweis')} zu bestätigen.</p>
        <div class="sig-meta">${meta}</div>
        <table class="sig-table">
            <thead><tr><th>Datum</th><th>Zeit</th><th>Pause</th><th class="sig-num">Std</th></tr></thead>
            <tbody>${rows}</tbody>
            <tfoot><tr><td colspan="2">${sigSummary(x)}</td><td>${x.pause > 0 ? parseInt(x.pause, 10) + ' Min' : '–'}</td><td class="sig-num">${sigNum(x.std)}</td></tr></tfoot>
        </table>
        ${actions}`;
    sigSheet().scrollTop = 0;
}

// Step 2 — signature pad.
function sigShowPad() {
    if (!sigState) return;
    sigSheet().parentNode.dataset.noSwipe = '1';   // dragging belongs to the signature pad
    const x = sigState.d.daten || {};
    sigSheet().innerHTML = sigHead('Unterschreiben') + `
        <p class="notif-ask-text">${esc(sigSummary(x))} · ${sigNum(x.std)} Std${x.objekt ? ' · ' + esc(x.objekt) : ''}</p>
        <div class="sig-pad-wrap"><canvas id="sigPadCanvas"></canvas><span class="sig-pad-hint" id="sigPadHint">Hier mit dem Finger unterschreiben</span></div>
        <div class="extend-sheet-actions">
            <button type="button" class="modal-btn modal-btn-outline" onclick="sigPadClear()"><i class="fas fa-eraser"></i> Löschen</button>
            <button type="button" class="modal-btn modal-btn-primary" id="sigSendBtn" disabled onclick="sigSubmit()"><i class="fas fa-check"></i> Senden</button>
        </div>
        <button type="button" class="modal-btn modal-btn-outline" onclick="sigRenderDetail()">Zurück</button>`;
    sigSheet().scrollTop = 0;
    const c = document.getElementById('sigPadCanvas');
    const box = c.getBoundingClientRect();
    const dpr = Math.min(3, window.devicePixelRatio || 1);
    c.width = Math.round(box.width * dpr);
    c.height = Math.round(box.height * dpr);
    const ctx = c.getContext('2d');
    ctx.scale(dpr, dpr);
    sigPad = { c: c, ctx: ctx, strokes: [], cur: null, len: 0 };
    const pt = (e) => { const b = c.getBoundingClientRect(); return { x: e.clientX - b.left, y: e.clientY - b.top }; };
    c.onpointerdown = (e) => {
        e.preventDefault();
        try { c.setPointerCapture(e.pointerId); } catch (err) { /* old WebView */ }
        sigPad.cur = [pt(e)];
        sigPad.strokes.push(sigPad.cur);
        sigPadPaint();
    };
    c.onpointermove = (e) => {
        if (!sigPad || !sigPad.cur) return;
        e.preventDefault();
        const p = pt(e), q = sigPad.cur[sigPad.cur.length - 1];
        const step = Math.hypot(p.x - q.x, p.y - q.y);
        if (step < 1) return;
        sigPad.len += step;
        sigPad.cur.push(p);
        sigPadPaint();
    };
    c.onpointerup = c.onpointercancel = () => { if (sigPad) sigPad.cur = null; };
}

// Smooth strokes (quadratic through the midpoints); k/dx/dy map pad
// coordinates onto another canvas (export).
function sigDrawStrokes(ctx, strokes, width, dx, dy, k) {
    ctx.lineWidth = width;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = SIG_INK;
    ctx.fillStyle = SIG_INK;
    const X = (p) => (p.x - dx) * k, Y = (p) => (p.y - dy) * k;
    strokes.forEach(s => {
        ctx.beginPath();
        if (s.length === 1) { ctx.arc(X(s[0]), Y(s[0]), width / 2, 0, Math.PI * 2); ctx.fill(); return; }
        ctx.moveTo(X(s[0]), Y(s[0]));
        for (let i = 1; i < s.length - 1; i++) {
            ctx.quadraticCurveTo(X(s[i]), Y(s[i]), (X(s[i]) + X(s[i + 1])) / 2, (Y(s[i]) + Y(s[i + 1])) / 2);
        }
        ctx.lineTo(X(s[s.length - 1]), Y(s[s.length - 1]));
        ctx.stroke();
    });
}

function sigPadPaint() {
    if (!sigPad) return;
    const b = sigPad.c.getBoundingClientRect();
    sigPad.ctx.clearRect(0, 0, b.width, b.height);
    sigDrawStrokes(sigPad.ctx, sigPad.strokes, 2.6, 0, 0, 1);
    const hint = document.getElementById('sigPadHint');
    if (hint) hint.style.display = sigPad.strokes.length ? 'none' : '';
    const btn = document.getElementById('sigSendBtn');
    if (btn) btn.disabled = sigPad.len < SIG_MIN_LEN;
}

function sigPadClear() {
    if (!sigPad) return;
    sigPad.strokes = [];
    sigPad.cur = null;
    sigPad.len = 0;
    sigPadPaint();
}

// Transparent PNG cropped to the ink (max 600×240) — fills the small
// signature cell of the document instead of floating in white space.
function sigPadPng() {
    const pts = sigPad.strokes.reduce((a, s) => a.concat(s), []);
    const xs = pts.map(p => p.x), ys = pts.map(p => p.y), pad = 8;
    const x0 = Math.min.apply(null, xs) - pad, y0 = Math.min.apply(null, ys) - pad;
    const w = Math.max.apply(null, xs) + pad - x0, h = Math.max.apply(null, ys) + pad - y0;
    const k = Math.min(600 / w, 240 / h, 3);
    const out = document.createElement('canvas');
    out.width = Math.max(60, Math.round(w * k));
    out.height = Math.max(30, Math.round(h * k));
    sigDrawStrokes(out.getContext('2d'), sigPad.strokes, 3 * k, x0, y0, k);
    return out.toDataURL('image/png');
}

async function sigSubmit() {
    if (!sigState || !sigPad || sigPad.len < SIG_MIN_LEN) return;
    const btn = document.getElementById('sigSendBtn');
    btn.disabled = true;
    const r = await apiPost(API.signaturen + '/' + sigState.id + '/unterschreiben', { signatur: sigPadPng() });
    if (r && r.success) {
        closeSignaturSheet();
        showToast('Danke – deine Unterschrift wurde gesendet');
        if (currentTab === 'feed') renderFeed();
        return;
    }
    showToast((r && (r.error || r.message)) || 'Senden fehlgeschlagen – bitte erneut versuchen', 'error');
    if (document.getElementById('sigSendBtn')) btn.disabled = false;
}

// "Stimmt nicht" — the worker tells the office what is wrong instead of signing.
function sigShowReject() {
    if (!sigState) return;
    sigPad = null;
    delete sigSheet().parentNode.dataset.noSwipe;
    sigSheet().innerHTML = sigHead('Was stimmt nicht?') + `
        <p class="notif-ask-text">Schreib kurz, welche Angabe falsch ist (z.B. Tag, Uhrzeit, Pause). Das Büro prüft es und meldet sich.</p>
        <textarea class="modal-input extend-reason" id="sigRejectText" rows="3" maxlength="500" placeholder="z.B. Am 25.09. habe ich bis 18:00 gearbeitet"
            oninput="document.getElementById('sigRejectBtn').disabled = this.value.trim().length < 3"></textarea>
        <button type="button" class="modal-btn modal-btn-primary" id="sigRejectBtn" disabled onclick="sigSubmitReject()">An das Büro senden</button>
        <button type="button" class="modal-btn modal-btn-outline" onclick="sigRenderDetail()">Zurück</button>`;
    sigSheet().scrollTop = 0;
}

async function sigSubmitReject() {
    const el = document.getElementById('sigRejectText');
    const btn = document.getElementById('sigRejectBtn');
    if (!sigState || !el || el.value.trim().length < 3) return;
    btn.disabled = true;
    const r = await apiPost(API.signaturen + '/' + sigState.id + '/ablehnen', { grund: el.value.trim() });
    if (r && r.success) {
        closeSignaturSheet();
        showToast('Danke – das Büro wurde informiert');
        if (currentTab === 'feed') renderFeed();
        return;
    }
    showToast((r && (r.error || r.message)) || 'Senden fehlgeschlagen – bitte erneut versuchen', 'error');
    btn.disabled = false;
}


// Notification polling is started from wBootstrap once the session is loaded.

// ---- Keyboard avoidance for bottom sheets (iOS) ----
// When the on-screen keyboard opens, the visual viewport shrinks but
// position:fixed sheets stay pinned to the LAYOUT viewport bottom — hidden
// behind the keyboard. Lift the active sheet by the keyboard height.
(function () {
    if (!window.visualViewport) return;
    const vv = window.visualViewport;
    function adjustSheets() {
        const kb = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
        document.querySelectorAll('.modal-overlay.active .modal-sheet').forEach(sheet => {
            sheet.style.marginBottom = kb > 0 ? kb + 'px' : '';
        });
    }
    // Keep the focused field visible inside the (now shorter) sheet.
    function revealFocused() {
        const el = document.activeElement;
        if (el && el.closest && el.closest('.modal-overlay.active .modal-sheet')) {
            try { el.scrollIntoView({ block: 'nearest' }); } catch (e) { /* old WebView */ }
        }
    }
    vv.addEventListener('resize', adjustSheets);
    vv.addEventListener('scroll', adjustSheets);
    // Inputs inside a sheet: re-check shortly after focus (keyboard animates in).
    document.addEventListener('focusin', e => {
        if (e.target.closest && e.target.closest('.modal-sheet')) {
            setTimeout(() => { adjustSheets(); revealFocused(); }, 350);
        }
    });
    // Native keyboard state (Android MainActivity / iOS Keyboard plugin)
    // arrives as body.keyboard-open — the sheet CSS lifts on it; once the
    // lift transition has run, scroll the focused field into view.
    if (window.MutationObserver && document.body) {
        new MutationObserver(() => setTimeout(revealFocused, 300))
            .observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }
})();

// ---- Bottom sheets: swipe down to dismiss (drag affordance) ----
// Works for EVERY .modal-overlay/.modal-sheet (delegated): the sheet follows
// the finger while dragging down and closes past the threshold, otherwise it
// springs back. The drag only starts when the sheet content is scrolled to
// the top, so normal scrolling inside the sheet keeps working.
(function () {
    let sheet = null, overlay = null, startY = 0, dy = 0, dragging = false;

    document.addEventListener('touchstart', (e) => {
        const s = e.target.closest && e.target.closest('.modal-overlay.active .modal-sheet');
        if (!s || s.scrollTop > 0 || s.closest('[data-no-swipe]')) return;
        sheet = s;
        overlay = s.closest('.modal-overlay');
        startY = e.touches[0].clientY;
        dy = 0;
        dragging = false;
    }, { passive: true });

    document.addEventListener('touchmove', (e) => {
        if (!sheet) return;
        dy = e.touches[0].clientY - startY;
        if (!dragging && dy > 8) dragging = true;
        if (dragging && dy > 0) {
            sheet.style.transition = 'none';
            sheet.style.transform = 'translateY(' + dy + 'px)';
        }
    }, { passive: true });

    document.addEventListener('touchend', () => {
        if (!sheet) return;
        const s = sheet, o = overlay, shouldClose = dragging && dy > 90;
        s.style.transition = '';
        s.style.transform = '';
        if (shouldClose && o) o.classList.remove('active');
        sheet = overlay = null;
        dragging = false;
        dy = 0;
    });
})();
