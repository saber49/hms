/* ============================================
   Kundenportal › Wachbuch (QM module, spec §6.3)
   One Wachbuch day (06:00–06:00) of the customer's objects: guard status,
   entries incl. corrections and photos, "Hinweis an den Wachdienst"
   (goes live to the guard on duty).
   ============================================ */

const KP_WB_ART_ICON = {
    dienstbeginn: 'login', dienstende: 'logout', notiz: 'edit', vorkommnis: 'warning',
    alarm: 'notifications', besucher: 'badge', lieferung: 'local_shipping', schluessel: 'vpn_key', foto: 'photo_camera', hinweis: 'campaign', berichtigung: 'ink_eraser',
};
let kpWbDatum = kpIso(new Date());

async function kpWbPdf(obj) {
    // Reserve the viewer while the click is still a user gesture; mobile
    // WebViews otherwise block window.open after the authenticated fetch.
    const win = window.open('', '_blank');
    try {
        const blob = await kpFetch('/wachbuch/pdf?obj_id=' + encodeURIComponent(obj) + '&datum=' + kpWbDatum, true);
        const url = URL.createObjectURL(blob);
        if (win) win.location.href = url;
        else {
            const link = document.createElement('a');
            link.href = url; link.download = 'Wachbuch_' + kpWbDatum + '.pdf';
            document.body.appendChild(link); link.click(); link.remove();
        }
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) {
        if (win) win.close();
        kpToast(e.message || 'PDF konnte nicht geladen werden');
    }
}

function kpWbTag(n) { const d = new Date(kpWbDatum + 'T12:00:00'); d.setDate(d.getDate() + n); kpWbDatum = kpIso(d); kpLoad(); }
function kpWbZeit(ts) { const d = new Date(ts); return isNaN(d) ? '' : d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }); }

function kpWbDetails(e) {
    const d = e.details || {};
    const ja = v => v === true ? 'ja' : (v === false ? 'nein' : '');
    return ({
        besucher: [d.name, d.firma, d.ziel && 'zu ' + d.ziel, ja(d.angemeldet) && 'angemeldet: ' + ja(d.angemeldet), d.gegangen && 'gegangen ' + d.gegangen],
        lieferung: [d.firma, d.zweck],
        schluessel: [d.schluessel, d.aktion],
        vorkommnis: [d.massnahmen && 'Maßnahmen: ' + d.massnahmen, (d.benachrichtigt || []).length && 'Benachrichtigt: ' + d.benachrichtigt.map(b => b.stelle + (b.zeit ? ' ' + b.zeit : '')).join(', ')],
    }[e.art === 'alarm' ? 'vorkommnis' : e.art] || []).filter(Boolean).join(' · ');
}

KP_TABS.wachbuch = {
    week: false,
    async load(box, seq) {
        const obj = document.getElementById('kpObjekt').value;
        const data = await kpFetch('/wachbuch?datum=' + kpWbDatum + (obj ? '&obj_id=' + encodeURIComponent(obj) : ''));
        if (!data || seq !== kpLoadSeq) return;
        const heute = kpWbDatum >= (kpMe?.wachbuch_tag || kpIso(new Date()));
        const korrigiert = new Set((data.eintraege || []).filter(e => e.berichtigt_durch).map(e => e.id));
        box.innerHTML = `
            <div class="kp-wb-nav">
                <button class="kp-icon-btn" onclick="kpWbTag(-1)" title="Vorheriger Tag"><i class="material-symbols-outlined">chevron_left</i></button>
                <input type="date" class="kp-select" value="${kpWbDatum}" max="${kpMe?.wachbuch_tag || kpIso(new Date())}" onchange="kpWbDatum=this.value;kpLoad()">
                <button class="kp-icon-btn" onclick="kpWbTag(1)" ${heute ? 'disabled' : ''} title="Nächster Tag"><i class="material-symbols-outlined">chevron_right</i></button>
                <span class="kp-muted">${kpDayName(kpWbDatum, true)} ${kpEsc(kpWbStart)} – folgender Tag ${kpEsc(kpWbStart)}</span>
            </div>
            <div class="kp-wb-status">${(data.status || []).map(s => `
                <div class="kp-card kp-wb-obj">
                    <div class="kp-card-title"><i class="material-symbols-outlined">apartment</i> ${kpEsc(s.objektname)}</div>
                    <div>${s.bewacht ? `<span class="kp-live"><span class="kp-live-dot"></span> bewacht · ${s.wachpersonen} Wachperson${s.wachpersonen === 1 ? '' : 'en'} seit ${kpWbZeit(s.seit)}</span>` : '<span class="kp-muted">derzeit niemand im Dienst</span>'}</div>
                    <div class="kp-card-sub">${s.vorkommnisse_7_tage} Vorkommnis${s.vorkommnisse_7_tage === 1 ? '' : 'se'} in 7 Tagen</div>
                    <button class="kp-chip-btn" onclick="kpHinweisDialog('${kpEsc(s.obj_id)}', '${kpEsc(s.objektname).replace(/'/g, '&#39;')}')"><i class="material-symbols-outlined">campaign</i> Hinweis an den Wachdienst</button>
                    ${s.pdf_erlaubt ? `<button class="kp-chip-btn" onclick="kpWbPdf('${kpEsc(s.obj_id)}')"><i class="material-symbols-outlined">picture_as_pdf</i> ${kpEsc(kpObLabel)} als PDF</button>` : ''}
                    ${s.bericht_wartet ? '<span class="kp-muted">Tagesbericht in Prüfung · PDF nach Freigabe</span>' : ''}
                </div>`).join('') || kpEmpty('menu_book', 'Für Ihre Objekte ist kein ' + kpObLabel + ' aktiv.')}</div>
            <div class="kp-wb-list">${(data.eintraege || []).map(e => `
                <article class="kp-card kp-wb-e kp-wb-${kpEsc(e.art)}${korrigiert.has(e.id) ? ' kp-wb-struck' : ''}">
                    <div class="kp-wb-time">${kpWbZeit(e.zeit)}</div>
                    <div class="kp-wb-body">
                        <div class="kp-card-title"><i class="material-symbols-outlined">${KP_WB_ART_ICON[e.art] || 'edit'}</i> ${kpEsc(e.art_label)}${e.ort ? ' · ' + kpEsc(e.ort) : ''}
                            ${e.ref_id ? '<span class="kp-pill">Berichtigung</span>' : ''}${e.nachgereicht ? '<span class="kp-pill">nachgereicht</span>' : ''}</div>
                        ${e.text ? `<div class="kp-wb-text">${kpEsc(e.text)}</div>` : ''}
                        ${kpWbDetails(e) ? `<div class="kp-card-sub">${kpEsc(kpWbDetails(e))}</div>` : ''}
                        ${(e.fotos || []).length ? `<div class="kp-foto-grid kp-wb-fotos">${e.fotos.map(f => `<button class="kp-foto" data-wbfoto="${f.id}" data-cap="${kpEsc(kpWbZeit(f.aufgenommen_am) + (f.lat ? ' · ' + f.lat + ', ' + f.lng : ' · ohne GPS'))}"><i class="material-symbols-outlined">image</i></button>`).join('')}</div>` : ''}
                        <div class="kp-card-meta">${kpEsc(e.erfasst_von || '')}</div>
                    </div>
                </article>`).join('') || (data.status?.length ? kpEmpty('menu_book', 'Keine Einträge an diesem Tag.') : '')}</div>`;
        kpWbFotos(box, seq);
    },
};

async function kpWbFotos(box, seq) {
    for (const el of box.querySelectorAll('[data-wbfoto]')) {
        try {
            const blob = await kpFetch('/wachbuch/fotos/' + el.dataset.wbfoto, true);
            if (!blob || seq !== kpLoadSeq) return;
            const url = URL.createObjectURL(blob);
            kpFotoUrls.push(url);
            el.innerHTML = `<img class="kp-foto-img" src="${url}" alt="">`;
            el.onclick = () => kpOpenLightbox(url, el.dataset.cap, 'Wachbuch-Foto.jpg');
        } catch (e) { /* keep the icon */ }
    }
}

function kpHinweisDialog(objId, name) {
    kpDialog('Hinweis an den Wachdienst', `
        <p class="kp-muted">${kpEsc(name)} — der Hinweis erscheint sofort beim Wachpersonal im Dienst und im ${kpEsc(kpObLabel)}.</p>
        <textarea id="kpHinweisText" class="kp-input" rows="4" maxlength="1000" placeholder="z. B. Heute ab 18 Uhr Anlieferung an Tor 3 erwartet"></textarea>`,
        'Senden', async () => {
            const text = document.getElementById('kpHinweisText').value.trim();
            if (!text) return 'Bitte einen Hinweis eingeben.';
            const r = await kpSend('POST', '/wachbuch/hinweis', { obj_id: objId, text });
            if (!r.ok) return r.data.error || 'Senden fehlgeschlagen';
            kpToast(r.data.zugestellt ? 'Hinweis an das Wachpersonal gesendet' : 'Hinweis gespeichert — derzeit ist niemand im Dienst, die Objektleitung wurde informiert');
            kpLoad();
        });
}
