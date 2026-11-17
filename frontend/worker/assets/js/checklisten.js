/* ============================================
   Clood-X — Checklisten (worker side)
   Shift-bound checklists (Kontrollgang, Leistungsnachweis, Wartung …):
   template list of the current Einsatz → fill form (9 field types,
   per-field photos, Mangel notes) → optional GPS + signatures →
   one multipart POST. The office sees the protocol incl. PDF;
   on Wachbuch objects it also lands as an entry in the shift log.
   API: GET/POST /api/worker-app/checklisten (WorkerAppChecklistenController)
   ============================================ */
window.WCl = (function () {
    'use strict';

    const MAX_FOTOS = 6;            // Checklisten::MAX_FOTOS_JE_FELD
    const SIG_MIN_LEN = 60;         // px drawn before a signature counts

    let S = null;
    // { einsatzId, einsatz, fenster, vorlagen, protokolle,
    //   vorlage, answers{fId:{wert,notiz}}, fotos{fId:[{blob,url}]},
    //   pads{ma:pad,kunde:pad}, uuid, startedAt, sending }

    const uuid = () => (crypto.randomUUID ? crypto.randomUUID()
        : Date.now().toString(36) + Math.random().toString(36).slice(2));
    const el = id => document.getElementById(id);
    const toast = (m, t) => showToast(m, t || 'error');

    /* ── Sheet scaffolding (same overlay pattern as the signature sheet) ── */

    function sheet() {
        let ov = el('wclSheet');
        if (!ov) {
            ov = document.createElement('div');
            ov.className = 'modal-overlay';
            ov.id = 'wclSheet';
            ov.innerHTML = '<div class="modal-sheet wcl-sheet"></div>';
            document.body.appendChild(ov);
            ov.addEventListener('click', e => { if (e.target === ov) close(); });
        }
        return ov.firstElementChild;
    }
    function close() {
        const ov = el('wclSheet');
        if (ov) ov.classList.remove('active');
        // A submit still in-flight keeps its state — nilling S here would
        // crash the fetch continuation. open() re-creates S anyway.
        if (!S || !S.sending) S = null;
        return true;
    }
    function head(title) {
        return `<div class="modal-handle"></div>
            <div class="modal-title-row">
                <div class="modal-title">${esc(title)}</div>
                <button type="button" class="modal-close-btn" onclick="WCl.close()" aria-label="Schließen">&times;</button>
            </div>`;
    }

    /* ── Step 1: template list of the shift ─────────────────────────── */

    async function open(einsatzId) {
        S = { einsatzId: String(einsatzId), answers: {}, fotos: {}, pads: {} };
        const sh = sheet();
        sh.innerHTML = head('Checklisten') + '<div class="loading" style="padding:32px"><i class="fas fa-spinner fa-spin"></i></div>';
        requestAnimationFrame(() => sh.parentNode.classList.add('active'));
        const d = await apiGet(API.checklisten + '?einsatz_id=' + encodeURIComponent(S.einsatzId));
        if (!d || d.error) {
            sh.innerHTML = head('Checklisten')
                + `<p class="notif-ask-text">${esc((d && d.error) || 'Keine Verbindung — bitte später erneut versuchen.')}</p>
                   <button type="button" class="modal-btn modal-btn-outline" onclick="WCl.close()">Schließen</button>`;
            return;
        }
        Object.assign(S, { einsatz: d.einsatz || {}, fenster: d.fenster || null,
            vorlagen: d.vorlagen || [], protokolle: d.protokolle || [] });
        renderList();
    }

    function protCount(vid) { return (S.protokolle || []).filter(p => p.vorlage_id === vid).length; }

    function renderList() {
        const sh = sheet();
        delete sh.parentNode.dataset.noSwipe;
        const e = S.einsatz, f = S.fenster;
        const editable = !f || f.editable;   // no window info → let the server decide
        let html = head('Checklisten');
        html += `<p class="notif-ask-text">${esc(e.objektname || '')}${e.datum ? ' · ' + esc(fmtDate(e.datum)) : ''}</p>`;
        if (f && !f.editable) {
            html += `<div class="wcl-hint"><i class="far fa-clock"></i> Ausfüllbar ${f.status === 'vorher' ? 'ab ' + esc((f.von || '').substring(11)) + ' Uhr' : 'war bis ' + esc((f.bis || '').substring(11)) + ' Uhr'}</div>`;
        }
        if (!S.vorlagen.length) {
            html += `<div class="wcl-empty"><i class="fas fa-clipboard-check"></i><p>Keine Checklisten für diese Schicht.</p></div>`;
        } else {
            html += '<div class="wcl-list">' + S.vorlagen.map(v => {
                const done = protCount(v.id);
                return `<div class="wcl-item ${v.pflicht && !done ? 'is-pflicht' : ''}">
                    <div class="wcl-item-info">
                        <div class="wcl-item-name">${esc(v.name)}</div>
                        <div class="wcl-item-sub">${esc(v.kategorie_label || '')}${v.pflicht ? ' · <b class="wcl-pflicht">Pflicht</b>' : ''}${done ? ' · ' + done + '× ausgefüllt' : ''}</div>
                    </div>
                    <button type="button" class="wcl-fill-btn" ${editable ? '' : 'disabled'} onclick="WCl.fill(${v.id})"><i class="fas fa-pen-to-square"></i> ${done ? 'Erneut' : 'Ausfüllen'}</button>
                </div>`;
            }).join('') + '</div>';
        }
        if ((S.protokolle || []).length) {
            html += '<div class="wcl-prot-title">Deine Protokolle dieser Schicht</div><div class="wcl-prot-list">'
                + S.protokolle.map(p => `<div class="wcl-prot">
                        <span>${esc(p.vorlage_name)}</span>
                        <span class="wcl-prot-meta">${p.ergebnis_prozent !== null && p.ergebnis_prozent !== undefined ? Math.round(p.ergebnis_prozent) + ' %' : '–'}${p.maengel ? ' · ' + p.maengel + ' Mängel' : ''} · ${esc(String(p.abgeschlossen_at || '').substring(11, 16))}</span>
                    </div>`).join('') + '</div>';
        }
        sh.innerHTML = html;
        sh.scrollTop = 0;
    }

    /* ── Step 2: fill form ──────────────────────────────────────────── */

    function fill(vorlageId) {
        const v = (S.vorlagen || []).find(x => x.id === vorlageId);
        if (!v) return;
        S.vorlage = v;
        S.answers = {};
        S.fotos = {};
        S.pads = {};
        S.uuid = uuid();
        S.startedAt = new Date().toISOString();
        S.sending = false;
        renderForm();
    }

    function isMangel(f, w) {
        return (f.typ === 'ok_nok' && w === 'nio') || (f.typ === 'bewertung' && typeof w === 'number' && w <= 2);
    }
    function fotoNeeded(f, w) {
        const pol = f.foto || 'nie';
        if (pol === 'nie') return false;
        return true;   // optional / bei_mangel / pflicht all get a camera button
    }
    function fieldHtml(f) {
        if (f.typ === 'abschnitt') return `<div class="wcl-sec">${esc(f.label)}</div>`;
        const a = S.answers[f.id] || {};
        const w = a.wert;
        const mark = f.pflicht ? ' <b class="wcl-req">*</b>' : '';
        const mangel = isMangel(f, w);
        let body = '';
        switch (f.typ) {
            case 'ok_nok':
                body = `<div class="wcl-seg" role="group">
                    <button type="button" class="wcl-seg-btn ${w === 'io' ? 'on ok' : ''}" onclick="WCl.set('${f.id}','io')"><i class="fas fa-check"></i> i. O.</button>
                    <button type="button" class="wcl-seg-btn ${w === 'nio' ? 'on bad' : ''}" onclick="WCl.set('${f.id}','nio')"><i class="fas fa-xmark"></i> n. i. O.</button>
                    <button type="button" class="wcl-seg-btn ${w === 'na' ? 'on' : ''}" onclick="WCl.set('${f.id}','na')">entfällt</button>
                </div>`;
                break;
            case 'check':
                body = `<label class="wcl-check"><input type="checkbox" ${w ? 'checked' : ''} onchange="WCl.set('${f.id}', this.checked ? 1 : 0)"><span class="wcl-check-box"><i class="fas fa-check"></i></span> erledigt</label>`;
                break;
            case 'bewertung':
                body = `<div class="wcl-stars">${[1, 2, 3, 4, 5].map(n =>
                    `<button type="button" class="wcl-star ${w >= n ? 'on' : ''}" onclick="WCl.set('${f.id}',${n})" aria-label="${n} von 5"><i class="fas fa-star"></i></button>`).join('')}</div>`;
                break;
            case 'text':
                body = `<textarea class="modal-input" rows="2" maxlength="5000" placeholder="…" oninput="WCl.set('${f.id}', this.value)">${esc(w || '')}</textarea>`;
                break;
            case 'zahl':
                body = `<div class="wcl-num"><input type="text" inputmode="decimal" class="modal-input" value="${esc(w ?? '')}" oninput="WCl.set('${f.id}', this.value)">${f.einheit ? `<span>${esc(f.einheit)}</span>` : ''}</div>`;
                break;
            case 'auswahl':
                body = `<select class="modal-input" onchange="WCl.set('${f.id}', this.value)"><option value="">— wählen —</option>${(f.optionen || []).map(o =>
                    `<option ${w === o ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`;
                break;
            case 'zeit':
                body = `<input type="text" class="modal-input t24 wcl-time" inputmode="numeric" placeholder="HH:MM" maxlength="5" value="${esc(w || '')}" oninput="WCl.set('${f.id}', this.value)">`;
                break;
            case 'foto':
                body = '';
                break;
        }
        const needNotiz = mangel;
        const notizRows = (f.typ !== 'foto' && (needNotiz || a.notiz !== undefined))
            ? `<input type="text" class="modal-input wcl-notiz" placeholder="${mangel ? 'Mangel beschreiben *' : 'Bemerkung (optional)'}" maxlength="2000" value="${esc(a.notiz || '')}" oninput="WCl.setNotiz('${f.id}', this.value)">`
            : '';
        const fotoRows = fotoNeeded(f)
            ? `<div class="wcl-fotos" id="wclFotos_${f.id}">${fotosHtml(f.id)}</div>
               <button type="button" class="wcl-foto-btn" onclick="document.getElementById('wclFile_${f.id}').click()"><i class="fas fa-camera"></i> Foto${(f.foto === 'pflicht' || (f.foto === 'bei_mangel' && mangel)) ? ' *' : ''}</button>
               <input type="file" accept="image/*" capture="environment" id="wclFile_${f.id}" style="display:none" onchange="WCl.addFoto('${f.id}', this)">`
            : '';
        return `<div class="wcl-field ${mangel ? 'is-mangel' : ''}" data-fid="${f.id}">
            <div class="wcl-field-label">${esc(f.label)}${mark}${f.hinweis ? `<small>${esc(f.hinweis)}</small>` : ''}</div>
            ${body}${notizRows}${fotoRows}
        </div>`;
    }

    function fotosHtml(fid) {
        return (S.fotos[fid] || []).map((p, i) =>
            `<div class="wcl-thumb"><img src="${p.url}" alt=""><button type="button" onclick="WCl.delFoto('${fid}',${i})" aria-label="Foto entfernen">&times;</button></div>`).join('');
    }

    function renderForm() {
        const v = S.vorlage;
        const sh = sheet();
        const needSigK = ['kunde', 'beide'].includes(v.unterschrift);
        const needSigM = ['mitarbeiter', 'beide'].includes(v.unterschrift);
        let html = head(v.name);
        if (v.beschreibung) html += `<p class="notif-ask-text">${esc(v.beschreibung)}</p>`;
        html += (v.felder || []).map(fieldHtml).join('');
        if (needSigK) {
            html += `<div class="wcl-sig-block"><div class="wcl-field-label">Unterschrift Kunde <b class="wcl-req">*</b></div>
                <input type="text" class="modal-input" id="wclKundeName" maxlength="120" placeholder="Name des Kunden" value="${esc(S.kundeName || '')}" oninput="WCl.kundeName(this.value)">
                <div class="wcl-pad-wrap"><canvas id="wclPadKunde" class="wcl-pad"></canvas><span class="wcl-pad-hint">Kunde unterschreibt hier</span></div>
                <button type="button" class="wcl-mini" onclick="WCl.padClear('kunde')"><i class="fas fa-eraser"></i> Löschen</button></div>`;
        }
        if (needSigM) {
            html += `<div class="wcl-sig-block"><div class="wcl-field-label">Deine Unterschrift <b class="wcl-req">*</b></div>
                <div class="wcl-pad-wrap"><canvas id="wclPadMa" class="wcl-pad"></canvas><span class="wcl-pad-hint">Hier unterschreiben</span></div>
                <button type="button" class="wcl-mini" onclick="WCl.padClear('ma')"><i class="fas fa-eraser"></i> Löschen</button></div>`;
        }
        html += `<div class="wcl-err" id="wclErr"></div>
            <div class="wcl-actions">
                <button type="button" class="modal-btn modal-btn-outline" onclick="WCl.renderList()"><i class="fas fa-arrow-left"></i> Zurück</button>
                <button type="button" class="modal-btn modal-btn-primary" id="wclSend" onclick="WCl.submit()"><i class="fas fa-paper-plane"></i> Senden</button>
            </div>`;
        sh.innerHTML = html;
        sh.scrollTop = 0;
        if (needSigK) mkPad('wclPadKunde', 'kunde');
        if (needSigM) mkPad('wclPadMa', 'ma');
        // Dragging belongs to the signature pad: the global swipe-down
        // dismiss is disabled via the data-no-swipe attribute (presence,
        // not value — delete it when no pad is on the form).
        const ov = sh.parentNode;
        if (needSigK || needSigM) ov.dataset.noSwipe = '1'; else delete ov.dataset.noSwipe;
    }

    /* ── Field updates ──────────────────────────────────────────────── */

    function set(fid, wert) {
        const f = (S.vorlage.felder || []).find(x => x.id === fid);
        S.answers[fid] = { ...(S.answers[fid] || {}), wert: wert === '' ? null : wert };
        // n.i.O. / a low rating reveals the notiz input + Mangel photo
        // demand, i.O./entfällt hides it again → rebuild just this field.
        if (f && ['ok_nok', 'bewertung'].includes(f.typ)) {
            const wrap = document.querySelector(`.wcl-field[data-fid="${fid}"]`);
            if (wrap) { const div = document.createElement('div'); div.innerHTML = fieldHtml(f); wrap.replaceWith(div.firstElementChild); }
        }
    }
    function setNotiz(fid, t) {
        S.answers[fid] = { ...(S.answers[fid] || {}), notiz: t };
    }
    function kundeName(v) { S.kundeName = v; }

    /* ── Photos: camera → scaled JPEG blob → thumb ──────────────────── */

    async function addFoto(fid, input) {
        const file = input.files && input.files[0];
        input.value = '';
        if (!file) return;
        const list = S.fotos[fid] = S.fotos[fid] || [];
        if (list.length >= MAX_FOTOS) { toast('Max. ' + MAX_FOTOS + ' Fotos je Punkt'); return; }
        const blob = await scalePhoto(file);
        list.push({ blob, url: URL.createObjectURL(blob) });
        const box = el('wclFotos_' + fid);
        if (box) box.innerHTML = fotosHtml(fid);
    }
    function delFoto(fid, i) {
        const p = (S.fotos[fid] || [])[i];
        if (p && p.url) URL.revokeObjectURL(p.url);
        (S.fotos[fid] || []).splice(i, 1);
        const box = el('wclFotos_' + fid);
        if (box) box.innerHTML = fotosHtml(fid);
    }
    async function scalePhoto(file) {
        try {
            const bmp = await createImageBitmap(file);
            const k = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
            const c = document.createElement('canvas');
            c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            return await new Promise(r => c.toBlob(b => r(b || file), 'image/jpeg', 0.85));
        } catch (e) { return file; }
    }

    /* ── Signature pads (compact version of the Leistungsnachweis pad) ── */

    function mkPad(canvasId, key) {
        const c = el(canvasId);
        if (!c) return;
        const box = c.getBoundingClientRect();
        const dpr = Math.min(3, window.devicePixelRatio || 1);
        c.width = Math.round(box.width * dpr);
        c.height = Math.round(box.height * dpr);
        const ctx = c.getContext('2d');
        ctx.scale(dpr, dpr);
        ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        ctx.strokeStyle = '#0b1f5c'; ctx.fillStyle = '#0b1f5c';
        const pad = { c, ctx, len: 0, cur: null };
        S.pads[key] = pad;
        const pt = e => { const b = c.getBoundingClientRect(); return { x: e.clientX - b.left, y: e.clientY - b.top }; };
        const hint = () => { const h = c.parentNode.querySelector('.wcl-pad-hint'); if (h) h.style.display = pad.len ? 'none' : ''; };
        c.onpointerdown = e => { e.preventDefault(); try { c.setPointerCapture(e.pointerId); } catch (x) {} pad.cur = pt(e); ctx.beginPath(); ctx.arc(pad.cur.x, pad.cur.y, 1.4, 0, 7); ctx.fill(); pad.len++; };
        c.onpointermove = e => {
            if (!pad.cur) return;
            e.preventDefault();
            const p = pt(e);
            ctx.beginPath(); ctx.moveTo(pad.cur.x, pad.cur.y); ctx.lineTo(p.x, p.y); ctx.stroke();
            pad.len += Math.hypot(p.x - pad.cur.x, p.y - pad.cur.y);
            pad.cur = p; hint();
        };
        c.onpointerup = c.onpointercancel = () => { pad.cur = null; };
    }
    function padClear(key) {
        const pad = S.pads[key];
        if (!pad) return;
        const b = pad.c.getBoundingClientRect();
        pad.ctx.clearRect(0, 0, b.width, b.height);
        pad.len = 0; pad.cur = null;
        const h = pad.c.parentNode.querySelector('.wcl-pad-hint');
        if (h) h.style.display = '';
    }
    function padPng(pad) {
        // Transparent PNG cropped to the ink (max 600×240), same trick as sigPadPng.
        const img = pad.ctx.getImageData(0, 0, pad.c.width, pad.c.height);
        let x0 = img.width, y0 = img.height, x1 = 0, y1 = 0, found = false;
        for (let y = 0; y < img.height; y += 4) for (let x = 0; x < img.width; x += 4) {
            if (img.data[(y * img.width + x) * 4 + 3] > 10) { found = true; if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
        }
        if (!found) return '';
        const padPx = 8 * (window.devicePixelRatio || 1);
        x0 = Math.max(0, x0 - padPx); y0 = Math.max(0, y0 - padPx);
        const w = Math.min(img.width, x1 + padPx) - x0, h = Math.min(img.height, y1 + padPx) - y0;
        const k = Math.min(600 / w, 240 / h, 1);
        const out = document.createElement('canvas');
        out.width = Math.max(60, Math.round(w * k)); out.height = Math.max(30, Math.round(h * k));
        out.getContext('2d').drawImage(pad.c, x0, y0, w, h, 0, 0, out.width, out.height);
        return out.toDataURL('image/png');
    }

    /* ── Validation + submit ────────────────────────────────────────── */

    function validate() {
        const v = S.vorlage;
        for (const f of (v.felder || [])) {
            if (f.typ === 'abschnitt') continue;
            const a = S.answers[f.id] || {};
            const w = a.wert, notiz = (a.notiz || '').trim(), label = '„' + f.label + '“';
            const n = (S.fotos[f.id] || []).length;
            if (f.typ === 'check' && f.pflicht && !w) return label + ' muss erledigt sein';
            if (f.typ === 'ok_nok' && w === 'nio' && !notiz) return label + ': bitte den Mangel beschreiben';
            if (f.typ === 'bewertung' && typeof w === 'number' && w <= 2 && !notiz) return label + ': bitte die Bewertung begründen';
            if (f.pflicht && f.typ !== 'check' && f.typ !== 'foto' && (w === null || w === undefined || w === '')) return label + ' ist ein Pflichtfeld';
            const pol = f.foto || 'nie';
            if (pol === 'pflicht' && n < 1) return label + ': Foto erforderlich';
            if (pol === 'bei_mangel' && n < 1 && isMangel(f, w)) return label + ': bitte den Mangel fotografieren';
        }
        if (['kunde', 'beide'].includes(v.unterschrift)) {
            if (!(S.kundeName || '').trim()) return 'Bitte den Namen des Kunden eintragen';
            if (!S.pads.kunde || S.pads.kunde.len < SIG_MIN_LEN) return 'Unterschrift des Kunden fehlt';
        }
        if (['mitarbeiter', 'beide'].includes(v.unterschrift) && (!S.pads.ma || S.pads.ma.len < SIG_MIN_LEN)) return 'Deine Unterschrift fehlt';
        return null;
    }

    async function submit() {
        if (!S || !S.vorlage || S.sending) return;
        const err = m => { const e = el('wclErr'); if (e) { e.textContent = m; e.scrollIntoView({ block: 'center' }); } };
        const msg = validate();
        if (msg) return err(msg);
        const btn = el('wclSend');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Senden…'; }
        S.sending = true;

        const fd = new FormData();
        fd.append('einsatz_id', S.einsatzId);
        fd.append('vorlage_id', S.vorlage.id);
        fd.append('client_uuid', S.uuid);
        fd.append('gestartet_at', S.startedAt);
        const answers = {};
        Object.keys(S.answers).forEach(fid => { answers[fid] = { wert: S.answers[fid].wert ?? null, notiz: (S.answers[fid].notiz || '').trim() || null }; });
        fd.append('antworten', JSON.stringify(answers));
        if (S.vorlage.gps) {
            const loc = await getGeo();
            if (loc && loc.geo) {
                const [lat, lng] = loc.geo.split(',');
                fd.append('gps_lat', lat); fd.append('gps_lng', lng);
                if (loc.accuracy != null) fd.append('gps_genauigkeit', loc.accuracy);
            }
        }
        if (S.pads.kunde) { const png = padPng(S.pads.kunde); if (png) { fd.append('unterschrift_kunde', png); fd.append('unterschrift_kunde_name', (S.kundeName || '').trim()); } }
        if (S.pads.ma) { const png = padPng(S.pads.ma); if (png) fd.append('unterschrift_ma', png); }
        Object.keys(S.fotos).forEach(fid => (S.fotos[fid] || []).forEach(p => fd.append('fotos[' + fid + '][]', p.blob, 'foto.jpg')));

        let res = null;
        try {
            res = await fetch(API.checklisten, { method: 'POST', headers: wAuthHeaders(), body: fd });
        } catch (e) { res = null; }
        S.sending = false;
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Senden'; }
        if (!res) return err('Keine Verbindung — die Eingaben bleiben, bitte später erneut senden.');
        if (res.status === 401) { wClearToken(); window.location.href = LOGIN_URL; return; }
        const r = await res.json().catch(() => ({}));
        if (!res.ok || !r.success) return err(r.error || 'Senden fehlgeschlagen — bitte erneut versuchen.');
        if (r.duplikat) { showToast('Bereits gesendet'); renderList(); return; }
        showToast('Checkliste gesendet' + (r.ergebnis_prozent !== null && r.ergebnis_prozent !== undefined ? ' — ' + Math.round(r.ergebnis_prozent) + ' %' : ''));
        // Refresh the list so protokolle/badges catch up, then the Zeit screen.
        await open(S.einsatzId);
        if (currentTab === 'zeit' && typeof renderZeit === 'function') renderZeit();
    }

    return { open, close, fill, set, setNotiz, kundeName, addFoto, delFoto, padClear, submit, renderList };
})();
