/* ============================================
   QM 06 — tab "Maßnahmen" (ISO 9001 10.2 / 10.3)
   Quelle (Reklamation / Wachbuch / manuell …), Verantwortlich, Frist,
   Verlängerung mit Begründung, Erledigung mit Nachweis, Wirksamkeit nach
   N Tagen, Folgemaßnahme bei "nicht wirksam".
   ============================================ */

const QM_MN_TABS = [['offen', 'Offen'], ['ueberfaellig', 'Überfällig'], ['pruefung', 'Wirksamkeit prüfen'], ['meine', 'Mir zugewiesen'], ['abgeschlossen', 'Abgeschlossen'], ['alle', 'Alle']];
const QM_MN_TON = { offen: 'info', erledigt: 'warn', wirksam: 'ok', unwirksam: 'err' };
hmsPagerDef('qmMnPager', 'Maßnahmen', () => qmMnApply());

function qmMnRender() {
    const body = document.getElementById('qmBody');
    body.innerHTML = `
        <div class="data-bento" id="qmMnBento"></div>
        <div class="data-toolbar">
            <div class="data-toolbar-left">
                ${qmMdSearch('mf', 'Maßnahmen suchen', 'Nr., Titel, Objekt…')}
                ${qmMdFilter('mf', 'quelle', 'Quelle', qmListe('mn_quellen'), 'Alle Quellen')}
                ${qmMdFilter('mf', 'obj_id', 'Objekt', qm.opt.objekte.map(o => ({ key: o.obj_id, name: o.objektname })), 'Alle Objekte')}
            </div>
            <div class="crm-toolbar-actions">${qmCan('massnahme_bearbeiten') ? `<md-filled-button onclick="qmMnNeu()"><md-icon slot="icon">add_task</md-icon>Neue Maßnahme</md-filled-button>` : ''}</div>
        </div>
        <div class="data-table-card"><div id="qmMnRows">${qmLoading()}</div><div id="qmMnPager" class="be-pager"></div></div>`;
    qmWire(body);
    if (qm.detail?.typ === 'massnahmen') return qmMnDetail(qm.detail.id);
    qmMnLaden();
}

async function qmMnLaden() {
    const seq = ++qm.seq;
    const r = await qmGet(QM_API + '/massnahmen' + qmQs(qm.mf));
    if (seq !== qm.seq || qm.view !== 'massnahmen' || qm.detail) return;
    const box = document.getElementById('qmMnRows');
    if (!box) return;
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, 'qmMnLaden()'); return; }
    qm.cache.mn = r.data;
    const z = r.data.zaehler || {};
    // 5 tiles (hero + 4) fill the bento grid; "Alle" sits in the hero footer.
    qmPaint(document.getElementById('qmMnBento'), QM_MN_TABS.filter(([k]) => k !== 'alle').map(([k, l], i) => qmTile({
        hero: i === 0, cls: (k === 'ueberfaellig' && z[k] ? 'bento-tile-warn crm-tile-red' : (k === 'pruefung' && z[k] ? 'crm-tile-amber' : (i === 0 ? 'bento-tile-total' : ''))),
        on: qm.mf.tab === k, icon: { offen: 'task_alt', ueberfaellig: 'event_busy', pruefung: 'fact_check', meine: 'person', abgeschlossen: 'verified', alle: 'list' }[k],
        label: l, n: z[k] ?? '–', click: `qm.mf.tab='${k}';qmMnLaden()`,
        foot: i === 0 ? `<span class="bento-foot-pill${qm.mf.tab === 'alle' ? ' qm-pill-on' : ''}" onclick="event.stopPropagation();qm.mf.tab='alle';qmMnLaden()">${qmIcon('list', 'font-size:14px')} Alle ${z.alle ?? ''}</span>` : '',
    })).join(''));
    qmMnApply();
}

function qmMnApply() {
    const d = qm.cache.mn; const box = document.getElementById('qmMnRows');
    if (!d || !box) return;
    const pg = hmsPage(d.data || [], HMS_PAGERS.qmMnPager.st);
    hmsPagerFill('qmMnPager', pg);
    qmPaint(box, pg.rows.length ? `<div class="table-container"><table class="crm-table qm-table">
        <thead><tr><th>Nr.</th><th>Maßnahme</th><th>Quelle</th><th>Objekt</th><th>Verantwortlich</th><th>Frist</th><th>Status</th></tr></thead>
        <tbody>${pg.rows.map(m => `<tr class="crm-row" onclick="qmMnDetail(${m.id})">
            <td><strong>${qmE(m.nr)}</strong></td><td>${qmE(m.titel)}<div class="crm-sub">${qmE(qmListe('mn_arten')[m.art] || m.art)}</div></td>
            <td>${qmE(qmListe('mn_quellen')[m.quelle_typ] || m.quelle_typ)}</td><td>${qmE(m.objektname || '–')}</td><td>${qmE(m.verantwortlich || '–')}</td>
            <td>${m.ueberfaellig ? qmBadge(qmDate(m.frist), 'err') : qmE(qmDate(m.frist))}${m.frist_verlaengert ? ` <span class="crm-muted">(${m.frist_verlaengert}× verl.)</span>` : ''}</td>
            <td>${qmBadge(qmListe('mn_status')[m.status] || m.status, QM_MN_TON[m.status])}${m.pruefung_faellig ? ' ' + qmBadge('Prüfung fällig', 'warn') : ''}${m.im_kundenportal ? ' ' + qmBadge('im Portal', 'info') : ''}</td>
        </tr>`).join('')}</tbody></table></div>${d.mehr ? `<div class="crm-more">${qmIcon('filter_alt')} Es werden die ersten 500 angezeigt — bitte filtern.</div>` : ''}`
        : qmEmpty('task_alt', 'Keine Maßnahmen in dieser Ansicht.'));
}

/** preset: {quelle_typ, quelle_id, obj_id, titel, ursache} — also used from Reklamation and Wachbuch. */
function qmMnNeu(preset = {}) {
    openModal('Neue Maßnahme', `
        <div class="crm-form crm-form-grid">
            ${preset.quelle_typ && preset.quelle_typ !== 'manuell' ? `<div class="crm-banner crm-span2">${qmIcon('link')} Quelle: ${qmE(qmListe('mn_quellen')[preset.quelle_typ] || preset.quelle_typ)}</div>` : ''}
            <md-outlined-text-field class="crm-fld crm-span2" label="Titel *" type="text" id="qm_titel" data-max="200" data-f="titel" value="${qmE(preset.titel || '')}"></md-outlined-text-field>
            <md-outlined-select class="crm-fld" label="Art" id="qm_art">${qmSelectOptions(qmListe('mn_arten'), 'korrektur')}</md-outlined-select>
            <md-outlined-select class="crm-fld" label="Objekt${qm.opt.ich.alle_objekte ? '' : ' *'}" id="qm_obj" data-f="obj_id"><md-select-option value=""><div slot="headline">${qm.opt.ich.alle_objekte ? '— übergreifend —' : '— wählen —'}</div></md-select-option>${qmObjOptions(preset.obj_id)}</md-outlined-select>
            <md-outlined-select class="crm-fld" label="Verantwortlich *" id="qm_ver" data-f="verantwortlich_id">${qmUserOptions(qm.opt.ich.id)}</md-outlined-select>
            <md-outlined-text-field class="crm-fld" label="Frist *" id="qm_frist" type="date" data-f="frist" min="${qmHeute()}" value="${qmAddDays(14)}"></md-outlined-text-field>
            <md-outlined-text-field class="crm-fld crm-span2" label="Ursache" type="textarea" id="qm_urs" rows="2" maxlength="4000" value="${qmE(preset.ursache || '')}"></md-outlined-text-field>
            <md-outlined-text-field class="crm-fld crm-span2" label="Beschreibung" type="textarea" id="qm_text" rows="3" maxlength="4000"></md-outlined-text-field>
            <label class="crm-switch-row crm-span2"><md-checkbox id="qm_portal"></md-checkbox> Im Kundenportal anzeigen (ohne Ursache und Namen)</label>
        </div>`,
        `<md-outlined-button onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button id="qmSave" onclick='qmMnSpeichern(${JSON.stringify({ quelle_typ: preset.quelle_typ || "manuell", quelle_id: preset.quelle_id || null })})'><md-icon slot="icon">save</md-icon>Anlegen</md-filled-button>`, 'modal-lg');
}

async function qmMnSpeichern(quelle) {
    const btn = document.getElementById('qmSave'); if (btn) btn.disabled = true;
    const r = await qmReq('POST', QM_API + '/massnahmen', {
        ...quelle, titel: qmV('qm_titel'), art: qmV('qm_art'), obj_id: qmV('qm_obj') || null,
        verantwortlich_id: +qmV('qm_ver') || null, frist: qmV('qm_frist'), ursache: qmV('qm_urs'), text: qmV('qm_text'), im_kundenportal: qmV('qm_portal'),
    });
    if (btn) btn.disabled = false;
    if (qmFehler(r)) return;
    closeModal();
    showToast('Maßnahme ' + r.data.nr + ' angelegt');
    if (currentPage === 'qm' && qm.view === 'reklamationen' && qm.detail) return qmRekDetail(qm.detail.id);
    if (currentPage === 'qm_wachbuch') return qmWbRender();
    qmGehe('massnahmen', r.data.id);
}

async function qmMnDetail(id) {
    qm.detail = { typ: 'massnahmen', id };
    const body = document.getElementById('qmBody');
    body.innerHTML = qmLoading();
    const r = await qmGet(QM_API + '/massnahmen/' + id);
    if (!qm.detail || qm.detail.id !== id) return;
    if (!r.ok) { body.innerHTML = qmFehlerBox(r, `qmMnDetail(${id})`); return; }
    const m = r.data, ed = qmCan('massnahme_bearbeiten');
    qm.cache.mnDetail = m;
    const quelle = m.quelle ? (m.quelle_typ === 'reklamation'
        ? `<a href="#" onclick="qmGehe('reklamationen', ${m.quelle.id});return false">${qmE(m.quelle.nr)}</a> — ${qmE((m.quelle.text || '').slice(0, 160))}`
        : `${qmE(qmLabel())}-Eintrag #${qmE(m.quelle.nr)} vom ${qmE(qmDT(m.quelle.zeit))} — ${qmE((m.quelle.text || '').slice(0, 160))}`) : '–';
    body.innerHTML = `
        <div class="crm-detail-top"><md-outlined-button onclick="qm.detail=null;qmMnRender()"><md-icon slot="icon">arrow_back</md-icon>Zur Liste</md-outlined-button>
            <div class="crm-actions">
                ${ed && m.status === 'offen' ? `<md-outlined-button onclick="qmMnVerlaengernDialog(${id})"><md-icon slot="icon">update</md-icon>Frist verlängern</md-outlined-button>
                    <md-outlined-button onclick="qmMnBearbeitenDialog(${id})"><md-icon slot="icon">edit</md-icon>Bearbeiten</md-outlined-button>
                    <md-filled-button onclick="qmMnErledigenDialog(${id})"><md-icon slot="icon">done_all</md-icon>Erledigt</md-filled-button>` : ''}
                ${m.status === 'erledigt' && qmCan('massnahme_wirksamkeit') ? `<md-filled-button onclick="qmMnWirksamDialog(${id})"><md-icon slot="icon">fact_check</md-icon>Wirksamkeit bewerten</md-filled-button>` : ''}
            </div></div>
        <div class="crm-head">
            <div><div class="crm-head-firma">${qmE(m.objektname || 'übergreifend')}</div>
                <h2 class="crm-head-title">${qmE(m.nr)} · ${qmE(m.titel)}</h2>
                <div class="crm-head-meta">${qmBadge(qmListe('mn_status')[m.status] || m.status, QM_MN_TON[m.status])} ${qmBadge(qmListe('mn_arten')[m.art] || m.art)}
                    ${m.im_kundenportal ? qmBadge('im Kundenportal', 'info') : ''}</div></div>
            <div class="crm-head-side">
                <div>Verantwortlich: <strong>${qmE(m.verantwortlich || '–')}</strong></div>
                <div>Frist ${qmE(qmDate(m.frist))} ${m.ueberfaellig ? qmBadge('überfällig', 'err') : ''}${m.frist_verlaengert ? ` <span class="crm-muted">(${m.frist_verlaengert}× verlängert)</span>` : ''}</div>
                ${m.wirksam_pruefung_am ? `<div>Wirksamkeitsprüfung ${qmE(qmDate(m.wirksam_pruefung_am))} ${m.pruefung_faellig ? qmBadge('fällig', 'warn') : ''}</div>` : ''}
            </div>
        </div>
        <div class="crm-grid">
            <div>
                <div class="crm-card"><div class="crm-card-title">${qmIcon('link')} Quelle</div><div class="crm-pad">${qmE(qmListe('mn_quellen')[m.quelle_typ] || m.quelle_typ)}: ${quelle}</div></div>
                <div class="crm-card"><div class="crm-card-title">${qmIcon('description')} Inhalt</div>
                    <div class="crm-kv crm-pad">
                        <div><span>Ursache</span><div class="qm-pre">${qmE(m.ursache || '–')}</div></div>
                        <div><span>Beschreibung</span><div class="qm-pre">${qmE(m.text || '–')}</div></div>
                        <div><span>Nachweis</span><div class="qm-pre">${qmE(m.nachweis || '–')}${m.erledigt_am ? `<div class="crm-sub">${qmE(qmDT(m.erledigt_am))} · ${qmE(m.erledigt_von || '')}</div>` : ''}</div></div>
                        <div><span>Wirksamkeit</span><div class="qm-pre">${m.wirksamkeit_am ? qmE((m.status === 'wirksam' ? 'wirksam' : 'nicht wirksam') + ' — ' + (m.wirksamkeit_text || '')) + `<div class="crm-sub">${qmE(qmDT(m.wirksamkeit_am))} · ${qmE(m.wirksamkeit_von || '')}</div>` : '–'}</div></div>
                        ${m.folge ? `<div><span>Folgemaßnahme</span><div><a href="#" onclick="qmMnDetail(${m.folge.id});return false">${qmE(m.folge.nr)} ${qmE(m.folge.titel)}</a></div></div>` : ''}
                    </div></div>
            </div>
            <div><details class="crm-card" open><summary class="crm-card-title">${qmIcon('receipt_long')} Verlauf</summary>${qmVerlaufHtml(m.verlauf)}</details></div>
        </div>`;
}

function qmMnBearbeitenDialog(id) {
    const m = qm.cache.mnDetail;
    openModal('Maßnahme bearbeiten', `
        <div class="crm-form crm-form-grid">
            <md-outlined-text-field class="crm-fld crm-span2" label="Titel *" type="text" id="qm_titel" data-max="200" data-f="titel" value="${qmE(m.titel)}"></md-outlined-text-field>
            <md-outlined-select class="crm-fld" label="Art" id="qm_art">${qmSelectOptions(qmListe('mn_arten'), m.art)}</md-outlined-select>
            <md-outlined-select class="crm-fld" label="Verantwortlich" id="qm_ver">${qmUserOptions(m.verantwortlich_id)}</md-outlined-select>
            <md-outlined-text-field class="crm-fld crm-span2" label="Ursache" type="textarea" id="qm_urs" rows="2" value="${qmE(m.ursache || '')}"></md-outlined-text-field>
            <md-outlined-text-field class="crm-fld crm-span2" label="Beschreibung" type="textarea" id="qm_text" rows="3" value="${qmE(m.text || '')}"></md-outlined-text-field>
            <label class="crm-switch-row crm-span2"><md-checkbox id="qm_portal" ${m.im_kundenportal ? 'checked' : ''}></md-checkbox> Im Kundenportal anzeigen</label>
        </div>`,
        `<md-outlined-button onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button onclick="qmMnPatch(${id})"><md-icon slot="icon">save</md-icon>Speichern</md-filled-button>`, 'modal-lg');
}
async function qmMnPatch(id) {
    const r = await qmReq('PUT', QM_API + '/massnahmen/' + id, { titel: qmV('qm_titel'), art: qmV('qm_art'), verantwortlich_id: +qmV('qm_ver'),
        ursache: qmV('qm_urs'), text: qmV('qm_text'), im_kundenportal: qmV('qm_portal') });
    if (qmFehler(r)) return;
    closeModal(); showToast('Gespeichert'); qmMnDetail(id);
}

function qmMnVerlaengernDialog(id) {
    const m = qm.cache.mnDetail;
    openModal('Frist verlängern', `<div class="crm-form">
        <div class="crm-muted">Bisher ${qmE(qmDate(m.frist))}${m.frist_verlaengert ? ` · bereits ${m.frist_verlaengert}× verlängert (max. ${qmE(qm.opt.einstellungen?.mn_max_verlaengerung || 2)})` : ''}</div>
        <md-outlined-text-field class="crm-fld" label="Neue Frist *" id="qv_frist" type="date" data-f="frist" min="${qmAddDays(1, m.frist)}" value="${qmAddDays(14, m.frist)}"></md-outlined-text-field>
        <md-outlined-text-field class="crm-fld" label="Begründung *" type="textarea" id="qv_grund" rows="3" data-f="grund"></md-outlined-text-field></div>`,
        `<md-outlined-button onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button onclick="qmMnAktion(${id},'verlaengern',{frist:qmV('qv_frist'),grund:qmV('qv_grund')})"><md-icon slot="icon">update</md-icon>Verlängern</md-filled-button>`);
}
function qmMnErledigenDialog(id) {
    openModal('Maßnahme erledigt', `<div class="crm-form">
        <md-outlined-text-field class="crm-fld" label="Nachweis (was wurde getan, wo ist es dokumentiert?) *" type="textarea" id="qe_nachweis" rows="4" data-f="nachweis"></md-outlined-text-field>
        <div class="crm-muted">Die Wirksamkeit wird nach ${qmE(qm.opt.einstellungen?.mn_wirksam_tage || 30)} Tagen vom QMB geprüft.</div></div>`,
        `<md-outlined-button onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button onclick="qmMnAktion(${id},'erledigen',{nachweis:qmV('qe_nachweis')})"><md-icon slot="icon">done_all</md-icon>Erledigt</md-filled-button>`);
}
function qmMnWirksamDialog(id) {
    openModal('Wirksamkeit bewerten', `<div class="crm-form">
        <div class="crm-radio-row"><label><md-radio name="qw_erg" value="wirksam" checked onchange="document.getElementById('qwFolge').hidden=true"></md-radio> wirksam</label>
            <label><md-radio name="qw_erg" value="unwirksam" onchange="document.getElementById('qwFolge').hidden=false"></md-radio> nicht wirksam</label></div>
        <md-outlined-text-field class="crm-fld" label="Begründung *" type="textarea" id="qw_text" rows="3" data-f="text"></md-outlined-text-field>
        <div id="qwFolge" hidden class="crm-form">
            <div class="crm-sec">Folgemaßnahme</div>
            <md-outlined-text-field class="crm-fld" label="Titel *" type="text" id="qw_ftitel" data-max="200"></md-outlined-text-field>
            <md-outlined-text-field class="crm-fld" label="Frist *" id="qw_ffrist" type="date" min="${qmHeute()}" value="${qmAddDays(30)}"></md-outlined-text-field>
            <md-outlined-select class="crm-fld" label="Verantwortlich" id="qw_fver">${qmUserOptions(qm.cache.mnDetail?.verantwortlich_id)}</md-outlined-select>
        </div></div>`,
        `<md-outlined-button onclick="closeModal()">Abbrechen</md-outlined-button><md-filled-button onclick="qmMnWirksam(${id})"><md-icon slot="icon">fact_check</md-icon>Speichern</md-filled-button>`);
}
function qmMnWirksam(id) {
    const ergebnis = [...document.querySelectorAll('md-radio[name="qw_erg"]')].find(r => r.checked).value;
    const body = { ergebnis, text: qmV('qw_text') };
    if (ergebnis === 'unwirksam' && qmV('qw_ftitel')) body.folge = { titel: qmV('qw_ftitel'), frist: qmV('qw_ffrist'), verantwortlich_id: +qmV('qw_fver') || null };
    return qmMnAktion(id, 'wirksamkeit', body);
}
async function qmMnAktion(id, aktion, body) {
    const r = await qmReq('POST', QM_API + '/massnahmen/' + id + '/' + aktion, body);
    if (qmFehler(r)) return;
    closeModal();
    showToast(r.data.folge_id ? 'Gespeichert — Folgemaßnahme angelegt' : 'Gespeichert');
    qmMnDetail(id);
}
