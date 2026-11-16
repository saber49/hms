/* =====================================================================
   pdf_design.js — "Dokument-Design" section (System area)
   ---------------------------------------------------------------------
   A small hub page with two cards:
     1) Bestellungen / Auftragsbestätigung → opens the existing PDF editor
        (order_form.php) in DESIGN mode (?design=1) with built-in sample
        data, so the user can configure company-wide branding
        (colours / logo / layout) without needing a real order.
     2) Mitarbeiter-PDF / Stundennachweis → renders an inline example of
        the worker timesheet PDF using the SAME builder the Monatsabschluss
        page uses (maBuildWorkerPDFHtml in tasks.js), so the preview always
        reflects the current company branding.

   Depends on globals exposed by tasks.js (loaded before this file):
     maLoadBranding(), maBuildWorkerPDFHtml(worker, monthLabel)
   and on CURRENT_TENANT / showToast from the shell.
===================================================================== */

// ---------- MD3 helpers (official <md-dialog> + Material Symbols) ----------
function pdfdIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}
const pdfdSpinner = (size = 24) => `<md-circular-progress indeterminate style="--md-circular-progress-size:${size}px"></md-circular-progress>`;
function openPdfdDialog(title, bodyHtml, footerHtml = '') {
    document.getElementById('pdfdMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'pdfdMdDialog';
    dlg.classList.add('pdfd-context');
    dlg.innerHTML = `
        <div slot="headline">${title}</div>
        <div slot="content" class="pdfd-dialog-content" id="pdfdDialogBody">${bodyHtml}</div>
        ${footerHtml ? `<div slot="actions">${footerHtml}</div>` : ''}`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();
    return dlg;
}
function closePdfdDialog() { document.getElementById('pdfdMdDialog')?.close(); }

let _pdfdLastHtml = null;
let _pdfdOrders = [];
let _pdfdUpload = null;   // { name, mime, size, dataUrl } of the design the user uploaded
let _pdfdDocType = 'order';   // 'order' (Bestellung) | 'worker' (Mitarbeiter) — target for the generated template
let _pdfdDraft = null;        // { type, html } generated but NOT yet adopted (awaiting approval)
const PDFD_MONTHS = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
const PDFD_MAX_UPLOAD_BYTES = 10 * 1024 * 1024;   // 10 MB — matches backend config/ai.php
const PDFD_ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'application/pdf'];

// ── Page render ──────────────────────────────────────────────────────
// One row per document type: where it is used, its current look (colour +
// standard template) and whether the company customised it. The sample
// editor opens from "Bearbeiten"; every change there is saved automatically.
const PDFD_DOCS = [
    { type: 'order', doc: '', icon: 'receipt_long', title: 'Leistungsnachweis',
      what: 'Nachweis der erbrachten Leistungen für den Kunden',
      where: 'Bestellungen › Auftragsblatt' },
    { type: 'ab', doc: 'ab', icon: 'description', title: 'Auftragsbestätigung',
      what: 'Bestätigung des Auftrags an den Kunden',
      where: 'Auftragsbestätigungen' },
    { type: 'worker', doc: 'worker', icon: 'work_history', title: 'Stundennachweis',
      what: 'Monatliche Arbeitszeitaufstellung für Ihre Mitarbeiter',
      where: 'Zeiterfassung (Monatsabschluss)' },
];
const PDFD_DESIGN_NAMES = { klassisch: 'Klassisch', modern: 'Modern', din5008: 'DIN 5008' };

// host = container inside the central Einstellungen page (default: #content).
function renderPdfDesign(host) {
    const c = host || document.getElementById('content');
    if (!c) return;

    c.innerHTML = `
        <div class="pdfd-wrap">
            <p class="pdfx-lead">Hier legen Sie fest, wie Ihre PDF-Dokumente aussehen. Jede Änderung gilt sofort für alle Dokumente dieses Typs.</p>

            <ol class="pdfx-steps">
                <li><span>1</span>Dokument wählen</li>
                <li><span>2</span>Vorlage, Farben, Logo &amp; Texte anpassen</li>
                <li><span>3</span>Fertig — wird automatisch gespeichert</li>
            </ol>

            <div class="pdfx-list">
                ${PDFD_DOCS.map(d => `
                <div class="pdfx-row" role="button" tabindex="0" data-type="${d.type}"
                     onclick="pdfdOpenSampleDesign('${d.doc}')"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();pdfdOpenSampleDesign('${d.doc}');}">
                    <div class="pdfx-icon">${pdfdIcon(d.icon)}</div>
                    <div class="pdfx-main">
                        <div class="pdfx-title">${d.title}</div>
                        <div class="pdfx-what">${d.what}</div>
                        <div class="pdfx-where">${pdfdIcon('place', 'font-size:14px')} Verwendet in: ${d.where}</div>
                    </div>
                    <div class="pdfx-state" data-state-for="${d.type}"><span class="pdfx-chip">…</span></div>
                    <span class="pdfx-btn">${pdfdIcon('edit', 'font-size:18px')} Bearbeiten</span>
                </div>`).join('')}
            </div>

            <p class="pdfx-note">${pdfdIcon('info', 'font-size:16px')} Die Vorlagen zeigen Beispieldaten. Ihre echten Dokumente finden Sie in den jeweiligen Bereichen — dort gibt es auch den Verlauf aller erstellten PDFs.</p>

            <div id="pdfdPreviewHost"></div>
        </div>`;
    pdfdLoadStatus();
}

// Fills the right-hand status of each row from GET /pdf/layouts.
async function pdfdLoadStatus() {
    let layouts = {};
    try { layouts = ((await apiGet(API_BASE + '/pdf/layouts')) || {}).layouts || {}; } catch (e) { layouts = {}; }
    PDFD_DOCS.forEach(d => {
        const host = document.querySelector(`.pdfx-state[data-state-for="${d.type}"]`);
        if (!host) return;
        const l = layouts[d.type];
        const color = (l && l.branding && /^#[0-9a-f]{6}$/i.test(l.branding.primary_color || '')) ? l.branding.primary_color : '';
        const row = host.closest('.pdfx-row');
        if (row && color) row.style.setProperty('--pdfx-c', color);
        const design = PDFD_DESIGN_NAMES[(l && l.design) || 'klassisch'] || 'Klassisch';
        const when = l && l.updated_at ? new Date(l.updated_at) : null;
        host.innerHTML = l
            ? `<span class="pdfx-chip is-custom">${pdfdIcon('check_circle', 'font-size:15px')} Angepasst${when && !isNaN(when) ? ' am ' + when.toLocaleDateString('de-DE') : ''}</span>
               <span class="pdfx-look"><i style="background:${color || 'var(--pdfx-c)'}"></i>${design}</span>`
            : `<span class="pdfx-chip">Standard</span><span class="pdfx-look"><i></i>${design}</span>`;
    });
}

// ── Card 1: pick a REAL order to edit (or open the sample design) ────
// NOTE: the real-order picker is temporarily disabled — Card 1 now opens the
// sample design directly (pdfdOpenSampleDesign). This function is kept so the
// picker can be re-enabled later by restoring the card's onclick handler.
async function pdfdOpenOrderPicker() {
    const body = `
        <div class="pdfd-picker">
            <div class="pdfd-picker-search">
                <md-outlined-text-field class="pdfd-search-md" id="pdfdOrderSearch" autocomplete="off"
                       placeholder="Suche nach Bestell-Nr., Kunde oder Objekt…"
                       oninput="pdfdFilterOrders(this.value)">
                    <md-icon slot="leading-icon">search</md-icon>
                </md-outlined-text-field>
            </div>
            <div class="pdfd-picker-list" id="pdfdOrderList">
                <div class="pdfd-picker-loading">${pdfdSpinner(24)} Lade Bestellungen&hellip;</div>
            </div>
        </div>`;
    const footer = `
        <md-text-button onclick="closePdfdDialog()">Abbrechen</md-text-button>
        <md-outlined-button onclick="pdfdOpenSampleDesign()" title="Editor mit Beispieldaten öffnen">
            <md-icon slot="icon">science</md-icon>Mit Beispieldaten
        </md-outlined-button>`;
    openPdfdDialog('Bestellung zum Bearbeiten wählen', body, footer);

    if (typeof API === 'undefined' || typeof apiGet !== 'function') {
        const host = document.getElementById('pdfdOrderList');
        if (host) host.innerHTML = `<div class="pdfd-picker-empty">${pdfdIcon('warning')}<p>Bestellungen konnten nicht geladen werden.</p></div>`;
        return;
    }
    let orders = [];
    try { orders = await apiGet(API.orders); } catch (e) { orders = []; }
    _pdfdOrders = Array.isArray(orders) ? orders : [];
    pdfdRenderOrderList(_pdfdOrders);
}

function pdfdRenderOrderList(list) {
    const host = document.getElementById('pdfdOrderList');
    if (!host) return;
    if (!list || !list.length) {
        host.innerHTML = `<div class="pdfd-picker-empty">${pdfdIcon('inbox')}<p>Keine Bestellungen gefunden</p></div>`;
        return;
    }
    const esc = (typeof escapeHtml === 'function') ? escapeHtml : (s) => String(s == null ? '' : s);
    host.innerHTML = list.map(o => {
        const period = ((o.monat ? (PDFD_MONTHS[o.monat] || o.monat) + ' ' : '') + (o.jahr || '')).trim();
        const status = o.status || '';
        const late = o.ueberfaellig ? '<span class="pdfd-ord-status ueberfaellig">überfällig</span>' : '';
        return `
            <button type="button" class="pdfd-picker-item" onclick="pdfdPickOrder('${esc(o.bestell_id)}')">
                <span class="pdfd-picker-item-id">${pdfdIcon('receipt_long', 'font-size:12px;vertical-align:-2px')} ${esc(o.bestell_id)}</span>
                <span class="pdfd-picker-item-main">
                    <span class="pdfd-picker-item-kunde">${esc(o.kundenname || o.kunden_id || '–')}</span>
                    <span class="pdfd-picker-item-obj">${esc(o.objektname || o.obj_id || '')}</span>
                </span>
                <span class="pdfd-picker-item-meta">
                    <span class="pdfd-picker-period">${esc(period || '–')}</span>
                    ${status ? `<span class="pdfd-ord-status ${esc(status)}">${esc(status)}</span>` : ''}
                    ${late}
                </span>
                <span class="pdfd-picker-item-arrow">${pdfdIcon('chevron_right', 'font-size:15px')}</span>
            </button>`;
    }).join('');
}

function pdfdFilterOrders(query) {
    const q = (query || '').toLowerCase().trim();
    if (!q) { pdfdRenderOrderList(_pdfdOrders); return; }
    const filtered = _pdfdOrders.filter(o =>
        [o.bestell_id, o.kundenname, o.kunden_id, o.objektname, o.obj_id]
            .filter(Boolean)
            .some(v => String(v).toLowerCase().includes(q))
    );
    pdfdRenderOrderList(filtered);
}

function pdfdPickOrder(bestellId) {
    closePdfdDialog();
    if (typeof openOrderForm === 'function') {
        openOrderForm(bestellId);   // window.open('/order_form.php?id=…')
    } else {
        window.open('/order_form.php?id=' + encodeURIComponent(bestellId), '_blank');
    }
}

function pdfdOpenSampleDesign(doc) {
    closePdfdDialog();
    const q = (doc === 'ab' || doc === 'worker') ? '&doc=' + doc : '';
    const fn = (typeof openOrderFormModal === 'function') ? openOrderFormModal
        : (typeof openPdfEditorModal === 'function' ? openPdfEditorModal : null);
    if (fn) {
        fn('/order_form.php?design=1' + q + '&embed=1');
        document.getElementById('orFormModal')?.addEventListener('close', () => pdfdLoadStatus(), { once: true });
    } else {
        window.open('/order_form.php?design=1' + q, '_blank');
    }
}

// ── Card 2: inline example of the worker timesheet PDF ───────────────
async function pdfdPreviewWorker() {
    const host = document.getElementById('pdfdPreviewHost');
    if (!host) return;

    // Skeleton with loading state
    host.innerHTML = `
        <div class="pdfd-preview">
            <div class="pdfd-preview-bar">
                <h3>${pdfdIcon('work_history', 'font-size:15px;vertical-align:-2px')} Beispiel: Stundennachweis</h3>
                <div class="pdfd-preview-actions">
                    <md-icon-button title="Schließen" onclick="pdfdClosePreview()"><md-icon>close</md-icon></md-icon-button>
                </div>
            </div>
            <div class="pdfd-preview-loading">${pdfdSpinner(24)} Vorschau wird erstellt&hellip;</div>
        </div>`;
    host.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    // Pull the latest company branding (colours + logo) so the example
    // matches exactly what workers receive.
    try { if (typeof maLoadBranding === 'function') await maLoadBranding(); } catch (e) { /* keep defaults */ }

    if (typeof maBuildWorkerPDFHtml !== 'function') {
        host.innerHTML = `
            <div class="pdfd-preview">
                <div class="pdfd-preview-bar">
                    <h3>${pdfdIcon('work_history', 'font-size:15px;vertical-align:-2px')} Beispiel: Stundennachweis</h3>
                    <div class="pdfd-preview-actions">
                        <md-icon-button title="Schließen" onclick="pdfdClosePreview()"><md-icon>close</md-icon></md-icon-button>
                    </div>
                </div>
                <div class="pdfd-preview-loading">Vorschau nicht verfügbar &mdash; bitte Seite neu laden.</div>
            </div>`;
        return;
    }

    const html = maBuildWorkerPDFHtml(pdfdSampleWorker(), 'Juni 2026');
    _pdfdLastHtml = html;

    host.innerHTML = `
        <div class="pdfd-preview">
            <div class="pdfd-preview-bar">
                <h3>${pdfdIcon('work_history', 'font-size:15px;vertical-align:-2px')} Beispiel: Stundennachweis</h3>
                <div class="pdfd-preview-actions">
                    <md-outlined-button onclick="pdfdPrintWorker()"><md-icon slot="icon">print</md-icon>Drucken / PDF</md-outlined-button>
                    <md-icon-button title="Schließen" onclick="pdfdClosePreview()"><md-icon>close</md-icon></md-icon-button>
                </div>
            </div>
            <div class="pdfd-preview-stage">
                <iframe class="pdfd-preview-frame" id="pdfdPreviewFrame" title="Stundennachweis Beispiel"></iframe>
            </div>
        </div>`;

    const frame = document.getElementById('pdfdPreviewFrame');
    if (frame) frame.srcdoc = html;
}

function pdfdPrintWorker() {
    if (!_pdfdLastHtml) return;
    const win = window.open('', '_blank');
    if (!win) {
        if (typeof showToast === 'function') showToast('Popup blockiert — bitte Popups erlauben', 'error');
        return;
    }
    win.document.write(_pdfdLastHtml);
    win.document.close();
    win.onload = function () { win.print(); };
}

function pdfdClosePreview() {
    const host = document.getElementById('pdfdPreviewHost');
    if (host) host.innerHTML = '';
}

// ── Sample data for the worker preview ───────────────────────────────
// Shape mirrors what Monatsabschluss feeds maBuildWorkerPDFHtml():
// { vorname, name, dienst_nr, bereich, entries:[{datum,ist_start,ist_ende,pause_minuten,ist_std,objektname}] }
function pdfdSampleWorker() {
    return {
        vorname: 'Max',
        name: 'Mustermann',
        dienst_nr: '1042',
        bereich: 'Sicherheitsdienst',
        entries: [
            { datum: '2026-06-02', ist_start: '06:00', ist_ende: '14:30', pause_minuten: 30, ist_std: 8.0, objektname: 'Bürozentrum Nord' },
            { datum: '2026-06-03', ist_start: '06:00', ist_ende: '14:30', pause_minuten: 30, ist_std: 8.0, objektname: 'Bürozentrum Nord' },
            { datum: '2026-06-05', ist_start: '14:00', ist_ende: '22:00', pause_minuten: 30, ist_std: 7.5, objektname: 'Logistikzentrum West' },
            { datum: '2026-06-08', ist_start: '22:00', ist_ende: '06:00', pause_minuten: 45, ist_std: 7.25, objektname: 'Logistikzentrum West' },
            { datum: '2026-06-09', ist_start: '06:00', ist_ende: '14:30', pause_minuten: 30, ist_std: 8.0, objektname: 'Bürozentrum Nord' },
            { datum: '2026-06-12', ist_start: '06:00', ist_ende: '12:00', pause_minuten: 15, ist_std: 5.75, objektname: 'Messe Halle 4' },
        ],
    };
}

// ── Card 3: upload the company's own design → KI extracts branding ───
function pdfdOpenUpload() {
    _pdfdUpload = null;
    _pdfdDocType = 'order';
    const body = `
        <div class="pdfd-up">
            <div class="pdfd-doctype">
                <div class="pdfd-doctype-label">Für welches Dokument?</div>
                <div class="pdfd-doctype-opts">
                    <label class="pdfd-doctype-opt is-active" id="pdfdTypeOrder" onclick="pdfdSetDocType('order')">
                        ${pdfdIcon('receipt_long')}
                        <span>Bestellung-PDF</span>
                        <small>Leistungsnachweis / Einsatzplan</small>
                    </label>
                    <label class="pdfd-doctype-opt" id="pdfdTypeWorker" onclick="pdfdSetDocType('worker')">
                        ${pdfdIcon('work_history')}
                        <span>Mitarbeiter-PDF</span>
                        <small>Stundennachweis / Monatsabschluss</small>
                    </label>
                </div>
            </div>
            <div class="pdfd-up-zone" id="pdfdUpZone"
                 onclick="document.getElementById('pdfdUpInput').click()"
                 ondragover="event.preventDefault(); this.classList.add('dragover');"
                 ondragleave="this.classList.remove('dragover');"
                 ondrop="pdfdOnDrop(event)">
                ${pdfdIcon('cloud_upload')}
                <div class="pdfd-up-title">Datei hierher ziehen oder klicken</div>
                <div class="pdfd-up-hint">Bild (PNG, JPG, WEBP) oder PDF &middot; max. 10 MB</div>
            </div>
            <input type="file" id="pdfdUpInput"
                   accept="image/png,image/jpeg,image/jpg,image/webp,application/pdf"
                   style="display:none" onchange="pdfdOnFile(this)">
            <div class="pdfd-up-preview" id="pdfdUpPreview" style="display:none"></div>
            <div class="pdfd-up-status" id="pdfdUploadStatus"></div>
            <p class="pdfd-up-note">
                ${pdfdIcon('info', 'font-size:13px;vertical-align:-2px')}
                <strong>Nur Farben:</strong> übernimmt Farben, Schrift &amp; Logo-Position auf das Standard-Layout.
                <strong>Design generieren:</strong> die KI baut ein vollständiges Layout nach, das Ihrem Dokument ähnelt.
            </p>
        </div>`;
    const footer = `
        <md-text-button onclick="closePdfdDialog()">Abbrechen</md-text-button>
        <md-outlined-button id="pdfdAnalyzeBtn" disabled onclick="pdfdAnalyzeDesign()">
            <md-icon slot="icon">palette</md-icon>Nur Farben übernehmen
        </md-outlined-button>
        <md-filled-button id="pdfdGenerateBtn" disabled onclick="pdfdGenerateTemplate()">
            <md-icon slot="icon">auto_awesome</md-icon>Design generieren (KI)
        </md-filled-button>`;
    openPdfdDialog('Eigenes Design hochladen', body, footer);
}

function pdfdOnDrop(e) {
    e.preventDefault();
    const zone = document.getElementById('pdfdUpZone');
    if (zone) zone.classList.remove('dragover');
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) pdfdHandleFile(f);
}

function pdfdOnFile(input) {
    const f = input.files && input.files[0];
    if (f) pdfdHandleFile(f);
}

function pdfdHandleFile(file) {
    if (!PDFD_ALLOWED_MIME.includes(file.type)) {
        if (typeof showToast === 'function') showToast('Nur Bild (PNG/JPG/WEBP) oder PDF erlaubt', 'error');
        return;
    }
    if (file.size > PDFD_MAX_UPLOAD_BYTES) {
        if (typeof showToast === 'function') showToast('Datei zu groß — maximal 10 MB', 'error');
        return;
    }
    const reader = new FileReader();
    reader.onload = (e) => {
        _pdfdUpload = { name: file.name, mime: file.type, size: file.size, dataUrl: e.target.result };
        pdfdRenderUploadPreview();
    };
    reader.onerror = () => {
        if (typeof showToast === 'function') showToast('Datei konnte nicht gelesen werden', 'error');
    };
    reader.readAsDataURL(file);
}

function pdfdRenderUploadPreview() {
    const host = document.getElementById('pdfdUpPreview');
    if (!host || !_pdfdUpload) return;
    const esc = (typeof escapeHtml === 'function') ? escapeHtml : (s) => String(s == null ? '' : s);
    const isImg = _pdfdUpload.mime.startsWith('image/');
    const thumb = isImg
        ? `<img src="${_pdfdUpload.dataUrl}" alt="">`
        : `<div class="pdfd-up-pdf">${pdfdIcon('picture_as_pdf')}</div>`;
    const kb = Math.round(_pdfdUpload.size / 1024);
    host.style.display = '';
    host.innerHTML = `
        <div class="pdfd-up-file">
            <div class="pdfd-up-thumb">${thumb}</div>
            <div class="pdfd-up-info">
                <strong>${esc(_pdfdUpload.name)}</strong>
                <span>${kb} KB</span>
            </div>
            <md-icon-button class="pdfd-up-remove" title="Entfernen" onclick="pdfdClearUpload()"><md-icon slot="icon">close</md-icon></md-icon-button>
        </div>`;
    const btn = document.getElementById('pdfdAnalyzeBtn');
    if (btn) btn.disabled = false;
    const gen = document.getElementById('pdfdGenerateBtn');
    if (gen) gen.disabled = false;
    const zone = document.getElementById('pdfdUpZone');
    if (zone) zone.classList.add('compact');
    const status = document.getElementById('pdfdUploadStatus');
    if (status) status.textContent = '';
}

function pdfdClearUpload() {
    _pdfdUpload = null;
    const host = document.getElementById('pdfdUpPreview');
    if (host) { host.style.display = 'none'; host.innerHTML = ''; }
    const btn = document.getElementById('pdfdAnalyzeBtn');
    if (btn) btn.disabled = true;
    const gen = document.getElementById('pdfdGenerateBtn');
    if (gen) gen.disabled = true;
    const zone = document.getElementById('pdfdUpZone');
    if (zone) zone.classList.remove('compact');
    const inp = document.getElementById('pdfdUpInput');
    if (inp) inp.value = '';
    const status = document.getElementById('pdfdUploadStatus');
    if (status) status.textContent = '';
}

// Send the uploaded design to the multimodal AI (POST /ai/chat) and ask it
// to return ONLY a JSON branding object. We then apply it company-wide via
// PUT /dashboard/settings — the same setting the editor + worker PDFs read.
async function pdfdAnalyzeDesign() {
    if (!_pdfdUpload) {
        if (typeof showToast === 'function') showToast('Bitte zuerst eine Datei wählen', 'warning');
        return;
    }
    const btn = document.getElementById('pdfdAnalyzeBtn');
    const status = document.getElementById('pdfdUploadStatus');
    if (btn) { btn.disabled = true; btn.innerHTML = `${pdfdSpinner(14)} Analysiere…`; }
    if (status) { status.className = 'pdfd-up-status is-busy'; status.textContent = 'Die KI analysiert Ihr Design — einen Moment…'; }

    const prompt = [
        'Du bist ein Branding-Analyst. Analysiere das angehängte Dokument (Briefkopf, Logo, Layout, Farben)',
        'und antworte AUSSCHLIESSLICH mit einem JSON-Objekt — ohne Erklärung, ohne Markdown — exakt in diesem Format:',
        '{"primary_color":"#RRGGBB","accent_color":"#RRGGBB","font_category":"serif","logo_position":"left"}',
        'Regeln:',
        '- primary_color = dominante Markenfarbe (Überschriften, Balken, Linien).',
        '- accent_color = sekundäre Akzentfarbe (Links, Hervorhebungen). Falls keine zweite Farbe erkennbar, identisch zu primary_color.',
        '- font_category = genau einer von: "serif", "sans-serif", "monospace".',
        '- logo_position = Position des Logos im Kopfbereich: "left", "center" oder "right".',
        '- Farben als 6-stelliger Hex-Code mit führendem #.',
    ].join('\n');

    try {
        const resp = await fetch(API_BASE + '/ai/chat', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + getAuthToken(),
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                message: prompt,
                attachments: [{ name: _pdfdUpload.name, mime: _pdfdUpload.mime, dataUrl: _pdfdUpload.dataUrl }],
            }),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) throw new Error(data.message || data.error || ('HTTP ' + resp.status));

        const parsed = pdfdParseBranding(data.reply);
        if (!parsed) throw new Error('Die KI-Antwort konnte nicht ausgewertet werden. Bitte erneut versuchen.');

        const applied = await pdfdApplyBranding(parsed);
        if (!applied) throw new Error('Aus dem Design ließen sich keine gültigen Farben erkennen.');

        closePdfdDialog();
        if (typeof showToast === 'function') showToast('Design übernommen — Farben, Schrift & Logo-Position aktualisiert', 'success');
        pdfdPreviewWorker();
    } catch (e) {
        if (status) { status.className = 'pdfd-up-status is-error'; status.textContent = 'Fehler: ' + (e.message || 'Analyse fehlgeschlagen'); }
        if (btn) { btn.disabled = false; btn.innerHTML = '<md-icon slot="icon">palette</md-icon>Nur Farben übernehmen'; }
    }
}

// Pull the first JSON object out of the model's reply (it may wrap it in
// prose or a ```json fence despite instructions).
function pdfdParseBranding(text) {
    if (!text || typeof text !== 'string') return null;
    let raw = text.trim();
    const fence = raw.match(/```(?:json)?\s*([\s\S]*?)```/i);
    if (fence) raw = fence[1].trim();
    const m = raw.match(/\{[\s\S]*\}/);
    if (!m) return null;
    try { return JSON.parse(m[0]); } catch (e) { return null; }
}

// Validate + merge the extracted branding onto the current tenant branding
// and persist it. Returns true if at least one valid colour was applied.
async function pdfdApplyBranding(b) {
    const FONT_MAP = {
        'serif':      'Georgia, serif',
        'sans-serif': "'Segoe UI', system-ui, sans-serif",
        'sans':       "'Segoe UI', system-ui, sans-serif",
        'monospace':  "'Courier New', monospace",
        'mono':       "'Courier New', monospace",
    };
    const hex = (v) => (typeof v === 'string' && /^#[0-9a-fA-F]{6}$/.test(v.trim())) ? v.trim() : null;

    let current = {};
    try {
        const c = await apiGet(API_BASE + '/dashboard/company');
        if (c && c.pdf_branding && typeof c.pdf_branding === 'object') current = c.pdf_branding;
    } catch (e) { /* start from empty */ }

    const branding = Object.assign({}, current);
    let appliedColour = false;

    const p = hex(b.primary_color); if (p) { branding.primary_color = p; appliedColour = true; }
    const a = hex(b.accent_color);  if (a) { branding.accent_color  = a; appliedColour = true; }
    const fam = FONT_MAP[String(b.font_category || '').toLowerCase()];
    if (fam) branding.font_family = fam;
    const pos = String(b.logo_position || '').toLowerCase();
    if (['left', 'center', 'right'].includes(pos)) branding.logo_position = pos;

    if (!appliedColour) return false;
    if (!branding.accent_color) branding.accent_color = branding.primary_color;

    const result = await apiPut(API_BASE + '/dashboard/settings', { pdf_branding: branding });
    return !!(result && (result.success || result.tenant));
}

// ── Card 3b: FULL template generation (AI rebuilds the layout) ───────
function pdfdEsc(s) {
    if (typeof escapeHtml === 'function') return escapeHtml(s);
    return String(s == null ? '' : s).replace(/[&<>"]/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

// Highlight the chosen document type (Bestellung vs Mitarbeiter).
function pdfdSetDocType(type) {
    _pdfdDocType = (type === 'worker') ? 'worker' : 'order';
    const o = document.getElementById('pdfdTypeOrder');
    const w = document.getElementById('pdfdTypeWorker');
    if (o) o.classList.toggle('is-active', _pdfdDocType === 'order');
    if (w) w.classList.toggle('is-active', _pdfdDocType === 'worker');
}

// Ask the backend to reproduce the uploaded design as a full HTML template.
// IMPORTANT: the result is NOT saved — it is shown for approval first. The
// tenant adopts it explicitly via pdfdAdoptTemplate() ("Übernehmen").
async function pdfdGenerateTemplate() {
    if (!_pdfdUpload) {
        if (typeof showToast === 'function') showToast('Bitte zuerst eine Datei wählen', 'warning');
        return;
    }
    const type = _pdfdDocType;
    const btn = document.getElementById('pdfdGenerateBtn');
    const altBtn = document.getElementById('pdfdAnalyzeBtn');
    const status = document.getElementById('pdfdUploadStatus');
    if (btn) { btn.disabled = true; btn.innerHTML = `${pdfdSpinner(14)} Generiere…`; }
    if (altBtn) altBtn.disabled = true;
    if (status) { status.className = 'pdfd-up-status is-busy'; status.textContent = 'Die KI erstellt ein passendes Design — das kann ~20 Sekunden dauern…'; }

    try {
        const resp = await fetch(API_BASE + '/ai/generate-template', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + getAuthToken(),
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                type: type,
                attachments: [{ name: _pdfdUpload.name, mime: _pdfdUpload.mime, dataUrl: _pdfdUpload.dataUrl }],
            }),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) throw new Error(data.message || data.error || ('HTTP ' + resp.status));
        if (!data.html) throw new Error('Es wurde kein Design zurückgegeben.');

        // Hold the draft for the approval step (not yet saved on the server).
        _pdfdDraft = { type: data.type || type, html: data.html };
        closePdfdDialog();
        await pdfdPreviewCustomTemplate(_pdfdDraft.html, _pdfdDraft.type);
    } catch (e) {
        if (status) { status.className = 'pdfd-up-status is-error'; status.textContent = 'Fehler: ' + (e.message || 'Generierung fehlgeschlagen'); }
        if (btn) { btn.disabled = false; btn.innerHTML = '<md-icon slot="icon">auto_awesome</md-icon>Design generieren (KI)'; }
        if (altBtn) altBtn.disabled = false;
    }
}

// Load the tenant's real company data + logo for a realistic preview.
async function pdfdLoadCompany() {
    let c = {};
    try { c = await apiGet(API_BASE + '/dashboard/company') || {}; } catch (e) { c = {}; }
    const name = c.name || 'Firma';
    const info = [
        c.company_address,
        ((c.company_zip || '') + ' ' + (c.company_city || '')).trim(),
        c.company_phone ? ('Tel: ' + c.company_phone) : '',
        c.company_email,
    ].filter(Boolean).join(' | ');
    let logoDataUrl = '';
    if (c.logo_path && typeof maFetchLogoDataUri === 'function') {
        try { logoDataUrl = await maFetchLogoDataUri(c.logo_path) || ''; } catch (e) { logoDataUrl = ''; }
    }
    return { name, info, logoDataUrl, footer: [name, info].filter(Boolean).join(' · ') };
}

// Sample order data used to fill the generated template in the preview.
function pdfdSampleOrder() {
    return {
        recipient:     'Muster Kunde GmbH',
        auftrags_nr:   'B-202606-0001',
        auftragsdatum: new Date().toLocaleDateString('de-DE'),
        kunde:         'Muster Kunde GmbH',
        objekt:        'Bürozentrum Nord',
        monat:         'Juni 2026',
        arbeitszeit:   '08:00 – 16:00',
        nu:            '–',
        tage: [
            { datum: '01.06.2026', wochentag: 'Montag',     von: '08:00', bis: '16:00', std: '7,50', bemerkung: '' },
            { datum: '02.06.2026', wochentag: 'Dienstag',   von: '08:00', bis: '16:00', std: '7,50', bemerkung: '' },
            { datum: '03.06.2026', wochentag: 'Mittwoch',   von: '08:00', bis: '16:00', std: '7,50', bemerkung: '' },
        ],
    };
}

// Build the COMPLETE Einsatztage table (header + rows) with self-contained
// inline styling so it renders correctly regardless of the AI's layout.
function pdfdEinsatzTable(order) {
    const head = ['Nr.', 'Datum', 'Wochentag', 'Zeit von', 'Zeit bis', 'Soll-Std', 'Bemerkung'];
    const thStyle = 'text-align:left;padding:9px 10px;font-size:11px;font-weight:600;color:var(--gray-500);text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #cbd5e1;';
    const tdStyle = 'padding:8px 10px;font-size:12.5px;color:var(--gray-800);border-bottom:1px solid #eef2f7;';
    const thead = '<tr>' + head.map(h => `<th style="${thStyle}">${h}</th>`).join('') + '</tr>';
    const rows = (order.tage || []).map((t, i) => [
        String(i + 1), t.datum, t.wochentag, t.von, t.bis, t.std, t.bemerkung || '',
    ]);
    const body = rows.length
        ? rows.map(r => '<tr>' + r.map((c, i) => `<td style="${tdStyle}${i === 0 ? 'width:42px;color:var(--gray-500);' : ''}">${pdfdEsc(c)}</td>`).join('') + '</tr>').join('')
        : '<tr><td colspan="7" style="padding:18px;text-align:center;color:var(--gray-400)">Keine Einsatztage</td></tr>';
    return `<table style="width:100%;border-collapse:collapse;margin-top:6px"><thead>${thead}</thead><tbody>${body}</tbody></table>`;
}

// Build the COMPLETE Stunden table (header + rows) for the worker PDF, with
// self-contained inline styling. Returns { html, total }.
function pdfdStundenTable(worker) {
    const head = ['Nr.', 'Datum', 'Tag', 'Objekt', 'Von', 'Bis', 'Pause', 'Std'];
    const thStyle = 'text-align:left;padding:9px 10px;font-size:11px;font-weight:600;color:var(--gray-500);text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #cbd5e1;';
    const tdStyle = 'padding:8px 10px;font-size:12.5px;color:var(--gray-800);border-bottom:1px solid #eef2f7;';
    const thead = '<tr>' + head.map(h => `<th style="${thStyle}">${h}</th>`).join('') + '</tr>';
    let total = 0;
    const rows = (worker.entries || []).map((e, i) => {
        const d = e.datum ? new Date(e.datum + 'T00:00:00') : null;
        const day = (d && typeof DAYS_SHORT !== 'undefined') ? DAYS_SHORT[d.getDay()] : '';
        const std = parseFloat(e.ist_std || 0) || 0;
        total += std;
        const datum = (typeof wpFormatDate === 'function') ? wpFormatDate(e.datum) : (e.datum || '');
        const von = (typeof wpFormatTime === 'function') ? wpFormatTime(e.ist_start) : (e.ist_start || '');
        const bis = (typeof wpFormatTime === 'function') ? wpFormatTime(e.ist_ende) : (e.ist_ende || '');
        const pause = e.pause_minuten ? (e.pause_minuten + ' Min') : '–';
        return [String(i + 1), datum, day, e.objektname || '–', von, bis, pause, std ? std.toFixed(2) : '–'];
    });
    const body = rows.length
        ? rows.map(r => '<tr>' + r.map((c, i) => `<td style="${tdStyle}${i === 0 ? 'width:42px;color:var(--gray-500);' : ''}${i === 7 ? 'text-align:right;' : ''}">${pdfdEsc(c)}</td>`).join('') + '</tr>').join('')
        : '<tr><td colspan="8" style="padding:18px;text-align:center;color:var(--gray-400)">Keine Einsätze</td></tr>';
    const html = `<table style="width:100%;border-collapse:collapse;margin-top:6px"><thead>${thead}</thead><tbody>${body}</tbody></table>`;
    return { html, total: total.toFixed(2) };
}

// Replace the fixed placeholders in the generated template with real values.
// `type` selects the placeholder set ('order' | 'worker'); `data` is the
// matching sample (pdfdSampleOrder / pdfdSampleWorker).
function pdfdFillTemplate(tpl, data, company, type) {
    const logoImg = company.logoDataUrl
        ? `<img src="${company.logoDataUrl}" alt="Logo" style="max-width:120px;max-height:70px;object-fit:contain">`
        : '';

    let map;
    if (type === 'worker') {
        const st = pdfdStundenTable(data);
        const name = ((data.vorname || '') + ' ' + (data.name || '')).trim() || (data.dienst_nr || '');
        map = {
            company_name:     pdfdEsc(company.name),
            company_info:     pdfdEsc(company.info),
            logo:             logoImg,
            doc_title:        'STUNDENNACHWEIS / MONATSABSCHLUSS',
            mitarbeiter_name: pdfdEsc(name),
            dienst_nr:        pdfdEsc(data.dienst_nr || ''),
            bereich:          pdfdEsc(data.bereich || '–'),
            einsaetze:        pdfdEsc(String((data.entries || []).length)),
            monat:            pdfdEsc(data.monat || 'Juni 2026'),
            stunden_table:    st.html,
            summe_std:        pdfdEsc(st.total + ' Std'),
            footer:           pdfdEsc(company.footer),
        };
    } else {
        const einsatzTable = pdfdEinsatzTable(data);
        map = {
            company_name:  pdfdEsc(company.name),
            company_info:  pdfdEsc(company.info),
            logo:          logoImg,
            doc_title:     'LEISTUNGSNACHWEIS / EINSATZPLAN',
            recipient:     pdfdEsc(data.recipient),
            auftragsdatum: pdfdEsc(data.auftragsdatum),
            auftrags_nr:   pdfdEsc(data.auftrags_nr),
            kunde:         pdfdEsc(data.kunde),
            objekt:        pdfdEsc(data.objekt),
            monat:         pdfdEsc(data.monat),
            arbeitszeit:   pdfdEsc(data.arbeitszeit),
            nu:            pdfdEsc(data.nu),
            einsatz_table: einsatzTable,
            einsatz_rows:  einsatzTable, // legacy templates: drop a full table in
            footer:        pdfdEsc(company.footer),
        };
    }

    let out = tpl;
    Object.keys(map).forEach((k) => {
        out = out.replace(new RegExp('\\{\\{\\s*' + k + '\\s*\\}\\}', 'g'), map[k]);
    });
    return out;
}

// Render the generated DRAFT template (filled + sanitised) into the preview
// host, with an explicit approval bar (Übernehmen / Verwerfen). Nothing is
// saved on the server until the user clicks "Übernehmen".
async function pdfdPreviewCustomTemplate(tplHtml, type) {
    const host = document.getElementById('pdfdPreviewHost');
    if (!host) return;
    type = (type === 'worker') ? 'worker' : 'order';

    const company = await pdfdLoadCompany();
    const sample = (type === 'worker') ? pdfdSampleWorker() : pdfdSampleOrder();
    let filled = pdfdFillTemplate(tplHtml, sample, company, type);

    // Client-side sanitisation (defence in depth) before injecting.
    if (window.DOMPurify) {
        filled = DOMPurify.sanitize(filled, { ADD_TAGS: ['style'], ADD_ATTR: ['style'] });
    }
    const doc = `<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;background:var(--white)">${filled}</body></html>`;
    _pdfdLastHtml = doc;

    const typeLabel = (type === 'worker') ? 'Mitarbeiter-PDF' : 'Bestellung-PDF';
    host.innerHTML = `
        <div class="pdfd-preview">
            <div class="pdfd-preview-bar">
                <h3>${pdfdIcon('auto_awesome', 'font-size:15px;vertical-align:-2px')} Vorschau: ${typeLabel} &middot; Beispieldaten</h3>
                <div class="pdfd-preview-actions">
                    <md-outlined-button onclick="pdfdPrintWorker()"><md-icon slot="icon">print</md-icon>Drucken</md-outlined-button>
                    <md-outlined-button onclick="pdfdDiscardDraft()"><md-icon slot="icon">close</md-icon>Verwerfen</md-outlined-button>
                    <md-filled-button onclick="pdfdAdoptTemplate()"><md-icon slot="icon">check</md-icon>Übernehmen</md-filled-button>
                </div>
            </div>
            <div class="pdfd-preview-note">
                ${pdfdIcon('info', 'font-size:13px;vertical-align:-2px')}
                Dies ist nur eine Vorschau. Erst mit <strong>„Übernehmen“</strong> wird das Design unternehmensweit aktiviert.
            </div>
            <div class="pdfd-preview-stage">
                <iframe class="pdfd-preview-frame" id="pdfdPreviewFrame" title="Design Vorschau"></iframe>
            </div>
        </div>`;
    const frame = document.getElementById('pdfdPreviewFrame');
    if (frame) frame.srcdoc = doc;
    host.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Approve the current draft → persist it company-wide for its document type.
async function pdfdAdoptTemplate() {
    if (!_pdfdDraft || !_pdfdDraft.html) {
        if (typeof showToast === 'function') showToast('Kein Entwurf zum Übernehmen', 'warning');
        return;
    }
    try {
        const resp = await fetch(API_BASE + '/ai/template', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + getAuthToken(),
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ type: _pdfdDraft.type, html: _pdfdDraft.html }),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || !data.success) throw new Error(data.message || data.error || ('HTTP ' + resp.status));
        const label = (_pdfdDraft.type === 'worker') ? 'Mitarbeiter-PDF' : 'Bestellung-PDF';
        if (typeof showToast === 'function') showToast('Design übernommen — aktiv für ' + label, 'success');
        _pdfdDraft = null;
        pdfdClosePreview();
    } catch (e) {
        if (typeof showToast === 'function') showToast('Konnte nicht übernommen werden: ' + (e.message || ''), 'error');
    }
}

// Discard the draft without saving (no server call — nothing was persisted).
function pdfdDiscardDraft() {
    _pdfdDraft = null;
    pdfdClosePreview();
    if (typeof showToast === 'function') showToast('Entwurf verworfen', 'success');
}

// Remove an ALREADY-ADOPTED template for a type (revert to standard layout).
async function pdfdClearTemplate(type) {
    type = (type === 'worker') ? 'worker' : 'order';
    const label = (type === 'worker') ? 'Mitarbeiter-PDF' : 'Bestellung-PDF';
    const ok = await mdConfirm('Aktives Design für <strong>' + label + '</strong> entfernen und zum Standard-Layout zurückkehren?', { title: 'Design entfernen', confirmLabel: 'Entfernen' });
    if (!ok) return;
    try {
        await fetch(API_BASE + '/ai/template?type=' + type, {
            method: 'DELETE',
            headers: { 'Authorization': 'Bearer ' + getAuthToken(), 'Accept': 'application/json' },
        });
        if (typeof showToast === 'function') showToast('Standard-Layout aktiv für ' + label, 'success');
    } catch (e) {
        if (typeof showToast === 'function') showToast('Konnte nicht entfernt werden', 'error');
    }
    pdfdClosePreview();
}

// Expose for the shell (app.js loadPage switch) + inline handlers.
window.pdfdOpenUpload         = pdfdOpenUpload;
window.pdfdOnDrop             = pdfdOnDrop;
window.pdfdOnFile             = pdfdOnFile;
window.pdfdClearUpload        = pdfdClearUpload;
window.pdfdAnalyzeDesign      = pdfdAnalyzeDesign;
window.pdfdGenerateTemplate   = pdfdGenerateTemplate;
window.pdfdClearTemplate      = pdfdClearTemplate;
window.pdfdSetDocType         = pdfdSetDocType;
window.pdfdAdoptTemplate      = pdfdAdoptTemplate;
window.pdfdDiscardDraft       = pdfdDiscardDraft;
window.renderPdfDesign        = renderPdfDesign;
window.pdfdOpenOrderPicker  = pdfdOpenOrderPicker;
window.pdfdRenderOrderList  = pdfdRenderOrderList;
window.pdfdFilterOrders     = pdfdFilterOrders;
window.pdfdPickOrder        = pdfdPickOrder;
window.pdfdOpenSampleDesign = pdfdOpenSampleDesign;
window.pdfdLoadStatus      = pdfdLoadStatus;
window.pdfdPreviewWorker    = pdfdPreviewWorker;
window.pdfdPrintWorker      = pdfdPrintWorker;
window.pdfdClosePreview     = pdfdClosePreview;
