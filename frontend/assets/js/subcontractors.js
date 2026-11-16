/* ============================================
   HMS Gruppe — Nachunternehmer (Subcontractors) Module
   - Stats overview row
   - Aktiv/Archiv filter toggle
   - Mitarbeiter + Dokumente columns with expiry warnings
   - Übersicht tab with stats + compliance checklist
   - Document expiry tracking (gueltig_ab / gueltig_bis)
   - Validation error mapping (422)
   - Delete preview with cascade impact
   ============================================ */

let _nuFilterMode = 'aktiv'; // 'aktiv' | 'archiv' | 'alle'

// ---------- MD3 dialog (official <md-dialog>) ----------
// The NU module no longer uses the legacy openModal() shell.
// custIcon + mdConfirm used to be borrowed from customers.js/users.js —
// but those modules only load with THEIR pages, so opening
// Nachunternehmer directly crashed on the first icon call. Local copies
// (identical implementations) keep this module self-contained.
function custIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}
function mdConfirm(message, { title = 'Bestätigen', confirmLabel = 'OK' } = {}) {
    return new Promise(resolve => {
        const dlg = document.createElement('md-dialog');
        dlg.innerHTML = `
          <div slot="headline">${title}</div>
          <div slot="content" style="white-space:pre-line">${message}</div>
          <div slot="actions">
            <md-text-button data-v="cancel">Abbrechen</md-text-button>
            <md-filled-button data-v="ok">${confirmLabel}</md-filled-button>
          </div>`;
        document.body.appendChild(dlg);
        dlg.querySelector('[data-v="cancel"]').onclick = () => dlg.close('cancel');
        dlg.querySelector('[data-v="ok"]').onclick = () => dlg.close('ok');
        dlg.addEventListener('closed', () => { const ok = dlg.returnValue === 'ok'; dlg.remove(); resolve(ok); });
        dlg.show();
    });
}
function openSubDialog(title, bodyHtml, footerHtml = '', wide = false) {
    document.getElementById('subMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'subMdDialog';
    if (wide) dlg.classList.add('sub-dialog-xl');
    dlg.innerHTML = `
      <div slot="headline">${title}</div>
      <div slot="content" class="sub-dialog-content">${bodyHtml}</div>
      <div slot="actions">${footerHtml}</div>`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();
    return dlg;
}
function closeSubDialog() { document.getElementById('subMdDialog')?.close(); }

const NU_DOK_TYPEN = [
    'Handelsregisterauszug',
    'Gewerbean-/ummeldung',
    'Auszug aus dem Gewerbezentralregister',
    'Unbedenklichkeitsbescheinigung Finanzamt',
    'Unbedenklichkeitsbescheinigung BG',
    'Unbedenklichkeitsbescheinigung Krankenkassen',
    'Nachweis der Betriebshaftpflichtversicherung',
    'Verpflichtung auf das Datengeheimnis',
    'Selbständigkeitserklärung',
    'Kopie Personalausweis des Vertragsunterzeichners'
];

const FAELLIG_LABELS = {
    vertragsbeginn: 'Vertragsbeginn',
    jaehrlich: 'Jährlich 01.01.',
    auf_anforderung: 'Auf Anforderung'
};

// ============================================
// RENDER PAGE
// ============================================
async function renderSubcontractors() {
    const content = document.getElementById('content');
    const data = await apiGet(API.subcontractors);
    const subs = Array.isArray(data) ? data : [];

    const total = subs.length;
    const aktiv = subs.filter(s => s.aktiv == 1).length;
    const portal = subs.filter(s => s.portal_aktiv == 1).length;
    const docExpired = subs.reduce((sum, s) => sum + (parseInt(s.dokumente_abgelaufen) || 0), 0);
    const docExpiring = subs.reduce((sum, s) => sum + (parseInt(s.dokumente_ablaufend) || 0), 0);
    const mitarbeiterTotal = subs.reduce((sum, s) => sum + (parseInt(s.mitarbeiter_aktiv) || 0), 0);

    const soft = !!document.getElementById('subsTable') && typeof hmsMorph === 'function';
    const complianceClass = docExpired > 0 ? 'danger' : (docExpiring > 0 ? 'warn' : '');
    const complianceLabel = docExpired > 0
        ? 'Dokumente abgelaufen'
        : (docExpiring > 0 ? 'Dokumente laufen ab' : 'Compliance OK');

    const html = `
        <div class="nu-stats-grid">
            <div class="nu-stat-card nu-stat-total ${_nuFilterMode === 'alle' ? 'is-active' : ''}" onclick="setNuFilter('alle')" title="Alle anzeigen">
                <div class="nu-stat-head">
                    <div class="nu-stat-icon">${custIcon('engineering')}</div>
                    <span class="nu-stat-cue">Total</span>
                </div>
                <div class="nu-stat-num">${total}</div>
                <div class="nu-stat-lbl">NU gesamt</div>
            </div>
            <div class="nu-stat-card nu-stat-active ${_nuFilterMode === 'aktiv' ? 'is-active' : ''}" onclick="setNuFilter('aktiv')" title="Nur aktive">
                <div class="nu-stat-head">
                    <div class="nu-stat-icon">${custIcon('how_to_reg')}</div>
                    <span class="nu-stat-cue">Aktiv</span>
                </div>
                <div class="nu-stat-num">${aktiv}<small>/ ${total}</small></div>
                <div class="nu-stat-lbl">Aktive NU</div>
            </div>
            <div class="nu-stat-card nu-stat-portal">
                <div class="nu-stat-head">
                    <div class="nu-stat-icon">${custIcon('public')}</div>
                    <span class="nu-stat-cue">Portal</span>
                </div>
                <div class="nu-stat-num">${portal}</div>
                <div class="nu-stat-lbl">Portal aktiv</div>
            </div>
            <div class="nu-stat-card nu-stat-people">
                <div class="nu-stat-head">
                    <div class="nu-stat-icon">${custIcon('group')}</div>
                    <span class="nu-stat-cue">Personal</span>
                </div>
                <div class="nu-stat-num">${mitarbeiterTotal}</div>
                <div class="nu-stat-lbl">Aktive Mitarbeiter</div>
            </div>
            <div class="nu-stat-card nu-stat-compliance ${complianceClass}">
                <div class="nu-stat-head">
                    <div class="nu-stat-icon">${custIcon('shield')}</div>
                    <span class="nu-stat-cue">Compliance</span>
                </div>
                <div class="nu-stat-num">${docExpired + docExpiring}</div>
                <div class="nu-stat-lbl">${complianceLabel}</div>
            </div>
        </div>

        <div class="data-toolbar">
            <div class="data-toolbar-left">
                <md-outlined-text-field class="nu-search" id="searchSubs" label="Nachunternehmer suchen" placeholder="Name, Bereich, Ort…">
                    <md-icon slot="leading-icon">search</md-icon>
                </md-outlined-text-field>
                <md-chip-set id="nuFilterToggle">
                    <md-filter-chip label="Aktiv" data-mode="aktiv" ${_nuFilterMode === 'aktiv' ? 'selected' : ''} onclick="setNuFilter('aktiv')"></md-filter-chip>
                    <md-filter-chip label="Archiv" data-mode="archiv" ${_nuFilterMode === 'archiv' ? 'selected' : ''} onclick="setNuFilter('archiv')"></md-filter-chip>
                    <md-filter-chip label="Alle" data-mode="alle" ${_nuFilterMode === 'alle' ? 'selected' : ''} onclick="setNuFilter('alle')"></md-filter-chip>
                </md-chip-set>
            </div>
            <div class="data-toolbar-right">
                <md-outlined-button onclick="togglePortalLink('nu')">
                    <md-icon slot="icon">link</md-icon>Portal-Link
                </md-outlined-button>
                <md-filled-button onclick="openSubModal()" style="--md-filled-button-container-color:var(--nu-accent);--md-filled-button-label-text-color:#fff">
                    <md-icon slot="icon">add</md-icon>Neuer Nachunternehmer
                </md-filled-button>
            </div>
        </div>

        <div id="nuPortalLinkBar" style="display:none;">
            <div class="nu-portal-link-bar">
                <div class="nu-portal-link-info">
                    ${custIcon('public', 'color:var(--nu-accent);font-size:1.15rem;flex-shrink:0')}
                    <div style="min-width:0;">
                        <div class="nu-portal-link-label">Nachunternehmer-Portal Link</div>
                        <div id="nuPortalLinkUrl" class="nu-portal-link-url"></div>
                    </div>
                </div>
                <md-filled-tonal-button onclick="copyPortalLink('nu')">
                    <md-icon slot="icon">content_copy</md-icon>Kopieren
                </md-filled-tonal-button>
            </div>
        </div>

        <div class="nu-table-card">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>NU-ID</th>
                            <th>Firmenname</th>
                            <th>Ansprechpartner</th>
                            <th>Bereich</th>
                            <th>Mitarbeiter</th>
                            <th>Dokumente</th>
                            <th>Portal</th>
                            <th>Aktiv</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody id="subsTable">
                        ${subs.map(s => nuRow(s)).join('')}
                    </tbody>
                </table>
                ${subs.length === 0 ? `<div class="empty-state">${custIcon('engineering', 'font-size:2.5rem')}<p>Noch keine Nachunternehmer vorhanden</p></div>` : ''}
                <div id="nuPager" class="be-pager"></div>
            </div>
        </div>
    `;

    cachedData.subcontractors = subs;
    if (soft) {
        // Page on screen (save, delete): tiles + rows patched; toolbar and the
        // portal link bar keep their state (search text, open bar).
        hmsMorph(content, html, { keep: el => el.classList.contains('data-toolbar') || el.id === 'nuPortalLinkBar' });
    } else {
        content.innerHTML = html;
        _wireNuSearch();
    }
    filterNuTable();
}

function nuRow(s) {
    const ma_t = parseInt(s.mitarbeiter_total) || 0;
    const ma_a = parseInt(s.mitarbeiter_aktiv) || 0;
    const dokTotal = parseInt(s.dokumente_total) || 0;
    const dokExp = parseInt(s.dokumente_abgelaufen) || 0;
    const dokWarn = parseInt(s.dokumente_ablaufend) || 0;

    let mitarbeiterBadge;
    if (ma_t === 0) mitarbeiterBadge = '<span class="nu-obj-badge none">–</span>';
    else mitarbeiterBadge = `<span class="nu-obj-badge ${ma_a === ma_t ? 'all-active' : (ma_a === 0 ? 'none-active' : 'partial')}" title="${ma_a} aktiv von ${ma_t}">${custIcon('group', 'font-size:13px')} ${ma_a}/${ma_t}</span>`;

    let dokBadge;
    if (dokTotal === 0) dokBadge = `<span class="nu-obj-badge none-active" title="Keine Dokumente">${custIcon('error', 'font-size:13px')} 0</span>`;
    else if (dokExp > 0) dokBadge = `<span class="nu-obj-badge none-active" title="${dokExp} abgelaufen">${custIcon('cancel', 'font-size:13px')} ${dokExp} / ${dokTotal}</span>`;
    else if (dokWarn > 0) dokBadge = `<span class="nu-obj-badge partial" title="${dokWarn} laufen ab">${custIcon('hourglass_top', 'font-size:13px')} ${dokWarn} / ${dokTotal}</span>`;
    else dokBadge = `<span class="nu-obj-badge all-active" title="Alle gültig">${custIcon('check_circle', 'font-size:13px')} ${dokTotal}</span>`;

    const portalBadge = s.portal_aktiv == 1
        ? `<span class="status status-ja" title="${escapeHtml(s.portal_username || '')}">${custIcon('public', 'font-size:13px')} Aktiv</span>`
        : '<span class="status status-nein">–</span>';

    const isAktiv = s.aktiv == 1;

    const sTags = (typeof bereichTagsOf === 'function') ? bereichTagsOf(s) : [s.bereich].filter(Boolean);
    return `<tr data-bereich="${escapeHtml(sTags.join('|'))}" data-aktiv="${s.aktiv}">
        <td><strong>${s.nu_id}</strong></td>
        <td>${escapeHtml(s.firmenname || '')}</td>
        <td>${s.ansprechpartner || '–'}</td>
        <td>${sTags.length ? sTags.map(t => bereichBadgeHtml(t)).join(' ') : '–'}</td>
        <td>${mitarbeiterBadge}</td>
        <td>${dokBadge}</td>
        <td>${portalBadge}</td>
        <td>${isAktiv ? '<span class="status status-ja">Ja</span>' : '<span class="status status-nein">Archiv</span>'}</td>
        <td>
            <div class="cust-row-actions">
                <md-icon-button onclick="openSubModal('${s.nu_id}')" title="Bearbeiten"><md-icon>edit</md-icon></md-icon-button>
                ${isAktiv
                    ? `<md-icon-button class="warn" onclick="deactivateSub('${s.nu_id}')" title="Deaktivieren"><md-icon>block</md-icon></md-icon-button>`
                    : `<md-icon-button class="ok" onclick="reactivateSub('${s.nu_id}')" title="Reaktivieren"><md-icon>undo</md-icon></md-icon-button>`}
                <md-icon-button class="danger" onclick="deleteSub('${s.nu_id}')" title="Endgültig löschen"><md-icon>delete</md-icon></md-icon-button>
            </div>
        </td>
    </tr>`;
}

function setNuFilter(mode) {
    _nuFilterMode = mode;
    // md-filter-chip: radio behaviour — exactly one chip stays selected.
    document.querySelectorAll('#nuFilterToggle md-filter-chip').forEach(ch => {
        ch.selected = ch.dataset.mode === mode;
    });
    filterNuTable();
}

function filterNuTable() {
    const q = (document.getElementById('searchSubs')?.value || '').toLowerCase();
    const rows = document.getElementById('subsTable')?.querySelectorAll('tr') || [];
    const vis = [];
    rows.forEach(r => {
        const matchText = !q || r.textContent.toLowerCase().includes(q);
        const aktiv = r.dataset.aktiv === '1';
        let matchAktiv = true;
        if (_nuFilterMode === 'aktiv') matchAktiv = aktiv;
        else if (_nuFilterMode === 'archiv') matchAktiv = !aktiv;
        r.style.display = 'none';
        if (matchText && matchAktiv) vis.push(r);
    });
    const _p = hmsPagerDef('nuPager', 'Nachunternehmer', () => filterNuTable());
    const pg = hmsPage(vis, _p.st, vis.length ? (vis[0].cells[0]?.textContent || '').trim().slice(0, 40) : '');
    pg.rows.forEach(r => { r.style.display = ''; });
    hmsPagerFill('nuPager', pg);
}

// Wire md-outlined-text-field search after render
function _wireNuSearch() {
    document.getElementById('searchSubs')?.addEventListener('input', filterNuTable);
}

// ============================================
// MODAL
// ============================================
async function openSubModal(id = null) {
    let sub = {};
    if (id) sub = await apiGet(API.subcontractors + '?id=' + id);

    const tabs = `
        <md-tabs class="cust-modal-tabs" id="nuModalTabs">
            ${id ? '<md-primary-tab data-panel="nu-uebersicht" active><md-icon slot="icon">donut_small</md-icon>Übersicht</md-primary-tab>' : ''}
            <md-primary-tab data-panel="nu-stammdaten" ${id ? '' : 'active'}><md-icon slot="icon">apartment</md-icon>Stammdaten</md-primary-tab>
            ${id ? '<md-primary-tab data-panel="nu-dokumente"><md-icon slot="icon">folder_open</md-icon>Dokumente</md-primary-tab>' : ''}
            ${id ? '<md-primary-tab data-panel="nu-portal"><md-icon slot="icon">public</md-icon>Portal</md-primary-tab>' : ''}
            ${id ? '<md-primary-tab data-panel="nu-mitarbeiter"><md-icon slot="icon">group</md-icon>Mitarbeiter</md-primary-tab>' : ''}
        </md-tabs>
    `;

    const uebersichtTab = id ? `
        <div class="customer-tab-content active" id="tab-nu-uebersicht">
            <div id="nuStatsContainer" class="customer-stats-container">
                <div class="customer-stats-loading"><md-circular-progress indeterminate style="--md-circular-progress-size:32px"></md-circular-progress> Statistiken werden geladen...</div>
            </div>
            <div class="nu-checklist-section">
                <div class="customer-form-section-title">${custIcon('assignment_turned_in')} Compliance-Checkliste</div>
                <div id="nuChecklistContainer">
                    <div class="customer-stats-loading"><md-circular-progress indeterminate style="--md-circular-progress-size:32px"></md-circular-progress> Checkliste wird geladen...</div>
                </div>
            </div>
        </div>
    ` : '';

    const stammdatenTab = `
        <div class="customer-tab-content ${id ? '' : 'active'}" id="tab-nu-stammdaten">
        <div class="customer-form-layout">
            <div class="customer-form-col">
                <div class="customer-form-section-title">${custIcon('location_on')} Anschrift</div>
                <div class="form-group">
                    <div class="cust-radio-group" role="radiogroup" aria-label="Anschrift-Typ">
                        <span class="cust-radio-caption">Anschrift-Typ</span>
                        <label class="cust-radio-label">
                            <md-radio name="f_anschrift_typ" value="Firma" ${sub.anschrift_typ !== 'Privat' ? 'checked' : ''}></md-radio>Firma
                        </label>
                        <label class="cust-radio-label">
                            <md-radio name="f_anschrift_typ" value="Privat" ${sub.anschrift_typ === 'Privat' ? 'checked' : ''}></md-radio>Privat
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <md-outlined-text-field id="f_firmenname" label="Firma / Name" value="${escapeHtml(sub.firmenname || '')}" required></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <md-outlined-text-field id="f_zusatz" label="Zusatz" value="${escapeHtml(sub.zusatz || '')}"></md-outlined-text-field>
                </div>
                <div class="customer-form-row">
                    <div class="form-group" style="flex:2"><md-outlined-text-field id="f_strasse" label="Straße" value="${escapeHtml(sub.strasse || '')}"></md-outlined-text-field></div>
                    <div class="form-group" style="flex:1">
                        <md-outlined-select id="f_land" label="Land">
                            <md-select-option value="D" ${(sub.land || 'D') === 'D' ? 'selected' : ''}><div slot="headline">D</div></md-select-option>
                            <md-select-option value="AT" ${sub.land === 'AT' ? 'selected' : ''}><div slot="headline">AT</div></md-select-option>
                            <md-select-option value="CH" ${sub.land === 'CH' ? 'selected' : ''}><div slot="headline">CH</div></md-select-option>
                        </md-outlined-select>
                    </div>
                </div>
                <div class="customer-form-row">
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_plz" label="PLZ" value="${escapeHtml(sub.plz || '')}" oninput="validateNuPlz(this)"></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:2"><md-outlined-text-field id="f_ort" label="Ort" value="${escapeHtml(sub.ort || '')}"></md-outlined-text-field></div>
                </div>
                <div class="customer-form-section-title" style="margin-top:18px">${custIcon('sticky_note_2')} Anmerkungen</div>
                <div class="form-group"><md-outlined-text-field type="textarea" rows="4" id="f_notiz" label="Notiz" value="${escapeHtml(sub.notiz || '')}"></md-outlined-text-field></div>
            </div>

            <div class="customer-form-col">
                <div class="customer-form-section-title">${custIcon('call')} Kommunikation</div>
                <div class="form-group"><md-outlined-text-field id="f_ansprechpartner" label="Ansprechpartner" value="${escapeHtml(sub.ansprechpartner || '')}"></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_telefon1" label="Telefon 1" value="${escapeHtml(sub.telefon1 || '')}"><md-icon slot="trailing-icon">call</md-icon></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_telefon2" label="Telefon 2" value="${escapeHtml(sub.telefon2 || '')}"><md-icon slot="trailing-icon">call</md-icon></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_fax" label="Fax" value="${escapeHtml(sub.fax || '')}"><md-icon slot="trailing-icon">fax</md-icon></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_mobil" label="Mobil" value="${escapeHtml(sub.mobil || '')}"><md-icon slot="trailing-icon">smartphone</md-icon></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field type="email" id="f_email" label="E-Mail" value="${escapeHtml(sub.email || '')}" oninput="validateNuEmail(this)"><md-icon slot="trailing-icon">alternate_email</md-icon></md-outlined-text-field></div>
                <div class="form-group"><md-outlined-text-field id="f_website" label="Website" value="${escapeHtml(sub.website || '')}"></md-outlined-text-field></div>
                <div class="customer-form-section-title" style="margin-top:18px">${custIcon('settings')} Einstellungen</div>
                <div class="form-group">
                    <label style="display:block;font-size:0.8rem;color:var(--gray-600);margin-bottom:4px">Dienstleistungen (Mehrfachauswahl)</label>
                    <div id="f_nu_bereiche" style="padding:4px 0"></div>
                </div>
                <div class="form-group">
                    <md-outlined-select id="f_sub_aktiv" label="Aktiv">
                        <md-select-option value="1" ${sub.aktiv == 1 || !id ? 'selected' : ''}><div slot="headline">Ja</div></md-select-option>
                        <md-select-option value="0" ${sub.aktiv == 0 ? 'selected' : ''}><div slot="headline">Nein (Archiv)</div></md-select-option>
                    </md-outlined-select>
                </div>
            </div>
        </div>
        </div>
    `;

    const tenantSlug = (JSON.parse(localStorage.getItem('hms_tenant') || '{}').slug || '');

    const portalTab = id ? `
        <div class="customer-tab-content" id="tab-nu-portal">
            <div class="customer-form-layout">
                <div class="customer-form-col">
                    <div class="customer-form-section-title">${custIcon('vpn_key')} Portal-Zugangsdaten</div>
                    <div class="nu-info-box">
                        ${custIcon('info')}
                        Login-URL: <strong><a href="/nu_login.php?tenant=${tenantSlug}" target="_blank">/nu_login.php?tenant=${tenantSlug}</a></strong>
                    </div>
                    <div class="form-group">
                        <md-outlined-select id="f_portal_aktiv" label="Portal aktiv">
                            <md-select-option value="0" ${!sub.portal_aktiv || sub.portal_aktiv == 0 ? 'selected' : ''}><div slot="headline">Nein</div></md-select-option>
                            <md-select-option value="1" ${sub.portal_aktiv == 1 ? 'selected' : ''}><div slot="headline">Ja</div></md-select-option>
                        </md-outlined-select>
                    </div>
                    <div class="form-group">
                        <md-outlined-text-field id="f_portal_username" label="Benutzername" value="${escapeHtml(sub.portal_username || '')}" placeholder="z.B. mueller-service"></md-outlined-text-field>
                        <small style="color:var(--gray-500);font-size:0.75rem">Nur Buchstaben, Ziffern, . _ - (3–50 Zeichen)</small>
                    </div>
                    <div class="form-group">
                        <md-outlined-text-field type="password" id="f_portal_password_new" label="Neues Passwort ${sub.portal_password ? '(leer lassen = unverändert)' : '*'}" placeholder="Mind. 6 Zeichen" autocomplete="new-password"><md-icon slot="trailing-icon" style="cursor:pointer;pointer-events:auto" onpointerdown="showNuPw()" onpointerup="hideNuPw()" onpointerleave="hideNuPw()" ontouchstart="showNuPw()" ontouchend="hideNuPw()" oncontextmenu="return false" id="nuPwEyeIcon" tabindex="0">visibility</md-icon></md-outlined-text-field>
                    </div>
                    ${sub.portal_aktiv == 1 && sub.portal_username ? `<div class="nu-success-box">${custIcon('check_circle')} Portal aktiv. Login: <strong>${escapeHtml(sub.portal_username)}</strong></div>` : ''}
                </div>
                <div class="customer-form-col">
                    <div class="customer-form-section-title">${custIcon('shield')} Hinweise</div>
                    <ul class="nu-hints-list">
                        <li>Der NU kann seine eigenen <strong>Mitarbeiter</strong> im Portal anlegen</li>
                        <li>Im Dispo definieren Sie <strong>Positionen</strong> mit Anforderungen</li>
                        <li>Der NU weist seine Mitarbeiter den Positionen zu</li>
                        <li>Passwort: mind. <strong>6 Zeichen</strong></li>
                        <li>Passwörter werden verschlüsselt gespeichert</li>
                    </ul>
                </div>
            </div>
        </div>
    ` : '';

    const dokumenteTab = id ? `
        <div class="customer-tab-content" id="tab-nu-dokumente">
            <div class="customer-docs-header">
                <h4>${custIcon('upload')} Dokument hochladen</h4>
            </div>
            <div class="customer-doc-upload-form">
                <div class="customer-form-row" style="flex-wrap:wrap;gap:10px">
                    <div class="form-group" style="flex:2;min-width:180px">
                        <md-outlined-select id="f_nu_dok_typ" label="Dokumententyp *">
                            <md-select-option value="" selected><div slot="headline">— Dokument wählen —</div></md-select-option>
                            ${NU_DOK_TYPEN.map(d => `<md-select-option value="${d}"><div slot="headline">${d}</div></md-select-option>`).join('')}
                        </md-outlined-select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:170px">
                        <md-outlined-select id="f_nu_dok_faellig" label="Fälligkeit *">
                            <md-select-option value="" selected><div slot="headline">— Typ wählen —</div></md-select-option>
                            <md-select-option value="vertragsbeginn"><div slot="headline">Vertragsbeginn</div></md-select-option>
                            <md-select-option value="jaehrlich"><div slot="headline">Jährlich zum 01.01.</div></md-select-option>
                            <md-select-option value="auf_anforderung"><div slot="headline">Auf Anforderung</div></md-select-option>
                        </md-outlined-select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:170px">
                        <md-outlined-text-field type="date" id="f_nu_dok_ab" label="Gültig ab"></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:1;min-width:170px">
                        <md-outlined-text-field type="date" id="f_nu_dok_bis" label="Gültig bis"></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:2;min-width:180px">
                        <label class="cust-file-label">PDF-Datei *</label>
                        <input type="file" class="form-control" id="f_nu_dok_file" accept=".pdf">
                    </div>
                    <div class="form-group" style="flex:0 0 auto;align-self:flex-end">
                        <md-filled-tonal-button onclick="uploadNuDoc('${id}')"><md-icon slot="icon">cloud_upload</md-icon>Hochladen</md-filled-tonal-button>
                    </div>
                </div>
            </div>
            <div class="customer-docs-grid" id="nuDocsGrid">
                <div class="text-center text-muted"><md-circular-progress indeterminate style="--md-circular-progress-size:32px"></md-circular-progress> Laden...</div>
            </div>
        </div>
    ` : '';

    const mitarbeiterTab = id ? `
        <div class="customer-tab-content" id="tab-nu-mitarbeiter">
            <div class="nu-admin-workers-header">
                <div>
                    <h4>${custIcon('group')} Mitarbeiter von ${escapeHtml(sub.firmenname || '')}</h4>
                    <p class="nu-admin-workers-sub">Diese Mitarbeiter werden vom NU im Portal selbst gepflegt.</p>
                </div>
                <div class="nu-admin-workers-stats" id="nuAdminWorkersStats"></div>
            </div>
            <div class="nu-admin-workers-toolbar">
                <md-outlined-text-field id="nuAdminWorkersSearch" label="Mitarbeiter suchen" style="flex:1;min-width:180px" oninput="filterNuAdminWorkers()"><md-icon slot="leading-icon">search</md-icon></md-outlined-text-field>
                <md-outlined-select id="nuAdminWorkersFilter" style="max-width:200px" onchange="filterNuAdminWorkers()">
                    <md-select-option value="all" selected><div slot="headline">Alle anzeigen</div></md-select-option>
                    <md-select-option value="active"><div slot="headline">Nur aktive</div></md-select-option>
                    <md-select-option value="inactive"><div slot="headline">Nur inaktive</div></md-select-option>
                    <md-select-option value="expired"><div slot="headline">Ausweis abgelaufen</div></md-select-option>
                    <md-select-option value="expiring"><div slot="headline">Ausweis läuft bald ab</div></md-select-option>
                </md-outlined-select>
            </div>
            <div id="nuAdminWorkersGrid" class="nu-admin-workers-grid">
                <div class="text-center text-muted" style="padding:40px"><md-circular-progress indeterminate style="--md-circular-progress-size:32px"></md-circular-progress> Lade Mitarbeiter...</div>
            </div>
            <div id="nuwPager" class="be-pager"></div>
        </div>
    ` : '';

    const body = tabs + uebersichtTab + stammdatenTab + dokumenteTab + portalTab + mitarbeiterTab;

    const footer = `
        <md-text-button onclick="closeSubDialog()">Abbrechen</md-text-button>
        <md-filled-button onclick="saveSub('${id || ''}')">${id ? 'Aktualisieren' : 'Erstellen'}</md-filled-button>
    `;
    openSubDialog(id ? 'Nachunternehmer bearbeiten — ' + (sub.nu_id || '') : 'Neuer Nachunternehmer', body, footer, true);
    if (typeof renderBereicheChecks === 'function') {
        renderBereicheChecks('f_nu_bereiche', sub.bereiche || (sub.bereich ? [sub.bereich] : []), sub.bereich || null);
    }

    window._currentNuId = id || null;
    window._nuAdminWorkersLoaded = false;
    window._nuAdminWorkersCache = [];

    // md-tabs drives the panels: sync on user tab change.
    const tabsEl = document.getElementById('nuModalTabs');
    tabsEl?.addEventListener('change', () => {
        const t = tabsEl.activeTab;
        if (t) switchNuTab(t.dataset.panel, false);
    });

    if (id) {
        loadNuStats(id);
        loadNuChecklist(id);
        loadNuDocs(id);
    }
}

function switchNuTab(tab, fromTabs = true) {
    document.querySelectorAll('.customer-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    document.querySelectorAll('.customer-tab-content').forEach(c => c.classList.toggle('active', c.id === 'tab-' + tab));
    if (tab === 'nu-mitarbeiter' && window._currentNuId && !window._nuAdminWorkersLoaded) {
        loadNuWorkers(window._currentNuId);
    }
}

function showNuPw() {
    const inp = document.getElementById('f_portal_password_new');
    if (inp) { inp.type = 'text'; }
}

function hideNuPw() {
    const inp = document.getElementById('f_portal_password_new');
    if (inp) { inp.type = 'password'; }
}

// ============================================
// STATS PANEL
// ============================================
async function loadNuStats(id) {
    const c = document.getElementById('nuStatsContainer');
    if (!c) return;
    try {
        const res = await fetch(API.subcontractors + '/' + encodeURIComponent(id) + '/stats', {
            headers: getAuthHeadersNoContent()
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const s = await res.json();

        const lastDate = s.last_einsatz_date ? new Date(s.last_einsatz_date).toLocaleDateString('de-DE') : '–';
        const ausweisColor = s.ausweis_abgelaufen > 0 ? '#dc2626' : (s.ausweis_ablaufend > 0 ? '#d97706' : '#059669');

        c.innerHTML = `
            <div class="customer-stats-grid">
                <div class="customer-stat">
                    <div class="customer-stat-icon" style="background: var(--md-sys-color-primary-container);color: var(--md-sys-color-primary)">${custIcon('group')}</div>
                    <div class="customer-stat-info">
                        <div class="customer-stat-value">${s.mitarbeiter_aktiv} <small>/ ${s.mitarbeiter_total}</small></div>
                        <div class="customer-stat-label">Mitarbeiter (aktiv / gesamt)</div>
                    </div>
                </div>
                <div class="customer-stat">
                    <div class="customer-stat-icon" style="background: var(--md-ext-color-warning-container);color:${ausweisColor}">${custIcon('badge')}</div>
                    <div class="customer-stat-info">
                        <div class="customer-stat-value" style="color:${ausweisColor}">${s.ausweis_abgelaufen} / ${s.ausweis_ablaufend}</div>
                        <div class="customer-stat-label">Ausweis abgelaufen / läuft ab</div>
                    </div>
                </div>
                <div class="customer-stat">
                    <div class="customer-stat-icon" style="background: var(--md-sys-color-primary-container);color: var(--md-sys-color-primary)">${custIcon('folder')}</div>
                    <div class="customer-stat-info">
                        <div class="customer-stat-value">${s.dokumente_total}</div>
                        <div class="customer-stat-label">Dokumente gesamt</div>
                    </div>
                </div>
                <div class="customer-stat">
                    <div class="customer-stat-icon" style="background: var(--md-ext-color-success-container);color: var(--md-ext-color-on-success-container)">${custIcon('assignment')}</div>
                    <div class="customer-stat-info">
                        <div class="customer-stat-value">${s.einsaetze_offen} <small>/ ${s.einsaetze_total}</small></div>
                        <div class="customer-stat-label">Einsätze (offen / gesamt)</div>
                    </div>
                </div>
                <div class="customer-stat">
                    <div class="customer-stat-icon" style="background: var(--md-sys-color-error-container);color: var(--md-sys-color-error)">${custIcon('calendar_today')}</div>
                    <div class="customer-stat-info">
                        <div class="customer-stat-value" style="font-size:1rem">${lastDate}</div>
                        <div class="customer-stat-label">Letzter Einsatz</div>
                    </div>
                </div>
            </div>
        `;
    } catch (e) {
        c.innerHTML = '<div class="customer-stats-loading text-danger">Statistiken konnten nicht geladen werden.</div>';
    }
}

async function loadNuChecklist(id) {
    const c = document.getElementById('nuChecklistContainer');
    if (!c) return;
    try {
        const res = await fetch(API.subcontractors + '/' + encodeURIComponent(id) + '/checklist', {
            headers: getAuthHeadersNoContent()
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();

        const sm = data.summary;
        const summaryHtml = `
            <div class="nu-checklist-summary">
                <div class="nu-cs-pill nu-cs-ok">${custIcon('check_circle', 'font-size:14px')} ${sm.ok} OK</div>
                ${sm.expiring > 0 ? `<div class="nu-cs-pill nu-cs-warn">${custIcon('hourglass_top', 'font-size:14px')} ${sm.expiring} läuft ab</div>` : ''}
                ${sm.expired > 0 ? `<div class="nu-cs-pill nu-cs-expired">${custIcon('cancel', 'font-size:14px')} ${sm.expired} abgelaufen</div>` : ''}
                ${sm.missing > 0 ? `<div class="nu-cs-pill nu-cs-missing">${custIcon('error', 'font-size:14px')} ${sm.missing} fehlt</div>` : ''}
                <div class="nu-cs-pill" style="margin-left:auto;background:var(--gray-100);color:var(--gray-700)">${custIcon('list', 'font-size:14px')} ${sm.ok} / ${sm.total_required} erfüllt</div>
            </div>
        `;

        const itemsHtml = data.checklist.map(item => {
            const icon = {
                ok: custIcon('check_circle', 'color:#059669'),
                expiring: custIcon('hourglass_top', 'color:#d97706'),
                expired: custIcon('cancel', 'color:#dc2626'),
                missing: custIcon('radio_button_unchecked', 'color:var(--gray-400)')
            }[item.status];

            const statusLbl = {
                ok: 'Gültig',
                expiring: 'Läuft bald ab',
                expired: 'Abgelaufen',
                missing: 'Fehlt'
            }[item.status];

            const validUntil = item.doc?.gueltig_bis
                ? new Date(item.doc.gueltig_bis).toLocaleDateString('de-DE')
                : null;

            return `
                <div class="nu-checklist-item nu-cs-${item.status}">
                    <div class="nu-cs-icon">${icon}</div>
                    <div class="nu-cs-body">
                        <div class="nu-cs-title">${escapeHtml(item.dok_typ)}</div>
                        <div class="nu-cs-meta">
                            <span class="nu-cs-status">${statusLbl}</span>
                            ${validUntil ? `<span>· Gültig bis: <strong>${validUntil}</strong></span>` : ''}
                            ${item.doc && item.doc.count > 1 ? `<span>· ${item.doc.count} Versionen</span>` : ''}
                        </div>
                    </div>
                    <div class="nu-cs-actions">
                        ${item.doc
                            ? `<md-icon-button onclick="hmsOpenFile('${hmsFileArg(item.doc.dateipfad)}')" title="Anzeigen"><md-icon>visibility</md-icon></md-icon-button>`
                            : `<md-text-button onclick="switchNuTab('nu-dokumente'); setTimeout(() => { const sel = document.getElementById('f_nu_dok_typ'); if (sel) sel.value = '${escapeHtml(item.dok_typ).replace(/'/g, "\\'")}'; }, 100);"><md-icon slot="icon">upload</md-icon>Hochladen</md-text-button>`}
                    </div>
                </div>
            `;
        }).join('');

        c.innerHTML = summaryHtml + '<div class="nu-checklist-list">' + itemsHtml + '</div>';
    } catch (e) {
        c.innerHTML = '<div class="customer-stats-loading text-danger">Checkliste konnte nicht geladen werden.</div>';
    }
}

// ============================================
// VALIDATORS
// ============================================
// MD3 text fields carry their own error state (error + error-text).
const NU_FIELD_IDS = {
    firmenname: 'f_firmenname',
    email: 'f_email',
    plz: 'f_plz',
    website: 'f_website',
    land: 'f_land',
    portal_username: 'f_portal_username',
    portal_password: 'f_portal_password_new'
};

function _nuMarkError(input, msg) {
    if (!input) return;
    input.error = !!msg;
    input.errorText = msg || '';
}

function validateNuEmail(input) {
    const v = String(input.value ?? '').trim();
    const ok = !v || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    _nuMarkError(input, ok ? '' : 'Ungültige E-Mail-Adresse');
}

function validateNuPlz(input) {
    const v = String(input.value ?? '').trim();
    const ok = !v || /^\d{4,5}$/.test(v);
    _nuMarkError(input, ok ? '' : '4–5 Ziffern erwartet');
}

function applyNuServerErrors(errors) {
    if (!errors || typeof errors !== 'object') return;
    Object.entries(errors).forEach(([field, msgs]) => {
        const text = Array.isArray(msgs) ? msgs.join(' · ') : String(msgs);
        _nuMarkError(document.getElementById(NU_FIELD_IDS[field] || ''), text);
    });
}

// ============================================
// SAVE / DELETE
// ============================================
async function saveSub(id) {
    // Clear all field errors
    Object.values(NU_FIELD_IDS).forEach(fid => _nuMarkError(document.getElementById(fid), ''));

    // md-radio: `:checked` doesn't match web components — use the property.
    const anschriftRadio = [...document.querySelectorAll('md-radio[name="f_anschrift_typ"]')].find(r => r.checked);

    const data = {
        firmenname: String(document.getElementById('f_firmenname').value ?? '').trim(),
        ansprechpartner: document.getElementById('f_ansprechpartner').value || null,
        telefon_email: null,
        bereich: (typeof mselPrimary === 'function') ? mselPrimary('f_nu_bereiche') : null,
        bereiche: (typeof collectBereicheChecks === 'function') ? collectBereicheChecks('f_nu_bereiche') : [],
        anschrift_typ: anschriftRadio ? anschriftRadio.value : 'Firma',
        zusatz: document.getElementById('f_zusatz').value || null,
        strasse: document.getElementById('f_strasse').value || null,
        plz: document.getElementById('f_plz').value || null,
        ort: document.getElementById('f_ort').value || null,
        land: document.getElementById('f_land').value || 'D',
        telefon1: document.getElementById('f_telefon1').value || null,
        telefon2: document.getElementById('f_telefon2').value || null,
        fax: document.getElementById('f_fax').value || null,
        mobil: document.getElementById('f_mobil').value || null,
        email: document.getElementById('f_email').value || null,
        website: document.getElementById('f_website').value || null,
        notiz: document.getElementById('f_notiz').value || null,
        aktiv: document.getElementById('f_sub_aktiv').value
    };

    // Portal fields — only present when editing an existing NU (Portal tab exists).
    // The select (Ja/Nein) is the single source of truth: Ja → activate, Nein → deactivate.
    // No auto-activation — the user's explicit choice is saved on "Aktualisieren".
    const portalAktivEl = document.getElementById('f_portal_aktiv');
    if (id && portalAktivEl) {
        data.portal_aktiv = portalAktivEl.value;
        data.portal_username = document.getElementById('f_portal_username').value || null;
        data.portal_password_new = document.getElementById('f_portal_password_new').value || null;
    }

    if (!data.firmenname) {
        _nuMarkError(document.getElementById('f_firmenname'), 'Firmenname ist erforderlich');
        switchNuTab('nu-stammdaten');
        showToast('Firmenname ist erforderlich', 'error');
        return;
    }

    let result;
    try {
        const url = id ? API.subcontractors + '/' + id : API.subcontractors;
        const res = await fetch(url, {
            method: id ? 'PUT' : 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify(data)
        });
        result = await res.json();

        if (res.status === 422 && result.errors) {
            applyNuServerErrors(result.errors);
            // Switch to the tab that has the first error
            const errorFields = Object.keys(result.errors);
            if (errorFields.some(f => f.startsWith('portal_'))) {
                switchNuTab('nu-portal');
            } else {
                switchNuTab('nu-stammdaten');
            }
            showToast('Bitte Eingabefehler korrigieren', 'error');
            return;
        }
        if (!res.ok) {
            showToast(result.error || 'Fehler beim Speichern', 'error');
            return;
        }
    } catch (e) {
        showToast('Netzwerkfehler: ' + e.message, 'error');
        return;
    }

    if (result.success) {
        closeSubDialog();
        if (typeof invalidateBereichCache === 'function') invalidateBereichCache();
        showToast(id ? 'Nachunternehmer aktualisiert' : 'Nachunternehmer erstellt');
        renderSubcontractors();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function deactivateSub(id) {
    if (!await mdConfirm('Nachunternehmer „' + id + '“ deaktivieren? Er wird ins Archiv verschoben.', { title: 'NU deaktivieren', confirmLabel: 'Deaktivieren' })) return;
    const result = await apiDelete(API.subcontractors + '/' + id);
    if (result.success) { showToast('Deaktiviert'); renderSubcontractors(); }
    else showToast(result.error || 'Fehler', 'error');
}

async function reactivateSub(id) {
    const c = await apiGet(API.subcontractors + '?id=' + id);
    if (!c || !c.nu_id) { showToast('NU nicht gefunden', 'error'); return; }
    c.aktiv = 1;
    const result = await apiPut(API.subcontractors + '/' + id, c);
    if (result.success) { showToast('Reaktiviert'); renderSubcontractors(); }
    else showToast(result.error || 'Fehler', 'error');
}

async function deleteSub(id) {
    let preview;
    try {
        const res = await fetch(API.subcontractors + '/' + encodeURIComponent(id) + '/delete-preview', {
            headers: getAuthHeadersNoContent()
        });
        preview = await res.json();
        if (!res.ok) throw new Error(preview.error || 'Fehler');
    } catch (e) {
        if (!await mdConfirm('NU „' + id + '“ endgültig löschen?\n\nAlle zugehörigen Daten werden unwiderruflich entfernt!', { title: 'Endgültig löschen', confirmLabel: 'Löschen' })) return;
        const r = await apiDelete(API.subcontractors + '/' + id, { permanent: true });
        if (r.success) { showToast('Gelöscht'); renderSubcontractors(); }
        else showToast(r.error || 'Fehler', 'error');
        return;
    }

    const body = `
        <div class="delete-preview">
            <div class="delete-preview-header">
                ${custIcon('warning')}
                <div>
                    <div class="delete-preview-title">Nachunternehmer endgültig löschen?</div>
                    <div class="delete-preview-sub">Folgende Daten werden <strong>unwiderruflich</strong> gelöscht:</div>
                </div>
            </div>
            <ul class="delete-preview-list">
                <li><strong>${preview.mitarbeiter}</strong> Mitarbeiter</li>
                <li><strong>${preview.dokumente}</strong> Dokumente</li>
                <li><strong>${preview.positionen}</strong> Positionen</li>
                <li><strong>${preview.einsaetze_refs}</strong> Einsatz-Verknüpfungen</li>
            </ul>
            <div class="nu-info-box" style="margin-bottom:12px">${custIcon('info')} ${escapeHtml(preview.note || '')}</div>
            <div class="delete-preview-confirm">
                <md-outlined-text-field id="confirmDeleteInput" label="Zur Bestätigung ${id} eintippen" autocomplete="off" style="width:100%" oninput="document.getElementById('confirmDeleteBtn').disabled = this.value !== '${id}'"></md-outlined-text-field>
            </div>
        </div>
    `;
    const footer = `
        <md-text-button onclick="closeSubDialog()">Abbrechen</md-text-button>
        <md-filled-button class="cust-danger-btn" id="confirmDeleteBtn" disabled onclick="confirmDeleteSub('${id}')"><md-icon slot="icon">delete_forever</md-icon>Endgültig löschen</md-filled-button>
    `;
    openSubDialog('NU löschen — ' + id, body, footer);
}

async function confirmDeleteSub(id) {
    const result = await apiDelete(API.subcontractors + '/' + id, { permanent: true });
    if (result.success) {
        closeSubDialog();
        showToast('Endgültig gelöscht');
        renderSubcontractors();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

// ============================================
// NU DOCUMENTS
// ============================================
async function loadNuDocs(nuId) {
    const grid = document.getElementById('nuDocsGrid');
    if (!grid) return;

    const docs = await apiGet(API.nu_documents + '?nu_id=' + nuId);
    const items = Array.isArray(docs) ? docs : [];

    if (items.length === 0) {
        grid.innerHTML = `<div class="customer-docs-empty">${custIcon('folder_open', 'font-size:2.5rem')}<p>Keine Dokumente vorhanden</p></div>`;
        return;
    }

    const today = new Date(); today.setHours(0,0,0,0);
    const warn60 = new Date(today); warn60.setDate(warn60.getDate() + 60);

    grid.innerHTML = items.map(d => {
        const fileSize = d.dateigroesse > 1024 * 1024
            ? (d.dateigroesse / (1024 * 1024)).toFixed(1) + ' MB'
            : Math.round(d.dateigroesse / 1024) + ' KB';
        const uploadDate = d.hochgeladen_am ? new Date(d.hochgeladen_am).toLocaleDateString('de-DE') : '';
        const validBis = d.gueltig_bis ? new Date(d.gueltig_bis) : null;

        let statusBadge = '';
        if (validBis) {
            const validBisStr = validBis.toLocaleDateString('de-DE');
            if (validBis < today) {
                statusBadge = `<div class="doc-meta nu-doc-expired">${custIcon('cancel', 'font-size:14px')} Abgelaufen am ${validBisStr}</div>`;
            } else if (validBis <= warn60) {
                statusBadge = `<div class="doc-meta nu-doc-expiring">${custIcon('hourglass_top', 'font-size:14px')} Läuft am ${validBisStr} ab</div>`;
            } else {
                statusBadge = `<div class="doc-meta nu-doc-ok">${custIcon('check_circle', 'font-size:14px')} Gültig bis ${validBisStr}</div>`;
            }
        }

        const thumbUrl = `${API_BASE}/nu-documents/${d.id}/thumb`;
        return `
            <div class="customer-doc-card ${validBis && validBis < today ? 'nu-doc-card-expired' : ''}">
                <div class="doc-thumb doc-thumb-pdf" data-thumb-url="${escapeHtml(thumbUrl)}" onclick="hmsOpenFile('${hmsFileArg(d.dateipfad)}')">
                    ${custIcon('picture_as_pdf', 'font-size:2rem;color:#dc2626')}
                </div>
                <div class="doc-info">
                    <div class="doc-title" title="${escapeHtml(d.titel)}">${escapeHtml(d.titel)}</div>
                    <div class="doc-meta">${escapeHtml(d.dok_typ || d.dateiname)}</div>
                    <div class="doc-meta">${fileSize} · ${uploadDate}</div>
                    ${d.faellig_typ ? `<div class="doc-meta"><span class="status status-offen" style="font-size:0.65rem">${FAELLIG_LABELS[d.faellig_typ] || d.faellig_typ}</span></div>` : ''}
                    ${statusBadge}
                </div>
                <div class="doc-actions">
                    <md-outlined-button class="md-btn-sm" onclick="hmsOpenFile('${hmsFileArg(d.dateipfad)}')" title="Anzeigen"><md-icon slot="icon">visibility</md-icon></md-outlined-button>
                    <md-outlined-button class="md-btn-sm" onclick="hmsOpenFile('${hmsFileArg(d.dateipfad)}', true)" title="Herunterladen"><md-icon slot="icon">download</md-icon></md-outlined-button>
                    <md-icon-button class="danger" onclick="deleteNuDoc(${d.id},'${d.nu_id}')" title="Löschen"><md-icon>delete</md-icon></md-icon-button>
                </div>
            </div>
        `;
    }).join('');

    if (typeof hydrateSecureThumbs === 'function') hydrateSecureThumbs(grid);
}

async function uploadNuDoc(nuId) {
    const dokTyp = document.getElementById('f_nu_dok_typ').value;
    const faellig = document.getElementById('f_nu_dok_faellig').value;
    const ab = document.getElementById('f_nu_dok_ab')?.value;
    const bis = document.getElementById('f_nu_dok_bis')?.value;
    const fileInput = document.getElementById('f_nu_dok_file');

    if (!dokTyp) { showToast('Bitte Dokumententyp wählen', 'error'); return; }
    if (!faellig) { showToast('Bitte Fälligkeit wählen', 'error'); return; }
    if (!fileInput.files.length) { showToast('Bitte PDF-Datei auswählen', 'error'); return; }
    if (ab && bis && bis < ab) { showToast('"Gültig bis" darf nicht vor "Gültig ab" liegen', 'error'); return; }

    const formData = new FormData();
    formData.append('nu_id', nuId);
    formData.append('titel', dokTyp);
    formData.append('dok_typ', dokTyp);
    formData.append('faellig_typ', faellig);
    if (ab) formData.append('gueltig_ab', ab);
    if (bis) formData.append('gueltig_bis', bis);
    formData.append('dokument', fileInput.files[0]);

    try {
        const res = await fetch(API.nu_documents + '/upload', {
            method: 'POST',
            headers: getAuthHeadersNoContent(),
            body: formData
        });
        const result = await res.json();
        if (result.success) {
            showToast('Dokument hochgeladen');
            fileInput.value = '';
            document.getElementById('f_nu_dok_typ').value = '';
            document.getElementById('f_nu_dok_faellig').value = '';
            document.getElementById('f_nu_dok_ab').value = '';
            document.getElementById('f_nu_dok_bis').value = '';
            loadNuDocs(nuId);
            loadNuChecklist(nuId);
        } else {
            showToast(result.error || 'Upload fehlgeschlagen', 'error');
        }
    } catch (e) {
        showToast('Upload-Fehler: ' + e.message, 'error');
    }
}

async function deleteNuDoc(docId, nuId) {
    if (!await mdConfirm('Dokument löschen?', { title: 'Dokument löschen', confirmLabel: 'Löschen' })) return;
    try {
        const res = await fetch(API.nu_documents + '/' + docId, {
            method: 'DELETE',
            headers: getAuthHeaders()
        });
        const result = await res.json();
        if (result.success) {
            showToast('Dokument gelöscht');
            loadNuDocs(nuId);
            loadNuChecklist(nuId);
        } else {
            showToast(result.error || 'Fehler', 'error');
        }
    } catch (e) {
        showToast('Fehler: ' + e.message, 'error');
    }
}

// ============================================
// NU WORKERS (admin view)
// ============================================
async function loadNuWorkers(nuId) {
    const grid = document.getElementById('nuAdminWorkersGrid');
    if (!grid) return;
    const workers = await apiGet(API.subcontractors + '/workers/list?nu_id=' + encodeURIComponent(nuId));
    window._nuAdminWorkersCache = Array.isArray(workers) ? workers : [];
    window._nuAdminWorkersLoaded = true;
    renderNuWorkers();
}

function nuWorkerAusweisStatus(w) {
    if (!w.ausweis_gueltig_bis) return null;
    const today = new Date(); today.setHours(0,0,0,0);
    const bis = new Date(w.ausweis_gueltig_bis);
    const diffDays = Math.round((bis - today) / (1000*60*60*24));
    if (diffDays < 0) return { kind: 'expired', days: diffDays, label: 'Abgelaufen' };
    if (diffDays < 60) return { kind: 'expiring', days: diffDays, label: 'Läuft in ' + diffDays + ' T. ab' };
    return { kind: 'ok', days: diffDays, label: 'Gültig' };
}

function renderNuWorkers() {
    const grid = document.getElementById('nuAdminWorkersGrid');
    const statsEl = document.getElementById('nuAdminWorkersStats');
    if (!grid) return;

    let workers = window._nuAdminWorkersCache || [];

    const total = workers.length;
    const active = workers.filter(w => w.aktiv == 1).length;
    const expiring = workers.filter(w => {
        const s = nuWorkerAusweisStatus(w);
        return s && (s.kind === 'expiring' || s.kind === 'expired');
    }).length;
    if (statsEl) {
        statsEl.innerHTML = `
            <div class="nu-admin-stat"><span class="nu-admin-stat-num">${total}</span><span class="nu-admin-stat-lbl">Gesamt</span></div>
            <div class="nu-admin-stat"><span class="nu-admin-stat-num" style="color: var(--md-ext-color-success)">${active}</span><span class="nu-admin-stat-lbl">Aktiv</span></div>
            <div class="nu-admin-stat"><span class="nu-admin-stat-num" style="color:${expiring > 0 ? '#dc2626' : '#16a34a'}">${expiring}</span><span class="nu-admin-stat-lbl">Ausweis-Warnung</span></div>
        `;
    }

    const q = (document.getElementById('nuAdminWorkersSearch')?.value || '').toLowerCase().trim();
    const flt = document.getElementById('nuAdminWorkersFilter')?.value || 'all';

    workers = workers.filter(w => {
        if (q) {
            const hay = [w.vorname, w.name, w.personal_nr, w.bewacher_id, w.qualifikation, w.ort, w.mobil, w.telefon, w.email].filter(Boolean).join(' ').toLowerCase();
            if (!hay.includes(q)) return false;
        }
        if (flt === 'active' && w.aktiv != 1) return false;
        if (flt === 'inactive' && w.aktiv == 1) return false;
        if (flt === 'expired') { const s = nuWorkerAusweisStatus(w); if (!s || s.kind !== 'expired') return false; }
        if (flt === 'expiring') { const s = nuWorkerAusweisStatus(w); if (!s || s.kind !== 'expiring') return false; }
        return true;
    });

    if (workers.length === 0) {
        hmsPagerClear('nuwPager');
        grid.innerHTML = `
            <div class="nu-admin-workers-empty">
                ${custIcon('group_off', 'font-size:2.5rem;display:block;margin-bottom:10px')}
                <p>${window._nuAdminWorkersCache.length === 0 ? 'Der Nachunternehmer hat noch keine Mitarbeiter angelegt.' : 'Keine Treffer mit diesen Filtern.'}</p>
            </div>`;
        return;
    }

    const _p = hmsPagerDef('nuwPager', 'Mitarbeiter', () => renderNuWorkers());
    const pg = hmsPage(workers, _p.st, workers.length ? String(workers[0].id || '') : '');
    hmsPagerFill('nuwPager', pg);
    grid.innerHTML = pg.rows.map(w => {
        const name = ((w.vorname || '') + ' ' + (w.name || '')).trim() || '—';
        const genderIcon = w.geschlecht === 'm' ? '♂' : w.geschlecht === 'w' ? '♀' : w.geschlecht === 'd' ? '⚧' : '';
        const initials = ((w.vorname || '')[0] || '').toUpperCase() + ((w.name || '')[0] || '').toUpperCase() || '?';
        const photo = w.foto
            ? `<img src="${escapeHtml(w.foto)}" alt="" onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'nu-admin-wcard-initials',textContent:'${initials}'}))">`
            : `<div class="nu-admin-wcard-initials">${initials}</div>`;

        const ausw = nuWorkerAusweisStatus(w);
        const auswBadge = ausw
            ? `<span class="nu-admin-wcard-badge ${ausw.kind}">${custIcon(ausw.kind === 'ok' ? 'check_circle' : (ausw.kind === 'expiring' ? 'hourglass_top' : 'warning'), 'font-size:13px')} ${ausw.label}</span>`
            : '';
        const statusBadge = w.aktiv == 1
            ? `<span class="nu-admin-wcard-badge ok">${custIcon('how_to_reg', 'font-size:13px')} Aktiv</span>`
            : `<span class="nu-admin-wcard-badge expired">${custIcon('person_off', 'font-size:13px')} Inaktiv</span>`;

        return `
            <div class="nu-admin-wcard ${w.aktiv == 0 ? 'inactive' : ''}" onclick="showNuWorkerDetail(${w.id})">
                <div class="nu-admin-wcard-photo">${photo}</div>
                <div class="nu-admin-wcard-info">
                    <div class="nu-admin-wcard-name">${escapeHtml(name)} ${genderIcon ? `<span class="nu-admin-wcard-gender">${genderIcon}</span>` : ''}</div>
                    ${w.personal_nr ? `<div class="nu-admin-wcard-id">${escapeHtml(w.personal_nr)}</div>` : ''}
                    <div class="nu-admin-wcard-meta">
                        ${w.qualifikation ? `<span>${custIcon('verified', 'font-size:13px')} ${escapeHtml(w.qualifikation)}</span>` : ''}
                        ${w.ort ? `<span>${custIcon('location_on', 'font-size:13px')} ${escapeHtml(w.ort)}</span>` : ''}
                        ${w.mobil ? `<span>${custIcon('smartphone', 'font-size:13px')} ${escapeHtml(w.mobil)}</span>` : ''}
                    </div>
                    <div class="nu-admin-wcard-badges">
                        ${statusBadge}
                        ${auswBadge}
                    </div>
                </div>
                <div class="nu-admin-wcard-arrow">${custIcon('chevron_right', 'font-size:18px')}</div>
            </div>
        `;
    }).join('');
}

function filterNuAdminWorkers() { renderNuWorkers(); }

function showNuWorkerDetail(workerId) {
    const w = (window._nuAdminWorkersCache || []).find(x => x.id == workerId);
    if (!w) return;
    const name = ((w.vorname || '') + ' ' + (w.name || '')).trim() || '—';
    const initials = ((w.vorname || '')[0] || '').toUpperCase() + ((w.name || '')[0] || '').toUpperCase() || '?';
    const photo = w.foto
        ? `<img src="${escapeHtml(w.foto)}" alt="">`
        : `<div class="nu-admin-detail-initials">${initials}</div>`;

    const fmtDate = (d) => d ? new Date(d).toLocaleDateString('de-DE') : '–';
    const ausw = nuWorkerAusweisStatus(w);
    const row = (label, val) => `<tr><th>${label}</th><td>${val ? escapeHtml(String(val)) : '<span class="text-muted">–</span>'}</td></tr>`;
    // Industry-specific (security / stadium) rows only when filled.
    const optRow = (label, val) => val ? row(label, val) : '';

    const body = `
        <div class="nu-admin-detail-layout">
            <div class="nu-admin-detail-left">
                <div class="nu-admin-detail-photo">${photo}</div>
                <div class="nu-admin-detail-name">${escapeHtml(name)}</div>
                ${w.personal_nr ? `<div class="nu-admin-detail-id">${escapeHtml(w.personal_nr)}</div>` : ''}
                <div class="nu-admin-detail-badges">
                    ${w.aktiv == 1 ? `<span class="nu-admin-wcard-badge ok">${custIcon('how_to_reg', 'font-size:13px')} Aktiv</span>` : `<span class="nu-admin-wcard-badge expired">${custIcon('person_off', 'font-size:13px')} Inaktiv</span>`}
                    ${ausw ? `<span class="nu-admin-wcard-badge ${ausw.kind}">${custIcon('badge', 'font-size:13px')} ${ausw.label}</span>` : ''}
                </div>
            </div>
            <div class="nu-admin-detail-right">
                <div class="customer-form-section-title">${custIcon('person')} Persönliche Daten</div>
                <table class="nu-admin-detail-table">
                    ${row('Name', w.name)} ${row('Vorname', w.vorname)}
                    ${row('Geschlecht', w.geschlecht === 'm' ? 'Männlich' : w.geschlecht === 'w' ? 'Weiblich' : w.geschlecht === 'd' ? 'Divers' : null)}
                    ${row('Geburtsname', w.geburtsname)} ${row('Geburtstag', fmtDate(w.geburtstag))}
                    ${row('Geburtsort', w.geburtsort)} ${row('Geburtsland', w.geburtsland)}
                </table>
                <div class="customer-form-section-title" style="margin-top:18px">${custIcon('badge')} Identifikation</div>
                <table class="nu-admin-detail-table">
                    ${row('Personal-Nr.', w.personal_nr)} ${optRow('Ordnernummer', w.ordner_nr)}
                    ${optRow('Barcode', w.barcode)} ${optRow('Bewacherregister-ID', w.bewacher_id)}
                    ${optRow('DFB-Pin', w.dfb_pin)} ${optRow('Funkrufname', w.funkrufname)}
                    ${row('Sozialversicherungsnr.', w.sv_nr)}
                </table>
                <div class="customer-form-section-title" style="margin-top:18px">${custIcon('badge')} Personalausweis</div>
                <table class="nu-admin-detail-table">
                    ${row('Ausweis-Nummer', w.ausweis_nr)} ${row('Gültig ab', fmtDate(w.ausweis_gueltig_ab))} ${row('Gültig bis', fmtDate(w.ausweis_gueltig_bis))}
                </table>
                <div class="customer-form-section-title" style="margin-top:18px">${custIcon('location_on')} Anschrift</div>
                <table class="nu-admin-detail-table">
                    ${row('Straße', w.strasse)} ${row('PLZ / Ort', [w.plz, w.ort].filter(Boolean).join(' '))}
                    ${row('Bundesland', w.bundesland)} ${row('Land', w.land)}
                </table>
                <div class="customer-form-section-title" style="margin-top:18px">${custIcon('call')} Kontakt</div>
                <table class="nu-admin-detail-table">
                    ${row('Telefon', w.telefon)} ${row('Mobil', w.mobil)} ${row('E-Mail', w.email)}
                </table>
                <div class="customer-form-section-title" style="margin-top:18px">${custIcon('award')} Qualifikation</div>
                <table class="nu-admin-detail-table">
                    ${row('Qualifikation', w.qualifikation)} ${row('Skills', w.skills)} ${row('Bemerkung', w.bemerkung)}
                </table>
            </div>
        </div>
    `;
    const footer = `<md-text-button onclick="closeSubDialog()">Schließen</md-text-button>`;

    openSubDialog('Mitarbeiter: ' + name, body, footer, true);
}

function closeNuWorkerDetail() {
    closeSubDialog();
}
