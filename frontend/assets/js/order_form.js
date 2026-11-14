/* =====================================================================
   order_form.js — PDF Editor for Auftragsbestätigung
   Handles: load, edit-mode toggle, anonymize, templates gallery,
            save PDFs, share via public link, settings, format bar
===================================================================== */

(() => {
    // ── Auth + constants ──────────────────────────────────────────
    if (!localStorage.getItem('hms_token')) { window.location.href = 'login.php'; return; }
    const API = window.location.origin + '/api';
    const TOKEN = localStorage.getItem('hms_token');
    const HEADERS = () => ({ 'Authorization': 'Bearer ' + TOKEN, 'Accept': 'application/json' });
    const MONTHS = ['', 'Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    const DAYS   = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
    const TENANT = JSON.parse(localStorage.getItem('hms_tenant') || '{}');
    const TENANT_SLUG = TENANT.slug || 'default';
    const FIRST_TIME_KEY = 'hms_pdf_first_time_' + TENANT_SLUG;
    // Signature/stamp buttons stamp company assets onto the PDF — same
    // gate as the upload routes (auftraege.bestellungen.edit). Mirrors
    // hasPermission() in app.js: owner + admin roles pass implicitly.
    const _user = JSON.parse(localStorage.getItem('hms_user') || '{}');
    const _params = new URLSearchParams(location.search);
    const _isAdmin = _user.is_owner === true || _user.is_owner === 1
        || ['admin', 'tenant_admin', 'super_admin'].includes(_user.role);
    const _hasPerm = (k) => _isAdmin || (Array.isArray(_user.permissions) && _user.permissions.includes(k));
    // Document type is fixed by the URL for the lifetime of the page.
    const DOC_TYPE = (_params.has('ab') || _params.get('doc') === 'ab') ? 'ab'
        : (_params.get('doc') === 'worker' ? 'worker' : 'order');
    const CAN_EDIT_DOC = _hasPerm('auftraege.bestellungen.edit')
        || (DOC_TYPE === 'ab' && _hasPerm('auftraege.bestaetigung.edit'));
    // Saving the company layout template — mirrors PdfLayoutController::denyWrite.
    const TYPE_EDIT_PERM = { order: 'auftraege.bestellungen.edit', ab: 'auftraege.bestaetigung.edit', worker: 'auftraege.monatsabschluss.edit' };
    const CAN_SAVE_LAYOUT = _hasPerm('system.dokument_design.manage') || _hasPerm(TYPE_EDIT_PERM[DOC_TYPE]);
    // Headless render mode (?headless=1): no editor chrome; a parent page
    // drives the renderer through window.__hmsDoc (see app.js
    // hmsWithHeadlessEditor) so every PDF of the system uses this code.
    const HEADLESS = !!window.__HEADLESS__;
    // Dokument-Design (?design=1): preview only — no text editing, every
    // option lives in the fixed sidebar (see order_form.php $designUi).
    const DESIGN_UI = !!window.__DESIGN_UI__;

    // ── State ──────────────────────────────────────────────────────
    const state = {
        docType: 'order',          // 'order' (Leistungsnachweis) | 'ab' (Auftragsbestätigung) | 'worker' (Stundennachweis)
        orderId: null,
        order: null,
        editMode: false,
        anonymize: false,
        anonymizeNu: false,
        templates: [],
        activeTemplate: null,
        settings: {
            primary_color: '#1a2744',
            accent_color:  '#3b82f6',
            font_family:   "'Segoe UI', system-ui, sans-serif",
            font_size:     'medium',
            extra_note:    '',
            show_zuschlaege: true,
            hide_extras:   [],
            hide_logo:     false,
            logo_position: 'right',   // 'left' | 'center' | 'right'
            design: 'klassisch',      // standard template: 'klassisch' | 'modern' | 'din5008'
        },
        // Textbausteine: fixed texts printed on the PDF (slots) and the
        // e-mail template used when the document is sent (future feature).
        bausteine: { intro: { on: false, text: '' }, closing: { on: false, text: '' } },
        email: { betreff: '', text: '', signatur: true },
        logoDataUrl: null,
        signatureUrl: null,        // /api/serve URL of the tenant signature image
        stampUrl: null,            // /api/serve URL of the tenant stamp image
        showSignature: false,      // render signature image on the document
        showStamp: false,          // render stamp image on the document
        // Per-PDF column overrides (simple viewer "Einstellungen"): null =
        // follow the company template. Never written into the template.
        pdfCols: { zusch: null, cols: {} },   // cols: { 'th.pause': false, … }
        // Company default colours/font (tenant pdf_branding). Only the
        // fallback for a document type whose template has no own branding.
        companyBranding: {},
        sigPos: null,              // {x,y,w} doc px — null = default spot above the signature line
        stampPos: null,
        activeFormatEl: null,
        customTemplate: null,      // AI-generated HTML template (with placeholders) for this tenant
        customDesignActive: false, // true = render via custom template instead of buildTemplate()
        layout: null,              // company layout template of DOC_TYPE (GET /pdf/layouts)
        wizardDone: false,         // first-run wizard completed company-wide
        textDefaults: {},          // data-key → built-in innerHTML (to detect edits)
        lastSavedLayout: '',       // JSON of the layout as last loaded/saved (unsaved-changes check)
        loadError: null,
        // Worker signatures from the app (GET /dokument-signaturen): by = dienst_nr → {img, keys}.
        sig: { workers: [], by: {}, canSend: false, aktiv: false, stamp: '' },
    };

    // ═══════════════════════════════════════════════════════════════
    // BOOTSTRAP
    // ═══════════════════════════════════════════════════════════════
    // Headless API handle — created synchronously so the parent can await
    // `ready` as soon as the iframe's window exists.
    let _readyResolve, _readyReject;
    if (HEADLESS) {
        window.__hmsDoc = {
            ready: new Promise((res, rej) => { _readyResolve = res; _readyReject = rej; }),
        };
        window.__hmsDoc.ready.catch(() => {});
    }

    document.addEventListener('DOMContentLoaded', () => {
        init().then(() => {
            if (!HEADLESS) return;
            if (state.loadError) _readyReject(new Error(state.loadError));
            else { exposeHeadlessApi(); _readyResolve(window.__hmsDoc); }
        }, (e) => { if (HEADLESS) _readyReject(e); });
    });

    async function init() {
        wireGlobals();
        // Initialize the Floating-Element engine BEFORE the first render —
        // renderDoc() restores the template's inserted/moved elements.
        if (window.FE) {
            const page = document.getElementById('orderForm');
            if (page) window.FE.init(page);
        }
        // Refresh tenant profile from server so the legal footer (Geschäftsführer,
        // Ust-Nr., IBAN, …) reflects the latest values even for users who were
        // already logged in before those fields existed in localStorage.
        // The company layout template of this document type loads in parallel.
        await Promise.all([refreshTenantProfile(), loadLayout()]);
        syncLogoUI();
        await loadOrder();
        if (HEADLESS) return;

        applyDocTypeUI();
        if (DESIGN_UI) ddInit();

        // Strg/Cmd+P prints the generated PDF too (same as the Drucken button).
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 'p' || e.key === 'P')) {
                if (DESIGN_UI || state.loadError || !state.order) return;
                e.preventDefault();
                printDoc();
            }
        });
        window.addEventListener('beforeprint', () => { const pg = document.getElementById('orderForm'); if (!pg) return; const z = printZoomFor(pg); pg.style.setProperty('--pz', String(z)); markPrintTail(pg, z); });
        window.addEventListener('afterprint', () => { const pg = document.getElementById('orderForm'); if (!pg) return; pg.style.removeProperty('--pz'); pg.querySelectorAll('.print-break').forEach(n => n.classList.remove('print-break')); });
        // Keep the A4 sheet fitted to the viewport (rotation, split view).
        window.addEventListener('resize', fitPageToViewport);
        // Inside the orders.js dialog: ESC in the iframe closes the parent modal.
        if (window.__EMBEDDED__) {
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !document.querySelector('.modal.show')) closeEditor();
            });
        }
        // Unsaved template changes — warn before a full page unload.
        window.addEventListener('beforeunload', (e) => {
            if (hasUnsavedLayout()) { e.preventDefault(); e.returnValue = ''; }
        });

        // First-run onboarding wizard (colours, font, logo). Shown once per
        // company (server flag pdf_wizard_done) — see maybeShowFirstRunWizard.
        setupWizard();
        // The first-run design wizard is an editor onboarding step — skip it
        // entirely in view-only mode (?view=1), where the editor is hidden.
        if (!window.__VIEWER_ONLY__) maybeShowFirstRunWizard();

        // Phase 2 (KI-Design) — vorübergehend deaktiviert ("Demnächst verfügbar").
        // Der "Eigenes Design"-Umschalter wird nicht angezeigt und das Layout
        // wird immer über das Standard-Template gerendert.
        // addDesignToggleButton();
        // if (state.customTemplate && state.order) setCustomDesign(true);
    }

    /**
     * Pull the full company profile (legal + bank fields) from the API and
     * merge it into TENANT + localStorage. This is best-effort: if the
     * call fails we keep whatever we have. Called once on editor open.
     */
    async function refreshTenantProfile() {
        try {
            const res = await fetch(API + '/dashboard/company', { headers: HEADERS() });
            if (!res.ok) return;
            const profile = await res.json();
            if (!profile || profile.error) return;
            // Mutate the in-scope TENANT object so buildTemplate() picks up
            // the fresh values without us having to re-import the const.
            Object.assign(TENANT, profile);
            const stored = JSON.parse(localStorage.getItem('hms_tenant') || '{}');
            localStorage.setItem('hms_tenant', JSON.stringify({ ...stored, ...profile }));

            // Company-wide PDF branding (colours) lives on the server and is
            // the source of truth. Apply it over the localStorage defaults so
            // the editor shows exactly the colours the Monatsabschluss worker
            // PDFs will use (they read the same tenant setting).
            // Since templates carry their own branding per document type,
            // this is only the fallback (applied in applyLayoutState).
            if (profile.pdf_branding && typeof profile.pdf_branding === 'object') {
                const b = profile.pdf_branding;
                BRANDING_KEYS.forEach(k => { if (b[k]) state.companyBranding[k] = b[k]; });
                if (!HEADLESS) {
                    try { localStorage.setItem('hms_pdf_settings_' + TENANT_SLUG, JSON.stringify(state.companyBranding)); } catch (e) {}
                }
            }

            // AI-generated full custom template (Dokument-Design → "Design
            // generieren"). When present we render the order through it.
            const tpl = profile.pdf_templates && profile.pdf_templates.order;
            if (tpl && typeof tpl.html === 'string' && tpl.html.trim() !== '') {
                state.customTemplate = tpl.html;
            }
        } catch (e) { /* offline / error → fall back to localStorage */ }

        // Auto-load the tenant logo into the document header (top-right
        // corner). We pull it through /api/serve so the browser can cache
        // the file across pages. If the tenant hasn't uploaded a logo we
        // simply leave state.logoDataUrl null and the header keeps an
        // empty placeholder.
        if (TENANT.logo_path && !state.logoDataUrl) {
            state.logoDataUrl = API + '/serve?path=' + encodeURIComponent(TENANT.logo_path);
        }
        // Document assets (signature/stamp) — same /api/serve mechanism as
        // the logo. The Unterschrift/Stempel buttons only appear for users
        // with auftraege.bestellungen.edit. Paths are per-user effective
        // (own ?? company default) — re-resolve so a mid-session upload
        // swaps the image without a page reload.
        if (TENANT.signature_path) {
            const u = API + '/serve?path=' + encodeURIComponent(TENANT.signature_path);
            if (u !== state.signatureUrl) state.signatureUrl = u;
        }
        if (TENANT.stamp_path) {
            const u = API + '/serve?path=' + encodeURIComponent(TENANT.stamp_path);
            if (u !== state.stampUrl) state.stampUrl = u;
        }
        syncDocAssetButtons();
    }

    // Company layout template of this document type + the company-wide
    // "wizard done" flag. Best-effort: without it the built-in layout shows.
    async function loadLayout() {
        try {
            const res = await fetch(API + '/pdf/layouts', { headers: HEADERS() });
            if (!res.ok) return;
            const d = await res.json();
            state.layout = (d && d.layouts && d.layouts[DOC_TYPE]) || null;
            state.wizardDone = !!(d && d.wizard_done);
        } catch (e) { /* offline → built-in layout */ }
    }

    function wireGlobals() {
        // Format bar follows clicks on editable elements (only in edit mode)

        // Signature/stamp drag & resize — document-level delegation so the
        // wraps (re-created on every buildTemplate render) never need
        // per-node listeners. Active in viewer AND editor mode.
        document.addEventListener('pointerdown', onAssetPointerDown);
        document.addEventListener('pointermove', onAssetPointerMove);
        document.addEventListener('pointerup',   onAssetPointerUp);
        document.addEventListener('dblclick',    onAssetDblClick);

        // Offline fallback for the company BRANDING only (colours / font /
        // logo position). The server copy (refreshTenantProfile) wins; all
        // document-level choices come from the company layout template.
        try {
            const s = JSON.parse(localStorage.getItem('hms_pdf_settings_' + TENANT_SLUG) || 'null');
            if (s && typeof s === 'object') {
                BRANDING_KEYS.forEach(k => { if (s[k]) state.companyBranding[k] = s[k]; });
            }
        } catch (e) {}
    }
    const BRANDING_KEYS = ['primary_color', 'accent_color', 'font_family', 'font_size', 'logo_position'];
    const BRANDING_DEFAULTS = {
        primary_color: '#1a2744',
        accent_color:  '#3b82f6',
        font_family:   "'Segoe UI', system-ui, sans-serif",
        font_size:     'medium',
        logo_position: 'right',
    };
    // Colours/font/logo position of THIS document type: its own template
    // branding → company default → built-in default. Isolated per type.
    function resolveBranding(layout) {
        const own = (layout && layout.branding && typeof layout.branding === 'object') ? layout.branding : {};
        BRANDING_KEYS.forEach(k => { state.settings[k] = own[k] || state.companyBranding[k] || BRANDING_DEFAULTS[k]; });
    }

    // ═══════════════════════════════════════════════════════════════
    // LOAD ORDER DATA
    // ═══════════════════════════════════════════════════════════════
    async function loadOrder() {
        const params = _params;
        const id = params.get('id');
        const abId = params.get('ab');
        state.docType = DOC_TYPE;
        const showDoc = (order, meta, title) => {
            state.order = order;
            renderDoc(state.layout);
            state.lastSavedLayout = layoutSignature(collectLayout());
            const m = document.getElementById('docMeta');
            if (m) m.textContent = meta;
            document.title = title;
        };
        const showError = (msg) => {
            state.loadError = msg;
            document.getElementById('orderForm').innerHTML =
                '<div class="loading"><i class="material-symbols-outlined">error</i><br>' + esc(msg) + '</div>';
        };

        // Only plain document ids are accepted — they end up in API paths.
        if ((id && !SAFE_REF.test(id)) || (abId && !SAFE_REF.test(abId))) { showError('Ungültige Dokument-ID'); return; }

        // Stundennachweis — the Monatsabschluss page hands the prepared rows
        // over via sessionStorage (same origin + tab as this iframe); the
        // Dokument-Design card opens it with sample data.
        if (state.docType === 'worker') {
            let d = null;
            if (params.get('design') !== '1') {
                try { d = JSON.parse(sessionStorage.getItem('hms_worker_doc') || 'null'); } catch (e) { d = null; }
            }
            state.designMode = !d;
            state.orderId = null;
            showDoc(d || buildSampleWorkerDoc(),
                d ? (d.name + ' · ' + d.monthLabel) : 'Design-Vorschau · Beispieldaten',
                'Stundennachweis' + (d ? ' — ' + d.name : ' (Vorschau)'));
            return;
        }

        // Auftragsbestätigung — sample (Dokument-Design) or a real order.
        if (state.docType === 'ab') {
            if (!abId) {
                state.orderId = null;
                state.designMode = true;
                showDoc(buildSampleConfirmation(), 'Design-Vorschau · Beispieldaten', 'PDF Editor — Auftragsbestätigung (Vorschau)');
                return;
            }
            try {
                // ?live=1 → current order data (used to render a NEW version
                // before sending); otherwise a sent AB comes back frozen.
                const res = await fetch(API + '/order-confirmations/' + encodeURIComponent(abId) + (params.get('live') === '1' ? '?live=1' : ''), { headers: HEADERS() });
                const d = await res.json();
                if (!res.ok || !d || d.error) throw new Error((d && d.error) || 'Auftragsbestätigung nicht gefunden');
                // Saved PDFs are filed under the order (Bestellung) of this Eingang.
                state.orderId = d.bestell_id || null;
                showDoc(d, 'Auftrag ' + (d.dokument_nr || abId) + ' · ' + ((d.kunde && d.kunde.name) || '')
                        + (d.frozen ? ' · Versendet (Version ' + (d.version || 1) + ', archiviert)' : ''),
                    'Auftragsbestätigung ' + (d.dokument_nr || abId));
            } catch (e) {
                showError(e.message || 'Auftragsbestätigung nicht gefunden');
            }
            return;
        }

        // Design-Vorschau (Dokument-Design → Bestellungen-Karte): open the
        // editor with built-in SAMPLE data so the user can configure the
        // company-wide template without needing a real order.
        // Triggered via order_form.php?design=1.
        if (!id && (params.get('design') === '1' || params.get('sample') === '1')) {
            state.orderId  = null;
            state.designMode = true;
            showDoc(buildSampleOrder(), 'Design-Vorschau · Beispieldaten', 'PDF Editor — Leistungsnachweis (Vorschau)');
            return;
        }

        if (!id) { showError('Keine Bestell-ID angegeben'); return; }
        state.orderId = id;

        try {
            const res = await fetch(API + '/orders/' + encodeURIComponent(id), { headers: HEADERS() });
            const order = await res.json().catch(() => null);
            if (!res.ok || !order || order.error) { showError((order && order.error) || 'Bestellung nicht gefunden'); return; }
            showDoc(order, 'Auftrag ' + order.bestell_id + ' · ' + (order.kundenname || order.kunden_id),
                'Leistungsnachweis ' + order.bestell_id);
            await loadWorkerSignatures();
        } catch (e) {
            showError('Fehler: ' + e.message);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // COMPANY LAYOUT TEMPLATE
    // One template per document type (PUT /api/pdf/layout). It stores the
    // STATIC parts the user changed — edited texts marked with data-key,
    // hidden sections, extra note, inserted/moved elements, signature &
    // stamp placement — never the per-document data (names, hours, prices).
    // Every render goes through renderDoc(), so re-renders (anonymise,
    // signature toggle, …) keep all edits, and every PDF of the system
    // (editor, Auftragsbestätigung list, Monatsabschluss) looks the same.
    // ═══════════════════════════════════════════════════════════════
    const FMT_PROPS = ['text-align', 'font-size', 'color', 'font-weight', 'font-style', 'text-decoration', 'background-color'];
    // Mirrors App\Support\PdfHtmlSanitizer (tables for the legal footer).
    const SAFE_TEXT_TAGS = ['b', 'strong', 'i', 'em', 'u', 'br', 'span', 'div', 'p', 'font', 'sup', 'sub',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th'];

    function sanitizeText(html) {
        if (!window.DOMPurify) return esc(String(html || '').replace(/<[^>]*>/g, ''));
        return window.DOMPurify.sanitize(String(html || ''), { ALLOWED_TAGS: SAFE_TEXT_TAGS, ALLOWED_ATTR: ['style', 'class', 'colspan', 'rowspan'] });
    }

    // Applies the non-text parts (settings + asset flags) — must run BEFORE
    // buildTemplate() because the builders read them (logo, signature …).
    function applyLayoutState(layout) {
        const l = layout || {};
        resolveBranding(l);
        state.settings.hide_extras     = Array.isArray(l.hidden) ? l.hidden.slice() : [];
        state.settings.show_zuschlaege = l.show_zuschlaege !== false;
        state.settings.extra_note      = l.extra_note || '';
        state.settings.hide_logo       = !!l.hide_logo;
        state.settings.design          = DD_TEMPLATES.some(t => t.id === l.design) ? l.design : 'klassisch';
        const lib = DD_LIB[DOC_TYPE];
        const bs = (l.bausteine && typeof l.bausteine === 'object') ? l.bausteine : {};
        state.bausteine = {};
        BAUSTEIN_SLOTS.forEach(s => {
            const b = (bs[s] && typeof bs[s] === 'object') ? bs[s] : {};
            const defOn = !!(BAUSTEIN_DEFAULT_ON[DOC_TYPE] || {})[s];
            // A saved slot is used as-is (even emptied); only a never-saved
            // slot falls back to the standard text.
            const saved = 'on' in b && typeof b.text === 'string';
            state.bausteine[s] = { on: 'on' in b ? !!b.on : defOn, text: saved ? b.text.slice(0, 2000) : lib[s][0].t };
        });
        const m = (l.email && typeof l.email === 'object') ? l.email : {};
        state.email = {
            betreff:  (typeof m.betreff === 'string' && m.betreff.trim()) ? m.betreff : lib.mail[0].betreff,
            text:     (typeof m.text === 'string' && m.text.trim()) ? m.text : lib.mail[0].text,
            signatur: m.signatur !== false,
        };
        const a = l.assets || {};
        state.showSignature = !!(a.show_signature && state.signatureUrl);
        state.showStamp     = !!(a.show_stamp && state.stampUrl);
        state.sigPos   = (state.showSignature && a.sig_pos)   ? { ...a.sig_pos }   : null;
        state.stampPos = (state.showStamp && a.stamp_pos)     ? { ...a.stamp_pos } : null;
    }

    function renderDoc(layout) {
        if (!state.order) return;
        const page = document.getElementById('orderForm');
        applyLayoutState(layout);
        page.innerHTML = buildTemplate(state.order);
        // Remember the built-in text of every keyed element (edit detection).
        state.textDefaults = {};
        page.querySelectorAll('[data-editable][data-key]').forEach(el => { state.textDefaults[el.dataset.key] = el.innerHTML; });
        const texts = (layout && layout.texts) || {};
        Object.keys(texts).forEach(k => {
            const el = page.querySelector('[data-editable][data-key="' + CSS.escape(k) + '"]');
            if (!el) return;
            const t = texts[k] || {};
            if (typeof t.h === 'string') el.innerHTML = sanitizeText(t.h);
            if (typeof t.s === 'string') {
                t.s.split(';').forEach(d => {
                    const i = d.indexOf(':');
                    if (i < 1) return;
                    const p = d.slice(0, i).trim().toLowerCase();
                    if (FMT_PROPS.includes(p)) el.style.setProperty(p, d.slice(i + 1).trim());
                });
            }
        });
        applySettingsToDocument();
        renderExtraNote();
        if (window.FE) {
            window.FE.deserialize({ inserted: (layout && layout.inserted) || [], moved: (layout && layout.moved) || [] });
            window.FE.resetHistory();
        }
        reanchorAssets();
        if (state.editMode) {
            page.classList.add('edit-mode');
            page.querySelectorAll('[data-editable]').forEach(el => el.setAttribute('contenteditable', 'true'));
            if (window.FE) window.FE.setEditMode(true);
        }
        if (!HEADLESS) { applySettingsToUI(); syncLogoUI(); syncDocAssetButtons(); }
        fitPageToViewport();
        // Images (logo) change block heights once loaded → re-apply the flow
        // anchors so placed elements keep their spot relative to the content.
        const token = ++_renderToken;
        waitForDocImages(page, 8000).then(() => {
            if (token !== _renderToken) return;
            if (window.FE) window.FE.reanchor();
            reanchorAssets();
            fitPageToViewport();
        });
    }
    let _renderToken = 0;

    // Signature/stamp placed absolutely on the page: follow their anchor
    // block (see FE.anchorFor) instead of a fixed page y.
    function reanchorAssets() {
        if (!window.FE) return;
        ['signature', 'stamp'].forEach(kind => {
            const a = DOC_ASSETS[kind];
            const p = state[a.pos];
            if (!p || !p.a) return;
            const y = window.FE.resolveAnchor(p.a, p.dy);
            if (y === null) return;
            p.y = y;
            const wrap = document.getElementById('docAsset-' + kind);
            if (wrap && wrap.parentNode === document.getElementById('orderForm')) wrap.style.top = y + 'px';
        });
    }
    const assetPosWithAnchor = (p) => {
        if (!p) return null;
        const anc = window.FE ? window.FE.anchorFor(p.y) : null;
        const out = { x: p.x, y: p.y, w: p.w, r: p.r || 0 };
        return anc ? { ...out, ...anc } : out;
    };

    // Snapshot of everything template-worthy currently on the page.
    function collectLayout() {
        const page = document.getElementById('orderForm');
        const texts = {};
        if (page) page.querySelectorAll('[data-editable][data-key]').forEach(el => {
            const k = el.dataset.key;
            const e = {};
            if (k in state.textDefaults && el.innerHTML !== state.textDefaults[k]) e.h = el.innerHTML;
            const s = FMT_PROPS.map(p => {
                const v = el.style.getPropertyValue(p);
                return v ? p + ':' + v : '';
            }).filter(Boolean).join(';');
            if (s) e.s = s;
            if (e.h !== undefined || e.s) texts[k] = e;
        });
        const fe = window.FE && page ? window.FE.serialize() : { inserted: [], moved: [] };
        return {
            texts,
            hidden: (state.settings.hide_extras || []).slice(),
            branding: BRANDING_KEYS.reduce((o, k) => { o[k] = state.settings[k]; return o; }, {}),
            show_zuschlaege: state.settings.show_zuschlaege !== false,
            extra_note: state.settings.extra_note || '',
            hide_logo: !!state.settings.hide_logo,
            design: state.settings.design || 'klassisch',
            bausteine: JSON.parse(JSON.stringify(state.bausteine || {})),
            email: { ...(state.email || {}) },
            inserted: fe.inserted || [],
            moved: fe.moved || [],
            assets: {
                show_signature: !!state.showSignature, show_stamp: !!state.showStamp,
                sig_pos: assetPosWithAnchor(state.sigPos), stamp_pos: assetPosWithAnchor(state.stampPos),
            },
        };
    }

    // Comparable form of a layout: anchored positions are compared by their
    // anchor (a late image load moves the absolute y, not the placement).
    function layoutSignature(l) {
        const c = JSON.parse(JSON.stringify(l));
        (c.inserted || []).forEach(e => { if (e.anchor) delete e.y; });
        (c.moved || []).forEach(m => { if (m.a) delete m.y; });
        ['sig_pos', 'stamp_pos'].forEach(k => { const p = c.assets && c.assets[k]; if (p && p.a) delete p.y; });
        return JSON.stringify(c);
    }

    function hasUnsavedLayout() {
        if (HEADLESS || window.__VIEWER_ONLY__ || !CAN_SAVE_LAYOUT || !state.order || !state.lastSavedLayout || state.ddPreview) return false;
        try { return layoutSignature(collectLayout()) !== state.lastSavedLayout; } catch (e) { return false; }
    }

    async function saveLayoutToServer() {
        const layout = collectLayout();
        const res = await fetch(API + '/pdf/layout', {
            method: 'PUT',
            headers: { ...HEADERS(), 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: DOC_TYPE, layout }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) throw new Error(data.error || data.message || ('HTTP ' + res.status));
        state.layout = data.layout || layout;
        state.lastSavedLayout = layoutSignature(layout);
        return data;
    }

    // Back arrow / ESC inside the embedded dialog.
    function closeEditor() {
        if (state.ddPreview) ddExitPreview();
        // Dokumentvorlagen autosaves — flush a pending change before closing.
        if (DESIGN_UI && hasUnsavedLayout() && !_ddSaving) {
            _ddSaving = true;
            ddSaveState('saving');
            saveLayoutToServer().then(() => { _ddSaving = false; closeEditor(); },
                (e) => { _ddSaving = false; ddSaveState('error'); toast('Speichern fehlgeschlagen: ' + e.message, 'error', 6000); });
            return false;
        }
        if (hasUnsavedLayout() && !confirm('Es gibt nicht gespeicherte Änderungen an der Vorlage. Ohne Speichern schließen?')) return false;
        state.lastSavedLayout = '';   // skip the beforeunload prompt
        if (window.parent !== window && window.parent.closeOrderFormModal) window.parent.closeOrderFormModal();
        else window.location.href = 'index.php';
        return false;
    }
    window.closeEditor = closeEditor;

    function renderExtraNote() {
        const sec = document.getElementById('extraNoteSection');
        if (!sec) return;
        const txt = state.settings.extra_note || '';
        sec.textContent = txt;
        sec.style.display = txt ? 'block' : 'none';
    }

    // Built-in sample order for the Design-Vorschau (see loadOrder). Shape
    // mirrors a real /orders/{id} response so buildTemplate() renders fully.
    function buildSampleOrder() {
        return {
            bestell_id: 'BEISPIEL-2026-001',
            kundenname:  'Muster Kunde GmbH',
            kunden_id:   'K-001',
            objektname:  'Bürozentrum Nord',
            obj_id:      'OBJ-001',
            monat: 6,
            jahr:  2026,
            zeit_von: '06:00',
            zeit_bis: '14:30',
            nu_name:  '–',
            confirmed_workers: [
                { vorname: 'Max',   name: 'Mustermann', dienst_nr: '1042', datum: '2026-06-02', ist_std: 8.0,  pause_minuten: 30, preis_kunde: 24.5, zuschlag_pct: 0,  ist_start: '06:00', ist_ende: '14:30', schichtart: 'tagdienst' },
                { vorname: 'Erika', name: 'Beispiel',   dienst_nr: '1043', datum: '2026-06-03', ist_std: 8.0,  pause_minuten: 30, preis_kunde: 24.5, zuschlag_pct: 0,  ist_start: '06:00', ist_ende: '14:30', schichtart: 'tagdienst' },
                { vorname: 'Hans',  name: 'Schmidt',    dienst_nr: '1044', datum: '2026-06-09', ist_std: 7.25, pause_minuten: 45, preis_kunde: 26.0, zuschlag_pct: 25, ist_start: '22:00', ist_ende: '06:00', schichtart: 'nacht' },
                { vorname: 'Anna',  name: 'Klein',      dienst_nr: '1045', datum: '2026-06-14', ist_std: 8.0,  pause_minuten: 30, preis_kunde: 30.0, zuschlag_pct: 50, ist_start: '06:00', ist_ende: '14:30', schichtart: 'sonntag' },
            ],
            confirmed_nu_workers: [],
        };
    }

    // Sample Auftragsbestätigung for the Design-Vorschau — same shape as
    // GET /api/order-confirmations/{id}.
    function buildSampleConfirmation() {
        return {
            eingang_id: 'BE-BEISPIEL', bestell_id: null, dokument_nr: 'BEISPIEL-2026-001',
            meta: {
                auftrags_nr: 'BEISPIEL-2026-001', kunden_nr: 'K-001', bestell_nr: '4500012345',
                datum: new Date().toLocaleDateString('de-DE'), bearbeiter: (_user.full_name || _user.name || 'Max Mustermann'),
                telefon: TENANT.company_phone || '', email: TENANT.company_email || '',
                zeitraum: 'Juni 2026', leistungszeit: '06:00 – 14:30 Uhr',
            },
            kunde: { name: 'Muster Kunde GmbH', zusatz: 'Einkauf', strasse: 'Musterstraße 12', plz_ort: '10115 Berlin' },
            lieferadresse: 'Bürozentrum Nord, Hauptstraße 5, 10117 Berlin',
            positions: [
                { name: 'Objektschutz', modell: 'Pro Stunde', tage_count: 20, stunden: 160, datum_von: '2026-06-01', datum_bis: '2026-06-30', menge: 160, einheit: 'Std.', preis: 24.5, gesamt: 3920 },
                { name: 'Unterhaltsreinigung', modell: 'Monatspauschale', tage_count: 0, stunden: 0, menge: 1, einheit: 'Pausch.', preis: 850, gesamt: 850 },
            ],
            totals: { netto: 4770, ust_rate: 19, ust: 906.3, brutto: 5676.3 },
            bemerkungen: ['Zugang über den Haupteingang, Schlüsselübergabe beim Objektleiter.'],
        };
    }

    // Per document type: hide the Leistungsnachweis-only controls (worker
    // anonymisation, summary/NU/Zuschlag toggles), relabel the topbar and
    // show only the actions that can work for this document.
    function applyDocTypeUI() {
        if (state.docType !== 'order') {
            ['btnAnonymize', 'btnAnonymizeNu'].forEach(i => { const b = document.getElementById(i); if (b) b.style.display = 'none'; });
            (state.docType === 'worker' ? ['showNu', 'showZuschColumn'] : ['showSummary', 'showNu', 'showZuschColumn']).forEach(i => {
                const cb = document.getElementById(i);
                const row = cb && cb.closest('label');
                if (row) row.style.display = 'none';
            });
        }
        const tt = document.querySelector('.tb-title');
        if (tt) tt.textContent = DOC_LABEL[state.docType];
        const archBtn = document.getElementById('btnArchiv');
        if (archBtn) archBtn.style.display = archiveRef() ? '' : 'none';
        syncSigRequestButton();
        // Logo upload changes the company logo — Dokument-Design only.
        if (!_hasPerm('system.dokument_design.manage')) {
            const up = document.querySelector('.logo-upload');
            if (up) { up.style.pointerEvents = 'none'; up.style.opacity = '0.6'; up.title = 'Firmenlogo nur unter Dokumentvorlagen änderbar'; }
        }
    }
    const DOC_LABEL = { order: 'Leistungsnachweis', ab: 'Auftragsbestätigung', worker: 'Stundennachweis' };

    // ═══════════════════════════════════════════════════════════════
    // RESPONSIVE PAGE SCALING
    // The .page sheet is a fixed 800px A4 — on narrow screens it
    // overflows and clips (body has overflow-x:hidden). Scale it to
    // the canvas width instead, like a PDF viewer's "fit width" mode.
    // The sheet keeps its true 800px layout inside #pageScaleWrap so the
    // document renders identically to the printed/PDF version, just
    // smaller; the wrap's fixed height compensates for the transform
    // not affecting layout.
    // ═══════════════════════════════════════════════════════════════
    let _pageRO = null;
    let _pageScaleSuspended = false;
    function fitPageToViewport() {
        if (_pageScaleSuspended) return;
        const page = document.getElementById('orderForm');
        if (!page || !page.parentElement) return;
        let wrap = document.getElementById('pageScaleWrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'pageScaleWrap';
            page.parentNode.insertBefore(wrap, page);
            wrap.appendChild(page);
            // Re-fit when the page's own height changes (webfont load,
            // toggled sections, settings) — transforms don't retrigger RO.
            if (window.ResizeObserver && !_pageRO) {
                _pageRO = new ResizeObserver(() => fitPageToViewport());
                _pageRO.observe(page);
            }
        }
        const host = wrap.parentElement; // .canvas
        const cs = getComputedStyle(host);
        const avail = host.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        const pageW = page.offsetWidth;   // 800 inside the wrap — layout ignores transforms
        if (!pageW || avail <= 0) return;
        const scale = Math.min(1, avail / pageW);
        // Editor handles / grab zones counter-scale so they keep a usable
        // on-screen size on a shrunk sheet (order_form.css --fe-inv).
        page.style.setProperty('--fe-inv', String(scale > 0.995 ? 1 : Math.round(100 / scale) / 100));
        if (scale > 0.995) {
            page.style.transform = '';
            wrap.style.height = '';
            wrap.style.overflow = '';
        } else {
            page.style.transform = 'scale(' + scale + ')';
            wrap.style.overflow = 'hidden';
            wrap.style.height = Math.ceil(page.scrollHeight * scale) + 'px';
        }
    }

    // Drop the on-screen scaling before html2pdf/print captures the
    // element — the artifact must always be full-size.
    function suspendPageScale() {
        _pageScaleSuspended = true;
        const page = document.getElementById('orderForm');
        const wrap = document.getElementById('pageScaleWrap');
        if (page) page.style.transform = '';
        if (wrap) { wrap.style.height = ''; wrap.style.overflow = ''; }
    }

    function resumePageScale() {
        _pageScaleSuspended = false;
        fitPageToViewport();
    }

    // ═══════════════════════════════════════════════════════════════
    // TEMPLATE RENDERING
    // ═══════════════════════════════════════════════════════════════
    function buildTemplate(order) {
        if (state.docType === 'ab') return buildAbTemplate(order);
        if (state.docType === 'worker') return buildWorkerTemplate(order);
        const today = new Date().toLocaleDateString('de-DE');
        const own = order.confirmed_workers || [];
        const nu  = order.confirmed_nu_workers || [];
        const all = [...own, ...nu];
        // Chronological row order for the document: by date, then worker
        // name and start time so same-day shifts of one worker stay
        // together. Entries without a date sink to the bottom.
        const _wName = w => (((w.vorname || '') + ' ' + (w.name || '')).trim()
            || (w.nu_firmenname || '')).toLowerCase();
        all.sort((a, b) =>
            String(a.datum || '9999').localeCompare(String(b.datum || '9999'))
            || _wName(a).localeCompare(_wName(b), 'de')
            || String(a.ist_start || '').localeCompare(String(b.ist_start || ''))
        );

        const companyName = TENANT.name || 'Firma';
        const companyInfo = [TENANT.company_address,
            ((TENANT.company_zip||'') + ' ' + (TENANT.company_city||'')).trim(),
            TENANT.company_phone ? 'Tel: ' + TENANT.company_phone : '',
            TENANT.company_email].filter(Boolean).join(' | ');

        let totalStd = 0, totalPause = 0, totalEinnahmen = 0, totalAufschlag = 0;
        const stdByArt = { tagdienst: 0, nachtdienst: 0, sonntag: 0, nacht_sonntag: 0, feiertag: 0, nacht_feiertag: 0 };
        const artAlias = { nacht: 'nachtdienst', sonntag_nacht: 'nacht_sonntag', feiertag_nacht: 'nacht_feiertag' };
        // Anonymised label per WORKER (not per row): the same person keeps
        // the same letter on every row; A…Z, then A2…Z2, ….
        const anonIds = new Map();
        const anonLabel = (w) => {
            const k = w.dienst_nr || w.nu_worker_id || _wName(w);
            if (!anonIds.has(k)) anonIds.set(k, anonIds.size);
            const n = anonIds.get(k);
            return 'Mitarbeiter ' + String.fromCharCode(65 + (n % 26)) + (n >= 26 ? Math.floor(n / 26) + 1 : '');
        };
        const rowsHtml = all.map((w, idx) => {
            const isNu = !!(w.nu_firmenname || w.nu_worker_id);
            const realName = ((w.vorname||'') + ' ' + (w.name||'')).trim() || (isNu ? 'NU-Mitarbeiter' : (w.dienst_nr||'Mitarbeiter'));
            const dispName = state.anonymize ? anonLabel(w) : realName;
            const label = (!state.anonymize && w.nu_firmenname) ? ' <span style="color:var(--gray-400)">(' + esc(state.anonymizeNu ? 'Nachunternehmer' : w.nu_firmenname) + ')</span>' : '';

            // Customer-billable hours come from the shared Abrechnung engine; legacy/sample rows fall back to worker/planned hours.
            const customerStd = parseFloat(w.kundenstunden);
            const istStd = parseFloat(w.ist_std);
            const std    = Number.isFinite(customerStd) ? customerStd : (istStd > 0 ? istStd : (parseFloat(w.ist_std_menge) || 0));
            const pause  = parseInt(w.pause_minuten || 0) || 0;
            // Customer hours include the paid part of the pause (only the
            // legal pause is deducted). With the Pause column visible the
            // sheet shows Von/Bis − Pause instead (applyPauseHours).
            const stdNet = Number.isFinite(customerStd)
                ? Math.max(0, std - Math.max(0, pause - (parseInt(w.gesetzl_pause || 0) || 0)) / 60)
                : std;
            const prKnd  = parseFloat(w.preis_kunde || 0) || 0;
            const zPct   = parseFloat(w.zuschlag_pct || 0) || 0;
            const calculated = prKnd * std * (1 + zPct/100);
            const einn   = (w.einnahmen !== null && w.einnahmen !== undefined && w.einnahmen !== '') ? (parseFloat(w.einnahmen) || 0) : calculated;
            const aufs   = (w.aufschlag !== null && w.aufschlag !== undefined && w.aufschlag !== '') ? (parseFloat(w.aufschlag) || 0) : (zPct > 0 ? einn - (einn / (1 + zPct/100)) : 0);

            totalStd += std; totalPause += pause; totalEinnahmen += einn; totalAufschlag += aufs;
            let artKey = w.schichtart || 'tagdienst';
            artKey = artAlias[artKey] || artKey;
            if (!(artKey in stdByArt)) artKey = 'tagdienst';
            stdByArt[artKey] += std;

            const d = w.datum ? new Date(w.datum + 'T00:00:00') : null;
            const dayName = d ? DAYS[d.getDay()] : '–';
            const [bL, bC] = schichtBadge(w.schichtart);

            return `<tr>
                <td data-editable contenteditable="false">${idx+1}</td>
                <td data-editable contenteditable="false">${esc(dispName)}${label}</td>
                <td data-editable contenteditable="false">${formatDate(w.datum)}</td>
                <td data-editable contenteditable="false">${dayName}</td>
                <td data-editable contenteditable="false">${formatTime(w.ist_start)}</td>
                <td data-editable contenteditable="false">${formatTime(w.ist_ende)}</td>
                <td data-editable contenteditable="false">${pause>0 ? pause+' Min' : '–'}</td>
                <td data-editable contenteditable="false" data-std-g="${std}" data-std-n="${stdNet}" data-std-art="${artKey}">${std ? std.toFixed(2).replace('.', ',') : '–'}</td>
                <td class="zusch-col" data-editable contenteditable="false">${bL ? '<span class="badge '+bC+'">'+bL+'</span>' : '–'}</td>
                <td class="zusch-col zusch-cell" data-editable contenteditable="false">${zPct > 0 ? '+'+zPct.toFixed(0)+' %' : '–'}</td>
                <td class="wk-sig-cell">${workerSigHtml(w)}</td>
            </tr>`;
        }).join('');

        const uniqueDays = new Set(all.map(w => w.datum).filter(Boolean)).size;

        return `
            ${headerHtml(companyName, companyInfo)}

            <div class="doc-title" ${K('doc.title')} data-draggable>LEISTUNGSNACHWEIS / EINSATZPLAN</div>

            <div class="meta-row" data-draggable data-mkey="meta.an">
                <div><span class="meta-label" ${K('lbl.an')}>AN:</span>
                     <span data-editable contenteditable="false">${esc(order.kundenname || order.kunden_id)}</span></div>
            </div>
            <div class="meta-row" data-draggable data-mkey="meta.datum">
                <div><span class="meta-label" ${K('lbl.auftragsdatum')}>AUFTRAGSDATUM:</span>
                     <span data-editable contenteditable="false">${today}</span></div>
                <div><span class="meta-label" ${K('lbl.auftragsnr')}>AUFTRAGS-NR:</span>
                     <span data-editable contenteditable="false">${esc(order.bestell_id)}</span></div>
            </div>

            ${bausteinHtml('intro')}

            <div class="section" data-draggable data-mkey="sec.details">
                <div class="section-title" ${K('sec.details')}>AUFTRAGSDETAILS</div>
                <div class="section-body">
                    <div class="detail-row"><div class="detail-label" ${K('lbl.kunde')}>KUNDE:</div><div class="detail-value" data-editable contenteditable="false">${esc(order.kundenname || order.kunden_id)}</div></div>
                    <div class="detail-row"><div class="detail-label" ${K('lbl.objekt')}>OBJEKT:</div><div class="detail-value" data-editable contenteditable="false">${esc(order.objektname || order.obj_id)}</div></div>
                    <div class="detail-row"><div class="detail-label" ${K('lbl.monat')}>MONAT:</div><div class="detail-value highlight" data-editable contenteditable="false">${esc(MONTHS[order.monat] || '')} ${esc(order.jahr || '')}</div></div>
                    <div class="detail-row"><div class="detail-label" ${K('lbl.arbeitszeit')}>ARBEITSZEIT:</div><div class="detail-value highlight" data-editable contenteditable="false">${esc(formatTime(order.zeit_von))} – ${esc(formatTime(order.zeit_bis))}</div></div>
                    <div class="detail-row" id="nuRow"${state.anonymizeNu ? ' style="display:none"' : ''}><div class="detail-label" ${K('lbl.nu')}>NU:</div><div class="detail-value" data-editable contenteditable="false">${esc(nuDisplayName(order))}</div></div>
                </div>
            </div>

            <div class="section section-flow">
                <div class="section-title">
                    <span ${K('sec.einsaetze')}>BESTÄTIGTE MITARBEITER-EINSÄTZE</span> (<span id="entryCount">${all.length}</span> <span ${K('sec.einsaetze.suffix')}>Einträge</span>)
                </div>
                <table>
                    <thead><tr>
                        <th ${K('th.nr')}>Nr.</th><th ${K('th.mitarbeiter')}>Mitarbeiter</th><th ${K('th.datum')}>Datum</th><th ${K('th.wochentag')}>Wochentag</th>
                        <th ${K('th.von')}>Von</th><th ${K('th.bis')}>Bis</th><th ${K('th.pause')}>Pause</th><th ${K('th.std')}>Std</th>
                        <th class="zusch-col" ${K('th.schicht')}>Schicht</th><th class="zusch-col" ${K('th.zuschlag')}>Zuschlag</th>
                        <th class="wk-sig-cell" ${K('th.unterschrift')}>Unterschrift</th>
                    </tr></thead>
                    <tbody>${rowsHtml || '<tr><td colspan="11" style="text-align:center;color:var(--gray-400);padding:20px">Keine freigegebenen Einsätze (Kunden-Freigabe im Monatsabschluss)</td></tr>'}</tbody>
                    ${all.length ? `<tfoot><tr>
                        <td colspan="6" style="text-align:right" ${K('lbl.gesamt')}>Gesamt:</td>
                        <td data-editable contenteditable="false">${totalPause} Min</td>
                        <td data-editable contenteditable="false" data-std-total>${totalStd.toFixed(2).replace('.', ',')}</td>
                        <td class="zusch-col" colspan="2"></td><td class="wk-sig-cell"></td>
                    </tr></tfoot>` : ''}
                </table>
            </div>

            <div class="summary-section" id="summarySection" data-draggable data-mkey="summary">
                <table class="summary-table">
                    <tr class="st-head"><th colspan="6" ${K('st.head')}>Zusammenfassung</th></tr>
                    <tr class="st-labels">
                        <td colspan="2" ${K('st.einsatztage')}>Einsatztage</td>
                        <td colspan="2" ${K('st.einsaetze')}>Mitarbeiter-Einsätze</td>
                        <td colspan="2" ${K('st.stunden')}>Gesamt-Stunden</td>
                    </tr>
                    <tr class="st-values">
                        <td colspan="2" data-editable contenteditable="false">${uniqueDays}</td>
                        <td colspan="2" data-editable contenteditable="false">${all.length}</td>
                        <td colspan="2" data-editable contenteditable="false" data-std-total>${totalStd.toFixed(2).replace('.', ',')}</td>
                    </tr>
                    <tr class="st-labels">
                        <td ${K('st.normal')}>Std. normal</td>
                        <td ${K('st.nacht')}>Nacht</td>
                        <td ${K('st.sonntag')}>Sonntag</td>
                        <td ${K('st.sonntagnacht')}>Sonntag Nacht</td>
                        <td ${K('st.feiertag')}>Feiertag</td>
                        <td ${K('st.feiertagnacht')}>Feiertag Nacht</td>
                    </tr>
                    <tr class="st-values">
                        <td data-editable contenteditable="false" data-std-art-total="tagdienst">${stdByArt['tagdienst'].toFixed(2).replace('.', ',')}</td>
                        <td data-editable contenteditable="false" data-std-art-total="nachtdienst">${stdByArt['nachtdienst'].toFixed(2).replace('.', ',')}</td>
                        <td data-editable contenteditable="false" data-std-art-total="sonntag">${stdByArt['sonntag'].toFixed(2).replace('.', ',')}</td>
                        <td data-editable contenteditable="false" data-std-art-total="nacht_sonntag">${stdByArt['nacht_sonntag'].toFixed(2).replace('.', ',')}</td>
                        <td data-editable contenteditable="false" data-std-art-total="feiertag">${stdByArt['feiertag'].toFixed(2).replace('.', ',')}</td>
                        <td data-editable contenteditable="false" data-std-art-total="nacht_feiertag">${stdByArt['nacht_feiertag'].toFixed(2).replace('.', ',')}</td>
                    </tr>
                </table>
            </div>

            ${bausteinHtml('closing')}

            <div class="signature-section" id="signatureSection">
                <div class="signature-block" data-draggable data-key="sig.block.1"><div class="signature-line" ${K('sig.1')}>Datum, Unterschrift Auftraggeber</div></div>
                <div class="signature-block" data-draggable data-key="sig.block.2">${docSigMedia()}<div class="signature-line" ${K('sig.2')}>Datum, Unterschrift ${esc(companyName)}</div></div>
            </div>

            ${EXTRA_NOTE_HTML}

            ${legalFooterHtml()}

            ${docFloats()}
        `;
    }

    // Editable + template-keyed attribute set for STATIC texts. Data cells
    // use plain data-editable (per-document edit, never in the template).
    function K(key) { return 'data-editable contenteditable="false" data-key="' + key + '"'; }

    const EXTRA_NOTE_HTML = '<div id="extraNoteSection" style="display:none; margin-top:16px; padding:12px 16px; background:var(--gray-50); border:1px solid var(--gray-200); border-radius:4px; font-size:0.85rem; color:var(--gray-600); white-space:pre-wrap;"></div>';

    // Shared document header (company name/info + logo) of all three types.
    // The coloured rule under the header is its own element (was the
    // header's border-bottom) so it can be moved and hidden like any block;
    // .header.has-rule + .header-rule render exactly like the old border.
    function headerHtml(companyName, companyInfo) {
        const pos = state.settings.logo_position === 'left' ? 'logo-left' : (state.settings.logo_position === 'center' ? 'logo-center' : '');
        return `<div class="header has-rule ${pos}">
                <div class="header-text">
                    <div class="company-name" ${K('co.name')} data-draggable>${esc(companyName)}</div>
                    <div class="company-info" ${K('co.info')} data-draggable>${esc(companyInfo)}</div>
                </div>
                ${state.logoDataUrl ? '<img class="header-logo" src="' + esc(state.logoDataUrl) + '" id="docLogo" data-key="logo" data-draggable draggable="false">' : '<div id="docLogo"></div>'}
            </div>
            <div class="header-rule" id="headerRule" data-draggable data-mkey="header.rule"></div>`;
    }

    // Three-column legal footer (Impressum), built from the tenant
    // profile in localStorage. Each line is omitted if empty so a
    // partial profile doesn't leave gaps. Must stay in sync with the
    // server-side blade templates (pdf/order-form, pdf/auftragsbestaetigung).
    // Shared by the Leistungsnachweis and the Auftragsbestätigung layout.
    function legalFooterHtml() {
        const companyName = TENANT.name || 'Firma';
        const fAddress = [TENANT.company_address || '',
            ((TENANT.company_zip||'') + ' ' + (TENANT.company_city||'')).trim()]
            .filter(Boolean).join(', ');
        const fRegisterLine = (TENANT.registergericht && TENANT.handelsregister_nr)
            ? TENANT.registergericht + ': ' + TENANT.handelsregister_nr
            : (TENANT.handelsregister_nr ? 'HR-Nr.: ' + TENANT.handelsregister_nr : '');
        const fCol1 = [
            companyName,
            TENANT.geschaeftsfuehrer ? 'Geschäftsführer: ' + TENANT.geschaeftsfuehrer : '',
            '__GAP__',
            fAddress,
            TENANT.company_phone ? 'Telefon: ' + TENANT.company_phone : '',
        ].filter(Boolean);
        const fCol2 = [
            TENANT.company_email ? 'E-Mail: ' + TENANT.company_email : '',
            TENANT.website       ? 'Internet: ' + TENANT.website     : '',
            fRegisterLine,
            TENANT.steuernummer  ? 'Ust.-Nr.: ' + TENANT.steuernummer : '',
            TENANT.ust_id        ? 'Ust.-IdNr.: ' + TENANT.ust_id     : '',
        ].filter(Boolean);
        const fCol3 = [
            TENANT.bank_name || '',
            TENANT.iban ? 'IBAN: ' + TENANT.iban : '',
            TENANT.bic  ? 'BIC: '  + TENANT.bic  : '',
        ].filter(Boolean);
        const renderFooterCol = (lines) => lines.map(l =>
            l === '__GAP__'
                ? '<div class="footer-line">&nbsp;</div>'
                : '<div class="footer-line">' + esc(l) + '</div>'
        ).join('');
        return `<div class="footer-legal" id="footerSection" ${K('footer')} data-draggable>
                <table class="footer-legal-table"><tr>
                    <td class="footer-legal-col">${renderFooterCol(fCol1)}</td>
                    <td class="footer-legal-col">${renderFooterCol(fCol2)}</td>
                    <td class="footer-legal-col">${renderFooterCol(fCol3)}</td>
                </tr></table>
            </div>`;
    }

    // ═══════════════════════════════════════════════════════════════
    // AUFTRAGSBESTÄTIGUNG layout (?ab=<eingang_id> or ?design=1&doc=ab)
    // Data = GET /api/order-confirmations/{id} (positions/totals computed
    // server-side, identical to pdf/auftragsbestaetigung.blade.php). Every
    // text node is data-editable so the same editor tools apply.
    // ═══════════════════════════════════════════════════════════════
    function buildAbTemplate(d) {
        const E = 'data-editable contenteditable="false"';
        const m = d.meta || {};
        const k = d.kunde || {};
        const num = v => (parseFloat(v) || 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const dm = s => s ? s.slice(8, 10) + '.' + s.slice(5, 7) + '.' : '';
        const dmy = s => s ? dm(s) + s.slice(0, 4) : '';
        const companyName = TENANT.name || 'Firma';
        const zipCity = ((TENANT.company_zip || '') + ' ' + (TENANT.company_city || '')).trim();
        const companyInfo = [TENANT.company_address, zipCity,
            TENANT.company_phone ? 'Tel: ' + TENANT.company_phone : '', TENANT.company_email].filter(Boolean).join(' | ');
        const senderLine = [companyName, [TENANT.company_address, zipCity].filter(Boolean).join(', ')].filter(Boolean).join(' · ');
        const metaRow = (key, label, value) => value
            ? `<div class="ocd-meta-row"><span class="ocd-meta-label" ${K('ab.lbl.' + key)}>${label}</span><span class="ocd-meta-value" ${E}>${esc(value)}</span></div>` : '';

        const posRows = (d.positions || []).map((p, i) => {
            const sub = [p.modell,
                p.tage_count ? p.tage_count + (p.tage_count === 1 ? ' Einsatztag' : ' Einsatztage') : '',
                p.datum_von ? dm(p.datum_von) + '–' + dmy(p.datum_bis) : '',
                (p.modell === 'Pro Stunde' && p.tage_count) ? num(p.stunden) + ' Std. gesamt' : '',
                p.bemerkung || ''].filter(Boolean).join(' · ');
            return `<tr>
                <td ${E}>${i + 1}</td>
                <td ${E}><strong>${esc(p.name)}</strong><div class="ocd-pos-sub">${esc(sub)}</div></td>
                <td class="ocd-num" ${E}>${num(p.menge)}</td>
                <td ${E}>${esc(p.einheit)}</td>
            </tr>`;
        }).join('');

        const bem = (d.bemerkungen || []).filter(Boolean);
        // No prices on the Auftragsbestätigung (user decision 2026-10-19): the
        // customer sees what he gets — positions, days and hours, no amounts.

        return `
            ${headerHtml(companyName, companyInfo)}

            <div class="ocd-sender" ${K('ab.sender')} data-draggable>${esc(senderLine)}</div>
            <div class="ocd-addr">
                <div class="ocd-recipient" ${E} data-draggable data-move="ab.recipient">
                    <strong>${esc(k.name || '')}</strong>
                    ${[k.zusatz, k.strasse, k.plz_ort, k.land].filter(Boolean).map(l => '<div>' + esc(l) + '</div>').join('')}
                </div>
                <div class="ocd-sender-block" ${K('ab.senderblock')} data-draggable>
                    <strong>${esc(companyName)}</strong>
                    ${[TENANT.company_address, zipCity, TENANT.company_phone, TENANT.company_email, TENANT.website]
                        .filter(Boolean).map(l => '<div>' + esc(l) + '</div>').join('')}
                </div>
            </div>

            <div class="doc-title" ${K('doc.title')} data-draggable>AUFTRAGSBESTÄTIGUNG</div>

            <div class="ocd-meta" data-draggable data-mkey="ab.meta">
                <div>
                    ${metaRow('auftragsnr', 'Auftrags-Nr.:', m.auftrags_nr)}
                    ${metaRow('kundennr', 'Kunden-Nr.:', m.kunden_nr)}
                    ${metaRow('bestellnr', 'Bestell-Nr.:', m.bestell_nr || '–')}
                    ${metaRow('zeitraum', 'Leistungszeitraum:', m.zeitraum)}
                </div>
                <div>
                    ${metaRow('datum', 'Datum:', m.datum || new Date().toLocaleDateString('de-DE'))}
                    ${(parseInt(m.version, 10) || 1) > 1 ? metaRow('version', 'Version:', String(m.version)) : ''}
                    ${metaRow('bearbeiter', 'Bearbeiter:', m.bearbeiter)}
                    ${metaRow('telefon', 'Telefon:', m.telefon)}
                    ${metaRow('email', 'E-Mail:', m.email)}
                </div>
            </div>

            ${d.lieferadresse ? `<div class="ocd-ort" id="ocdOrtRow" data-draggable data-mkey="ab.ort"><span class="ocd-meta-label" ${K('ab.lbl.ort')}>Leistungsort:</span> <strong ${E}>${esc(d.lieferadresse)}</strong></div>` : ''}

            ${bausteinHtml('intro', 'class="ocd-text" data-move="ab.intro"')}

            <div class="section section-flow">
                <table class="ocd-pos">
                    <thead><tr>
                        <th style="width:40px" ${K('ab.th.pos')}>Pos.</th><th ${K('ab.th.bez')}>Bezeichnung</th><th class="ocd-num" ${K('ab.th.menge')}>Menge</th>
                        <th ${K('ab.th.einheit')}>Einheit</th>
                    </tr></thead>
                    <tbody>${posRows || '<tr><td colspan="4" style="text-align:center;color:var(--gray-400);padding:20px">Keine Positionen</td></tr>'}</tbody>
                </table>
            </div>

            <table class="ocd-totals" id="ocdTotals" data-draggable data-mkey="ab.totals">
                <tr><td ${K('ab.tage')}>Einsatztage</td><td class="ocd-num" ${E}>${(d.positions || []).reduce((s, p) => s + (parseInt(p.tage_count, 10) || 0), 0)}</td></tr>
                <tr class="ocd-grand"><td ${K('ab.stunden')}>Gesamtstunden</td><td class="ocd-num" ${E}>${num((d.positions || []).reduce((s, p) => s + (parseFloat(p.stunden) || 0), 0))} Std.</td></tr>
            </table>

            ${bem.length ? `<div class="section" id="ocdNotes" data-draggable data-mkey="ab.notes">
                <div class="section-title" ${K('ab.sec.notes')}>BEMERKUNGEN</div>
                <div class="section-body ocd-notes" ${E}>${bem.map(b => esc(b).replace(/\n/g, '<br>')).join('<br><br>')}</div>
            </div>` : ''}

            ${bausteinHtml('closing', 'class="ocd-closing" id="ocdClosing"')}

            <div class="signature-section" id="signatureSection">
                <div class="signature-block" data-draggable data-key="sig.block.1">${docSigMedia()}<div class="signature-line" ${K('sig.1')}>${esc(companyName)}</div></div>
            </div>

            ${EXTRA_NOTE_HTML}

            ${legalFooterHtml()}

            ${docFloats()}
        `;
    }

    // Download file name per document type.
    function docFileName() {
        const clean = v => String(v || '').trim().replace(/[^\wäöüÄÖÜß.-]+/g, '_').replace(/^_+|_+$/g, '');
        if (state.docType === 'worker' && state.order) {
            return 'Stundennachweis_' + (clean(state.order.name) || 'Mitarbeiter') + '_' + (clean(state.order.monthLabel) || 'Monat') + '.pdf';
        }
        if (state.docType === 'ab' && state.order) {
            const v = parseInt((state.order.meta || {}).version || state.order.version, 10) || 1;
            return 'Auftragsbestaetigung_' + (clean(state.order.dokument_nr) || 'dokument') + (v > 1 ? '_V' + v : '') + '.pdf';
        }
        return 'Leistungsnachweis_' + (clean(state.orderId) || 'dokument') + '.pdf';
    }

    function buildSampleWorkerDoc() {
        return {
            name: 'Max Mustermann', objekt: 'Bürozentrum Nord', anschrift: 'Musterstraße 12, 10115 Berlin', monthLabel: 'Juni 2026',
            rows: [
                { datum: '02.06.2026', tag: 'Di', objekt: 'Bürozentrum Nord', von: '06:00', bis: '14:30', pause: 30, std: 8 },
                { datum: '03.06.2026', tag: 'Mi', objekt: 'Bürozentrum Nord', von: '06:00', bis: '14:30', pause: 30, std: 8 },
                { datum: '07.06.2026', tag: 'So', objekt: 'Lager Süd', von: '22:00', bis: '06:00', pause: 45, std: 7.25 },
                { datum: '10.06.2026', tag: 'Mi', objekt: 'Bürozentrum Nord', von: '14:00', bis: '22:00', pause: 30, std: 7.5 },
            ],
            totalStd: 30.75, totalPause: 135,
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // STUNDENNACHWEIS layout (?doc=worker) — mirrors tasks.js
    // maBuildWorkerPDFHtml (header, info strip, Einsätze table, summary)
    // with every text data-editable, plus the legal footer.
    // ═══════════════════════════════════════════════════════════════
    function buildWorkerTemplate(d) {
        const E = 'data-editable contenteditable="false"';
        const n2 = v => (parseFloat(v) || 0).toFixed(2).replace('.', ',');
        const companyName = TENANT.name || 'Firma';
        const companyInfo = [TENANT.company_address,
            ((TENANT.company_zip || '') + ' ' + (TENANT.company_city || '')).trim(),
            TENANT.company_phone ? 'Tel: ' + TENANT.company_phone : '', TENANT.company_email].filter(Boolean).join(' | ');
        // Only the counted (quarter-rounded) times are printed — no Plan
        // column, no real app stamps (both removed 2026-10-23).
        const rows = (d.rows || []).map((r, i) => `<tr>
                <td ${E}>${i + 1}</td>
                <td ${E}>${esc(r.datum)}</td>
                <td ${E}>${esc(r.tag)}</td>
                <td ${E}>${esc([r.objekt || '–', r.position].filter(Boolean).join(' · '))}</td>
                <td ${E}>${esc(r.von || '–')}</td>
                <td ${E}>${esc(r.bis || '–')}</td>
                <td ${E}>${r.pause > 0 ? r.pause + ' Min' : '–'}</td>
                <td class="ocd-num" ${E}>${r.std ? n2(r.std) : '–'}</td>
            </tr>`).join('');
        const count = (d.rows || []).length;
        const cols = 8;
        return `
            ${headerHtml(companyName, companyInfo)}

            <div class="doc-title" ${K('doc.title')} data-draggable>STUNDENNACHWEIS / MONATSABSCHLUSS</div>

            <div class="wkd-info" id="wkdInfo" data-draggable data-mkey="wk.info">
                <div><span ${K('wk.lbl.mitarbeiter')}>Mitarbeiter</span><strong ${E}>${esc(d.name || '')}</strong></div>
                <div><span ${K('wk.lbl.objekt')}>Objekt</span><strong ${E}>${esc(d.objekt || '–')}</strong></div>
                <div><span ${K('wk.lbl.anschrift')}>Anschrift</span><strong ${E}>${esc(d.anschrift || '–')}</strong></div>
                <div><span ${K('wk.lbl.zeit')}>Zeit</span><strong ${E}>${esc(d.monthLabel || '')}</strong></div>
                <div><span ${K('wk.lbl.einsaetze')}>Einsätze</span><strong ${E}>${count}</strong></div>
            </div>

            ${bausteinHtml('intro')}

            <div class="section section-flow">
                <div class="section-title"><span ${K('wk.sec.einsaetze')}>EINSÄTZE</span> (${count})</div>
                <table>
                    <thead><tr>
                        <th ${K('wk.th.nr')}>Nr.</th><th ${K('wk.th.datum')}>Datum</th><th ${K('wk.th.tag')}>Tag</th><th ${K('wk.th.objekt')}>Objekt</th><th ${K('wk.th.von')}>Von</th><th ${K('wk.th.bis')}>Bis</th><th ${K('wk.th.pause')}>MA-Pause</th><th class="ocd-num" ${K('wk.th.std')}>Std</th>
                    </tr></thead>
                    <tbody>${rows || `<tr><td colspan="${cols}" style="text-align:center;color:var(--gray-400);padding:20px">Keine Einsätze</td></tr>`}</tbody>
                    ${count ? `<tfoot><tr>
                        <td colspan="6" style="text-align:right" ${K('lbl.gesamt')}>Gesamt:</td>
                        <td ${E}>${d.totalPause > 0 ? d.totalPause + ' Min' : '–'}</td>
                        <td class="ocd-num" ${E}>${n2(d.totalStd)}</td>
                    </tr></tfoot>` : ''}
                </table>
                ${count ? `<div class="wkd-legend" ${K('wk.legend')} style="margin-top:8px;font-size:0.78em;line-height:1.45;color: var(--gray-500,var(--md-sys-color-on-surface-variant))">Von / Bis = angerechnete Arbeitszeit (auf Viertelstunden gerundet): Beginn frühestens zum Planbeginn, Ende spätestens zum Planende bzw. zum Ende einer begründeten Verlängerung. Std = Von bis Bis abzüglich Pause.</div>` : ''}
            </div>

            <div class="wkd-summary" id="summarySection" data-draggable data-mkey="wk.summary">
                <span><span ${K('wk.sum.einsaetze')}>Einsätze:</span> <strong>${count}</strong></span>
                <span><span ${K('wk.sum.stunden')}>Gesamtstunden:</span> <strong>${n2(d.totalStd)} Std</strong></span>
            </div>

            ${bausteinHtml('closing')}

            <div class="signature-section" id="signatureSection">
                <div class="signature-block" data-draggable data-key="sig.block.1"><div class="signature-line" ${K('sig.1')}>Datum, Unterschrift Mitarbeiter</div></div>
                <div class="signature-block" data-draggable data-key="sig.block.2">${docSigMedia()}<div class="signature-line" ${K('sig.2')}>Datum, Unterschrift ${esc(companyName)}</div></div>
            </div>

            ${EXTRA_NOTE_HTML}

            ${legalFooterHtml()}

            ${docFloats()}
        `;
    }

    function schichtBadge(art) {
        switch(art) {
            case 'feiertag':       return ['Feiertag', 'badge-feiertag'];
            case 'sonntag':        return ['Sonntag', 'badge-sonntag'];
            case 'nacht':          return ['Nacht', 'badge-nacht'];
            case 'nachtdienst':    return ['Nacht', 'badge-nacht'];
            case 'nacht_sonntag':  return ['Nacht+So', 'badge-nacht'];
            case 'sonntag_nacht':  return ['Nacht+So', 'badge-nacht'];
            case 'nacht_feiertag': return ['Nacht+FT', 'badge-nacht'];
            case 'feiertag_nacht': return ['Nacht+FT', 'badge-nacht'];
            case 'tagdienst':      return ['', 'badge-tag'];
            default:               return ['', 'badge-tag'];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PHASE 2 — CUSTOM (AI-generated) DESIGN
    // Renders the order through the tenant's generated HTML template
    // instead of buildTemplate(). It is injected straight into #orderForm
    // so every downstream pipeline (print, save, share, download) keeps
    // working unchanged — they all snapshot #orderForm.
    // ═══════════════════════════════════════════════════════════════
    function addDesignToggleButton() {
        if (!state.customTemplate) return;
        if (document.getElementById('btnDesignToggle')) return;
        const center = document.querySelector('.topbar-center');
        if (!center) return;
        const b = document.createElement('button');
        b.className = 'tb-btn';
        b.id = 'btnDesignToggle';
        b.title = 'Zwischen eigenem KI-Design und Standard-Layout wechseln';
        b.onclick = toggleDesign;
        b.innerHTML = '<i class="material-symbols-outlined">auto_fix_high</i> <span>Eigenes Design</span>';
        center.appendChild(b);
    }

    function toggleDesign() {
        setCustomDesign(!state.customDesignActive);
        toast(state.customDesignActive ? 'Eigenes Design aktiv' : 'Standard-Layout aktiv', 'success');
    }
    window.toggleDesign = toggleDesign;

    function setCustomDesign(active) {
        const page = document.getElementById('orderForm');
        if (!page) return;
        state.customDesignActive = active;
        const btn = document.getElementById('btnDesignToggle');

        if (active) {
            page.classList.add('custom-design');
            page.innerHTML = fillCustomTemplate(state.customTemplate, state.order || {});
            if (btn) btn.classList.add('active');
            setEditingButtonsDisabled(true);
            const info = document.getElementById('canvasInfo');
            if (info) info.style.display = 'none';
        } else {
            page.classList.remove('custom-design');
            page.innerHTML = buildTemplate(state.order || {});
            applySettingsToDocument();
            if (btn) btn.classList.remove('active');
            setEditingButtonsDisabled(false);
        }
    }

    function setEditingButtonsDisabled(disabled) {
        ['btnEdit', 'btnInsert', 'btnAnonymize', 'btnAnonymizeNu', 'btnSignature', 'btnStamp'].forEach(id => {
            const b = document.getElementById(id);
            if (!b) return;
            b.style.opacity = disabled ? '0.4' : '';
            b.style.pointerEvents = disabled ? 'none' : '';
        });
    }

    // Build the COMPLETE Einsatztage table (header + rows) with self-contained
    // inline styling, so it always renders correctly regardless of how the
    // AI laid out the surrounding template. One row per unique day.
    function buildEinsatzTable(order) {
        const all = [...(order.confirmed_workers || []), ...(order.confirmed_nu_workers || [])];
        const seen = {};
        const days = [];
        all.forEach(w => { if (w.datum && !seen[w.datum]) { seen[w.datum] = 1; days.push(w); } });
        days.sort((a, b) => (a.datum < b.datum ? -1 : a.datum > b.datum ? 1 : 0));

        const rows = days.map((w, i) => {
            const d = w.datum ? new Date(w.datum + 'T00:00:00') : null;
            const dayName = d ? DAYS[d.getDay()] : '–';
            const von = formatTime(w.ist_start || order.zeit_von);
            const bis = formatTime(w.ist_ende || order.zeit_bis);
            const std = parseFloat(w.ist_std ?? 0) || 0;
            return [
                String(i + 1), formatDate(w.datum), dayName, von, bis,
                std ? std.toFixed(2).replace('.', ',') : '–', '',
            ];
        });
        return einsatzTableHtml(rows);
    }

    // Shared table renderer (header + body) — see also pdf_design.js.
    function einsatzTableHtml(rows) {
        const head = ['Nr.', 'Datum', 'Wochentag', 'Zeit von', 'Zeit bis', 'Soll-Std', 'Bemerkung'];
        const thStyle = 'text-align:left;padding:9px 10px;font-size:11px;font-weight:600;color:var(--gray-500);text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #cbd5e1;';
        const tdStyle = 'padding:8px 10px;font-size:12.5px;color:var(--gray-800);border-bottom:1px solid #eef2f7;';
        const thead = '<tr>' + head.map(h => `<th style="${thStyle}">${h}</th>`).join('') + '</tr>';
        const body = rows.length
            ? rows.map(r => '<tr>' + r.map((c, i) => `<td style="${tdStyle}${i === 0 ? 'width:42px;color:var(--gray-500);' : ''}">${c}</td>`).join('') + '</tr>').join('')
            : '<tr><td colspan="7" style="padding:18px;text-align:center;color:var(--gray-400);font-size:12.5px">Keine Einsatztage</td></tr>';
        return `<table style="width:100%;border-collapse:collapse;margin-top:6px">`
            + `<thead>${thead}</thead><tbody>${body}</tbody></table>`;
    }

    // Replace the fixed placeholders in the generated template with real data,
    // then sanitise (DOMPurify) before it is injected into the page.
    function fillCustomTemplate(tpl, order) {
        const today = new Date().toLocaleDateString('de-DE');
        const companyName = TENANT.name || 'Firma';
        const companyInfo = [
            TENANT.company_address,
            ((TENANT.company_zip || '') + ' ' + (TENANT.company_city || '')).trim(),
            TENANT.company_phone ? 'Tel: ' + TENANT.company_phone : '',
            TENANT.company_email,
        ].filter(Boolean).join(' | ');
        const logoImg = (state.logoDataUrl && !state.settings.hide_logo)
            ? `<img src="${state.logoDataUrl}" alt="Logo" style="max-width:120px;max-height:70px;object-fit:contain">`
            : '';
        const footer = [companyName, companyInfo].filter(Boolean).join(' · ');

        const map = {
            company_name:  esc(companyName),
            company_info:  esc(companyInfo),
            logo:          logoImg,
            doc_title:     'LEISTUNGSNACHWEIS / EINSATZPLAN',
            recipient:     esc(order.kundenname || order.kunden_id || ''),
            auftragsdatum: today,
            auftrags_nr:   esc(order.bestell_id || ''),
            kunde:         esc(order.kundenname || order.kunden_id || ''),
            objekt:        esc(order.objektname || order.obj_id || ''),
            monat:         esc(((MONTHS[order.monat] || '') + ' ' + (order.jahr || '')).trim()),
            arbeitszeit:   esc(formatTime(order.zeit_von) + ' – ' + formatTime(order.zeit_bis)),
            nu:            esc(nuDisplayName(order)),
            einsatz_table: buildEinsatzTable(order),
            einsatz_rows:  buildEinsatzTable(order), // legacy templates: drop a full table in
            footer:        esc(footer),
        };

        let out = tpl;
        Object.keys(map).forEach(k => {
            out = out.replace(new RegExp('\\{\\{\\s*' + k + '\\s*\\}\\}', 'g'), map[k]);
        });
        if (window.DOMPurify) {
            out = window.DOMPurify.sanitize(out, { ADD_TAGS: ['style'], ADD_ATTR: ['style'] });
        }
        return out;
    }

    // ═══════════════════════════════════════════════════════════════
    // ANONYMIZE WORKERS
    // ═══════════════════════════════════════════════════════════════
    function toggleAnonymize() {
        state.anonymize = !state.anonymize;
        document.getElementById('btnAnonymize').classList.toggle('active', state.anonymize);
        rerenderDocument();
        toast(state.anonymize ? 'Mitarbeiternamen ausgeblendet' : 'Mitarbeiternamen sichtbar', 'success');
    }
    window.toggleAnonymize = toggleAnonymize;

    // Generic label shown wherever the NU (subcontractor) company name would
    // normally appear — the NU detail row, the {{nu}} template placeholder and
    // the "(Firmenname)" suffix on NU worker rows.
    function nuDisplayName(order) {
        const name = order.nu_name || order.nu_id;
        return (state.anonymizeNu && name) ? 'Nachunternehmer' : (name || '–');
    }

    function toggleAnonymizeNu() {
        state.anonymizeNu = !state.anonymizeNu;
        document.getElementById('btnAnonymizeNu').classList.toggle('active', state.anonymizeNu);
        rerenderDocument();
        toast(state.anonymizeNu ? 'NU-Zeile ausgeblendet' : 'NU-Zeile sichtbar', 'success');
    }
    window.toggleAnonymizeNu = toggleAnonymizeNu;

    // Shared re-render used by every document-content toggle (anonymize,
    // signature, stamp): snapshots the current edits (texts, inserted /
    // moved elements, sections) and re-applies them on the fresh markup, so
    // nothing the user changed is lost.
    function rerenderDocument() {
        if (!state.order) return;
        renderDoc(collectLayout());
    }

    // ── Signatur & Stempel on the document ───────────────────────────
    // The two topbar buttons work in viewer AND editor mode. When no
    // image is uploaded yet the button opens a picker and uploads to
    // /api/pdf/upload-{signature,stamp}; afterwards it toggles the
    // image on/off the company-side signature block.
    const DOC_ASSETS = {
        signature: { btn: 'btnSignature', url: 'signatureUrl', flag: 'showSignature', pos: 'sigPos',   label: 'Unterschrift', defW: 180 },
        stamp:     { btn: 'btnStamp',     url: 'stampUrl',     flag: 'showStamp',     pos: 'stampPos', label: 'Stempel',      defW: 130 },
    };

    function syncDocAssetButtons() {
        Object.keys(DOC_ASSETS).forEach(kind => {
            const a = DOC_ASSETS[kind];
            const btn = document.getElementById(a.btn);
            if (!btn) return;
            (btn.closest('.dd-switch-row') || btn).style.display = CAN_EDIT_DOC ? '' : 'none';
            btn.classList.toggle('active', !!state[a.flag]);
            if (btn.type === 'checkbox') btn.checked = !!state[a.flag];
            const hint = document.getElementById(kind === 'signature' ? 'ddSigHint' : 'ddStampHint');
            if (hint && !state[a.url]) hint.textContent = 'Noch nicht hochgeladen — Konto & Firma › Mein Profil › Signatur & Stempel';
        });
    }

    function toggleDocAsset(kind) {
        const a = DOC_ASSETS[kind];
        if (!a || !CAN_EDIT_DOC) return;
        if (!state[a.url]) {
            // No upload here — assets are managed centrally in Mein Profil
            // (per-user + company default). Point the user there instead.
            toast('„' + a.label + '“ ist noch nicht hochgeladen — bitte zuerst in „Mein Profil“ unter „Signatur & Stempel“ hochladen.', 'warning', 7000);
            syncDocAssetButtons();
            return;
        }
        state[a.flag] = !state[a.flag];
        persistDocAssets();
        syncDocAssetButtons();
        rerenderDocument();
        toast(state[a.flag] ? a.label + ' auf dem Dokument' : a.label + ' entfernt', 'success');
    }
    window.toggleDocAsset = toggleDocAsset;

    // Signature/stamp images live in .doc-asset-wrap elements. Until the
    // user drags them they sit inline above the signature line (default
    // spot); after the first drag they are rendered position:absolute at
    // the stored doc-pixel coordinates — which is exactly what html2pdf
    // captures, so the exported PDF matches the screen 1:1.
    function assetWrapHtml(kind) {
        const a = DOC_ASSETS[kind];
        const p = state[a.pos];
        const rot = p && p.r ? 'transform:rotate(' + Math.round(p.r) + 'deg);' : '';
        const style = p
            ? 'position:absolute;left:' + Math.round(p.x) + 'px;top:' + Math.round(p.y) + 'px;width:' + Math.round(p.w) + 'px;z-index:60;' + rot
            : 'width:' + a.defW + 'px;';
        return '<div class="doc-asset-wrap" id="docAsset-' + kind + '" data-kind="' + kind + '" style="' + style + '">'
            + '<img class="doc-asset-img" src="' + state[a.url] + '" alt="" draggable="false">'
            + '<span class="doc-asset-remove" title="Entfernen">&times;</span>'
            + '<span class="doc-asset-rot" title="Ziehen: drehen · Shift: 15°-Schritte">&#8635;</span>'
            + '<span class="doc-asset-grip" title="Ziehen: verschieben · Ecke: Größe · Doppelklick: zurücksetzen"></span>'
            + '</div>';
    }

    function docSigMedia() {
        const parts = [];
        if (state.showSignature && state.signatureUrl && !state.sigPos)   parts.push(assetWrapHtml('signature'));
        if (state.showStamp && state.stampUrl && !state.stampPos)         parts.push(assetWrapHtml('stamp'));
        return parts.length ? '<div class="sig-media">' + parts.join('') + '</div>' : '';
    }

    // Floated wraps are appended at the end of the page markup so they sit
    // directly inside .page (the positioned ancestor they measure against).
    function docFloats() {
        let out = '';
        if (state.showSignature && state.signatureUrl && state.sigPos)   out += assetWrapHtml('signature');
        if (state.showStamp && state.stampUrl && state.stampPos)         out += assetWrapHtml('stamp');
        return out;
    }

    // Signature/stamp show + position are part of the company layout
    // template (collectLayout → "Speichern"); nothing is kept per browser.
    function persistDocAssets() {}

    // ── Drag & resize layer (works in viewer AND editor mode) ─────────
    // Pointer Events cover mouse + iOS/Android touch. Coordinates are
    // divided by the current fit-to-viewport scale so dragging feels
    // 1:1 even when the sheet is scaled down on a phone.
    let _assetDrag = null;

    function pageScaleFactor() {
        const page = document.getElementById('orderForm');
        const m = page && (page.style.transform || '').match(/scale\(([\d.]+)\)/);
        return m ? parseFloat(m[1]) : 1;
    }

    function onAssetPointerDown(e) {
        const grip = e.target.closest('.doc-asset-grip');
        const rotH = e.target.closest('.doc-asset-rot');
        const wrap = e.target.closest('.doc-asset-wrap');
        if (!wrap) return;
        const kind = wrap.dataset.kind;
        const a = DOC_ASSETS[kind];
        if (!a) return;
        e.preventDefault();
        // Red ✕ on the asset — remove it from the document directly
        // (same as toggling the topbar button off) and drop its saved
        // position so the next placement starts at the default spot.
        if (e.target.closest('.doc-asset-remove')) {
            state[a.flag] = false;
            state[a.pos] = null;
            persistDocAssets();
            syncDocAssetButtons();
            rerenderDocument();
            toast(a.label + ' entfernt', 'success');
            return;
        }
        const s = pageScaleFactor();
        const pr = document.getElementById('orderForm').getBoundingClientRect();
        const r  = wrap.getBoundingClientRect();
        if (rotH) {
            // Rotate around the element's own center. floatAssetNow() may
            // re-parent the wrap into .page, so measure the center AFTER.
            // startA captures the pointer's initial angle so the drag is
            // relative — otherwise a corner handle would snap ~45° on the
            // first move.
            floatAssetNow(wrap, kind, pr, r, s);
            const cr = wrap.getBoundingClientRect();
            const cx = cr.left + cr.width / 2, cy = cr.top + cr.height / 2;
            const r0 = (state[a.pos] && state[a.pos].r) || 0;
            _assetDrag = { kind, mode: 'rotate', s, pr, cx, cy,
                           startA: Math.atan2(e.clientY - cy, e.clientX - cx) * 180 / Math.PI,
                           startR: r0, deg: r0 };
        } else if (grip) {
            floatAssetNow(wrap, kind, pr, r, s);
            _assetDrag = { kind, mode: 'resize', s, pr, startX: e.clientX, startW: r.width / s };
        } else {
            floatAssetNow(wrap, kind, pr, r, s);
            _assetDrag = { kind, mode: 'drag', s, pr, offX: (e.clientX - r.left) / s, offY: (e.clientY - r.top) / s };
        }
        try { wrap.setPointerCapture(e.pointerId); } catch (_) {}
        wrap.classList.add('doc-asset-active');
    }

    // First interaction "floats" an inline wrap: it keeps its visual spot
    // but becomes position:absolute (measured against .page, in doc px).
    // The coords only anchor correctly if the wrap is a direct .page child
    // — so it is moved out of .sig-media (which is itself positioned and
    // would otherwise steal the anchor → the image flies off-page).
    function floatAssetNow(wrap, kind, pr, r, s) {
        if (state[DOC_ASSETS[kind].pos]) return;
        const p = { x: (r.left - pr.left) / s, y: (r.top - pr.top) / s, w: r.width / s };
        state[DOC_ASSETS[kind].pos] = p;
        const page = document.getElementById('orderForm');
        if (page && wrap.parentNode !== page) page.appendChild(wrap);
        wrap.style.position = 'absolute';
        wrap.style.left = p.x + 'px';
        wrap.style.top = p.y + 'px';
        wrap.style.width = p.w + 'px';
        wrap.style.zIndex = '60';
    }

    function onAssetPointerMove(e) {
        if (!_assetDrag) return;
        const d = _assetDrag;
        const wrap = document.getElementById('docAsset-' + d.kind);
        if (!wrap) { _assetDrag = null; return; }
        if (d.mode === 'resize') {
            wrap.style.width = Math.max(48, d.startW + (e.clientX - d.startX) / d.s) + 'px';
        } else if (d.mode === 'rotate') {
            // Relative rotation: current pointer angle minus the angle at
            // pointerdown, added to the rotation the element started with.
            // Client-space math — the page scale factor cancels out.
            const ang = Math.atan2(e.clientY - d.cy, e.clientX - d.cx) * 180 / Math.PI;
            let deg = d.startR + Math.round(ang - d.startA);
            if (e.shiftKey) deg = Math.round(deg / 15) * 15;
            d.deg = ((deg % 360) + 360) % 360;
            wrap.style.transform = 'rotate(' + d.deg + 'deg)';
        } else {
            wrap.style.left = ((e.clientX - d.pr.left) / d.s - d.offX) + 'px';
            wrap.style.top  = ((e.clientY - d.pr.top)  / d.s - d.offY) + 'px';
        }
    }

    function onAssetPointerUp() {
        if (!_assetDrag) return;
        const d = _assetDrag;
        _assetDrag = null;
        const wrap = document.getElementById('docAsset-' + d.kind);
        if (wrap) {
            wrap.classList.remove('doc-asset-active');
            const a = DOC_ASSETS[d.kind];
            const prevR = (state[a.pos] && state[a.pos].r) || 0;
            state[a.pos] = {
                x: parseFloat(wrap.style.left)  || 0,
                y: parseFloat(wrap.style.top)   || 0,
                w: parseFloat(wrap.style.width) || a.defW,
                r: d.mode === 'rotate' ? d.deg : prevR,
            };
            persistDocAssets();
        }
    }

    // Double-click / double-tap drops the custom position — the asset
    // returns to its default spot above the signature line.
    function onAssetDblClick(e) {
        const wrap = e.target.closest('.doc-asset-wrap');
        if (!wrap) return;
        const a = DOC_ASSETS[wrap.dataset.kind];
        if (!a || !state[a.pos]) return;
        state[a.pos] = null;
        persistDocAssets();
        rerenderDocument();
        toast(a.label + ' zurückgesetzt', 'success');
    }

    // ═══════════════════════════════════════════════════════════════
    // RESET TO DEFAULT
    // Reverts ALL customizations (design, visibility, note, hidden logo,
    // anonymize, and any inserted/moved free-form elements) and re-renders
    // the document from the original order data. The company logo itself
    // (tenant logo_path) is NOT deleted — only shown again.
    // ═══════════════════════════════════════════════════════════════
    async function resetToDefault() {
        if (!confirm('Vorlage „' + DOC_LABEL[state.docType] + '“ auf Standard zurücksetzen?\n\nAlle Anpassungen (Texte, Sichtbarkeit, Notiz, eingefügte Elemente, Unterschrift/Stempel, Farben und Schrift) werden für ALLE Dokumente dieses Typs zurückgesetzt. Die anderen Dokumenttypen bleiben unverändert.')) return;

        state.anonymize = false;
        state.anonymizeNu = false;
        ['btnAnonymize', 'btnAnonymizeNu'].forEach(i => { const b = document.getElementById(i); if (b) b.classList.remove('active'); });

        // 1) Re-render with the built-in layout + default branding of THIS
        //    type only (the other types and the company default stay).
        state.layout = { branding: { ...BRANDING_DEFAULTS } };
        renderDoc(state.layout);
        if (DESIGN_UI) ddRenderTexte();

        // 2) Persist it as this type's template.
        if (CAN_SAVE_LAYOUT) {
            try {
                await saveLayoutToServer();
            } catch (e) {
                toast('Vorlage konnte nicht zurückgesetzt werden: ' + e.message, 'error', 6000);
                return;
            }
        }
        toast('Auf Standard zurückgesetzt', 'success');
    }
    window.resetToDefault = resetToDefault;

    // ═══════════════════════════════════════════════════════════════
    // SETTINGS PANEL
    // ═══════════════════════════════════════════════════════════════
    function toggleSettings() {
        const sb = document.getElementById('sidebar');
        const btn = document.getElementById('btnSettings');
        sb.classList.toggle('collapsed');
        btn.classList.toggle('active');
    }
    window.toggleSettings = toggleSettings;

    function applySettingsToUI() {
        // Sidebar fields exist only in Dokumentvorlagen (?design=1).
        const setF = (id, v, prop) => { const el = document.getElementById(id); if (el) el[prop || 'value'] = v; };
        setF('settingPrimary', state.settings.primary_color);
        setF('settingAccent', state.settings.accent_color);
        setF('settingFont', state.settings.font_family);
        setF('settingSize', state.settings.font_size);
        setF('settingLogoPos', state.settings.logo_position || 'right');
        setF('extraNote', state.settings.extra_note || '');
        setF('showZuschColumn', state.settings.show_zuschlaege !== false, 'checked');
        // Section-visibility checkboxes mirror the hide_extras list.
        const hidden = state.settings.hide_extras || [];
        const secMap = { showSummary: 'summarySection', showSignature: 'signatureSection', showFooter: 'footerSection', showNu: 'nuRow', showHeaderRule: 'headerRule' };
        Object.keys(secMap).forEach(cbId => {
            const cb = document.getElementById(cbId);
            if (cb) cb.checked = !hidden.includes(secMap[cbId]);
        });
        renderColToggles('colToggles', false);
        syncPdfColsUI();
        if (DESIGN_UI) ddSyncTemplates();
    }

    function applySettingsToDocument() {
        const root = document.documentElement;
        root.style.setProperty('--primary', state.settings.primary_color);
        root.style.setProperty('--accent',  state.settings.accent_color);
        const page = document.getElementById('orderForm');
        if (!page) return;
        page.style.fontFamily = state.settings.font_family;
        DD_TEMPLATES.forEach(t => page.classList.toggle('tpl-' + t.id, t.id !== 'klassisch' && state.settings.design === t.id));
        const sizes = { small: '12px', medium: '14px', large: '16px' };
        page.style.fontSize = sizes[state.settings.font_size] || '14px';
        // Table columns (Zuschlag-Spalten group + every single column)
        applyColumnVisibility(page);
        // Hide-extras
        (state.settings.hide_extras || []).forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        // Show/hide the company logo on THIS document only (the tenant
        // logo_path itself is never touched — see removeLogo()).
        const docLogoEl = document.getElementById('docLogo');
        if (docLogoEl) docLogoEl.style.display = state.settings.hide_logo ? 'none' : '';
        // Logo position (left = above company name, center, right). Toggling
        // classes lets the change apply live without rebuilding the header.
        const headerEl = page.querySelector('.header');
        if (headerEl) {
            headerEl.classList.remove('logo-left', 'logo-center');
            if (state.settings.logo_position === 'left') headerEl.classList.add('logo-left');
            else if (state.settings.logo_position === 'center') headerEl.classList.add('logo-center');
        }
    }

    // Persist the company DEFAULT branding (tenant.settings.pdf_branding via
    // PUT /pdf/branding). Only the first-run wizard writes it; document
    // types with their own template branding are not affected by it.
    let _brandSaveTimer = null;
    let _brandErrShown = false;
    function saveBrandingToServer(immediate) {
        if (!CAN_SAVE_LAYOUT) {
            if (!_brandErrShown) { _brandErrShown = true; toast('Keine Berechtigung — Farben/Schrift gelten nur in dieser Ansicht und werden nicht gespeichert.', 'warning', 6000); }
            return Promise.resolve(false);
        }
        clearTimeout(_brandSaveTimer);
        return new Promise(resolve => {
            _brandSaveTimer = setTimeout(async () => {
                try {
                    const b = {};
                    BRANDING_KEYS.forEach(k => { b[k] = state.settings[k]; });
                    const res = await fetch(API + '/pdf/branding', {
                        method: 'PUT',
                        headers: { ...HEADERS(), 'Content-Type': 'application/json' },
                        body: JSON.stringify({ pdf_branding: b }),
                    });
                    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || ('HTTP ' + res.status));
                    _brandErrShown = false;
                    resolve(true);
                } catch (e) {
                    if (!_brandErrShown) { _brandErrShown = true; toast('Firmenfarben konnten nicht gespeichert werden: ' + e.message, 'error', 6000); }
                    resolve(false);
                }
            }, immediate ? 0 : 600);
        });
    }

    // Colours/font/logo position belong to the template of THIS document
    // type (layout.branding) — saved with „Speichern“, never company-wide.
    let _brandHintShown = false;
    function brandingChanged() {
        applySettingsToDocument();
        if (!_brandHintShown && !state.wizardOpen) {
            _brandHintShown = true;
            toast(DESIGN_UI ? 'Gilt für alle „' + DOC_LABEL[state.docType] + '“-Dokumente — wird automatisch gespeichert'
                : 'Gilt nur für „' + DOC_LABEL[state.docType] + '“ — mit „Speichern“ übernehmen', 'success', 4000);
        }
    }
    function setPrimary(v)    { state.settings.primary_color = v; brandingChanged(); }
    function setAccent(v)     { state.settings.accent_color = v; brandingChanged(); }
    function setFontFamily(v) { state.settings.font_family = v; brandingChanged(); }
    function setFontSize(v)   { state.settings.font_size = v; brandingChanged(); }
    // Choosing a position puts a hand-dragged logo back into the header
    // layout — otherwise the choice would have no visible effect.
    function setLogoPosition(v){
        state.settings.logo_position = v;
        if (window.FE) window.FE.unfloat(document.getElementById('docLogo'));
        brandingChanged();
    }
    window.setPrimary = setPrimary;
    window.setAccent = setAccent;
    window.setFontFamily = setFontFamily;
    window.setFontSize = setFontSize;
    window.setLogoPosition = setLogoPosition;

    function toggleSection(id, show) {
        const el = document.getElementById(id);
        if (el) el.style.display = show ? '' : 'none';
        const extras = state.settings.hide_extras || [];
        const idx = extras.indexOf(id);
        if (!show && idx === -1) extras.push(id);
        if (show && idx !== -1) extras.splice(idx, 1);
        state.settings.hide_extras = extras;
    }
    window.toggleSection = toggleSection;

    // ── Table column visibility ───────────────────────────────────────
    // Template: hide_extras holds 'col.<header data-key>' (e.g.
    // 'col.th.pause'); 'colZuschlag' is the older spelling of
    // 'col.th.zuschlag'. show_zuschlaege=false hides Schicht + Zuschlag.
    // The simple viewer overrides both for THIS PDF via state.pdfCols —
    // never written into the template (collectLayout ignores pdfCols).
    const COL_KEY_RE = /^(th|ab\.th|wk\.th)\./;
    const ZUSCH_KEYS = ['th.schicht', 'th.zuschlag'];

    function templateHiddenCols() {
        const set = new Set();
        (state.settings.hide_extras || []).forEach(id => {
            if (id === 'colZuschlag') set.add('th.zuschlag');
            else if (id.startsWith('col.')) set.add(id.slice(4));
        });
        return set;
    }
    function effectiveCols(perPdf) {
        const o = (perPdf && state.pdfCols) || { zusch: null, cols: {} };
        const showZusch = o.zusch !== null && o.zusch !== undefined ? !!o.zusch : state.settings.show_zuschlaege !== false;
        const hidden = templateHiddenCols();
        Object.keys(o.cols || {}).forEach(k => { if (o.cols[k]) hidden.delete(k); else hidden.add(k); });
        if (!showZusch) ZUSCH_KEYS.forEach(k => hidden.add(k));
        return { showZusch, hidden };
    }

    // Hide whole columns of every keyed table: header, body and footer
    // cells; a cell spanning a hidden column (e.g. "Gesamt:") shrinks its
    // colspan. Re-runnable — the original span is kept in data-span.
    function applyColumnVisibility(page) {
        const { hidden } = effectiveCols(true);
        page.querySelectorAll('table').forEach(tbl => {
            const head = tbl.tHead && tbl.tHead.rows[0];
            if (!head || !head.querySelector('th[data-key]')) return;
            const cols = new Set();
            let c = 0;
            [...head.cells].forEach(cell => {
                const span = +(cell.dataset.span || cell.colSpan || 1);
                if (cell.dataset.key && hidden.has(cell.dataset.key)) for (let i = 0; i < span; i++) cols.add(c + i);
                c += span;
            });
            if (cols.size >= c) cols.clear(); // never hide every column
            [...tbl.rows].forEach(row => {
                let col = 0;
                [...row.cells].forEach(cell => {
                    if (!cell.dataset.span) cell.dataset.span = cell.colSpan || 1;
                    const span = +cell.dataset.span;
                    let off = 0;
                    for (let i = 0; i < span; i++) if (cols.has(col + i)) off++;
                    col += span;
                    cell.style.display = off >= span ? 'none' : '';
                    cell.colSpan = Math.max(1, span - off);
                });
            });
        });
        applyPauseHours(page, hidden.has('th.pause'));
    }

    // Leistungsnachweis: Pause column visible → Std = Von/Bis − Pause;
    // hidden → Std incl. the paid pause (= billed customer hours). Row
    // cells carry both values (data-std-g / data-std-n); Gesamt and the
    // Zusammenfassung are re-summed. A cell the user typed over by hand
    // keeps its text (and its number counts in the totals).
    function applyPauseHours(page, pauseHidden) {
        const cells = [...page.querySelectorAll('td[data-std-g]')];
        if (!cells.length) return;
        const fmt = v => v ? v.toFixed(2).replace('.', ',') : '–';
        const num = t => { const v = parseFloat(String(t).replace(/\./g, '').replace(',', '.')); return Number.isFinite(v) ? v : 0; };
        const isOurs = (el, vals) => vals.some(v => el.textContent.trim() === fmt(v)) || el.textContent.trim() === (el.dataset.stdLast || '');
        let total = 0;
        const byArt = {};
        cells.forEach(td => {
            const g = parseFloat(td.dataset.stdG) || 0, n = parseFloat(td.dataset.stdN) || 0;
            let v = pauseHidden ? g : n;
            if (isOurs(td, [g, n])) { td.textContent = fmt(v); td.dataset.stdLast = fmt(v); }
            else v = num(td.textContent);
            total += v;
            const art = td.dataset.stdArt || 'tagdienst';
            byArt[art] = (byArt[art] || 0) + v;
        });
        const setTotal = (el, v) => {
            const prev = el.dataset.stdLast || el.textContent.trim();
            if (el.textContent.trim() !== prev) return;          // edited by hand
            el.textContent = v.toFixed(2).replace('.', ',');
            el.dataset.stdLast = el.textContent;
        };
        page.querySelectorAll('[data-std-total]').forEach(el => setTotal(el, total));
        page.querySelectorAll('[data-std-art-total]').forEach(el => setTotal(el, byArt[el.dataset.stdArtTotal] || 0));
    }

    // Checkbox list of all columns of the document's table(s).
    // perPdf=false → Sichtbarkeit (template), true → viewer panel.
    function renderColToggles(boxId, perPdf) {
        const box = document.getElementById(boxId);
        const page = document.getElementById('orderForm');
        if (!box || !page) return;
        const { showZusch, hidden } = effectiveCols(perPdf);
        const seen = new Set();
        const ths = [...page.querySelectorAll('thead th[data-key]')].filter(th => {
            const k = th.dataset.key;
            if (!COL_KEY_RE.test(k) || seen.has(k)) return false;
            seen.add(k);
            return true;
        });
        box.innerHTML = ths.map(th => {
            const k = th.dataset.key;
            const groupOff = !showZusch && ZUSCH_KEYS.includes(k);
            const label = th.textContent.trim() || k;
            return '<label class="sb-toggle' + (groupOff ? ' is-disabled' : '') + '"><input type="checkbox" data-col="' + esc(k) + '"'
                + (hidden.has(k) ? '' : ' checked') + (groupOff ? ' disabled' : '') + '> ' + esc(label) + '</label>';
        }).join('');
        box.querySelectorAll('input[data-col]').forEach(cb => {
            cb.addEventListener('change', () => setColumnVisible(cb.dataset.col, cb.checked, perPdf, cb));
        });
    }

    function setColumnVisible(key, show, perPdf, cb) {
        const { hidden } = effectiveCols(perPdf);
        const total = document.querySelectorAll('#orderForm thead th[data-key]').length;
        if (!show && !hidden.has(key) && hidden.size + 1 >= total) {
            if (cb) cb.checked = true;
            toast('Mindestens eine Spalte muss sichtbar bleiben', 'warning');
            return;
        }
        if (perPdf) {
            state.pdfCols.cols[key] = !!show;
        } else {
            const ex = (state.settings.hide_extras || []).filter(id => id !== 'col.' + key && !(key === 'th.zuschlag' && id === 'colZuschlag'));
            if (!show) ex.push('col.' + key);
            state.settings.hide_extras = ex;
        }
        applySettingsToDocument();
        renderColToggles(perPdf ? 'pcCols' : 'colToggles', perPdf);
    }

    // Simple viewer (?view=1): "Einstellungen" = column switches for this
    // PDF only — Download / Teilen / Speichern use them, the company
    // template is left untouched.
    function syncPdfColsUI() {
        const wrap = document.getElementById('pdfColsWrap');
        if (!wrap) return;
        wrap.style.display = window.__VIEWER_ONLY__ ? '' : 'none';
        const zRow = document.getElementById('pcZuschRow');
        if (zRow) zRow.style.display = state.docType === 'order' ? '' : 'none';
        const a = document.getElementById('pcZusch');
        if (a) a.checked = effectiveCols(true).showZusch;
        renderColToggles('pcCols', true);
    }
    function togglePdfColsPanel(ev) {
        if (ev) ev.stopPropagation();
        const p = document.getElementById('pdfColsPanel');
        if (!p) return;
        syncPdfColsUI();
        p.classList.toggle('show');
    }
    function setPdfCol(which, show) {
        state.pdfCols[which] = !!show;
        applySettingsToDocument();
        syncPdfColsUI();
    }
    window.setPdfZusch = (show) => setPdfCol('zusch', show);
    document.addEventListener('click', (e) => {
        const p = document.getElementById('pdfColsPanel');
        if (p && p.classList.contains('show') && !e.target.closest('#pdfColsWrap')) p.classList.remove('show');
    });
    window.togglePdfColsPanel = togglePdfColsPanel;
    window.setPdfCol = setPdfCol;

    function toggleZuschColumns(show) {
        state.settings.show_zuschlaege = !!show;
        applySettingsToDocument();
        renderColToggles('colToggles', false);
    }
    window.toggleZuschColumns = toggleZuschColumns;

    function updateExtraNote() {
        state.settings.extra_note = document.getElementById('extraNote').value.trim();
        renderExtraNote();
    }
    window.updateExtraNote = updateExtraNote;

    // ═══════════════════════════════════════════════════════════════
    // LOGO
    // ═══════════════════════════════════════════════════════════════
    // Uploads the COMPANY logo (tenant logo_path — used by every document)
    // first and only shows it once the server accepted it, so the user never
    // sees a logo that was silently rejected.
    async function handleLogoUpload(input) {
        const file = input.files && input.files[0];
        input.value = '';
        if (!file) return;
        if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) { toast('Nur PNG, JPG oder WEBP erlaubt', 'error'); return; }
        if (file.size > 2 * 1024 * 1024) { toast('Logo zu groß — maximal 2 MB', 'error'); return; }
        const fd = new FormData();
        fd.append('logo', file);
        try {
            const res = await fetch(API + '/pdf/upload-logo', {
                method: 'POST', headers: { 'Authorization': 'Bearer ' + TOKEN, 'Accept': 'application/json' }, body: fd
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) throw new Error(data.error || data.message || ('HTTP ' + res.status));
            TENANT.logo_path = data.logo_path;
            state.logoDataUrl = API + '/serve?path=' + encodeURIComponent(data.logo_path);
            // A freshly uploaded logo should be visible on the document.
            state.settings.hide_logo = false;
            rerenderDocument();
            syncLogoUI();
            syncWizardLogoPreview();
            toast('Firmenlogo gespeichert', 'success');
        } catch (e) {
            toast('Logo-Upload fehlgeschlagen: ' + e.message, 'error', 6000);
        }
    }
    window.handleLogoUpload = handleLogoUpload;

    // Reflect the current logo state in the sidebar: thumbnail when shown,
    // an "ausgeblendet" hint when hidden, or the upload placeholder when no
    // company logo exists. Toggles the matching Entfernen/Anzeigen button.
    function syncLogoUI() {
        const preview   = document.getElementById('logoPreview');
        const removeBtn = document.getElementById('btnRemoveLogo');
        const showBtn   = document.getElementById('btnShowLogo');
        const hasLogo = !!state.logoDataUrl;
        const hidden  = !!state.settings.hide_logo;
        if (preview) {
            if (hasLogo && !hidden) {
                preview.innerHTML = '<img src="' + state.logoDataUrl + '" alt="Logo">';
            } else if (hasLogo && hidden) {
                preview.innerHTML = '<i class="material-symbols-outlined">visibility_off</i><div>Logo ausgeblendet</div>';
            } else {
                preview.innerHTML = '<i class="material-symbols-outlined">cloud_upload</i><div>Logo hochladen</div>';
            }
        }
        if (removeBtn) removeBtn.style.display = (hasLogo && !hidden) ? '' : 'none';
        if (showBtn)   showBtn.style.display   = (hasLogo && hidden)  ? '' : 'none';
    }

    // "Entfernen" only HIDES the logo on this document/PDF. The company
    // logo (tenant logo_path — shown in the dashboard and elsewhere) is
    // deliberately left untouched. Reversible via showLogo().
    function removeLogo() {
        state.settings.hide_logo = true;
        applySettingsToDocument();   // hides #docLogo + persists settings
        syncLogoUI();
        toast('Logo aus dem Dokument entfernt — Firmenlogo bleibt erhalten', 'success');
    }
    window.removeLogo = removeLogo;

    function showLogo() {
        state.settings.hide_logo = false;
        applySettingsToDocument();
        syncLogoUI();
        toast('Logo wird wieder angezeigt', 'success');
    }
    window.showLogo = showLogo;


    /**
     * Render the live #orderForm to a PDF blob (html2pdf) and upload it as
     * multipart so the server stores the exact binary the user sees. Returns
     * the saved PDF record ({ success, id, name, ... }) or throws. Shared by
     * Save and Share so both are guaranteed WYSIWYG.
     */
    // Wait for every <img> in the document (logo, signature, stamp) so
    // html2canvas never captures an empty box. Errors resolve too — a
    // missing asset must not block the export.
    function waitForDocImages(root, timeoutMs) {
        const imgs = Array.from(root.querySelectorAll('img'));
        return Promise.all(imgs.map(img => new Promise(res => {
            if (img.complete && img.naturalWidth > 0) return res();
            const done = () => res();
            img.addEventListener('load', done, { once: true });
            img.addEventListener('error', done, { once: true });
            setTimeout(done, timeoutMs || 10000);
        })));
    }

    // Insert explicit page-break spacers before blocks that the fixed A4
    // slicing would cut mid-element. Replaces html2pdf's pagebreak plugin:
    // that one measures raw getBoundingClientRect() viewport coordinates,
    // so any scroll/overlay offset corrupts the padding it injects (blank
    // first page, content pushed past the canvas). Offsets here are
    // measured in document space (element rect minus the .page rect) and
    // are therefore immune to scroll position and viewport quirks.
    //
    // IMPORTANT: this runs inside html2canvas's onclone() on the CLONED
    // document — the very DOM tree that gets painted. html2canvas lays
    // the clone out in its own hidden iframe; measuring there guarantees
    // the spacers land where the slicer needs them. (Pads measured on the
    // live DOM could drift when webfonts/images settled after measuring,
    // which used to slice rows mid-line.)
    const EXPORT_PAGE_TOP = 40;     // = .page top padding (px)
    const EXPORT_PAGE_BOTTOM = 16;  // = .page bottom padding (px)
    const EXPORT_BREAK_BOTTOM = 32; // min. white space above the paper edge (px)
    // Main table (inside .section-flow) + the last visible block of the
    // document: the rows that must travel with the closing part.
    const EXPORT_TAIL_ROWS = 3;
    function exportTail(page, view) {
        const table = page.querySelector('.section-flow table');
        const body = table && table.tBodies[0];
        if (!body) return null;
        const rows = [...body.rows].filter(r => !r.classList.contains('export-pad'));
        if (rows.length < 2) return null;
        const flowing = (el) => el && view.getComputedStyle(el).display !== 'none' && view.getComputedStyle(el).position !== 'absolute';
        const end = [page.querySelector('.footer-legal'), page.querySelector('.signature-section')].find(flowing);
        if (!end) return null;
        const headH = table.tHead ? table.tHead.getBoundingClientRect().height : 0;
        return { row: rows[Math.max(1, rows.length - EXPORT_TAIL_ROWS)], end, headH };
    }

    function insertExportBreaks(page) {
        const doc  = page.ownerDocument;             // live doc OR html2canvas clone
        const view = doc.defaultView || window;      // getComputedStyle of THAT tree
        // The html2canvas clone inherits the user's scroll position, and it
        // can shift while pads are inserted — so the sheet's position is
        // re-measured for EVERY element (pageRect.top), never cached.
        try { view.scrollTo(0, 0); } catch (e) {}
        const pageRect = { get top() { return page.getBoundingClientRect().top; }, width: page.getBoundingClientRect().width };
        const pageH = pageRect.width * (297 / 210); // one A4 slice in doc px
        const inserted = [];
        const shifted = [];
        // Spacer of height h before `el` (a <tr> inside tables). Absolutely
        // positioned children of .page (floated signature/stamp wraps,
        // free-form elements) do NOT reflow with the pad — without the
        // shift they keep their old doc-y and end up hovering over whatever
        // moved beneath them. Anything at or below `top` follows.
        const insertPad = (el, h, top) => {
            let pad;
            if (el.tagName === 'TR') {
                pad = doc.createElement('tr');
                const td = doc.createElement('td');
                td.colSpan = 20;
                td.style.cssText = 'height:' + h + 'px;padding:0;border:0;background:#fff;';
                pad.appendChild(td);
            } else {
                pad = doc.createElement('div');
                pad.style.cssText = 'display:block;height:' + h + 'px;';
            }
            pad.className = 'export-pad';
            el.parentNode.insertBefore(pad, el);
            inserted.push(pad);
            page.querySelectorAll(':scope > *').forEach(n => {
                if (n === pad || n.classList.contains('export-pad')) return;
                if (view.getComputedStyle(n).position !== 'absolute') return;
                const nTop = parseFloat(n.style.top);
                if (isNaN(nTop)) return; // bottom/right-anchored — leave
                if (n.getBoundingClientRect().top - pageRect.top >= top) {
                    if (!shifted.some(([m]) => m === n)) shifted.push([n, nTop]);
                    n.style.top = (nTop + h) + 'px';
                }
            });
            return pad;
        };
        // Moves `el` (starting at doc-y `top`) to the top of the next page.
        const breakBefore = (el, top) => {
            const pageEnd = (Math.floor(top / pageH) + 1) * pageH;
            // Continuation pages get the same top margin as page 1.
            const pad = insertPad(el, Math.ceil(pageEnd - top) + EXPORT_PAGE_TOP, top);
            // A table row that starts a new page repeats the column header
            // there, so page 2 is readable on its own. The copy sits in the
            // <tbody>, where the thead colours (and the zebra stripes) do not
            // apply — pin the original header's colours on its cells.
            const table = el.tagName === 'TR' && el.parentNode.tagName === 'TBODY' && el.closest('table');
            const head = table && table.tHead && table.tHead.rows[0];
            if (head) {
                const rep = head.cloneNode(true);
                rep.classList.add('export-pad');
                rep.querySelectorAll('[data-key]').forEach(n => n.removeAttribute('data-key'));
                const headBg = view.getComputedStyle(table.tHead).backgroundColor;
                const clear = (c) => !c || c === 'transparent' || c === 'rgba(0, 0, 0, 0)';
                [...rep.cells].forEach((c, i) => {
                    const o = head.cells[i];
                    if (!o) return;
                    const cs = view.getComputedStyle(o);
                    c.style.backgroundColor = clear(cs.backgroundColor) ? (clear(headBg) ? '#ffffff' : headBg) : cs.backgroundColor;
                    c.style.color = cs.color;
                });
                rep.style.backgroundColor = clear(headBg) ? '#ffffff' : headBg;
                pad.parentNode.insertBefore(rep, el);
                inserted.push(rep);
            }
        };
        // Closing part of the document = the last rows of the main table +
        // totals, legend, summary, signature and legal footer. If it would
        // not fit on the page where it starts, the last rows move to the next
        // page with it — the signature / company footer never stand alone.
        const tail = exportTail(page, view);
        page.querySelectorAll('tr, .detail-row, .section-title, .section, .summary-section, .signature-section, .footer-legal').forEach(el => {
            if (el.classList.contains('section-flow')) return; // long table may split
            const r = el.getBoundingClientRect(); // live rect — already reflects pads inserted above
            const top = r.top - pageRect.top;
            const bottom = r.bottom - pageRect.top;
            // Usable area of a slice ends EXPORT_BREAK_BOTTOM above the paper
            // edge, so no block is printed flush against the bottom.
            const pageEnd = (Math.floor(top / pageH) + 1) * pageH;
            if (tail && el === tail.row) {
                const endBottom = tail.end.getBoundingClientRect().bottom - pageRect.top;
                const headH = tail.headH;
                const spills = endBottom > pageEnd - EXPORT_BREAK_BOTTOM;
                const fits = endBottom - top + headH + EXPORT_PAGE_TOP + EXPORT_BREAK_BOTTOM <= pageH;
                const atPageTop = top - (pageEnd - pageH) <= EXPORT_PAGE_TOP + headH + 4;
                if (spills && fits && !atPageTop) { breakBefore(el, top); return; }
            }
            if (bottom > pageEnd - EXPORT_BREAK_BOTTOM && bottom - top < pageH - EXPORT_PAGE_TOP - EXPORT_BREAK_BOTTOM) {
                breakBefore(el, top);
            }
        });
        // Legal footer sits at the bottom of the last page (like letter
        // paper) instead of floating mid-page after the content.
        const footer = page.querySelector('.footer-legal');
        // A footer the user placed by hand (absolute) keeps its spot.
        if (footer && view.getComputedStyle(footer).display !== 'none' && view.getComputedStyle(footer).position !== 'absolute') {
            const fr = footer.getBoundingClientRect();
            const fTop = fr.top - pageRect.top, fBottom = fr.bottom - pageRect.top;
            const pageEnd = Math.ceil((fBottom - 0.5) / pageH) * pageH;
            // −4px: A4 slices are fractional px — never spill a blank page.
            const gap = Math.floor(pageEnd - EXPORT_PAGE_BOTTOM - fBottom - 4);
            if (gap > 0 && Math.floor(fTop / pageH) === Math.floor((fBottom - 0.5) / pageH)) insertPad(footer, gap, fTop);
        }
        // Floated assets (.doc-asset-wrap, .floating-element) are absolutely
        // positioned: no pad can protect them, and the shift above only fires
        // when a pad landed *below* a float's top — a stamp anchored onto a
        // pushed section keeps its old doc-y and gets sliced in half. Any
        // float still straddling an A4 slice is moved fully onto whichever
        // side already holds its center, so an image is never cut.
        page.querySelectorAll(':scope > *').forEach(n => {
            if (n.classList.contains('export-pad')) return;
            if (view.getComputedStyle(n).position !== 'absolute') return;
            const nTop = parseFloat(n.style.top);
            if (isNaN(nTop)) return;
            const r = n.getBoundingClientRect();
            const top = r.top - pageRect.top;
            const h = r.bottom - r.top;
            if (h <= 0 || h >= pageH) return; // hidden, or too tall to ever fit
            if (Math.floor(top / pageH) === Math.floor((top + h - 0.5) / pageH)) return;
            const centerOnUpper = Math.floor((top + h / 2) / pageH) === Math.floor(top / pageH);
            const delta = centerOnUpper ? -((top + h) % pageH) : (pageH - (top % pageH));
            if (!shifted.some(([m]) => m === n)) shifted.push([n, nTop]);
            n.style.top = (nTop + delta) + 'px';
        });
        return () => {
            inserted.forEach(n => n.parentNode && n.parentNode.removeChild(n));
            shifted.forEach(([n, t]) => { n.style.top = t + 'px'; });
        };
    }

    // Swap every <img> src for a data: URL before capture and return a
    // restore function. /api/serve sends Cache-Control: no-store, so
    // html2canvas's internal re-fetch of the images is a second network
    // round-trip that silently fails on some WebViews — images then paint
    // as empty boxes. Same-origin drawImage cannot taint; if it ever did
    // (cross-origin src), we keep the original and let h2c refetch.
    function inlineDocImages(root) {
        const swaps = [];
        root.querySelectorAll('img').forEach(img => {
            const src = img.getAttribute('src') || '';
            if (!src || src.startsWith('data:') || !img.naturalWidth) return;
            try {
                const c = document.createElement('canvas');
                c.width = img.naturalWidth;
                c.height = img.naturalHeight;
                c.getContext('2d').drawImage(img, 0, 0);
                const url = c.toDataURL('image/png');
                swaps.push([img, src]);
                img.src = url;
            } catch (e) { /* leave original src — h2c refetches as fallback */ }
        });
        return () => swaps.forEach(([img, src]) => { img.src = src; });
    }

    // Render #orderForm to an html2pdf Worker whose PDF is already built.
    // The element is captured IN FLOW with html2canvas directly — we no
    // longer hand the element to html2pdf's container/overlay pipeline:
    // on iOS that fixed-overlay document-clone render can offset the whole
    // image by the scroll offset (~900px), which pushed table rows and
    // the signature/stamp off the canvas (blank first page, missing days,
    // missing assets). Only the slicing (toPdf) is reused.
    const PDF_EXPORT_BUILD = 'v8';
    async function capturePdf() {
        const element = document.getElementById('orderForm');
        if (typeof html2canvas !== 'function') throw new Error('html2canvas nicht geladen — Seite neu laden');
        if (typeof html2pdf !== 'function') throw new Error('html2pdf nicht geladen — Seite neu laden');
        element.classList.add('exporting');
        suspendPageScale();
        let canvas, fitOnePage = false;
        try {
            await waitForDocImages(element);
            // Fonts settle asynchronously — a face arriving after the
            // break pass shifts every row's height and the spacers land
            // off-target (the mid-row slice bug). Wait for them first.
            if (document.fonts && document.fonts.ready) {
                try { await document.fonts.ready; } catch (e) {}
            }
            // A document only slightly taller than one A4 page used to push
            // its last block (typically the legal footer) alone onto page 2.
            // Up to FIT_ONE_PAGE_MAX × A4 it is scaled onto ONE page instead
            // (≥ 80 % size stays legible); longer documents split normally.
            const a4H = (element.offsetWidth || 800) * 297 / 210;
            const fullH = element.scrollHeight;
            // Lower bound: content that fits but would leave less than the
            // bottom break margin would otherwise also spill onto page 2.
            fitOnePage = fullH > a4H - EXPORT_BREAK_BOTTOM - EXPORT_PAGE_BOTTOM && fullH <= a4H * FIT_ONE_PAGE_MAX;
            const restoreImgs = inlineDocImages(element);
            try {
                // iOS/WKWebView silently kills canvas contexts above ~16.7MP —
                // cap the raster size so tall documents still render.
                const w = element.offsetWidth || 800;
                const h = element.scrollHeight || 1130;
                const scale = Math.min(2, Math.sqrt(12000000 / (w * h)));
                canvas = await html2canvas(element, {
                    scale: Math.max(0.75, scale), useCORS: true, letterRendering: true, backgroundColor: '#ffffff',
                    // Page-break spacers go into the CLONE: it is the exact
                    // DOM tree html2canvas paints, so the pads can never
                    // drift away from the slice boundaries — and the live
                    // document is never touched (no undo pass needed).
                    onclone: (clonedDoc) => {
                        if (fitOnePage) return;
                        const clonedPage = clonedDoc.getElementById('orderForm');
                        if (clonedPage) insertExportBreaks(clonedPage);
                    }
                });
            } finally { restoreImgs(); }
        } finally {
            element.classList.remove('exporting');
            resumePageScale();
        }
        if (!canvas || !canvas.width) throw new Error('html2canvas konnte das Dokument nicht rendern');
        const worker = html2pdf().set({
            margin: 0,
            image: { type: 'jpeg', quality: 0.98 },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
        });
        worker.prop.canvas = canvas; // feed our capture straight into the slicer
        await worker.toPdf();
        if (!worker.prop || !worker.prop.pdf) throw new Error('PDF-Engine hat kein Dokument erzeugt');
        const pdf = worker.prop.pdf; // the jsPDF instance — callers use pdf.output('blob') directly
        if (fitOnePage) {
            // Replace the sliced pages by one A4 page holding the whole
            // capture, scaled to the page height and centred horizontally.
            const n = pdf.getNumberOfPages();
            pdf.addPage('a4', 'portrait');
            for (let i = n; i >= 1; i--) pdf.deletePage(i);
            // Never enlarge: content shorter than A4 keeps its natural size.
            const ratio = canvas.height / canvas.width;          // = fullH / pageWidth
            const imgH = Math.min(297, 210 * ratio);
            const imgW = imgH / ratio;
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.98), 'JPEG', (210 - imgW) / 2, 0, imgW, imgH);
        }
        return pdf;
    }
    const FIT_ONE_PAGE_MAX = 1.25;

    // Download a generated PDF blob. <a download> on a blob URL works in
    // WKWebView/Capacitor where jsPDF's FileSaver path is unreliable.
    function downloadBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    }

    // ═══════════════════════════════════════════════════════════════
    // DRUCKEN — prints the generated PDF itself, so the paper looks exactly
    // like the downloaded file (same pages, colours, margins). Chromium
    // desktop prints it from a hidden frame; other browsers open the PDF in
    // a tab (their viewer prints it); if that fails the CSS print of the
    // sheet is used (@media print in order_form.css mirrors the sheet).
    // ═══════════════════════════════════════════════════════════════
    // Browser print (Strg+P fallback / print-all): a document only slightly
    // taller than page 1 is zoomed onto ONE page — same rule as capturePdf
    // (FIT_ONE_PAGE_MAX). Returns the CSS zoom for --pz (order_form.css).
    const PRINT_ZOOM = 0.9921;
    const PRINT_PAGE1_PX = 285 / 25.4 * 96;   // 297mm − 12mm bottom margin
    function printZoomFor(page) {
        if (!page) return PRINT_ZOOM;
        const had = page.classList.contains('exporting');
        page.classList.add('exporting');
        const h = page.scrollHeight;
        if (!had) page.classList.remove('exporting');
        const printed = h * PRINT_ZOOM;
        if (!h || printed <= PRINT_PAGE1_PX - 8 || printed > PRINT_PAGE1_PX * FIT_ONE_PAGE_MAX) return PRINT_ZOOM;
        return Math.floor((PRINT_PAGE1_PX - 12) / h * 10000) / 10000;
    }

    // Browser print: same "closing part stays together" rule as the PDF
    // export. Chrome ignores break-before:avoid here, so the page breaks are
    // estimated from the sheet layout (A4 page 1 = 285mm, later pages 273mm,
    // zoomed by --pz, repeated table header) and the last rows get a forced
    // break (.print-break) when signature / footer would otherwise spill.
    function markPrintTail(page, zoom) {
        if (!page) return;
        page.querySelectorAll('.print-break').forEach(n => n.classList.remove('print-break'));
        const tail = exportTail(page, window);
        if (!tail) return;
        const had = page.classList.contains('exporting');
        page.classList.add('exporting');
        const pr = page.getBoundingClientRect();
        const s = pr.width / page.offsetWidth || 1;          // undo an on-screen scale
        const top = (tail.row.getBoundingClientRect().top - pr.top) / s;
        const end = (tail.end.getBoundingClientRect().bottom - pr.top) / s;
        const headH = tail.headH / s;
        if (!had) page.classList.remove('exporting');
        const mm = 96 / 25.4;
        const p1 = 285 * mm / zoom, pn = 273 * mm / zoom - headH;
        if (end <= p1) return;                                 // everything on page 1
        const pageOf = (y) => y < p1 ? 0 : 1 + Math.floor((y - p1) / pn);
        if (pageOf(end + 24) > pageOf(top) && end - top + headH < pn) tail.row.classList.add('print-break');
    }

    let _printing = false;
    const _ua = navigator.userAgent;
    const CAN_FRAME_PRINT = /Chrome\/|Chromium\/|Edg\//.test(_ua) && !/Android|iPhone|iPad|Mobile|OPR\//.test(_ua)
        && !document.documentElement.classList.contains('app-shell');
    async function printDoc() {
        if (_printing) return;
        const tab = CAN_FRAME_PRINT ? null : window.open('', '_blank');
        if (tab) { try { tab.document.write('<p style="font-family:sans-serif;padding:24px">Druckansicht wird erstellt…</p>'); } catch (e) {} }
        _printing = true;
        toast('Druckansicht wird erstellt…', 'success');
        try {
            let blob;
            if (state.docType === 'ab' && state.order && state.order.frozen && state.order.eingang_id) {
                const res = await fetch(API + '/order-confirmations/' + encodeURIComponent(state.order.eingang_id) + '/archive', { headers: HEADERS() });
                if (!res.ok) throw new Error('Archivierte Version nicht gefunden');
                blob = await res.blob();
            } else {
                blob = (await capturePdf()).output('blob');
                archiveDoc(blob, 'druck');
            }
            if (CAN_FRAME_PRINT) await printBlobInFrame(blob);
            else if (tab && !tab.closed) tab.location.href = URL.createObjectURL(blob);
            else window.print();
        } catch (e) {
            console.error('printDoc failed', e);
            if (tab && !tab.closed) tab.close();
            toast('PDF-Druck nicht möglich — Browser-Druck wird verwendet', 'warning', 4000);
            window.print();
        } finally {
            _printing = false;
        }
    }
    window.printDoc = printDoc;

    function printBlobInFrame(blob) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(blob);
            const f = document.createElement('iframe');
            f.setAttribute('aria-hidden', 'true');
            f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
            let done = false;
            const go = () => {
                if (done) return;
                done = true;
                try { f.contentWindow.focus(); f.contentWindow.print(); resolve(); } catch (e) { reject(e); }
            };
            f.onload = () => setTimeout(go, 250);
            setTimeout(() => { if (!done) reject(new Error('Druckvorschau konnte nicht geladen werden')); }, 20000);
            f.src = url;
            document.body.appendChild(f);
            // Keep the frame alive while the print dialog is open.
            setTimeout(() => { f.remove(); URL.revokeObjectURL(url); }, 10 * 60 * 1000);
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // ARCHIV — every download / print / send files the exact PDF on the
    // server (POST /api/dokument-archiv, private storage). Unchanged
    // content keeps its version, changed content becomes a new version.
    // Best-effort: a failing upload never blocks the user's action.
    // ═══════════════════════════════════════════════════════════════
    const SAFE_REF = /^[A-Za-z0-9_-]{1,64}$/;
    function archiveRef() {
        if (state.designMode || !state.order) return null;
        if (state.docType === 'order' && state.orderId && SAFE_REF.test(state.orderId)) {
            return { id: state.orderId, label: 'Leistungsnachweis ' + state.orderId };
        }
        if (state.docType === 'ab' && state.order.eingang_id && SAFE_REF.test(state.order.eingang_id)) {
            return { id: state.order.eingang_id, label: 'Auftragsbestätigung ' + (state.order.dokument_nr || state.order.eingang_id) };
        }
        if (state.docType === 'worker' && typeof state.order.archive_ref === 'string' && state.order.archive_ref) {
            return { id: state.order.archive_ref, label: 'Stundennachweis ' + (state.order.name || '') + ' ' + (state.order.monthLabel || '') };
        }
        return null;
    }
    async function archiveDoc(blob, aktion) {
        const ref = archiveRef();
        if (!ref || !blob) return null;
        try {
            const fd = new FormData();
            fd.append('pdf', blob, 'dokument.pdf');
            fd.append('doc_type', state.docType);
            fd.append('ref_id', ref.id);
            fd.append('aktion', aktion);
            fd.append('label', ref.label.trim().slice(0, 190));
            const res = await fetch(API + '/dokument-archiv', {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + TOKEN, 'Accept': 'application/json' },
                body: fd,
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok || !d.success) { console.warn('Archiv:', d.error || res.status); return null; }
            return d;
        } catch (e) {
            console.warn('Archiv fehlgeschlagen', e);
            return null;
        }
    }

    const ARCHIV_AKTION = { download: 'Heruntergeladen', druck: 'Gedruckt', senden: 'Gesendet', teilen: 'Geteilt', versendet: 'An Kunden versendet', gespeichert: 'Gespeichert (alt)' };
    async function openArchiv() {
        const ref = archiveRef();
        const modal = document.getElementById('pdfsModal');
        const list = document.getElementById('pdfsList');
        if (!ref || !modal || !list) return;
        const title = modal.querySelector('.modal-title');
        if (title) title.textContent = 'Verlauf · archivierte PDFs';
        document.getElementById('pdfsSubtitle').textContent = ref.label;
        list.innerHTML = '<div class="loading"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Lade…</div>';
        modal.classList.add('show');
        try {
            const res = await fetch(API + '/dokument-archiv?doc_type=' + encodeURIComponent(state.docType) + '&ref_id=' + encodeURIComponent(ref.id), { headers: HEADERS() });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(d.error || ('HTTP ' + res.status));
            const rows = d.entries || [];
            if (!rows.length) {
                list.innerHTML = '<div class="empty-state"><i class="material-symbols-outlined">history</i><h3>Noch keine archivierten PDFs</h3><p>Jeder Download, Druck oder Versand wird hier automatisch als Version gesichert.</p></div>';
                return;
            }
            state.archivRows = rows;
            list.innerHTML = rows.map((r, i) => `
                <div class="pdf-row">
                    <i class="material-symbols-outlined pdf-icon">picture_as_pdf</i>
                    <div class="pdf-info">
                        <div class="pdf-name">${r.version ? 'Version ' + parseInt(r.version, 10) + ' · ' : ''}${esc(ARCHIV_AKTION[r.aktion] || r.aktion)}</div>
                        <div class="pdf-meta">
                            ${r.at ? esc(new Date(String(r.at).replace(' ', 'T')).toLocaleString('de-DE')) : '–'}
                            ${r.by ? ' · ' + esc(r.by) : ''}${r.file_size ? ' · ' + formatBytes(r.file_size) : ''}
                        </div>
                    </div>
                    <div class="pdf-actions">
                        <button class="primary" onclick="downloadArchiv(${i})" title="Herunterladen"><i class="material-symbols-outlined">download</i></button>
                    </div>
                </div>`).join('');
        } catch (e) {
            list.innerHTML = '<div class="empty-state"><i class="material-symbols-outlined">error</i><h3>Fehler beim Laden</h3><p>' + esc(e.message) + '</p></div>';
        }
    }
    window.openArchiv = openArchiv;

    function closePdfsModal() { document.getElementById('pdfsModal').classList.remove('show'); }
    window.closePdfsModal = closePdfsModal;

    async function downloadArchiv(i) {
        const r = (state.archivRows || [])[i];
        // Only server-built relative API paths are followed.
        if (!r || typeof r.download !== 'string' || !/^\/(dokument-archiv|order-confirmations|pdfs)\/[A-Za-z0-9_%-]+(\/[a-z]+)?(\?[A-Za-z0-9=&_-]*)?$/.test(r.download)) return;
        try {
            const res = await fetch(API + r.download, { headers: HEADERS() });
            if (!res.ok) throw new Error('Datei nicht gefunden');
            const base = (r.label || DOC_LABEL[state.docType]).replace(/[^A-Za-z0-9ÄÖÜäöüß_.-]+/g, '_').slice(0, 100);
            downloadBlob(await res.blob(), base + (r.version ? '_V' + r.version : '') + '.pdf');
        } catch (e) { toast('Fehler: ' + e.message, 'error'); }
    }
    window.downloadArchiv = downloadArchiv;

    // ═══════════════════════════════════════════════════════════════
    // MITARBEITER-UNTERSCHRIFT PER APP (Leistungsnachweis)
    // The office sends every worker a summary of HIS OWN rows to the
    // worker app (never the document). His signature comes back through
    // GET /dokument-signaturen and is printed in the Unterschrift cells of
    // exactly the rows he confirmed — a row changed afterwards stays empty
    // until he signs again.
    // ═══════════════════════════════════════════════════════════════
    const SIG_IMG_RE = /^data:image\/png;base64,[A-Za-z0-9+/]+={0,2}$/;
    const SIG_STATUS = {
        signiert:  ['Unterschrieben', 'ok'],
        offen:     ['Wartet auf Unterschrift', 'wait'],
        abgelehnt: ['Abgelehnt', 'bad'],
        veraltet:  ['Geändert seit Unterschrift', 'warn'],
        keine:     ['Noch nicht angefragt', ''],
    };
    function sigRef() {
        return (state.docType === 'order' && !state.designMode && state.order && state.orderId && SAFE_REF.test(state.orderId))
            ? state.orderId : null;
    }
    const sigModalOpen = () => { const m = document.getElementById('sigReqModal'); return !!m && m.classList.contains('show'); };

    function applySigStates(d) {
        const workers = Array.isArray(d && d.workers) ? d.workers : [];
        const by = {};
        workers.forEach(w => {
            if (typeof w.signatur === 'string' && SIG_IMG_RE.test(w.signatur) && Array.isArray(w.keys) && w.keys.length) {
                by[String(w.dienst_nr)] = { img: w.signatur, keys: new Set(w.keys.map(String)) };
            }
        });
        const stamp = Object.keys(by).sort().map(k => k + ':' + [...by[k].keys].sort().join(',') + ':' + by[k].img.length).join('|');
        const changed = stamp !== state.sig.stamp;
        state.sig = { workers, by, canSend: !!(d && d.can_send), aktiv: !!(d && d.aktiv), stamp };
        if (changed) {
            // Signatures need their column: show it for this PDF unless the
            // user switched it off here himself.
            if (stamp && !('th.unterschrift' in state.pdfCols.cols) && effectiveCols(true).hidden.has('th.unterschrift')) {
                state.pdfCols.cols['th.unterschrift'] = true;
            }
            rerenderDocument();
        }
        if (HEADLESS) return;
        syncSigRequestButton();
        if (sigModalOpen()) renderSigRequestList();
    }

    async function loadWorkerSignatures() {
        const ref = sigRef();
        if (!ref) return;
        try {
            const res = await fetch(API + '/dokument-signaturen?doc_type=order&ref_id=' + encodeURIComponent(ref), { headers: HEADERS() });
            if (!res.ok) return;
            applySigStates(await res.json());
        } catch (e) { /* offline → document without app signatures */ }
    }

    // Unterschrift cell of one row: the worker's signature when he signed
    // exactly this row. Hidden names (anonymised) never show a signature.
    function workerSigHtml(w) {
        if (state.anonymize || !w.dienst_nr) return '';
        const s = state.sig.by[String(w.dienst_nr)];
        return (s && s.keys.has(String(w.einsatz_id))) ? '<img class="wk-sig-img" src="' + s.img + '" alt="Unterschrift">' : '';
    }

    let _sigPoll = null;
    function syncSigRequestButton() {
        const btn = document.getElementById('btnSigRequest');
        const ws = state.sig.workers;
        if (btn) {
            const on = !!(sigRef() && state.sig.aktiv && ws.length);
            btn.style.display = on ? '' : 'none';
            document.body.classList.toggle('has-sigreq', on);   // narrower topbar labels (order_form.css)
            const done = ws.filter(w => w.status === 'signiert').length;
            btn.classList.toggle('active', ws.length > 0 && done === ws.length);
            const badge = document.getElementById('sigReqBadge');
            if (badge) {
                badge.hidden = !ws.some(w => w.status !== 'keine');
                badge.textContent = done + '/' + ws.length;
                badge.classList.toggle('ok', done === ws.length);
            }
        }
        // Someone still has to sign (or the dialog is open): refresh quietly.
        const need = !!sigRef() && (sigModalOpen() || ws.some(w => w.status === 'offen'));
        if (need && !_sigPoll) _sigPoll = setInterval(() => { if (!document.hidden) loadWorkerSignatures(); }, 20000);
        if (!need && _sigPoll) { clearInterval(_sigPoll); _sigPoll = null; }
    }

    // Server timestamps are UTC ("YYYY-MM-DD HH:MM:SS").
    function sigTime(ts) {
        if (!ts) return '';
        const d = new Date(String(ts).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(ts) ? '' : 'Z'));
        return isNaN(d) ? '' : d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function renderSigRequestList() {
        const list = document.getElementById('sigReqList');
        if (!list) return;
        const ws = state.sig.workers;
        const kept = new Map([...list.querySelectorAll('input[data-dn]')].map(cb => [cb.dataset.dn, cb.checked]));
        const nuCount = new Set(((state.order && state.order.confirmed_nu_workers) || []).map(w => w.nu_worker_id)).size;
        let html = ws.map(w => {
            const dn = String(w.dienst_nr);
            const [label, cls] = SIG_STATUS[w.status] || SIG_STATUS.keine;
            const off = !state.sig.canSend || w.status === 'signiert' || !w.app_zugang;
            const checked = !off && (kept.has(dn) ? kept.get(dn) : w.status !== 'offen');
            const at = sigTime(w.status === 'signiert' ? w.signiert_am : w.status === 'offen' ? w.angefragt_am : w.status === 'abgelehnt' ? w.abgelehnt_am : '');
            const chip = (!w.app_zugang && w.status !== 'signiert')
                ? '<span class="sigreq-chip">Kein App-Zugang</span>'
                : '<span class="sigreq-chip ' + cls + '">' + esc(label) + (at ? ' · ' + esc(at) : '') + '</span>';
            const std = (parseFloat(w.std) || 0).toFixed(2).replace('.', ',');
            return '<div class="sigreq-row' + (off ? ' is-off' : '') + '">'
                + '<label class="sigreq-main"><input type="checkbox" data-dn="' + esc(dn) + '"' + (checked ? ' checked' : '') + (off ? ' disabled' : '') + '>'
                + '<span><b>' + esc(w.name || dn) + '</b><small>' + (parseInt(w.einsaetze, 10) || 0) + (w.einsaetze === 1 ? ' Einsatz' : ' Einsätze') + ' · ' + std + ' Std</small></span></label>'
                + chip
                + (w.status === 'offen' && w.offen_id && state.sig.canSend
                    ? '<button type="button" class="sigreq-x" data-withdraw="' + parseInt(w.offen_id, 10) + '" title="Anfrage zurückziehen"><i class="material-symbols-outlined">close</i></button>' : '')
                + ((w.status === 'signiert' || w.status === 'veraltet') && w.signiert_id && state.sig.canSend
                    ? '<button type="button" class="sigreq-x" data-discard="' + parseInt(w.signiert_id, 10) + '" data-name="' + esc(w.name || dn) + '" title="Unterschrift löschen (danach neu anfragen)"><i class="material-symbols-outlined">delete</i></button>' : '')
                + '</div>'
                + (w.status === 'abgelehnt' && w.grund ? '<div class="sigreq-note">„' + esc(w.grund) + '“</div>' : '')
                + (w.status === 'veraltet' ? '<div class="sigreq-note">Die Zeiten wurden nach der Unterschrift geändert — bitte erneut senden.</div>' : '');
        }).join('');
        if (!ws.length) html = '<div class="empty-state"><i class="material-symbols-outlined">pending_actions</i><h3>Keine freigegebenen Einsätze</h3><p>Erst nach der Kunden-Freigabe im Monatsabschluss kann unterschrieben werden.</p></div>';
        if (nuCount) html += '<div class="sigreq-note is-foot">' + nuCount + ' NU-Mitarbeiter nutzen die App nicht — ihre Unterschrift bitte auf Papier einholen.</div>';
        list.innerHTML = html;
        list.querySelectorAll('input[data-dn]').forEach(cb => cb.addEventListener('change', syncSigSendButton));
        list.querySelectorAll('[data-withdraw]').forEach(b => b.addEventListener('click', () => withdrawSigRequest(b.dataset.withdraw)));
        list.querySelectorAll('[data-discard]').forEach(b => b.addEventListener('click', () => {
            if (window.confirm('Unterschrift von ' + b.dataset.name + ' löschen?\n\nSie verschwindet aus dem Dokument. Danach können Sie die Unterschrift neu anfragen.')) {
                withdrawSigRequest(b.dataset.discard, 'Unterschrift gelöscht — Sie können jetzt neu anfragen');
            }
        }));
        syncSigSendButton();
    }

    function sigSelected() {
        return [...document.querySelectorAll('#sigReqList input[data-dn]:checked:not(:disabled)')].map(cb => cb.dataset.dn);
    }
    function syncSigSendButton() {
        const btn = document.getElementById('sigReqSend');
        if (!btn) return;
        const n = sigSelected().length;
        btn.style.display = state.sig.canSend ? '' : 'none';
        btn.disabled = !n;
        const lbl = btn.querySelector('span');
        if (lbl) lbl.textContent = n ? 'An ' + n + ' Mitarbeiter senden' : 'Zur Unterschrift senden';
    }

    function openSigRequest() {
        const modal = document.getElementById('sigReqModal');
        if (!sigRef() || !modal) return;
        document.getElementById('sigReqSubtitle').textContent = 'Leistungsnachweis ' + state.orderId
            + (state.order.objektname ? ' · ' + state.order.objektname : '');
        renderSigRequestList();
        modal.classList.add('show');
        syncSigRequestButton();
        loadWorkerSignatures();
    }
    window.openSigRequest = openSigRequest;

    function closeSigRequest() {
        const modal = document.getElementById('sigReqModal');
        if (modal) modal.classList.remove('show');
        syncSigRequestButton();
    }
    window.closeSigRequest = closeSigRequest;

    async function sigWrite(method, path, body) {
        const res = await fetch(API + path, {
            method,
            headers: { ...HEADERS(), 'Content-Type': 'application/json' },
            body: body ? JSON.stringify(body) : undefined,
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok || !d.success) throw new Error(d.error || d.message || ('HTTP ' + res.status));
        return d;
    }

    async function sendSigRequests() {
        const dns = sigSelected();
        const btn = document.getElementById('sigReqSend');
        if (!dns.length || !sigRef()) return;
        btn.disabled = true;
        try {
            const d = await sigWrite('POST', '/dokument-signaturen/anfragen', { doc_type: 'order', ref_id: sigRef(), dienst_nrs: dns });
            document.getElementById('sigReqList').innerHTML = '';   // forget the ticks — sent rows start unticked
            applySigStates({ workers: d.workers, aktiv: true, can_send: true });
            const parts = [];
            if (d.gesendet) parts.push(d.gesendet + ' Anfrage' + (d.gesendet === 1 ? '' : 'n') + ' gesendet');
            if (d.erinnert) parts.push(d.erinnert + ' Erinnerung' + (d.erinnert === 1 ? '' : 'en') + ' gesendet');
            (d.uebersprungen || []).forEach(s => parts.push((s.name || s.dienst_nr) + ': ' + s.grund));
            toast(parts.join(' · ') || 'Nichts zu senden', (d.gesendet || d.erinnert) ? 'success' : 'warning', 5000);
        } catch (e) {
            toast('Senden fehlgeschlagen: ' + e.message, 'error', 6000);
        } finally { syncSigSendButton(); }
    }
    window.sendSigRequests = sendSigRequests;

    async function withdrawSigRequest(id, doneText) {
        if (!/^\d+$/.test(String(id))) return;
        try {
            const d = await sigWrite('DELETE', '/dokument-signaturen/' + id);
            applySigStates({ workers: d.workers, aktiv: true, can_send: true });
            toast(doneText || 'Anfrage zurückgezogen', 'success', doneText ? 5000 : undefined);
        } catch (e) {
            toast('Aktion fehlgeschlagen: ' + e.message, 'error', 6000);
            loadWorkerSignatures();
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DOWNLOAD PDF (client-side via html2pdf)
    // ═══════════════════════════════════════════════════════════════
    async function downloadPdfClient() {
        // A sent Auftragsbestätigung is archived: download exactly the PDF
        // that went to the customer, never a re-render.
        if (state.docType === 'ab' && state.order && state.order.frozen && state.order.eingang_id) {
            try {
                const res = await fetch(API + '/order-confirmations/' + encodeURIComponent(state.order.eingang_id) + '/archive?download=1', { headers: HEADERS() });
                if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || ('HTTP ' + res.status));
                downloadBlob(await res.blob(), docFileName());
                toast('Archivierte Version ' + (state.order.version || 1) + ' heruntergeladen', 'success');
            } catch (e) { toast('Download fehlgeschlagen: ' + e.message, 'error'); }
            return;
        }
        toast('PDF wird erstellt…', 'success');
        try {
            const pdf = await capturePdf();
            const blob = pdf.output('blob');
            downloadBlob(blob, docFileName());
            toast('PDF heruntergeladen ✓', 'success');
            archiveDoc(blob, 'download');
        } catch (e) {
            console.error('downloadPdfClient failed', e);
            toast('Fehler [' + PDF_EXPORT_BUILD + ']: ' + (e && e.message || e), 'error');
        }
    }
    window.downloadPdfClient = downloadPdfClient;

    // ═══════════════════════════════════════════════════════════════
    // FIRST-RUN WIZARD — branding onboarding (colours, font, logo)
    // Shown once per tenant (per browser) when the editor first opens.
    // Every choice applies LIVE; "Fertig" stores it as the company default
    // and as this document type's template (wizardFinish).
    // ═══════════════════════════════════════════════════════════════
    const WIZARD_STEPS = 3;
    const COLOR_PRESETS = [
        { name: 'Klassisch Blau',    primary: '#1a2744', accent: '#3b82f6' },
        { name: 'Elegant Anthrazit', primary: '#1f2937', accent: '#6b7280' },
        { name: 'Frisch Grün',       primary: '#14532d', accent: '#22c55e' },
        { name: 'Modern Violett',    primary: '#2e1065', accent: '#8b5cf6' },
        { name: 'Warm Rot',          primary: '#7f1d1d', accent: '#ef4444' },
        { name: 'Petrol',            primary: '#0f3a44', accent: '#06b6d4' },
    ];

    function setupWizard() {
        // Build the colour-preset buttons once.
        const palettes = document.getElementById('wzPalettes');
        if (palettes && !palettes.dataset.ready) {
            palettes.dataset.ready = '1';
            palettes.innerHTML = COLOR_PRESETS.map((p, i) => `
                <button type="button" class="wz-palette" data-i="${i}" title="${p.name}">
                    <span class="wz-sw" style="background:${p.primary}"></span>
                    <span class="wz-sw" style="background:${p.accent}"></span>
                    <span class="wz-pname">${p.name}</span>
                </button>`).join('');
            palettes.querySelectorAll('.wz-palette').forEach(btn => {
                btn.addEventListener('click', () => {
                    const p = COLOR_PRESETS[+btn.dataset.i];
                    applyWizardColors(p.primary, p.accent);
                    palettes.querySelectorAll('.wz-palette').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                });
            });
        }

        // Sync wizard inputs with the current (server/localStorage) branding.
        const wzP = document.getElementById('wzPrimary');
        const wzA = document.getElementById('wzAccent');
        const wzF = document.getElementById('wzFont');
        const wzLp = document.getElementById('wzLogoPos');
        if (wzP) { wzP.value = state.settings.primary_color; wzP.oninput = () => applyWizardColors(wzP.value, null); }
        if (wzA) { wzA.value = state.settings.accent_color;  wzA.oninput = () => applyWizardColors(null, wzA.value); }
        if (wzF) {
            wzF.value = state.settings.font_family;
            wzF.onchange = () => { setFontFamily(wzF.value); syncSidebarValue('settingFont', wzF.value); updateWizardPreview(); };
        }
        if (wzLp) {
            wzLp.value = state.settings.logo_position || 'right';
            wzLp.onchange = () => { setLogoPosition(wzLp.value); syncSidebarValue('settingLogoPos', wzLp.value); };
        }

        syncWizardLogoPreview();
        updateWizardPreview();
    }

    function syncSidebarValue(id, val) { const el = document.getElementById(id); if (el) el.value = val; }

    function applyWizardColors(primary, accent) {
        if (primary) { setPrimary(primary); syncSidebarValue('settingPrimary', primary); const i = document.getElementById('wzPrimary'); if (i) i.value = primary; }
        if (accent)  { setAccent(accent);   syncSidebarValue('settingAccent',  accent);  const i = document.getElementById('wzAccent');  if (i) i.value = accent; }
        updateWizardPreview();
    }

    function updateWizardPreview() {
        const prev = document.getElementById('wzPreview');
        if (!prev) return;
        // Colours flow in via the --primary/--accent CSS vars (set by
        // applySettingsToDocument); only the font needs an explicit set.
        prev.style.fontFamily = state.settings.font_family;
    }

    // Called from the wizard's logo file input — reuses the main upload flow.
    function wizardHandleLogo(input) {
        handleLogoUpload(input);
        setTimeout(syncWizardLogoPreview, 400);
    }
    window.wizardHandleLogo = wizardHandleLogo;

    function syncWizardLogoPreview() {
        const box = document.getElementById('wzLogoPreview');
        if (!box) return;
        if (state.logoDataUrl && !state.settings.hide_logo) {
            box.innerHTML = '<img src="' + state.logoDataUrl + '" alt="Logo">';
        } else {
            box.innerHTML = '<i class="material-symbols-outlined">cloud_upload</i><div>Logo hochladen (optional)</div>';
        }
    }

    // Once per COMPANY (server flag), not per browser — and only for users
    // who can actually change the company design.
    function maybeShowFirstRunWizard() {
        if (state.wizardDone || !CAN_SAVE_LAYOUT) return;
        try { if (localStorage.getItem(FIRST_TIME_KEY)) { markWizardDone(); return; } } catch (e) {}
        const ov = document.getElementById('pdfWizard');
        if (!ov) return;
        wizardGoTo(1);
        state.wizardOpen = true;
        ov.classList.add('show');
    }

    function wizardGoTo(n) {
        n = Math.max(1, Math.min(WIZARD_STEPS, n));
        state.wizardStep = n;
        document.querySelectorAll('#pdfWizard .wizard-step').forEach(s => {
            s.classList.toggle('active', parseInt(s.dataset.step, 10) === n);
        });
        document.querySelectorAll('#pdfWizard .wz-dot').forEach((d, i) => {
            d.classList.toggle('active', i === (n - 1));
            d.classList.toggle('done', i < (n - 1));
        });
        const back = document.getElementById('wzBack');
        const next = document.getElementById('wzNext');
        if (back) back.style.visibility = (n === 1) ? 'hidden' : 'visible';
        if (next) next.innerHTML = (n === WIZARD_STEPS)
            ? 'Fertig <i class="material-symbols-outlined">check</i>'
            : 'Weiter <i class="material-symbols-outlined">arrow_forward</i>';
    }

    function wizardNext() { if ((state.wizardStep || 1) >= WIZARD_STEPS) wizardFinish(); else wizardGoTo((state.wizardStep || 1) + 1); }
    function wizardBack() { wizardGoTo((state.wizardStep || 1) - 1); }
    // First-run design = company default (used by every document type
    // without its own design) + the template of the type opened now.
    async function wizardFinish() {
        closeWizard();
        BRANDING_KEYS.forEach(k => { state.companyBranding[k] = state.settings[k]; });
        try {
            await Promise.all([saveBrandingToServer(true), saveLayoutToServer()]);
            toast('Design gespeichert ✓ — jederzeit über „Einstellungen“ änderbar', 'success');
        } catch (e) { toast('Design konnte nicht gespeichert werden: ' + e.message, 'error', 6000); }
    }
    function wizardSkip()   { closeWizard(); }
    function closeWizard() {
        state.wizardOpen = false;
        const ov = document.getElementById('pdfWizard');
        if (ov) ov.classList.remove('show');
        try { localStorage.setItem(FIRST_TIME_KEY, '1'); } catch (e) {}
        markWizardDone();
    }
    function markWizardDone() {
        state.wizardDone = true;
        fetch(API + '/pdf/wizard-done', { method: 'POST', headers: HEADERS() }).catch(() => {});
    }
    window.wizardNext = wizardNext;
    window.wizardBack = wizardBack;
    window.wizardSkip = wizardSkip;

    // ═══════════════════════════════════════════════════════════════
    // DOKUMENT-DESIGN — standard templates + Textbausteine (?design=1)
    // The sheet is a read-only preview here: everything is configured in
    // the sidebar and saved with „Speichern“ into the company template
    // (layout.design / layout.bausteine / layout.email — PdfLayoutController).
    // ═══════════════════════════════════════════════════════════════
    const DD_TEMPLATES = [
        { id: 'klassisch', name: 'Klassisch', desc: 'Farbige Balken, klare Tabellen',
          primary: '#1a2744', accent: '#3b82f6', font: "'Segoe UI', system-ui, sans-serif" },
        { id: 'modern', name: 'Modern', desc: 'Farbverlauf, luftig, abgerundet',
          primary: '#0b57d0', accent: '#14b8a6', font: "'Helvetica Neue', Arial, sans-serif" },
        { id: 'din5008', name: 'DIN 5008', desc: 'Sachlicher Geschäftsbrief, schwarz-weiß',
          primary: '#111827', accent: '#4b5563', font: 'Arial, sans-serif' },
    ];
    const BAUSTEIN_SLOTS = ['intro', 'closing'];
    const BAUSTEIN_DEFAULT_ON = { ab: { intro: true, closing: true } };
    // Standard texts following German business practice (Sie-Form,
    // Prüf-/Einwandfrist, Grußformel). {{…}} = placeholders (docContext).
    const DD_LIB = {
        order: {
            intro: [
                { n: 'Leistungsbestätigung', t: 'Hiermit bestätigen wir die im Leistungszeitraum {{zeitraum}} für das Objekt {{objekt}} erbrachten Leistungen gemäß Auftrag {{dokument_nr}}.' },
                { n: 'Kurz', t: 'Nachfolgend erhalten Sie die Aufstellung der erbrachten Leistungen für {{zeitraum}}.' },
            ],
            closing: [
                { n: 'Anerkennung mit Einwandfrist', t: 'Mit seiner Unterschrift bestätigt der Auftraggeber die ordnungsgemäße Erbringung der aufgeführten Leistungen. Einwände bitten wir innerhalb von 7 Tagen nach Erhalt schriftlich mitzuteilen; andernfalls gilt der Leistungsnachweis als anerkannt.' },
                { n: 'Grundlage der Rechnung', t: 'Dieser Leistungsnachweis ist Grundlage unserer Rechnungsstellung. Die Rechnung erhalten Sie gesondert.' },
                { n: 'Dank', t: 'Wir bedanken uns für die angenehme Zusammenarbeit.' },
            ],
            mail: [
                { n: 'Standard (förmlich)', betreff: 'Leistungsnachweis {{zeitraum}} – {{objekt}}',
                  text: '{{anrede}}\n\nanbei erhalten Sie den Leistungsnachweis für den Zeitraum {{zeitraum}} (Objekt: {{objekt}}, Auftrag {{dokument_nr}}).\n\nBitte prüfen Sie die aufgeführten Einsätze und senden Sie uns den Nachweis unterschrieben zurück. Einwände teilen Sie uns bitte innerhalb von 7 Tagen mit.\n\nFür Rückfragen stehen wir Ihnen gerne zur Verfügung.\n\nMit freundlichen Grüßen\n\n{{absender}}\n{{firma}}' },
                { n: 'Kurz', betreff: 'Leistungsnachweis {{dokument_nr}}',
                  text: '{{anrede}}\n\nanbei der Leistungsnachweis für {{zeitraum}}.\n\nMit freundlichen Grüßen\n\n{{absender}}\n{{firma}}' },
            ],
        },
        ab: {
            intro: [
                { n: 'Standard (Auftrag bestätigen)', t: 'Gerne bestätigen wir hiermit Ihren Auftrag für {{zeitraum}} ({{leistungszeit}}) zu folgenden Positionen:' },
                { n: 'Dank für den Auftrag', t: 'Vielen Dank für Ihre Bestellung und das damit verbundene Vertrauen in unser Unternehmen.' },
                { n: 'Bezug auf Angebot', t: 'Auf Grundlage unseres Angebots und Ihrer Bestellung bestätigen wir Ihnen den Auftrag wie folgt.' },
            ],
            closing: [
                { n: 'Standard (Dank & Gruß)', t: 'Wir bedanken uns herzlich für Ihren Auftrag!\n\nMit freundlichen Grüßen' },
                { n: 'Prüfung & Widerspruch', t: 'Bitte prüfen Sie diese Auftragsbestätigung auf Richtigkeit. Abweichungen teilen Sie uns bitte innerhalb von 3 Werktagen mit; andernfalls gilt der Auftrag zu den genannten Bedingungen als bestätigt.' },
                { n: 'AGB', t: 'Es gelten unsere Allgemeinen Geschäftsbedingungen in der jeweils gültigen Fassung, die wir Ihnen auf Wunsch gerne zusenden.' },
                { n: 'Änderungen in Textform', t: 'Änderungen oder Erweiterungen des Leistungsumfangs bedürfen der Textform.' },
            ],
            mail: [
                { n: 'Standard (förmlich)', betreff: 'Auftragsbestätigung {{dokument_nr}} – {{zeitraum}}',
                  text: '{{anrede}}\n\nvielen Dank für Ihren Auftrag. Anbei erhalten Sie unsere Auftragsbestätigung {{dokument_nr}} für den Leistungszeitraum {{zeitraum}}.\n\nBitte prüfen Sie die Angaben. Sollten Abweichungen bestehen, teilen Sie uns diese bitte innerhalb von 3 Werktagen mit.\n\nWir freuen uns auf die Zusammenarbeit.\n\nMit freundlichen Grüßen\n\n{{absender}}\n{{firma}}' },
                { n: 'Neue Version', betreff: 'Aktualisierte Auftragsbestätigung {{dokument_nr}}',
                  text: '{{anrede}}\n\nanbei erhalten Sie die aktualisierte Auftragsbestätigung {{dokument_nr}}. Sie ersetzt die bisherige Fassung.\n\nMit freundlichen Grüßen\n\n{{absender}}\n{{firma}}' },
            ],
        },
        worker: {
            intro: [
                { n: 'Aufstellung', t: 'Nachfolgend erhalten Sie die Aufstellung Ihrer im Zeitraum {{zeitraum}} geleisteten Arbeitszeiten.' },
            ],
            closing: [
                { n: 'Prüfung durch Mitarbeiter', t: 'Bitte prüfen Sie Ihre Arbeitszeiten. Unstimmigkeiten teilen Sie bitte innerhalb von 7 Tagen der Personalabteilung mit; danach gelten die Zeiten als bestätigt.' },
                { n: 'Aufzeichnung (MiLoG)', t: 'Die Arbeitszeiten werden gemäß § 17 MiLoG aufgezeichnet und mindestens zwei Jahre aufbewahrt.' },
            ],
            mail: [
                { n: 'Standard', betreff: 'Ihr Stundennachweis {{zeitraum}}',
                  text: '{{anrede}}\n\nanbei erhalten Sie Ihren Stundennachweis für {{zeitraum}}.\n\nBitte prüfen Sie die Angaben und melden Sie Unstimmigkeiten innerhalb von 7 Tagen.\n\nViele Grüße\n\n{{absender}}\n{{firma}}' },
                { n: 'Förmlich', betreff: 'Stundennachweis {{zeitraum}} – {{mitarbeiter}}',
                  text: 'Guten Tag {{mitarbeiter}},\n\nim Anhang finden Sie Ihren Stundennachweis für den Zeitraum {{zeitraum}}.\n\nMit freundlichen Grüßen\n\n{{absender}}\n{{firma}}' },
            ],
        },
    };
    const DD_PH_LABEL = {
        firma: 'Firma', kunde: 'Kunde', leistungszeit: 'Uhrzeit', objekt: 'Objekt', zeitraum: 'Zeitraum', dokument_nr: 'Dokument-Nr.',
        mitarbeiter: 'Mitarbeiter', datum: 'Datum', anrede: 'Anrede', absender: 'Absender', dokument: 'Dokumentart',
    };
    const DD_PH_PDF  = DOC_TYPE === 'worker' ? ['firma', 'mitarbeiter', 'objekt', 'zeitraum', 'datum']
        : (DOC_TYPE === 'ab' ? ['firma', 'kunde', 'objekt', 'zeitraum', 'leistungszeit', 'dokument_nr', 'datum'] : ['firma', 'kunde', 'objekt', 'zeitraum', 'dokument_nr', 'datum']);
    const DD_PH_MAIL = ['anrede', ...DD_PH_PDF, 'absender', 'dokument'];

    // Placeholder values of the rendered document.
    function docContext() {
        const o = state.order || {};
        const today = new Date().toLocaleDateString('de-DE');
        const base = { firma: TENANT.name || '', kunde: '', objekt: '', zeitraum: '', leistungszeit: '', dokument_nr: '', mitarbeiter: '', datum: today };
        if (state.docType === 'ab') {
            const m = o.meta || {};
            return { ...base, kunde: (o.kunde || {}).name || '', objekt: o.lieferadresse || '', zeitraum: m.zeitraum || '', leistungszeit: m.leistungszeit || '',
                dokument_nr: o.dokument_nr || m.auftrags_nr || '', datum: m.datum || today };
        }
        if (state.docType === 'worker') return { ...base, mitarbeiter: o.name || '', objekt: o.objekt || '', zeitraum: o.monthLabel || '' };
        return { ...base, kunde: o.kundenname || o.kunden_id || '', objekt: o.objektname || o.obj_id || '',
            zeitraum: ((MONTHS[o.monat] || '') + ' ' + (o.jahr || '')).trim(), dokument_nr: o.bestell_id || '' };
    }
    function fillPlaceholders(txt, ctx) {
        return String(txt || '').replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (m, k) => (k in ctx ? ctx[k] : m));
    }
    function bausteinHtml(slot, attrs) {
        const b = state.bausteine && state.bausteine[slot];
        if (!b || !b.on || !String(b.text || '').trim()) return '';
        const txt = fillPlaceholders(b.text, docContext()).replace(/\s*\(\s*\)/g, '');
        const at = attrs ? attrs.replace('class="', 'class="doc-baustein doc-baustein-' + slot + ' ') : 'class="doc-baustein doc-baustein-' + slot + '"';
        return '<div ' + at + (/\bid="/.test(at) ? '' : ' id="baustein-' + slot + '"') + ' data-baustein="' + slot + '">'
            + esc(txt).replace(/\n/g, '<br>') + '</div>';
    }
    function mailContext() {
        const c = docContext();
        return { ...c,
            anrede: state.docType === 'worker' ? 'Hallo ' + (c.mitarbeiter || 'Max Mustermann') + ',' : 'Sehr geehrte Damen und Herren,',
            absender: _user.full_name || _user.name || '', dokument: DOC_LABEL[state.docType] };
    }
    // Business e-mail signature incl. the mandatory details (§ 37a HGB,
    // § 35a GmbHG, § 80 AktG) — built from Konto & Firma.
    function mailSignature() {
        const T = TENANT;
        const zipCity = ((T.company_zip || '') + ' ' + (T.company_city || '')).trim();
        const lines = [T.name, T.firmen_zusatz, T.company_address, zipCity,
            T.company_phone ? 'Tel.: ' + T.company_phone : '', T.company_email ? 'E-Mail: ' + T.company_email : '',
            T.website ? 'Web: ' + T.website : ''].filter(Boolean);
        const legal = [T.company_city ? 'Sitz der Gesellschaft: ' + T.company_city : '',
            T.registergericht && T.handelsregister_nr ? 'Registergericht: ' + T.registergericht + ', ' + T.handelsregister_nr
                : (T.handelsregister_nr ? 'Handelsregister: ' + T.handelsregister_nr : ''),
            T.geschaeftsfuehrer ? 'Geschäftsführung: ' + T.geschaeftsfuehrer : '',
            T.ust_id ? 'USt-IdNr.: ' + T.ust_id : ''].filter(Boolean);
        const missing = [];
        if (!T.handelsregister_nr) missing.push('Handelsregister-Nr.');
        if (!T.registergericht) missing.push('Registergericht');
        if (!T.geschaeftsfuehrer) missing.push('Geschäftsführer');
        return { lines, legal, missing };
    }

    let _ddLastField = null;
    let _ddRerenderTimer = null;
    const ddRerenderSoon = () => { clearTimeout(_ddRerenderTimer); _ddRerenderTimer = setTimeout(rerenderDocument, 250); };
    const ddChips = (keys) => '<div class="dd-chips">' + keys.map(k =>
        '<button type="button" class="dd-chip" data-ph="' + k + '" title="{{' + k + '}} einfügen">' + esc(DD_PH_LABEL[k]) + '</button>').join('') + '</div>';

    // Autosave: a changed template is written once it stayed unchanged for
    // one tick (debounce); errors back off for 15 s instead of spamming.
    let _ddSaving = false, _ddLastSig = null, _ddRetryAt = 0;
    const DD_SAVE_UI = {
        saved:  ['cloud_done', 'Gespeichert'],
        dirty:  ['edit', 'Änderungen …'],
        saving: ['progress_activity', 'Wird gespeichert …'],
        error:  ['cloud_off', 'Nicht gespeichert'],
        ro:     ['visibility', 'Nur Ansicht'],
    };
    function ddSaveState(st) {
        const el = document.getElementById('ddSaveState');
        if (!el || el.dataset.state === st) return;
        el.dataset.state = st;
        const [icon, text] = DD_SAVE_UI[st];
        el.innerHTML = '<span class="material-symbols-outlined' + (st === 'saving' ? ' ms-spin' : '') + '">' + icon + '</span> <span class="dd-ss-text">' + text + '</span>';
    }
    function ddAutosaveTick() {
        if (!CAN_SAVE_LAYOUT) { ddSaveState('ro'); return; }
        // Not while the first-run wizard previews colours (it saves on „Fertig“).
        if (_ddSaving || state.wizardOpen || state.ddPreview || !state.order || !state.lastSavedLayout || Date.now() < _ddRetryAt) return;
        let sig;
        try { sig = layoutSignature(collectLayout()); } catch (e) { return; }
        if (sig === state.lastSavedLayout) { _ddLastSig = sig; ddSaveState('saved'); return; }
        ddSaveState('dirty');
        if (sig !== _ddLastSig) { _ddLastSig = sig; return; }
        _ddSaving = true;
        ddSaveState('saving');
        saveLayoutToServer().then(() => ddSaveState('saved'), (e) => {
            _ddRetryAt = Date.now() + 15000;
            ddSaveState('error');
            toast('Vorlage konnte nicht gespeichert werden: ' + e.message, 'error', 6000);
        }).finally(() => { _ddSaving = false; });
    }

    function ddInit() {
        setInterval(ddAutosaveTick, 1200);
        ddRenderTemplates();
        ddRenderTexte();
        ddSyncTemplates();
        document.getElementById('sidebar')?.addEventListener('focusin', (e) => {
            if (e.target.matches('textarea[data-bs-text], textarea[data-mail-text], input[data-mail-betreff]')) _ddLastField = e.target;
        });
        // Placeholder chips insert at the caret of the last focused field.
        document.getElementById('sidebar')?.addEventListener('click', (e) => {
            const chip = e.target.closest('.dd-chip');
            if (!chip) return;
            const scope = chip.closest('.dd-segpanel');
            let f = _ddLastField && scope && scope.contains(_ddLastField) ? _ddLastField : scope && scope.querySelector('textarea');
            if (!f) return;
            const ins = '{{' + chip.dataset.ph + '}}';
            const a = f.selectionStart ?? f.value.length, b = f.selectionEnd ?? f.value.length;
            f.value = f.value.slice(0, a) + ins + f.value.slice(b);
            f.focus();
            f.selectionStart = f.selectionEnd = a + ins.length;
            f.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function ddRenderTemplates() {
        const host = document.getElementById('ddTemplates');
        if (!host) return;
        host.innerHTML = DD_TEMPLATES.map(t => `
            <button type="button" class="dd-tpl" data-tpl="${t.id}" onclick="ddApplyTemplate('${t.id}')">
                <span class="dd-tpl-thumb dd-thumb-${t.id}" style="--tp:${t.primary};--ta:${t.accent}"><i></i><i></i><i></i><i></i><i></i></span>
                <span class="dd-tpl-name">${esc(t.name)}</span>
                <span class="dd-tpl-desc">${esc(t.desc)}</span>
                <span class="material-symbols-outlined dd-tpl-check">check_circle</span>
            </button>`).join('');
    }
    function ddSyncTemplates() {
        document.querySelectorAll('#ddTemplates .dd-tpl').forEach(b => b.classList.toggle('active', b.dataset.tpl === state.settings.design));
    }
    function ddApplyTemplate(id) {
        const t = DD_TEMPLATES.find(x => x.id === id);
        if (!t) return;
        state.settings.design = id;
        state.settings.primary_color = t.primary;
        state.settings.accent_color = t.accent;
        state.settings.font_family = t.font;
        applySettingsToUI();
        brandingChanged();
        if (window.FE) window.FE.reanchor();
        reanchorAssets();
        fitPageToViewport();
    }
    window.ddApplyTemplate = ddApplyTemplate;

    function ddRenderTexte() {
        const lib = DD_LIB[DOC_TYPE];
        const pdf = document.getElementById('ddPdfBausteine');
        const meta = {
            intro:   ['Einleitung', state.docType === 'ab' ? 'Einleitungssatz — erscheint über der Positionstabelle' : 'Erscheint über der Tabelle'],
            closing: ['Schlusstext', state.docType === 'ab' ? 'Dank & Grußformel — erscheint vor der Unterschrift' : 'Erscheint vor den Unterschriften'],
        };
        if (pdf) {
            pdf.innerHTML = BAUSTEIN_SLOTS.map(s => {
                const b = state.bausteine[s];
                return `<div class="sb-section dd-bs" data-slot="${s}">
                    <div class="dd-bs-head">
                        <div><div class="dd-bs-title">${meta[s][0]}</div><div class="dd-bs-sub">${meta[s][1]}</div></div>
                        <label class="dd-switch" title="Im PDF anzeigen"><input type="checkbox" data-bs-on ${b.on ? 'checked' : ''}><span></span></label>
                    </div>
                    <select class="dd-input" data-bs-lib><option value="">Standardtext wählen …</option>
                        ${lib[s].map((x, i) => `<option value="${i}">${esc(x.n)}</option>`).join('')}</select>
                    <textarea class="dd-input" data-bs-text rows="4" maxlength="2000">${esc(b.text)}</textarea>
                </div>`;
            }).join('') + '<div class="dd-lbl">Platzhalter</div>' + ddChips(DD_PH_PDF);
            pdf.querySelectorAll('.dd-bs').forEach(box => {
                const s = box.dataset.slot;
                const on = box.querySelector('[data-bs-on]');
                const ta = box.querySelector('[data-bs-text]');
                on.addEventListener('change', () => { state.bausteine[s].on = on.checked; rerenderDocument(); });
                box.querySelector('[data-bs-lib]').addEventListener('change', (e) => {
                    const x = lib[s][+e.target.value];
                    e.target.value = '';
                    if (!x) return;
                    ta.value = x.t;
                    state.bausteine[s] = { on: true, text: x.t };
                    on.checked = true;
                    rerenderDocument();
                });
                ta.addEventListener('input', () => { state.bausteine[s].text = ta.value; if (state.bausteine[s].on) ddRerenderSoon(); });
            });
        }

        const mail = document.getElementById('ddMail');
        if (!mail) return;
        const sig = mailSignature();
        mail.innerHTML = `
            <p class="dd-hint">Text der E-Mail, mit der dieses Dokument verschickt wird (Versand folgt). Aufbau nach deutschem Geschäftsbrauch: Betreff mit Dokument und Nummer, förmliche Anrede, kurzer Sachtext, Grußformel und Signatur mit Pflichtangaben.</p>
            <div class="sb-section">
                <div class="sb-section-title">E-Mail · ${esc(DOC_LABEL[state.docType])}</div>
                <select class="dd-input" data-mail-lib><option value="">Standardvorlage wählen …</option>
                    ${lib.mail.map((x, i) => `<option value="${i}">${esc(x.n)}</option>`).join('')}</select>
                <label class="dd-lbl">Betreff</label>
                <input type="text" class="dd-input" data-mail-betreff maxlength="200" value="${esc(state.email.betreff)}">
                <label class="dd-lbl">Nachricht</label>
                <textarea class="dd-input" data-mail-text rows="11" maxlength="5000">${esc(state.email.text)}</textarea>
                <div class="dd-lbl">Platzhalter</div>
                ${ddChips(DD_PH_MAIL)}
                <label class="sb-toggle" style="margin-top:8px"><input type="checkbox" data-mail-sig ${state.email.signatur ? 'checked' : ''}> Signatur mit Pflichtangaben anhängen</label>
                ${sig.missing.length ? `<div class="dd-warn"><span class="material-symbols-outlined">warning</span><span>Für die Pflichtangaben fehlt: ${esc(sig.missing.join(', '))} — bitte unter Konto &amp; Firma › Unternehmensdaten ergänzen.</span></div>` : ''}
            </div>
            <div class="sb-section">
                <div class="sb-section-title">Vorschau</div>
                <div class="dd-mail-prev" id="ddMailPrev"></div>
            </div>`;
        const bet = mail.querySelector('[data-mail-betreff]');
        const txt = mail.querySelector('[data-mail-text]');
        const sg = mail.querySelector('[data-mail-sig]');
        bet.addEventListener('input', () => { state.email.betreff = bet.value; ddRenderMailPreview(); });
        txt.addEventListener('input', () => { state.email.text = txt.value; ddRenderMailPreview(); });
        sg.addEventListener('change', () => { state.email.signatur = sg.checked; ddRenderMailPreview(); });
        mail.querySelector('[data-mail-lib]').addEventListener('change', (e) => {
            const x = lib.mail[+e.target.value];
            e.target.value = '';
            if (!x) return;
            bet.value = state.email.betreff = x.betreff;
            txt.value = state.email.text = x.text;
            ddRenderMailPreview();
        });
        ddRenderMailPreview();
    }

    function ddRenderMailPreview() {
        const host = document.getElementById('ddMailPrev');
        if (!host) return;
        const ctx = mailContext();
        const sig = mailSignature();
        const to = state.docType === 'worker' ? (ctx.mitarbeiter || 'Mitarbeiter') : (ctx.kunde || 'Kunde');
        host.innerHTML = `
            <div class="dd-mp-row"><span>An</span><strong>${esc(to)}</strong></div>
            <div class="dd-mp-row"><span>Betreff</span><strong>${esc(fillPlaceholders(state.email.betreff, ctx))}</strong></div>
            <div class="dd-mp-row"><span>Anhang</span><span class="dd-mp-att"><span class="material-symbols-outlined">picture_as_pdf</span>${esc(docFileName())}</span></div>
            <div class="dd-mp-body">${esc(fillPlaceholders(state.email.text, ctx))}</div>
            ${state.email.signatur ? `<div class="dd-mp-sig">-- <br>${sig.lines.map(esc).join('<br>')}${sig.legal.length ? '<br><br>' + sig.legal.map(esc).join('<br>') : ''}</div>` : ''}`;
    }

    function ddTab(name) {
        if (name === 'verlauf') ddLoadHistory();
        document.querySelectorAll('.dd-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
        document.querySelectorAll('.dd-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === name));
        const sb = document.getElementById('sidebar');
        if (sb) sb.scrollTop = 0;
    }
    function ddSeg(name) {
        document.querySelectorAll('.dd-seg button').forEach(b => b.classList.toggle('active', b.dataset.seg === name));
        document.querySelectorAll('.dd-segpanel').forEach(p => p.classList.toggle('active', p.dataset.segpanel === name));
    }
    function ddToggleSidebar(open) {
        document.body.classList.toggle('dd-sb-open', open === undefined ? !document.body.classList.contains('dd-sb-open') : !!open);
    }
    window.ddTab = ddTab;
    window.ddSeg = ddSeg;
    window.ddToggleSidebar = ddToggleSidebar;


    // ═══════════════════════════════════════════════════════════════
    // DOKUMENTVORLAGEN — VERLAUF (template versions: preview + restore)
    // ═══════════════════════════════════════════════════════════════
    const DD_REASON = { edit: 'Bearbeitet', restore: 'Wiederhergestellt', reset: 'Auf Standard zurückgesetzt', initial: 'Stand vor dem Verlauf' };
    async function ddLoadHistory() {
        const host = document.getElementById('ddHistory');
        if (!host) return;
        if (!CAN_SAVE_LAYOUT) { host.innerHTML = '<p class="dd-hint">Nur mit Berechtigung zum Bearbeiten der Vorlage sichtbar.</p>'; return; }
        host.innerHTML = '<p class="dd-hint">Lade Verlauf …</p>';
        try {
            const res = await fetch(API + '/pdf/layout/history?type=' + encodeURIComponent(DOC_TYPE), { headers: HEADERS() });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(d.error || ('HTTP ' + res.status));
            const rows = d.entries || [];
            if (!rows.length) { host.innerHTML = '<p class="dd-hint">Noch keine Versionen — die erste Änderung wird automatisch gesichert.</p>'; return; }
            host.innerHTML = rows.map((r, i) => `
                <div class="dd-hist${i === 0 ? ' current' : ''}${state.ddPreview && state.ddPreview.id === r.id ? ' previewing' : ''}" data-id="${esc(r.id)}">
                    <div class="dd-hist-main">
                        <div class="dd-hist-when">${esc(new Date(r.at * 1000).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }))}${i === 0 ? ' <span class="dd-hist-badge">Aktuell</span>' : ''}</div>
                        <div class="dd-hist-who">${esc(DD_REASON[r.reason] || r.reason)}${r.by_name ? ' · ' + esc(r.by_name) : ''}</div>
                    </div>
                    ${i === 0 ? '' : `<div class="dd-hist-actions">
                        <button type="button" class="dd-hist-btn" onclick="ddPreviewVersion('${esc(r.id)}')" title="Auf dem Beispiel ansehen"><span class="material-symbols-outlined">visibility</span></button>
                        <button type="button" class="dd-hist-btn primary" onclick="ddRestoreVersion('${esc(r.id)}')" title="Wiederherstellen"><span class="material-symbols-outlined">restore</span></button>
                    </div>`}
                </div>`).join('');
            state.ddHistory = rows;
        } catch (e) {
            host.innerHTML = '<p class="dd-hint">Verlauf konnte nicht geladen werden: ' + esc(e.message) + '</p>';
        }
    }

    async function ddFetchVersion(id) {
        const res = await fetch(API + '/pdf/layout/history/' + encodeURIComponent(id) + '?type=' + encodeURIComponent(DOC_TYPE), { headers: HEADERS() });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.error || ('HTTP ' + res.status));
        return d.layout || null;
    }

    async function ddPreviewVersion(id) {
        try {
            // Pending autosave first — the preview must never overwrite it.
            if (!state.ddPreview && hasUnsavedLayout()) await saveLayoutToServer();
            const layout = await ddFetchVersion(id);
            if (!state.ddPreview) state.ddPreview = { saved: state.layout };
            state.ddPreview.id = id;
            renderDoc(layout);
            document.body.classList.add('dd-previewing');
            const row = (state.ddHistory || []).find(r => r.id === id);
            const bar = document.getElementById('ddPreviewBar');
            if (bar) {
                bar.hidden = false;
                bar.querySelector('.dd-pv-text').textContent = 'Vorschau: Stand vom ' + (row ? new Date(row.at * 1000).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : '');
                bar.dataset.id = id;
            }
            document.querySelectorAll('.dd-hist').forEach(el => el.classList.toggle('previewing', el.dataset.id === id));
        } catch (e) { toast('Version konnte nicht geladen werden: ' + e.message, 'error', 5000); }
    }
    window.ddPreviewVersion = ddPreviewVersion;

    function ddExitPreview() {
        if (!state.ddPreview) return;
        const saved = state.ddPreview.saved;
        state.ddPreview = null;
        state.layout = saved;
        renderDoc(saved);
        state.lastSavedLayout = layoutSignature(collectLayout());
        document.body.classList.remove('dd-previewing');
        const bar = document.getElementById('ddPreviewBar');
        if (bar) bar.hidden = true;
        document.querySelectorAll('.dd-hist').forEach(el => el.classList.remove('previewing'));
        ddRenderTexte();
        ddSyncTemplates();
    }
    window.ddExitPreview = ddExitPreview;

    async function ddRestoreVersion(id) {
        id = id || (document.getElementById('ddPreviewBar') || {}).dataset?.id;
        if (!id) return;
        if (!confirm('Diesen Stand als Vorlage „' + DOC_LABEL[state.docType] + '“ wiederherstellen?\n\nEr gilt danach für alle Dokumente dieses Typs. Der aktuelle Stand bleibt im Verlauf erhalten.')) return;
        try {
            if (!state.ddPreview && hasUnsavedLayout()) await saveLayoutToServer();
            const res = await fetch(API + '/pdf/layout/restore', {
                method: 'POST',
                headers: { ...HEADERS(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: DOC_TYPE, id }),
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok || !d.success) throw new Error(d.error || ('HTTP ' + res.status));
            state.ddPreview = null;
            document.body.classList.remove('dd-previewing');
            const bar = document.getElementById('ddPreviewBar');
            if (bar) bar.hidden = true;
            state.layout = d.layout || null;
            renderDoc(state.layout);
            state.lastSavedLayout = layoutSignature(collectLayout());
            ddRenderTexte();
            ddSyncTemplates();
            ddSaveState('saved');
            toast('Version wiederhergestellt', 'success');
            ddLoadHistory();
        } catch (e) { toast('Wiederherstellen fehlgeschlagen: ' + e.message, 'error', 6000); }
    }
    window.ddRestoreVersion = ddRestoreVersion;

    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════
    function formatDate(d) { if (!d) return '–'; const p = d.split('-'); return p[2]+'.'+p[1]+'.'+p[0]; }
    function formatTime(t) { if (!t) return '–'; return t.substring(0, 5); }
    function formatEur(n) { return n.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; }
    function formatBytes(b) {
        b = parseInt(b) || 0;
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1024 / 1024).toFixed(2) + ' MB';
    }
    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function rgbToHex(rgb) {
        if (!rgb || rgb === 'transparent' || rgb === 'rgba(0, 0, 0, 0)') return '#000000';
        const m = rgb.match(/\d+/g);
        if (!m || m.length < 3) return '#000000';
        return '#' + m.slice(0,3).map(x => parseInt(x).toString(16).padStart(2,'0')).join('');
    }
    function shade(hex, pct) {
        const m = hex.replace('#','').match(/.{2}/g);
        if (!m) return hex;
        const [r,g,b] = m.map(x => parseInt(x,16));
        const adj = (v) => Math.max(0, Math.min(255, v + Math.round((255-v) * pct / 100)));
        return '#' + [adj(r),adj(g),adj(b)].map(x => x.toString(16).padStart(2,'0')).join('');
    }
    function toast(msg, type, ms) {
        const host = document.getElementById('toastHost');
        // Deduplicate: an identical toast that is still visible gets
        // re-raised instead of stacking clones (e.g. repeated clicks on
        // the signature toggle before the asset is uploaded).
        for (const el of [...host.children]) {
            if (el.dataset.msg === msg) el.remove();
        }
        // A new hint replaces older hints (they used to pile up over the
        // sheet); errors/warnings stay until they time out. Max 3 at once.
        if (type !== 'error' && type !== 'warning') {
            for (const el of [...host.children]) if (!el.classList.contains('error') && !el.classList.contains('warning')) el.remove();
        }
        while (host.children.length >= 3) host.firstChild.remove();
        const el = document.createElement('div');
        el.className = 'toast ' + (type || '');
        el.dataset.msg = msg;
        el.innerHTML = '<i class="material-symbols-outlined">' + (type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'check_circle') + '</i> ' + esc(msg);
        host.appendChild(el);
        const t = ms || 2200;
        setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateX(80px)'; }, t);
        setTimeout(() => el.remove(), t + 500);
    }

    // ═══════════════════════════════════════════════════════════════
    // HEADLESS RENDER API (?headless=1)
    // Used by app.js hmsWithHeadlessEditor() so the Auftragsbestätigung
    // list and the Monatsabschluss (send / print all) produce their PDFs
    // with THIS renderer + the company template — one look everywhere.
    // ═══════════════════════════════════════════════════════════════
    function exposeHeadlessApi() {
        const page = () => document.getElementById('orderForm');
        const api = window.__hmsDoc;
        api.fileName = () => docFileName();
        // Rendered document data (e.g. content_hash / next_version of an AB).
        api.data = () => JSON.parse(JSON.stringify(state.order || {}));
        // PDF of the currently rendered document.
        api.pdfBlob = async () => (await capturePdf()).output('blob');
        // Files a rendered PDF in the archive (Monatsabschluss send / AB list).
        api.archive = (blob, aktion) => archiveDoc(blob, aktion);
        api.archiveRef = () => archiveRef();
        // Render another Stundennachweis (Monatsabschluss batch) with the
        // same template; resolves once its images are loaded.
        api.renderWorker = async (doc) => {
            state.order = doc;
            renderDoc(state.layout);
            await waitForDocImages(page());
        };
        // Self-contained HTML of the current sheet (styles + images inlined)
        // — for the worker app message and the print-all document.
        api.sheetHtml = () => {
            const p = page();
            const restore = inlineDocImages(p);
            const pz = printZoomFor(p);
            markPrintTail(p, pz);
            let html;
            try {
                const clone = p.cloneNode(true);
                clone.removeAttribute('id');
                clone.classList.add('exporting');
                clone.classList.remove('edit-mode', 'fe-edit');
                clone.style.transform = '';
                clone.style.setProperty('--pz', String(pz));
                clone.querySelectorAll('.fe-handle, .fe-toolbar, .doc-asset-remove, .doc-asset-rot, .doc-asset-grip, .export-pad').forEach(n => n.remove());
                clone.querySelectorAll('[contenteditable]').forEach(n => n.removeAttribute('contenteditable'));
                html = clone.outerHTML;
            } finally { restore(); p.querySelectorAll('.print-break').forEach(n => n.classList.remove('print-break')); }
            return html;
        };
        api.documentHtml = (sheets, title) => {
            let css = '';
            for (const sh of Array.from(document.styleSheets)) {
                try {
                    if (sh.href && new URL(sh.href).origin !== location.origin) continue;
                    css += Array.from(sh.cssRules).map(r => r.cssText).join('\n') + '\n';
                } catch (e) { /* cross-origin sheet — not needed for the sheet */ }
            }
            const root = getComputedStyle(document.documentElement);
            css += ':root{--primary:' + root.getPropertyValue('--primary') + ';--accent:' + root.getPropertyValue('--accent') + ';}'
                + 'html,body{margin:0;padding:0;background:#fff !important;overflow:visible !important;}'
                + '.page{margin:0 auto !important;box-shadow:none !important;}'
                + '.hms-sheet-break{page-break-after:always;break-after:page;}'
                + '@media print{.page{max-width:none !important;}}'
                // Print-all: every sheet starts on its own page — same margins on all pages.
                + '@page{margin:0 0 12mm 0;}@page :first{margin:0 0 12mm 0;}';
            const body = (sheets || []).map((s, i) => i < sheets.length - 1 ? '<div class="hms-sheet-break">' + s + '</div>' : s).join('');
            return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                + '<title>' + esc(title || DOC_LABEL[state.docType]) + '</title><style>' + css + '</style></head><body>' + body + '</body></html>';
        };
    }
})();
