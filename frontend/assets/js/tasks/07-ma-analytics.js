// tasks/07-ma-analytics.js — Monatsabschluss: Analytics-Tab (Chart.js), Monat/Zeitraum-Navigation
// Teil 7/10 des früheren tasks.js. Alle Teile teilen den globalen Scope und MÜSSEN in dieser Reihenfolge geladen werden (PAGE_MODULE_SCRIPTS in app.js).

// ============================================================
// ANALYTICS TAB — Charts via Chart.js
// ============================================================
/** Current value of a page colour token (follows the Mitarbeiter/Kunde mode theme). */
function maCssVar(name, fallback) {
    const v = getComputedStyle(document.querySelector('.ma-page') || document.body).getPropertyValue(name).trim();
    return v || fallback;
}

function maEnsureChartJs(callback) {
    if (typeof Chart !== 'undefined') { callback(); return; }
    if (document.getElementById('maChartJsScript')) {
        const check = setInterval(() => {
            if (typeof Chart !== 'undefined') { clearInterval(check); callback(); }
        }, 100);
        return;
    }
    const script = document.createElement('script');
    script.id = 'maChartJsScript';
    script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    script.onload = () => callback();
    script.onerror = () => showToast('Chart.js konnte nicht geladen werden', 'error');
    document.head.appendChild(script);
}

function maRenderAnalytics() {
    const mainBody = document.getElementById('maMainBody');
    if (!mainBody || !_maData) return;

    mainBody.innerHTML = `
        <div class="ma-analytics-grid">
            <div class="ma-chart-card">
                <div class="ma-chart-card-title">${maIcon('bar_chart', 'font-size:15px')} Tägliche Verteilung — Gesamtstunden pro Tag</div>
                <div class="ma-chart-canvas"><canvas id="maChartDaily"></canvas></div>
            </div>
            <div class="ma-chart-card">
                <div class="ma-chart-card-title">${maIcon('pie_chart', 'font-size:15px')} Stunden nach Bereich</div>
                <div class="ma-chart-canvas-small"><canvas id="maChartBereich"></canvas></div>
            </div>
        </div>
        <div class="ma-analytics-grid">
            <div class="ma-chart-card">
                <div class="ma-chart-card-title">${maIcon('show_chart', 'font-size:15px')} Intern vs. Nachunternehmer</div>
                <div class="ma-chart-canvas-small"><canvas id="maChartSource"></canvas></div>
            </div>
            <div class="ma-chart-card">
                <div class="ma-chart-card-title">${maIcon('trophy', 'font-size:15px')} Top 10 Mitarbeiter</div>
                <div id="maTopWorkers" class="ma-top-list"></div>
            </div>
        </div>
        <div class="ma-analytics-grid">
            <div class="ma-chart-card">
                <div class="ma-chart-card-title">${maIcon('domain', 'font-size:15px')} Top 10 Objekte</div>
                <div id="maTopObjects" class="ma-top-list"></div>
            </div>
            <div class="ma-chart-card">
                <div class="ma-chart-card-title">${maIcon('handshake', 'font-size:15px')} Nachunternehmer-Anteil</div>
                <div id="maNuList" class="ma-top-list"></div>
            </div>
        </div>`;

    maRenderTopLists();
    maEnsureChartJs(() => {
        maDestroyCharts();
        maRenderDailyChart();
        maRenderBereichChart();
        maRenderSourceChart();
    });
}

function maDestroyCharts() {
    Object.values(_maCharts).forEach(c => { try { c.destroy(); } catch (e) {} });
    _maCharts = {};
}

function maRenderDailyChart() {
    const canvas = document.getElementById('maChartDaily');
    if (!canvas) return;

    // Determine the date window for the X axis. In range mode we use the actual
    // ?von / ?bis the backend echoed back; in month mode we use the full month.
    let startIso, endIso;
    if (_maRangeMode && _maData && _maData.von && _maData.bis) {
        startIso = _maData.von;
        endIso = _maData.bis;
    } else {
        const pad = n => String(n).padStart(2, '0');
        const daysInMonth = new Date(_maPageYear, _maPageMonth, 0).getDate();
        startIso = `${_maPageYear}-${pad(_maPageMonth)}-01`;
        endIso   = `${_maPageYear}-${pad(_maPageMonth)}-${pad(daysInMonth)}`;
    }

    // Build the day labels — short form when the window is large (> 31 days)
    const startMs = new Date(startIso + 'T00:00:00').getTime();
    const endMs   = new Date(endIso   + 'T00:00:00').getTime();
    const totalDays = Math.max(1, Math.round((endMs - startMs) / 86400000) + 1);
    const labels = [];
    const internalData = new Array(totalDays).fill(0);
    const nuData = new Array(totalDays).fill(0);
    const useShort = totalDays > 31;
    const [sy, sm, sd] = startIso.split('-').map(Number);
    for (let i = 0; i < totalDays; i++) {
        const d = new Date(sy, sm - 1, sd + i);
        labels.push(useShort
            ? `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}`
            : String(d.getDate()));
    }

    const bucketIdx = isoDate => {
        if (!isoDate) return -1;
        const ms = new Date(isoDate + 'T00:00:00').getTime();
        const idx = Math.round((ms - startMs) / 86400000);
        return (idx >= 0 && idx < totalDays) ? idx : -1;
    };

    (_maData.entries || []).forEach(e => {
        const idx = bucketIdx(e.datum);
        if (idx >= 0) internalData[idx] += maCalcEntryStd(e);
    });
    (_maData.nu_entries || []).forEach(e => {
        const idx = bucketIdx(e.datum);
        if (idx >= 0) nuData[idx] += maCalcEntryStd(e);
    });

    _maCharts.daily = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'Intern', data: internalData, backgroundColor: maCssVar('--ma-primary', '#15803d'), borderRadius: 4 },
                { label: 'NU', data: nuData, backgroundColor: maCssVar('--ma-nu', '#1e40af'), borderRadius: 4 },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: { stacked: true, ticks: { callback: v => v + 'h' } }
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' h' } }
            }
        }
    });
}

function maRenderBereichChart() {
    const canvas = document.getElementById('maChartBereich');
    if (!canvas) return;

    const byBereich = {};
    (_maData.entries || []).forEach(e => {
        const b = e.bereich || 'Sonstige';
        byBereich[b] = (byBereich[b] || 0) + maCalcEntryStd(e);
    });
    (_maData.nu_entries || []).forEach(e => {
        const b = 'NU: ' + (e.nu_firmenname || 'Unbekannt');
        byBereich[b] = (byBereich[b] || 0) + maCalcEntryStd(e);
    });

    const labels = Object.keys(byBereich);
    const values = Object.values(byBereich);
    const colors = [maCssVar('--ma-primary', '#15803d'), '#ca8a04', maCssVar('--ma-nu', '#1e40af'), '#7c3aed', '#0891b2', '#f59e0b', '#dc2626', '#10b981'];

    if (labels.length === 0) {
        canvas.parentElement.innerHTML = '<div style="text-align:center;color:var(--ma-text-muted);padding:60px 20px;font-size:0.85rem">Keine Daten</div>';
        return;
    }

    _maCharts.bereich = new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: labels.map((l, i) => (!l.startsWith('NU: ') && l !== 'Sonstige' && typeof bereichColor === 'function') ? bereichColor(l) : colors[i % colors.length]),
                borderColor: maCssVar('--ma-surface', '#fff'),
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 }, padding: 8 } },
                tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed.toFixed(2) + ' h' } }
            }
        }
    });
}

function maRenderSourceChart() {
    const canvas = document.getElementById('maChartSource');
    if (!canvas) return;
    const internalStd = _maData.total_std || 0;
    const nuStd = _maData.nu_total_std || 0;

    if (internalStd === 0 && nuStd === 0) {
        canvas.parentElement.innerHTML = '<div style="text-align:center;color:var(--ma-text-muted);padding:60px 20px;font-size:0.85rem">Keine Daten</div>';
        return;
    }

    _maCharts.source = new Chart(canvas.getContext('2d'), {
        type: 'pie',
        data: {
            labels: ['Intern', 'Nachunternehmer'],
            datasets: [{
                data: [internalStd, nuStd],
                backgroundColor: [maCssVar('--ma-primary', '#15803d'), maCssVar('--ma-nu', '#1e40af')],
                borderColor: maCssVar('--ma-surface', '#fff'),
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed.toFixed(2) + ' h (' + ((ctx.parsed / (internalStd + nuStd)) * 100).toFixed(1) + '%)' } }
            }
        }
    });
}

function maRenderTopLists() {
    // Top Workers
    const workers = [];
    Object.values(_maPageWorkers).forEach(w => {
        const name = ((w.vorname || '') + ' ' + (w.name || '')).trim() || w.dienst_nr;
        workers.push({ name, std: maCalcWorkerTotal(w.entries), entries: w.entries.length, isNu: false });
    });
    Object.entries(_maPageNuWorkers).forEach(([firma, ws]) => {
        Object.values(ws).forEach(w => {
            const name = ((w.vorname || '') + ' ' + (w.name || '')).trim() || w.personal_nr || ('NU-' + w.nu_worker_id);
            workers.push({ name: name + ' (' + firma + ')', std: maCalcWorkerTotal(w.entries), entries: w.entries.length, isNu: true });
        });
    });
    workers.sort((a, b) => b.std - a.std);
    const topWorkers = workers.slice(0, 10);
    const topWorkersHtml = topWorkers.length === 0
        ? '<div style="color:var(--ma-text-muted);font-size:0.85rem;padding:14px;text-align:center">Keine Daten</div>'
        : topWorkers.map((w, i) => {
            const rankCls = i === 0 ? 'gold' : (i === 1 ? 'silver' : (i === 2 ? 'bronze' : ''));
            return `<div class="ma-top-item">
                <span class="ma-top-rank ${rankCls}">${i + 1}</span>
                <span class="ma-top-name">${escapeHtml(w.name)}</span>
                <span class="ma-top-value">${w.std.toFixed(1)}h</span>
            </div>`;
        }).join('');
    const tw = document.getElementById('maTopWorkers');
    if (tw) tw.innerHTML = topWorkersHtml;

    // Top Objects
    const objects = {};
    [...(_maData.entries || []), ...(_maData.nu_entries || [])].forEach(e => {
        const o = e.objektname || '–';
        if (!objects[o]) objects[o] = { std: 0, entries: 0 };
        objects[o].std += maCalcEntryStd(e);
        objects[o].entries++;
    });
    const topObjects = Object.entries(objects).sort((a, b) => b[1].std - a[1].std).slice(0, 10);
    const topObjectsHtml = topObjects.length === 0
        ? '<div style="color:var(--ma-text-muted);font-size:0.85rem;padding:14px;text-align:center">Keine Daten</div>'
        : topObjects.map(([name, info], i) => {
            const rankCls = i === 0 ? 'gold' : (i === 1 ? 'silver' : (i === 2 ? 'bronze' : ''));
            return `<div class="ma-top-item">
                <span class="ma-top-rank ${rankCls}">${i + 1}</span>
                <span class="ma-top-name">${escapeHtml(name)}</span>
                <span class="ma-top-value">${info.std.toFixed(1)}h</span>
            </div>`;
        }).join('');
    const to = document.getElementById('maTopObjects');
    if (to) to.innerHTML = topObjectsHtml;

    // NU Companies list
    const nuList = Object.entries(_maPageNuWorkers).map(([firma, ws]) => {
        const std = Object.values(ws).reduce((sum, w) => sum + maCalcWorkerTotal(w.entries), 0);
        return { firma, workers: Object.keys(ws).length, std };
    }).sort((a, b) => b.std - a.std);
    const nuListHtml = nuList.length === 0
        ? '<div style="color:var(--ma-text-muted);font-size:0.85rem;padding:14px;text-align:center">Keine NU-Einsätze</div>'
        : nuList.map((nu, i) => {
            const rankCls = i === 0 ? 'gold' : (i === 1 ? 'silver' : (i === 2 ? 'bronze' : ''));
            return `<div class="ma-top-item">
                <span class="ma-top-rank ${rankCls}">${i + 1}</span>
                <span class="ma-top-name">${escapeHtml(nu.firma)} <small style="color:var(--ma-text-muted);font-weight:500"> · ${nu.workers} MA</small></span>
                <span class="ma-top-value">${nu.std.toFixed(1)}h</span>
            </div>`;
        }).join('');
    const nl = document.getElementById('maNuList');
    if (nl) nl.innerHTML = nuListHtml;
}

// ============================================================
// MONTH / RANGE NAVIGATION
// ============================================================
function maPageChangeMonth(offset) {
    // Prev/Next chevrons only operate in single-month mode.
    if (_maRangeMode) return;
    _maPageMonth += offset;
    if (_maPageMonth > 12) { _maPageMonth = 1; _maPageYear++; }
    if (_maPageMonth < 1) { _maPageMonth = 12; _maPageYear--; }
    _maEditAllMode = false;
    maDestroyCharts();
    renderMonatsabschlussContent();
}

function maPageGoCurrentMonth() {
    _maPageMonth = new Date().getMonth() + 1;
    _maPageYear = new Date().getFullYear();
    _maRangeMode = false;
    _maRangeStart = '';
    _maRangeEnd = '';
    _maEditAllMode = false;
    maDestroyCharts();
    renderMonatsabschlussContent();
}

// ----------------------------------------------------------------
// Date range picker — popover with presets + custom from/to inputs
// ----------------------------------------------------------------
function maToggleRangePicker(ev) {
    if (ev) ev.stopPropagation();
    const pop = document.getElementById('maRangePopover');
    if (!pop) return;
    if (pop.style.display === '' || pop.style.display === 'block') {
        pop.style.display = 'none';
        pop.innerHTML = '';
        document.removeEventListener('click', maRangePopoverOutsideClick, true);
        return;
    }
    pop.innerHTML = maBuildRangePopoverHtml();
    pop.style.display = 'block';
    // Close on outside click
    setTimeout(() => document.addEventListener('click', maRangePopoverOutsideClick, true), 0);
}

function maRangePopoverOutsideClick(ev) {
    const pop = document.getElementById('maRangePopover');
    if (!pop) return;
    if (pop.contains(ev.target) || (ev.target.closest && ev.target.closest('.ma-range-btn'))) return;
    pop.style.display = 'none';
    pop.innerHTML = '';
    document.removeEventListener('click', maRangePopoverOutsideClick, true);
}

function maBuildRangePopoverHtml() {
    const isoToday = maTodayIso();
    const startVal = _maRangeMode ? _maRangeStart : isoToday;
    const endVal   = _maRangeMode ? _maRangeEnd   : isoToday;
    return `
        <div class="ma-range-popover-title">${maIcon('date_range', 'font-size:14px;vertical-align:-2px')} Zeitraum wählen</div>
        <div class="ma-range-presets">
            <button class="ma-range-preset" onclick="maApplyRangePreset('current-month')">Aktueller Monat</button>
            <button class="ma-range-preset" onclick="maApplyRangePreset('prev-month')">Vormonat</button>
            <button class="ma-range-preset" onclick="maApplyRangePreset('last-30')">Letzte 30 Tage</button>
            <button class="ma-range-preset" onclick="maApplyRangePreset('last-90')">Letzte 90 Tage</button>
            <button class="ma-range-preset" onclick="maApplyRangePreset('q1')">Q1 (Jan–März)</button>
            <button class="ma-range-preset" onclick="maApplyRangePreset('q2')">Q2 (Apr–Juni)</button>
            <button class="ma-range-preset" onclick="maApplyRangePreset('q3')">Q3 (Juli–Sept)</button>
            <button class="ma-range-preset" onclick="maApplyRangePreset('q4')">Q4 (Okt–Dez)</button>
            <button class="ma-range-preset" onclick="maApplyRangePreset('ytd')">Jahr bis heute</button>
        </div>
        <div class="ma-range-custom">
            <div class="ma-range-custom-title">Benutzerdefiniert</div>
            <div class="ma-range-custom-row">
                <label>Von <input type="date" id="maRangeFromInput" value="${startVal}"></label>
                <label>Bis <input type="date" id="maRangeToInput" value="${endVal}"></label>
            </div>
            <div class="ma-range-custom-actions">
                ${_maRangeMode ? `<button class="ma-range-reset" onclick="maResetRange()">${maIcon('undo', 'font-size:13px')} Zurücksetzen</button>` : ''}
                <button class="ma-range-apply" onclick="maApplyCustomRange()">${maIcon('check', 'font-size:13px')} Anwenden</button>
            </div>
        </div>`;
}

function maApplyCustomRange() {
    const from = document.getElementById('maRangeFromInput')?.value || '';
    const to   = document.getElementById('maRangeToInput')?.value   || '';
    if (!from || !to) { showToast('Bitte beide Daten wählen', 'error'); return; }
    let start = from, end = to;
    if (start > end) { [start, end] = [end, start]; }
    _maRangeMode = true;
    _maRangeStart = start;
    _maRangeEnd = end;
    _maEditAllMode = false;
    maDestroyCharts();
    renderMonatsabschlussContent();
}

function maApplyRangePreset(preset) {
    const now = new Date();
    const y = now.getFullYear();
    const pad = n => String(n).padStart(2, '0');
    const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    let start, end;
    switch (preset) {
        case 'current-month': {
            _maPageMonth = now.getMonth() + 1;
            _maPageYear = y;
            _maRangeMode = false;
            _maRangeStart = '';
            _maRangeEnd = '';
            _maEditAllMode = false;
            maDestroyCharts();
            renderMonatsabschlussContent();
            return;
        }
        case 'prev-month': {
            const pm = now.getMonth() === 0 ? 12 : now.getMonth();
            const py = now.getMonth() === 0 ? y - 1 : y;
            _maPageMonth = pm;
            _maPageYear = py;
            _maRangeMode = false;
            _maRangeStart = '';
            _maRangeEnd = '';
            _maEditAllMode = false;
            maDestroyCharts();
            renderMonatsabschlussContent();
            return;
        }
        case 'last-30':
            end = iso(now);
            start = iso(new Date(now.getTime() - 29 * 86400000));
            break;
        case 'last-90':
            end = iso(now);
            start = iso(new Date(now.getTime() - 89 * 86400000));
            break;
        case 'q1': start = `${y}-01-01`; end = `${y}-03-31`; break;
        case 'q2': start = `${y}-04-01`; end = `${y}-06-30`; break;
        case 'q3': start = `${y}-07-01`; end = `${y}-09-30`; break;
        case 'q4': start = `${y}-10-01`; end = `${y}-12-31`; break;
        case 'ytd':
            start = `${y}-01-01`;
            end = iso(now);
            break;
        default: return;
    }
    _maRangeMode = true;
    _maRangeStart = start;
    _maRangeEnd = end;
    _maEditAllMode = false;
    maDestroyCharts();
    renderMonatsabschlussContent();
}

function maResetRange() {
    _maRangeMode = false;
    _maRangeStart = '';
    _maRangeEnd = '';
    _maPageMonth = new Date().getMonth() + 1;
    _maPageYear = new Date().getFullYear();
    _maEditAllMode = false;
    maDestroyCharts();
    renderMonatsabschlussContent();
}
