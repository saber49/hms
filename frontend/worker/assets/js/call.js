/* =====================================================================
   call.js — worker side of office→worker WebRTC voice calls.

   Self-hosted stack, no external libs (CSP is script-src 'self'):
   - Minimal pusher-protocol WebSocket client for Reverb
     (private-worker.{tenant}.{workerId}, auth via /worker-app/broadcasting/auth).
   - Invite also arrives via native push (app closed) → /calls/pending
     on boot / resume / notification tap shows the incoming screen.
   - Signaling REST: GET /calls/{id} (offer + queued ICE),
     POST answer / reject / ice / end.

   Events handled: call.invite, call.offer, call.ice, call.cancel, call.end
===================================================================== */
(function () {
    const ICE_POLL_MS = 2500;
    const RING_TIMEOUT_MS = 60000;

    const s = {
        ws: null, wsKey: null, channel: null, socketId: null,
        reconnectTimer: null, started: false,
        call: null, pc: null, localStream: null, remoteAudio: null,
        offerSdp: null, earlyIce: [], answering: false,
        ringTimer: null, tickTimer: null, icePollTimer: null, dropTimer: null,
        startedAt: null, muted: false, ringtone: null, ringingFor: null,
    };

    // Inline SVGs — the bundled Material Symbols subset ships only the icons
    // the app already uses; call/mic glyphs are not in the 6 KB font.
    const ICON_PHONE = '<svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.4 21 3 13.6 3 4.5a1 1 0 0 1 1-1H7.5a1 1 0 0 1 1 1c0 1.24.2 2.45.57 3.57a1 1 0 0 1-.25 1.02l-2.2 2.2z"/></svg>';
    const ICON_END = '<svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><path d="M12 9c-5.52 0-10 2.24-10 5v2.5a1 1 0 0 0 1 1H6a1 1 0 0 0 1-1v-2.1c1.55-.52 3.24-.8 5-.8s3.45.28 5 .8v2.1a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1V14c0-2.76-4.48-5-10-5z" transform="rotate(180 12 13.2)"/></svg>';
    const ICON_MIC = '<svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M12 15a3.5 3.5 0 0 0 3.5-3.5v-6a3.5 3.5 0 1 0-7 0v6A3.5 3.5 0 0 0 12 15z"/><path d="M17.5 11.5a5.5 5.5 0 0 1-11 0H4.9a7.1 7.1 0 0 0 6.2 6.6V21h1.8v-2.9a7.1 7.1 0 0 0 6.2-6.6h-1.6z"/></svg>';
    const ICON_MIC_OFF = '<svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M12 15a3.5 3.5 0 0 0 3.5-3.5V9.6L8.5 5.1v.4A3.5 3.5 0 0 0 12 15z" opacity=".35"/><path d="M17.5 11.5a5.48 5.48 0 0 1-4.4 5.4l1.4 1.4a7.1 7.1 0 0 0 4.6-6.8h-1.6zM4.9 11.5a7.1 7.1 0 0 0 6.2 6.6V21h1.8v-2.9c.5-.1 1-.3 1.5-.5l-1.5-1.5a5.5 5.5 0 0 1-6.4-4.6H4.9z"/><rect x="4" y="2.5" width="1.8" height="21" rx=".9" transform="rotate(40 4 2.5)"/></svg>';

    /* ================= UI ================= */

    function ensureDom() {
        if (document.getElementById('wcallOverlay')) return;
        const style = document.createElement('style');
        style.textContent = `
            #wcallOverlay{position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;
                align-items:center;justify-content:space-between;
                background:linear-gradient(170deg,#1a2744 0%,#0f1830 70%,#0a1020 100%);
                color:#fff;padding:calc(env(safe-area-inset-top,0px) + 60px) 24px calc(env(safe-area-inset-bottom,0px) + 48px);
                font-family:inherit;}
            .wcall-brand{display:flex;align-items:center;gap:10px;opacity:.85}
            .wcall-brand img{width:34px;height:34px;border-radius:8px}
            .wcall-brand span{font-size:17px;font-weight:600;letter-spacing:.4px}
            .wcall-mid{display:flex;flex-direction:column;align-items:center;text-align:center}
            .wcall-avatar{width:104px;height:104px;border-radius:50%;background:#3b82f6;display:flex;
                align-items:center;justify-content:center;font-size:38px;font-weight:700;position:relative}
            .wcall-ringing .wcall-avatar::after{content:'';position:absolute;inset:-10px;border-radius:50%;
                border:3px solid rgba(59,130,246,.6);animation:wcallPulse 1.5s ease-out infinite}
            @keyframes wcallPulse{0%{transform:scale(.85);opacity:1}100%{transform:scale(1.45);opacity:0}}
            .wcall-name{margin-top:22px;font-size:24px;font-weight:700}
            .wcall-sub{margin-top:6px;font-size:15px;color:rgba(255,255,255,.65)}
            .wcall-timer{margin-top:8px;font-size:17px;color:#7ee2a8;font-variant-numeric:tabular-nums;min-height:22px}
            .wcall-actions{display:flex;align-items:center;justify-content:center;gap:34px;width:100%}
            .wcall-btn-col{display:flex;flex-direction:column;align-items:center;gap:10px}
            .wcall-btn{width:70px;height:70px;border-radius:50%;border:none;font-size:26px;color:#fff;
                display:flex;align-items:center;justify-content:center;cursor:pointer;
                box-shadow:0 8px 24px rgba(0,0,0,.35);transition:transform .12s}
            .wcall-btn:active{transform:scale(.92)}
            .wcall-btn-accept{background:#22c55e}
            .wcall-btn-reject{background:#ef4444}
            .wcall-btn-mute{background:rgba(255,255,255,.16)}
            .wcall-btn-mute.wcall-on{background:#f59e0b}
            .wcall-btn-label{font-size:12.5px;color:rgba(255,255,255,.75)}
            .wcall-ringing .wcall-btn-accept{animation:wcallWiggle 1.2s ease-in-out infinite}
            @keyframes wcallWiggle{0%,100%{transform:rotate(0)}25%{transform:rotate(-12deg)}75%{transform:rotate(12deg)}}
        `;
        document.head.appendChild(style);

        const ov = document.createElement('div');
        ov.id = 'wcallOverlay';
        ov.style.display = 'none';
        ov.innerHTML = `
            <div class="wcall-brand">
                <img src="assets/icons/icon-192.png" alt="" onerror="this.style.display='none'">
                <span>Clood-X</span>
            </div>
            <div class="wcall-mid">
                <div class="wcall-avatar" id="wcallAvatar">CX</div>
                <div class="wcall-name" id="wcallName">Clood-X</div>
                <div class="wcall-sub" id="wcallSub">Eingehender Anruf…</div>
                <div class="wcall-timer" id="wcallTimer"></div>
            </div>
            <div class="wcall-actions" id="wcallIncoming">
                <div class="wcall-btn-col">
                    <button class="wcall-btn wcall-btn-reject" id="wcallReject">${ICON_END}</button>
                    <span class="wcall-btn-label">Ablehnen</span>
                </div>
                <div class="wcall-btn-col">
                    <button class="wcall-btn wcall-btn-accept" id="wcallAccept">${ICON_PHONE}</button>
                    <span class="wcall-btn-label">Annehmen</span>
                </div>
            </div>
            <div class="wcall-actions" id="wcallIncall" style="display:none">
                <div class="wcall-btn-col">
                    <button class="wcall-btn wcall-btn-mute" id="wcallMute">${ICON_MIC}</button>
                    <span class="wcall-btn-label">Stumm</span>
                </div>
                <div class="wcall-btn-col">
                    <button class="wcall-btn wcall-btn-reject" id="wcallEnd">${ICON_END}</button>
                    <span class="wcall-btn-label">Beenden</span>
                </div>
            </div>`;
        document.body.appendChild(ov);

        ov.querySelector('#wcallAccept').onclick = () => answer();
        ov.querySelector('#wcallReject').onclick = () => reject();
        ov.querySelector('#wcallEnd').onclick = () => hangup();
        ov.querySelector('#wcallMute').onclick = () => toggleMute();
    }

    function showIncoming(call) {
        ensureDom();
        const ov = document.getElementById('wcallOverlay');
        ov.classList.add('wcall-ringing');
        ov.style.display = 'flex';
        document.getElementById('wcallIncoming').style.display = 'flex';
        document.getElementById('wcallIncall').style.display = 'none';
        document.getElementById('wcallName').textContent = call.admin_name || 'Disposition';
        document.getElementById('wcallAvatar').textContent =
            (call.admin_name || 'CX').trim().split(/\s+/).map(w => w[0]).join('').substring(0, 2).toUpperCase();
        sub('Eingehender Anruf…');
        document.getElementById('wcallTimer').textContent = '';
        // Invite can arrive twice (WS + pending-poll) — without this guard
        // each run spawns a new oscillator pair and the previous one keeps
        // buzzing underneath the conversation.
        if (s.ringingFor === call.id) return;
        s.ringingFor = call.id;
        startRingtone();
    }

    function showIncall() {
        stopRingtone();   // belt & suspenders — never ring under the call
        const ov = document.getElementById('wcallOverlay');
        ov.classList.remove('wcall-ringing');
        document.getElementById('wcallIncoming').style.display = 'none';
        document.getElementById('wcallIncall').style.display = 'flex';
        sub('Verbunden');
        startTimer();
    }

    function sub(t) { const el = document.getElementById('wcallSub'); if (el) el.textContent = t; }
    function hide() { const o = document.getElementById('wcallOverlay'); if (o) o.style.display = 'none'; }

    function startTimer() {
        s.startedAt = Date.now();
        clearInterval(s.tickTimer);
        s.tickTimer = setInterval(() => {
            const sec = Math.floor((Date.now() - s.startedAt) / 1000);
            const el = document.getElementById('wcallTimer');
            if (el) el.textContent = String(Math.floor(sec / 60)).padStart(2, '0') + ':' + String(sec % 60).padStart(2, '0');
        }, 1000);
    }

    /* ---------- ringtone + vibration ---------- */

    function startRingtone() {
        stopRingtone();   // idempotent — a leaked oscillator pair must never
        try { navigator.vibrate && navigator.vibrate([600, 400, 600, 400, 600, 1600]); } catch (e) {}
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const gain = ctx.createGain(); gain.gain.value = 0;
            gain.connect(ctx.destination);
            const osc1 = ctx.createOscillator(); osc1.frequency.value = 440;
            const osc2 = ctx.createOscillator(); osc2.frequency.value = 480;
            osc1.connect(gain); osc2.connect(gain); osc1.start(); osc2.start();
            let on = true;
            const iv = setInterval(() => {
                on = !on;
                gain.gain.setTargetAtTime(on ? 0.09 : 0, ctx.currentTime, 0.02);
            }, 900);
            gain.gain.setTargetAtTime(0.09, ctx.currentTime, 0.02);
            s.ringtone = { ctx, iv, oscs: [osc1, osc2] };
        } catch (e) {}
    }
    function stopRingtone() {
        if (!s.ringtone) return;
        try { navigator.vibrate && navigator.vibrate(0); } catch (e) {}
        try { clearInterval(s.ringtone.iv); s.ringtone.oscs.forEach(o => o.stop()); s.ringtone.ctx.close(); } catch (e) {}
        s.ringtone = null;
    }

    /* ================= minimal pusher-protocol client ================= */

    function wsHost() {
        if (IS_CAPACITOR) return 'clood-x.com';
        return window.location.hostname;
    }

    async function connect() {
        if (s.ws || s.reconnectTimer) return;
        let cfg;
        try {
            cfg = await apiGet(W_API_BASE + '/worker-app/realtime/config');
        } catch (e) { scheduleReconnect(8000); return; }
        if (!cfg || !cfg.enabled || !cfg.key || !cfg.channel) return;
        s.wsKey = cfg.key; s.channel = cfg.channel;

        let ws;
        try {
            ws = new WebSocket('wss://' + wsHost() + '/app/' + cfg.key
                + '?protocol=7&client=cloodx-worker&version=8.4.0');
        } catch (e) { scheduleReconnect(8000); return; }
        s.ws = ws;

        ws.onmessage = async (ev) => {
            let msg; try { msg = JSON.parse(ev.data); } catch (e) { return; }
            if (msg.event === 'pusher:connection_established') {
                try { s.socketId = JSON.parse(msg.data).socket_id; } catch (e) { return; }
                await subscribe();
            } else if (msg.event === 'pusher:ping') {
                try { ws.send(JSON.stringify({ event: 'pusher:pong', data: {} })); } catch (e) {}
            } else if (typeof msg.event === 'string' && msg.event.indexOf('call.') === 0) {
                let d = msg.data; try { d = typeof d === 'string' ? JSON.parse(d) : d; } catch (e) {}
                onCallEvent(msg.event.slice(5), d || {});
            }
        };
        ws.onclose = () => { s.ws = null; s.socketId = null; scheduleReconnect(5000); };
        ws.onerror = () => { try { ws.close(); } catch (e) {} };
    }

    async function subscribe() {
        if (!s.ws || !s.socketId || !s.channel) return;
        try {
            const res = await fetch(W_API_BASE + '/worker-app/broadcasting/auth', {
                method: 'POST', headers: wAuthHeadersJson(),
                body: JSON.stringify({ socket_id: s.socketId, channel_name: 'private-' + s.channel }),
            });
            const d = await res.json();
            if (!d.auth) return;
            s.ws.send(JSON.stringify({
                event: 'pusher:subscribe',
                data: { channel: 'private-' + s.channel, auth: d.auth },
            }));
        } catch (e) { /* auth failed — pending-poll still catches invites */ }
    }

    function scheduleReconnect(ms) {
        if (s.reconnectTimer) return;
        s.reconnectTimer = setTimeout(() => { s.reconnectTimer = null; connect(); }, ms);
    }

    function onCallEvent(type, d) {
        if (!d || !d.call_id) return;
        if (s.call && d.call_id !== s.call.id) return;   // not our call
        switch (type) {
            case 'invite':
                s.call = { id: d.call_id, admin_name: d.caller || 'Clood-X', state: 'ringing' };
                s.offerSdp = null; s.earlyIce = [];
                showIncoming(s.call);
                armRingTimeout();
                break;
            case 'offer':   if (s.call) s.offerSdp = d.sdp; break;
            case 'ice':
                if (s.pc) s.pc.addIceCandidate(d.candidate).catch(() => {});
                else s.earlyIce.push(d.candidate);
                break;
            case 'cancel':  endLocal('Verpasster Anruf'); break;
            case 'end':     endLocal('Anruf beendet'); break;
        }
    }

    /* ================= REST ================= */

    function apiGet(url) {
        return fetch(url, { headers: wAuthHeaders() }).then(r => r.json().catch(() => null));
    }
    function apiPost(url, body) {
        return fetch(url, { method: 'POST', headers: wAuthHeadersJson(), body: JSON.stringify(body || {}) })
            .then(r => r.json().catch(() => null));
    }

    /* ================= WebRTC ================= */

    async function buildPeer() {
        const ice = await apiGet(W_API_BASE + '/worker-app/calls/ice-servers');
        s.pc = new RTCPeerConnection({ iceServers: (ice && ice.iceServers) || [] });
        s.pc.onicecandidate = e => {
            if (e.candidate && s.call) {
                apiPost(W_API_BASE + '/worker-app/calls/' + encodeURIComponent(s.call.id) + '/ice',
                    { candidate: e.candidate.toJSON() });
            }
        };
        s.pc.ontrack = e => {
            if (!s.remoteAudio) {
                s.remoteAudio = document.createElement('audio');
                s.remoteAudio.autoplay = true;
                s.remoteAudio.playsInline = true;
                document.body.appendChild(s.remoteAudio);
            }
            // e.streams is empty on some WebKit/WKWebView builds — fall back
            // to wrapping the raw track, otherwise the call connects silent.
            s.remoteAudio.srcObject =
                (e.streams && e.streams[0]) ? e.streams[0] : new MediaStream([e.track]);
            s.remoteAudio.play && s.remoteAudio.play().catch(() => {});
        };
        s.pc.onconnectionstatechange = () => {
            if (!s.pc) return;
            if (s.pc.connectionState === 'connected') {
                clearTimeout(s.dropTimer); s.dropTimer = null;
                if (s.call) showIncall();
            } else if (['disconnected', 'failed'].includes(s.pc.connectionState)) {
                // Mobile networks flap — ICE restart + grace window instead of
                // instantly killing a call that may recover.
                try { s.pc.restartIce(); } catch (e) {}
                clearTimeout(s.dropTimer);
                s.dropTimer = setTimeout(() => {
                    if (s.pc && s.pc.connectionState !== 'connected') endLocal('Verbindung unterbrochen');
                }, 8000);
            } else if (s.pc.connectionState === 'closed') endLocal('Verbindung unterbrochen');
        };
        s.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        s.localStream.getTracks().forEach(t => s.pc.addTrack(t, s.localStream));
        (s.earlyIce || []).forEach(c => s.pc.addIceCandidate(c).catch(() => {}));
        s.earlyIce = [];
    }

    async function answer() {
        if (!s.call || s.answering) return;   // one tap = one negotiation
        s.answering = true;
        stopRingtone();
        clearTimeout(s.ringTimer); s.ringTimer = null;  // answered — no "missed call" kill
        sub('Verbinde…');
        const callId = s.call.id;
        try {
            await buildPeer();
            // Offer may still be in flight — fetch state (drains queued ICE too)
            // with a short retry while the caller finishes posting it.
            let st = null;
            for (let i = 0; i < 8 && s.call && !(s.offerSdp || (st && st.offer_sdp)); i++) {
                const res = await apiGet(W_API_BASE + '/worker-app/calls/' + encodeURIComponent(callId));
                st = res && res.call;
                if (!st) break;
                if (!s.offerSdp) await new Promise(r => setTimeout(r, 700));
            }
            if (!s.call) return;                       // ended while we waited
            const offer = s.offerSdp || (st && st.offer_sdp);
            if (!offer || !s.pc) { endLocal('Verbindung fehlgeschlagen'); return; }
            (st && st.admin_ice || []).forEach(c => s.pc.addIceCandidate(c).catch(() => {}));

            // HTTP input normalization strips \r and the final EOL —
            // WebRTC demands CRLF endings incl. the last line.
            let offerSdp = offer.replace(/\r?\n/g, '\r\n');
            if (!offerSdp.endsWith('\r\n')) offerSdp += '\r\n';
            await s.pc.setRemoteDescription({ type: 'offer', sdp: offerSdp });
            const ans = await s.pc.createAnswer();
            await s.pc.setLocalDescription(ans);
            await apiPost(W_API_BASE + '/worker-app/calls/' + encodeURIComponent(callId) + '/answer',
                { sdp: ans.sdp });
            if (!s.call || s.call.id !== callId) return;
            s.answering = false;
            showIncall();                              // leave the ringing UI now
            sub('Verbinde…');
            startIcePoll();
        } catch (e) {
            const denied = e && (e.name === 'NotAllowedError' || e.name === 'NotFoundError');
            endLocal(denied ? 'Mikrofon-Zugriff verweigert' : 'Verbindung fehlgeschlagen');
        }
    }

    function reject() {
        if (s.call) apiPost(W_API_BASE + '/worker-app/calls/' + encodeURIComponent(s.call.id) + '/reject');
        endLocal(null);
    }

    function hangup() {
        if (s.call) apiPost(W_API_BASE + '/worker-app/calls/' + encodeURIComponent(s.call.id) + '/end');
        endLocal(null);
    }

    function toggleMute() {
        if (!s.localStream) return;
        s.muted = !s.muted;
        s.localStream.getAudioTracks().forEach(t => { t.enabled = !s.muted; });
        const b = document.getElementById('wcallMute');
        b.classList.toggle('wcall-on', s.muted);
        b.innerHTML = s.muted ? ICON_MIC_OFF : ICON_MIC;
    }

    function startIcePoll() {
        clearInterval(s.icePollTimer);
        s.icePollTimer = setInterval(async () => {
            if (!s.call || !s.pc) return;
            const res = await apiGet(W_API_BASE + '/worker-app/calls/' + encodeURIComponent(s.call.id));
            const st = res && res.call;
            // Session deleted server-side = remote ended. A parse failure
            // (res=null) is transient — keep polling, don't kill the call.
            if (res && res.error) { endLocal('Anruf beendet'); return; }
            (st && st.admin_ice || []).forEach(c => s.pc.addIceCandidate(c).catch(() => {}));
            if (s.pc && s.pc.connectionState === 'connected') clearInterval(s.icePollTimer);
        }, ICE_POLL_MS);
    }

    function armRingTimeout() {
        clearTimeout(s.ringTimer);
        s.ringTimer = setTimeout(() => endLocal('Verpasster Anruf'), RING_TIMEOUT_MS);
    }

    function endLocal(msg) {
        stopRingtone();
        clearTimeout(s.ringTimer); s.ringTimer = null;
        clearTimeout(s.dropTimer); s.dropTimer = null;
        clearInterval(s.tickTimer); s.tickTimer = null;
        clearInterval(s.icePollTimer); s.icePollTimer = null;
        if (s.pc) { try { s.pc.close(); } catch (e) {} }
        if (s.localStream) { s.localStream.getTracks().forEach(t => t.stop()); }
        if (s.remoteAudio) { try { s.remoteAudio.remove(); } catch (e) {} }
        s.pc = null; s.localStream = null; s.remoteAudio = null;
        s.call = null; s.offerSdp = null; s.earlyIce = []; s.startedAt = null;
        s.muted = false; s.answering = false; s.ringingFor = null;
        if (msg) {
            ensureDom();
            sub(msg);
            document.getElementById('wcallOverlay').classList.remove('wcall-ringing');
            document.getElementById('wcallOverlay').style.display = 'flex';
            document.getElementById('wcallIncoming').style.display = 'none';
            document.getElementById('wcallIncall').style.display = 'none';
            if (typeof showToast === 'function') showToast(msg, 'error');
            setTimeout(hide, 1600);
        } else {
            hide();
        }
    }

    /* ================= public ================= */

    // Called on push tap / app resume: check for a ringing invite.
    async function checkPending() {
        if (s.call) return;
        try {
            const res = await apiGet(W_API_BASE + '/worker-app/calls/pending');
            if (res && res.call) {
                s.call = { id: res.call.id, admin_name: res.call.admin_name, state: 'ringing' };
                s.offerSdp = null; s.earlyIce = [];
                showIncoming(s.call);
                armRingTimeout();
            }
        } catch (e) {}
    }

    function init() {
        if (s.started) return;
        s.started = true;
        connect();
        checkPending();
    }

    window.WCall = {
        init: init,
        checkPending: checkPending,
        active: function () { return !!s.call; },
    };
})();
