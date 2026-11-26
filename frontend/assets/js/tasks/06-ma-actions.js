// tasks/06-ma-actions.js — Monatsabschluss: Zeit-Freigabe, Helfer, Filter, Bearbeiten, Excel-Export
// Teil 6/10 des früheren tasks.js. Alle Teile teilen den globalen Scope und MÜSSEN in dieser Reihenfolge geladen werden (PAGE_MODULE_SCRIPTS in app.js).

// ============================================================
// ZEIT-FREIGABE — admin confirmation of recorded times
// ============================================================
async function maToggleFreigabe(einsatzId, dienstNr) {
    const worker = _maPageWorkers[dienstNr];
    if (!worker) return;
    const entry = worker.entries.find(e => String(e.einsatz_id) === String(einsatzId));
    if (!entry) return;

    const target = parseInt(entry.zeit_freigabe) !== 1;
    const res = await apiPost(API.disposition + '/zeit-freigabe', {
        dienst_nr: dienstNr, einsatz_ids: [einsatzId], freigabe: target
    });
    if (!res || !res.success) {
        showToast(res && res.error ? res.error : 'Freigabe fehlgeschlagen', 'error');
        return;
    }
    entry.zeit_freigabe = target ? 1 : 0;
    maRenderBlocks({ keepEditing: true });
    showToast(target ? 'Für die Mitarbeiter-App freigegeben' : 'Mitarbeiter-Freigabe zurückgenommen');
}

// ============================================================
// KUNDEN-FREIGABE — Modus "Kunde": values for the Leistungsnachweis
// (Bestellungen), independent of the Mitarbeiter-Freigabe above.
// ============================================================
function maSetMode(mode) {
    if (mode !== 'ma' && mode !== 'kunde') return;
    _maMode = mode;
    if (!_maData) { _maTab = 'overview'; renderMonatsabschlussContent(); return; }
    maRefreshToolbar();
    // Bento sits outside the tab body — its mode-dependent tile must swap now.
    maMorphInner(document.getElementById('maBentoWrap'), maBuildBento(_maData));
    if (_maTab !== 'overview') { _maTab = 'overview'; maRefreshToolbar(); maShowActiveTab(); return; }
    maRenderBlocks();
}

/** The real _maData entry behind a table row (internal or NU). */
function maRawEntryForRow(tr) {
    if (!_maData || !tr) return null;
    const eid = String(tr.dataset.einsatzId || '');
    if (tr.dataset.nuWorkerId) {
        return (_maData.nu_entries || []).find(e => String(e.einsatz_id) === eid
            && String(e.nu_worker_id) === tr.dataset.nuWorkerId
            && (!tr.dataset.positionId || String(e.position_id) === tr.dataset.positionId)) || null;
    }
    return (_maData.entries || []).find(e => String(e.einsatz_id) === eid
        && String(e.dienst_nr) === String(tr.dataset.dienstNr || '')) || null;
}

/**
 * POST the Kunden-Freigabe for the given rows of ONE worker block.
 * Rows without any recorded time are refused by the server (409
 * code 'ohne_zeit') — they would be billed with the PLAN hours. The office
 * then decides: one row → approve with plan hours or cancel; several rows
 * → approve only the rows with a recorded time or cancel.
 */
async function maKundenFreigabeRows(rows, target) {
    let entries = rows.map(maRawEntryForRow).filter(Boolean);
    if (!entries.length) return false;
    const first = rows[0];
    const payload = { freigabe: target };
    if (first.dataset.nuWorkerId) {
        payload.nu_worker_id = first.dataset.nuWorkerId;
        payload.position_ids = [...new Set(entries.map(e => e.position_id).filter(v => v != null))];
    } else {
        payload.dienst_nr = first.dataset.dienstNr;
        payload.einsatz_ids = [...new Set(entries.map(e => e.einsatz_id))];
    }
    let res = await apiPost(API.disposition + '/kunden-freigabe', payload);
    if (res && res.code === 'ohne_zeit' && Array.isArray(res.ohne_zeit)) {
        const list = res.ohne_zeit.map(r => escapeHtml(wpFormatDate(r.datum))).join(', ');
        const single = payload.einsatz_ids && payload.einsatz_ids.length === 1;
        const ok = await systemConfirm(single ? {
            title: 'Keine Zeit erfasst',
            message: `Für den ${list} wurde keine Arbeitszeit erfasst (keine App-Stempelung, kein Büro-Eintrag).<br>Trotzdem mit den <strong>Planstunden</strong> für den Kunden freigeben?`,
            confirmText: 'Mit Planstunden freigeben',
        } : {
            title: `${res.ohne_zeit.length} Einsätze ohne erfasste Zeit`,
            message: `Ohne Arbeitszeit: ${list}.<br>Nur die Einsätze <strong>mit</strong> erfasster Zeit freigeben? Die übrigen bleiben offen — Zeit über das Stift-Symbol in der Zeile eintragen und dann freigeben.`,
            confirmText: 'Nur mit Zeit freigeben',
        });
        if (!ok) return false;
        payload.ohne_zeit = single ? 'plan' : 'auslassen';
        res = await apiPost(API.disposition + '/kunden-freigabe', payload);
    }
    if (!res || !res.success) {
        showToast(res && res.error ? res.error : 'Kunden-Freigabe fehlgeschlagen', 'error');
        return false;
    }
    // The server reports which internal Einsätze it really changed
    // (skipped rows without time and storno-locked shifts are missing).
    if (target && payload.dienst_nr && Array.isArray(res.einsatz_ids)) {
        const done = new Set(res.einsatz_ids.map(String));
        entries = entries.filter(e => done.has(String(e.einsatz_id)));
        if (res.ausgelassen > 0) showToast(`${res.ausgelassen} Einsatz/Einsätze ohne erfasste Zeit nicht freigegeben`, 'error');
        if (!entries.length) return false;
    }
    const has = v => v != null && v !== '';
    entries.forEach(e => {
        e.kunden_freigabe = target ? 1 : 0;
        if (!target) return;
        // Same snapshot as the server: shown values are frozen on approval.
        if (!has(e.kunden_start)) e.kunden_start = e.em_ist_start || e.ist_start || null;
        if (!has(e.kunden_ende))  e.kunden_ende = e.em_ist_ende || e.ist_ende || null;
        // One customer pause: kept if set, else the Planung pause; Std re-based like the server.
        if (!has(e.kunden_std))   e.kunden_std = maCalcEntryStd(maKundeEntry(e)) || null;
        if (!has(e.kunden_pause)) e.kunden_pause = maPlanPause(e);
    });
    return entries.length;
}

async function maToggleKundenFreigabe(btn) {
    const tr = btn && btn.closest('tr[data-einsatz-id]');
    const entry = maRawEntryForRow(tr);
    if (!entry) return;
    const target = parseInt(entry.kunden_freigabe) !== 1;
    if (!(await maKundenFreigabeRows([tr], target))) return;
    maRenderBlocks({ keepEditing: true });
    showToast(target ? 'Für den Kunden freigegeben → Bestellungen' : 'Kunden-Freigabe zurückgenommen');
}

// ============================================================
// HELPERS
// ============================================================

// Epoch ms of a Europe/Berlin wall-clock time — independent of the office
// PC's own timezone. Two passes settle the offset across a DST switch.
function maBerlinEpoch(dateIso, hhmm) {
    const [y, mo, d] = dateIso.split('-').map(Number);
    const [h, mi] = hhmm.split(':').map(Number);
    const guess = Date.UTC(y, mo - 1, d, h, mi);
    const fmt = new Intl.DateTimeFormat('en-US', { timeZone: 'Europe/Berlin', hourCycle: 'h23',
        year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const offsetAt = (ms) => {
        const p = Object.fromEntries(fmt.formatToParts(new Date(ms)).map(x => [x.type, x.value]));
        return Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour % 24, +p.minute, +p.second) - ms;
    };
    let ms = guess - offsetAt(guess);
    ms = guess - offsetAt(ms);
    return ms;
}

// Worked span in minutes for Von/Bis on the shift's date: a Bis before Von
// ends the next day, and the real clock is used — the night of the October
// DST switch has one hour more (18:00–06:00 = 13 h), the March night one
// hour less, exactly like the app's own clock-in/out. Without a date the
// plain wall-clock difference is used.
function maShiftSpanMin(datum, von, bis) {
    const [vh, vm] = von.split(':').map(Number);
    const [bh, bm] = bis.split(':').map(Number);
    let wall = (bh * 60 + bm) - (vh * 60 + vm);
    if (wall < 0) wall += 1440;
    const day = String(datum || '').substring(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(day) || typeof Intl === 'undefined') return wall;
    try {
        const [y, mo, d] = day.split('-').map(Number);
        const endDay = (bh * 60 + bm) < (vh * 60 + vm)
            ? new Date(Date.UTC(y, mo - 1, d + 1)).toISOString().substring(0, 10) : day;
        const real = Math.round((maBerlinEpoch(endDay, bis) - maBerlinEpoch(day, von)) / 60000);
        return real >= 0 && Math.abs(real - wall) <= 60 ? real : wall;
    } catch (_) {
        return wall;
    }
}

function maCalcEntryStd(e) {
    // ist_std <= 0 means "not recorded" — falls back to planned hours,
    // identical to the Controlling billing side (NULLIF(ist_std,0)).
    if (e.ist_std != null && e.ist_std !== '' && parseFloat(e.ist_std) > 0) return parseFloat(e.ist_std);
    // Shift falls on an approved full-day absence (Urlaub/Krank/…) and
    // nothing was recorded — the plan hours are paid as absence credit
    // (Abwesenheiten block), not as worked shift hours. Kunde mode projects
    // kunden_std into ist_std first, so an office-set LN value still wins.
    if (e.abwesenheit_covered) return 0;
    return e.ist_std_menge ? parseFloat(e.ist_std_menge) : 0;
}

// Human-readable period label for PDFs / CSVs / messages.
// Uses month name in single-month mode, or a "TT.MM.JJJJ – TT.MM.JJJJ" span in range mode.
function maCurrentPeriodLabel() {
    if (_maRangeMode && _maRangeStart && _maRangeEnd) {
        return wpFormatDate(_maRangeStart) + ' – ' + wpFormatDate(_maRangeEnd);
    }
    return MONTHS_FULL[_maPageMonth] + ' ' + _maPageYear;
}

function maCalcWorkerTotal(entries) {
    return entries.reduce((sum, e) => sum + maCalcEntryStd(e), 0);
}

// Today in Europe/Berlin as YYYY-MM-DD (shift dates are local dates).
function maTodayIso() {
    return new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Berlin' });
}

// Checks run on the WORKER's really recorded time (app clock or office
// entry) — never on the plan fallback, which would hide missing records.
// ArbZG limits concern the worker, so the Kunde projection is ignored.
function maDetectAnomalies(entry) {
    const e = entry._ma || entry;
    const anomalies = [];
    if (entry.abw_aber_gearbeitet) {
        anomalies.push(`Abwesenheit „${entry.abwesenheit || 'Abwesenheit'}“ eingetragen, aber gearbeitet`);
    }
    const std = maIstStd(e);
    if (std == null) {
        // Internal workers only: they clock in the app, so a past shift
        // without any record is a no-show / forgotten stamp. NU times are
        // entered by the office and often stay on the plan by design.
        const running = !!e.app_clock_in && !e.app_clock_out;
        if (!e.nu_worker_id && !running && e.datum && e.datum < maTodayIso() && !entry.abwesenheit_covered) anomalies.push('Keine Zeit erfasst');
        return anomalies;
    }
    const pause = e.em_pause != null && e.em_pause !== '' ? (parseInt(e.em_pause) || 0) : (parseInt(e.pause_minuten) || 0);
    if (std > MA_ARBZG_MAX_HRS) {
        anomalies.push(`Über ${MA_ARBZG_MAX_HRS} h Arbeitszeit (${maFmtHrs(std)} h, § 3 ArbZG)`);
    }
    const rule = MA_ARBZG_PAUSE.find(([hrs]) => std > hrs);
    if (rule && pause < rule[1]) {
        anomalies.push(`Pause zu kurz (${pause} Min bei ${maFmtHrs(std)} h, mind. ${rule[1]} Min – § 4 ArbZG)`);
    }
    return anomalies;
}

function maSortWorkers(workers) {
    const sorted = [...workers];
    sorted.sort((a, b) => {
        if (_maSort === 'std') {
            return maCalcWorkerTotal(b.entries) - maCalcWorkerTotal(a.entries);
        }
        if (_maSort === 'entries') {
            return b.entries.length - a.entries.length;
        }
        if (_maSort === 'bereich') {
            return ((a.bereich || 'zz') + '').localeCompare(b.bereich || 'zz');
        }
        // default: name
        const nameA = ((a.vorname || '') + ' ' + (a.name || '')).trim() || a.dienst_nr || '';
        const nameB = ((b.vorname || '') + ' ' + (b.name || '')).trim() || b.dienst_nr || '';
        return nameA.localeCompare(nameB);
    });
    return sorted;
}

// ============================================================
// FILTERS + NAVIGATION
// ============================================================
function maAllEntries() {
    const all = [];
    Object.values(_maPageWorkers || {}).forEach(w => all.push(...(w.entries || [])));
    Object.values(_maPageNuWorkers || {}).forEach(g =>
        Object.values(g).forEach(w => all.push(...(w.entries || []))));
    return all;
}

/** Does the worker have at least one entry matching the active scope? */
function maWorkerScopeMatch(worker) {
    const es = worker.entries || [];
    if (_maFilterKunde && !es.some(e => (e.kundenname || '').trim() === _maFilterKunde)) return false;
    if (_maFilterObjekt && !es.some(e => (e.objektname || '').trim() === _maFilterObjekt)) return false;
    return true;
}

/**
 * Kunde/Objekt option lists for the toolbar selects — linked like the Dispo
 * filterbar: picking a Kunde narrows the Objekt list to that customer's
 * objects (and vice versa); a single remaining counterpart is auto-selected
 * so its name shows in the box. Auto-fill never touches the field the user
 * just cleared (_maLinkedSrc) — otherwise "Alle …" would snap back.
 */
function maResolveScopeLists() {
    const all = maAllEntries();
    const kOf = e => (e.kundenname || '').trim();
    const oOf = e => (e.objektname || '').trim();
    const uniq = arr => [...new Set(arr.map(v => v.trim()).filter(Boolean))]
        .sort((a, b) => a.localeCompare(b, 'de'));
    const allKunden  = uniq(all.map(kOf));
    const allObjekte = uniq(all.map(oOf));
    const lists = () => ({
        kunden:  _maFilterObjekt ? uniq(all.filter(e => oOf(e) === _maFilterObjekt).map(kOf)) : allKunden,
        objekte: _maFilterKunde  ? uniq(all.filter(e => kOf(e) === _maFilterKunde).map(oOf)) : allObjekte,
    });
    const src = _maLinkedSrc;
    let kunden, objekte;
    for (let i = 0; i < 4; i++) {
        const pk = _maFilterKunde, po = _maFilterObjekt;
        ({ kunden, objekte } = lists());
        if (_maFilterObjekt && !objekte.includes(_maFilterObjekt)) _maFilterObjekt = '';
        if (_maFilterKunde && !kunden.includes(_maFilterKunde)) _maFilterKunde = '';
        if (pk === _maFilterKunde && po === _maFilterObjekt) break;
    }
    return lists();
}

/** Select onchange: store the scope filter, rebuild the toolbar (so the
 *  linked counterpart's options refresh) and re-apply visibility. */
function maScopeChange(which, value) {
    _maLinkedSrc = which;
    if (which === 'kunde') _maFilterKunde = value; else _maFilterObjekt = value;
    maRefreshToolbar();
    maRenderQuickJump();
    maApplyFilters();
}

function maApplyFilters() {
    const q = (_maSearch || '').toLowerCase().trim();
    const scopeActive = !!(_maFilterKunde || _maFilterObjekt);
    document.querySelectorAll('.ma-worker-block').forEach(block => {
        // Kunde mode Objekt blocks: filter the worker ROWS, hide empty blocks.
        if (block.dataset.byObj === '1') {
            let any = false;
            const objName = (block.dataset.objekt || '').toLowerCase();
            block.querySelectorAll('tbody tr[data-einsatz-id]').forEach(tr => {
                let ok = true;
                if (_maFilter === 'internal' && tr.dataset.rowNu === '1') ok = false;
                if (_maFilter === 'nu' && tr.dataset.rowNu !== '1') ok = false;
                if (_maFilter === 'anomaly' && tr.dataset.rowAnomaly !== '1') ok = false;
                if (_maFilter === 'abwesenheit' && tr.dataset.rowAbw !== '1') ok = false;
                if (q && !(tr.dataset.rowName || '').includes(q) && !objName.includes(q)) ok = false;
                if (_maFilterKunde && (tr.dataset.rowKunde || '').trim() !== _maFilterKunde) ok = false;
                if (_maFilterObjekt && (block.dataset.objekt || '').trim() !== _maFilterObjekt) ok = false;
                tr.style.display = ok ? '' : 'none';
                if (ok) any = true;
            });
            block.style.display = any ? '' : 'none';
            return;
        }
        const name = block.dataset.workerName || '';
        const hasAnomaly = block.dataset.hasAnomaly === '1';
        const isNu = block.dataset.isNu === '1';

        let show = true;
        if (_maFilter === 'internal' && isNu) show = false;
        if (_maFilter === 'nu' && !isNu) show = false;
        if (_maFilter === 'anomaly' && !hasAnomaly) show = false;
        if (_maFilter === 'abwesenheit' && block.dataset.hasAbw !== '1') show = false;
        if (q && !name.includes(q)) show = false;
        if (show && scopeActive) {
            let wk = [], wo = [];
            try {
                wk = JSON.parse(block.dataset.kunden || '[]');
                wo = JSON.parse(block.dataset.objekte || '[]');
            } catch (e) { /* keep */ }
            if (_maFilterKunde && !wk.map(v => (v || '').trim()).includes(_maFilterKunde)) show = false;
            if (_maFilterObjekt && !wo.map(v => (v || '').trim()).includes(_maFilterObjekt)) show = false;
        }

        block.style.display = show ? '' : 'none';
    });

    // Hide NU dividers if all their blocks are hidden
    document.querySelectorAll('.ma-nu-divider').forEach(divider => {
        let nextBlocks = [];
        let next = divider.nextElementSibling;
        while (next && next.classList.contains('ma-worker-block') && next.dataset.isNu === '1') {
            nextBlocks.push(next);
            next = next.nextElementSibling;
        }
        const anyVisible = nextBlocks.some(b => b.style.display !== 'none');
        divider.style.display = anyVisible ? '' : 'none';
    });
}

function maSetFilter(filter) {
    _maFilter = filter;
    // md-filter-chip: drive selection via the .selected property (exactly one active).
    document.querySelectorAll('#maFilterPills md-filter-chip').forEach(c => { c.selected = (c.dataset.filter === filter); });
    if (_maTab === 'analytics') { _maTab = 'overview'; if (_maData) { maRefreshToolbar(); maShowActiveTab(); } else renderMonatsabschlussContent(); return; }
    maRenderQuickJump();
    maApplyFilters();
}

function maSetTab(tab) {
    _maTab = tab;
    if (!_maData) { renderMonatsabschlussContent(); return; }
    maRefreshToolbar();
    maShowActiveTab();
}

function maJumpTo(blockId) {
    const block = document.getElementById(blockId);
    if (!block) return;
    block.scrollIntoView({ behavior: 'smooth', block: 'start' });
    block.style.transition = 'all 0.4s ease';
    const origBorder = block.style.boxShadow;
    block.style.boxShadow = '0 0 0 4px var(--ma-accent-soft), var(--ma-shadow-lg)';
    setTimeout(() => { block.style.boxShadow = origBorder; }, 1500);
}

// ============================================================
// ROW EDIT — one Einsatz at a time (block-wide editing removed).
// The pencil toggles the row's inputs; save/cancel live in the
// same cell. Only one row may be open for editing at a time.
// ============================================================
function maToggleRowEdit(btn) {
    const row = btn.closest('tr[data-einsatz-id]');
    if (!row) return;
    document.querySelectorAll('tr.ma-row-editing').forEach(r => { if (r !== row) maCancelRowEditEl(r); });
    row.classList.add('ma-row-editing');
    row.querySelector('.ma-row-edit-btn').style.display = 'none';
    row.querySelector('.ma-row-save-btn').style.display = '';
    row.querySelector('.ma-row-cancel-btn').style.display = '';
    row.querySelectorAll('.ma-display').forEach(el => el.style.display = 'none');
    row.querySelectorAll('.ma-input').forEach(el => {
        el.style.display = '';
        el.dataset.original = el.value;
    });
    const diffEl = row.querySelector('.ma-cell-diff .ma-diff');
    if (diffEl) { diffEl.dataset.originalText = diffEl.textContent; diffEl.dataset.originalClass = diffEl.className; }
    maBindRowRecalc(row);
    // A row without recorded time opens with the plan times — show the
    // resulting Std and Diff right away instead of an empty field.
    const stdIn = row.querySelector('.ma-input[data-field="std"]');
    if (stdIn && stdIn.value === '' && row._maRecalc) row._maRecalc();
    const vonIn = row.querySelector('.ma-input[data-field="von"]');
    if (vonIn) vonIn.focus();
}

// Von/Bis/Pause → Std auto-recalc, bound once per row.
function maBindRowRecalc(row) {
    if (row._recalcBound) return;
    row._recalcBound = true;
    const vonIn = row.querySelector('.ma-input[data-field="von"]');
    const bisIn = row.querySelector('.ma-input[data-field="bis"]');
    const pauseIn = row.querySelector('.ma-input[data-field="pause"]');
    const stdIn = row.querySelector('.ma-input[data-field="std"]');
    if (!stdIn) return;
    const entry = maRawEntryForRow(row);
    const diffEl = row.querySelector('.ma-cell-diff .ma-diff');
    const recalc = () => {
        const v = vonIn ? vonIn.value : '';
        const b = bisIn ? bisIn.value : '';
        if (v && b) {
            const totalMin = maShiftSpanMin(entry && entry.datum, v, b);
            const pMin = pauseIn ? parseInt(pauseIn.value) || 0 : 0;
            const std = Math.max(0, totalMin - pMin) / 60;
            stdIn.value = std.toFixed(2);
            // Live Diff against the Soll while editing (same format as the row).
            const soll = entry ? maSollStd(entry) : 0;
            if (diffEl && soll > 0) {
                const d = std - soll;
                diffEl.className = 'ma-diff' + (Math.abs(d) < 0.005 ? '' : (d > 0 ? ' pos' : ' neg'));
                diffEl.textContent = Math.abs(d) < 0.005 ? '±0' : (d > 0 ? '+' : '−') + maFmtHrs(Math.abs(d));
            }
        }
    };
    row._maRecalc = recalc;
    if (vonIn) vonIn.addEventListener('input', recalc);
    if (bisIn) bisIn.addEventListener('input', recalc);
    if (pauseIn) pauseIn.addEventListener('input', recalc);
    // Keyboard: Enter saves, Escape cancels.
    row.addEventListener('keydown', ev => {
        if (ev.key === 'Escape') { ev.preventDefault(); maCancelRowEditEl(row); }
        else if (ev.key === 'Enter') {
            ev.preventDefault();
            const sb = row.querySelector('.ma-row-save-btn');
            if (sb) maSaveRowEdit(sb);
        }
    });
}

function maCancelRowEdit(btn) { maCancelRowEditEl(btn.closest('tr')); }
function maCancelRowEditEl(row) {
    if (!row) return;
    row.classList.remove('ma-row-editing');
    const editBtn = row.querySelector('.ma-row-edit-btn');
    const saveBtn = row.querySelector('.ma-row-save-btn');
    const cancelBtn = row.querySelector('.ma-row-cancel-btn');
    if (editBtn) editBtn.style.display = '';
    if (saveBtn) saveBtn.style.display = 'none';
    if (cancelBtn) cancelBtn.style.display = 'none';
    row.querySelectorAll('.ma-input').forEach(el => {
        el.value = el.dataset.original || '';
        el.style.display = 'none';
    });
    const diffEl = row.querySelector('.ma-cell-diff .ma-diff');
    if (diffEl && diffEl.dataset.originalClass !== undefined) {
        diffEl.textContent = diffEl.dataset.originalText;
        diffEl.className = diffEl.dataset.originalClass;
    }
    row.querySelectorAll('.ma-display').forEach(el => el.style.display = '');
}

// Build the save payload + local patch for ONE row (null when nothing changed).
function maRowPayload(row, kunde) {
    const eid = row.dataset.einsatzId;
    const dienstNr = row.dataset.dienstNr || '';
    const nuWorkerId = row.dataset.nuWorkerId || '';
    const positionId = row.dataset.positionId || '';
    const vonInput = row.querySelector('.ma-input[data-field="von"]');
    const bisInput = row.querySelector('.ma-input[data-field="bis"]');
    const stdInput = row.querySelector('.ma-input[data-field="std"]');
    const pauseInput = row.querySelector('.ma-input[data-field="pause"]');

    const changed = (vonInput && vonInput.value !== vonInput.dataset.original)
        || (bisInput && bisInput.value !== bisInput.dataset.original)
        || (stdInput && stdInput.value !== stdInput.dataset.original)
        || (pauseInput && pauseInput.value !== pauseInput.dataset.original);
    if (!changed) return null;

    // Kunde mode writes ONLY the customer values (kunden_*) — the
    // worker's Ist and the worker app stay untouched.
    const stdVal = stdInput ? (stdInput.value === '' ? null : (parseFloat(stdInput.value) || 0)) : undefined;
    const payload = kunde ? {} : {
        ist_std_menge: stdInput ? parseFloat(stdInput.value) || 0 : null
    };
    if (kunde) {
        if (vonInput) payload.kunden_start = vonInput.value || null;
        if (bisInput) payload.kunden_ende = bisInput.value || null;
        if (pauseInput) payload.kunden_pause = parseInt(pauseInput.value) || 0;
        if (stdInput) payload.kunden_std = stdVal;
    } else {
        if (vonInput) payload.ist_start = vonInput.value || null;
        if (bisInput) payload.ist_ende = bisInput.value || null;
        if (pauseInput) payload.pause_minuten = parseInt(pauseInput.value) || 0;
    }
    if (dienstNr) payload.dienst_nr = dienstNr;
    if (nuWorkerId) { payload.nu_worker_id = nuWorkerId; payload.position_id = positionId; }

    const patch = kunde ? {
        eid, kunde: true,
        isNu: !!nuWorkerId,
        nuWorkerId: nuWorkerId || null,
        positionId: positionId || null,
        dienstNr: dienstNr || null,
        kunden_start: payload.kunden_start,
        kunden_ende: payload.kunden_ende,
        kunden_pause: payload.kunden_pause,
        kunden_std: payload.kunden_std,
    } : {
        eid,
        isNu: !!nuWorkerId,
        nuWorkerId: nuWorkerId || null,
        positionId: positionId || null,
        dienstNr: dienstNr || null,
        ist_start: vonInput ? (vonInput.value || null) : undefined,
        ist_ende:  bisInput ? (bisInput.value || null) : undefined,
        pause_minuten: pauseInput ? (parseInt(pauseInput.value) || 0) : undefined,
        ist_std_menge: stdInput ? (parseFloat(stdInput.value) || 0) : undefined,
    };
    return { payload: Object.assign({ einsatz_id: eid }, payload), patch };
}

async function maSaveRowEdit(btn) {
    const row = btn.closest('tr[data-einsatz-id]');
    if (!row) return;
    const block = row.closest('.ma-worker-block');
    const kunde = _maMode === 'kunde';
    const built = maRowPayload(row, kunde);
    if (!built) { maCancelRowEditEl(row); return; }

    const saveBtn = row.querySelector('.ma-row-save-btn');
    if (saveBtn) saveBtn.disabled = true;

    // Single row through the same transactional endpoint.
    const result = await apiPost(API.disposition + '/zeiten-speichern', { rows: [built.payload] });

    if (saveBtn) saveBtn.disabled = false;

    if (!result || !result.success) {
        // Nothing was stored — keep the row in edit mode so the input is not lost.
        showToast((result && result.error) || 'Speichern fehlgeschlagen — nichts wurde geändert', 'error');
        return;
    }

    const r = (result.rows || [])[0] || {};
    built.patch.revoked = !!(kunde ? r.kunden_freigabe_revoked : r.zeit_freigabe_revoked);
    showToast('Einsatz aktualisiert');
    if (built.patch.revoked) showToast(kunde
        ? 'Kunden-Freigabe zurückgenommen — Werte geändert, bitte erneut freigeben'
        : 'Freigabe zurückgenommen — Zeit geändert', 'error');

    // Apply the patch in-memory and rebuild only the affected block + KPIs.
    if (block) maApplyPatchesAndRefreshBlock(block.id, [built.patch]);
    if (!kunde) maRealtimeSchedule();
}

/**
 * Apply local patches to _maData + caches, then rebuild a single worker block
 * and refresh the Bento KPIs — avoids a full page re-render after each save.
 */
function maApplyPatchesAndRefreshBlock(blockId, patches) {
    if (!_maData || !patches || patches.length === 0) return;

    // 1) Patch _maData entries in-place
    patches.forEach(p => {
        const list = p.isNu ? (_maData.nu_entries || []) : (_maData.entries || []);
        for (const entry of list) {
            if (String(entry.einsatz_id) !== String(p.eid)) continue;
            // For NU we also need the matching nu_worker_id (+ optional position_id)
            if (p.isNu) {
                if (String(entry.nu_worker_id) !== String(p.nuWorkerId)) continue;
                if (p.positionId && entry.position_id != null
                    && String(entry.position_id) !== String(p.positionId)) continue;
            } else if (p.dienstNr && String(entry.dienst_nr) !== String(p.dienstNr)) {
                continue;
            }
            if (p.kunde) {
                // Customer values only — the worker's Ist is unchanged.
                ['kunden_start', 'kunden_ende', 'kunden_pause', 'kunden_std'].forEach(f => {
                    if (p[f] !== undefined) entry[f] = p[f];
                });
                if (p.revoked) entry.kunden_freigabe = 0;
                break;
            }
            // The server writes these to the per-worker row (em / npz), i.e. the Ist.
            if (p.ist_start !== undefined)     { entry.ist_start = p.ist_start; entry.em_ist_start = p.ist_start; }
            if (p.ist_ende !== undefined)      { entry.ist_ende = p.ist_ende; entry.em_ist_ende = p.ist_ende; }
            if (p.pause_minuten !== undefined) { entry.pause_minuten = p.pause_minuten; entry.em_pause = p.pause_minuten; }
            if (p.ist_std_menge !== undefined) entry.ist_std = p.ist_std_menge;
            // Editing an approved row revokes its Freigabe (server decides).
            if (p.revoked) entry.zeit_freigabe = 0;
            entry.ist_quelle = entry.app_clock_in ? 'korrigiert' : 'manuell';
            break;
        }
    });

    // 2) Rebuild grouped caches from the patched _maData
    _maPageWorkers = maGroupInternalWorkers(_maData.entries || []);
    _maPageNuWorkers = maGroupNuWorkers(_maData.nu_entries || []);

    // 3) Update aggregated totals so the Bento + header reflect new sums.
    //    (We recalc from the actual entries to stay accurate.)
    const recalcSum = list => list.reduce((sum, e) => sum + maCalcEntryStd(e), 0);
    _maData.total_std = +recalcSum(_maData.entries || []).toFixed(2);
    _maData.nu_total_std = +recalcSum(_maData.nu_entries || []).toFixed(2);

    // 4) Rebuild only the affected block (preserving its DOM id + scroll position)
    const oldBlock = document.getElementById(blockId);
    if (oldBlock && oldBlock.dataset.byObj === '1') {
        const g = maGroupByObjekt().find(x => x.objektname === oldBlock.dataset.objekt);
        if (g) {
            const tmp = document.createElement('template');
            tmp.innerHTML = maBuildObjektBlock(g, blockId).trim();
            if (tmp.content.firstElementChild) maMorphNode(oldBlock, tmp.content.firstElementChild);
        } else oldBlock.remove();
    } else if (oldBlock) {
        const isNu = oldBlock.dataset.isNu === '1';
        const dienstNr = oldBlock.querySelector('tr[data-einsatz-id]')?.dataset.dienstNr || '';
        const nuWorkerId = oldBlock.querySelector('tr[data-einsatz-id]')?.dataset.nuWorkerId || '';

        let workerObj = null;
        let firma = '';
        if (isNu) {
            for (const [f, ws] of Object.entries(_maPageNuWorkers)) {
                if (ws[nuWorkerId]) { workerObj = ws[nuWorkerId]; firma = f; break; }
            }
            if (workerObj) workerObj._firma = firma;
        } else if (dienstNr) {
            workerObj = _maPageWorkers[dienstNr];
        }

        if (workerObj) {
            // Replace the block HTML in-place
            const tmp = document.createElement('template');
            tmp.innerHTML = maBuildWorkerBlock(workerObj, isNu, blockId).trim();
            const newBlock = tmp.content.firstElementChild;
            if (newBlock) maMorphNode(oldBlock, newBlock);
        } else {
            // Worker disappeared (e.g. all entries removed) → drop the block
            oldBlock.remove();
        }
    }

    // 5) Refresh the Bento header (KPIs + anomaly counter)
    maMorphInner(document.getElementById('maBentoWrap'), maBuildBento(_maData));

    // 6) Re-apply current filters so the rebuilt block respects active filter/search
    maApplyFilters();
}



// ============================================================
// BULK PDF DOWNLOAD (delegates to legacy maDownloadAllPDFs)
// ============================================================
function maBulkDownloadAllPDFs() {
    maOpenExportDialog('pdf');
}

// ============================================================
// EXPORT DIALOG — Excel / PDF for a week, month, year or range,
// all workers or a single one. Data for other periods is fetched
// separately; the page itself (period, filters) stays untouched.
// ============================================================
let _maExp = null;

function maExpIso(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function maExpParse(iso) { const [y, m, d] = iso.split('-').map(Number); return new Date(y, m - 1, d); }
function maExpShort(iso) { const p = iso.split('-'); return p[2] + '.' + p[1] + '.'; }
/** ISO 8601 week: Monday-based, week 1 contains the year's first Thursday. */
function maExpIsoWeek(d) {
    const t = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    t.setDate(t.getDate() + 3 - ((t.getDay() + 6) % 7));
    const y = t.getFullYear();
    const w1 = new Date(y, 0, 4);
    return { week: 1 + Math.round(((t - w1) / 86400000 - 3 + ((w1.getDay() + 6) % 7)) / 7), year: y };
}

/** Period of the dialog state → { von, bis, label, sub, periodLabel, fileLabel }. */
function maExpPeriod(s) {
    const a = maExpParse(s.anchor);
    if (s.mode === 'week') {
        const mon = new Date(a); mon.setDate(a.getDate() - ((a.getDay() + 6) % 7));
        const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
        const { week, year } = maExpIsoWeek(mon);
        const von = maExpIso(mon), bis = maExpIso(sun);
        return { von, bis, label: `KW ${week}`, sub: `${maExpShort(von)} – ${wpFormatDate(bis)}`,
            periodLabel: `KW ${week}/${year} (${wpFormatDate(von)} – ${wpFormatDate(bis)})`, fileLabel: `KW${String(week).padStart(2, '0')}_${year}` };
    }
    if (s.mode === 'month') {
        const y = a.getFullYear(), m = a.getMonth() + 1;
        const von = maExpIso(new Date(y, m - 1, 1)), bis = maExpIso(new Date(y, m, 0));
        return { von, bis, label: MONTHS_FULL[m], sub: String(y), periodLabel: `${MONTHS_FULL[m]} ${y}`, fileLabel: `${MONTHS_FULL[m]}_${y}` };
    }
    if (s.mode === 'year') {
        const y = a.getFullYear();
        return { von: `${y}-01-01`, bis: `${y}-12-31`, label: String(y), sub: '01.01. – 31.12.', periodLabel: `Jahr ${y}`, fileLabel: String(y) };
    }
    if (s.mode === 'range') {
        let von = s.from, bis = s.to;
        if (von > bis) [von, bis] = [bis, von];
        return { von, bis, label: '', sub: '', periodLabel: `${wpFormatDate(von)} – ${wpFormatDate(bis)}`, fileLabel: `${von}_bis_${bis}` };
    }
    // 'view' — exactly the page (period + filters)
    return { von: '', bis: '', label: maCurrentPeriodLabel(), sub: '', periodLabel: maCurrentPeriodLabel(), fileLabel: maCurrentFileLabel() };
}

function maOpenExportDialog(format) {
    // Kunde mode: no export at all — customer documents (Leistungsnachweis)
    // are produced in Bestellungen.
    if (_maMode === 'kunde') return;
    const page = _maRangeMode && _maRangeStart ? maExpParse(_maRangeStart) : new Date(_maPageYear, _maPageMonth - 1, 1);
    const today = new Date();
    const inPageMonth = !_maRangeMode && today.getFullYear() === _maPageYear && today.getMonth() + 1 === _maPageMonth;
    _maExp = {
        format: format === 'pdf' ? 'pdf' : 'xlsx',
        mode: 'view',
        anchor: maExpIso(inPageMonth ? today : page),
        from: _maRangeMode && _maRangeStart ? _maRangeStart : maExpIso(new Date(_maPageYear, _maPageMonth - 1, 1)),
        to: _maRangeMode && _maRangeEnd ? _maRangeEnd : maExpIso(new Date(_maPageYear, _maPageMonth, 0)),
        worker: maExportFilterLabel() ? 'view' : 'all',
        objekt: _maFilterObjekt || '',   // '' = alle Objekte; else only the shifts at this Objekt
        freiOnly: true,                  // Excel: only days with Zeit-Freigabe (NU has no Freigabe)
        cache: {}, data: null, loading: false, req: 0,
    };
    const footer = `
        <md-outlined-button onclick="closeModal()">Abbrechen</md-outlined-button>
        <md-filled-button id="maExpGo" onclick="maExpRun()" disabled></md-filled-button>`;
    openModal('Exportieren', `<div class="ma-exp" id="maExpBody"></div>`, footer);
    maExpRender();
    maExpLoad();
}

function maExpRender() {
    const s = _maExp; const body = document.getElementById('maExpBody');
    if (!s || !body) return;
    const p = maExpPeriod(s);
    const modes = [['view', 'Ansicht'], ['week', 'Woche'], ['month', 'Monat'], ['year', 'Jahr'], ['range', 'Zeitraum']];
    const fmt = (f, icon, title, sub) => `
        <button type="button" class="ma-exp-format ${s.format === f ? 'active' : ''}" onclick="maExpSet('format','${f}')" aria-pressed="${s.format === f}">
            <span class="ma-exp-format-icon">${maIcon(icon)}</span>
            <span class="ma-exp-format-text"><strong>${title}</strong><small>${sub}</small></span>
        </button>`;
    let period;
    if (s.mode === 'view') {
        const f = maExportFilterLabel();
        period = `<div class="ma-exp-view">${maIcon('visibility', 'font-size:18px')}<div><strong>${escapeHtml(p.periodLabel)}</strong><small>${f ? 'Filter: ' + escapeHtml(f) : 'Wie auf der Seite angezeigt'}</small></div></div>`;
    } else if (s.mode === 'range') {
        period = `<div class="ma-exp-range">
            <label>Von<input type="date" value="${s.from}" onchange="maExpSet('from', this.value)"></label>
            <label>Bis<input type="date" value="${s.to}" onchange="maExpSet('to', this.value)"></label>
        </div>`;
    } else {
        const nowTitle = { week: 'Diese Woche', month: 'Dieser Monat', year: 'Dieses Jahr' }[s.mode];
        period = `<div class="ma-exp-stepper">
            <button type="button" class="ma-exp-step" onclick="maExpStep(-1)" aria-label="Zurück">${maIcon('chevron_left')}</button>
            <div class="ma-exp-step-label"><strong>${escapeHtml(p.label)}</strong><small>${escapeHtml(p.sub)}</small></div>
            <button type="button" class="ma-exp-step" onclick="maExpStep(1)" aria-label="Weiter">${maIcon('chevron_right')}</button>
        </div>
        <button type="button" class="ma-exp-now" onclick="maExpSet('anchor','${maExpIso(new Date())}')">${maIcon('today', 'font-size:14px')} ${nowTitle}</button>`;
    }
    body.innerHTML = `
        <div class="ma-exp-section">
            <div class="ma-exp-label">Format</div>
            <div class="ma-exp-formats">
                ${fmt('xlsx', 'table_view', 'Excel', 'Tabelle + Summen je Mitarbeiter')}
                ${fmt('pdf', 'picture_as_pdf', 'PDF', 'Stundennachweis je Mitarbeiter')}
            </div>
        </div>
        <div class="ma-exp-section">
            <div class="ma-exp-label">Zeitraum</div>
            <div class="ma-exp-modes" role="tablist">
                ${modes.map(([m, l]) => `<button type="button" role="tab" class="ma-exp-mode ${s.mode === m ? 'active' : ''}" aria-selected="${s.mode === m}" onclick="maExpSet('mode','${m}')">${l}</button>`).join('')}
            </div>
            <div class="ma-exp-period">${period}</div>
        </div>
        <div class="ma-exp-section">
            <div class="ma-exp-label">Mitarbeiter</div>
            <select class="ma-exp-select" id="maExpWorker" onchange="maExpSet('worker', this.value)">${maExpWorkerOptions()}</select>
        </div>
        <div class="ma-exp-section">
            <div class="ma-exp-label">Objekt</div>
            <select class="ma-exp-select" id="maExpObjekt" onchange="maExpSet('objekt', this.value)">${maExpObjektOptions()}</select>
        </div>
        ${s.format === 'xlsx' ? `<div class="ma-exp-section ma-exp-frei">
            <div><div class="ma-exp-label">Nur freigegebene Tage</div>
            <small>Einsätze ohne Zeit-Freigabe und beantragte Abwesenheiten werden nicht exportiert</small></div>
            <md-switch ${s.freiOnly !== false ? 'selected' : ''} onchange="maExpSet('freiOnly', this.selected)" aria-label="Nur freigegebene Tage"></md-switch>
        </div>` : ''}
        <div class="ma-exp-summary" id="maExpSummary">${maExpSummaryHtml()}</div>`;
    maExpUpdateButton();
}

/** Groups (intern sorted like the page, NU by firma) of the loaded period. */
function maExpGroups() {
    const s = _maExp;
    if (s.mode === 'view') return { intern: _maPageWorkers, nu: _maPageNuWorkers };
    if (!s.data) return null;
    if (!s.data._groups) s.data._groups = { intern: maGroupInternalWorkers(s.data.entries || [], s.data.abwesenheiten || {}), nu: maGroupNuWorkers(s.data.nu_entries || []) };
    return s.data._groups;
}

/**
 * Worker restricted to the dialog's scope (null = nothing to export):
 * the Objekt filter and — for Excel with "Nur freigegebene Tage" — the
 * Zeit-Freigabe. Abwesenheiten are not Objekt-bound and count as
 * released once the Antrag is genehmigt (beantragt → not exported).
 */
function maExpByObjekt(w) {
    const s = _maExp, o = s && s.objekt;
    const only = s && s.format === 'xlsx' && s.freiOnly !== false;
    const isNu = !w.dienst_nr;
    let entries = w.entries;
    if (only && !isNu) entries = entries.filter(e => parseInt(e.zeit_freigabe) === 1);
    if (o) entries = entries.filter(e => (e.objektname || '').trim() === o);
    let abw = o ? [] : (w.abw || []);
    if (only) abw = abw.filter(r => r.status !== 'pending');
    return (entries.length || abw.length) ? Object.assign({}, w, { entries, abw }) : null;
}

/** Objekte of the loaded period with their shift count. */
function maExpObjektOptions() {
    const s = _maExp; const g = maExpGroups();
    const counts = new Map();
    if (g) {
        const all = [...Object.values(g.intern), ...Object.values(g.nu).flatMap(ws => Object.values(ws))];
        all.forEach(w => w.entries.forEach(e => {
            const n = (e.objektname || '').trim();
            if (n) counts.set(n, (counts.get(n) || 0) + 1);
        }));
    }
    const names = [...counts.keys()].sort((a, b) => a.localeCompare(b, 'de'));
    let html = `<option value="" ${!s.objekt ? 'selected' : ''}>Alle Objekte</option>`;
    html += names.map(n => `<option value="${escapeHtml(n)}" ${n === s.objekt ? 'selected' : ''}>${escapeHtml(n)} · ${counts.get(n)} Eins.</option>`).join('');
    // An Objekt picked in another period without shifts here stays selectable.
    if (s.objekt && !counts.has(s.objekt)) html += `<option value="${escapeHtml(s.objekt)}" selected>${escapeHtml(s.objekt)} · keine Einsätze</option>`;
    return html;
}

function maExpWorkerOptions() {
    const s = _maExp; const g = maExpGroups();
    const name = w => ((w.vorname || '') + ' ' + (w.name || '')).trim() || w.dienst_nr || w.personal_nr || ('NU-' + w.nu_worker_id);
    let html = '';
    if (s.mode === 'view' && maExportFilterLabel()) html += `<option value="view" ${s.worker === 'view' ? 'selected' : ''}>Wie Filter der Seite</option>`;
    html += `<option value="all" ${s.worker === 'all' ? 'selected' : ''}>Alle Mitarbeiter</option>`;
    if (!g) return html + (s.worker !== 'all' && s.worker !== 'view' ? `<option value="${escapeHtml(s.worker)}" selected>${escapeHtml(s.workerName || 'Mitarbeiter')}</option>` : '');
    const intern = maSortWorkers(Object.values(g.intern).map(maExpByObjekt).filter(Boolean));
    let found = s.worker === 'all' || s.worker === 'view';
    if (intern.length) {
        html += '<optgroup label="Interne Mitarbeiter">' + intern.map(w => {
            const v = 'i:' + w.dienst_nr; if (v === s.worker) found = true;
            return `<option value="${escapeHtml(v)}" ${v === s.worker ? 'selected' : ''}>${escapeHtml(name(w))} · ${w.entries.length} Eins.</option>`;
        }).join('') + '</optgroup>';
    }
    Object.entries(g.nu).forEach(([firma, ws]) => {
        const list = Object.values(ws).map(maExpByObjekt).filter(Boolean);
        if (!list.length) return;
        html += `<optgroup label="NU · ${escapeHtml(firma)}">` + list.map(w => {
            const v = 'n:' + w.nu_worker_id; if (v === s.worker) found = true;
            return `<option value="${escapeHtml(v)}" ${v === s.worker ? 'selected' : ''}>${escapeHtml(name(w))} · ${w.entries.length} Eins.</option>`;
        }).join('') + '</optgroup>';
    });
    // A worker picked in another period without entries here stays selectable.
    if (!found) html += `<option value="${escapeHtml(s.worker)}" selected>${escapeHtml(s.workerName || 'Mitarbeiter')} · keine Einsätze</option>`;
    return html;
}

/** Selected workers as [{w,isNu,firma}] in page order. */
function maExpSelection() {
    const s = _maExp; const g = maExpGroups();
    if (!g) return [];
    const pick = x => { const w = maExpByObjekt(x.w); return w ? Object.assign({}, x, { w }) : null; };
    if (s.worker === 'view') return maExportWorkers().map(pick).filter(Boolean);
    const out = [];
    maSortWorkers(Object.values(g.intern)).forEach(w => {
        if (s.worker === 'all' || s.worker === 'i:' + w.dienst_nr) out.push({ w, isNu: false });
    });
    Object.entries(g.nu).forEach(([firma, ws]) => Object.values(ws).forEach(w => {
        if (s.worker === 'all' || s.worker === 'n:' + w.nu_worker_id) out.push({ w, isNu: true, firma });
    }));
    return out.map(pick).filter(Boolean);
}

function maExpSummaryHtml() {
    const s = _maExp;
    if (s.mode === 'range' && (!s.from || !s.to)) return `${maIcon('event', 'font-size:18px')}<span>Bitte Von und Bis wählen</span>`;
    if (s.loading) return `${maSpinner(18)}<span>Lade Einsätze …</span>`;
    if (s.error) return `${maIcon('error', 'font-size:18px')}<span>${escapeHtml(s.error)}</span>`;
    const sel = maExpSelection();
    const n = sel.reduce((a, x) => a + x.w.entries.length, 0);
    const abwN = sel.reduce((a, x) => a + (x.w.abw || []).length, 0);
    if (!n && !abwN) return `${maIcon('event_busy', 'font-size:18px')}<span>Keine bestätigten Einsätze ${s.objekt ? 'an diesem Objekt ' : ''}in diesem Zeitraum</span>`;
    const std = sel.reduce((a, x) => a + maCalcWorkerTotal(x.w.entries), 0);
    const abwStd = sel.reduce((a, x) => a + (x.isNu ? 0 : maAbwSumOf(x.w.abw).std), 0);
    return `${maIcon('check_circle', 'font-size:18px')}<span><strong>${sel.length}</strong> Mitarbeiter · <strong>${n}</strong> Einsätze${abwN ? ` · <strong>${abwN}</strong> Abwesenheiten` : ''} · <strong>${maFmtHrs(std + abwStd)} h</strong></span>`;
}

function maExpUpdateButton() {
    const s = _maExp; const btn = document.getElementById('maExpGo');
    if (!s || !btn) return;
    const sel = s.loading || s.error ? [] : maExpSelection();
    const ready = sel.some(x => x.w.entries.length || (x.w.abw || []).length);
    const single = sel.length === 1;
    btn.disabled = !ready || !!s.busy;
    btn.innerHTML = s.busy ? `${maSpinner(16)} Wird erstellt …`
        : s.format === 'pdf'
            ? `<md-icon slot="icon">picture_as_pdf</md-icon>${single && !sel[0].isNu ? 'PDF öffnen' : 'PDF erstellen'}`
            : '<md-icon slot="icon">download</md-icon>Excel herunterladen';
    btn.classList.toggle('ma-exp-go-pdf', s.format === 'pdf');
    const sum = document.getElementById('maExpSummary');
    if (sum) {
        sum.innerHTML = maExpSummaryHtml();
        sum.classList.toggle('empty', !ready && !s.loading);
    }
}

function maExpSet(key, value) {
    const s = _maExp; if (!s) return;
    if (key === 'worker') {
        const opt = document.querySelector(`#maExpWorker option[value="${CSS.escape(value)}"]`);
        s.workerName = opt ? opt.textContent.replace(/ · .*$/, '') : '';
    }
    s[key] = value;
    if (key === 'mode' && value === 'view' && s.worker === 'all' && maExportFilterLabel()) s.worker = 'view';
    if (key === 'mode' && value !== 'view' && s.worker === 'view') s.worker = 'all';
    if (key === 'format' || key === 'worker' || key === 'objekt' || key === 'freiOnly') { maExpRender(); return; }
    maExpRender();
    maExpLoad();
}

function maExpStep(dir) {
    const s = _maExp; const a = maExpParse(s.anchor);
    if (s.mode === 'week') a.setDate(a.getDate() + 7 * dir);
    else if (s.mode === 'month') { a.setDate(1); a.setMonth(a.getMonth() + dir); }
    else if (s.mode === 'year') a.setFullYear(a.getFullYear() + dir, 0, 1);
    maExpSet('anchor', maExpIso(a));
}

async function maExpLoad() {
    const s = _maExp; if (!s) return;
    s.error = '';
    if (s.mode === 'view') { s.data = null; s.loading = false; maExpRender(); return; }
    const p = maExpPeriod(s);
    if (!p.von || !p.bis) { s.data = null; maExpUpdateButton(); return; }
    const key = p.von + '|' + p.bis;
    if (s.cache[key]) { s.data = s.cache[key]; s.loading = false; maExpRender(); return; }
    const req = ++s.req;
    s.loading = true; s.data = null; maExpUpdateButton();
    const data = await apiGet(API.disposition + '/completed-workers?von=' + encodeURIComponent(p.von) + '&bis=' + encodeURIComponent(p.bis));
    if (_maExp !== s || req !== s.req) return;          // dialog closed or a newer request won
    s.loading = false;
    if (!data || data.error) { s.error = (data && data.error) || 'Daten konnten nicht geladen werden'; maExpUpdateButton(); return; }
    s.cache[key] = data; s.data = data;
    maExpRender();
}

/** Click on the primary button. Synchronous up to window.open (popup blockers). */
function maExpRun() {
    const s = _maExp; if (!s || s.busy) return;
    const sel = maExpSelection().filter(x => x.w.entries.length || (x.w.abw || []).length);
    if (!sel.length) return;
    const p = maExpPeriod(s);
    const single = sel.length === 1 ? sel[0] : null;
    const wName = x => ((x.w.vorname || '') + ' ' + (x.w.name || '')).trim() || x.w.dienst_nr || x.w.personal_nr || 'Mitarbeiter';
    const safe = v => v.replace(/[^A-Za-z0-9ÄÖÜäöüß_-]+/g, '_').replace(/^_+|_+$/g, '');
    let filterLabel = s.worker === 'view' ? maExportFilterLabel() : (single && s.worker !== 'all' ? 'Mitarbeiter: ' + wName(single) : '');
    let fileSuffix = single && s.worker !== 'all' ? safe(wName(single)) : (s.worker === 'view' ? 'gefiltert' : '');
    if (s.objekt) {
        // The page filter may already name the same Objekt — never twice.
        if (!filterLabel.includes('Objekt: ' + s.objekt)) filterLabel = [filterLabel, 'Objekt: ' + s.objekt].filter(Boolean).join(' · ');
        fileSuffix = [fileSuffix === 'gefiltert' ? '' : fileSuffix, safe(s.objekt)].filter(Boolean).join('_');
    }
    if (s.format === 'pdf') {
        if (single && !single.isNu) {
            // One internal worker → the PDF editor (edit, sign, download, print).
            closeModal();
            maOpenWorkerPDF(single.w.dienst_nr, { worker: single.w, periodLabel: p.periodLabel });
            return;
        }
        closeModal();
        maDownloadAllPDFs({ workers: sel, periodLabel: p.periodLabel, title: single ? wName(single) : (s.objekt || 'Alle Mitarbeiter') });
        return;
    }
    s.busy = true; maExpUpdateButton();
    maExportCSV({ workers: sel, periodLabel: p.periodLabel, fileLabel: p.fileLabel, filterLabel, fileSuffix, freiOnly: s.freiOnly })
        .then(ok => { s.busy = false; if (ok && _maExp === s) closeModal(); else maExpUpdateButton(); });
}

// ============================================================
// EXCEL EXPORT — exactly what the page shows
// ============================================================
/** Same visibility rule as maApplyFilters (filter chip, search, Kunde/Objekt scope). */
function maWorkerVisible(worker, isNu) {
    const name = (((worker.vorname || '') + ' ' + (worker.name || '')).trim()
        || worker.dienst_nr || worker.personal_nr || ('NU-' + worker.nu_worker_id)).toLowerCase();
    const q = (_maSearch || '').toLowerCase().trim();
    if (_maFilter === 'internal' && isNu) return false;
    if (_maFilter === 'nu' && !isNu) return false;
    if (_maFilter === 'anomaly' && !worker.entries.some(e => maDetectAnomalies(e).length > 0)) return false;
    if (_maFilter === 'abwesenheit' && !((worker.abw || _maAbw[worker.dienst_nr]) || []).length) return false;
    if (q && !name.includes(q)) return false;
    return maWorkerScopeMatch(worker);
}

/** Visible workers in on-screen order (same sort + NU grouping as maRenderBlocks). */
function maExportWorkers() {
    const out = [];
    if (_maFilter === 'all' || _maFilter === 'internal' || _maFilter === 'anomaly' || _maFilter === 'abwesenheit') {
        maSortWorkers(Object.values(_maPageWorkers)).forEach(w => { if (maWorkerVisible(w, false)) out.push({ w, isNu: false }); });
    }
    if (_maFilter === 'all' || _maFilter === 'nu' || _maFilter === 'anomaly') {
        Object.entries(_maPageNuWorkers).forEach(([firma, ws]) => Object.values(ws).forEach(w => {
            if (maWorkerVisible(w, true)) out.push({ w, isNu: true, firma });
        }));
    }
    return out;
}

function maExportFilterLabel() {
    const parts = [];
    const chip = { internal: 'Intern', nu: 'NU', anomaly: 'Hinweise' }[_maFilter];
    if (chip) parts.push(chip);
    if ((_maSearch || '').trim()) parts.push(`Suche „${_maSearch.trim()}“`);
    if (_maFilterKunde) parts.push('Kunde: ' + _maFilterKunde);
    if (_maFilterObjekt) parts.push('Objekt: ' + _maFilterObjekt);
    return parts.join(' · ');
}

/** File-name part of the page's own period (month or custom range). */
function maCurrentFileLabel() {
    return _maRangeMode && _maRangeStart && _maRangeEnd
        ? `${_maRangeStart}_bis_${_maRangeEnd}`
        : `${MONTHS_FULL[_maPageMonth]}_${_maPageYear}`;
}

/**
 * Excel export. Without options: the current view (visible workers, page
 * period). The export dialog passes { workers, periodLabel, fileLabel, filterLabel }
 * for another week / month / year / range or a single worker.
 */
async function maExportCSV(opts) {
    if (!opts && !_maData) { showToast('Keine Daten geladen', 'error'); return false; }
    const workers = opts ? opts.workers : maExportWorkers();
    if (workers.length === 0) { showToast('Keine Einträge für den aktuellen Filter', 'error'); return false; }
    // Only days the office released (Zeit-Freigabe) leave the system; the
    // same rule drops still-pending absence requests. NU rows have no
    // Freigabe mechanism — they always export.
    const freiOnly = !opts || opts.freiOnly !== false;

    const headers = ['Typ', 'Dienst-Nr / Personal-Nr', 'Vorname', 'Name', 'Bereich / Firma',
               'Datum', 'Tag', 'Kunde', 'Objekt', 'Position',
               'Soll Von', 'Soll Bis', 'Soll Pause (Min)', 'Soll Std',
               'Ist Von', 'Ist Bis', 'Ist Pause (Min)', 'Ist Std', 'Diff (Ist − Soll)', 'Ist-Quelle',
               'App gestempelt Start', 'App beendet', 'App Pause (Min)', 'App Hinweis',
               'Abrechnung Std', 'Status', 'Freigegeben', 'Hinweise', 'Einsatz-ID'];
    const rows = [];
    const r2 = v => Math.round(v * 100) / 100;

    const formatRow = (e, isNu) => {
        const d = e.datum ? new Date(e.datum + 'T00:00:00') : null;
        const dayName = d ? DAYS_SHORT[d.getDay()] : '';
        const std = maCalcEntryStd(e);
        const status = e.hms_bestaetigt === 'ja' ? 'Bestätigt' : (e.pruefung === 'geplant' ? 'Geplant' : 'Offen');
        const hm = v => v ? String(v).substring(0, 5) : '';
        const sollStd = maSollStd(e);
        const istStd = maIstStd(e);
        const hasIst = !!(e.em_ist_start || e.em_ist_ende || istStd != null);
        const istPause = e.em_pause != null && e.em_pause !== '' ? parseInt(e.em_pause) : (parseInt(e.pause_minuten) || 0);
        // Raw app taps (before snapping to the plan) + what happened to the shift.
        const hasApp = !!e.app_clock_out;
        const appStart = hasApp ? maUtcToLocalHm(e.app_clock_in_real || e.app_clock_in) : '';
        const appEnd = hasApp ? maUtcToLocalHm(e.app_clock_out_real || e.app_clock_out) : '';
        const notes = [];
        if (hasApp && /^Automatisch beendet/.test(e.app_notiz || '') && !e.app_clock_out_real) notes.push('autom. beendet');
        let ext = [];
        try { ext = JSON.parse(e.app_verlaengerung_gruende || '[]') || []; } catch (err) { ext = []; }
        ext.forEach(g => notes.push(`verl. ${maUtcToLocalHm(g.am)} → ${maUtcToLocalHm(g.bis)}: ${g.grund || ''}`));
        const hinweise = maDetectAnomalies(e);
        if (e.abwesenheit_covered) hinweise.unshift('Abwesend: ' + (e.abwesenheit || ''));
        return [
            isNu ? 'NU' : 'Intern',
            isNu ? (e.personal_nr || ('NU-' + e.nu_worker_id)) : (e.dienst_nr || ''),
            e.vorname || '',
            e.name || '',
            isNu ? (e.nu_firmenname || '') : (e.bereich || ''),
            e.datum || '',
            dayName,
            e.kundenname || '',
            e.objektname || '',
            e.position_name || '',
            hm(e.soll_start),
            hm(e.soll_ende),
            parseInt(e.soll_pause) || 0,
            // Real numbers → typed Excel cells; sums/sorts work in every locale.
            r2(sollStd),
            hm(e.em_ist_start),
            hm(e.em_ist_ende),
            hasIst ? istPause : '',
            istStd != null ? r2(istStd) : '',
            istStd != null && sollStd > 0 ? r2(istStd - sollStd) : '',
            MA_IST_QUELLE[e.ist_quelle] ? MA_IST_QUELLE[e.ist_quelle].label : '',
            appStart,
            appEnd,
            hasApp && e.app_pause != null && e.app_pause !== '' ? parseInt(e.app_pause) || 0 : '',
            notes.join(' | '),
            r2(std),
            status,
            isNu ? '' : (parseInt(e.zeit_freigabe) === 1 ? 'Ja' : 'Nein'),
            hinweise.join(' · '),
            e.einsatz_id || ''
        ];
    };

    // ── One normalised record per Einsatz — every sheet is built from these ──
    const fmtDate = iso => iso ? String(iso).substring(0, 10).split('-').reverse().join('.') : '';
    const hm = v => v ? String(v).substring(0, 5) : '';
    const wName = (w, isNu) => ((w.vorname || '') + ' ' + (w.name || '')).trim()
        || (isNu ? (w.personal_nr || ('NU-' + w.nu_worker_id)) : (w.dienst_nr || 'Mitarbeiter'));
    const recs = [];
    workers.forEach(({ w, isNu, firma }) => w.entries.forEach(e => {
        if (freiOnly && !isNu && parseInt(e.zeit_freigabe) !== 1) return;
        rows.push(formatRow(e, isNu));
        const d = e.datum ? new Date(e.datum + 'T00:00:00') : null;
        const soll = maSollStd(e), ist = maIstStd(e);
        const hasIst = !!(e.em_ist_start || e.em_ist_ende || ist != null);
        const istPause = e.em_pause != null && e.em_pause !== '' ? parseInt(e.em_pause) : (parseInt(e.pause_minuten) || 0);
        recs.push({
            e, w, isNu,
            key: isNu ? 'n:' + w.nu_worker_id : 'i:' + w.dienst_nr,
            name: wName(w, isNu),
            nr: isNu ? (w.personal_nr || ('NU-' + w.nu_worker_id)) : (w.dienst_nr || ''),
            gruppe: isNu ? (firma || e.nu_firmenname || '') : (w.bereich || ''),
            iso: e.datum || '', datum: fmtDate(e.datum), tag: d ? DAYS_SHORT[d.getDay()] : '',
            kunde: e.kundenname || '', objekt: e.objektname || '', position: e.position_name || '',
            adresse: [e.objekt_adresse, [e.objekt_plz, e.objekt_stadt].filter(Boolean).join(' ')].filter(Boolean).join(', '),
            sollVon: hm(e.soll_start), sollBis: hm(e.soll_ende), soll: r2(soll),
            istVon: hm(e.em_ist_start), istBis: hm(e.em_ist_ende), pause: hasIst ? istPause : '',
            ist: ist != null ? r2(ist) : '', diff: ist != null && soll > 0 ? r2(ist - soll) : '',
            abr: r2(maCalcEntryStd(e)),
            quelle: MA_IST_QUELLE[e.ist_quelle] ? MA_IST_QUELLE[e.ist_quelle].label : '',
            frei: isNu ? '' : (parseInt(e.zeit_freigabe) === 1 ? 'Ja' : 'Nein'),
            hinweise: (e.abwesenheit_covered ? 'Abwesend: ' + (e.abwesenheit || '') + ' · ' : '') + maDetectAnomalies(e).join(' · '),
        });
    }));
    const sum = list => {
        const t = { n: list.length, tage: new Set(list.map(r => r.iso)).size, soll: 0, ist: 0, diff: 0, abr: 0, ohne: 0, frei: 0, intern: 0, anom: 0,
            workers: new Set(list.map(r => r.key)).size };
        list.forEach(r => {
            t.soll += r.soll; t.abr += r.abr;
            if (r.ist === '') t.ohne++; else { t.ist += r.ist; if (r.diff !== '') t.diff += r.diff; }
            if (!r.isNu) { t.intern++; if (r.frei === 'Ja') t.frei++; }
            if (r.hinweise) t.anom++;
        });
        ['soll', 'ist', 'diff', 'abr'].forEach(k => { t[k] = r2(t[k]); });
        return t;
    };
    const freiTxt = t => t.intern ? `${t.frei} / ${t.intern}` : '';
    const byDate = (a, b) => a.iso.localeCompare(b.iso) || a.istVon.localeCompare(b.istVon) || a.name.localeCompare(b.name, 'de');
    const groupBy = (list, keyFn) => {
        const m = new Map();
        list.forEach(r => { const k = keyFn(r); if (!m.has(k)) m.set(k, []); m.get(k).push(r); });
        return m;
    };
    const SEP = '   |   ';

    const filterLabel = opts ? (opts.filterLabel || '') : maExportFilterLabel();
    const periodLabel = opts ? opts.periodLabel : maCurrentPeriodLabel();
    const now = new Date();
    const titleRows = [
        ['Zeiterfassung — ' + periodLabel],
        ['Filter: ' + (filterLabel || 'keiner (alle Mitarbeiter)')],
        ['Erstellt: ' + now.toLocaleDateString('de-DE') + ' ' + now.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
            + '  ·  Abrechnung Std = Ist, ohne Erfassung Plan-Stunden'
            + (freiOnly ? '  ·  nur freigegebene Tage' : '')],
    ];
    const workerGroups = groupBy(recs, r => r.key);   // keeps the page order
    const objektGroups = [...groupBy(recs, r => (r.objekt || 'Ohne Objekt') + '\u0001' + r.kunde).values()]
        .sort((a, b) => (a[0].objekt || 'zzz').localeCompare(b[0].objekt || 'zzz', 'de') || a[0].kunde.localeCompare(b[0].kunde, 'de'));

    // ── Sheet 1 · Übersicht: one line per worker, totals, then one line per object ──
    const ovHeaders = ['Mitarbeiter', 'Nr.', 'Typ', 'Bereich / Firma', 'Einsätze', 'Tage', 'Soll Std', 'Ist Std',
        'Diff (Ist − Soll)', 'Abrechnung Std', 'Ohne Ist', 'Freigegeben', 'Hinweise'];
    const ov = [], ovGroup = [], ovSum = [], ovHead = [];
    const ovLine = (label, nr, typ, grp, t) => [label, nr, typ, grp, t.n, t.tage, t.soll, t.ist, t.diff, t.abr, t.ohne, freiTxt(t), t.anom];
    workerGroups.forEach(list => {
        const r = list[0];
        ov.push(ovLine(r.name, r.nr, r.isNu ? 'NU' : 'Intern', r.gruppe, sum(list)));
    });
    const intern = recs.filter(r => !r.isNu), nu = recs.filter(r => r.isNu);
    if (intern.length && nu.length) {
        const ti = sum(intern), tn = sum(nu);
        ovSum.push(ov.length); ov.push(ovLine('Summe Intern', ti.workers + ' MA', '', '', ti));
        ovSum.push(ov.length); ov.push(ovLine('Summe NU', tn.workers + ' MA', '', '', tn));
    }
    const all = sum(recs);
    ovSum.push(ov.length); ov.push(ovLine('Gesamt', all.workers + ' MA', '', '', all));
    ov.push([]);
    ovGroup.push(ov.length); ov.push(['Summen je Objekt']);
    ovHead.push(ov.length); ov.push(['Objekt', 'Mitarbeiter', '', 'Kunde', 'Einsätze', 'Tage', 'Soll Std', 'Ist Std',
        'Diff (Ist − Soll)', 'Abrechnung Std', 'Ohne Ist', 'Freigegeben', 'Hinweise']);
    objektGroups.forEach(list => {
        const t = sum(list), r = list[0];
        ov.push([r.objekt || 'Ohne Objekt', t.workers, '', r.kunde, t.n, t.tage, t.soll, t.ist, t.diff, t.abr, t.ohne, freiTxt(t), t.anom]);
    });

    // ── Sheet 2 · Nach Mitarbeiter: a block per worker (header line, days, subtotal) ──
    const maHeaders = ['Datum', 'Tag', 'Kunde', 'Objekt', 'Position', 'Soll Von', 'Soll Bis', 'Soll Std',
        'Ist Von', 'Ist Bis', 'Pause (Min)', 'Ist Std', 'Diff (Ist − Soll)', 'Abrechnung Std', 'Ist-Quelle', 'Freigegeben', 'Hinweise'];
    const ma = [], maGroup = [], maSum = [];
    workerGroups.forEach(list => {
        const r = list[0], t = sum(list);
        if (ma.length) ma.push([]);
        maGroup.push(ma.length);
        ma.push([[r.name, r.isNu ? 'NU · ' + r.gruppe : 'Intern' + (r.gruppe ? ' · ' + r.gruppe : ''), 'Nr. ' + r.nr,
            `${t.n} Einsätze an ${t.tage} Tagen`].filter(Boolean).join(SEP)]);
        [...list].sort(byDate).forEach(x => ma.push([x.datum, x.tag, x.kunde, x.objekt, x.position, x.sollVon, x.sollBis, x.soll,
            x.istVon, x.istBis, x.pause, x.ist, x.diff, x.abr, x.quelle, x.frei, x.hinweise]));
        maSum.push(ma.length);
        ma.push(['Summe', '', '', `${t.n} Einsätze`, '', '', '', t.soll, '', '', '', t.ist, t.diff, t.abr,
            t.ohne ? `${t.ohne} ohne Ist` : '', freiTxt(t), t.anom ? `${t.anom} Hinweise` : '']);
    });

    // ── Sheet 3 · Nach Objekt: a block per object (header line, shifts by date, subtotal) ──
    const obHeaders = ['Datum', 'Tag', 'Mitarbeiter', 'Nr.', 'Typ / Firma', 'Position', 'Soll Von', 'Soll Bis', 'Soll Std',
        'Ist Von', 'Ist Bis', 'Pause (Min)', 'Ist Std', 'Diff (Ist − Soll)', 'Abrechnung Std', 'Freigegeben', 'Hinweise'];
    const ob = [], obGroup = [], obSum = [];
    objektGroups.forEach(list => {
        const r = list[0], t = sum(list);
        if (ob.length) ob.push([]);
        obGroup.push(ob.length);
        ob.push([[r.objekt || 'Ohne Objekt', r.kunde ? 'Kunde: ' + r.kunde : '', r.adresse,
            `${t.n} Einsätze · ${t.workers} Mitarbeiter`].filter(Boolean).join(SEP)]);
        [...list].sort(byDate).forEach(x => ob.push([x.datum, x.tag, x.name, x.nr, x.isNu ? 'NU · ' + x.gruppe : 'Intern', x.position,
            x.sollVon, x.sollBis, x.soll, x.istVon, x.istBis, x.pause, x.ist, x.diff, x.abr, x.frei, x.hinweise]));
        obSum.push(ob.length);
        ob.push(['Summe', '', `${t.workers} Mitarbeiter`, '', `${t.n} Einsätze`, '', '', '', t.soll, '', '', '', t.ist, t.diff, t.abr,
            freiTxt(t), t.anom ? `${t.anom} Hinweise` : '']);
    });

    // ── Sheet 4 · Abwesenheiten (Anträge & Urlaub, bezahlte Stunden nach dt. Recht) ──
    const abwHeaders = ['Mitarbeiter', 'Nr.', 'Von', 'Bis', 'Tage', 'Typ', 'Kategorie',
        'Status', 'Bezahlt', 'Stunden', 'Grundlage', 'Hinweise'];
    const abwRows = [];
    workers.forEach(({ w, isNu }) => {
        if (isNu) return;
        (w.abw || _maAbw[w.dienst_nr] || []).forEach(r => {
            if (freiOnly && r.status === 'pending') return;
            abwRows.push([wName(w, false), w.dienst_nr || '', fmtDate(r.startdatum), fmtDate(r.enddatum),
                +(+r.zeitraum_tage).toFixed(1), r.typ || '', r.kategorie || '',
                r.status === 'pending' ? 'Beantragt' : 'Genehmigt',
                parseInt(r.bezahlt) ? 'Ja' : 'Nein',
                r.std > 0 ? r2(r.std) : 0, r.basis_label || '', (r.hinweise || []).join(' · ')]);
        });
    });
    abwRows.sort((a, b) => a[0].localeCompare(b[0], 'de') || String(a[2]).localeCompare(String(b[2])));

    const extraSheets = [
        { title: 'Nach Mitarbeiter', headers: maHeaders, rows: ma, layout: {
            group_rows: maGroup, sum_rows: maSum, num_cols: [7, 11, 12, 13], int_cols: [10], landscape: true,
            widths: [11, 5, 40, 34, 16, 9, 9, 9, 9, 9, 10, 9, 11, 13, 10, 12, 40] } },
        { title: 'Nach Objekt', headers: obHeaders, rows: ob, layout: {
            group_rows: obGroup, sum_rows: obSum, num_cols: [8, 12, 13, 14], int_cols: [11], landscape: true,
            widths: [11, 5, 24, 10, 22, 16, 9, 9, 9, 9, 9, 10, 9, 11, 13, 12, 40] } },
        { title: 'Alle Einsätze', headers, rows, layout: {
            autofilter: true, num_cols: [13, 17, 18, 24], int_cols: [12, 16, 22], landscape: true } },
    ];
    if (abwRows.length) extraSheets.push({ title: 'Abwesenheiten', headers: abwHeaders, rows: abwRows, layout: {
        autofilter: true, num_cols: [4, 9], landscape: true,
        widths: [26, 10, 11, 11, 7, 22, 11, 11, 9, 9, 22, 50] } });

    const fileLabel = opts ? opts.fileLabel : maCurrentFileLabel();
    const fileSuffix = opts && opts.fileSuffix ? '_' + opts.fileSuffix : (filterLabel ? '_gefiltert' : '');
    const filename = `Zeiterfassung_${fileLabel}${fileSuffix}.xlsx`;
    try {
        await downloadXlsx(filename, 'Übersicht', ovHeaders, ov, extraSheets, {
            title_rows: titleRows, group_rows: ovGroup, sum_rows: ovSum, head_rows: ovHead,
            num_cols: [6, 7, 8, 9], int_cols: [4, 5, 10, 12], landscape: true,
            widths: [34, 12, 8, 40, 10, 8, 10, 10, 12, 13, 9, 12, 10],
        });
        showToast(`Excel exportiert — ${workerGroups.size} Mitarbeiter, ${recs.length} Einsätze${freiOnly ? ' (nur freigegebene)' : ''}${filterLabel ? ' (gefiltert)' : ''}`);
        return true;
    } catch (err) {
        showToast(err.message || 'Export fehlgeschlagen', 'error');
        return false;
    }
}
