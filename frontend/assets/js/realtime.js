/* ============================================
   Realtime — Reverb WebSocket client
   ============================================

   Connects the admin panel to the self-hosted Reverb server
   (nginx proxies wss://<host>/app/* → php artisan reverb:start).
   Two private channels per session:

     private-user.{centralUserId}   → 'notification.created'  (bell, toast,
                                      dropdown, desktop OS notification)
     private-tenant.{tenantId}      → 'data.changed' {area,hint} — fired by
                                      controllers after mutations; modules
                                      register per-area refresh callbacks
                                      via HMSRealtime.on(area, fn).

   Design rules:
   - Best-effort layer ON TOP of polling — if the socket never connects
     (offline, CDN unreachable, Reverb down) every existing poll keeps
     working unchanged.
   - Per-area debounce collapses write bursts (bulk planning etc.) into
     a single refresh.
   - After a reconnect all registered areas refresh once — events that
     arrived while the socket was down are replayed by refetching.
*/
(function () {
    const listeners = {};            // area → [fn]
    const debounceTimers = {};       // area → timeout id
    const state = { echo: null, ready: false, wasDown: false };

    function fire(area, hint) {
        clearTimeout(debounceTimers[area]);
        debounceTimers[area] = setTimeout(() => {
            (listeners[area] || []).concat(listeners['*'] || []).forEach(fn => {
                try { fn(hint || {}); } catch (e) { /* a listener must not break others */ }
            });
            try {
                document.dispatchEvent(new CustomEvent('hms:data-changed', { detail: { area: area, hint: hint || {} } }));
            } catch (e) { /* CustomEvent unsupported — ignore */ }
        }, 700);
    }

    window.HMSRealtime = {
        on: function (area, fn) {
            (listeners[area] = listeners[area] || []).push(fn);
        },
        fire: fire,
        connected: function () { return !!state.ready; },
        // Direct private-channel access for point-to-point features (calls).
        // Echo dedupes by channel name, so channel('user.X') returns the same
        // underlying subscription realtime.js already opened for the bell.
        channel: function (name) {
            try { return state.echo ? state.echo.private(name) : null; }
            catch (e) { return null; }
        },
    };

    function onNotification(n) {
        try {
            if (window.HMSNotifications && typeof HMSNotifications.pushReceived === 'function') {
                HMSNotifications.pushReceived(n);
            }
        } catch (e) { /* notification surfacing is best-effort */ }
    }

    async function boot() {
        const token = localStorage.getItem('hms_token');
        if (!token || typeof Echo === 'undefined' || typeof Pusher === 'undefined') return;

        let conf = null;
        try {
            const r = await fetch('/api/realtime/config', {
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
            });
            if (r.ok) conf = await r.json();
        } catch (e) { return; }
        if (!conf || !conf.enabled || !conf.key) return;

        let user = {}, tenant = {};
        try {
            user = JSON.parse(localStorage.getItem('hms_user') || '{}');
            tenant = JSON.parse(localStorage.getItem('hms_tenant') || '{}');
        } catch (e) { /* malformed storage — still connect, channels just skip */ }

        const secure = window.location.protocol === 'https:';
        try {
            state.echo = new Echo({
                broadcaster: 'reverb',
                key: conf.key,
                wsHost: window.location.hostname,
                wsPort: secure ? 443 : 80,
                wssPort: 443,
                forceTLS: secure,
                // pusher-js 8 names its TLS socket transport 'ws' too (TLS comes from
                // forceTLS) — ['wss'] alone disabled every strategy → state "failed".
                enabledTransports: ['ws', 'wss'],
                authEndpoint: '/api/broadcasting/auth',
                auth: {
                    headers: {
                        'Authorization': 'Bearer ' + token,
                        'Accept': 'application/json',
                    },
                },
            });
        } catch (e) { return; }

        if (user.id) {
            state.echo.private('user.' + user.id)
                .listen('.notification.created', onNotification);
        }
        if (tenant.id) {
            state.echo.private('tenant.' + tenant.id)
                .listen('.data.changed', (e) => fire(e && e.area, e && e.hint));
        }

        // Reconnect catch-up: refetch every registered area once —
        // events emitted while the socket was down were missed.
        try {
            const conn = state.echo.connector && state.echo.connector.pusher
                && state.echo.connector.pusher.connection;
            if (conn) {
                conn.bind('connected', () => {
                    const replay = state.wasDown;
                    state.wasDown = false;
                    state.ready = true;
                    if (replay) Object.keys(listeners).forEach(a => fire(a, { reconnect: true }));
                });
                conn.bind('unavailable', () => { state.wasDown = true; state.ready = false; });
                conn.bind('disconnected', () => { state.wasDown = true; state.ready = false; });
            }
        } catch (e) { /* connector introspection optional */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
