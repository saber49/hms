/* ============================================
   Clood-X — Auswertung & Controlling · Drill-down panel
   Click any row in any card → wide side panel with EVERYTHING about that
   entity (Kunde / Objekt / NU / Artikel / Bereich / Schichtart / Mitarbeiter):
   KPIs + Δ vs. Vorperiode, share of total, status mix, trend chart,
   sub-breakdowns (drill further!), heatmap, Stammdaten and the shifts.
   Backend: GET /controlling/drilldown/{dim}/{id}
   Requires: controlling-core.js, controlling-widgets.js, controlling-charts.js
   ============================================ */

const DRILL = {
    stack: [],              // breadcrumb: [{dim,id,name}, ...]
    current: null,          // last payload
    tab: null,              // active sub-breakdown tab
    reqSeq: 0,              // guards against out-of-order responses
};

const DRILL_DIM_LABEL = {
    kunde: 'Kunde', objekt: 'Objekt', nu: 'Nachunternehmer', artikel: 'Artikel',
    bereich: 'Bereich', schichtart: 'Schichtart', mitarbeiter: 'Mitarbeiter',
};
const DRILL_SUB_LABEL = {
    kunden: 'Kunden', objekte: 'Objekte', artikel: 'Artikel', nu: 'Nachunternehmer',
    mitarbeiter: 'Mitarbeiter', schichtart: 'Schichtarten', bereich: 'Bereiche',
};
const DRILL_SUB_ICON = {
    kunden: 'person', objekte: 'location_on', artikel: 'sell', nu: 'engineering',
    mitarbeiter: 'badge', schichtart: 'dark_mode', bereich: 'layers',
};

// ---------- Shell ----------
function ctrlDrillPanelHtml() {
    return `
      <div class="ctrl-drill-overlay" id="ctrlDrillOverlay"></div>
      <aside class="ctrl-drill" id="ctrlDrill" aria-hidden="true">
        <div class="ctrl-drill-head">
          <div class="ctrl-drill-crumbs" id="ctrlDrillCrumbs"></div>
          <div class="ctrl-drill-title-row">
            <h3 id="ctrlDrillTitle">${ctrlIcon('insights')} Details</h3>
            <div class="ctrl-drill-actions">
              <md-outlined-button id="ctrlDrillFilter" style="display:none"><md-icon slot="icon">filter_alt</md-icon>Als Filter anwenden</md-outlined-button>
              <md-icon-button id="ctrlDrillClose" title="Schließen"><md-icon>close</md-icon></md-icon-button>
            </div>
          </div>
          <div class="ctrl-drill-meta" id="ctrlDrillMeta"></div>
        </div>
        <div class="ctrl-drill-body" id="ctrlDrillBody"></div>
      </aside>
    `;
}

/**
 * Mount the panel + overlay directly under <body>.
 *
 * Why not inside #content: `.content` carries a permanent `transform`
 * (pageFadeIn with fill-mode "both"), which turns it into the containing
 * block for `position: fixed` descendants. A panel placed there would be
 * pinned to the TOP of the (long) page instead of the viewport — invisible
 * when the user has scrolled down. Mounting on <body> avoids that.
 */
function bindCtrlDrill() {
    ['ctrlDrillOverlay', 'ctrlDrill'].forEach(id => document.getElementById(id)?.remove());
    document.body.insertAdjacentHTML('beforeend', ctrlDrillPanelHtml());
    document.getElementById('ctrlDrillClose')?.addEventListener('click', closeDrill);
    document.getElementById('ctrlDrillOverlay')?.addEventListener('click', closeDrill);
    document.getElementById('ctrlDrillFilter')?.addEventListener('click', drillApplyAsFilter);
    if (!DRILL.escBound) {
        DRILL.escBound = true;
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && document.getElementById('ctrlDrill')?.classList.contains('open')) closeDrill(); });
        // Leaving the Controlling page (SPA hash navigation) must not leave an
        // open panel behind on <body>.
        window.addEventListener('hashchange', () => {
            closeDrill();
            if (typeof closeSnapDrawer === 'function') closeSnapDrawer();
        });
    }
}

// ---------- Open / navigate / close ----------
/**
 * Open the panel for an entity. `push=true` adds a breadcrumb level so the
 * user can go back (Kunde → Objekt → Artikel …).
 */
async function openDrill(dim, id, name = '', push = true) {
    if (!dim || id === null || id === undefined || id === '') return;
    if (!push) DRILL.stack = DRILL.stack.slice(0, -1);
    DRILL.stack.push({ dim, id: String(id), name: name || String(id) });
    DRILL.tab = null;
    await drillLoad(dim, String(id));
}

function drillBack(toIndex) {
    DRILL.stack = DRILL.stack.slice(0, toIndex + 1);
    const top = DRILL.stack[DRILL.stack.length - 1];
    DRILL.tab = null;
    if (top) drillLoad(top.dim, top.id);
}

function closeDrill() {
    document.getElementById('ctrlDrill')?.classList.remove('open');
    document.getElementById('ctrlDrillOverlay')?.classList.remove('active');
    document.getElementById('ctrlDrill')?.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ctrl-drill-open');
    DRILL.stack = [];
    DRILL.current = null;
    if (CTRL.charts.drill) { CTRL.charts.drill.destroy(); CTRL.charts.drill = null; }
}

async function drillLoad(dim, id) {
    const panel = document.getElementById('ctrlDrill');
    const body = document.getElementById('ctrlDrillBody');
    if (!panel || !body) return;
    panel.classList.add('open');
    panel.setAttribute('aria-hidden', 'false');
    document.getElementById('ctrlDrillOverlay')?.classList.add('active');
    document.body.classList.add('ctrl-drill-open');
    renderDrillCrumbs();
    document.getElementById('ctrlDrillTitle').innerHTML = `${ctrlIcon('insights')} ${escapeHtml(DRILL_DIM_LABEL[dim] || '')} wird geladen…`;
    document.getElementById('ctrlDrillMeta').innerHTML = '';
    body.innerHTML = `<div class="ctrl-drill-loading">${ctrlSpinner(36)}<div>Analyse wird berechnet…</div></div>`;
    body.scrollTop = 0;

    const seq = ++DRILL.reqSeq;
    const g = CTRL.state.granularity === 'auto' ? null : CTRL.state.granularity;
    const data = await apiGetNoCancel(CTRL.api + '/drilldown/' + encodeURIComponent(dim) + '/' + encodeURIComponent(id) + '?' + ctrlBuildParams({ granularity: g }));
    if (seq !== DRILL.reqSeq) return; // a newer navigation superseded this one

    if (!data || Array.isArray(data) || data.error || !data.kpi) {
        console.warn('[Controlling] drilldown failed', dim, id, data);
        body.innerHTML = ctrlEmptyHtml('warning', escapeHtml((data && data.error) || 'Details konnten nicht geladen werden'));
        return;
    }
    DRILL.current = data;
    // Keep the breadcrumb name in sync with the real entity name.
    const top = DRILL.stack[DRILL.stack.length - 1];
    if (top && data.entity?.name) { top.name = data.entity.name; renderDrillCrumbs(); }
    renderDrill(data);
}

function renderDrillCrumbs() {
    const el = document.getElementById('ctrlDrillCrumbs');
    if (!el) return;
    if (DRILL.stack.length <= 1) { el.innerHTML = ''; return; }
    el.innerHTML = DRILL.stack.map((s, i) => {
        const last = i === DRILL.stack.length - 1;
        const lbl = `${escapeHtml(DRILL_DIM_LABEL[s.dim] || s.dim)}: ${escapeHtml(s.name)}`;
        return last
            ? `<span class="crumb current">${lbl}</span>`
            : `<button class="crumb" data-i="${i}">${lbl}</button><span class="sep">›</span>`;
    }).join('');
    el.querySelectorAll('button.crumb').forEach(b => b.addEventListener('click', () => drillBack(parseInt(b.dataset.i, 10))));
}

// ---------- Apply entity as dashboard filter ----------
function drillApplyAsFilter() {
    const d = DRILL.current;
    if (!d || !d.entity) return;
    const { dim, id } = d.entity;
    closeDrill();
    switch (dim) {
        case 'kunde': {
            CTRL.state.filters.kunden_id = id; CTRL.state.filters.obj_id = '';
            const k = document.getElementById('ctrlKunde'); if (k) k.value = id;
            getObjectOptions(id).then(opts => { const o = document.getElementById('ctrlObjekt'); if (o) o.innerHTML = ctrlToMdOptions(opts, 'Alle Objekte'); }).catch(() => {});
            break;
        }
        case 'objekt': {
            if (d.entity.parent?.id) {
                CTRL.state.filters.kunden_id = d.entity.parent.id;
                const k = document.getElementById('ctrlKunde'); if (k) k.value = d.entity.parent.id;
                getObjectOptions(d.entity.parent.id).then(opts => { const o = document.getElementById('ctrlObjekt'); if (o) o.innerHTML = ctrlToMdOptions(opts, 'Alle Objekte', id); }).catch(() => {});
            }
            CTRL.state.filters.obj_id = id;
            break;
        }
        case 'nu': { CTRL.state.filters.nu_id = id; const n = document.getElementById('ctrlNu'); if (n) n.value = id; break; }
        case 'bereich': { CTRL.state.filters.bereich = id === '–' ? '' : id; const b = document.getElementById('ctrlBereich'); if (b) b.value = CTRL.state.filters.bereich; break; }
        case 'mitarbeiter': return ctrlSelectWorker(id);
        default: return;
    }
    CTRL.state.page = 1;
    ctrlLoadAll();
    if (typeof showToast === 'function') showToast(`Filter gesetzt: ${d.entity.name}`, 'success');
}

// ============================================================
// RENDER
// ============================================================
function renderDrill(d) {
    const e = d.entity, k = d.kpi, dl = d.delta || {}, sh = d.share || {};
    const filterable = ['kunde', 'objekt', 'nu', 'bereich', 'mitarbeiter'].includes(e.dim);
    const fBtn = document.getElementById('ctrlDrillFilter');
    if (fBtn) fBtn.style.display = filterable ? '' : 'none';

    document.getElementById('ctrlDrillTitle').innerHTML =
        `${ctrlIcon(e.icon || 'insights')} <span class="dim">${escapeHtml(DRILL_DIM_LABEL[e.dim] || e.dim)}</span> ${escapeHtml(e.name)}` +
        (e.aktiv === false ? ' <span class="ctrl-status-pill s-nein">inaktiv</span>' : '');

    const parent = e.parent && e.parent.id
        ? `<button class="ctrl-drill-chip link" data-dim="${escapeHtml(e.parent.dim)}" data-id="${escapeHtml(e.parent.id)}" title="Übergeordnet öffnen">${ctrlIcon('arrow_upward', 'font-size:12px')} ${escapeHtml(DRILL_DIM_LABEL[e.parent.dim])}: ${escapeHtml(e.parent.name || e.parent.id)}</button>`
        : '';
    document.getElementById('ctrlDrillMeta').innerHTML = `
        <span class="ctrl-drill-chip">${ctrlIcon('date_range', 'font-size:12px')} ${escapeHtml(d.period?.label || '')}</span>
        <span class="ctrl-drill-chip muted">Vergleich: ${escapeHtml(d.compare?.label || '')}</span>
        ${parent}
        ${(e.stammdaten || []).map(s => `<span class="ctrl-drill-chip muted" title="${escapeHtml(s.label)}"><b>${escapeHtml(s.label)}:</b> ${escapeHtml(s.value)}</span>`).join('')}
    `;
    document.getElementById('ctrlDrillMeta').querySelectorAll('button[data-dim]').forEach(b =>
        b.addEventListener('click', () => openDrill(b.dataset.dim, b.dataset.id)));

    const body = document.getElementById('ctrlDrillBody');
    if (!k.einsaetze_total) {
        body.innerHTML = ctrlEmptyHtml('inbox', 'Keine Einsätze für diese Auswahl im Zeitraum.');
        return;
    }

    const gewinnCls = (k.gewinn || 0) >= 0 ? 'ok' : 'bad';
    const subs = Object.keys(d.breakdowns || {}).filter(s => Array.isArray(d.breakdowns[s]));
    if (!DRILL.tab || !subs.includes(DRILL.tab)) DRILL.tab = subs[0] || null;

    body.innerHTML = `
      ${d.basis === 'abrechnung' ? unconfirmedBannerHtml({ ...k, ausstehend_count: d.status?.ausstehend ?? k.ausstehend_count, bestaetigt_count: d.status?.bestaetigt ?? k.bestaetigt_count }, { scope: e.name }) : ''}
      <!-- KPIs -->
      <div class="ctrl-drill-kpis">
        ${drillKpi(d.basis === 'abrechnung' ? 'Umsatz (Kunde freigegeben)' : 'Umsatz (geleistet)', formatCurrency(k.einnahmen), dl.einnahmen, `${k.einsaetze_basis ?? k.bestaetigt_count} Einsätze · Ø ${formatCurrency(k.einnahmen_pro_einsatz)}/Einsatz`)}
        ${drillKpi('Kosten NU', formatCurrency(k.kosten), dl.kosten, 'Subunternehmer', true)}
        ${k.ma_kosten > 0 ? drillKpi('Personal (Vollkosten)', formatCurrency(k.ma_kosten), dl.ma_kosten, `Lohn ${formatCurrency(k.personal)} + Lohnnebenkosten ${formatCurrency(k.lohnnebenkosten)}`, true) : ''}
        ${drillKpi('Deckungsbeitrag II', formatCurrency(k.gewinn), dl.gewinn, `${(k.marge || 0).toLocaleString('de-DE')} % Marge · ${formatCurrency(k.gewinn_pro_stunde)}/h · DB I ${formatCurrency(k.db1)}`, false, gewinnCls)}
        ${drillKpi('Einsätze', k.einsaetze_total, dl.einsaetze_total, `${k.arbeitstage} Arbeitstage`)}
        ${drillKpi('Kundenstunden', (k.ist_stunden || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' h', dl.ist_stunden, `${d.basis === 'abrechnung' ? `${(k.ist_stunden_offen || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 })} h nicht freigegeben · ` : ''} Ø ${(k.stunden_pro_einsatz || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 })} h/Einsatz`)}
      </div>

      <!-- Share + Status + counts -->
      <div class="ctrl-drill-row3">
        <div class="ctrl-drill-box">
          <h4>${ctrlIcon('donut_small', 'font-size:14px')} Anteil am Gesamtergebnis</h4>
          ${drillShare('Einnahmen', sh.einnahmen_pct, formatCurrency(k.einnahmen), formatCurrency(sh.total_einnahmen))}
          ${drillShare('DB II', sh.gewinn_pct, formatCurrency(k.gewinn), formatCurrency(sh.total_gewinn))}
          ${drillShare('Einsätze', sh.einsaetze_pct)}
          ${drillShare('Stunden', sh.stunden_pct)}
        </div>
        <div class="ctrl-drill-box">
          <h4>${ctrlIcon('task_alt', 'font-size:14px')} Status der Einsätze</h4>
          ${drillStatus(d.status, k.einsaetze_total)}
          ${d.bestellungen ? `<div class="ctrl-drill-note">${ctrlIcon('assignment', 'font-size:12px')} ${d.bestellungen.total} Bestellungen im Zeitraum · Soll ${(d.bestellungen.soll_stunden || 0).toLocaleString('de-DE')} h · Ist ${(k.ist_stunden || 0).toLocaleString('de-DE')} h${d.bestellungen.soll_stunden > 0 ? ` · Auslastung ${Math.round(k.ist_stunden / d.bestellungen.soll_stunden * 100)} %` : ''}</div>` : ''}
          ${k.aufschlag > 0 ? `<div class="ctrl-drill-note">${ctrlIcon('dark_mode', 'font-size:12px')} davon Zuschläge (Nacht/So/Feiertag): <b>${formatCurrency(k.aufschlag)}</b></div>` : ''}
        </div>
        <div class="ctrl-drill-box">
          <h4>${ctrlIcon('hub', 'font-size:14px')} Beteiligte</h4>
          <div class="ctrl-drill-counts">
            ${e.dim !== 'kunde' ? drillCount('person', k.kunden_aktiv, 'Kunden') : ''}
            ${!['objekt', 'artikel'].includes(e.dim) ? drillCount('location_on', k.objekte_aktiv, 'Objekte') : ''}
            ${e.dim !== 'artikel' ? drillCount('sell', k.artikel_aktiv, 'Artikel') : ''}
            ${e.dim !== 'nu' ? drillCount('engineering', k.nu_aktiv, 'NU') : ''}
            ${e.dim !== 'mitarbeiter' ? drillCount('badge', k.mitarbeiter_aktiv, 'Mitarbeiter') : ''}
            ${drillCount('event', `${formatDate(k.erster_einsatz)} – ${formatDate(k.letzter_einsatz)}`, 'erster / letzter Einsatz', true)}
          </div>
        </div>
      </div>

      <!-- Trend -->
      <div class="ctrl-drill-box">
        <h4>${ctrlIcon('show_chart', 'font-size:14px')} Verlauf (${escapeHtml(d.granularity || '')})</h4>
        <div class="ctrl-chart-wrap" style="min-height:220px"><canvas id="ctrlDrillChart"></canvas></div>
      </div>

      <!-- Sub-breakdowns -->
      ${subs.length ? `
      <div class="ctrl-drill-box">
        <div class="ctrl-drill-tabs" id="ctrlDrillTabs">
          ${subs.map(s => `<button class="chip ${s === DRILL.tab ? 'active' : ''}" data-s="${s}">${ctrlIcon(DRILL_SUB_ICON[s] || 'label', 'font-size:13px')} ${DRILL_SUB_LABEL[s] || s} <small>(${d.breakdowns[s].length})</small></button>`).join('')}
        </div>
        <div id="ctrlDrillSub">${renderDrillSub(d.breakdowns[DRILL.tab], DRILL.tab)}</div>
        <div class="ctrl-drill-hint">${ctrlIcon('touch_app', 'font-size:12px')} Zeile anklicken, um weiter in die Tiefe zu gehen.</div>
      </div>` : ''}

      <!-- Heatmap -->
      <div class="ctrl-drill-box">
        <h4>${ctrlIcon('grid_view', 'font-size:14px')} Aktivität (Wochentag × Monat) <span class="ctrl-heatmap-legend" id="ctrlDrillHmLegend"></span></h4>
        <div id="ctrlDrillHeatmap"></div>
      </div>

      <!-- Einsätze -->
      <div class="ctrl-drill-box">
        <h4>${ctrlIcon('list', 'font-size:14px')} Einsätze im Zeitraum <small>(${d.einsaetze.length}${d.einsaetze.length >= d.einsaetze_limit ? '+ · neueste zuerst' : ''})</small></h4>
        <div style="overflow-x:auto">${renderDrillEinsaetze(d.einsaetze, e.dim)}</div>
      </div>
    `;

    // Tabs
    body.querySelectorAll('#ctrlDrillTabs .chip').forEach(b => b.addEventListener('click', () => {
        DRILL.tab = b.dataset.s;
        body.querySelectorAll('#ctrlDrillTabs .chip').forEach(x => x.classList.toggle('active', x === b));
        document.getElementById('ctrlDrillSub').innerHTML = renderDrillSub(d.breakdowns[DRILL.tab], DRILL.tab);
        bindDrillSubRows();
    }));
    bindDrillSubRows();
    bindDrillEinsatzLinks(body);

    // Heatmap (reuse the dashboard renderer on a scoped container)
    renderDrillHeatmap(d.heatmap);

    // Chart
    ensureChartReady().then(ok => ok ? renderDrillChart(d.trend || []) : showChartError('ctrlDrillChart'));
}

// ---------- pieces ----------
function drillKpi(label, value, delta, sub, invert = false, cls = '') {
    // invert: for costs, "up" is bad → swap the colour semantics
    let badge = deltaBadge(delta);
    if (invert && delta) badge = badge.replace(' up"', ' up inv"').replace(' down"', ' down inv"');
    return `<div class="ctrl-drill-kpi ${cls}"><div class="lbl">${label}</div><div class="val">${value}</div><div class="foot"><span>${sub}</span>${badge}</div></div>`;
}
function drillShare(label, pct, abs = '', total = '') {
    const p = Math.max(0, Math.min(100, Number(pct) || 0));
    return `<div class="ctrl-drill-share"><span class="lbl">${label}</span><span class="bar"><span style="width:${p}%"></span></span><span class="val">${(Number(pct) || 0).toLocaleString('de-DE')} %</span>${abs ? `<span class="abs" title="von ${total}">${abs} / ${total}</span>` : ''}</div>`;
}
function drillStatus(st, total) {
    if (!st || !total) return '';
    const seg = (n, cls, lbl) => n ? `<span class="${cls}" style="width:${(n / total * 100).toFixed(1)}%" title="${lbl}: ${n}"></span>` : '';
    return `
      <div class="ctrl-drill-status">${seg(st.bestaetigt, 's-ja', 'Kunde freigegeben')}${seg(st.ausstehend, 's-ausstehend', 'geleistet, nicht freigegeben')}${seg(st.unbesetzt, 's-unbesetzt', 'unbesetzt')}${seg(st.geplant, 's-geplant', 'geplant')}${seg(st.nein, 's-nein', 'abgelehnt')}</div>
      <div class="ctrl-drill-status-legend">
        <span><i class="s-ja"></i>${st.bestaetigt} Kunde freigegeben</span>
        <span><i class="s-ausstehend"></i>${st.ausstehend} nicht freigegeben</span>
        ${st.unbesetzt ? `<span><i class="s-unbesetzt"></i>${st.unbesetzt} unbesetzt</span>` : ''}
        ${st.geplant ? `<span><i class="s-geplant"></i>${st.geplant} geplant</span>` : ''}
        ${st.nein ? `<span><i class="s-nein"></i>${st.nein} abgelehnt</span>` : ''}
      </div>
      <div class="ctrl-drill-note">${ctrlIcon('info', 'font-size:12px')} Status aus der Zeiterfassung; teilweise freigegebene Einsätze zählen in beiden Gruppen.</div>`;
}
function drillCount(icon, val, lbl, wide = false) {
    return `<div class="ctrl-drill-count${wide ? ' wide' : ''}">${ctrlIcon(icon, 'font-size:16px')}<div><b>${val ?? '–'}</b><small>${lbl}</small></div></div>`;
}

function renderDrillSub(rows, sub) {
    if (!Array.isArray(rows) || rows.length === 0) return ctrlEmptyHtml('folder_open', 'Keine Daten');
    const maxRev = Math.max(...rows.map(r => r.einnahmen || 0), 1);
    const totalRev = rows.reduce((s, r) => s + (r.einnahmen || 0), 0);
    const isMa = sub === 'mitarbeiter';
    return `
      <table class="ctrl-bd-table ctrl-bd-clickable">
        <thead><tr>
          <th>${DRILL_SUB_LABEL[sub] || sub}</th><th class="num">Einsätze</th><th class="num">Stunden</th>
          <th class="num">Umsatz</th><th class="num">${isMa ? 'Personal (voll)' : 'Kosten (voll)'}</th><th class="num">DB II</th><th class="num">Marge</th>
        </tr></thead>
        <tbody>
          ${rows.map(r => `
            <tr ${r.drill_dim !== null && r.dim_id !== null && r.dim_id !== '' ? `data-dim="${escapeHtml(r.drill_dim || (isMa ? 'mitarbeiter' : ''))}" data-id="${escapeHtml(r.dim_id)}" data-name="${escapeHtml(r.dim_value)}"` : 'class="no-drill"'}>
              <td>
                <div class="name" title="${escapeHtml(r.dim_value)}">${escapeHtml(r.dim_value)}</div>
                <div class="ctrl-bd-bar"><span style="width:${((r.einnahmen || 0) / maxRev * 100).toFixed(1)}%"></span></div>
              </td>
              <td class="num">${r.einsaetze || 0}</td>
              <td class="num">${(r.ist_stunden || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 })}</td>
              <td class="num">${formatCurrency(r.einnahmen)}<small class="pct">${totalRev > 0 ? ((r.einnahmen || 0) / totalRev * 100).toFixed(0) + ' %' : ''}</small></td>
              <td class="num">${formatCurrency(isMa ? r.ma_kosten : (r.kosten || 0) + (r.ma_kosten || 0))}</td>
              <td class="num" style="color:${(r.gewinn || 0) >= 0 ? 'var(--success)' : 'var(--danger)'};font-weight:600">${formatCurrency(r.gewinn)}</td>
              <td class="num">${(r.marge || 0).toLocaleString('de-DE')} %</td>
            </tr>`).join('')}
        </tbody>
      </table>`;
}
function bindDrillSubRows() {
    document.querySelectorAll('#ctrlDrillSub tr[data-dim]').forEach(tr =>
        tr.addEventListener('click', () => tr.dataset.dim && openDrill(tr.dataset.dim, tr.dataset.id, tr.dataset.name)));
}

function renderDrillEinsaetze(rows, dim) {
    if (!Array.isArray(rows) || rows.length === 0) return ctrlEmptyHtml('inbox', 'Keine Einsätze');
    const link = (d, id, label) => (id && label && d !== dim)
        ? `<a href="#" class="ctrl-drill-link" data-dim="${d}" data-id="${escapeHtml(id)}" data-name="${escapeHtml(label)}">${escapeHtml(label)}</a>`
        : escapeHtml(label || '–');
    const time = r => (r.ist_start || r.ist_ende) ? `${r.ist_start || '–'}–${r.ist_ende || '–'}${r.pause_minuten ? `<small> (${r.pause_minuten}′ P.)</small>` : ''}` : '–';
    return `
      <table class="ctrl-details-table ctrl-drill-einsaetze">
        <thead><tr>
          <th>Datum</th><th>Zeit</th><th>Kunde</th><th>Objekt</th><th>Artikel</th><th>Ausführung</th>
          <th class="num">Plan-Std</th><th class="num">Umsatz</th><th class="num">Kosten (voll)</th><th class="num">DB II</th><th>Status</th>
        </tr></thead>
        <tbody>
          ${rows.map(r => `
            <tr>
              <td>${formatDate(r.datum)}<small class="sub">${WEEKDAYS_DE[(new Date(r.datum).getDay() + 6) % 7] || ''} · ${escapeHtml(r.einsatz_id || '')}</small></td>
              <td>${time(r)}${r.zuschlag_pct ? `<small class="sub" title="Schichtart: ${escapeHtml(r.schichtart)}">+${r.zuschlag_pct} %</small>` : ''}</td>
              <td>${link('kunde', r.kunden_id, r.kundenname)}</td>
              <td>${link('objekt', r.obj_id, r.objektname)}</td>
              <td>${link('artikel', r.artikel_id, r.artikel_name)}</td>
              <td>${r.nu_id ? link('nu', r.nu_id, r.nu_name) : `<span title="${escapeHtml(r.mitarbeiter_namen || '')}">${escapeHtml(r.mitarbeiter_namen || 'Intern')}</span>`}<small class="sub">${r.workers} Pers. · ${(r.billable_std || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 })} h abrechenbar</small></td>
              <td class="num">${r.ist_std_menge ?? '–'}</td>
              <td class="num">${formatCurrency(r.einnahmen)}</td>
              <td class="num" title="NU ${formatCurrency(r.nu_kosten)} · Personal (voll) ${formatCurrency(r.ma_kosten)}">${formatCurrency((r.nu_kosten || 0) + (r.ma_kosten || 0))}</td>
              <td class="num" style="font-weight:600;color:${(r.gewinn || 0) >= 0 ? 'var(--success)' : 'var(--danger)'}">${formatCurrency(r.gewinn)}</td>
              <td>${ctrlStatusPill(r.hms_bestaetigt)}</td>
            </tr>`).join('')}
        </tbody>
      </table>`;
}
function bindDrillEinsatzLinks(root) {
    root.querySelectorAll('a.ctrl-drill-link').forEach(a => a.addEventListener('click', ev => {
        ev.preventDefault();
        openDrill(a.dataset.dim, a.dataset.id, a.dataset.name);
    }));
}

function renderDrillHeatmap(hm) {
    const wrap = document.getElementById('ctrlDrillHeatmap');
    if (!wrap) return;
    if (!hm || !hm.grid) { wrap.innerHTML = ctrlEmptyHtml('grid_view', 'Keine Daten'); return; }
    renderHeatmap(hm.grid, hm, 'ctrlDrillHeatmap', 'ctrlDrillHmLegend');
}

function renderDrillChart(points) {
    const ctx = document.getElementById('ctrlDrillChart');
    if (!ctx) return;
    if (CTRL.charts.drill) CTRL.charts.drill.destroy();
    const opts = chartBaseOpts({ currency: true });
    opts.scales.y1 = { position: 'right', grid: { display: false }, ticks: { font: { size: 10 }, precision: 0 } };
    opts.plugins.tooltip.callbacks.label = c => `${c.dataset.label}: ${c.dataset.yAxisID === 'y1' ? c.parsed.y : formatCurrency(c.parsed.y)}`;
    CTRL.charts.drill = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: points.map(p => p.bucket),
            datasets: [
                { type: 'bar', label: 'Einsätze', data: points.map(p => p.einsaetze), yAxisID: 'y1',
                  backgroundColor: CTRL.palette.gray + '55', borderColor: CTRL.palette.gray, borderWidth: 1, borderRadius: 4, order: 3 },
                { type: 'line', ...lineDataset('Umsatz', points.map(p => p.einnahmen), CTRL.palette.success, true), yAxisID: 'y', order: 1 },
                { type: 'line', ...lineDataset('Kosten (voll)', points.map(p => (p.kosten || 0) + (p.ma_kosten || 0)), CTRL.palette.warning, false), yAxisID: 'y', order: 1 },
                { type: 'line', ...lineDataset('DB II', points.map(p => p.gewinn), CTRL.palette.accent, true), yAxisID: 'y', order: 0 },
            ],
        },
        options: opts,
    });
}
