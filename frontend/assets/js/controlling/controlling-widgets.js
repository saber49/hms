/* ============================================
   Clood-X — Auswertung & Controlling · Widgets
   Non-chart renderers: KPI cards, breakdown tables, Schichtart,
   heatmap and the paginated details table.
   Requires: controlling-core.js
   ============================================ */

// ============================================================
// KPI CARDS
// ============================================================
function renderKpiCards(d) {
    const k = d.kpi;
    const dl = d.delta;
    // MA-Kosten card is only shown when at least one tenant Mitarbeiter has
    // a stundenlohn configured (ma_kosten > 0). For tenants who haven't
    // filled wages yet, the card stays hidden so the layout doesn't show
    // a meaningless "0 €" pile.
    const maKosten = Number(k.ma_kosten || 0);
    const cards = [
        kpiCard('k-revenue', 'euro',           'Einnahmen (bestätigt)', formatCurrency(k.einnahmen), dl.einnahmen, `${k.bestaetigt_count} bestätigte Einsätze`),
        kpiCard('k-cost',    'paid',           'Kosten NU (bestätigt)', formatCurrency(k.kosten),     dl.kosten, 'Subunternehmer-Vergütung'),
    ];
    if (maKosten > 0) {
        cards.push(kpiCard('k-ma-cost', 'person', 'Kosten Mitarbeiter (bestätigt)',
            formatCurrency(maKosten), dl.ma_kosten,
            'Stunden × Lohn inkl. Schichtzuschläge'));
    }
    cards.push(
        kpiCard('k-profit',  'show_chart',     'Gewinn (bestätigt)',    formatCurrency(k.gewinn),     dl.gewinn, `Ø ${formatCurrency(k.gewinn_pro_einsatz)} / bestätigtem Einsatz`),
        kpiCard('k-margin',  'percent',        'Marge (bestätigt)',     (k.marge || 0).toLocaleString('de-DE') + ' %', dl.marge, k.einnahmen > 0 ? 'Profitabilität' : 'Keine Einnahmen'),
        kpiCard('k-orders',  'assignment',     'Einsätze gesamt',        k.einsaetze_total,             dl.einsaetze, `${k.bestaetigt_count} bestätigt · ${k.ausstehend_count} offen · ${k.nicht_bestaetigt_count} abgelehnt`, true),
        kpiCard('k-hours',   'timer',          'Leistungsstd. bestätigt/Soll', `${(k.ist_stunden || 0).toLocaleString('de-DE')}/${(k.soll_stunden || 0).toLocaleString('de-DE')}`, dl.ist_stunden,
            `${(k.ist_stunden_offen || 0).toLocaleString('de-DE')} h offen${k.auslastung_pct !== null ? ` · ${k.auslastung_pct}% bestätigt` : ''}`),
    );
    document.getElementById('ctrlKpis').innerHTML = cards.join('');

    // Banner lives in its own container right above the KPI row (kept
    // separate so the KPI grid layout is untouched).
    let bn = document.getElementById('ctrlUnconfirmed');
    if (!bn) {
        bn = document.createElement('div');
        bn.id = 'ctrlUnconfirmed';
        const kpis = document.getElementById('ctrlKpis');
        kpis?.parentNode?.insertBefore(bn, kpis);
    }
    bn.innerHTML = unconfirmedBannerHtml(k) + dataQualityBannerHtml(k);
}

/**
 * Warning banner whenever Einsätze are still `ausstehend`.
 * Main KPIs count confirmed shifts only; the banner shows the separate
 * expected result if every open shift is confirmed.
 */
function unconfirmedBannerHtml(k, opts = {}) {
    if (!k) return '';
    const total = Number(k.einsaetze_total || 0);
    const offen = Number(k.ausstehend_count || 0);
    if (!total || !offen) return '';
    const ratio = offen / total;
    const hasForecast = typeof k.einnahmen_erwartet === 'number' || typeof k.einnahmen_offen === 'number';
    const erwEin = hasForecast ? Number(k.einnahmen_erwartet ?? (Number(k.einnahmen || 0) + Number(k.einnahmen_offen || 0))) : null;
    const erwGew = hasForecast ? Number(k.gewinn_erwartet ?? (Number(k.gewinn || 0) + Number(k.einnahmen_offen || 0) - Number(k.kosten_offen || 0))) : null;
    const pct = Math.round(ratio * 100);
    const scope = opts.scope ? ` (${escapeHtml(opts.scope)})` : '';
    return `
      <div class="ctrl-unconfirmed ${opts.compact ? 'compact' : ''}" role="status">
        ${ctrlIcon('pending_actions', 'font-size:20px;flex-shrink:0')}
        <div class="txt">
          <b>${offen.toLocaleString('de-DE')} von ${total.toLocaleString('de-DE')} Einsätzen (${pct} %) noch nicht für den Kunden freigegeben${scope}</b> –
          die Kennzahlen der Abrechnungssicht enthalten nur in der Zeiterfassung freigegebene Stunden.
          ${hasForecast ? `<span class="exp">Nach Freigabe aller geleisteten Einsätze erwartet: Umsatz <b>${formatCurrency(erwEin)}</b> · DB II <b style="color:${erwGew >= 0 ? 'var(--success)' : 'var(--danger)'}">${formatCurrency(erwGew)}</b></span>` : ''}
          <small>Nicht freigegebene Stunden werden getrennt ausgewiesen und nicht mit dem abrechenbaren Ergebnis vermischt.</small>
        </div>
      </div>`;
}

function dataQualityBannerHtml(k) {
    const q = k && k.datenqualitaet;
    if (!q) return '';
    const missingCount = Number(q.ma_ohne_lohn_count || 0);
    const missingHours = Number(q.ma_stunden_ohne_lohn || 0);
    const actualHours = Number(q.ma_stunden_erfasst || 0);
    const fallbackHours = Number(q.ma_stunden_plan_fallback || 0);
    const missingStates = Number(q.objekte_ohne_bundesland || 0);
    if (!missingCount && !fallbackHours && !missingStates) return '';
    const parts = [];
    if (missingCount) parts.push(`<b>${missingCount.toLocaleString('de-DE')} Mitarbeiter ohne Lohnangabe</b> (${missingHours.toLocaleString('de-DE', { maximumFractionDigits: 2 })} h ohne Personalkosten)`);
    if (fallbackHours) parts.push(`<b>${fallbackHours.toLocaleString('de-DE', { maximumFractionDigits: 2 })} h</b> aus Planwerten statt erfasster Mitarbeiterzeit`);
    if (missingStates) parts.push(`<b>${missingStates.toLocaleString('de-DE')} Objekte ohne Bundesland</b> (regionale Feiertage nicht bestimmbar)`);
    return `<div class="ctrl-unconfirmed" role="status">
      ${ctrlIcon('data_alert', 'font-size:20px;flex-shrink:0')}
      <div class="txt"><b>Datenqualität:</b> ${parts.join(' · ')}.
      <small>Erfasste Mitarbeiterzeit: ${actualHours.toLocaleString('de-DE', { maximumFractionDigits: 2 })} h. Fehlende Eingaben werden nicht erfunden und können Kosten/Ergebnis verfälschen.</small></div>
    </div>`;
}

function renderKpiError() {
    document.getElementById('ctrlKpis').innerHTML =
        `<div class="ctrl-empty" style="grid-column:1/-1">${ctrlIcon('warning')}<div>KPIs konnten nicht geladen werden.</div></div>`;
}

/** Sets the "Zeitraum · Vergleich" label under the page title from a KPI payload. */
function renderPeriodLabel(kpiPayload) {
    const lbl = document.getElementById('ctrlPeriodLabel');
    if (!lbl || !kpiPayload || !kpiPayload.period) return;
    lbl.textContent = (kpiPayload.period.label || '') + ' · ' +
        (kpiPayload.compare && kpiPayload.compare.label ? 'Vergleich ' + kpiPayload.compare.label : '');
}

function kpiCard(cls, icon, label, value, delta, sub, isCount = false) {
    const deltaHtml = deltaBadge(delta, isCount);
    return `
      <div class="ctrl-kpi ${cls}">
        <div class="ctrl-kpi-head">
          <span>${label}</span>
          ${ctrlIcon(icon, 'font-size:15px')}
        </div>
        <div class="ctrl-kpi-value">${value}</div>
        <div class="ctrl-kpi-foot">
          <span>${sub}</span>
          ${deltaHtml}
        </div>
      </div>
    `;
}

function deltaBadge(delta, isCount = false) {
    if (delta === null || delta === undefined) {
        return `<span class="ctrl-delta flat">${ctrlIcon('remove', 'font-size:11px')} –</span>`;
    }
    const d = Number(delta);
    if (!isFinite(d) || d === 0) {
        return `<span class="ctrl-delta flat">${ctrlIcon('drag_handle', 'font-size:11px')} 0 %</span>`;
    }
    const cls = d > 0 ? 'up' : 'down';
    const ic  = d > 0 ? 'arrow_upward' : 'arrow_downward';
    const txt = Math.abs(d).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' %';
    return `<span class="ctrl-delta ${cls}">${ctrlIcon(ic, 'font-size:11px')} ${txt}</span>`;
}

// ============================================================
// BREAKDOWN TABLES (Top Kunden / Objekte / NU / Mitarbeiter / Artikel)
// ============================================================
/**
 * Render a {dimension, rows} breakdown into a table container.
 * Used both for fresh fetches and for slices of the combined dashboard payload.
 */
function renderBreakdownTable(data, containerId, headLabel) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!data || !Array.isArray(data.rows)) {
        el.innerHTML = ctrlEmptyHtml('warning', 'Daten konnten nicht geladen werden');
        return;
    }
    if (data.rows.length === 0) {
        el.innerHTML = ctrlEmptyHtml('folder_open', 'Keine Daten');
        return;
    }
    const maxRev = Math.max(...data.rows.map(r => r.einnahmen || 0), 1);
    // Every Top-N row opens the drill-down panel for that entity.
    const drillDim = CTRL_BD_DRILL_DIM[data.dimension] || null;
    const clickable = !!drillDim && typeof openDrill === 'function';
    // Bereich rows are keyed by their name; every other dimension needs a real
    // id (the "–" / null row = Einsätze without that entity → not drillable).
    const isBereich = data.dimension === 'bereich';
    const rowId = r => drillDim === 'bereich' ? (r.dim_value && r.dim_value !== '–' ? r.dim_value : null) : (r.dim_id || null);
    el.innerHTML = `
      <table class="ctrl-bd-table${clickable ? ' ctrl-bd-clickable' : ''}">
        <thead>
          <tr>
            <th>${headLabel}</th>
            <th class="num">Einsätze</th>
            <th class="num">Umsatz</th>
            <th class="num">Gewinn</th>
          </tr>
        </thead>
        <tbody>
          ${data.rows.map(r => `
            <tr${clickable && rowId(r) ? ` data-dim-id="${escapeHtml(rowId(r))}" data-dim-name="${escapeHtml(r.dim_value)}" title="Details öffnen"` : (clickable ? ' class="no-drill"' : '')}>
              <td>
                <div class="name" title="${escapeHtml(r.dim_value)}">${isBereich && typeof bereichDotHtml === 'function' ? bereichDotHtml(r.dim_value, false) + ' ' : ''}${escapeHtml(r.dim_value)}</div>
                <div class="ctrl-bd-bar"><span style="width:${((r.einnahmen || 0) / maxRev * 100).toFixed(1)}%${isBereich && typeof bereichColor === 'function' ? ';background:' + bereichColor(r.dim_value) : ''}"></span></div>
              </td>
              <td class="num">${r.einsaetze || 0}</td>
              <td class="num">${formatCurrency(r.einnahmen)}</td>
              <td class="num" style="color:${(r.gewinn || 0) >= 0 ? 'var(--success)' : 'var(--danger)'}">
                ${formatCurrency(r.gewinn)}
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
    if (clickable) {
        el.querySelectorAll('tr[data-dim-id]').forEach(tr =>
            tr.addEventListener('click', () => openDrill(drillDim, tr.dataset.dimId, tr.dataset.dimName)));
    }
}

/** Breakdown `dimension` (API) → drill-down dim (API path segment). */
const CTRL_BD_DRILL_DIM = {
    kunde: 'kunde', objekt: 'objekt', nu: 'nu', artikel: 'artikel',
    mitarbeiter: 'mitarbeiter', bereich: 'bereich',
};

// ============================================================
// SCHICHTART (Schicht- & Feiertagszuschläge)
// ============================================================
/**
 * Render the Schichtart breakdown from a plain rows array (the schichtart
 * endpoint returns a flat array, not the {rows:[]} envelope).
 */
function renderSchichtartFromArray(rows) {
    const el = document.getElementById('ctrlSchichtart');
    if (!el) return;
    if (!Array.isArray(rows) || rows.length === 0) {
        el.innerHTML = ctrlEmptyHtml('dark_mode', 'Keine Einsätze im Zeitraum');
        return;
    }

    const totalEin    = rows.reduce((s, r) => s + (r.einnahmen || 0), 0);
    const totalAufsch = rows.reduce((s, r) => s + (r.aufschlag || 0), 0);
    const totalGew    = rows.reduce((s, r) => s + (r.gewinn    || 0), 0);
    const totalCount  = rows.reduce((s, r) => s + (r.einsaetze || 0), 0);
    const fmt = (v) => formatCurrency(v);
    const pctOfTotal = (v) => totalEin > 0 ? ` (${((v / totalEin) * 100).toFixed(1)}%)` : '';

    const canDrill = typeof openDrill === 'function';
    el.innerHTML = `
      <table class="ctrl-bd-table${canDrill ? ' ctrl-bd-clickable' : ''}">
        <thead>
          <tr>
            <th>Schichtart</th>
            <th class="num">Einsätze</th>
            <th class="num" title="Durchschnittlicher Zuschlag">⌀ Zuschlag</th>
            <th class="num" title="Mehreinnahme aus dem Zuschlag">Aufschlag</th>
            <th class="num">Umsatz</th>
            <th class="num">Gewinn</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map(r => `
            <tr${canDrill && r.code ? ` data-code="${escapeHtml(r.code)}" data-name="${escapeHtml(r.dim_value)}" title="Details öffnen"` : ''}>
              <td>
                ${typeof schichtartBadge === 'function' ? schichtartBadge(r.code, r.avg_pct) : ''}
                <span style="margin-left:6px">${escapeHtml(r.dim_value)}</span>
              </td>
              <td class="num">${r.einsaetze || 0}</td>
              <td class="num">${r.avg_pct ? r.avg_pct.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' %' : '–'}</td>
              <td class="num" style="color:#047857;font-weight:600">${r.aufschlag > 0 ? '+ ' + fmt(r.aufschlag) : '–'}</td>
              <td class="num">${fmt(r.einnahmen)}<small style="color:var(--gray-500);font-weight:400">${pctOfTotal(r.einnahmen)}</small></td>
              <td class="num" style="color:${(r.gewinn || 0) >= 0 ? 'var(--success)' : 'var(--danger)'}">${fmt(r.gewinn)}</td>
            </tr>
          `).join('')}
        </tbody>
        <tfoot>
          <tr style="font-weight:700;background:var(--gray-50)">
            <td>Gesamt</td>
            <td class="num">${totalCount}</td>
            <td class="num">–</td>
            <td class="num" style="color:#047857">${totalAufsch > 0 ? '+ ' + fmt(totalAufsch) : '–'}</td>
            <td class="num">${fmt(totalEin)}</td>
            <td class="num" style="color:${totalGew >= 0 ? 'var(--success)' : 'var(--danger)'}">${fmt(totalGew)}</td>
          </tr>
        </tfoot>
      </table>
    `;
    if (canDrill) {
        el.querySelectorAll('tbody tr[data-code]').forEach(tr =>
            tr.addEventListener('click', () => openDrill('schichtart', tr.dataset.code, tr.dataset.name)));
    }
}

// ============================================================
// HEATMAP (Wochentag × Monat)
// ============================================================
/**
 * @param {Object} grid   — keyed by [dow][mon] → {einsaetze, einnahmen}
 * @param {Object} [meta] — optional payload returned by /heatmap:
 *                          { years: number[], aggregated_years: bool, period: {from,to} }
 */
function renderHeatmap(grid, meta, wrapId = 'ctrlHeatmap', legendId = 'ctrlHmLegend') {
    const wrap = document.getElementById(wrapId);
    if (!wrap) return;
    // Collect all values to compute thresholds
    const all = [];
    for (let d = 0; d < 7; d++) {
        for (let m = 1; m <= 12; m++) {
            all.push((grid[d] && grid[d][m]) ? grid[d][m].einsaetze : 0);
        }
    }
    const max = Math.max(...all, 1);
    const lvl = v => {
        if (!v) return '';
        const p = v / max;
        if (p > 0.8) return 'l5';
        if (p > 0.6) return 'l4';
        if (p > 0.4) return 'l3';
        if (p > 0.2) return 'l2';
        return 'l1';
    };

    // Year banner — shown only when the requested period spans multiple
    // calendar years, so the user knows a single cell represents the SUM
    // across e.g. Mar-2024 + Mar-2025 instead of one specific year.
    const years = (meta && Array.isArray(meta.years)) ? meta.years : [];
    const multi = !!(meta && meta.aggregated_years);
    let html = '';
    if (years.length > 0) {
        const lbl = multi
            ? `${ctrlIcon('info', 'font-size:12px;vertical-align:-2px')} ${years.join(' + ')} zusammengefasst — jede Zelle ist die Summe über alle Jahre des Zeitraums`
            : `${ctrlIcon('calendar_month', 'font-size:12px;vertical-align:-2px')} Jahr ${years[0]}`;
        html += `<div class="ctrl-heatmap-meta ${multi ? 'is-multi' : ''}" style="font-size:0.78rem;color:${multi ? 'var(--md-ext-color-on-warning-container, #92400e)' : 'var(--gray-500)'};${multi ? 'background:var(--warning-light, #fef3c7);padding:6px 10px;border-radius:6px;' : 'padding:0 0 6px 0;'}margin:0 14px 8px;display:inline-block">${lbl}</div>`;
    }

    html += '<div class="ctrl-heatmap">';
    // Header row: empty corner + months
    html += '<div class="hm-corner"></div>';
    for (let m = 1; m <= 12; m++) html += `<div class="hm-mon-label">${MONTHS_SHORT_DE[m]}</div>`;
    // Data rows
    const yearSuffix = multi ? ` (${years.join('+')})` : (years.length === 1 ? ` (${years[0]})` : '');
    for (let d = 0; d < 7; d++) {
        html += `<div class="hm-dow-label">${WEEKDAYS_DE[d]}</div>`;
        for (let m = 1; m <= 12; m++) {
            const cell = grid[d] && grid[d][m] ? grid[d][m] : { einsaetze: 0, einnahmen: 0 };
            const cls = lvl(cell.einsaetze);
            const tip = `${WEEKDAYS_DE[d]} · ${MONTHS_SHORT_DE[m]}${yearSuffix}: ${cell.einsaetze} Einsätze · ${formatCurrency(cell.einnahmen)}`;
            html += `<div class="hm-cell ${cls}" title="${tip}"></div>`;
        }
    }
    html += '</div>';
    wrap.innerHTML = html;

    // Legend
    const legend = document.getElementById(legendId);
    if (legend) {
        legend.innerHTML = '0 ' +
            ['l1','l2','l3','l4','l5'].map(l => `<span class="swatch ${l}" style="background:${
                {l1:'rgba(16,185,129,0.18)',l2:'rgba(16,185,129,0.36)',l3:'rgba(16,185,129,0.55)',l4:'rgba(16,185,129,0.75)',l5:'#10b981'}[l]
            }"></span>`).join('') + ` ${max}`;
    }
}

function renderHeatmapError() {
    const el = document.getElementById('ctrlHeatmap');
    if (el) el.innerHTML = ctrlEmptyHtml('warning', 'Heatmap konnte nicht geladen werden');
}

// ============================================================
// EINSATZ STATUS (Zeiterfassung classes, same as the cockpit)
// ============================================================
const CTRL_STATUS_LABEL = {
    freigegeben: 'Kunde freigegeben', teilweise: 'teilweise freigegeben', offen: 'nicht freigegeben',
    geplant: 'geplant', unbesetzt: 'unbesetzt', abgelehnt: 'abgelehnt',
    ja: 'bestätigt', ausstehend: 'ausstehend', nein: 'abgelehnt',
};
function ctrlStatusPill(s) {
    const k = String(s || '');
    return `<span class="ctrl-status-pill s-${escapeHtml(k)}">${escapeHtml(CTRL_STATUS_LABEL[k] || k || '–')}</span>`;
}

// ============================================================
// DETAILS TABLE + PAGER
// ============================================================
function renderDetails(d) {
    const wrap = document.getElementById('ctrlDetails');
    const pager = document.getElementById('ctrlPager');
    if (!d.rows || d.rows.length === 0) {
        wrap.innerHTML = `<div class="ctrl-empty">${ctrlIcon('inbox')}<div>Keine Einsätze im Zeitraum.</div></div>`;
        pager.innerHTML = '';
        return;
    }
    const sortIcon = col => col === CTRL.state.sort.col
        ? `<span class="material-symbols-outlined sort-ind">${CTRL.state.sort.dir === 'asc' ? 'arrow_drop_up' : 'arrow_drop_down'}</span>`
        : `<span class="material-symbols-outlined sort-ind">unfold_more</span>`;
    const sortable = (col, label, num = false) =>
        `<th class="${num ? 'num ' : ''}sortable ${col === CTRL.state.sort.col ? 'active' : ''}" data-col="${col}">${label}${sortIcon(col)}</th>`;
    // Entity cells link into the drill-down panel. The /details rows carry
    // names only (ids are added by newer backends) — fall back gracefully.
    const canDrill = typeof openDrill === 'function';
    const cell = (dim, id, label) => (canDrill && id && label)
        ? `<a href="#" class="ctrl-drill-link" data-dim="${dim}" data-id="${escapeHtml(id)}" data-name="${escapeHtml(label)}">${escapeHtml(label)}</a>`
        : escapeHtml(label || '–');

    wrap.innerHTML = `
      <table class="ctrl-details-table">
        <thead>
          <tr>
            ${sortable('datum', 'Datum')}
            <th>Einsatz-ID</th>
            ${sortable('kundenname', 'Kunde')}
            ${sortable('objektname', 'Objekt')}
            ${sortable('artikel_name', 'Artikel')}
            ${sortable('nu_name', 'NU')}
            ${sortable('ist_std_menge', 'Plan-Std', true)}
            <th class="num" title="Abrechenbare Kundenstunden aus der Zeiterfassung">Kunden-Std</th>
            ${sortable('einnahmen_detail', 'Umsatz', true)}
            ${sortable('nu_kosten_detail', 'NU-Kosten', true)}
            ${sortable('ma_kosten_detail', 'Personal (voll)', true)}
            ${sortable('gewinn_detail', 'DB II', true)}
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          ${d.rows.map(r => `
            <tr>
              <td>${formatDate(r.datum)}</td>
              <td style="color:var(--gray-500);font-size:0.78rem">${escapeHtml(r.einsatz_id || '')}</td>
              <td>${cell('kunde', r.kunden_id, r.kundenname)}</td>
              <td>${cell('objekt', r.obj_id, r.objektname)}</td>
              <td>${cell('artikel', r.artikel_id, r.artikel_name)}</td>
              <td>${cell('nu', r.nu_id, r.nu_name)}</td>
              <td class="num">${r.ist_std_menge || '–'}</td>
              <td class="num">${r.billable_std !== undefined ? Number(r.billable_std || 0).toLocaleString('de-DE', { maximumFractionDigits: 2 }) : '–'}</td>
              <td class="num">${formatCurrency(r.einnahmen_detail)}</td>
              <td class="num">${formatCurrency(r.nu_kosten_detail)}</td>
              <td class="num" title="Personal-Vollkosten: Stunden \u00d7 Lohn inkl. Zuschl\u00e4ge \u00d7 Vollkostenfaktor (AG-Anteil + Ausfallzeiten)">${formatCurrency(r.ma_kosten_detail)}</td>
              <td class="num" style="font-weight:600;color:${(r.gewinn_detail || 0) >= 0 ? 'var(--success)' : 'var(--danger)'}">${formatCurrency(r.gewinn_detail)}</td>
              <td>${ctrlStatusPill(r.status || r.hms_bestaetigt)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;

    // Entity links → drill-down
    wrap.querySelectorAll('a.ctrl-drill-link').forEach(a => a.addEventListener('click', ev => {
        ev.preventDefault();
        openDrill(a.dataset.dim, a.dataset.id, a.dataset.name);
    }));

    // Bind column sort
    wrap.querySelectorAll('th.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const col = th.dataset.col;
            if (CTRL.state.sort.col === col) {
                CTRL.state.sort.dir = CTRL.state.sort.dir === 'asc' ? 'desc' : 'asc';
            } else {
                CTRL.state.sort.col = col;
                CTRL.state.sort.dir = 'desc';
            }
            CTRL.state.page = 1;
            ctrlLoadDetails();
        });
    });

    // Pager
    const totalPages = Math.max(1, d.pages);
    const p = CTRL.state.page;
    const pageBtns = paginatorButtons(p, totalPages);
    pager.innerHTML = `
      <span>${d.total.toLocaleString('de-DE')} Einsätze · Seite ${p} / ${totalPages}</span>
      <div class="pages">
        <md-icon-button ${p <= 1 ? 'disabled' : ''} data-page="1" title="Erste Seite" aria-label="Erste Seite"><md-icon>first_page</md-icon></md-icon-button>
        <md-icon-button ${p <= 1 ? 'disabled' : ''} data-page="${p - 1}" title="Zurück" aria-label="Zurück"><md-icon>chevron_left</md-icon></md-icon-button>
        ${pageBtns}
        <md-icon-button ${p >= totalPages ? 'disabled' : ''} data-page="${p + 1}" title="Weiter" aria-label="Weiter"><md-icon>chevron_right</md-icon></md-icon-button>
        <md-icon-button ${p >= totalPages ? 'disabled' : ''} data-page="${totalPages}" title="Letzte Seite" aria-label="Letzte Seite"><md-icon>last_page</md-icon></md-icon-button>
      </div>
    `;
    pager.querySelectorAll('[data-page]').forEach(b => {
        b.addEventListener('click', () => {
            const np = parseInt(b.dataset.page, 10);
            if (!isNaN(np) && np !== CTRL.state.page) {
                CTRL.state.page = np;
                ctrlLoadDetails();
            }
        });
    });
}

function renderDetailsError() {
    const el = document.getElementById('ctrlDetails');
    if (el) el.innerHTML = ctrlEmptyHtml('warning', 'Detail-Liste konnte nicht geladen werden');
}

function paginatorButtons(current, total) {
    if (total <= 7) {
        return Array.from({ length: total }, (_, i) =>
            (i + 1 === current ? `<md-filled-tonal-button data-page="${i + 1}" class="active">${i + 1}</md-filled-tonal-button>` : `<md-text-button data-page="${i + 1}">${i + 1}</md-text-button>`)
        ).join('');
    }
    const out = [];
    const push = i => out.push(i === current ? `<md-filled-tonal-button data-page="${i}" class="active">${i}</md-filled-tonal-button>` : `<md-text-button data-page="${i}">${i}</md-text-button>`);
    push(1);
    if (current > 4) out.push('<span style="padding:0 4px;color:var(--gray-400)">…</span>');
    for (let i = Math.max(2, current - 2); i <= Math.min(total - 1, current + 2); i++) push(i);
    if (current < total - 3) out.push('<span style="padding:0 4px;color:var(--gray-400)">…</span>');
    push(total);
    return out.join('');
}
