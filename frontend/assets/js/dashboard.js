/* ============================================
   Clood-X — Übersicht (Command Center)
   Backend: GET /api/dashboard/cockpit   (Jetzt · Zu erledigen · 7 Tage)
            GET /api/dashboard/overview?slim=1  (Hero + Kennzahlen + Umsatz-Trend)
   ============================================ */

let _dashTrendChart = null;

// Material Symbols shorthand (module-local, mirrors users.js _ms())
function dashIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}

// Kennzahlen always show the current month (compared with the previous month).
// Other periods live in Controlling ("Mehr Auswertung").
const _dashPeriod = { key: 'month' };

async function renderDashboard() {
    const content = document.getElementById('content');
    if (!content) return;

    content.innerHTML = `
        <div class="dash-hero">
            <div class="dash-hero-text">
                <h2><md-circular-progress indeterminate style="--md-circular-progress-size:28px;--md-circular-progress-active-indicator-color:#fff"></md-circular-progress> Übersicht wird geladen…</h2>
                <div class="dash-hero-sub"><span class="pill">Daten werden zusammengetragen</span></div>
            </div>
        </div>`;

    if (typeof Chart === 'undefined') {
        try { await loadChartJsForDashboard(); } catch (e) { /* fall back silently */ }
    }

    const [data, cp] = await Promise.all([
        apiGet(API_BASE + '/dashboard/overview?' + dashOverviewQs()),
        apiGet(API_BASE + '/dashboard/cockpit'),
    ]);
    if (!data || data.error || !data.can) {
        content.innerHTML = `
            <div class="dash-empty">
                ${dashIcon('cloud_off')}
                <div>Übersicht konnte nicht geladen werden.</div>
                <md-filled-button style="margin-top:12px" onclick="renderDashboard()">
                    <md-icon slot="icon">refresh</md-icon>Erneut versuchen
                </md-filled-button>
            </div>`;
        return;
    }
    const can = data.can || {};
    const c = (cp && !cp.error) ? cp : { heute: null, woche: [], inbox: [] };

    const nowHtml   = c.heute ? `<section class="dash-sec" id="dashNow">${dashNowHtml(c.heute)}</section>` : '';
    const inboxHtml = `<div class="dash-card"><div class="dash-card-head"><h3>${dashIcon('inbox')} Zu erledigen</h3>
                         <span class="dash-muted" id="dashInboxCount">${dashInboxCountText(c.inbox)}</span></div>
                         <div id="dashInbox">${dashInboxHtml(c.inbox)}</div></div>`;
    const weekHtml  = (c.woche || []).length ? `<div class="dash-card"><div class="dash-card-head"><h3>${dashIcon('calendar_month')} Nächste 7 Tage — Besetzung</h3>
                         <a class="link-more" onclick="loadPage('tasks')">Zur Planung →</a></div>
                         <div id="dashWeek">${dashWeekHtml(c.woche)}</div></div>` : '';
    const kpiHtml   = dashKpiHtml(data.kpis, data.period_label, data.prev_label);
    const trendHtml = can.controlling ? `
        <div class="dash-card">
            <div class="dash-card-head">
                <h3>${dashIcon('area_chart')} Umsatz — ${dashEscape(data.trend_label || data.period_label || '')}</h3>
                <a class="link-more" onclick="loadPage('reports')">Mehr Auswertung →</a>
            </div>
            <div class="dash-chart-wrap"><canvas id="dashTrend"></canvas></div>
        </div>` : '';
    const figures = (kpiHtml || trendHtml)
        ? `<h3 class="dash-sec-title">${dashIcon('monitoring')} Kennzahlen — ${dashEscape(data.period_label || '')}</h3>${kpiHtml}${trendHtml}` : '';

    const any = nowHtml || (c.inbox || []).length || weekHtml || figures;
    content.innerHTML = `
        ${dashHeroHtml(data)}
        ${nowHtml}
        <div class="dash-grid"><div>${inboxHtml}</div><div>${weekHtml}</div></div>
        ${figures}
        ${any ? '' : `<div class="dash-empty">${dashIcon('lock')}<div>Für Ihre Berechtigungen gibt es hier noch keine Übersichtsdaten.<br>Nutzen Sie das Menü links, um Ihre freigegebenen Bereiche zu öffnen.</div></div>`}
    `;
    if (can.controlling) drawDashTrend(data.trend_30d);
}

function dashOverviewQs() {
    const qs = new URLSearchParams({ period: _dashPeriod.key, slim: '1' });
    return qs.toString();
}

// ============================================================
// HTML BUILDERS
// ============================================================

function dashHeroHtml(d) {
    const can = d.can || {};
    const g = d.user.greeting || 'Willkommen';
    const name = d.user.name ? ', ' + dashEscape(d.user.name) : '';

    // The operational summary line is only meaningful when the user may see
    // Einsätze (today_einsaetze is only present then).
    let opsLine = '';
    const te = d.kpis && d.kpis.today_einsaetze;
    if (te) {
        const todayCount = te.value;
        const diff = todayCount - te.prev;
        const diffTxt = diff === 0
            ? 'gleich viele wie gestern'
            : (diff > 0 ? '+' + diff + ' mehr als gestern' : Math.abs(diff) + ' weniger als gestern');
        opsLine = todayCount === 0
            ? 'Keine Einsätze für heute eingeplant.'
            : `${todayCount} Einsätze heute — ${diffTxt}.`;
    }

    // Quick-action buttons: only the ones the user is allowed to open.
    const quick = [];
    if (can.dispo)   quick.push(`<a class="dash-quick-btn primary" onclick="loadPage('tasks')">${dashIcon('checklist')} Planung</a>`);
    if (can.eingang) quick.push(`<a class="dash-quick-btn" onclick="loadPage('incoming_orders')">${dashIcon('inbox')} Bestelleingang</a>`);
    if (can.controlling) quick.push(`<a class="dash-quick-btn" onclick="loadPage('reports')">${dashIcon('monitoring')} Controlling</a>`);

    return `
      <div class="dash-hero">
        <div class="dash-hero-text">
          <h2>${dashEscape(g)}${name}</h2>
          <div class="dash-hero-sub">
            <span class="pill">${dashIcon('date_range')} ${dashEscape(d.date.weekday)}, ${dashFormatDate(d.date.today)}</span>
            <span class="pill">${dashIcon('tag')} KW ${d.date.kw}</span>
            ${opsLine ? `<span>${opsLine}</span>` : ''}
          </div>
        </div>
        ${quick.length ? `<div class="dash-hero-quick">${quick.join('')}</div>` : ''}
      </div>
    `;
}

function dashKpiHtml(k, periodLabel = 'Dieser Monat', prevLabel = 'Vormonat') {
    if (!k || Object.keys(k).length === 0) return '';
    const p = { kpi: periodLabel, prev: prevLabel };

    const card = (cls, icon, label, value, sub, delta, extra = '') => `
      <div class="dash-kpi ${cls}">
        <div class="dash-kpi-top">
          <span>${label}</span>
          <span class="kpi-ic">${dashIcon(icon)}</span>
        </div>
        <div class="dash-kpi-value">${value}</div>
        ${extra}
        <div class="dash-kpi-foot">
          <span>${sub}</span>
          ${dashDeltaBadge(delta)}
        </div>
      </div>`;

    // "Hochrechnung" = Controlling cockpit projection (freigegeben + geleistet
    // + geplant). Only shown when it differs from the current figure.
    const erwartetLine = (kpi) => {
        if (!kpi || kpi.erwartet === undefined || Math.abs(kpi.erwartet - kpi.value) < 0.005) return '';
        const offen = kpi.offen ? ` · ${kpi.offen} nicht freigegeben` : '';
        return `<div class="dash-kpi-expected" title="Hochrechnung wie im Controlling: freigegeben + geleistet + geplant">${dashIcon('schedule')} Hochrechnung ${formatCurrency(kpi.erwartet)}${offen}</div>`;
    };
    // Financial KPIs compare like-for-like (e.g. "Vormonat bis 21.09.").
    const prevOf = (kpi) => (kpi && kpi.prev_label) || p.prev;

    const cards = [];
    if (k.umsatz_monat) cards.push(card('k-rev', 'euro', `Umsatz (${p.kpi})`,
        formatCurrency(k.umsatz_monat.value), `${prevOf(k.umsatz_monat)} ${formatCurrency(k.umsatz_monat.prev)}`, k.umsatz_monat.delta,
        erwartetLine(k.umsatz_monat)));
    if (k.gewinn_monat) {
        const g = k.gewinn_monat;
        const marge = (g.marge !== null && g.marge !== undefined)
            ? `<div class="dash-kpi-expected" title="Marge nach Lohnnebenkosten (wie im Controlling)">${dashIcon('percent')} Marge ${Number(g.marge).toLocaleString('de-DE', { maximumFractionDigits: 1 })} %</div>` : '';
        cards.push(card('k-prof', 'trending_up', `${g.label || 'Gewinn'} (${p.kpi})`,
            formatCurrency(g.value), `${prevOf(g)} ${formatCurrency(g.prev)}`, g.delta,
            marge + erwartetLine(g)));
    }
    if (k.einsaetze_monat) cards.push(card('k-cnt', 'task_alt', `Einsätze (${p.kpi})`,
        k.einsaetze_monat.value, `${p.prev} ${k.einsaetze_monat.prev}`, k.einsaetze_monat.delta));
    if (k.stunden_monat) cards.push(card('k-hrs', 'timer', `Stunden (${p.kpi})`,
        (k.stunden_monat.value || 0).toLocaleString('de-DE') + ' h',
        `${p.prev} ${(k.stunden_monat.prev || 0).toLocaleString('de-DE')} h`, k.stunden_monat.delta));

    if (cards.length === 0) return '';
    return `<div class="dash-kpi-row">${cards.join('')}</div>`;
}

async function dashOpenActiveWorkersPanel(trigger) {
    if (trigger?.dataset.loading === '1') return;
    if (trigger) trigger.dataset.loading = '1';
    try {
        if (typeof openActiveWorkersPanel !== 'function') await ensurePageScripts('tasks');
        if (typeof openActiveWorkersPanel !== 'function') throw new Error('Live-Ansicht ist nicht verfügbar');
        await openActiveWorkersPanel();
    } catch (error) {
        showToast(error.message || 'Live-Ansicht konnte nicht geladen werden', 'error');
    } finally {
        if (trigger) delete trigger.dataset.loading;
    }
}

// ── Navigation: open the EXACT place (filter / date / record), not just a page ──
function dashZiel(z) { return dashEscape(JSON.stringify(z || {})); }
// Wait until the target page has rendered (loadPage does not await the renderer).
function dashWhen(test, fn, ms = 10000) {
    const t0 = Date.now();
    const tick = () => {
        let ok = false; try { ok = !!test(); } catch (e) {}
        if (ok) { try { fn(); } catch (e) { console.warn('dashGo', e); } return; }
        if (Date.now() - t0 < ms) setTimeout(tick, 150);
    };
    tick();
}
async function dashGo(z) {
    if (typeof z === 'string') { try { z = JSON.parse(z); } catch (e) { z = { page: z }; } }
    const page = z && z.page;
    if (!page) return;
    try { if (typeof ensurePageScripts === 'function') await ensurePageScripts(page); } catch (e) {}

    if (page === 'tasks') {
        if (typeof plannerState !== 'undefined') {
            const d = z.datum ? new Date(z.datum + 'T12:00:00') : new Date();
            plannerState.viewMode = z.ansicht === 'month' ? 'month' : 'week';
            plannerState.month = d.getMonth() + 1;
            plannerState.year = d.getFullYear();
            if (typeof plannerGetWeekStart === 'function') plannerState.weekStart = plannerToIsoDate(plannerGetWeekStart(d));
        }
        // A concrete shift / the Börse was asked for: skip the "Bereich auswählen" gate
        // (it would cover the detail). '' = all Bereiche within the user's own scope;
        // an earlier manual pick in this session is kept.
        if ((z.einsatz || z.boerse) && typeof plannerRecallBereich === 'function' && plannerRecallBereich() === null) plannerRememberBereich('');
        if (z.einsatz) { try { sessionStorage.setItem('hms_focus_tasks', 'einsatz=' + encodeURIComponent(z.einsatz)); } catch (e) {} }
        loadPage('tasks');
        if (z.boerse) dashWhen(() => typeof openBoerseDialog === 'function' && document.querySelector('#plannerGrid') && !document.querySelector('#plannerGrid .planner-loading'), () => openBoerseDialog());
        return;
    }
    if (page === 'monatsabschluss') {
        window._maPreset = { mode: z.mode === 'kunde' ? 'kunde' : 'ma', monat: z.monat, jahr: z.jahr };
        loadPage('monatsabschluss');
        return;
    }
    if (page === 'absences' && z.antraege) { try { sessionStorage.setItem('hms_focus_absences', 'view=requests'); } catch (e) {} }
    loadPage(page);
    if (page === 'incoming_orders' && z.status) dashWhen(() => typeof beSetFilter === 'function' && document.getElementById('beFilterPills'), () => beSetFilter(z.status));
    if (page === 'workers' && z.ablauf) dashWhen(() => typeof wkOpenExpiringDocs === 'function' && document.getElementById('wkGrid'), () => wkOpenExpiringDocs());
    if (page === 'interessenten' && z.overdue) dashWhen(() => typeof setIntPhaseFilter === 'function' && document.querySelector('.int-page .bento-tile'), () => setIntPhaseFilter('__overdue'));
}
window.dashGo = dashGo;

// ── Jetzt / Heute ────────────────────────────────────────────
let _dashHeute = null; // last cockpit.heute — source for the tile dialogs

// Tile → list of exactly those shifts; each row opens the Einsatz detail.
const DASH_TODAY_FILTERS = {
    alle:      { title: 'Einsätze heute',                     test: () => true },
    besetzt:   { title: 'Heute voll besetzt',                 test: x => x.status === 'besetzt' || x.status === 'nu' },
    teilweise: { title: 'Heute nur teilweise besetzt',        test: x => x.status === 'teilweise' },
    offen:     { title: 'Heute ohne Mitarbeiter',             test: x => x.status === 'offen' },
};
const DASH_STATUS_LABEL = { besetzt: 'besetzt', nu: 'Nachunternehmer', teilweise: 'teilweise', offen: 'offen' };

function dashTodayStr() {
    const n = new Date();
    return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-${String(n.getDate()).padStart(2, '0')}`;
}
let _dashWoche = []; // last cockpit.woche — source for the day dialogs

// Einsatz detail opens in the planner week of its date (the detail modal needs the week loaded).
function dashOpenEinsatz(id, datum) {
    if (typeof closeModal === 'function') closeModal();
    dashGo({ page: 'tasks', datum: datum || dashTodayStr(), einsatz: id });
}
function dashOpenPlanung(datum) {
    if (typeof closeModal === 'function') closeModal();
    dashGo({ page: 'tasks', datum: datum || dashTodayStr() });
}
function dashShiftRows(rows, datum) {
    return `<ul class="dash-list dash-modal-list">${rows.map(x => `
        <li onclick="dashOpenEinsatz('${dashEscape(x.einsatz_id)}', '${dashEscape(datum)}')" title="Einsatz öffnen">
          <span class="t">${dashEscape(x.start || '––')}<small>${dashEscape(x.ende || '')}</small></span>
          <span class="b"><b>${dashEscape(x.kunde || '–')}</b> · ${dashEscape(x.objekt || '–')}
            <small>${x.status === 'nu' ? 'durch Nachunternehmer' : `${x.ist} von ${x.soll} Mitarbeitern eingeplant`}</small></span>
          <span class="dash-chip ${dashEscape(x.status)}">${dashEscape(DASH_STATUS_LABEL[x.status] || x.status)}</span>
        </li>`).join('')}</ul>`;
}
function dashShiftModal(title, body, datum) {
    openModal(title, body,
        `<md-outlined-button  onclick="closeModal()">Schließen</md-outlined-button>
         <md-filled-button  onclick="dashOpenPlanung('${dashEscape(datum)}')">In der Planung öffnen</md-filled-button>`);
}
function dashShowToday(key) {
    const f = DASH_TODAY_FILTERS[key] || DASH_TODAY_FILTERS.alle;
    const rows = ((_dashHeute && _dashHeute.liste) || []).filter(f.test);
    const today = dashTodayStr();
    dashShiftModal(`${f.title} (${rows.length})`,
        rows.length ? dashShiftRows(rows, today) : `<div class="dash-alert-empty">${dashIcon('check_circle')}Keine Einsätze in dieser Kategorie.</div>`, today);
}
// Week row → that day's shifts: not fully staffed first, then the staffed ones.
function dashShowDay(datum) {
    const d = (_dashWoche || []).find(x => x.datum === datum);
    const rows = (d && d.liste) || [];
    const open = rows.filter(x => x.status === 'offen' || x.status === 'teilweise');
    const done = rows.filter(x => !(x.status === 'offen' || x.status === 'teilweise'));
    const dt = new Date(datum + 'T12:00:00');
    const label = dt.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit' });
    const sec = (t, list) => list.length ? `<div class="dash-modal-sec">${t} (${list.length})</div>${dashShiftRows(list, datum)}` : '';
    const body = rows.length
        ? (open.length ? '' : `<div class="dash-alert-empty">${dashIcon('check_circle')}Alle Einsätze an diesem Tag sind besetzt.</div>`)
          + sec('Nicht voll besetzt', open) + sec('Besetzt', done)
        : `<div class="dash-alert-empty">${dashIcon('event_busy')}Für diesen Tag sind keine Einsätze geplant.</div>`;
    dashShiftModal(`${label} — ${rows.length} Einsätze${open.length ? ', ' + open.length + ' offen' : ''}`, body, datum);
}
function dashShowLate() {
    const rows = (_dashHeute && _dashHeute.verspaetet_liste) || [];
    const body = rows.length ? `<ul class="dash-list dash-modal-list">${rows.map(x => `
        <li onclick="dashOpenEinsatz('${dashEscape(x.einsatz_id)}')" title="Einsatz öffnen">
          <span class="t">${dashEscape(x.start)}</span>
          <span class="b"><b>${dashEscape(x.name)}</b> — noch nicht eingestempelt<small>${dashEscape(x.kunde || '–')} · ${dashEscape(x.objekt || '–')}</small></span>
        </li>`).join('')}</ul>`
        : `<div class="dash-alert-empty">${dashIcon('check_circle')}Niemand ist verspätet — alle eingeplanten Mitarbeiter haben pünktlich eingestempelt.</div>`;
    openModal(`Verspätet — nicht eingestempelt (${(_dashHeute && _dashHeute.verspaetet) || 0})`, body,
        `<md-outlined-button  onclick="closeModal()">Schließen</md-outlined-button>
         <md-filled-button  onclick="closeModal();dashOpenActiveWorkersPanel()">Live-Ansicht öffnen</md-filled-button>`);
}

function dashNowHtml(h) {
    _dashHeute = h;
    const n0 = new Date(); // local date (not UTC — wrong just after midnight)
    const today = { page: 'tasks', datum: `${n0.getFullYear()}-${String(n0.getMonth() + 1).padStart(2, '0')}-${String(n0.getDate()).padStart(2, '0')}` };
    const goLive = `onclick="dashOpenActiveWorkersPanel(this)"`;
    const tile = (cls, icon, n, label, attrs, title = '') => `
        <div class="dash-now-tile ${cls}${n > 0 ? ' has' : ''}" ${attrs} ${title ? `title="${dashEscape(title)}"` : ''}>
          <span class="ic">${dashIcon(icon)}</span>
          <div><div class="n">${(n || 0).toLocaleString('de-DE')}</div><div class="l">${label}</div></div>
        </div>`;
    const late = (h.verspaetet_liste || []).slice(0, 8).map(x => `
        <li onclick="dashGo(this.dataset.ziel)" data-ziel="${dashZiel({ ...today, einsatz: x.einsatz_id })}" title="Einsatz öffnen"><span class="t">${dashEscape(x.start)}</span>
          <span class="b"><b>${dashEscape(x.name)}</b> — noch nicht eingestempelt<small>${dashEscape(x.kunde || '–')} · ${dashEscape(x.objekt || '–')}</small></span></li>`).join('');
    const probs = (h.probleme || []).map(x => `
        <li onclick="dashGo(this.dataset.ziel)" data-ziel="${dashZiel({ ...today, einsatz: x.einsatz_id })}" title="Einsatz öffnen"><span class="t">${dashEscape(x.start || '––')}</span>
          <span class="b"><b>${dashEscape(x.kunde || '–')}</b> · ${dashEscape(x.objekt || '–')}<small>${x.status === 'offen' ? 'Kein Mitarbeiter eingeplant' : `${x.ist} von ${x.soll} besetzt`}</small></span>
          <span class="dash-chip ${x.status}">${x.status === 'offen' ? 'offen' : 'teilweise'}</span></li>`).join('');
    return `
      <h3 class="dash-sec-title">${dashIcon('bolt')} Jetzt — heute</h3>
      <div class="dash-now">
        ${tile('t-total', 'event', h.gesamt, 'Einsätze heute', `onclick="dashShowToday('alle')"`, 'Alle Einsätze von heute anzeigen')}
        ${tile('t-ok', 'how_to_reg', h.besetzt, 'voll besetzt', `onclick="dashShowToday('besetzt')"`, 'Voll besetzte Einsätze anzeigen')}
        ${tile('t-part', 'group_remove', h.teilweise, 'teilweise besetzt', `onclick="dashShowToday('teilweise')"`, 'Teilweise besetzte Einsätze anzeigen')}
        ${tile('t-open', 'person_off', h.offen, 'ohne Mitarbeiter', `onclick="dashShowToday('offen')"`, 'Einsätze ohne Mitarbeiter anzeigen')}
        ${tile('t-live', 'directions_run', h.im_dienst, 'jetzt im Dienst', goLive, 'Live-Ansicht: wer ist eingestempelt')}
        ${tile('t-late', 'alarm', h.verspaetet, 'verspätet (nicht eingestempelt)', `onclick="dashShowLate()"`, 'Verspätete Mitarbeiter anzeigen')}
      </div>
      ${(late || probs) ? `<div class="dash-now-lists">
        ${late ? `<div class="dash-card"><div class="dash-card-head"><h3>${dashIcon('alarm')} Verspätet</h3></div><ul class="dash-list">${late}</ul></div>` : ''}
        ${probs ? `<div class="dash-card"><div class="dash-card-head"><h3>${dashIcon('person_off')} Heute nicht voll besetzt</h3></div><ul class="dash-list">${probs}</ul></div>` : ''}
      </div>` : ''}`;
}

// ── Zu erledigen ─────────────────────────────────────────────
function dashInboxCountText(items) {
    const n = (items || []).length;
    return n ? `${n} ${n === 1 ? 'Punkt' : 'Punkte'}` : '';
}
function dashInboxHtml(items) {
    if (!items || !items.length) {
        return `<div class="dash-alert-empty">${dashIcon('check_circle')}Alles erledigt — nichts wartet auf Sie.</div>`;
    }
    return items.map(a => `
        <div class="dash-inbox-item ${a.level}" onclick="dashGo(this.dataset.ziel)" data-ziel="${dashZiel(a.ziel || { page: a.page })}">
          <span class="icn">${dashIcon(a.icon || 'info')}</span>
          <span class="cnt">${Number(a.count).toLocaleString('de-DE')}</span>
          <span class="txt">${dashEscape(a.title)}${a.hint ? `<small>${dashEscape(a.hint)}</small>` : ''}</span>
          <span class="cta">${dashEscape(a.cta)} →</span>
        </div>`).join('');
}

// ── Nächste 7 Tage ───────────────────────────────────────────
function dashWeekHtml(days) {
    _dashWoche = days || [];
    const wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    return `<div class="dash-week">${(days || []).map((d, i) => {
        const ok = (d.besetzt || 0) + (d.nu || 0);
        const pct = d.gesamt ? Math.round(ok / d.gesamt * 100) : null;
        const w = v => d.gesamt ? (v / d.gesamt * 100).toFixed(1) : 0;
        const dt = new Date(d.datum + 'T12:00:00');
        const cls = pct === null ? 'none' : pct === 100 ? 'full' : pct >= 80 ? 'good' : 'low';
        return `
          <div class="dash-week-row" onclick="dashShowDay('${dashEscape(d.datum)}')" title="${d.gesamt} Einsätze · ${ok} besetzt · ${d.teilweise} teilweise · ${d.offen} offen">
            <span class="d">${i === 0 ? 'Heute' : wd[dt.getDay()]}<small>${dashFormatShort(d.datum)}</small></span>
            <span class="bar">${d.gesamt ? `<i class="ok" style="width:${w(ok)}%"></i><i class="part" style="width:${w(d.teilweise)}%"></i><i class="open" style="width:${w(d.offen)}%"></i>` : ''}</span>
            <span class="p ${cls}">${pct === null ? '—' : pct + ' %'}</span>
            <span class="c">${d.gesamt ? `${d.offen + d.teilweise ? `<b>${d.offen + d.teilweise}</b> offen` : 'alles besetzt'}` : 'keine'}</span>
          </div>`;
    }).join('')}</div>`;
}

function dashDeltaBadge(delta) {
    if (delta === null || delta === undefined) {
        return `<span class="dash-delta flat">${dashIcon('remove')}–</span>`;
    }
    const d = Number(delta);
    if (!isFinite(d) || d === 0) {
        return `<span class="dash-delta flat">${dashIcon('equal')}0 %</span>`;
    }
    const up = d > 0;
    return `<span class="dash-delta ${up ? 'up' : 'down'}">${dashIcon(up ? 'arrow_upward' : 'arrow_downward')}${Math.abs(d).toLocaleString('de-DE', { maximumFractionDigits: 1 })} %</span>`;
}

// ============================================================
// CHART
// ============================================================
function drawDashTrend(points) {
    const canvas = document.getElementById('dashTrend');
    if (!canvas) return;
    if (typeof Chart === 'undefined') {
        canvas.parentElement.innerHTML = `<div class="dash-empty">${dashIcon('monitoring')}<div>Chart-Bibliothek nicht geladen.</div></div>`;
        return;
    }
    if (_dashTrendChart) _dashTrendChart.destroy();

    const labels = points.map(p => dashFormatShort(p.bucket));
    const values = points.map(p => p.umsatz);

    // MD3 primary (see md3-tokens.css); fall back to the static value.
    const primary = (getComputedStyle(document.documentElement)
        .getPropertyValue('--md-sys-color-primary') || '').trim() || '#0b57d0';

    const ctx = canvas.getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, 180);
    grad.addColorStop(0, 'rgba(11,87,208,0.30)');
    grad.addColorStop(1, 'rgba(11,87,208,0)');

    _dashTrendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Umsatz',
                data: values,
                borderColor: primary,
                backgroundColor: grad,
                fill: true,
                tension: 0.32,
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: primary,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => 'Umsatz: ' + formatCurrency(ctx.parsed.y),
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 }, maxRotation: 0, autoSkipPadding: 18 },
                },
                y: {
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: {
                        font: { size: 10 },
                        callback: v => new Intl.NumberFormat('de-DE', { notation: 'compact', maximumFractionDigits: 1 }).format(v) + ' €',
                    },
                },
            },
        },
    });
}

// ============================================================
// UTILITIES
// ============================================================

function dashEscape(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function dashFormatDate(d) {
    if (!d) return '–';
    const s = String(d).slice(0, 10);
    const parts = s.split('-');
    if (parts.length !== 3) return s;
    return `${parts[2]}.${parts[1]}.${parts[0]}`;
}

function dashFormatShort(d) {
    if (!d) return '';
    const parts = String(d).slice(0, 10).split('-');
    if (parts.length !== 3) return d;
    return `${parts[2]}.${parts[1]}.`;
}

function loadChartJsForDashboard() {
    return new Promise((resolve, reject) => {
        if (typeof Chart !== 'undefined') return resolve();
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
        s.onload = () => resolve();
        s.onerror = () => reject();
        document.head.appendChild(s);
    });
}

// ============================================================
// LIVE COUNT REFRESH
// ============================================================
// The Übersicht is a one-time snapshot — "Jetzt im Einsatz" & co. silently
// go stale while the SPA stays open (e.g. a phone tab resumed hours later
// still showed 0 while the live panel already listed workers). We refresh
// "Jetzt", "Zu erledigen", the 7-day strip and the KPIs IN PLACE (no
// skeleton, no chart redraw, scroll kept): every 60s while the dashboard is the visible page, whenever the
// tab comes back to the foreground, and when the live panel closes.

let _dashLiveFetching = false;
let _dashLiveBound    = false;

async function dashRefreshLive() {
    if (currentPage !== 'dashboard' || document.hidden || _dashLiveFetching) return;
    if (!document.querySelector('.dash-hero')) return; // dashboard not rendered
    _dashLiveFetching = true;
    try {
        const [data, c] = await Promise.all([
            apiGet(API_BASE + '/dashboard/overview?' + dashOverviewQs()),
            apiGet(API_BASE + '/dashboard/cockpit'),
        ]);
        if (currentPage !== 'dashboard') return;
        if (c && !c.error) {
            const nowEl = document.getElementById('dashNow');
            if (nowEl && c.heute) nowEl.innerHTML = dashNowHtml(c.heute);
            const inEl = document.getElementById('dashInbox');
            if (inEl) inEl.innerHTML = dashInboxHtml(c.inbox);
            const cntEl = document.getElementById('dashInboxCount');
            if (cntEl) cntEl.textContent = dashInboxCountText(c.inbox);
            const wkEl = document.getElementById('dashWeek');
            if (wkEl && c.woche) wkEl.innerHTML = dashWeekHtml(c.woche);
        }
        if (data && !data.error && data.can) {
            const kpiHtml = dashKpiHtml(data.kpis, data.period_label, data.prev_label);
            const kpiEl = document.querySelector('.dash-kpi-row');
            if (kpiEl && kpiHtml) kpiEl.outerHTML = kpiHtml;
        }
    } finally {
        _dashLiveFetching = false;
    }
}

// Bound once at script load — the guards inside dashRefreshLive keep it
// inert on every other page and while the tab is hidden.
(function initDashLiveRefresh() {
    if (_dashLiveBound) return;
    _dashLiveBound = true;
    setInterval(dashRefreshLive, 60000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') dashRefreshLive();
    });
})();

/* ---- Realtime hook: any tenant change refreshes the dashboard live.
   dashRefreshLive self-guards (only runs while the dashboard is up). ---- */
if (window.HMSRealtime) {
    HMSRealtime.on('*', () => {
        try { if (typeof dashRefreshLive === 'function') dashRefreshLive(); } catch (e) {}
    });
}
