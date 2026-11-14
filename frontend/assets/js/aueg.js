/* ============================================================
   AÜG-Fristen — Überlassungshöchstdauer (§ 1 Abs. 1b AÜG) and
   Equal Pay (§ 8 Abs. 4 AÜG) per Leiharbeitnehmer and Entleiher.
   Backend: GET    /api/aueg                       (overview + config)
            PUT    /api/aueg/entleiher/{kunde_id}  { aktiv, hoechstdauer_monate, equal_pay_monate, bemerkung }
            DELETE /api/aueg/entleiher/{kunde_id}
   Calculation: App\Support\Aueg (server) — this page only displays it.
   ============================================================ */

const AG_API = API_BASE + '/aueg';
const AG_STATUS = {
    ueberschritten: { label: 'Höchstdauer überschritten', cls: 'ag-s-bad',  icon: 'block' },
    bald:           { label: 'Höchstdauer endet bald',    cls: 'ag-s-warn', icon: 'schedule' },
    equal_pay:      { label: 'Equal Pay',                 cls: 'ag-s-info', icon: 'payments' },
    geplant:        { label: 'Geplant',                   cls: 'ag-s-plan', icon: 'event_upcoming' },
    ok:             { label: 'Im Rahmen',                 cls: 'ag-s-ok',   icon: 'check_circle' },
};
const _ag = { data: null, filter: 'alle', q: '' };

function agIcon(n, st = '') { return `<span class="material-symbols-outlined"${st ? ` style="${st}"` : ''}>${n}</span>`; }
function agDate(d) { return d ? formatDate(String(d).substring(0, 10)) : '–'; }
function agCanEdit() { return typeof hasPermission !== 'function' || hasPermission('stammdaten.kunden.edit'); }

async function renderAueg() {
    const content = document.getElementById('content');
    content.innerHTML = `
        <div class="ag-page">
            <div class="ag-info">
                ${agIcon('gavel')}
                <div>
                    <strong>Arbeitnehmerüberlassung (AÜG):</strong> berechnet aus den geplanten und geleisteten Einsätzen je Mitarbeiter und Entleiher.
                    Höchstdauer standardmäßig <b>18 Monate</b> (§ 1 Abs. 1b), Equal Pay nach <b>9 Monaten</b> (§ 8 Abs. 4).
                    Unterbrechungen bis 3 Monate werden angerechnet (Monat = 30 Tage), längere starten die Frist neu.
                    Lücken bis <span id="agLuecke">7</span> Tage zwischen Einsätzen gelten als durchgehende Überlassung.
                    Nur Kunden, die unten als <b>Entleiher</b> markiert sind, werden geprüft.
                </div>
            </div>
            <div class="ag-toolbar">
                <div class="ag-seg" id="agSeg">
                    <button data-f="alle" onclick="agSetFilter('alle')">Alle</button>
                    <button data-f="handeln" onclick="agSetFilter('handeln')">${agIcon('priority_high')} Handlungsbedarf</button>
                    <button data-f="equal_pay" onclick="agSetFilter('equal_pay')">${agIcon('payments')} Equal Pay</button>
                </div>
                <div class="ag-search">${agIcon('search')}<input id="agSearch" placeholder="Mitarbeiter oder Entleiher suchen…" value="${escapeHtml(_ag.q)}"></div>
                <button class="ag-btn" onclick="agExportCsv()">${agIcon('download')} CSV</button>
            </div>
            <div id="agTable" class="ag-card"><div class="ag-empty">${agIcon('hourglass_top')} Lade…</div></div>
            <h3 class="ag-h">${agIcon('domain')} Entleiher</h3>
            <div id="agEntleiher" class="ag-card"></div>
        </div>
        ${agStyles()}`;
    let t = null;
    document.getElementById('agSearch').addEventListener('input', e => {
        clearTimeout(t); t = setTimeout(() => { _ag.q = e.target.value.trim().toLowerCase(); agRenderTable(); }, 200);
    });
    await agLoad();
}

async function agLoad() {
    const d = await apiGet(AG_API);
    if (!d || Array.isArray(d) || d.error) {
        document.getElementById('agTable').innerHTML = `<div class="ag-empty">${agIcon('error')} ${escapeHtml((d && d.error) || 'AÜG-Daten konnten nicht geladen werden')}</div>`;
        return;
    }
    _ag.data = d;
    const l = document.getElementById('agLuecke');
    if (l && d.regeln) l.textContent = d.regeln.luecke_tage;
    agRenderTable();
    agRenderEntleiher();
}

function agSetFilter(f) { _ag.filter = f; agRenderTable(); }

function agRows() {
    const rows = (_ag.data && _ag.data.rows) || [];
    return rows.filter(r => {
        if (_ag.filter === 'handeln' && !['ueberschritten', 'bald'].includes(r.status) && !r.geplant_ueber_frist) return false;
        if (_ag.filter === 'equal_pay' && r.status !== 'equal_pay' && r.equal_pay_ab > new Date().toISOString().substring(0, 10)) return false;
        if (_ag.q && !`${r.mitarbeiter} ${r.dienst_nr} ${r.kundenname} ${r.kunde_id}`.toLowerCase().includes(_ag.q)) return false;
        return true;
    });
}

function agRenderTable() {
    document.querySelectorAll('#agSeg button').forEach(b => b.classList.toggle('active', b.dataset.f === _ag.filter));
    const box = document.getElementById('agTable');
    if (!box || !_ag.data) return;
    if (!(_ag.data.entleiher || []).some(e => e.aktiv)) {
        box.innerHTML = `<div class="ag-empty">${agIcon('domain_add')} Noch kein Entleiher markiert. Wählen Sie unten die Kunden aus, an die Sie Mitarbeiter überlassen.</div>`;
        return;
    }
    const _p = hmsPagerDef('agPager', 'Einträge', () => agRenderTable());
    const pg = hmsPage(agRows(), _p.st, (agRows()[0] && (agRows()[0].dienst_nr + '|' + agRows()[0].kunde_id)) || '');
    const rows = pg.rows;
    if (!rows.length) { box.innerHTML = `<div class="ag-empty">${agIcon('task_alt')} Keine Einträge für diesen Filter.</div>`; return; }
    box.innerHTML = `
        <div class="table-container"><table class="ag-table">
            <thead><tr>
                <th>Status</th><th>Mitarbeiter</th><th>Entleiher</th><th>Beginn</th>
                <th class="ag-num">Tage</th><th>Equal Pay ab</th><th>Höchstdauer bis</th><th>Letzter Einsatz</th>
            </tr></thead>
            <tbody>${rows.map(r => {
                const s = AG_STATUS[r.status] || AG_STATUS.ok;
                return `<tr>
                    <td><span class="ag-chip ${s.cls}">${agIcon(s.icon, 'font-size:15px')} ${s.label}</span>${r.geplant_ueber_frist && r.status !== 'ueberschritten' ? `<div class="ag-sub ag-bad">Geplante Einsätze über die Frist hinaus</div>` : ''}</td>
                    <td><b>${escapeHtml(r.mitarbeiter || r.dienst_nr)}</b><div class="ag-sub">${escapeHtml(r.dienst_nr)}${r.mitarbeiter_aktiv ? '' : ' · inaktiv'}</div></td>
                    <td>${escapeHtml(r.kundenname)}<div class="ag-sub">${r.max_monate} / ${r.ep_monate} Monate</div></td>
                    <td>${agDate(r.beginn)}${r.unterbrechungen ? `<div class="ag-sub">${r.unterbrechungen}× unterbrochen</div>` : ''}</td>
                    <td class="ag-num">${r.tage}</td>
                    <td>${agDate(r.equal_pay_ab)}</td>
                    <td><b>${agDate(r.hoechstdauer_bis)}</b></td>
                    <td>${agDate(r.letzter_einsatz)}</td>
                </tr>`;
            }).join('')}</tbody>
        </table></div>
        <div id="agPager" class="be-pager">${hmsPagerHtml('agPager', pg, _p.unit)}</div>`;
}

function agRenderEntleiher() {
    const box = document.getElementById('agEntleiher');
    if (!box || !_ag.data) return;
    const ent = _ag.data.entleiher || [];
    const used = new Set(ent.map(e => e.kunde_id));
    const free = (_ag.data.kunden || []).filter(k => !used.has(k.kunde_id));
    const edit = agCanEdit();
    box.innerHTML = `
        ${ent.length ? `<div class="table-container"><table class="ag-table">
            <thead><tr><th>Kunde</th><th class="ag-num">Höchstdauer (Monate)</th><th class="ag-num">Equal Pay nach (Monaten)</th><th>Bemerkung (z.B. Tarifvertrag)</th><th>Aktiv</th><th></th></tr></thead>
            <tbody>${ent.map(e => `<tr data-k="${escapeHtml(e.kunde_id)}">
                <td><b>${escapeHtml(e.kundenname)}</b><div class="ag-sub">${escapeHtml(e.kunde_id)}</div></td>
                <td class="ag-num"><input type="number" class="ag-in ag-max" min="1" max="120" value="${e.hoechstdauer_monate}" ${edit ? '' : 'disabled'}></td>
                <td class="ag-num"><input type="number" class="ag-in ag-ep" min="1" max="120" value="${e.equal_pay_monate}" ${edit ? '' : 'disabled'}></td>
                <td><input type="text" class="ag-in ag-bem" maxlength="255" value="${escapeHtml(e.bemerkung || '')}" placeholder="z.B. TV LeiZ Metall: 48 Monate" ${edit ? '' : 'disabled'}></td>
                <td><input type="checkbox" class="ag-akt" ${e.aktiv ? 'checked' : ''} ${edit ? '' : 'disabled'}></td>
                <td class="ag-actions">${edit ? `<button class="ag-btn ag-btn-sm" onclick="agSaveEntleiher('${escapeHtml(e.kunde_id)}')">${agIcon('save')}</button><button class="ag-btn ag-btn-sm ag-danger" onclick="agRemoveEntleiher('${escapeHtml(e.kunde_id)}')" title="Nicht mehr prüfen">${agIcon('delete')}</button>` : ''}</td>
            </tr>`).join('')}</tbody>
        </table></div>` : `<div class="ag-empty" style="padding:16px">Noch keine Entleiher.</div>`}
        ${edit ? `<div class="ag-add">
            <select id="agAddKunde"><option value="">Kunde als Entleiher hinzufügen…</option>${free.map(k => `<option value="${escapeHtml(k.kunde_id)}">${escapeHtml(k.kundenname)} (${escapeHtml(k.kunde_id)})</option>`).join('')}</select>
            <button class="ag-btn ag-btn-primary" onclick="agAddEntleiher()">${agIcon('add')} Hinzufügen</button>
            <span class="ag-sub">Abweichende Höchstdauer nur bei Tarifvertrag der Einsatzbranche (§ 1 Abs. 1b Satz 3 AÜG).</span>
        </div>` : ''}`;
}

async function agAddEntleiher() {
    const id = document.getElementById('agAddKunde')?.value;
    if (!id) return showToast('Bitte einen Kunden wählen', 'error');
    const res = await apiPut(AG_API + '/entleiher/' + encodeURIComponent(id), { aktiv: true, hoechstdauer_monate: 18, equal_pay_monate: 9 });
    if (!res || res.error) return showToast((res && res.error) || 'Speichern fehlgeschlagen', 'error');
    showToast('Entleiher hinzugefügt');
    await agLoad();
}

async function agSaveEntleiher(id) {
    const tr = document.querySelector(`#agEntleiher tr[data-k="${CSS.escape(id)}"]`);
    if (!tr) return;
    const max = parseInt(tr.querySelector('.ag-max').value, 10);
    const ep = parseInt(tr.querySelector('.ag-ep').value, 10);
    if (!(max >= 1 && max <= 120) || !(ep >= 1 && ep <= 120)) return showToast('Monate: 1 bis 120', 'error');
    const res = await apiPut(AG_API + '/entleiher/' + encodeURIComponent(id), {
        aktiv: tr.querySelector('.ag-akt').checked, hoechstdauer_monate: max, equal_pay_monate: ep,
        bemerkung: tr.querySelector('.ag-bem').value.trim(),
    });
    if (!res || res.error) return showToast((res && res.error) || 'Speichern fehlgeschlagen', 'error');
    showToast('Gespeichert');
    await agLoad();
}

async function agRemoveEntleiher(id) {
    const ok = typeof mdConfirm === 'function'
        ? await mdConfirm('Dieser Kunde wird nicht mehr auf AÜG-Fristen geprüft. Einsätze bleiben unverändert.', { title: 'Entleiher entfernen', confirmLabel: 'Entfernen' })
        : confirm('Entleiher entfernen?');
    if (!ok) return;
    const res = await apiDelete(AG_API + '/entleiher/' + encodeURIComponent(id));
    if (!res || res.error) return showToast((res && res.error) || 'Fehler', 'error');
    await agLoad();
}

function agExportCsv() {
    const rows = agRows();
    if (!rows.length) return showToast('Keine Daten für den Export', 'error');
    const head = ['Status', 'Personal-Nr.', 'Mitarbeiter', 'Kunden-Nr.', 'Entleiher', 'Beginn', 'Überlassungstage', 'Unterbrechungen', 'Equal Pay ab', 'Höchstdauer bis', 'Letzter Einsatz', 'Höchstdauer (Monate)', 'Equal Pay (Monate)'];
    const q = v => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [head.map(q).join(';')].concat(rows.map(r => [
        (AG_STATUS[r.status] || AG_STATUS.ok).label, r.dienst_nr, r.mitarbeiter, r.kunde_id, r.kundenname, agDate(r.beginn), r.tage,
        r.unterbrechungen, agDate(r.equal_pay_ab), agDate(r.hoechstdauer_bis), agDate(r.letzter_einsatz), r.max_monate, r.ep_monate,
    ].map(q).join(';')));
    const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'AUEG-Fristen_' + new Date().toISOString().substring(0, 10) + '.csv';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 10000);
}

function agStyles() {
    return `<style>
    .ag-page{display:flex;flex-direction:column;gap:14px}
    .ag-info{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:12px;background:var(--md-sys-color-surface-container,#f1f5f9);font-size:.85rem;line-height:1.5;color:var(--gray-700,#334155)}
    .ag-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
    .ag-seg{display:inline-flex;border:1px solid var(--gray-300,#cbd5e1);border-radius:999px;overflow:hidden}
    .ag-seg button{display:inline-flex;align-items:center;gap:4px;padding:7px 14px;border:0;background:transparent;cursor:pointer;font:inherit;font-size:.85rem;color:inherit}
    .ag-seg button .material-symbols-outlined{font-size:17px}
    .ag-seg button.active{background:var(--md-sys-color-secondary-container,#dbeafe);font-weight:600}
    .ag-search{flex:1;min-width:200px;display:flex;align-items:center;gap:6px;border:1px solid var(--gray-300,#cbd5e1);border-radius:999px;padding:5px 12px}
    .ag-search input{border:0;outline:0;background:transparent;flex:1;font:inherit;color:inherit}
    .ag-btn{display:inline-flex;align-items:center;gap:4px;padding:7px 14px;border-radius:999px;border:1px solid var(--gray-300,#cbd5e1);background:transparent;cursor:pointer;font:inherit;font-size:.85rem;color:inherit}
    .ag-btn-primary{background:var(--md-sys-color-primary,#2563eb);border-color:transparent;color:#fff}
    .ag-btn-sm{padding:4px 8px}
    .ag-btn .material-symbols-outlined{font-size:18px}
    .ag-danger{color:#dc2626}
    .ag-card{border:1px solid var(--gray-200,#e2e8f0);border-radius:12px;overflow:hidden;background:var(--md-sys-color-surface,#fff)}
    .ag-table{width:100%;border-collapse:collapse;font-size:.85rem}
    .ag-table th,.ag-table td{padding:9px 10px;border-bottom:1px solid var(--gray-200,#e2e8f0);text-align:left;vertical-align:top}
    .ag-table th{font-size:.75rem;text-transform:uppercase;letter-spacing:.03em;color:var(--gray-500,#64748b)}
    .ag-num{text-align:right!important}
    .ag-sub{font-size:.75rem;color:var(--gray-500,#64748b);margin-top:2px}
    .ag-bad{color:#dc2626}
    .ag-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:999px;font-size:.75rem;font-weight:600;white-space:nowrap}
    .ag-s-bad{background:rgba(220,38,38,.12);color:#dc2626}
    .ag-s-warn{background:rgba(217,119,6,.14);color:#b45309}
    .ag-s-info{background:rgba(37,99,235,.12);color:#1d4ed8}
    .ag-s-plan{background:rgba(100,116,139,.14);color:#475569}
    .ag-s-ok{background:rgba(22,163,74,.12);color:#15803d}
    .ag-empty{padding:28px 16px;text-align:center;color:var(--gray-500,#64748b);display:flex;gap:8px;justify-content:center;align-items:center}
    .ag-h{display:flex;align-items:center;gap:6px;margin:8px 0 0;font-size:1rem}
    .ag-in{width:100%;max-width:260px;padding:5px 8px;border:1px solid var(--gray-300,#cbd5e1);border-radius:8px;font:inherit;background:transparent;color:inherit}
    .ag-num .ag-in{max-width:80px;text-align:right}
    .ag-actions{white-space:nowrap}
    .ag-add{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px}
    .ag-add select{padding:7px 10px;border-radius:8px;border:1px solid var(--gray-300,#cbd5e1);font:inherit;min-width:260px;background:transparent;color:inherit}
    [data-theme="dark"] .ag-s-warn{color:#fbbf24}[data-theme="dark"] .ag-s-info{color:#93c5fd}[data-theme="dark"] .ag-s-ok{color:#4ade80}[data-theme="dark"] .ag-s-bad{color:#f87171}
    </style>`;
}

window.renderAueg = renderAueg;
