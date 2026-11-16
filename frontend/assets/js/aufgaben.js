/* ============================================================
   Aufgaben (tasks) — assign work to workers and office users.
   Backend: GET  /api/aufgaben?ansicht=meine|erstellt|alle&status=&q=
            GET  /api/aufgaben/options   (Bereiche + recipients)
            GET  /api/aufgaben/{id}
            POST /api/aufgaben           (creates + notifies: bell, push, e-mail)
            PUT  /api/aufgaben/empfaenger/{id} { status, kommentar }
            DELETE /api/aufgaben/{id}
   Office-only: recipients are the users of Benutzerverwaltung (no field workers).
   ============================================================ */

const AU_API = API_BASE + '/aufgaben';
const AU_PRIO = {
    niedrig:  { label: 'Niedrig',  tone: 'info' },
    normal:   { label: 'Normal',   tone: 'neutral' },
    hoch:     { label: 'Hoch',     tone: 'warning' },
    dringend: { label: 'Dringend', tone: 'error' },
};
const AU_STATUS = {
    offen:     { label: 'Offen',     tone: 'primary', icon: 'radio_button_unchecked' },
    in_arbeit: { label: 'In Arbeit', tone: 'warning', icon: 'pending' },
    erledigt:  { label: 'Erledigt',  tone: 'success', icon: 'check_circle' },
};
const AU_ANSICHTEN = [
    { key: 'meine', label: 'Mir zugewiesen', icon: 'inbox' },
    { key: 'erstellt', label: 'Von mir erstellt', icon: 'outbox' },
    { key: 'alle', label: 'Alle', icon: 'list_alt' },
];
const AU_STATI = [['offen', 'Offen'], ['erledigt', 'Erledigt'], ['alle', 'Alle']];
const _au = { ansicht: 'meine', status: 'offen', q: '', rows: [], isAdmin: false, opts: null, sel: new Map() };

function auIcon(n, st = '') { return `<span class="material-symbols-outlined"${st ? ` style="${st}"` : ''}>${n}</span>`; }
function auDate(d) { return d ? formatDate(String(d).substring(0, 10)) : ''; }
function auOverdue(r) {
    if (!r.faellig_am || r.status === 'erledigt') return false;
    return String(r.faellig_am).substring(0, 10) < new Date().toISOString().substring(0, 10);
}

async function renderAufgaben() {
    const content = document.getElementById('content');
    content.innerHTML = `
        <div class="au-page">
            ${uiTabs('auSeg', AU_ANSICHTEN, _au.ansicht)}
            ${uiToolbar(
                uiChips(AU_STATI.map(([k, l]) => uiChip(l, { kind: 'filter', selected: _au.status === k, attrs: `data-s="${k}"`, onclick: `auSetStatus('${k}')` })).join(''), { id: 'auStat' })
                + uiTextField({ id: 'auSearch', type: 'search', icon: 'search', cls: 'au-search', value: _au.q, placeholder: 'Aufgaben suchen…' }),
                uiButton('Neue Aufgabe', { icon: 'add_task', onclick: 'auOpenCreate()' }))}
            <div id="auList" class="au-list">${uiEmptyState('hourglass_top', 'Lade…')}</div>
            <div id="auPager" class="be-pager"></div>
        </div>`;
    uiBindTabs('auSeg', auSetAnsicht);
    let t = null;
    document.getElementById('auSearch').addEventListener('input', e => {
        clearTimeout(t); t = setTimeout(() => { _au.q = e.target.value.trim(); auLoad(); }, 250);
    });
    if (window.HMSRealtime && typeof HMSRealtime.on === 'function' && !window._auRt) {
        window._auRt = true;
        HMSRealtime.on('aufgaben', () => { if (document.getElementById('auList')) auLoad(); });
    }
    auLoad();
}

function auSetAnsicht(a) { _au.ansicht = a; auLoad(); }
function auSetStatus(s) { _au.status = s; auLoad(); }

async function auLoad() {
    document.querySelectorAll('#auSeg md-primary-tab').forEach(t => { if (t.active !== (t.dataset.key === _au.ansicht)) t.active = t.dataset.key === _au.ansicht; });
    document.querySelectorAll('#auStat md-filter-chip').forEach(c => { c.selected = c.dataset.s === _au.status; });
    const qs = new URLSearchParams({ ansicht: _au.ansicht, status: _au.status, q: _au.q });
    const r = await apiGet(AU_API + '?' + qs.toString());
    _au.rows = (r && Array.isArray(r.data)) ? r.data : [];
    _au.isAdmin = !!(r && r.is_admin);
    const list = document.getElementById('auList');
    if (!list) return;
    if (!_au.rows.length) {
        hmsPagerClear('auPager');
        list.innerHTML = uiEmptyState('task_alt', '', { title: _au.ansicht === 'meine'
            ? (_au.status === 'offen' ? 'Keine offenen Aufgaben für Sie' : 'Keine Aufgaben')
            : 'Keine Aufgaben gefunden' });
        return;
    }
    const _p = hmsPagerDef('auPager', 'Aufgaben', () => auLoad());
    const pg = hmsPage(_au.rows, _p.st);
    hmsPagerFill('auPager', pg);
    const cards = pg.rows.map(auCardHtml).join('');
    // Patch in place: after a save / realtime only the changed task repaints.
    if (typeof hmsMorph === 'function') hmsMorph(list, cards); else list.innerHTML = cards;
}

function auCardHtml(r) {
    const p = AU_PRIO[r.prioritaet] || AU_PRIO.normal;
    const st = AU_STATUS[(_au.ansicht === 'meine' && r.mein_status) ? r.mein_status : r.status] || AU_STATUS.offen;
    const over = auOverdue(r);
    const bereich = r.bereich ? (typeof bereichBadgeHtml === 'function' ? bereichBadgeHtml(r.bereich) : uiBadge(r.bereich)) : '';
    const names = (r.empfaenger || []).map(e => e.name).filter(Boolean);
    const who = names.length > 3 ? names.slice(0, 3).join(', ') + ' +' + (names.length - 3) : names.join(', ');
    return `
        <div data-mk="a${parseInt(r.id, 10)}" class="au-card ${r.status === 'erledigt' ? 'is-done' : ''}" onclick="auOpenDetail(${parseInt(r.id, 10)})">
            <div class="au-card-main">
                <div class="au-card-top">
                    ${uiBadge(st.label, st.tone, st.icon)}
                    ${r.prioritaet !== 'normal' ? uiBadge(p.label, p.tone) : ''}
                    ${bereich}
                </div>
                <div class="au-card-title">${escapeHtml(r.titel)}</div>
                ${r.beschreibung ? `<div class="au-card-desc">${escapeHtml(r.beschreibung)}</div>` : ''}
                <div class="au-card-meta">
                    ${auIcon('person', 'font-size:15px')} ${escapeHtml(r.created_by_name || '–')}
                    <span>·</span> ${auIcon('group', 'font-size:15px')} ${escapeHtml(who || '–')}
                    ${r.anzahl > 1 ? uiBadge(`${r.erledigt}/${r.anzahl} erledigt`) : ''}
                </div>
            </div>
            <div class="au-card-side">
                ${r.faellig_am ? `<div class="au-due ${over ? 'is-over' : ''}">${auIcon('event', 'font-size:16px')} ${auDate(r.faellig_am)}</div>` : ''}
                <div class="au-created">${auDate(r.created_at)}</div>
            </div>
        </div>`;
}

// ---------- Detail ----------
async function auOpenDetail(id) {
    const a = await apiGet(AU_API + '/' + id);
    if (!a || a.error) return showToast((a && a.error) || 'Aufgabe nicht gefunden', 'error');
    const p = AU_PRIO[a.prioritaet] || AU_PRIO.normal;
    const mine = (a.empfaenger || []).find(e => e.id === a.mein_empfaenger_id);
    const rows = (a.empfaenger || []).map(e => {
        const st = AU_STATUS[e.status] || AU_STATUS.offen;
        return `<div class="au-emp">
            ${auIcon(e.typ === 'mitarbeiter' ? 'badge' : 'shield_person', 'color:var(--gray-500)')}
            <div class="au-emp-main"><strong>${escapeHtml(e.name || '–')}</strong>
                <small>Benutzer${e.erledigt_am ? ' · erledigt ' + auDate(e.erledigt_am) : (e.gelesen_am ? ' · gelesen' : '')}</small>
                ${e.kommentar ? `<div class="au-emp-note">${auIcon('chat', 'font-size:14px')} ${escapeHtml(e.kommentar)}</div>` : ''}
            </div>
            ${uiBadge(st.label, st.tone, st.icon)}
        </div>`;
    }).join('');
    const body = `
        <div class="au-detail">
            <div class="au-card-top">
                ${a.prioritaet !== 'normal' ? uiBadge(p.label, p.tone) : ''}
                ${a.bereich ? (typeof bereichBadgeHtml === 'function' ? bereichBadgeHtml(a.bereich) : escapeHtml(a.bereich)) : ''}
                ${a.faellig_am ? `<span class="au-due ${auOverdue(a) ? 'is-over' : ''}">${auIcon('event', 'font-size:16px')} fällig ${auDate(a.faellig_am)}</span>` : ''}
            </div>
            <h3 class="au-detail-title">${escapeHtml(a.titel)}</h3>
            ${a.bezug_typ && a.bezug_id ? uiButton(`${({ lead: 'Lead', deal: 'Deal', kontakt: 'Kontakt' })[a.bezug_typ] || 'Datensatz'} öffnen`, { variant: 'outlined', icon: 'open_in_new', onclick: `closeModal(); window._crmPreset = { open: { typ: '${escapeHtml(a.bezug_typ)}', id: '${escapeHtml(a.bezug_id)}' } }; loadPage('interessenten')` }) : ''}
            ${a.beschreibung ? `<div class="au-detail-desc">${escapeHtml(a.beschreibung).replace(/\n/g, '<br>')}</div>` : ''}
            <div class="au-detail-meta">${auIcon('person', 'font-size:15px')} Erstellt von <strong>${escapeHtml(a.created_by_name || '–')}</strong> am ${auDate(a.created_at)}</div>
            <div class="au-sec-title">Empfänger · ${a.erledigt}/${a.anzahl} erledigt</div>
            <div class="au-emps">${rows}</div>
            ${mine ? `
            <div class="au-sec-title">Mein Status</div>
            <md-outlined-text-field id="auKommentar" class="au-full" type="textarea" rows="2" maxlength="2000" placeholder="Kommentar (optional)…" value="${escapeHtml(mine.kommentar || '')}"></md-outlined-text-field>
            ${uiActions(uiButton('In Arbeit', { variant: 'outlined', icon: 'pending', onclick: `auSetMine(${mine.id}, 'in_arbeit', ${a.id})` })
                + uiButton('Erledigt', { tone: 'success', icon: 'check_circle', onclick: `auSetMine(${mine.id}, 'erledigt', ${a.id})` })
                + (mine.status !== 'offen' ? uiButton('Wieder offen', { variant: 'text', icon: 'undo', onclick: `auSetMine(${mine.id}, 'offen', ${a.id})` }) : ''))}` : ''}
        </div>`;
    const footer = (a.darf_loeschen ? uiButton('Löschen', { tone: 'danger', icon: 'delete', onclick: `auDelete(${a.id})` }) : '')
        + uiButton('Schließen', { variant: 'outlined', onclick: 'closeModal()' });
    openModal('Aufgabe', body, footer, 'modal-lg');
}

async function auSetMine(empId, status, aufgabeId) {
    const kommentar = document.getElementById('auKommentar')?.value ?? null;
    const r = await apiPut(AU_API + '/empfaenger/' + empId, { status, kommentar });
    if (!r || r.error) return showToast((r && r.error) || 'Fehler', 'error');
    showToast(status === 'erledigt' ? 'Als erledigt markiert' : 'Status gespeichert');
    closeModal(); auLoad(); auRefreshBadge();
}

async function auDelete(id) {
    if (!await mdConfirm('Aufgabe für alle Empfänger löschen?', { title: 'Aufgabe löschen', confirmLabel: 'Löschen' })) return;
    const r = await apiDelete(AU_API + '/' + id);
    if (!r || r.error) return showToast((r && r.error) || 'Fehler', 'error');
    showToast('Aufgabe gelöscht'); closeModal(); auLoad(); auRefreshBadge();
}

// ---------- Create ----------
async function auOpenCreate() {
    if (!_au.opts) _au.opts = await apiGet(AU_API + '/options');
    const o = _au.opts || { bereiche: [], mitarbeiter: [], users: [] };
    _au.sel = new Map();
    const body = `
        <div class="au-form">
            <div class="au-row">
                ${uiSelect({ id: 'auBereich', label: 'Bereich', cls: 'au-grow', attrs: 'onchange="auRenderEmp()"',
                    optionsHtml: uiOption('', '— ohne Bereich —', true) + (o.bereiche || []).map(b => uiOption(b, b)).join('') })}
                ${uiSelect({ id: 'auPrio', label: 'Priorität', cls: 'au-prio-field', optionsHtml: Object.entries(AU_PRIO).map(([k, v]) => uiOption(k, v.label, k === 'normal')).join('') })}
                ${uiTextField({ id: 'auFaellig', type: 'date', label: 'Fällig am', cls: 'au-date-field' })}
            </div>
            ${uiTextField({ id: 'auTitel', label: 'Was ist zu tun?', required: true, placeholder: 'z.B. Schlüssel im Objekt abholen', cls: 'au-full', attrs: 'maxlength="200"' })}
            <md-outlined-text-field id="auBeschreibung" class="au-full" type="textarea" rows="4" maxlength="5000" label="Details" placeholder="Was genau, wo, bis wann, worauf achten…"></md-outlined-text-field>
            <div class="au-field">
                <div class="au-field-label">Empfänger * <small id="auSelCount" class="au-muted">— niemand ausgewählt</small></div>
                ${uiTextField({ id: 'auEmpSearch', type: 'search', icon: 'search', cls: 'au-full', placeholder: 'Benutzer suchen…', attrs: 'oninput="auRenderEmp()"' })}
                <md-chip-set class="ui-chips" id="auSelChips"></md-chip-set>
                <div class="au-emp-pick" id="auEmpPick"></div>
            </div>
        </div>`;
    const footer = uiButton('Abbrechen', { variant: 'outlined', onclick: 'closeModal()' })
        + uiButton('Speichern & senden', { icon: 'send', id: 'auSendBtn', onclick: 'auSave()' });
    openModal('Neue Aufgabe', body, footer, 'modal-lg');
    auRenderEmp();
    setTimeout(() => document.getElementById('auTitel')?.focus(), 50);
}

function auRenderEmp() {
    const o = _au.opts || { users: [] };
    const q = (document.getElementById('auEmpSearch')?.value || '').toLowerCase().trim();
    const bereich = (document.getElementById('auBereich')?.value || '').toLowerCase();
    // Users responsible for the chosen Bereich (Benutzerverwaltung → Bereiche) first;
    // users without restriction count as responsible for everything.
    const fits = u => !bereich || !(u.bereiche || []).length || (u.bereiche || []).some(b => String(b).toLowerCase() === bereich);
    const us = (o.users || []).filter(u => !q || (u.name + ' ' + (u.rolle || '') + ' ' + (u.bereiche || []).join(' ')).toLowerCase().includes(q))
        .sort((a, b) => (fits(b) ? 1 : 0) - (fits(a) ? 1 : 0));
    const item = u => {
        const key = 'user:' + u.id;
        const on = _au.sel.has(key);
        const sub = [u.rolle, (u.bereiche || []).length ? (u.bereiche || []).join(', ') : 'alle Bereiche'].filter(Boolean).join(' · ');
        return `<label class="au-pick ${on ? 'on' : ''}"><md-checkbox ${on ? 'checked' : ''} onchange="auToggleEmp('user', '${parseInt(u.id, 10)}', this.checked)"></md-checkbox>
            <span><strong>${escapeHtml(u.name)}${u.ich ? ' (ich)' : ''}</strong><small>${escapeHtml(sub)}</small></span></label>`;
    };
    document.getElementById('auEmpPick').innerHTML = `
        <div class="au-pick-col"><div class="au-pick-head">${auIcon('shield_person')} Benutzer <small>(Benutzerverwaltung)</small></div>
            ${us.map(item).join('') || '<div class="au-muted">Keine Treffer</div>'}</div>`;
    auRenderSel();
}

function auToggleEmp(typ, id, on) {
    const o = _au.opts || {};
    const key = typ + ':' + id;
    if (on) {
        const src = (o.users || []).find(u => String(u.id) === String(id));
        _au.sel.set(key, { typ, id: typ === 'user' ? parseInt(id, 10) : id, name: src ? src.name : id });
    } else _au.sel.delete(key);
    auRenderEmp();
}

function auRenderSel() {
    const chips = document.getElementById('auSelChips');
    const cnt = document.getElementById('auSelCount');
    const list = [..._au.sel.entries()];
    if (cnt) cnt.textContent = list.length ? `— ${list.length} ausgewählt` : '— niemand ausgewählt';
    if (!chips) return;
    chips.innerHTML = list.map(([k, v]) => uiChip(v.name, { kind: 'input', icon: v.typ === 'mitarbeiter' ? 'badge' : 'shield_person', attrs: `data-typ="${escapeHtml(v.typ)}" data-id="${escapeHtml(String(v.id))}"` })).join('');
    // md-input-chip fires `remove` from its x button — sync the selection
    chips.querySelectorAll('md-input-chip').forEach(c => c.addEventListener('remove', e => {
        e.preventDefault();
        auToggleEmp(c.dataset.typ, c.dataset.id, false);
    }));
}

async function auSave() {
    const titel = String(document.getElementById('auTitel')?.value ?? '').trim();
    if (!titel) return showToast('Bitte eintragen, was zu tun ist', 'error');
    if (!_au.sel.size) return showToast('Bitte mindestens einen Empfänger auswählen', 'error');
    const btn = document.getElementById('auSendBtn');
    if (btn) btn.disabled = true;
    const body = {
        titel,
        beschreibung: String(document.getElementById('auBeschreibung')?.value ?? '').trim() || null,
        bereich: document.getElementById('auBereich')?.value || null,
        prioritaet: document.getElementById('auPrio')?.value || 'normal',
        faellig_am: document.getElementById('auFaellig')?.value || null,
        empfaenger: [..._au.sel.values()].map(v => ({ typ: v.typ, id: v.id })),
    };
    const r = await apiPost(AU_API, body);
    if (btn) btn.disabled = false;
    if (!r || r.error || !r.id) return showToast((r && (r.error || r.message)) || 'Aufgabe konnte nicht gespeichert werden', 'error');
    showToast(`Aufgabe gesendet an ${r.empfaenger} Empfänger`);
    closeModal();
    _au.ansicht = 'erstellt'; _au.status = 'offen';
    auLoad(); auRefreshBadge();
}

// Sidebar badge: open tasks assigned to me (also refreshed by notifications.js polling).
async function auRefreshBadge() {
    try {
        const r = await apiGet(AU_API + '/counts');
        const b = document.getElementById('badge-aufgaben');
        if (!b || !r) return;
        b.textContent = r.offen > 99 ? '99+' : r.offen;
        b.style.display = r.offen > 0 ? '' : 'none';
    } catch (e) { /* ignore */ }
}

window.renderAufgaben = renderAufgaben;
window.auRefreshBadge = auRefreshBadge;
