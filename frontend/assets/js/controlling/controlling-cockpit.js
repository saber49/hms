/* ============================================
   Clood-X — Controlling-Cockpit
   Stufenweise Deckungsbeitragsrechnung, Objektcontrolling, Preis-Check,
   Hochrechnung, Kostensätze & Fixkosten.
   Backend: GET /controlling/cockpit, /settings, /fixkosten (+ writes, Inhaber)
   Requires: controlling-core.js, controlling-charts.js, controlling-widgets.js,
             controlling-api.js (details + export), controlling-worker.js
   Loaded AFTER controlling-api.js → its ctrlLoadAll() replaces the legacy one
   (the drill-down and the worker card call ctrlLoadAll()).
   ============================================ */

const CTRLC = {
    basis: 'operativ',      // operativ | abrechnung
    tab: 'kunden',          // kunden | mitarbeiter | nu | bereiche
    objFilter: 'all',       // all | rot | gelb | gruen
    expanded: {},           // table id → show all rows
    data: null,
    seq: 0,
};
const CC_ROWS = 10;

/** Top-N rows + "Alle anzeigen" toggle (re-renders through `rerender`). */
function ccLimit(id, rows) {
    return CTRLC.expanded[id] ? rows : rows.slice(0, CC_ROWS);
}
function ccMoreBtn(id, total) {
    if (total <= CC_ROWS) return '';
    const open = !!CTRLC.expanded[id];
    return `<div class="cc-more"><md-text-button class="chip" onclick="ccToggleRows('${id}')">${open ? 'Weniger anzeigen' : `Alle ${total} anzeigen`}</md-text-button></div>`;
}
function ccToggleRows(id) {
    CTRLC.expanded[id] = !CTRLC.expanded[id];
    if (!CTRLC.data) return;
    ({ objekte: renderCcObjekte, artikel: renderCcArtikel, tab: renderCcTab })[id]?.(CTRLC.data);
}

const CC_AMPEL_LABEL = { rot: 'Kritisch', gelb: 'Beobachten', gruen: 'Gut' };
const CC_FIX_KATEGORIEN = ['Personal Verwaltung', 'Büro & Miete', 'Fahrzeuge', 'Versicherungen', 'Software & IT', 'Ausrüstung', 'Sonstiges'];

// ---------- formatting ----------
const ccEur = v => (v === null || v === undefined) ? '–' : formatCurrency(v);
const ccPct = v => (v === null || v === undefined) ? '–' : Number(v).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' %';
const ccNum = (v, d = 0) => (v === null || v === undefined) ? '–' : Number(v).toLocaleString('de-DE', { maximumFractionDigits: d });
const ccSign = v => (v || 0) >= 0 ? 'cc-pos' : 'cc-neg';
function ccAmpel(a, title = '') {
    const t = a ? (CC_AMPEL_LABEL[a] || a) : 'Keine Daten';
    return `<span class="cc-ampel a-${a || 'none'}" title="${escapeHtml(title || t)}"></span>`;
}
function ccDate(d) { return d ? formatDate(d) : '–'; }

// ============================================================
// LOADER (replaces the legacy fan-out loader)
// ============================================================
async function ctrlLoadAll(withDetails = true) {
    if (typeof ctrlLoadWorker === 'function') ctrlLoadWorker();
    if (withDetails) ctrlLoadDetails();

    const seq = ++CTRLC.seq;
    document.getElementById('ccResult')?.classList.add('cc-loading');
    const data = await apiGetNoCancel(CTRL.api + '/cockpit?' + ctrlBuildParams({ basis: CTRLC.basis }));
    if (seq !== CTRLC.seq) return; // superseded by a newer request
    document.getElementById('ccResult')?.classList.remove('cc-loading');

    if (!data || Array.isArray(data) || data.error || !data.ergebnis) {
        const msg = ctrlEmptyHtml('warning', escapeHtml((data && data.error) || 'Controlling-Daten konnten nicht geladen werden'));
        ['ccResult', 'ccObjekte', 'ccArtikel', 'ccTabBody'].forEach(id => { const el = document.getElementById(id); if (el) el.innerHTML = msg; });
        const k = document.getElementById('ccKpis'); if (k) k.innerHTML = '';
        return;
    }
    CTRLC.data = data;
    renderCockpit(data);
}

function renderCockpit(d) {
    const lbl = document.getElementById('ctrlPeriodLabel');
    if (lbl) {
        lbl.textContent = d.period.label
            + (d.vergleich ? ` · Vergleich ${d.vergleich.label}${d.vergleich.cut ? ' (bis zum gleichen Tag)' : ''}` : '')
            + ` · ${d.basis === 'abrechnung' ? 'nur für den Kunden freigegebene Stunden' : 'freigegeben + geleistet'}`;
    }
    renderCcBanners(d);
    renderCcResult(d);
    renderCcKpis(d);
    renderCcForecast(d);
    renderCcObjekte(d);
    renderCcArtikel(d);
    renderCcTab(d);
    renderCcTrend(d);
}

// ============================================================
// BANNERS — open approvals, unstaffed shifts, data quality
// ============================================================
function renderCcBanners(d) {
    const el = document.getElementById('ccBanners');
    if (!el) return;
    const s = d.status, out = [];
    out.push(ccDatenbasisHtml(d));
    if (s.offen.einsaetze > 0) {
        const teil = d.datenbasis && d.datenbasis.teilweise ? ` · ${ccNum(d.datenbasis.teilweise)} davon teilweise freigegeben` : '';
        out.push(`<div class="cc-banner warn">
          ${ctrlIcon('pending_actions')}
          <div class="txt"><b>${ccNum(s.offen.einsaetze)} geleistete Einsätze noch nicht für den Kunden freigegeben</b> (${ccEur(s.offen.umsatz)} Umsatz, ältester vom ${ccDate(s.offen.min_datum)}${teil}).
            ${d.basis === 'abrechnung' ? 'Sie fehlen in dieser Abrechnungssicht, bis sie in der Zeiterfassung (Modus „Kunde“) freigegeben sind.' : 'Sie sind in der operativen Sicht enthalten, aber noch nicht abrechnungsreif.'}</div>
          <md-text-button onclick="loadPage('monatsabschluss')">In der Zeiterfassung freigeben</md-text-button>
        </div>`);
    }
    if (s.unbesetzt.einsaetze > 0) {
        out.push(`<div class="cc-banner danger">
          ${ctrlIcon('person_off')}
          <div class="txt"><b>${ccNum(s.unbesetzt.einsaetze)} vergangene Einsätze ohne Personal</b> (Auftragswert ${ccEur(s.unbesetzt.umsatz)}, ältester vom ${ccDate(s.unbesetzt.min_datum)}) —
            werden nicht als Umsatz gezählt. Bitte nachträglich besetzen oder stornieren.</div>
          <md-text-button onclick="loadPage('tasks')">Zur Disposition</md-text-button>
        </div>`);
    }
    const q = d.datenqualitaet || {}, dq = [];
    if (q.einsaetze_ohne_preis) dq.push(`<b>${ccNum(q.einsaetze_ohne_preis)} Einsätze ohne Kundenpreis</b>`);
    if (q.mitarbeiter_ohne_lohn) dq.push(`<b>${ccNum(q.mitarbeiter_ohne_lohn)} Mitarbeiter ohne Lohnangabe</b> (Personalkosten 0 €)`);
    if (dq.length) {
        out.push(`<div class="cc-banner info">${ctrlIcon('data_alert')}<div class="txt"><b>Datenqualität:</b> ${dq.join(' · ')} — das Ergebnis ist dadurch zu gut.</div></div>`);
    }
    el.innerHTML = out.join('');
}

/** Where the hours come from (Zeiterfassung): app clock / office / plan + customer release. */
function ccDatenbasisHtml(d) {
    const b = d.datenbasis;
    if (!b || !b.stunden) return '';
    const seg = (v, cls, t) => v > 0 ? `<span class="${cls}" style="width:${v}%" title="${t}: ${ccPct(v)}"></span>` : '';
    const good = (b.plan_pct || 0) <= 20;
    return `<div class="cc-source">
      <div class="cc-source-head">${ctrlIcon('fact_check', 'font-size:18px')}
        <b>Datenbasis aus der Zeiterfassung</b>
        <small class="cc-muted">${ccNum(b.stunden, 1)} geleistete Std. bis heute</small></div>
      <div class="cc-source-grid">
        <div>
          <small>Mitarbeiterzeit (→ Personalkosten)</small>
          <div class="cc-stack">${seg(b.app_pct, 's-app', 'App-Stempelung')}${seg(b.manuell_pct, 's-man', 'Vom Büro erfasst')}${seg(b.plan_pct, 's-planx', 'Nur Planwert')}</div>
          <div class="cc-legend"><span><i class="s-app"></i>App ${ccPct(b.app_pct)}</span><span><i class="s-man"></i>Büro ${ccPct(b.manuell_pct)}</span>
            <span class="${good ? '' : 'cc-neg'}"><i class="s-planx"></i>nur Plan ${ccPct(b.plan_pct)}</span></div>
        </div>
        <div>
          <small>Kundenstunden (→ Umsatz Abrechnungssicht)</small>
          <div class="cc-stack">${seg(b.kunden_frei_pct, 's-frei', 'Für den Kunden freigegeben')}</div>
          <div class="cc-legend"><span><i class="s-frei"></i>freigegeben ${ccPct(b.kunden_frei_pct)}</span><span class="cc-muted">Rest: Ist-Zeit bzw. Plan</span></div>
        </div>
      </div>
    </div>`;
}

// ============================================================
// ERGEBNISRECHNUNG (stufenweise DB)
// ============================================================
function renderCcResult(d) {
    const el = document.getElementById('ccResult');
    if (!el) return;
    const e = d.ergebnis, st = d.settings, v = d.vergleich, dl = v ? v.delta : {};
    const prev = v ? v.vorher : null;
    const sub = document.getElementById('ccResultSub');
    if (sub) sub.textContent = `${ccNum(e.einsaetze)} Einsätze · ${ccNum(e.stunden, 1)} Std.`;

    const share = x => e.umsatz > 0 ? ccPct(x / e.umsatz * 100) : '–';
    const rows = [
        { l: 'Umsatz', v: e.umsatz, k: 'umsatz', cls: 'head' },
        { l: '− Nachunternehmer', v: -e.nu_kosten, pv: prev && -prev.nu_kosten },
        { l: '= Rohertrag', v: e.rohertrag, k: 'rohertrag', cls: 'sub' },
        { l: '− Personal operativ', v: -e.personal, pv: prev && -prev.personal, hint: 'Lohn × Stunden inkl. Schichtzuschläge' },
        { l: '= Deckungsbeitrag I', v: e.db1, k: 'db1', cls: 'sub' },
        { l: '− Lohnnebenkosten & Ausfallzeiten', v: -e.lohnnebenkosten, pv: prev && -prev.lohnnebenkosten,
          hint: `AG-Anteil ${ccNum(st.ag_anteil, 1)} % · Ausfallfaktor ${ccNum(st.ausfall_faktor, 3)} (Urlaub, Feiertage, Krankheit)` },
        { l: '= Deckungsbeitrag II', v: e.db2, k: 'db2', cls: 'total', ampel: e.ampel },
    ];
    if (d.fix_aktiv) {
        const missing = !e.fixkosten;
        rows.push({ l: '− Fixkosten (Gemeinkosten)', v: missing ? null : -e.fixkosten, pv: prev && prev.fixkosten !== null ? -prev.fixkosten : null,
            hint: missing ? 'Noch keine Fixkosten erfasst' : 'Büro, Verwaltung, Fahrzeuge … anteilig für den Zeitraum', missing });
        rows.push({ l: '= Betriebsergebnis', v: missing ? null : e.betriebsergebnis, k: missing ? null : 'betriebsergebnis', cls: 'total big' });
    }

    el.innerHTML = `
      <table class="cc-pl">
        <thead><tr><th></th><th class="num">Betrag</th><th class="num cc-col-share">% v. Umsatz</th>${v ? `<th class="num cc-col-prev" title="${escapeHtml(v.label)}">${v.mode === 'year' ? 'Vorjahr' : 'Vorperiode'}</th><th class="num">Δ</th>` : ''}</tr></thead>
        <tbody>
          ${rows.map(r => `
            <tr class="${r.cls || 'line'}">
              <td>${r.ampel !== undefined ? ccAmpel(r.ampel) + ' ' : ''}${escapeHtml(r.l)}
                ${r.hint ? `<small>${escapeHtml(r.hint)}${r.missing && d.can_edit_settings ? ` · <a href="#" onclick="openCcSettings();return false">jetzt erfassen</a>` : ''}</small>` : ''}</td>
              <td class="num ${r.cls && r.v !== null ? ccSign(r.v) : ''}">${r.v === null ? '–' : ccEur(r.v)}</td>
              <td class="num cc-muted cc-col-share">${r.v === null || r.k === 'umsatz' ? '' : share(Math.abs(r.v)) }</td>
              ${v ? `<td class="num cc-muted cc-col-prev">${r.k ? ccEur(prev[r.k]) : (r.pv === null || r.pv === undefined ? '' : ccEur(r.pv))}</td>
                     <td class="num">${r.k ? deltaBadge(dl[r.k]) : ''}</td>` : ''}
            </tr>`).join('')}
        </tbody>
      </table>
      ${v && v.cut ? `<div class="cc-foot">${ctrlIcon('info', 'font-size:14px')} Vergleich bis zum gleichen Tag der Vorperiode (${escapeHtml(v.label)}), damit ein laufender Zeitraum nicht schlechter aussieht als er ist.</div>` : ''}
    `;
}

function renderCcKpis(d) {
    const el = document.getElementById('ccKpis');
    if (!el) return;
    const e = d.ergebnis, dl = d.vergleich ? d.vergleich.delta : {};
    const pp = dl.marge_db2_pp;
    const ppHtml = pp === null || pp === undefined ? '' :
        `<span class="ctrl-delta ${pp > 0 ? 'up' : pp < 0 ? 'down' : 'flat'}">${pp > 0 ? '+' : ''}${ccNum(pp, 1)} Pkt.</span>`;
    const dbH = e.erloes_h !== null && e.kosten_h !== null ? e.erloes_h - e.kosten_h : null;
    el.innerHTML = `
      ${kpiCard('k-revenue', 'euro', 'Umsatz', ccEur(e.umsatz), dl.umsatz, `${ccNum(e.einsaetze)} Einsätze`)}
      <div class="ctrl-kpi k-profit">
        <div class="ctrl-kpi-head"><span>Marge DB II</span>${ccAmpel(e.ampel)}</div>
        <div class="ctrl-kpi-value ${ccSign(e.marge_db2)}">${ccPct(e.marge_db2)}</div>
        <div class="ctrl-kpi-foot"><span>${ccEur(e.db2)} Deckungsbeitrag II</span>${ppHtml}</div>
      </div>
      ${kpiCard('k-hours', 'timer', 'Leistungsstunden', ccNum(e.stunden, 1), dl.stunden, 'abrechenbare Kundenstunden')}
      <div class="ctrl-kpi k-margin">
        <div class="ctrl-kpi-head"><span>Erlös / Kosten je Std.</span>${ctrlIcon('price_check', 'font-size:15px')}</div>
        <div class="ctrl-kpi-value">${ccEur(e.erloes_h)} <small class="cc-muted">/ ${ccEur(e.kosten_h)}</small></div>
        <div class="ctrl-kpi-foot"><span class="${ccSign(dbH)}">${dbH === null ? '–' : (dbH >= 0 ? '+' : '') + ccEur(dbH)} je Stunde</span></div>
      </div>
    `;
}

// ============================================================
// HOCHRECHNUNG
// ============================================================
function renderCcForecast(d) {
    const card = document.getElementById('ccForecastCard');
    const el = document.getElementById('ccForecast');
    if (!card || !el) return;
    const h = d.hochrechnung;
    if (!h) { card.style.display = 'none'; return; }
    card.style.display = '';
    const e = h.erwartet, tot = Math.max(e.umsatz, 1), cmp = h.vergleich;
    const seg = (v, cls, t) => v > 0 ? `<span class="${cls}" style="width:${(v / tot * 100).toFixed(1)}%" title="${t}: ${ccEur(v)}"></span>` : '';
    const diff = cmp ? e.umsatz - cmp.umsatz : null;
    el.innerHTML = `
      <div class="cc-fc-main">
        <div><small>Erwarteter Umsatz</small><b>${ccEur(e.umsatz)}</b></div>
        <div><small>Erwarteter DB II</small><b class="${ccSign(e.db2)}">${ccEur(e.db2)}</b> <small>${ccPct(e.marge_db2)}</small></div>
        ${e.fixkosten ? `<div><small>Erw. Betriebsergebnis</small><b class="${ccSign(e.betriebsergebnis)}">${ccEur(e.betriebsergebnis)}</b></div>` : ''}
      </div>
      <div class="cc-stack">${seg(h.davon_freigegeben, 's-frei', 'Freigegeben')}${seg(h.davon_offen, 's-offen', 'Geleistet, offen')}${seg(h.davon_geplant, 's-plan', 'Geplant')}</div>
      <div class="cc-legend">
        <span><i class="s-frei"></i>Kunde freigegeben ${ccEur(h.davon_freigegeben)}</span>
        <span><i class="s-offen"></i>Geleistet, offen ${ccEur(h.davon_offen)}</span>
        <span><i class="s-plan"></i>Geplant ${ccEur(h.davon_geplant)}</span>
      </div>
      ${cmp ? `<div class="cc-foot">${h.vergleich_label}: ${ccEur(cmp.umsatz)} Umsatz · DB II ${ccEur(cmp.db2)} —
        Hochrechnung <b class="${ccSign(diff)}">${diff >= 0 ? '+' : ''}${ccEur(diff)}</b></div>` : ''}
      ${h.geplant_ohne_personal ? `<div class="cc-foot warn">${ctrlIcon('warning', 'font-size:14px')} ${ccNum(h.geplant_ohne_personal)} geplante Einsätze haben noch kein Personal — Kosten mit Ø-Lohn ${ccEur(d.settings.avg_stundenlohn)}/Std. geschätzt.</div>` : ''}
    `;
}

// ============================================================
// OBJEKTCONTROLLING
// ============================================================
function renderCcObjekte(d) {
    const el = document.getElementById('ccObjekte');
    if (!el) return;
    let rows = (d.objekte || []).filter(r => CTRLC.objFilter === 'all' || r.ampel === CTRLC.objFilter);
    const counts = { rot: 0, gelb: 0, gruen: 0 };
    (d.objekte || []).forEach(r => { if (counts[r.ampel] !== undefined) counts[r.ampel]++; });
    document.querySelectorAll('#ccObjFilter .chip').forEach(c => {
        const a = c.dataset.a;
        c.textContent = a === 'all' ? `Alle (${(d.objekte || []).length})` : `${CC_AMPEL_LABEL[a]} (${counts[a]})`;
    });
    if (!rows.length) { el.innerHTML = ctrlEmptyHtml('folder_open', 'Keine Objekte im Zeitraum'); return; }
    const all = rows.length;
    rows = ccLimit('objekte', rows);
    el.innerHTML = `
      <table class="ctrl-bd-table ctrl-bd-clickable cc-table">
        <thead><tr>
          <th></th><th>Objekt</th><th>ABC</th>
          <th class="num">Umsatz</th><th class="num">DB II</th><th class="num">Marge</th>
          <th class="num" title="Bestellte Stunden laut Bestelleingang bis heute">Soll-Std.</th>
          <th class="num" title="Geleistete Stunden (freigegeben + offen)">Ist-Std.</th>
          <th class="num" title="Ist − Soll">Abw.</th>
        </tr></thead>
        <tbody>
          ${rows.map(r => `
            <tr data-id="${escapeHtml(r.dim_id || '')}" data-name="${escapeHtml(r.name)}">
              <td>${ccAmpel(r.ampel)}</td>
              <td><div class="name">${escapeHtml(r.name)}</div><small class="cc-muted">${escapeHtml(r.kunde || '–')}</small></td>
              <td><span class="cc-abc abc-${r.abc}">${r.abc}</span></td>
              <td class="num">${ccEur(r.umsatz)}</td>
              <td class="num ${ccSign(r.db2)}">${ccEur(r.db2)}</td>
              <td class="num ${ccSign(r.marge_db2)}">${ccPct(r.marge_db2)}</td>
              <td class="num">${ccNum(r.soll_std, 1)}</td>
              <td class="num">${ccNum(r.ist_std, 1)}</td>
              <td class="num">${r.abweichung_std === null ? '–' : `<span class="${Math.abs(r.abweichung_pct || 0) > 10 ? 'cc-neg' : 'cc-muted'}">${r.abweichung_std > 0 ? '+' : ''}${ccNum(r.abweichung_std, 1)}</span>`}</td>
            </tr>`).join('')}
        </tbody>
      </table>${ccMoreBtn('objekte', all)}`;
    el.querySelectorAll('tbody tr[data-id]').forEach(tr => tr.addEventListener('click', () => {
        if (tr.dataset.id) openDrill('objekt', tr.dataset.id, tr.dataset.name);
    }));
}

// ============================================================
// PREIS-CHECK JE ARTIKEL
// ============================================================
function renderCcArtikel(d) {
    const el = document.getElementById('ccArtikel');
    if (!el) return;
    const all = (d.artikel || []).length;
    const rows = ccLimit('artikel', d.artikel || []);
    if (!rows.length) { el.innerHTML = ctrlEmptyHtml('folder_open', 'Keine Artikel im Zeitraum'); return; }
    el.innerHTML = `
      <table class="ctrl-bd-table ctrl-bd-clickable cc-table">
        <thead><tr>
          <th></th><th>Artikel</th><th class="num">Std.</th>
          <th class="num">Erlös/Std.</th><th class="num">Vollkosten/Std.</th><th class="num">DB/Std.</th>
          <th class="num">Umsatz</th><th class="num">Marge</th>
        </tr></thead>
        <tbody>
          ${rows.map(r => {
              const dbh = r.erloes_h !== null && r.kosten_h !== null ? r.erloes_h - r.kosten_h : null;
              const tooLow = r.erloes_h !== null && r.kosten_h !== null && r.erloes_h < r.kosten_h * 0.5;
              return `
            <tr data-id="${escapeHtml(r.dim_id || '')}" data-name="${escapeHtml(r.name)}">
              <td>${ccAmpel(r.ampel)}</td>
              <td><div class="name">${escapeHtml(r.name)}</div>${tooLow ? `<small class="cc-neg">Erlös unter halben Kosten — Preis oder Abrechnungsmodell (z. B. „Pro Einsatz“ statt „Pro Stunde“) prüfen</small>` : ''}</td>
              <td class="num">${ccNum(r.stunden, 1)}</td>
              <td class="num">${ccEur(r.erloes_h)}</td>
              <td class="num cc-muted">${ccEur(r.kosten_h)}</td>
              <td class="num ${ccSign(dbh)}">${dbh === null ? '–' : ccEur(dbh)}</td>
              <td class="num">${ccEur(r.umsatz)}</td>
              <td class="num ${ccSign(r.marge_db2)}">${ccPct(r.marge_db2)}</td>
            </tr>`; }).join('')}
        </tbody>
      </table>${ccMoreBtn('artikel', all)}`;
    el.querySelectorAll('tbody tr[data-id]').forEach(tr => tr.addEventListener('click', () => {
        if (tr.dataset.id) openDrill('artikel', tr.dataset.id, tr.dataset.name);
    }));
}

// ============================================================
// TABS: Kunden / Mitarbeiter / NU / Bereiche
// ============================================================
const CC_TAB_META = {
    kunden:      { label: 'Kunde',           drill: 'kunde' },
    mitarbeiter: { label: 'Mitarbeiter',     drill: 'mitarbeiter' },
    nu:          { label: 'Nachunternehmer', drill: 'nu' },
    bereiche:    { label: 'Bereich',         drill: 'bereich' },
};

function renderCcTab(d) {
    const el = document.getElementById('ccTabBody');
    if (!el) return;
    const meta = CC_TAB_META[CTRLC.tab] || CC_TAB_META.kunden;
    const all = (d[CTRLC.tab] || []).length;
    const rows = ccLimit('tab', d[CTRLC.tab] || []);
    if (!rows.length) { el.innerHTML = ctrlEmptyHtml('folder_open', 'Keine Daten im Zeitraum'); return; }
    const isMa = CTRLC.tab === 'mitarbeiter';
    el.innerHTML = `
      ${isMa ? `<small class="cc-muted cc-pad" style="display:block">Umsatzanteil nach eigenen Stunden · Kosten = Stunden × Lohn × Vollkostenfaktor ${ccNum(d.settings.vollkostenfaktor, 3)} · nur interne Einsätze</small>` : ''}
      <table class="ctrl-bd-table ctrl-bd-clickable cc-table">
        <thead><tr>
          <th></th><th>${meta.label}</th><th class="num">Einsätze</th><th class="num">Std.</th>
          <th class="num">Umsatz</th>${isMa ? '' : '<th class="num">DB I</th>'}<th class="num">DB II</th><th class="num">Marge</th>
        </tr></thead>
        <tbody>
          ${rows.map(r => `
            <tr data-id="${escapeHtml(r.dim_id ?? '')}" data-name="${escapeHtml(r.name ?? '')}">
              <td>${ccAmpel(r.ampel)}</td>
              <td><div class="name">${CTRLC.tab === 'bereiche' && typeof bereichDotHtml === 'function' ? bereichDotHtml(r.name, false) + ' ' : ''}${escapeHtml(r.name ?? '–')}</div>
                ${isMa && r.ohne_lohn ? '<small class="cc-neg">Kein Lohn hinterlegt</small>' : ''}</td>
              <td class="num">${ccNum(r.einsaetze)}</td>
              <td class="num">${ccNum(r.stunden, 1)}</td>
              <td class="num">${ccEur(r.umsatz)}</td>
              ${isMa ? '' : `<td class="num ${ccSign(r.db1)}">${ccEur(r.db1)}</td>`}
              <td class="num ${ccSign(r.db2)}">${ccEur(r.db2)}</td>
              <td class="num ${ccSign(r.marge_db2)}">${ccPct(r.marge_db2)}</td>
            </tr>`).join('')}
        </tbody>
      </table>${ccMoreBtn('tab', all)}`;
    el.querySelectorAll('tbody tr[data-id]').forEach(tr => tr.addEventListener('click', () => {
        const id = tr.dataset.id;
        if (id && id !== '–') openDrill(meta.drill, id, tr.dataset.name);
    }));
}

// ============================================================
// TREND (12 Monate)
// ============================================================
async function renderCcTrend(d) {
    if (!(await ensureChartReady())) return showChartError('ccTrendChart');
    const canvas = document.getElementById('ccTrendChart');
    if (!canvas) return;
    if (CTRL.charts.cc) { CTRL.charts.cc.destroy(); CTRL.charts.cc = null; }
    const t = d.trend || [];
    const labels = t.map(r => { const [y, m] = r.monat.split('-'); return `${MONTHS_SHORT_DE[+m]} ${y.slice(2)}`; });
    const opBasis = d.basis === 'operativ';
    const ds = [
        { type: 'bar', label: opBasis ? 'Umsatz (freigegeben + geleistet)' : 'Umsatz Kunde freigegeben', data: t.map(r => r.umsatz), backgroundColor: 'rgba(59,130,246,0.75)', stack: 'u', order: 3 },
    ];
    if (!opBasis) ds.push({ type: 'bar', label: 'Geleistet, nicht freigegeben', data: t.map(r => r.umsatz_offen), backgroundColor: 'rgba(245,158,11,0.6)', stack: 'u', order: 3 });
    ds.push({ type: 'bar', label: 'Geplant', data: t.map(r => r.umsatz_geplant), backgroundColor: 'rgba(148,163,184,0.45)', stack: 'u', order: 3 });
    ds.push({ type: 'line', label: 'Deckungsbeitrag II', data: t.map(r => r.db2), borderColor: '#10b981', backgroundColor: '#10b981', tension: 0.25, order: 1, pointRadius: 3 });
    if (d.fix_aktiv && t.some(r => r.fixkosten)) {
        ds.push({ type: 'line', label: 'Betriebsergebnis', data: t.map(r => r.betriebsergebnis), borderColor: '#1a2744', backgroundColor: '#1a2744', borderDash: [5, 4], tension: 0.25, order: 0, pointRadius: 3 });
    }
    const opts = chartBaseOpts({ currency: true });
    // The card grid settles after the first paint (scrollbar, side column);
    // without animation every resize re-lays bars and ticks together.
    opts.animation = false;
    opts.scales = Object.assign({}, opts.scales, {
        x: Object.assign({}, opts.scales && opts.scales.x, { stacked: true }),
        y: Object.assign({}, opts.scales && opts.scales.y, { stacked: false }),
    });
    CTRL.charts.cc = new Chart(canvas, { type: 'bar', data: { labels, datasets: ds }, options: opts });
}

// ============================================================
// SETTINGS: Kostensätze + Fixkosten
// ============================================================
async function openCcSettings() {
    const [cfg, fix] = await Promise.all([
        apiGet(CTRL.api + '/settings'),
        apiGet(CTRL.api + '/fixkosten'),
    ]);
    if (!cfg || !cfg.settings) { showToast('Einstellungen konnten nicht geladen werden', 'error'); return; }
    const s = cfg.settings, edit = !!cfg.can_edit, ro = edit ? '' : 'disabled';
    const items = (fix && Array.isArray(fix.items)) ? fix.items : [];
    const curMonth = new Date().toISOString().slice(0, 7);

    document.getElementById('ccSettingsDlg')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'ccSettingsDlg';
    dlg.classList.add('cc-settings-dlg');
    dlg.innerHTML = `
      <div slot="headline">Kostensätze & Fixkosten</div>
      <div slot="content" class="cc-set">
        ${edit ? '' : `<div class="cc-banner info" style="margin:0 0 12px">${ctrlIcon('lock')}<div class="txt">Nur der Firmen-Inhaber kann diese Werte ändern.</div></div>`}
        <h4>Personal-Vollkosten</h4>
        <p class="cc-muted">Auf den Bruttolohn kommen Arbeitgeberanteile und bezahlte, aber nicht verrechenbare Ausfallzeiten. Standardwerte nach Branchenkalkulation 2026 — bitte durch die eigenen Zahlen (Lohnbüro) ersetzen.</p>
        <div class="cc-set-grid">
          <label>AG-Anteil Sozialversicherung + Umlagen (%)<input type="number" step="0.1" class="form-control" id="ccAg" value="${s.ag_anteil}" ${ro}><small>Standard ${ccNum(cfg.defaults.ag_anteil, 1)} % (KV, RV, AV, PV, U2, Insolvenzgeld)</small></label>
          <label>Ausfallfaktor<input type="number" step="0.001" class="form-control" id="ccAf" value="${s.ausfall_faktor}" ${ro}><small>Bezahlte ÷ produktive Stunden · Standard ${ccNum(cfg.defaults.ausfall_faktor, 3)} (≈ 40 Ausfalltage/Jahr)</small></label>
          <label>Ampel rot unter DB-II-Marge (%)<input type="number" step="0.5" class="form-control" id="ccRot" value="${s.ampel_rot}" ${ro}></label>
          <label>Ampel gelb unter DB-II-Marge (%)<input type="number" step="0.5" class="form-control" id="ccGelb" value="${s.ampel_gelb}" ${ro}></label>
        </div>
        <div class="cc-foot">Vollkostenfaktor aktuell: <b id="ccFactor">${ccNum(cfg.vollkostenfaktor, 3)}</b> — 1 € Bruttolohn kostet ${ccEur(cfg.vollkostenfaktor)}.</div>
        ${edit ? `<div style="text-align:right;margin-top:8px"><md-filled-tonal-button id="ccSaveSettings">Kostensätze speichern</md-filled-tonal-button></div>` : ''}

        <h4 style="margin-top:22px">Monatliche Fixkosten</h4>
        <p class="cc-muted">Kosten, die keinem Objekt zuzuordnen sind (Büro, Verwaltungspersonal, Fahrzeuge, Versicherungen …). Sie wiederholen sich jeden Monat und werden als Sammelposition zwischen DB II und Betriebsergebnis abgezogen.</p>
        ${items.length ? `
        <table class="ctrl-bd-table cc-table">
          <thead><tr><th>Position</th><th>Kategorie</th><th class="num">€ / Monat</th><th>Gültig</th>${edit ? '<th></th>' : ''}</tr></thead>
          <tbody>
            ${items.map(i => `
              <tr class="${i.aktiv ? '' : 'cc-inactive'}">
                <td>${escapeHtml(i.bezeichnung)}</td>
                <td class="cc-muted">${escapeHtml(i.kategorie || '–')}</td>
                <td class="num">${ccEur(i.betrag)}</td>
                <td class="cc-muted">${ccMonth(i.ab_monat)} – ${i.bis_monat ? ccMonth(i.bis_monat) : 'laufend'}</td>
                ${edit ? `<td style="white-space:nowrap">
                  <md-icon-button title="Betrag ab einem Monat ändern" data-act="change" data-id="${i.id}"><md-icon>edit_calendar</md-icon></md-icon-button>
                  <md-icon-button title="Beenden / korrigieren" data-act="edit" data-id="${i.id}"><md-icon>edit</md-icon></md-icon-button>
                  <md-icon-button title="Löschen" data-act="del" data-id="${i.id}"><md-icon>delete</md-icon></md-icon-button>
                </td>` : ''}
              </tr>`).join('')}
          </tbody>
          <tfoot><tr><td colspan="2"><b>Summe aktueller Monat</b></td><td class="num"><b>${ccEur(fix.monat_summe)}</b></td><td colspan="${edit ? 2 : 1}"></td></tr></tfoot>
        </table>` : `<div class="cc-empty-fix">${ctrlIcon('receipt_long')} Noch keine Fixkosten erfasst — ohne sie zeigt das Cockpit nur den Deckungsbeitrag, kein Betriebsergebnis.</div>`}
        ${edit ? `
        <div class="cc-fix-form" id="ccFixForm">
          <input type="hidden" id="ccFixId" value="">
          <input class="form-control" id="ccFixName" placeholder="Bezeichnung, z. B. Büromiete" maxlength="150">
          <input class="form-control" id="ccFixKat" list="ccFixKatList" placeholder="Kategorie" maxlength="50">
          <datalist id="ccFixKatList">${CC_FIX_KATEGORIEN.map(k => `<option value="${k}">`).join('')}</datalist>
          <input class="form-control" id="ccFixBetrag" type="number" step="0.01" min="0" placeholder="€ / Monat">
          <input class="form-control" id="ccFixAb" type="month" value="${curMonth}" title="Ab Monat">
          <input class="form-control" id="ccFixBis" type="month" title="Bis Monat (leer = laufend)">
          <md-filled-button id="ccFixSave">Hinzufügen</md-filled-button>
        </div>` : ''}
      </div>
      <div slot="actions"><md-text-button data-v="close">Schließen</md-text-button></div>`;
    document.body.appendChild(dlg);
    dlg.querySelector('[data-v="close"]').onclick = () => dlg.close();
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();

    if (!edit) return;
    const factorOut = () => {
        const ag = parseFloat(document.getElementById('ccAg').value) || 0;
        const af = parseFloat(document.getElementById('ccAf').value) || 1;
        document.getElementById('ccFactor').textContent = ccNum((1 + ag / 100) * af, 3);
    };
    ['ccAg', 'ccAf'].forEach(id => document.getElementById(id).addEventListener('input', factorOut));

    document.getElementById('ccSaveSettings').onclick = async () => {
        const body = {
            ag_anteil: parseFloat(document.getElementById('ccAg').value),
            ausfall_faktor: parseFloat(document.getElementById('ccAf').value),
            ampel_rot: parseFloat(document.getElementById('ccRot').value),
            ampel_gelb: parseFloat(document.getElementById('ccGelb').value),
        };
        const r = await apiPut(CTRL.api + '/settings', body);
        if (r && r.success) { showToast('Kostensätze gespeichert'); ctrlLoadAll(false); }
        else showToast((r && (r.error || r.message)) || 'Speichern fehlgeschlagen', 'error');
    };

    document.getElementById('ccFixSave').onclick = async () => {
        const id = document.getElementById('ccFixId').value;
        const body = {
            bezeichnung: document.getElementById('ccFixName').value.trim(),
            kategorie: document.getElementById('ccFixKat').value.trim(),
            betrag: parseFloat(document.getElementById('ccFixBetrag').value),
            ab_monat: document.getElementById('ccFixAb').value,
            bis_monat: document.getElementById('ccFixBis').value || null,
        };
        if (!body.bezeichnung || isNaN(body.betrag) || !body.ab_monat) { showToast('Bezeichnung, Betrag und Ab-Monat sind erforderlich', 'error'); return; }
        const r = id ? await apiPut(CTRL.api + '/fixkosten/' + id, body) : await apiPost(CTRL.api + '/fixkosten', body);
        if (r && r.success) { showToast(id ? 'Position aktualisiert' : 'Fixkosten hinzugefügt'); dlg.close(); openCcSettings(); ctrlLoadAll(false); }
        else showToast((r && (r.error || r.message)) || 'Speichern fehlgeschlagen', 'error');
    };

    dlg.querySelectorAll('md-icon-button[data-act]').forEach(b => b.addEventListener('click', async () => {
        const item = items.find(i => String(i.id) === b.dataset.id);
        if (!item) return;
        if (b.dataset.act === 'del') {
            if (!(await mdConfirm(`„${escapeHtml(item.bezeichnung)}“ komplett löschen? Auch vergangene Monate verlieren diese Kosten.\n\nWenn die Kosten nur wegfallen, stattdessen über „Beenden“ einen Bis-Monat setzen.`, { title: 'Fixkosten löschen', confirmLabel: 'Löschen' }))) return;
            const r = await apiDelete(CTRL.api + '/fixkosten/' + item.id, {});
            if (r && r.success) { showToast('Position gelöscht'); dlg.close(); openCcSettings(); ctrlLoadAll(false); }
            else showToast((r && r.error) || 'Löschen fehlgeschlagen', 'error');
        } else if (b.dataset.act === 'edit') {
            document.getElementById('ccFixId').value = item.id;
            document.getElementById('ccFixName').value = item.bezeichnung;
            document.getElementById('ccFixKat').value = item.kategorie || '';
            document.getElementById('ccFixBetrag').value = item.betrag;
            document.getElementById('ccFixAb').value = item.ab_monat;
            document.getElementById('ccFixBis').value = item.bis_monat || '';
            document.getElementById('ccFixSave').textContent = 'Änderung speichern';
            document.getElementById('ccFixForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            ccChangeFromMonth(item, () => { dlg.close(); openCcSettings(); ctrlLoadAll(false); });
        }
    }));
}

/** New amount from a month on — past months keep the old value. */
function ccChangeFromMonth(item, done) {
    const next = new Date(); next.setMonth(next.getMonth() + 1);
    const d = document.createElement('md-dialog');
    d.innerHTML = `
      <div slot="headline">Betrag ändern ab Monat</div>
      <div slot="content" class="cc-set">
        <p class="cc-muted" style="margin-top:0">„${escapeHtml(item.bezeichnung)}“ — bisher ${ccEur(item.betrag)}/Monat. Frühere Monate behalten den alten Betrag.</p>
        <div class="cc-set-grid">
          <label>Neuer Betrag (€ / Monat)<input type="number" step="0.01" min="0" class="form-control" id="ccChgBetrag" value="${item.betrag}"></label>
          <label>Gültig ab<input type="month" class="form-control" id="ccChgAb" value="${next.toISOString().slice(0, 7)}"></label>
        </div>
      </div>
      <div slot="actions"><md-text-button data-v="cancel">Abbrechen</md-text-button><md-filled-button data-v="ok">Übernehmen</md-filled-button></div>`;
    document.body.appendChild(d);
    d.querySelector('[data-v="cancel"]').onclick = () => d.close();
    d.querySelector('[data-v="ok"]').onclick = async () => {
        const r = await apiPost(CTRL.api + '/fixkosten/' + item.id + '/aendern', {
            betrag: parseFloat(document.getElementById('ccChgBetrag').value),
            ab_monat: document.getElementById('ccChgAb').value,
        });
        if (r && r.success) { showToast('Betrag geändert'); d.close(); done(); }
        else showToast((r && (r.error || r.message)) || 'Ändern fehlgeschlagen', 'error');
    };
    d.addEventListener('closed', () => d.remove());
    d.show();
}

function ccMonth(ym) {
    if (!ym) return '–';
    const [y, m] = ym.split('-');
    return `${MONTHS_SHORT_DE[+m]} ${y}`;
}
