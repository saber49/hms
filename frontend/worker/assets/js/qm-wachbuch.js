/* ============================================
   Clood-X — Wachbuch + Dienstanweisungen (QM module), shared UI
   ONE source for the worker app (base …/worker-app/qm) and the
   Objekt-Terminal (base …/qm-terminal) — both APIs share these sub-paths:
     GET  /wachbuch · POST /wachbuch/eintraege · POST /wachbuch/eintraege/{id}/fotos
     GET  /wachbuch/fotos/{id} · POST /wachbuch/hinweise/{id}/umgesetzt
     GET  /dienstanweisungen · GET /dienstanweisungen/{id}/link · POST …/bestaetigen
   Offline is mandatory (spec §11): entries and photos go into an IndexedDB
   queue and are sent automatically; the device time is kept (nachgereicht).
   ============================================ */
(function () {
    'use strict';

    const ARTEN_BUTTONS = [
        ['notiz', 'Notiz', 'fa-pen'], ['vorkommnis', 'Vorkommnis', 'fa-triangle-exclamation'], ['alarm', 'Alarm', 'fa-bell'],
        ['besucher', 'Besucher', 'fa-id-badge'], ['lieferung', 'Lieferung', 'fa-truck'], ['schluessel', 'Schlüssel', 'fa-key'], ['foto', 'Foto', 'fa-camera'],
    ];
    const ICON = { dienstbeginn: 'fa-right-to-bracket', dienstende: 'fa-right-from-bracket', notiz: 'fa-pen', vorkommnis: 'fa-triangle-exclamation', alarm: 'fa-bell',
        besucher: 'fa-id-badge', lieferung: 'fa-truck', schluessel: 'fa-key', foto: 'fa-camera', uebergabe: 'fa-right-left', hinweis: 'fa-bullhorn', berichtigung: 'fa-eraser' };
    const FREIGABE = { objektleitung: 'Objektleitung', auftraggeber: 'Auftraggeber', haustechnik: 'Haustechnik', empfang: 'Empfang', liste_kunde: 'Liste des Kunden', ohne: 'ohne' };

    let cfg = null;           // { base, headers(json), onAuthLost(), label }
    let state = { data: null, view: 'wachbuch', das: null, root: null };

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const zeit = ts => { const d = new Date(ts); return isNaN(d) ? '' : d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }); };
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2));
    const toast = (m, t) => (typeof window.showToast === 'function' ? window.showToast(m, t) : alert(m));

    async function req(method, path, body, isForm) {
        const h = cfg.headers(!isForm && method !== 'GET');
        try {
            const res = await fetch(cfg.base + path, { method, headers: h, body: method === 'GET' ? undefined : (isForm ? body : JSON.stringify(body || {})) });
            if (res.status === 401) { cfg.onAuthLost && cfg.onAuthLost(); return { ok: false, status: 401, data: {} }; }
            const data = await res.json().catch(() => ({}));
            return { ok: res.ok, status: res.status, data };
        } catch (e) {
            return { ok: false, status: 0, data: { error: 'offline' } };   // status 0 = no network
        }
    }

    // ---------------------------------------------------------------
    // Offline queue (IndexedDB) — entries first, then their photos
    // ---------------------------------------------------------------
    const DB = { db: null };
    function idb() {
        if (DB.db) return Promise.resolve(DB.db);
        return new Promise((res, rej) => {
            const r = indexedDB.open('cx-qm', 1);
            r.onupgradeneeded = () => r.result.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
            r.onsuccess = () => { DB.db = r.result; res(DB.db); };
            r.onerror = () => rej(r.error);
        });
    }
    async function qAdd(item) { const db = await idb(); return new Promise(r => { const tx = db.transaction('queue', 'readwrite'); tx.objectStore('queue').add({ ...item, base: cfg.base }); tx.oncomplete = r; }); }
    async function qAll() { const db = await idb(); return new Promise(r => { const q = db.transaction('queue').objectStore('queue').getAll(); q.onsuccess = () => r((q.result || []).filter(x => x.base === cfg.base)); }); }
    async function qDel(id) { const db = await idb(); return new Promise(r => { const tx = db.transaction('queue', 'readwrite'); tx.objectStore('queue').delete(id); tx.oncomplete = r; }); }
    async function qCount() { try { return (await qAll()).length; } catch (e) { return 0; } }
    const uuidMap = () => { try { return JSON.parse(localStorage.getItem('cx_qm_uuid') || '{}'); } catch (e) { return {}; } };
    const uuidSet = (u, id) => { const m = uuidMap(); m[u] = id; localStorage.setItem('cx_qm_uuid', JSON.stringify(m)); };

    let flushing = false;
    async function flush() {
        if (flushing || !cfg) return;
        flushing = true;
        try {
            const items = (await qAll()).sort((a, b) => (a.kind === 'foto') - (b.kind === 'foto') || a.id - b.id);
            for (const it of items) {
                if (it.kind === 'eintrag') {
                    const r = await req('POST', '/wachbuch/eintraege', it.body);
                    if (r.status === 0) break;                               // still offline
                    if (r.ok && r.data.eintrag) uuidSet(it.body.client_uuid, r.data.eintrag.id);
                    await qDel(it.id);                                       // sent, duplicate or permanent rejection
                } else {
                    const id = uuidMap()[it.eintrag_uuid];
                    if (!id) { if (Date.now() - it.t > 864e5) await qDel(it.id); continue; }
                    const r = await sendFoto(id, it.blob, it.meta);
                    if (r.status === 0) break;
                    await qDel(it.id);
                }
            }
        } catch (e) { /* storage unavailable — retry later */ }
        finally { flushing = false; }
        if (state.root && state.view === 'wachbuch') render();
    }
    window.addEventListener('online', () => flush());

    // ---------------------------------------------------------------
    // Photos: camera → GPS (max 5 s) → scaled to 1600 px
    // ---------------------------------------------------------------
    function gps() {
        return new Promise(res => {
            if (!navigator.geolocation) return res(null);
            const t = setTimeout(() => res(null), 5000);
            navigator.geolocation.getCurrentPosition(p => { clearTimeout(t); res({ lat: p.coords.latitude, lng: p.coords.longitude, genauigkeit_m: Math.round(p.coords.accuracy) }); },
                () => { clearTimeout(t); res(null); }, { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 });
        });
    }
    async function verkleinern(file) {
        try {
            const bmp = await createImageBitmap(file);
            const f = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
            const c = document.createElement('canvas');
            c.width = Math.round(bmp.width * f); c.height = Math.round(bmp.height * f);
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            return await new Promise(r => c.toBlob(b => r(b || file), 'image/jpeg', 0.85));
        } catch (e) { return file; }
    }
    function sendFoto(eintragId, blob, meta) {
        const fd = new FormData();
        fd.append('foto', blob, 'foto.jpg');
        Object.entries(meta || {}).forEach(([k, v]) => { if (v != null) fd.append(k, v); });
        return req('POST', '/wachbuch/eintraege/' + eintragId + '/fotos', fd, true);
    }

    // ---------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------
    async function mount(root, options) {
        cfg = options;
        state.root = root;
        state.view = options.view || state.view;
        root.innerHTML = '<div class="wqm-loading"><i class="fas fa-spinner fa-spin"></i></div>';
        flush();
        if (state.view === 'dienstanweisungen') return renderDa();
        const r = await req('GET', '/wachbuch');
        if (r.status === 0 && state.data) return render(true);
        state.data = r.ok ? r.data : null;
        render(r.status === 0);
    }

    function tabs() {
        const offen = (state.das || []).filter(d => !d.bestaetigt_am).length;
        return `<div class="wqm-tabs">
            <button class="${state.view === 'wachbuch' ? 'on' : ''}" onclick="WQm.zeige('wachbuch')"><i class="fas fa-book"></i> ${esc(cfg.label || 'Wachbuch')}</button>
            <button class="${state.view === 'dienstanweisungen' ? 'on' : ''}" onclick="WQm.zeige('dienstanweisungen')"><i class="fas fa-file-shield"></i> Dienstanweisungen${offen ? ` <span class="wqm-badge">${offen}</span>` : ''}</button>
        </div>`;
    }

    async function render(offline = false) {
        const d = state.data;
        const pending = await qCount();
        if (cfg.label == null && d?.label) cfg.label = d.label;
        if (!d) { state.root.innerHTML = tabs() + `<div class="wqm-empty"><i class="fas fa-wifi"></i><p>Keine Verbindung. Einträge werden gespeichert, sobald das Netz zurück ist.</p></div>`; return; }
        if (!d.aktiv) {
            state.root.innerHTML = tabs() + `<div class="wqm-empty"><i class="fas fa-book"></i><p>Kein laufender Dienst an einem Objekt mit ${esc(d.label || 'Wachbuch')}.</p>
                <p class="wqm-muted">${esc(cfg.hinweisOhneDienst || 'Nach dem Check-in kannst du hier dein ' + (d.label || 'Wachbuch') + ' führen.')}</p></div>`;
            return;
        }
        const s = d.schicht;
        const offeneHinweise = (d.hinweise || []).filter(h => !h.umgesetzt_am);
        const struck = new Set(d.eintraege.filter(e => e.berichtigt_durch).map(e => e.id));
        state.root.innerHTML = tabs() + `
            <div class="wqm-head">
                <div><strong>${esc(s.objektname)}</strong><div class="wqm-muted">Plan ${esc(s.plan_von)}–${esc(s.plan_bis)} · ${s.status === 'pause' ? 'Pause' : 'im Dienst'}</div></div>
                ${pending || offline ? `<span class="wqm-pending"><i class="fas fa-cloud-arrow-up"></i> ${pending} wartet auf Netz</span>` : ''}
            </div>
            ${offeneHinweise.map(h => `<div class="wqm-hinweis"><i class="fas fa-bullhorn"></i><div><strong>Hinweis vom Kunden · ${zeit(h.zeit)}</strong><div>${esc(h.text)}</div></div>
                <button onclick="WQm.umgesetzt(${h.id})">Umgesetzt</button></div>`).join('')}
            <div class="wqm-grid">${ARTEN_BUTTONS.map(([a, l, i]) => `<button class="wqm-art wqm-art-${a}" onclick="WQm.neu('${a}')"><i class="fas ${i}"></i><span>${l}</span></button>`).join('')}</div>
            ${cfg.uebergabe ? `<label class="wqm-label">Hinweis an die Folgeschicht (wird beim Check-out übergeben)</label>
                <textarea class="wqm-input" rows="2" maxlength="2000" oninput="localStorage.setItem('cx_qm_uebergabe', this.value)">${esc(localStorage.getItem('cx_qm_uebergabe') || '')}</textarea>` : ''}
            <div class="wqm-list">${d.eintraege.slice().reverse().map(e => `
                <div class="wqm-e wqm-e-${esc(e.art)}${struck.has(e.id) ? ' wqm-struck' : ''}">
                    <div class="wqm-e-time">${zeit(e.zeit)}<small>#${e.nr}</small></div>
                    <div class="wqm-e-body">
                        <div class="wqm-e-head"><i class="fas ${ICON[e.art] || 'fa-pen'}"></i> <strong>${esc(e.art_label)}</strong>${e.ort ? ' · ' + esc(e.ort) : ''}
                            ${e.nachgereicht ? '<span class="wqm-chip">nachgereicht</span>' : ''}${e.ref_id ? '<span class="wqm-chip">Berichtigung</span>' : ''}</div>
                        ${e.text ? `<div class="wqm-e-text">${esc(e.text)}</div>` : ''}
                        ${e.fotos.length ? `<div class="wqm-e-fotos">${e.fotos.map(f => `<img data-wqm-foto="${f.id}" alt="">`).join('')}</div>` : ''}
                    </div>
                    ${!e.auto && e.art !== 'berichtigung' && !struck.has(e.id) && e.dienst_nr ? `<button class="wqm-e-fix" title="Berichtigen" onclick="WQm.neu('berichtigung', ${e.id})"><i class="fas fa-eraser"></i></button>` : ''}
                </div>`).join('')}</div>`;
        state.root.querySelectorAll('img[data-wqm-foto]').forEach(async img => {
            try {
                const res = await fetch(cfg.base + '/wachbuch/fotos/' + img.dataset.wqmFoto, { headers: cfg.headers(false) });
                if (res.ok) img.src = URL.createObjectURL(await res.blob());
            } catch (e) { /* offline */ }
        });
    }

    // ---------------------------------------------------------------
    // Entry sheet
    // ---------------------------------------------------------------
    function feld(id, label, attrs = '') { return `<label class="wqm-label">${label}</label><input id="${id}" class="wqm-input" ${attrs}>`; }
    function janein(id, label) { return `<label class="wqm-check"><input type="checkbox" id="${id}"> ${label}</label>`; }

    function neu(art, refId = null) {
        const d = state.data;
        const fotoPflicht = d?.fotopflicht && art === 'vorkommnis';
        const ref = refId ? d.eintraege.find(e => e.id === refId) : null;
        const spezifisch = {
            besucher: feld('wq_name', 'Name *') + feld('wq_firma', 'Firma') + feld('wq_ziel', 'Ziel / Ansprechpartner *') + feld('wq_kennz', 'Kennzeichen', 'maxlength="20"')
                + janein('wq_angemeldet', 'angemeldet') + janein('wq_ausweis', 'Ausweis geprüft'),
            lieferung: feld('wq_firma', 'Firma *') + feld('wq_person', 'Person') + feld('wq_kennz', 'Kennzeichen', 'maxlength="20"') + feld('wq_zweck', 'Zweck')
                + `<label class="wqm-label">Freigabe durch</label><select id="wq_freigabe" class="wqm-input">${Object.entries(FREIGABE).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>`
                + janein('wq_ausweis', 'Ausweis geprüft'),
            schluessel: feld('wq_schluessel', 'Schlüssel *') + `<label class="wqm-label">Vorgang</label><select id="wq_aktion" class="wqm-input"><option value="ausgabe">Ausgabe</option><option value="ruecknahme">Rücknahme</option><option value="bestand">Bestand geprüft</option></select>`
                + feld('wq_an', 'An / von'),
            vorkommnis: `<label class="wqm-label">Maßnahmen</label><textarea id="wq_mass" class="wqm-input" rows="2"></textarea>`
                + `<label class="wqm-label">Benachrichtigt (Stelle + Uhrzeit)</label><div class="wqm-row"><input id="wq_stelle" class="wqm-input" placeholder="z. B. Polizei, Objektleitung"><input id="wq_bzeit" class="wqm-input wqm-time" placeholder="HH:MM" maxlength="5"></div>`,
        };
        spezifisch.alarm = spezifisch.vorkommnis;
        const titel = ref ? 'Berichtigung zu #' + ref.nr : (ARTEN_BUTTONS.find(a => a[0] === art) || [, art])[1];
        document.getElementById('wqmSheet')?.remove();
        document.body.insertAdjacentHTML('beforeend', `
            <div class="wqm-sheet-overlay" id="wqmSheet" onclick="if(event.target===this)WQm.zu()"><div class="wqm-sheet">
                <div class="wqm-sheet-title">${esc(titel)}<button onclick="WQm.zu()" aria-label="Schließen">&times;</button></div>
                ${ref ? `<div class="wqm-muted">Der ursprüngliche Eintrag bleibt durchgestrichen sichtbar: „${esc((ref.text || ref.art_label).slice(0, 120))}“</div>` : feld('wq_ort', 'Ort', 'maxlength="200"')}
                ${spezifisch[art] || ''}
                <label class="wqm-label">${ref ? 'Richtigstellung *' : (['notiz', 'vorkommnis', 'alarm'].includes(art) ? 'Text *' : 'Bemerkung')}</label>
                <textarea id="wq_text" class="wqm-input" rows="4" maxlength="4000"></textarea>
                ${ref ? '' : `<label class="wqm-label">Foto${fotoPflicht || art === 'foto' ? ' *' : ''}</label><input id="wq_foto" type="file" accept="image/*" capture="environment" class="wqm-input">`}
                <div class="wqm-err" id="wq_err"></div>
                <button class="wqm-primary" id="wq_save" onclick="WQm.speichern('${art}', ${refId || 'null'}, ${fotoPflicht || art === 'foto'})">Speichern</button>
            </div></div>`);
        if (art === 'foto') setTimeout(() => document.getElementById('wq_foto')?.click(), 100);
    }

    function wert(id) { const el = document.getElementById(id); return el ? (el.type === 'checkbox' ? el.checked : el.value.trim()) : undefined; }

    async function speichern(art, refId, fotoNoetig) {
        const err = m => { document.getElementById('wq_err').textContent = m; };
        const file = document.getElementById('wq_foto')?.files?.[0];
        if (fotoNoetig && !file) return err('Bitte ein Foto aufnehmen.');
        const details = {
            besucher: { name: wert('wq_name'), firma: wert('wq_firma'), ziel: wert('wq_ziel'), kennzeichen: wert('wq_kennz'), angemeldet: wert('wq_angemeldet'), ausweis_geprueft: wert('wq_ausweis') },
            lieferung: { firma: wert('wq_firma'), person: wert('wq_person'), kennzeichen: wert('wq_kennz'), zweck: wert('wq_zweck'), freigabe_durch: wert('wq_freigabe'), ausweis_geprueft: wert('wq_ausweis') },
            schluessel: { schluessel: wert('wq_schluessel'), aktion: wert('wq_aktion'), an: wert('wq_an') },
            vorkommnis: { massnahmen: wert('wq_mass'), benachrichtigt: wert('wq_stelle') ? [{ stelle: wert('wq_stelle'), zeit: wert('wq_bzeit') || null }] : [] },
        };
        details.alarm = details.vorkommnis;
        const text = wert('wq_text');
        if (art === 'besucher' && (!details.besucher.name || !details.besucher.ziel)) return err('Name und Ziel / Ansprechpartner sind Pflicht.');
        if (art === 'lieferung' && !details.lieferung.firma) return err('Firma ist Pflicht.');
        if (art === 'schluessel' && !details.schluessel.schluessel) return err('Schlüssel ist Pflicht.');
        if ((['notiz', 'vorkommnis', 'alarm'].includes(art) || refId) && !text) return err('Bitte einen Text eingeben.');
        const body = {
            art: refId ? 'berichtigung' : (art === 'foto' ? 'foto' : art), text, ort: wert('wq_ort'), ref_id: refId,
            details: details[art] || {}, client_uuid: uuid(), erfasst_am: new Date().toISOString(),
            einsatz_id: state.data?.schicht?.einsatz_id,
        };
        const btn = document.getElementById('wq_save'); btn.disabled = true;
        const meta = file ? await gps() : null;
        const blob = file ? await verkleinern(file) : null;
        const r = await req('POST', '/wachbuch/eintraege', body);
        if (r.status === 0) {
            await qAdd({ kind: 'eintrag', body });
            if (blob) await qAdd({ kind: 'foto', eintrag_uuid: body.client_uuid, blob, meta: { ...meta, erfasst_am: body.erfasst_am }, t: Date.now() });
            zu(); toast('Offline gespeichert — wird automatisch gesendet');
            state.data.eintraege.push({ id: -Date.now(), nr: '…', art: body.art, art_label: (ARTEN_BUTTONS.find(a => a[0] === art) || [, art])[1], zeit: body.erfasst_am,
                text, ort: body.ort, fotos: [], nachgereicht: true, auto: false });
            return render(true);
        }
        btn.disabled = false;
        if (!r.ok) return err(r.data.error || Object.values(r.data.errors || {}).flat()[0] || 'Speichern fehlgeschlagen');
        if (blob) {
            const f = await sendFoto(r.data.eintrag.id, blob, meta);
            if (f.status === 0) { uuidSet(body.client_uuid, r.data.eintrag.id); await qAdd({ kind: 'foto', eintrag_uuid: body.client_uuid, blob, meta, t: Date.now() }); }
            else if (!f.ok) toast(f.data.error || 'Foto konnte nicht hochgeladen werden', 'error');
        }
        zu(); toast((art === 'alarm' ? 'Alarm gemeldet — die Objektleitung ist informiert' : 'Gespeichert'));
        mount(state.root, cfg);
    }

    function zu() { document.getElementById('wqmSheet')?.remove(); }

    async function umgesetzt(id) {
        const r = await req('POST', '/wachbuch/hinweise/' + id + '/umgesetzt');
        if (!r.ok) return toast(r.data.error || 'Keine Verbindung', 'error');
        toast('Als umgesetzt vermerkt');
        mount(state.root, cfg);
    }

    // ---------------------------------------------------------------
    // Dienstanweisungen (spec §6.7)
    // ---------------------------------------------------------------
    async function ladeDa() {
        const r = await req('GET', '/dienstanweisungen');
        if (r.ok) state.das = r.data.dienstanweisungen || [];
        return r;
    }
    async function renderDa(fokusId) {
        const r = await ladeDa();
        if (!r.ok) { state.root.innerHTML = tabs() + `<div class="wqm-empty"><i class="fas fa-wifi"></i><p>Dienstanweisungen konnten nicht geladen werden.</p></div>`; return; }
        const das = state.das;
        state.root.innerHTML = tabs() + (das.length ? das.map(d => `
            <div class="wqm-da${fokusId === d.id ? ' wqm-fokus' : ''}" id="wqmDa_${d.id}">
                <div class="wqm-da-head"><div><strong>${esc(d.objektname)}</strong><div class="wqm-muted">Version ${d.version} · gültig ab ${esc(d.gueltig_ab.split('-').reverse().join('.'))}</div></div>
                    ${d.bestaetigt_am ? '<span class="wqm-chip wqm-ok"><i class="fas fa-check"></i> bestätigt</span>' : '<span class="wqm-chip wqm-warn">offen</span>'}</div>
                <div class="wqm-label">Das Wichtigste</div>
                <ul class="wqm-kurz">${d.kurz.map(k => `<li>${esc(k)}</li>`).join('')}</ul>
                <button class="wqm-secondary" onclick="WQm.pdf(${d.id})"><i class="fas fa-file-pdf"></i> Vollständige Dienstanweisung öffnen (${d.seiten} Seite${d.seiten === 1 ? '' : 'n'})</button>
                ${d.bestaetigt_am ? '' : `<button class="wqm-primary" onclick="WQm.bestaetigen(${d.id})" ${d.pdf_geoeffnet_am ? '' : 'disabled'} id="wqmDaOk_${d.id}">Gelesen und verstanden</button>
                    ${d.pdf_geoeffnet_am ? '' : '<div class="wqm-muted">Erst die vollständige Dienstanweisung öffnen.</div>'}`}
            </div>`).join('') : `<div class="wqm-empty"><i class="fas fa-file-shield"></i><p>Für deine Objekte liegt keine Dienstanweisung vor.</p></div>`);
        if (fokusId) document.getElementById('wqmDa_' + fokusId)?.scrollIntoView({ block: 'center' });
    }
    async function pdf(id) {
        const r = await req('GET', '/dienstanweisungen/' + id + '/link');
        if (!r.ok || !r.data.url) return toast(r.data.error || 'PDF konnte nicht geöffnet werden', 'error');
        if (typeof window.openDocument === 'function') window.openDocument(r.data.url, 'Dienstanweisung');
        else window.open(r.data.url, '_blank');
        // The server marks the PDF as opened when the signed link is fetched.
        setTimeout(() => { const b = document.getElementById('wqmDaOk_' + id); if (b) b.disabled = false; }, 1500);
    }
    async function bestaetigen(id) {
        const r = await req('POST', '/dienstanweisungen/' + id + '/bestaetigen');
        if (!r.ok) return toast(r.data.error || 'Keine Verbindung', 'error');
        toast('Bestätigt');
        cfg.nachBestaetigung ? cfg.nachBestaetigung(id) : renderDa();
    }

    window.WQm = {
        mount, flush, neu, speichern, zu, umgesetzt, pdf, bestaetigen, ladeDa,
        konfig(o) { cfg = o; },
        /** Live update (Reverb): re-fetch the open view without spinner; never while a form is open. */
        async refresh() {
            if (!cfg || !state.root || !document.body.contains(state.root) || document.getElementById('wqmSheet')) return;
            if (state.view === 'dienstanweisungen') return renderDa();
            const r = await req('GET', '/wachbuch');
            if (r.ok) { state.data = r.data; render(); }
        },
        zeige(v, fokusId) { state.view = v; return v === 'dienstanweisungen' ? (state.root.innerHTML = '<div class="wqm-loading"><i class="fas fa-spinner fa-spin"></i></div>', renderDa(fokusId)) : mount(state.root, cfg); },
        offeneDa: async () => { const r = await ladeDa(); return r.ok ? state.das.filter(d => !d.bestaetigt_am).length : 0; },
    };
})();
