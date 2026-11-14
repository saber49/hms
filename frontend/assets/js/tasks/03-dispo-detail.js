// tasks/03-dispo-detail.js — Einsatz-Detail-Modal: Mitarbeiter zuweisen, Plan/Bestätigen, Speichern
// Teil 3/10 des früheren tasks.js. Alle Teile teilen den globalen Scope und MÜSSEN in dieser Reihenfolge geladen werden (PAGE_MODULE_SCRIPTS in app.js).

async function openPlannerTaskDetail(einsatzId) {
    // Reset all disposition state on fresh open
    stopNuRefreshPolling();
    DispoState.reset();
    _nuEditingNuId = null;
    _nuPositions = [];
    _nuGroupsLoaded = false;
    window._plannerSelectedNuId = null;
    window._nuPositionsBaseline = null;

    const data = await apiGet(API.tasks + '/' + encodeURIComponent(einsatzId));
    if (!data || data.error) {
        showToast((data && data.error) || 'Einsatz konnte nicht geladen werden', 'error');
        return;
    }

    const task = data.task;
    const assignedMap = {};
    (task.assigned_workers || []).forEach(w => { assignedMap[w.dienst_nr] = w; });
    const assignedIds = new Set(Object.keys(assignedMap));
    const assignedWorkers = (data.workers || []).filter(worker => assignedIds.has(worker.dienst_nr)).map(w => ({ ...w, ...assignedMap[w.dienst_nr], warnings: w.warnings || [], is_favorite: w.is_favorite || 0 }));
    const availableWorkers = (data.workers || []).filter(worker => !assignedIds.has(worker.dienst_nr));
    // Qualifications the shift asks for (Anforderungen) — matched by name
    // against the worker's catalog qualifications (Personal → Mitarbeiter).
    window._plannerQualCatalog = new Set((data.qual_catalog || []).map(_plannerQualKey));
    window._plannerReqQuals = plannerRequiredQuals(task.anforderungen);
    window._plannerReqGender = plannerRequiredGenderMix(task.anforderungen || {});
    // Stadium ban ("Gesperrt") only matters on stadium/DFB shifts (server: ist_stadion).
    window._plannerIsStadion = !!task.ist_stadion;
    // Pool order: workers holding ALL required qualifications first, then
    // fewest missing; within that this customer's favorites (Stamm-Team).
    const missingOf = w => plannerQualMatch(w).missing.length + (w.stadion_gesperrt == 1 && window._plannerIsStadion ? 100 : 0);
    availableWorkers.sort((a, b) => missingOf(a) - missingOf(b) || (b.is_favorite ? 1 : 0) - (a.is_favorite ? 1 : 0));

    // Store NU acceptance status for card styling
    _nuTaskNuStatus = task.nu_status || null;

    // Determine required worker count: from soll_menge or fallback from beschreibung
    let sollMenge = task.soll_menge ? parseInt(task.soll_menge) : 0;
    if (!sollMenge && task.beschreibung) {
        const m = task.beschreibung.match(/(\d+)\s*Mitarbeiter\s*ben/i);
        if (m) sollMenge = parseInt(m[1]);
    }
    // Store current task data for the new NU selection overlay
    window._plannerSubcontractors = data.subcontractors || [];
    window._plannerTaskAnf = task.anforderungen || {};
    window._plannerSollMenge = sollMenge;
    window._plannerEinsatzId = einsatzId;

    // Store original worker IDs for detecting changes on re-plan
    window._plannerOriginalWorkerIds = [...assignedIds];
    window._plannerOriginalPruefung = task.pruefung || null;

    // ── Read-only view for confirmed tasks ──
    if (task.hms_bestaetigt === 'ja') {
        const nuName = (data.subcontractors || []).find(nu => nu.nu_id === task.nu_id);
        const nuAssigned = task.assigned_nu_workers || [];
        const totalWorkerCount = assignedWorkers.length + nuAssigned.length;
        const readBody = `
            <div class="planner-detail">
                <div class="planner-confirmed-banner">
                    <span class="material-symbols-outlined">check_circle</span>
                    <span>Einsatz bestätigt</span>
                </div>
                <div class="planner-detail-headline">${formatDate(task.datum)} · ${task.ist_start ? task.ist_start.substring(0,5) : '–'} - ${task.ist_ende ? task.ist_ende.substring(0,5) : '–'} Uhr</div>
                <div class="planner-detail-tabs">
                    <div class="planner-detail-pill active">${escapeHtml(task.kundenname || task.kunden_id)}</div>
                    <div class="planner-detail-pill">${escapeHtml(task.objektname || task.obj_id || '–')}</div>
                    ${typeof schichtartBadge === 'function' ? schichtartBadge(task.schichtart, task.zuschlag_pct) : ''}
                </div>
                <div class="planner-detail-meta" style="margin:10px 0">${plannerOrderRefHtml(task)} · Pause ${task.pause_minuten || 0} Min${task.soll_menge ? ` · Menge ${task.soll_menge}` : ''}</div>
                ${task.beschreibung ? `<div style="margin-bottom:12px"><strong>Schichtnotiz:</strong> ${escapeHtml(task.beschreibung)}</div>` : ''}
                ${task.ort ? `<div style="margin-bottom:12px"><strong><span class="material-symbols-outlined" style="color:#3b82f6">location_on</span> Ort / Treffpunkt:</strong> ${escapeHtml(task.ort)} <a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(ortMapsQuery(task.ort))}" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:none;font-weight:600;margin-left:6px" title="In Google Maps öffnen"><span class="material-symbols-outlined">map</span> Karte</a></div>` : ''}
                ${task.bemerkung ? `<div style="margin-bottom:12px"><strong>Bemerkung:</strong> ${escapeHtml(task.bemerkung)}</div>` : ''}
                ${nuName ? `<div style="margin-bottom:12px"><strong>Nachunternehmer:</strong> ${escapeHtml(nuName.firmenname)}</div>` : ''}
                ${plannerAnforderungenHtml(task.anforderungen)}
                <div class="planner-team-grid">
                    <div class="planner-team-column">
                        <div class="planner-team-header">Zugewiesene Mitarbeiter <span>${totalWorkerCount}</span></div>
                        <div class="planner-team-list">${assignedWorkers.map(w => `
                            <div class="planner-worker-item" style="pointer-events:none">
                                ${plannerWorkerAvatarHtml(w.foto, w.vorname || w.name || w.dienst_nr)}
                                <div class="planner-worker-info">
                                    <div class="planner-worker-name">${escapeHtml(w.vorname || '')} ${escapeHtml(w.name || '')}</div>
                                    <div class="planner-worker-meta">${escapeHtml(w.dienst_nr)}${w.bereich ? ' · ' + bereichDotHtml(w.bereich, false) + ' ' + escapeHtml(w.bereich) : ''}</div>
                                    ${plannerWorkerActualTimeHtml(w)}
                                    ${w.aufgabe ? `<div class="planner-worker-meta" style="color:#4f46e5;margin-top:3px"><span class="material-symbols-outlined">assignment</span> ${escapeHtml(w.aufgabe)}</div>` : ''}
                                </div>
                            </div>
                        `).join('')}${nuAssigned.map(nw => `
                            <div class="planner-worker-item" style="pointer-events:none">
                                ${plannerWorkerAvatarHtml(nw.foto, nw.vorname || nw.name || nw.nu_firmenname, "border:2px solid #2563eb")}
                                <div class="planner-worker-info">
                                    <div class="planner-worker-name">${escapeHtml(nw.vorname || '')} ${escapeHtml(nw.name || '')} <span style="font-size:0.65rem;background:#2563eb;color:white;padding:1px 5px;border-radius:6px;margin-left:4px">${escapeHtml(nw.nu_firmenname || 'NU')}</span></div>
                                    <div class="planner-worker-meta">${nw.personal_nr ? 'Nr. ' + escapeHtml(nw.personal_nr) : 'NU'}${nw.position_name ? ' · ' + escapeHtml(nw.position_name) : ''}</div>
                                </div>
                            </div>
                        `).join('') || (assignedWorkers.length === 0 ? '<div class="planner-empty-list">Keine Mitarbeiter zugewiesen</div>' : '')}</div>
                    </div>
                </div>
            </div>
        `;
        const readFooter = `
            <button class="btn btn-danger" type="button" onclick="deleteTask('${task.einsatz_id}')"><span class="material-symbols-outlined">delete</span> Endgültig löschen</button>
            <button class="btn btn-outline" type="button" onclick="plannerDownloadBriefingPdf('${einsatzId}', this)"><span class="material-symbols-outlined">picture_as_pdf</span> Briefing PDF</button>
        `;
        openModal(`Einsatz — ${task.bestell_id || task.einsatz_id || ''}`, readBody, readFooter, 'modal-xl');
        const modalHeader = document.querySelector('#modalOverlay .modal-header');
        if (modalHeader) modalHeader.classList.add('planner-modal-header');
        return;
    }

    // ── Read-only view for storno-locked tasks (day removed from
    //    Bestelleingang). The shift is preserved but cannot be edited
    //    until the day is re-added to the Bestelleingang. ──
    if (task.bestell_storno_at) {
        const nuName = (data.subcontractors || []).find(nu => nu.nu_id === task.nu_id);
        const nuAssigned = task.assigned_nu_workers || [];
        const totalWorkerCount = assignedWorkers.length + nuAssigned.length;
        const stornoDate = task.bestell_storno_at
            ? new Date(task.bestell_storno_at).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
            : '';
        const stornoBody = `
            <div class="planner-detail">
                <div class="planner-storno-banner">
                    <span class="material-symbols-outlined">block</span>
                    <span>Vom Bestelleingang entfernt</span>
                </div>
                <div style="padding:8px 12px;background:var(--gray-100, #f3f4f6);border-radius:8px;margin:10px 0;font-size:0.82rem;color:var(--gray-500, #6b7280)">
                    Dieser Einsatz wurde am ${stornoDate} aus dem Bestelleingang entfernt und ist gesperrt.
                    Er kann reaktiviert werden, indem der Tag im Bestelleingang erneut hinzugefügt wird.
                    Alle zugewiesenen Mitarbeiter bleiben erhalten.
                </div>
                <div class="planner-detail-headline">${formatDate(task.datum)} · ${task.ist_start ? task.ist_start.substring(0,5) : '–'} - ${task.ist_ende ? task.ist_ende.substring(0,5) : '–'} Uhr</div>
                <div class="planner-detail-tabs">
                    <div class="planner-detail-pill active">${escapeHtml(task.kundenname || task.kunden_id)}</div>
                    <div class="planner-detail-pill">${escapeHtml(task.objektname || task.obj_id || '–')}</div>
                    ${typeof schichtartBadge === 'function' ? schichtartBadge(task.schichtart, task.zuschlag_pct) : ''}
                </div>
                <div class="planner-detail-meta" style="margin:10px 0">${plannerOrderRefHtml(task)} · Pause ${task.pause_minuten || 0} Min${task.soll_menge ? ` · Menge ${task.soll_menge}` : ''}</div>
                ${task.beschreibung ? `<div style="margin-bottom:12px"><strong>Schichtnotiz:</strong> ${escapeHtml(task.beschreibung)}</div>` : ''}
                ${task.ort ? `<div style="margin-bottom:12px"><strong><span class="material-symbols-outlined" style="color:#3b82f6">location_on</span> Ort / Treffpunkt:</strong> ${escapeHtml(task.ort)}</div>` : ''}
                ${task.bemerkung ? `<div style="margin-bottom:12px"><strong>Bemerkung:</strong> ${escapeHtml(task.bemerkung)}</div>` : ''}
                ${nuName ? `<div style="margin-bottom:12px"><strong>Nachunternehmer:</strong> ${escapeHtml(nuName.firmenname)}</div>` : ''}
                ${plannerAnforderungenHtml(task.anforderungen)}
                <div class="planner-team-grid">
                    <div class="planner-team-column">
                        <div class="planner-team-header">Zugewiesene Mitarbeiter <span>${totalWorkerCount}</span></div>
                        <div class="planner-team-list">${assignedWorkers.map(w => `
                            <div class="planner-worker-item" style="pointer-events:none">
                                ${plannerWorkerAvatarHtml(w.foto, w.vorname || w.name || w.dienst_nr)}
                                <div class="planner-worker-info">
                                    <div class="planner-worker-name">${escapeHtml(w.vorname || '')} ${escapeHtml(w.name || '')}</div>
                                    <div class="planner-worker-meta">${escapeHtml(w.dienst_nr)}${w.bereich ? ' · ' + bereichDotHtml(w.bereich, false) + ' ' + escapeHtml(w.bereich) : ''}</div>
                                    ${plannerWorkerActualTimeHtml(w)}
                                    ${w.aufgabe ? `<div class="planner-worker-meta" style="color:#4f46e5;margin-top:3px"><span class="material-symbols-outlined">assignment</span> ${escapeHtml(w.aufgabe)}</div>` : ''}
                                </div>
                            </div>
                        `).join('')}${nuAssigned.map(nw => `
                            <div class="planner-worker-item" style="pointer-events:none">
                                ${plannerWorkerAvatarHtml(nw.foto, nw.vorname || nw.name || nw.nu_firmenname, "border:2px solid #2563eb")}
                                <div class="planner-worker-info">
                                    <div class="planner-worker-name">${escapeHtml(nw.vorname || '')} ${escapeHtml(nw.name || '')} <span style="font-size:0.65rem;background:#2563eb;color:white;padding:1px 5px;border-radius:6px;margin-left:4px">${escapeHtml(nw.nu_firmenname || 'NU')}</span></div>
                                    <div class="planner-worker-meta">${nw.personal_nr ? 'Nr. ' + escapeHtml(nw.personal_nr) : 'NU'}${nw.position_name ? ' · ' + escapeHtml(nw.position_name) : ''}</div>
                                </div>
                            </div>
                        `).join('') || (assignedWorkers.length === 0 ? '<div class="planner-empty-list">Keine Mitarbeiter zugewiesen</div>' : '')}</div>
                    </div>
                </div>
            </div>
        `;
        openModal(`Einsatz — ${task.bestell_id || task.einsatz_id}`, stornoBody,
            `<button class="btn btn-danger" type="button" onclick="deleteTask('${task.einsatz_id}')"><span class="material-symbols-outlined">delete</span> Endgültig löschen</button>`,
            'modal-xl');
        const modalHeader = document.querySelector('#modalOverlay .modal-header');
        if (modalHeader) modalHeader.classList.add('planner-modal-header');
        return;
    }

    // ── Editable view for non-confirmed tasks ──
    // Prefill the bulk "team task" field when all assigned workers already
    // share the same task, so editing a common task on reopen is one step.
    let commonTask = '';
    if (assignedWorkers.length > 0) {
        const _t = assignedWorkers.map(w => (w.aufgabe || '').trim());
        if (_t.every(t => t && t === _t[0])) commonTask = _t[0];
    }
    // Audit notice shown above "Zugewiesen" when a published plan was
    // withdrawn ("Plan aufheben") — prevents confusing a pulled-back shift
    // with a never-published draft. Only rendered while the shift is
    // actually in the withdrawn state (not re-planned, not confirmed).
    const planWithdrawnNotice = (task.plan_aufgehoben_at && task.pruefung !== 'geplant' && task.hms_bestaetigt !== 'ja')
        ? `<div style="display:flex;gap:10px;align-items:flex-start;background:var(--warning-light, #fff7ed);border:1px solid #fed7aa;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:0.85rem;color:var(--md-ext-color-on-warning-container, #9a3412);line-height:1.45">
            <span class="material-symbols-outlined" style="font-size:18px;color:var(--warning, #ea580c);margin-top:1px">event_busy</span>
            <span>${plannerPlanWithdrawnText(task)}</span>
        </div>` : '';
    // Staffed shifts keep their Anforderungen copy when the order changes —
    // tell the Dispo and offer the order's current version (display only).
    const anfChangedNotice = plannerAnfChangedInOrder(task)
        ? `<details class="planner-anf-changed">
            <summary><span class="material-symbols-outlined">sync_problem</span>
                <span>Anforderungen wurden im Bestelleingang geändert — diese Schicht zeigt noch den Stand vom Zeitpunkt der Besetzung. <u>Aktuellen Stand anzeigen</u></span>
            </summary>
            ${plannerAnforderungenHtml(task.plan_anforderungen) || '<div class="planner-anf-changed-empty">Im Bestelleingang sind keine Anforderungen mehr hinterlegt.</div>'}
        </details>` : '';
    window._plannerHasGender = (data.workers || []).some(w => w && Object.prototype.hasOwnProperty.call(w, 'geschlecht'));
    const body = `
        <div class="planner-detail">
            <div class="planner-head-row">
                <div class="planner-detail-headline">${formatDate(task.datum)} · ${formatTime(task.ist_start)} - ${formatTime(task.ist_ende)} Uhr</div>
                ${task.ort ? `<div class="planner-detail-ort planner-head-ort">
                    <span class="material-symbols-outlined" style="color:#3b82f6;font-size:1.05rem">location_on</span>
                    <span class="planner-head-ort-text" title="Ort / Treffpunkt: ${escapeHtml(task.ort)}"><strong>Ort / Treffpunkt</strong>${escapeHtml(task.ort)}</span>
                    <a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(ortMapsQuery(task.ort))}" target="_blank" rel="noopener" title="In Google Maps öffnen"><span class="material-symbols-outlined">map</span> Karte</a>
                </div>` : ''}
            </div>
            <div class="planner-detail-tabs">
                <div class="planner-detail-pill active">${escapeHtml(task.kundenname || task.kunden_id)}</div>
                <div class="planner-detail-pill">${escapeHtml(task.objektname || task.obj_id || '–')}</div>
            </div>
            <div class="planner-detail-note">
                <span class="material-symbols-outlined">chat</span>
                <div style="flex:1">
                    <strong>Schichtnotiz:</strong> <span class="planner-beschreibung-text">${escapeHtml(task.beschreibung || '—')}</span>
                    <div class="planner-detail-meta">${plannerOrderRefHtml(task)} · Pause ${task.pause_minuten || 0} Min · Status ${escapeHtml(task.hms_bestaetigt)}${task.soll_menge ? ` · Menge ${task.soll_menge}` : ''}</div>
                </div>
            </div>
            ${anfChangedNotice}
            ${planWithdrawnNotice}
            <div id="plannerBoerseBox"></div>
            <div class="planner-staff-dock">
            ${plannerAnforderungenHtml(task.anforderungen)}
            <div class="planner-team-grid planner-team-grid-split">
                <div class="planner-team-column">
                    <div class="planner-team-header"><span class="planner-team-title">Zugewiesen <span class="planner-gender-cov" id="plannerGenderCoverage"></span></span><span class="planner-team-count"><span id="plannerAssignedCount">${assignedWorkers.length}</span>${sollMenge > 0 ? `<span class="planner-soll-menge" id="plannerSollMenge"> / ${sollMenge}</span>` : ''}</span></div>
                    <div class="planner-bulk-task">
                        <span class="material-symbols-outlined planner-bulk-task-icon">assignment</span>
                        <textarea id="plannerBulkTask" class="planner-bulk-task-input" rows="1" maxlength="500" placeholder="Aufgabe für das ganze Team…" oninput="plannerAutoGrow(this)">${escapeHtml(commonTask)}</textarea>
                        <button type="button" class="planner-bulk-task-btn" onclick="plannerApplyTaskToAll()"><span class="material-symbols-outlined">arrow_downward</span> Für alle</button>
                    </div>
                    <div class="planner-team-list planner-team-list-assigned" id="plannerAssignedList">${assignedWorkers.map(worker => plannerWorkerOption(worker, true)).join('') || '<div class="planner-empty-list">Noch kein Team ausgewählt</div>'}</div>
                </div>
                <div class="planner-team-column">
                    <div class="planner-team-header">Mitarbeiter <span id="plannerWorkerCount">${availableWorkers.length}</span></div>
                    <div class="planner-worker-search-wrap">
                        <div class="search-input planner-worker-search">
                            <span class="material-symbols-outlined">search</span>
                            <input type="text" id="plannerWorkerSearch" placeholder="Mitarbeiter suchen..." oninput="plannerFilterWorkers()">
                        </div>
                    </div>
                    <div class="planner-team-list" id="plannerWorkerList">${availableWorkers.map(worker => plannerWorkerOption(worker, false)).join('') || '<div class="planner-empty-list">Keine Mitarbeiter verfügbar</div>'}</div>
                </div>
            </div>
            </div>
            <div class="planner-detail-topfields">
                <input type="hidden" id="planner_nu_select" value="${task.nu_id || ''}">
                <div class="form-group" style="flex:1" id="plannerNuAddGroup">
                    <label>Nachunternehmer</label>
                    <div class="planner-add-nu-card" onclick="plannerOpenNuAddOverlay('${einsatzId}')" id="plannerAddNuCard">
                        <div class="planner-add-nu-icon"><span class="material-symbols-outlined">add</span></div>
                        <div class="planner-add-nu-text">
                            <div class="planner-add-nu-title">Nachunternehmer hinzufügen</div>
                            <div class="planner-add-nu-sub">Schichtanfrage an einen Partner senden</div>
                        </div>
                        <span class="material-symbols-outlined" style="margin-left:auto;color:var(--nu-primary)">arrow_forward</span>
                    </div>
                </div>
            </div>
        </div>
    `;

    const footer = `
        <button class="btn btn-outline" type="button" onclick="plannerDownloadBriefingPdf('${einsatzId}', this)"><span class="material-symbols-outlined">picture_as_pdf</span> Briefing PDF</button>
        <button class="btn btn-outline" onclick="closeModal()">Abbrechen</button>
        <button class="btn btn-primary" id="plannerSaveBtn" onclick="savePlannerAssignment('${einsatzId}')">Speichern</button>
    `;
    openModal(`Schicht bearbeiten — ${task.bestell_id || task.einsatz_id || ''}`, body, footer, 'modal-xl');
    const modalHeader = document.querySelector('#modalOverlay .modal-header');
    if (modalHeader) {
        modalHeader.classList.add('planner-modal-header');
        const existingAction = modalHeader.querySelector('.planner-header-action');
        if (existingAction) existingAction.remove();
        // The Geplant button itself reflects the plan state: green "Geplant"
        // when currently planned, amber "Erneut planen" after a withdrawal,
        // plain amber "Geplant" for a never-published draft.
        window._plannerGeplantState = task.pruefung === 'geplant' ? 'planned'
            : (task.plan_aufgehoben_at ? 'withdrawn' : 'draft');
        window._plannerShiftEnded = plannerShiftEnded(task);
        const gb = plannerGeplantBtnContent(window._plannerGeplantState);
        const headerAction = document.createElement('div');
        headerAction.className = 'planner-header-action';
        headerAction.innerHTML = `
            <div class="planner-header-action-group">
                ${/* Ended shift: confirm directly — also never-planned ones (staffed
                    afterwards; a plan email for the past would be pointless). */
                  plannerShiftEnded(task) && !task.bestell_storno_at
                    ? `<button class="btn btn-success" type="button" id="plannerConfirmBtn" onclick="plannerConfirmSingleShift('${einsatzId}')"><span class="material-symbols-outlined">check</span> Bestätigen</button>`
                    : ''}
                <button class="${gb.cls}" type="button" id="plannerGeplantBtn" onclick="plannerMarkSingleShiftPlanned('${einsatzId}', this)">${gb.html}</button>
            </div>
        `;
        // Right-aligned, directly before the fullscreen/close buttons.
        modalHeader.insertBefore(headerAction,
            modalHeader.querySelector('.modal-max-btn') || modalHeader.querySelector('#modalClose'));
    }
    const modalFooter = document.querySelector('#modalOverlay .modal-footer');
    if (modalFooter) modalFooter.classList.add('planner-modal-footer');
    plannerBindWorkerSearchViewport();
    plannerRefreshAssignmentLists();
    plannerUpdateGeplantButton();
    plannerInitTaskFieldHeights();
    plannerLoadBoerse(einsatzId);

    // NU selector change → load NU groups for this einsatz. The positions
    // editor lives inside the NU overlay popup — nothing inline to toggle.
    const nuSelect = document.getElementById('planner_nu_select');
    if (nuSelect) {
        nuSelect.addEventListener('change', function() {
            if (this.value) plannerLoadNuPositions(einsatzId, this.value);
            plannerUpdateNuAddCard();
        });
        // Load existing positions if NU already selected
        if (task.nu_id) plannerLoadNuPositions(einsatzId, task.nu_id);
        else plannerUpdateNuAddCard();
    }
}

// Download the printable team-lead briefing (workers, gender, qualifications,
// meeting point, notes) for a shift as a PDF.
async function plannerDownloadBriefingPdf(einsatzId, btn) {
    const original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-symbols-outlined">hourglass_top</span> PDF…'; }
    try {
        const token = localStorage.getItem('hms_token');
        // Reflect the CURRENT modal state (also unsaved additions/removals):
        // pass the assigned worker list + their tasks so the briefing matches
        // exactly what the Dispo sees on screen. Absent in the read-only view
        // → backend falls back to the saved assignment.
        const params = new URLSearchParams();
        const assignedItems = document.querySelectorAll('#plannerAssignedList .planner-worker-item');
        if (assignedItems.length) {
            const ids = [...assignedItems].map(item => item.getAttribute('data-worker-id')).filter(Boolean);
            params.set('workers', ids.join(','));
            params.set('aufgaben', JSON.stringify(typeof collectWorkerTasks === 'function' ? collectWorkerTasks() : {}));
        }
        const qs = params.toString() ? ('?' + params.toString()) : '';
        const res = await fetch(window.location.origin + '/api/tasks/' + encodeURIComponent(einsatzId) + '/briefing-pdf' + qs,
            { headers: { 'Authorization': 'Bearer ' + token } });
        if (!res.ok) throw new Error('Server-Fehler (' + res.status + ')');
        const blob = await res.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'Einsatz-Briefing_' + einsatzId + '.pdf';
        a.click();
        URL.revokeObjectURL(a.href);
    } catch (e) {
        showToast('PDF konnte nicht erstellt werden: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
    }
}

// Button look per plan state — the Geplant button doubles as the status
// indicator so no separate label is needed next to it.
function plannerGeplantBtnContent(state) {
    if (state === 'planned')   return { cls: 'btn btn-success', html: '<span class="material-symbols-outlined">check_circle</span> Geplant' };
    if (state === 'withdrawn') return { cls: 'btn btn-warning', html: '<span class="material-symbols-outlined">history</span> Erneut planen' };
    return { cls: 'btn btn-warning', html: '<span class="material-symbols-outlined">event_available</span> Geplant' };
}

function plannerUpdateGeplantButton() {
    const btn = document.getElementById('plannerGeplantBtn');
    if (!btn) return;
    const assignedCount = document.querySelectorAll('#plannerAssignedList .planner-worker-item').length;
    const previousIds = window._plannerOriginalWorkerIds || [];
    const wasPlanned = window._plannerOriginalPruefung === 'geplant';
    const saveBtn = document.getElementById('plannerSaveBtn');
    const aufhebenState = wasPlanned && assignedCount === 0 && previousIds.length > 0;
    // Shift already over: publishing/re-publishing would only spam workers
    // about a past shift — hide the plan button. Sole exception: "Plan
    // aufheben" (all workers removed) stays, it's the only way to mark an
    // ended planned shift as not-taken-place (confirm needs a worker).
    if (window._plannerShiftEnded && !aufhebenState) {
        btn.style.display = 'none';
        if (saveBtn) { saveBtn.style.opacity = ''; saveBtn.dataset.blocked = ''; }
        return;
    }
    btn.style.display = '';
    if (aufhebenState) {
        btn.className = 'btn btn-danger';
        btn.innerHTML = '<span class="material-symbols-outlined">event_busy</span> Plan aufheben';
        btn.title = '';
        if (saveBtn) { saveBtn.style.opacity = '0.5'; saveBtn.style.pointerEvents = 'auto'; saveBtn.dataset.blocked = 'true'; }
        showToast('Alle Mitarbeiter entfernt — bitte zuerst "Plan aufheben" klicken, bevor Sie speichern können.', 'warning');
    } else {
        // Team changed on a LIVE plan (workers removed and/or swapped while
        // the shift is 'geplant'): clicking still publishes, and the backend
        // automatically sends a Stornierung to every removed worker plus the
        // plan to new ones. The button must say that — otherwise it looks
        // like the removed worker's plan was silently kept.
        const currentIds = [...document.querySelectorAll('#plannerAssignedList .planner-worker-item')]
            .map(i => i.getAttribute('data-worker-id')).filter(Boolean);
        const sameTeam = currentIds.length === previousIds.length
            && currentIds.every(id => previousIds.includes(id));
        let gb, title = '';
        if (wasPlanned && !sameTeam) {
            gb = { cls: 'btn btn-warning', html: '<span class="material-symbols-outlined">sync</span> Plan aktualisieren' };
            title = 'Entfernte Mitarbeiter erhalten automatisch eine Stornierung';
        } else {
            gb = plannerGeplantBtnContent(window._plannerGeplantState || 'draft');
        }
        btn.className = gb.cls;
        btn.innerHTML = gb.html;
        btn.title = title;
        if (saveBtn) { saveBtn.style.opacity = ''; saveBtn.dataset.blocked = ''; }
    }
}

function plannerCheckTimeChanged() {
    const startInput = document.getElementById('planner_ist_start');
    const endInput = document.getElementById('planner_ist_ende');
    const beschreibungInput = document.getElementById('planner_beschreibung');
    const hint = document.getElementById('plannerOriginalTime');
    const hintText = document.getElementById('plannerOriginalTimeText');
    if (!startInput || !endInput || !hint || !hintText) return;

    const origStart = startInput.getAttribute('data-original');
    const origEnd = endInput.getAttribute('data-original');
    const origBeschreibung = beschreibungInput ? beschreibungInput.getAttribute('data-original') : '';

    const timeChanged = (origStart && startInput.value !== origStart) || (origEnd && endInput.value !== origEnd);
    const beschreibungChanged = beschreibungInput && beschreibungInput.value !== origBeschreibung;
    const changed = timeChanged || beschreibungChanged;

    const confirmBtn = document.getElementById('plannerConfirmBtn');
    const confirmedLabel = document.getElementById('plannerConfirmedLabel');

    if (timeChanged) {
        hintText.textContent = `${origStart || '?'} - ${origEnd || '?'} Uhr`;
        hint.style.display = '';
    } else {
        hint.style.display = 'none';
    }

    if (changed) {
        if (confirmBtn) confirmBtn.style.display = '';
        if (confirmedLabel) confirmedLabel.style.display = 'none';
    } else {
        if (confirmedLabel) { confirmedLabel.style.display = ''; if (confirmBtn) confirmBtn.style.display = 'none'; }
    }
}

function plannerWorkerActualTimeHtml(worker) {
    const start = wpUtcDate(worker.app_clock_in);
    const end = wpUtcDate(worker.app_clock_out);
    const extended = wpUtcDate(worker.app_extended_until);
    const format = value => value ? value.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' }) : '';
    const parts = [];
    if (start) parts.push(`Beginn: ${format(start)}`);
    if (end) parts.push(`Ende: ${format(end)}`);
    if (extended && parseInt(worker.app_extensions || 0) > 0) parts.push(`Verlängert bis: ${format(extended)}`);
    return `<div class="planner-worker-meta planner-worker-actual" data-worker-id="${escapeHtml(worker.dienst_nr || '')}" style="${parts.length ? 'color:#15803d;margin-top:3px' : 'display:none'}"><span class="material-symbols-outlined" style="font-size:14px;vertical-align:-2px">schedule</span> ${parts.join(' · ')}</div>`;
}

async function plannerRefreshWorkerActualTimes() {
    const einsatzId = window._plannerEinsatzId;
    if (!einsatzId || !document.querySelector('#modalOverlay .planner-worker-actual')) return;
    const data = await apiGet(API.tasks + '/' + encodeURIComponent(einsatzId));
    if (!data?.task?.assigned_workers) return;
    const byWorker = Object.fromEntries(data.task.assigned_workers.map(worker => [String(worker.dienst_nr), worker]));
    document.querySelectorAll('#modalOverlay .planner-worker-actual').forEach(element => {
        const worker = byWorker[String(element.dataset.workerId)];
        if (worker) element.outerHTML = plannerWorkerActualTimeHtml(worker);
    });
}

// Info-level warning types (blue): never a conflict, just a notice.
const PLANNER_SOFT_WARNINGS = ['multi_shift', 'aueg_equal_pay', 'aueg_soon'];

function plannerWorkerOption(worker, assigned) {
    const warnings = worker.warnings || [];
    const warningsPayload = encodeURIComponent(JSON.stringify(warnings)).replace(/'/g, '%27');
    const searchableName = `${plannerWorkerName(worker)} ${worker.dienst_nr || ''} ${worker.bereich || ''}`.toLowerCase();
    const vonVal = worker.ist_von || '';
    const bisVal = worker.ist_bis || '';
    const stdVal = worker.ist_std != null && worker.ist_std !== '' ? parseFloat(worker.ist_std).toFixed(2) : '';
    // Soft (info) when there are only multi_shift hints; harder when any conflict/absence/hours fires.
    const hasHardWarning = warnings.some(w => !PLANNER_SOFT_WARNINGS.includes(w.type));
    const warnBtnClass = hasHardWarning ? 'planner-warning-btn' : 'planner-warning-btn info';
    const warnIcon = hasHardWarning ? 'error' : 'info';
    const isFav = !!worker.is_favorite;
    return `
        <div class="planner-worker-item ${assigned ? 'assigned' : ''}${isFav ? ' favorite' : ''}" data-worker-id="${escapeHtml(worker.dienst_nr)}" data-geschlecht="${escapeHtml(String(worker.geschlecht || '').trim().charAt(0).toLowerCase())}" data-worker-search="${escapeHtml(searchableName)}" data-assigned="${assigned ? '1' : '0'}" data-favorite="${isFav ? '1' : '0'}" style="${isFav ? 'box-shadow:inset 3px 0 0 #f59e0b' : ''}" onclick="${assigned ? '' : 'plannerToggleWorkerAssignment(this)'}">
            ${assigned ? `<button type="button" class="planner-worker-remove" onclick="event.stopPropagation(); plannerRemoveWorker(this)" title="Entfernen"><span class="material-symbols-outlined">close</span></button>` : ''}
            <div class="planner-worker-main">
                ${plannerWorkerAvatarHtml(worker.foto, worker.vorname || worker.name || worker.dienst_nr)}
                <div class="planner-worker-info">
                    <div class="planner-worker-name">${escapeHtml(plannerWorkerName(worker))}${isFav ? ' <span class="material-symbols-outlined md-filled" style="color:#f59e0b;font-size:0.9em;vertical-align:-1px" title="Stamm-Team / Favorit dieses Kunden">star</span>' : ''}</div>
                    <div class="planner-worker-subline">${escapeHtml(worker.dienst_nr)}${worker.bereich ? ` · ${bereichDotHtml(worker.bereich, false)} ${escapeHtml(worker.bereich)}` : ''}</div>
                    ${plannerWorkerReqChipsHtml(worker)}
                    ${assigned ? plannerWorkerActualTimeHtml(worker) : ''}
                </div>
            </div>
            <div class="planner-worker-side">
                ${warnings.length > 0 ? `<button type="button" class="${warnBtnClass}" onclick="event.stopPropagation(); plannerToggleWarningDetails(this, '${warningsPayload}')"><span class="material-symbols-outlined">${warnIcon}</span></button>` : ''}
            </div>
            <div class="planner-warning-panel"></div>
            ${assigned ? plannerWorkerTaskFieldHtml(worker) : ''}
        </div>
    `;
}

// ── Anforderungen ↔ worker qualifications ──────────────────────────────
// Bestelleingang stores the required qualifications as comma-separated text
// (catalog names from the picker, older orders free text); the worker carries
// catalog options, delivered as names (qual_names). Display only — never blocks.
//   §34a levels are recognised in any spelling (Unterrichtung / U / SMA/U <
//   Sachkunde / SKP < GSSK < Fachkraft) and a higher level always counts.
//   Other catalog names: exact match. Unknown free text: "bitte prüfen".
const _plannerQualKey = s => String(s || '').toLowerCase().replace(/\s+/g, ' ').trim();
function plannerQ34Level(text) {
    const t = ' ' + _plannerQualKey(text).replace(/[^a-z0-9äöüß§/]+/g, ' ') + ' ';
    if (/ohne (§ ?)?34/.test(t)) return null;
    const lv = [];
    if (/fachkraft|\bfk\b/.test(t)) lv.push(4);
    if (/gssk|schutz und sicherheitskraft/.test(t)) lv.push(3);
    if (/\bskp\b|sach ?kunde|sackunde|sma\/skp/.test(t)) lv.push(2);
    if (/unterrichtung|\bu\b|sma\/u|34 ?a? ?u\b|§ ?34 ?u\b/.test(t)) lv.push(1);
    if (!lv.length && /34 ?a|§ ?34/.test(t)) lv.push(1);
    return lv.length ? Math.min(...lv) : null;
}
const _PLANNER_Q34_LABEL = { 1: 'Unterrichtung §34a', 2: 'Sachkunde §34a', 3: 'GSSK', 4: 'Fachkraft' };
function plannerRequiredQuals(anf) {
    if (!anf || typeof anf !== 'object') return [];
    const src = [anf.qualifikation, ...(Array.isArray(anf.positionen) ? anf.positionen.map(p => p && p.qualifikation) : [])];
    const out = new Map();
    let q34 = null;
    src.forEach(v => String(v || '').split(',').forEach(raw => {
        const tok = raw.trim();
        const k = _plannerQualKey(tok);
        if (!k) return;
        const lvl = plannerQ34Level(tok);
        // §34a: one requirement = the lowest level asked for.
        if (lvl != null) { q34 = q34 == null ? lvl : Math.min(q34, lvl); return; }
        if (!out.has(k)) out.set(k, { name: tok });
    }));
    const list = [...out.values()];
    if (q34 != null) list.unshift({ name: _PLANNER_Q34_LABEL[q34] + (q34 < 4 ? '+' : ''), q34 });
    return list;
}
function plannerQualMatch(worker) {
    const req = window._plannerReqQuals || [];
    const own = (worker.qual_names || []);
    const ownKeys = new Set(own.map(_plannerQualKey));
    const ownLvl = Math.max(0, ...own.map(n => plannerQ34Level(n) || 0));
    // Name of the worker's own (highest) §34a entry, exactly as in his profile.
    const ownQ34 = own.find(n => (plannerQ34Level(n) || 0) === ownLvl) || '';
    const cat = window._plannerQualCatalog || new Set();
    const have = [], missing = [], unknown = [];
    req.forEach(r => {
        if (r.q34) ownLvl >= r.q34 ? have.push(ownQ34) : missing.push(r.name);
        else if (ownKeys.has(_plannerQualKey(r.name))) have.push(r.name);
        else (cat.has(_plannerQualKey(r.name)) ? missing : unknown).push(r.name);
    });
    return { have, missing, unknown };
}
function plannerWorkerReqChipsHtml(worker) {
    const chip = (txt, kind, title) => {
        const st = kind === 'ok' ? 'color:#15803d;background:rgba(22,163,74,.12)'
            : kind === 'bad' ? 'color:#dc2626;background:rgba(220,38,38,.10)'
            : 'color:#6b7280;background:rgba(107,114,128,.12)';
        return `<span title="${escapeHtml(title)}" style="display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:999px;font-size:.7rem;font-weight:600;line-height:1.5;white-space:nowrap;${st}">${txt}</span>`;
    };
    const chips = [];
    if (worker.stadion_gesperrt == 1 && window._plannerIsStadion) chips.push(chip('⛔ Gesperrt', 'bad', 'Stadionverbot — für Stadion-/DFB-Einsätze gesperrt'));
    const g = String(worker.geschlecht || '').trim().charAt(0).toLowerCase();
    const req = window._plannerReqGender || { m: 0, w: 0 };
    // Only what the worker really has (user decision): matching gender and
    // qualifications hinterlegt in his profile — no ✗ / "?" chips.
    const fitsGender = (g === 'm' && req.m > 0) || ((g === 'w' || g === 'f') && req.w > 0);
    if (fitsGender) chips.push(chip(g === 'm' ? '♂' : '♀', 'ok', 'Geschlecht wird laut Anforderungen benötigt'));
    plannerQualMatch(worker).have.forEach(q => chips.push(chip('✓ ' + escapeHtml(q), 'ok', 'Im Mitarbeiterprofil hinterlegt')));
    return chips.length ? `<div class="planner-worker-reqs" style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px">${chips.join('')}</div>` : '';
}

// Per-worker task field — shown only for ASSIGNED workers. Its value is sent to
// the worker's app on save (einsatz_mitarbeiter.aufgabe), separate from the
// shift-level Dispo note (bemerkung), which stays internal to Dispo.
function plannerWorkerTaskFieldHtml(worker) {
    const wid = escapeHtml(worker.dienst_nr || '');
    const val = escapeHtml(worker.aufgabe || '');
    return `<div class="planner-worker-task-wrap" onclick="event.stopPropagation()">
            <span class="material-symbols-outlined planner-worker-task-icon">assignment</span>
            <textarea class="planner-worker-task" data-worker-id="${wid}" rows="1" maxlength="500" placeholder="Aufgabe für diese/n Mitarbeiter/in (an App)…" oninput="plannerAutoGrow(this)">${val}</textarea>
        </div>`;
}

function plannerRemoveWorker(btn) {
    const item = btn.closest('.planner-worker-item');
    if (!item) return;
    item.setAttribute('data-assigned', '0');
    item.classList.remove('assigned');
    const removeBtn = item.querySelector('.planner-worker-remove');
    if (removeBtn) removeBtn.remove();
    const fieldsEl = item.querySelector('.planner-worker-fields');
    if (fieldsEl) fieldsEl.remove();
    const taskWrapEl = item.querySelector('.planner-worker-task-wrap');
    if (taskWrapEl) taskWrapEl.remove();
    item.setAttribute('onclick', 'plannerToggleWorkerAssignment(this)');
    const workerList = document.getElementById('plannerWorkerList');
    if (workerList) workerList.appendChild(item);
    plannerRefreshAssignmentLists();
    plannerFilterWorkers();
}

// Execute the actual "Plan aufheben" — the same confirm + backend calls the
// red button uses, but kept factored out so the worker-click path can run it
// before a new worker is added to an emptied live plan. On success the modal
// baseline is reset: the shift behaves as a withdrawn draft from then on.
async function plannerWithdrawPlan(einsatzId) {
    const previousIds = window._plannerOriginalWorkerIds || [];
    const confirmed = await systemConfirm({
        title: 'Plan aufheben?',
        message: `Alle ${previousIds.length} Mitarbeiter wurden entfernt. Der Plan wird aufgehoben und eine Stornierung an die betroffenen Mitarbeiter gesendet.`,
        confirmText: 'Plan aufheben',
        cancelText: 'Abbrechen',
        icon: 'event_busy',
        type: 'danger'
    });
    if (!confirmed) return false;

    const nuId = document.getElementById('planner_nu_select')?.value || null;
    const saveResult = await apiPost(API.disposition + '/save-assignment', {
        einsatz_id: einsatzId,
        worker_ids: [],
        worker_tasks: {},
        nu_id: nuId
    });
    if (!saveResult || !saveResult.success) {
        showToast(saveResult?.error || 'Speichern fehlgeschlagen', 'error');
        return false;
    }
    // Parity with the button path — 0 workers means it is skipped server-side.
    await apiPost(API.disposition + '/bulk-mark-planned', {
        einsatz_ids: [einsatzId],
        previous_worker_ids: previousIds
    });

    // Plan is withdrawn server-side — reset the modal baseline: button turns
    // into "Erneut planen" and Speichern is no longer blocked.
    window._plannerOriginalPruefung = null;
    window._plannerOriginalWorkerIds = [];
    window._plannerGeplantState = 'withdrawn';
    const saveBtn = document.getElementById('plannerSaveBtn');
    if (saveBtn) { saveBtn.style.opacity = ''; saveBtn.style.pointerEvents = ''; saveBtn.dataset.blocked = ''; }
    plannerUpdateGeplantButton();
    showToast('Plan wurde aufgehoben');
    return true;
}

async function plannerToggleWorkerAssignment(item) {
    if (!item) return;
    const assignedList = document.getElementById('plannerAssignedList');
    const workerList = document.getElementById('plannerWorkerList');
    if (!assignedList || !workerList) return;

    const isAssigned = item.getAttribute('data-assigned') === '1';

    // If already assigned, do nothing (use X button to remove)
    if (isAssigned) return;

    // Clicking a worker while the LIVE plan sits fully empty = the user is
    // starting a re-plan. The old plan must be withdrawn FIRST — run the
    // "Plan aufheben" confirm + execution here so the Stornierung visibly
    // happens before the new worker lands in the list.
    const _prevIds = window._plannerOriginalWorkerIds || [];
    const _wasPlanned = window._plannerOriginalPruefung === 'geplant';
    const _assignedNow = assignedList.querySelectorAll('.planner-worker-item').length;
    const _nuNow = assignedList.querySelectorAll('.planner-nu-worker-card').length;
    if (_wasPlanned && _assignedNow === 0 && _nuNow === 0 && _prevIds.length > 0) {
        const ok = await plannerWithdrawPlan(window._plannerEinsatzId);
        if (!ok) return;
    }

    // Prevent assigning more workers than Menge allows
    const maxWorkers = window._plannerSollMenge || 0;
    if (maxWorkers > 0) {
        const currentCount = assignedList.querySelectorAll('.planner-worker-item').length;
        if (currentCount >= maxWorkers) {
            showToast(`Maximale Menge erreicht (${maxWorkers}). Keine weiteren Mitarbeiter möglich.`, 'error');
            return;
        }
    }

    const currentParent = item.parentElement;
    if (currentParent && currentParent.classList.contains('planner-team-list')) {
        const emptyState = currentParent.querySelector('.planner-empty-list');
        if (emptyState) emptyState.remove();
    }

    item.setAttribute('data-assigned', '1');
    item.classList.add('assigned');
    item.setAttribute('onclick', '');

    // Add remove button and fields
    const wid = item.getAttribute('data-worker-id');
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'planner-worker-remove';
    removeBtn.title = 'Entfernen';
    removeBtn.innerHTML = '<span class="material-symbols-outlined">close</span>';
    removeBtn.onclick = function(e) { e.stopPropagation(); plannerRemoveWorker(this); };
    item.insertBefore(removeBtn, item.firstChild);

    // Per-worker task input (sent to the worker's app on save). Newly assigned
    // workers start empty; the field is removed again on unassign.
    if (!item.querySelector('.planner-worker-task-wrap')) {
        const taskWrap = document.createElement('div');
        taskWrap.className = 'planner-worker-task-wrap';
        taskWrap.onclick = function(e) { e.stopPropagation(); };
        taskWrap.innerHTML = '<span class="material-symbols-outlined planner-worker-task-icon">assignment</span>';
        const taskInput = document.createElement('textarea');
        taskInput.rows = 1;
        taskInput.className = 'planner-worker-task';
        taskInput.dataset.workerId = wid;
        taskInput.maxLength = 500;
        taskInput.placeholder = 'Aufgabe für diese/n Mitarbeiter/in (an App)…';
        taskInput.oninput = function() { plannerAutoGrow(this); };
        // Inherit the current team task so newly added workers match the rest.
        const bulkVal = (document.getElementById('plannerBulkTask')?.value || '').trim();
        if (bulkVal) taskInput.value = bulkVal;
        taskWrap.appendChild(taskInput);
        item.appendChild(taskWrap);
    }

    assignedList.appendChild(item);
    plannerRefreshAssignmentLists();
    plannerFilterWorkers();
}

function plannerRefreshAssignmentLists() {
    const assignedList = document.getElementById('plannerAssignedList');
    const workerList = document.getElementById('plannerWorkerList');
    if (!assignedList || !workerList) return;

    const assignedItems = [...assignedList.querySelectorAll('.planner-worker-item')];
    const workerItems = [...workerList.querySelectorAll('.planner-worker-item')];

    const assignedCount = document.getElementById('plannerAssignedCount');
    if (assignedCount) assignedCount.textContent = assignedItems.length;

    if (assignedItems.length === 0 && !assignedList.querySelector('.planner-empty-list')) {
        assignedList.innerHTML = '<div class="planner-empty-list">Noch kein Team ausgewählt</div>';
    } else if (assignedItems.length > 0) {
        const empty = assignedList.querySelector('.planner-empty-list');
        if (empty) empty.remove();
    }

    if (workerItems.length === 0 && !workerList.querySelector('.planner-empty-list')) {
        workerList.innerHTML = '<div class="planner-empty-list">Keine Mitarbeiter verfügbar</div>';
    } else if (workerItems.length > 0) {
        const empty = workerList.querySelector('.planner-empty-list');
        if (empty) empty.remove();
    }

    plannerUpdateGeplantButton();
    plannerUpdateGenderCoverage();
}

// Required ♂/♀ split of the shift — same priority as the board chip:
// explicit geschlecht_mix > per-position sums. Legacy single value → none.
function plannerRequiredGenderMix(anf) {
    if (!anf || typeof anf !== 'object') return { m: 0, w: 0 };
    const mix = anf.geschlecht_mix;
    if (mix && (parseInt(mix.maennlich) > 0 || parseInt(mix.weiblich) > 0)) {
        return { m: parseInt(mix.maennlich) || 0, w: parseInt(mix.weiblich) || 0 };
    }
    const pos = Array.isArray(anf.positionen) ? anf.positionen : [];
    return {
        m: pos.filter(p => p.geschlecht === 'maennlich').reduce((s, p) => s + (parseInt(p.anzahl) || 1), 0),
        w: pos.filter(p => p.geschlecht === 'weiblich').reduce((s, p) => s + (parseInt(p.anzahl) || 1), 0),
    };
}

// "♂ 1/1 · ♀ 0/1" next to "Zugewiesen": how far the assigned own workers
// cover the required gender mix. Display only — never blocks assignment.
// Hidden when no mix is required or the API sends no worker gender.
function plannerUpdateGenderCoverage() {
    const el = document.getElementById('plannerGenderCoverage');
    if (!el) return;
    const req = plannerRequiredGenderMix(window._plannerTaskAnf);
    if (!window._plannerHasGender || (req.m === 0 && req.w === 0)) { el.innerHTML = ''; return; }
    const items = [...document.querySelectorAll('#plannerAssignedList .planner-worker-item:not(.planner-nu-worker-card)')];
    const hasM = items.filter(i => i.dataset.geschlecht === 'm').length;
    const hasW = items.filter(i => ['w', 'f'].includes(i.dataset.geschlecht)).length;
    const nu = document.querySelectorAll('#plannerAssignedList .planner-nu-worker-card').length;
    const part = (sym, cls, have, need) => need > 0
        ? `<span class="planner-gender-cov-part ${cls}${have >= need ? ' ok' : ''}">${sym} ${have}/${need}</span>` : '';
    el.innerHTML = part('♂', 'm', hasM, req.m) + part('♀', 'w', hasW, req.w)
        + (nu > 0 ? `<span class="planner-gender-cov-part nu">+${nu} NU</span>` : '');
    el.title = 'Geschlecht-Abdeckung laut Anforderungen (eigene Mitarbeiter)' + (nu > 0 ? ' — NU-Mitarbeiter ohne Geschlechtsangabe' : '');
}

// Stable JSON for comparing two Anforderungen objects (key order agnostic).
function plannerAnfKey(anf) {
    const norm = v => Array.isArray(v) ? v.map(norm)
        : (v && typeof v === 'object')
            ? Object.keys(v).sort().reduce((o, k) => { o[k] = norm(v[k]); return o; }, {})
            : v;
    return JSON.stringify(norm(anf || null));
}

// True when the order day carries different Anforderungen than the shift's
// own copy (staffed shifts are not re-synced on order edits). Only when the
// API sends plan_anforderungen and the order day was actually found.
function plannerAnfChangedInOrder(task) {
    if (!task || !Object.prototype.hasOwnProperty.call(task, 'plan_anforderungen')) return false;
    if (task.plan_anforderungen == null && task.soll_menge == null) return false;
    return plannerAnfKey(task.anforderungen) !== plannerAnfKey(task.plan_anforderungen);
}

function plannerToggleWarningDetails(button, warningsEncoded) {
    const panel = button.closest('.planner-worker-item').querySelector('.planner-warning-panel');
    if (!panel) return;
    if (panel.innerHTML) {
        panel.innerHTML = '';
        panel.classList.remove('open');
        return;
    }
    const warnings = JSON.parse(decodeURIComponent(warningsEncoded || '[]'));
    panel.innerHTML = warnings.map(warning => {
        if (warning.type === 'assignment' || warning.type === 'multi_shift') {
            const datumLine = warning.details.datum ? `<div>${formatDate(warning.details.datum)} · ${formatTime(warning.details.start)} - ${formatTime(warning.details.end)}</div>` : `<div>${formatTime(warning.details.start)} - ${formatTime(warning.details.end)}</div>`;
            return `<div class="planner-warning-entry ${warning.type === 'multi_shift' ? 'info' : ''}"><strong>${escapeHtml(warning.label)}</strong><div>${escapeHtml(warning.details.kundenname || '')} · ${escapeHtml(warning.details.objektname || '')}</div>${datumLine}</div>`;
        }
        const timeLine = warning.details.startzeit || warning.details.endzeit
            ? `${formatTime(warning.details.startzeit)} - ${formatTime(warning.details.endzeit)}`
            : (warning.type === 'absence' || warning.type === 'hours' ? 'Ganztägig' : '');
        return `<div class="planner-warning-entry ${PLANNER_SOFT_WARNINGS.includes(warning.type) ? 'info' : ''}"><strong>${escapeHtml(warning.label)}</strong><div>${formatDate(warning.details.startdatum)}${warning.details.enddatum && warning.details.enddatum !== warning.details.startdatum ? ` - ${formatDate(warning.details.enddatum)}` : ''}</div>${timeLine ? `<div>${timeLine}</div>` : ''}${warning.details.beschreibung ? `<div>${escapeHtml(warning.details.beschreibung)}</div>` : ''}</div>`;
    }).join('');
    panel.classList.add('open');
}

function plannerBindWorkerSearchViewport() {
    if (typeof window._plannerViewportCleanup === 'function') window._plannerViewportCleanup();
    const overlay = document.getElementById('modalOverlay');
    const input = document.getElementById('plannerWorkerSearch');
    const viewport = window.visualViewport;
    if (!overlay || !input || !viewport) return;
    const baselineHeight = Math.max(window.innerHeight, viewport.height);
    let frame = 0;
    const sync = () => {
        if (frame) cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => {
            frame = 0;
            const keyboardOpen = baselineHeight - viewport.height > 100;
            overlay.classList.toggle('planner-keyboard-open', keyboardOpen);
            if (keyboardOpen) {
                overlay.style.top = `${viewport.offsetTop}px`;
                overlay.style.bottom = 'auto';
                overlay.style.height = `${viewport.height}px`;
                overlay.style.paddingTop = '0px';
                overlay.style.paddingBottom = '0px';
                overlay.style.setProperty('--planner-visible-height', `${viewport.height}px`);
            } else {
                overlay.style.removeProperty('top');
                overlay.style.removeProperty('bottom');
                overlay.style.removeProperty('height');
                overlay.style.removeProperty('padding-top');
                overlay.style.removeProperty('padding-bottom');
                overlay.style.removeProperty('--planner-visible-height');
            }
        });
    };
    const focus = () => {
        overlay.classList.add('planner-worker-search-active');
        sync();
        setTimeout(() => input.closest('.planner-team-column')?.scrollIntoView({ block: 'start' }), 80);
    };
    const blur = () => setTimeout(() => {
        if (document.activeElement !== input) overlay.classList.remove('planner-worker-search-active');
        sync();
    }, 180);
    input.addEventListener('focus', focus);
    input.addEventListener('blur', blur);
    viewport.addEventListener('resize', sync);
    viewport.addEventListener('scroll', sync);
    window._plannerViewportCleanup = () => {
        if (frame) cancelAnimationFrame(frame);
        input.removeEventListener('focus', focus);
        input.removeEventListener('blur', blur);
        viewport.removeEventListener('resize', sync);
        viewport.removeEventListener('scroll', sync);
        overlay.classList.remove('planner-keyboard-open', 'planner-worker-search-active');
        overlay.style.removeProperty('top');
        overlay.style.removeProperty('bottom');
        overlay.style.removeProperty('height');
        overlay.style.removeProperty('padding-top');
        overlay.style.removeProperty('padding-bottom');
        overlay.style.removeProperty('--planner-visible-height');
        window._plannerViewportCleanup = null;
    };
    sync();
}

function plannerFilterWorkers() {
    const input = document.getElementById('plannerWorkerSearch');
    const list = document.getElementById('plannerWorkerList');
    if (!input || !list) return;

    const query = input.value.trim().toLowerCase();
    let visibleCount = 0;

    list.querySelectorAll('.planner-worker-item').forEach(item => {
        const haystack = item.getAttribute('data-worker-search') || '';
        const visible = !query || haystack.includes(query);
        item.style.display = visible ? '' : 'none';
        if (visible) visibleCount++;
    });

    const counter = document.getElementById('plannerWorkerCount');
    if (counter) counter.textContent = visibleCount;
    list.scrollTop = 0;
}

// "Bestätigen" is only offered once the shift's scheduled end has passed
// (Europe/Berlin wall clock — the dispatch timezone). A shift still running
// or in the future cannot be signed off. Overnight shifts end next day;
// missing end time falls back to end-of-day; missing datum keeps the old
// behavior (button visible).
function plannerShiftEnded(task) {
    const datum = String(task && task.datum || '').substring(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(datum)) return true;
    const start = String(task.ist_start || '').substring(0, 5);
    const end = String(task.ist_ende || '').substring(0, 5);
    const [y, m, d] = datum.split('-').map(Number);
    const endDay = new Date(y, m - 1, d);
    if (/^\d{2}:\d{2}$/.test(start) && /^\d{2}:\d{2}$/.test(end) && end < start) {
        endDay.setDate(endDay.getDate() + 1); // overnight
    }
    const endTime = /^\d{2}:\d{2}$/.test(end) ? end : '23:59';
    const endIso = endDay.getFullYear() + '-' + String(endDay.getMonth() + 1).padStart(2, '0')
        + '-' + String(endDay.getDate()).padStart(2, '0') + ' ' + endTime + ':00';
    const nowBerlin = new Date().toLocaleString('sv-SE', { timeZone: 'Europe/Berlin' });
    return nowBerlin >= endIso;
}

// Format a UTC "YYYY-MM-DD HH:MM:SS" timestamp (as stored by now()) as
// Berlin wall time for the plan-cycle audit notice.
function plannerPlanStampFmt(ts) {
    const m = String(ts || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
    if (!m) return null;
    const utc = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]));
    const berlin = utc.toLocaleString('sv-SE', { timeZone: 'Europe/Berlin' });
    const b = String(berlin).match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/);
    return b ? `${b[3]}.${b[2]}.${b[1]} um ${b[4]}:${b[5]} Uhr` : null;
}

// Sentence for the "Plan aufgehoben" notice above "Zugewiesen". Falls back
// gracefully when the publish timestamp is missing (shifts withdrawn before
// the audit columns existed).
function plannerPlanWithdrawnText(task) {
    const wd = plannerPlanStampFmt(task.plan_aufgehoben_at);
    const pub = plannerPlanStampFmt(task.geplant_at);
    if (pub && wd) return `Dieser Einsatz wurde am <strong>${pub}</strong> veröffentlicht und am <strong>${wd}</strong> zurückgezogen.`;
    if (wd) return `Dieser Einsatz wurde am <strong>${wd}</strong> zurückgezogen.`;
    return 'Dieser Einsatz wurde veröffentlicht und wieder zurückgezogen.';
}

async function plannerConfirmSingleShift(einsatzId, confirmShort = false) {
    const workerIds = [...document.querySelectorAll('#plannerAssignedList .planner-worker-item')].map(item => item.getAttribute('data-worker-id')).filter(Boolean);
    const nuWorkerCount = document.querySelectorAll('#plannerAssignedList .planner-nu-worker-card').length;
    if (workerIds.length === 0 && nuWorkerCount === 0) {
        showToast('Bitte zuerst Mitarbeiter zuweisen', 'error');
        return;
    }
    const nuId = document.getElementById('planner_nu_select')?.value || null;

    const result = await apiPost(API.disposition + '/save-assignment', {
        einsatz_id: einsatzId,
        worker_ids: workerIds,
        worker_hours: collectWorkerHours(),
        worker_tasks: collectWorkerTasks(),
        nu_id: nuId,
        confirm: true,
        confirm_short: confirmShort
    });
    // Shift has fewer workers than required — warn once, then confirm anyway
    // if the dispatcher explicitly wants it (e.g. NU covers the rest).
    if (result && result.short_staffed) {
        const ok = await mdConfirm(
            `Nur ${result.assigned} von ${result.required} benötigten Mitarbeitern sind zugewiesen. Einsatz trotzdem bestätigen?`,
            { title: 'Unterbesetzt bestätigen?', confirmLabel: 'Trotzdem bestätigen' }
        );
        if (ok) return plannerConfirmSingleShift(einsatzId, true);
        return;
    }
    if (!result || !result.success) {
        showToast(result?.error || 'Speichern/Bestätigen fehlgeschlagen', 'error');
        return;
    }
    showToast('Einsatz bestätigt');
    closeModal();
    loadDisposition();
}

function collectWorkerHours() {
    const hours = {};
    document.querySelectorAll('#plannerAssignedList .planner-worker-hours').forEach(input => {
        const wid = input.dataset.workerId;
        if (wid && input.value !== '') hours[wid] = parseFloat(input.value) || 0;
    });
    return hours;
}

// Collect the per-worker task inputs from the assigned list. Sent as
// `worker_tasks` (a { dienst_nr: text } map) and persisted to
// einsatz_mitarbeiter.aufgabe, then surfaced in the worker's app.
function collectWorkerTasks() {
    const tasks = {};
    document.querySelectorAll('#plannerAssignedList .planner-worker-task').forEach(input => {
        const wid = input.dataset.workerId;
        if (wid) tasks[wid] = (input.value || '').trim();
    });
    return tasks;
}

// Apply the bulk "team task" to every assigned worker's task field at once.
// Workers can still be edited individually afterwards.
function plannerApplyTaskToAll() {
    const src = document.getElementById('plannerBulkTask');
    if (!src) return;
    const val = (src.value || '').trim();
    const inputs = document.querySelectorAll('#plannerAssignedList .planner-worker-task');
    if (inputs.length === 0) {
        showToast('Noch keine Mitarbeiter zugewiesen', 'error');
        return;
    }
    inputs.forEach(input => { input.value = val; plannerAutoGrow(input); });
    showToast(`Aufgabe für ${inputs.length} Mitarbeiter übernommen`);
}

// Auto-grow a task textarea to fit its text. Native `field-sizing: content`
// handles this in modern browsers; the scrollHeight fallback covers older ones.
function plannerAutoGrow(el) {
    if (!el || (window.CSS && CSS.supports('field-sizing', 'content'))) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 260) + 'px';
    el.style.overflowY = el.scrollHeight > 260 ? 'auto' : 'hidden';
}

// Resize every prefilled task field once the modal has rendered.
function plannerInitTaskFieldHeights() {
    requestAnimationFrame(() => {
        document.querySelectorAll('#plannerAssignedList .planner-worker-task, #plannerBulkTask')
            .forEach(plannerAutoGrow);
    });
}

async function plannerMarkSingleShiftPlanned(einsatzId, button) {
    // Block plan if NU positions are filled but not sent
    if (hasUnsentNuPositions()) {
        showToast('NU-Positionen wurden ausgefüllt aber nicht gesendet. Bitte "Anfrage absenden" klicken oder die Positionen entfernen.', 'error');
        return;
    }
    const workerIds = [...document.querySelectorAll('#plannerAssignedList .planner-worker-item')].map(item => item.getAttribute('data-worker-id')).filter(Boolean);
    const nuWorkersRemaining = document.querySelectorAll('#plannerAssignedList .planner-nu-worker-card').length;
    const previousWorkerIds = window._plannerOriginalWorkerIds || [];
    const wasPlanned = window._plannerOriginalPruefung === 'geplant';

    // Confirmation when removing all workers from a planned task — only when NO
    // NU workers remain either. With NU workers still assigned the backend keeps
    // the shift 'geplant', so showing a "Plan aufheben" dialog would be wrong.
    if (wasPlanned && workerIds.length === 0 && nuWorkersRemaining === 0 && previousWorkerIds.length > 0) {
        const confirmed = await systemConfirm({
            title: 'Plan aufheben?',
            message: `Alle ${previousWorkerIds.length} Mitarbeiter wurden entfernt. Der Plan wird aufgehoben und eine Stornierung an die betroffenen Mitarbeiter gesendet.`,
            confirmText: 'Plan aufheben',
            cancelText: 'Abbrechen',
            icon: 'event_busy',
            type: 'danger'
        });
        if (!confirmed) return;
    }

    // Nothing to plan: a draft shift with zero workers (internal + NU) can
    // never become 'geplant' — bulk-mark-planned would silently skip it and
    // the success toast below would wrongly claim "Plan wurde aufgehoben".
    // Only the un-plan path (wasPlanned + confirmed) may proceed empty.
    if (!wasPlanned && workerIds.length === 0 && nuWorkersRemaining === 0) {
        showToast('Bitte zuerst Mitarbeiter oder NU-Mitarbeiter zuweisen', 'error');
        return;
    }

    // NO-OP guard: on a live plan with literally nothing changed — same team,
    // same NU link, same task texts — a publish would only rewrite identical
    // values. defaultValue always holds what the field was rendered with.
    if (wasPlanned) {
        const sameTeam = workerIds.length === previousWorkerIds.length
            && workerIds.every(id => previousWorkerIds.includes(id));
        const nuSel = document.getElementById('planner_nu_select');
        const nuUnchanged = !nuSel || nuSel.value === nuSel.defaultValue;
        const tasksUnchanged = [...document.querySelectorAll('#plannerAssignedList .planner-worker-task')]
            .every(t => t.value === t.defaultValue);
        const bulkEl = document.getElementById('plannerBulkTask');
        const bulkUnchanged = !bulkEl || bulkEl.value === bulkEl.defaultValue;
        if (sameTeam && nuUnchanged && tasksUnchanged && bulkUnchanged) {
            showToast('Bereits geplant — nichts geändert');
            return;
        }
    }

    if (button) button.disabled = true;
    const nuId = document.getElementById('planner_nu_select')?.value || null;
    const saveResult = await apiPost(API.disposition + '/save-assignment', {
        einsatz_id: einsatzId,
        worker_ids: workerIds,
        worker_tasks: collectWorkerTasks(),
        nu_id: nuId
    });
    if (!saveResult || !saveResult.success) {
        showToast(saveResult?.error || 'Speichern fehlgeschlagen', 'error');
        if (button) button.disabled = false;
        return;
    }

    const result = await apiPost(API.disposition + '/bulk-mark-planned', {
        einsatz_ids: [einsatzId],
        previous_worker_ids: previousWorkerIds
    });
    if (result.success) {
        if (workerIds.length === 0 && nuWorkersRemaining === 0) {
            showToast('Plan wurde aufgehoben');
        } else {
            // NU-only shifts land here too — they ARE planned (NU workers
            // count). On a live plan the team-change case is an UPDATE:
            // removed workers already got their Stornierung from
            // save-assignment, new ones get the plan email from bulk-mark.
            showToast(wasPlanned ? 'Plan aktualisiert' : 'Einsatz als geplant markiert');
        }
        closeModal();
        loadDisposition();
    } else {
        showToast(result.error || 'Geplant konnte nicht gesetzt werden', 'error');
    }
    if (button) button.disabled = false;
}

function hasUnsentNuPositions() {
    if (!_nuEditingNuId) return false;
    // If the NU being edited already exists as a saved group, it's not "unsent"
    const existingGroup = _nuGroups.find(g => String(g.nu_id) === String(_nuEditingNuId));
    if (existingGroup && existingGroup.positions.length > 0) return false;
    // Only block if there are actual positions being created that haven't been sent
    if (_nuPositions.length === 0) return false;
    return true;
}

async function savePlannerAssignment(einsatzId) {
    // Block save if plan must be revoked first (all workers removed from a planned task)
    const saveBtn = document.getElementById('plannerSaveBtn');
    if (saveBtn && saveBtn.dataset.blocked === 'true') {
        showToast('Alle Mitarbeiter entfernt — bitte zuerst "Plan aufheben" klicken, bevor Sie speichern können.', 'warning');
        return;
    }
    // Block save if NU positions are filled but not sent
    if (hasUnsentNuPositions()) {
        showToast('NU-Positionen wurden ausgefüllt aber nicht gesendet. Bitte "Anfrage absenden" klicken oder die Positionen entfernen.', 'error');
        return;
    }
    const workerIds = [...document.querySelectorAll('#plannerAssignedList .planner-worker-item')].map(item => item.getAttribute('data-worker-id')).filter(Boolean);
    // Persist a NU link only for NUs with an actually sent request (a saved
    // group). Merely clicking a partner in the overlay must not write
    // einsaetze.nu_id — that link is committed by save-nu-positions.
    const selNu = document.getElementById('planner_nu_select')?.value || null;
    let nuId;
    if (_nuGroupsLoaded) {
        nuId = _nuGroups.some(g => String(g.nu_id) === String(selNu)) ? selNu
             : (_nuGroups.length > 0 ? _nuGroups[0].nu_id : null);
    } else {
        nuId = selNu; // groups not loaded yet — keep the existing link
    }
    const result = await apiPost(API.disposition + '/save-assignment', {
        einsatz_id: einsatzId,
        worker_ids: workerIds,
        worker_hours: collectWorkerHours(),
        worker_tasks: collectWorkerTasks(),
        nu_id: nuId
    });
    if (result.success) {
        closeModal();
        showToast('Schicht gespeichert');
        loadDisposition();
    } else {
        showToast(result.error || 'Speichern fehlgeschlagen', 'error');
    }
}


// ============================================================
// Schichtbörse (shift exchange) — Dispo side
// The office publishes understaffed shifts; suitable workers request them in
// the app (or hand over their own shift); nothing changes in the plan until
// the office approves here. Backend: /api/schichtboerse/*.
// ============================================================
const BOERSE_API = API_BASE + '/schichtboerse';

function boerseReqRowHtml(r, angebot, einsatzId) {
    const who = angebot.typ === 'abgabe' ? `übernimmt von <strong>${escapeHtml(angebot.anbieter_name || '')}</strong>` : 'möchte die offene Schicht';
    return `<div class="boerse-req">
        ${plannerWorkerAvatarHtml(r.foto, r.name || r.dienst_nr, 'width:34px;height:34px')}
        <div class="boerse-req-main"><strong>${escapeHtml(r.name || r.dienst_nr)}</strong> <small>${escapeHtml(r.dienst_nr)}</small>
            <div class="boerse-req-sub">${who}</div></div>
        <button type="button" class="btn btn-sm btn-success" onclick="plannerBoerseDecide(${parseInt(r.id, 10)}, true, '${escapeHtml(einsatzId || '')}')"><span class="material-symbols-outlined">check</span> Genehmigen</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="plannerBoerseDecide(${parseInt(r.id, 10)}, false, '${escapeHtml(einsatzId || '')}')"><span class="material-symbols-outlined">close</span></button>
    </div>`;
}

async function plannerLoadBoerse(einsatzId) {
    const box = document.getElementById('plannerBoerseBox');
    if (!box) return;
    const s = await apiGet(BOERSE_API + '/einsatz/' + encodeURIComponent(einsatzId));
    if (!s || !s.ready || window._plannerEinsatzId !== einsatzId || !document.getElementById('plannerBoerseBox')) return;
    const offen = (s.angebote || []).find(a => a.typ === 'offen');
    const abgaben = (s.angebote || []).filter(a => a.typ === 'abgabe');
    const edit = typeof canEdit !== 'function' || canEdit('tasks');
    let html = '';
    if (offen) {
        html += `<div class="boerse-box is-live">
            <div class="boerse-box-head"><span class="material-symbols-outlined">swap_horiz</span>
                <span><strong>In der Schichtbörse</strong> · ${offen.freie_plaetze} ${offen.freie_plaetze === 1 ? 'Platz' : 'Plätze'} frei · ${s.passend} passende Mitarbeiter sehen die Schicht</span>
                ${edit ? `<button type="button" class="btn btn-sm btn-outline" onclick="plannerBoerseWithdraw(${offen.id}, '${escapeHtml(einsatzId)}')">Zurückziehen</button>` : ''}</div>
            ${(offen.anfragen || []).length ? (offen.anfragen || []).map(r => boerseReqRowHtml(r, offen, einsatzId)).join('') : '<div class="boerse-empty">Noch keine Anfragen</div>'}
        </div>`;
    }
    abgaben.forEach(a => {
        html += `<div class="boerse-box is-abgabe">
            <div class="boerse-box-head"><span class="material-symbols-outlined">move_up</span>
                <span><strong>${escapeHtml(a.anbieter_name || '')}</strong> möchte die Schicht abgeben${a.notiz ? ' · „' + escapeHtml(a.notiz) + '“' : ''}</span>
                ${edit ? `<button type="button" class="btn btn-sm btn-outline" onclick="plannerBoerseWithdraw(${a.id}, '${escapeHtml(einsatzId)}')">Abgabe beenden</button>` : ''}</div>
            ${(a.anfragen || []).length ? (a.anfragen || []).map(r => boerseReqRowHtml(r, a, einsatzId)).join('') : '<div class="boerse-empty">Noch kein Kollege hat angefragt</div>'}
        </div>`;
    });
    if (!offen && s.kann_veroeffentlichen && edit) {
        html += `<div class="boerse-box boerse-box-cta">
            <span class="material-symbols-outlined">swap_horiz</span>
            <span>${s.freie_plaetze} ${s.freie_plaetze === 1 ? 'Platz ist' : 'Plätze sind'} noch offen — passende Mitarbeiter können die Schicht in der App anfragen.</span>
            <button type="button" class="btn btn-sm btn-primary" onclick="plannerBoersePublish('${escapeHtml(einsatzId)}', this)"><span class="material-symbols-outlined">campaign</span> In Schichtbörse veröffentlichen</button>
        </div>`;
    }
    box.innerHTML = html;
    if (!edit) box.querySelectorAll('.boerse-req .btn').forEach(b => b.remove());
}

async function plannerBoersePublish(einsatzId, btn) {
    if (btn) btn.disabled = true;
    const r = await apiPost(BOERSE_API + '/einsatz/' + encodeURIComponent(einsatzId) + '/veroeffentlichen', {});
    if (btn) btn.disabled = false;
    if (!r || r.error || !r.id) return showToast((r && (r.error || r.message)) || 'Konnte nicht veröffentlicht werden', 'error');
    showToast(r.benachrichtigt ? `Veröffentlicht — ${r.benachrichtigt} passende Mitarbeiter benachrichtigt` : 'Veröffentlicht — aktuell passt kein Mitarbeiter', r.benachrichtigt ? 'success' : 'warning');
    plannerLoadBoerse(einsatzId);
    plannerBoerseRefreshBadge();
}

async function plannerBoerseWithdraw(angebotId, einsatzId) {
    if (!await mdConfirm('Angebot aus der Schichtbörse nehmen? Offene Anfragen werden abgelehnt.', { title: 'Schichtbörse', confirmLabel: 'Zurückziehen' })) return;
    const r = await apiPost(BOERSE_API + '/angebote/' + angebotId + '/zurueckziehen', {});
    if (!r || r.error) return showToast((r && r.error) || 'Fehler', 'error');
    showToast('Aus der Schichtbörse genommen');
    if (einsatzId && document.getElementById('plannerBoerseBox')) plannerLoadBoerse(einsatzId);
    if (document.getElementById('boerseDialogList')) boerseLoadDialog();
    plannerBoerseRefreshBadge();
}

// Approve → the worker is put on the shift (or swapped) server-side; the
// open modal is reloaded so it shows the new team.
async function plannerBoerseDecide(anfrageId, approve, einsatzId) {
    let body = {};
    if (!approve) {
        const grund = await mdPrompt('Der Mitarbeiter sieht den Grund in der App (optional).', { title: 'Anfrage ablehnen', type: 'text', label: 'Grund' });
        if (grund === null) return;
        body = { grund: grund.trim() || null };
    }
    const r = await apiPost(BOERSE_API + '/anfragen/' + anfrageId + (approve ? '/genehmigen' : '/ablehnen'), body);
    if (!r || r.error) return showToast((r && (r.error || r.message)) || 'Fehler', 'error');
    showToast(approve ? 'Genehmigt — Mitarbeiter ist eingeplant und benachrichtigt' : 'Anfrage abgelehnt');
    if (document.getElementById('boerseDialogList')) { boerseLoadDialog(); if (approve) loadDisposition(); return; }
    if (approve && einsatzId && document.getElementById('plannerBoerseBox')) { loadDisposition(); openPlannerTaskDetail(einsatzId); return; }
    if (einsatzId && document.getElementById('plannerBoerseBox')) plannerLoadBoerse(einsatzId);
    plannerBoerseRefreshBadge();
}

async function plannerBoerseRefreshBadge() {
    const r = await apiGet(BOERSE_API + '/count');
    const n = (r && r.offene_anfragen) | 0;
    document.querySelectorAll('.planner-boerse-badge').forEach(b => { b.textContent = n > 99 ? '99+' : n; b.style.display = n > 0 ? '' : 'none'; });
}

// Toolbar dialog: every active offer with its open requests.
async function openBoerseDialog() {
    openModal('Schichtbörse', '<div id="boerseDialogList" class="boerse-dialog"><div class="planner-loading">Lade…</div></div>',
        '<button class="btn btn-outline" onclick="closeModal()">Schließen</button>', 'modal-lg');
    boerseLoadDialog();
}

async function boerseLoadDialog() {
    const el = document.getElementById('boerseDialogList');
    if (!el) return;
    const r = await apiGet(BOERSE_API);
    const list = (r && r.angebote) || [];
    plannerBoerseRefreshBadge();
    if (!list.length) {
        el.innerHTML = `<div class="boerse-empty boerse-empty-big"><span class="material-symbols-outlined">swap_horiz</span>
            <div>Keine Schichten in der Schichtbörse.</div>
            <small>Öffnen Sie eine unterbesetzte Schicht und klicken Sie auf „In Schichtbörse veröffentlichen“. Mitarbeiter können ihre eigenen Schichten in der App abgeben.</small></div>`;
        return;
    }
    list.sort((a, b) => (b.anfragen || []).length - (a.anfragen || []).length || String(a.einsatz.datum + a.einsatz.ist_start).localeCompare(String(b.einsatz.datum + b.einsatz.ist_start)));
    const edit = typeof canEdit !== 'function' || canEdit('tasks');
    el.innerHTML = list.map(a => `
        <div class="boerse-box ${a.typ === 'abgabe' ? 'is-abgabe' : 'is-live'}">
            <div class="boerse-box-head">
                <span class="material-symbols-outlined">${a.typ === 'abgabe' ? 'move_up' : 'swap_horiz'}</span>
                <span><strong>${escapeHtml(a.einsatz.label)}</strong>${a.einsatz.kundenname ? ' · ' + escapeHtml(a.einsatz.kundenname) : ''}
                    <small class="boerse-sub">${a.typ === 'abgabe' ? 'Abgabe von ' + escapeHtml(a.anbieter_name || '') : 'Offene Schicht · ' + a.freie_plaetze + ' frei'}${a.einsatz.bestell_id ? ' · ' + escapeHtml(a.einsatz.bestell_id) : ''}</small></span>
                <button type="button" class="btn btn-sm btn-outline" onclick="closeModal();openPlannerTaskDetail('${escapeHtml(a.einsatz.einsatz_id)}')">Schicht öffnen</button>
                ${edit ? `<button type="button" class="btn btn-sm btn-outline" onclick="plannerBoerseWithdraw(${a.id}, '')">Zurückziehen</button>` : ''}
            </div>
            ${(a.anfragen || []).length ? a.anfragen.map(rq => boerseReqRowHtml(rq, a, '')).join('') : '<div class="boerse-empty">Noch keine Anfragen</div>'}
        </div>`).join('');
    if (!edit) el.querySelectorAll('.boerse-req .btn').forEach(b => b.remove());
}

// Planung → Bestelleingang: "Bestellung B-…" links back to its order
// (opens the order's view dialog in the Bestelleingang).
function plannerOrderRefHtml(task) {
    const label = 'Bestellung ' + escapeHtml(task.bestell_id || '–');
    const card = ((plannerState.data && plannerState.data.cards) || []).find(c => String(c.einsatz_id) === String(task.einsatz_id));
    const eid = task.order_eingang_id || (card && card.eingang_id) || '';
    if (!eid || (typeof hasPermission === 'function' && !hasPermission(PAGE_PERMS.incoming_orders))) return label;
    return `${label} · <a href="#" class="planner-be-link" style="color:var(--accent,#2563eb);font-weight:600;text-decoration:none;white-space:nowrap" onclick="event.preventDefault();plannerOpenEingang('${escapeHtml(eid)}')" title="Bestelleingang ${escapeHtml(eid)} öffnen"><span class="material-symbols-outlined" style="font-size:14px;vertical-align:-2px">inbox</span> Im Bestelleingang öffnen</a>`;
}
function plannerOpenEingang(eingangId) {
    try { sessionStorage.setItem('be_focus_order', eingangId); } catch (e) { /* ignore */ }
    if (typeof closeModal === 'function') closeModal();
    loadPage('incoming_orders');
}
