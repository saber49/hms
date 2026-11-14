/* ============================================
   Clood-X — Auswertung & Controlling · Mitarbeiter-Analyse
   Deep-dive card for ONE internal worker: hours, when (weekday / start
   hour), where (Objekte), profit/loss, and the concrete shifts.
   Shown whenever the "Mitarbeiter" filter is set (dropdown or click on a
   row in "Top Mitarbeiter"). Backend: GET /controlling/mitarbeiter/{dienst_nr}
   Requires: controlling-core.js, controlling-widgets.js, controlling-charts.js
   ============================================ */

const CTRL_HOURS_FMT = v => (Number(v) || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' h';

/** Skeleton/placeholder shell — the card is hidden until a worker is chosen. */
function ctrlWorkerCardHtml() {
    return `
      <div class="ctrl-card ctrl-worker-card" id="ctrlWorkerCard" style="display:none;margin-bottom:18px">
        <div class="ctrl-card-header">
          <h3 class="ctrl-card-title">${ctrlIcon('badge')} Mitarbeiter-Analyse <span id="ctrlWorkerName" class="ctrl-worker-name"></span></h3>
          <div class="ctrl-card-toolbar">
            <span class="chip" id="ctrlWorkerLohn" style="cursor:default"></span>
            <md-outlined-button id="ctrlWorkerClear"><md-icon slot="icon">close</md-icon>Filter aufheben</md-outlined-button>
          </div>
        </div>
        <div id="ctrlWorkerBody">${ctrlBdSkeleton()}</div>
      </div>
    `;
}

/** Populate the Mitarbeiter dropdown (called in background on page render). */
async function ctrlLoadWorkerOptions() {
    const sel = document.getElementById('ctrlMitarbeiter');
    if (!sel) return;
    const rows = await apiGetNoCancel(CTRL.api + '/mitarbeiter-options');
    if (!Array.isArray(rows)) return;
    const optHtml = rows.map(r => `<option value="${escapeHtml(r.dienst_nr)}">${escapeHtml(r.dienst_nr)} – ${escapeHtml(r.name)}</option>`).join('');
    sel.innerHTML = ctrlToMdOptions(optHtml, 'Alle Mitarbeiter', CTRL.state.filters.dienst_nr);
}

/**
 * Select a worker programmatically (e.g. click on a Top-Mitarbeiter row):
 * sets the filter, syncs the dropdown and reloads the whole dashboard so
 * EVERY card is scoped to that worker.
 */
function ctrlSelectWorker(dienstNr) {
    CTRL.state.filters.dienst_nr = dienstNr || '';
    CTRL.state.page = 1;
    const sel = document.getElementById('ctrlMitarbeiter');
    if (sel) sel.value = CTRL.state.filters.dienst_nr;
    ctrlLoadAll();
    if (dienstNr) document.getElementById('ctrlWorkerCard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/** Called from ctrlLoadAll(): shows + fills the card, or hides it. */
async function ctrlLoadWorker() {
    const card = document.getElementById('ctrlWorkerCard');
    if (!card) return;
    const dn = CTRL.state.filters.dienst_nr;
    if (!dn) {
        card.style.display = 'none';
        if (CTRL.charts.worker) { CTRL.charts.worker.destroy(); CTRL.charts.worker = null; }
        return;
    }
    card.style.display = '';
    document.getElementById('ctrlWorkerBody').innerHTML = ctrlBdSkeleton();

    const g = CTRL.state.granularity === 'auto' ? null : CTRL.state.granularity;
    const data = await apiGetNoCancel(CTRL.api + '/mitarbeiter/' + encodeURIComponent(dn) + '?' + ctrlBuildParams({ granularity: g }));
    if (!data || Array.isArray(data) || data.error || !data.kpi) {
        console.warn('[Controlling] Worker analysis load failed', data);
        document.getElementById('ctrlWorkerBody').innerHTML = ctrlEmptyHtml('warning', 'Mitarbeiter-Analyse konnte nicht geladen werden');
        return;
    }
    renderWorkerAnalysis(data);
}

// ============================================================
// RENDER
// ============================================================
function renderWorkerAnalysis(d) {
    const k = d.kpi;
    const m = d.mitarbeiter || {};
    const nameEl = document.getElementById('ctrlWorkerName');
    if (nameEl) nameEl.textContent = `· ${m.name || m.dienst_nr || ''} (${m.dienst_nr || '–'})`;
    const lohnEl = document.getElementById('ctrlWorkerLohn');
    if (lohnEl) lohnEl.textContent = m.stundenlohn ? `Stundenlohn ${formatCurrency(m.stundenlohn)}/h` : 'Kein Stundenlohn hinterlegt';

    const body = document.getElementById('ctrlWorkerBody');
    if (!body) return;

    if (!k.einsaetze) {
        body.innerHTML = ctrlEmptyHtml('badge', 'Keine Einsätze dieses Mitarbeiters im Zeitraum');
        return;
    }

    const gewinnColor = (k.gewinn || 0) >= 0 ? 'var(--success)' : 'var(--danger)';
    const tile = (lbl, val, sub = '', color = '') =>
        `<div class="ctrl-fc-item"><div class="lbl">${lbl}</div><div class="val"${color ? ` style="color:${color}"` : ''}>${val}</div>${sub ? `<div class="sub">${sub}</div>` : ''}</div>`;

    body.innerHTML = `
      <div class="ctrl-forecast-grid ctrl-worker-kpis">
        ${tile('Arbeitsstunden', CTRL_HOURS_FMT(k.stunden), `Ø ${CTRL_HOURS_FMT(k.stunden_pro_einsatz)} / Einsatz`)}
        ${tile('Einsätze', k.einsaetze, `${k.bestaetigt} Kunde freigegeben · ${k.arbeitstage} Arbeitstage`)}
        ${tile('Umsatzanteil', formatCurrency(k.einnahmen), 'anteilig nach Stunden')}
        ${tile('Personal (Vollkosten)', formatCurrency(k.ma_kosten), 'Stunden × Lohn × Vollkostenfaktor')}
        ${tile('Deckungsbeitrag II', formatCurrency(k.gewinn), `${(k.marge || 0).toLocaleString('de-DE')} % Marge · ${formatCurrency(k.gewinn_pro_stunde)}/h`, gewinnColor)}
        ${tile('Einsatzorte', k.objekte, `${k.kunden} Kunden`)}
      </div>

      <div class="ctrl-worker-grid">
        <div class="ctrl-worker-block">
          <h4>${ctrlIcon('location_on', 'font-size:14px;vertical-align:-2px')} Wo hat der Mitarbeiter gearbeitet?</h4>
          ${renderWorkerObjekte(d.by_objekt)}
        </div>
        <div class="ctrl-worker-block">
          <h4>${ctrlIcon('schedule', 'font-size:14px;vertical-align:-2px')} Wann? Stunden nach Wochentag</h4>
          ${renderWorkerBars(d.by_weekday, 'dow', 7, r => WEEKDAYS_DE[r.dow] || '?')}
          <h4 style="margin-top:14px">${ctrlIcon('wb_twilight', 'font-size:14px;vertical-align:-2px')} Schichtbeginn (Uhrzeit)</h4>
          ${renderWorkerStartHours(d.by_start_hour)}
        </div>
      </div>

      <div class="ctrl-worker-block">
        <h4>${ctrlIcon('show_chart', 'font-size:14px;vertical-align:-2px')} Verlauf: Stunden &amp; DB II</h4>
        <div class="ctrl-chart-wrap" style="min-height:200px"><canvas id="ctrlWorkerChart"></canvas></div>
      </div>

      <div class="ctrl-worker-block">
        <h4>${ctrlIcon('list', 'font-size:14px;vertical-align:-2px')} Einsätze im Zeitraum
          <small style="color:var(--gray-500);font-weight:400">(${d.einsaetze.length}${d.einsaetze.length >= d.einsaetze_limit ? '+, neueste zuerst' : ''})</small>
        </h4>
        <div style="overflow-x:auto">${renderWorkerEinsaetze(d.einsaetze)}</div>
      </div>
    `;

    ensureChartReady().then(ok => {
        if (!ok) return showChartError('ctrlWorkerChart');
        renderWorkerTrendChart(d.trend || []);
    });
}

function renderWorkerObjekte(rows) {
    if (!Array.isArray(rows) || rows.length === 0) return ctrlEmptyHtml('folder_open', 'Keine Daten');
    const maxH = Math.max(...rows.map(r => r.ist_stunden || 0), 1);
    return `
      <table class="ctrl-bd-table">
        <thead><tr><th>Objekt</th><th class="num">Einsätze</th><th class="num">Stunden</th><th class="num">Umsatz</th><th class="num">DB II</th></tr></thead>
        <tbody>
          ${rows.map(r => `
            <tr>
              <td>
                <div class="name" title="${escapeHtml(r.dim_value)}">${escapeHtml(r.dim_value)}</div>
                <div style="font-size:0.72rem;color:var(--gray-500)">${escapeHtml(r.kundenname || '')}</div>
                <div class="ctrl-bd-bar"><span style="width:${((r.ist_stunden || 0) / maxH * 100).toFixed(1)}%"></span></div>
              </td>
              <td class="num">${r.einsaetze || 0}</td>
              <td class="num">${CTRL_HOURS_FMT(r.ist_stunden)}</td>
              <td class="num">${formatCurrency(r.einnahmen)}</td>
              <td class="num" style="color:${(r.gewinn || 0) >= 0 ? 'var(--success)' : 'var(--danger)'}">${formatCurrency(r.gewinn)}</td>
            </tr>`).join('')}
        </tbody>
      </table>`;
}

/**
 * Horizontal bar list over a fixed index range 0..count-1 (e.g. weekdays).
 * rows: [{[key]: idx, stunden, einsaetze}] — missing indices render as empty.
 */
function renderWorkerBars(rows, key, count, labelFn) {
    const list = Array.from({ length: count }, (_, i) =>
        (rows || []).find(x => x[key] === i) || { [key]: i, stunden: 0, einsaetze: 0 });
    const max = Math.max(...list.map(r => r.stunden || 0), 1);
    return `<div class="ctrl-worker-bars">${list.map(r => `
        <div class="ctrl-worker-bar-row" title="${r.einsaetze || 0} Einsätze">
          <span class="lbl">${labelFn(r)}</span>
          <span class="bar"><span style="width:${((r.stunden || 0) / max * 100).toFixed(1)}%"></span></span>
          <span class="val">${(r.stunden || 0) ? CTRL_HOURS_FMT(r.stunden) : '–'}</span>
        </div>`).join('')}</div>`;
}

function renderWorkerStartHours(rows) {
    if (!Array.isArray(rows) || rows.length === 0) return `<div style="font-size:0.78rem;color:var(--gray-500)">Keine Uhrzeiten erfasst.</div>`;
    const max = Math.max(...rows.map(r => r.stunden || 0), 1);
    return `<div class="ctrl-worker-bars">${rows.map(r => `
        <div class="ctrl-worker-bar-row" title="${r.einsaetze} Einsätze · ${CTRL_HOURS_FMT(r.stunden)}">
          <span class="lbl">${String(r.hour).padStart(2, '0')}:00</span>
          <span class="bar"><span style="width:${((r.stunden || 0) / max * 100).toFixed(1)}%"></span></span>
          <span class="val">${r.einsaetze}×</span>
        </div>`).join('')}</div>`;
}

function renderWorkerEinsaetze(rows) {
    if (!Array.isArray(rows) || rows.length === 0) return ctrlEmptyHtml('inbox', 'Keine Einsätze');
    const time = r => (r.ist_start || r.ist_ende)
        ? `${r.ist_start || '–'} – ${r.ist_ende || '–'}${r.pause_minuten ? ` <small style="color:var(--gray-500)">(${r.pause_minuten} min Pause)</small>` : ''}`
        : '<span style="color:var(--gray-400)">–</span>';
    return `
      <table class="ctrl-details-table">
        <thead><tr>
          <th>Datum</th><th>Uhrzeit</th><th>Kunde</th><th>Objekt</th><th>Artikel</th>
          <th class="num">Stunden</th><th class="num">Umsatzanteil</th><th class="num">Personal (voll)</th><th class="num">DB II</th><th>Status</th>
        </tr></thead>
        <tbody>
          ${rows.map(r => `
            <tr>
              <td>${formatDate(r.datum)} <small style="color:var(--gray-500)">${WEEKDAYS_DE[(new Date(r.datum).getDay() + 6) % 7] || ''}</small></td>
              <td>${time(r)}</td>
              <td>${escapeHtml(r.kundenname || '–')}</td>
              <td>${escapeHtml(r.objektname || '–')}</td>
              <td>${escapeHtml(r.artikel_name || '–')}</td>
              <td class="num">${CTRL_HOURS_FMT(r.stunden)}</td>
              <td class="num">${formatCurrency(r.einnahmen)}</td>
              <td class="num">${formatCurrency(r.ma_kosten)}</td>
              <td class="num" style="font-weight:600;color:${(r.gewinn || 0) >= 0 ? 'var(--success)' : 'var(--danger)'}">${formatCurrency(r.gewinn)}</td>
              <td>${ctrlStatusPill(r.hms_bestaetigt)}</td>
            </tr>`).join('')}
        </tbody>
      </table>`;
}

function renderWorkerTrendChart(points) {
    const ctx = document.getElementById('ctrlWorkerChart');
    if (!ctx) return;
    if (CTRL.charts.worker) CTRL.charts.worker.destroy();
    const opts = chartBaseOpts({ currency: true });
    opts.scales.y1 = {
        position: 'right',
        grid: { display: false },
        ticks: { font: { size: 10 }, callback: v => v + ' h' },
    };
    opts.plugins.tooltip.callbacks.label = c => `${c.dataset.label}: ${c.dataset.yAxisID === 'y1' ? CTRL_HOURS_FMT(c.parsed.y) : formatCurrency(c.parsed.y)}`;
    CTRL.charts.worker = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: points.map(p => p.bucket),
            datasets: [
                { type: 'bar', label: 'Stunden', data: points.map(p => p.ist_stunden), yAxisID: 'y1',
                  backgroundColor: CTRL.palette.accent + '55', borderColor: CTRL.palette.accent, borderWidth: 1, borderRadius: 4 },
                { ...lineDataset('DB II', points.map(p => p.gewinn), CTRL.palette.success, false), yAxisID: 'y' },
                { ...lineDataset('Personal (voll)', points.map(p => p.ma_kosten), CTRL.palette.warning, false), yAxisID: 'y' },
            ],
        },
        options: opts,
    });
}
