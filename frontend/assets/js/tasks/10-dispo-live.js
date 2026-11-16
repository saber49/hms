// tasks/10-dispo-live.js — Dispo: NU-Karten, Polling/Auto-Refresh, NU-Entfernen, Realtime-Hook
// Teil 10/10 des früheren tasks.js. Alle Teile teilen den globalen Scope und MÜSSEN in dieser Reihenfolge geladen werden (PAGE_MODULE_SCRIPTS in app.js).

// ============================================
// Inject NU Worker Cards into Zugewiesen list
// ============================================
function plannerInjectNuWorkerCards() {
    // Remove any previously injected NU cards
    document.querySelectorAll('.planner-nu-worker-card').forEach(el => el.remove());

    const assignedList = document.getElementById('plannerAssignedList');
    if (!assignedList) return;

    // Collect all assigned NU workers across all groups
    const nuWorkers = [];
    _nuGroups.forEach(group => {
        (group.positions || []).forEach(pos => {
            (pos.zuweisungen || []).forEach(z => {
                if (!nuWorkers.find(w => w.nu_mitarbeiter_id === z.nu_mitarbeiter_id)) {
                    nuWorkers.push({ ...z, position_name: pos.position_name, firmenname: group.firmenname });
                }
            });
        });
    });

    if (nuWorkers.length === 0) return;

    // Remove empty-list placeholder if present
    const emptyMsg = assignedList.querySelector('.planner-empty-list');
    if (emptyMsg) emptyMsg.remove();

    nuWorkers.forEach(w => {
        const wName = ((w.vorname || '') + ' ' + (w.name || '')).trim();
        const initials = ((w.vorname || '')[0] || '').toUpperCase() + ((w.name || '')[0] || '').toUpperCase() || '?';
        const card = document.createElement('div');
        card.className = 'planner-worker-item planner-nu-worker-card';
        card.setAttribute('data-nu-mitarbeiter-id', w.nu_mitarbeiter_id);
        card.setAttribute('data-position-id', w.position_id || '');
        card.setAttribute('data-nu-worker-name', escapeHtml(wName));
        card.setAttribute('data-nu-firma', escapeHtml(w.firmenname || ''));
        card.innerHTML = `
            <button type="button" class="planner-worker-remove planner-nu-remove" onclick="event.stopPropagation(); openNuWorkerRemoveModal(this)" title="NU-Mitarbeiter entfernen"><span class="material-symbols-outlined">close</span></button>
            <div class="planner-worker-main">
                <div class="planner-worker-avatar planner-nu-avatar">
                    ${w.foto ? `<img src="${escapeHtml(w.foto)}" alt="${escapeHtml(wName)}">` : `<span>${escapeHtml(initials)}</span>`}
                </div>
                <div class="planner-worker-info">
                    <div class="planner-worker-name">${escapeHtml(wName)}</div>
                    <div class="planner-worker-subline">${escapeHtml(w.personal_nr || '')}${w.position_name ? ' · ' + escapeHtml(w.position_name) : ''}</div>
                    <div class="planner-nu-firma"><span class="material-symbols-outlined">domain</span> ${escapeHtml(w.firmenname || 'Nachunternehmer')}</div>
                </div>
            </div>
        `;
        assignedList.appendChild(card);
    });

    // Update assigned count
    const countEl = document.getElementById('plannerAssignedCount');
    if (countEl) {
        const hmsCount = assignedList.querySelectorAll('.planner-worker-item:not(.planner-nu-worker-card)').length;
        countEl.textContent = hmsCount + nuWorkers.length;
    }
    if (typeof plannerUpdateGenderCoverage === 'function') plannerUpdateGenderCoverage();
}

// ============================================
// NU Real-Time Refresh — periodic polling while modal is open
// ============================================
let _nuRefreshInterval = null;

function startNuRefreshPolling(einsatzId) {
    stopNuRefreshPolling();
    _nuRefreshInterval = setInterval(async () => {
        if (!document.getElementById('plannerAssignedList')) { stopNuRefreshPolling(); return; }
        try {
            const result = await apiGet(API.disposition + '/nu-positions?einsatz_id=' + einsatzId);
            if (result && result.groups) {
                const oldJson = JSON.stringify(_nuGroups.map(g => ({ id: g.nu_id, z: g.total_zugewiesen, s: g.nu_status })));
                const newJson = JSON.stringify(result.groups.map(g => ({ id: g.nu_id, z: g.total_zugewiesen, s: g.nu_status })));
                if (oldJson !== newJson) {
                    _nuGroups = result.groups;
                    _nuKundenname = result.kundenname || '';
                    _nuObjektname = result.objektname || '';
                    if (!_nuEditingNuId) plannerRenderNuSummary();
                    plannerInjectNuWorkerCards();
                }
            }
        } catch (e) { /* silent — polling failure is non-critical */ }
    }, 2000);
}

function stopNuRefreshPolling() {
    if (_nuRefreshInterval) { clearInterval(_nuRefreshInterval); _nuRefreshInterval = null; }
}

// ============================================
// Planner board auto-refresh — keep the Dispo board in sync without a
// manual page reload. Re-fetches the planner payload every 2s while the
// Dispo page is open, and immediately when the tab regains focus. The
// DOM is only rebuilt when the data actually changed (silent mode), and
// the tick is skipped entirely while any modal/dialog is open or the
// user is typing in a field, so edits and focus are never clobbered.
// ============================================
let _plannerAutoRefreshTimer = null;

function plannerStartAutoRefresh() {
    if (_plannerAutoRefreshTimer) return;
    _plannerAutoRefreshTimer = setInterval(plannerAutoRefreshTick, 2000);
    if (!window._plannerVisRefreshBound) {
        window._plannerVisRefreshBound = true;
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) plannerAutoRefreshTick();
        });
    }
}

function plannerUiBusy() {
    if (typeof _activeDialogOverlay === 'function' && _activeDialogOverlay()) return true;
    if (document.querySelector('md-dialog[open]')) return true;
    if (document.getElementById('dispoBereichGate')) return true;
    const ae = document.activeElement;
    return !!ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.tagName === 'SELECT');
}

async function plannerAutoRefreshTick() {
    if (currentPage !== 'tasks' && currentPage !== 'disposition') return;
    const grid = document.getElementById('plannerGrid');
    if (!plannerState.data || !grid || plannerUiBusy()) return;
    // Preserve grid scroll across the re-render.
    const sl = grid.scrollLeft, st = grid.scrollTop;
    try { await loadDisposition(true); } catch (e) { return; }
    const g2 = document.getElementById('plannerGrid');
    if (g2) { g2.scrollLeft = sl; g2.scrollTop = st; }
}

// ============================================
// NU Worker Removal — Modal + API + Email
// ============================================
let _nuRemovePending = null;

function openNuWorkerRemoveModal(btn) {
    const card = btn.closest('.planner-nu-worker-card');
    if (!card) return;
    const nuMitarbeiterId = card.getAttribute('data-nu-mitarbeiter-id');
    const positionId = card.getAttribute('data-position-id') || '';
    const workerName = card.getAttribute('data-nu-worker-name') || 'NU-Mitarbeiter';
    const firma = card.getAttribute('data-nu-firma') || '';

    _nuRemovePending = { nuMitarbeiterId, positionId, workerName, firma, card };

    // Create or reuse overlay
    let overlay = document.getElementById('nuRemoveOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'nuRemoveOverlay';
        document.body.appendChild(overlay);
    }

    overlay.innerHTML = `
        <div class="nu-remove-backdrop" onclick="closeNuRemoveModal()"></div>
        <div class="nu-remove-modal">
            <div class="nu-remove-header">
                <div class="nu-remove-header-icon"><span class="material-symbols-outlined">person_remove</span></div>
                <div>
                    <div class="nu-remove-header-title">NU-Mitarbeiter entfernen</div>
                    <div class="nu-remove-header-sub">${escapeHtml(workerName)}${firma ? ' — ' + escapeHtml(firma) : ''}</div>
                </div>
                <button class="nu-remove-close" onclick="closeNuRemoveModal()"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="nu-remove-body">
                <label class="nu-remove-label">Grund der Abmeldung <span style="color:#dc2626">*</span></label>
                <textarea id="nuRemoveReason" class="nu-remove-textarea" rows="4" placeholder="Bitte geben Sie den Grund ein, warum dieser Mitarbeiter entfernt wird..."></textarea>
                <div class="nu-remove-info">
                    <span class="material-symbols-outlined">mail</span>
                    <span>Eine E-Mail mit dem Grund wird automatisch an den Nachunternehmer gesendet.</span>
                </div>
            </div>
            <div class="nu-remove-footer">
                <button class="btn btn-outline btn-sm" onclick="closeNuRemoveModal()"><span class="material-symbols-outlined">arrow_back</span> Abbrechen</button>
                <button class="btn btn-sm nu-remove-confirm-btn" id="nuRemoveConfirmBtn" onclick="confirmNuWorkerRemoval()"><span class="material-symbols-outlined">person_remove</span> Entfernen & Benachrichtigen</button>
            </div>
        </div>
    `;
    overlay.classList.add('active');
    setTimeout(() => { const ta = document.getElementById('nuRemoveReason'); if (ta) ta.focus(); }, 150);
}

function closeNuRemoveModal() {
    const overlay = document.getElementById('nuRemoveOverlay');
    if (overlay) overlay.classList.remove('active');
    _nuRemovePending = null;
}

async function confirmNuWorkerRemoval() {
    if (!_nuRemovePending) return;
    const reason = String(document.getElementById('nuRemoveReason')?.value ?? '').trim();
    if (!reason) {
        const ta = document.getElementById('nuRemoveReason');
        if (ta) { ta.style.borderColor = '#dc2626'; ta.focus(); }
        showToast('Bitte geben Sie einen Grund ein', 'error');
        return;
    }

    const btn = document.getElementById('nuRemoveConfirmBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<md-circular-progress indeterminate style="--md-circular-progress-size:14px;vertical-align:middle"></md-circular-progress> Wird entfernt...'; }

    const { nuMitarbeiterId, positionId, card } = _nuRemovePending;
    const result = await apiPost(API.disposition + '/remove-nu-worker', {
        einsatz_id: _nuEinsatzId,
        nu_mitarbeiter_id: nuMitarbeiterId,
        position_id: positionId || null,
        reason: reason
    });

    if (result && result.success) {
        showToast(result.message || 'Mitarbeiter entfernt');
        // Remove the card from DOM
        if (card && card.parentElement) card.remove();
        // Refresh NU data
        const allResult = await apiGet(API.disposition + '/nu-positions?einsatz_id=' + _nuEinsatzId);
        if (allResult && allResult.groups) {
            _nuGroups = allResult.groups;
        }
        plannerInjectNuWorkerCards();
        // Update NU summary if visible
        if (_nuGroups.length > 0 && !_nuEditingNuId) {
            plannerRenderNuSummary();
        }
        loadDisposition();
        closeNuRemoveModal();
    } else {
        showToast(result?.error || 'Fehler beim Entfernen', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-symbols-outlined">person_remove</span> Entfernen & Benachrichtigen'; }
    }
}

/* ---- Realtime hook: refresh Dispo when it changes elsewhere ---- */
if (window.HMSRealtime) {
    HMSRealtime.on('dispo', () => {
        try {
            if (typeof currentPage !== 'undefined' && (currentPage === 'tasks' || currentPage === 'disposition')) {
                loadDisposition(true);
            }
            plannerRefreshWorkerActualTimes().catch(() => {});
            const ov = document.getElementById('wpActiveOverlay');
            if (ov && ov.classList.contains('active') && typeof wpActiveReload === 'function') wpActiveReload();
        } catch (e) { /* realtime refresh is best-effort */ }
    });
    HMSRealtime.on('workers', () => {
        try {
            const ov = document.getElementById('wpActiveOverlay');
            if (ov && ov.classList.contains('active') && typeof wpActiveReload === 'function') wpActiveReload();
        } catch (e) { /* best-effort */ }
    });
}
