// tasks/01-tasks-core.js — Einsätze-Liste, Einsatz-Modal, Planner-State + Datums-Helfer
// Teil 1/10 des früheren tasks.js. Alle Teile teilen den globalen Scope und MÜSSEN in dieser Reihenfolge geladen werden (PAGE_MODULE_SCRIPTS in app.js).
// ============================================
// TASKS (Einsätze) — moved from app.js
// ============================================
let plannerState = {
    weekStart: null,
    data: null,
    viewMode: 'week',
    month: new Date().getMonth() + 1,
    year: new Date().getFullYear(),
    // Client-side filters applied in renderPlannerGrid. Stored on the state
    // (not on DOM) so they survive month/week navigation re-renders within
    // the same session.
    filters: {
        search: '',
        kunde: '',
        objekt: '',
        mitarbeiter: '',
        bereich: '',
    },
    _searchDebounce: null,
};

// Bereich gate memory — the pick made in the "Bereich auswählen" window is
// kept for the rest of the browser session (sessionStorage: survives page
// switches inside the SPA, dies with the tab / app). While a value is
// stored the gate is skipped and the filter applied straight away.
const PLANNER_BEREICH_KEY = 'hms_dispo_bereich';
function plannerRememberBereich(val) {
    try {
        if (val === null) sessionStorage.removeItem(PLANNER_BEREICH_KEY);
        else sessionStorage.setItem(PLANNER_BEREICH_KEY, val); // '' = "Alle Bereiche" is a valid pick
    } catch (e) { /* storage unavailable — gate simply shows again */ }
}
function plannerRecallBereich() {
    try { return sessionStorage.getItem(PLANNER_BEREICH_KEY); } catch (e) { return null; }
}

function plannerGetWeekStart(baseDate = null) {
    const date = baseDate ? new Date(baseDate) : new Date();
    const jsDay = date.getDay();
    const diff = jsDay === 0 ? -6 : 1 - jsDay;
    date.setDate(date.getDate() + diff);
    date.setHours(0, 0, 0, 0);
    return date;
}

function plannerToIsoDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function plannerFormatWeekLabel(weekStartIso) {
    const start = new Date(weekStartIso + 'T00:00:00');
    const end = new Date(start);
    end.setDate(end.getDate() + 6);
    return `${start.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' })} - ${end.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })}`;
}

function plannerFormatMonthLabel(month, year) {
    return `${MONTHS_DE[month]} ${year}`;
}
function plannerGetRangeLabel() {
    if (plannerState.viewMode === 'month') return plannerFormatMonthLabel(plannerState.month, plannerState.year);
    return plannerFormatWeekLabel(plannerState.weekStart);
}

function plannerWorkerName(worker) {
    return `${worker.vorname || ''} ${worker.name || ''}`.trim() || worker.dienst_nr;
}

// Avatar with initial-letter fallback — `default-avatar.png` doesn't exist
// and a stored `foto` URL can still 404; onerror swaps the broken <img> for
// a <span> holding the initial so the circle never shows a broken image.
function plannerWorkerAvatarHtml(foto, nameSource, extraStyle = '') {
    const ch = (nameSource || '?').toString().trim().charAt(0).toUpperCase() || '?';
    const inner = foto
        ? `<img src="${escapeHtml(foto)}" alt="${escapeHtml(ch)}" onerror="this.replaceWith(Object.assign(document.createElement('span'),{textContent:this.alt}))">`
        : `<span>${escapeHtml(ch)}</span>`;
    return `<div class="planner-worker-avatar"${extraStyle ? ` style="${extraStyle}"` : ''}>${inner}</div>`;
}

function plannerStatusClass(status) {
    if (status === 'confirmed') return 'confirmed';
    if (status === 'rejected') return 'rejected';
    if (status === 'planned') return 'planned';
    if (status === 'nu-pending') return 'nu-pending';
    return 'pending';
}

async function renderTasks() {
    console.log('[DISPO] renderTasks start');
    // Fresh page entry: show the Bereich selection gate once after data loads —
    // unless a pick from earlier in this session is remembered, then apply it
    // silently and skip the gate.
    const remembered = plannerRecallBereich();
    if (remembered !== null) {
        plannerState.filters.bereich = remembered;
        plannerState._gateShown = true;
    } else {
        plannerState._gateShown = false;
    }
    const content = document.getElementById('content');
    const start = plannerState.weekStart ? new Date(plannerState.weekStart + 'T00:00:00') : plannerGetWeekStart();
    plannerState.weekStart = plannerToIsoDate(start);

    // Replace page title with week navigation
    const titleEl = document.getElementById('pageTitle');
    if (titleEl) {
        titleEl.innerHTML = `
            <span class="planner-topbar-title-mobile">Planung</span>
            <div class="planner-topbar-nav">
                <button class="btn btn-outline btn-sm" onclick="plannerChangeWeek(-1)"><span class="material-symbols-outlined">chevron_left</span></button>
                <div style="text-align:center">
                    <div style="font-size:1rem;font-weight:700;line-height:1.2">Planung</div>
                    <div class="planner-week-range" id="plannerWeekRange" style="font-size:0.78rem;color:var(--primary);font-weight:600">${plannerFormatWeekLabel(plannerState.weekStart)}</div>
                </div>
                <button class="btn btn-outline btn-sm" onclick="plannerChangeWeek(1)"><span class="material-symbols-outlined">chevron_right</span></button>
                <div class="planner-view-toggle" style="position:relative;display:inline-flex">
                    <button class="btn btn-primary btn-sm planner-topbar-thisweek" onclick="plannerToggleViewMenu(this)" style="display:flex;align-items:center;gap:4px">${plannerState.viewMode === 'month' ? 'Dieser Monat' : 'Diese Woche'} <span class="material-symbols-outlined" style="font-size:1rem">arrow_drop_down</span></button>
                    <div class="planner-view-dropdown" id="plannerViewDropdown" style="display:none;position:absolute;top:100%;left:0;z-index:9999;background:var(--white);border:1px solid var(--gray-200);border-radius:8px;box-shadow:var(--shadow-md);min-width:160px;margin-top:4px;overflow:hidden">
                        <button class="planner-view-option" onclick="plannerSwitchView('week')" style="display:block;width:100%;text-align:left;padding:10px 14px;border:none;background:none;cursor:pointer;font-size:0.85rem;font-weight:600"><span class="material-symbols-outlined" style="width:20px;font-size:1rem;vertical-align:-3px">date_range</span> Diese Woche</button>
                        <button class="planner-view-option" onclick="plannerSwitchView('month')" style="display:block;width:100%;text-align:left;padding:10px 14px;border:none;background:none;cursor:pointer;font-size:0.85rem;font-weight:600"><span class="material-symbols-outlined" style="width:20px;font-size:1rem;vertical-align:-3px">calendar_month</span> Dieser Monat</button>
                    </div>
                </div>
                <div class="planner-topbar-actions">
                    <button class="btn btn-outline btn-sm" style="border-color:#16a34a;color:#16a34a" title="Aktive MA" onclick="openActiveWorkersPanel()"><span class="material-symbols-outlined">engineering</span> <span class="planner-btn-label">Aktive MA</span></button>
                    <button class="btn btn-outline btn-sm" style="border-color:#f59e0b;color:#f59e0b" title="Woche planen" onclick="bulkMarkPlanned()"><span class="material-symbols-outlined">assignment</span> <span class="planner-btn-label">Woche planen</span></button>
                    <button class="btn btn-outline btn-sm" title="Woche bestätigen" onclick="bulkConfirmDispo()"><span class="material-symbols-outlined">done_all</span> <span class="planner-btn-label">Woche bestätigen</span></button>
                    <button class="btn btn-outline btn-sm planner-boerse-btn" title="Schichtbörse" onclick="openBoerseDialog()"><span class="material-symbols-outlined">swap_horiz</span> <span class="planner-btn-label">Schichtbörse</span><span class="planner-boerse-badge" style="display:none">0</span></button>
                </div>
                <div class="planner-topbar-stats" id="plannerTopbarStats"></div>
            </div>
        `;
    }

    content.innerHTML = `
        <div class="planner-shell">
            <div class="planner-mobile-toolbar">
                <button class="btn btn-outline btn-sm" style="border-color:#16a34a;color:#16a34a" onclick="openActiveWorkersPanel()"><span class="material-symbols-outlined">engineering</span> Aktive MA</button>
                <button class="btn btn-outline btn-sm" style="border-color:#f59e0b;color:#f59e0b" onclick="bulkMarkPlanned()"><span class="material-symbols-outlined">assignment</span> Woche planen</button>
                <button class="btn btn-outline btn-sm" onclick="bulkConfirmDispo()"><span class="material-symbols-outlined">done_all</span> Woche bestätigen</button>
                <button class="btn btn-outline btn-sm planner-boerse-btn" title="Schichtbörse" onclick="openBoerseDialog()"><span class="material-symbols-outlined">swap_horiz</span> <span class="planner-btn-label">Schichtbörse</span><span class="planner-boerse-badge" style="display:none">0</span></button>
            </div>
            <div id="dispoOverview"></div>
            <div class="planner-mobile-weeknav card">
                <div class="planner-week-nav" style="justify-content:center">
                    <button class="btn btn-outline" onclick="plannerChangeWeek(-1)"><span class="material-symbols-outlined">chevron_left</span></button>
                    <div style="text-align:center">
                        <div style="font-size:1rem;font-weight:700;line-height:1.2">Planung</div>
                        <div id="plannerWeekRangeMobile" style="font-size:0.82rem;color:var(--primary);font-weight:600">${plannerFormatWeekLabel(plannerState.weekStart)}</div>
                    </div>
                    <button class="btn btn-outline" onclick="plannerChangeWeek(1)"><span class="material-symbols-outlined">chevron_right</span></button>
                    <div class="planner-view-toggle" style="position:relative;display:inline-flex">
                        <button class="btn btn-primary planner-topbar-thisweek" onclick="plannerToggleViewMenu(this)" style="display:flex;align-items:center;gap:4px">${plannerState.viewMode === 'month' ? 'Dieser Monat' : 'Diese Woche'} <span class="material-symbols-outlined" style="font-size:1rem">arrow_drop_down</span></button>
                        <div class="planner-view-dropdown" style="display:none;position:absolute;top:100%;left:0;z-index:9999;background:var(--white);border:1px solid var(--gray-200);border-radius:8px;box-shadow:var(--shadow-md);min-width:160px;margin-top:4px;overflow:hidden">
                            <button class="planner-view-option" onclick="plannerSwitchView('week')" style="display:block;width:100%;text-align:left;padding:10px 14px;border:none;background:none;cursor:pointer;font-size:0.85rem;font-weight:600"><span class="material-symbols-outlined" style="width:20px;font-size:1rem;vertical-align:-3px">date_range</span> Diese Woche</button>
                            <button class="planner-view-option" onclick="plannerSwitchView('month')" style="display:block;width:100%;text-align:left;padding:10px 14px;border:none;background:none;cursor:pointer;font-size:0.85rem;font-weight:600"><span class="material-symbols-outlined" style="width:20px;font-size:1rem;vertical-align:-3px">calendar_month</span> Dieser Monat</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="planner-calendar card">
                <div class="planner-grid" id="plannerGrid">
                    <div class="planner-loading">Lade Kalender...</div>
                </div>
            </div>
        </div>
    `;

    await loadDisposition();
    plannerStartAutoRefresh();

    // Deep-link from a notification ('#tasks?einsatz=<id>' / '#disposition?…'):
    // open the exact Einsatz detail modal it came from.
    try {
        const fq = sessionStorage.getItem('hms_focus_tasks');
        if (fq) {
            sessionStorage.removeItem('hms_focus_tasks');
            const einsatzId = new URLSearchParams(fq).get('einsatz');
            if (einsatzId) openPlannerTaskDetail(einsatzId);
        }
    } catch (e) { /* sessionStorage unavailable — ignore */ }
}

async function openTaskModal(id = null) {
    let task = {};
    if (id) task = await apiGet(API.tasks + '?id=' + id);
    const orderOpts = await getOrderOptions();
    const nuOpts = await getSubcontractorOptions();

    const body = `
        <div class="form-grid">
            <div class="form-group">
                <label>Bestellung *</label>
                <select class="form-control" id="f_task_bestell">${orderOpts}</select>
            </div>
            <div class="form-group">
                <label>Nachunternehmer</label>
                <select class="form-control" id="f_task_nu"><option value="">–</option>${nuOpts}</select>
            </div>
            <div class="form-group">
                <label>Datum *</label>
                <input type="date" class="form-control" id="f_task_datum" value="${task.datum || ''}">
            </div>
            <div class="form-group">
                <label>Ist-Start</label>
                <input type="text" lang="de-DE" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="form-control t24" id="f_task_start" value="${task.ist_start || ''}" readonly>
            </div>
            <div class="form-group">
                <label>Ist-Ende</label>
                <input type="text" lang="de-DE" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="form-control t24" id="f_task_ende" value="${task.ist_ende || ''}" readonly>
            </div>
            <div class="form-group">
                <label>Ist-Std/Menge</label>
                <input type="number" step="0.01" class="form-control" id="f_task_std" value="${task.ist_std_menge || ''}">
            </div>
            <div class="form-group">
                <label>Ist-Betrag (€)</label>
                <input type="number" step="0.01" class="form-control" id="f_task_betrag" value="${task.ist_betrag || ''}">
            </div>
            <div class="form-group">
                <label>HMS bestätigt</label>
                <select class="form-control" id="f_task_confirm">
                    <option value="ausstehend" ${task.hms_bestaetigt === 'ausstehend' || !id ? 'selected' : ''}>Ausstehend</option>
                    <option value="ja" ${task.hms_bestaetigt === 'ja' ? 'selected' : ''}>Ja</option>
                    <option value="nein" ${task.hms_bestaetigt === 'nein' ? 'selected' : ''}>Nein</option>
                </select>
            </div>
            <div class="form-group full-width">
                <label>Bemerkung</label>
                <textarea class="form-control" id="f_task_bemerkung" rows="2">${escapeHtml(task.bemerkung || '')}</textarea>
            </div>
        </div>
    `;
    const footer = `
        <button class="btn btn-outline" onclick="closeModal()">Abbrechen</button>
        <button class="btn btn-primary" onclick="saveTask('${id || ''}')">${id ? 'Aktualisieren' : 'Erstellen'}</button>
    `;
    openModal(id ? 'Einsatz bearbeiten' : 'Neuer Einsatz', body, footer);

    if (task.bestell_id) document.getElementById('f_task_bestell').value = task.bestell_id;
    if (task.nu_id) document.getElementById('f_task_nu').value = task.nu_id;
}

async function saveTask(id) {
    const data = {
        bestell_id: document.getElementById('f_task_bestell').value,
        nu_id: document.getElementById('f_task_nu').value || null,
        datum: document.getElementById('f_task_datum').value,
        ist_start: document.getElementById('f_task_start').value || null,
        ist_ende: document.getElementById('f_task_ende').value || null,
        ist_std_menge: parseFloat(document.getElementById('f_task_std').value) || null,
        ist_betrag: parseFloat(document.getElementById('f_task_betrag').value) || null,
        hms_bestaetigt: document.getElementById('f_task_confirm').value,
        bemerkung: document.getElementById('f_task_bemerkung').value || null
    };
    if (!data.bestell_id || !data.datum) { showToast('Bestellung und Datum erforderlich', 'error'); return; }

    if (id) data.einsatz_id = id;
    const result = id ? await apiPut(API.tasks + '/' + id, data) : await apiPost(API.tasks, data);
    if (result.success) {
        closeModal();
        showToast(id ? 'Einsatz aktualisiert' : 'Einsatz erstellt');
        loadDisposition();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function deleteTask(id) {
    const ok = await mdConfirm('Einsatz "<strong>' + id + '</strong>" endgültig löschen?', { title: 'Einsatz löschen', confirmLabel: 'Löschen' });
    if (!ok) return;
    let result = await apiDelete(API.tasks + '/' + id);
    if (result.needs_confirm) {
        const sure = await mdConfirm(
            '<strong>' + escapeHtml(id) + '</strong> ist ein <strong>bestätigter</strong> Einsatz.<br><br>' +
            'Der Tag wird auch aus der Bestellung entfernt, eingeplante Mitarbeiter und Nachunternehmer werden benachrichtigt und noch laufende Zeiterfassungen beendet. Der Vorgang wird im Verlauf der Bestellung protokolliert.<br><br>Wirklich löschen?',
            { title: 'Bestätigten Einsatz löschen', confirmLabel: 'Endgültig löschen' }
        );
        if (!sure) return;
        result = await apiDelete(API.tasks + '/' + id, { bestaetigt_loeschen: true });
    }
    if (result.success) {
        showToast('Einsatz gelöscht');
        closeModal();
        if (currentPage === 'tasks' || currentPage === 'disposition') loadDisposition();
    } else if (result.freigegeben || result.blocked_shifts || result.eingang_id) {
        mdAlert(escapeHtml(result.error || 'Fehler beim Löschen') + (Array.isArray(result.blocked_shifts) && result.blocked_shifts.length ? '<br><br>&bull; ' + result.blocked_shifts.map(escapeHtml).join('<br>&bull; ') : ''), 'Löschen nicht möglich');
    } else {
        showToast(result.error || 'Fehler beim Löschen', 'error');
    }
}

async function confirmTask(id, status) {
    const result = await apiPut(API.tasks + '/' + id, { einsatz_id: id, hms_bestaetigt: status, confirm_only: true });
    if (result.success) {
        showToast(status === 'ja' ? 'Einsatz bestätigt' : 'Einsatz abgelehnt');
        if (currentPage === 'tasks' || currentPage === 'disposition') loadDisposition();
    }
}
