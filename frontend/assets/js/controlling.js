/* ============================================
   Clood-X — Auswertung & Controlling · Page entry
   Backend: /api/controlling/cockpit (+ /details, /drilldown, /mitarbeiter)

   Controlling-Cockpit nach deutschem Marktstandard:
   stufenweise Deckungsbeitragsrechnung, Objektcontrolling (Ampel, Soll/Ist,
   ABC), Preis-Check je Artikel, Hochrechnung aus dem Dienstplan.

   Module layout (all plain <script> globals, loaded in this order):
     controlling/controlling-core.js       state, constants, helpers, Chart.js loader
     controlling/controlling-charts.js     chart helpers (also used by the drill-down)
     controlling/controlling-widgets.js    details table, shared badges
     controlling/controlling-api.js        details loader + Excel export
     controlling/controlling-worker.js     per-worker deep-dive card
     controlling/controlling-drilldown.js  side panel for one entity
     controlling/controlling-snapshots.js  (legacy, not mounted)
     controlling/controlling-cockpit.js    cockpit loader, renderers, settings dialog
     controlling.js (this file)            layout HTML + event bindings
   ============================================ */

async function renderControlling() {
    const content = document.getElementById('content');
    if (!['month', 'last_month', 'quarter', 'ytd', 'year', 'custom'].includes(CTRL.state.period)) {
        CTRL.state.period = 'month';
    }

    content.innerHTML = `
      ${ctrlTopbarHtml()}
      ${ctrlFiltersHtml('', '')}
      <div id="ccBanners"></div>
      <div class="cc-grid-top">
        <div class="ctrl-card cc-result-card">
          <div class="ctrl-card-header">
            <h3 class="ctrl-card-title">${ctrlIcon('account_balance')} Ergebnisrechnung</h3>
            <small class="cc-muted" id="ccResultSub"></small>
          </div>
          <div id="ccResult">${ctrlBdSkeleton()}</div>
        </div>
        <div class="cc-side">
          <div id="ccKpis" class="cc-kpis">${ctrlKpiSkeleton()}</div>
          <div class="ctrl-card" id="ccForecastCard" style="display:none">
            <div class="ctrl-card-header">
              <h3 class="ctrl-card-title">${ctrlIcon('trending_up')} Hochrechnung bis Periodenende</h3>
            </div>
            <div id="ccForecast"></div>
          </div>
        </div>
      </div>
      ${ctrlWorkerCardHtml()}
      <div class="ctrl-card cc-block">
        <div class="ctrl-card-header">
          <h3 class="ctrl-card-title">${ctrlIcon('location_on')} Objektcontrolling</h3>
          <div class="ctrl-card-toolbar" id="ccObjFilter">
            <button class="chip active" data-a="all">Alle</button>
            <button class="chip" data-a="rot">Rot</button>
            <button class="chip" data-a="gelb">Gelb</button>
            <button class="chip" data-a="gruen">Grün</button>
          </div>
        </div>
        <small class="cc-muted cc-pad">Schlechteste Marge zuerst · Ampel auf DB II · Soll/Ist-Stunden bis heute · ABC nach Deckungsbeitrag</small>
        <div id="ccObjekte" class="cc-scroll">${ctrlBdSkeleton()}</div>
      </div>
      <div class="ctrl-card cc-block">
        <div class="ctrl-card-header">
          <h3 class="ctrl-card-title">${ctrlIcon('price_check')} Preis-Check je Artikel</h3>
        </div>
        <small class="cc-muted cc-pad">Erlös je Stunde gegen Vollkosten je Stunde (Personal inkl. Lohnnebenkosten + NU) — niedrigster Deckungsbeitrag zuerst</small>
        <div id="ccArtikel" class="cc-scroll">${ctrlBdSkeleton()}</div>
      </div>
      <div class="ctrl-card cc-block">
        <div class="ctrl-card-header">
          <h3 class="ctrl-card-title">${ctrlIcon('table_chart')} Auswertung nach</h3>
          <div class="ctrl-card-toolbar" id="ccTabs">
            <button class="chip active" data-t="kunden">Kunden</button>
            <button class="chip" data-t="mitarbeiter">Mitarbeiter</button>
            <button class="chip" data-t="nu">Nachunternehmer</button>
            <button class="chip" data-t="bereiche">Bereiche</button>
          </div>
        </div>
        <div id="ccTabBody" class="cc-scroll">${ctrlBdSkeleton()}</div>
      </div>
      <div class="ctrl-card cc-block">
        <div class="ctrl-card-header">
          <h3 class="ctrl-card-title">${ctrlIcon('bar_chart')} Entwicklung der letzten 12 Monate</h3>
        </div>
        <div class="ctrl-chart-wrap" style="min-height:280px"><canvas id="ccTrendChart"></canvas></div>
      </div>
      ${ctrlDetailsHtml()}
    `;

    bindCtrlTopbar();
    bindCtrlFilters();
    bindCtrlDetailsPager();
    bindCcSections();
    bindCtrlDrill();
    CTRL.state.page = 1;

    Promise.all([getCustomerOptions(), getSubcontractorOptions(), getObjectOptions(), ccBereichOptions()])
        .then(([custOpts, nuOpts, objOpts, berOpts]) => {
            const set = (id, html) => { const el = document.getElementById(id); if (el) el.innerHTML = html; };
            set('ctrlKunde', ctrlToMdOptions(custOpts, 'Alle Kunden', CTRL.state.filters.kunden_id));
            set('ctrlNu', ctrlToMdOptions(nuOpts, 'Alle NU', CTRL.state.filters.nu_id));
            set('ctrlObjekt', ctrlToMdOptions(objOpts, 'Alle Objekte', CTRL.state.filters.obj_id));
            set('ctrlBereich', ctrlToMdOptions(berOpts, 'Alle Bereiche', CTRL.state.filters.bereich));
        })
        .catch(() => { /* dropdowns are optional */ });
    ctrlLoadWorkerOptions().catch(() => {});

    ctrlLoadAll();
}

// ============================================================
// HTML BUILDERS
// ============================================================
function ctrlTopbarHtml() {
    const p = CTRL.state.period;
    const btn = (k, label) => `<button data-p="${k}" class="${p === k ? 'active' : ''}">${label}</button>`;
    return `
      <div class="ctrl-topbar">
        <div class="ctrl-title">
          <h2>${ctrlIcon('monitoring')} Controlling</h2>
          <small id="ctrlPeriodLabel">Zeitraum wird geladen…</small>
        </div>
        <div class="ctrl-period" id="ctrlPeriodSwitcher">
          ${btn('month', 'Monat')}${btn('last_month', 'Vormonat')}${btn('quarter', 'Quartal')}${btn('ytd', 'Lfd. Jahr')}${btn('year', 'Jahr')}
          <button data-p="custom" class="${p === 'custom' ? 'active' : ''}" title="Eigener Zeitraum">${ctrlIcon('calendar_month', 'font-size:14px')}</button>
        </div>
        <div class="ctrl-period cc-basis" id="ccBasis" title="Welche Einsätze zählen?">
          <button data-b="operativ" class="${CTRLC.basis === 'operativ' ? 'active' : ''}" title="Alle geleisteten Stunden — freigegeben oder noch nicht">Operativ</button>
          <button data-b="abrechnung" class="${CTRLC.basis === 'abrechnung' ? 'active' : ''}" title="Nur Stunden, die in der Zeiterfassung für den Kunden freigegeben sind (= Leistungsnachweis)">Abrechnung</button>
        </div>
        <div class="ctrl-topbar-actions">
          <md-outlined-button id="ctrlBtnCompare" title="Vergleichsmodus umschalten">
            <md-icon slot="icon">swap_horiz</md-icon><span id="ctrlCompareLbl">${CTRL.state.compare === 'year' ? 'vs. Vorjahr' : 'vs. Vorperiode'}</span>
          </md-outlined-button>
          <md-icon-button id="ctrlBtnRefresh" title="Aktualisieren"><md-icon>refresh</md-icon></md-icon-button>
          <md-icon-button id="ccBtnSettings" title="Kostensätze & Fixkosten"><md-icon>tune</md-icon></md-icon-button>
          <md-filled-button id="ctrlBtnExport" title="Einsätze als Excel exportieren"><md-icon slot="icon">download</md-icon>Export</md-filled-button>
        </div>
      </div>
    `;
}

function ctrlFiltersHtml(customerOpts, nuOpts) {
    return `
      <div class="ctrl-filters">
        <div class="form-group">
          <label>${ctrlIcon('person', 'font-size:12px;vertical-align:-2px')} Kunde</label>
          <md-outlined-select class="ctrl-md-select" id="ctrlKunde">${ctrlToMdOptions(customerOpts, 'Alle Kunden')}</md-outlined-select>
        </div>
        <div class="form-group">
          <label>${ctrlIcon('location_on', 'font-size:12px;vertical-align:-2px')} Objekt</label>
          <md-outlined-select class="ctrl-md-select" id="ctrlObjekt">${ctrlToMdOptions('', 'Alle Objekte')}</md-outlined-select>
        </div>
        <div class="form-group">
          <label>${ctrlIcon('engineering', 'font-size:12px;vertical-align:-2px')} Nachunternehmer</label>
          <md-outlined-select class="ctrl-md-select" id="ctrlNu">${ctrlToMdOptions(nuOpts, 'Alle NU')}</md-outlined-select>
        </div>
        <div class="form-group">
          <label>${ctrlIcon('badge', 'font-size:12px;vertical-align:-2px')} Mitarbeiter</label>
          <md-outlined-select class="ctrl-md-select" id="ctrlMitarbeiter">${ctrlToMdOptions('', 'Alle Mitarbeiter')}</md-outlined-select>
        </div>
        <div class="form-group">
          <label>${ctrlIcon('layers', 'font-size:12px;vertical-align:-2px')} Bereich</label>
          <md-outlined-select class="ctrl-md-select" id="ctrlBereich">${ctrlToMdOptions('', 'Alle Bereiche')}</md-outlined-select>
        </div>
        <div class="form-group ctrl-custom-range" style="${CTRL.state.period === 'custom' ? '' : 'display:none'}">
          <label>${ctrlIcon('date_range', 'font-size:12px;vertical-align:-2px')} Zeitraum</label>
          <div class="ctrl-date-range">
            <md-outlined-text-field type="date" class="form-control" id="ctrlFrom" value="${CTRL.state.from || ''}"></md-outlined-text-field>
            <span class="sep">→</span>
            <md-outlined-text-field type="date" class="form-control" id="ctrlTo" value="${CTRL.state.to || ''}"></md-outlined-text-field>
          </div>
        </div>
        <md-outlined-button id="ctrlBtnClearFilters" class="ctrl-clear-btn" title="Alle Filter zurücksetzen">
          <md-icon slot="icon">filter_alt_off</md-icon>Zurücksetzen
        </md-outlined-button>
      </div>
    `;
}

function ctrlDetailsHtml() {
    return `
      <div class="ctrl-details">
        <div class="ctrl-details-head">
          <h3 class="ctrl-card-title">${ctrlIcon('list')} Einzelne Einsätze</h3>
          <div style="display:flex;gap:8px;align-items:center">
            <label style="font-size:0.78rem;color:var(--gray-600)">
              <span style="margin-right:4px">Pro Seite:</span>
              <md-outlined-select id="ctrlPerPage" class="ctrl-md-select-sm">
                <md-select-option value="25" selected><div slot="headline">25</div></md-select-option>
                <md-select-option value="50"><div slot="headline">50</div></md-select-option>
                <md-select-option value="100"><div slot="headline">100</div></md-select-option>
                <md-select-option value="250"><div slot="headline">250</div></md-select-option>
              </md-outlined-select>
            </label>
          </div>
        </div>
        <small class="cc-muted" style="display:block;margin:-4px 0 8px">Beträge je Einsatz nur für bestätigte Einsätze · „MA-Kosten“ = operativer Lohn (DB I)</small>
        <div id="ctrlDetails" style="overflow-x:auto">${ctrlBdSkeleton()}</div>
        <div id="ctrlPager" class="ctrl-paginator"></div>
      </div>
    `;
}

/** Hauptbereiche from the managed catalog (scope-filtered server-side). */
async function ccBereichOptions() {
    const data = await apiGet(API_BASE + '/bereich-catalog');
    const mains = (data && Array.isArray(data.mains)) ? data.mains : [];
    return mains.filter(m => m.aktiv !== false && m.aktiv !== 0)
        .map(m => `<option value="${escapeHtml(m.name)}">${escapeHtml(m.name)}</option>`).join('');
}

// ============================================================
// BINDINGS
// ============================================================
function bindCtrlTopbar() {
    document.querySelectorAll('#ctrlPeriodSwitcher button').forEach(b => {
        b.addEventListener('click', () => {
            const p = b.dataset.p;
            document.querySelectorAll('#ctrlPeriodSwitcher button').forEach(x => x.classList.toggle('active', x === b));
            CTRL.state.period = p;
            const customRange = document.querySelector('.ctrl-custom-range');
            if (p === 'custom') {
                customRange.style.display = '';
                if (!CTRL.state.from) {
                    const now = new Date();
                    CTRL.state.from = isoDate(new Date(now.getFullYear(), now.getMonth(), 1));
                    CTRL.state.to   = isoDate(new Date(now.getFullYear(), now.getMonth() + 1, 0));
                    document.getElementById('ctrlFrom').value = CTRL.state.from;
                    document.getElementById('ctrlTo').value   = CTRL.state.to;
                }
            } else {
                customRange.style.display = 'none';
                CTRL.state.from = null;
                CTRL.state.to = null;
            }
            CTRL.state.page = 1;
            ctrlLoadAll();
        });
    });

    document.querySelectorAll('#ccBasis button').forEach(b => {
        b.addEventListener('click', () => {
            CTRLC.basis = b.dataset.b;
            document.querySelectorAll('#ccBasis button').forEach(x => x.classList.toggle('active', x === b));
            ctrlLoadAll(false);
        });
    });

    document.getElementById('ctrlBtnCompare').addEventListener('click', () => {
        CTRL.state.compare = CTRL.state.compare === 'prev' ? 'year' : 'prev';
        document.getElementById('ctrlCompareLbl').textContent =
            CTRL.state.compare === 'prev' ? 'vs. Vorperiode' : 'vs. Vorjahr';
        ctrlLoadAll(false);
    });

    document.getElementById('ctrlBtnRefresh').addEventListener('click', () => ctrlLoadAll());
    document.getElementById('ccBtnSettings').addEventListener('click', openCcSettings);
    document.getElementById('ctrlBtnExport').addEventListener('click', exportCsv);

    document.getElementById('ctrlFrom')?.addEventListener('change', e => {
        CTRL.state.from = e.target.value || null;
        ctrlLoadAll();
    });
    document.getElementById('ctrlTo')?.addEventListener('change', e => {
        CTRL.state.to = e.target.value || null;
        ctrlLoadAll();
    });
}

function bindCtrlFilters() {
    document.getElementById('ctrlKunde').addEventListener('change', async e => {
        CTRL.state.filters.kunden_id = e.target.value;
        const objSel = document.getElementById('ctrlObjekt');
        const objOpts = await getObjectOptions(e.target.value || undefined);
        objSel.innerHTML = ctrlToMdOptions(objOpts, 'Alle Objekte');
        CTRL.state.filters.obj_id = '';
        CTRL.state.page = 1;
        ctrlLoadAll();
    });
    document.getElementById('ctrlObjekt').addEventListener('change', e => {
        CTRL.state.filters.obj_id = e.target.value;
        CTRL.state.page = 1;
        ctrlLoadAll();
    });
    document.getElementById('ctrlNu').addEventListener('change', e => {
        CTRL.state.filters.nu_id = e.target.value;
        CTRL.state.page = 1;
        ctrlLoadAll();
    });
    document.getElementById('ctrlBereich').addEventListener('change', e => {
        CTRL.state.filters.bereich = e.target.value;
        CTRL.state.page = 1;
        ctrlLoadAll();
    });
    document.getElementById('ctrlMitarbeiter').addEventListener('change', e => ctrlSelectWorker(e.target.value));
    document.getElementById('ctrlWorkerClear')?.addEventListener('click', () => ctrlSelectWorker(''));
    document.getElementById('ctrlBtnClearFilters').addEventListener('click', ctrlResetFilters);
}

/** Reset every filter (not the period) and reload all cards. */
function ctrlResetFilters() {
    Object.keys(CTRL.state.filters).forEach(k => { CTRL.state.filters[k] = ''; });
    ['ctrlKunde', 'ctrlNu', 'ctrlMitarbeiter', 'ctrlBereich'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    const obj = document.getElementById('ctrlObjekt');
    if (obj) getObjectOptions().then(opts => { obj.innerHTML = ctrlToMdOptions(opts, 'Alle Objekte'); }).catch(() => {});
    CTRL.state.page = 1;
    ctrlLoadAll();
}

function bindCtrlDetailsPager() {
    document.getElementById('ctrlPerPage').addEventListener('change', e => {
        CTRL.state.perPage = parseInt(e.target.value, 10);
        CTRL.state.page = 1;
        ctrlLoadDetails();
    });
}

function bindCcSections() {
    document.querySelectorAll('#ccObjFilter .chip').forEach(c => c.addEventListener('click', () => {
        document.querySelectorAll('#ccObjFilter .chip').forEach(x => x.classList.toggle('active', x === c));
        CTRLC.objFilter = c.dataset.a;
        if (CTRLC.data) renderCcObjekte(CTRLC.data);
    }));
    document.querySelectorAll('#ccTabs .chip').forEach(c => c.addEventListener('click', () => {
        document.querySelectorAll('#ccTabs .chip').forEach(x => x.classList.toggle('active', x === c));
        CTRLC.tab = c.dataset.t;
        if (CTRLC.data) renderCcTab(CTRLC.data);
    }));
}
