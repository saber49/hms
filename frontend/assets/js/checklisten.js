/* ============================================================
   Checklisten & Protokolle — office side.
   Protokolle: filled by workers in the app (QM, Leistungsnachweis,
   Wartung, Winterdienst, Kontrollgang) — list, detail, PDF.
   Vorlagen:  template builder, Objekt assignment, industry packs.
   Backend: ChecklistenController (/api/checklisten/…), engine
   App\Support\Checklisten. Read = stammdaten.objekte.view,
   write = stammdaten.objekte.edit.
   ============================================================ */

const CL_API = API_BASE + '/checklisten';
const CL_TYPEN = {
    abschnitt: { label: 'Abschnitt (Überschrift)', icon: 'title' },
    ok_nok:    { label: 'i. O. / n. i. O.',        icon: 'rule' },
    check:     { label: 'Erledigt (Haken)',        icon: 'check_box' },
    bewertung: { label: 'Bewertung 1–5',           icon: 'star' },
    text:      { label: 'Text',                    icon: 'notes' },
    zahl:      { label: 'Zahl / Messwert',         icon: 'pin' },
    auswahl:   { label: 'Auswahl',                 icon: 'list' },
    zeit:      { label: 'Uhrzeit',                 icon: 'schedule' },
    foto:      { label: 'Foto',                    icon: 'photo_camera' },
};
const CL_FOTO = { nie: 'Kein Foto', optional: 'Foto möglich', bei_mangel: 'Foto bei Mangel', pflicht: 'Foto Pflicht' };
const CL_SIG = { keine: 'Keine Unterschrift', kunde: 'Unterschrift Kunde', mitarbeiter: 'Unterschrift Mitarbeiter', beide: 'Kunde + Mitarbeiter' };
const _cl = {
    tab: 'protokolle', data: null, vorlagen: null, filter: { von: '', bis: '', kategorie: '', vorlage_id: '', maengel: false, q: '' },
    edit: null, objekte: null, blobs: [],
};

function clIcon(n, st = '') { return `<span class="material-symbols-outlined"${st ? ` style="${st}"` : ''}>${n}</span>`; }
function clCanEdit() { return typeof hasPermission !== 'function' || hasPermission('stammdaten.objekte.edit'); }
function clTerm(k) { return typeof term === 'function' ? term(k) : ({ kunde: 'Kunde', objekt: 'Objekt', objekte: 'Objekte' }[k] || k); }
function clSigLabel(k) { return k === 'kunde' ? 'Unterschrift ' + clTerm('kunde') : (k === 'beide' ? clTerm('kunde') + ' + Mitarbeiter' : CL_SIG[k]); }
function clDate(d) { return d ? formatDate(String(d).substring(0, 10)) : '–'; }
function clDateTime(ts) {
    if (!ts) return '–';
    const d = new Date(String(ts).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(ts) ? '' : 'Z'));
    return isNaN(d) ? ts : d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function clScoreHtml(p) {
    if (p === null || p === undefined) return '<span class="cl-score cl-score-na">–</span>';
    const cls = p >= 90 ? 'cl-score-ok' : (p < 70 ? 'cl-score-bad' : 'cl-score-mid');
    return `<span class="cl-score ${cls}">${Math.round(p)} %</span>`;
}
function clIsoDay(offset) { const d = new Date(); d.setDate(d.getDate() + offset); return d.toISOString().substring(0, 10); }

async function renderChecklisten() {
    if (!_cl.filter.von) { _cl.filter.von = clIsoDay(-30); _cl.filter.bis = clIsoDay(0); }
    document.getElementById('content').innerHTML = `
        <div class="cl-page">
            <div class="cl-tabs">
                <button data-t="protokolle" onclick="clTab('protokolle')">${clIcon('fact_check')} Protokolle</button>
                <button data-t="vorlagen" onclick="clTab('vorlagen')">${clIcon('checklist')} Vorlagen</button>
            </div>
            <div id="clBody"></div>
        </div>
        ${clStyles()}`;
    if (window.HMSRealtime && !_cl.rt) { HMSRealtime.on('checklisten', () => { if (document.querySelector('.cl-page')) clTab(_cl.tab, true); }); _cl.rt = true; }
    await clTab(_cl.tab);
}

async function clTab(t, quiet) {
    _cl.tab = t;
    document.querySelectorAll('.cl-tabs button').forEach(b => b.classList.toggle('active', b.dataset.t === t));
    const body = document.getElementById('clBody');
    if (!body) return;
    if (t === 'protokolle') { if (!quiet) clRenderProtokolleShell(); await clLoadProtokolle(); }
    else { if (!quiet) body.innerHTML = `<div class="cl-empty">${clIcon('hourglass_top')} Lade…</div>`; await clLoadVorlagen(); }
}

/* ── Protokolle ─────────────────────────────────────────── */

function clRenderProtokolleShell() {
    const f = _cl.filter;
    const kat = (_cl.vorlagen && _cl.vorlagen.kategorien) || {};
    document.getElementById('clBody').innerHTML = `
        <div class="cl-toolbar">
            <label class="cl-fld"><span>Von</span><input type="date" id="clVon" value="${escapeHtml(f.von)}"></label>
            <label class="cl-fld"><span>Bis</span><input type="date" id="clBis" value="${escapeHtml(f.bis)}"></label>
            <label class="cl-fld"><span>Kategorie</span><select id="clKat"><option value="">Alle</option>${Object.entries(kat).map(([k, l]) => `<option value="${k}" ${f.kategorie === k ? 'selected' : ''}>${escapeHtml(l)}</option>`).join('')}</select></label>
            <label class="cl-chk"><input type="checkbox" id="clMaengel" ${f.maengel ? 'checked' : ''}> Nur mit Mängeln</label>
            <div class="cl-search">${clIcon('search')}<input id="clQ" placeholder="${escapeHtml(clTerm('objekt'))}, ${escapeHtml(clTerm('kunde'))}, Mitarbeiter, Vorlage…" value="${escapeHtml(f.q)}"></div>
        </div>
        <div class="cl-kpis" id="clKpis"></div>
        <div class="cl-card" id="clList"><div class="cl-empty">${clIcon('hourglass_top')} Lade…</div></div>`;
    const reload = () => {
        Object.assign(_cl.filter, {
            von: document.getElementById('clVon').value, bis: document.getElementById('clBis').value,
            kategorie: document.getElementById('clKat').value, maengel: document.getElementById('clMaengel').checked,
        });
        clLoadProtokolle();
    };
    ['clVon', 'clBis', 'clKat', 'clMaengel'].forEach(id => document.getElementById(id).addEventListener('change', reload));
    let t = null;
    document.getElementById('clQ').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => { _cl.filter.q = e.target.value.trim(); clLoadProtokolle(); }, 300); });
    if (!_cl.vorlagen) apiGet(CL_API + '/vorlagen').then(d => { if (d && d.kategorien) { _cl.vorlagen = d; if (_cl.tab === 'protokolle' && !document.getElementById('clKat').options.length > 1) clRenderProtokolleShell(); } });
}

async function clLoadProtokolle() {
    const f = _cl.filter;
    const qs = new URLSearchParams({ von: f.von, bis: f.bis });
    if (f.kategorie) qs.set('kategorie', f.kategorie);
    if (f.vorlage_id) qs.set('vorlage_id', f.vorlage_id);
    if (f.maengel) qs.set('maengel', '1');
    if (f.q) qs.set('q', f.q);
    const d = await apiGet(CL_API + '/protokolle?' + qs);
    const list = document.getElementById('clList');
    if (!list) return;
    if (!d || d.error || !Array.isArray(d.protokolle)) {
        list.innerHTML = `<div class="cl-empty">${clIcon('error')} ${escapeHtml((d && d.error) || 'Protokolle konnten nicht geladen werden')}</div>`;
        return;
    }
    _cl.data = d;
    const s = d.summary || {};
    document.getElementById('clKpis').innerHTML = `
        <div class="cl-kpi">${clIcon('fact_check')}<div><b>${s.anzahl || 0}</b><span>Protokolle</span></div></div>
        <div class="cl-kpi">${clIcon('speed')}<div><b>${s.durchschnitt !== null && s.durchschnitt !== undefined ? Math.round(s.durchschnitt) + ' %' : '–'}</b><span>Ø Ergebnis</span></div></div>
        <div class="cl-kpi ${s.maengel ? 'cl-kpi-bad' : ''}">${clIcon('report')}<div><b>${s.maengel || 0}</b><span>Mängel</span></div></div>
        <div class="cl-kpi">${clIcon('assignment_late')}<div><b>${s.mit_maengeln || 0}</b><span>Protokolle mit Mängeln</span></div></div>`;
    if (!d.protokolle.length) {
        list.innerHTML = `<div class="cl-empty">${clIcon('inbox')} Keine Protokolle im Zeitraum.<br><small>Mitarbeiter füllen Checklisten in der App direkt an der Schicht aus. Vorlagen legen Sie im Reiter „Vorlagen“ an.</small></div>`;
        return;
    }
    list.innerHTML = `<div class="cl-table-wrap"><table class="cl-table">
        <thead><tr><th>Datum</th><th>Checkliste</th><th>${escapeHtml(clTerm('objekt'))}</th><th>Mitarbeiter</th><th>Ergebnis</th><th>Mängel</th><th></th><th></th></tr></thead>
        <tbody>${d.protokolle.map(p => `
            <tr onclick="clOpenProtokoll(${p.id})" class="${p.maengel ? 'cl-row-bad' : ''}">
                <td data-l="Datum">${clDate(p.datum)}</td>
                <td data-l="Checkliste"><b>${escapeHtml(p.vorlage_name)}</b><div class="cl-sub">${escapeHtml(p.kategorie_label || '')}</div></td>
                <td data-l="${escapeHtml(clTerm('objekt'))}">${escapeHtml(p.objektname || p.obj_id || '–')}<div class="cl-sub">${escapeHtml(p.kundenname || '')}</div></td>
                <td data-l="Mitarbeiter">${escapeHtml(p.mitarbeiter || '–')}</td>
                <td data-l="Ergebnis">${clScoreHtml(p.ergebnis_prozent)}</td>
                <td data-l="Mängel">${p.maengel ? `<span class="cl-badge-bad">${p.maengel}</span>` : '<span class="cl-sub">0</span>'}</td>
                <td class="cl-icons">${p.fotos ? `<span title="${p.fotos} Foto(s)">${clIcon('photo_camera')}${p.fotos}</span>` : ''}${p.unterschrift ? `<span title="Unterschrieben">${clIcon('draw')}</span>` : ''}${p.gps ? `<span title="Mit GPS">${clIcon('location_on')}</span>` : ''}</td>
                <td class="cl-actions"><button class="cl-ibtn" title="PDF" onclick="event.stopPropagation();clPdf(${p.id})">${clIcon('picture_as_pdf')}</button></td>
            </tr>`).join('')}</tbody></table></div>`;
}

function clPdf(id) { lgOpenSecureCl(CL_API + '/protokolle/' + id + '/pdf', 'Protokoll_' + id + '.pdf'); }

// Bearer-authenticated file open (same approach as lager.js lgOpenSecure).
async function lgOpenSecureCl(url, name) {
    const win = window.open('', '_blank');
    try {
        const res = await fetch(url, { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || '') } });
        if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Datei nicht gefunden');
        const href = URL.createObjectURL(await res.blob());
        if (win) win.location.href = href;
        else { const a = document.createElement('a'); a.href = href; a.download = name || 'datei'; a.click(); }
        setTimeout(() => URL.revokeObjectURL(href), 60000);
    } catch (e) {
        if (win) win.close();
        showToast(e.message || 'Datei konnte nicht geöffnet werden', 'error');
    }
}

async function clBlob(url) {
    try {
        const res = await fetch(url, { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || '') } });
        if (!res.ok) return null;
        const href = URL.createObjectURL(await res.blob());
        _cl.blobs.push(href);
        return href;
    } catch (e) { return null; }
}
function clFreeBlobs() { _cl.blobs.forEach(u => URL.revokeObjectURL(u)); _cl.blobs = []; }

function clAnswerHtml(f, a) {
    const w = a ? a.wert : null;
    switch (f.typ) {
        case 'check': return w ? `<span class="cl-v-ok">${clIcon('check_circle')} erledigt</span>` : `<span class="cl-v-na">${clIcon('radio_button_unchecked')} nicht erledigt</span>`;
        case 'ok_nok': return ({ io: `<span class="cl-v-ok">${clIcon('check_circle')} i. O.</span>`, nio: `<span class="cl-v-bad">${clIcon('cancel')} n. i. O.</span>`, na: `<span class="cl-v-na">entfällt</span>` })[w] || '–';
        case 'bewertung': return Number.isInteger(w) ? `<span class="cl-stars ${w <= 2 ? 'cl-v-bad' : ''}">${'★'.repeat(w)}${'☆'.repeat(5 - w)}</span> ${w}/5` : '–';
        case 'zahl': return w !== null && w !== undefined ? escapeHtml(String(w).replace('.', ',')) + (f.einheit ? ' ' + escapeHtml(f.einheit) : '') : '–';
        case 'zeit': return w ? escapeHtml(w) + ' Uhr' : '–';
        case 'foto': return '';
        default: return w ? `<span class="cl-pre">${escapeHtml(w)}</span>` : '–';
    }
}

async function clOpenProtokoll(id) {
    clFreeBlobs();
    const p = await apiGet(CL_API + '/protokolle/' + id);
    if (!p || p.error) { showToast((p && p.error) || 'Protokoll nicht gefunden', 'error'); return; }
    const rows = (p.felder || []).map(f => {
        if (f.typ === 'abschnitt') return `<div class="cl-d-sec">${escapeHtml(f.label)}</div>`;
        const a = (p.antworten || {})[f.id] || {};
        const mangel = (f.typ === 'ok_nok' && a.wert === 'nio') || (f.typ === 'bewertung' && Number.isInteger(a.wert) && a.wert <= 2);
        const fotos = (p.fotos_je_feld || {})[f.id] || [];
        return `<div class="cl-d-row ${mangel ? 'cl-d-bad' : ''}">
            <div class="cl-d-lbl">${escapeHtml(f.label)}${f.hinweis ? `<small>${escapeHtml(f.hinweis)}</small>` : ''}</div>
            <div class="cl-d-val">${clAnswerHtml(f, a)}
                ${a.notiz ? `<div class="cl-d-notiz">${escapeHtml(a.notiz)}</div>` : ''}
                ${fotos.length ? `<div class="cl-d-fotos">${fotos.map(fid => `<img data-foto="${fid}" alt="Foto" onclick="clZoom(this)">`).join('')}</div>` : ''}
            </div></div>`;
    }).join('');
    const maps = p.gps_lat !== null ? `https://www.google.com/maps/search/?api=1&query=${p.gps_lat},${p.gps_lng}` : null;
    const body = `
        <div class="cl-d">
            <div class="cl-d-head">
                <div><div class="cl-d-kat">${escapeHtml(p.kategorie_label || '')}</div>
                    <div class="cl-d-meta">${clIcon('location_city')} <b>${escapeHtml(p.objektname || '')}</b>${p.adresse ? ' · ' + escapeHtml(p.adresse) : ''}</div>
                    ${p.kundenname ? `<div class="cl-d-meta">${clIcon('person')} ${escapeHtml(clTerm('kunde'))}: ${escapeHtml(p.kundenname)}</div>` : ''}
                    <div class="cl-d-meta">${clIcon('badge')} ${escapeHtml(p.mitarbeiter || '–')} · ${clIcon('event')} ${clDate(p.datum)} · ${p.gestartet_at ? clDateTime(p.gestartet_at) + ' – ' : ''}${clDateTime(p.abgeschlossen_at)}</div>
                    ${maps ? `<div class="cl-d-meta">${clIcon('location_on')} <a href="${maps}" target="_blank" rel="noopener">GPS ${p.gps_lat.toFixed(5)}, ${p.gps_lng.toFixed(5)}</a>${p.gps_genauigkeit ? ' (± ' + p.gps_genauigkeit + ' m)' : ''}</div>` : ''}
                </div>
                <div class="cl-d-score">${clScoreHtml(p.ergebnis_prozent)}<div class="${p.maengel ? 'cl-v-bad' : 'cl-v-ok'}">${p.maengel} ${p.maengel === 1 ? 'Mangel' : 'Mängel'}</div></div>
            </div>
            <div class="cl-d-rows">${rows}</div>
            ${p.unterschrift_ma || p.unterschrift_kunde ? `<div class="cl-d-sigs">
                ${p.unterschrift_ma ? `<div><img data-sig="ma" alt=""><span>Unterschrift Mitarbeiter</span></div>` : ''}
                ${p.unterschrift_kunde ? `<div><img data-sig="kunde" alt=""><span>Unterschrift ${escapeHtml(clTerm('kunde'))}${p.unterschrift_kunde_name ? ' · ' + escapeHtml(p.unterschrift_kunde_name) : ''}</span></div>` : ''}
            </div>` : ''}
            <div class="cl-sub" style="margin-top:10px">Protokoll Nr. ${p.id} · Vorlage Version ${p.vorlage_version}</div>
        </div>`;
    const footer = `
        ${clCanEdit() ? `<button class="btn btn-outline" style="color:#b91c1c;margin-right:auto" onclick="clDeleteProtokoll(${p.id})">Löschen</button>` : ''}
        <button class="btn btn-outline" onclick="closeModal()">Schließen</button>
        <button class="btn btn-primary" onclick="clPdf(${p.id})">PDF</button>`;
    openModal(p.vorlage_name, body + clStyles(), footer, 'modal-xl');
    document.querySelectorAll('.cl-d img[data-foto]').forEach(async img => { const u = await clBlob(CL_API + '/fotos/' + img.dataset.foto); if (u) img.src = u; });
    document.querySelectorAll('.cl-d img[data-sig]').forEach(async img => { const u = await clBlob(CL_API + '/protokolle/' + p.id + '/unterschrift/' + img.dataset.sig); if (u) img.src = u; });
}

function clZoom(img) {
    if (!img.src) return;
    const ov = document.createElement('div');
    ov.className = 'cl-zoom';
    ov.innerHTML = `<img src="${img.src}" alt="">`;
    ov.onclick = () => ov.remove();
    document.body.appendChild(ov);
}

async function clDeleteProtokoll(id) {
    if (!confirm('Protokoll mit allen Fotos und Unterschriften endgültig löschen?')) return;
    const r = await apiDelete(CL_API + '/protokolle/' + id);
    if (!r || r.error) { showToast((r && r.error) || 'Fehler', 'error'); return; }
    closeModal();
    showToast('Protokoll gelöscht');
    clLoadProtokolle();
}

/* ── Vorlagen ───────────────────────────────────────────── */

async function clLoadVorlagen() {
    const d = await apiGet(CL_API + '/vorlagen');
    const body = document.getElementById('clBody');
    if (!body || _cl.tab !== 'vorlagen') return;
    if (!d || d.error || !Array.isArray(d.vorlagen)) {
        body.innerHTML = `<div class="cl-empty">${clIcon('error')} ${escapeHtml((d && d.error) || 'Vorlagen konnten nicht geladen werden')}</div>`;
        return;
    }
    _cl.vorlagen = d;
    const edit = clCanEdit();
    const brMains = (d.bereiche || []).filter(b => b.branche && d.pakete.some(p => p.key === b.branche));
    const installed = new Set(d.vorlagen.map(v => v.vorlage_key).filter(Boolean));
    const pakete = brMains.map(b => {
        const pk = d.pakete.find(p => p.key === b.branche);
        const missing = pk.vorlagen.length - d.vorlagen.filter(v => (v.vorlage_key || '').endsWith('@' + b.id)).length;
        return { b, pk, missing };
    }).filter(x => x.missing > 0);
    const byKat = {};
    d.vorlagen.forEach(v => { (byKat[v.kategorie] = byKat[v.kategorie] || []).push(v); });
    body.innerHTML = `
        <div class="cl-vhead">
            <div class="cl-info">${clIcon('info')}<div>Vorlagen erscheinen beim Mitarbeiter in der App an jeder Schicht, deren ${escapeHtml(clTerm('objekt'))} zur Vorlage passt
                (alle ${escapeHtml(clTerm('objekte'))} des Bereichs oder gezielt zugeordnete). <b>Pflicht</b>-Zuordnungen werden in der App hervorgehoben.
                Änderungen an Feldern gelten für neue Protokolle — bestehende behalten ihren Stand.</div></div>
            ${edit ? `<button class="btn btn-primary" onclick="clEditVorlage(null)">${clIcon('add')} Neue Vorlage</button>` : ''}
        </div>
        ${edit && pakete.length ? `<div class="cl-pakete">${pakete.map(x => `
            <div class="cl-paket">${clIcon('library_add')}<div><b>Branchen-Vorlagen für „${escapeHtml(x.b.name)}“</b><span>${escapeHtml(x.pk.label)}: ${x.pk.vorlagen.map(escapeHtml).join(' · ')}</span></div>
                <button class="btn btn-outline" onclick="clInstallPaket('${escapeHtml(x.pk.key)}', ${x.b.id})">Übernehmen</button></div>`).join('')}</div>` : ''}
        ${d.vorlagen.length ? Object.entries(byKat).map(([k, list]) => `
            <h3 class="cl-h">${escapeHtml(d.kategorien[k] || k)} <span>${list.length}</span></h3>
            <div class="cl-vgrid">${list.map(v => `
                <div class="cl-vcard ${v.aktiv ? '' : 'cl-off'}">
                    <div class="cl-vtop"><b>${escapeHtml(v.name)}</b>
                        ${edit ? `<label class="cl-switch" title="${v.aktiv ? 'Aktiv' : 'Inaktiv'}"><input type="checkbox" ${v.aktiv ? 'checked' : ''} onchange="clToggleVorlage(${v.id}, this.checked)"><span></span></label>` : ''}</div>
                    ${v.beschreibung ? `<div class="cl-vdesc">${escapeHtml(v.beschreibung)}</div>` : ''}
                    <div class="cl-pills">
                        <span class="cl-pill">${clIcon('format_list_bulleted')}${v.felder_anzahl} Felder</span>
                        <span class="cl-pill">${clIcon('category')}${escapeHtml(v.bereich_name || 'Alle Bereiche')}</span>
                        <span class="cl-pill">${clIcon('location_city')}${v.alle_objekte ? 'Alle ' + escapeHtml(clTerm('objekte')) : v.objekte_anzahl + ' ' + escapeHtml(clTerm('objekte'))}${v.alle_objekte && v.objekte_anzahl ? ' + ' + v.objekte_anzahl : ''}</span>
                        ${v.pflicht_anzahl ? `<span class="cl-pill cl-pill-warn">${clIcon('priority_high')}Pflicht bei ${v.pflicht_anzahl}</span>` : ''}
                        ${v.unterschrift !== 'keine' ? `<span class="cl-pill">${clIcon('draw')}${escapeHtml(clSigLabel(v.unterschrift))}</span>` : ''}
                        <span class="cl-pill">${clIcon('fact_check')}${v.protokolle_anzahl} Protokolle</span>
                    </div>
                    <div class="cl-vactions">
                        ${v.protokolle_anzahl ? `<button class="cl-tbtn" onclick="clShowProtokolleOf(${v.id})">${clIcon('visibility')} Protokolle</button>` : ''}
                        ${edit ? `<button class="cl-tbtn" onclick="clEditVorlage(${v.id})">${clIcon('edit')} Bearbeiten</button>
                        <button class="cl-tbtn" onclick="clOpenObjekte(${v.id})">${clIcon('location_city')} ${escapeHtml(clTerm('objekte'))}</button>
                        <button class="cl-tbtn" onclick="clEditVorlage(${v.id}, true)">${clIcon('content_copy')} Kopie</button>
                        ${!v.protokolle_anzahl ? `<button class="cl-tbtn cl-del" onclick="clDeleteVorlage(${v.id})">${clIcon('delete')}</button>` : ''}` : ''}
                    </div>
                </div>`).join('')}</div>`).join('') : `<div class="cl-card"><div class="cl-empty">${clIcon('checklist')} Noch keine Vorlagen.<br><small>Legen Sie eine neue Vorlage an${edit && pakete.length ? ' oder übernehmen Sie die Branchen-Vorlagen oben' : ' — oder wählen Sie unter Konto & Firma → Bereiche eine Branche, dann stehen fertige Vorlagen bereit'}.</small></div></div>`}`;
}

function clShowProtokolleOf(id) { _cl.filter.vorlage_id = String(id); _cl.filter.von = clIsoDay(-365); clTab('protokolle'); setTimeout(() => { _cl.filter.vorlage_id = ''; }, 0); }

async function clInstallPaket(key, bereichId) {
    const r = await apiPost(CL_API + '/vorlagen/paket', { branche: key, bereich_id: bereichId });
    if (!r || r.error) { showToast((r && r.error) || 'Fehler', 'error'); return; }
    showToast(r.angelegt ? r.angelegt + ' Vorlage(n) angelegt' : 'Alle Vorlagen sind bereits vorhanden');
    clLoadVorlagen();
}

async function clToggleVorlage(id, on) {
    const r = await apiPut(CL_API + '/vorlagen/' + id, { aktiv: on });
    if (!r || r.error) showToast((r && r.error) || 'Fehler', 'error');
    clLoadVorlagen();
}

async function clDeleteVorlage(id) {
    if (!confirm('Vorlage löschen?')) return;
    const r = await apiDelete(CL_API + '/vorlagen/' + id);
    if (!r || r.error) { showToast((r && r.error) || 'Fehler', 'error'); return; }
    showToast('Vorlage gelöscht');
    clLoadVorlagen();
}

/* Builder */
function clNewFieldId() { return 'f' + Math.random().toString(36).slice(2, 8); }

async function clEditVorlage(id, copy) {
    let v = { name: '', beschreibung: '', kategorie: 'qualitaet', bereich_id: null, alle_objekte: true, unterschrift: 'keine', gps: true, im_kundenportal: true, aktiv: true,
        felder: [{ id: clNewFieldId(), typ: 'ok_nok', label: '', pflicht: true, foto: 'bei_mangel' }] };
    if (id) {
        const d = await apiGet(CL_API + '/vorlagen/' + id);
        if (!d || d.error) { showToast((d && d.error) || 'Vorlage nicht gefunden', 'error'); return; }
        v = d;
        if (copy) { v = { ...d, name: d.name + ' (Kopie)', felder: d.felder.map(f => ({ ...f })) }; delete v.id; delete v.vorlage_key; }
    }
    _cl.edit = { id: copy ? null : id, v };
    clRenderBuilder();
}

function clRenderBuilder() {
    const { id, v } = _cl.edit;
    const d = _cl.vorlagen || { bereiche: [], kategorien: {} };
    const rows = v.felder.map((f, i) => {
        const t = CL_TYPEN[f.typ] || CL_TYPEN.text;
        const isSec = f.typ === 'abschnitt';
        return `<div class="cl-b-row ${isSec ? 'cl-b-sec' : ''}" data-i="${i}">
            <div class="cl-b-move">
                <button class="cl-ibtn" title="Nach oben" ${i === 0 ? 'disabled' : ''} onclick="clMoveField(${i}, -1)">${clIcon('arrow_upward')}</button>
                <button class="cl-ibtn" title="Nach unten" ${i === v.felder.length - 1 ? 'disabled' : ''} onclick="clMoveField(${i}, 1)">${clIcon('arrow_downward')}</button>
            </div>
            <div class="cl-b-main">
                <div class="cl-b-line">
                    <select onchange="clSetField(${i}, 'typ', this.value)" title="Feldtyp">${Object.entries(CL_TYPEN).map(([k, x]) => `<option value="${k}" ${k === f.typ ? 'selected' : ''}>${x.label}</option>`).join('')}</select>
                    <input class="cl-b-label" value="${escapeHtml(f.label || '')}" placeholder="${isSec ? 'Überschrift, z.B. Sanitärbereiche' : 'Prüfpunkt / Frage'}" oninput="clSetField(${i}, 'label', this.value, true)">
                </div>
                ${isSec ? '' : `<div class="cl-b-line cl-b-opts">
                    <label class="cl-chk"><input type="checkbox" ${f.pflicht ? 'checked' : ''} onchange="clSetField(${i}, 'pflicht', this.checked, true)"> Pflicht</label>
                    <select onchange="clSetField(${i}, 'foto', this.value, true)" title="Foto">${Object.entries(CL_FOTO).filter(([k]) => (k !== 'bei_mangel' || ['ok_nok', 'bewertung'].includes(f.typ)) && (k !== 'nie' || f.typ !== 'foto')).map(([k, l]) => `<option value="${k}" ${k === (f.foto || 'nie') ? 'selected' : ''}>${l}</option>`).join('')}</select>
                    ${f.typ === 'zahl' ? `<input class="cl-b-small" value="${escapeHtml(f.einheit || '')}" placeholder="Einheit (°C, kg …)" oninput="clSetField(${i}, 'einheit', this.value, true)">` : ''}
                    <input class="cl-b-hint" value="${escapeHtml(f.hinweis || '')}" placeholder="Hinweis für den Mitarbeiter (optional)" oninput="clSetField(${i}, 'hinweis', this.value, true)">
                </div>
                ${f.typ === 'auswahl' ? `<div class="cl-b-line"><input class="cl-b-full" value="${escapeHtml((f.optionen || []).join('; '))}" placeholder="Optionen, getrennt mit ; (z.B. Splitt; Salz; Granulat)" oninput="clSetField(${i}, 'optionen', this.value.split(';').map(s => s.trim()).filter(Boolean), true)"></div>` : ''}`}
            </div>
            <button class="cl-ibtn cl-del" title="Feld entfernen" onclick="clRemoveField(${i})">${clIcon('delete')}</button>
        </div>`;
    }).join('');
    const body = `
        <div class="cl-b">
            <div class="cl-b-grid">
                <label class="cl-fld cl-span2"><span>Name *</span><input id="clbName" value="${escapeHtml(v.name)}" maxlength="190" placeholder="z.B. Qualitätskontrolle Büroreinigung"></label>
                <label class="cl-fld"><span>Kategorie</span><select id="clbKat">${Object.entries(d.kategorien).map(([k, l]) => `<option value="${k}" ${k === v.kategorie ? 'selected' : ''}>${escapeHtml(l)}</option>`).join('')}</select></label>
                <label class="cl-fld"><span>Bereich</span><select id="clbBereich"><option value="">Alle Bereiche</option>${(d.bereiche || []).map(b => `<option value="${b.id}" ${b.id === v.bereich_id ? 'selected' : ''}>${escapeHtml(b.name)}</option>`).join('')}</select></label>
                <label class="cl-fld cl-span2"><span>Beschreibung</span><input id="clbDesc" value="${escapeHtml(v.beschreibung || '')}" maxlength="2000" placeholder="Wofür ist die Checkliste? (sieht auch der Mitarbeiter)"></label>
                <label class="cl-fld"><span>Unterschrift</span><select id="clbSig">${Object.keys(CL_SIG).map(k => `<option value="${k}" ${k === v.unterschrift ? 'selected' : ''}>${escapeHtml(clSigLabel(k))}</option>`).join('')}</select></label>
                <div class="cl-fld"><span>Optionen</span>
                    <label class="cl-chk"><input type="checkbox" id="clbAlle" ${v.alle_objekte ? 'checked' : ''}> Für alle ${escapeHtml(clTerm('objekte'))} des Bereichs</label>
                    <label class="cl-chk"><input type="checkbox" id="clbGps" ${v.gps ? 'checked' : ''}> GPS-Standort speichern</label>
                </div>
            </div>
            <div class="cl-b-fhead"><b>Felder</b><span class="cl-sub">„i. O. / n. i. O.“ und „Bewertung“ ergeben das Ergebnis in Prozent; n. i. O. verlangt eine Mangelbeschreibung.</span></div>
            <div id="clbRows">${rows}</div>
            <div class="cl-b-add">${['ok_nok', 'check', 'bewertung', 'text', 'zahl', 'auswahl', 'zeit', 'foto', 'abschnitt'].map(k => `<button class="cl-tbtn" onclick="clAddField('${k}')">${clIcon(CL_TYPEN[k].icon)} ${CL_TYPEN[k].label}</button>`).join('')}</div>
        </div>`;
    const footer = `<button class="btn btn-outline" onclick="closeModal()">Abbrechen</button><button class="btn btn-primary" onclick="clSaveVorlage()">Speichern</button>`;
    const keep = document.getElementById('modalBody') && document.querySelector('.cl-b') ? document.getElementById('modalBody').scrollTop : 0;
    clSyncHead();
    openModal(id ? 'Vorlage bearbeiten' : 'Neue Vorlage', body + clStyles(), footer, 'modal-xl');
    if (keep) document.getElementById('modalBody').scrollTop = keep;
}

// Keep the header inputs when the field list re-renders.
function clSyncHead() {
    if (!_cl.edit || !document.getElementById('clbName')) return;
    const v = _cl.edit.v;
    v.name = document.getElementById('clbName').value;
    v.kategorie = document.getElementById('clbKat').value;
    v.bereich_id = document.getElementById('clbBereich').value ? parseInt(document.getElementById('clbBereich').value, 10) : null;
    v.beschreibung = document.getElementById('clbDesc').value;
    v.unterschrift = document.getElementById('clbSig').value;
    v.alle_objekte = document.getElementById('clbAlle').checked;
    v.gps = document.getElementById('clbGps').checked;
}

function clSetField(i, key, val, noRender) {
    const f = _cl.edit.v.felder[i];
    f[key] = val;
    if (key === 'typ') {
        if (val === 'foto') f.foto = 'pflicht';
        else if (['ok_nok', 'bewertung'].includes(val)) f.foto = f.foto || 'bei_mangel';
        else if (f.foto === 'bei_mangel') f.foto = 'optional';
        if (val === 'auswahl' && !(f.optionen || []).length) f.optionen = [];
    }
    if (!noRender) clRenderBuilder();
}
function clMoveField(i, d) { const a = _cl.edit.v.felder; const j = i + d; if (j < 0 || j >= a.length) return; [a[i], a[j]] = [a[j], a[i]]; clRenderBuilder(); }
function clRemoveField(i) { _cl.edit.v.felder.splice(i, 1); clRenderBuilder(); }
function clAddField(typ) {
    _cl.edit.v.felder.push({ id: clNewFieldId(), typ, label: '', pflicht: typ !== 'abschnitt' && typ !== 'text', foto: typ === 'foto' ? 'pflicht' : (['ok_nok', 'bewertung'].includes(typ) ? 'bei_mangel' : 'nie') });
    clRenderBuilder();
    const rows = document.querySelectorAll('#clbRows .cl-b-label');
    if (rows.length) { rows[rows.length - 1].focus(); rows[rows.length - 1].scrollIntoView({ block: 'center' }); }
}

async function clSaveVorlage() {
    clSyncHead();
    const { id, v } = _cl.edit;
    if (!v.name.trim()) { showToast('Bitte einen Namen eingeben', 'error'); return; }
    const payload = { ...v, felder: v.felder.map(f => ({ ...f, label: (f.label || '').trim() })).filter(f => f.label) };
    const r = id ? await apiPut(CL_API + '/vorlagen/' + id, payload) : await apiPost(CL_API + '/vorlagen', payload);
    if (!r || r.error) { showToast((r && r.error) || 'Fehler beim Speichern', 'error'); return; }
    closeModal();
    showToast('Vorlage gespeichert');
    _cl.edit = null;
    clLoadVorlagen();
}

/* Objekt assignment */
async function clOpenObjekte(id) {
    const v = (_cl.vorlagen.vorlagen || []).find(x => x.id === id);
    const d = await apiGet(CL_API + '/vorlagen/' + id + '/objekte');
    if (!d || d.error) { showToast((d && d.error) || 'Fehler', 'error'); return; }
    _cl.objekte = { id, list: d.objekte, q: '' };
    const body = `
        <div class="cl-o">
            <div class="cl-info">${clIcon('info')}<div>${v && v.alle_objekte ? `Die Vorlage gilt bereits für <b>alle ${escapeHtml(clTerm('objekte'))}${v.bereich_name ? ' im Bereich „' + escapeHtml(v.bereich_name) + '“' : ''}</b>. Hier zugeordnete ${escapeHtml(clTerm('objekte'))} können zusätzlich als <b>Pflicht</b> markiert werden.` : `Die Vorlage erscheint nur bei den hier ausgewählten ${escapeHtml(clTerm('objekte'))}.`}
                Pflicht = der Mitarbeiter sieht die Checkliste in der App als „offen“, bis sie ausgefüllt ist.</div></div>
            <div class="cl-search">${clIcon('search')}<input id="clOq" placeholder="Suchen…" oninput="_cl.objekte.q=this.value.toLowerCase();clRenderObjekte()"></div>
            <div class="cl-o-tools"><button class="cl-tbtn" onclick="clObjAll(true)">Alle sichtbaren wählen</button><button class="cl-tbtn" onclick="clObjAll(false)">Keine</button></div>
            <div id="clOList" class="cl-o-list"></div>
        </div>`;
    openModal((v ? v.name + ' — ' : '') + clTerm('objekte'), body + clStyles(), `<button class="btn btn-outline" onclick="closeModal()">Abbrechen</button><button class="btn btn-primary" onclick="clSaveObjekte()">Speichern</button>`, 'modal-xl');
    clRenderObjekte();
}
function clObjVisible() { const q = _cl.objekte.q; return _cl.objekte.list.filter(o => !q || `${o.objektname} ${o.kundenname} ${o.stadt} ${o.obj_id}`.toLowerCase().includes(q)); }
function clRenderObjekte() {
    const el = document.getElementById('clOList');
    if (!el) return;
    const list = clObjVisible();
    el.innerHTML = list.length ? list.map(o => `
        <div class="cl-o-row ${o.zugeordnet ? 'on' : ''}">
            <label class="cl-chk"><input type="checkbox" ${o.zugeordnet ? 'checked' : ''} onchange="clObjSet('${escapeHtml(o.obj_id)}','zugeordnet',this.checked)"> <span><b>${escapeHtml(o.objektname || o.obj_id)}</b><small>${escapeHtml([o.kundenname, o.stadt, o.bereich].filter(Boolean).join(' · '))}</small></span></label>
            <label class="cl-chk cl-o-pf"><input type="checkbox" ${o.pflicht ? 'checked' : ''} ${o.zugeordnet ? '' : 'disabled'} onchange="clObjSet('${escapeHtml(o.obj_id)}','pflicht',this.checked)"> Pflicht</label>
        </div>`).join('') : `<div class="cl-empty">Keine ${escapeHtml(clTerm('objekte'))} gefunden</div>`;
}
function clObjSet(oid, k, val) {
    const o = _cl.objekte.list.find(x => x.obj_id === oid);
    if (!o) return;
    o[k] = val;
    if (k === 'zugeordnet' && !val) o.pflicht = false;
    clRenderObjekte();
}
function clObjAll(on) { clObjVisible().forEach(o => { o.zugeordnet = on; if (!on) o.pflicht = false; }); clRenderObjekte(); }
async function clSaveObjekte() {
    const objekte = _cl.objekte.list.filter(o => o.zugeordnet).map(o => ({ obj_id: o.obj_id, pflicht: !!o.pflicht }));
    const r = await apiPut(CL_API + '/vorlagen/' + _cl.objekte.id + '/objekte', { objekte });
    if (!r || r.error) { showToast((r && r.error) || 'Fehler', 'error'); return; }
    closeModal();
    showToast(r.anzahl + ' ' + clTerm(r.anzahl === 1 ? 'objekt' : 'objekte') + ' zugeordnet');
    clLoadVorlagen();
}

function clStyles() {
    return `<style>
    .cl-page{padding:4px 0 24px}
    .cl-tabs{display:flex;gap:6px;margin-bottom:14px;border-bottom:1px solid var(--gray-200)}
    .cl-tabs button{display:flex;align-items:center;gap:6px;border:0;background:none;padding:10px 14px;font:inherit;font-weight:600;color:var(--gray-600);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}
    .cl-tabs button.active{color:var(--accent,#1a73e8);border-bottom-color:var(--accent,#1a73e8)}
    .cl-tabs .material-symbols-outlined{font-size:19px}
    .cl-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:12px}
    .cl-fld{display:flex;flex-direction:column;gap:4px;font-size:.78rem;color:var(--gray-600)}
    .cl-fld>span{font-weight:600}
    .cl-fld input,.cl-fld select,.cl-b input,.cl-b select{padding:8px 10px;border:1px solid var(--gray-300);border-radius:8px;font:inherit;font-size:.88rem;background:var(--white);color:inherit}
    .cl-chk{display:inline-flex;align-items:center;gap:6px;font-size:.86rem;cursor:pointer;color:inherit}
    .cl-chk input{accent-color:var(--accent,#1a73e8)}
    .cl-search{display:flex;align-items:center;gap:6px;flex:1;min-width:220px;border:1px solid var(--gray-300);border-radius:8px;padding:0 10px;background:var(--white)}
    .cl-search input{border:0;outline:0;flex:1;padding:8px 0;font:inherit;font-size:.88rem;background:transparent;color:inherit}
    .cl-search .material-symbols-outlined{color:var(--gray-400);font-size:19px}
    .cl-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-bottom:12px}
    .cl-kpi{display:flex;align-items:center;gap:10px;border:1px solid var(--gray-200);border-radius:12px;padding:12px 14px;background:var(--white)}
    .cl-kpi .material-symbols-outlined{font-size:26px;color:var(--accent,#1a73e8)}
    .cl-kpi b{display:block;font-size:1.25rem}
    .cl-kpi span{font-size:.76rem;color:var(--gray-500)}
    .cl-kpi-bad .material-symbols-outlined,.cl-kpi-bad b{color:#b91c1c}
    .cl-card{border:1px solid var(--gray-200);border-radius:12px;background:var(--white);overflow:hidden}
    .cl-empty{padding:34px 16px;text-align:center;color:var(--gray-500)}
    .cl-empty small{display:block;margin-top:6px;color:var(--gray-400)}
    .cl-table-wrap{overflow-x:auto}
    .cl-table{width:100%;border-collapse:collapse;font-size:.87rem}
    .cl-table th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.4px;color:var(--gray-500);padding:10px 12px;border-bottom:1px solid var(--gray-200);background:var(--gray-50)}
    .cl-table td{padding:10px 12px;border-bottom:1px solid var(--gray-100);vertical-align:top}
    .cl-table tbody tr{cursor:pointer}
    .cl-table tbody tr:hover{background:var(--gray-50)}
    .cl-row-bad td:first-child{box-shadow:inset 3px 0 0 #dc2626}
    .cl-sub{font-size:.76rem;color:var(--gray-500)}
    .cl-score{display:inline-block;font-weight:700;border-radius:999px;padding:2px 9px;font-size:.8rem}
    .cl-score-ok{background:#dcfce7;color:#15803d}.cl-score-mid{background:#fef3c7;color:#b45309}.cl-score-bad{background:#fee2e2;color:#b91c1c}.cl-score-na{color:var(--gray-400)}
    .cl-badge-bad{display:inline-block;min-width:22px;text-align:center;background:#fee2e2;color:#b91c1c;font-weight:700;border-radius:999px;padding:1px 7px;font-size:.8rem}
    .cl-icons span{display:inline-flex;align-items:center;gap:1px;color:var(--gray-500);font-size:.75rem;margin-right:6px}
    .cl-icons .material-symbols-outlined{font-size:17px}
    .cl-ibtn{border:0;background:none;cursor:pointer;color:var(--gray-500);padding:4px;border-radius:6px;line-height:1}
    .cl-ibtn:hover{background:var(--gray-100);color:var(--gray-800)}
    .cl-ibtn[disabled]{opacity:.3;cursor:default}
    .cl-ibtn .material-symbols-outlined{font-size:19px}
    .cl-del:hover{color:#b91c1c!important}
    .cl-vhead{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px}
    .cl-vhead .btn{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
    .cl-info{display:flex;gap:10px;flex:1;min-width:260px;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:10px 12px;font-size:.84rem;color:var(--gray-700);line-height:1.45;margin-bottom:10px}
    .cl-info .material-symbols-outlined{color:var(--accent,#1a73e8)}
    .cl-pakete{display:grid;gap:8px;margin-bottom:14px}
    .cl-paket{display:flex;align-items:center;gap:12px;border:1px dashed var(--accent,#1a73e8);border-radius:12px;padding:12px 14px;background:var(--white)}
    .cl-paket>.material-symbols-outlined{color:var(--accent,#1a73e8);font-size:26px}
    .cl-paket>div{flex:1;display:flex;flex-direction:column;font-size:.86rem}
    .cl-paket span{color:var(--gray-500);font-size:.78rem}
    .cl-h{font-size:.8rem;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-600);margin:18px 0 8px}
    .cl-h span{color:var(--gray-400);font-weight:400}
    .cl-vgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:12px}
    .cl-vcard{border:1px solid var(--gray-200);border-left:4px solid var(--accent,#1a73e8);border-radius:12px;padding:12px 14px;background:var(--white);display:flex;flex-direction:column;gap:8px}
    .cl-off{opacity:.55;border-left-color:var(--gray-300)}
    .cl-vtop{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
    .cl-vdesc{font-size:.8rem;color:var(--gray-500);line-height:1.4}
    .cl-pills{display:flex;flex-wrap:wrap;gap:5px}
    .cl-pill{display:inline-flex;align-items:center;gap:3px;font-size:.73rem;color:var(--gray-600);background:var(--gray-50);border:1px solid var(--gray-200);border-radius:999px;padding:2px 8px}
    .cl-pill .material-symbols-outlined{font-size:14px;color:var(--gray-500)}
    .cl-pill-warn{background:#fef3c7;border-color:#fde68a;color:#92400e}
    .cl-vactions{display:flex;flex-wrap:wrap;gap:4px;margin-top:auto}
    .cl-tbtn{display:inline-flex;align-items:center;gap:4px;border:1px solid var(--gray-200);background:var(--white);border-radius:8px;padding:5px 9px;font:inherit;font-size:.79rem;cursor:pointer;color:var(--gray-700)}
    .cl-tbtn:hover{border-color:var(--accent,#1a73e8);color:var(--accent,#1a73e8)}
    .cl-tbtn .material-symbols-outlined{font-size:16px}
    .cl-switch{position:relative;width:36px;height:20px;flex:none}
    .cl-switch input{opacity:0;width:0;height:0}
    .cl-switch span{position:absolute;inset:0;background:var(--gray-300);border-radius:20px;cursor:pointer;transition:.2s}
    .cl-switch span:before{content:'';position:absolute;width:16px;height:16px;left:2px;top:2px;background:#fff;border-radius:50%;transition:.2s}
    .cl-switch input:checked+span{background:var(--accent,#1a73e8)}
    .cl-switch input:checked+span:before{transform:translateX(16px)}
    .cl-d-head{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;padding-bottom:12px;border-bottom:1px solid var(--gray-200);margin-bottom:6px}
    .cl-d-kat{font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--accent,#1a73e8);font-weight:700;margin-bottom:4px}
    .cl-d-meta{display:flex;align-items:center;gap:4px;flex-wrap:wrap;font-size:.86rem;margin:3px 0;color:var(--gray-700)}
    .cl-d-meta .material-symbols-outlined{font-size:17px;color:var(--gray-400)}
    .cl-d-score{text-align:center;font-size:.85rem;font-weight:600}
    .cl-d-score .cl-score{font-size:1.2rem;padding:4px 14px;margin-bottom:4px}
    .cl-d-sec{font-weight:700;color:var(--gray-700);background:var(--gray-50);padding:8px 10px;border-radius:8px;margin-top:12px}
    .cl-d-row{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:12px;padding:9px 10px;border-bottom:1px solid var(--gray-100)}
    .cl-d-bad{background:#fef2f2;border-radius:8px}
    .cl-d-lbl small{display:block;color:var(--gray-400);font-size:.75rem}
    .cl-d-val{font-weight:600;font-size:.88rem}
    .cl-d-val .material-symbols-outlined{font-size:17px;vertical-align:-3px}
    .cl-v-ok{color:#15803d}.cl-v-bad{color:#b91c1c}.cl-v-na{color:var(--gray-400)}
    .cl-stars{color:#f59e0b;letter-spacing:1px}
    .cl-pre{white-space:pre-wrap;font-weight:500}
    .cl-d-notiz{font-weight:400;font-size:.82rem;color:var(--gray-700);margin-top:3px;white-space:pre-wrap}
    .cl-d-fotos{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
    .cl-d-fotos img{width:96px;height:72px;object-fit:cover;border-radius:6px;border:1px solid var(--gray-200);cursor:zoom-in;background:var(--gray-100)}
    .cl-d-sigs{display:flex;gap:24px;flex-wrap:wrap;margin-top:16px}
    .cl-d-sigs div{display:flex;flex-direction:column;gap:4px;min-width:200px}
    .cl-d-sigs img{max-height:70px;max-width:260px;background:#fff;border-bottom:1px solid var(--gray-700)}
    .cl-d-sigs span{font-size:.76rem;color:var(--gray-500)}
    .cl-zoom{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:100000;display:flex;align-items:center;justify-content:center;cursor:zoom-out}
    .cl-zoom img{max-width:94vw;max-height:92vh;border-radius:6px}
    .cl-b-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}
    .cl-span2{grid-column:span 2}
    .cl-b-fhead{display:flex;flex-direction:column;gap:2px;margin:6px 0 8px}
    .cl-b-row{display:flex;gap:8px;align-items:flex-start;border:1px solid var(--gray-200);border-radius:10px;padding:8px;margin-bottom:6px;background:var(--white)}
    .cl-b-sec{background:var(--gray-50)}
    .cl-b-move{display:flex;flex-direction:column}
    .cl-b-main{flex:1;display:flex;flex-direction:column;gap:6px;min-width:0}
    .cl-b-line{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .cl-b-line select{flex:0 0 190px}
    .cl-b-label{flex:1;min-width:200px;font-weight:600}
    .cl-b-hint{flex:1;min-width:180px}
    .cl-b-small{width:150px}
    .cl-b-full{flex:1}
    .cl-b-opts select{flex:0 0 160px}
    .cl-b-add{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
    .cl-o-tools{display:flex;gap:6px;margin:8px 0}
    .cl-o-list{max-height:52vh;overflow:auto;border:1px solid var(--gray-200);border-radius:10px}
    .cl-o-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid var(--gray-100)}
    .cl-o-row.on{background:var(--gray-50)}
    .cl-o-row span{display:flex;flex-direction:column}
    .cl-o-row small{color:var(--gray-500);font-size:.75rem}
    @media (max-width:760px){
        .cl-b-grid{grid-template-columns:1fr 1fr}
        .cl-span2{grid-column:1/-1}
        .cl-table thead{display:none}
        .cl-table,.cl-table tbody,.cl-table tr,.cl-table td{display:block;width:100%}
        .cl-table tr{border-bottom:1px solid var(--gray-200);padding:8px 4px}
        .cl-table td{border:0;padding:3px 10px}
        .cl-table td[data-l]:before{content:attr(data-l);display:block;font-size:.68rem;text-transform:uppercase;color:var(--gray-400)}
        .cl-d-row{grid-template-columns:1fr}
        .cl-b-line select,.cl-b-opts select{flex:1 1 100%}
    }
    </style>`;
}

window.renderChecklisten = renderChecklisten;
Object.assign(window, {
    clTab, clOpenProtokoll, clPdf, clZoom, clDeleteProtokoll, clInstallPaket, clToggleVorlage, clDeleteVorlage,
    clEditVorlage, clSetField, clMoveField, clRemoveField, clAddField, clSaveVorlage, clOpenObjekte, clRenderObjekte,
    clObjSet, clObjAll, clSaveObjekte, clShowProtokolleOf,
});
