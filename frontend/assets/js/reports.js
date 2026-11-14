/* ============================================
   HMS Gruppe — Auswertung & Controlling Module
   Backend: api/reports.php
   ============================================ */

async function renderReports() {
    const content = document.getElementById('content');
    const now = new Date();
    const customerOpts = await getCustomerOptions();
    const nuOpts = await getSubcontractorOptions();

    content.innerHTML = `
        <div class="filter-bar" style="background:var(--orange-light);border:1px solid #fed7aa">
            <div class="form-group">
                <label><i class="fas fa-user"></i> Kunde</label>
                <select class="form-control" id="rep_kunde"><option value="">Alle</option>${customerOpts}</select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-map-marker-alt"></i> Objekt</label>
                <select class="form-control" id="rep_objekt"><option value="">Alle</option></select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-calendar"></i> Monat</label>
                <select class="form-control" id="rep_monat">
                    <option value="">Alle</option>
                    ${MONTHS_DE.map((m, i) => i > 0 ? `<option value="${i}" ${i === now.getMonth() + 1 ? 'selected' : ''}>${m}</option>` : '').join('')}
                </select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-calendar"></i> Jahr</label>
                <input type="number" class="form-control" id="rep_jahr" value="${now.getFullYear()}">
            </div>
            <div class="form-group">
                <label><i class="fas fa-hard-hat"></i> NU</label>
                <select class="form-control" id="rep_nu"><option value="">Alle</option>${nuOpts}</select>
            </div>
            <div class="form-group" style="align-self:flex-end">
                <md-filled-button  class="md-btn-warning" onclick="loadReports()"><i slot="icon" class="fas fa-search"></i> Auswerten</md-filled-button>
            </div>
        </div>
        <div id="reportKPIs"></div>
        <div class="card mt-2">
            <div class="card-header"><h3><i class="fas fa-list"></i> Detail-Ansicht — Einsätze</h3></div>
            <div class="card-body no-pad"><div class="table-container" id="reportTable">
                <p class="text-center text-muted" style="padding:40px">Filter setzen und "Auswerten" klicken</p>
            </div></div>
        </div>
    `;

    document.getElementById('rep_kunde').addEventListener('change', async () => {
        const kundeId = document.getElementById('rep_kunde').value;
        const objOpts = kundeId ? await getObjectOptions(kundeId) : '';
        document.getElementById('rep_objekt').innerHTML = '<option value="">Alle</option>' + objOpts;
    });

    loadReports();
}

let _repCache = null;
async function loadReports(useCache) {
    const params = new URLSearchParams();
    const kunde = document.getElementById('rep_kunde').value;
    const objekt = document.getElementById('rep_objekt').value;
    const monat = document.getElementById('rep_monat').value;
    const jahr = document.getElementById('rep_jahr').value;
    const nu = document.getElementById('rep_nu').value;

    if (kunde) params.set('kunden_id', kunde);
    if (objekt) params.set('obj_id', objekt);
    if (monat && jahr) { params.set('monat', monat); params.set('jahr', jahr); }
    if (nu) params.set('nu_id', nu);

    const data = (useCache && _repCache) ? _repCache : await apiGet(API.reports + '?' + params.toString());
    _repCache = data;
    if (!data || data.error) return;
    const kpi = data.kpi;

    document.getElementById('reportKPIs').innerHTML = `
        <div class="report-kpi-grid">
            <div class="card" style="border-top:4px solid var(--success)">
                <div class="card-body">
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-euro-sign"></i> Einnahmen (bestätigt)</span><span class="quick-stat-value text-success">${formatCurrency(kpi.einnahmen)}</span></div>
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-cog"></i> Kosten NU</span><span class="quick-stat-value">${formatCurrency(kpi.nu_kosten)}</span></div>
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-chart-line"></i> Gewinn</span><span class="quick-stat-value text-success">${formatCurrency(kpi.gewinn)}</span></div>
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-percentage"></i> Marge</span><span class="quick-stat-value">${kpi.marge}%</span></div>
                </div>
            </div>
            <div class="card" style="border-top:4px solid var(--accent)">
                <div class="card-body">
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-check"></i> Bestätigte Einsätze</span><span class="quick-stat-value">${kpi.bestaetigt_count}</span></div>
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-hourglass"></i> Ausstehend</span><span class="quick-stat-value">${kpi.ausstehend_count}</span></div>
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-times"></i> Nicht bestätigt</span><span class="quick-stat-value text-danger">${kpi.nicht_bestaetigt_count}</span></div>
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-coins"></i> Ø Gewinn/Einsatz</span><span class="quick-stat-value">${formatCurrency(kpi.gewinn_pro_einsatz)}</span></div>
                </div>
            </div>
            <div class="card" style="border-top:4px solid var(--warning)">
                <div class="card-body">
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-file-invoice"></i> Bestellungen</span><span class="quick-stat-value">${kpi.bestellungen_total}</span></div>
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-clock"></i> Soll-Stunden</span><span class="quick-stat-value">${kpi.soll_stunden}</span></div>
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-stopwatch"></i> Ist-Stunden</span><span class="quick-stat-value">${kpi.ist_stunden_total}</span></div>
                    <div class="quick-stat"><span class="quick-stat-label"><i class="fas fa-balance-scale"></i> Δ Soll – Ist</span><span class="quick-stat-value ${kpi.delta_stunden >= 0 ? '' : 'text-danger'}">${kpi.delta_stunden}</span></div>
                </div>
            </div>
        </div>
    `;

    const details = data.details || [];
    const _p = hmsPagerDef('repPager', 'Einträge', () => loadReports(true));
    const pg = hmsPage(details, _p.st);
    document.getElementById('reportTable').innerHTML = details.length > 0 ? `
        <table>
            <thead><tr>
                <th>Datum</th><th>Kunde</th><th>Objekt</th><th>NU</th><th>Ist-Std</th>
                <th>Einnahmen (€)</th><th>NU-Kosten (€)</th><th>Gewinn (€)</th><th>Bestätigt</th>
            </tr></thead>
            <tbody>
                ${pg.rows.map(d => `<tr>
                    <td>${formatDate(d.datum)}</td><td>${d.kundenname || '–'}</td><td>${d.objektname || '–'}</td>
                    <td>${d.nu_name || '–'}</td><td class="text-right">${d.ist_std_menge || '–'}</td>
                    <td class="text-right">${formatCurrency(d.einnahmen_detail)}</td>
                    <td class="text-right">${formatCurrency(d.nu_kosten_detail)}</td>
                    <td class="text-right fw-bold ${parseFloat(d.gewinn_detail) >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(d.gewinn_detail)}</td>
                    <td>${statusBadge(d.hms_bestaetigt)}</td>
                </tr>`).join('')}
            </tbody>
        </table>
        <div id="repPager" class="be-pager">${hmsPagerHtml('repPager', pg, _p.unit)}</div>
    ` : '<p class="text-center text-muted" style="padding:40px">Keine Daten</p>';
}
