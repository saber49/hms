/* ============================================
   QM 02 — Wachbuch page (#qm_wachbuch)
   Reiter "Leitstand · alle Objekte" (start) and "Wachbuchblätter · je Objekt"
   (Steckbrief, months → days → complete day log 06:00–06:00).
   ============================================ */

const QM_WB_TABS = [['leitstand', 'monitor_heart', 'Leitstand · alle Objekte'], ['blaetter', 'menu_book', 'Wachbuchblätter · je Objekt']];
const QM_WB_ART_ICON = {
    dienstbeginn: 'login', dienstende: 'logout', notiz: 'edit_note', vorkommnis: 'report', alarm: 'e911_emergency',
    besucher: 'badge', lieferung: 'local_shipping', schluessel: 'key', foto: 'photo_camera', uebergabe: 'sync_alt',
    hinweis: 'campaign', berichtigung: 'edit_off',
};
let _qmWbTimer = null;

async function renderQmWachbuch() {
    const content = document.getElementById('content');
    try { await qmLoadOpt(true); } catch (e) {
        content.innerHTML = qmEmpty('error', e.message, `<md-filled-button onclick="renderQmWachbuch()">Erneut versuchen</md-filled-button>`);
        return;
    }
    const p = qmParams();
    if (p.get('obj')) { qm.wb.tab = 'blaetter'; qm.wb.obj = p.get('obj'); qm.wb.tag = p.get('datum') || ''; }
    document.getElementById('pageTitle').textContent = qmLabel();
    content.innerHTML = `
        <div class="crm-page qm-page">
            ${qmTabs('qmWbSetTab', QM_WB_TABS, qm.wb.tab)}
            <div id="qmWbBody"></div>
        </div>`;
    qmWire(content);
    qmWbRender();
    clearInterval(_qmWbTimer);
    _qmWbTimer = setInterval(() => {
        if (currentPage !== 'qm_wachbuch') return clearInterval(_qmWbTimer);
        if (qm.wb.tab === 'leitstand' && !document.getElementById('modalOverlay')?.classList.contains('active')) qmLeitstandLaden();
    }, 60000);
}

function qmWbSetTab(v) {
    qm.wb.tab = v;
    document.querySelectorAll('[data-qm-tabs="qmWbSetTab"] md-primary-tab').forEach(t => { t.active = t.dataset.v === v; });
    qmWbRender();
}
function qmWbRender() { return qm.wb.tab === 'blaetter' ? qmBlaetterRender() : qmLeitstandRender(); }

/** From the Leitstand / a notification straight to one Objekt + day. */
function qmWbOeffne(objId, datum) {
    qm.wb.obj = objId; qm.wb.tag = datum || qmHeute(); qm.wb.monat = (datum || qmHeute()).slice(0, 7);
    if (currentPage !== 'qm_wachbuch') { window._pageParams = new URLSearchParams({ obj: objId, datum: qm.wb.tag }); return loadPage('qm_wachbuch'); }
    qmWbSetTab('blaetter');
}

// ============================================
// LEITSTAND
// ============================================
function qmLeitstandRender() {
    const body = document.getElementById('qmWbBody');
    body.innerHTML = `
        <div class="data-bento" id="qmLsBento"></div>
        <div class="data-table-card"><div id="qmLsRows">${qmLoading()}</div></div>
        <div class="crm-card qm-mt"><div class="crm-card-title">${qmIcon('notification_important')} Offene Vorkommnisse &amp; Alarme</div><div id="qmLsVk"></div></div>`;
    qmLeitstandLaden();
}

async function qmLeitstandLaden() {
    const seq = ++qm.seq;
    const r = await qmGet(QM_API + '/wachbuch/leitstand');
    if (seq !== qm.seq || qm.wb.tab !== 'leitstand') return;
    const rows = document.getElementById('qmLsRows');
    if (!rows) return;
    if (!r.ok) { rows.innerHTML = qmFehlerBox(r, 'qmLeitstandLaden()'); return; }
    const o = r.data.objekte || [];
    const sum = k => o.reduce((s, x) => s + (Array.isArray(x[k]) ? x[k].length : (+x[k] || 0)), 0);
    qmPaint(document.getElementById('qmLsBento'), [
        qmTile({ hero: true, cls: 'bento-tile-total', icon: 'shield_person', label: 'Im Dienst', n: sum('im_dienst'), foot: `<span class="bento-foot-pill">${o.length} Objekte mit ${qmE(qmLabel())}</span>` }),
        qmTile({ cls: sum('fehlende_checkins') ? 'bento-tile-warn crm-tile-amber' : '', icon: 'person_off', label: 'Fehlende Check-ins', n: sum('fehlende_checkins') }),
        qmTile({ cls: sum('vorkommnisse_offen') ? 'bento-tile-warn crm-tile-red' : '', icon: 'report', label: 'Offene Vorkommnisse', n: sum('vorkommnisse_offen') }),
        qmTile({ cls: sum('alarm_offen') ? 'bento-tile-warn crm-tile-red' : '', icon: 'e911_emergency', label: 'Offene Alarme', n: sum('alarm_offen') }),
        qmTile({ icon: 'edit_note', label: 'Einträge seit 06:00', n: sum('eintraege_heute') }),
    ].join(''));

    qmPaint(rows, o.length ? `<div class="table-container"><table class="crm-table qm-table">
        <thead><tr><th>Objekt</th><th>Im Dienst</th><th class="crm-num">Geplant heute</th><th>Fehlende Check-ins</th><th class="crm-num">Vorkommnisse</th><th class="crm-num">Einträge</th><th>Letzter Eintrag</th></tr></thead>
        <tbody>${o.map(x => `<tr class="crm-row" onclick="qmWbOeffne('${qmE(x.obj_id)}')">
            <td><strong>${qmE(x.objektname)}</strong><div class="crm-sub">${qmE(x.kundenname || '')}</div></td>
            <td>${x.im_dienst.length ? x.im_dienst.map(w => `<span class="qm-badge qm-ok">${qmIcon('circle', 'font-size:9px')} ${qmE(w.name)}${w.status === 'pause' ? ' (Pause)' : ''}</span>`).join(' ') : '<span class="crm-muted">niemand</span>'}</td>
            <td class="crm-num">${x.geplant_heute}</td>
            <td>${x.fehlende_checkins.length ? x.fehlende_checkins.map(w => qmBadge(w.name + ' (' + w.plan + ')', 'warn')).join(' ') : '–'}</td>
            <td class="crm-num">${x.alarm_offen ? qmBadge(x.alarm_offen + ' Alarm', 'err') + ' ' : ''}${x.vorkommnisse_offen ? qmBadge(x.vorkommnisse_offen, 'err') : '0'}</td>
            <td class="crm-num">${x.eintraege_heute}</td>
            <td>${x.letzter_eintrag ? qmE(qmTime(x.letzter_eintrag)) : '–'}</td>
        </tr>`).join('')}</tbody></table></div>`
        : qmEmpty('menu_book', `Für kein Objekt ist das ${qmLabel()} aktiv. Aktivieren unter Qualitätsmanagement › Objekte & Dienstanweisungen.`));

    const vk = r.data.vorkommnisse || [];
    qmPaint(document.getElementById('qmLsVk'), vk.length ? vk.map(e => `
        <div class="qm-vk ${e.art === 'alarm' ? 'qm-vk-alarm' : ''}">
            <div class="qm-vk-main" onclick="qmWbOeffne('${qmE(e.obj_id)}','${qmE(e.datum)}')">
                <div><strong>${qmE(e.art_label)}</strong> · ${qmE(e.objektname)} · ${qmE(qmDT(e.zeit))}${e.ort ? ' · ' + qmE(e.ort) : ''}</div>
                <div class="crm-sub">${qmE(e.text || '')}${e.erfasst_von ? ' — ' + qmE(e.erfasst_von) : ''}</div>
            </div>
            <div class="crm-actions">
                ${qmCan('massnahme_bearbeiten') ? `<md-outlined-button onclick="qmMnNeu({quelle_typ:'wachbuch', quelle_id:'${e.id}', obj_id:'${qmE(e.obj_id)}', titel:${qmE(JSON.stringify((e.art_label + ': ' + (e.text || '')).slice(0, 200)))}})"><md-icon slot="icon">add_task</md-icon>Maßnahme anlegen</md-outlined-button>` : ''}
                ${qmCan('wachbuch_eintrag') ? `<md-filled-tonal-button onclick="qmVkErledigt(${e.id})"><md-icon slot="icon">done</md-icon>Erledigt</md-filled-tonal-button>` : ''}
            </div>
        </div>`).join('') : `<div class="crm-muted crm-pad">${qmIcon('check_circle')} Keine offenen Vorkommnisse</div>`);
}

async function qmVkErledigt(id, offen = false) {
    const r = await qmReq('POST', QM_API + '/wachbuch/eintraege/' + id + '/erledigt', { offen });
    if (qmFehler(r)) return;
    showToast(offen ? 'Wieder offen' : 'Als erledigt markiert');
    qm.wb.tab === 'leitstand' ? qmLeitstandLaden() : qmTagLaden(qm.wb.tag, true);
}

// ============================================
// WACHBUCHBLÄTTER
// ============================================
function qmBlaetterRender() {
    const body = document.getElementById('qmWbBody');
    const objekte = qm.opt.objekte || [];
    if (!qm.wb.obj) qm.wb.obj = (objekte.find(o => o.wachbuch_aktiv) || objekte[0] || {}).obj_id || '';
    body.innerHTML = `
        <div class="data-toolbar">
            <div class="data-toolbar-left">
                <md-outlined-select class="cust-bereich-select qm-obj-select" label="Objekt" id="qmWbObj">
                    ${objekte.map(o => `<md-select-option value="${qmE(o.obj_id)}" ${o.obj_id === qm.wb.obj ? 'selected' : ''}><div slot="headline">${qmE(o.objektname)}${o.wachbuch_aktiv ? '' : ' (inaktiv)'}</div><div slot="supporting-text">${qmE(o.kundenname || '')}</div></md-select-option>`).join('')}
                </md-outlined-select>
            </div>
            <div class="crm-toolbar-actions">
                ${qmCan('wachbuch_eintrag') && qm.wb.obj ? `<md-filled-button onclick="qmEintragDialog()"><md-icon slot="icon">edit_note</md-icon>Eintrag aus dem Büro</md-filled-button>` : ''}
            </div>
        </div>
        <div id="qmWbSteckbrief"></div>
        <div id="qmWbMonate">${qm.wb.obj ? qmLoading() : qmEmpty('domain', 'Keine Objekte im Zugriff.')}</div>`;
    document.getElementById('qmWbObj')?.addEventListener('change', e => { qm.wb.obj = e.target.value; qm.wb.tag = ''; qm.wb.monat = ''; qmBlaetterRender(); });
    if (qm.wb.obj) qmObjektLaden();
}

async function qmObjektLaden() {
    const obj = qm.wb.obj;
    const r = await qmGet(QM_API + '/wachbuch/objekte/' + encodeURIComponent(obj));
    if (obj !== qm.wb.obj) return;
    const box = document.getElementById('qmWbMonate');
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, 'qmObjektLaden()'); return; }
    const d = r.data, o = d.objekt || {}, cfg = d.einstellung || {}, da = d.dienstanweisung;
    document.getElementById('qmWbSteckbrief').innerHTML = `
        <div class="crm-card qm-steckbrief">
            <div class="qm-kv-grid">
                <div><span>Objekt</span><strong>${qmE(o.objektname)}</strong><div class="crm-sub">${qmE([o.adresse, [o.plz, o.stadt].filter(Boolean).join(' ')].filter(Boolean).join(', '))}</div></div>
                <div><span>Kunde</span><strong>${qmE(o.kundenname || '–')}</strong><div class="crm-sub">${qmE([o.ansprechpartner, o.kontakt_telefon].filter(Boolean).join(' · '))}</div></div>
                <div><span>Objektleiter</span><strong>${qmE(d.objektleiter || '–')}</strong></div>
                <div><span>${qmE(qmLabel())}</span>${cfg.wachbuch_aktiv ? qmBadge('aktiv', 'ok') : qmBadge('nicht aktiv', 'warn')} ${cfg.fotopflicht ? qmBadge('Fotopflicht') : ''}</div>
                <div><span>Dienstanweisung</span>${da ? `<a href="#" onclick="qmOpenPdf('${QM_API}/dienstanweisungen/${da.id}/pdf');return false">Version ${da.version} · gültig ab ${qmE(qmDate(da.gueltig_ab))}</a>` : '<span class="crm-muted">keine</span>'}</div>
            </div>
        </div>`;
    const monate = d.monate || [];
    const aktMonat = qmHeute().slice(0, 7);
    if (!monate.some(m => m.monat === aktMonat)) monate.unshift({ monat: aktMonat, tage: 0, eintraege: 0, vorkommnisse: 0 });
    if (!qm.wb.monat) qm.wb.monat = (qm.wb.tag || aktMonat).slice(0, 7);
    box.innerHTML = monate.map(m => `
        <details class="crm-card qm-monat" data-monat="${m.monat}" ${m.monat === qm.wb.monat ? 'open' : ''}>
            <summary class="crm-card-title">${qmIcon('calendar_month')} ${qmE(qmMonatName(m.monat))}
                <span class="crm-muted">${m.tage} Tage · ${m.eintraege} Einträge${+m.vorkommnisse ? ' · ' + m.vorkommnisse + ' Vorkommnisse' : ''}</span></summary>
            <div class="qm-tage" id="qmTage_${m.monat}"></div>
        </details>`).join('');
    box.querySelectorAll('details.qm-monat').forEach(det => {
        det.addEventListener('toggle', () => { if (det.open) { qm.wb.monat = det.dataset.monat; qmTageLaden(det.dataset.monat); } });
        if (det.open) qmTageLaden(det.dataset.monat);
    });
}

function qmMonatName(ym) {
    const [y, m] = ym.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' });
}

const QM_TAG_STATUS = { laeuft: ['läuft', 'ok'], abgeschlossen: ['abgeschlossen', ''], gegengezeichnet: ['gegengezeichnet', 'info'] };

async function qmTageLaden(monat) {
    const box = document.getElementById('qmTage_' + monat);
    if (!box) return;
    if (!box.dataset.loaded) box.innerHTML = qmLoading();
    const r = await qmGet(QM_API + '/wachbuch/objekte/' + encodeURIComponent(qm.wb.obj) + '/tage?monat=' + monat);
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, `qmTageLaden('${monat}')`); return; }
    const tage = r.data.tage || [];
    box.dataset.loaded = '1';
    box.innerHTML = tage.length ? `<div class="table-container"><table class="crm-table qm-table">
        <thead><tr><th>Datum</th><th>Wachpersonen</th><th class="crm-num">Einträge</th><th class="crm-num">Fotos</th><th class="crm-num">Vorkommnisse</th><th>Status</th></tr></thead>
        <tbody>${tage.map(t => `
            <tr class="crm-row" onclick="qmTagToggle('${t.datum}')"><td><strong>${qmE(new Date(t.datum + 'T12:00:00').toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' }))}</strong></td>
                <td>${qmE(t.wachpersonen.join(', ') || '–')}</td><td class="crm-num">${t.eintraege}</td><td class="crm-num">${t.fotos}</td>
                <td class="crm-num">${t.vorkommnisse ? qmBadge(t.vorkommnisse, 'err') : '0'}</td>
                <td>${qmBadge(...(QM_TAG_STATUS[t.status] || [t.status]))}</td></tr>
            <tr class="qm-tag-row" id="qmTag_${t.datum}" ${t.datum === qm.wb.tag ? '' : 'hidden'}><td colspan="6"><div class="qm-tag-body"></div></td></tr>`).join('')}
        </tbody></table></div>` : `<div class="crm-muted crm-pad">Keine Wachbuchblätter in diesem Monat.</div>`;
    if (qm.wb.tag && qm.wb.tag.startsWith(monat)) qmTagLaden(qm.wb.tag);
}

function qmTagToggle(datum) {
    const row = document.getElementById('qmTag_' + datum);
    if (!row) return;
    if (!row.hidden) { row.hidden = true; if (qm.wb.tag === datum) qm.wb.tag = ''; return; }
    document.querySelectorAll('.qm-tag-row').forEach(r => { r.hidden = true; });
    row.hidden = false;
    qm.wb.tag = datum;
    qmTagLaden(datum);
}

async function qmTagLaden(datum, still = false) {
    const row = document.getElementById('qmTag_' + datum);
    const box = row?.querySelector('.qm-tag-body');
    if (!box) return;
    row.hidden = false;
    if (!still) box.innerHTML = qmLoading();
    const r = await qmGet(QM_API + '/wachbuch/objekte/' + encodeURIComponent(qm.wb.obj) + '/tag/' + datum);
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, `qmTagLaden('${datum}')`); return; }
    const { blaetter, eintraege } = r.data;
    qm.cache.tag = r.data;
    const nameDesBlatts = id => blaetter.find(b => b.id === id)?.name || '';
    box.innerHTML = `
        <div class="qm-blaetter">${blaetter.map(b => `
            <div class="qm-blatt">
                <div><strong>${qmE(b.name)}</strong>${b.plan_von ? ` <span class="crm-muted">Plan ${qmE(b.plan_von)}–${qmE(b.plan_bis)}</span>` : ''}</div>
                <div class="crm-sub">${b.geoeffnet_am ? 'ab ' + qmE(qmTime(b.geoeffnet_am)) : ''}${b.geschlossen_am ? ' · bis ' + qmE(qmTime(b.geschlossen_am)) : (b.dienst_nr ? ' · läuft' : '')}</div>
                <div>${b.kette_ok ? qmBadge('Prüfkette ok', 'ok') : qmBadge('Prüfkette verletzt!', 'err')}
                    ${b.gegengezeichnet_am ? qmBadge('gegengezeichnet ' + (b.gegengezeichnet_von || ''), 'info')
                        : (qmCan('wachbuch_gegenzeichnen') && (b.geschlossen_am || !b.dienst_nr) ? `<md-text-button onclick="qmGegenzeichnen(${b.id},'${datum}')"><md-icon slot="icon">draw</md-icon>Gegenzeichnen</md-text-button>` : '')}</div>
                ${b.hinweis_nachfolger ? `<div class="qm-hinweis">${qmIcon('sync_alt')} Übergabe: ${qmE(b.hinweis_nachfolger)}</div>` : ''}
            </div>`).join('')}
        </div>
        <div class="qm-eintraege">${eintraege.length ? eintraege.map(e => qmEintragHtml(e, blaetter.length > 1 ? nameDesBlatts(e.blatt_id) : '')).join('') : '<div class="crm-muted crm-pad">Keine Einträge</div>'}</div>`;
    qmLoadImages(box);
}

function qmDetailsText(e) {
    const d = e.details || {};
    const ja = v => v === true ? 'ja' : (v === false ? 'nein' : null);
    const parts = {
        besucher: [d.name && 'Name: ' + d.name, d.firma && 'Firma: ' + d.firma, d.ziel && 'Ziel: ' + d.ziel, d.kennzeichen && 'Kennz.: ' + d.kennzeichen,
            ja(d.angemeldet) && 'angemeldet: ' + ja(d.angemeldet), ja(d.ausweis_geprueft) && 'Ausweis geprüft: ' + ja(d.ausweis_geprueft), d.gegangen && 'gegangen ' + d.gegangen],
        lieferung: [d.firma && 'Firma: ' + d.firma, d.person && 'Person: ' + d.person, d.kennzeichen && 'Kennz.: ' + d.kennzeichen, d.zweck && 'Zweck: ' + d.zweck,
            d.freigabe_durch && 'Freigabe: ' + d.freigabe_durch, ja(d.ausweis_geprueft) && 'Ausweis geprüft: ' + ja(d.ausweis_geprueft)],
        schluessel: [d.schluessel && 'Schlüssel: ' + d.schluessel, d.aktion && d.aktion, d.an && 'an ' + d.an],
        vorkommnis: [d.massnahmen && 'Maßnahmen: ' + d.massnahmen, (d.benachrichtigt || []).length && 'Benachrichtigt: ' + d.benachrichtigt.map(b => b.stelle + (b.zeit ? ' ' + b.zeit : '')).join(', ')],
        uebergabe: [d.von && 'von ' + d.von],
    };
    parts.alarm = parts.vorkommnis;
    return (parts[e.art] || []).filter(Boolean).join(' · ');
}

function qmEintragHtml(e, wer = '') {
    const det = qmDetailsText(e);
    const struck = e.berichtigt_durch ? ' qm-berichtigt' : '';
    return `<div class="qm-eintrag qm-art-${qmE(e.art)}${e.wichtig ? ' qm-wichtig' : ''}${struck}" id="qmE_${e.id}">
        <div class="qm-eintrag-zeit">${qmE(qmTime(e.zeit))}<span class="crm-muted">#${e.nr}</span></div>
        <div class="qm-eintrag-icon">${qmIcon(QM_WB_ART_ICON[e.art] || 'notes')}</div>
        <div class="qm-eintrag-body">
            <div class="qm-eintrag-head"><strong>${qmE(e.art_label)}</strong>${e.ort ? ' · ' + qmE(e.ort) : ''}
                ${e.auto ? qmBadge('automatisch') : ''}${e.nachgereicht ? qmBadge('nachgereicht · erfasst ' + qmTime(e.erfasst_am), 'warn') : ''}
                ${e.status === 'offen' ? qmBadge('offen', 'err') : ''}${e.status === 'erledigt' && ['vorkommnis', 'alarm'].includes(e.art) ? qmBadge('erledigt', 'ok') : ''}
                ${e.ref_id ? `<a href="#qmE_${e.ref_id}" class="crm-muted">berichtigt #${(qm.cache.tag?.eintraege || []).find(x => x.id === e.ref_id)?.nr ?? ''}</a>` : ''}</div>
            ${e.text ? `<div class="qm-eintrag-text">${qmE(e.text)}</div>` : ''}
            ${det ? `<div class="crm-sub">${qmE(det)}</div>` : ''}
            ${e.fotos?.length ? `<div class="qm-fotos">${e.fotos.map(f => `<img data-qm-src="${QM_API}/wachbuch/fotos/${f.id}" alt="Foto" onclick="qmFotoGross('${QM_API}/wachbuch/fotos/${f.id}', ${qmE(JSON.stringify((f.lat ? f.lat + ', ' + f.lng + ' ±' + (f.genauigkeit_m || 0) + ' m' : 'ohne GPS') + (f.nicht_am_objekt ? ' · nicht am Objekt' : '')))})">`).join('')}</div>` : ''}
            <div class="crm-sub">${qmE(e.erfasst_von || wer || '')} · ${qmE({ app: 'App', terminal: 'Terminal', buero: 'Büro', kunde: 'Kundenportal', system: 'System' }[e.quelle] || e.quelle)}</div>
        </div>
        <div class="qm-eintrag-akt">
            ${qmCan('wachbuch_eintrag') && e.art !== 'berichtigung' && !e.berichtigt_durch && !e.auto ? `<md-icon-button title="Berichtigen" onclick="qmEintragDialog(${e.id})"><md-icon>edit_off</md-icon></md-icon-button>` : ''}
            ${qmCan('wachbuch_eintrag') && e.status ? `<md-icon-button title="${e.status === 'offen' ? 'Erledigt' : 'Wieder öffnen'}" onclick="qmVkErledigt(${e.id}, ${e.status !== 'offen'})"><md-icon>${e.status === 'offen' ? 'done' : 'undo'}</md-icon></md-icon-button>` : ''}
            ${qmCan('massnahme_bearbeiten') && ['vorkommnis', 'alarm'].includes(e.art) ? `<md-icon-button title="Maßnahme anlegen" onclick="qmMnNeu({quelle_typ:'wachbuch', quelle_id:'${e.id}', obj_id:'${qmE(e.obj_id)}', titel:${qmE(JSON.stringify((e.art_label + ': ' + (e.text || '')).slice(0, 200)))}})"><md-icon>add_task</md-icon></md-icon-button>` : ''}
        </div>
    </div>`;
}

async function qmGegenzeichnen(id, datum) {
    if (!(await mdConfirm('Dieses Wachbuchblatt gegenzeichnen? Danach ist es als geprüft markiert.', { title: 'Gegenzeichnen', confirmLabel: 'Gegenzeichnen' }))) return;
    const r = await qmReq('POST', QM_API + '/wachbuch/blaetter/' + id + '/gegenzeichnen');
    if (qmFehler(r)) return;
    showToast('Gegengezeichnet');
    qmTagLaden(datum, true);
}

/** Office entry; with refId = correction of that entry (append-only). */
function qmEintragDialog(refId = null) {
    const ref = refId ? (qm.cache.tag?.eintraege || []).find(e => e.id === refId) : null;
    const arten = { notiz: 'Notiz', vorkommnis: 'Vorkommnis', hinweis: 'Hinweis', besucher: 'Besucher', lieferung: 'Lieferung / Fremdfirma', schluessel: 'Schlüssel' };
    openModal(ref ? `Berichtigung zu #${ref.nr}` : 'Eintrag aus dem Büro', `
        <div class="crm-form">
            ${ref ? `<div class="crm-banner">${qmIcon('info')} Der ursprüngliche Eintrag bleibt durchgestrichen sichtbar: „${qmE((ref.text || ref.art_label).slice(0, 160))}“</div>`
                : `<md-outlined-select class="crm-fld" label="Art" id="qe_art">${qmSelectOptions(arten, 'notiz')}</md-outlined-select>
                   <md-outlined-text-field class="crm-fld" label="Datum" id="qe_datum" type="date" value="${qmE(qm.wb.tag || qmHeute())}"></md-outlined-text-field>
                   <md-outlined-text-field class="crm-fld" label="Ort" type="text" id="qe_ort" data-max="200"></md-outlined-text-field>`}
            <md-outlined-text-field class="crm-fld" label="${ref ? 'Richtigstellung *' : 'Text *'}" type="textarea" id="qe_text" rows="4" maxlength="4000" data-f="text"></md-outlined-text-field>
        </div>`,
        `<md-outlined-button onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button id="qeSave" onclick="qmEintragSpeichern(${refId || 'null'})"><md-icon slot="icon">save</md-icon>Speichern</md-filled-button>`);
}

async function qmEintragSpeichern(refId) {
    const body = refId ? { art: 'berichtigung', ref_id: refId, text: qmV('qe_text'), obj_id: qm.wb.obj }
        : { art: qmV('qe_art'), text: qmV('qe_text'), ort: qmV('qe_ort'), datum: qmV('qe_datum'), obj_id: qm.wb.obj, details: {} };
    if (['besucher', 'lieferung', 'schluessel'].includes(body.art)) {
        // Office shortcut: the free text carries the details; required keys are filled from it.
        body.details = { besucher: { name: body.text, ziel: body.text }, lieferung: { firma: body.text }, schluessel: { schluessel: body.text } }[body.art];
    }
    const btn = document.getElementById('qeSave'); if (btn) btn.disabled = true;
    const r = await qmReq('POST', QM_API + '/wachbuch/eintraege', body);
    if (btn) btn.disabled = false;
    if (qmFehler(r)) return;
    closeModal();
    showToast(refId ? 'Berichtigung gespeichert' : 'Eintrag gespeichert');
    const datum = refId ? qm.wb.tag : body.datum;
    qm.wb.tag = datum; qm.wb.monat = datum.slice(0, 7);
    qmObjektLaden();
}
