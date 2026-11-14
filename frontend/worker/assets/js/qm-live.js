/* ============================================
   Clood-X — QM live updates (Laravel Reverb, pusher protocol)
   ONE client for the worker app, the Objekt-Terminal and the Kundenportal.
   CxQmLive.start({ base, headers(json), host, onChange(bereich) })
     GET  base + '/live/config' → {enabled, key, channels[]}
     POST base + '/live/auth'   → {auth}   (server allows only own Objekte)
   Events "qm.changed" are debounced; reconnects with back-off.
   ============================================ */
(function () {
    'use strict';
    let o = null, ws = null, socketId = null, timer = null, deb = null, tries = 0;

    async function connect() {
        if (ws || !o) return;
        let cfg;
        try {
            const r = await fetch(o.base + '/live/config', { headers: o.headers(false) });
            if (!r.ok) return retry();
            cfg = await r.json();
        } catch (e) { return retry(); }
        if (!cfg.enabled || !cfg.key || !(cfg.channels || []).length) return;
        try { ws = new WebSocket('wss://' + (o.host || location.hostname) + '/app/' + cfg.key + '?protocol=7&client=cloodx-qm&version=8.4.0'); }
        catch (e) { return retry(); }
        ws.onmessage = async ev => {
            let m; try { m = JSON.parse(ev.data); } catch (e) { return; }
            if (m.event === 'pusher:connection_established') {
                tries = 0;
                try { socketId = JSON.parse(m.data).socket_id; } catch (e) { return; }
                for (const ch of cfg.channels) subscribe('private-' + ch);
            } else if (m.event === 'pusher:ping') {
                try { ws.send(JSON.stringify({ event: 'pusher:pong', data: {} })); } catch (e) {}
            } else if (m.event === 'qm.changed') {
                let d = m.data; try { d = typeof d === 'string' ? JSON.parse(d) : d; } catch (e) { d = {}; }
                clearTimeout(deb);
                deb = setTimeout(() => { try { o.onChange((d && d.bereich) || ''); } catch (e) {} }, 400);
            }
        };
        ws.onclose = () => { ws = null; socketId = null; retry(); };
        ws.onerror = () => { try { ws.close(); } catch (e) {} };
    }

    async function subscribe(channel) {
        try {
            const r = await fetch(o.base + '/live/auth', { method: 'POST', headers: o.headers(true), body: JSON.stringify({ socket_id: socketId, channel_name: channel }) });
            const d = await r.json();
            if (d.auth && ws) ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth: d.auth } }));
        } catch (e) { /* not entitled / offline */ }
    }

    function retry() {
        if (timer || !o) return;
        tries = Math.min(tries + 1, 6);
        timer = setTimeout(() => { timer = null; connect(); }, 3000 * tries);
    }

    window.CxQmLive = {
        start(opts) { o = opts; connect(); },
        /** New shifts / objects → resubscribe with a fresh channel list. */
        restart() { if (ws) { try { ws.close(); } catch (e) {} } else connect(); },
        stop() { o = null; clearTimeout(timer); if (ws) try { ws.close(); } catch (e) {} },
    };
})();
