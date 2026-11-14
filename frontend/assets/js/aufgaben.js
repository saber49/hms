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
    niedrig:  { label: 'Niedrig',  cls: 'au-p-low' },
    normal:   { label: 'Normal',   cls: 'au-p-normal' },
    hoch:     { label: 'Hoch',     cls: 'au-p-high' },
    dringend: { label: 'Dringend', cls: 'au-p-urgent' },
};
const AU_STATUS = {
    offen:     { label: 'Offen',     cls: 'au-s-open',  icon: 'radio_button_unchecked' },
    in_arbeit: { label: 'In Arbeit', cls: 'au-s-work',  icon: 'pending' },
    erledigt:  { label: 'Erledigt',  cls: 'au-s-done',  icon: 'check_circle' },
};
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
            <div class="au-toolbar">
                <div class="au-seg" id="auSeg">
                    <button data-a="meine" onclick="auSetAnsicht('meine')">${auIcon('inbox')} Mir zugewiesen</button>
                    <button data-a="erstellt" onclick="auSetAnsicht('erstellt')">${auIcon('outbox')} Von mir erstellt</button>
                    <button data-a="alle" onclick="auSetAnsicht('alle')">${auIcon('list_alt')} Alle</button>
                </div>
                <div class="au-seg au-seg-sm" id="auStat">
                    <button data-s="offen" onclick="auSetStatus('offen')">Offen</button>
                    <button data-s="erledigt" onclick="auSetStatus('erledigt')">Erledigt</button>
                    <button data-s="alle" onclick="auSetStatus('alle')">Alle</button>
                </div>
                <div class="au-search">${auIcon('search')}<input id="auSearch" placeholder="Aufgaben suchen…" value="${escapeHtml(_au.q)}"></div>
                <button class="au-btn au-btn-primary" onclick="auOpenCreate()">${auIcon('add_task')} Neue Aufgabe</button>
            </div>
            <div id="auList" class="au-list"><div class="au-empty">${auIcon('hourglass_top')} Lade…</div></div>
            <div id="auPager" class="be-pager"></div>
        </div>
        ${auStyles()}`;
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
    document.querySelectorAll('#auSeg button').forEach(b => b.classList.toggle('on', b.dataset.a === _au.ansicht));
    document.querySelectorAll('#auStat button').forEach(b => b.classList.toggle('on', b.dataset.s === _au.status));
    const qs = new URLSearchParams({ ansicht: _au.ansicht, status: _au.status, q: _au.q });
    const r = await apiGet(AU_API + '?' + qs.toString());
    _au.rows = (r && Array.isArray(r.data)) ? r.data : [];
    _au.isAdmin = !!(r && r.is_admin);
    const list = document.getElementById('auList');
    if (!list) return;
    if (!_au.rows.length) {
        hmsPagerClear('auPager');
        list.innerHTML = `<div class="au-empty">${auIcon('task_alt', 'font-size:40px')}<div>${_au.ansicht === 'meine'
            ? (_au.status === 'offen' ? 'Keine offenen Aufgaben für Sie' : 'Keine Aufgaben')
            : 'Keine Aufgaben gefunden'}</div></div>`;
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
    const bereich = r.bereich ? (typeof bereichBadgeHtml === 'function' ? bereichBadgeHtml(r.bereich) : `<span class="au-chip">${escapeHtml(r.bereich)}</span>`) : '';
    const names = (r.empfaenger || []).map(e => e.name).filter(Boolean);
    const who = names.length > 3 ? names.slice(0, 3).join(', ') + ' +' + (names.length - 3) : names.join(', ');
    return `
        <div data-mk="a${parseInt(r.id, 10)}" class="au-card ${r.status === 'erledigt' ? 'is-done' : ''}" onclick="auOpenDetail(${parseInt(r.id, 10)})">
            <div class="au-card-main">
                <div class="au-card-top">
                    <span class="au-status ${st.cls}">${auIcon(st.icon, 'font-size:15px')} ${st.label}</span>
                    ${r.prioritaet !== 'normal' ? `<span class="au-prio ${p.cls}">${p.label}</span>` : ''}
                    ${bereich}
                </div>
                <div class="au-card-title">${escapeHtml(r.titel)}</div>
                ${r.beschreibung ? `<div class="au-card-desc">${escapeHtml(r.beschreibung)}</div>` : ''}
                <div class="au-card-meta">
                    ${auIcon('person', 'font-size:15px')} ${escapeHtml(r.created_by_name || '–')}
                    <span>·</span> ${auIcon('group', 'font-size:15px')} ${escapeHtml(who || '–')}
                    ${r.anzahl > 1 ? `<span class="au-prog">${r.erledigt}/${r.anzahl} erledigt</span>` : ''}
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
            <span class="au-status ${st.cls}">${auIcon(st.icon, 'font-size:15px')} ${st.label}</span>
        </div>`;
    }).join('');
    const body = `
        <div class="au-detail">
            <div class="au-card-top">
                ${a.prioritaet !== 'normal' ? `<span class="au-prio ${p.cls}">${p.label}</span>` : ''}
                ${a.bereich ? (typeof bereichBadgeHtml === 'function' ? bereichBadgeHtml(a.bereich) : escapeHtml(a.bereich)) : ''}
                ${a.faellig_am ? `<span class="au-due ${auOverdue(a) ? 'is-over' : ''}">${auIcon('event', 'font-size:16px')} fällig ${auDate(a.faellig_am)}</span>` : ''}
            </div>
            <h3 class="au-detail-title">${escapeHtml(a.titel)}</h3>
            ${a.bezug_typ && a.bezug_id ? `<button class="au-btn" onclick="closeModal(); window._crmPreset = { open: { typ: '${escapeHtml(a.bezug_typ)}', id: '${escapeHtml(a.bezug_id)}' } }; loadPage('interessenten')">${auIcon('open_in_new')} ${({ lead: 'Lead', deal: 'Deal', kontakt: 'Kontakt' })[a.bezug_typ] || 'Datensatz'} öffnen</button>` : ''}
            ${a.beschreibung ? `<div class="au-detail-desc">${escapeHtml(a.beschreibung).replace(/\n/g, '<br>')}</div>` : ''}
            <div class="au-detail-meta">${auIcon('person', 'font-size:15px')} Erstellt von <strong>${escapeHtml(a.created_by_name || '–')}</strong> am ${auDate(a.created_at)}</div>
            <div class="au-sec-title">Empfänger · ${a.erledigt}/${a.anzahl} erledigt</div>
            <div class="au-emps">${rows}</div>
            ${mine ? `
            <div class="au-sec-title">Mein Status</div>
            <textarea id="auKommentar" class="au-input" rows="2" maxlength="2000" placeholder="Kommentar (optional)…">${escapeHtml(mine.kommentar || '')}</textarea>
            <div class="au-mine-btns">
                <button class="au-btn" onclick="auSetMine(${mine.id}, 'in_arbeit', ${a.id})">${auIcon('pending')} In Arbeit</button>
                <button class="au-btn au-btn-ok" onclick="auSetMine(${mine.id}, 'erledigt', ${a.id})">${auIcon('check_circle')} Erledigt</button>
                ${mine.status !== 'offen' ? `<button class="au-btn" onclick="auSetMine(${mine.id}, 'offen', ${a.id})">${auIcon('undo')} Wieder offen</button>` : ''}
            </div>` : ''}
        </div>`;
    const footer = `${a.darf_loeschen ? `<button class="btn btn-danger" onclick="auDelete(${a.id})">${auIcon('delete')} Löschen</button>` : ''}
        <button class="btn btn-outline" onclick="closeModal()">Schließen</button>`;
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
                <label class="au-field" style="flex:1">Bereich
                    <select id="auBereich" class="au-input" onchange="auRenderEmp()"><option value="">— ohne Bereich —</option>
                        ${(o.bereiche || []).map(b => `<option value="${escapeHtml(b)}">${escapeHtml(b)}</option>`).join('')}
                    </select></label>
                <label class="au-field" style="flex:0 0 150px">Priorität
                    <select id="auPrio" class="au-input">${Object.entries(AU_PRIO).map(([k, v]) => `<option value="${k}" ${k === 'normal' ? 'selected' : ''}>${v.label}</option>`).join('')}</select></label>
                <label class="au-field" style="flex:0 0 170px">Fällig am
                    <input type="date" id="auFaellig" class="au-input"></label>
            </div>
            <label class="au-field">Was ist zu tun? *
                <input id="auTitel" class="au-input" maxlength="200" placeholder="z.B. Schlüssel im Objekt abholen"></label>
            <label class="au-field">Details
                <textarea id="auBeschreibung" class="au-input" rows="4" maxlength="5000" placeholder="Was genau, wo, bis wann, worauf achten…"></textarea></label>
            <div class="au-field">Empfänger * <small id="auSelCount" class="au-muted">— niemand ausgewählt</small>
                <div class="au-search au-search-full">${auIcon('search')}<input id="auEmpSearch" placeholder="Benutzer suchen…" oninput="auRenderEmp()"></div>
                <div class="au-chips" id="auSelChips"></div>
                <div class="au-emp-pick" id="auEmpPick"></div>
            </div>
        </div>`;
    const footer = `<button class="btn btn-outline" onclick="closeModal()">Abbrechen</button>
        <button class="btn btn-primary" id="auSendBtn" onclick="auSave()">${auIcon('send')} Speichern & senden</button>`;
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
        return `<label class="au-pick ${on ? 'on' : ''}"><input type="checkbox" ${on ? 'checked' : ''} onchange="auToggleEmp('user', '${parseInt(u.id, 10)}', this.checked)">
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
    if (chips) chips.innerHTML = list.map(([k, v]) => `<span class="au-chip au-chip-sel">${auIcon(v.typ === 'mitarbeiter' ? 'badge' : 'shield_person', 'font-size:14px')} ${escapeHtml(v.name)}
        <button type="button" onclick="auToggleEmp('${v.typ}', '${escapeHtml(String(v.id)).replace(/'/g, '')}', false)">${auIcon('close', 'font-size:14px')}</button></span>`).join('');
}

async function auSave() {
    const titel = (document.getElementById('auTitel')?.value || '').trim();
    if (!titel) return showToast('Bitte eintragen, was zu tun ist', 'error');
    if (!_au.sel.size) return showToast('Bitte mindestens einen Empfänger auswählen', 'error');
    const btn = document.getElementById('auSendBtn');
    if (btn) btn.disabled = true;
    const body = {
        titel,
        beschreibung: (document.getElementById('auBeschreibung')?.value || '').trim() || null,
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

function auStyles() {
    return `<style>
    .au-page{padding:4px 2px}
    .au-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px}
    .au-seg{display:inline-flex;background:var(--gray-100);border-radius:12px;padding:3px;gap:2px}
    .au-seg button{display:inline-flex;align-items:center;gap:6px;border:0;background:none;padding:7px 12px;border-radius:9px;font:inherit;font-size:.85rem;font-weight:600;color:var(--gray-600);cursor:pointer}
    .au-seg button .material-symbols-outlined{font-size:18px}
    .au-seg button.on{background:var(--white);color:var(--gray-900);box-shadow:0 1px 3px rgba(15,23,42,.12)}
    .au-seg-sm button{padding:6px 10px;font-size:.8rem}
    .au-search{display:flex;align-items:center;gap:6px;flex:1;min-width:200px;border:1px solid var(--gray-200);border-radius:10px;padding:0 10px;background:var(--white)}
    .au-search input{border:0;outline:0;background:none;flex:1;padding:9px 0;font:inherit;color:inherit}
    .au-search .material-symbols-outlined{font-size:19px;color:var(--gray-400)}
    .au-search-full{margin:6px 0}
    .au-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--gray-300);background:var(--white);color:var(--gray-800);padding:8px 14px;border-radius:10px;font:inherit;font-size:.86rem;font-weight:600;cursor:pointer}
    .au-btn .material-symbols-outlined{font-size:18px}
    .au-btn-primary{background:var(--accent,#2563eb);border-color:var(--accent,#2563eb);color:var(--md-sys-color-on-primary,#fff)}
    .au-btn-ok{background:#16a34a;border-color:#16a34a;color:#fff}
    .au-list{display:flex;flex-direction:column;gap:10px}
    .au-card{display:flex;gap:14px;padding:14px 16px;border:1px solid var(--gray-200);border-left:4px solid var(--accent,#2563eb);border-radius:14px;background:var(--white);cursor:pointer;transition:box-shadow .15s}
    .au-card:hover{box-shadow:0 4px 14px rgba(15,23,42,.08)}
    .au-card.is-done{opacity:.65;border-left-color:#16a34a}
    .au-card-main{flex:1;min-width:0}
    .au-card-top{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px}
    .au-card-title{font-weight:700;font-size:1rem;color:var(--gray-900)}
    .au-card-desc{font-size:.85rem;color:var(--gray-600);margin-top:3px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
    .au-card-meta{display:flex;flex-wrap:wrap;align-items:center;gap:5px;margin-top:8px;font-size:.78rem;color:var(--gray-500)}
    .au-prog{margin-left:6px;padding:1px 8px;border-radius:999px;background:var(--gray-100);font-weight:600;color:var(--gray-700)}
    .au-card-side{display:flex;flex-direction:column;align-items:flex-end;gap:6px;white-space:nowrap}
    .au-created{font-size:.74rem;color:var(--gray-400)}
    .au-due{display:inline-flex;align-items:center;gap:4px;font-size:.8rem;font-weight:600;color:var(--gray-700)}
    .au-due.is-over{color:#dc2626}
    .au-status,.au-prio,.au-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:999px;font-size:.72rem;font-weight:700}
    .au-s-open{background:rgba(100,116,139,.12);color:#475569}
    .au-s-work{background:rgba(234,179,8,.16);color:#a16207}
    .au-s-done{background:rgba(22,163,74,.14);color:#15803d}
    .au-p-low{background:rgba(100,116,139,.12);color:#64748b}
    .au-p-high{background:rgba(249,115,22,.15);color:#c2410c}
    .au-p-urgent{background:rgba(220,38,38,.14);color:#dc2626}
    .au-chip{background:var(--gray-100);color:var(--gray-700)}
    .au-chip-sel{padding:3px 4px 3px 9px}
    .au-chip-sel button{border:0;background:none;cursor:pointer;display:inline-flex;color:inherit;padding:0 2px}
    .au-chips{display:flex;flex-wrap:wrap;gap:6px;margin:4px 0}
    .au-empty{display:flex;flex-direction:column;align-items:center;gap:8px;padding:50px 10px;color:var(--gray-400)}
    .au-form{display:flex;flex-direction:column;gap:12px}
    .au-row{display:flex;gap:12px;flex-wrap:wrap}
    .au-field{display:flex;flex-direction:column;gap:5px;font-size:.8rem;font-weight:600;color:var(--gray-600)}
    .au-input{font:inherit;font-size:.92rem;font-weight:400;padding:9px 11px;border:1px solid var(--gray-300);border-radius:10px;background:var(--white);color:var(--gray-900);width:100%;box-sizing:border-box}
    .au-input:focus{outline:none;border-color:var(--accent,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.15)}
    textarea.au-input{resize:vertical;line-height:1.45}
    .au-muted{color:var(--gray-400);font-weight:400;font-size:.8rem}
    .au-emp-pick{display:grid;grid-template-columns:1fr;gap:10px;max-height:300px;overflow:auto;border:1px solid var(--gray-200);border-radius:12px;padding:8px}
    .au-pick-col{display:flex;flex-direction:column;gap:2px;min-width:0}
    .au-pick-head{display:flex;align-items:center;gap:6px;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--gray-500);padding:4px 6px;position:sticky;top:-8px;background:var(--white)}
    .au-pick{display:flex;align-items:flex-start;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;font-weight:400}
    .au-pick:hover{background:var(--gray-50)}
    .au-pick.on{background:rgba(37,99,235,.08)}
    .au-pick span{display:flex;flex-direction:column;min-width:0;font-size:.86rem;color:var(--gray-800)}
    .au-pick small{color:var(--gray-500);font-size:.74rem}
    .au-detail-title{margin:4px 0 6px;font-size:1.2rem}
    .au-detail-desc{background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:10px 12px;font-size:.92rem;line-height:1.5;color:var(--gray-800)}
    .au-detail-meta{display:flex;align-items:center;gap:5px;font-size:.8rem;color:var(--gray-500);margin:10px 0}
    .au-sec-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-500);margin:14px 0 6px}
    .au-emps{display:flex;flex-direction:column;gap:6px}
    .au-emp{display:flex;align-items:flex-start;gap:10px;padding:8px 10px;border:1px solid var(--gray-200);border-radius:10px}
    .au-emp-main{flex:1;display:flex;flex-direction:column;min-width:0}
    .au-emp-main small{color:var(--gray-500);font-size:.75rem}
    .au-emp-note{margin-top:4px;font-size:.82rem;color:var(--gray-700)}
    .au-mine-btns{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
    @media (max-width:700px){.au-emp-pick{grid-template-columns:1fr}.au-card{flex-direction:column}.au-card-side{align-items:flex-start;flex-direction:row}}
    </style>`;
}

window.renderAufgaben = renderAufgaben;
window.auRefreshBadge = auRefreshBadge;
