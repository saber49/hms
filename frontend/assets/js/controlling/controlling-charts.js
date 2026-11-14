/* ============================================
   Clood-X — Auswertung & Controlling · Charts
   Trend / Donut / Forecast renderers + Chart.js option helpers.
   Requires: controlling-core.js, Chart.js
   ============================================ */

function showChartError(canvasId) {
    const cv = document.getElementById(canvasId);
    if (!cv) return;
    const wrap = cv.closest('.ctrl-chart-wrap');
    if (wrap) wrap.innerHTML = ctrlEmptyHtml('show_chart', 'Diagramm konnte nicht geladen werden');
}

// ============================================================
// TREND
// ============================================================
function renderTrendChart(d) {
    const ctx = document.getElementById('ctrlTrendChart');
    if (!ctx) return;
    const labels = d.points.map(p => p.bucket);
    const einnahmen = d.points.map(p => p.einnahmen);
    const kosten    = d.points.map(p => p.kosten);
    const gewinn    = d.points.map(p => p.gewinn);

    if (CTRL.charts.trend) CTRL.charts.trend.destroy();

    CTRL.charts.trend = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                lineDataset('Umsatz',  einnahmen, CTRL.palette.success, true),
                lineDataset('Kosten',  kosten,    CTRL.palette.warning, false),
                lineDataset('Gewinn',  gewinn,    CTRL.palette.accent,  true),
            ],
        },
        options: chartBaseOpts({ currency: true }),
    });
}

// ============================================================
// DONUT (Verteilung)
// ============================================================
function renderDonut(d) {
    const ctx = document.getElementById('ctrlDonut');
    if (!ctx) return;
    const rows = (d.rows || []).slice(0, 8);
    if (rows.length === 0) {
        if (CTRL.charts.donut) { CTRL.charts.donut.destroy(); CTRL.charts.donut = null; }
        ctx.parentNode.innerHTML = `<div class="ctrl-empty" style="height:100%">${ctrlIcon('pie_chart')}<div>Keine Daten</div></div>`;
        return;
    }
    if (CTRL.charts.donut) CTRL.charts.donut.destroy();
    // Breakdown by Bereich → each slice in the Bereich's company colour.
    const colors = (d.dimension === 'bereich' && typeof bereichColor === 'function')
        ? rows.map(r => bereichColor(r.dim_value))
        : donutColors(rows.length);

    CTRL.charts.donut = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: rows.map(r => r.dim_value),
            datasets: [{
                data: rows.map(r => r.einnahmen),
                backgroundColor: colors,
                borderColor: '#fff',
                borderWidth: 2,
                hoverOffset: 6,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            // Click a slice → drill-down panel for that entity.
            onClick: (_evt, els) => {
                const r = els && els.length ? rows[els[0].index] : null;
                const dim = (typeof CTRL_BD_DRILL_DIM !== 'undefined') ? CTRL_BD_DRILL_DIM[d.dimension] : null;
                const id = dim === 'bereich' ? (r && r.dim_value !== '–' ? r.dim_value : null) : (r ? r.dim_id : null);
                if (id && dim && typeof openDrill === 'function') openDrill(dim, id, r.dim_value);
            },
            onHover: (evt, els) => { if (evt?.native?.target) evt.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
            plugins: {
                legend: {
                    position: 'right',
                    labels: { boxWidth: 10, padding: 8, font: { size: 11 } },
                },
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.label}: ${formatCurrency(ctx.parsed)}`,
                    },
                },
            },
        },
    });
}

// ============================================================
// FORECAST
// ============================================================
function renderForecast(d) {
    const ctx = document.getElementById('ctrlForecastChart');
    if (!ctx) return;
    const histLabels = d.history.map(p => p.bucket);
    const fcLabels   = d.forecast.einnahmen.map((_, i) => 'F+' + (i + 1));
    const labels     = [...histLabels, ...fcLabels];

    const einHist = d.history.map(p => p.einnahmen);
    const einFc   = d.forecast.einnahmen;
    const gwHist  = d.history.map(p => p.gewinn);
    const gwFc    = d.forecast.gewinn;

    const einSeries = [...einHist, ...Array(einFc.length).fill(null)];
    const einFcSer  = [...Array(einHist.length).fill(null), ...einFc];
    const gwSeries  = [...gwHist,  ...Array(gwFc.length).fill(null)];
    const gwFcSer   = [...Array(gwHist.length).fill(null),  ...gwFc];

    // Connect last hist point to first forecast for visual continuity
    if (einHist.length > 0) {
        einFcSer[einHist.length - 1] = einHist[einHist.length - 1];
        gwFcSer[gwHist.length - 1]   = gwHist[gwHist.length - 1];
    }

    if (CTRL.charts.forecast) CTRL.charts.forecast.destroy();
    CTRL.charts.forecast = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                lineDataset('Umsatz (Ist)',    einSeries, CTRL.palette.success, true),
                lineDataset('Umsatz (Prog.)',  einFcSer,  CTRL.palette.success, true, true),
                lineDataset('Gewinn (Ist)',    gwSeries,  CTRL.palette.accent,  true),
                lineDataset('Gewinn (Prog.)',  gwFcSer,   CTRL.palette.accent,  true, true),
            ],
        },
        options: chartBaseOpts({ currency: true }),
    });

    // Forecast tiles
    const grid = document.getElementById('ctrlFcGrid');
    if (grid) {
        const totalRev    = einFc.reduce((a,b) => a + b, 0);
        const totalProfit = gwFc.reduce((a,b) => a + b, 0);
        const avgRev      = einFc.length ? totalRev / einFc.length : 0;
        const avgProfit   = gwFc.length ? totalProfit / gwFc.length : 0;
        const horizonLbl  = einFc.length + ' Perioden';
        grid.innerHTML = `
          <div class="ctrl-fc-item"><div class="lbl">Horizont</div><div class="val">${horizonLbl}</div></div>
          <div class="ctrl-fc-item"><div class="lbl">Erwarteter Umsatz</div><div class="val">${formatCurrency(totalRev)}</div></div>
          <div class="ctrl-fc-item"><div class="lbl">Ø Umsatz/Periode</div><div class="val">${formatCurrency(avgRev)}</div></div>
          <div class="ctrl-fc-item"><div class="lbl">Erwarteter Gewinn</div><div class="val">${formatCurrency(totalProfit)}</div></div>
          <div class="ctrl-fc-item"><div class="lbl">Ø Gewinn/Periode</div><div class="val">${formatCurrency(avgProfit)}</div></div>
        `;
    }

    // Update the "Basis: letzte X Perioden" chip to reflect the actual
    // history sample size used by the regression (varies per granularity:
    // day=30, week=24, month=18, quarter=12, year=8).
    const chip = document.getElementById('ctrlFcInfo');
    if (chip) {
        const n = (typeof d.history_n === 'number' && d.history_n > 0)
            ? d.history_n
            : (Array.isArray(d.history) ? d.history.length : 12);
        const unit = ({
            day: 'Tage', week: 'Wochen', month: 'Monate',
            quarter: 'Quartale', year: 'Jahre',
        })[d.granularity] || 'Perioden';
        chip.textContent = `Basis: letzte ${n} ${unit}`;
    }
}

// ============================================================
// CHART HELPERS
// ============================================================
function lineDataset(label, data, color, fill, dashed = false) {
    return {
        label,
        data,
        borderColor: color,
        backgroundColor: fill ? color + '22' : 'transparent',
        fill: fill,
        tension: 0.32,
        borderWidth: 2,
        borderDash: dashed ? [6, 4] : [],
        pointRadius: 2.5,
        pointHoverRadius: 5,
        spanGaps: true,
    };
}

function chartBaseOpts({ currency = false } = {}) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { labels: { boxWidth: 12, padding: 12, font: { size: 11 } } },
            tooltip: {
                callbacks: {
                    label: ctx => `${ctx.dataset.label}: ${currency ? formatCurrency(ctx.parsed.y) : ctx.parsed.y}`,
                },
            },
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: {
                grid: { color: 'rgba(0,0,0,0.04)' },
                ticks: {
                    font: { size: 10 },
                    callback: v => currency
                        ? new Intl.NumberFormat('de-DE', { notation: 'compact', maximumFractionDigits: 1 }).format(v) + ' €'
                        : v,
                },
            },
        },
    };
}

function donutColors(n) {
    const base = [
        CTRL.palette.accent, CTRL.palette.success, CTRL.palette.warning,
        CTRL.palette.info, CTRL.palette.orange, CTRL.palette.danger,
        CTRL.palette.primary, CTRL.palette.gray,
    ];
    const out = [];
    for (let i = 0; i < n; i++) out.push(base[i % base.length]);
    return out;
}
