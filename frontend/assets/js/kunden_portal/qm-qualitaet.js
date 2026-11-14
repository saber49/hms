/* ============================================
   Kundenportal › Dienstanweisungen + Reklamationen & Maßnahmen
   (QM module, spec §6.3). Only released fields come from the server.
   ============================================ */

KP_TABS.dienstanweisungen = {
    week: false,
    async load(box, seq) {
        const data = await kpFetch('/dienstanweisungen');
        if (!data || seq !== kpLoadSeq) return;
        const obj = document.getElementById('kpObjekt').value;
        const list = (data.dienstanweisungen || []).filter(d => !obj || d.obj_id === obj);
        box.innerHTML = list.length ? list.map(d => {
            const letzte = d.meine[d.meine.length - 1];
            return `<article class="kp-card">
                <div class="kp-card-head"><div>
                    <div class="kp-card-title"><i class="material-symbols-outlined">policy</i> ${kpEsc(d.objektname)}</div>
                    <div class="kp-card-sub">Version ${d.version} · gültig ab ${kpDe(d.gueltig_ab)} · ${d.seiten} Seite${d.seiten === 1 ? '' : 'n'}</div></div>
                    <div class="kp-card-meta">${d.bestaetigt ? '<span class="kp-pill kp-pill-ok"><i class="material-symbols-outlined">check</i> bestätigt</span>' : '<span class="kp-pill">Bestätigung offen</span>'}</div>
                </div>
                <div class="kp-sec">Das Wichtigste</div>
                <ul class="kp-kurz">${d.kurz.map(k => `<li>${kpEsc(k)}</li>`).join('')}</ul>
                ${letzte ? `<div class="kp-card-sub">Ihre letzte Rückmeldung: ${letzte.aktion === 'bestaetigt' ? 'Inhalt bestätigt' : 'Änderung gewünscht — ' + kpEsc(letzte.text || '')} (${kpEsc(new Date(letzte.am).toLocaleString('de-DE'))})</div>` : ''}
                <div class="kp-actions">
                    <button class="kp-chip-btn" onclick="kpDaPdf(${d.id})"><i class="material-symbols-outlined">picture_as_pdf</i> Vollständige Dienstanweisung öffnen</button>
                    ${d.bestaetigt ? '' : `<button class="kp-btn-primary" onclick="kpDaAktion(${d.id}, 'bestaetigt')"><i class="material-symbols-outlined">check</i> Inhalt bestätigen</button>`}
                    <button class="kp-chip-btn" onclick="kpDaAenderung(${d.id})"><i class="material-symbols-outlined">edit</i> Änderung wünschen</button>
                </div>
            </article>`;
        }).join('') : kpEmpty('policy', 'Für Ihre Objekte liegt noch keine Dienstanweisung vor.');
    },
};

async function kpDaPdf(id) {
    const win = window.open('', '_blank');
    try {
        const blob = await kpFetch('/dienstanweisungen/' + id + '/pdf', true);
        const url = URL.createObjectURL(blob);
        if (win) win.location.href = url; else window.location.href = url;
    } catch (e) { if (win) win.close(); kpToast('PDF konnte nicht geladen werden', true); }
}
async function kpDaAktion(id, aktion, text = '') {
    const r = await kpSend('POST', '/dienstanweisungen/' + id, { aktion, text });
    if (!r.ok) { kpToast(r.data.error || 'Fehler', true); return r.data.error || 'Fehler'; }
    kpToast(aktion === 'bestaetigt' ? 'Vielen Dank — Inhalt bestätigt' : 'Ihr Änderungswunsch wurde an die Objektleitung gesendet');
    kpLoad();
}
function kpDaAenderung(id) {
    kpDialog('Änderung wünschen', `<textarea id="kpDaText" class="kp-input" rows="5" maxlength="2000" placeholder="Was soll geändert werden?"></textarea>`, 'Senden', () => {
        const t = document.getElementById('kpDaText').value.trim();
        return t ? kpDaAktion(id, 'aenderung', t) : 'Bitte die gewünschte Änderung beschreiben.';
    });
}

KP_TABS.reklamationen = {
    week: false,
    async load(box, seq) {
        const [rek, mn] = await Promise.all([kpFetch('/reklamationen'), kpFetch('/massnahmen')]);
        if (!rek || !mn || seq !== kpLoadSeq) return;
        const obj = document.getElementById('kpObjekt').value;
        const objName = obj ? ([...document.getElementById('kpObjekt').options].find(o => o.value === obj)?.textContent || '') : '';
        const list = (rek.reklamationen || []).filter(r => !obj || r.obj_id === obj);
        const tone = { eingegangen: '', bestaetigt: 'kp-pill-info', in_bearbeitung: 'kp-pill-info', beantwortet: 'kp-pill-ok', abgeschlossen: 'kp-pill-ok' };
        box.innerHTML = `
            <div class="kp-actions kp-actions-top"><button class="kp-btn-primary" onclick="kpRekMelden()"><i class="material-symbols-outlined">add_comment</i> Beschwerde melden</button></div>
            <div class="kp-sec">Ihre Reklamationen</div>
            ${list.length ? list.map(r => `<article class="kp-card">
                <div class="kp-card-head"><div><div class="kp-card-title">${kpEsc(r.nr)} · ${kpEsc(r.objektname)}</div>
                    <div class="kp-card-sub">gemeldet ${kpEsc(new Date(r.eingang_am).toLocaleString('de-DE'))}${r.frist ? ' · Erledigung bis ' + kpDe(r.frist) : ''}</div></div>
                    <span class="kp-pill ${tone[r.status] || ''}">${kpEsc(r.status_label)}</span></div>
                <div class="kp-wb-text">${kpEsc(r.text)}</div>
                ${r.antwort ? `<div class="kp-antwort"><strong>Antwort:</strong> ${kpEsc(r.antwort)}</div>` : ''}
                <details><summary class="kp-muted">Verlauf</summary>${r.verlauf.map(v => `<div class="kp-card-sub">${kpEsc(new Date(v.am).toLocaleString('de-DE'))} · ${kpEsc(v.art)}</div>`).join('')}</details>
            </article>`).join('') : kpEmpty('forum', 'Keine Reklamationen' + (objName ? ' für ' + objName : '') + '.')}
            <div class="kp-sec">Umgesetzte Verbesserungen</div>
            ${(mn.massnahmen || []).filter(m => !objName || m.objektname === objName).map(m => `<article class="kp-card kp-card-slim">
                <div class="kp-card-title"><i class="material-symbols-outlined">checklist</i> ${kpEsc(m.titel)}</div>
                <div class="kp-card-sub">${kpEsc(m.objektname)} · ${kpEsc(m.status)}${m.umgesetzt_am ? ' am ' + kpEsc(new Date(m.umgesetzt_am).toLocaleDateString('de-DE')) : ''}</div>
            </article>`).join('') || '<p class="kp-muted">Noch keine freigegebenen Maßnahmen.</p>'}`;
    },
};

function kpRekMelden() {
    const opts = [...document.getElementById('kpObjekt').options].filter(o => o.value);
    const cur = document.getElementById('kpObjekt').value || opts[0]?.value;
    kpDialog('Beschwerde melden', `
        <label class="kp-label">Objekt</label>
        <select id="kpRekObj" class="kp-select kp-input">${opts.map(o => `<option value="${kpEsc(o.value)}" ${o.value === cur ? 'selected' : ''}>${kpEsc(o.textContent)}</option>`).join('')}</select>
        <label class="kp-label">Was ist passiert?</label>
        <textarea id="kpRekText" class="kp-input" rows="6" maxlength="5000" placeholder="Datum, Uhrzeit, Ort und was Ihnen aufgefallen ist"></textarea>
        <p class="kp-muted">Sie erhalten eine Eingangsbestätigung und sehen den Bearbeitungsstand hier im Portal.</p>`,
        'Absenden', async () => {
            const text = document.getElementById('kpRekText').value.trim();
            if (text.length < 5) return 'Bitte beschreiben Sie den Sachverhalt.';
            const r = await kpSend('POST', '/reklamationen', { obj_id: document.getElementById('kpRekObj').value, text });
            if (!r.ok) return r.data.error || 'Senden fehlgeschlagen';
            kpToast('Danke — Ihre Meldung ' + r.data.nr + ' ist eingegangen');
            kpLoad();
        });
}
