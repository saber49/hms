// tasks/08-ma-pdf.js — Monatsabschluss: PDF-Branding, Stundennachweis-PDFs, an Mitarbeiter senden
// Teil 8/10 des früheren tasks.js. Alle Teile teilen den globalen Scope und MÜSSEN in dieser Reihenfolge geladen werden (PAGE_MODULE_SCRIPTS in app.js).

// ============================================
// PDF STUNDENNACHWEIS — Branding (logo + colours)
// ============================================
// Company-wide branding for the worker PDFs. The colours mirror the brand
// colour set in the "Bestellungen" PDF editor; both are stored server-side in
// tenant.settings (pdf_branding), so they stay in sync across devices/users.
// The logo is embedded as a data: URI so it survives in the copy that gets
// SENT to the worker (which may be viewed without an authenticated session).
let _maBrand = { primary: '#1e3a5f', accent: '#1e3a5f' };
let _maBrandLogo = null;        // data: URI of the company logo (or null)
let _maBrandLogoPath = null;    // last logo_path we resolved (cache key)
let _maBrandLogoPos = 'right';  // header logo position: 'left' | 'center' | 'right'
let _maCustomWorkerTemplate = null;

function _maApiBase() {
    return (typeof API_BASE !== 'undefined' && API_BASE) ? API_BASE : (window.location.origin + '/api');
}

// Pull the Stundennachweis colours + logo from the server: the worker
// template's own branding (Dokument-Design, isolated per document type),
// else the company default. Called on every Monatsabschluss render so a
// colour just saved in the editor is picked up.
async function maLoadBranding() {
    try {
        const [profile, layouts] = await Promise.all([
            apiGet(_maApiBase() + '/dashboard/company'),
            apiGet(_maApiBase() + '/pdf/layouts').catch(() => null),
        ]);
        if (profile && !profile.error) {
            const own = layouts && layouts.layouts && layouts.layouts.worker && layouts.layouts.worker.branding;
            const company = (profile.pdf_branding && typeof profile.pdf_branding === 'object') ? profile.pdf_branding : {};
            const b = { ...company, ...((own && typeof own === 'object') ? own : {}) };
            _maBrand.primary = b.primary_color || '#1e3a5f';
            _maBrand.accent  = b.accent_color  || _maBrand.primary;
            _maBrandLogoPos  = b.logo_position || 'right';
            const tpl = profile.pdf_templates && profile.pdf_templates.worker;
            _maCustomWorkerTemplate = (tpl && typeof tpl.html === 'string' && tpl.html.trim() !== '') ? tpl.html : null;
            const path = profile.logo_path || null;
            if (path !== _maBrandLogoPath) {
                _maBrandLogoPath = path;
                _maBrandLogo = path ? await maFetchLogoDataUri(path) : null;
            }
        }
    } catch (e) { /* keep defaults on any failure */ }
}

// Fetch the logo and convert it to a base64 data: URI so it can be embedded
// directly into the generated/sent HTML (no auth or origin dependency).
async function maFetchLogoDataUri(path) {
    try {
        const url = (typeof tenantLogoUrl === 'function')
            ? tenantLogoUrl(path)
            : (_maApiBase() + '/serve?path=' + encodeURIComponent(path));
        const res = await fetch(url);
        if (!res.ok) return null;
        const blob = await res.blob();
        return await new Promise((resolve) => {
            const fr = new FileReader();
            fr.onload  = () => resolve(fr.result);
            fr.onerror = () => resolve(null);
            fr.readAsDataURL(blob);
        });
    } catch (e) { return null; }
}

// Build the worker-PDF header honouring the company-wide logo position
// (left = logo above the company name, center, right = default). Shared by
// the single, combined and NU header builders so all three stay in sync.
function _maHeaderHtml(companyHtml, subText, monthLabel, logoImg) {
    const pos = _maBrandLogoPos || 'right';
    const logoRow = logoImg ? `<div class="logo-row logo-${pos}">${logoImg}</div>` : '';
    return logoRow +
        `<div class="header"><div class="brand"><div class="company">${companyHtml}</div><div class="company-sub">${subText}</div></div>` +
        `<div><div class="doc-title">Stundennachweis</div><div class="doc-period">${monthLabel}</div></div></div>`;
}

function maBuildWorkerTemplateTable(worker) {
    const head = ['Nr.', 'Datum', 'Tag', 'Objekt', 'Von', 'Bis', 'Pause', 'Std'];
    const thStyle = 'text-align:left;padding:9px 10px;font-size:11px;font-weight:600;color:var(--gray-500);text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #cbd5e1;';
    const tdStyle = 'padding:8px 10px;font-size:12.5px;color:var(--gray-800);border-bottom:1px solid #eef2f7;';
    const thead = '<tr>' + head.map(h => `<th style="${thStyle}">${escapeHtml(h)}</th>`).join('') + '</tr>';
    let total = 0;
    let totalPause = 0;
    const rows = (worker.entries || []).map((e, i) => {
        const d = e.datum ? new Date(e.datum + 'T00:00:00') : null;
        const dayName = d ? DAYS_SHORT[d.getDay()] : '-';
        const std = maCalcEntryStd(e);
        const pause = wpCalcPause(e.ist_start, e.ist_ende, std, e.pause_minuten ?? 0);
        total += std;
        totalPause += pause;
        const t = maWorkerTimeInfo(e);
        const cells = [
            escapeHtml(String(i + 1)),
            escapeHtml(wpFormatDate(e.datum)),
            escapeHtml(dayName),
            escapeHtml(e.objektname || '-'),
            escapeHtml(t.von),
            escapeHtml(t.bis),
            escapeHtml(pause > 0 ? pause + ' Min' : '-'),
            escapeHtml(std ? std.toFixed(2) : '-'),
        ];
        return '<tr>' + cells.map((c, idx) => `<td style="${tdStyle}${idx === 0 ? 'width:42px;color:var(--gray-500);' : ''}${idx === 7 ? 'text-align:right;' : ''}">${c}</td>`).join('') + '</tr>';
    }).join('');
    const body = rows || '<tr><td colspan="8" style="padding:18px;text-align:center;color:var(--gray-400)">Keine Einsätze</td></tr>';
    const html = `<table style="width:100%;border-collapse:collapse;margin-top:6px"><thead>${thead}</thead><tbody>${body}</tbody></table>`;
    return { html, total, totalPause };
}

function maFillWorkerTemplate(tpl, worker, monthLabel) {
    const table = maBuildWorkerTemplateTable(worker);
    const name = ((worker.vorname || '') + ' ' + (worker.name || '')).trim() || worker.dienst_nr || '';
    const companyName = escapeHtml((typeof CURRENT_TENANT !== 'undefined' && CURRENT_TENANT.name) || 'Unternehmen');
    const logoImg = _maBrandLogo ? `<img src="${_maBrandLogo}" alt="Logo" style="max-width:120px;max-height:70px;object-fit:contain">` : '';
    const objInfo = maWorkerObjektInfo(worker.entries);
    const map = {
        company_name: companyName,
        company_info: '',
        logo: logoImg,
        doc_title: 'STUNDENNACHWEIS / MONATSABSCHLUSS',
        mitarbeiter_name: escapeHtml(name),
        dienst_nr: escapeHtml(worker.dienst_nr || ''),
        bereich: escapeHtml(worker.bereich || '-'),
        objekt: escapeHtml(objInfo.objekt),
        anschrift: escapeHtml(objInfo.anschrift),
        einsaetze: escapeHtml(String((worker.entries || []).length)),
        monat: escapeHtml(monthLabel || ''),
        stunden_table: table.html,
        summe_std: escapeHtml(table.total.toFixed(2) + ' Std'),
        footer: '',
    };
    let out = tpl;
    Object.keys(map).forEach((k) => {
        out = out.replace(new RegExp('\\{\\{\\s*' + k + '\\s*\\}\\}', 'g'), map[k]);
    });
    // Sanitise the (body-level) content first, THEN wrap it in the document
    // structure — this mirrors the proven pattern in pdf_design.js. DOMPurify
    // strips <html>/<head>/<body> unless WHOLE_DOCUMENT is set, so we must NOT
    // sanitise the wrapped document.
    if (typeof DOMPurify !== 'undefined') {
        out = DOMPurify.sanitize(out, { ADD_TAGS: ['style'], ADD_ATTR: ['style'] });
    }
    return `<!DOCTYPE html><html><head><meta charset="utf-8">` +
        `<title>Stundennachweis - ${escapeHtml(name)} - ${escapeHtml(monthLabel || '')}</title>` +
        `</head><body style="margin:0;background:var(--white)">${out}</body></html>`;
}

// ============================================
// PDF STUNDENNACHWEIS
// ============================================
function maBuildWorkerPDFHtml(worker, monthLabel) {
    if (_maCustomWorkerTemplate) {
        return maFillWorkerTemplate(_maCustomWorkerTemplate, worker, monthLabel);
    }

    const name = ((worker.vorname || '') + ' ' + (worker.name || '')).trim() || worker.dienst_nr;
    const objInfo = maWorkerObjektInfo(worker.entries);
    let totalStd = 0;
    let totalPause = 0;

    const rows = worker.entries.map((e, i) => {
        const d = e.datum ? new Date(e.datum + 'T00:00:00') : null;
        const dayName = d ? DAYS_SHORT[d.getDay()] : '-';
        const std = maCalcEntryStd(e);
        const pause = wpCalcPause(e.ist_start, e.ist_ende, std, e.pause_minuten ?? 0);
        totalStd += std;
        totalPause += pause;
        return '<tr>' +
            '<td>' + (i + 1) + '</td>' +
            '<td>' + wpFormatDate(e.datum) + '</td>' +
            '<td>' + dayName + '</td>' +
            '<td>' + escapeHtml(e.objektname || '-') + '</td>' +
            maWorkerTimeCellsHtml(e) +
            '<td>' + (pause > 0 ? pause + ' Min' : '-') + '</td>' +
            '<td style="text-align:right">' + (std ? std.toFixed(2) : '-') + '</td>' +
            '</tr>';
    }).join('');

    const primary = _maBrand.primary || '#1e3a5f';
    const accent  = _maBrand.accent  || primary;
    const companyName = escapeHtml((typeof CURRENT_TENANT !== 'undefined' && CURRENT_TENANT.name) || 'Unternehmen');
    const logoImg = _maBrandLogo ? `<img class="brand-logo" src="${_maBrandLogo}" alt="Logo">` : '';

    return `<!DOCTYPE html><html><head><meta charset="utf-8">` +
        `<title>Stundennachweis - ${name} - ${monthLabel}</title>` +
        `<style>` +
        `*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }` +
        `body { font-family: Arial, Helvetica, sans-serif; font-size:10px; color:#1a1a1a; padding:15mm; }` +
        `.header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:18px; padding-bottom:10px; border-bottom:2px solid ${primary}; }` +
        `.brand { display:flex; flex-direction:column; gap:2px; }` +
        `.logo-row { margin-bottom:8px; } .logo-row.logo-left { text-align:left; } .logo-row.logo-center { text-align:center; } .logo-row.logo-right { text-align:right; margin-top:-6px; }` +
        `.brand-logo { height:42px; width:auto; max-width:150px; object-fit:contain; }` +
        `.company { font-size:15px; font-weight:700; color:${primary}; }` +
        `.company-sub { font-size:8px; color:#666; margin-top:2px; }` +
        `.doc-title { font-size:13px; font-weight:700; text-align:right; color:${primary}; }` +
        `.doc-period { font-size:10px; color:#444; text-align:right; }` +
        `.info { background:var(--gray-50); border:1px solid var(--gray-200); border-radius:4px; padding:8px 12px; margin-bottom:14px; display:flex; gap:24px; }` +
        `.info div { font-size:8px; color:#666; }` +
        `.info strong { font-size:10px; color:#1a1a1a; display:block; }` +
        `table { width:100%; border-collapse:collapse; margin-bottom:14px; }` +
        `th { background:${accent}; color:white; padding:5px 6px; text-align:left; font-size:8px; font-weight:600; text-transform:uppercase; letter-spacing:0.3px; }` +
        `td { padding:4px 6px; border-bottom:1px solid var(--gray-200); font-size:9px; }` +
        `tr:nth-child(even) { background:var(--gray-50); }` +
        `tfoot td { font-weight:700; border-top:2px solid ${primary}; background:var(--gray-100); }` +
        `.summary { display:flex; justify-content:flex-end; gap:20px; padding:8px 12px; background:${primary}; color:white; border-radius:4px; font-size:10px; }` +
        `.summary strong { font-size:12px; }` +
        `.footer { margin-top:24px; padding-top:8px; border-top:1px solid var(--gray-200); display:flex; justify-content:space-between; font-size:7px; color:#999; }` +
        `.sub { display:block; font-size:7.5px; color:#6b7280; } .legend { margin-top:8px; font-size:7.5px; line-height:1.45; color:#6b7280; }` +
        `@media print { body { padding:10mm; } @page { margin:0; size:A4; } }` +
        `</style></head><body>` +
        _maHeaderHtml(companyName, 'Stundennachweis / Monatsabschluss', monthLabel, logoImg) +
        `<div class="info">` +
        `<div>Mitarbeiter<strong>${name}</strong></div>` +
        `<div>Objekt<strong>${escapeHtml(objInfo.objekt)}</strong></div>` +
        `<div>Anschrift<strong>${escapeHtml(objInfo.anschrift)}</strong></div>` +
        `<div>Zeit<strong>${escapeHtml(monthLabel || '')}</strong></div>` +
        `<div>Eins&auml;tze<strong>${worker.entries.length}</strong></div>` +
        `</div>` +
        `<table><thead><tr><th>#</th><th>Datum</th><th>Tag</th><th>Objekt</th><th>Von</th><th>Bis</th><th>MA-Pause</th><th style="text-align:right">Std</th></tr></thead>` +
        `<tbody>${rows}</tbody>` +
        `<tfoot><tr><td colspan="6" style="text-align:right">Gesamt:</td><td>${totalPause > 0 ? totalPause + ' Min' : '-'}</td><td style="text-align:right">${totalStd.toFixed(2)}</td></tr></tfoot></table>` +
        `<div class="summary"><span>Eins&auml;tze: <strong>${worker.entries.length}</strong></span><span>Gesamtstunden: <strong>${totalStd.toFixed(2)} Std</strong></span></div>` +
        `<div class="legend">${MA_WORKER_PDF_LEGEND}</div>` +
        `</body></html>`;
}

// Counted Von/Bis for one Stundennachweis row. Plan data and the real app
// stamps are intentionally NOT printed (removed 2026-10-23) — the PDF shows
// only the angerechnete times.
function maWorkerTimeInfo(e) {
    return { von: wpFormatTime(e.ist_start), bis: wpFormatTime(e.ist_ende) };
}

const MA_WORKER_PDF_LEGEND = 'Von / Bis = angerechnete Arbeitszeit (auf Viertelstunden gerundet): Beginn frühestens zum Planbeginn, Ende spätestens zum Planende bzw. zum Ende einer begründeten Verlängerung. Std = Von bis Bis abzüglich Pause.';

// Header Objekt/Anschrift for the Stundennachweis info strip. A worker can
// cover several objects in a month — only when all entries share one object
// do we print its name and stored address, otherwise "Verschiedene".
function maWorkerObjektInfo(entries) {
    const seen = new Map();
    (entries || []).forEach(e => {
        const n = (e.objektname || '').trim();
        if (n && !seen.has(n)) seen.set(n, e);
    });
    if (seen.size !== 1) return { objekt: seen.size ? 'Verschiedene' : '–', anschrift: '–' };
    const [name, e] = seen.entries().next().value;
    const anschrift = [e.objekt_adresse, ((e.objekt_plz || '') + ' ' + (e.objekt_stadt || '')).trim()]
        .filter(Boolean).join(', ');
    return { objekt: name, anschrift: anschrift || '–' };
}

/** Von · Bis cells for the legacy print builders. */
function maWorkerTimeCellsHtml(e) {
    const t = maWorkerTimeInfo(e);
    return '<td>' + escapeHtml(t.von) + '</td><td>' + escapeHtml(t.bis) + '</td>';
}

// Rows for the shared PDF editor (order_form.php?doc=worker) — the same
// hour/pause maths as maBuildWorkerPDFHtml, so editor and print match.
function maBuildWorkerDocData(worker, monthLabel) {
    let totalStd = 0;
    let totalPause = 0;
    const rows = (worker.entries || []).map((e) => {
        const d = e.datum ? new Date(e.datum + 'T00:00:00') : null;
        const std = maCalcEntryStd(e);
        const pause = wpCalcPause(e.ist_start, e.ist_ende, std, e.pause_minuten ?? 0);
        const t = maWorkerTimeInfo(e);
        totalStd += std;
        totalPause += pause;
        return {
            datum: wpFormatDate(e.datum), tag: d ? DAYS_SHORT[d.getDay()] : '-',
            objekt: e.objektname || '-', von: t.von, bis: t.bis,
            pause, std,
        };
    });
    const objInfo = maWorkerObjektInfo(worker.entries);
    return {
        // Archive key of this Stundennachweis (POST /api/dokument-archiv).
        archive_ref: worker.dienst_nr ? 'MA:' + worker.dienst_nr + '|' + String(monthLabel || '').replace(/[|\u0000-\u001f]/g, ' ').slice(0, 80) : '',
        name: ((worker.vorname || '') + ' ' + (worker.name || '')).trim() || worker.dienst_nr,
        objekt: objInfo.objekt, anschrift: objInfo.anschrift, monthLabel,
        rows, totalStd, totalPause,
    };
}

// Opens the Stundennachweis in the shared PDF editor (edit texts, insert
// elements, signature/stamp, branding, download/print) — same flow as the
// Leistungsnachweis and the Auftragsbestätigung.
// opts (export dialog): { worker, periodLabel } for another period.
function maOpenWorkerPDF(dienstNr, opts) {
    const worker = opts && opts.worker ? opts.worker : _maPageWorkers[dienstNr];
    if (!worker) { showToast('Mitarbeiter nicht gefunden', 'error'); return; }
    const monthLabel = opts && opts.periodLabel ? opts.periodLabel : maCurrentPeriodLabel();
    if (typeof openPdfEditorModal === 'function') {
        try { sessionStorage.setItem('hms_worker_doc', JSON.stringify(maBuildWorkerDocData(worker, monthLabel))); } catch (e) {}
        openPdfEditorModal('/order_form.php?doc=worker&view=1&embed=1');
        return;
    }
    const html = maBuildWorkerPDFHtml(worker, monthLabel);
    const win = window.open('', '_blank');
    if (!win) { showToast('Popup blockiert — bitte Popups erlauben', 'error'); return; }
    win.document.write(html);
    win.document.close();
    win.onload = function() { win.print(); };
}

// NU (subcontractor) worker → the editor's Stundennachweis doc shape.
function maBuildNuDocData(w, firma, monthLabel) {
    let totalStd = 0;
    let totalPause = 0;
    const rows = (w.entries || []).map((e) => {
        const d = e.datum ? new Date(e.datum + 'T00:00:00') : null;
        const std = e.ist_std_menge ? parseFloat(e.ist_std_menge) : 0;
        const pause = wpCalcPause(e.ist_start, e.ist_ende, std, e.pause_minuten ?? 0);
        const t = maWorkerTimeInfo(e);
        totalStd += std;
        totalPause += pause;
        return {
            datum: wpFormatDate(e.datum), tag: d ? DAYS_SHORT[d.getDay()] : '-',
            objekt: e.objektname || '-', position: e.position_name || '',
            von: t.von, bis: t.bis, pause, std,
        };
    });
    const objInfo = maWorkerObjektInfo(w.entries);
    return {
        archive_ref: w.nu_worker_id ? 'NU:' + w.nu_worker_id + '|' + String(monthLabel || '').replace(/[|\u0000-\u001f]/g, ' ').slice(0, 80) : '',
        name: ((w.vorname || '') + ' ' + (w.name || '')).trim() || w.personal_nr || ('NU-' + w.nu_worker_id),
        objekt: objInfo.objekt, anschrift: objInfo.anschrift, monthLabel, rows, totalStd, totalPause,
    };
}

// Print all Stundennachweise of the period — rendered by the PDF editor
// (headless) with the company template, so they look exactly like the
// single Stundennachweis opened in the editor. Falls back to the legacy
// builder if the editor cannot be loaded.
/** {workers, nuCompanies} for the PDF builders — page caches, or an export-dialog list [{w,isNu,firma}]. */
function maPdfSources(list) {
    if (!list) return { workers: Object.values(_maPageWorkers), nuCompanies: _maPageNuWorkers || {} };
    const nuCompanies = {};
    list.filter(x => x.isNu).forEach(({ w, firma }) => {
        const f = firma || w.nu_firmenname || 'Unbekannt';
        (nuCompanies[f] = nuCompanies[f] || {})[w.nu_worker_id] = w;
    });
    return { workers: list.filter(x => !x.isNu).map(x => x.w), nuCompanies };
}

// opts (export dialog): { workers: [{w,isNu,firma}], periodLabel, title }.
// MUST be called synchronously from a click (it opens the print window first).
async function maDownloadAllPDFs(opts) {
    const { workers, nuCompanies } = maPdfSources(opts && opts.workers);
    if (workers.length === 0 && !Object.keys(nuCompanies).length) { showToast('Keine Mitarbeiter vorhanden', 'error'); return; }
    const monthLabel = opts && opts.periodLabel ? opts.periodLabel : maCurrentPeriodLabel();
    const docTitle = 'Stundennachweis ' + (opts && opts.title ? opts.title : 'Alle Mitarbeiter') + ' - ' + monthLabel;
    const docs = workers.map(w => maBuildWorkerDocData(w, monthLabel));
    Object.entries(nuCompanies).forEach(([firma, map]) => {
        Object.values(map).forEach(w => docs.push(maBuildNuDocData(w, firma, monthLabel)));
    });
    // Pre-open inside the click (popup blockers), fill after rendering.
    const win = window.open('', '_blank');
    if (!win) { showToast('Popup blockiert — bitte Popups erlauben', 'error'); return; }
    win.document.write('<p style="font-family:sans-serif;padding:24px">Stundennachweise werden erstellt…</p>');
    try {
        const html = await hmsWithHeadlessEditor('/order_form.php?doc=worker', async (api) => {
            const sheets = [];
            for (const d of docs) { await api.renderWorker(d); sheets.push(api.sheetHtml()); }
            return api.documentHtml(sheets, docTitle);
        });
        win.document.open();
        win.document.write(html);
        win.document.close();
        let printed = false;
        const doPrint = () => { if (printed) return; printed = true; try { win.focus(); win.print(); } catch (e) {} };
        win.onload = doPrint;
        setTimeout(doPrint, 1500);
    } catch (e) {
        console.error('maDownloadAllPDFs (editor) failed', e);
        win.close();
        showToast('Vorlage konnte nicht geladen werden — Standard-Layout wird verwendet', 'warning');
        maDownloadAllPDFsLegacy(opts);
    }
}

function maDownloadAllPDFsLegacy(opts) {
    const { workers, nuCompanies } = maPdfSources(opts && opts.workers);
    const hasNuWorkers = Object.keys(nuCompanies).length > 0;
    if (workers.length === 0 && !hasNuWorkers) { showToast('Keine Mitarbeiter vorhanden', 'error'); return; }
    const monthLabel = opts && opts.periodLabel ? opts.periodLabel : maCurrentPeriodLabel();
    const primary = _maBrand.primary || '#1e3a5f';
    const accent  = _maBrand.accent  || primary;
    const brandCompany = escapeHtml((typeof CURRENT_TENANT !== 'undefined' && CURRENT_TENANT.name) || 'Unternehmen');
    const brandLogoImg = _maBrandLogo ? `<img class="brand-logo" src="${_maBrandLogo}" alt="Logo">` : '';

    // Build one combined document with page breaks
    const allHtml = workers.map(w => {
        const name = ((w.vorname || '') + ' ' + (w.name || '')).trim() || w.dienst_nr;
        let totalStd = 0;
        let totalPause = 0;
        const rows = w.entries.map((e, i) => {
            const d = e.datum ? new Date(e.datum + 'T00:00:00') : null;
            const dayName = d ? DAYS_SHORT[d.getDay()] : '-';
            const std = maCalcEntryStd(e);
            const pause = wpCalcPause(e.ist_start, e.ist_ende, std, e.pause_minuten ?? 0);
            totalStd += std;
            totalPause += pause;
            return '<tr><td>' + (i+1) + '</td><td>' + wpFormatDate(e.datum) + '</td><td>' + dayName + '</td><td>' + escapeHtml(e.objektname||'-') + '</td>' + maWorkerTimeCellsHtml(e) + '<td>' + (pause>0?pause+' Min':'-') + '</td><td style="text-align:right">' + (std?std.toFixed(2):'-') + '</td></tr>';
        }).join('');
        const oi = maWorkerObjektInfo(w.entries);
        return '<div class="page">' +
            _maHeaderHtml(brandCompany, 'Stundennachweis / Monatsabschluss', monthLabel, brandLogoImg) +
            '<div class="info"><div>Mitarbeiter<strong>' + name + '</strong></div><div>Objekt<strong>' + escapeHtml(oi.objekt) + '</strong></div><div>Anschrift<strong>' + escapeHtml(oi.anschrift) + '</strong></div><div>Zeit<strong>' + escapeHtml(monthLabel || '') + '</strong></div><div>Eins&auml;tze<strong>' + w.entries.length + '</strong></div></div>' +
            '<table><thead><tr><th>#</th><th>Datum</th><th>Tag</th><th>Objekt</th><th>Von</th><th>Bis</th><th>MA-Pause</th><th style="text-align:right">Std</th></tr></thead><tbody>' + rows + '</tbody>' +
            '<tfoot><tr><td colspan="6" style="text-align:right">Gesamt:</td><td>' + (totalPause>0?totalPause+' Min':'-') + '</td><td style="text-align:right">' + totalStd.toFixed(2) + '</td></tr></tfoot></table>' +
            '<div class="summary"><span>Eins&auml;tze: <strong>' + w.entries.length + '</strong></span><span>Gesamtstunden: <strong>' + totalStd.toFixed(2) + ' Std</strong></span></div>' +
            '<div class="legend">' + MA_WORKER_PDF_LEGEND + '</div>' +
            '</div>';
    }).join('');

    // NU worker pages
    let nuHtml = '';
    Object.entries(nuCompanies).forEach(([firma, nuWorkerMap]) => {
        Object.values(nuWorkerMap).forEach(w => {
            const name = ((w.vorname || '') + ' ' + (w.name || '')).trim() || w.personal_nr || ('NU-' + w.nu_worker_id);
            let totalStd = 0;
            let totalPause = 0;
            const rows = w.entries.map((e, i) => {
                const d = e.datum ? new Date(e.datum + 'T00:00:00') : null;
                const dayName = d ? DAYS_SHORT[d.getDay()] : '-';
                const std = e.ist_std_menge ? parseFloat(e.ist_std_menge) : 0;
                const pause = wpCalcPause(e.ist_start, e.ist_ende, std, e.pause_minuten ?? 0);
                totalStd += std;
                totalPause += pause;
                return '<tr><td>' + (i+1) + '</td><td>' + wpFormatDate(e.datum) + '</td><td>' + dayName + '</td><td>' + (e.objektname||'-') + '</td><td>' + (e.position_name||'-') + '</td><td>' + wpFormatTime(e.ist_start) + '</td><td>' + wpFormatTime(e.ist_ende) + '</td><td>' + (pause>0?pause+' Min':'-') + '</td><td style="text-align:right">' + (std?std.toFixed(2):'-') + '</td></tr>';
            }).join('');
            const oi = maWorkerObjektInfo(w.entries);
            nuHtml += '<div class="page">' +
                _maHeaderHtml(brandCompany, 'Stundennachweis / Monatsabschluss &mdash; Nachunternehmer', monthLabel, brandLogoImg) +
                '<div class="info"><div>Mitarbeiter<strong>' + escapeHtml(name) + '</strong></div><div>Firma<strong>' + escapeHtml(firma) + '</strong></div><div>Objekt<strong>' + escapeHtml(oi.objekt) + '</strong></div><div>Anschrift<strong>' + escapeHtml(oi.anschrift) + '</strong></div><div>Zeit<strong>' + escapeHtml(monthLabel || '') + '</strong></div><div>Eins&auml;tze<strong>' + w.entries.length + '</strong></div></div>' +
                '<table><thead><tr><th>#</th><th>Datum</th><th>Tag</th><th>Objekt</th><th>Position</th><th>Von</th><th>Bis</th><th>Pause</th><th style="text-align:right">Std</th></tr></thead><tbody>' + rows + '</tbody>' +
                '<tfoot><tr><td colspan="7" style="text-align:right">Gesamt:</td><td>' + (totalPause>0?totalPause+' Min':'-') + '</td><td style="text-align:right">' + totalStd.toFixed(2) + '</td></tr></tfoot></table>' +
                '<div class="summary"><span>Firma: <strong>' + escapeHtml(firma) + '</strong></span><span>Eins&auml;tze: <strong>' + w.entries.length + '</strong></span><span>Gesamtstunden: <strong>' + totalStd.toFixed(2) + ' Std</strong></span></div>' +
                '</div>';
        });
    });

    const fullHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Stundennachweis Alle Mitarbeiter - ' + monthLabel + '</title><style>' +
        '*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }' +
        'body { font-family:Arial,Helvetica,sans-serif; font-size:10px; color:#1a1a1a; }' +
        '.page { padding:15mm; }' +
        '.header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:18px; padding-bottom:10px; border-bottom:2px solid ' + primary + '; }' +
        '.brand { display:flex; flex-direction:column; gap:2px; }' +
        '.logo-row { margin-bottom:8px; } .logo-row.logo-left { text-align:left; } .logo-row.logo-center { text-align:center; } .logo-row.logo-right { text-align:right; margin-top:-6px; }' +
        '.brand-logo { height:42px; width:auto; max-width:150px; object-fit:contain; }' +
        '.company { font-size:15px; font-weight:700; color:' + primary + '; }' +
        '.company-sub { font-size:8px; color:#666; margin-top:2px; }' +
        '.doc-title { font-size:13px; font-weight:700; text-align:right; color:' + primary + '; }' +
        '.doc-period { font-size:10px; color:#444; text-align:right; }' +
        '.info { background:var(--gray-50); border:1px solid var(--gray-200); border-radius:4px; padding:8px 12px; margin-bottom:14px; display:flex; gap:24px; }' +
        '.info div { font-size:8px; color:#666; } .info strong { font-size:10px; color:#1a1a1a; display:block; }' +
        'table { width:100%; border-collapse:collapse; margin-bottom:14px; }' +
        'th { background:' + accent + '; color:white; padding:5px 6px; text-align:left; font-size:8px; font-weight:600; text-transform:uppercase; letter-spacing:0.3px; }' +
        'td { padding:4px 6px; border-bottom:1px solid var(--gray-200); font-size:9px; }' +
        'tr:nth-child(even) { background:var(--gray-50); }' +
        'tfoot td { font-weight:700; border-top:2px solid ' + primary + '; background:var(--gray-100); }' +
        '.summary { display:flex; justify-content:flex-end; gap:20px; padding:8px 12px; background:' + primary + '; color:white; border-radius:4px; font-size:10px; } .summary strong { font-size:12px; }' +
        '.footer { margin-top:24px; padding-top:8px; border-top:1px solid var(--gray-200); display:flex; justify-content:space-between; font-size:7px; color:#999; }' +
        '.sub { display:block; font-size:7.5px; color:#6b7280; } .legend { margin-top:8px; font-size:7.5px; line-height:1.45; color:#6b7280; }' +
        '@media print { .page { padding:10mm; page-break-after:always; } .page:last-child { page-break-after:auto; } @page { margin:0; size:A4; } }' +
        '</style></head><body>' + allHtml + nuHtml + '</body></html>';

    const win = window.open('', '_blank');
    if (!win) { showToast('Popup blockiert — bitte Popups erlauben', 'error'); return; }
    win.document.write(fullHtml);
    win.document.close();
    win.onload = function() { win.print(); };
}

async function maSendToWorker(dienstNr) {
    const worker = _maPageWorkers[dienstNr];
    if (!worker) { showToast('Mitarbeiter nicht gefunden', 'error'); return; }
    const name = ((worker.vorname || '') + ' ' + (worker.name || '')).trim() || worker.dienst_nr;
    const monthLabel = maCurrentPeriodLabel();

    const ok = await mdConfirm('Stundennachweis <strong>' + escapeHtml(monthLabel) + '</strong> an <strong>' + escapeHtml(name) + '</strong> senden?', 'Stundennachweis senden');
    if (!ok) return;

    // Same renderer + company template as the editor; legacy builder only
    // as a fallback when the editor cannot be loaded.
    let html;
    try {
        html = await hmsWithHeadlessEditor('/order_form.php?doc=worker', async (api) => {
            await api.renderWorker(maBuildWorkerDocData(worker, monthLabel));
            // Archive the exact PDF that is sent (Verlauf in the editor).
            if (api.archive) { try { await api.archive(await api.pdfBlob(), 'senden'); } catch (e) { console.warn('Archiv', e); } }
            return api.documentHtml([api.sheetHtml()], 'Stundennachweis - ' + name + ' - ' + monthLabel);
        });
    } catch (e) {
        console.error('maSendToWorker (editor) failed', e);
        html = maBuildWorkerPDFHtml(worker, monthLabel);
    }
    const dateiName = 'Stundennachweis_' + monthLabel.replace(/\s+/g, '_') + '.html';

    const result = await apiPost(API.worker_messages + '/send-document', {
        dienst_nr: dienstNr,
        nachricht: 'Stundennachweis ' + monthLabel,
        html_content: html,
        datei_name: dateiName
    });

    if (result && result.success) {
        showToast('Stundennachweis an ' + name + ' gesendet');
    } else {
        showToast(result?.error || 'Fehler beim Senden', 'error');
    }
}
