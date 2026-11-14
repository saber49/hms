// tasks/02-dispo-board.js — Dispo-Board: Laden, Filterleiste, Bereich-Gate, Bulk-Aktionen, Wochen-Grid, Karten
// Teil 2/10 des früheren tasks.js. Alle Teile teilen den globalen Scope und MÜSSEN in dieser Reihenfolge geladen werden (PAGE_MODULE_SCRIPTS in app.js).

// ============================================
// DISPOSITION & MONTHLY CLOSURE
// ============================================
async function renderDisposition() {
    currentPage = 'tasks';
    await renderTasks();
}

async function loadDisposition(silent = false) {
    if (typeof plannerBoerseRefreshBadge === 'function') plannerBoerseRefreshBadge();
    const params = new URLSearchParams();

    if (plannerState.viewMode === 'month') {
        params.set('view', 'month');
        params.set('month', plannerState.month);
        params.set('year', plannerState.year);
    } else {
        params.set('week_start', plannerState.weekStart || plannerToIsoDate(plannerGetWeekStart()));
    }

    if (!silent) console.log('[DISPO] fetching:', API.disposition + '/planner?' + params.toString());
    const data = await apiGet(API.disposition + '/planner?' + params.toString());
    if (!silent) console.log('[DISPO] response:', data);
    if (!data || Array.isArray(data) || data.error || !data.days) {
        if (!silent) {
            const grid = document.getElementById('plannerGrid');
            if (grid) grid.innerHTML = '<div class="planner-loading">Kalender konnte nicht geladen werden</div>';
        }
        return;
    }

    // Silent mode (auto-refresh): if nothing actually changed, only swap the
    // cached payload — skip the DOM rebuild entirely so there is no flicker.
    if (silent && plannerState.data) {
        const snap = d => JSON.stringify({ c: d.cards, o: d.overview, w: d.week_start, d: d.days });
        if (snap(data) === snap(plannerState.data)) { plannerState.data = data; return; }
    }

    plannerState.data = data;
    plannerState.weekStart = data.week_start;
    if (data.month) plannerState.month = data.month;
    if (data.year) plannerState.year = data.year;
    const label = plannerGetRangeLabel();
    const rangeEl = document.getElementById('plannerWeekRange');
    if (rangeEl) rangeEl.textContent = label;
    const mobileRange = document.getElementById('plannerWeekRangeMobile');
    if (mobileRange) mobileRange.textContent = label;
    const overviewCardsHtml = `
                <div class="overview-card pending"><small><span class="material-symbols-outlined">hourglass_top</span> Ausstehend</small>${data.overview.ausstehend}</div>
                <div class="overview-card planned"><small><span class="material-symbols-outlined">event_available</span> Geplant</small>${data.overview.geplant || 0}</div>
                <div class="overview-card confirmed"><small><span class="material-symbols-outlined">check</span> Bestätigt</small>${data.overview.bestaetigt}</div>
                ${data.overview.abgelehnt > 0 ? `<div class="overview-card rejected"><small><span class="material-symbols-outlined">cancel</span> Abgelehnt</small>${data.overview.abgelehnt}</div>` : ''}`;
    // Desktop (≥1200px): the same counters sit compactly in the topbar next
    // to the action buttons (CSS hides the row copy there).
    const topStats = document.getElementById('plannerTopbarStats');
    if (topStats) { if (typeof hmsMorph === 'function') hmsMorph(topStats, overviewCardsHtml); else topStats.innerHTML = overviewCardsHtml; }
    const ovHtml = `
        <div class="dispo-overview-row">
            <div class="overview-cards planner-overview-cards">${overviewCardsHtml}
            </div>
            ${plannerRenderFilterBar(data)}
        </div>
        <div class="dispo-filter-summary" id="dispoFilterSummary"></div>
        ${data.overview.truncated ? '<div class="dispo-truncated-warning"><span class="material-symbols-outlined">warning</span> Ergebnisse auf 2000 Einsätze begrenzt. Bitte den Zeitraum eingrenzen.</div>' : ''}
    `;
    // Filter bar already on screen (realtime refresh, after a save, week
    // switch): patch counters + options in place — the inputs keep their
    // listeners, focus and typed search. First paint builds + wires it.
    const ovEl = document.getElementById('dispoOverview');
    if (ovEl && document.getElementById('dispoSearchInput') && typeof hmsMorph === 'function') {
        hmsMorph(ovEl, ovHtml);
    } else if (ovEl) {
        ovEl.innerHTML = ovHtml;
        plannerWireFilterBar();
    }
    renderPlannerGrid(data);
    plannerShowBereichGate(data);
}

/**
 * Build the search + 3 filter dropdowns next to the overview cards.
 * Options for the dropdowns are derived from the cards currently in `data`,
 * so the user can only pick filters that actually have results.
 */
function plannerRenderFilterBar(data) {
    const cards = data.cards || [];
    const objektOf = c => c.subtitle ? c.subtitle.split(' · ')[0] : null;
    const allKunden  = plannerCollectUnique(cards, c => c.title);
    const allObjekte = plannerCollectUnique(cards, objektOf);
    const mitarbeiter = plannerCollectUnique(
        cards.flatMap(c => (c.workers || []).map(w => plannerWorkerName(w))),
        x => x
    );

    const f = plannerState.filters;

    const linkedLists = () => ({
        objekte: f.kunde
            ? plannerCollectUnique(cards.filter(c => c.title === f.kunde), objektOf)
            : allObjekte,
        kunden: f.objekt
            ? plannerCollectUnique(cards.filter(c => objektOf(c) === f.objekt), c => c.title)
            : allKunden,
    });

    // Linked filters: a picked Kunde narrows the Objekt list to that
    // customer's objects — and vice versa. When a selection leaves exactly
    // one possible counterpart it is auto-selected so its name shows in the
    // box; selections made stale by the counterpart are dropped. Iterate to
    // settle chained auto-fills (≤2 can occur). Auto-fill never touches the
    // field the user just cleared (plannerState._linkedSrc) — otherwise
    // picking "Alle Kunden" would instantly snap back.
    const src = plannerState._linkedSrc;
    let objekte, kunden;
    for (let i = 0; i < 4; i++) {
        const pk = f.kunde, po = f.objekt;
        ({ objekte, kunden } = linkedLists());
        if (f.objekt && !objekte.includes(f.objekt)) f.objekt = '';
        if (f.kunde && !kunden.includes(f.kunde)) f.kunde = '';
        if (f.objekt && !f.kunde && kunden.length === 1 && src !== 'kunde') f.kunde = kunden[0];
        if (f.kunde && !f.objekt && objekte.length === 1 && src !== 'objekt') f.objekt = objekte[0];
        if (pk === f.kunde && po === f.objekt) break;
    }
    ({ objekte, kunden } = linkedLists());
    const hasActive = !!(f.search || f.kunde || f.objekt || f.mitarbeiter || f.bereich);

    const opt = (list, current) => list.map(v =>
        `<option value="${escapeHtml(v)}" ${v === current ? 'selected' : ''}>${escapeHtml(v)}</option>`
    ).join('');

    return `
        <div class="dispo-filterbar">
            <div class="dispo-search-wrap">
                <span class="material-symbols-outlined dispo-search-icon">search</span>
                <input
                    type="text"
                    id="dispoSearchInput"
                    class="dispo-search-input"
                    placeholder="Suchen: Kunde, Objekt, Mitarbeiter …"
                    value="${escapeHtml(f.search)}"
                    autocomplete="off"
                />
                <button type="button" class="dispo-search-clear" id="dispoSearchClear"
                        ${f.search ? '' : 'hidden'}
                        title="Suche löschen"><span class="material-symbols-outlined">close</span></button>
            </div>
            ${plannerBereichSelectHtml(data)}
            <select class="dispo-filter-select" id="dispoFilterKunde" title="Kunde">
                <option value="">Alle Kunden</option>
                ${opt(kunden, f.kunde)}
            </select>
            <select class="dispo-filter-select" id="dispoFilterObjekt" title="Objekt">
                <option value="">Alle Objekte</option>
                ${opt(objekte, f.objekt)}
            </select>
            <select class="dispo-filter-select" id="dispoFilterMitarbeiter" title="Mitarbeiter">
                <option value="">Alle Mitarbeiter</option>
                ${opt(mitarbeiter, f.mitarbeiter)}
            </select>
            <button type="button" class="dispo-reset-btn" id="dispoResetBtn"
                    ${hasActive ? '' : 'hidden'}><span class="material-symbols-outlined">ink_eraser</span> Reset</button>
        </div>
    `;
}

/** Color palette for a Bereich chip (matches the bereich-badge scheme).
 *  Theme-aware: dark mode swaps the light pastels for translucent tints
 *  with bright tone-300/400 foregrounds. */
function plannerBereichColors(name) {
    // Company colour from Konto & Firma → Bereiche (app.js bereichPalette).
    if (typeof bereichPalette === 'function') { const p = bereichPalette(name); return { bg: p.bg, fg: p.fg, bd: p.bd }; }
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    const k = (name || '').toLowerCase();
    if (k === 'sicherheit') return dark
        ? { bg: 'rgba(96,165,250,0.16)',  fg: '#93c5fd', bd: 'rgba(96,165,250,0.45)' }
        : { bg: '#dbeafe', fg: '#1d4ed8', bd: '#93c5fd' };
    if (k === 'reinigung')  return dark
        ? { bg: 'rgba(52,211,153,0.16)',  fg: '#6ee7b7', bd: 'rgba(52,211,153,0.45)' }
        : { bg: '#d1fae5', fg: '#059669', bd: '#6ee7b7' };
    if (k === 'beides')     return dark
        ? { bg: 'rgba(251,191,36,0.16)',  fg: '#fcd34d', bd: 'rgba(251,191,36,0.45)' }
        : { bg: '#fef3c7', fg: '#b45309', bd: '#fcd34d' };
    return dark
        ? { bg: 'rgba(167,139,250,0.16)', fg: '#c4b5fd', bd: 'rgba(167,139,250,0.45)' }
        : { bg: '#f3e8ff', fg: '#7c3aed', bd: '#d8b4fe' }; // fallback (any custom Bereich)
}

/**
 * Compute the Bereich list + per-Bereich Einsatz counts for the current range.
 * The list always includes ALL known Bereiche (data.all_bereiche) — even those
 * with no Einsätze in the shown month/week — so every classification is
 * selectable regardless of date. card.bereich is article-based (service) with
 * a customer fallback. Counts reflect the currently loaded range.
 */
function plannerBereichStats(data) {
    const cards = data.cards || [];
    const counts = {};
    let noneCount = 0;
    cards.forEach(c => {
        const b = (c.bereich || '').trim();
        if (b) counts[b] = (counts[b] || 0) + 1;
        else noneCount++;
    });
    // Seed every known customer Bereich at 0 so it shows even when empty.
    (data.all_bereiche || []).forEach(b => {
        const v = (b || '').trim();
        if (v && !(v in counts)) counts[v] = 0;
    });
    const bereiche = Object.keys(counts).sort((a, b) => a.localeCompare(b, 'de'));
    return { counts, noneCount, bereiche, total: cards.length };
}

/**
 * Bereich quick-filter as a compact <select> inside the filter bar — replaces
 * the old full-width chip row so the search/filters sit one row higher.
 * Options carry per-Bereich Einsatz counts; the selected Bereich name shows
 * in the closed select, so "Alle Bereiche" reads like the old "Alle" chip.
 */
function plannerBereichSelectHtml(data) {
    const { counts, noneCount, bereiche, total } = plannerBereichStats(data);
    if (bereiche.length === 0) return '';

    const active = plannerState.filters.bereich;
    let options = `<option value="" ${!active ? 'selected' : ''}>Alle (${total})</option>`;
    bereiche.forEach(b => {
        options += `<option value="${escapeHtml(b)}" ${active === b ? 'selected' : ''}>${escapeHtml(b)} (${counts[b]})</option>`;
    });
    if (noneCount > 0) {
        options += `<option value="__none__" ${active === '__none__' ? 'selected' : ''}>Ohne Bereich (${noneCount})</option>`;
    }

    return `<select class="dispo-filter-select dispo-bereich-select${active ? ' has-value' : ''}" id="dispoFilterBereich" title="Bereich">${options}</select>`;
}

/**
 * Floating, frosted-glass selection window shown above the Dispo when the page
 * is first opened. Presents one large square per Bereich (+ "Alle" / "Ohne
 * Bereich"); picking one filters the planner. Shown once per page entry unless
 * forced (re-open button). Skips itself when there are no Bereich values.
 */
function plannerShowBereichGate(data, force = false) {
    if (!force && plannerState._gateShown) return;
    plannerState._gateShown = true;

    const { counts, noneCount, bereiche, total } = plannerBereichStats(data);
    if (bereiche.length < 1) return; // nothing to choose from
    // Only ONE Bereich (security-only company) and nothing untagged: there
    // is nothing to choose — no gate, all Einsätze, remembered for the session.
    if (bereiche.length === 1 && !noneCount) {
        if (typeof plannerRememberBereich === 'function') plannerRememberBereich('');
        return;
    }

    const existing = document.getElementById('dispoBereichGate');
    if (existing) existing.remove();

    let squares = `<button type="button" class="dispo-gate-square dispo-gate-all" data-bereich="">`
        + `<span class="dispo-gate-ico"><span class="material-symbols-outlined">layers</span></span>`
        + `<span class="dispo-gate-name">Alle Bereiche</span>`
        + `<span class="dispo-gate-count">${total} Einsätze</span></button>`;

    bereiche.forEach(b => {
        const col = plannerBereichColors(b);
        squares += `<button type="button" class="dispo-gate-square" data-bereich="${escapeHtml(b)}"`
            + ` style="--g-bg:${col.bg};--g-fg:${col.fg};--g-bd:${col.bd}">`
            + `<span class="dispo-gate-ico"><span class="material-symbols-outlined">sell</span></span>`
            + `<span class="dispo-gate-name">${escapeHtml(b)}</span>`
            + `<span class="dispo-gate-count">${counts[b]} Einsätze</span></button>`;
    });

    if (noneCount > 0) {
        squares += `<button type="button" class="dispo-gate-square dispo-gate-none" data-bereich="__none__">`
            + `<span class="dispo-gate-ico"><span class="material-symbols-outlined">block</span></span>`
            + `<span class="dispo-gate-name">Ohne Bereich</span>`
            + `<span class="dispo-gate-count">${noneCount} Einsätze</span></button>`;
    }

    const html = `
        <div class="dispo-bereich-gate" id="dispoBereichGate">
            <div class="dispo-gate-backdrop"></div>
            <div class="dispo-gate-panel" role="dialog" aria-modal="true" aria-label="Bereich auswählen">
                <button type="button" class="dispo-gate-close" title="Schließen"><span class="material-symbols-outlined">close</span></button>
                <div class="dispo-gate-head"><span class="material-symbols-outlined">layers</span> Bereich auswählen</div>
                <div class="dispo-gate-sub">Wähle einen Bereich, um die passenden Einsätze in der Dispo anzuzeigen.</div>
                <div class="dispo-gate-grid">${squares}</div>
            </div>
        </div>`;
    document.body.insertAdjacentHTML('beforeend', html);

    const gate = document.getElementById('dispoBereichGate');
    if (!gate) return;

    const pick = (val) => {
        plannerState.filters.bereich = val;
        plannerRememberBereich(val);
        gate.remove();
        document.removeEventListener('keydown', onKey);
        plannerApplyFiltersAndRerender(true);
    };
    const dismiss = () => {
        gate.remove();
        document.removeEventListener('keydown', onKey);
    };
    const onKey = (e) => { if (e.key === 'Escape') dismiss(); };

    gate.querySelector('.dispo-gate-backdrop').addEventListener('click', dismiss);
    gate.querySelector('.dispo-gate-close').addEventListener('click', dismiss);
    gate.querySelectorAll('.dispo-gate-square').forEach(btn =>
        btn.addEventListener('click', () => pick(btn.dataset.bereich || ''))
    );
    document.addEventListener('keydown', onKey);

    requestAnimationFrame(() => gate.classList.add('open'));
}

/** Unique, sorted, non-empty values extracted from cards via accessor fn. */
function plannerCollectUnique(items, accessor) {
    const set = new Set();
    items.forEach(item => {
        const v = accessor(item);
        if (v && String(v).trim() !== '') set.add(String(v).trim());
    });
    return [...set].sort((a, b) => a.localeCompare(b, 'de'));
}

/** Wire input + selects (must be re-called every time dispoOverview is re-rendered). */
function plannerWireFilterBar() {
    const search = document.getElementById('dispoSearchInput');
    const clear  = document.getElementById('dispoSearchClear');
    const fK     = document.getElementById('dispoFilterKunde');
    const fO     = document.getElementById('dispoFilterObjekt');
    const fM     = document.getElementById('dispoFilterMitarbeiter');
    const reset  = document.getElementById('dispoResetBtn');

    if (search) {
        search.addEventListener('input', e => {
            clearTimeout(plannerState._searchDebounce);
            const val = e.target.value;
            plannerState._searchDebounce = setTimeout(async () => {
                plannerState.filters.search = val.trim().toLowerCase();
                if (clear) clear.hidden = !val;
                const q = val.trim();
                const zeroHits = () => !plannerState.data
                    || plannerFilterCards(plannerState.data.cards || []).length === 0;
                let navigated = false;
                if (q) {
                    // Week view only holds this week's cards — a zero-hit
                    // query may still match Einsätze in other weeks of the
                    // same month, so widen to month view first.
                    if (plannerState.viewMode === 'week' && zeroHits()) {
                        await plannerSwitchView('month');
                        navigated = true;
                    }
                    // Still nothing? The hit may live in a different month
                    // entirely — ask the backend where it is and jump there.
                    if (zeroHits() && await plannerJumpToSearchMonth(q)) {
                        navigated = true;
                    }
                }
                plannerApplyFiltersAndRerender();
                if (navigated) {
                    const inp = document.getElementById('dispoSearchInput');
                    if (inp) { inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }
                }
            }, 180);
        });
        // Focus shortcut: pressing "/" anywhere outside an input focuses search.
        if (!window._dispoSearchHotkey) {
            window._dispoSearchHotkey = true;
            document.addEventListener('keydown', ev => {
                if (ev.key === '/' && document.activeElement?.tagName !== 'INPUT'
                    && document.activeElement?.tagName !== 'TEXTAREA'
                    && document.getElementById('dispoSearchInput')) {
                    ev.preventDefault();
                    document.getElementById('dispoSearchInput').focus();
                }
            });
        }
    }
    if (clear) clear.addEventListener('click', () => {
        plannerState.filters.search = '';
        if (search) { search.value = ''; search.focus(); }
        clear.hidden = true;
        plannerApplyFiltersAndRerender();
    });
    // Kunde/Objekt rebuild the bar (rebuildBar=true) so the linked
    // counterpart's option list refreshes. _linkedSrc marks the field the
    // user touched — its own auto-fill is suppressed so "Alle …" sticks.
    if (fK) fK.addEventListener('change', e => { plannerState.filters.kunde = e.target.value; plannerState._linkedSrc = 'kunde'; plannerApplyFiltersAndRerender(true); });
    if (fO) fO.addEventListener('change', e => { plannerState.filters.objekt = e.target.value; plannerState._linkedSrc = 'objekt'; plannerApplyFiltersAndRerender(true); });
    if (fM) fM.addEventListener('change', e => { plannerState.filters.mitarbeiter = e.target.value; plannerApplyFiltersAndRerender(); });
    // Bereich filter — compact select in the filter bar; rebuild the bar so
    // the option counts refresh and the selection stays in sync.
    const fB = document.getElementById('dispoFilterBereich');
    if (fB) fB.addEventListener('change', e => {
        plannerState.filters.bereich = e.target.value;
        plannerRememberBereich(e.target.value); // keep the session memory in sync
        plannerApplyFiltersAndRerender(true);
    });
    if (reset) reset.addEventListener('click', () => {
        plannerRememberBereich(null); // forget → the gate asks again next time
        plannerState.filters = { search: '', kunde: '', objekt: '', mitarbeiter: '', bereich: '' };
        plannerState._linkedSrc = null;
        plannerApplyFiltersAndRerender(true);
    });
}

/** Re-render grid (and optionally the filter bar to clear selects). */
function plannerApplyFiltersAndRerender(rebuildBar = false) {
    if (!plannerState.data) return;
    if (rebuildBar) {
        // Fully rebuild the dispoOverview block to reset all input/select states.
        const dispoOverview = document.getElementById('dispoOverview');
        if (dispoOverview) {
            // Re-trigger the same rendering path used in loadDisposition().
            // We keep data the same; only the bar/grid are redrawn.
            const data = plannerState.data;
            dispoOverview.innerHTML = `
                <div class="dispo-overview-row">
                    <div class="overview-cards planner-overview-cards">
                        <div class="overview-card pending"><small><span class="material-symbols-outlined">hourglass_top</span> Ausstehend</small>${data.overview.ausstehend}</div>
                        <div class="overview-card planned"><small><span class="material-symbols-outlined">event_available</span> Geplant</small>${data.overview.geplant || 0}</div>
                        <div class="overview-card confirmed"><small><span class="material-symbols-outlined">check</span> Bestätigt</small>${data.overview.bestaetigt}</div>
                        ${data.overview.abgelehnt > 0 ? `<div class="overview-card rejected"><small><span class="material-symbols-outlined">cancel</span> Abgelehnt</small>${data.overview.abgelehnt}</div>` : ''}
                    </div>
                    ${plannerRenderFilterBar(data)}
                </div>
                <div class="dispo-filter-summary" id="dispoFilterSummary"></div>
            `;
            plannerWireFilterBar();
        }
    }
    renderPlannerGrid(plannerState.data);
}

/** Apply current plannerState.filters to a card list. */
function plannerFilterCards(cards) {
    const f = plannerState.filters;
    const q = f.search;
    if (!q && !f.kunde && !f.objekt && !f.mitarbeiter && !f.bereich) return cards;

    return cards.filter(card => {
        if (f.bereich) {
            const b = (card.bereich || '').trim();
            if (f.bereich === '__none__') { if (b) return false; }
            else if (b !== f.bereich) return false;
        }
        if (f.kunde && card.title !== f.kunde) return false;
        if (f.objekt) {
            const obj = card.subtitle ? card.subtitle.split(' · ')[0] : '';
            if (obj !== f.objekt) return false;
        }
        if (f.mitarbeiter) {
            const names = (card.workers || []).map(w => plannerWorkerName(w));
            if (!names.includes(f.mitarbeiter)) return false;
        }
        if (q) {
            const hay = [
                card.title,
                card.subtitle,
                card.description,
                card.bestell_id,
                card.einsatz_id,
                card.eingang_id,
                card.nu_name,
                ...(card.workers || []).map(w => plannerWorkerName(w)),
            ].filter(Boolean).join(' ').toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    });
}

async function bulkMarkPlanned() {
    // Collect tasks that are not yet planned and not confirmed — only those with
    // workers assigned (worker_count > 0). Respects the active filter bar so the
    // action only touches the cards the user is actually looking at.
    const cards = plannerFilterCards(plannerState.data?.cards || []);
    const periodLabel = plannerState.viewMode === 'month' ? 'dieses Monats' : 'dieser Woche';
    const ids = cards
        .filter(card => card.planner_state === 'new' && card.worker_count > 0)
        .map(card => card.einsatz_id);
    if (ids.length === 0) {
        showToast('Keine Einsätze mit zugewiesenen Mitarbeitern zum Planen gefunden', 'warning');
        return;
    }
    const ok = await mdConfirm(`${ids.length} Einsätze ${periodLabel} als "Geplant" markieren und Mitarbeiter benachrichtigen?`, { title: 'Woche planen', confirmLabel: 'Planen' });
    if (!ok) return;

    const result = await apiPost(API.disposition + '/bulk-mark-planned', { einsatz_ids: ids });
    if (result.success) {
        showToast(`${result.updated} Einsätze als geplant markiert`);
        loadDisposition();
    } else {
        showToast(result.error || 'Fehler beim Planen', 'error');
    }
}

async function bulkConfirmDispo() {
    // Respects the active filter bar (see bulkMarkPlanned). PLANNED shifts are
    // confirmable, plus shifts that already ENDED (staffed afterwards — "Geplant"
    // is not offered for the past, a plan email would be pointless). Same rule
    // as the single-shift confirm button. Future never-planned shifts stay
    // excluded so their workers still get the "Neuer Einsatzplan" email first.
    const cards = plannerFilterCards(plannerState.data?.cards || []);
    const periodLabel = plannerState.viewMode === 'month' ? 'dieses Monats' : 'dieser Woche';
    const pending = cards.filter(card => card.status === 'ausstehend');
    const ended = card => !card.bestell_storno_at && plannerShiftEnded({ datum: card.date, ist_start: card.start, ist_ende: card.end });
    const confirmable = card => card.worker_count > 0 && (card.planner_state === 'planned' || (card.planner_state === 'new' && ended(card)));
    const ids = pending.filter(confirmable).map(card => card.einsatz_id);
    const skippedEmpty = pending.filter(card => card.worker_count === 0).length;
    const skippedUnplanned = pending.filter(card => card.planner_state === 'new' && card.worker_count > 0 && !ended(card)).length;
    if (ids.length === 0) {
        showToast(skippedUnplanned > 0
            ? `${skippedUnplanned} Einsätze sind noch nicht als "Geplant" markiert — bitte zuerst planen`
            : (skippedEmpty > 0
                ? `${skippedEmpty} Einsätze ohne Mitarbeiter übersprungen — keine bestätigbaren Einsätze`
                : `Keine ausstehenden Einsätze ${periodLabel}`), 'warning');
        return;
    }
    const skipParts = [];
    if (skippedUnplanned > 0) skipParts.push(`${skippedUnplanned} nicht geplant`);
    if (skippedEmpty > 0) skipParts.push(`${skippedEmpty} ohne Mitarbeiter`);
    const msg = skipParts.length > 0
        ? `${ids.length} Einsätze bestätigen? (Übersprungen: ${skipParts.join(', ')})`
        : `${ids.length} Einsätze ${periodLabel} bestätigen?`;
    const ok = await mdConfirm(msg, { title: 'Woche bestätigen', confirmLabel: 'Bestätigen' });
    if (!ok) return;

    const result = await apiPost(API.disposition + '/bulk-confirm', { einsatz_ids: ids, hms_bestaetigt: 'ja' });
    if (result.success) {
        let toast = `${result.updated} Einsätze bestätigt`;
        if (result.skipped > 0) toast += ` (${result.skipped} übersprungen)`;
        if (result.short > 0) toast += ` — ⚠ ${result.short} unterbesetzt`;
        showToast(toast, result.short > 0 ? 'warning' : 'success');
        loadDisposition();
    }
}

function plannerChangeWeek(offset) {
    if (plannerState.viewMode === 'month') {
        plannerState.month += offset;
        if (plannerState.month > 12) { plannerState.month = 1; plannerState.year++; }
        if (plannerState.month < 1) { plannerState.month = 12; plannerState.year--; }
    } else {
        const current = new Date((plannerState.weekStart || plannerToIsoDate(plannerGetWeekStart())) + 'T00:00:00');
        current.setDate(current.getDate() + (offset * 7));
        plannerState.weekStart = plannerToIsoDate(current);
    }
    loadDisposition();
}

function plannerGoToCurrentWeek() {
    if (plannerState.viewMode === 'month') {
        plannerState.month = new Date().getMonth() + 1;
        plannerState.year = new Date().getFullYear();
    } else {
        plannerState.weekStart = plannerToIsoDate(plannerGetWeekStart());
    }
    loadDisposition();
}

function plannerToggleViewMenu(btn) {
    const dropdown = btn.parentElement.querySelector('.planner-view-dropdown');
    const allDropdowns = document.querySelectorAll('.planner-view-dropdown');
    allDropdowns.forEach(d => { if (d !== dropdown) d.style.display = 'none'; });
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    const closeHandler = (e) => { if (!btn.parentElement.contains(e.target)) { dropdown.style.display = 'none'; document.removeEventListener('click', closeHandler); } };
    setTimeout(() => document.addEventListener('click', closeHandler), 0);
}

function plannerSwitchView(mode) {
    document.querySelectorAll('.planner-view-dropdown').forEach(d => d.style.display = 'none');
    plannerState.viewMode = mode;
    if (mode === 'month') {
        const d = plannerState.weekStart ? new Date(plannerState.weekStart + 'T00:00:00') : new Date();
        plannerState.month = d.getMonth() + 1;
        plannerState.year = d.getFullYear();
    } else {
        plannerState.weekStart = plannerToIsoDate(plannerGetWeekStart());
    }
    // Update toggle button labels
    const label = mode === 'month' ? 'Dieser Monat' : 'Diese Woche';
    document.querySelectorAll('.planner-topbar-thisweek').forEach(b => {
        b.childNodes[0].textContent = label + ' ';
    });
    return loadDisposition();
}

/**
 * Search fallback: ask the backend which date range holds einsätze matching
 * this query and jump the planner to the first hit's month. Returns true
 * when the view actually navigated somewhere new.
 */
async function plannerJumpToSearchMonth(q) {
    try {
        const res = await apiGet(API.disposition + '/search?q=' + encodeURIComponent(q));
        if (!res || !res.von) return false;
        const y = parseInt(res.von.slice(0, 4), 10);
        const m = parseInt(res.von.slice(5, 7), 10);
        if (!y || !m) return false;
        if (plannerState.viewMode === 'month' && plannerState.year === y && plannerState.month === m) {
            return false; // already showing the hit's month — nothing to jump to
        }
        plannerState.viewMode = 'month';
        plannerState.month = m;
        plannerState.year = y;
        document.querySelectorAll('.planner-topbar-thisweek').forEach(b => {
            b.childNodes[0].textContent = 'Dieser Monat ';
        });
        await loadDisposition();
        return true;
    } catch (e) {
        return false;
    }
}

function renderPlannerGrid(data) {
    const grid = document.getElementById('plannerGrid');
    if (!grid) return;

    // Apply client-side filters BEFORE grouping by date so empty days
    // remain visible (looks better than blank ranges).
    const allCards = data.cards || [];
    const visibleCards = plannerFilterCards(allCards);

    // Update the small summary line under the filter bar.
    const sum = document.getElementById('dispoFilterSummary');
    if (sum) {
        const f = plannerState.filters;
        const filtersActive = !!(f.search || f.kunde || f.objekt || f.mitarbeiter || f.bereich);
        if (filtersActive) {
            const parts = [];
            if (f.bereich)     parts.push(`<span class="dispo-chip"><span class="material-symbols-outlined">layers</span> ${f.bereich === '__none__' ? 'Ohne Bereich' : bereichDotHtml(f.bereich, false) + ' ' + escapeHtml(f.bereich)}</span>`);
            if (f.search)      parts.push(`<span class="dispo-chip"><span class="material-symbols-outlined">search</span> "${escapeHtml(f.search)}"</span>`);
            if (f.kunde)       parts.push(`<span class="dispo-chip"><span class="material-symbols-outlined">domain</span> ${escapeHtml(f.kunde)}</span>`);
            if (f.objekt)      parts.push(`<span class="dispo-chip"><span class="material-symbols-outlined">location_on</span> ${escapeHtml(f.objekt)}</span>`);
            if (f.mitarbeiter) parts.push(`<span class="dispo-chip"><span class="material-symbols-outlined">person</span> ${escapeHtml(f.mitarbeiter)}</span>`);
            sum.innerHTML = `<span class="material-symbols-outlined">filter_alt</span> ${visibleCards.length} von ${allCards.length} Einsätzen — ${parts.join(' ')}`;
        } else {
            sum.innerHTML = '';
        }
    }

    const cardsByDate = {};
    visibleCards.forEach(card => {
        if (!cardsByDate[card.date]) cardsByDate[card.date] = [];
        cardsByDate[card.date].push(card);
    });

    if (plannerState.viewMode === 'month') {
        grid.classList.add('planner-grid-month');
        grid.classList.remove('planner-grid');
        // Build calendar-style month grid
        const days = data.days || [];
        if (days.length === 0) { grid.innerHTML = '<div class="planner-loading">Keine Tage</div>'; return; }
        // Weekday headers
        const headers = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'].map(d => `<div class="planner-month-header">${d}</div>`).join('');
        // Pad start: first day might not be Monday
        const firstDate = new Date(days[0].date + 'T00:00:00');
        const startPad = (firstDate.getDay() + 6) % 7; // 0=Mon
        let cells = '';
        for (let i = 0; i < startPad; i++) cells += '<div class="planner-month-cell planner-month-empty"></div>';
        days.forEach(day => {
            const cards = cardsByDate[day.date] || [];
            const count = cards.length;
            // Group the day's cards by customer — mirrors the weekly view's
            // Kunde rows at monthly scale: a mini header (color dot + name +
            // count) followed by that customer's compact cards.
            const groups = {};
            cards.forEach(c => {
                const key = c.title || 'Ohne Kunde';
                (groups[key] = groups[key] || []).push(c);
            });
            const groupNames = Object.keys(groups).sort((a, b) => a.localeCompare(b, 'de'));
            const body = groupNames.map(name => {
                const gCards = groups[name].sort((a, b) => (a.start || '').localeCompare(b.start || ''));
                const gColor = (gCards.find(c => c.kunde_farbe && /^#[0-9a-fA-F]{6}$/.test(c.kunde_farbe)) || {}).kunde_farbe || '';
                const gDot = gColor ? `<span class="planner-kunde-row-dot" style="background:${gColor}"></span>` : '';
                return `
                    <div class="planner-month-kgroup">
                        <div class="planner-month-kgroup-name" title="${escapeHtml(name)}">${gDot}<span class="planner-month-kgroup-label">${escapeHtml(name)}</span><span class="planner-month-kgroup-count">${gCards.length}</span></div>
                        ${gCards.map(card => plannerRenderCard(card, true)).join('')}
                    </div>
                `;
            }).join('') || '<span class="planner-month-empty-label">—</span>';
            cells += `
                <div class="planner-month-cell ${day.is_today ? 'today' : ''} ${count > 0 ? 'has-tasks' : ''}">
                    <div class="planner-month-cell-date">${day.day_name} ${day.label}</div>
                    <div class="planner-month-cell-body">
                        ${body}
                    </div>
                </div>
            `;
        });
        plannerPaintGrid(grid, headers + cells);
    } else {
        grid.classList.add('planner-grid');
        grid.classList.remove('planner-grid-month');

        const days = data.days || [];

        // Sticky day header row (one cell per weekday).
        let html = days.map(day => `
            <div class="planner-day-header ${day.is_today ? 'today' : ''}">
                <div class="planner-day-name">${day.day_name}</div>
                <div class="planner-day-date">${day.label}</div>
            </div>
        `).join('');

        // Group cards by customer — each customer gets a full-width name row
        // followed by one cell per day holding only that customer's shifts.
        const kundeGroups = {};
        visibleCards.forEach(card => {
            const key = card.title || 'Ohne Kunde';
            (kundeGroups[key] = kundeGroups[key] || []).push(card);
        });
        const kundeNames = Object.keys(kundeGroups).sort((a, b) => a.localeCompare(b, 'de'));

        if (kundeNames.length === 0) {
            html += '<div class="planner-week-empty">Keine Einsätze in dieser Woche</div>';
        } else {
            kundeNames.forEach(name => {
                const gCards = kundeGroups[name];
                const gColor = (gCards.find(c => c.kunde_farbe && /^#[0-9a-fA-F]{6}$/.test(c.kunde_farbe)) || {}).kunde_farbe || '';
                const gDot = gColor
                    ? `<span class="planner-kunde-row-dot" style="background:${gColor}"></span>`
                    : '';
                html += `
                    <div class="planner-kunde-row">
                        ${gDot}<span class="planner-kunde-row-name">${escapeHtml(name)}</span>
                        <span class="planner-kunde-row-count">${gCards.length}</span>
                    </div>
                `;
                html += days.map((day, di) => {
                    const cellCards = gCards
                        .filter(c => c.date === day.date)
                        .sort((a, b) => (a.start || '').localeCompare(b.start || ''));
                    return `
                        <div class="planner-kunde-cell ${day.is_today ? 'today' : ''}${di === days.length - 1 ? ' last' : ''}">
                            ${cellCards.map(card => plannerRenderCard(card)).join('')}
                        </div>
                    `;
                }).join('');
            });
        }

        plannerPaintGrid(grid, html);
    }

    plannerSyncHscroll();
}

/** Patch the grid in place: only changed shift cards repaint (no flicker,
 *  scroll position kept) — realtime pushes refresh it for every change. */
function plannerPaintGrid(grid, html) {
    if (typeof hmsMorph === 'function') hmsMorph(grid, html); else grid.innerHTML = html;
}

// The base CSS turns .planner-grid into an inner scroll container only at
// fixed breakpoints (<1500px fine-pointer, <641px). Between those and real
// content width (sidebar, zoom, windowed browsers) the 7×180px columns can
// overflow while the element itself isn't scrollable — the floating
// hscroll arrows then show but scrollBy() is a no-op. Measure the real
// overflow instead: .hscroll-on gives the grid the same scroll-container
// behaviour at any viewport. scrollWidth reports the overflowing content
// width in both states, so the toggle is stable (no flapping).
function plannerSyncHscroll() {
    const grid = document.getElementById('plannerGrid');
    if (!grid) return;
    if (!grid.classList.contains('planner-grid')) {
        grid.classList.remove('hscroll-on');
        return;
    }
    grid.classList.toggle('hscroll-on', grid.scrollWidth - grid.clientWidth > 1);
    if (!grid._hscrollBound && typeof ResizeObserver !== 'undefined') {
        grid._hscrollBound = true;
        // The grid element is rebuilt on every page visit — drop the
        // observer bound to the previous (detached) element.
        if (window._plannerGridRO) window._plannerGridRO.disconnect();
        window._plannerGridRO = new ResizeObserver(plannerSyncHscroll);
        window._plannerGridRO.observe(grid);
    }
}

function plannerRenderCard(card, monthMode = false) {
    const added = parseInt(card.worker_count) || 0;
    const hasReq = card.soll_menge != null && card.soll_menge !== '' && Number(card.soll_menge) > 0;
    const req = hasReq ? Math.round(Number(card.soll_menge)) : 0;
    const isComplete = hasReq ? (added >= req) : (added > 0);
    // Tombstone: day was removed from Bestelleingang → card is locked,
    // grayed-out, read-only. No partial stripes, no click-to-edit.
    const isStorno = !!card.bestell_storno_at;
    let countHtml;
    if (hasReq) {
        countHtml = `<span class="dispo-cnt ${isComplete ? 'dispo-cnt-done' : 'dispo-cnt-added'}">${added}</span>`
                  + `<span class="dispo-cnt-sep">/</span>`
                  + `<span class="dispo-cnt ${isComplete ? 'dispo-cnt-done' : 'dispo-cnt-req'}">${req}</span> Mitarbeiter`;
    } else {
        countHtml = added > 0
            ? `<span class="dispo-cnt dispo-cnt-added">${added}</span> Mitarbeiter`
            : 'Noch nicht zugewiesen';
    }
    const workerNames = (card.workers || []).slice(0, 3).map(worker => `<span class="planner-card-worker">${escapeHtml(plannerWorkerName(worker))}</span>`).join('');
    let cardState = card.planner_state || card.status;
    const isPartial = !isStorno && cardState !== 'confirmed' && cardState !== 'rejected' && !isComplete;
    const nuStatuses = card.nu_statuses || {};
    const nuStatusValues = Object.values(nuStatuses);
    const hasAnyNuPending = nuStatusValues.includes('pending');
    const allNuAccepted = nuStatusValues.length > 0 && !hasAnyNuPending;
    // NU pending status is shown via the chip only — card keeps its planning state color
    let nuChip = '';
    if (card.nu_name) {
        const thisNuStatus = nuStatuses[card.nu_id] || null;
        if (thisNuStatus === 'pending') {
            nuChip = `<span class="planner-card-chip nu-pending-chip"><span class="material-symbols-outlined">schedule</span> ${escapeHtml(card.nu_name)} — Warte</span>`;
        } else if (thisNuStatus === 'accepted') {
            nuChip = `<span class="planner-card-chip nu-accepted-chip"><span class="material-symbols-outlined">check_circle</span> ${escapeHtml(card.nu_name)}</span>`;
        }
    }
    // Storno badge: shown on locked cards to explain why they're grayed out.
    const stornoBadge = isStorno
        ? `<span class="planner-card-chip storno-chip"><span class="material-symbols-outlined">block</span> Vom Bestelleingang entfernt</span>`
        : '';
    // Customer color (kunden.farbe): soft full-card tint (low-alpha gradient of
    // the chosen color, easy on the eyes) + a tinted border + small title dot.
    // The tint only applies while the card is in its neutral/pending state —
    // planning status colors (geplant=gelb, bestätigt=grün, abgelehnt=rot)
    // always take priority so the workflow state stays visible at a glance.
    const kColor = (card.kunde_farbe && /^#[0-9a-fA-F]{6}$/.test(card.kunde_farbe)) ? card.kunde_farbe : '';
    const statusCls = plannerStatusClass(cardState);
    let kColorStyle = '';
    if (kColor && statusCls === 'pending' && !isStorno) {
        // Partial (understaffed) cards keep their diagonal stripes — drawn in
        // the customer's color — so the planning signal is never lost.
        kColorStyle = isPartial
            ? ` style="background:repeating-linear-gradient(45deg, ${kColor}3d 0, ${kColor}3d 9px, ${kColor}12 9px, ${kColor}12 18px);border-color:${kColor}59"`
            : ` style="background:linear-gradient(180deg, ${kColor}1a 0%, ${kColor}33 100%);border-color:${kColor}59"`;
    }
    const kColorDot = kColor
        ? `<span class="planner-card-kdot" style="display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:5px;flex:0 0 auto;background:${kColor};box-shadow:0 0 0 1px rgba(0,0,0,0.15)" title="Kundenfarbe"></span>`
        : '';
    const clickHandler = isStorno ? 'event.preventDefault()' : `openPlannerTaskDetail('${card.einsatz_id}')`;
    if (monthMode) {
        // Compact month card: the customer name lives in the group header
        // above, so the card itself leads with time + object.
        return `
            <button data-mk="e${card.einsatz_id}" class="planner-card planner-card-month ${statusCls}${isPartial ? ' partial' : ''}${isStorno ? ' storno-locked' : ''}${kColor ? ' has-kunde-color' : ''}"${kColorStyle} onclick="${clickHandler}">
                <div class="planner-card-title-row">
                    <span class="planner-card-title">${formatTime(card.start)} - ${formatTime(card.end)}</span>
                    <span class="planner-card-count">${card.worker_count || 0}</span>
                </div>
                <div class="planner-card-month-objekt">${escapeHtml(card.subtitle || '–')}</div>
                ${plannerCardWorkersHtml(card)}
                <div class="planner-card-footer">
                    <span class="planner-card-chip"><span class="material-symbols-outlined">group</span> ${countHtml}</span>
                    ${nuChip}
                    ${stornoBadge}
                    ${plannerCardAnfChip(card.anforderungen)}
                </div>
            </button>
        `;
    }
    return `
        <button data-mk="e${card.einsatz_id}" class="planner-card ${statusCls}${isPartial ? ' partial' : ''}${isStorno ? ' storno-locked' : ''}${kColor ? ' has-kunde-color' : ''}"${kColorStyle} onclick="${clickHandler}">
            <div class="planner-card-title-row">
                <span class="planner-card-title">${kColorDot}${escapeHtml(card.title || card.bestell_id)}</span>
                <span class="planner-card-count">${card.worker_count || 0}</span>
            </div>
            <div class="planner-card-time">${formatTime(card.start)} - ${formatTime(card.end)}</div>
            <div class="planner-card-subtitle">${escapeHtml(card.subtitle || '–')}</div>
            ${plannerCardWorkersHtml(card)}
            <div class="planner-card-footer">
                <span class="planner-card-chip"><span class="material-symbols-outlined">group</span> ${countHtml}</span>
                ${nuChip}
                ${stornoBadge}
                ${plannerCardAnfChip(card.anforderungen)}
            </div>
        </button>
    `;
}

// Assigned-worker name chips on a planner card — first 3 names, "+N" for the
// rest, full list in the tooltip.
function plannerCardWorkersHtml(card) {
    const names = (card.workers || []).map(w => plannerWorkerName(w));
    if (!names.length) return '';
    const shown = names.slice(0, 3)
        .map(n => `<span class="planner-card-worker">${escapeHtml(n)}</span>`).join('');
    const more = names.length > 3
        ? `<span class="planner-card-worker planner-card-worker-more">+${names.length - 3}</span>` : '';
    return `<div class="planner-card-workers" title="${escapeHtml(names.join(', '))}">${shown}${more}</div>`;
}

// Compact Anforderungen chip for a planner card (positions count + total persons).
function plannerCardAnfChip(anf) {
    if (!anf || typeof anf !== 'object') return '';
    const positionen = Array.isArray(anf.positionen) ? anf.positionen : [];
    const total = positionen.reduce((s, p) => s + (parseInt(p.anzahl) || 0), 0);
    const has = anf.qualifikation || anf.kleidung || anf.skill || anf.geschlecht || anf.geschlecht_mix || positionen.length;
    if (!has) return '';
    const label = positionen.length ? `${positionen.length} Pos.${total > 0 ? ' · ' + total + ' P' : ''}` : 'Anforderungen';
    return `<span class="planner-card-chip planner-card-anf-chip" title="Anforderungen hinterlegt"><span class="material-symbols-outlined">assignment</span> ${label}</span>${plannerGeschlechtChip(anf)}`;
}

// Gender-requirement chip — shown directly on the planner card so Dispo sees
// at a glance whether the site requires male/female staff. Aggregates the
// general `geschlecht` field and per-position requirements.
function plannerGeschlechtChip(anf) {
    const positionen = Array.isArray(anf.positionen) ? anf.positionen : [];
    // Priority: explicit geschlecht_mix > per-position sums > legacy single value.
    let m = 0, w = 0;
    if (anf.geschlecht_mix && (anf.geschlecht_mix.maennlich > 0 || anf.geschlecht_mix.weiblich > 0)) {
        m = parseInt(anf.geschlecht_mix.maennlich) || 0;
        w = parseInt(anf.geschlecht_mix.weiblich) || 0;
    } else {
        m = positionen.filter(p => p.geschlecht === 'maennlich').reduce((s, p) => s + (parseInt(p.anzahl) || 1), 0);
        w = positionen.filter(p => p.geschlecht === 'weiblich').reduce((s, p) => s + (parseInt(p.anzahl) || 1), 0);
    }
    const parts = [];
    if (m > 0) parts.push(`♂ ${m}`);
    if (w > 0) parts.push(`♀ ${w}`);
    if (!parts.length && anf.geschlecht) {
        parts.push(anf.geschlecht === 'maennlich' ? '♂ Männlich' : (anf.geschlecht === 'weiblich' ? '♀ Weiblich' : '⚥ Gemischt'));
    }
    if (!parts.length) return '';
    const isW = (anf.geschlecht === 'weiblich' || (w > 0 && m === 0));
    const mixed = (m > 0 && w > 0);
    const bg = mixed ? '#f5f3ff' : (isW ? '#fdf2f8' : '#eff6ff');
    const fg = mixed ? '#7c3aed' : (isW ? '#db2777' : '#2563eb');
    return `<span class="planner-card-chip" style="background:${bg};color:${fg};font-weight:700" title="Geschlecht-Anforderung">${parts.join(' · ')}</span>`;
}

// "♂ 2 · ♀ 1" HTML for the detail panel — mix object or legacy single value.
function plannerGeschlechtMixHtml(anf) {
    const mix = anf.geschlecht_mix;
    if (mix && (mix.maennlich > 0 || mix.weiblich > 0)) {
        const parts = [];
        if (mix.maennlich > 0) parts.push(`<span style="font-weight:700;color:#2563eb">♂ ${parseInt(mix.maennlich)}</span>`);
        if (mix.weiblich > 0) parts.push(`<span style="font-weight:700;color:#db2777">♀ ${parseInt(mix.weiblich)}</span>`);
        return parts.join(' · ');
    }
    return anf.geschlecht ? plannerGeschlechtBadge(anf.geschlecht) : '';
}

// Human label + colored badge for a geschlecht requirement value.
function plannerGeschlechtBadge(g) {
    if (!g) return '–';
    const txt = g === 'maennlich' ? 'Männlich' : (g === 'weiblich' ? 'Weiblich' : 'Gemischt');
    const sym = g === 'maennlich' ? '♂' : (g === 'weiblich' ? '♀' : '⚥');
    const color = g === 'weiblich' ? '#db2777' : (g === 'maennlich' ? '#2563eb' : '#7c3aed');
    return `<span style="font-weight:700;color:${color}">${sym} ${txt}</span>`;
}

// Full Anforderungen panel for the task-detail modal: general fields + a
// Positionen table so Dispo sees exactly who to pick (set in Bestelleingang).
function plannerAnforderungenHtml(anf) {
    if (!anf || typeof anf !== 'object') return '';
    const hasGeneral = anf.qualifikation || anf.kleidung || anf.skill || anf.geschlecht || anf.geschlecht_mix;
    const positionen = Array.isArray(anf.positionen) ? anf.positionen : [];
    if (!hasGeneral && positionen.length === 0) return '';
    const totalPersons = positionen.reduce((s, p) => s + (parseInt(p.anzahl) || 0), 0);
    const field = (icon, label, val, raw) => val ? `<div class="planner-anf-field"><span class="planner-anf-field-label"><span class="material-symbols-outlined">${icon}</span> ${label}</span><span class="planner-anf-field-val">${raw ? val : escapeHtml(val)}</span></div>` : '';
    const rows = positionen.map(p => `
        <tr>
            <td class="planner-anf-anzahl">${(p.anzahl != null && p.anzahl !== '') ? escapeHtml(String(p.anzahl)) : '–'}</td>
            <td>${p.funktion ? escapeHtml(p.funktion) : '–'}</td>
            <td>${plannerGeschlechtBadge(p.geschlecht)}</td>
            <td>${p.qualifikation ? escapeHtml(p.qualifikation) : '–'}</td>
            <td>${p.kleidung ? escapeHtml(p.kleidung) : '–'}</td>
        </tr>
    `).join('');
    return `
        <div class="planner-anf-panel">
            <div class="planner-anf-head"><span class="material-symbols-outlined">assignment</span> Anforderungen${totalPersons > 0 ? ` <span class="planner-anf-total">${totalPersons} Personen</span>` : ''}</div>
            ${hasGeneral ? `<div class="planner-anf-fields">
                ${field('verified', 'Qualifikation', anf.qualifikation)}
                ${field('apparel', 'Kleidung', anf.kleidung)}
                ${field('star', 'Skill / Sprache', anf.skill)}
                ${field('wc', 'Geschlecht', plannerGeschlechtMixHtml(anf), true)}
            </div>` : ''}
            ${positionen.length ? `<table class="planner-anf-table">
                <thead><tr><th>Anz.</th><th>Funktion</th><th>Geschlecht</th><th>Qualifikation</th><th>Kleidung</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>` : ''}
        </div>
    `;
}
