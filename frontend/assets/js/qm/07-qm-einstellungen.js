/* ============================================
   QM 07 — tab "Einstellungen" (spec §6.9)
   Every setting is a question with a simple choice, saved at once and
   logged. Sub-tabs: Allgemein · Zuständigkeit & Rechte · Objekt-Terminals ·
   Mitarbeiter-PIN · Kundenzugänge. Objektleiter only see Terminals + PINs
   of their objects.
   ============================================ */

const QM_FRAGEN = [
    ['Wachbuch', [
        ['modul_wachbuch', 'Soll das digitale Wachbuch genutzt werden?', 'Schaltet das Wachbuch für die Firma ein. Je Objekt wird es zusätzlich unter „Objekte & Dienstanweisungen" aktiviert.', { 0: 'Nein', 1: 'Ja' }],
        ['terminal_sitzung_std', 'Wie lange bleibt ein Objekt-Terminal angemeldet?', 'Danach muss das Gerät an der Pforte neu mit Kennung und PIN angemeldet werden.', { 8: '8 Stunden', 12: '12 Stunden', 24: '24 Stunden' }],
        ['terminal_max_fehl', 'Nach wie vielen falschen Terminal-PINs wird gesperrt?', 'Die Objektleitung bekommt eine Meldung und kann den Zugang wieder freigeben.', { 3: '3 Versuchen', 5: '5 Versuchen', 10: '10 Versuchen' }],
        ['pin_max_fehl', 'Nach wie vielen falschen Mitarbeiter-PINs wird gesperrt?', 'Gilt für die persönliche 4-stellige PIN der Wachperson.', { 3: '3 Versuchen', 5: '5 Versuchen' }],
    ]],
    ['Qualität', [
        ['modul_qm', 'Sollen Dienstanweisungen vor dem Check-in bestätigt werden müssen?', 'Ohne Bestätigung der aktuellen Version ist kein Check-in am Objekt möglich (Nachweis DIN 77200).', { 0: 'Nein', 1: 'Ja' }],
        ['da_freigabe_pflicht', 'Muss der QMB Dienstanweisungen der Objektleiter freigeben?', 'Ohne Freigabe sieht niemand außer Büro und Objektleitung den Entwurf.', { 0: 'Nein', 1: 'Ja' }],
        ['da_pruefung_monate', 'Wie oft wird jede Dienstanweisung geprüft?', 'Die Objektleitung bekommt dann eine Erinnerung.', { 6: 'alle 6 Monate', 12: 'jährlich', 24: 'alle 2 Jahre' }],
        ['rek_bestaetigung_std', 'Wie schnell muss der Eingang einer Reklamation bestätigt sein?', 'Danach erscheint sie als „Bestätigung überfällig".', { 4: 'in 4 Stunden', 8: 'in 8 Stunden', 24: 'in 24 Stunden', 48: 'in 2 Tagen' }],
        ['rek_frist_kritisch', 'Wie schnell muss eine kritische Reklamation erledigt sein?', '', { 1: 'in 1 Tag', 2: 'in 2 Tagen' }],
        ['rek_frist_hoch', 'Wie schnell muss eine Reklamation mit hoher Schwere erledigt sein?', '', { 1: 'in 1 Tag', 2: 'in 2 Tagen', 3: 'in 3 Tagen', 5: 'in 5 Tagen' }],
        ['rek_frist_mittel', 'Wie schnell muss eine Reklamation mittlerer Schwere erledigt sein?', '', { 3: 'in 3 Tagen', 5: 'in 5 Tagen', 7: 'in 1 Woche', 14: 'in 2 Wochen' }],
        ['rek_frist_gering', 'Wie schnell muss eine geringe Reklamation erledigt sein?', '', { 7: 'in 1 Woche', 14: 'in 2 Wochen', 30: 'in 1 Monat' }],
        ['mn_wirksam_tage', 'Wann wird die Wirksamkeit einer Maßnahme geprüft?', 'Gezählt ab der Erledigung.', { 14: 'nach 2 Wochen', 30: 'nach 30 Tagen', 60: 'nach 60 Tagen', 90: 'nach 90 Tagen' }],
        ['mn_max_verlaengerung', 'Wie oft darf eine Frist verlängert werden?', 'Danach muss eine Folgemaßnahme angelegt werden.', { 1: '1-mal', 2: '2-mal', 3: '3-mal' }],
    ]],
];

const QM_ST_TABS = [['allgemein', 'tune', 'Allgemein', 'einstellungen_lesen'], ['rechte', 'admin_panel_settings', 'Zuständigkeit & Rechte', 'einstellungen_lesen'],
    ['terminals', 'tablet', 'Objekt-Terminals', 'terminal_pin'], ['pins', 'pin', 'Mitarbeiter-PIN', 'terminal_pin'], ['kunden', 'badge', 'Kundenzugänge', 'kundenzugaenge']];

function qmEinstRender() {
    const tabs = QM_ST_TABS.filter(t => t[3] === 'einstellungen_lesen' ? qm.opt.ich.alle_objekte : qmCan(t[3]));
    if (!tabs.length) { document.getElementById('qmBody').innerHTML = qmEmpty('lock', 'Keine Einstellungen freigegeben.'); return; }
    if (!tabs.some(t => t[0] === qm.st.tab)) qm.st.tab = tabs[0][0];
    document.getElementById('qmBody').innerHTML = `
        ${qmTabs('qmStSetTab', tabs, qm.st.tab, 'crm-tabs-bar qm-subtabs')}
        <div id="qmStBody">${qmLoading()}</div>`;
    qmWire(document.getElementById('qmBody'));
    qmStRender();
}
function qmStSetTab(v) { qm.st.tab = v; qmStRender(); }
function qmStRender() {
    ({ allgemein: qmStAllgemein, rechte: qmStRechte, terminals: qmStTerminals, pins: qmStPins, kunden: qmStKunden }[qm.st.tab])();
}

// ---------- Allgemein ----------
async function qmStAllgemein() {
    const box = document.getElementById('qmStBody');
    const r = await qmGet(QM_API + '/einstellungen');
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, 'qmStAllgemein()'); return; }
    qm.cache.st = r.data;
    const w = r.data.werte, darf = r.data.darf_aendern;
    box.innerHTML = `
        <div class="crm-banner">${qmIcon('lightbulb')} <strong>Kurz erklärt:</strong> Jede Einstellung ist eine Frage. Änderungen werden sofort gespeichert und im Verlauf protokolliert. ${darf ? '' : 'Ändern darf nur der QMB.'}</div>
        <div class="qm-fragen-grid">${QM_FRAGEN.map(([gruppe, fragen]) => `
            <div class="crm-card crm-settings-card"><div class="crm-card-title">${qmIcon(gruppe === 'Wachbuch' ? 'menu_book' : 'verified')} ${qmE(gruppe)}</div>
                ${fragen.map(([k, frage, hilfe, wahl]) => `<div class="qm-frage">
                    <div><strong>${qmE(frage)}</strong>${hilfe ? `<div class="crm-sub">${qmE(hilfe)}</div>` : ''}</div>
                    <select class="crm-input crm-input-sm" ${darf ? '' : 'disabled'} onchange="qmStSetzen('${k}', this.value)">${Object.entries(wahl).map(([v, l]) => `<option value="${v}" ${String(w[k]) === String(v) ? 'selected' : ''}>${qmE(l)}</option>`).join('')}</select>
                </div>`).join('')}
            </div>`).join('')}
            <div class="crm-card crm-settings-card"><div class="crm-card-title">${qmIcon('mail')} System</div>
                <div class="qm-frage"><div><strong>An welche Adresse sollen Kunden auf QM-E-Mails antworten?</strong><div class="crm-sub">Leer = Standard-Absender des Systems.</div></div>
                    <input class="crm-input crm-input-sm" type="email" value="${qmE(w.absender_email || '')}" ${darf ? '' : 'disabled'} onchange="qmStSetzen('absender_email', this.value)"></div>
            </div>
        </div>
        <details class="crm-card qm-mt"><summary class="crm-card-title">${qmIcon('receipt_long')} Verlauf der Einstellungen</summary>
            <table class="crm-proto"><tbody>${(r.data.verlauf || []).map(v => { const a = JSON.parse(v.alt || '{}'), n = JSON.parse(v.neu || '{}');
                return `<tr><td class="crm-muted">${qmE(qmDT(v.zeit))}</td><td>${qmE(v.benutzer_name || '')}</td><td>${qmE(v.datensatz_id)}: ${qmE(a.wert ?? '–')} → ${qmE(n.wert ?? '–')}</td></tr>`; }).join('') || '<tr><td class="crm-muted">Noch keine Änderungen</td></tr>'}</tbody></table></details>`;
}
async function qmStSetzen(k, v) {
    const r = await qmReq('PUT', QM_API + '/einstellungen', { [k]: v });
    if (qmFehler(r)) return qmStAllgemein();
    showToast('Gespeichert');
    qmLoadOpt(true);
}

// ---------- Rechte ----------
async function qmStRechte() {
    const box = document.getElementById('qmStBody');
    const r = qm.cache.st ? { ok: true, data: qm.cache.st } : await qmGet(QM_API + '/einstellungen');
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, 'qmStRechte()'); return; }
    qm.cache.st = r.data;
    const rollen = r.data.rollen, darf = r.data.darf_aendern;
    box.innerHTML = `
        <div class="crm-banner">${qmIcon('lightbulb')} <strong>Kurz erklärt:</strong> Die Rollen QMB, Objektleiter und Büro vergeben Sie in der Benutzerverwaltung (Bereich „Qualität"). Hier legen Sie fest, was jede Rolle darf. Objektleiter sehen immer nur die Objekte, für die sie zuständig sind.</div>
        <div class="data-table-card"><div class="crm-settings-tablewrap"><table class="crm-table crm-settings-table">
            <thead><tr><th>Recht</th>${Object.values(rollen).map(l => `<th class="crm-num">${qmE(l)}</th>`).join('')}</tr></thead>
            <tbody>${Object.entries(r.data.rechte).map(([k, v]) => `<tr><td>${qmE(v.label)}</td>${Object.keys(rollen).map(rl => `
                <td class="crm-num"><input type="checkbox" data-recht="${k}" data-rolle="${rl}" ${v.rollen.includes(rl) ? 'checked' : ''} ${darf && k !== 'einstellungen' ? '' : 'disabled'}></td>`).join('')}</tr>`).join('')}</tbody>
        </table></div></div>
        ${darf ? `<div class="crm-form-actions qm-mt"><md-filled-button onclick="qmRechteSpeichern()"><md-icon slot="icon">save</md-icon>Rechte speichern</md-filled-button></div>` : ''}`;
}
async function qmRechteSpeichern() {
    const body = {};
    document.querySelectorAll('input[data-recht]').forEach(i => { (body[i.dataset.recht] ||= []); if (i.checked) body[i.dataset.recht].push(i.dataset.rolle); });
    const r = await qmReq('PUT', QM_API + '/einstellungen/rechte', body);
    if (qmFehler(r)) return;
    qm.cache.st = r.data;
    showToast('Rechte gespeichert');
    await qmLoadOpt(true);
    qmStRechte();
}

// ---------- Objekt-Terminals ----------
async function qmStTerminals() {
    const box = document.getElementById('qmStBody');
    const r = await qmGet(QM_API + '/terminals');
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, 'qmStTerminals()'); return; }
    box.innerHTML = `
        <div class="crm-banner">${qmIcon('lightbulb')} <strong>Kurz erklärt:</strong> Ein Gerät an der Pforte (Tablet, PC, Objekt-Handy) meldet sich unter
            <a href="${qmE(r.data.adresse)}" target="_blank" rel="noopener">${qmE(r.data.adresse)}</a> mit Objekt-Kennung und 6-stelliger PIN an und sieht nur das ${qmE(qmLabel())} dieses Objekts.
            Danach meldet sich jede Wachperson mit ihrer persönlichen PIN an.</div>
        <div class="data-table-card"><div class="table-container"><table class="crm-table qm-table">
            <thead><tr><th>Objekt</th><th>Kennung</th><th>Status</th><th>Zuletzt genutzt</th><th class="crm-num">Offene Sitzungen</th><th></th></tr></thead>
            <tbody>${(r.data.terminals || []).map(o => { const t = o.terminal; return `<tr>
                <td><strong>${qmE(o.objektname)}</strong><div class="crm-sub">${qmE(o.kundenname || '')}${o.wachbuch_aktiv ? '' : ' · ' + qmE(qmLabel()) + ' nicht aktiv'}</div></td>
                <td>${t ? `<code>${qmE(t.kennung)}</code>` : '–'}</td>
                <td>${!t ? qmBadge('kein Zugang') : (t.gesperrt_seit ? qmBadge('gesperrt seit ' + qmDT(t.gesperrt_seit), 'err') : (t.aktiv ? qmBadge('aktiv', 'ok') : qmBadge('deaktiviert')))}</td>
                <td>${t?.letzte_anmeldung ? qmE(qmDT(t.letzte_anmeldung)) : '–'}</td><td class="crm-num">${t ? t.sitzungen : ''}</td>
                <td class="crm-actions">
                    <md-outlined-button onclick="qmTerminalPin('${qmE(o.obj_id)}', ${t ? 1 : 0})"><md-icon slot="icon">key</md-icon>${t ? 'Neue PIN' : 'Zugang anlegen'}</md-outlined-button>
                    ${t ? `<md-text-button onclick="qmTerminalAktiv('${qmE(o.obj_id)}', ${t.aktiv && !t.gesperrt_seit ? 0 : 1})">${t.aktiv && !t.gesperrt_seit ? 'Sperren' : 'Entsperren'}</md-text-button>` : ''}
                </td></tr>`; }).join('') || `<tr><td colspan="6" class="crm-muted">Keine Objekte</td></tr>`}</tbody></table></div></div>`;
}
async function qmTerminalPin(objId, vorhanden) {
    if (vorhanden && !(await mdConfirm('Neue PIN erzeugen? Die alte PIN gilt sofort nicht mehr und laufende Sitzungen werden beendet.', { title: 'Neue Terminal-PIN', confirmLabel: 'Neue PIN' }))) return;
    const r = await qmReq('POST', QM_API + '/terminals/' + encodeURIComponent(objId) + '/pin');
    if (qmFehler(r)) return;
    qmGeheim('Terminal-Zugang', [['Objekt-Kennung', r.data.kennung], ['PIN', r.data.pin]]);
    qmStTerminals();
}
async function qmTerminalAktiv(objId, aktiv) {
    const r = await qmReq('POST', QM_API + '/terminals/' + encodeURIComponent(objId) + '/aktiv', { aktiv: !!aktiv });
    if (qmFehler(r)) return;
    showToast(aktiv ? 'Entsperrt' : 'Gesperrt');
    qmStTerminals();
}

// ---------- Mitarbeiter-PIN ----------
hmsPagerDef('qmPinPager', 'Mitarbeiter', () => qmPinApply());
async function qmStPins() {
    const box = document.getElementById('qmStBody');
    const r = await qmGet(QM_API + '/pins');
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, 'qmStPins()'); return; }
    qm.cache.pins = r.data.mitarbeiter || [];
    box.innerHTML = `
        <div class="crm-banner">${qmIcon('lightbulb')} <strong>Kurz erklärt:</strong> Jede Wachperson meldet sich am Objekt-Terminal mit einer persönlichen 4-stelligen PIN an. Sie erzeugen eine Start-PIN (wird nur einmal angezeigt, bitte persönlich weitergeben); bei der ersten Anmeldung legt die Wachperson ihre eigene PIN fest.</div>
        <div class="data-toolbar"><div class="data-toolbar-left"><md-outlined-text-field class="cust-search" label="Mitarbeiter suchen" id="qmPinQ"><md-icon slot="leading-icon">search</md-icon></md-outlined-text-field></div></div>
        <div class="data-table-card"><div id="qmPinRows"></div><div id="qmPinPager" class="be-pager"></div></div>`;
    document.getElementById('qmPinQ').addEventListener('input', e => { qm.cache.pinQ = e.target.value.toLowerCase(); qmPinApply(); });
    qmPinApply();
}
function qmPinApply() {
    const q = qm.cache.pinQ || '';
    const list = (qm.cache.pins || []).filter(m => !q || (m.name + ' ' + m.dienst_nr).toLowerCase().includes(q));
    const pg = hmsPage(list, HMS_PAGERS.qmPinPager.st, q + '|' + list.length);
    hmsPagerFill('qmPinPager', pg);
    const st = { keine: ['keine PIN', ''], start: ['Start-PIN vergeben', 'warn'], aktiv: ['eigene PIN', 'ok'], gesperrt: ['gesperrt', 'err'] };
    qmPaint(document.getElementById('qmPinRows'), `<div class="table-container"><table class="crm-table qm-table">
        <thead><tr><th>Mitarbeiter</th><th>Status</th><th>Zuletzt geändert</th><th></th></tr></thead>
        <tbody>${pg.rows.map(m => `<tr><td><strong>${qmE(m.name)}</strong><div class="crm-sub">${qmE(m.dienst_nr)}</div></td>
            <td>${qmBadge(...st[m.status])}${m.fehlversuche && m.status !== 'gesperrt' ? ` <span class="crm-muted">${m.fehlversuche} Fehlversuch(e)</span>` : ''}</td>
            <td>${m.geaendert_am ? qmE(qmDT(m.geaendert_am)) + `<div class="crm-sub">${qmE(m.geaendert_von || '')}</div>` : '–'}</td>
            <td class="crm-actions"><md-outlined-button onclick="qmPinAktion('${qmE(m.dienst_nr)}','neu')"><md-icon slot="icon">pin</md-icon>${m.status === 'keine' ? 'Start-PIN' : 'Neue Start-PIN'}</md-outlined-button>
                ${m.status === 'gesperrt' ? `<md-text-button onclick="qmPinAktion('${qmE(m.dienst_nr)}','entsperren')">Entsperren</md-text-button>`
                    : (m.status !== 'keine' ? `<md-text-button onclick="qmPinAktion('${qmE(m.dienst_nr)}','sperren')">Sperren</md-text-button>` : '')}</td></tr>`).join('')
            || '<tr><td colspan="4" class="crm-muted">Keine Mitarbeiter</td></tr>'}</tbody></table></div>`);
}
async function qmPinAktion(dn, aktion) {
    if (aktion === 'neu' && !(await mdConfirm('Neue Start-PIN erzeugen? Eine bestehende PIN gilt sofort nicht mehr.', { title: 'Start-PIN', confirmLabel: 'Erzeugen' }))) return;
    const r = await qmReq('POST', QM_API + '/pins/' + encodeURIComponent(dn) + '/' + aktion);
    if (qmFehler(r)) return;
    if (r.data.pin) qmGeheim('Start-PIN für ' + ((qm.cache.pins || []).find(m => m.dienst_nr === dn)?.name || dn), [['Start-PIN', r.data.pin]],
        'Nur jetzt sichtbar. Bei der ersten Anmeldung am Terminal legt die Wachperson ihre eigene PIN fest.');
    else showToast(aktion === 'sperren' ? 'Gesperrt' : 'Entsperrt');
    qmStPins();
}

// ---------- Kundenzugänge (the Kundenportal is part of the QM module) ----------
async function qmStKunden() {
    const box = document.getElementById('qmStBody');
    const r = await qmGet(QM_API + '/kundenzugaenge');
    if (!r.ok) { box.innerHTML = qmFehlerBox(r, 'qmStKunden()'); return; }
    qm.cache.kz = r.data;
    box.innerHTML = `
        <div class="crm-banner">${qmIcon('lightbulb')} <strong>Kurz erklärt:</strong> Jeder Ansprechpartner eines Kunden bekommt einen eigenen Zugang zum Kundenportal, beschränkt auf ausgewählte Objekte.
            Sie vergeben ein Start-Passwort (nur einmal sichtbar); beim ersten Login muss es geändert werden. Nach 5 Fehlversuchen wird gesperrt.
            Anmeldung: <a href="${qmE(r.data.login_url)}" target="_blank" rel="noopener">${qmE(r.data.login_url)}</a>
            <md-text-button onclick="qmCopy('${qmE(r.data.login_url)}','Link kopiert')"><md-icon slot="icon">content_copy</md-icon>Kopieren</md-text-button></div>
        <div class="data-toolbar"><div class="data-toolbar-left"></div><div class="crm-toolbar-actions">
            <md-filled-button onclick="qmKzDialog()"><md-icon slot="icon">person_add</md-icon>Neuer Zugang</md-filled-button></div></div>
        <div class="data-table-card"><div class="table-container"><table class="crm-table qm-table">
            <thead><tr><th>Kunde</th><th>Ansprechpartner</th><th>Benutzername</th><th>Objekte</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr></thead>
            <tbody>${(r.data.zugaenge || []).map(z => {
                const objs = (r.data.kunden_objekte[z.kunde_id] || []);
                const sicht = z.objekte === null ? 'alle (' + objs.length + ')' : objs.filter(o => z.objekte.includes(o.obj_id)).map(o => o.objektname).join(', ');
                return `<tr><td>${qmE(z.kundenname)}</td><td><span class="qm-name">${qmE(z.name || '–')}</span><div class="crm-sub">${qmE(z.email || '')}</div></td><td><code>${qmE(z.username)}</code></td>
                    <td class="qm-clip">${qmE(sicht)}</td>
                    <td>${z.gesperrt ? qmBadge('gesperrt', 'err') : (z.aktiv ? (z.passwort_aendern ? qmBadge('Start-Passwort', 'warn') : qmBadge('aktiv', 'ok')) : qmBadge('deaktiviert'))}</td>
                    <td>${z.last_login_at ? qmE(qmDT(z.last_login_at)) : '–'}</td>
                    <td class="crm-actions"><md-icon-button title="Bearbeiten" onclick="qmKzDialog(${z.id})"><md-icon>edit</md-icon></md-icon-button>
                        <md-icon-button title="Neues Start-Passwort" onclick="qmKzAktion(${z.id},'passwort')"><md-icon>lock_reset</md-icon></md-icon-button>
                        ${z.gesperrt ? `<md-text-button onclick="qmKzAktion(${z.id},'entsperren')">Entsperren</md-text-button>` : ''}</td></tr>`;
            }).join('') || '<tr><td colspan="7" class="crm-muted">Noch keine Kundenzugänge</td></tr>'}</tbody></table></div></div>`;
}

async function qmKzDialog(id = null) {
    const z = id ? (qm.cache.kz?.zugaenge || []).find(x => x.id === id) : null;
    if (!qm.cache.kzKunden) {
        const r = await qmGet(QM_API + '/kundenzugaenge/kunden');
        if (qmFehler(r, 'Kunden konnten nicht geladen werden')) return;
        qm.cache.kzKunden = r.data.kunden;
    }
    const kunden = qm.cache.kzKunden;
    const objHtml = (kid, sel) => {
        const objs = (kunden.find(k => k.kunde_id === kid)?.objekte) || [];
        return `<label class="crm-switch-row"><input type="checkbox" id="qk_alle" ${sel === null ? 'checked' : ''} onchange="document.querySelectorAll('.qk-obj').forEach(c=>c.disabled=this.checked)"> alle Objekte des Kunden (auch künftige)</label>
            ${objs.map(o => `<label class="crm-switch-row"><input type="checkbox" class="qk-obj" value="${qmE(o.obj_id)}" ${sel === null ? 'disabled' : ''} ${sel && sel.includes(o.obj_id) ? 'checked' : ''}> ${qmE(o.objektname)}</label>`).join('') || '<div class="crm-muted">Kunde hat keine aktiven Objekte</div>'}`;
    };
    window._qmKzObj = objHtml;
    openModal(z ? 'Kundenzugang bearbeiten' : 'Neuer Kundenzugang', `
        <div class="crm-form crm-form-grid">
            <label class="crm-field crm-span2">Kunde *<select id="qk_kunde" class="crm-input" data-f="kunde_id" ${z ? 'disabled' : ''} onchange="document.getElementById('qk_objekte').innerHTML=window._qmKzObj(this.value, null)">
                <option value="">— wählen —</option>${kunden.map(k => `<option value="${qmE(k.kunde_id)}" ${z?.kunde_id === k.kunde_id ? 'selected' : ''}>${qmE(k.kundenname)}</option>`).join('')}</select></label>
            <label class="crm-field">Ansprechpartner *<input id="qk_name" class="crm-input" data-f="name" maxlength="200" value="${qmE(z?.name || '')}"></label>
            <label class="crm-field">E-Mail (für Benachrichtigungen)<input id="qk_mail" type="email" class="crm-input" data-f="email" maxlength="200" value="${qmE(z?.email || '')}"></label>
            <label class="crm-field">Benutzername *<input id="qk_user" class="crm-input" data-f="username" maxlength="50" value="${qmE(z?.username || '')}" placeholder="z. B. mueller.lager"></label>
            ${z ? `<label class="crm-switch-row"><input type="checkbox" id="qk_aktiv" ${z.aktiv ? 'checked' : ''}> Zugang aktiv</label>` : '<div></div>'}
            <div class="crm-span2"><div class="crm-sec">Sichtbare Objekte</div><div id="qk_objekte" data-f="objekte">${z ? objHtml(z.kunde_id, z.objekte) : '<div class="crm-muted">Bitte zuerst den Kunden wählen</div>'}</div></div>
        </div>`,
        `<button class="btn btn-outline" onclick="closeModal()">Abbrechen</button><button class="btn btn-primary" id="qkSave" onclick="qmKzSpeichern(${id || 'null'})">${qmIcon('save')} ${z ? 'Speichern' : 'Anlegen & Start-Passwort erzeugen'}</button>`, 'modal-lg');
}

async function qmKzSpeichern(id) {
    const alle = document.getElementById('qk_alle')?.checked;
    const body = {
        kunde_id: qmV('qk_kunde'), name: qmV('qk_name'), email: qmV('qk_mail') || null, username: qmV('qk_user'),
        objekte: alle ? null : [...document.querySelectorAll('.qk-obj:checked')].map(c => c.value),
    };
    if (id) body.aktiv = qmV('qk_aktiv');
    const btn = document.getElementById('qkSave'); if (btn) btn.disabled = true;
    const r = await qmReq(id ? 'PUT' : 'POST', QM_API + '/kundenzugaenge' + (id ? '/' + id : ''), body);
    if (btn) btn.disabled = false;
    if (qmFehler(r)) return;
    closeModal();
    if (r.data.passwort) qmGeheim('Zugang zum Kundenportal', [['Benutzername', r.data.username], ['Start-Passwort', r.data.passwort], ['Anmeldung', qm.cache.kz?.login_url || '']],
        'Nur jetzt sichtbar. Beim ersten Login muss der Kunde ein eigenes Passwort festlegen.');
    else showToast('Gespeichert');
    qmStKunden();
}

async function qmKzAktion(id, aktion) {
    if (aktion === 'passwort' && !(await mdConfirm('Neues Start-Passwort erzeugen? Das alte gilt sofort nicht mehr, alle Sitzungen werden beendet.', { title: 'Start-Passwort', confirmLabel: 'Erzeugen' }))) return;
    const r = await qmReq('POST', QM_API + '/kundenzugaenge/' + id + '/' + aktion);
    if (qmFehler(r)) return;
    if (r.data.passwort) qmGeheim('Neues Start-Passwort', [['Benutzername', r.data.username], ['Start-Passwort', r.data.passwort]]);
    else showToast('Entsperrt');
    qmStKunden();
}
