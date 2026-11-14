/* ============================================
   HMS Gruppe — Kunden (Customers) Extended Module
   - Stats overview row
   - Archive filter toggle
   - Inline Objekte counts
   - Validation error mapping (422)
   - Delete preview with cascade impact
   ============================================ */

let _currentCustomerId = null;
let _customerFilterMode = 'aktiv'; // 'aktiv' | 'archiv' | 'alle'

// Material Symbols shorthand (module-local, mirrors users.js _ms())
function custIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}

// Live updates: customer mutations broadcast data.changed{area:'kunden'} on
// the tenant channel — re-render only while this page is actually visible.
if (window.HMSRealtime && typeof HMSRealtime.on === 'function') {
    HMSRealtime.on('kunden', () => {
        if (typeof currentPage !== 'undefined' && currentPage === 'customers') renderCustomers();
    });
}

// ---------- MD3 dialog (official <md-dialog>) ----------
// The customers module no longer uses the legacy openModal() shell.
function openCustomersDialog(title, bodyHtml, footerHtml = '', wide = false) {
    document.getElementById('customersMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'customersMdDialog';
    if (wide) dlg.classList.add('customers-dialog-xl');
    dlg.innerHTML = `
      <div slot="headline">${title}</div>
      <div slot="content" class="customers-dialog-content">${bodyHtml}</div>
      <div slot="actions">${footerHtml}</div>`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => { dlg.remove(); _currentCustomerId = null; });
    dlg.show();
    return dlg;
}
function closeCustomersDialog() { document.getElementById('customersMdDialog')?.close(); }

// ============================================
// RENDER CUSTOMERS PAGE
// ============================================
async function renderCustomers() {
    const content = document.getElementById('content');
    const data = await apiGet(API.customers);
    const customers = Array.isArray(data) ? data : [];
    cachedData.customers = customers;

    const total = customers.length;
    const aktiv = customers.filter(c => c.aktiv == 1).length;
    const archiv = total - aktiv;
    const objektTotal = customers.reduce((s, c) => s + (parseInt(c.objekte_total) || 0), 0);
    const aktivPct = total > 0 ? Math.round((aktiv / total) * 100) : 0;
    const objektAvg = total > 0 ? (objektTotal / total).toFixed(1) : '0';
    const bereiche = [...new Set(customers.flatMap(c =>
        (typeof bereichTagsOf === 'function') ? bereichTagsOf(c) : [c.bereich]
    ).filter(Boolean))].sort();
    const bentoHtml = `
            <div class="bento-tile bento-tile-hero bento-tile-total" onclick="setCustomerFilter('alle')" title="Alle Kunden anzeigen">
                <div class="bento-tile-head">${custIcon('group')} Kunden gesamt</div>
                <div class="bento-tile-number">${total}</div>
                <div class="bento-tile-foot">
                    <span class="bento-foot-pill">${custIcon('check_circle')} ${aktiv} Aktiv</span>
                    <span class="bento-foot-pill">${custIcon('archive')} ${archiv} Archiv</span>
                    <span class="bento-foot-pill">${custIcon('domain')} ${objektTotal} Objekte</span>
                </div>
            </div>
            <div class="bento-tile bento-tile-active" onclick="setCustomerFilter('aktiv')" title="Nur aktive Kunden">
                <div class="bento-tile-icon">${custIcon('how_to_reg')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${aktiv}</div>
                    <div class="bento-tile-label">Aktive Kunden</div>
                </div>
                <div class="bento-progress" title="${aktivPct}% aktiv">
                    <div class="bento-progress-bar" style="width:${aktivPct}%"></div>
                </div>
            </div>
            <div class="bento-tile bento-tile-archiv" onclick="setCustomerFilter('archiv')" title="Archivierte Kunden">
                <div class="bento-tile-icon">${custIcon('archive')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${archiv}</div>
                    <div class="bento-tile-label">Archiviert</div>
                </div>
            </div>
            <div class="bento-tile bento-tile-wide bento-tile-objekte" onclick="if(typeof loadPage==='function')loadPage('objects')" title="Zu Objekten wechseln">
                <div class="bento-tile-icon">${custIcon('domain')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${objektTotal}</div>
                    <div class="bento-tile-label">Objekte gesamt</div>
                </div>
                <div class="bento-tile-meta">
                    <span>⌀ ${objektAvg} pro Kunde</span>
                    <span>${aktivPct}% aktiv</span>
                </div>
            </div>`;
    const emptyHtml = customers.length === 0 ? `<div class="empty-state">${custIcon('group')}<p>Noch keine Kunden vorhanden</p></div>` : '';

    // Page already on screen (after a save, realtime change): patch tiles,
    // rows and Bereich options in place — search text, filter and scroll stay.
    const table = document.getElementById('customersTable');
    if (table && typeof hmsMorph === 'function') {
        hmsMorph(content.querySelector('.data-bento'), bentoHtml);
        hmsMorph(table, customers.map(c => customerRow(c)).join(''));
        hmsMorph(document.getElementById('custEmpty'), emptyHtml);
        const sel = document.getElementById('filterBereich');
        if (sel) {
            const cur = bereiche.includes(sel.value) ? sel.value : '';
            hmsMorph(sel, [''].concat(bereiche).map(b => `<md-select-option value="${escapeHtml(b)}" ${b === cur ? 'selected' : ''}><div slot="headline">${b ? escapeHtml(b) : 'Alle Bereiche'}</div></md-select-option>`).join(''));
            if (sel.value !== cur) sel.value = cur;
        }
        filterCustomers();
        return;
    }

    content.innerHTML = `
        <div class="data-bento">${bentoHtml}
        </div>

        <div class="data-toolbar">
            <div class="data-toolbar-left">
                <md-outlined-text-field class="cust-search" id="searchCustomers" label="Kunden suchen" placeholder="Name, Nr., Ort…">
                    <md-icon slot="leading-icon">search</md-icon>
                </md-outlined-text-field>
                <md-outlined-select class="cust-bereich-select" id="filterBereich" label="Bereich">
                    <md-select-option value="" selected><div slot="headline">Alle Bereiche</div></md-select-option>
                </md-outlined-select>
                <md-chip-set id="kundenFilterToggle">
                    <md-filter-chip label="Aktiv" data-mode="aktiv" ${_customerFilterMode === 'aktiv' ? 'selected' : ''} onclick="setCustomerFilter('aktiv')"></md-filter-chip>
                    <md-filter-chip label="Archiv" data-mode="archiv" ${_customerFilterMode === 'archiv' ? 'selected' : ''} onclick="setCustomerFilter('archiv')"></md-filter-chip>
                    <md-filter-chip label="Alle" data-mode="alle" ${_customerFilterMode === 'alle' ? 'selected' : ''} onclick="setCustomerFilter('alle')"></md-filter-chip>
                </md-chip-set>
            </div>
            <md-filled-button onclick="openCustomerModal()">
                <md-icon slot="icon">add</md-icon>Neuer Kunde
            </md-filled-button>
        </div>

        <div class="data-table-card">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>KU-ID</th>
                            <th>Kundenname</th>
                            <th>Kunden-Nr.</th>
                            <th>Bereich</th>
                            <th>Ort</th>
                            <th>Telefon</th>
                            <th>E-Mail</th>
                            <th>Objekte</th>
                            <th>Abrechnung</th>
                            <th>Status</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody id="customersTable">
                        ${customers.map(c => customerRow(c)).join('')}
                    </tbody>
                </table>
                <div id="custEmpty">${emptyHtml}</div>
                <div id="custPager" class="be-pager"></div>
            </div>
        </div>
    `;

    // Build bereich filter dropdown from ALL service tags of the customers
    const sel = document.getElementById('filterBereich');
    if (sel) bereiche.forEach(b => {
        const o = document.createElement('md-select-option');
        o.value = b;
        o.innerHTML = `<div slot="headline">${escapeHtml(b)}</div>`;
        sel.appendChild(o);
    });

    // MD3 web components re-dispatch input/change from their shadow DOM —
    // wire them here instead of inline attributes.
    document.getElementById('searchCustomers')?.addEventListener('input', filterCustomers);
    sel?.addEventListener('change', filterCustomers);

    // Apply current filter mode visually
    filterCustomers();
}

function customerRow(c) {
    const total = parseInt(c.objekte_total) || 0;
    const aktiv = parseInt(c.objekte_aktiv) || 0;
    const objBadge = total > 0
        ? `<span class="kunden-obj-badge ${aktiv === total ? 'all-active' : (aktiv === 0 ? 'none-active' : 'partial')}" title="${aktiv} aktiv von ${total} Objekten">${custIcon('domain', 'font-size:13px')} ${aktiv}/${total}</span>`
        : `<span class="kunden-obj-badge none">–</span>`;

    const isAktiv = c.aktiv == 1;
    const tags = (typeof bereichTagsOf === 'function') ? bereichTagsOf(c) : [c.bereich].filter(Boolean);
    return `<tr data-mk="${escapeHtml(c.kunde_id)}" data-bereich="${escapeHtml(tags.join('|'))}" data-aktiv="${c.aktiv}">
        <td><strong>${escapeHtml(c.kunde_id)}</strong></td>
        <td>${c.farbe && /^#[0-9a-fA-F]{6}$/.test(c.farbe) ? `<span class="cust-color-dot" style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle;background:${c.farbe};box-shadow: 0 0 0 1px color-mix(in srgb, var(--md-sys-color-scrim) 15%, transparent)" title="Kundenfarbe ${c.farbe}"></span>` : ''}${escapeHtml(c.kundenname)}</td>
        <td>${escapeHtml(c.kunden_nr_kunde || '–')}</td>
        <td>${bereicheBadgesHtml(c.bereich, c.bereiche)}</td>
        <td>${c.ort ? escapeHtml(c.ort) : '–'}</td>
        <td>${escapeHtml(c.telefon1 || '–')}</td>
        <td>${escapeHtml(c.email || '–')}</td>
        <td>${objBadge}</td>
        <td>${escapeHtml(c.abrechnungstyp || '–')}</td>
        <td>${isAktiv ? '<span class="status status-ja">Ja</span>' : '<span class="status status-nein">Archiv</span>'}</td>
        <td>
            <div class="cust-row-actions">
                <md-icon-button onclick="openCustomerModal('${c.kunde_id}')" title="Bearbeiten"><md-icon>edit</md-icon></md-icon-button>
                ${isAktiv
                    ? `<md-icon-button class="warn" onclick="deactivateCustomer('${c.kunde_id}')" title="Deaktivieren"><md-icon>block</md-icon></md-icon-button>`
                    : `<md-icon-button class="ok" onclick="reactivateCustomer('${c.kunde_id}')" title="Reaktivieren"><md-icon>undo</md-icon></md-icon-button>`}
                <md-icon-button class="danger" onclick="deleteCustomer('${c.kunde_id}')" title="Endgültig löschen"><md-icon>delete</md-icon></md-icon-button>
            </div>
        </td>
    </tr>`;
}

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function setCustomerFilter(mode) {
    _customerFilterMode = mode;
    // md-filter-chip: radio behaviour — exactly one chip stays selected.
    // (`:checked`/class toggling doesn't work on MD web components, use .selected)
    document.querySelectorAll('#kundenFilterToggle md-filter-chip').forEach(ch => {
        ch.selected = ch.dataset.mode === mode;
    });
    filterCustomers();
}

function filterCustomers() {
    const q = (document.getElementById('searchCustomers')?.value || '').toLowerCase();
    const bereich = document.getElementById('filterBereich')?.value || '';
    const rows = document.getElementById('customersTable')?.querySelectorAll('tr') || [];
    const vis = [];
    rows.forEach(r => {
        const matchText = !q || r.textContent.toLowerCase().includes(q);
        const matchBereich = !bereich || (r.dataset.bereich || '').split('|').includes(bereich);
        const aktiv = r.dataset.aktiv === '1';
        let matchAktiv = true;
        if (_customerFilterMode === 'aktiv') matchAktiv = aktiv;
        else if (_customerFilterMode === 'archiv') matchAktiv = !aktiv;
        r.style.display = 'none';
        if (matchText && matchBereich && matchAktiv) vis.push(r);
    });
    const _p = hmsPagerDef('custPager', 'Kunden', () => filterCustomers());
    const pg = hmsPage(vis, _p.st, vis.length ? (vis[0].dataset.mk || '') : '');
    pg.rows.forEach(r => { r.style.display = ''; });
    hmsPagerFill('custPager', pg);
}

// ============================================
// CUSTOMER MODAL — Tabbed Form (Übersicht + Stammdaten + Dokumente)
// ============================================
// Kundenbetreuer options (active users of the company) — cached per session.
let _custBetreuerOpts = null;
async function custLoadBetreuer() {
    if (_custBetreuerOpts) return _custBetreuerOpts.slice();
    try {
        const r = await apiGet(API.customers + '/betreuer-options');
        _custBetreuerOpts = Array.isArray(r) ? r : [];
    } catch (e) { return []; }
    return _custBetreuerOpts.slice();
}

async function openCustomerModal(id = null) {
    let c = {};
    _currentCustomerId = id;
    if (id) {
        c = await apiGet(API.customers + '?id=' + id);
    }
    const betreuerOpts = await custLoadBetreuer();
    const curBetreuer = c.betreuer_id ? String(c.betreuer_id) : '';
    // A Betreuer who is no longer an active user stays visible (so a save
    // never silently drops him) — marked as inactive.
    if (curBetreuer && !betreuerOpts.some(o => String(o.id) === curBetreuer)) {
        betreuerOpts.push({ id: c.betreuer_id, name: 'Benutzer #' + c.betreuer_id + ' (inaktiv)' });
    }

    const tabs = `
        <md-tabs class="cust-modal-tabs" id="custModalTabs">
            ${id ? '<md-primary-tab data-panel="uebersicht" active><md-icon slot="icon">donut_small</md-icon>Übersicht</md-primary-tab>' : ''}
            <md-primary-tab data-panel="stammdaten" ${id ? '' : 'active'}><md-icon slot="icon">person</md-icon>Stammdaten</md-primary-tab>
            ${id ? '<md-primary-tab data-panel="dokumente"><md-icon slot="icon">folder_open</md-icon>Dokumente</md-primary-tab>' : ''}
            ${id ? '<md-primary-tab data-panel="favoriten"><md-icon slot="icon">star</md-icon>Stamm-Team</md-primary-tab>' : ''}
            ${id ? '<md-primary-tab data-panel="kportal"><md-icon slot="icon">public</md-icon>Kundenportal</md-primary-tab>' : ''}
        </md-tabs>
    `;

    const uebersichtTab = id ? `
        <div class="customer-tab-content active" id="tab-uebersicht">
            <div id="customerStatsContainer" class="customer-stats-container">
                <div class="customer-stats-loading"><md-circular-progress indeterminate style="--md-circular-progress-size:32px"></md-circular-progress> Statistiken werden geladen...</div>
            </div>
        </div>
    ` : '';

    const stammdatenTab = `
        <div class="customer-tab-content ${id ? '' : 'active'}" id="tab-stammdaten">
        <div class="customer-form-layout">
            <!-- Left Column: Anschrift -->
            <div class="customer-form-col">
                <div class="customer-form-section-title">
                    ${custIcon('location_on')} Anschrift
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_kunden_nr" label="Kunden-Nr. (extern)" value="${escapeHtml(c.kunden_nr_kunde || '')}" placeholder="optional"></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <div class="cust-radio-group" role="radiogroup" aria-label="Anschrift-Typ">
                        <span class="cust-radio-caption">Anschrift-Typ</span>
                        <label class="cust-radio-label">
                            <md-radio name="anschrift_typ" value="Firma" ${(c.anschrift_typ || 'Firma') === 'Firma' ? 'checked' : ''}></md-radio>Firma
                        </label>
                        <label class="cust-radio-label">
                            <md-radio name="anschrift_typ" value="Privat" ${c.anschrift_typ === 'Privat' ? 'checked' : ''}></md-radio>Privat
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_kundenname" label="Firma / Name" value="${escapeHtml(c.kundenname || '')}" required></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_zusatz" label="Zusatz" value="${escapeHtml(c.zusatz || '')}"></md-outlined-text-field>
                </div>

                <div class="customer-form-row">
                    <div class="form-group" style="flex:2">
                        <md-outlined-text-field id="f_strasse" label="Straße" value="${escapeHtml(c.strasse || '')}"></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:1">
                        <md-outlined-select id="f_land" label="Land">
                            <md-select-option value="D" ${(c.land || 'D') === 'D' ? 'selected' : ''}><div slot="headline">D</div></md-select-option>
                            <md-select-option value="AT" ${c.land === 'AT' ? 'selected' : ''}><div slot="headline">AT</div></md-select-option>
                            <md-select-option value="CH" ${c.land === 'CH' ? 'selected' : ''}><div slot="headline">CH</div></md-select-option>
                        </md-outlined-select>
                    </div>
                </div>

                <div class="customer-form-row">
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_plz" label="PLZ" value="${escapeHtml(c.plz || '')}" oninput="validatePlz(this)"></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:2">
                        <md-outlined-text-field id="f_ort" label="Ort" value="${escapeHtml(c.ort || '')}"></md-outlined-text-field>
                    </div>
                </div>

                <div class="customer-form-section-title" style="margin-top:18px;">
                    ${custIcon('sticky_note_2')} Anmerkungen
                </div>
                <div class="form-group">
                    <md-outlined-text-field type="textarea" rows="4" id="f_notiz" label="Notiz" value="${escapeHtml(c.notiz || '')}"></md-outlined-text-field>
                </div>
            </div>

            <!-- Right Column: Kommunikation -->
            <div class="customer-form-col">
                <div class="customer-form-section-title">
                    ${custIcon('call')} Kommunikation
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_telefon1" label="Telefon 1" value="${escapeHtml(c.telefon1 || '')}"><md-icon slot="trailing-icon">call</md-icon></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_telefon2" label="Telefon 2" value="${escapeHtml(c.telefon2 || '')}"><md-icon slot="trailing-icon">call</md-icon></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_fax" label="Fax" value="${escapeHtml(c.fax || '')}"><md-icon slot="trailing-icon">fax</md-icon></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_mobil" label="Mobil" value="${escapeHtml(c.mobil || '')}"><md-icon slot="trailing-icon">smartphone</md-icon></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <md-outlined-text-field type="email" id="f_email" label="E-Mail" value="${escapeHtml(c.email || '')}" oninput="validateEmail(this)"><md-icon slot="trailing-icon">alternate_email</md-icon></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <md-outlined-text-field type="email" id="f_rechnung_email" label="Rechnungs-E-Mail" value="${escapeHtml(c.rechnung_email || '')}" oninput="validateEmail(this)" placeholder="E-Mail für Rechnungsversand"><md-icon slot="trailing-icon">receipt_long</md-icon></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_website" label="Website" value="${escapeHtml(c.website || '')}" placeholder="z.B. www.firma.de"></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_haupt_ap" label="Haupt-Ansprechpartner" value="${escapeHtml(c.haupt_ap || '')}" placeholder="Name der Kontaktperson"></md-outlined-text-field>
                </div>

                <div class="customer-form-section-title" style="margin-top:18px;">
                    ${custIcon('settings')} Einstellungen
                </div>

                <div class="form-group">
                    <label style="display:block;font-size:0.8rem;color:var(--gray-600);margin-bottom:4px">Dienstleistungen (Mehrfachauswahl)</label>
                    <div id="f_bereiche" style="padding:4px 0"></div>
                </div>

                <div class="form-group">
                    <md-outlined-select id="f_abrechnungstyp" label="Abrechnungstyp">
                        <md-select-option value="Pro Einheit" ${c.abrechnungstyp === 'Pro Einheit' || !c.abrechnungstyp ? 'selected' : ''}><div slot="headline">Pro Einheit</div></md-select-option>
                        <md-select-option value="Pro Stunde" ${c.abrechnungstyp === 'Pro Stunde' ? 'selected' : ''}><div slot="headline">Pro Stunde</div></md-select-option>
                        <md-select-option value="Monatspauschale/gemischt" ${c.abrechnungstyp === 'Monatspauschale/gemischt' ? 'selected' : ''}><div slot="headline">Monatspauschale/gemischt</div></md-select-option>
                        <md-select-option value="Pauschal" ${c.abrechnungstyp === 'Pauschal' ? 'selected' : ''}><div slot="headline">Pauschal</div></md-select-option>
                    </md-outlined-select>
                </div>

                <div class="form-group">
                    <md-outlined-select id="f_betreuer" label="Kundenbetreuer" supporting-text="Erscheint als „Bearbeiter“ auf den Auftragsbestätigungen dieses Kunden">
                        <md-select-option value="" ${curBetreuer ? '' : 'selected'}><div slot="headline">Kein Kundenbetreuer (Ersteller des Auftrags)</div></md-select-option>
                        ${betreuerOpts.map(o => `<md-select-option value="${parseInt(o.id, 10)}" ${String(o.id) === curBetreuer ? 'selected' : ''}><div slot="headline">${escapeHtml(o.name)}</div></md-select-option>`).join('')}
                    </md-outlined-select>
                </div>

                <div class="form-group">
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:var(--gray-500);margin-bottom:6px">Kundenfarbe (Dispo-Karten)</label>
                    ${custColorPickerHtml(c.farbe || '')}
                </div>

                <div class="form-group">
                    <md-outlined-select id="f_aktiv" label="Aktiv">
                        <md-select-option value="1" ${c.aktiv == 1 || !id ? 'selected' : ''}><div slot="headline">Ja</div></md-select-option>
                        <md-select-option value="0" ${c.aktiv == 0 && id ? 'selected' : ''}><div slot="headline">Nein (Archiv)</div></md-select-option>
                    </md-outlined-select>
                </div>
            </div>
        </div>
        </div>
    `;

    const dokumenteTab = id ? `
        <div class="customer-tab-content" id="tab-dokumente">
            <div class="customer-docs-header">
                <h4>${custIcon('upload')} Dokument hochladen</h4>
            </div>
            <div class="customer-doc-upload-form">
                <div class="customer-form-row">
                    <div class="form-group" style="flex:2">
                        <md-outlined-text-field id="f_doc_titel" label="Titel / Bezeichnung *" placeholder="z.B. Vertrag, Rechnung, Ausweis..."></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:2">
                        <label class="cust-file-label">Datei auswählen *</label>
                        <input type="file" class="form-control" id="f_doc_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.xls,.xlsx">
                    </div>
                    <div class="form-group" style="flex:0 0 auto; align-self:center">
                        <md-filled-tonal-button onclick="uploadCustomerDocument('${id}')"><md-icon slot="icon">cloud_upload</md-icon>Hochladen</md-filled-tonal-button>
                    </div>
                </div>
            </div>
            <div class="customer-docs-grid" id="customerDocsGrid">
                <div class="text-center text-muted" style="padding:16px"><md-circular-progress indeterminate style="--md-circular-progress-size:32px"></md-circular-progress></div>
            </div>
        </div>
    ` : '';

    const favoritenTab = id ? `
        <div class="customer-tab-content" id="tab-favoriten">
            <div class="customer-docs-header"><h4>${custIcon('star')} Bevorzugte Mitarbeiter (Stamm-Team)</h4></div>
            <p style="color:var(--gray-500);font-size:0.85rem;margin:0 0 12px;line-height:1.5">Diese Mitarbeiter werden den Disponenten in Schichten dieses Kunden bevorzugt (mit Stern) angezeigt. Klicken zum An-/Abwählen — wird sofort gespeichert.</p>
            <md-outlined-text-field id="custFavSearch" label="Mitarbeiter suchen" style="width:100%;margin-bottom:12px" oninput="filterCustomerFavorites()"><md-icon slot="leading-icon">search</md-icon></md-outlined-text-field>
            <div id="customerFavoritesList"><div class="text-center text-muted" style="padding:16px"><md-circular-progress indeterminate style="--md-circular-progress-size:32px"></md-circular-progress></div></div>
        </div>
    ` : '';

    const kportalTab = id ? `
        <div class="customer-tab-content" id="tab-kportal">
            <div id="custPortalBox"><div class="text-center text-muted" style="padding:16px"><md-circular-progress indeterminate style="--md-circular-progress-size:32px"></md-circular-progress></div></div>
        </div>
    ` : '';

    const body = tabs + uebersichtTab + stammdatenTab + dokumenteTab + favoritenTab + kportalTab;

    const footer = `
        <md-text-button onclick="closeCustomersDialog()">Abbrechen</md-text-button>
        <md-filled-button onclick="saveCustomer('${id || ''}')">${id ? 'Aktualisieren' : 'Erstellen'}</md-filled-button>
    `;
    openCustomersDialog(id ? 'Kunde bearbeiten — ' + (c.kunde_id || '') : 'Neuen Kunden anlegen', body, footer, true);

    // md-tabs drives the panels: sync on user tab change.
    const tabsEl = document.getElementById('custModalTabs');
    tabsEl?.addEventListener('change', () => {
        const t = tabsEl.activeTab;
        if (t) switchCustomerTab(t.dataset.panel, false);
    });

    renderBereicheChecks('f_bereiche', c.bereiche || (c.bereich ? [c.bereich] : []), c.bereich || null);

    if (id) {
        loadCustomerStats(id);
        loadCustomerDocuments(id);
        loadCustomerFavorites(id);
        loadCustomerPortal(id);
    }
}

// ============================================
// KUNDENPORTAL ACCESS (read-only customer login)
// ============================================
async function loadCustomerPortal(id) {
    const box = document.getElementById('custPortalBox');
    if (!box) return;
    // The Kundenportal is part of the Qualitätsmanagement module: logins per
    // Ansprechpartner with object selection live in QM › Einstellungen.
    const darf = typeof hasPermission !== 'function' || hasPermission('qm.view');
    box.innerHTML = `
        <div class="customer-form-layout"><div class="customer-form-col">
            <div class="customer-form-section-title">${custIcon('vpn_key')} Zugänge zum Kundenportal</div>
            <p style="font-size:.88rem;color:var(--gray-700);line-height:1.6">Das Kundenportal gehört jetzt zum Qualitätsmanagement. Jeder Ansprechpartner des Kunden bekommt dort einen eigenen Zugang mit Objekt-Auswahl und Start-Passwort.</p>
            ${darf ? `<md-filled-button onclick="closeCustomersDialog();loadPage('einstellungen?tab=qualitaet&sub=kunden')"><md-icon slot="icon">open_in_new</md-icon>Kundenzugänge verwalten</md-filled-button>`
                : '<p style="color:var(--gray-500);font-size:.85rem">Verwaltet vom QMB bzw. Büro (Bereich Qualität).</p>'}
        </div></div>`;
}

// ============================================
// CUSTOMER COLOR PICKER (Kundenfarbe für Dispo)
// ============================================
const CUST_COLOR_PALETTE = [
    '#ef4444', '#f97316', '#14b8a6', '#0ea5e9', '#3b82f6',
    '#6366f1', '#a855f7', '#ec4899', '#78716c'
];

function custColorPickerHtml(current) {
    const cur = (current || '').toLowerCase();
    const swatches = CUST_COLOR_PALETTE.map(col => `
        <button type="button" class="cust-color-swatch${cur === col ? ' selected' : ''}" data-color="${col}"
                onclick="selectCustomerColor('${col}')" title="${col}"
                style="width:26px;height:26px;border-radius:50%;background:${col};cursor:pointer;border:2px solid ${cur === col ? 'var(--gray-800, #1f2937)' : 'transparent'};box-shadow:0 0 0 1px rgba(0,0,0,0.12);padding:0"></button>
    `).join('');
    const isCustom = cur && !CUST_COLOR_PALETTE.includes(cur);
    return `
        <div class="cust-color-picker" id="custColorPicker" style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
            <input type="hidden" id="f_farbe" value="${escapeHtml(current || '')}">
            <button type="button" class="cust-color-swatch cust-color-none${!cur ? ' selected' : ''}" data-color=""
                    onclick="selectCustomerColor('')" title="Keine Farbe"
                    style="width:26px;height:26px;border-radius:50%;background:#fff;cursor:pointer;border:2px solid ${!cur ? 'var(--gray-800, #1f2937)' : '#d1d5db'};padding:0;position:relative">
                <span class="material-symbols-outlined" style="font-size:16px;color: var(--md-sys-color-on-surface-variant);line-height:22px">block</span>
            </button>
            ${swatches}
            <label title="Eigene Farbe wählen" style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                <input type="color" id="f_farbe_custom" value="${isCustom ? cur : '#64748b'}"
                       oninput="selectCustomerColor(this.value)"
                       style="width:30px;height:26px;border:none;background:none;cursor:pointer;padding:0">
            </label>
        </div>
    `;
}

function selectCustomerColor(color) {
    const input = document.getElementById('f_farbe');
    if (input) input.value = color || '';
    document.querySelectorAll('#custColorPicker .cust-color-swatch').forEach(btn => {
        const isSel = (btn.dataset.color || '') === (color || '').toLowerCase();
        btn.classList.toggle('selected', isSel);
        btn.style.border = `2px solid ${isSel ? 'var(--gray-800, #1f2937)' : (btn.dataset.color ? 'transparent' : '#d1d5db')}`;
    });
}

// ============================================
// CUSTOMER STATS PANEL
// ============================================
async function loadCustomerStats(id) {
    const container = document.getElementById('customerStatsContainer');
    if (!container) return;

    try {
        const res = await fetch(API.customers + '/' + encodeURIComponent(id) + '/stats', {
            headers: getAuthHeadersNoContent()
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const s = await res.json();

        const lastDate = s.last_einsatz_date ? new Date(s.last_einsatz_date).toLocaleDateString('de-DE') : '–';
        const objAktivPct = s.objekte_total > 0 ? Math.round((s.objekte_aktiv / s.objekte_total) * 100) : 0;
        const bestLaufPct = s.bestellungen_total > 0 ? Math.round((s.bestellungen_laufend / s.bestellungen_total) * 100) : 0;

        container.innerHTML = `
            <div class="customer-bento">
                <div class="bento-tile bento-tile-hero bento-tile-objekte-hero" data-clickable="1" onclick="openCustomerObjects('${id}')" title="Objekte öffnen">
                    <div class="bento-tile-head">${custIcon('domain')} Objekte</div>
                    <div class="bento-tile-number">${s.objekte_aktiv}<small> / ${s.objekte_total}</small></div>
                    <div class="bento-tile-foot">
                        <span class="bento-foot-pill">${custIcon('sell')} ${s.artikel_aktiv} Artikel aktiv</span>
                        <span class="bento-foot-pill">${custIcon('monitoring')} ${objAktivPct}% aktiv</span>
                    </div>
                    <div class="bento-progress" title="${objAktivPct}% aktiv">
                        <div class="bento-progress-bar" style="width:${objAktivPct}%"></div>
                    </div>
                </div>
                <div class="bento-tile bento-tile-artikel">
                    <div class="bento-tile-icon">${custIcon('sell')}</div>
                    <div class="bento-tile-body">
                        <div class="bento-tile-number-sm">${s.artikel_aktiv}</div>
                        <div class="bento-tile-label">Artikel aktiv</div>
                    </div>
                </div>
                <div class="bento-tile bento-tile-orders" data-clickable="1" onclick="openCustomerOrders('${id}')" title="Bestellungen öffnen">
                    <div class="bento-tile-icon">${custIcon('receipt_long')}</div>
                    <div class="bento-tile-body">
                        <div class="bento-tile-number-sm">${s.bestellungen_laufend}<small> / ${s.bestellungen_total}</small></div>
                        <div class="bento-tile-label">Bestellungen laufend</div>
                    </div>
                    <div class="bento-progress"><div class="bento-progress-bar" style="width:${bestLaufPct}%"></div></div>
                </div>
                <div class="bento-tile bento-tile-einsaetze">
                    <div class="bento-tile-icon">${custIcon('task_alt')}</div>
                    <div class="bento-tile-body">
                        <div class="bento-tile-number-sm">${s.einsaetze_total}</div>
                        <div class="bento-tile-label">Einsätze gesamt</div>
                    </div>
                </div>
                <div class="bento-tile bento-tile-last">
                    <div class="bento-tile-icon">${custIcon('today')}</div>
                    <div class="bento-tile-body">
                        <div class="bento-tile-number-sm" style="font-size:1.6rem;letter-spacing:-0.5px">${lastDate}</div>
                        <div class="bento-tile-label">Letzter Einsatz</div>
                    </div>
                </div>
                <div class="bento-tile bento-tile-docs" data-clickable="1" onclick="switchCustomerTab('dokumente')" title="Dokumente öffnen">
                    <div class="bento-tile-icon">${custIcon('folder')}</div>
                    <div class="bento-tile-body">
                        <div class="bento-tile-number-sm">${s.documents}</div>
                        <div class="bento-tile-label">Dokumente</div>
                    </div>
                </div>
            </div>
            <div class="customer-stats-actions">
                <md-outlined-button onclick="openCustomerObjects('${id}')"><md-icon slot="icon">arrow_forward</md-icon>Objekte öffnen</md-outlined-button>
                <md-outlined-button onclick="openCustomerOrders('${id}')"><md-icon slot="icon">arrow_forward</md-icon>Bestellungen öffnen</md-outlined-button>
            </div>
        `;
    } catch (e) {
        container.innerHTML = '<div class="customer-stats-loading text-danger">Statistiken konnten nicht geladen werden.</div>';
    }
}

// ============================================
// QUICK NAVIGATION FROM CUSTOMER STATS
// ============================================
function openCustomerObjects(kundeId) {
    closeCustomersDialog();
    if (typeof loadPage === 'function') loadPage('objects');
    setTimeout(() => {
        const search = document.getElementById('searchObjects');
        if (search) {
            search.value = kundeId;
            search.dispatchEvent(new Event('input'));
            search.focus();
        }
    }, 350);
}

function openCustomerOrders(kundeId) {
    closeCustomersDialog();
    if (typeof loadPage === 'function') loadPage('orders');
    setTimeout(() => {
        const search = document.getElementById('searchOrders');
        if (search) {
            search.value = kundeId;
            search.dispatchEvent(new Event('input'));
            search.focus();
        }
    }, 350);
}

// ============================================
// INLINE VALIDATORS
// ============================================
// MD3 text fields carry their own error state (error + error-text),
// the legacy .field-error spans / .input-error class are gone.
const CUST_FIELD_IDS = {
    kundenname: 'f_kundenname',
    email: 'f_email',
    rechnung_email: 'f_rechnung_email',
    plz: 'f_plz',
    kunden_nr_kunde: 'f_kunden_nr',
    website: 'f_website',
    land: 'f_land',
    abrechnungstyp: 'f_abrechnungstyp',
    betreuer_id: 'f_betreuer'
};

function _custMarkError(input, msg) {
    if (!input) return;
    input.error = !!msg;
    input.errorText = msg || '';
}

function validateEmail(input) {
    const v = (input.value || '').trim();
    const ok = !v || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    _custMarkError(input, ok ? '' : 'Ungültige E-Mail-Adresse');
}

function validatePlz(input) {
    const v = (input.value || '').trim();
    const ok = !v || /^\d{4,5}$/.test(v);
    _custMarkError(input, ok ? '' : '4–5 Ziffern erwartet');
}

function setFieldError(field, msg) {
    _custMarkError(document.getElementById(CUST_FIELD_IDS[field] || ''), msg);
}

function clearAllFieldErrors() {
    Object.values(CUST_FIELD_IDS).forEach(id => _custMarkError(document.getElementById(id), ''));
}

function applyServerErrors(errors) {
    if (!errors || typeof errors !== 'object') return;
    Object.entries(errors).forEach(([field, msgs]) => {
        const text = Array.isArray(msgs) ? msgs.join(' · ') : String(msgs);
        setFieldError(field, text);
    });
}

// ============================================
// SAVE CUSTOMER
// ============================================
async function saveCustomer(id) {
    clearAllFieldErrors();
    // md-radio: `:checked` doesn't match web components — use the property.
    const anschriftTyp = [...document.querySelectorAll('md-radio[name="anschrift_typ"]')].find(r => r.checked);

    const data = {
        kundenname: (document.getElementById('f_kundenname').value || '').trim(),
        kunden_nr_kunde: document.getElementById('f_kunden_nr').value || null,
        abrechnungstyp: document.getElementById('f_abrechnungstyp').value,
        anschrift_typ: anschriftTyp ? anschriftTyp.value : 'Firma',
        zusatz: document.getElementById('f_zusatz').value || null,
        strasse: document.getElementById('f_strasse').value || null,
        land: document.getElementById('f_land').value || 'D',
        plz: document.getElementById('f_plz').value || null,
        ort: document.getElementById('f_ort').value || null,
        telefon1: document.getElementById('f_telefon1').value || null,
        telefon2: document.getElementById('f_telefon2').value || null,
        fax: document.getElementById('f_fax').value || null,
        mobil: document.getElementById('f_mobil').value || null,
        email: document.getElementById('f_email').value || null,
        rechnung_email: document.getElementById('f_rechnung_email').value || null,
        website: document.getElementById('f_website').value || null,
        haupt_ap: document.getElementById('f_haupt_ap').value || null,
        notiz: document.getElementById('f_notiz').value || null,
        bereich: (typeof mselPrimary === 'function') ? mselPrimary('f_bereiche') : null,
        bereiche: collectBereicheChecks('f_bereiche'),
        farbe: document.getElementById('f_farbe')?.value || null,
        aktiv: document.getElementById('f_aktiv').value
    };
    const betreuerEl = document.getElementById('f_betreuer');
    if (betreuerEl) data.betreuer_id = betreuerEl.value ? parseInt(betreuerEl.value, 10) : null;

    if (!data.kundenname) {
        setFieldError('kundenname', 'Kundenname ist erforderlich');
        switchCustomerTab('stammdaten');
        showToast('Kundenname ist erforderlich', 'error');
        return;
    }

    let result;
    try {
        const url = id ? API.customers + '/' + id : API.customers;
        const res = await fetch(url, {
            method: id ? 'PUT' : 'POST',
            headers: getAuthHeaders(),
            body: JSON.stringify(data)
        });
        result = await res.json();

        if (res.status === 422 && result.errors) {
            switchCustomerTab('stammdaten');
            applyServerErrors(result.errors);
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
        closeCustomersDialog();
        if (typeof invalidateBereichCache === 'function') invalidateBereichCache();
        showToast(id ? 'Kunde aktualisiert' : 'Kunde erstellt');
        renderCustomers();
    } else {
        showToast(result.error || 'Fehler beim Speichern', 'error');
    }
}

async function deactivateCustomer(id) {
    if (!await mdConfirm('Kunde „' + id + '“ deaktivieren? Er wird ins Archiv verschoben.', { title: 'Kunde deaktivieren', confirmLabel: 'Deaktivieren' })) return;
    const result = await apiDelete(API.customers + '/' + id);
    if (result.success) {
        showToast('Kunde deaktiviert');
        renderCustomers();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function reactivateCustomer(id) {
    // Re-activate by sending an update with aktiv=1 (loads existing then updates)
    const c = await apiGet(API.customers + '?id=' + id);
    if (!c || !c.kunde_id) { showToast('Kunde nicht gefunden', 'error'); return; }
    c.aktiv = 1;
    // show() returns `bereiche` as a raw JSON string — dropping the key lets the
    // server rebuild the list from its stored value instead of storing the
    // string itself as a bogus tag (and restricted users avoid a 403).
    delete c.bereiche;
    const result = await apiPut(API.customers + '/' + id, c);
    if (result.success) {
        showToast('Kunde reaktiviert');
        renderCustomers();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function deleteCustomer(id) {
    // Step 1: load impact preview from server
    let preview;
    try {
        const res = await fetch(API.customers + '/' + encodeURIComponent(id) + '/delete-preview', {
            headers: getAuthHeadersNoContent()
        });
        preview = await res.json();
        if (!res.ok) throw new Error(preview.error || 'Fehler');
    } catch (e) {
        if (!await mdConfirm('Kunde „' + id + '“ endgültig löschen?\n\nAlle zugehörigen Daten werden unwiderruflich entfernt!', { title: 'Endgültig löschen', confirmLabel: 'Löschen' })) return;
        const r = await apiDelete(API.customers + '/' + id, { permanent: true });
        if (r.success) { showToast('Kunde gelöscht'); renderCustomers(); }
        else showToast(r.error || 'Fehler', 'error');
        return;
    }

    // Step 2: confirmation dialog with cascade preview
    const body = `
        <div class="delete-preview">
            <div class="delete-preview-header">
                ${custIcon('warning')}
                <div>
                    <div class="delete-preview-title">Kunde endgültig löschen?</div>
                    <div class="delete-preview-sub">Folgende Daten werden <strong>unwiderruflich</strong> gelöscht:</div>
                </div>
            </div>
            <ul class="delete-preview-list">
                <li><strong>${preview.objekte}</strong> Objekt(e)</li>
                <li><strong>${preview.artikel}</strong> Artikel / Leistung(en)</li>
                <li><strong>${preview.bestelleingang}</strong> Bestelleingang-Eintrag/-Einträge</li>
                <li><strong>${preview.bestellungen}</strong> Bestellung(en)</li>
                <li><strong>${preview.einsaetze}</strong> Einsatz/Einsätze</li>
                <li><strong>${preview.documents}</strong> Dokument(e)</li>
            </ul>
            <div class="delete-preview-confirm">
                <md-outlined-text-field id="confirmDeleteInput" label="Zur Bestätigung ${id} eintippen" autocomplete="off" style="width:100%" oninput="document.getElementById('confirmDeleteBtn').disabled = this.value !== '${id}'"></md-outlined-text-field>
            </div>
        </div>
    `;
    const footer = `
        <md-text-button onclick="closeCustomersDialog()">Abbrechen</md-text-button>
        <md-filled-button class="cust-danger-btn" id="confirmDeleteBtn" disabled onclick="confirmDeleteCustomer('${id}')"><md-icon slot="icon">delete_forever</md-icon>Endgültig löschen</md-filled-button>
    `;
    openCustomersDialog('Kunde löschen — ' + id, body, footer);
}

async function confirmDeleteCustomer(id) {
    const result = await apiDelete(API.customers + '/' + id, { permanent: true });
    if (result.success) {
        closeCustomersDialog();
        showToast('Kunde endgültig gelöscht');
        renderCustomers();
    } else {
        showToast(result.error || 'Fehler beim Löschen', 'error');
    }
}

// ============================================
// CUSTOMER TABS
// ============================================
function switchCustomerTab(tab, syncTabs = true) {
    document.querySelectorAll('.customer-tab-content').forEach(c => c.classList.toggle('active', c.id === 'tab-' + tab));
    if (syncTabs) {
        // Programmatic switch (e.g. validation error) — move the md-tabs
        // indicator too. `.active`-class toggling doesn't work on md-tabs.
        const tabsEl = document.getElementById('custModalTabs');
        if (tabsEl && tabsEl.tabs) {
            const idx = [...tabsEl.tabs].findIndex(t => t.dataset.panel === tab);
            if (idx >= 0 && tabsEl.activeTabIndex !== idx) tabsEl.activeTabIndex = idx;
        }
    }
}

// ============================================
// CUSTOMER FAVORITE WORKERS (Stamm-Team)
// ============================================
let _custFavSet = new Set();
let _custFavWorkers = [];
let _custFavId = null;

async function loadCustomerFavorites(id) {
    _custFavId = id;
    const list = document.getElementById('customerFavoritesList');
    if (!list) return;
    try {
        const data = await apiGet(API.customers + '/' + encodeURIComponent(id) + '/favorites');
        _custFavWorkers = (data && data.workers) || [];
        _custFavSet = new Set(((data && data.favorites) || []).map(String));
        renderCustomerFavorites();
    } catch (e) {
        list.innerHTML = '<div class="text-danger" style="padding:12px">Favoriten konnten nicht geladen werden.</div>';
    }
}

function filterCustomerFavorites() { renderCustomerFavorites(); }

function renderCustomerFavorites() {
    const list = document.getElementById('customerFavoritesList');
    if (!list) return;
    const q = (document.getElementById('custFavSearch')?.value || '').toLowerCase().trim();
    const sorted = [..._custFavWorkers].sort((a, b) => {
        const fa = _custFavSet.has(String(a.dienst_nr)) ? 0 : 1;
        const fb = _custFavSet.has(String(b.dienst_nr)) ? 0 : 1;
        if (fa !== fb) return fa - fb;
        return (`${a.vorname||''} ${a.name||''}`).localeCompare(`${b.vorname||''} ${b.name||''}`);
    });
    const rows = sorted.filter(w => !q || (`${w.vorname||''} ${w.name||''} ${w.dienst_nr||''} ${w.bereich||''}`).toLowerCase().includes(q)).map(w => {
        const dn = String(w.dienst_nr);
        const on = _custFavSet.has(dn);
        const initial = (w.vorname || w.name || dn || '?').charAt(0).toUpperCase();
        const avatar = w.foto
            ? `<img src="${escapeHtml(w.foto)}" alt="" style="width:38px;height:38px;border-radius:50%;object-fit:cover">`
            : `<span style="width:38px;height:38px;border-radius:50%;background: var(--accent-soft, var(--md-sys-color-primary-container));color: var(--accent, var(--md-sys-color-primary));display:flex;align-items:center;justify-content:center;font-weight:700;flex:0 0 auto">${escapeHtml(initial)}</span>`;
        return `<div onclick="toggleCustomerFavorite('${escapeHtml(dn)}')" style="display:flex;align-items:center;gap:10px;padding:8px 12px;margin-bottom:6px;border:1px solid ${on ? 'var(--warning, #f59e0b)' : 'var(--gray-200, #e5e7eb)'};background:${on ? 'var(--warning-light, #fffbeb)' : 'var(--white, #fff)'};border-radius:10px;cursor:pointer;transition:all .12s">
            ${avatar}
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;color:var(--gray-800)">${escapeHtml(w.vorname || '')} ${escapeHtml(w.name || '')}</div>
                <div style="font-size:0.8rem;color:var(--gray-500)">${escapeHtml(dn)}${w.bereich ? ' · ' + escapeHtml(w.bereich) : ''}</div>
            </div>
            ${custIcon('star', `font-size:20px;color:${on ? 'var(--warning, #f59e0b)' : 'var(--gray-300, #cbd5e1)'};font-variation-settings:'FILL' ${on ? 1 : 0}`)}
        </div>`;
    }).join('');
    list.innerHTML = rows || '<div class="text-center text-muted" style="padding:16px">Keine Mitarbeiter gefunden</div>';
}

async function toggleCustomerFavorite(dienstNr) {
    dienstNr = String(dienstNr);
    if (_custFavSet.has(dienstNr)) _custFavSet.delete(dienstNr);
    else _custFavSet.add(dienstNr);
    renderCustomerFavorites();
    if (!_custFavId) return;
    try {
        const res = await fetch(API.customers + '/' + encodeURIComponent(_custFavId) + '/favorites', {
            method: 'PUT',
            headers: getAuthHeaders(),
            body: JSON.stringify({ dienst_nrs: [..._custFavSet] })
        });
        if (!res.ok) {
            const r = await res.json().catch(() => ({}));
            showToast((r && r.error) || 'Favoriten konnten nicht gespeichert werden', 'error');
        }
    } catch (e) {
        showToast('Netzwerkfehler beim Speichern der Favoriten', 'error');
    }
}

// ============================================
// CUSTOMER DOCUMENTS
// ============================================
async function loadCustomerDocuments(kundeId) {
    const grid = document.getElementById('customerDocsGrid');
    if (!grid) return;

    const docs = await apiGet(API.customer_documents + '?kunde_id=' + kundeId);
    const items = Array.isArray(docs) ? docs : [];

    if (items.length === 0) {
        grid.innerHTML = `<div class="customer-docs-empty">${custIcon('folder_open')}<p>Keine Dokumente vorhanden</p></div>`;
        return;
    }

    grid.innerHTML = items.map(doc => {
        const isImage = doc.dateityp === 'image';
        const isPdf = doc.dateityp === 'pdf';
        const fileSize = doc.dateigroesse > 1024 * 1024
            ? (doc.dateigroesse / (1024 * 1024)).toFixed(1) + ' MB'
            : Math.round(doc.dateigroesse / 1024) + ' KB';
        const uploadDate = doc.hochgeladen_am ? new Date(doc.hochgeladen_am).toLocaleDateString('de-DE') : '';

        // For PDFs and images we now lazy-render an actual preview via the
        // auth-gated /thumb endpoint. Other types keep their generic icon.
        const thumbUrl = (isImage || isPdf)
            ? `${API_BASE}/customer-documents/${doc.id}/thumb`
            : null;

        let thumbnail = '';
        if (thumbUrl) {
            const fbIcon  = isPdf ? 'picture_as_pdf' : 'image';
            const fbClass = isPdf ? 'doc-thumb-pdf' : 'doc-thumb-image';
            thumbnail = `<div class="doc-thumb-icon ${fbClass} doc-thumb" data-thumb-url="${escapeHtml(thumbUrl)}">${custIcon(fbIcon)}</div>`;
        } else if (doc.dateityp === 'doc' || doc.dateityp === 'docx') {
            thumbnail = `<div class="doc-thumb-icon doc-thumb-word">${custIcon('description')}</div>`;
        } else if (doc.dateityp === 'xls' || doc.dateityp === 'xlsx') {
            thumbnail = `<div class="doc-thumb-icon doc-thumb-excel">${custIcon('table')}</div>`;
        } else {
            thumbnail = `<div class="doc-thumb-icon doc-thumb-generic">${custIcon('draft')}</div>`;
        }

        return `
            <div class="customer-doc-card">
                <div class="doc-thumb-wrap">${thumbnail}</div>
                <div class="doc-info">
                    <div class="doc-title" title="${escapeHtml(doc.titel)}">${escapeHtml(doc.titel)}</div>
                    <div class="doc-meta">${escapeHtml(doc.dateiname)}</div>
                    <div class="doc-meta">${fileSize} · ${uploadDate}</div>
                </div>
                <div class="doc-actions">
                    <md-icon-button onclick="hmsOpenFile('${hmsFileArg(doc.dateipfad)}')" title="Anzeigen"><md-icon>visibility</md-icon></md-icon-button>
                    <md-icon-button onclick="hmsOpenFile('${hmsFileArg(doc.dateipfad)}', true)" title="Herunterladen"><md-icon>download</md-icon></md-icon-button>
                    <md-icon-button class="danger" onclick="deleteCustomerDocument(${doc.id}, '${kundeId}')" title="Löschen"><md-icon>delete</md-icon></md-icon-button>
                </div>
            </div>
        `;
    }).join('');

    if (typeof hydrateSecureThumbs === 'function') hydrateSecureThumbs(grid);
}

async function uploadCustomerDocument(kundeId) {
    const titel = document.getElementById('f_doc_titel').value.trim();
    const fileInput = document.getElementById('f_doc_file');

    if (!titel) { showToast('Bitte einen Titel eingeben', 'error'); return; }
    if (!fileInput.files || !fileInput.files[0]) { showToast('Bitte eine Datei auswählen', 'error'); return; }

    const formData = new FormData();
    formData.append('kunde_id', kundeId);
    formData.append('titel', titel);
    formData.append('dokument', fileInput.files[0]);

    try {
        const res = await fetch(API.customer_documents + '/upload', {
            method: 'POST',
            headers: getAuthHeadersNoContent(),
            body: formData
        });
        const result = await res.json();
        if (result.success) {
            showToast('Dokument hochgeladen');
            document.getElementById('f_doc_titel').value = '';
            fileInput.value = '';
            loadCustomerDocuments(kundeId);
        } else {
            showToast(result.error || 'Upload fehlgeschlagen', 'error');
        }
    } catch (e) {
        showToast('Upload-Fehler: ' + e.message, 'error');
    }
}

async function deleteCustomerDocument(docId, kundeId) {
    if (!await mdConfirm('Dokument wirklich löschen?', { title: 'Dokument löschen', confirmLabel: 'Löschen' })) return;
    try {
        const res = await fetch(API.customer_documents + '/' + docId, {
            method: 'DELETE',
            headers: getAuthHeaders()
        });
        const result = await res.json();
        if (result.success) {
            showToast('Dokument gelöscht');
            loadCustomerDocuments(kundeId);
        } else {
            showToast(result.error || 'Löschen fehlgeschlagen', 'error');
        }
    } catch (e) {
        showToast('Fehler: ' + e.message, 'error');
    }
}

