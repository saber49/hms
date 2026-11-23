/* ============================================
   QM 04 — tab "Objekte & Dienstanweisungen" ("Meine Objekte" for an
   Objektleiter): Wachbuch switches, on duty, current Dienstanweisung with
   confirmation counts, drafts to release, upload of a new version (PDF +
   "Das Wichtigste"). The ONLY place where Dienstanweisungen are uploaded.
   ============================================ */

async function qmObjekteRender() {
    const body = document.getElementById('qmBody');
    body.innerHTML = `
        <div class="data-toolbar"><div class="data-toolbar-left">
            <md-outlined-text-field class="cust-search" label="Objekte suchen" placeholder="Objekt, Kunde…" id="qmObjQ"><md-icon slot="leading-icon">search</md-icon></md-outlined-text-field>
        </div></div>
        <div class="data-table-card"><div id="qmObjRows">${qmLoading()}</div></div>`;
    document.getElementById('qmObjQ').addEventListener('input', e => { qm.cache.objQ = e.target.value.toLowerCase(); qmObjekteZeichnen(); });
    const r = await qmGet(QM_API + '/objekte');
    if (qm.view !== 'objekte') return;
    if (!r.ok) { document.getElementById('qmObjRows').innerHTML = qmFehlerBox(r, 'qmObjekteRender()'); return; }
    qm.cache.objekte = r.data.objekte || [];
    qmObjekteZeichnen();
}

function qmObjekteZeichnen() {
    const q = qm.cache.objQ || '';
    const list = (qm.cache.objekte || []).filter(o => !q || (o.objektname + ' ' + (o.kundenname || '')).toLowerCase().includes(q));
    const box = document.getElementById('qmObjRows');
    if (!box) return;
    if (!list.length) { box.innerHTML = qmEmpty('domain', qm.opt.ich.alle_objekte ? 'Keine Objekte gefunden.' : 'Ihnen sind noch keine Objekte zugeordnet. Die Zuordnung macht der QMB je Objekt.'); return; }
    qmPaint(box, `<div class="table-container"><table class="crm-table qm-table">
        <thead><tr><th>Objekt</th><th>${qmE(qmLabel())}</th><th>Im Dienst</th><th>Dienstanweisung</th><th>Bestätigt</th><th>Terminal</th><th></th></tr></thead>
        <tbody>${list.map(o => {
            const da = o.dienstanweisung;
            return `<tr>
                <td><strong>${qmE(o.objektname)}</strong><div class="crm-sub">${qmE(o.kundenname || '')}${o.objektleiter ? ' · OL ' + qmE(o.objektleiter) : ''}</div></td>
                <td>${o.wachbuch_aktiv ? qmBadge('aktiv', 'ok') : qmBadge('aus')}</td>
                <td>${o.im_dienst.length ? qmE(o.im_dienst.join(', ')) : '<span class="crm-muted">–</span>'}</td>
                <td>${da ? `<a href="#" onclick="qmOpenPdf('${QM_API}/dienstanweisungen/${da.id}/pdf');return false">v${da.version} · ${qmE(qmDate(da.gueltig_ab))}</a><div class="crm-sub">${da.seiten} Seiten</div>` : '<span class="crm-muted">keine</span>'}
                    ${o.entwuerfe ? qmBadge(o.entwuerfe + ' Entwurf zur Freigabe', 'warn') : ''}</td>
                <td>${da ? `${qmBadge('MA ' + da.ma_bestaetigt + ' von ' + da.ma_soll, da.ma_bestaetigt >= da.ma_soll ? 'ok' : 'warn')}
                    ${da.kunde ? qmBadge(da.kunde.aktion === 'bestaetigt' ? 'Kunde bestätigt' : 'Kunde: Änderung gewünscht', da.kunde.aktion === 'bestaetigt' ? 'ok' : 'err') : qmBadge('Kunde: offen')}` : ''}</td>
                <td>${o.terminal ? (o.terminal.gesperrt ? qmBadge('gesperrt', 'err') : qmBadge(o.terminal.kennung, o.terminal.aktiv ? 'info' : '')) : '<span class="crm-muted">–</span>'}</td>
                <td class="crm-actions">
                    <md-icon-button title="Details" onclick="qmObjDetail('${qmE(o.obj_id)}')"><md-icon>chevron_right</md-icon></md-icon-button>
                </td></tr>`;
        }).join('')}</tbody></table></div>`);
}

async function qmObjDetail(objId) {
    const o = (qm.cache.objekte || []).find(x => x.obj_id === objId) || qmObj(objId) || { obj_id: objId };
    const body = document.getElementById('qmBody');
    body.innerHTML = qmLoading();
    const r = await qmGet(QM_API + '/objekte/' + encodeURIComponent(objId));
    if (!r.ok) { body.innerHTML = qmFehlerBox(r, `qmObjDetail('${objId}')`); return; }
    const d = r.data, cfg = d.einstellung, akt = d.versionen.find(v => v.status === 'freigegeben');
    const darfCfg = qmCan('objekt_einstellungen');
    const statusTon = { freigegeben: 'ok', entwurf: 'warn', zurueckgewiesen: 'err', archiv: '' };
    body.innerHTML = `
        <div class="crm-detail-top"><md-outlined-button onclick="qmObjekteRender()"><md-icon slot="icon">arrow_back</md-icon>Zur Liste</md-outlined-button>
            <div class="crm-actions">
                <md-outlined-button onclick="qmWbOeffne('${qmE(objId)}')"><md-icon slot="icon">menu_book</md-icon>${qmE(qmLabel())} öffnen</md-outlined-button>
                ${qmCan('da_anlegen') ? `<md-filled-button onclick="qmDaDialog('${qmE(objId)}')"><md-icon slot="icon">upload_file</md-icon>Neue Dienstanweisung (PDF)</md-filled-button>` : ''}
            </div></div>
        <div class="crm-head"><div><div class="crm-head-firma">${qmE(o.kundenname || '')}</div><h2 class="crm-head-title">${qmE(o.objektname || objId)}</h2></div></div>
        <div class="crm-grid">
            <div>
                <div class="crm-card"><div class="crm-card-title">${qmIcon('description')} Dienstanweisungen</div>
                    ${d.versionen.length ? `<table class="crm-proto"><tbody>${d.versionen.map(v => `<tr>
                        <td>${v.version ? '<strong>v' + v.version + '</strong>' : 'Entwurf'}</td>
                        <td>${qmBadge(v.status, statusTon[v.status])}</td>
                        <td>gültig ab ${qmE(qmDate(v.gueltig_ab))} ${v.status === 'freigegeben' && v.gueltig_ab > qmHeute() ? qmBadge('noch nicht gültig', 'warn') : ''}<div class="crm-sub">${qmE(v.erstellt_von || '')} · ${qmE(qmDT(v.created_at))}${v.zurueckgewiesen_grund ? ' · Grund: ' + qmE(v.zurueckgewiesen_grund) : ''}</div>
                            <ul class="qm-kurz">${v.kurz.map(k => `<li>${qmE(k)}</li>`).join('')}</ul></td>
                        <td class="crm-actions"><md-icon-button title="PDF öffnen (${v.seiten} Seiten)" onclick="qmOpenPdf('${QM_API}/dienstanweisungen/${v.id}/pdf')"><md-icon>picture_as_pdf</md-icon></md-icon-button>
                            ${v.status === 'entwurf' && qmCan('da_freigeben') ? `<md-filled-tonal-button onclick="qmDaFreigabe(${v.id},'${qmE(objId)}')">Freigeben…</md-filled-tonal-button>` : ''}</td>
                    </tr>`).join('')}</tbody></table>` : '<div class="crm-muted crm-pad">Noch keine Dienstanweisung hochgeladen.</div>'}
                </div>
                ${akt ? `<div class="crm-card"><div class="crm-card-title">${qmIcon('fact_check')} Bestätigungen Version ${akt.version}</div>
                    <table class="crm-proto"><tbody>${d.bestaetigungen.map(b => `<tr><td>${qmE(b.name)}</td>
                        <td>${b.bestaetigt_am ? qmBadge('bestätigt ' + qmDT(b.bestaetigt_am) + (b.quelle ? ' (' + b.quelle + ')' : ''), 'ok') : (b.pdf_geoeffnet_am ? qmBadge('geöffnet, nicht bestätigt', 'warn') : qmBadge('offen'))}</td></tr>`).join('') || '<tr><td class="crm-muted">Keine eingeplanten Mitarbeiter</td></tr>'}
                    ${(d.kunde || []).map(k => `<tr><td>Kunde · ${qmE(k.name || k.username || '')}</td><td>${k.aktion === 'bestaetigt' ? qmBadge('bestätigt ' + qmDT(k.created_at), 'ok') : qmBadge('Änderung gewünscht', 'err') + ' ' + qmE(k.text || '')}</td></tr>`).join('')}
                    </tbody></table></div>` : ''}
            </div>
            <div>
                <div class="crm-card"><div class="crm-card-title">${qmIcon('tune')} QM-Einstellung des Objekts</div>
                    <div class="crm-form crm-pad">
                        <label class="crm-switch-row"><md-checkbox id="qo_wb" ${cfg.wachbuch_aktiv ? 'checked' : ''} ${darfCfg ? '' : 'disabled'}></md-checkbox> ${qmE(qmLabel())} aktiv</label>
                        <label class="crm-switch-row"><md-checkbox id="qo_foto" ${cfg.fotopflicht ? 'checked' : ''} ${darfCfg ? '' : 'disabled'}></md-checkbox> Fotopflicht bei Vorkommnissen</label>
                        <label class="crm-switch-row"><md-checkbox id="qo_kpdf" ${cfg.kunde_pdf ? 'checked' : ''} ${darfCfg ? '' : 'disabled'}></md-checkbox> Kundenportal: ${qmE(qmLabel())} als PDF herunterladen</label>
                        <div class="crm-sub">Der Kunde sieht im Portal einen „PDF herunterladen"-Button je Tag — mit derselben gefilterten Kundenfassung wie seine Online-Ansicht.</div>
                        <div class="crm-sec">Tagesbericht · objektbezogene Nachweise</div>
                        <label class="crm-switch-row"><md-checkbox id="qo_bericht" ${cfg.bericht_pflicht ? 'checked' : ''} ${darfCfg ? '' : 'disabled'}></md-checkbox> Tagesbericht nach Prüfung freigeben</label>
                        <label class="crm-switch-row"><md-checkbox id="qo_24h" ${cfg.durchgehend_bewacht ? 'checked' : ''} ${darfCfg ? '' : 'disabled'}></md-checkbox> Bewachung rund um die Uhr vereinbart (06:00–06:00)</label>
                        <label class="crm-switch-row"><md-checkbox id="qo_ausweis" ${cfg.besucher_ausweis_pflicht ? 'checked' : ''} ${darfCfg ? '' : 'disabled'}></md-checkbox> Ausweiskontrolle bei Besuchern verpflichtend</label>
                        <label class="crm-switch-row"><md-checkbox id="qo_freigabe" ${cfg.lieferung_freigabe_pflicht ? 'checked' : ''} ${darfCfg ? '' : 'disabled'}></md-checkbox> Freigabe für Lieferungen verpflichtend</label>
                        <label class="crm-switch-row"><md-checkbox id="qo_uebergabe" ${cfg.uebergabe_checkliste ? 'checked' : ''} ${darfCfg ? '' : 'disabled'}></md-checkbox> Übernahme-Checkliste bei Schichtwechsel</label>
                        <md-outlined-text-field class="crm-fld" label="Besuchszeit endet (HH:MM, optional)" id="qo_besuch_ende" value="${qmE(cfg.besuchszeit_ende || '')}" ${darfCfg ? '' : 'disabled'}></md-outlined-text-field>
                        <div class="crm-sub">Aussagen zur lückenlosen Besetzung oder zum Schlüsselbestand nur nach Prüfung der tatsächlichen Daten.</div>
                        <div class="crm-sub">App und Terminal verlangen das Foto bereits beim Speichern; im Büro bleibt ein fehlendes Pflichtfoto rot markiert.</div>
                        <md-outlined-text-field class="crm-fld" label="GPS-Grenze für Fotos (m)" type="number" min="20" max="2000" step="10" id="qo_gps" value="${qmE(cfg.gps_grenze_m || 150)}" ${darfCfg ? '' : 'disabled'}></md-outlined-text-field>
                        <div class="crm-sub">Fotos außerhalb dieser Entfernung zum Einsatz- bzw. Objektort werden als „nicht am Objekt“ markiert.</div>
                        <md-outlined-select class="crm-fld" label="Zuständiger Objektleiter" id="qo_ol" ${darfCfg ? '' : 'disabled'}>${qmUserOptions(cfg.objektleiter_id, '— keiner —')}</md-outlined-select>
                        <md-outlined-text-field class="crm-fld" label="Empfänger der Tagesberichte (E-Mail, mehrere mit Komma)" type="text" id="qo_mail" data-f="bericht_empfaenger" value="${qmE(cfg.bericht_empfaenger || '')}" ${darfCfg ? '' : 'disabled'}></md-outlined-text-field>
                        <div class="crm-sub">Jeden Morgen zum Beginn des Wachbuch-Tags (${qmE(qm.opt.einstellungen?.tag_beginn || '06:00')} Uhr) geht der Bericht des Vortags per E-Mail raus — nur wenn es Einträge gab, in der Kundenfassung.</div>
                        <md-outlined-text-field class="crm-fld" label="Notfallkontakte" type="textarea" id="qo_notfall" rows="3" ${darfCfg ? '' : 'disabled'} value="${qmE(cfg.notfallkontakte || '')}"></md-outlined-text-field>
                        <div class="crm-sub">Wird der Wachperson im Dienst angezeigt (App und Terminal).</div>
                        ${darfCfg ? `<div class="crm-form-actions"><md-filled-button onclick="qmObjCfgSpeichern('${qmE(objId)}')"><md-icon slot="icon">save</md-icon>Speichern</md-filled-button></div>` : '<div class="crm-muted">Nur der QMB ändert diese Einstellung.</div>'}
                    </div></div>
                <div class="crm-card qm-mt"><div class="crm-card-title">${qmIcon('key')} Schlüsselregister</div>
                    <div id="qmKeys" data-obj="${qmE(objId)}" class="crm-pad">${qmLoading()}</div></div>
            </div>
        </div>`;
    qmKeysLoad(objId);
}

async function qmKeysLoad(objId) {
    const r = await qmGet(QM_API + '/objekte/' + encodeURIComponent(objId) + '/schluessel');
    const box = document.getElementById('qmKeys');
    if (!box || box.dataset.obj !== objId) return;
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, `qmKeysLoad('${qmE(objId)}')`); return; }
    const keys = r.data.schluessel || [];
    box.innerHTML = `<div class="crm-sub">Nur physisch geprüfte, registrierte Schlüssel dürfen als Bestand im Tagesbericht gelten.</div>
        ${keys.length ? `<table class="crm-proto"><tbody>${keys.map(k => `<tr><td><strong>${qmE(k.kennung)}</strong> · ${qmE(k.bezeichnung)}</td>
            <td>${k.bestand_geprueft_am ? qmBadge('geprüft ' + qmDT(k.bestand_geprueft_am), 'ok') : qmBadge('Bestand nicht geprüft', 'warn')}</td>
            <td>${qmCan('objekt_einstellungen') && !k.bestand_geprueft_am ? `<md-text-button onclick="qmKeyPruefen('${qmE(objId)}',${k.id})">Physisch geprüft</md-text-button>` : ''}</td></tr>`).join('')}</tbody></table>` : '<div class="crm-muted">Noch keine Schlüssel registriert. Kein Bestand kann als vollständig bestätigt werden.</div>'}
        ${qmCan('objekt_einstellungen') ? `<div class="crm-form-actions">
            <md-outlined-text-field label="Kennung" id="qmKeyId"></md-outlined-text-field>
            <md-outlined-text-field label="Bezeichnung" id="qmKeyName"></md-outlined-text-field>
            <md-filled-tonal-button onclick="qmKeyAnlegen('${qmE(objId)}')">Registrieren</md-filled-tonal-button>
        </div>` : ''}`;
}

async function qmKeyAnlegen(objId) {
    const r = await qmReq('POST', QM_API + '/objekte/' + encodeURIComponent(objId) + '/schluessel', {
        kennung: qmV('qmKeyId'), bezeichnung: qmV('qmKeyName'),
    });
    if (qmFehler(r)) return;
    showToast('Schlüssel registriert. Physische Bestandsprüfung noch offen.');
    qmKeysLoad(objId);
}

async function qmKeyPruefen(objId, id) {
    if (!(await mdConfirm('Schlüssel physisch vorhanden und persönlich geprüft? Diese Bestätigung wird protokolliert.', {
        title: 'Bestand bestätigen', confirmLabel: 'Bestätigen',
    }))) return;
    const r = await qmReq('POST', QM_API + '/objekte/' + encodeURIComponent(objId) + '/schluessel/' + id + '/bestand', {});
    if (qmFehler(r)) return;
    showToast('Bestand protokolliert');
    qmKeysLoad(objId);
}

async function qmObjCfgSpeichern(objId) {
    const r = await qmReq('PUT', QM_API + '/objekte/' + encodeURIComponent(objId) + '/einstellung', {
        wachbuch_aktiv: qmV('qo_wb'), fotopflicht: qmV('qo_foto'), kunde_pdf: qmV('qo_kpdf'), gps_grenze_m: +qmV('qo_gps') || 150,
        bericht_pflicht: qmV('qo_bericht'), durchgehend_bewacht: qmV('qo_24h'), besucher_ausweis_pflicht: qmV('qo_ausweis'),
        lieferung_freigabe_pflicht: qmV('qo_freigabe'), uebergabe_checkliste: qmV('qo_uebergabe'),
        besuchszeit_ende: qmV('qo_besuch_ende') || null,
        objektleiter_id: qmV('qo_ol') ? +qmV('qo_ol') : null, bericht_empfaenger: qmV('qo_mail') || null,
        notfallkontakte: qmV('qo_notfall') || null,
    });
    if (qmFehler(r)) return;
    showToast('Gespeichert');
    await qmLoadOpt(true);
}

// ---------- upload (spec §4: PDF ≤ 20 MB, 1–8 points à 140 chars) ----------
function qmDaDialog(objId) {
    const punkt = (v = '') => `<div class="qm-kurz-row"><md-outlined-text-field class="crm-fld qm-kurz-in" type="text" data-max="140" value="${qmE(v)}" placeholder="z. B. Ausweis jeder Person am Tor prüfen"></md-outlined-text-field>${uiIconButton('close', { cls: 'crm-mini', title: 'Punkt entfernen', onclick: 'this.parentElement.remove()' })}</div>`;
    window._qmKurzPunkt = punkt;
    const freigabe = qm.opt.einstellungen?.da_freigabe_pflicht === '1' && !qmCan('da_freigeben');
    openModal('Neue Dienstanweisung', `
        <div class="crm-form">
            ${uiFilePicker({ id: 'qd_pdf', label: 'PDF (alle Seiten, max. 20 MB) *', accept: 'application/pdf', attrs: 'data-f="pdf"' })}
            <md-outlined-text-field class="crm-fld" label="Gültig ab *" id="qd_ab" type="date" data-f="gueltig_ab" value="${qmHeute()}"></md-outlined-text-field>
            <div class="crm-sec">Das Wichtigste (1–8 Punkte, je max. 140 Zeichen) — erscheint in der Mitarbeiter-App</div>
            <div id="qd_kurz" data-f="kurz">${punkt()}${punkt()}${punkt()}</div>
            <md-outlined-button onclick="if(document.querySelectorAll('.qm-kurz-in').length<8)document.getElementById('qd_kurz').insertAdjacentHTML('beforeend', window._qmKurzPunkt())"><md-icon slot="icon">add</md-icon>Punkt</md-outlined-button>
            <div class="crm-banner">${qmIcon('info')} ${freigabe ? 'Der Entwurf geht zur Freigabe an den QMB. Erst danach sehen ihn Mitarbeiter und Kunde.'
                : 'Wird sofort veröffentlicht. Mitarbeiter (eingeplant oder in 90 Tagen dort tätig) bekommen eine Push-Nachricht, der Kunde eine E-Mail.'}
                Verbindlich wird die neue Version am Datum „Gültig ab": Bis dahin gilt die bisherige weiter (auch beim Check-in), danach kommt sie ins Archiv.</div>
        </div>`,
        `<md-outlined-button onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button id="qdSave" onclick="qmDaSpeichern('${qmE(objId)}')"><md-icon slot="icon">upload</md-icon>${freigabe ? 'Zur Freigabe senden' : 'Veröffentlichen'}</md-filled-button>`);
}

async function qmDaSpeichern(objId) {
    const f = document.getElementById('qd_pdf').files[0];
    if (!f) return showToast('Bitte eine PDF-Datei wählen', 'error');
    if (f.size > 20 * 1024 * 1024) return showToast('Die PDF ist größer als 20 MB', 'error');
    const fd = new FormData();
    fd.append('pdf', f);
    fd.append('gueltig_ab', qmV('qd_ab'));
    document.querySelectorAll('.qm-kurz-in').forEach(i => { if (i.value.trim()) fd.append('kurz[]', i.value.trim()); });
    const btn = document.getElementById('qdSave'); if (btn) btn.disabled = true;
    const r = await qmReq('POST', QM_API + '/objekte/' + encodeURIComponent(objId) + '/dienstanweisungen', fd);
    if (btn) btn.disabled = false;
    if (qmFehler(r)) return;
    closeModal();
    showToast(r.data.dienstanweisung?.status === 'freigegeben' ? 'Veröffentlicht als Version ' + r.data.dienstanweisung.version : 'Entwurf zur Freigabe gesendet');
    qmObjDetail(objId);
}

function qmDaFreigabe(id, objId) {
    openModal('Dienstanweisung freigeben', `
        <div class="crm-form">
            <p>Bitte prüfen Sie die PDF vor der Freigabe. <a href="#" onclick="qmOpenPdf('${QM_API}/dienstanweisungen/${id}/pdf');return false">PDF öffnen</a></p>
            <md-outlined-text-field class="crm-fld" label="Begründung (nur bei Zurückweisen)" type="textarea" id="qf_grund" rows="3" data-f="grund"></md-outlined-text-field>
        </div>`,
        `<md-outlined-button onclick="qmDaEntscheid(${id},'${qmE(objId)}','zurueckweisen')"><md-icon slot="icon">undo</md-icon>Zurückweisen</md-outlined-button>
         <md-filled-button onclick="qmDaEntscheid(${id},'${qmE(objId)}','freigeben')"><md-icon slot="icon">verified</md-icon>Freigeben &amp; veröffentlichen</md-filled-button>`);
}

async function qmDaEntscheid(id, objId, aktion) {
    const r = await qmReq('POST', QM_API + '/dienstanweisungen/' + id + '/freigabe', { aktion, grund: qmV('qf_grund') });
    if (qmFehler(r)) return;
    closeModal();
    showToast(aktion === 'freigeben' ? 'Freigegeben und verteilt' : 'Zurückgewiesen');
    qmObjDetail(objId);
}
