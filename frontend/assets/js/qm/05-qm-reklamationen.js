/* ============================================
   QM 05 — tab "Reklamationen" (ISO 9001 8.2.1 / 10.2)
   Eingang → Eingangsbestätigung → Sofortmaßnahme → Ursache (berechtigt?) →
   Antwort an Kunde → Abschluss. Buttons follow the Rechte-Tabelle.
   ============================================ */

const QM_REK_TABS = [['offen', 'Offen'], ['ueberfaellig', 'Überfällig'], ['meine', 'Mir zugewiesen'], ['abgeschlossen', 'Abgeschlossen'], ['alle', 'Alle']];
const QM_REK_STATUS_TON = { eingegangen: 'warn', bestaetigt: 'info', in_bearbeitung: 'info', beantwortet: 'ok', abgeschlossen: '' };
const QM_SCHWERE_TON = { gering: '', mittel: 'info', hoch: 'warn', kritisch: 'err' };
hmsPagerDef('qmRekPager', 'Reklamationen', () => qmRekApply());

function qmRekRender() {
    const body = document.getElementById('qmBody');
    body.innerHTML = `
        <div class="data-bento" id="qmRekBento"></div>
        <div class="data-toolbar">
            <div class="data-toolbar-left">
                ${qmMdSearch('rf', 'Reklamationen suchen', 'Nr., Text, Objekt, Kunde…')}
                ${qmMdFilter('rf', 'obj_id', 'Objekt', qm.opt.objekte.map(o => ({ key: o.obj_id, name: o.objektname })), 'Alle Objekte')}
                ${qmMdFilter('rf', 'schwere', 'Schwere', qmListe('rek_schwere'), 'Alle')}
            </div>
            <div class="crm-toolbar-actions">${qmCan('reklamation_anlegen') ? `<md-filled-button onclick="qmRekNeu()"><md-icon slot="icon">add_comment</md-icon>Neue Reklamation</md-filled-button>` : ''}</div>
        </div>
        <div class="data-table-card"><div id="qmRekRows">${qmLoading()}</div><div id="qmRekPager" class="be-pager"></div></div>`;
    qmWire(body);
    if (qm.detail?.typ === 'reklamationen') return qmRekDetail(qm.detail.id);
    qmRekLaden();
}

async function qmRekLaden() {
    const seq = ++qm.seq;
    const r = await qmGet(QM_API + '/reklamationen' + qmQs(qm.rf));
    if (seq !== qm.seq || qm.view !== 'reklamationen' || qm.detail) return;
    const box = document.getElementById('qmRekRows');
    if (!box) return;
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, 'qmRekLaden()'); return; }
    qm.cache.rek = r.data;
    const z = r.data.zaehler || {};
    qmPaint(document.getElementById('qmRekBento'), QM_REK_TABS.map(([k, l], i) => qmTile({
        hero: i === 0, cls: (k === 'ueberfaellig' && z[k] ? 'bento-tile-warn crm-tile-red' : (i === 0 ? 'bento-tile-total' : '')),
        on: qm.rf.tab === k, icon: { offen: 'feedback', ueberfaellig: 'alarm', meine: 'person', abgeschlossen: 'task_alt', alle: 'list' }[k],
        label: l, n: z[k] ?? '–', click: `qm.rf.tab='${k}';qmRekLaden()`,
    })).join(''));
    qmRekApply();
}

function qmRekApply() {
    const d = qm.cache.rek; const box = document.getElementById('qmRekRows');
    if (!d || !box) return;
    const pg = hmsPage(d.data || [], HMS_PAGERS.qmRekPager.st);
    hmsPagerFill('qmRekPager', pg);
    qmPaint(box, pg.rows.length ? `<div class="table-container"><table class="crm-table qm-table">
        <thead><tr><th>Nr.</th><th>Eingang</th><th>Objekt</th><th>Sachverhalt</th><th>Schwere</th><th>Status</th><th>Zuständig</th><th>Frist</th></tr></thead>
        <tbody>${pg.rows.map(x => `<tr class="crm-row" onclick="qmRekDetail(${x.id})">
            <td><strong>${qmE(x.nr)}</strong></td><td>${qmE(qmDate(x.eingang_am))}<div class="crm-sub">${qmE(qmListe('rek_kanal')[x.kanal] || x.kanal)}</div></td>
            <td>${qmE(x.objektname)}<div class="crm-sub">${qmE(x.kundenname || '')}</div></td>
            <td class="qm-clip">${qmE((x.text || '').slice(0, 140))}</td>
            <td>${qmBadge(qmListe('rek_schwere')[x.schwere] || x.schwere, QM_SCHWERE_TON[x.schwere])}</td>
            <td>${qmBadge(qmListe('rek_status')[x.status] || x.status, QM_REK_STATUS_TON[x.status])}${x.bestaetigung_ueberfaellig ? ' ' + qmBadge('Bestätigung überfällig', 'err') : ''}</td>
            <td>${qmE(x.zustaendig || '–')}</td>
            <td>${x.status === 'abgeschlossen' ? '–' : (x.ueberfaellig ? qmBadge(qmDate(x.frist_erledigung), 'err') : qmE(qmDate(x.frist_erledigung)))}</td>
        </tr>`).join('')}</tbody></table></div>${d.mehr ? `<div class="crm-more">${qmIcon('filter_alt')} Es werden die ersten 500 angezeigt — bitte filtern.</div>` : ''}`
        : qmEmpty('feedback', 'Keine Reklamationen in dieser Ansicht.'));
}

function qmRekNeu(preset = {}) {
    openModal('Neue Reklamation', `
        <div class="crm-form crm-form-grid">
            <md-outlined-select class="crm-fld crm-span2" label="Objekt *" id="qr_obj" data-f="obj_id"><md-select-option value=""><div slot="headline">— wählen —</div></md-select-option>${qmObjOptions(preset.obj_id)}</md-outlined-select>
            <md-outlined-select class="crm-fld" label="Eingangsweg" id="qr_kanal">${qmSelectOptions(qmListe('rek_kanal'), preset.kanal || 'telefon')}</md-outlined-select>
            <md-outlined-text-field class="crm-fld" label="Eingang am" id="qr_eingang" type="datetime-local" value="${new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)}"></md-outlined-text-field>
            <md-outlined-text-field class="crm-fld" label="Gemeldet von" type="text" id="qr_melder" data-max="200" placeholder="Name beim Kunden"></md-outlined-text-field>
            <md-outlined-select class="crm-fld" label="Schwere *" id="qr_schwere">${qmSelectOptions(qmListe('rek_schwere'), 'mittel')}</md-outlined-select>
            <md-outlined-select class="crm-fld" label="Kategorie" id="qr_kat">${qmSelectOptions(qmListe('rek_kategorien'), '', '—')}</md-outlined-select>
            <md-outlined-select class="crm-fld" label="Zuständig" id="qr_zust">${qmUserOptions('', 'Objektleiter des Objekts')}</md-outlined-select>
            <md-outlined-text-field class="crm-fld crm-span2" label="Sachverhalt *" type="textarea" id="qr_text" rows="5" maxlength="5000" data-f="text"></md-outlined-text-field>
        </div>`,
        `<md-outlined-button onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button id="qrSave" onclick="qmRekSpeichern()"><md-icon slot="icon">save</md-icon>Anlegen</md-filled-button>`, 'modal-lg');
}

async function qmRekSpeichern() {
    const btn = document.getElementById('qrSave'); if (btn) btn.disabled = true;
    const r = await qmReq('POST', QM_API + '/reklamationen', {
        obj_id: qmV('qr_obj'), kanal: qmV('qr_kanal'), eingang_am: qmV('qr_eingang'), melder: qmV('qr_melder'),
        schwere: qmV('qr_schwere'), kategorie: qmV('qr_kat') || null, zustaendig_id: qmV('qr_zust') ? +qmV('qr_zust') : null, text: qmV('qr_text'),
    });
    if (btn) btn.disabled = false;
    if (qmFehler(r)) return;
    closeModal();
    showToast('Reklamation ' + r.data.nr + ' angelegt');
    qmGehe('reklamationen', r.data.id);
}

async function qmRekDetail(id) {
    qm.detail = { typ: 'reklamationen', id };
    const body = document.getElementById('qmBody');
    body.innerHTML = qmLoading();
    const r = await qmGet(QM_API + '/reklamationen/' + id);
    if (!qm.detail || qm.detail.id !== id) return;
    if (!r.ok) { body.innerHTML = qmFehlerBox(r, `qmRekDetail(${id})`); return; }
    const x = r.data, offen = x.status !== 'abgeschlossen';
    const btn = (aktion, recht, icon, label, primary = false) => offen && qmCan(recht)
        ? `<md-${primary ? 'filled' : 'outlined'}-button onclick="qmRekAktionDialog(${id},'${aktion}')"><md-icon slot="icon">${icon}</md-icon>${label}</md-${primary ? 'filled' : 'outlined'}-button>` : '';
    body.innerHTML = `
        <div class="crm-detail-top"><md-outlined-button onclick="qm.detail=null;qmRekRender()"><md-icon slot="icon">arrow_back</md-icon>Zur Liste</md-outlined-button>
            <div class="crm-actions">
                ${!x.bestaetigt_am ? btn('bestaetigen', 'reklamation_antworten', 'mark_email_read', 'Eingang bestätigen', true) : ''}
                ${btn('sofortmassnahme', 'reklamation_bearbeiten', 'bolt', 'Sofortmaßnahme')}
                ${btn('ursache', 'reklamation_bearbeiten', 'search', 'Ursache')}
                ${btn('antworten', 'reklamation_antworten', 'reply', 'Antwort an Kunde', !!x.bestaetigt_am && !x.antwort)}
                ${btn('zustaendig', 'reklamation_zustaendig', 'person_search', 'Zuständigkeit')}
                ${qmCan('massnahme_bearbeiten') ? `<md-outlined-button onclick="qmMnNeu({quelle_typ:'reklamation', quelle_id:'${id}', obj_id:'${qmE(x.obj_id)}', titel:${qmE(JSON.stringify('Reklamation ' + x.nr))}, ursache:${qmE(JSON.stringify(x.ursache || ''))}})"><md-icon slot="icon">add_task</md-icon>Maßnahme</md-outlined-button>` : ''}
                ${btn('abschliessen', 'reklamation_abschliessen', 'task_alt', 'Abschließen', !!x.antwort)}
                ${btn('notiz', 'reklamation_anlegen', 'sticky_note_2', 'Notiz')}
            </div></div>
        <div class="crm-head">
            <div><div class="crm-head-firma">${qmE(x.objektname)} · ${qmE(x.kundenname || '')}</div>
                <h2 class="crm-head-title">${qmE(x.nr)}</h2>
                <div class="crm-head-meta">${qmBadge(qmListe('rek_status')[x.status] || x.status, QM_REK_STATUS_TON[x.status])}
                    ${qmBadge(qmListe('rek_schwere')[x.schwere] || x.schwere, QM_SCHWERE_TON[x.schwere])}
                    ${x.kategorie ? qmBadge(qmListe('rek_kategorien')[x.kategorie] || x.kategorie) : ''}
                    <span>Eingang ${qmE(qmDT(x.eingang_am))} · ${qmE(qmListe('rek_kanal')[x.kanal] || x.kanal)}${x.melder ? ' · ' + qmE(x.melder) : ''}</span></div></div>
            <div class="crm-head-side">
                <div>Zuständig: <strong>${qmE(x.zustaendig || '–')}</strong></div>
                <div>Bestätigung bis ${qmE(qmDT(x.frist_bestaetigung))} ${x.bestaetigung_ueberfaellig ? qmBadge('überfällig', 'err') : (x.bestaetigt_am ? qmBadge('erledigt', 'ok') : '')}</div>
                <div>Erledigung bis ${qmE(qmDate(x.frist_erledigung))} ${x.ueberfaellig ? qmBadge('überfällig', 'err') : ''}</div>
            </div>
        </div>
        <div class="crm-grid">
            <div>
                <div class="crm-card"><div class="crm-card-title">${qmIcon('description')} Sachverhalt</div><div class="crm-pad qm-pre">${qmE(x.text)}</div>
                    ${x.mitarbeiter ? `<div class="crm-pad crm-sub">Betroffener Mitarbeiter: ${qmE(x.mitarbeiter)}</div>` : ''}</div>
                <div class="crm-card"><div class="crm-card-title">${qmIcon('history')} Bearbeitung</div>
                    <div class="crm-timeline">${(x.schritte || []).map(s => `<div class="crm-tl-item"><div class="crm-tl-icon">${qmIcon({ eingang: 'inbox', bestaetigung: 'mark_email_read', sofortmassnahme: 'bolt', ursache: 'search', antwort: 'reply', zustaendig: 'person_search', abschluss: 'task_alt', massnahme: 'add_task', eskalation: 'priority_high' }[s.art] || 'sticky_note_2')}</div>
                        <div class="crm-tl-body"><div class="crm-tl-head"><strong>${qmE(qmListe('rek_schritte')[s.art] || s.art)}</strong>${s.an_kunde ? ' ' + qmBadge('an Kunde', 'info') : ''} <span class="crm-tl-meta">${qmE(qmDT(s.am))} · ${qmE(s.von_name || '')}</span></div>
                        ${s.text ? `<div class="crm-tl-text qm-pre">${qmE(s.text)}</div>` : ''}</div></div>`).join('')}</div></div>
            </div>
            <div>
                <div class="crm-card"><div class="crm-card-title">${qmIcon('assignment')} Ergebnis</div>
                    <div class="crm-kv crm-pad">
                        <div><span>Sofortmaßnahme</span><div class="qm-pre">${qmE(x.sofortmassnahme || '–')}</div></div>
                        <div><span>Ursache</span><div class="qm-pre">${qmE(x.ursache || '–')}</div></div>
                        <div><span>Berechtigt</span><div>${x.berechtigt === null ? '–' : (x.berechtigt ? 'ja' : 'nein')}</div></div>
                        <div><span>Antwort an Kunde</span><div class="qm-pre">${qmE(x.antwort || '–')}</div></div>
                        ${x.kosten_eur != null ? `<div><span>Kosten</span><div>${qmE(formatCurrency(x.kosten_eur))}</div></div>` : ''}
                    </div></div>
                <div class="crm-card"><div class="crm-card-title">${qmIcon('task_alt')} Maßnahmen</div>
                    ${(x.massnahmen || []).map(m => `<div class="crm-link-row" onclick="qmGehe('massnahmen', ${m.id})">${qmIcon('chevron_right')} <strong>${qmE(m.nr)}</strong> ${qmE(m.titel)} ${qmBadge(qmListe('mn_status')[m.status] || m.status, m.ueberfaellig ? 'err' : '')}</div>`).join('') || '<div class="crm-muted crm-pad">Keine</div>'}</div>
                <details class="crm-card"><summary class="crm-card-title">${qmIcon('receipt_long')} Verlauf</summary>${qmVerlaufHtml(x.verlauf)}</details>
            </div>
        </div>`;
    qm.cache.rekDetail = x;
}

function qmRekAktionDialog(id, aktion) {
    const x = qm.cache.rekDetail || {};
    const titel = { bestaetigen: 'Eingangsbestätigung an den Kunden', sofortmassnahme: 'Sofortmaßnahme', ursache: 'Ursache',
        antworten: 'Antwort an den Kunden', zustaendig: 'Zuständigkeit ändern', abschliessen: 'Reklamation abschließen', notiz: 'Notiz' }[aktion];
    const text = (label, val = '', ph = '') => `<md-outlined-text-field class="crm-fld" label="${label}" type="textarea" id="qa_text" rows="5" maxlength="5000" placeholder="${qmE(ph)}" value="${qmE(val)}"></md-outlined-text-field>`;
    const felder = {
        bestaetigen: text('Text der Bestätigung (leer = Standardtext)', '', 'Vielen Dank für Ihre Meldung …') + `<div class="crm-muted">Geht per E-Mail an den Kunden und erscheint im Kundenportal.</div>`,
        sofortmassnahme: text('Was wurde sofort getan? *', x.sofortmassnahme || ''),
        ursache: text('Ursache (intern, nie im Kundenportal) *', x.ursache || '') + `<div class="crm-radio-row"><label><md-radio name="qa_ber" value="1" ${x.berechtigt === true ? 'checked' : ''}></md-radio> berechtigt</label><label><md-radio name="qa_ber" value="0" ${x.berechtigt === false ? 'checked' : ''}></md-radio> nicht berechtigt</label></div>`,
        antworten: text('Antwort *', x.antwort || '') + `<div class="crm-muted">Geht per E-Mail an den Kunden und erscheint im Kundenportal. Keine internen Ursachen oder Namen nennen.</div>`,
        zustaendig: `<md-outlined-select class="crm-fld" label="Neu zuständig *" id="qa_user">${qmUserOptions(x.zustaendig_id)}</md-outlined-select>` + text('Bemerkung'),
        abschliessen: `<md-outlined-text-field class="crm-fld" label="Kosten in € (optional)" id="qa_kosten" type="number" min="0" step="0.01"></md-outlined-text-field>` + text('Abschlussvermerk'),
        notiz: text('Notiz (intern) *'),
    }[aktion];
    openModal(titel, `<div class="crm-form">${felder}</div>`,
        `<md-outlined-button onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button id="qaSave" onclick="qmRekAktion(${id},'${aktion}')"><md-icon slot="icon">save</md-icon>Speichern</md-filled-button>`);
}

async function qmRekAktion(id, aktion) {
    const body = { text: qmV('qa_text') || '' };
    if (aktion === 'ursache') { const b = [...document.querySelectorAll('md-radio[name="qa_ber"]')].find(r => r.checked); body.berechtigt = b ? b.value === '1' : null; }
    if (aktion === 'zustaendig') body.zustaendig_id = +qmV('qa_user');
    if (aktion === 'abschliessen') body.kosten_eur = qmV('qa_kosten');
    const btn = document.getElementById('qaSave'); if (btn) btn.disabled = true;
    const r = await qmReq('POST', QM_API + '/reklamationen/' + id + '/' + aktion, body);
    if (btn) btn.disabled = false;
    if (qmFehler(r)) return;
    closeModal();
    showToast('Gespeichert');
    qmRekDetail(id);
}
