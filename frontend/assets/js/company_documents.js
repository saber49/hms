/* =====================================================================
   company_documents.js — "Konto & Firma" (System area)

   Merged full page replacing the old "Mein Konto" modal. MD3 tabs:
     • Mein Profil        (all users — profile + password change)
     • Unternehmensdaten  (admins — logo, contact, address)
     • Rechtliches        (admins — Handelsregister, USt-IdNr, …)
     • Bankverbindung     (admins — IBAN / BIC)
     • Dokumente          (admins — Firmendokumente upload/manage)
   Reuses the renderers + save logic from app.js:
     renderProfileTab / renderCompanyTab / renderLegalTab / renderBankTab
     saveAccountActiveTab / saveAccountPassword / _accountCompanyData
   Built with Google Material Design 3 web components (md-*):
     md-tabs / md-primary-tab, md-outlined-select, md-outlined-text-field,
     md-filled-button, md-icon-button, md-chip-set / md-filter-chip.
===================================================================== */

let _cdocsItems  = [];
let _cdocsCats   = {};
let _cdocsFilter = 'all';
let _cdocsSearch = '';
let _cdocsFile   = null;   // file picked via input OR drag&drop
let _cdocsTab    = 'profile';

function _cdocsCtx() {
    const u = window.CURRENT_USER || {};
    const t = typeof CURRENT_TENANT !== 'undefined' ? CURRENT_TENANT : {};
    // Company master data is Inhaber-only (backend: tenant.owner). NOT
    // users.manage — that key is delegable to administrators.
    const isAdmin = u.is_owner === true || u.is_owner === 1 || u.role === 'super_admin';
    const roleLabel = u.role === 'tenant_admin' ? 'Tenant Admin'
        : (u.role || 'viewer').charAt(0).toUpperCase() + (u.role || 'viewer').slice(1);
    return { user: u, tenant: t, isAdmin, roleLabel };
}

function _cdocsCanManageBereiche() {
    if (typeof hasPermission !== 'function') return false;
    return hasPermission('users.manage')
        || hasPermission('stammdaten.kunden.edit')
        || hasPermission('stammdaten.objekte.edit')
        || hasPermission('stammdaten.mitarbeiter.edit')
        || hasPermission('stammdaten.nu.edit');
}

function _cdocsTabDefs(isAdmin) {
    const tabs = [
        { id: 'profile',       icon: 'person',        label: 'Mein Profil' },
        { id: 'notifications', icon: 'notifications', label: 'Benachrichtigungen' },
    ];
    // Everything beyond the two personal tabs is Inhaber-only
    // (isAdmin = is_owner / super_admin, see _cdocsCtx).
    if (isAdmin) {
        tabs.push({ id: 'bereiche',  icon: 'shield',          label: 'Sicherheitsbereich' });
        tabs.push({ id: 'company',   icon: 'apartment',       label: 'Unternehmensdaten' });
        tabs.push({ id: 'legal',     icon: 'balance',         label: 'Rechtliches' });
        tabs.push({ id: 'bank',      icon: 'account_balance', label: 'Bankverbindung' });
        tabs.push({ id: 'documents', icon: 'folder_managed',  label: 'Dokumente' });
    }
    return tabs;
}

async function renderCompanyDocuments() {
    const content = document.getElementById('content');
    content.innerHTML = '<div class="text-center mt-3"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>';

    const ctx = _cdocsCtx();

    if (ctx.isAdmin) {
        try {
            const [companyRes, docsRes] = await Promise.all([
                apiGet(API_BASE + '/dashboard/company'),
                apiGet(API_BASE + '/dashboard/documents'),
            ]);
            _accountCompanyData = companyRes || {};
            _cdocsItems = Array.isArray(docsRes.items) ? docsRes.items : [];
            _cdocsCats  = docsRes.categories || {};
        } catch (e) {
            _accountCompanyData = _accountCompanyData || {};
            _cdocsItems = [];
            _cdocsCats  = {};
        }
    } else {
        if (!_cdocsTabDefs(false).some(t => t.id === _cdocsTab)) _cdocsTab = 'profile';
        // "Signatur & Stempel" on the profile tab needs the current asset
        // paths (own / company default) — Bestellungen editors only.
        if (typeof hasPermission === 'function' && hasPermission('auftraege.bestellungen.edit')) {
            try {
                const companyRes = await apiGet(API_BASE + '/dashboard/company');
                if (companyRes && typeof companyRes === 'object' && !Array.isArray(companyRes) && !companyRes.error) {
                    _accountCompanyData = companyRes;
                }
            } catch (e) { /* section falls back to "Nicht gesetzt" */ }
        }
    }
    _cdocsFilter = 'all';
    _cdocsSearch = '';
    _cdocsFile   = null;

    content.innerHTML = _cdocsPageHtml(ctx);
    _cdocsBindTabs();
    _cdocsRenderTab(_cdocsTab);
}
window.renderCompanyDocuments = renderCompanyDocuments;

/* Route the old modal entry points (sidebar user-info click, deep links)
   to the merged page. Loaded AFTER app.js, so this override wins. */
window.openAccountModal = function (activeTab) {
    _cdocsTab = activeTab || 'profile';
    loadPage('company_documents');
};

/* ── Page layout: hero + MD3 tabs ────────────────────────────────── */

function _cdocsPageHtml(ctx) {
    const u = ctx.user;
    const totalBytes = _cdocsItems.reduce((s, d) => s + (d.size_bytes || 0), 0);

    const tabsHtml = _cdocsTabDefs(ctx.isAdmin).map(t => `
        <md-primary-tab data-tab="${t.id}" ${t.id === _cdocsTab ? 'active' : ''}>
            <span class="material-symbols-outlined" slot="icon">${t.icon}</span>
            ${t.label}
        </md-primary-tab>`).join('');

    const statsHtml = ctx.isAdmin ? `
            <div class="cdocs-hero-stats">
                <div class="cdocs-stat"><span class="cdocs-stat-num" id="cdocsStatCount">${_cdocsItems.length}</span><span class="cdocs-stat-label">Dokumente</span></div>
                <div class="cdocs-stat"><span class="cdocs-stat-num" id="cdocsStatSize">${_cdocsFmtSize(totalBytes)}</span><span class="cdocs-stat-label">Speicher</span></div>
                <div class="cdocs-stat"><span class="cdocs-stat-num" id="cdocsStatCats">${new Set(_cdocsItems.map(d => d.category)).size}</span><span class="cdocs-stat-label">Kategorien</span></div>
            </div>` : '';

    return `
    <div class="cdocs-wrap">

        <!-- Hero: logged-in account + company -->
        <div class="cdocs-hero">
            <div class="cdocs-hero-icon"><span class="material-symbols-outlined">account_circle</span></div>
            <div class="cdocs-hero-text">
                <h2 class="md-typescale-headline-small">${escapeHtml(u.full_name || 'Benutzer')}</h2>
                <p class="md-typescale-body-medium">
                    <span class="role-badge role-${escapeHtml(u.role || 'viewer')}">${escapeHtml(ctx.roleLabel)}</span>
                    &nbsp;&middot;&nbsp; ${escapeHtml(ctx.tenant.name || '–')}
                </p>
            </div>
            ${statsHtml}
        </div>

        <!-- MD3 tab bar -->
        <md-tabs id="cdocsTabs" class="cdocs-tabs">${tabsHtml}</md-tabs>

        <!-- Active tab panel -->
        <div class="cdocs-panel" id="cdocsPanel"></div>
    </div>`;
}

function _cdocsBindTabs() {
    const tabs = document.getElementById('cdocsTabs');
    if (!tabs) return;
    _cdocsStickyTabs(tabs);
    tabs.addEventListener('change', () => {
        const active = tabs.activeTab;
        if (active && active.dataset.tab) _cdocsRenderTab(active.dataset.tab);
        // Switched while the bar is pinned: show the new section from its top.
        if (tabs.classList.contains('is-stuck')) {
            const wrapTop = tabs.parentElement.querySelector('.cdocs-panel').getBoundingClientRect().top;
            window.scrollBy({ top: wrapTop - tabs.getBoundingClientRect().bottom, behavior: 'smooth' });
        }
    });
}

/* Tab bar stays pinned under the app top bar while scrolling, so the
   current section is always visible. The offset follows the REAL top-bar
   height (60px desktop, 50px phone, + safe area in the iOS/Android app). */
let _cdocsStickyCleanup = null;
function _cdocsStickyTabs(tabs) {
    if (_cdocsStickyCleanup) _cdocsStickyCleanup();
    const topbar = document.querySelector('.topbar');
    const setTop = () => {
        const h = topbar ? Math.round(topbar.getBoundingClientRect().height) : 0;
        tabs.style.setProperty('--cdocs-sticky-top', h + 'px');
    };
    const onScroll = () => {
        if (!tabs.isConnected) return _cdocsStickyCleanup && _cdocsStickyCleanup();
        const top = parseFloat(getComputedStyle(tabs).top) || 0;
        tabs.classList.toggle('is-stuck', tabs.getBoundingClientRect().top <= top + 0.5 && window.scrollY > 0);
    };
    setTop(); onScroll();
    const ro = (topbar && 'ResizeObserver' in window) ? new ResizeObserver(() => { setTop(); onScroll(); }) : null;
    if (ro) ro.observe(topbar);
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', setTop);
    _cdocsStickyCleanup = () => {
        if (ro) ro.disconnect();
        window.removeEventListener('scroll', onScroll);
        window.removeEventListener('resize', setTop);
        _cdocsStickyCleanup = null;
    };
}

function _cdocsRenderTab(tabId) {
    _cdocsTab = tabId;
    const panel = document.getElementById('cdocsPanel');
    if (!panel) return;
    const ctx = _cdocsCtx();
    const c = _accountCompanyData || {};

    const saveBar = `
        <div class="cdocs-savebar">
            <md-filled-button onclick="cdocsSaveCompany()">
                <span class="material-symbols-outlined" slot="icon">save</span>
                Speichern
            </md-filled-button>
        </div>`;

    if (tabId === 'profile') {
        _accountCtx = ctx;
        panel.innerHTML = renderProfileTab(ctx, c);
    } else if (tabId === 'notifications') {
        _cdocsNotifRender(panel);
    } else if (tabId === 'company') {
        panel.innerHTML = renderCompanyTab(c) + saveBar;
        cfInitPauseRules(c.pause_rules);
    } else if (tabId === 'legal') {
        panel.innerHTML = renderLegalTab(c) + saveBar;
    } else if (tabId === 'bank') {
        panel.innerHTML = renderBankTab(c) + saveBar;
    } else if (tabId === 'documents') {
        panel.innerHTML = _cdocsDocsPanelHtml();
        _cdocsBind();
        _cdocsRenderList();
    } else if (tabId === 'bereiche') {
        _cdocsBereicheRender(panel);
    }
}

/* Save Unternehmensdaten / Rechtliches / Bankverbindung — reuses the
   modal's saveAccountActiveTab() (reads cf_* inputs via _readCompanyForm). */
async function cdocsSaveCompany() {
    _accountActiveTab = _cdocsTab;
    await saveAccountActiveTab();
}
window.cdocsSaveCompany = cdocsSaveCompany;

/* ── Benachrichtigungen tab — personal per-user toggles ────────────
   Master switches gate whole channels (E-Mail / Glocke); each
   Meldungstyp row carries one switch per channel plus an explanation.
   Persisted via /api/notifications/preferences on central_users
   .notification_prefs; NotificationDispatcher honours them at send
   time (opt-out model — unset keys stay on). */

async function _cdocsNotifRender(panel) {
    panel.innerHTML = '<div class="acc-loading"><i class="fas fa-spinner fa-spin"></i> Laden...</div>';
    let data = null;
    try {
        data = await apiGet(API_BASE + '/notifications/preferences');
    } catch (e) { /* fall through to error box */ }
    if (!data || data.error) {
        panel.innerHTML = '<div class="acc-info-box"><i class="fas fa-triangle-exclamation"></i> Benachrichtigungs-Einstellungen konnten nicht geladen werden.</div>';
        return;
    }
    panel.innerHTML = _cdocsNotifHtml(data);
    _cdocsNotifBind(panel);
}

function _cdocsNotifHtml(data) {
    const p = data.prefs || {};
    const appOn = p.app !== false;
    const pushOn = p.push !== false;
    const types = p.types || {};

    const masterRow = (ch, icon, title, desc, on) => `
        <div class="cnotif-master ${on ? '' : 'off'}">
            <span class="material-symbols-outlined cnotif-master-icon">${icon}</span>
            <div class="cnotif-master-text">
                <div class="cnotif-master-title">${escapeHtml(title)}</div>
                <div class="cnotif-master-desc">${escapeHtml(desc)}</div>
            </div>
            <md-switch id="cnotif_master_${ch}" ${on ? 'selected' : ''} aria-label="${escapeHtml(title)}"></md-switch>
        </div>`;

    const rows = (data.catalog || []).map(t => {
        const tp = types[t.key] || {};
        const ap = tp.app !== false, pu = tp.push !== false;
        return `
        <div class="cnotif-row" data-key="${escapeHtml(t.key)}">
            <div class="cnotif-row-text">
                <div class="cnotif-row-title">${escapeHtml(t.label)}${t.group ? ` <span class="cnotif-group">${escapeHtml(t.group)}</span>` : ''}</div>
                <div class="cnotif-row-desc">${escapeHtml(t.desc || '')}</div>
            </div>
            <div class="cnotif-toggles">
                <label class="cnotif-toggle ${appOn ? '' : 'disabled'}"><md-switch data-ch="app" ${ap ? 'selected' : ''} ${appOn ? '' : 'disabled'}></md-switch><span>App</span></label>
                <label class="cnotif-toggle ${pushOn ? '' : 'disabled'}"><md-switch data-ch="push" ${pu ? 'selected' : ''} ${pushOn ? '' : 'disabled'}></md-switch><span>Push</span></label>
            </div>
        </div>`;
    }).join('');

    return `
        <div class="acc-info-box" style="margin-bottom:16px">
            <i class="fas fa-info-circle"></i>
            Persönliche Einstellung — gilt nur für Ihr Konto. Meldungen kommen in der
            Glocke (oben rechts) und als Push auf Ihre Clood-X App (Android / iOS / Desktop).
            Ein deaktivierter Schalter wirkt sofort; andere Benutzer bleiben unberührt.
        </div>

        <div class="cdocs-card">
            <div class="cdocs-card-title md-typescale-title-medium">
                <span class="material-symbols-outlined">hub</span> Kanäle
            </div>
            ${masterRow('app', 'notifications_active', 'In-App-Benachrichtigungen',
                'Master-Schalter für ALLE Meldungen in der Glocke (oben rechts).', appOn)}
            ${masterRow('push', 'smartphone', 'Push-Benachrichtigungen',
                'Master-Schalter für ALLE Push-Meldungen auf Ihre Geräte — Clood-X App (Smartphone, auch bei geschlossener App) und Desktop-App.', pushOn)}
        </div>

        <div class="cdocs-card">
            <div class="cdocs-card-title md-typescale-title-medium">
                <span class="material-symbols-outlined">tune</span> Meldungstypen
            </div>
            <div class="cnotif-head"><span>Typ</span><span class="cnotif-head-ch">App</span><span class="cnotif-head-ch">Push</span></div>
            ${rows}
        </div>

        <div class="cnotif-status" id="cnotifStatus"></div>`;
}

let _cnotifSaveT = null;
function _cdocsNotifScheduleSave() {
    clearTimeout(_cnotifSaveT);
    _cnotifSaveT = setTimeout(() => cdocsSaveNotifications(), 400);
}

function _cdocsNotifBind(panel) {
    ['app', 'push'].forEach(ch => {
        const master = panel.querySelector('#cnotif_master_' + ch);
        if (!master) return;
        const apply = () => {
            const on = !!master.selected;
            master.closest('.cnotif-master').classList.toggle('off', !on);
            panel.querySelectorAll(`md-switch[data-ch="${ch}"]`).forEach(sw => {
                sw.disabled = !on;
                const lab = sw.closest('.cnotif-toggle');
                if (lab) lab.classList.toggle('disabled', !on);
            });
        };
        master.addEventListener('change', apply);
        master.addEventListener('input', apply);
    });
    // Auto-save: every switch flip persists after a short debounce.
    panel.querySelectorAll('md-switch').forEach(sw => {
        sw.addEventListener('change', _cdocsNotifScheduleSave);
    });
}

async function cdocsSaveNotifications() {
    const panel = document.getElementById('cdocsPanel');
    if (!panel) return;
    const types = {};
    panel.querySelectorAll('.cnotif-row').forEach(r => {
        const ap = r.querySelector('md-switch[data-ch="app"]');
        const pu = r.querySelector('md-switch[data-ch="push"]');
        types[r.dataset.key] = {
            app:   ap ? !!ap.selected : true,
            push:  pu ? !!pu.selected : true,
        };
    });
    const ma = panel.querySelector('#cnotif_master_app');
    const mp = panel.querySelector('#cnotif_master_push');
    const payload = {
        app:   ma ? !!ma.selected : true,
        push:  mp ? !!mp.selected : true,
        types,
    };
    const status = document.getElementById('cnotifStatus');
    if (status) status.textContent = 'Speichern…';
    try {
        const res = await apiPut(API_BASE + '/notifications/preferences', payload);
        if (res && res.success) {
            if (status) {
                status.textContent = 'Gespeichert';
                clearTimeout(status._fadeT);
                status._fadeT = setTimeout(() => { status.textContent = ''; }, 2500);
            }
        } else {
            if (status) status.textContent = '';
            showToast((res && res.error) || 'Speichern fehlgeschlagen', 'error');
        }
    } catch (e) {
        if (status) status.textContent = '';
        showToast('Speichern fehlgeschlagen', 'error');
    }
}
window.cdocsSaveNotifications = cdocsSaveNotifications;

/* ── Documents tab panel ─────────────────────────────────────────── */

function _cdocsDocsPanelHtml() {
    const catOptions = Object.entries(_cdocsCats)
        .map(([k, label]) => `<md-select-option value="${escapeHtml(k)}"><div slot="headline">${escapeHtml(label)}</div></md-select-option>`)
        .join('');

    const filterChips = ['<md-filter-chip label="Alle" data-cat="all" selected></md-filter-chip>']
        .concat(Object.entries(_cdocsCats).map(([k, label]) =>
            `<md-filter-chip label="${escapeHtml(label)}" data-cat="${escapeHtml(k)}"></md-filter-chip>`))
        .join('');

    return `
        <div class="acc-info-box" style="margin-bottom:16px">
            <i class="fas fa-info-circle"></i>
            Hinterlegen Sie offizielle Unternehmensunterlagen — Gewerbeschein, Handelsregisterauszug,
            Steuerbescheid, Versicherungsnachweise &hellip; Erlaubt: PDF, JPG, PNG, WEBP &middot; max. 10&nbsp;MB pro Datei.
        </div>

        <!-- Upload card -->
        <div class="cdocs-card cdocs-upload-card">
            <div class="cdocs-card-title md-typescale-title-medium">
                <span class="material-symbols-outlined">upload_file</span> Neues Dokument hochladen
            </div>
            <div class="cdocs-upload-grid">
                <div class="cdocs-dropzone" id="cdocsDropzone" tabindex="0">
                    <input type="file" id="cdocsFileInput" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" hidden>
                    <span class="material-symbols-outlined cdocs-dz-icon">cloud_upload</span>
                    <div class="cdocs-dz-text" id="cdocsDzText">
                        <strong>Datei hierher ziehen</strong> oder klicken zum Auswählen
                    </div>
                    <div class="cdocs-dz-hint md-typescale-body-small">PDF, JPG, PNG, WEBP &middot; max. 10 MB</div>
                </div>
                <div class="cdocs-upload-fields">
                    <md-outlined-select id="cdocsCategory" label="Kategorie" required>
                        ${catOptions}
                    </md-outlined-select>
                    <md-outlined-text-field id="cdocsTitle" label="Bezeichnung" placeholder="z. B. Gewerbeschein 2026" maxlength="200" required>
                        <span class="material-symbols-outlined" slot="leading-icon">description</span>
                    </md-outlined-text-field>
                    <md-filled-button id="cdocsUploadBtn">
                        <span class="material-symbols-outlined" slot="icon">upload</span>
                        Hochladen
                    </md-filled-button>
                </div>
            </div>
        </div>

        <!-- Toolbar: filter chips + search -->
        <div class="cdocs-toolbar">
            <md-chip-set id="cdocsChips">${filterChips}</md-chip-set>
            <md-outlined-text-field id="cdocsSearch" label="Suchen" placeholder="Bezeichnung oder Dateiname" class="cdocs-search">
                <span class="material-symbols-outlined" slot="leading-icon">search</span>
            </md-outlined-text-field>
        </div>

        <!-- Documents grid -->
        <div class="cdocs-grid" id="cdocsGrid"></div>
        <div id="cdocsPager" class="be-pager"></div>`;
}

/* ── List rendering ──────────────────────────────────────────────── */

function _cdocsRenderList() {
    const grid = document.getElementById('cdocsGrid');
    if (!grid) return;

    const q = _cdocsSearch.toLowerCase();
    const items = _cdocsItems.filter(d => {
        if (_cdocsFilter !== 'all' && d.category !== _cdocsFilter) return false;
        if (q && !((d.title || '').toLowerCase().includes(q) || (d.original_filename || '').toLowerCase().includes(q))) return false;
        return true;
    });

    if (items.length === 0) {
        hmsPagerClear('cdocsPager');
        grid.innerHTML = `
            <div class="cdocs-empty">
                <span class="material-symbols-outlined">folder_off</span>
                <p class="md-typescale-body-large">${_cdocsItems.length === 0 ? 'Noch keine Dokumente hochgeladen.' : 'Keine Dokumente für diesen Filter.'}</p>
            </div>`;
        return;
    }

    const _p = hmsPagerDef('cdocsPager', 'Dokumente', () => _cdocsRenderList());
    const pg = hmsPage(items, _p.st);
    hmsPagerFill('cdocsPager', pg);
    grid.innerHTML = pg.rows.map(d => _cdocsCardHtml(d)).join('');
    if (window.hydrateSecureThumbs) window.hydrateSecureThumbs(grid);
}

function _cdocsCardHtml(d) {
    const isPdf = (d.mime_type || '').includes('pdf');
    const icon  = isPdf ? 'fa-file-pdf' : 'fa-file-image';
    const iconColor = isPdf ? '#dc2626' : '#2563eb';
    const dateStr = d.created_at
        ? new Date(d.created_at).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
        : '';
    const thumbUrl = API_BASE + '/dashboard/documents/' + d.id + '/thumb';

    return `
    <div class="cdocs-doc" data-id="${d.id}">
        <div class="doc-thumb cdocs-doc-thumb" data-thumb-url="${escapeHtml(thumbUrl)}">
            <i class="fas ${icon}" style="color:${iconColor}"></i>
        </div>
        <div class="cdocs-doc-body">
            <div class="cdocs-doc-title md-typescale-title-small" title="${escapeHtml(d.original_filename || '')}">${escapeHtml(d.title)}</div>
            <div class="cdocs-doc-cat"><span class="cdocs-cat-badge">${escapeHtml(d.category_label || d.category)}</span></div>
            <div class="cdocs-doc-meta md-typescale-body-small">
                ${_cdocsFmtSize(d.size_bytes || 0)} &middot; ${escapeHtml(dateStr)}
                ${d.uploaded_by_name ? '&middot; ' + escapeHtml(d.uploaded_by_name) : ''}
            </div>
        </div>
        <div class="cdocs-doc-actions">
            <md-icon-button title="Anzeigen" onclick="cdocsView(${d.id})"><span class="material-symbols-outlined">visibility</span></md-icon-button>
            <md-icon-button title="Herunterladen" onclick="cdocsDownload(${d.id})"><span class="material-symbols-outlined">download</span></md-icon-button>
            <md-icon-button title="Löschen" class="cdocs-danger" onclick="cdocsDelete(${d.id})"><span class="material-symbols-outlined">delete</span></md-icon-button>
        </div>
    </div>`;
}

/* ── Bindings ────────────────────────────────────────────────────── */

function _cdocsBind() {
    // Dropzone
    const dz = document.getElementById('cdocsDropzone');
    const fileInput = document.getElementById('cdocsFileInput');
    if (dz && fileInput) {
        dz.addEventListener('click', () => fileInput.click());
        dz.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
        fileInput.addEventListener('change', () => _cdocsSetFile(fileInput.files && fileInput.files[0]));
        ['dragover', 'dragenter'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('is-drag'); }));
        ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('is-drag'); }));
        dz.addEventListener('drop', e => {
            const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) _cdocsSetFile(f);
        });
    }

    // Upload button
    const btn = document.getElementById('cdocsUploadBtn');
    if (btn) btn.addEventListener('click', _cdocsUpload);

    // Filter chips
    const chips = document.getElementById('cdocsChips');
    if (chips) {
        chips.addEventListener('click', e => {
            const chip = e.target.closest('md-filter-chip');
            if (!chip) return;
            // Single-select behaviour
            chips.querySelectorAll('md-filter-chip').forEach(c => { c.selected = (c === chip); });
            chip.selected = true;
            _cdocsFilter = chip.dataset.cat || 'all';
            _cdocsRenderList();
        });
    }

    // Search
    const search = document.getElementById('cdocsSearch');
    if (search) {
        search.addEventListener('input', () => {
            _cdocsSearch = (search.value || '').trim();
            _cdocsRenderList();
        });
    }
}

function _cdocsSetFile(file) {
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) {
        showToast('Datei zu groß (max. 10 MB)', 'error');
        return;
    }
    _cdocsFile = file;
    const txt = document.getElementById('cdocsDzText');
    if (txt) txt.innerHTML = `<strong>${escapeHtml(file.name)}</strong> (${_cdocsFmtSize(file.size)})`;
    const dz = document.getElementById('cdocsDropzone');
    if (dz) dz.classList.add('has-file');
    // Pre-fill title from filename if empty
    const titleEl = document.getElementById('cdocsTitle');
    if (titleEl && !titleEl.value) {
        titleEl.value = file.name.replace(/\.[^.]+$/, '');
    }
}

/* ── Actions ─────────────────────────────────────────────────────── */

async function _cdocsUpload() {
    const catEl   = document.getElementById('cdocsCategory');
    const titleEl = document.getElementById('cdocsTitle');
    const category = catEl ? catEl.value : '';
    const title    = titleEl ? (titleEl.value || '').trim() : '';

    if (!category)   { showToast('Bitte Kategorie auswählen', 'error'); return; }
    if (!title)      { showToast('Bitte Bezeichnung eingeben', 'error'); return; }
    if (!_cdocsFile) { showToast('Bitte Datei auswählen', 'error'); return; }

    const fd = new FormData();
    fd.append('file', _cdocsFile);
    fd.append('category', category);
    fd.append('title', title);

    const btn = document.getElementById('cdocsUploadBtn');
    if (btn) btn.disabled = true;

    try {
        const res = await fetch(API_BASE + '/dashboard/documents', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || ''), 'Accept': 'application/json' },
            body: fd,
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showToast(data.error || (data.errors && Object.values(data.errors)[0]?.[0]) || 'Upload fehlgeschlagen', 'error');
            return;
        }
        showToast('Dokument hochgeladen');
        const docsRes = await apiGet(API_BASE + '/dashboard/documents').catch(() => null);
        if (docsRes && Array.isArray(docsRes.items)) { _cdocsItems = docsRes.items; _cdocsCats = docsRes.categories || _cdocsCats; }
        _cdocsFile = null;
        if (document.getElementById('cdocsPanel') && _cdocsTab === 'documents') { _cdocsRenderTab('documents'); _cdocsUpdateStats(); }
        else await renderCompanyDocuments();
    } catch (e) {
        showToast('Upload fehlgeschlagen', 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

function _cdocsById(id) {
    return _cdocsItems.find(d => Number(d.id) === Number(id));
}

async function cdocsView(id) {
    try {
        const res = await fetch(API_BASE + '/dashboard/documents/' + id + '/view', {
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || '') },
        });
        if (!res.ok) { showToast('Datei konnte nicht geladen werden', 'error'); return; }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        window.open(url, '_blank');
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) {
        showToast('Datei konnte nicht geladen werden', 'error');
    }
}
window.cdocsView = cdocsView;

async function cdocsDownload(id) {
    const d = _cdocsById(id);
    if (!d) return;
    try {
        const res = await fetch(API_BASE + '/dashboard/documents/' + id + '/download', {
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || '') },
        });
        if (!res.ok) { showToast('Download fehlgeschlagen', 'error'); return; }
        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url;
        a.download = d.original_filename || d.title || 'dokument';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 5000);
    } catch (e) {
        showToast('Download fehlgeschlagen', 'error');
    }
}
window.cdocsDownload = cdocsDownload;

async function cdocsDelete(id) {
    if (!confirm('Dokument wirklich löschen?')) return;
    const result = await apiDelete(API_BASE + '/dashboard/documents/' + id);
    if (!result || !result.success) {
        showToast((result && result.error) || 'Löschen fehlgeschlagen', 'error');
        return;
    }
    showToast('Dokument gelöscht');
    _cdocsItems = _cdocsItems.filter(d => Number(d.id) !== Number(id));
    _cdocsRenderList();
    _cdocsUpdateStats();
}
window.cdocsDelete = cdocsDelete;

/* ── Helpers ─────────────────────────────────────────────────────── */

function _cdocsUpdateStats() {
    const countEl = document.getElementById('cdocsStatCount');
    const sizeEl  = document.getElementById('cdocsStatSize');
    const catsEl  = document.getElementById('cdocsStatCats');
    if (countEl) countEl.textContent = _cdocsItems.length;
    if (sizeEl)  sizeEl.textContent  = _cdocsFmtSize(_cdocsItems.reduce((s, d) => s + (d.size_bytes || 0), 0));
    if (catsEl)  catsEl.textContent  = new Set(_cdocsItems.map(d => d.category)).size;
}

function _cdocsFmtSize(bytes) {
    const kb = Math.round((bytes || 0) / 1024);
    return kb >= 1024 ? (kb / 1024).toFixed(1) + '\u00a0MB' : kb + '\u00a0KB';
}

/* ── Bereiche tab — Hauptbereiche + Unterbereiche catalog ──────────
   The catalog feeds the Dienstleistungen checkboxes (customers,
   objects, workers, subcontractors) and the Objektarten suggestions.
   Restricted users only see the mains inside their own whitelist. */

let _bereichCat = [];
// Platform service types of the Sicherheitsbranche (GET /sicherheitsleistungen).
let _bcatLeistungen = [];

async function bcatSetLeistung(id, value) {
    const r = await apiPut(API_BASE + '/bereich-catalog/' + id, { leistungsart_id: value ? Number(value) : null });
    if (r && r.error) { showToast(r.error, 'error'); return; }
    showToast('Leistungsart gespeichert');
    _bcatReload();
}
window.bcatSetLeistung = bcatSetLeistung;
async function _cdocsBereicheRender(panel, quiet) {
    // quiet=true keeps the current DOM while refetching (no "Laden…" flash)
    // — used for the reload after own edits and for realtime pushes.
    if (!quiet) {
        panel.innerHTML = '<div class="acc-loading"><i class="fas fa-spinner fa-spin"></i> Laden...</div>';
    }
    _bcatBindRealtime();
    let data = null;
    try {
        const [cat, types] = await Promise.all([
            apiGet(API_BASE + '/bereich-catalog'),
            apiGet(API_BASE + '/sicherheitsleistungen').catch(() => []),
        ]);
        data = cat;
        _bcatLeistungen = Array.isArray(types) ? types : [];
    } catch (e) { /* error box below */ }
    if (!data || !Array.isArray(data.mains)) {
        if (!quiet) {
            panel.innerHTML = '<div class="acc-info-box"><i class="fas fa-triangle-exclamation"></i> Bereiche konnten nicht geladen werden.</div>';
        }
        return;
    }
    _bereichCat = data.mains;
    if (typeof ensureBereichColors === 'function') await ensureBereichColors(true);
    if (quiet && panel.childElementCount && typeof hmsMorph === 'function') {
        // Own edit: the field that was just submitted must come back empty
        // (a focused field keeps its typed value during a patch).
        if (quiet !== 'rt' && panel.contains(document.activeElement)) document.activeElement.blur();
        hmsMorph(panel, _cdocsBereicheHtml());
    } else {
        panel.innerHTML = _cdocsBereicheHtml();
    }
}

// Sub-tab of the Sicherheitsbereich page + expanded Qualifikations-/Kleidungs-Listen.
let _bcatTab = (() => { try { return localStorage.getItem('bcat_tab') || 'einsatzarten'; } catch { return 'einsatzarten'; } })();
const _bqOpen = new Set((() => { try { return JSON.parse(localStorage.getItem('bq_open') || '[]'); } catch { return []; } })());
let _bcatMainId = null;

function _bcatRepaint() {
    const panel = document.getElementById('cdocsPanel');
    if (!panel) return;
    if (typeof hmsMorph === 'function') hmsMorph(panel, _cdocsBereicheHtml()); else panel.innerHTML = _cdocsBereicheHtml();
}
function bcatSetTab(tab) {
    _bcatTab = tab;
    try { localStorage.setItem('bcat_tab', tab); } catch { /* private mode */ }
    _bcatRepaint();
}
window.bcatSetTab = bcatSetTab;
function bcatPickMain(id) { _bcatMainId = id; _bcatRepaint(); }
window.bcatPickMain = bcatPickMain;
function bqToggleOpen(ev, id) {
    if (ev && ev.target.closest('button, input, select, label')) return;
    _bqOpen.has(id) ? _bqOpen.delete(id) : _bqOpen.add(id);
    try { localStorage.setItem('bq_open', JSON.stringify([..._bqOpen])); } catch { /* private mode */ }
    _bcatRepaint();
}
window.bqToggleOpen = bqToggleOpen;

// "Verwendet in" chips — where a list shows up in the system.
function _bcatUsedIn(items) {
    return `<div class="bsec-used"><span class="bsec-used-lbl">Verwendet in</span>${items.map(([ic, t]) =>
        `<span class="bsec-chip"><span class="material-symbols-outlined">${ic}</span>${t}</span>`).join('')}</div>`;
}

function _cdocsBereicheHtml() {
    const canEdit = _cdocsCanManageBereiche() || (typeof hasPermission === 'function' && hasPermission('users.manage'));
    const q = (s) => escapeHtml(s).replace(/'/g, "\\'");
    const mains = _bereichCat || [];
    if (!mains.length) {
        return `<div class="bsec-wrap"><div class="acc-info-box">Noch kein Sicherheitsbereich vorhanden.
            ${canEdit ? '<md-filled-button onclick="bcatAddMain()" style="margin-left:8px"><span class="material-symbols-outlined" slot="icon">add_moderator</span>Sicherheitsbereich anlegen</md-filled-button>' : ''}</div></div>`;
    }
    const m = mains.find(x => x.id === _bcatMainId) || mains.find(x => x.aktiv) || mains[0];
    const vars = (typeof bereichColorVars === 'function') ? bereichColorVars(m.name) : '';
    const col = (typeof bereichColor === 'function') ? bereichColor(m.name) : '#2563eb';
    const subs = m.subs || [];
    const quals = m.quals || [];
    const kleidung = m.kleidung || [];
    // Leistungsart column hidden (user decision) — mapping stays internal
    // (auto by name on create), only the § 34a requirement is shown.
    const unmapped = 0;

    const tabs = [
        { id: 'einsatzarten',   icon: 'shield',            label: 'Einsatzarten',     n: subs.length, warn: unmapped },
        { id: 'qualifikationen', icon: 'workspace_premium', label: 'Qualifikationen',  n: quals.length },
        { id: 'kleidung',       icon: 'apparel',           label: 'Dienstkleidung',   n: kleidung.length },
        { id: 'einstellungen',  icon: 'tune',              label: 'Einstellungen' },
    ];
    const tab = tabs.some(t => t.id === _bcatTab) ? _bcatTab : 'einsatzarten';

    // ── Einsatzarten ────────────────────────────────────────────────
    const leistungCell = (s) => {
        if (!_bcatLeistungen.length) return '';
        const cur = _bcatLeistungen.find(t => t.id === s.leistungsart_id) || null;
        const sel = canEdit
            ? `<select class="bsec-sel ${cur ? '' : 'bsec-sel-open'}" aria-label="Leistungsart" onchange="bcatSetLeistung(${s.id}, this.value)">
                <option value="" ${cur ? '' : 'selected'}>— zuordnen —</option>
                ${_bcatLeistungen.map(t => `<option value="${t.id}" ${cur && cur.id === t.id ? 'selected' : ''}>${escapeHtml(t.name)}</option>`).join('')}
               </select>`
            : `<span class="bsec-muted">${cur ? escapeHtml(cur.name) : 'nicht zugeordnet'}</span>`;
        const badge = cur && cur.sachkunde
            ? `<span class="bsec-badge bsec-badge-sk" title="${escapeHtml(cur.hinweis || '')}">Sachkunde</span>`
            : (cur && cur.hinweis ? `<span class="bsec-badge" title="${escapeHtml(cur.hinweis)}">Hinweis</span>` : `<span class="bsec-badge bsec-badge-ok">Unterrichtung</span>`);
        void sel; void badge;
        return ''; // § 34a column removed (user decision)
    };
    const rowActions = (s, rename, toggle, del) => canEdit ? `<div class="bsec-c-act">
            <button class="bcat-btn" title="Umbenennen" onclick="${rename}">edit</button>
            <button class="bcat-btn" title="${s.aktiv ? 'Ausblenden' : 'Einblenden'}" onclick="${toggle}">${s.aktiv ? 'visibility_off' : 'visibility'}</button>
            <button class="bcat-btn bcat-del" title="Löschen" onclick="${del}">delete</button>
        </div>` : '';
    const einsatzHtml = `
        <section class="bsec-card bsec-card-ea">
            <header class="bsec-head">
                <div>
                    <h4>Einsatzarten</h4>
                    <p>Was Ihr Unternehmen leistet — Namen frei wählbar. Diese Liste erscheint bei Objekten und Artikeln.</p>
                </div>
                ${_bcatUsedIn([['location_on', 'Objekte'], ['sell', 'Artikel']])}
            </header>
            ${unmapped ? `<div class="bsec-warn"><span class="material-symbols-outlined">info</span>${unmapped} Einsatzart${unmapped === 1 ? '' : 'en'} ohne Leistungsart — bitte zuordnen.</div>` : ''}
            <div class="bsec-table bsec-table-ea">
                <div class="bsec-tr bsec-th"><div>Einsatzart</div>${canEdit ? '<div class="bsec-c-act"></div>' : ''}</div>
                ${subs.map(s => `
                <div class="bsec-tr ${s.aktiv ? '' : 'bcat-off'}">
                    <div class="bsec-c-name"><span class="bcat-sub-dot" style="${vars}"></span>${escapeHtml(s.name)}</div>
                    ${leistungCell(s)}
                    ${rowActions(s, `bcatRename(${s.id}, '${q(s.name)}', true)`, `bcatToggle(${s.id}, ${s.aktiv ? 0 : 1})`, `bcatDelete(${s.id}, '${q(s.name)}')`)}
                </div>`).join('') || '<div class="bsec-empty">Noch keine Einsatzarten — z.B. Objektschutz, Revierdienst, Empfangsdienst.</div>'}
            </div>
            ${canEdit ? `<div class="bsec-add">
                <input class="bcat-input" id="bcat_sub_${m.id}" placeholder="Neue Einsatzart, z.B. Objektschutz" maxlength="100" onkeydown="if(event.key==='Enter')bcatAddSub(${m.id})">
                <md-filled-tonal-button onclick="bcatAddSub(${m.id})"><span class="material-symbols-outlined" slot="icon">add</span>Einsatzart</md-filled-tonal-button>
            </div>` : ''}
        </section>`;

    // ── Qualifikations-/Kleidungs-Listen (accordion) ─────────────────
    const listsHtml = (arr, icon, optPh, empty) => arr.map(l => {
        const open = _bqOpen.has(l.id);
        const opts = l.options || [];
        return `
        <div class="bsec-list ${l.aktiv ? '' : 'bcat-off'} ${open ? 'is-open' : ''}">
            <div class="bsec-list-head" onclick="bqToggleOpen(event, ${l.id})">
                <span class="material-symbols-outlined bsec-list-ic">${icon}</span>
                <span class="bsec-list-name">${escapeHtml(l.name)}</span>
                <span class="bsec-count">${opts.length} ${opts.length === 1 ? 'Eintrag' : 'Einträge'}</span>
                ${canEdit ? `<span class="bsec-list-act">
                    <button class="bcat-btn" title="Liste umbenennen" onclick="bqRename(${l.id}, '${q(l.name)}', true)">edit</button>
                    <button class="bcat-btn" title="${l.aktiv ? 'Ausblenden' : 'Einblenden'}" onclick="bqToggle(${l.id}, ${l.aktiv ? 0 : 1})">${l.aktiv ? 'visibility_off' : 'visibility'}</button>
                    <button class="bcat-btn bcat-del" title="Liste löschen" onclick="bqDelete(${l.id}, '${q(l.name)}')">delete</button>
                </span>` : ''}
                <span class="material-symbols-outlined bsec-chev">expand_more</span>
            </div>
            ${open ? `<div class="bsec-list-body">
                ${opts.map(o => `
                <div class="bsec-opt ${o.aktiv ? '' : 'bcat-off'}">
                    <span class="bsec-opt-name">${escapeHtml(o.name)}</span>
                    ${canEdit ? `<span class="bsec-list-act">
                        <button class="bcat-btn" title="Umbenennen" onclick="bqRename(${o.id}, '${q(o.name)}', false)">edit</button>
                        <button class="bcat-btn" title="${o.aktiv ? 'Ausblenden' : 'Einblenden'}" onclick="bqToggle(${o.id}, ${o.aktiv ? 0 : 1})">${o.aktiv ? 'visibility_off' : 'visibility'}</button>
                        <button class="bcat-btn bcat-del" title="Löschen" onclick="bqDelete(${o.id}, '${q(o.name)}')">delete</button>
                    </span>` : ''}
                </div>`).join('') || '<div class="bsec-empty">Noch keine Einträge</div>'}
                ${canEdit ? `<div class="bsec-add bsec-add-sm">
                    <input class="bcat-input" id="bq_opt_${l.id}" placeholder="Neuer Eintrag, z.B. ${optPh}" maxlength="100" onkeydown="if(event.key==='Enter')bqAddOption(${l.id})">
                    <md-text-button onclick="bqAddOption(${l.id})"><span class="material-symbols-outlined" slot="icon">add</span>Eintrag</md-text-button>
                </div>` : ''}
            </div>` : ''}
        </div>`;
    }).join('') || `<div class="bsec-empty">${empty}</div>`;

    const qualHtml = `
        <section class="bsec-card">
            <header class="bsec-head">
                <div>
                    <h4>Qualifikationen &amp; Nachweise</h4>
                    <p>Sachkunde, Unterrichtung, DFB-Module, Erste Hilfe … Sie werden beim Mitarbeiter hinterlegt und in Bestellungen als Anforderung gewählt.</p>
                </div>
                ${_bcatUsedIn([['badge', 'Personal'], ['inbox', 'Bestelleingang · Anforderungen'], ['event_note', 'Planung'], ['swap_horiz', 'Schichtbörse']])}
            </header>
            ${canEdit ? `<div class="bsec-toolbar"><md-outlined-button onclick="bqStandardLists(${m.id})"><span class="material-symbols-outlined" slot="icon">library_add</span>Branchenvorlagen übernehmen (§ 34a, DFB)</md-outlined-button></div>` : ''}
            <div class="bsec-lists">${listsHtml(quals, 'workspace_premium', 'Sachkundeprüfung', 'Noch keine Listen — z.B. „§ 34a GewO“ mit Unterrichtung, Sachkundeprüfung, GSSK.')}</div>
            ${canEdit ? `<div class="bsec-add">
                <input class="bcat-input" id="bq_list_${m.id}" placeholder="Neue Liste, z.B. Erste Hilfe" maxlength="100" onkeydown="if(event.key==='Enter')bqAddList(${m.id})">
                <md-filled-tonal-button onclick="bqAddList(${m.id})"><span class="material-symbols-outlined" slot="icon">add</span>Liste</md-filled-tonal-button>
            </div>` : ''}
        </section>`;

    const klHtml = `
        <section class="bsec-card">
            <header class="bsec-head">
                <div>
                    <h4>Dienstkleidung</h4>
                    <p>Was das Personal im Einsatz trägt. Wird pro Bestellung als Anforderung gewählt und in der Planung angezeigt.</p>
                </div>
                ${_bcatUsedIn([['inbox', 'Bestelleingang · Anforderungen'], ['event_note', 'Planung']])}
            </header>
            <div class="bsec-lists">${listsHtml(kleidung, 'apparel', 'Security-Weste', 'Noch keine Listen — z.B. „Dienstkleidung“ mit Uniform, Security-Weste, schwarze Hose.')}</div>
            ${canEdit ? `<div class="bsec-add">
                <input class="bcat-input" id="bq_klist_${m.id}" placeholder="Neue Liste, z.B. Dienstkleidung" maxlength="100" onkeydown="if(event.key==='Enter')bqAddList(${m.id}, 'kleidung')">
                <md-filled-tonal-button onclick="bqAddList(${m.id}, 'kleidung')"><span class="material-symbols-outlined" slot="icon">add</span>Liste</md-filled-tonal-button>
            </div>` : ''}
        </section>`;

    // ── Einstellungen ───────────────────────────────────────────────
    const legacy = mains.length > 1;
    const setHtml = `
        <section class="bsec-card">
            <header class="bsec-head">
                <div>
                    <h4>Personalakte — Zusatzfelder</h4>
                    <p>Welche Sicherheitsfelder bei Mitarbeitern und im Nachunternehmer-Portal erscheinen.</p>
                </div>
                ${_bcatUsedIn([['badge', 'Personal · Tab Sicherheit'], ['handshake', 'NU-Portal']])}
            </header>
            <div class="bsec-flags">
                <label class="bsec-flag bsec-flag-fixed" title="Immer aktiv — Clood-X ist für Sicherheitsdienste ausgelegt">
                    <input type="checkbox" checked disabled>
                    <span><b>Bewachung nach § 34a GewO</b><small>Bewacherregister-ID, Funkrufname · immer aktiv</small></span>
                </label>
                <label class="bsec-flag">
                    <input type="checkbox" ${m.stadiondienst ? 'checked' : ''} ${canEdit ? '' : 'disabled'} onchange="bcatSetFlag(${m.id}, 'stadiondienst', this.checked)">
                    <span><b>Veranstaltungs- &amp; Stadiondienst</b><small>DFB-Pin, Ordnernummer, Barcode, Stadionverbot</small></span>
                </label>
            </div>
        </section>
        <section class="bsec-card">
            <header class="bsec-head">
                <div>
                    <h4>Bereichsfarbe</h4>
                    <p>Farbe des Sicherheitsbereichs in Planung, Auswertungen und Listen.</p>
                </div>
            </header>
            <div class="bsec-color-row">
                <label class="bcat-color${canEdit ? '' : ' bcat-color-ro'}" style="${vars}" title="${canEdit ? 'Farbe wählen' : 'Farbe'}">
                    <input type="color" value="${col}" ${canEdit ? '' : 'disabled'} onchange="bcatSetColor(${m.id}, this.value)">
                </label>
                <span class="bsec-muted">${m.farbe ? 'Eigene Farbe' : 'Automatische Farbe'}</span>
                ${canEdit && m.farbe ? `<md-text-button onclick="bcatSetColor(${m.id}, '')">Automatisch</md-text-button>` : ''}
            </div>
        </section>
        ${legacy ? `<section class="bsec-card">
            <header class="bsec-head"><div><h4>Weitere Bereiche (Altdaten)</h4><p>Aus früheren Versionen. Neue Hauptbereiche können nicht angelegt werden.</p></div></header>
            ${mains.filter(x => x.id !== m.id).map(x => `<div class="bsec-tr ${x.aktiv ? '' : 'bcat-off'}"><div class="bsec-c-name">${escapeHtml(x.name)}</div>
                ${rowActions(x, `bcatRename(${x.id}, '${q(x.name)}', false)`, `bcatToggle(${x.id}, ${x.aktiv ? 0 : 1})`, `bcatDelete(${x.id}, '${q(x.name)}')`)}</div>`).join('')}
        </section>` : ''}`;

    const body = { einsatzarten: einsatzHtml, qualifikationen: qualHtml, kleidung: klHtml, einstellungen: setHtml }[tab];

    return `
    <div class="bsec-wrap" style="${vars}">
        <div class="bsec-top">
            <span class="bsec-top-ic material-symbols-outlined">shield_person</span>
            <div>
                <h3 class="md-typescale-title-large">Sicherheitsbereich</h3>
                <p>Stammlisten Ihres Sicherheitsdienstes — bei jeder Liste steht, wo sie verwendet wird.</p>
            </div>
        </div>
        ${legacy ? `<div class="bsec-mains">${mains.map(x => `<button class="bsec-main-pill ${x.id === m.id ? 'is-on' : ''}" onclick="bcatPickMain(${x.id})">${escapeHtml(x.name)}</button>`).join('')}</div>` : ''}
        <nav class="bsec-tabs" role="tablist">
            ${tabs.map(t => `<button role="tab" class="bsec-tab ${t.id === tab ? 'is-on' : ''}" aria-selected="${t.id === tab}" onclick="bcatSetTab('${t.id}')">
                <span class="material-symbols-outlined">${t.icon}</span><span class="bsec-tab-lbl">${t.label}</span>
                ${t.n !== undefined ? `<span class="bsec-tab-n">${t.n}</span>` : ''}${t.warn ? '<span class="bsec-tab-dot" title="Zuordnung fehlt"></span>' : ''}
            </button>`).join('')}
        </nav>
        ${body}
    </div>
    <style>
        .bsec-wrap{padding:16px;max-width:1100px}
        .bsec-top{display:flex;gap:14px;align-items:center;margin-bottom:16px}
        .bsec-top h3{margin:0}
        .bsec-top p{margin:2px 0 0;color:var(--gray-600);font-size:.9rem}
        .bsec-top-ic{flex:none;width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:26px;color:#fff;background:linear-gradient(135deg,#1e3a8a,#2563eb)}
        .bsec-mains{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
        .bsec-main-pill{border:1px solid var(--gray-300);background:var(--white);border-radius:999px;padding:4px 12px;font:inherit;font-size:.82rem;cursor:pointer}
        .bsec-main-pill.is-on{background:var(--gray-900,#111827);color:#fff;border-color:transparent}
        .bsec-tabs{display:flex;gap:4px;border-bottom:1px solid var(--gray-200);margin-bottom:16px;overflow-x:auto;scrollbar-width:none}
        .bsec-tab{display:inline-flex;align-items:center;gap:6px;border:0;background:none;font:inherit;font-size:.9rem;color:var(--gray-600);padding:10px 14px;border-bottom:2px solid transparent;margin-bottom:-1px;cursor:pointer;white-space:nowrap;position:relative}
        .bsec-tab .material-symbols-outlined{font-size:19px}
        .bsec-tab:hover{color:var(--gray-900,#111827)}
        .bsec-tab.is-on{color:var(--accent,#1a73e8);border-bottom-color:var(--accent,#1a73e8);font-weight:600}
        .bsec-tab-n{font-size:.72rem;background:var(--gray-100);color:var(--gray-700);border-radius:999px;padding:0 7px;font-weight:600}
        .bsec-tab-dot{width:7px;height:7px;border-radius:50%;background:#f59e0b}
        .bsec-card{border:1px solid var(--gray-200);border-radius:14px;background:var(--white);padding:18px;margin-bottom:14px}
        .bsec-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}
        .bsec-head > div:first-child{flex:1 1 320px;min-width:0}
        .bsec-head h4{margin:0 0 4px;font-size:1.02rem}
        .bsec-head p{margin:0;color:var(--gray-600);font-size:.85rem;line-height:1.45}
        .bsec-used{display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex:0 1 auto}
        .bsec-used-lbl{font-size:.68rem;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--gray-500)}
        .bsec-chip{display:inline-flex;align-items:center;gap:4px;font-size:.76rem;color:#1e3a8a;background:#eff6ff;border:1px solid #dbeafe;border-radius:999px;padding:2px 9px}
        .bsec-chip .material-symbols-outlined{font-size:14px}
        .bsec-warn{display:flex;gap:6px;align-items:center;font-size:.82rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:8px 10px;margin-bottom:12px}
        .bsec-warn .material-symbols-outlined{font-size:18px}
        .bsec-table{border:1px solid var(--gray-200);border-radius:10px;overflow:hidden}
        .bsec-tr{display:grid;grid-template-columns:minmax(160px,1.2fr) minmax(200px,1.6fr) 120px 110px;align-items:center;gap:10px;padding:8px 12px;border-top:1px solid var(--gray-100)}
        .bsec-tr:first-child{border-top:0}
        .bsec-card-ea{max-width:680px}
        .bsec-table-ea .bsec-tr{grid-template-columns:minmax(160px,1fr) 110px}
        .bsec-card-ea .bsec-add .bcat-input{max-width:none}
        .bsec-th{background:var(--gray-50);font-size:.7rem;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--gray-500)}
        .bsec-c-name{display:flex;align-items:center;gap:8px;font-size:.9rem;font-weight:500;min-width:0;overflow-wrap:anywhere}
        .bsec-c-act{display:flex;justify-content:flex-end;gap:2px}
        .bsec-sel{width:100%;font:inherit;font-size:.82rem;padding:5px 8px;border:1px solid var(--gray-200);border-radius:8px;background:var(--white);color:var(--gray-800)}
        .bsec-sel-open{border-color:#f59e0b;background:#fffbeb;color:#92400e}
        .bsec-badge{font-size:.7rem;font-weight:600;padding:2px 9px;border-radius:999px;background:var(--gray-100);color:var(--gray-700);cursor:help;white-space:nowrap}
        .bsec-badge-sk{background:#fef3c7;color:#92400e}
        .bsec-badge-ok{background:#ecfdf5;color:#065f46;cursor:default}
        .bsec-muted{font-size:.82rem;color:var(--gray-500)}
        .bsec-empty{color:var(--gray-400);font-size:.85rem;padding:10px 12px}
        .bsec-add{display:flex;gap:8px;align-items:center;margin-top:12px}
        .bsec-add .bcat-input{flex:1;max-width:420px}
        .bsec-add-sm{margin:6px 0 2px}
        .bsec-add-sm .bcat-input{padding:6px 10px;font-size:.85rem}
        .bsec-toolbar{margin-bottom:12px}
        .bsec-lists{display:flex;flex-direction:column;gap:8px}
        .bsec-list{border:1px solid var(--gray-200);border-radius:10px;background:var(--white)}
        .bsec-list.is-open{border-color:var(--gray-300);box-shadow:0 1px 6px rgba(15,23,42,.05)}
        .bsec-list-head{display:flex;align-items:center;gap:8px;padding:10px 12px;cursor:pointer}
        .bsec-list-ic{font-size:19px;color:var(--bc,var(--accent,#1a73e8))}
        .bsec-list-name{flex:1;font-weight:600;font-size:.92rem;min-width:0;overflow-wrap:anywhere}
        .bsec-count{font-size:.75rem;color:var(--gray-500);white-space:nowrap}
        .bsec-list-act{display:flex;gap:2px}
        .bsec-chev{color:var(--gray-500);transition:transform .2s}
        .bsec-list.is-open .bsec-chev{transform:rotate(180deg)}
        .bsec-list-body{padding:2px 12px 10px 39px;border-top:1px solid var(--gray-100)}
        .bsec-opt{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px dashed var(--gray-100)}
        .bsec-opt:last-of-type{border-bottom:0}
        .bsec-opt-name{flex:1;font-size:.88rem;min-width:0;overflow-wrap:anywhere}
        .bsec-flags{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}
        .bsec-flag{display:flex;gap:10px;align-items:flex-start;border:1px solid var(--gray-200);border-radius:10px;padding:10px 12px;cursor:pointer}
        .bsec-flag input{margin-top:3px;accent-color:var(--bc,var(--accent,#1a73e8))}
        .bsec-flag span{display:flex;flex-direction:column;font-size:.88rem}
        .bsec-flag small{color:var(--gray-500);font-size:.76rem}
        .bsec-flag-fixed{background:var(--gray-50);cursor:default}
        .bsec-color-row{display:flex;align-items:center;gap:10px}
        .bcat-color{position:relative;width:26px;height:26px;border-radius:50%;flex:none;cursor:pointer;background:var(--bc);box-shadow:0 0 0 3px var(--bc-bg),0 1px 3px rgba(0,0,0,.25)}
        .bcat-color-ro{cursor:default}
        .bcat-color input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:inherit;border:0;padding:0}
        .bcat-sub-dot{width:9px;height:9px;border-radius:50%;background:var(--bc);flex:none}
        .bcat-off{opacity:.55}
        .bcat-input{min-width:0;padding:9px 12px;border:1px solid var(--gray-300);border-radius:8px;font:inherit;font-size:.9rem}
        .bcat-btn{border:0;background:none;cursor:pointer;font-family:'Material Symbols Outlined';font-size:18px;color:var(--gray-500);padding:4px;border-radius:6px;line-height:1}
        .bcat-btn:hover{background:var(--gray-100);color:var(--gray-800)}
        .bcat-del:hover{color:#b91c1c}
        @media (max-width:760px){
            .bsec-wrap{padding:12px}
            .bsec-th{display:none}
            .bsec-tr{grid-template-columns:1fr auto;grid-template-areas:"name act" "type type" "req req";row-gap:6px}
            .bsec-c-name{grid-area:name}
            .bsec-c-act{grid-area:act}
            .bsec-c-type{grid-area:type}
            .bsec-table-ea .bsec-tr{grid-template-columns:1fr auto;grid-template-areas:"name act"}
            .bsec-card-ea{max-width:none}
            .bsec-c-req{grid-area:req}
            .bsec-tab-lbl{font-size:.82rem}
            .bsec-tab{padding:10px 10px}
            .bsec-add{flex-wrap:wrap}
            .bsec-add .bcat-input{flex:1 1 100%;max-width:none}
            .bsec-list-body{padding-left:12px}
        }
    </style>`;
}

/* Realtime: another user's catalog edit broadcasts data.changed{bereiche}
   on the tenant channel — refresh the list if the tab is on screen. */
let _bcatRtBound = false;
function _bcatBindRealtime() {
    if (_bcatRtBound) return;
    if (window.HMSRealtime && typeof HMSRealtime.on === 'function') {
        HMSRealtime.on('bereiche', () => _bcatReload(true));
        _bcatRtBound = true;
    }
}

async function _bcatReload(fromRealtime) {
    invalidateBereichCache();
    // Only repaint when the Bereiche tab is actually showing — a realtime
    // push while the user sits on another tab must not clobber its panel.
    if (_cdocsTab !== 'bereiche') return;
    const panel = document.getElementById('cdocsPanel');
    if (panel) _cdocsBereicheRender(panel, fromRealtime === true ? 'rt' : true);
}

// Only used when a company has no main at all: creates its security area.
async function bcatAddMain() {
    const r = await apiPost(API_BASE + '/bereich-catalog', { name: 'Sicherheit' });
    if (r && r.error) { showToast(r.error, 'error'); return; }
    showToast('Sicherheitsbereich angelegt');
    _bcatReload();
}
window.bcatAddMain = bcatAddMain;

// Colour of a Hauptbereich ('' = back to the automatic colour). Every
// chip / badge / chart in the app reads it via bereichColor().
async function bcatSetColor(id, farbe) {
    const r = await apiPut(API_BASE + '/bereich-catalog/' + id, { farbe: farbe || null });
    if (!r || r.error) { showToast((r && r.error) || 'Farbe konnte nicht gespeichert werden', 'error'); _bcatReload(); return; }
    showToast(farbe ? 'Farbe gespeichert — gilt überall im System' : 'Automatische Farbe aktiv');
    _bcatReload();
}
window.bcatSetColor = bcatSetColor;

async function bcatAddSub(parentId) {
    const inp = document.getElementById('bcat_sub_' + parentId);
    const name = (inp.value || '').trim();
    if (!name) return;
    const r = await apiPost(API_BASE + '/bereich-catalog', { name, parent_id: parentId });
    if (r && r.error) { showToast(r.error, 'error'); return; }
    inp.value = '';
    showToast('Einsatzart angelegt');
    _bcatReload();
}
window.bcatAddSub = bcatAddSub;

async function bcatRename(id, oldName, isSub) {
    const neu = prompt((isSub ? 'Einsatzart' : 'Bereich') + ' umbenennen — alle vorhandenen Daten werden aktualisiert:', oldName);
    if (neu === null) return;
    const name = neu.trim();
    if (!name || name === oldName) return;
    const r = await apiPut(API_BASE + '/bereich-catalog/' + id, { name });
    if (r && r.error) { showToast(r.error, 'error'); return; }
    showToast('Umbenannt — ' + (r.renamed_rows || 0) + ' Datensätze aktualisiert');
    _bcatReload();
}
window.bcatRename = bcatRename;

// Security switches of a Hauptbereich (worker form: Sicherheit tab /
// stadium fields). Stadium implies Bewachung; Bewachung off clears both.
async function bcatSetFlag(id, field, on) {
    const r = await apiPut(API_BASE + '/bereich-catalog/' + id, { [field]: on ? 1 : 0 });
    if (!r || r.error) { showToast((r && r.error) || 'Fehler', 'error'); _bcatReload(); return; }
    showToast('Gespeichert');
    _bcatReload();
}
window.bcatSetFlag = bcatSetFlag;

// Starter lists for a security area — only lists the Bereich does not have
// yet (same name) are created; existing lists stay untouched.
const BQ_STANDARD_LISTS = [
    { name: '§ 34 GewO', opts: ['Unterrichtung (§34a)', 'Sachkundeprüfung (§34a)',
        'Geprüfte Schutz- und Sicherheitskraft (GSSK)', 'Fachkraft für Schutz und Sicherheit'] },
    { name: 'DFB Schulung', stadium: true, opts: ['Modul 1 - E-Learning', 'Modul 1 - Prüfung', 'Modul 2 - Ortskunde',
        'Modul 3 - Brandschutz', 'Modul 4 - Erste-Hilfe', 'Modul 5 - Deeskalation', 'Modul 6 - Einlass',
        'Modul 7 - Führungskräfte', 'Modul 8 - Einsatzbereitschaft', 'Modul 9 - VIP'] },
];
async function bqStandardLists(mainId) {
    const m = (_bereichCat || []).find(x => x.id === mainId);
    if (!m) return;
    const have = new Set((m.quals || []).map(l => String(l.name).trim().toLowerCase()));
    const todo = BQ_STANDARD_LISTS.filter(l => (!l.stadium || m.stadiondienst) && !have.has(l.name.toLowerCase()));
    if (!todo.length) { showToast('Alle Standard-Listen sind bereits vorhanden'); return; }
    if (!confirm('Folgende Listen für „' + m.name + '“ anlegen?\n\n' + todo.map(l => '• ' + l.name + ' (' + l.opts.length + ' Einträge)').join('\n')
        + '\n\nVorhandene Listen bleiben unverändert. Einträge können danach umbenannt oder gelöscht werden.')) return;
    for (const l of todo) {
        const r = await apiPost(API_BASE + '/bereich-qualifikationen', { bereich_id: mainId, name: l.name });
        if (!r || r.error || !r.id) { showToast((r && r.error) || 'Fehler', 'error'); break; }
        for (const name of l.opts) await apiPost(API_BASE + '/bereich-qualifikationen', { parent_id: r.id, name });
    }
    showToast('Standard-Listen angelegt');
    _bcatReload();
}
window.bqStandardLists = bqStandardLists;

async function bcatToggle(id, aktiv) {
    const r = await apiPut(API_BASE + '/bereich-catalog/' + id, { aktiv });
    if (r && r.error) { showToast(r.error, 'error'); return; }
    _bcatReload();
}
window.bcatToggle = bcatToggle;

async function bcatDelete(id, name) {
    if (!confirm('„' + name + '" wirklich löschen? Bereits zugeordnete Daten behalten ihren Wert.')) return;
    const r = await apiDelete(API_BASE + '/bereich-catalog/' + id);
    if (r && r.error) { showToast(r.error, 'error'); return; }
    showToast('Gelöscht');
    _bcatReload();
}
window.bcatDelete = bcatDelete;

/* ── Qualifikationen lists per Hauptbereich (e.g. „§34a“ → Sachkunde …) ── */
async function _bqSave(promise, okMsg) {
    const r = await promise;
    if (!r || r.error) { showToast((r && r.error) || 'Fehler', 'error'); return false; }
    if (okMsg) showToast(okMsg);
    _bcatReload();
    return true;
}

async function bqAddList(mainId, typ) {
    const inp = document.getElementById((typ === 'kleidung' ? 'bq_klist_' : 'bq_list_') + mainId);
    const name = (inp?.value || '').trim();
    if (!name) return;
    const body = { bereich_id: mainId, name };
    if (typ) body.typ = typ;
    if (await _bqSave(apiPost(API_BASE + '/bereich-qualifikationen', body), 'Liste angelegt')) inp.value = '';
}
window.bqAddList = bqAddList;

async function bqAddOption(listId) {
    const inp = document.getElementById('bq_opt_' + listId);
    const name = (inp?.value || '').trim();
    if (!name) return;
    if (await _bqSave(apiPost(API_BASE + '/bereich-qualifikationen', { parent_id: listId, name }), 'Eintrag angelegt')) inp.value = '';
}
window.bqAddOption = bqAddOption;

async function bqRename(id, oldName, isList) {
    const neu = prompt((isList ? 'Liste' : 'Eintrag') + ' umbenennen — gilt für alle Mitarbeiter:', oldName);
    if (neu === null) return;
    const name = neu.trim();
    if (!name || name === oldName) return;
    _bqSave(apiPut(API_BASE + '/bereich-qualifikationen/' + id, { name }), 'Umbenannt');
}
window.bqRename = bqRename;

function bqToggle(id, aktiv) {
    _bqSave(apiPut(API_BASE + '/bereich-qualifikationen/' + id, { aktiv }));
}
window.bqToggle = bqToggle;

function bqDelete(id, name) {
    if (!confirm('„' + name + '" wirklich löschen?')) return;
    _bqSave(apiDelete(API_BASE + '/bereich-qualifikationen/' + id), 'Gelöscht');
}
window.bqDelete = bqDelete;
