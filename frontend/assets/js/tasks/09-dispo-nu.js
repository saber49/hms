// tasks/09-dispo-nu.js — Dispo: Nachunternehmer-Positionen (Overlay, Gruppen, Speichern)
// Teil 9/10 des früheren tasks.js. Alle Teile teilen den globalen Scope und MÜSSEN in dieser Reihenfolge geladen werden (PAGE_MODULE_SCRIPTS in app.js).

// ============================================
// NU POSITION REQUIREMENTS (Admin-side, in Dispo modal)
// ============================================
// Disposition state namespace — prevents stale global state across modal openings
const DispoState = {
    nu: { positions: [], firmenname: '', groups: [], editingNuId: null, einsatzId: null, kundenname: '', objektname: '', taskNuStatus: null },
    reset() {
        this.nu.positions = []; this.nu.firmenname = ''; this.nu.groups = [];
        this.nu.editingNuId = null; this.nu.einsatzId = null;
        this.nu.kundenname = ''; this.nu.objektname = ''; this.nu.taskNuStatus = null;
    }
};
// Backward-compatible aliases (used throughout the module)
let _nuPositions = DispoState.nu.positions;
let _nuFirmenname = DispoState.nu.firmenname;
let _nuGroups = DispoState.nu.groups;
let _nuEditingNuId = DispoState.nu.editingNuId;
let _nuEinsatzId = DispoState.nu.einsatzId;
let _nuKundenname = DispoState.nu.kundenname;
let _nuObjektname = DispoState.nu.objektname;
let _nuTaskNuStatus = DispoState.nu.taskNuStatus;
let _nuGroupsLoaded = false; // true once nu-positions finished loading for this einsatz

async function plannerLoadNuPositions(einsatzId, nuId) {
    _nuEinsatzId = einsatzId;
    startNuRefreshPolling(einsatzId);
    ensureNuOverlay(); // create hidden body-level overlay so renders have targets
    const list = document.getElementById('plannerNuPosList');
    if (list) {
        list.innerHTML = '<div style="color:var(--gray-400);font-size:0.8rem;padding:12px;text-align:center"><md-circular-progress indeterminate style="--md-circular-progress-size:18px;vertical-align:middle"></md-circular-progress> Lade...</div>';
    }

    // Load all NU groups for this einsatz
    const allResult = await apiGet(API.disposition + '/nu-positions?einsatz_id=' + einsatzId);
    if (allResult && allResult.groups) {
        _nuGroups = allResult.groups;
        _nuKundenname = allResult.kundenname || '';
        _nuObjektname = allResult.objektname || '';
    } else {
        _nuGroups = [];
    }
    _nuGroupsLoaded = true;
    // Committed selection = a NU that actually has a sent request (group).
    // A bare einsaetze.nu_id without positions is a stale link — treat as none.
    const existingGroup = _nuGroups.find(g => String(g.nu_id) === String(nuId));
    window._plannerSelectedNuId = existingGroup ? nuId
        : (_nuGroups.length > 0 ? _nuGroups[0].nu_id : null);
    const nuSelectEl = document.getElementById('planner_nu_select');
    if (nuSelectEl) nuSelectEl.value = window._plannerSelectedNuId || '';

    if (_nuGroups.length > 0) {
        _nuEditingNuId = null;
        plannerRenderNuSummary();
    } else {
        // Nothing sent yet — start clean on the partner list when opened.
        _nuEditingNuId = null;
        _nuPositions = [];
        window._nuPositionsBaseline = null;
        if (list) list.innerHTML = '';
    }
    plannerInjectNuWorkerCards();
    plannerUpdateNuAddCard();
}

// Update the "Nachunternehmer hinzufügen" card to reflect the current NU
// state: saved groups (names + count), a selected-but-unsent NU, or the
// default empty state. Clicking it always opens the NU overlay.
function plannerUpdateNuAddCard() {
    const addCard = document.getElementById('plannerAddNuCard');
    if (!addCard) return;
    const subs = window._plannerSubcontractors || [];
    const nuFor = id => subs.find(n => String(n.nu_id) === String(id));
    let title = 'Nachunternehmer hinzufügen';
    let sub = 'Schichtanfrage an einen Partner senden';
    let icon = 'add';
    let tail = 'arrow_forward';
    if (_nuGroups.length > 0) {
        title = _nuGroups.map(g => g.firmenname || g.nu_id).join(', ');
        sub = _nuGroups.length + ' Anfrage(n) · Tippen zum Verwalten';
        icon = 'domain';
        tail = 'edit';
    } else if (window._plannerSelectedNuId && nuFor(window._plannerSelectedNuId)) {
        const nu = nuFor(window._plannerSelectedNuId);
        title = nu.firmenname;
        sub = (nu.bereich ? nu.bereich : 'Nachunternehmer') + ' · Tippen zum Ändern';
        icon = 'domain';
        tail = 'edit';
    }
    addCard.innerHTML = `
        <div class="planner-add-nu-icon"><span class="material-symbols-outlined">${icon}</span></div>
        <div class="planner-add-nu-text">
            <div class="planner-add-nu-title">${escapeHtml(title)}</div>
            <div class="planner-add-nu-sub">${escapeHtml(sub)}</div>
        </div>
        <span class="material-symbols-outlined" style="margin-left:auto;color:var(--nu-primary)">${tail}</span>
    `;
}

// Switch the visible view inside the NU overlay dialog.
//   'select'  → NU list + Anforderungen summary
//   'edit'    → positions form for the currently edited NU
//   'summary' → group overview cards (rendered into plannerNuPosList)
function plannerNuOverlayShow(view) {
    const selBody = document.getElementById('plannerNuViewSelect');
    const edtBody = document.getElementById('plannerNuViewEdit');
    const title = document.getElementById('plannerNuOverlayTitle');
    const sub = document.getElementById('plannerNuOverlaySub');
    const dialog = document.querySelector('#plannerNuSelectOverlay .planner-nu-select-dialog');
    if (dialog) dialog.dataset.view = view;
    if (!selBody || !edtBody) return;
    if (view === 'select') {
        selBody.style.display = '';
        edtBody.style.display = 'none';
        if (title) title.textContent = 'Nachunternehmer auswählen';
        if (sub) sub.textContent = 'Wählen Sie einen Partner für diese Schicht';
    } else {
        selBody.style.display = 'none';
        edtBody.style.display = '';
        if (title) title.textContent = view === 'summary' ? 'NU-Anfragen' : 'NU-Positionen & Anforderungen';
        if (sub) sub.textContent = view === 'summary' ? 'Angefragte Partner für diese Schicht' : (_nuFirmenname || 'Positionen definieren');
    }
}

function plannerRenderNuSummary() {
    const list = document.getElementById('plannerNuPosList');
    if (!list) return;

    // The panel lives inside the NU overlay — only content is rendered here;
    // which view is visible is decided by plannerNuOverlayShow.
    const container = document.getElementById('plannerNuViewEdit');

    // Hide NU panel header elements (show/hide handled differently in summary mode)
    const headerRight = container?.querySelector('.nu-positions-header-right');
    if (headerRight) headerRight.style.display = 'none';
    const footer = container?.querySelector('.nu-positions-footer');
    if (footer) footer.style.display = 'none';
    const firmaLabel = document.getElementById('plannerNuFirmaLabel');
    if (firmaLabel) firmaLabel.textContent = '';

    let html = '<div class="nu-summary-cards">';
    _nuGroups.forEach(group => {
        const isFull = group.total_zugewiesen >= group.total_anzahl && group.total_anzahl > 0;
        const groupNuStatus = group.nu_status || null;
        const isAccepted = groupNuStatus === 'accepted';
        const cardClass = isFull ? 'complete' : (isAccepted ? 'accepted' : (groupNuStatus === 'pending' ? 'nu-pending' : ''));
        html += `
        <div class="nu-summary-card ${cardClass}" onclick="plannerExpandNuGroup('${escapeHtml(group.nu_id)}')">
            <div class="nu-summary-actions">
                <md-icon-button class="nu-summary-action-btn" onclick="event.stopPropagation();plannerExpandNuGroup('${escapeHtml(group.nu_id)}')" title="Bearbeiten"><md-icon>edit</md-icon></md-icon-button>
                <md-icon-button class="nu-summary-action-btn danger" onclick="event.stopPropagation();plannerDeleteNuGroup('${escapeHtml(group.nu_id)}')" title="Löschen"><md-icon>delete</md-icon></md-icon-button>
            </div>
            <div class="nu-summary-location"><span class="material-symbols-outlined">location_on</span> ${escapeHtml(_nuObjektname || _nuKundenname || '—')}</div>
            <div class="nu-summary-firma">${escapeHtml(group.firmenname)}</div>
            <div class="nu-summary-stats">
                <div class="nu-summary-stat ${isFull ? 'filled' : ''}">
                    <span class="material-symbols-outlined">group</span>
                    <span class="count">${group.total_zugewiesen}/${group.total_anzahl}</span> MA
                </div>
                <div class="nu-summary-stat">
                    <span class="material-symbols-outlined">assignment</span>
                    <span class="count">${group.positions.length}</span> Pos.
                </div>
            </div>
        </div>`;
    });
    // Add "new company" card
    html += `
    <div class="nu-add-card" onclick="plannerStartNewNuRequest()">
        <span class="material-symbols-outlined">add_circle</span>
        <span>Weiterer NU</span>
    </div>`;
    html += '</div>';
    list.innerHTML = html;
}

// ── New NU selection overlay + auto-fill ──
// Normalise a geschlecht_mix value coming from the DB (JSON string) or the
// task's anforderungen (object) into { maennlich, weiblich } or null.
function plannerParseGeschlechtMix(mix) {
    if (!mix) return null;
    if (typeof mix === 'string') {
        try { mix = JSON.parse(mix); } catch (e) { return null; }
    }
    const m = parseInt(mix.maennlich ?? mix.m) || 0;
    const w = parseInt(mix.weiblich ?? mix.w) || 0;
    return (m > 0 || w > 0) ? { maennlich: m, weiblich: w } : null;
}

// Derive the coarse geschlecht label from a {maennlich,weiblich} mix.
function plannerGeschlechtFromMix(mix) {
    if (!mix) return '';
    const m = mix.maennlich || 0, w = mix.weiblich || 0;
    if (m > 0 && w > 0) return 'gemischt';
    if (m > 0) return 'maennlich';
    if (w > 0) return 'weiblich';
    return '';
}

// Resolve the gender split for one NU position: per-position mix/geschlecht
// first, then the shift-level mix or single value. The mix is the source of
// truth; `geschlecht` is derived from it for storage/labels.
function plannerResolvePositionGeschlecht(p, anf, anzahl, singlePosition) {
    const n = parseInt(anzahl) || 0;
    let mix = plannerParseGeschlechtMix(p.geschlecht_mix);
    const anfMix = plannerParseGeschlechtMix(anf.geschlecht_mix);
    if (!mix) {
        const g = p.geschlecht || '';
        if (g === 'maennlich') mix = { maennlich: n, weiblich: 0 };
        else if (g === 'weiblich') mix = { maennlich: 0, weiblich: n };
        else if (g === 'gemischt') mix = anfMix;
    }
    if (!mix) {
        if (anfMix) mix = anfMix;
        else {
            const ag = anf.geschlecht || '';
            if (ag === 'maennlich') mix = { maennlich: n, weiblich: 0 };
            else if (ag === 'weiblich') mix = { maennlich: 0, weiblich: n };
        }
    }
    // A single position covers the whole shift — scale the mix to anzahl.
    if (mix && singlePosition && n > 0) {
        const sum = mix.maennlich + mix.weiblich;
        if (sum > 0 && sum !== n) {
            const m = Math.round(n * mix.maennlich / sum);
            mix = { maennlich: m, weiblich: n - m };
        }
    }
    return { geschlecht: plannerGeschlechtFromMix(mix), geschlecht_mix: mix };
}

function plannerBuildNuPositionsFromTask() {
    const anf = window._plannerTaskAnf || {};
    const sollMenge = window._plannerSollMenge || 0;
    const positionen = Array.isArray(anf.positionen) ? anf.positionen : [];
    const positions = [];
    if (positionen.length > 0) {
        positionen.forEach(p => {
            const g = plannerResolvePositionGeschlecht(p, anf, p.anzahl, positionen.length === 1);
            positions.push({
                position_name: p.funktion || '',
                anzahl: parseInt(p.anzahl) || 1,
                geschlecht: g.geschlecht,
                geschlecht_mix: g.geschlecht_mix,
                qualifikation: p.qualifikation || anf.qualifikation || '',
                skills: p.skills || anf.skill || '',
                kleidung: p.kleidung || anf.kleidung || '',
                checkin_info: '',
                bemerkung: ''
            });
        });
    } else {
        const g = plannerResolvePositionGeschlecht({}, anf, sollMenge, true);
        positions.push({
            position_name: '',
            anzahl: sollMenge || 1,
            geschlecht: g.geschlecht,
            geschlecht_mix: g.geschlecht_mix,
            qualifikation: anf.qualifikation || '',
            skills: anf.skill || '',
            kleidung: anf.kleidung || '',
            checkin_info: '',
            bemerkung: ''
        });
    }
    return positions;
}

// The NU overlay is created lazily as a DIRECT CHILD of <body> — never
// inside the shift modal. Reason: the .modal element keeps a `transform`
// after its open animation (fill-mode both), which makes position:fixed
// children position/scroll relative to the modal instead of the viewport.
// Appended to <body>, the overlay is a true viewport-fixed popup.
function ensureNuOverlay() {
    let overlay = document.getElementById('plannerNuSelectOverlay');
    if (overlay && overlay.tagName === 'DIALOG') return overlay;
    if (overlay) overlay.remove(); // stale pre-<dialog> element — rebuild it
    overlay = document.createElement('dialog');
    overlay.id = 'plannerNuSelectOverlay';
    overlay.className = 'planner-nu-select-overlay';
    overlay.innerHTML = `
        <div class="planner-nu-select-dialog">
            <div class="planner-nu-select-header">
                <div>
                    <div class="planner-nu-select-title" id="plannerNuOverlayTitle">Nachunternehmer auswählen</div>
                    <div class="planner-nu-select-sub" id="plannerNuOverlaySub">Wählen Sie einen Partner für diese Schicht</div>
                </div>
                <md-icon-button class="planner-nu-select-close" onclick="plannerCloseNuAddOverlay()" title="Schließen"><md-icon>close</md-icon></md-icon-button>
            </div>
            <div class="planner-nu-select-body" id="plannerNuViewSelect">
                <div class="planner-nu-select-list" id="plannerNuSelectList"></div>
            </div>
            <div class="planner-nu-edit-body" id="plannerNuViewEdit" style="display:none">
                <div class="nu-positions-panel">
                    <div class="nu-positions-header">
                        <div class="nu-positions-header-left">
                            <span class="material-symbols-outlined">engineering</span>
                            <span class="nu-positions-title">NU-Positionen & Anforderungen</span>
                            <span class="nu-firma-badge" id="plannerNuFirmaLabel"></span>
                        </div>
                        <div class="nu-positions-header-right">
                            <md-outlined-button class="nu-add-btn" onclick="plannerAddNuPosition()"><i slot="icon" class="material-symbols-outlined">add</i> Position</md-outlined-button>
                            <md-icon-button class="nu-close-btn" id="plannerNuCloseBtn" onclick="plannerCloseNuPanel()" title="Zurück"><md-icon>arrow_back</md-icon></md-icon-button>
                        </div>
                    </div>
                    <div class="nu-positions-body" id="plannerNuPosList"></div>
                    <div class="nu-positions-footer">
                        <md-filled-button class="nu-submit-btn" onclick="plannerSaveNuPositions(window._plannerEinsatzId || '')"><i slot="icon" class="material-symbols-outlined">send</i> Anfrage absenden</md-filled-button>
                    </div>
                </div>
            </div>
        </div>`;
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) plannerCloseNuAddOverlay();
    });
    // Native <dialog>: Esc fires 'close' — same cleanup as the X button.
    overlay.addEventListener('close', () => {
        overlay.classList.remove('active');
        _nuDiscardPristineDraft();
        plannerUpdateNuAddCard();
    });
    document.body.appendChild(overlay);
    return overlay;
}

// Open the NU overlay and pick the right starting view:
//   'edit'    — a NU was picked but has no saved positions yet
//   'summary' — saved NU groups exist for this einsatz
//   'select'  — pick a partner (default; forced by "Weiterer NU")
function plannerOpenNuAddOverlay(einsatzId, forceSelect) {
    const overlay = ensureNuOverlay();
    if (!overlay) return;
    // 'edit' only for a live in-flight selection: positions drafted or the
    // NU already owns a saved group. A bare _nuEditingNuId with nothing
    // behind it is stale state → fall through to the partner list.
    const editingLive = _nuEditingNuId && (_nuPositions.length > 0
        || _nuGroups.some(g => String(g.nu_id) === String(_nuEditingNuId)));
    const view = forceSelect ? 'select'
        : (editingLive ? 'edit' : (_nuGroups.length > 0 ? 'summary' : 'select'));
    plannerNuOverlayShow(view);
    overlay.classList.add('active');
    // showModal() puts the dialog into the top layer — required because
    // #modalOverlay is an md-dialog; a plain z-index overlay can never
    // paint above it. Called BEFORE rendering so a renderer failure can
    // never leave the dialog silently closed.
    if (!overlay.open) overlay.showModal();
    if (view === 'select') plannerRenderNuSelectList();
    else if (view === 'summary') plannerRenderNuSummary();
    else plannerRenderNuEditMode();
}

// Fill the select view: only the list of available NU partners.
function plannerRenderNuSelectList() {
    const list = document.getElementById('plannerNuSelectList');
    if (!list) return;

    const subs = (window._plannerSubcontractors || []).filter(nu => {
        const alreadyUsed = _nuGroups.map(g => String(g.nu_id));
        return !alreadyUsed.includes(String(nu.nu_id));
    });

    list.innerHTML = subs.length
        ? `<div class="planner-nu-select-list-title">Verfügbare Nachunternehmer</div>
            <md-list class="planner-nu-select-options">${subs.map(nu => `
                <md-list-item type="button" class="planner-nu-select-item" onclick="plannerSelectNu('${escapeHtml(nu.nu_id)}')">
                    <span slot="start" class="planner-nu-select-avatar">${escapeHtml((nu.firmenname || '').substring(0, 2).toUpperCase())}</span>
                    <span slot="headline">${escapeHtml(nu.firmenname)}</span>
                    <span slot="supporting-text">${nu.bereich ? escapeHtml(nu.bereich) : 'Nachunternehmer'}</span>
                    <span slot="end" class="material-symbols-outlined planner-nu-select-arrow">chevron_right</span>
                </md-list-item>
            `).join('')}</md-list>`
        : '<div class="nu-empty" style="padding:30px 10px"><i class="material-symbols-outlined" style="font-size:2rem;color:var(--gray-400)">handshake</i><p style="margin-top:8px;color:var(--gray-500)">Keine weiteren Nachunternehmer verfügbar.</p></div>';
}

function plannerCloseNuAddOverlay() {
    const overlay = document.getElementById('plannerNuSelectOverlay');
    if (!overlay) return;
    _nuDiscardPristineDraft();
    plannerUpdateNuAddCard();
    overlay.classList.remove('active');
    if (overlay.open) overlay.close();
}

// If the in-flight NU draft was never touched (positions still equal the
// auto-filled baseline) and has no saved group, discard it on close/back —
// a merely-peeked partner must not linger as an unsent draft.
function _nuDiscardPristineDraft() {
    if (!_nuEditingNuId) return;
    if (_nuGroups.some(g => String(g.nu_id) === String(_nuEditingNuId))) return;
    if (JSON.stringify(_nuPositions) !== (window._nuPositionsBaseline || '')) return;
    _nuEditingNuId = null;
    _nuPositions = [];
}

async function plannerSelectNu(nuId) {
    const subs = window._plannerSubcontractors || [];
    const nu = subs.find(n => String(n.nu_id) === String(nuId));
    if (!nu) return;

    // In-flight selection only — the committed link (planner_nu_select /
    // _plannerSelectedNuId / add-card) is set when "Anfrage absenden"
    // actually succeeds, so a merely-clicked partner is never persisted.
    _nuEditingNuId = nuId;
    _nuFirmenname = nu.firmenname;
    _nuEinsatzId = window._plannerEinsatzId || '';
    _nuPositions = plannerBuildNuPositionsFromTask();
    window._nuPositionsBaseline = JSON.stringify(_nuPositions);

    const firmaLabel = document.getElementById('plannerNuFirmaLabel');
    if (firmaLabel) firmaLabel.textContent = nu.firmenname;
    plannerNuOverlayShow('edit');
    plannerRenderNuEditMode();
    startNuRefreshPolling(_nuEinsatzId);
}

function plannerStartNewNuRequest() {
    plannerOpenNuAddOverlay(window._plannerEinsatzId || '', true);
}

function plannerExpandNuGroup(nuId) {
    const group = _nuGroups.find(g => g.nu_id === nuId);
    if (!group) return;
    _nuEditingNuId = nuId;
    _nuFirmenname = group.firmenname;
    _nuPositions = group.positions.map(p => ({
        // Keep the DB id so the backend can diff (update in place) instead of
        // delete-all + re-insert — preserving the NU's worker zuweisungen.
        id: p.id,
        position_name: p.position_name || '',
        anzahl: p.anzahl || 1,
        geschlecht_mix: plannerParseGeschlechtMix(p.geschlecht_mix),
        geschlecht: p.geschlecht || '',
        qualifikation: p.qualifikation || '',
        skills: p.skills || '',
        kleidung: p.kleidung || '',
        checkin_info: p.checkin_info || '',
        bemerkung: p.bemerkung || '',
        zuweisungen: p.zuweisungen || []
    }));
    window._nuPositionsBaseline = JSON.stringify(_nuPositions);
    // Update the NU select
    const sel = document.getElementById('planner_nu_select');
    if (sel) sel.value = nuId;
    const firmaLabel = document.getElementById('plannerNuFirmaLabel');
    if (firmaLabel) firmaLabel.textContent = _nuFirmenname;
    plannerNuOverlayShow('edit');
    plannerRenderNuEditMode();
}

async function plannerDeleteNuGroup(nuId) {
    const ok = await mdConfirm('Alle Positionen für diesen Nachunternehmer löschen?', { title: 'NU-Positionen löschen', confirmLabel: 'Löschen' });
    if (!ok) return;
    const result = await apiPost(API.disposition + '/save-nu-positions', {
        einsatz_id: _nuEinsatzId,
        nu_id: nuId,
        positions: []
    });
    if (result && result.success) {
        showToast('Positionen gelöscht');
        _nuGroups = _nuGroups.filter(g => g.nu_id !== nuId);
        // Keep the committed link in sync: point to a remaining group or clear.
        const remainingNuId = _nuGroups.length > 0 ? _nuGroups[0].nu_id : null;
        const sel = document.getElementById('planner_nu_select');
        if (sel) sel.value = remainingNuId || '';
        window._plannerSelectedNuId = remainingNuId;
        plannerUpdateNuAddCard();
        const overlay = document.getElementById('plannerNuSelectOverlay');
        const overlayOpen = overlay && overlay.open;
        if (_nuGroups.length > 0) {
            if (overlayOpen) plannerNuOverlayShow('summary');
            plannerRenderNuSummary();
        } else {
            _nuEditingNuId = null;
            _nuPositions = [];
            if (overlayOpen) {
                plannerNuOverlayShow('select');
                plannerRenderNuSelectList();
            }
        }
        plannerInjectNuWorkerCards();
    }
}

function plannerRenderNuEditMode() {
    const container = document.getElementById('plannerNuViewEdit');
    if (container) {
        const headerRight = container.querySelector('.nu-positions-header-right');
        if (headerRight) headerRight.style.display = '';
        const footer = container.querySelector('.nu-positions-footer');
        if (footer) footer.style.display = '';
    }
    plannerRenderNuPositions();
}

function plannerRenderNuPositions() {
    const list = document.getElementById('plannerNuPosList');
    if (!list) return;

    // If we have groups but not editing, show summary
    if (!_nuEditingNuId && _nuGroups.length > 0) {
        plannerRenderNuSummary();
        return;
    }

    if (_nuPositions.length === 0) {
        list.innerHTML = '<div class="nu-empty-state"><div class="nu-empty-icon"><span class="material-symbols-outlined">assignment</span></div><div class="nu-empty-title">Keine Positionen definiert</div><div class="nu-empty-text">Definieren Sie Positionen und Anforderungen für den Nachunternehmer.</div><md-filled-button class="nu-empty-cta" onclick="plannerAddNuPosition()"><i slot="icon" class="material-symbols-outlined">add</i> Erste Position erstellen</md-filled-button></div>';
        return;
    }

    let html = '';
    // Back button if there are other groups
    if (_nuGroups.length > 0) {
        html += `<div style="margin-bottom:8px"><md-outlined-button onclick="plannerBackToNuSummary()"><i slot="icon" class="material-symbols-outlined">arrow_back</i> Zurück zur Übersicht</md-outlined-button></div>`;
    }

    _nuPositions.forEach((pos, idx) => {
        const zuweisungen = pos.zuweisungen || [];
        const anzahl = pos.anzahl || 1;
        const isFull = zuweisungen.length >= anzahl;
        const hasAssignments = zuweisungen.length > 0;

        let zugewiesenHtml = '';
        if (hasAssignments) {
            const statusClass = isFull ? 'complete' : 'partial';
            const statusIcon = isFull ? 'check_circle' : 'hourglass_top';
            zugewiesenHtml = `
            <div class="nu-assignment-status ${statusClass}">
                <div class="nu-assignment-status-header">
                    <span class="material-symbols-outlined">${statusIcon}</span> Zugewiesen ${zuweisungen.length}/${anzahl}
                </div>
                ${zuweisungen.map(z => {
                    const wName = ((z.vorname || '') + ' ' + (z.name || '')).trim();
                    return `<div class="nu-assigned-worker">
                        <span class="nu-assigned-worker-avatar">${escapeHtml(((z.vorname||'')[0]||'').toUpperCase())}${escapeHtml(((z.name||'')[0]||'').toUpperCase())}</span>
                        <span class="nu-assigned-worker-name">${escapeHtml(wName)}</span>
                        ${z.personal_nr ? '<span class="nu-assigned-worker-nr">' + escapeHtml(z.personal_nr) + '</span>' : ''}
                    </div>`;
                }).join('')}
            </div>`;
        } else {
            zugewiesenHtml = '';
        }

        html += `
        <div class="nu-position-card">
            <div class="nu-position-card-header">
                <span class="nu-position-badge">${idx + 1}</span>
                <input type="text" class="form-control" value="${escapeHtml(pos.position_name || '')}" placeholder="Position (z.B. Checker, Security)" style="flex:1" onchange="_nuPositions[${idx}].position_name=this.value">
                <input type="number" class="form-control nu-position-count-input" value="${pos.anzahl || 1}" min="1" title="Anzahl Mitarbeiter" onchange="_nuPositions[${idx}].anzahl=parseInt(this.value)||1">
                <md-icon-button class="nu-position-remove-btn" onclick="_nuPositions.splice(${idx},1);plannerRenderNuPositions()" title="Position entfernen"><md-icon>close</md-icon></md-icon-button>
            </div>
            <div class="nu-position-card-body">
                <div class="nu-position-fields">
                    <div class="nu-field-group">
                        <span class="nu-field-label">Qualifikation</span>
                        <input type="text" class="form-control" value="${escapeHtml(pos.qualifikation || '')}" placeholder="z.B. Erste Hilfe, Staplerschein" onchange="_nuPositions[${idx}].qualifikation=this.value">
                    </div>
                    <div class="nu-field-group">
                        <span class="nu-field-label">Skills</span>
                        <input type="text" class="form-control" value="${escapeHtml(pos.skills || '')}" placeholder="z.B. Deutsch, Englisch" onchange="_nuPositions[${idx}].skills=this.value">
                    </div>
                    <div class="nu-field-group">
                        <span class="nu-field-label">Kleidung</span>
                        <input type="text" class="form-control" value="${escapeHtml(pos.kleidung || '')}" placeholder="z.B. Schwarz, Anzug" onchange="_nuPositions[${idx}].kleidung=this.value">
                    </div>
                    <div class="nu-field-group">
                        <span class="nu-field-label">Geschlecht</span>
                        <div class="nu-geschlecht-mix">
                            <span class="nu-geschlecht-mix-part"><span class="nu-mix-sym" style="color:#2563eb">♂</span><input type="number" class="form-control" min="0" value="${(pos.geschlecht_mix && pos.geschlecht_mix.maennlich) || 0}" title="Männlich" onchange="plannerNuSetMix(${idx},'maennlich',this.value)"></span>
                            <span class="nu-geschlecht-mix-part"><span class="nu-mix-sym" style="color:#db2777">♀</span><input type="number" class="form-control" min="0" value="${(pos.geschlecht_mix && pos.geschlecht_mix.weiblich) || 0}" title="Weiblich" onchange="plannerNuSetMix(${idx},'weiblich',this.value)"></span>
                        </div>
                    </div>
                    <div class="nu-field-group">
                        <span class="nu-field-label">Check-in Info</span>
                        <input type="text" class="form-control" value="${escapeHtml(pos.checkin_info || '')}" placeholder="z.B. Eingang B, 06:00" onchange="_nuPositions[${idx}].checkin_info=this.value">
                    </div>
                </div>
                ${zugewiesenHtml}
            </div>
        </div>`;
    });
    list.innerHTML = html;
}

// Gender mix editor on a NU position card — updates the mix and derives the
// coarse geschlecht label (gemischt/maennlich/weiblich/'') for storage.
function plannerNuSetMix(idx, key, val) {
    const pos = _nuPositions[idx];
    if (!pos) return;
    const mix = pos.geschlecht_mix || { maennlich: 0, weiblich: 0 };
    mix[key] = Math.max(0, parseInt(val) || 0);
    pos.geschlecht_mix = mix;
    pos.geschlecht = plannerGeschlechtFromMix(mix);
}

function plannerBackToNuSummary() {
    _nuEditingNuId = null;
    plannerNuOverlayShow('summary');
    plannerRenderNuSummary();
}

function plannerCloseNuPanel() {
    _nuDiscardPristineDraft();
    plannerUpdateNuAddCard();
    const overlay = document.getElementById('plannerNuSelectOverlay');
    if (!overlay || !overlay.open) return;
    if (_nuGroups.length > 0) {
        _nuEditingNuId = null;
        plannerNuOverlayShow('summary');
        plannerRenderNuSummary();
    } else {
        _nuEditingNuId = null;
        plannerNuOverlayShow('select');
        plannerRenderNuSelectList();
    }
}

function plannerAddNuPosition() {
    if (!_nuEditingNuId) {
        // If in summary mode, start editing the currently selected NU
        const sel = document.getElementById('planner_nu_select');
        if (sel && sel.value) {
            _nuEditingNuId = sel.value;
            const nuMeta = (window._plannerSubcontractors || []).find(n => String(n.nu_id) === String(_nuEditingNuId));
            _nuFirmenname = nuMeta ? nuMeta.firmenname : '';
            _nuPositions = [];
        } else {
            showToast('Bitte zuerst einen Nachunternehmer auswählen', 'error');
            return;
        }
    }
    _nuPositions.push({
        position_name: '',
        anzahl: 1,
        qualifikation: '',
        skills: '',
        kleidung: '',
        checkin_info: '',
        bemerkung: ''
    });
    plannerRenderNuEditMode();
}

async function plannerCancelNu(einsatzId) {
    const nuSelect = document.getElementById('planner_nu_select');
    const selectedNuId = nuSelect?.value || null;
    const nuGroup = _nuGroups.find(g => String(g.nu_id) === String(selectedNuId));
    const nuName = nuGroup?.firmenname || _nuFirmenname || 'Nachunternehmer';
    const ok = await mdConfirm(`Nachunternehmer "<strong>${escapeHtml(nuName)}</strong>" stornieren?<br><br>Alle NU-Positionen und Zuweisungen dieses Nachunternehmers für diesen Einsatz werden entfernt.`, { title: 'NU stornieren', confirmLabel: 'Stornieren' });
    if (!ok) return;

    // Cancel ONLY the selected NU's group. Previously this looped over ALL
    // groups — with several NUs on one shift, cancelling one silently
    // cancelled every other NU too (and emailed them all a Stornierung).
    const groupsToCancel = selectedNuId
        ? _nuGroups.filter(g => String(g.nu_id) === String(selectedNuId))
        : _nuGroups;
    for (const group of groupsToCancel) {
        await apiPost(API.disposition + '/save-nu-positions', {
            einsatz_id: einsatzId,
            nu_id: group.nu_id,
            positions: []
        });
    }
    const cancelledIds = new Set(groupsToCancel.map(g => String(g.nu_id)));
    _nuGroups = _nuGroups.filter(g => !cancelledIds.has(String(g.nu_id)));
    _nuPositions = [];
    _nuEditingNuId = null;

    // Keep the einsatz linked to a remaining NU (if any), otherwise clear it.
    const remainingNuId = _nuGroups.length > 0 ? _nuGroups[0].nu_id : null;

    // Update NU select + overlay view to reflect the remaining state
    if (nuSelect) nuSelect.value = remainingNuId || '';
    window._plannerSelectedNuId = remainingNuId;
    plannerUpdateNuAddCard();
    const overlay = document.getElementById('plannerNuSelectOverlay');
    if (overlay && overlay.open) {
        if (_nuGroups.length > 0) {
            plannerNuOverlayShow('summary');
            plannerRenderNuSummary();
        } else {
            plannerNuOverlayShow('select');
            plannerRenderNuSelectList();
        }
    } else if (_nuGroups.length > 0) {
        plannerRenderNuSummary();
    }

    // Save assignment with the remaining NU (or null when none is left)
    const workerIds = [...document.querySelectorAll('#plannerAssignedList .planner-worker-item:not(.planner-nu-worker-card)')].map(item => item.getAttribute('data-worker-id')).filter(Boolean);

    const result = await apiPost(API.disposition + '/save-assignment', {
        einsatz_id: einsatzId,
        worker_ids: workerIds,
        worker_hours: collectWorkerHours(),
        worker_tasks: collectWorkerTasks(),
        nu_id: remainingNuId
    });

    if (result.success) {
        // Re-render NU worker cards from the remaining groups only
        plannerInjectNuWorkerCards();
        showToast('Nachunternehmer storniert');
        loadDisposition();
    } else {
        showToast(result.error || 'Stornierung fehlgeschlagen', 'error');
    }
}

async function plannerSaveNuPositions(einsatzId) {
    const nuId = _nuEditingNuId || document.getElementById('planner_nu_select')?.value;
    if (!nuId) { showToast('Bitte zuerst einen Nachunternehmer auswählen', 'error'); return; }

    // Remove completely empty positions (no name and no other data)
    _nuPositions = _nuPositions.filter(p => {
        const hasName = p.position_name && p.position_name.trim();
        const hasData = p.qualifikation || p.skills || p.kleidung || p.checkin_info || p.bemerkung || p.geschlecht || p.geschlecht_mix;
        return hasName || hasData;
    });

    // Validate — positions with data but no name
    for (let i = 0; i < _nuPositions.length; i++) {
        if (!_nuPositions[i].position_name || !_nuPositions[i].position_name.trim()) {
            plannerRenderNuPositions();
            const nameInputs = document.querySelectorAll('.nu-position-card-header .form-control');
            if (nameInputs[i]) { nameInputs[i].style.borderColor = '#ef4444'; nameInputs[i].focus(); }
            showToast('Position ' + (i + 1) + ': Name ist erforderlich', 'error');
            return;
        }
    }

    if (_nuPositions.length === 0) {
        showToast('Bitte mindestens eine Position hinzufügen', 'error');
        return;
    }

    // Capacity guard — warn before requesting a NU when the shift's required
    // headcount is already covered (internal workers + other NU requests).
    const sollMenge = parseInt(window._plannerSollMenge) || 0;
    if (sollMenge > 0) {
        const internalAssigned = document.querySelectorAll('#plannerAssignedList .planner-worker-item:not(.planner-nu-worker-card)').length;
        const otherNuTotal = _nuGroups
            .filter(g => String(g.nu_id) !== String(nuId))
            .reduce((s, g) => s + (g.positions || []).reduce((s2, p) => s2 + (parseInt(p.anzahl) || 0), 0), 0);
        const newNuTotal = _nuPositions.reduce((s, p) => s + (parseInt(p.anzahl) || 0), 0);
        if (internalAssigned + otherNuTotal + newNuTotal > sollMenge) {
            const msg = internalAssigned >= sollMenge
                ? `Alle Plätze dieser Schicht sind bereits vergeben (${internalAssigned}/${sollMenge}).\n\nAnfrage an den Nachunternehmer trotzdem senden?`
                : `Diese Anfrage überschreitet die benötigte Belegschaft:\n${internalAssigned} zugewiesen + ${otherNuTotal + newNuTotal} angefragt / ${sollMenge} benötigt.\n\nTrotzdem senden?`;
            const ok = await mdConfirm(msg, { title: 'Schicht voll besetzt', confirmLabel: 'Trotzdem senden' });
            if (!ok) return;
        }
    }

    const result = await apiPost(API.disposition + '/save-nu-positions', {
        einsatz_id: einsatzId,
        nu_id: nuId,
        positions: _nuPositions
    });

    if (result && result.success) {
        // email_sent is only true when NEW positions triggered a fresh NU
        // request — pure edits update silently (assignments are preserved).
        showToast(result.email_sent
            ? 'Anfrage gesendet (' + (result.count || 0) + ' Positionen)'
            : 'Positionen aktualisiert (' + (result.count || 0) + ' Positionen)');
        // Reload all groups and show summary inside the overlay
        _nuEditingNuId = null;
        const allResult = await apiGet(API.disposition + '/nu-positions?einsatz_id=' + einsatzId);
        if (allResult && allResult.groups) {
            _nuGroups = allResult.groups;
            _nuKundenname = allResult.kundenname || '';
            _nuObjektname = allResult.objektname || '';
        }
        _nuGroupsLoaded = true;
        // Request was actually sent — commit the NU link now.
        const sel = document.getElementById('planner_nu_select');
        if (sel) sel.value = nuId;
        window._plannerSelectedNuId = nuId;
        plannerUpdateNuAddCard();
        const overlay = document.getElementById('plannerNuSelectOverlay');
        if (overlay && overlay.open) plannerNuOverlayShow('summary');
        plannerRenderNuSummary();
        plannerInjectNuWorkerCards();
        loadDisposition();
    } else {
        showToast(result?.error || 'Fehler beim Speichern', 'error');
    }
}
