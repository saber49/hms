/* =====================================================================
   call.js — office → worker WebRTC voice call (admin portal)

   Entry point: CloodCall.start(dienstNr, displayName) — rendered as the
   phone button in MA-Nachrichten (worker_messages.js).

   Flow:
     POST /calls {dienst_nr}        → ringing session + push to the worker
     POST /calls/{id}/offer {sdp}   → relayed to private-worker.{t}.{w}
     POST /calls/{id}/ice           → candidate relay
     events on private-user.{me}    → .call.answer / .call.ice /
                                      .call.rejected / .call.end
   Poll fallback: GET /calls/{id} every 1.5 s while connecting — covers
   sessions where Reverb never connected (offline CDN, WS down).
===================================================================== */
(function () {
    const state = {
        call: null,          // {id, worker_name, state}
        pc: null,
        localStream: null,
        remoteAudio: null,
        channel: null,
        pollTimer: null,
        ringTimer: null, dropTimer: null, pendingIce: [],
        tickTimer: null,
        startedAt: null,
        muted: false,
        lastWorkerIceCount: 0,
    };

    // Refresh/close must end the call — otherwise the worker keeps a live
    // session while this page forgets it, and the next "call" rings on top
    // of the old one. keepalive lets the request outlive the page unload.
    window.addEventListener('pagehide', (e) => {
        // persisted = page parked in bfcache (iOS/Android backgrounding a tab
        // fires pagehide with persisted=true) — the user can come BACK, so
        // only end the call on a real unload (refresh/close/navigation).
        if (e.persisted) return;
        const c = state.call;
        if (!c) return;
        const path = state.startedAt ? 'end' : 'cancel';
        try {
            fetch(API_BASE + '/calls/' + encodeURIComponent(c.id) + '/' + path, {
                method: 'POST', keepalive: true,
                headers: {
                    'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || ''),
                    'Accept': 'application/json',
                },
            });
        } catch (e) {}
    });

    /* ---------- UI ---------- */

    function ensureDom() {
        if (document.getElementById('ccallOverlay')) return;
        const style = document.createElement('style');
        style.textContent = `
            #ccallOverlay{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;
                background:rgba(10,15,30,.72);backdrop-filter:blur(6px);}
            .ccall-card{width:min(340px,92vw);border-radius:28px;padding:36px 24px 28px;text-align:center;
                background:linear-gradient(160deg,#1a2744 0%,#0f1830 100%);color:#fff;
                box-shadow:0 24px 80px rgba(0,0,0,.5);font-family:inherit;}
            .ccall-avatar{width:88px;height:88px;border-radius:50%;margin:0 auto 18px;display:flex;align-items:center;
                justify-content:center;font-size:32px;font-weight:700;background:#3b82f6;position:relative;}
            .ccall-ringing .ccall-avatar::after{content:'';position:absolute;inset:-8px;border-radius:50%;
                border:2px solid rgba(59,130,246,.7);animation:ccallPulse 1.4s ease-out infinite;}
            @keyframes ccallPulse{0%{transform:scale(.9);opacity:1}100%{transform:scale(1.5);opacity:0}}
            .ccall-name{font-size:20px;font-weight:700;margin-bottom:4px;}
            .ccall-sub{font-size:14px;color:rgba(255,255,255,.65);min-height:20px;}
            .ccall-timer{font-size:15px;color:#7ee2a8;font-variant-numeric:tabular-nums;margin-top:6px;min-height:20px;}
            .ccall-controls{display:flex;justify-content:center;gap:18px;margin-top:26px;}
            .ccall-btn{width:58px;height:58px;border-radius:50%;border:none;cursor:pointer;font-size:20px;
                display:flex;align-items:center;justify-content:center;transition:transform .12s,filter .12s;color:#fff;}
            .ccall-btn:hover{transform:scale(1.08)}
            .ccall-btn:active{transform:scale(.95)}
            .ccall-btn-end{background:#ef4444;}
            .ccall-btn-mute{background:rgba(255,255,255,.16);}
            .ccall-btn-mute.ccall-on{background:#f59e0b;}
            .ccall-btn:disabled{opacity:.4;cursor:default;transform:none}
        `;
        document.head.appendChild(style);

        const overlay = document.createElement('div');
        overlay.id = 'ccallOverlay';
        overlay.innerHTML = `
            <div class="ccall-card ccall-ringing" id="ccallCard">
                <div class="ccall-avatar" id="ccallAvatar">–</div>
                <div class="ccall-name" id="ccallName">–</div>
                <div class="ccall-sub" id="ccallSub">Verbinde…</div>
                <div class="ccall-timer" id="ccallTimer"></div>
                <div class="ccall-controls">
                    <button class="ccall-btn ccall-btn-mute" id="ccallMute" title="Mikrofon stumm" disabled>
                        <i class="fas fa-microphone"></i>
                    </button>
                    <button class="ccall-btn ccall-btn-end" id="ccallEnd" title="Auflegen">
                        <i class="fas fa-phone-slash"></i>
                    </button>
                </div>
            </div>`;
        overlay.style.display = 'none';
        document.body.appendChild(overlay);

        document.getElementById('ccallEnd').onclick = () => hangup();
        document.getElementById('ccallMute').onclick = () => toggleMute();
    }

    function showOverlay(name) {
        ensureDom();
        const o = document.getElementById('ccallOverlay');
        o.style.display = 'flex';
        document.getElementById('ccallAvatar').textContent =
            (name || 'MA').trim().split(/\s+/).map(w => w[0]).join('').substring(0, 2).toUpperCase() || 'MA';
        document.getElementById('ccallName').textContent = name || 'Mitarbeiter';
        document.getElementById('ccallCard').classList.add('ccall-ringing');
        setSub('Verbinde…');
        document.getElementById('ccallTimer').textContent = '';
        document.getElementById('ccallMute').disabled = true;
    }

    function hideOverlay() {
        const o = document.getElementById('ccallOverlay');
        if (o) o.style.display = 'none';
    }

    function setSub(text) {
        const el = document.getElementById('ccallSub');
        if (el) el.textContent = text;
    }

    function startTimer() {
        state.startedAt = Date.now();
        state.tickTimer = setInterval(() => {
            const s = Math.floor((Date.now() - state.startedAt) / 1000);
            const mm = String(Math.floor(s / 60)).padStart(2, '0');
            const ss = String(s % 60).padStart(2, '0');
            const el = document.getElementById('ccallTimer');
            if (el) el.textContent = mm + ':' + ss;
        }, 1000);
    }

    /* ---------- ringback tone (office hears ringing while worker rings) ---------- */

    let ringback = null;
    function startRingback() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.frequency.value = 440;
            gain.gain.value = 0;
            osc.connect(gain); gain.connect(ctx.destination); osc.start();
            // German ringback cadence: 1s on, 4s off
            let on = true;
            const iv = setInterval(() => {
                on = !on;
                gain.gain.setTargetAtTime(on ? 0.06 : 0, ctx.currentTime, 0.02);
            }, 1000);
            gain.gain.setTargetAtTime(0.06, ctx.currentTime, 0.02);
            ringback = { ctx, osc, iv };
        } catch (e) { /* audio feedback is cosmetic */ }
    }
    function stopRingback() {
        if (!ringback) return;
        try { clearInterval(ringback.iv); ringback.osc.stop(); ringback.ctx.close(); } catch (e) {}
        ringback = null;
    }

    /* ---------- signaling ---------- */

    function api(path, method, body) {
        return fetch(API_BASE + path, {
            method: method || 'GET',
            headers: {
                'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || ''),
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: body ? JSON.stringify(body) : undefined,
        }).then(r => r.json().catch(() => ({})));
    }

    function subscribeEvents() {
        // Reuse the shared Echo instance — private('user.{id}') is already
        // subscribed by realtime.js; Echo returns the same channel and we
        // just attach our listeners.
        try {
            const me = JSON.parse(localStorage.getItem('hms_user') || '{}');
            const ch = window.HMSRealtime && HMSRealtime.channel
                ? HMSRealtime.channel('user.' + me.id)
                : null;
            if (!ch) return;
            state.channel = ch;
            ch.listen('.call.answer', e => onAnswer(e));
            ch.listen('.call.ice', e => onRemoteIce(e));
            ch.listen('.call.rejected', () => finish('abgelehnt'));
            ch.listen('.call.end', () => finish('beendet'));
        } catch (e) { /* polling fallback below covers this */ }
    }

    function startPolling() {
        stopPolling();
        state.pollTimer = setInterval(async () => {
            if (!state.call) return;
            const res = await api('/calls/' + encodeURIComponent(state.call.id));
            const c = res && res.call;
            // A real 404 means the session ended server-side (worker hung
            // up / expired) — a parse failure ({}) is transient, keep polling.
            if (!c) {
                if (res && res.error) finish('beendet');
                return;
            }
            if (c.answer_sdp && state.pc && !state.pc.currentRemoteDescription) {
                await applyAnswer(c.answer_sdp);
            }
            // Server DRAINs the queue on every poll — every entry here is new,
            // so apply them all (a cumulative offset used to skip late
            // candidates — e.g. the TURN relay one — and kill media paths).
            (c.worker_ice || []).forEach(cand => {
                if (state.pc && cand) state.pc.addIceCandidate(cand).catch(() => {});
            });
            // Session gone from server side while we still ring → hung up.
            if (c.state !== 'ringing' && c.state !== 'active') finish('beendet');
        }, 1500);
    }
    function stopPolling() {
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    }

    /* ---------- WebRTC ---------- */

    async function buildPeer(ice) {
        state.pc = new RTCPeerConnection({ iceServers: (ice && ice.iceServers) || [] });
        state.pc.onicecandidate = e => {
            if (!e.candidate) return;
            const cand = e.candidate.toJSON();
            // state.call is set only after POST /calls returns — candidates
            // gathered during that roundtrip used to be silently dropped
            // (=> dead media). Buffer them, flushed right after start().
            if (!state.call) { state.pendingIce.push(cand); return; }
            api('/calls/' + encodeURIComponent(state.call.id) + '/ice', 'POST', { candidate: cand });
        };
        state.pc.ontrack = e => {
            if (!state.remoteAudio) {
                state.remoteAudio = document.createElement('audio');
                state.remoteAudio.autoplay = true;
                state.remoteAudio.playsInline = true;
                document.body.appendChild(state.remoteAudio);
            }
            // e.streams is empty on some WebKit/WKWebView builds — fall back
            // to wrapping the raw track, otherwise the call connects silent.
            state.remoteAudio.srcObject =
                (e.streams && e.streams[0]) ? e.streams[0] : new MediaStream([e.track]);
            state.remoteAudio.play && state.remoteAudio.play().catch(() => {});
            onConnected();
        };
        state.pc.onconnectionstatechange = () => {
            if (!state.pc) return;
            if (state.pc.connectionState === 'connected') {
                clearTimeout(state.dropTimer); state.dropTimer = null;
                onConnected();
            } else if (['disconnected', 'failed'].includes(state.pc.connectionState)) {
                // Mobile networks flap — try ICE restart and give it a grace
                // window before tearing down a call that may recover.
                try { state.pc.restartIce(); } catch (e) {}
                clearTimeout(state.dropTimer);
                state.dropTimer = setTimeout(() => {
                    if (state.pc && state.pc.connectionState !== 'connected') finish('Verbindung unterbrochen');
                }, 8000);
            } else if (state.pc.connectionState === 'closed') finish('Verbindung unterbrochen');
        };
        state.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        state.localStream.getTracks().forEach(t => state.pc.addTrack(t, state.localStream));
    }

    async function applyAnswer(sdp) {
        try {
            // Stored SDPs pass through HTTP input normalization (LF-only,
            // trailing terminator trimmed) — rebuild spec CRLF + final EOL.
            let fixed = String(sdp || '').replace(/\r?\n/g, '\r\n');
            if (fixed && !fixed.endsWith('\r\n')) fixed += '\r\n';
            await state.pc.setRemoteDescription({ type: 'answer', sdp: fixed });
        } catch (e) { console.warn('call: setRemoteDescription failed', e); }
    }

    function onAnswer(e) {
        if (!state.pc || !e || e.call_id !== (state.call && state.call.id)) return;
        // Worker picked up — kill the "no answer" auto-cancel NOW, not when
        // media connects: while ICE still negotiates the 45s ring timer would
        // otherwise fire /cancel and murder the live call.
        clearTimeout(state.ringTimer); state.ringTimer = null;
        applyAnswer(e.sdp);
    }

    function onRemoteIce(e) {
        if (!state.pc || !e || e.call_id !== (state.call && state.call.id) || !e.candidate) return;
        state.pc.addIceCandidate(e.candidate).catch(() => {});
    }

    function onConnected() {
        clearTimeout(state.ringTimer); state.ringTimer = null;  // belt & suspenders
        stopRingback();
        document.getElementById('ccallCard').classList.remove('ccall-ringing');
        setSub('Verbunden');
        document.getElementById('ccallMute').disabled = false;
        if (!state.startedAt) startTimer();
    }

    /* ---------- public API ---------- */

    async function start(dienstNr, name) {
        if (state.call) return;                       // one call at a time
        showOverlay(name);
        setSub('Verbinde…');

        try {
            // Build the offer BEFORE the ringing session exists — the invite
            // push/WS event only reaches the worker once offer_sdp is already
            // stored, so an early "Annehmen" can never race ahead of it. A
            // denied mic prompt also leaves no phantom ring on the phone.
            const ice = await api('/calls/ice-servers');
            await buildPeer(ice);
            const offer = await state.pc.createOffer({ offerToReceiveAudio: true });
            await state.pc.setLocalDescription(offer);

            const res = await api('/calls', 'POST', { dienst_nr: dienstNr, sdp: offer.sdp });
            if (!res.call) {
                teardown();
                const msg = res.busy ? 'Mitarbeiter wird bereits angerufen'
                    : (res.error || 'Anruf konnte nicht gestartet werden');
                if (typeof showToast === 'function') showToast(msg, 'error'); else alert(msg);
                return;
            }
            state.call = res.call;
            // Flush candidates that were gathered while POST /calls was in
            // flight — without this the worker only saw late stragglers.
            const cid = res.call.id;
            state.pendingIce.forEach(cand =>
                api('/calls/' + encodeURIComponent(cid) + '/ice', 'POST', { candidate: cand }));
            state.pendingIce = [];
            subscribeEvents();
            startPolling();                            // belt & suspenders next to WS
            setSub('Es klingelt…');
            startRingback();

            // Auto-cancel when nobody picks up within 45 s — tell the server
            // so the worker's ring + pending entry die with it.
            state.ringTimer = setTimeout(async () => {
                const c = state.call;
                if (c) { try { await api('/calls/' + encodeURIComponent(c.id) + '/cancel', 'POST'); } catch (e) {} }
                finish('Keine Antwort', true);
            }, 45000);
        } catch (e) {
            console.warn('call start failed', e);
            const denied = e && (e.name === 'NotAllowedError' || e.name === 'NotFoundError');
            finish(denied ? 'Mikrofon-Zugriff verweigert' : 'Verbindung fehlgeschlagen', true);
        }
    }

    function toggleMute() {
        if (!state.localStream) return;
        state.muted = !state.muted;
        state.localStream.getAudioTracks().forEach(t => { t.enabled = !state.muted; });
        const btn = document.getElementById('ccallMute');
        btn.classList.toggle('ccall-on', state.muted);
        btn.innerHTML = '<i class="fas fa-microphone' + (state.muted ? '-slash' : '') + '"></i>';
    }

    async function hangup() {
        const c = state.call;
        if (!c) { hideOverlay(); return; }
        const path = state.startedAt ? 'end' : 'cancel';
        try { await api('/calls/' + encodeURIComponent(c.id) + '/' + path, 'POST'); } catch (e) {}
        teardown();
    }

    function finish(reason, silent) {
        const msg = reason === 'abgelehnt' ? 'Anruf abgelehnt'
            : reason === 'beendet' ? 'Anruf beendet' : (reason || 'Anruf beendet');
        teardown();
        if (!silent) {
            // Briefly show the end reason on the card before closing it.
            ensureDom();
            setSub(msg);
            document.getElementById('ccallCard').classList.remove('ccall-ringing');
            document.getElementById('ccallOverlay').style.display = 'flex';
            setTimeout(hideOverlay, 1400);
        }
    }

    function teardown() {
        stopRingback();
        stopPolling();
        clearTimeout(state.ringTimer); state.ringTimer = null;
        clearTimeout(state.dropTimer); state.dropTimer = null;
        clearInterval(state.tickTimer); state.tickTimer = null;
        if (state.channel) {
            try {
                state.channel.stopListening('.call.answer');
                state.channel.stopListening('.call.ice');
                state.channel.stopListening('.call.rejected');
                state.channel.stopListening('.call.end');
            } catch (e) {}
        }
        if (state.pc) { try { state.pc.close(); } catch (e) {} }
        if (state.localStream) { state.localStream.getTracks().forEach(t => t.stop()); }
        if (state.remoteAudio) { try { state.remoteAudio.remove(); } catch (e) {} }
        state.pc = null; state.localStream = null; state.remoteAudio = null;
        state.channel = null; state.call = null; state.startedAt = null; state.muted = false;
        state.pendingIce = [];
        hideOverlay();
    }

    window.CloodCall = {
        start: start,
        hangup: hangup,
        active: function () { return !!state.call; },
    };
})();
