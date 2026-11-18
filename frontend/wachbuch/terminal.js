/* ============================================
   Objekt-Terminal (QM module, spec §5)
   1. Objekt login: Kennung + 6-digit PIN (12 h session, only this Objekt)
   2. "Wer bist du?": only workers planned here today + personal 4-digit PIN
      (start PIN → own PIN on first use)
   3. Dienstanweisung gate → Check-in → Wachbuch (shared WQm module) → Check-out
   ============================================ */
const T_API = window.location.origin + '/api/qm-terminal';
const T = { status: null };

const tEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const tToken = () => localStorage.getItem('qmt_token');
function tHeaders(json) { const h = { Accept: 'application/json', Authorization: 'Bearer ' + tToken() }; if (json) h['Content-Type'] = 'application/json'; return h; }
window.showToast = function (msg, type) {
    const t = document.createElement('div');
    t.className = 't-toast' + (type === 'error' ? ' t-toast-err' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
};

async function tReq(method, path, body) {
    try {
        const res = await fetch(T_API + path, { method, headers: tHeaders(method !== 'GET'), body: method === 'GET' ? undefined : JSON.stringify(body || {}) });
        const data = await res.json().catch(() => ({}));
        if (res.status === 401 && data.abgemeldet !== false && path !== '/wachperson') { localStorage.removeItem('qmt_token'); tLogin(data.error); return { ok: false, data }; }
        return { ok: res.ok, status: res.status, data };
    } catch (e) { return { ok: false, status: 0, data: { error: 'Keine Verbindung' } }; }
}

// ---------- 1. Objekt login ----------
function tLogin(msg = '') {
    document.getElementById('tObjekt').textContent = 'Objekt-Terminal';
    document.getElementById('tSub').textContent = '';
    document.getElementById('tTopActions').innerHTML = '';
    document.getElementById('tMain').innerHTML = `
        <form class="t-card t-login" onsubmit="tLoginSenden(event)">
            <h1><i class="fas fa-book"></i> Wachbuch</h1>
            <p class="wqm-muted">Anmeldung des Geräts am Objekt</p>
            <label class="wqm-label">Objekt-Kennung</label><input id="tKennung" class="wqm-input" autocomplete="username" autocapitalize="characters" placeholder="z. B. HMS-LAGER-01" required>
            <label class="wqm-label">PIN (6 Ziffern)</label><input id="tPin" class="wqm-input" type="password" inputmode="numeric" maxlength="6" pattern="\\d{6}" autocomplete="current-password" required>
            <div class="wqm-err">${tEsc(msg)}</div>
            <button class="wqm-primary">Anmelden</button>
        </form>`;
}
async function tLoginSenden(e) {
    e.preventDefault();
    const r = await fetch(T_API + '/login', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ kennung: document.getElementById('tKennung').value, pin: document.getElementById('tPin').value }) })
        .then(async x => ({ ok: x.ok, data: await x.json().catch(() => ({})) })).catch(() => ({ ok: false, data: { error: 'Keine Verbindung' } }));
    if (!r.ok) return tLogin(r.data.error || 'Anmeldung fehlgeschlagen');
    localStorage.setItem('qmt_token', r.data.token);
    tStart();
}
async function tLogout() {
    if (!confirm('Gerät vom Objekt abmelden?')) return;
    await tReq('POST', '/logout');
    localStorage.removeItem('qmt_token');
    tLogin();
}

// ---------- 2. Wer bist du? ----------
async function tStart() {
    if (!tToken()) return tLogin();
    const r = await tReq('GET', '/status');
    if (!r.ok) return r.status === 0 ? (document.getElementById('tMain').innerHTML = '<div class="wqm-empty"><i class="fas fa-wifi"></i><p>Keine Verbindung</p><button class="wqm-secondary" onclick="tStart()">Erneut versuchen</button></div>') : null;
    T.status = r.data;
    if (window.CxQmLive && !T.live) { T.live = true; CxQmLive.start({ base: T_API, headers: tHeaders, onChange: () => { if (document.getElementById('wqmSheet')) return; if (T.status?.wachperson && window.WQm) WQm.refresh(); } }); }
    const o = r.data.objekt || {};
    document.getElementById('tObjekt').textContent = o.objektname || r.data.kennung;
    document.getElementById('tSub').textContent = [o.adresse, [o.plz, o.stadt].filter(Boolean).join(' ')].filter(Boolean).join(', ');
    document.getElementById('tTopActions').innerHTML = r.data.wachperson
        ? `<span class="t-person"><i class="fas fa-user-shield"></i> ${tEsc(r.data.wachperson.name)}</span><button class="t-btn" onclick="tWechseln()"><i class="fas fa-user-group"></i> Wachperson wechseln</button>`
        : `<button class="t-btn" onclick="tLogout()"><i class="fas fa-power-off"></i> Gerät abmelden</button>`;
    if (!r.data.wachbuch_aktiv) { document.getElementById('tMain').innerHTML = '<div class="wqm-empty"><i class="fas fa-book"></i><p>Für dieses Objekt ist das Wachbuch nicht aktiv.</p></div>'; return; }
    return r.data.wachperson ? tDienst() : tWerBistDu();
}

function tWerBistDu() {
    const g = T.status.geplant || [];
    document.getElementById('tMain').innerHTML = `<div class="t-card"><h2>Wer bist du?</h2>
        ${T.status.notfallkontakte ? `<details class="wqm-notfall"><summary><i class="fas fa-phone"></i> Notfallkontakte</summary><div>${tEsc(T.status.notfallkontakte).replace(/\n/g, '<br>')}</div></details>` : ''}
        ${g.length ? `<div class="t-people">${g.map(p => `<button class="t-p" onclick="tPinDialog('${tEsc(p.dienst_nr)}', '${tEsc(p.name).replace(/'/g, '&#39;')}')">
            <i class="fas fa-user"></i><strong>${tEsc(p.name)}</strong><span>${tEsc(p.plan_von)}–${tEsc(p.plan_bis)}${p.status ? ' · ' + (p.status === 'beendet' ? 'beendet' : 'im Dienst') : ''}</span></button>`).join('')}</div>`
            : '<p class="wqm-muted">Heute ist für dieses Objekt niemand eingeplant.</p>'}</div>`;
}

function tPinDialog(dn, name, neu = false, fehler = '') {
    document.getElementById('tMain').innerHTML = `<form class="t-card t-login" onsubmit="tPinSenden(event, '${tEsc(dn)}', '${tEsc(name).replace(/'/g, '&#39;')}', ${neu})">
        <h2>${tEsc(name)}</h2>
        <label class="wqm-label">${neu ? 'Start-PIN' : 'Deine PIN'}</label><input id="tP1" class="wqm-input t-pin" type="password" inputmode="numeric" maxlength="4" pattern="\\d{4}" required autofocus>
        ${neu ? `<p class="wqm-muted">Lege jetzt deine eigene 4-stellige PIN fest (keine gleichen Ziffern, keine Folgen wie 1234).</p>
            <label class="wqm-label">Neue PIN</label><input id="tP2" class="wqm-input t-pin" type="password" inputmode="numeric" maxlength="4" pattern="\\d{4}" required>
            <label class="wqm-label">Neue PIN wiederholen</label><input id="tP3" class="wqm-input t-pin" type="password" inputmode="numeric" maxlength="4" pattern="\\d{4}" required>` : ''}
        <div class="wqm-err">${tEsc(fehler)}</div>
        <button class="wqm-primary">Anmelden</button>
        <button type="button" class="wqm-secondary" onclick="tWerBistDu()">Zurück</button></form>`;
}
async function tPinSenden(e, dn, name, neu) {
    e.preventDefault();
    const body = { dienst_nr: dn, pin: document.getElementById('tP1').value };
    if (neu) {
        if (document.getElementById('tP2').value !== document.getElementById('tP3').value) return tPinDialog(dn, name, true, 'Die neuen PINs stimmen nicht überein.');
        body.neue_pin = document.getElementById('tP2').value;
    }
    const r = await tReq('POST', '/wachperson', body);
    if (r.data.neue_pin_noetig) return tPinDialog(dn, name, true, neu ? r.data.error : '');
    if (!r.ok) return tPinDialog(dn, name, false, r.data.error || 'Anmeldung fehlgeschlagen');
    tStart();
}
async function tWechseln() { await tReq('POST', '/wachperson/abmelden'); tStart(); }

// ---------- 3. Dienst ----------
function tWqmCfg(view) {
    return {
        base: T_API, label: T.status.label, view, uebergabe: false, headers: tHeaders,
        onAuthLost: () => tStart(), hinweisOhneDienst: 'Bitte zuerst einchecken.',
        nachBestaetigung: () => tDienst(),
    };
}
async function tDienst() {
    const ich = T.status.geplant.find(p => p.dienst_nr === T.status.wachperson.dienst_nr) || {};
    const imDienst = ich.status === 'aktiv' || ich.status === 'pause';
    const main = document.getElementById('tMain');
    main.innerHTML = `<div class="t-bar">
            ${imDienst ? `<button class="t-big t-out" onclick="tCheckOut()"><i class="fas fa-right-from-bracket"></i> Check-out</button>`
                : (ich.status === 'beendet' ? '<span class="wqm-muted">Dienst beendet</span>' : `<button class="t-big t-in" onclick="tCheckIn()"><i class="fas fa-right-to-bracket"></i> Check-in</button>`)}
        </div><div id="tWqm"></div>`;
    WQm.mount(document.getElementById('tWqm'), tWqmCfg(imDienst ? 'wachbuch' : 'dienstanweisungen'));
}
async function tCheckIn() {
    const r = await tReq('POST', '/check-in');
    if (r.data.da_sperre) { showToast('Check-in noch nicht möglich — bitte zuerst die Dienstanweisung lesen und bestätigen.', 'error'); return WQm.zeige('dienstanweisungen', r.data.da_sperre.id); }
    if (!r.ok) return showToast(r.data.error || 'Check-in fehlgeschlagen', 'error');
    showToast('Dienstbeginn ' + new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }));
    tStart();
}
function tCheckOut() {
    document.getElementById('wqmSheet')?.remove();
    document.body.insertAdjacentHTML('beforeend', `<div class="wqm-sheet-overlay" id="wqmSheet"><div class="wqm-sheet">
        <div class="wqm-sheet-title">Check-out<button onclick="WQm.zu()">&times;</button></div>
        <label class="wqm-label">Hinweis an die Folgeschicht (optional)</label><textarea id="tUebergabe" class="wqm-input" rows="3" maxlength="2000"></textarea>
        <button class="wqm-primary" onclick="tCheckOutSenden()">Dienst beenden</button></div></div>`);
}
async function tCheckOutSenden() {
    const r = await tReq('POST', '/check-out', { uebergabe: document.getElementById('tUebergabe').value.trim() || null });
    if (!r.ok) return showToast(r.data.error || 'Check-out fehlgeschlagen', 'error');
    WQm.zu();
    showToast('Dienstende — du bist abgemeldet');
    tStart();
}

document.addEventListener('DOMContentLoaded', tStart);
