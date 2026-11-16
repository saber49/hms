/* ============================================
   HMS Gruppe — Interessentenverwaltung
   - Prospect list: search + phase chips + sortable table
   - Create/Edit dialog: Stammdaten + Akquise-Status
   - Detail view: master data + communication log
   - "Zu Kunde konvertieren" → creates a kunden record
   ============================================ */

let _intRows = [];
let _intSortCol = 'created_at';
let _intSortDir = 'desc';
let _intPhaseFilter = '';
let _intDetailId = null;
let _intMaOpts = null;
let _intOptionen = null;

function intIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}

const INT_PHASEN = ['Erstkontakt', 'Termin', 'Demo', 'Angebot', 'Verhandlung', 'Gewonnen', 'Verloren'];
const INT_PHASEN_OPEN = ['Erstkontakt', 'Termin', 'Demo', 'Angebot', 'Verhandlung'];

// Live updates: mutations broadcast data.changed{area:'interessenten'}.
if (window.HMSRealtime && typeof HMSRealtime.on === 'function') {
    HMSRealtime.on('interessenten', () => {
        if (typeof currentPage !== 'undefined' && currentPage === 'interessenten') {
            if (_intDetailId) renderInteressentDetail(_intDetailId);
            else renderInteressenten();
        }
    });
}

function intPhaseBadge(phase) {
    const slug = String(phase || '').toLowerCase().replace(/[^a-z]/g, '');
    return `<span class="int-phase int-phase-${slug}">${escapeHtml(phase || '–')}</span>`;
}

function intWiedervorlageBadge(row) {
    if (!row.wiedervorlage_am) return '<span class="int-muted">–</span>';
    const today = new Date().toISOString().slice(0, 10);
    const d = row.wiedervorlage_am.slice(0, 10);
    const offen = INT_PHASEN_OPEN.includes(row.pipeline_phase);
    const label = formatDate(d);
    if (offen && d < today) return `<span class="int-wv int-wv-overdue">${intIcon('alarm')} ${label}</span>`;
    if (offen && d === today) return `<span class="int-wv int-wv-today">${intIcon('schedule')} ${label}</span>`;
    return `<span class="int-wv">${label}</span>`;
}

function intOptionen() {
    return _intOptionen ? Promise.resolve(_intOptionen) : apiGet(API.interessenten + '/optionen')
        .then(r => { _intOptionen = r || {}; return _intOptionen; })
        .catch(() => ({}));
}

function intMaOpts() {
    return _intMaOpts ? Promise.resolve(_intMaOpts.slice()) : apiGet(API.interessenten + '/mitarbeiter-options')
        .then(r => { _intMaOpts = Array.isArray(r) ? r : []; return _intMaOpts.slice(); })
        .catch(() => []);
}

// ============================================
// LIST VIEW
// ============================================
async function renderInteressenten() {
    _intDetailId = null;
    const content = document.getElementById('content');
    const editable = typeof window.canEdit === 'function' ? window.canEdit('interessenten') : false;

    const [rows, stats] = await Promise.all([
        apiGet(API.interessenten).catch(() => []),
        apiGet(API.interessenten + '/stats').catch(() => null),
    ]);
    _intRows = Array.isArray(rows) ? rows : [];

    const s = stats || { gesamt: _intRows.length, offen: 0, gewonnen: 0, ueberfaellig: 0 };
    const offenPct = s.gesamt > 0 ? Math.round((s.offen / s.gesamt) * 100) : 0;

    const chips = ['', ...INT_PHASEN].map(p => `
        <md-filter-chip label="${p || 'Alle'}" data-phase="${p}"
            ${(_intPhaseFilter || '') === p ? 'selected' : ''}
            onclick="setIntPhaseFilter('${p}')"></md-filter-chip>`).join('');

    content.innerHTML = `
        <div class="int-page">
        <div class="data-bento">
            <div class="bento-tile bento-tile-hero bento-tile-total" onclick="setIntPhaseFilter('')" title="Alle Interessenten">
                <div class="bento-tile-head">${intIcon('person_search')} Interessenten gesamt</div>
                <div class="bento-tile-number">${s.gesamt}</div>
                <div class="bento-tile-foot">
                    <span class="bento-foot-pill">${intIcon('rocket_launch')} ${s.offen} in Akquise</span>
                    <span class="bento-foot-pill">${intIcon('check_circle')} ${s.gewonnen} Gewonnen</span>
                </div>
            </div>
            <div class="bento-tile bento-tile-active" onclick="setIntPhaseFilter('')" title="Offene Akquise">
                <div class="bento-tile-icon">${intIcon('rocket_launch')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${s.offen}</div>
                    <div class="bento-tile-label">In Akquise</div>
                </div>
                <div class="bento-progress" title="${offenPct}% offen">
                    <div class="bento-progress-bar" style="width:${offenPct}%"></div>
                </div>
            </div>
            <div class="bento-tile" onclick="setIntPhaseFilter('Gewonnen')" title="Gewonnene Interessenten">
                <div class="bento-tile-icon">${intIcon('check_circle')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${s.gewonnen}</div>
                    <div class="bento-tile-label">Gewonnen</div>
                </div>
            </div>
            <div class="bento-tile ${s.ueberfaellig > 0 ? 'bento-tile-warn' : ''}" onclick="setIntPhaseFilter('__overdue')" title="Überfällige Wiedervorlagen">
                <div class="bento-tile-icon">${intIcon('alarm')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${s.ueberfaellig || 0}</div>
                    <div class="bento-tile-label">Wiedervorlage fällig</div>
                </div>
            </div>
        </div>

        <div class="data-toolbar">
            <div class="data-toolbar-left">
                <md-outlined-text-field class="cust-search" id="searchInteressenten" label="Interessenten suchen" placeholder="Firma, Ansprechpartner, Ort…">
                    <md-icon slot="leading-icon">search</md-icon>
                </md-outlined-text-field>
                <md-chip-set id="intPhaseChips">${chips}</md-chip-set>
            </div>
            ${editable ? `<md-filled-button onclick="openInteressentDialog()">
                <md-icon slot="icon">add</md-icon>Neuer Interessent
            </md-filled-button>` : ''}
        </div>

        <div class="data-table-card">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            ${intSortTh('firmenname', 'Firmenname')}
                            ${intSortTh('branche', 'Branche')}
                            ${intSortTh('ansprechpartner_name', 'Ansprechpartner')}
                            ${intSortTh('einsatzgebiet', 'Einsatzgebiet')}
                            ${intSortTh('pipeline_phase', 'Phase')}
                            <th>Zuständig</th>
                            ${intSortTh('wiedervorlage_am', 'Wiedervorlage')}
                            <th>Kontakte</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="interessentenTable"></tbody>
                </table>
                <div class="empty-state" id="intEmpty" style="display:none">${intIcon('person_search')}<p>Keine Interessenten gefunden</p></div>
            </div>
        </div>
        </div>
    `;

    document.getElementById('searchInteressenten')?.addEventListener('input', renderIntRows);
    renderIntRows();
}

function intSortTh(col, label) {
    const active = _intSortCol === col;
    const icon = active ? (_intSortDir === 'asc' ? 'arrow_upward' : 'arrow_downward') : '';
    return `<th class="int-sortable ${active ? 'sorted' : ''}" onclick="intSort('${col}')">${label}${icon ? ' ' + intIcon(icon, 'font-size:14px;vertical-align:-3px') : ''}</th>`;
}

function intSort(col) {
    if (_intSortCol === col) _intSortDir = _intSortDir === 'asc' ? 'desc' : 'asc';
    else { _intSortCol = col; _intSortDir = 'asc'; }
    renderInteressenten();
}

function setIntPhaseFilter(phase) {
    _intPhaseFilter = phase === '__overdue' ? '__overdue' : (phase || '');
    document.querySelectorAll('#intPhaseChips md-filter-chip').forEach(ch => {
        ch.selected = (ch.dataset.phase || '') === (phase === '__overdue' ? '' : _intPhaseFilter);
    });
    renderIntRows();
}

function intRowVisible(row, q) {
    if (_intPhaseFilter === '__overdue') {
        const today = new Date().toISOString().slice(0, 10);
        if (!(INT_PHASEN_OPEN.includes(row.pipeline_phase) && row.wiedervorlage_am && row.wiedervorlage_am < today)) return false;
    } else if (_intPhaseFilter && row.pipeline_phase !== _intPhaseFilter) {
        return false;
    }
    if (q) {
        const hay = [row.interessent_id, row.firmenname, row.branche, row.ansprechpartner_name, row.einsatzgebiet, row.email, row.zustaendiger_name]
            .filter(Boolean).join(' ').toLowerCase();
        if (!hay.includes(q)) return false;
    }
    return true;
}

function renderIntRows() {
    const tbody = document.getElementById('interessentenTable');
    if (!tbody) return;
    const q = String(document.getElementById('searchInteressenten')?.value ?? '').trim().toLowerCase();

    const rows = _intRows.filter(r => intRowVisible(r, q));
    const dir = _intSortDir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
        const va = a[_intSortCol] ?? '';
        const vb = b[_intSortCol] ?? '';
        return String(va).localeCompare(String(vb), 'de', { numeric: true }) * dir;
    });

    tbody.innerHTML = rows.map(r => `
        <tr class="int-row" onclick="renderInteressentDetail('${r.interessent_id}')" title="Details öffnen">
            <td class="int-c-id"><strong>${escapeHtml(r.interessent_id)}</strong></td>
            <td class="int-c-firma">${escapeHtml(r.firmenname)}${r.konvertiert_zu ? ` <span class="int-conv" title="Konvertiert zu ${escapeHtml(r.konvertiert_zu)}">${intIcon('move_up', 'font-size:14px')}Kunde</span>` : ''}</td>
            <td class="int-c-branche" data-label="Branche">${escapeHtml(r.branche || '–')}</td>
            <td class="int-c-ap" data-label="Ansprechpartner">${escapeHtml(r.ansprechpartner_name || '–')}</td>
            <td class="int-c-ort" data-label="Einsatzgebiet">${escapeHtml(r.einsatzgebiet || '–')}</td>
            <td class="int-c-phase">${intPhaseBadge(r.pipeline_phase)}</td>
            <td class="int-c-zust" data-label="Zuständig">${escapeHtml(r.zustaendiger_name || '–')}</td>
            <td class="int-c-wv" data-label="Wiedervorlage">${intWiedervorlageBadge(r)}</td>
            <td class="int-c-kont" title="Kontakte"><span class="int-kontakte">${r.kontakte || 0}</span></td>
            <td class="int-c-act" onclick="event.stopPropagation()">
                <md-icon-button onclick="renderInteressentDetail('${r.interessent_id}')" title="Öffnen"><md-icon>open_in_new</md-icon></md-icon-button>
            </td>
        </tr>`).join('');

    const empty = document.getElementById('intEmpty');
    if (empty) empty.style.display = rows.length === 0 ? '' : 'none';
}

// ============================================
// DETAIL VIEW — two columns per specification
// ============================================
async function renderInteressentDetail(id) {
    _intDetailId = id;
    const content = document.getElementById('content');
    const editable = typeof window.canEdit === 'function' ? window.canEdit('interessenten') : false;

    const r = await apiGet(API.interessenten + '/' + encodeURIComponent(id));
    if (!r || r.error || !r.interessent_id) {
        showToast(r?.error || 'Interessent nicht gefunden', 'error');
        renderInteressenten();
        return;
    }

    const today = new Date().toISOString().slice(0, 10);
    const wvOffen = INT_PHASEN_OPEN.includes(r.pipeline_phase);
    const wvOverdue = r.wiedervorlage_am && wvOffen && r.wiedervorlage_am < today;
    const konvertiert = !!r.konvertiert_zu;
    const komm = Array.isArray(r.kommunikation) ? r.kommunikation : [];

    const field = (label, val) => `
        <div class="int-field">
            <div class="int-field-label">${label}</div>
            <div class="int-field-value">${val || '<span class="int-muted">–</span>'}</div>
        </div>`;

    const kommHtml = komm.length === 0
        ? `<div class="int-muted" style="padding:12px 0">Noch keine Einträge</div>`
        : komm.map(e => {
            const dStr = e.datum ? formatDateTimeLocal(e.datum) : '';
            return `
            <div class="int-komm-entry">
                <div class="int-komm-icon">${intIcon({Anruf:'call', 'E-Mail':'mail', Termin:'event', Demo:'present_to_all', Notiz:'sticky_note_2'}[e.typ] || 'sticky_note_2')}</div>
                <div class="int-komm-body">
                    <div class="int-komm-head">
                        <span class="int-komm-typ">${escapeHtml(e.typ)}</span>
                        <span class="int-komm-meta">${dStr} · ${escapeHtml(e.erfasst_von_name || '')}</span>
                    </div>
                    <div class="int-komm-notiz">${escapeHtml(e.notiz).replace(/\n/g, '<br>')}</div>
                    ${e.naechster_schritt || e.wiedervorlage_am ? `<div class="int-komm-follow">
                        ${e.naechster_schritt ? `<span>${intIcon('flag', 'font-size:15px')} <b>Nächster Schritt:</b> ${escapeHtml(e.naechster_schritt).replace(/\n/g, '<br>')}</span>` : ''}
                        ${e.wiedervorlage_am ? `<span>${intIcon('event_repeat', 'font-size:15px')} <b>Wiedervorlage:</b> ${escapeHtml(formatDate(String(e.wiedervorlage_am).slice(0, 10)))}</span>` : ''}
                    </div>` : ''}
                    ${e.datei_pfad ? `<a class="int-komm-file" href="#" onclick="intDownloadDatei(${e.eintrag_id});return false">${intIcon('attach_file')} ${escapeHtml(e.datei_name || 'Anhang')}</a>` : ''}
                </div>
            </div>`;
        }).join('');

    content.innerHTML = `
        <div class="int-detail">
            <div class="int-detail-topbar">
                <md-text-button onclick="renderInteressenten()"><md-icon slot="icon">arrow_back</md-icon>Zur Liste</md-text-button>
                <div class="int-detail-actions">
                    ${editable && !konvertiert ? `<md-outlined-button onclick="openInteressentDialog('${r.interessent_id}')"><md-icon slot="icon">edit</md-icon>Bearbeiten</md-outlined-button>` : ''}
                    ${editable && !konvertiert && hasPermission('stammdaten.kunden.edit') ? `<md-filled-button onclick="konvertiereInteressent('${r.interessent_id}')" ${r.pipeline_phase !== 'Gewonnen' ? 'title="Konvertieren ist aus Phase Gewonnen möglich"' : ''}><md-icon slot="icon">move_up</md-icon>Zu Kunde konvertieren</md-filled-button>` : ''}
                    ${konvertiert ? `<md-outlined-button onclick="loadPage('customers')"><md-icon slot="icon">open_in_new</md-icon>Kunde ${escapeHtml(r.konvertiert_zu)} öffnen</md-outlined-button>` : ''}
                    ${editable ? `<md-icon-button onclick="deleteInteressent('${r.interessent_id}')" title="Löschen"><md-icon>delete</md-icon></md-icon-button>` : ''}
                </div>
            </div>

            <div class="int-detail-head">
                <div>
                    <div class="int-detail-id">${escapeHtml(r.interessent_id)}</div>
                    <h2 class="int-detail-title">${escapeHtml(r.firmenname)}</h2>
                </div>
                <div class="int-detail-badges">
                    ${intPhaseBadge(r.pipeline_phase)}
                    ${konvertiert ? `<span class="int-conv">${intIcon('move_up', 'font-size:14px')} Konvertiert</span>` : ''}
                    ${wvOverdue ? `<span class="int-wv int-wv-overdue">${intIcon('alarm')} Wiedervorlage überfällig</span>` : ''}
                </div>
            </div>

            <div class="int-detail-grid">
                <div class="int-detail-left">
                    <div class="int-card">
                        <div class="int-card-title">${intIcon('domain')} Stammdaten</div>
                        ${field('Firmenname', escapeHtml(r.firmenname))}
                        ${field('Branche', escapeHtml(r.branche))}
                        ${field('Mitarbeiterzahl', escapeHtml(r.mitarbeiterzahl_bereich))}
                        ${field('Einsatzgebiet', escapeHtml(r.einsatzgebiet))}
                        ${field('Ansprechpartner', escapeHtml(r.ansprechpartner_name))}
                        ${field('Position', escapeHtml(r.ansprechpartner_position))}
                        ${field('Telefon', r.telefon ? `<a href="tel:${escapeHtml(r.telefon)}">${escapeHtml(r.telefon)}</a>` : '')}
                        ${field('E-Mail', r.email ? `<a href="mailto:${escapeHtml(r.email)}">${escapeHtml(r.email)}</a>` : '')}
                        ${field('Erstellt am', r.created_at ? escapeHtml(formatDateTimeLocal(r.created_at)) : '')}
                        ${field('Erstellt von', r.erstellt_von_name ? escapeHtml(r.erstellt_von_name) : '')}
                    </div>

                    <div class="int-card">
                        <div class="int-card-title">${intIcon('rocket_launch')} Akquise-Status</div>
                        ${wvOverdue ? `<div class="int-wv-banner">${intIcon('alarm')} Wiedervorlage seit ${escapeHtml(formatDate(r.wiedervorlage_am))} überfällig</div>` : ''}
                        ${field('Quelle', escapeHtml(r.quelle))}
                        ${field('Zuständiger Mitarbeiter', escapeHtml(r.zustaendiger_name))}
                        ${field('Pipeline-Phase', intPhaseBadge(r.pipeline_phase))}
                        ${field('Nächster Schritt', r.naechster_schritt ? escapeHtml(r.naechster_schritt) : '')}
                        ${field('Wiedervorlage am', intWiedervorlageBadge(r))}
                    </div>
                </div>

                <div class="int-detail-right">
                    <div class="int-card">
                        <div class="int-card-title">${intIcon('forum')} Kommunikationsverlauf <span class="int-muted">(${komm.length})</span></div>
                        ${editable && !konvertiert ? `
                        <form class="int-komm-form" onsubmit="addKommEintrag(event, '${r.interessent_id}')">
                            <div class="int-komm-form-row">
                                <md-outlined-select id="f_komm_typ" label="Typ" style="min-width:130px">
                                    ${['Anruf','E-Mail','Termin','Demo','Notiz'].map(t => `<md-select-option value="${t}"><div slot="headline">${t}</div></md-select-option>`).join('')}
                                </md-outlined-select>
                                <md-outlined-select id="f_komm_phase" label="Phase aktualisieren (optional)" style="flex:1">
                                    <md-select-option value="" selected><div slot="headline">— unverändert (${escapeHtml(r.pipeline_phase)}) —</div></md-select-option>
                                    ${INT_PHASEN.map(p => `<md-select-option value="${p}"><div slot="headline">${p}</div></md-select-option>`).join('')}
                                </md-outlined-select>
                            </div>
                            <md-outlined-text-field id="f_komm_notiz" type="textarea" rows="2" label="Notiz" required style="width:100%"></md-outlined-text-field>
                            <md-outlined-text-field id="f_komm_schritt" type="textarea" rows="2" label="Nächster Schritt (optional)" style="width:100%"></md-outlined-text-field>
                            <md-outlined-text-field id="f_komm_wv" type="date" label="Wiedervorlage am (optional)" class="int-komm-date"><md-icon slot="trailing-icon">event</md-icon></md-outlined-text-field>
                            <div class="int-komm-form-row">
                                <input type="file" id="f_komm_datei" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx" class="int-file-input">
                                <md-filled-tonal-button type="submit"><md-icon slot="icon">send</md-icon>Eintrag speichern</md-filled-tonal-button>
                            </div>
                        </form>` : ''}
                        <div class="int-komm-list">${kommHtml}</div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

async function intDownloadDatei(eintragId) {
    const res = await fetch(API.interessenten + '/kommunikation/' + eintragId + '/datei', {
        headers: getAuthHeadersNoContent ? getAuthHeadersNoContent() : getAuthHeaders(),
    });
    if (!res.ok) { showToast('Datei konnte nicht geladen werden', 'error'); return; }
    const blob = await res.blob();
    const dispo = res.headers.get('content-disposition') || '';
    const m = dispo.match(/filename="?([^";]+)"?/);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = m ? m[1] : 'Anhang';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 10000);
}

async function addKommEintrag(ev, id) {
    ev.preventDefault();
    const typ = document.getElementById('f_komm_typ')?.value || 'Notiz';
    const notiz = String(document.getElementById('f_komm_notiz')?.value ?? '').trim();
    const phase = document.getElementById('f_komm_phase')?.value || '';
    const schritt = String(document.getElementById('f_komm_schritt')?.value ?? '').trim();
    const wv = document.getElementById('f_komm_wv')?.value || '';
    const datei = document.getElementById('f_komm_datei')?.files?.[0];

    if (!notiz) { showToast('Notiz ist erforderlich', 'error'); return; }

    const fd = new FormData();
    fd.append('typ', typ);
    fd.append('notiz', notiz);
    if (phase) fd.append('pipeline_phase', phase);
    if (schritt) fd.append('naechster_schritt', schritt);
    if (wv) fd.append('wiedervorlage_am', wv);
    if (datei) fd.append('datei', datei);

    try {
        const res = await fetch(API.interessenten + '/' + encodeURIComponent(id) + '/kommunikation', {
            method: 'POST',
            headers: getAuthHeadersNoContent ? getAuthHeadersNoContent() : getAuthHeaders(),
            body: fd,
        });
        const result = await res.json().catch(() => ({}));
        if (res.status === 422) {
            showToast(Object.values(result.errors || {}).flat().join(' · ') || 'Eingabefehler', 'error');
            return;
        }
        if (!res.ok || !result.success) {
            showToast(result.error || 'Eintrag fehlgeschlagen', 'error');
            return;
        }
        showToast('Eintrag gespeichert');
        renderInteressentDetail(id);
    } catch (e) {
        showToast('Netzwerkfehler: ' + e.message, 'error');
    }
}

// ============================================
// CREATE / EDIT DIALOG — Stammdaten + Akquise-Status
// ============================================
const INT_FIELD_IDS = {
    firmenname: 'f_int_firmenname', branche: 'f_int_branche',
    mitarbeiterzahl_bereich: 'f_int_ma_bereich', einsatzgebiet: 'f_int_einsatzgebiet',
    ansprechpartner_name: 'f_int_ap_name', ansprechpartner_position: 'f_int_ap_position',
    telefon: 'f_int_telefon', email: 'f_int_email', quelle: 'f_int_quelle',
    zustaendiger_mitarbeiter: 'f_int_zustaendig', pipeline_phase: 'f_int_phase',
};

function _intMarkError(el, msg) {
    if (!el) return;
    if (msg) { el.error = true; el.errorText = msg; }
    else { el.error = false; el.errorText = ''; }
}
function intSetFieldError(field, msg) { _intMarkError(document.getElementById(INT_FIELD_IDS[field] || ''), msg); }
function intClearErrors() { Object.values(INT_FIELD_IDS).forEach(id => _intMarkError(document.getElementById(id), '')); }
function intApplyErrors(errors) {
    Object.entries(errors || {}).forEach(([f, msgs]) =>
        intSetFieldError(f, Array.isArray(msgs) ? msgs.join(' · ') : String(msgs)));
}

function intShowDialog(title, bodyHtml, footerHtml = '') {
    document.getElementById('interessentMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'interessentMdDialog';
    dlg.classList.add('customers-dialog-xl');
    dlg.innerHTML = `
      <div slot="headline">${title}</div>
      <div slot="content" class="customers-dialog-content int-dialog-content">${bodyHtml}</div>
      <div slot="actions">${footerHtml}</div>`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();
    return dlg;
}
function closeInteressentDialog() { document.getElementById('interessentMdDialog')?.close(); }

async function openInteressentDialogForm(id = null) {
    let r = {};
    if (id) {
        r = await apiGet(API.interessenten + '/' + encodeURIComponent(id));
        if (!r || r.error) { showToast(r?.error || 'Interessent nicht gefunden', 'error'); return; }
    }
    const [opt, maOpts] = await Promise.all([intOptionen(), intMaOpts()]);
    const canReassign = hasPermission('stammdaten.interessenten.view_all');

    const meId = window.CURRENT_USER && window.CURRENT_USER.id ? String(window.CURRENT_USER.id) : '';
    const curMa = r.zustaendiger_mitarbeiter ? String(r.zustaendiger_mitarbeiter) : meId;
    if (curMa && !maOpts.some(o => String(o.id) === curMa)) {
        maOpts.push({ id: curMa, name: r.zustaendiger_name || ('Benutzer #' + curMa) });
    }

    const selOpts = (list, curVal, placeholder) =>
        (placeholder ? `<md-select-option value="" ${!curVal ? 'selected' : ''}><div slot="headline">${placeholder}</div></md-select-option>` : '') +
        (list || []).map(v => `<md-select-option value="${escapeHtml(v)}" ${curVal === v ? 'selected' : ''}><div slot="headline">${escapeHtml(v)}</div></md-select-option>`).join('');

    const body = `
        <div class="customer-form-layout">
            <div class="customer-form-col">
                <div class="customer-form-section-title">${intIcon('domain')} Stammdaten</div>
                <div class="form-group">
                    <md-outlined-text-field id="f_int_firmenname" label="Firmenname" value="${escapeHtml(r.firmenname || '')}" required></md-outlined-text-field>
                </div>
                <div class="customer-form-row">
                    <div class="form-group" style="flex:1">
                        <md-outlined-select id="f_int_branche" label="Branche *">${selOpts(opt.branchen, r.branche, '— wählen —')}</md-outlined-select>
                    </div>
                    <div class="form-group" style="flex:1">
                        <md-outlined-select id="f_int_ma_bereich" label="Mitarbeiterzahl">${selOpts(opt.ma_bereiche, r.mitarbeiterzahl_bereich, '— optional —')}</md-outlined-select>
                    </div>
                </div>
                <div class="form-group">
                    <md-outlined-text-field id="f_int_einsatzgebiet" label="Einsatzgebiet" value="${escapeHtml(r.einsatzgebiet || '')}" placeholder="Stadt oder Region"><md-icon slot="trailing-icon">location_on</md-icon></md-outlined-text-field>
                </div>
                <div class="customer-form-row">
                    <div class="form-group" style="flex:2">
                        <md-outlined-text-field id="f_int_ap_name" label="Ansprechpartner" value="${escapeHtml(r.ansprechpartner_name || '')}" required></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_int_ap_position" label="Position" value="${escapeHtml(r.ansprechpartner_position || '')}"></md-outlined-text-field>
                    </div>
                </div>
                <div class="customer-form-row">
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_int_telefon" label="Telefon" value="${escapeHtml(r.telefon || '')}"><md-icon slot="trailing-icon">call</md-icon></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_int_email" label="E-Mail" value="${escapeHtml(r.email || '')}"><md-icon slot="trailing-icon">mail</md-icon></md-outlined-text-field>
                    </div>
                </div>
            </div>
            <div class="customer-form-col">
                <div class="customer-form-section-title">${intIcon('rocket_launch')} Akquise-Status</div>
                <div class="customer-form-row">
                    <div class="form-group" style="flex:1">
                        <md-outlined-select id="f_int_quelle" label="Quelle *">${selOpts(opt.quellen, r.quelle, '— wählen —')}</md-outlined-select>
                    </div>
                    <div class="form-group" style="flex:1">
                        <md-outlined-select id="f_int_phase" label="Pipeline-Phase *">${selOpts(opt.phasen, r.pipeline_phase || 'Erstkontakt')}</md-outlined-select>
                    </div>
                </div>
                <div class="form-group">
                    <md-outlined-select id="f_int_zustaendig" label="Zuständiger Mitarbeiter *" ${!canReassign ? 'disabled' : ''}>
                        ${maOpts.map(o => `<md-select-option value="${o.id}" ${String(o.id) === curMa ? 'selected' : ''}><div slot="headline">${escapeHtml(o.name)}</div></md-select-option>`).join('')}
                    </md-outlined-select>
                    ${!canReassign ? '<div class="int-muted" style="font-size:.75rem;margin-top:4px">Zuordnung nur durch Vertriebsleitung änderbar</div>' : ''}
                </div>
                <div class="int-auto-info">
                    ${intIcon('schedule', 'font-size:16px')}
                    <span>${id
                        ? `Erstellt am ${escapeHtml(r.created_at ? formatDateTimeLocal(r.created_at) : '–')}${r.erstellt_von_name ? ' · von ' + escapeHtml(r.erstellt_von_name) : ''}`
                        : `Erstellt am ${escapeHtml(new Date().toLocaleDateString('de-DE'))} · von ${escapeHtml((window.CURRENT_USER && (window.CURRENT_USER.full_name || window.CURRENT_USER.username)) || '–')} <span class="int-muted">(automatisch)</span>`}</span>
                </div>
                <div class="int-muted" style="font-size:.8rem;margin-top:6px">Nächster Schritt und Wiedervorlage werden im Kommunikationsverlauf erfasst.</div>
                <div class="customer-form-section-title" style="margin-top:18px">${intIcon('forum')} Kommunikationsverlauf</div>
                <div class="int-muted" style="font-size:.85rem">
                    ${id ? 'Einträge werden in der Detailansicht gepflegt.' : 'Verfügbar nach dem ersten Speichern.'}
                </div>
            </div>
        </div>
    `;

    const footer = `
        <md-text-button onclick="closeInteressentDialog()">Abbrechen</md-text-button>
        <md-filled-button onclick="saveInteressent('${id || ''}')">${id ? 'Aktualisieren' : 'Erstellen'}</md-filled-button>
    `;
    intShowDialog(id ? 'Interessent bearbeiten' : 'Neuer Interessent', body, footer);
}
// Public name used by buttons
function openInteressentDialog(id = null) { return openInteressentDialogForm(id); }

async function saveInteressent(id) {
    intClearErrors();
    const v = el => String(document.getElementById(el)?.value ?? '').trim();
    const data = {
        firmenname: v('f_int_firmenname'),
        branche: v('f_int_branche'),
        mitarbeiterzahl_bereich: v('f_int_ma_bereich') || null,
        einsatzgebiet: v('f_int_einsatzgebiet') || null,
        ansprechpartner_name: v('f_int_ap_name'),
        ansprechpartner_position: v('f_int_ap_position') || null,
        telefon: v('f_int_telefon') || null,
        email: v('f_int_email') || null,
        quelle: v('f_int_quelle'),
        zustaendiger_mitarbeiter: (() => {
            const z = document.getElementById('f_int_zustaendig');
            if (z && z.value) return z.value;
            return id ? undefined : null; // keep existing on update when select is disabled
        })(),
        pipeline_phase: v('f_int_phase'),
    };

    if (!data.firmenname) { intSetFieldError('firmenname', 'Firmenname ist erforderlich'); showToast('Firmenname ist erforderlich', 'error'); return; }
    if (!data.ansprechpartner_name) { intSetFieldError('ansprechpartner_name', 'Ansprechpartner ist erforderlich'); showToast('Ansprechpartner ist erforderlich', 'error'); return; }

    let result;
    try {
        const res = await fetch(id ? API.interessenten + '/' + encodeURIComponent(id) : API.interessenten, {
            method: id ? 'PUT' : 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify(data),
        });
        result = await res.json();
        if (res.status === 422 && result.errors) {
            intApplyErrors(result.errors);
            showToast('Bitte Eingabefehler korrigieren', 'error');
            return;
        }
        if (!res.ok) { showToast(result.error || 'Fehler beim Speichern', 'error'); return; }
    } catch (e) { showToast('Netzwerkfehler: ' + e.message, 'error'); return; }

    if (result.success) {
        closeInteressentDialog();
        showToast(id ? 'Interessent aktualisiert' : 'Interessent erstellt');
        if (_intDetailId && id) renderInteressentDetail(id);
        else renderInteressenten();
    } else {
        showToast(result.error || 'Fehler beim Speichern', 'error');
    }
}

async function konvertiereInteressent(id) {
    const row = _intRows.find(r => r.interessent_id === id) ||
        await apiGet(API.interessenten + '/' + encodeURIComponent(id)).catch(() => null);
    if (row && row.pipeline_phase !== 'Gewonnen') {
        if (!await mdConfirm('Der Interessent ist noch nicht in Phase „Gewonnen“ (aktuell: ' + (row.pipeline_phase || '–') + '). Die Phase muss zuerst auf „Gewonnen“ gesetzt werden — jetzt setzen?', { title: 'Zu Kunde konvertieren', confirmLabel: 'Phase setzen' })) return;
        const res = await fetch(API.interessenten + '/' + encodeURIComponent(id), {
            method: 'PUT',
            headers: getAuthHeaders(),
            body: JSON.stringify({ ...row, pipeline_phase: 'Gewonnen' }),
        });
        const out = await res.json().catch(() => ({}));
        if (!res.ok || !out.success) {
            showToast(out.error || (out.errors ? Object.values(out.errors).flat().join(' · ') : 'Phase konnte nicht gesetzt werden'), 'error');
            return;
        }
        row.pipeline_phase = 'Gewonnen';
    }
    if (!await mdConfirm('Interessent „' + (row?.firmenname || id) + '“ als Kunden übernehmen? Die Stammdaten werden in das Kundenmodul übertragen.', { title: 'Zu Kunde konvertieren', confirmLabel: 'Konvertieren' })) return;

    const result = await apiPost(API.interessenten + '/' + encodeURIComponent(id) + '/konvertieren', {});
    if (result && result.success) {
        showToast('Als Kunde ' + result.kunde_id + ' übernommen');
        renderInteressentDetail(id);
    } else {
        showToast(result?.error || (result?.errors ? Object.values(result.errors).flat().join(' · ') : 'Konvertierung fehlgeschlagen'), 'error');
        if (result?.error === 'Bereits konvertiert') renderInteressentDetail(id);
    }
}

async function deleteInteressent(id) {
    if (!await mdConfirm('Interessent „' + id + '“ endgültig löschen? Der Kommunikationsverlauf wird ebenfalls entfernt.', { title: 'Interessent löschen', confirmLabel: 'Löschen' })) return;
    const result = await apiDelete(API.interessenten + '/' + encodeURIComponent(id));
    if (result && result.success) {
        showToast('Interessent gelöscht');
        renderInteressenten();
    } else {
        showToast(result?.error || 'Löschen fehlgeschlagen', 'error');
    }
}
