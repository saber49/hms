/* ============================================
   Clood-X — Auswertung & Controlling · Snapshots
   Drawer (list/delete), save prompt, frozen-snapshot rendering + banner.
   Requires: controlling-core.js, controlling-widgets.js,
             controlling-charts.js, controlling-api.js
   ============================================ */

function ctrlSnapDrawerHtml() {
    return `
      <div class="ctrl-snap-drawer-overlay" id="ctrlSnapOverlay"></div>
      <aside class="ctrl-snap-drawer" id="ctrlSnapDrawer">
        <div class="ctrl-snap-head">
          <h3>${ctrlIcon('bookmark', 'font-size:15px;vertical-align:-2px')} Gespeicherte Snapshots</h3>
          <md-icon-button id="ctrlSnapClose" title="Schließen" aria-label="Schließen"><md-icon>close</md-icon></md-icon-button>
        </div>
        <div class="ctrl-snap-list" id="ctrlSnapList">
          <div class="ctrl-empty">${ctrlSpinner(28)}<div>Laden…</div></div>
        </div>
      </aside>
    `;
}

function bindCtrlSnapDrawer() {
    // Mounted on <body>, not inside #content — `.content` has a permanent
    // transform (pageFadeIn) that would re-anchor this fixed drawer to the
    // page top instead of the viewport.
    ['ctrlSnapOverlay', 'ctrlSnapDrawer'].forEach(id => document.getElementById(id)?.remove());
    document.body.insertAdjacentHTML('beforeend', ctrlSnapDrawerHtml());
    document.getElementById('ctrlSnapClose').addEventListener('click', closeSnapDrawer);
    document.getElementById('ctrlSnapOverlay').addEventListener('click', closeSnapDrawer);
}

// ============================================================
// DRAWER
// ============================================================
async function openSnapDrawer() {
    document.getElementById('ctrlSnapDrawer').classList.add('open');
    document.getElementById('ctrlSnapOverlay').classList.add('active');
    const list = document.getElementById('ctrlSnapList');
    list.innerHTML = `<div class="ctrl-empty">${ctrlSpinner(28)}<div>Laden…</div></div>`;
    const data = await apiGetNoCancel(CTRL.api + '/snapshots');
    if (!data || data.error || !Array.isArray(data) || data.length === 0) {
        list.innerHTML = `<div class="ctrl-empty">${ctrlIcon('bookmark')}<div>Noch keine Snapshots gespeichert.</div></div>`;
        return;
    }
    list.innerHTML = data.map(s => `
      <div class="ctrl-snap-item" data-id="${s.id}">
        <div class="t">${escapeHtml(s.title)}</div>
        <div class="m">
          <span>${formatDate(s.date_from)} – ${formatDate(s.date_bis)}</span>
          <md-icon-button class="del md-icon-danger" data-id="${s.id}" title="Löschen" aria-label="Löschen"><md-icon>delete</md-icon></md-icon-button>
        </div>
        <div style="font-size:0.7rem;color:var(--gray-400);margin-top:4px">
          ${escapeHtml(s.period_type || '')} · ${escapeHtml(s.created_by || '–')}
        </div>
      </div>
    `).join('');

    list.querySelectorAll('.ctrl-snap-item').forEach(it => {
        it.addEventListener('click', e => {
            if (e.target.closest('.del')) return;
            loadSnapshot(it.dataset.id);
        });
    });
    list.querySelectorAll('.del').forEach(btn => {
        btn.addEventListener('click', async e => {
            e.stopPropagation();
            const ok = await mdConfirm('Snapshot wirklich löschen?', { title: 'Snapshot löschen', confirmLabel: 'Löschen' });
            if (!ok) return;
            const r = await apiDelete(CTRL.api + '/snapshots/' + btn.dataset.id);
            if (r && !r.error) openSnapDrawer(); // refresh
        });
    });
}

function closeSnapDrawer() {
    // Also called on every hashchange after Controlling was visited — the
    // drawer is gone on other pages (threw on each navigation before).
    document.getElementById('ctrlSnapDrawer')?.classList.remove('open');
    document.getElementById('ctrlSnapOverlay')?.classList.remove('active');
}

// ============================================================
// SAVE
// ============================================================
async function saveSnapshotPrompt() {
    const def = `Snapshot ${new Date().toLocaleDateString('de-DE')}`;
    const title = await mdPrompt('Titel für den Snapshot:', { title: 'Snapshot speichern', type: 'text', label: 'Titel', value: def });
    if (!title) return;
    const params = ctrlBuildParams({ title, notes: '', period_type: CTRL.state.period });
    const url = CTRL.api + '/snapshots?' + params;
    const res = await apiPost(url, { title, notes: '', period_type: CTRL.state.period });
    if (res && !res.error) {
        showToast('Snapshot gespeichert', 'success');
    } else {
        showToast('Fehler beim Speichern: ' + (res && res.error ? res.error : 'unbekannt'), 'error');
    }
}

// ============================================================
// LOAD (frozen view)
// ============================================================
/**
 * Open a saved snapshot: renders the STORED payload directly (no refetch
 * against live data) and shows a persistent banner so the user knows the
 * numbers are frozen. Filters/date range are restored so subsequent edits
 * start from the same state the snapshot was taken in.
 */
async function loadSnapshot(id) {
    const snap = await apiGetNoCancel(CTRL.api + '/snapshots/' + id);
    if (!snap || snap.error) return;
    closeSnapDrawer();

    if (snap.filters) {
        CTRL.state.filters.kunden_id = snap.filters.kunden_id || '';
        CTRL.state.filters.obj_id    = snap.filters.obj_id    || '';
        CTRL.state.filters.nu_id     = snap.filters.nu_id     || '';
        CTRL.state.filters.bereich   = snap.filters.bereich   || '';
        CTRL.state.filters.dienst_nr = snap.filters.dienst_nr || '';
        const k = document.getElementById('ctrlKunde');       if (k) k.value = CTRL.state.filters.kunden_id;
        const n = document.getElementById('ctrlNu');          if (n) n.value = CTRL.state.filters.nu_id;
        const b = document.getElementById('ctrlBereich');     if (b) b.value = CTRL.state.filters.bereich;
        const w = document.getElementById('ctrlMitarbeiter'); if (w) w.value = CTRL.state.filters.dienst_nr;
    }
    // The worker card is live data (not part of the snapshot) — hide it.
    const wc = document.getElementById('ctrlWorkerCard'); if (wc) wc.style.display = 'none';
    CTRL.state.period = 'custom';
    CTRL.state.from = snap.date_from;
    CTRL.state.to   = snap.date_bis;
    document.querySelectorAll('#ctrlPeriodSwitcher button').forEach(x => x.classList.remove('active'));
    document.querySelector('#ctrlPeriodSwitcher button[data-p="custom"]')?.classList.add('active');
    const customRange = document.querySelector('.ctrl-custom-range');
    if (customRange) customRange.style.display = '';
    const fEl = document.getElementById('ctrlFrom'); if (fEl) fEl.value = snap.date_from;
    const tEl = document.getElementById('ctrlTo');   if (tEl) tEl.value = snap.date_bis;

    CTRL.snapshotMode = { id: snap.id, title: snap.title, date_from: snap.date_from, date_bis: snap.date_bis };
    showSnapshotBanner(CTRL.snapshotMode);
    await renderFromSnapshot(snap);
}

/**
 * Sticky banner above the KPI row indicating a frozen snapshot is shown,
 * with a button to return to live data.
 */
function showSnapshotBanner(snap) {
    let banner = document.getElementById('ctrlSnapBanner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'ctrlSnapBanner';
        banner.className = 'ctrl-snap-banner';
        banner.style.cssText = 'background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;padding:10px 14px;border-radius:8px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:0.85rem';
        const kpis = document.getElementById('ctrlKpis');
        if (kpis && kpis.parentNode) kpis.parentNode.insertBefore(banner, kpis);
    }
    banner.innerHTML = `
      <span>
        ${ctrlIcon('bookmark', 'font-size:13px;vertical-align:-2px;margin-right:6px')}
        <strong>Snapshot:</strong> ${escapeHtml(snap.title)}
        <small style="color:#1e3a8a;margin-left:8px">${formatDate(snap.date_from)} – ${formatDate(snap.date_bis)} · eingefroren</small>
      </span>
      <md-outlined-button id="ctrlSnapExit">
        <md-icon slot="icon">refresh</md-icon>Live-Daten anzeigen
      </md-outlined-button>
    `;
    document.getElementById('ctrlSnapExit')?.addEventListener('click', exitSnapshotMode);
}

function exitSnapshotMode() {
    CTRL.snapshotMode = null;
    document.getElementById('ctrlSnapBanner')?.remove();
    ctrlLoadAll();
}

/**
 * Render the dashboard from a saved snapshot payload. Widgets the snapshot
 * doesn't contain (older saves) get a "nicht im Snapshot enthalten" notice.
 */
async function renderFromSnapshot(snap) {
    // --- KPI --- saveSnapshot stores ONLY the kpi object; fabricate the
    // {kpi, delta} wrapper so renderKpiCards() works unchanged.
    if (snap.kpi) {
        renderKpiCards({
            kpi: snap.kpi,
            delta: { einnahmen:null, kosten:null, gewinn:null, marge:null, einsaetze:null, ist_stunden:null },
        });
        const lbl = document.getElementById('ctrlPeriodLabel');
        if (lbl) lbl.textContent = `${formatDate(snap.date_from)} – ${formatDate(snap.date_bis)} · Snapshot`;
    } else {
        renderKpiError();
    }

    // --- Top breakdowns ---
    const bd = snap.breakdowns || {};
    renderBreakdownTable(bd.kunden,  'ctrlTopKunden',  'Kunde');
    renderBreakdownTable(bd.objekte, 'ctrlTopObjekte', 'Objekt');
    renderBreakdownTable(bd.nu,      'ctrlTopNu',      'NU');
    if (bd.mitarbeiter) renderBreakdownTable(bd.mitarbeiter, 'ctrlTopMitarbeiter', 'Mitarbeiter');
    else                snapshotMissingWidget('ctrlTopMitarbeiter', 'Mitarbeiter-Top nicht im Snapshot enthalten');
    if (bd.artikel)     renderBreakdownTable(bd.artikel,     'ctrlTopArtikel',     'Artikel');
    else                snapshotMissingWidget('ctrlTopArtikel',     'Artikel-Top nicht im Snapshot enthalten');

    // --- Schichtart ---
    if (Array.isArray(snap.schichtart)) renderSchichtartFromArray(snap.schichtart);
    else snapshotMissingWidget('ctrlSchichtart', 'Schicht-Aufschläge nicht im Snapshot enthalten');

    // --- Heatmap ---
    if (snap.heatmap && snap.heatmap.grid) renderHeatmap(snap.heatmap.grid, snap.heatmap);
    else snapshotMissingWidget('ctrlHeatmap', 'Heatmap nicht im Snapshot enthalten');

    // --- Details: snapshots don't store paginated rows, so refetch a single
    //     page from /details (still scoped to the snapshot's date range).
    ctrlLoadDetails();

    // --- Charts ---
    if (await ensureChartReady()) {
        if (Array.isArray(snap.trend) && snap.trend.length) renderTrendChart({ points: snap.trend });
        else showChartError('ctrlTrendChart');

        const donutSrc = ({
            customers:      bd.kunden,
            objects:        bd.objekte,
            subcontractors: bd.nu,
            bereich:        null,
        })[currentBdTab()];
        if (donutSrc) renderDonut(donutSrc); else showChartError('ctrlDonut');

        if (snap.forecast && Array.isArray(snap.forecast.history)) renderForecast(snap.forecast);
        else showChartError('ctrlForecastChart');
    }
}

function snapshotMissingWidget(containerId, msg) {
    const el = document.getElementById(containerId);
    if (el) el.innerHTML = ctrlEmptyHtml('bookmark', escapeHtml(msg));
}
