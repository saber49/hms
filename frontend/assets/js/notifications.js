/* ============================================
   Notifications — in-app + sidebar badge
   ============================================

   Surfaces tenant-scoped notifications (e.g. worker Wunschfrei
   requests) in two places:

   1. A small red counter on the "Mitarbeiter" sidebar nav-item,
      filtered to worker.* notifications, so a manager sees
      "something needs my attention" at a glance.
   2. A bell icon + dropdown in the topbar that lists every
      notification (newest first), with click-to-mark-read and a
      "Alle als gelesen markieren" action.

   Polling cadence: 30 s, plus an immediate refresh whenever the tab
   regains focus/visibility (throttled to 5 s). Cheap (one row count +
   one short list). Failures are swallowed — never block the rest of the app.
*/
(function () {
    const NOTIF_API = window.location.origin + '/api/notifications';
    const POLL_MS = 30_000;
    let pollTimer = null;
    let lastList = [];
    let lastFocusRefresh = 0;

    function authHeaders() {
        const t = localStorage.getItem('hms_token');
        const h = { 'Accept': 'application/json' };
        if (t) h['Authorization'] = 'Bearer ' + t;
        return h;
    }

    async function getJson(url) {
        try {
            const r = await fetch(url, { headers: authHeaders() });
            if (!r.ok) return null;
            return await r.json();
        } catch (e) { return null; }
    }
    async function postJson(url) {
        try {
            const r = await fetch(url, { method: 'POST', headers: authHeaders() });
            if (!r.ok) return null;
            return await r.json();
        } catch (e) { return null; }
    }
    async function postJsonBody(url, body) {
        try {
            const h = authHeaders();
            h['Content-Type'] = 'application/json';
            const r = await fetch(url, { method: 'POST', headers: h, body: JSON.stringify(body) });
            if (!r.ok) return null;
            return await r.json();
        } catch (e) { return null; }
    }

    function esc(s) {
        return (s == null ? '' : String(s))
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtRelative(iso) {
        if (!iso) return '';
        // Backend stores created_at in UTC (config/app.php timezone = UTC) as a
        // naive MySQL DATETIME with no offset. Treat it as UTC and let the
        // browser convert to the viewer's local (German) time — otherwise a
        // fresh notification would read e.g. "2 Std" instead of "gerade eben".
        let s = iso.replace(' ', 'T');
        if (!/[zZ]|[+\-]\d{2}:?\d{2}$/.test(s)) s += 'Z';
        const d = new Date(s);
        if (isNaN(d.getTime())) return esc(iso);
        const diff = (Date.now() - d.getTime()) / 1000;
        if (diff < 60) return 'gerade eben';
        if (diff < 3600) return Math.floor(diff / 60) + ' Min';
        if (diff < 86400) return Math.floor(diff / 3600) + ' Std';
        if (diff < 86400 * 7) return Math.floor(diff / 86400) + ' Tg';
        return d.toLocaleDateString('de-DE');
    }

    /* -------- Sidebar badge on Mitarbeiter nav-item -------- */
    async function refreshBadges() {
        const data = await getJson(NOTIF_API + '/unread-count');
        if (!data) return;

        const workersBadge = document.getElementById('navBadgeWorkers');
        if (workersBadge) {
            if ((data.workers | 0) > 0) {
                workersBadge.textContent = data.workers > 99 ? '99+' : data.workers;
                workersBadge.style.display = '';
            } else {
                workersBadge.style.display = 'none';
            }
        }
        // "Anträge & Urlaub" badge — live count of pending absence requests.
        // Reuses the same 30s poll; failure is silently ignored (e.g. user
        // without Mitarbeiter permission gets a 403).
        getJson(window.location.origin + '/api/worker-absences/pending-counts').then(pc => {
            const b = document.getElementById('badge-absences');
            if (!b || !pc || !pc.pending) return;
            const total = Object.values(pc.pending).reduce((s, c) => s + (c | 0), 0);
            if (total > 0) {
                b.textContent = total > 99 ? '99+' : total;
                b.style.display = '';
            } else {
                b.style.display = 'none';
            }
        }).catch(() => {});

        // "Aufgaben" badge — open tasks assigned to the current user.
        getJson(window.location.origin + '/api/aufgaben/counts').then(ac => {
            const b = document.getElementById('badge-aufgaben');
            if (!b || !ac) return;
            b.textContent = (ac.offen | 0) > 99 ? '99+' : (ac.offen | 0);
            b.style.display = (ac.offen | 0) > 0 ? '' : 'none';
        }).catch(() => {});

        const bellBadge = document.getElementById('notifBellBadge');
        if (bellBadge) {
            if ((data.total | 0) > 0) {
                bellBadge.textContent = data.total > 99 ? '99+' : data.total;
                bellBadge.classList.add('visible');
            } else {
                bellBadge.classList.remove('visible');
            }
        }

        // Desktop OS toasts — piggybacks on this 30 s poll (Electron only;
        // IS_DESKTOP short-circuits it everywhere else).
        desktopToastCheck();
    }

    /* ---- Desktop (Electron) OS notifications ----
       The desktop app keeps its window alive in the tray, so this 30 s
       poll keeps running while the window is hidden. Every NEW unread
       row fires a real OS notification (HTML5 Notification → Windows
       toast / macOS banner) — no push service needed, since every
       desktop push transport requires the app to be running anyway.
       Gated by the 'push' pref channel (device-level alert), evaluated
       here with the same master-switch + longest-prefix rules as
       CentralUser::wantsNotification(). The bell table stays the
       source of truth — rows only exist when 'app' is enabled. */
    const IS_DESKTOP = !!(window.desktop && window.desktop.isDesktop);
    let notifPrefsCache = null;
    let notifPrefsAt = 0;
    let desktopSeenInit = false;

    function desktopWants(type) {
        const p = notifPrefsCache;
        if (!p) return true;
        if (p.push === false) return false;
        const types = p.types || {};
        let t = type;
        while (t) {
            const row = types[t];
            if (row && typeof row === 'object'
                && Object.prototype.hasOwnProperty.call(row, 'push')) {
                return !!row.push;
            }
            const i = t.lastIndexOf('.');
            if (i < 0) break;
            t = t.slice(0, i);
        }
        return true;
    }

    async function loadNotifPrefs() {
        const data = await getJson(NOTIF_API + '/preferences');
        if (data && data.prefs) {
            notifPrefsCache = data.prefs;
            notifPrefsAt = Date.now();
        }
    }

    async function desktopToastCheck() {
        if (!IS_DESKTOP) return;
        try {
            if (!notifPrefsCache || Date.now() - notifPrefsAt > 10 * 60 * 1000) {
                await loadNotifPrefs();
            }

            const data = await getJson(NOTIF_API + '?unread_only=1&limit=15');
            const rows = (data && data.notifications) || [];

            // Baseline: the first poll after app start only records what
            // already existed — no toast storm for the unread backlog.
            let seen = parseInt(localStorage.getItem('hms_desktop_seen') || '0', 10);
            const maxId = rows.reduce((m, r) => Math.max(m, r.id | 0), 0);
            if (!desktopSeenInit) {
                desktopSeenInit = true;
                if (!seen) {
                    localStorage.setItem('hms_desktop_seen', String(maxId));
                    return;
                }
            }
            const fresh = rows
                .filter(r => (r.id | 0) > seen && desktopWants(r.type || ''))
                .sort((a, b) => (a.id | 0) - (b.id | 0));
            for (const n of fresh) {
                try {
                    const notif = new Notification(n.title || 'Clood-X', {
                        body: (n.body || '').split('\n')[0],
                        tag: 'hms-n' + n.id,
                        silent: false,
                    });
                    notif.onclick = () => {
                        try { if (window.desktop && window.desktop.focus) window.desktop.focus(); } catch (e) {}
                        const link = n.link || '';
                        if (link.startsWith('#')) window.location.hash = link;
                        postJson(NOTIF_API + '/' + n.id + '/read');
                        refreshBadges();
                    };
                } catch (e) { /* notification unsupported — skip */ }
            }
            if (maxId > 0) {
                localStorage.setItem('hms_desktop_seen', String(Math.max(seen, maxId)));
            }
        } catch (e) { /* desktop toast check must never break polling */ }
    }

    /* -------- Bell dropdown -------- */
    async function loadList() {
        const data = await getJson(NOTIF_API + '?limit=30');
        const body = document.getElementById('notifDropdownBody');
        if (!body) return;

        const rows = (data && data.notifications) || [];
        lastList = rows;
        if (rows.length === 0) {
            body.innerHTML = '<div class="notif-empty">Keine Benachrichtigungen</div>';
            return;
        }
        body.innerHTML = rows.map(n => `
            <div class="notif-item ${n.gelesen == 0 ? 'notif-unread' : ''}" data-id="${n.id}" data-link="${esc(n.link || '')}">
                <div class="notif-item-title">${esc(n.title)}</div>
                <div class="notif-item-body">${esc((n.body || '').split('\n')[0])}</div>
                <div class="notif-item-meta">${fmtRelative(n.created_at)}</div>
            </div>
        `).join('');

        body.querySelectorAll('.notif-item').forEach(el => {
            el.addEventListener('click', async () => {
                const id = el.dataset.id;
                const link = el.dataset.link;
                el.classList.remove('notif-unread');
                await postJson(NOTIF_API + '/' + id + '/read');
                refreshBadges();
                if (link && link.startsWith('#')) {
                    closeDropdown();
                    // Strip query params for hash navigation (loadPage doesn't parse them)
                    const [target, query] = link.replace(/^#/, '').split('?');
                    // '#chat?conv=<id>' is a pseudo-route: open the side panel
                    // on that conversation (the chat panel is not a page).
                    if (target === 'chat') {
                        const convId = query ? new URLSearchParams(query).get('conv') : null;
                        if (typeof toggleChatPanel === 'function' && !chatOpen) toggleChatPanel();
                        if (convId && typeof openConversation === 'function') {
                            setTimeout(() => openConversation(convId), 200);
                        }
                        return;
                    }
                    // Stash the full query under a per-page key so the
                    // destination page can auto-open the exact record this
                    // notification came from (request card, worker profile,
                    // Einsatz detail…). 'disposition' is an alias of 'tasks'.
                    if (query) {
                        const focusKey = target === 'disposition' ? 'tasks' : target;
                        try { sessionStorage.setItem('hms_focus_' + focusKey, query); } catch (e) { /* ignore */ }
                    }
                    // Legacy key still consumed by incoming_orders.js.
                    if (target === 'incoming_orders' && query) {
                        const m = /(?:^|&)id=([^&]+)/.exec(query);
                        if (m) {
                            try { sessionStorage.setItem('be_focus_order', decodeURIComponent(m[1])); } catch (e) { /* ignore */ }
                        }
                    }
                    if (typeof window.loadPage === 'function') {
                        window.loadPage(target);
                    } else {
                        window.location.hash = target;
                    }
                }
            });
        });
    }

    function openDropdown() {
        const dd = document.getElementById('notifDropdown');
        if (!dd) return;
        dd.classList.add('open');
        loadList();
        document.addEventListener('click', outsideClick, true);
    }
    function closeDropdown() {
        const dd = document.getElementById('notifDropdown');
        if (dd) dd.classList.remove('open');
        document.removeEventListener('click', outsideClick, true);
    }
    function outsideClick(e) {
        const dd = document.getElementById('notifDropdown');
        const btn = document.getElementById('notifBellBtn');
        if (!dd) return;
        if (dd.contains(e.target) || (btn && btn.contains(e.target))) return;
        closeDropdown();
    }

    async function markAllRead() {
        await postJson(NOTIF_API + '/read-all');
        refreshBadges();
        loadList();
    }

    /**
     * Public helper invoked by app.js when the Mitarbeiter page is opened.
     * Marks only `worker.*` notifications as read so the Mitarbeiter
     * sidebar badge clears immediately, while other (future) notification
     * groups stay visible in the bell dropdown.
     */
    async function markWorkersRead() {
        await postJson(NOTIF_API + '/read-all?group=workers');
        refreshBadges();
    }

    function injectDom() {
        // Bell button in topbar — placed just before the existing chat icon
        // so its position is consistent with the rest of the topbar icons.
        const topbarRight = document.querySelector('.topbar-right');
        const chatBtn = document.getElementById('chatToggleBtn');
        if (topbarRight && !document.getElementById('notifBellBtn')) {
            const btn = document.createElement('button');
            btn.className = 'topbar-icon-btn';
            btn.id = 'notifBellBtn';
            btn.title = 'Benachrichtigungen';
            btn.innerHTML = '<i class="fas fa-bell"></i><span class="chat-badge" id="notifBellBadge"></span>';
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const dd = document.getElementById('notifDropdown');
                if (dd && dd.classList.contains('open')) closeDropdown();
                else openDropdown();
            });
            if (chatBtn) topbarRight.insertBefore(btn, chatBtn);
            else topbarRight.appendChild(btn);
        }

        // Dropdown (attached to body so it can overlay everything cleanly).
        if (!document.getElementById('notifDropdown')) {
            const dd = document.createElement('div');
            dd.id = 'notifDropdown';
            dd.className = 'notif-dropdown';
            dd.innerHTML = `
                <div class="notif-dropdown-head">
                    <span><i class="fas fa-bell"></i> Benachrichtigungen</span>
                    <button class="notif-mark-all" id="notifMarkAllBtn">Alle gelesen</button>
                </div>
                <div class="notif-dropdown-body" id="notifDropdownBody">
                    <div class="notif-empty">Laden…</div>
                </div>
            `;
            document.body.appendChild(dd);
            document.getElementById('notifMarkAllBtn').addEventListener('click', markAllRead);
        }

        // Badge inside Mitarbeiter nav-item
        const navWorkers = document.querySelector('.nav-item[data-page="workers"]');
        if (navWorkers && !document.getElementById('navBadgeWorkers')) {
            const span = document.createElement('span');
            span.id = 'navBadgeWorkers';
            span.className = 'nav-badge';
            span.style.display = 'none';
            navWorkers.appendChild(span);
        }
    }

    // Refresh immediately when the user returns to the tab/window so a new
    // notification shows without waiting for the next poll — throttled to
    // avoid hammering the API on rapid focus changes.
    function refreshOnReturn() {
        if (document.visibilityState === 'hidden') return;
        const now = Date.now();
        if (now - lastFocusRefresh < 5_000) return;
        lastFocusRefresh = now;
        refreshBadges();
        // If the dropdown is open, refresh its list too.
        const dd = document.getElementById('notifDropdown');
        if (dd && dd.classList.contains('open')) loadList();
    }

    /* ---- Native device push for admins in the worker app ----
       When the dashboard runs inside the Capacitor WebView (admin login
       path of the worker app), the PushNotifications plugin is injected
       on this remote page too — so the device token can be registered
       here without rebuilding the app shell. The token lands in
       central_user_push_tokens via /api/notifications/push/subscribe and
       NotificationDispatcher then pushes per the 'push' pref channel.
       On desktop/browser Capacitor is absent → the whole block no-ops. */
    let adminPushStarted = false;
    async function initAdminPush() {
        if (adminPushStarted) return;
        try {
            const Cap = window.Capacitor;
            const Push = Cap && Cap.Plugins ? Cap.Plugins.PushNotifications : null;
            if (!Push || typeof Cap.getPlatform !== 'function' || Cap.getPlatform() === 'web') return;
            adminPushStarted = true;

            let perm = await Push.checkPermissions();
            if (perm.receive === 'prompt' || perm.receive === 'prompt-with-rationale') {
                perm = await Push.requestPermissions();
            }
            if (perm.receive !== 'granted') return;

            const provider = Cap.getPlatform() === 'ios' ? 'apns' : 'fcm';
            await Push.addListener('registration', async (t) => {
                if (!t || !t.value) return;
                // Skip the POST when the token is unchanged since last boot.
                if (localStorage.getItem('hms_push_token') === t.value) return;
                const res = await postJsonBody(NOTIF_API + '/push/subscribe',
                    { provider: provider, endpoint: t.value });
                if (res && res.success) localStorage.setItem('hms_push_token', t.value);
            });
            await Push.addListener('registrationError', () => {});

            // Foreground: the OS shows no banner — toast + refresh bell.
            await Push.addListener('pushNotificationReceived', (n) => {
                try {
                    if (typeof window.showToast === 'function') {
                        window.showToast((n.title || '') + (n.body ? ' — ' + n.body : ''), 'info');
                    }
                } catch (e) { /* toast optional */ }
                refreshBadges();
            });

            // Tap on a system notification → open the linked view.
            await Push.addListener('pushNotificationActionPerformed', (action) => {
                const link = action && action.notification && action.notification.data
                    && action.notification.data.tab;
                if (link && typeof link === 'string' && link.startsWith('#')) {
                    window.location.hash = link;
                }
            });

            await Push.register();
        } catch (e) { /* no plugin / no permission — push stays off */ }
    }

    function init() {
        if (!localStorage.getItem('hms_token')) return;
        injectDom();
        refreshBadges();
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(refreshBadges, POLL_MS);
        document.addEventListener('visibilitychange', refreshOnReturn);
        window.addEventListener('focus', refreshOnReturn);
        initAdminPush();
    }

    /* -------- Realtime (WebSocket) entry point --------
       Called by realtime.js when 'notification.created' arrives on the
       user's private channel. Mirrors what a poll cycle would do —
       badge + open dropdown + desktop toast — but instantly, and with
       the payload already in hand (no extra fetch needed for toast). */
    async function onRealtimeNotification(n) {
        refreshBadges();
        const dd = document.getElementById('notifDropdown');
        if (dd && dd.classList.contains('open')) loadList();
        try {
            if (typeof window.showToast === 'function') {
                window.showToast(n.title || 'Benachrichtigung', 'info');
            }
        } catch (e) { /* toast optional */ }

        if (IS_DESKTOP) {
            if (!notifPrefsCache) await loadNotifPrefs();
            if (!desktopWants(n.type || '')) return;
            // Keep the dedupe baseline ahead of this row so the poll
            // never re-toasts what the socket already delivered.
            const seen = parseInt(localStorage.getItem('hms_desktop_seen') || '0', 10);
            if ((n.id | 0) > seen) localStorage.setItem('hms_desktop_seen', String(n.id | 0));
            try {
                const notif = new Notification(n.title || 'Clood-X', {
                    body: (n.body || '').split('\n')[0],
                    tag: 'hms-n' + n.id,
                });
                notif.onclick = () => {
                    try { if (window.desktop && window.desktop.focus) window.desktop.focus(); } catch (e) {}
                    if ((n.link || '').startsWith('#')) window.location.hash = n.link;
                    if (n.id) postJson(NOTIF_API + '/' + n.id + '/read');
                    refreshBadges();
                };
            } catch (e) { /* OS notification unsupported — bell already updated */ }
        }
    }

    // Any tenant data change (absence filed, order deleted, …) may also
    // move the badge counters — refresh immediately instead of waiting
    // for the next 30 s poll. Debounced by HMSRealtime.
    if (window.HMSRealtime) {
        HMSRealtime.on('*', () => { try { refreshBadges(); } catch (e) {} });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.HMSNotifications = {
        refresh: refreshBadges,
        openDropdown, closeDropdown, markWorkersRead,
        pushReceived: onRealtimeNotification,
    };
})();
