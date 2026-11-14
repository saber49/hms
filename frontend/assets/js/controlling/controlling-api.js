/* ============================================
   Clood-X — Auswertung & Controlling · Data loaders
   Talks to /api/controlling/* and dispatches payloads to the renderers.
   Requires: controlling-core.js, controlling-widgets.js, controlling-charts.js
   ============================================ */

/**
 * Loads the entire Controlling dashboard.
 *
 * Strategy:
 *  1) Try the combined /controlling/dashboard endpoint first — one HTTP
 *     request returns ALL widgets. Replacing 10 parallel requests with 1
 *     keeps the Laravel rate limiter out of reach on repeated F5.
 *  2) If the combined endpoint isn't available (older backend) or fails,
 *     fall back to the legacy fan-out so the page still renders.
 */
async function ctrlLoadAll() {
    // Any explicit reload of LIVE data clears snapshot mode and removes the
    // "Sie sehen einen Snapshot" banner, so the dashboard never shows a
    // banner while displaying fresh numbers.
    if (CTRL.snapshotMode) {
        CTRL.snapshotMode = null;
        document.getElementById('ctrlSnapBanner')?.remove();
    }

    const params = ctrlBuildParams({
        page: CTRL.state.page,
        per_page: CTRL.state.perPage,
        sort: CTRL.state.sort.col,
        dir: CTRL.state.sort.dir,
        granularity: CTRL.state.granularity === 'auto' ? null : CTRL.state.granularity,
    });

    // Worker deep-dive card runs in parallel to the dashboard payload; it
    // hides itself when no Mitarbeiter filter is active.
    if (typeof ctrlLoadWorker === 'function') ctrlLoadWorker();

    const data = await apiGetNoCancel(CTRL.api + '/dashboard?' + params);

    const looksValid = data && !Array.isArray(data) && !data.error
        && data.kpi && data.breakdown && data.trend;

    if (looksValid) {
        // Cache breakdown payloads so switching the Verteilung tab doesn't
        // need an extra request — we already have the data.
        CTRL.cache.breakdown = data.breakdown;
        try { await renderDashboardPayload(data); }
        catch (e) { console.error('[Controlling] dashboard render failed', e); }
        return;
    }

    // Fallback: legacy fan-out.
    console.warn('[Controlling] combined /dashboard unavailable, falling back to fan-out');
    await Promise.allSettled([
        ctrlLoadKpi(),
        ctrlLoadTrend(),
        ctrlLoadDonut(currentBdTab()),
        ctrlLoadBreakdownTable('customers',      'ctrlTopKunden',       'Kunde'),
        ctrlLoadBreakdownTable('objects',        'ctrlTopObjekte',      'Objekt'),
        ctrlLoadBreakdownTable('subcontractors', 'ctrlTopNu',           'NU'),
        ctrlLoadBreakdownTable('mitarbeiter',    'ctrlTopMitarbeiter',  'Mitarbeiter'),
        ctrlLoadBreakdownTable('artikel',        'ctrlTopArtikel',      'Artikel'),
        ctrlLoadSchichtart(),
        ctrlLoadHeatmap(),
        ctrlLoadForecast(),
        ctrlLoadDetails(),
    ]);
}

/**
 * Dispatch the combined /dashboard payload to the per-widget renderers,
 * mirroring the per-endpoint code paths so behaviour is identical.
 */
async function renderDashboardPayload(d) {
    // --- KPI ---
    if (d.kpi && d.kpi.kpi && d.kpi.period) {
        renderPeriodLabel(d.kpi);
        renderKpiCards(d.kpi);
    } else {
        renderKpiError();
    }

    // --- Schichtart (plain array shape) ---
    renderSchichtartFromArray(Array.isArray(d.breakdown?.schichtart) ? d.breakdown.schichtart : []);

    // --- Breakdown tables ---
    renderBreakdownTable(d.breakdown?.customers,      'ctrlTopKunden',       'Kunde');
    renderBreakdownTable(d.breakdown?.objects,        'ctrlTopObjekte',      'Objekt');
    renderBreakdownTable(d.breakdown?.subcontractors, 'ctrlTopNu',           'NU');
    // Mitarbeiter / Artikel — added in v4. If the combined endpoint is from
    // an older backend (no mitarbeiter/artikel keys), fall back to a fetch.
    if (d.breakdown?.mitarbeiter) renderBreakdownTable(d.breakdown.mitarbeiter, 'ctrlTopMitarbeiter', 'Mitarbeiter');
    else                          ctrlLoadBreakdownTable('mitarbeiter', 'ctrlTopMitarbeiter', 'Mitarbeiter');
    if (d.breakdown?.artikel)     renderBreakdownTable(d.breakdown.artikel,     'ctrlTopArtikel',     'Artikel');
    else                          ctrlLoadBreakdownTable('artikel',     'ctrlTopArtikel',     'Artikel');

    // --- Heatmap ---
    if (d.heatmap && d.heatmap.grid) renderHeatmap(d.heatmap.grid, d.heatmap);
    else                             renderHeatmapError();

    // --- Details (with pager) ---
    if (d.details && Array.isArray(d.details.rows)) renderDetails(d.details);

    // --- Charts: only after Chart.js is ready ---
    if (await ensureChartReady()) {
        if (d.trend && Array.isArray(d.trend.points)) renderTrendChart(d.trend);
        else showChartError('ctrlTrendChart');

        const donutSrc = pickBreakdownForTab(d.breakdown, currentBdTab());
        if (donutSrc) renderDonut(donutSrc);
        else showChartError('ctrlDonut');

        if (d.forecast && Array.isArray(d.forecast.history)) renderForecast(d.forecast);
        else showChartError('ctrlForecastChart');
    } else {
        showChartError('ctrlTrendChart');
        showChartError('ctrlDonut');
        showChartError('ctrlForecastChart');
    }
}

// ============================================================
// PER-WIDGET LOADERS (used by the fan-out fallback and by
// toolbar interactions that only need to refresh one card)
// ============================================================
async function ctrlLoadKpi() {
    const data = await apiGetNoCancel(CTRL.api + '/kpi?' + ctrlBuildParams());
    // apiGet returns [] on AbortError or network error — detect that explicitly
    if (!data || Array.isArray(data) || data.error || !data.kpi || !data.period) {
        console.warn('[Controlling] KPI load failed', data);
        renderKpiError();
        return;
    }
    renderPeriodLabel(data);
    renderKpiCards(data);
}

async function ctrlLoadTrend() {
    const g = CTRL.state.granularity === 'auto' ? null : CTRL.state.granularity;
    const data = await apiGetNoCancel(CTRL.api + '/trend?' + ctrlBuildParams({ granularity: g }));
    if (!data || Array.isArray(data) || data.error || !Array.isArray(data.points)) {
        console.warn('[Controlling] Trend load failed', data);
        return showChartError('ctrlTrendChart');
    }
    if (!(await ensureChartReady())) return showChartError('ctrlTrendChart');
    renderTrendChart(data);
}

async function ctrlLoadDonut(dim) {
    // Prefer the slice cached from the combined dashboard payload — tab
    // switching is then instant and saves one HTTP request per click.
    const cached = pickBreakdownForTab(CTRL.cache.breakdown, dim);
    if (cached && Array.isArray(cached.rows)) {
        if (!(await ensureChartReady())) return showChartError('ctrlDonut');
        return renderDonut(cached);
    }

    // limit=10 matches ctrlLoadBreakdownTable so the server-side cache entry
    // is shared between donut and Top-N table.
    const data = await apiGetNoCancel(CTRL.api + '/breakdown/' + dim + '?' + ctrlBuildParams({ limit: 10 }));
    if (!data || Array.isArray(data) || data.error || !Array.isArray(data.rows)) {
        console.warn('[Controlling] Donut load failed', dim, data);
        return showChartError('ctrlDonut');
    }
    if (!(await ensureChartReady())) return showChartError('ctrlDonut');
    renderDonut(data);
}

async function ctrlLoadBreakdownTable(dim, containerId, headLabel) {
    const data = await apiGetNoCancel(CTRL.api + '/breakdown/' + dim + '?' + ctrlBuildParams({ limit: 10 }));
    if (!data || Array.isArray(data) || data.error || !Array.isArray(data.rows)) {
        console.warn('[Controlling] Breakdown ' + dim + ' load failed', data);
        renderBreakdownTable(null, containerId, headLabel);
        return;
    }
    renderBreakdownTable(data, containerId, headLabel);
}

/**
 * Loads the Schichtart breakdown. The endpoint returns a plain array, not
 * the {rows:[]} envelope used by the other breakdowns.
 */
async function ctrlLoadSchichtart() {
    const data = await apiGetNoCancel(CTRL.api + '/breakdown/schichtart?' + ctrlBuildParams());
    renderSchichtartFromArray(Array.isArray(data) ? data : []);
}

async function ctrlLoadHeatmap() {
    const data = await apiGetNoCancel(CTRL.api + '/heatmap?' + ctrlBuildParams());
    if (!data || Array.isArray(data) || data.error || !data.grid) {
        console.warn('[Controlling] Heatmap load failed', data);
        return renderHeatmapError();
    }
    renderHeatmap(data.grid, data);
}

async function ctrlLoadForecast() {
    const g = CTRL.state.granularity === 'auto' ? null : CTRL.state.granularity;
    const data = await apiGetNoCancel(CTRL.api + '/forecast?' + ctrlBuildParams({ granularity: g, horizon: 3 }));
    if (!data || Array.isArray(data) || data.error || !Array.isArray(data.history)) {
        console.warn('[Controlling] Forecast load failed', data);
        return showChartError('ctrlForecastChart');
    }
    if (!(await ensureChartReady())) return showChartError('ctrlForecastChart');
    renderForecast(data);
}

async function ctrlLoadDetails() {
    const params = ctrlBuildParams({
        page: CTRL.state.page,
        per_page: CTRL.state.perPage,
        sort: CTRL.state.sort.col,
        dir: CTRL.state.sort.dir,
    });
    const data = await apiGetNoCancel(CTRL.api + '/details?' + params);
    if (!data || Array.isArray(data) || data.error || !Array.isArray(data.rows)) {
        console.warn('[Controlling] Details load failed', data);
        return renderDetailsError();
    }
    renderDetails(data);
}

// ============================================================
// EXPORT
// ============================================================
function exportCsv() {
    const token = getAuthToken();
    if (!token) return;
    const url = CTRL.api + '/export.xlsx?' + ctrlBuildParams();
    // Bearer auth + download — use fetch + blob
    fetch(url, { headers: { 'Authorization': 'Bearer ' + token } })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const cd = r.headers.get('Content-Disposition') || '';
            const m = cd.match(/filename="?([^";]+)"?/);
            return r.blob().then(blob => ({ blob, name: m ? m[1] : 'controlling.xlsx' }));
        })
        .then(({ blob, name }) => {
            const u = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = u;
            a.download = name;
            document.body.appendChild(a);
            a.click();
            setTimeout(() => { URL.revokeObjectURL(u); a.remove(); }, 200);
        })
        .catch(() => showToast('Export fehlgeschlagen.', 'error'));
}
