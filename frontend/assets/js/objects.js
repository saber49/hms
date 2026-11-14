/* ============================================
   HMS Gruppe — Objekte (locations) + Artikel (services)
   Backend: api/objects + api/artikel
   ============================================ */

// Add /artikel to API namespace if missing
if (typeof API !== 'undefined' && !API.artikel) {
    API.artikel = (typeof API_BASE !== 'undefined' ? API_BASE : (window.location.origin + '/api')) + '/artikel';
}

const _modellOptions = ['Standard', 'Pro Einsatz', 'Pro Stunde', 'Monatspauschale'];
let _objectFilterMode = 'aktiv'; // 'aktiv' | 'archiv' | 'alle'
let _objAdresseTimer = null;
let _objAdresseAbort = null;
let _objKontaktTimer = null;
let _objKontaktSeq = 0;
let _objKontaktItems = [];   // current suggestions (for keyboard navigation)
let _objKontaktActive = -1;

// ---------- MD3 dialog (official <md-dialog>) ----------
// The objects module no longer uses the legacy openModal() shell.
// Icons come from custIcon() (customers.js) and confirms from
// mdConfirm() (users.js) — both global at runtime.
function openObjectsDialog(title, bodyHtml, footerHtml = '', wide = false) {
    document.getElementById('objectsMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'objectsMdDialog';
    if (wide) dlg.classList.add('objects-dialog-xl');
    dlg.innerHTML = `
      <div slot="headline">${title}</div>
      <div slot="content" class="objects-dialog-content">${bodyHtml}</div>
      <div slot="actions">${footerHtml}</div>`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();
    return dlg;
}
function closeObjectsDialog() { document.getElementById('objectsMdDialog')?.close(); }

async function renderObjects() {
    const content = document.getElementById('content');
    const data = await apiGet(API.objects);
    const objects = Array.isArray(data) ? data : [];

    const total = objects.length;
    const aktiv = objects.filter(o => o.aktiv == 1).length;
    const archiv = total - aktiv;
    const mitArtikel = objects.filter(o => (parseInt(o.total_artikel) || 0) > 0).length;
    const artikelTotal = objects.reduce((s, o) => s + (parseInt(o.aktive_artikel) || 0), 0);
    const aktivPct = total > 0 ? Math.round((aktiv / total) * 100) : 0;
    const artikelAvg = total > 0 ? (artikelTotal / total).toFixed(1) : '0';

    // Unique customers for filter dropdown
    const customerList = [...new Map(objects.map(o => [o.kunden_id, o.kundenname || o.kunden_id])).entries()]
        .filter(([id]) => id).sort((a, b) => String(a[1]).localeCompare(String(b[1])));

    const html = `
        <div class="data-bento">
            <div class="bento-tile bento-tile-hero bento-tile-total" onclick="setObjectFilter('alle')" title="Alle Objekte anzeigen">
                <div class="bento-tile-head">${custIcon('domain')} Objekte gesamt</div>
                <div class="bento-tile-number">${total}</div>
                <div class="bento-tile-foot">
                    <span class="bento-foot-pill">${custIcon('check_circle')} ${aktiv} Aktiv</span>
                    <span class="bento-foot-pill">${custIcon('archive')} ${archiv} Inaktiv</span>
                    <span class="bento-foot-pill">${custIcon('sell')} ${artikelTotal} Artikel</span>
                </div>
            </div>
            <div class="bento-tile bento-tile-active" onclick="setObjectFilter('aktiv')" title="Nur aktive Objekte">
                <div class="bento-tile-icon">${custIcon('check_circle')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${aktiv}</div>
                    <div class="bento-tile-label">Aktive Objekte</div>
                </div>
                <div class="bento-progress" title="${aktivPct}% aktiv">
                    <div class="bento-progress-bar" style="width:${aktivPct}%"></div>
                </div>
            </div>
            <div class="bento-tile bento-tile-archiv" onclick="setObjectFilter('archiv')" title="Inaktive Objekte">
                <div class="bento-tile-icon">${custIcon('archive')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${archiv}</div>
                    <div class="bento-tile-label">Inaktiv</div>
                </div>
            </div>
            <div class="bento-tile bento-tile-wide bento-tile-objekte" title="Artikel / Leistungen">
                <div class="bento-tile-icon">${custIcon('sell')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${artikelTotal}</div>
                    <div class="bento-tile-label">Aktive Artikel</div>
                </div>
                <div class="bento-tile-meta">
                    <span>⌀ ${artikelAvg} pro Objekt</span>
                    <span>${mitArtikel}/${total} mit Artikeln</span>
                </div>
            </div>
        </div>

        <div class="data-toolbar">
            <div class="data-toolbar-left">
                <md-outlined-text-field class="cust-search" id="searchObjects" label="Objekte suchen" placeholder="Name, Nr., Adresse…">
                    <md-icon slot="leading-icon">search</md-icon>
                </md-outlined-text-field>
                <md-outlined-select class="cust-bereich-select" id="filterKunde" label="Kunde">
                    <md-select-option value="" selected><div slot="headline">Alle Kunden</div></md-select-option>
                    ${customerList.map(([id, name]) => `<md-select-option value="${id}"><div slot="headline">${escapeHtml(name)}</div></md-select-option>`).join('')}
                </md-outlined-select>
                <md-chip-set id="objectFilterToggle">
                    <md-filter-chip label="Aktiv" data-mode="aktiv" ${_objectFilterMode === 'aktiv' ? 'selected' : ''} onclick="setObjectFilter('aktiv')"></md-filter-chip>
                    <md-filter-chip label="Inaktiv" data-mode="archiv" ${_objectFilterMode === 'archiv' ? 'selected' : ''} onclick="setObjectFilter('archiv')"></md-filter-chip>
                    <md-filter-chip label="Alle" data-mode="alle" ${_objectFilterMode === 'alle' ? 'selected' : ''} onclick="setObjectFilter('alle')"></md-filter-chip>
                </md-chip-set>
            </div>
            <md-filled-button onclick="openObjectModal()">
                <md-icon slot="icon">add</md-icon>Neues Objekt
            </md-filled-button>
        </div>

        <div class="data-table-card">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Obj-ID</th>
                            <th>Kunde</th>
                            <th>AST-Nr.</th>
                            <th>Obj-Nr. intern</th>
                            <th>Objektname</th>
                            <th>Art</th>
                            <th>Anschrift</th>
                            <th>Artikel</th>
                            <th class="text-center">${escapeHtml(wbLabel())}</th>
                            <th>Status</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody id="objectsTable">
                        ${objects.map(o => {
                            const adresseFull = [o.adresse, o.plz, o.stadt].filter(Boolean).join(', ');
                            const aktive = parseInt(o.aktive_artikel) || 0;
                            const totalArt = parseInt(o.total_artikel) || 0;
                            const artBadge = totalArt > 0
                                ? `<span class="kunden-obj-badge ${aktive === totalArt ? 'all-active' : (aktive === 0 ? 'none-active' : 'partial')}" title="${aktive} aktiv von ${totalArt} Artikeln">${custIcon('sell', 'font-size:13px')} ${aktive}/${totalArt}</span>`
                                : `<span class="kunden-obj-badge none">–</span>`;
                            return `<tr data-mk="${escapeHtml(o.obj_id)}" data-kunde="${o.kunden_id || ''}" data-aktiv="${o.aktiv}">
                                <td><strong>${o.obj_id}</strong></td>
                                <td>${escapeHtml(o.kundenname || o.kunden_id || '–')}</td>
                                <td>${o.obj_nr_kunde || '–'}</td>
                                <td>${o.obj_nr_intern || '–'}</td>
                                <td>${escapeHtml(o.objektname)}</td>
                                <td><small>${typeof bereicheBadgesHtml === 'function' ? bereicheBadgesHtml(o.art, o.arten) : escapeHtml(o.art || '–')}</small></td>
                                <td><small>${escapeHtml(adresseFull) || '–'}</small></td>
                                <td>${artBadge}</td>
                                <td class="text-center">${wachbuchBtnHtml(o.obj_id, '', o.objektname)}</td>
                                <td>${o.aktiv == 1 ? '<span class="status status-ja">Aktiv</span>' : '<span class="status status-nein">Inaktiv</span>'}</td>
                                <td>
                                    <div class="cust-row-actions">
                                        <md-icon-button onclick="openObjectModal('${o.obj_id}')" title="Bearbeiten"><md-icon>edit</md-icon></md-icon-button>
                                        <md-icon-button onclick="openArtikelManager('${o.obj_id}')" title="Artikel verwalten"><md-icon>sell</md-icon></md-icon-button>
                                        ${o.aktiv == 1 ? `<md-icon-button class="warn" onclick="deactivateObject('${o.obj_id}')" title="Deaktivieren"><md-icon>block</md-icon></md-icon-button>` : ''}
                                        <md-icon-button class="danger" onclick="deleteObject('${o.obj_id}')" title="Endgültig löschen"><md-icon>delete</md-icon></md-icon-button>
                                    </div>
                                </td>
                            </tr>`;
                        }).join('')}
                    </tbody>
                </table>
                ${objects.length === 0 ? `<div class="empty-state">${custIcon('location_on')}<p>Noch keine Objekte vorhanden</p></div>` : ''}
                <div id="objPager" class="be-pager"></div>
            </div>
        </div>
    `;

    // Page already on screen (save, realtime): patch tiles + rows in place;
    // the toolbar (search text, Kunde filter, chips) stays untouched.
    if (document.getElementById('objectsTable') && typeof hmsMorph === 'function') {
        hmsMorph(content, html, { keep: el => el.classList.contains('data-toolbar') });
    } else {
        content.innerHTML = html;
        // MD3 web components re-dispatch input/change from their shadow DOM —
        // wire them here instead of inline attributes.
        document.getElementById('searchObjects')?.addEventListener('input', filterObjects);
        document.getElementById('filterKunde')?.addEventListener('change', filterObjects);
    }

    filterObjects();
}

function setObjectFilter(mode) {
    _objectFilterMode = mode;
    // md-filter-chip: radio behaviour — exactly one chip stays selected.
    document.querySelectorAll('#objectFilterToggle md-filter-chip').forEach(ch => {
        ch.selected = ch.dataset.mode === mode;
    });
    filterObjects();
}

function filterObjects() {
    const q = (document.getElementById('searchObjects')?.value || '').toLowerCase();
    const kunde = document.getElementById('filterKunde')?.value || '';
    const rows = document.getElementById('objectsTable')?.querySelectorAll('tr') || [];
    const vis = [];
    rows.forEach(r => {
        const matchText = !q || r.textContent.toLowerCase().includes(q);
        const matchKunde = !kunde || r.dataset.kunde === kunde;
        const aktiv = r.dataset.aktiv === '1';
        let matchAktiv = true;
        if (_objectFilterMode === 'aktiv') matchAktiv = aktiv;
        else if (_objectFilterMode === 'archiv') matchAktiv = !aktiv;
        r.style.display = 'none';
        if (matchText && matchKunde && matchAktiv) vis.push(r);
    });
    const _p = hmsPagerDef('objPager', 'Objekte', () => filterObjects());
    const pg = hmsPage(vis, _p.st, vis.length ? (vis[0].dataset.mk || '') : '');
    pg.rows.forEach(r => { r.style.display = ''; });
    hmsPagerFill('objPager', pg);
}

// ============================================================
// OBJECT modal — physical location only
// ============================================================
async function openObjectModal(id = null) {
    let obj = {};
    let articles = [];
    if (id) {
        obj = await apiGet(API.objects + '?id=' + id);
        articles = Array.isArray(obj.artikel) ? obj.artikel : [];
    }

    const allCustomers = await apiGet(API.customers);
    const activeCustomers = (Array.isArray(allCustomers) ? allCustomers : []).filter(c => c.aktiv == 1);
    const isSel = (kid) => String(obj.kunden_id) === String(kid) ? 'selected' : '';
    const customerOpts = activeCustomers.map(c => `<md-select-option value="${c.kunde_id}" ${isSel(c.kunde_id)}><div slot="headline">${c.kunde_id} – ${escapeHtml(c.kundenname)}</div></md-select-option>`).join('');
    let extraOpt = '';
    if (obj.kunden_id && !activeCustomers.find(c => String(c.kunde_id) === String(obj.kunden_id))) {
        const allArr = Array.isArray(allCustomers) ? allCustomers : [];
        const inactiveCust = allArr.find(c => String(c.kunde_id) === String(obj.kunden_id));
        if (inactiveCust) extraOpt = `<md-select-option value="${inactiveCust.kunde_id}" selected><div slot="headline">${inactiveCust.kunde_id} – ${escapeHtml(inactiveCust.kundenname)} (inaktiv)</div></md-select-option>`;
    }

    const articlesSection = id ? `
        <div class="objekt-artikel-section">
            <div class="objekt-artikel-header">
                <div class="customer-form-section-title" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;">
                    ${custIcon('sell')} Artikel / Leistungen
                    <span class="objekt-artikel-count">${articles.length}</span>
                </div>
                <md-filled-tonal-button onclick="openArtikelModal('${id}')"><md-icon slot="icon">add</md-icon>Neuer Artikel</md-filled-tonal-button>
            </div>
            <div class="objekt-info-box">
                ${custIcon('info')}
                <span>Ein Objekt ist ein physischer Standort. Verschiedene Leistungen (Sicherheit, Reinigung, Reparatur…) werden als Artikel hinterlegt — jeweils mit eigenem Modell und Preis.</span>
            </div>
            ${articles.length === 0 ? `
                <div class="empty-state" style="padding:32px 16px">
                    ${custIcon('sell')}
                    <p>Noch keine Artikel angelegt</p>
                </div>
            ` : `
                <div class="data-table-card" style="margin-top: 8px;">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Artikel-ID</th>
                                    <th>Bezeichnung</th>
                                    <th>Bereich</th>
                                    <th>Modell</th>
                                    <th class="text-right">Preis Kunde</th>
                                    <th class="text-right">NU-Preis</th>
                                    <th class="text-right">Gewinn</th>
                                    <th>Status</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${articles.map(a => `<tr>
                                    <td><strong>${a.artikel_id}</strong></td>
                                    <td>${escapeHtml(a.artikel_name)}</td>
                                    <td>${a.bereich ? bereichBadgeHtml(a.bereich) : '–'}${typeof bereicheBadgesHtml === 'function' && a.arten ? ' ' + bereicheBadgesHtml(null, a.arten) : ''}</td>
                                    <td>${a.modell || '–'}</td>
                                    <td class="text-right">${formatCurrency(a.preis_kunde)}</td>
                                    <td class="text-right">${formatCurrency(a.nu_preis)}</td>
                                    <td class="text-right fw-bold ${parseFloat(a.gewinn) >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(a.gewinn)}</td>
                                    <td>${a.aktiv == 1 ? '<span class="status status-ja">Aktiv</span>' : '<span class="status status-nein">Inaktiv</span>'}</td>
                                    <td>
                                        <div class="cust-row-actions">
                                            <md-icon-button onclick="openArtikelModal('${id}','${a.artikel_id}')" title="Bearbeiten"><md-icon>edit</md-icon></md-icon-button>
                                            <md-icon-button class="${a.aktiv == 1 ? 'warn' : 'danger'}" onclick="deleteArtikel('${a.artikel_id}','${id}')" title="${a.aktiv == 1 ? 'Deaktivieren' : 'Endgültig löschen'}"><md-icon>${a.aktiv == 1 ? 'block' : 'delete'}</md-icon></md-icon-button>
                                        </div>
                                    </td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `}
        </div>
    ` : `
        <div class="objekt-info-box" style="margin-top:16px">
            ${custIcon('info')}
            <span>Nach dem Anlegen können Sie Artikel/Leistungen für dieses Objekt hinzufügen.</span>
        </div>
    `;

    const bundeslandOpts = [
        ['BW','Baden-Württemberg'],['BY','Bayern'],['BE','Berlin'],['BB','Brandenburg'],
        ['HB','Bremen'],['HH','Hamburg'],['HE','Hessen'],['MV','Mecklenburg-Vorpommern'],
        ['NI','Niedersachsen'],['NW','Nordrhein-Westfalen'],['RP','Rheinland-Pfalz'],
        ['SL','Saarland'],['SN','Sachsen'],['ST','Sachsen-Anhalt'],['SH','Schleswig-Holstein'],['TH','Thüringen'],
    ].map(([code, name]) => `<md-select-option value="${code}" ${obj.bundesland === code ? 'selected' : ''}><div slot="headline">${name}</div></md-select-option>`).join('');

    const body = `
        <div class="customer-form-layout">
            <!-- Left Column: Objektdaten -->
            <div class="customer-form-col">
                <div class="customer-form-section-title">
                    ${custIcon('location_on')} Objektdaten
                </div>

                <div class="form-group">
                    <md-outlined-select id="f_obj_kunden_id" label="Kunde" required onchange="objKundeChanged()">
                        <md-select-option value="" ${obj.kunden_id ? '' : 'selected'}><div slot="headline">— Kunde wählen —</div></md-select-option>
                        ${customerOpts}${extraOpt}
                    </md-outlined-select>
                </div>

                <div class="customer-form-row">
                    <div class="form-group">
                        <md-outlined-text-field id="f_obj_nr_kunde" label="Obj-Nr. Kunde (AST-Nr.)" value="${escapeHtml(obj.obj_nr_kunde || '')}" placeholder="Vom Kunden vergeben"></md-outlined-text-field>
                    </div>
                    <div class="form-group">
                        <md-outlined-text-field id="f_obj_nr_intern" label="Obj-Nr. intern" value="${escapeHtml(obj.obj_nr_intern || '')}" placeholder="Auto"></md-outlined-text-field>
                    </div>
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_objektname" label="Objektname" value="${escapeHtml(obj.objektname || '')}" required placeholder="z.B. Bürohaus Berlin Mitte"></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <label style="display:block;font-size:0.8rem;color:var(--gray-600);margin-bottom:4px">Einsatzart (Mehrfachauswahl)</label>
                    <div id="f_obj_arten" style="padding:4px 0"></div>
                    <small style="display:block;margin-top:2px;font-size:.75rem;color:var(--gray-500)"><span class="material-symbols-outlined" style="font-size:13px;vertical-align:-2px">tune</span> Liste bearbeiten: Konto &amp; Firma → Sicherheitsbereich → Einsatzarten</small>
                </div>

                <div class="form-group" style="position:relative">
                    <md-outlined-text-field id="f_adresse" label="Adresse" value="${escapeHtml(obj.adresse || '')}" placeholder="Musterstraße 12" oninput="objAdresseInput(event)" onfocusout="objAdresseHide()"></md-outlined-text-field>
                    <div id="f_adresse_suggest" class="obj-adr-suggest" style="display:none"></div>
                    <style>
                      .obj-adr-suggest{position:absolute;left:0;right:0;top:100%;z-index:60;background:var(--white);border:1px solid var(--gray-200);border-radius:8px;box-shadow: 0 10px 28px color-mix(in srgb, var(--md-sys-color-primary) 14%, transparent);max-height:240px;overflow:auto;margin-top:4px}
                      .obj-adr-item{padding:8px 10px;cursor:pointer;border-bottom:1px solid var(--gray-200);font-size:.85rem;display:flex;align-items:flex-start;gap:8px;color:var(--gray-700);line-height:1.3}
                      .obj-adr-item:last-child{border-bottom:0}
                      .obj-adr-item:hover{background: color-mix(in srgb, var(--accent, var(--md-sys-color-primary)) 10%, transparent)}
                      .obj-adr-empty{padding:8px 10px;font-size:.8rem;color:var(--gray-400)}
                    </style>
                </div>

                <div class="customer-form-row">
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_plz" label="PLZ" value="${escapeHtml(obj.plz || '')}" placeholder="10115"></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:2">
                        <md-outlined-text-field id="f_stadt" label="Stadt" value="${escapeHtml(obj.stadt || '')}" placeholder="Berlin"></md-outlined-text-field>
                    </div>
                </div>
                <div class="form-group">
                    <md-outlined-select id="f_bundesland" label="Bundesland für Feiertage" required>
                        <md-select-option value="" ${obj.bundesland ? '' : 'selected'}><div slot="headline">— Bundesland wählen —</div></md-select-option>
                        ${bundeslandOpts}
                    </md-outlined-select>
                </div>

                <div class="customer-form-section-title" style="margin-top:14px;">
                    ${custIcon('settings')} Status
                </div>
                <div class="form-group">
                    <md-outlined-select id="f_obj_aktiv" label="Aktiv">
                        <md-select-option value="1" ${obj.aktiv == 1 || !id ? 'selected' : ''}><div slot="headline">Ja — aktiv</div></md-select-option>
                        <md-select-option value="0" ${obj.aktiv == 0 && id ? 'selected' : ''}><div slot="headline">Nein — inaktiv</div></md-select-option>
                    </md-outlined-select>
                </div>
            </div>

            <!-- Right Column: Kontakt + Hinweise -->
            <div class="customer-form-col">
                <div class="customer-form-section-title">
                    ${custIcon('contacts')} Kontakt vor Ort
                </div>

                <div class="form-group" style="position:relative">
                    <md-outlined-text-field id="f_ansprechpartner" label="Ansprechpartner" value="${escapeHtml(obj.ansprechpartner || '')}" placeholder="Max Mustermann"
                        oninput="objKontaktInput(event)" onfocus="objKontaktInput(event)" onblur="objKontaktHide()" onkeydown="objKontaktKeydown(event)">
                        <md-icon slot="trailing-icon" title="Vorschläge aus anderen Objekten">person_search</md-icon>
                    </md-outlined-text-field>
                    <div id="f_kontakt_suggest" class="obj-adr-suggest" style="display:none"></div>
                </div>

                <div class="form-group">
                    <md-outlined-text-field id="f_kontakt_telefon" label="Telefon" value="${escapeHtml(obj.kontakt_telefon || '')}" placeholder="+49 30 1234567"><md-icon slot="trailing-icon">call</md-icon></md-outlined-text-field>
                </div>

                <div class="form-group">
                    <md-outlined-text-field type="email" id="f_kontakt_email" label="E-Mail" value="${escapeHtml(obj.kontakt_email || '')}" placeholder="kontakt@objekt.de"><md-icon slot="trailing-icon">alternate_email</md-icon></md-outlined-text-field>
                </div>

                <div class="customer-form-section-title" style="margin-top:14px;">
                    ${custIcon('sticky_note_2')} Hinweise
                </div>
                <div class="form-group">
                    <md-outlined-text-field type="textarea" rows="4" id="f_hinweise" label="Bemerkungen" value="${escapeHtml(obj.hinweise || '')}" placeholder="Zugang, Schlüsselübergabe, besondere Anweisungen…"></md-outlined-text-field>
                </div>
                <div class="form-group">
                    <md-outlined-text-field type="textarea" rows="4" id="f_bestellbeschreibung" label="Bestellbeschreibung" value="${escapeHtml(obj.bestellbeschreibung || '')}" placeholder="Beschreibung für Bestellungen zu diesem Objekt…"></md-outlined-text-field>
                </div>
            </div>
        </div>

        ${articlesSection}
    `;

    const footer = `
        <md-text-button onclick="closeObjectsDialog()">Schließen</md-text-button>
        <md-filled-button onclick="saveObject('${id || ''}')"><md-icon slot="icon">save</md-icon>${id ? 'Aktualisieren' : 'Erstellen'}</md-filled-button>
    `;
    openObjectsDialog(id ? 'Objekt bearbeiten — ' + (obj.obj_id || '') : 'Neues Objekt anlegen', body, footer, true);

    // Stored arten survive a Kunde switch — only the pickable options narrow.
    let _storedArten = obj.arten;
    if (typeof _storedArten === 'string') { try { _storedArten = JSON.parse(_storedArten) || []; } catch { _storedArten = []; } }
    if (!Array.isArray(_storedArten)) _storedArten = [];
    _objArtStored = _storedArten.length ? _storedArten : (obj.art ? [obj.art] : []);
    _objArtStoredPrimary = obj.art || null;
    // The object carries NO editable Hauptbereich field — its bereich/bereiche
    // silently follow the selected Kunde (they still drive the Bereich scope).
    _objBereichStored = (typeof bereichTagsOf === 'function')
        ? bereichTagsOf(obj)
        : (obj.bereiche || (obj.bereich ? [obj.bereich] : []));
    _objBereichStoredPrimary = obj.bereich || null;
    _objKundeTags = [];
    _objKundePrimary = null;

    // Objektart options come ONLY from the Kunde cascade (subs_for_kunde).
    // No global render here: a pending global render resolving after the
    // narrowed one would leak subs of unrelated mains into the picker.
    if (obj.kunden_id) {
        // Pass the id explicitly — md-outlined-select's .value is not yet
        // populated this early (custom element upgrade), so reading the DOM
        // here would see an empty Kunde and skip the cascade.
        objKundeChanged(true, obj.kunden_id);
    } else {
        objRenderAllArten();
    }
}

// No Kunde chosen yet: the full Einsatzart catalog. Rendered only while
// the Kunde field is still empty (a Kunde picked meanwhile wins — its
// narrowed list must not be overwritten by this late render).
async function objRenderAllArten() {
    if (typeof renderArtenChecks !== 'function') return;
    const subs = typeof getBereichSubSuggestions === 'function' ? await getBereichSubSuggestions() : [];
    if (document.getElementById('f_obj_kunden_id')?.value) return;
    renderArtenChecks('f_obj_arten', _objArtStored, _objArtStoredPrimary, subs);
}

/* Customer → Bereich cascade: picking a Kunde adopts his Dienstleistungen
   and narrows the Objektart dropdown to subs under the customer's mains
   (scope-filtered server-side). The object's own STORED arten always stay
   selected — they are real data, only newly pickable options narrow. */
let _objArtStored = [];
let _objArtStoredPrimary = null;
let _objBereichStored = [];
let _objBereichStoredPrimary = null;
let _objKundeTags = [];
let _objKundePrimary = null;

async function objKundeChanged(init = false, kidParam = null) {
    const kid = kidParam ?? document.getElementById('f_obj_kunden_id')?.value;
    if (!kid) {
        _objKundeTags = [];
        _objKundePrimary = null;
        objRenderAllArten();
        return;
    }
    let kunde = null;
    try { kunde = await apiGet(API.customers + '?id=' + encodeURIComponent(kid)); } catch (e) { return; }
    if (kunde && kunde.data) kunde = kunde.data;
    _objKundeTags = (typeof bereichTagsOf === 'function') ? bereichTagsOf(kunde || {}) : [];
    _objKundePrimary = kunde?.bereich || null;
    let subs = [];
    try { subs = await apiGet(API.objects + '/arten?subs_for_kunde=' + encodeURIComponent(kid)); } catch (e) {}
    if (!Array.isArray(subs)) subs = [];
    const storedLower = new Set(_objArtStored.map(v => String(v).trim().toLowerCase()));
    const subsLower = new Set(subs.map(v => String(v).trim().toLowerCase()));
    const keep = init
        ? _objArtStored
        : collectArtenChecks('f_obj_arten').filter(v =>
            storedLower.has(String(v).trim().toLowerCase()) || subsLower.has(String(v).trim().toLowerCase()));
    if (typeof renderArtenChecks === 'function') {
        renderArtenChecks('f_obj_arten', keep, _objArtStoredPrimary, subs);
    }
}
window.objKundeChanged = objKundeChanged;

async function saveObject(id, opts = {}) {
    const data = {
        kunden_id:        document.getElementById('f_obj_kunden_id').value,
        obj_nr_kunde:     document.getElementById('f_obj_nr_kunde').value || null,
        obj_nr_intern:    document.getElementById('f_obj_nr_intern').value || null,
        objektname:       document.getElementById('f_objektname').value,
        art:              (typeof mselPrimary === 'function') ? mselPrimary('f_obj_arten') : null,
        adresse:          document.getElementById('f_adresse').value || null,
        plz:              document.getElementById('f_plz').value || null,
        stadt:            document.getElementById('f_stadt').value || null,
        bundesland:       document.getElementById('f_bundesland')?.value || null,
        ansprechpartner:  document.getElementById('f_ansprechpartner').value || null,
        kontakt_telefon:  document.getElementById('f_kontakt_telefon').value || null,
        kontakt_email:    document.getElementById('f_kontakt_email').value || null,
        hinweise:         document.getElementById('f_hinweise').value || null,
        bestellbeschreibung: document.getElementById('f_bestellbeschreibung')?.value || null,
        arten:            (typeof collectArtenChecks === 'function') ? collectArtenChecks('f_obj_arten') : [],
        aktiv:            document.getElementById('f_obj_aktiv').value,
    };
    // Hauptbereich has no UI on objects — it follows the Kunde. Customer
    // tags win; the object's stored tags survive only when the customer
    // carries none (legacy records). Primary prefers the stored value,
    // then the customer's primary, then the first tag.
    const bereiche = (_objKundeTags.length ? _objKundeTags : _objBereichStored).slice();
    const inTags = v => v && bereiche.some(t => String(t).trim().toLowerCase() === String(v).trim().toLowerCase());
    data.bereich = inTags(_objBereichStoredPrimary) ? _objBereichStoredPrimary
        : (inTags(_objKundePrimary) ? _objKundePrimary : (bereiche[0] || null));
    data.bereiche = bereiche;
    // All fields are mandatory when CREATING a new object — collect every
    // missing one so the user sees the full list instead of fixing them
    // one toast at a time. Updates stay lenient (legacy objects may lack
    // the newer fields, and silent auto-saves must not break).
    if (!id) {
    const requiredFields = [
        ['f_obj_kunden_id',   'Kunde'],
        ['f_objektname',      'Objektname'],
        ['f_adresse',         'Adresse'],
        ['f_plz',             'PLZ'],
        ['f_stadt',           'Stadt'],
        ['f_bundesland',      'Bundesland'],
        ['f_ansprechpartner', 'Ansprechpartner'],
        ['f_kontakt_telefon', 'Telefon'],
        ['f_kontakt_email',   'E-Mail'],
        ['f_hinweise',        'Bemerkungen'],
    ];
    const missing = requiredFields.filter(([fid]) => {
        const el = document.getElementById(fid);
        const empty = !el || !String(el.value || '').trim();
        if (el) { try { el.error = empty; } catch (_) {} }
        return empty;
    }).map(([, label]) => label);
    if (typeof collectArtenChecks === 'function' && !collectArtenChecks('f_obj_arten').length) {
        missing.push('Objektart');
    }
    if (missing.length) {
        showToast('Pflichtfelder fehlen: ' + missing.join(', '), 'error');
        return;
    }
    }

    if (id) data.obj_id = id;
    const result = id ? await apiPut(API.objects + '/' + id, data) : await apiPost(API.objects, data);
    if (result.success) {
        if (id) {
            if (!opts.silent) showToast('Objekt aktualisiert');
        } else {
            showToast('Objekt erstellt — bitte Artikel/Leistungen anlegen');
            // Re-open in edit mode so user can immediately add articles
            await openObjectModal(result.obj_id);
            renderObjects();
            return;
        }
        if (!opts.silent) {
            closeObjectsDialog();
            renderObjects();
        }
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function deactivateObject(id) {
    if (!await mdConfirm('Objekt deaktivieren?\n\nAlle zugehörigen Artikel werden ebenfalls deaktiviert.', { title: 'Objekt deaktivieren', confirmLabel: 'Deaktivieren' })) return;
    const result = await apiDelete(API.objects + '/' + id);
    if (result.success) { showToast('Objekt deaktiviert'); renderObjects(); }
    else showToast(result.error || 'Fehler', 'error');
}

async function deleteObject(id) {
    if (!await mdConfirm('Objekt „' + id + '“ endgültig löschen?\n\nAlle zugehörigen Artikel, Bestellungen, Einsätze und Preishistorie werden unwiderruflich gelöscht!', { title: 'Endgültig löschen', confirmLabel: 'Löschen' })) return;
    const result = await apiDelete(API.objects + '/' + id, { permanent: true });
    if (result.success) {
        showToast('Objekt endgültig gelöscht');
        renderObjects();
    } else if (result.blocked_shifts || result.eingang_id) {
        mdAlert(escapeHtml(result.error || 'Fehler beim Löschen') + (Array.isArray(result.blocked_shifts) && result.blocked_shifts.length ? '<br><br>&bull; ' + result.blocked_shifts.map(escapeHtml).join('<br>&bull; ') : ''), 'Löschen nicht möglich');
    } else {
        showToast(result.error || 'Fehler beim Löschen', 'error');
    }
}

// ============================================================
// ARTIKEL modals & helpers
// ============================================================
async function openArtikelManager(objId) {
    // Reuse the object edit modal — articles section is right there
    await openObjectModal(objId);
}

// Default Zuschläge for new Artikel — standard rates in the German
// security & cleaning industries.
const _zuschlagDefaults = {
    zuschlaege_aktiv:             0,
    zuschlag_tag_pct:             0.00,
    zuschlag_nacht_pct:           10.00,
    zuschlag_sonntag_pct:         25.00,
    zuschlag_feiertag_pct:        50.00,
    zuschlag_nacht_sonntag_pct:   35.00,
    zuschlag_nacht_feiertag_pct:  60.00,
    nacht_von:                    '22:00',
    nacht_bis:                    '06:00',
};

async function openArtikelModal(objId, artikelId = null) {
    let art = { obj_id: objId, modell: 'Pro Einsatz', preis_kunde: 0, nu_preis: 0, aktiv: 1, ..._zuschlagDefaults };
    if (artikelId) {
        const fetched = await apiGet(API.artikel + '/' + artikelId);
        if (fetched && !fetched.error) art = { ..._zuschlagDefaults, ...art, ...fetched };
    }
    // Cascade: the parent object's customer drives both pickers — Bereich
    // is limited to the object's mains, Unterbereiche to their catalog subs.
    let parentObj = null;
    try { parentObj = await apiGet(API.objects + '?id=' + encodeURIComponent(objId)); } catch (e) {}
    // Mains for the Bereich picker: the object's own tags, but legacy rows
    // may have bereich NULL — then the CUSTOMER's tags are authoritative
    // (objects silently inherit them on save).
    let artMains = (typeof bereichTagsOf === 'function') ? bereichTagsOf(parentObj || {}) : [];
    if (parentObj && parentObj.kunden_id) {
        try {
            let kunde = await apiGet(API.customers + '?id=' + encodeURIComponent(parentObj.kunden_id));
            if (kunde && kunde.data) kunde = kunde.data;
            const kTags = (typeof bereichTagsOf === 'function') ? bereichTagsOf(kunde || {}) : [];
            if (kTags.length) artMains = kTags;
        } catch (e) {}
    }
    const modellOpts = _modellOptions.map(m => `<md-select-option value="${m}" ${art.modell === m ? 'selected' : ''}><div slot="headline">${m}</div></md-select-option>`).join('');
    // Einsatzart → platform service type (Konto & Firma → Sicherheitsbereich);
    // the type is derived and only shown, never picked here.
    await _artLoadLeistungMap();
    _artObjArten = (() => { let a = parentObj && parentObj.arten; if (typeof a === 'string') { try { a = JSON.parse(a); } catch { a = []; } } return Array.isArray(a) ? a : []; })();

    // Normalize HH:MM:SS → HH:MM for the input[type=time]
    const normalizeTime = (t) => (typeof t === 'string' ? t.substring(0, 5) : '22:00');

    const body = `
        <div class="acc-grid acc-grid-2">
            <div class="form-group acc-span-2">
                <md-outlined-text-field id="f_art_name" label="Bezeichnung" value="${escapeHtml(art.artikel_name || '')}" required placeholder="z.B. Objektschutz Haupteingang, Empfangsdienst, Revierdienst Nacht…"></md-outlined-text-field>
            </div>
            <div class="form-group acc-span-2">
                <label style="display:block;font-size:0.8rem;color:var(--gray-600);margin-bottom:4px">Einsatzart * (Mehrfachauswahl)</label>
                <div id="f_art_arten" style="padding:4px 0" onchange="_artUpdateLeistungHint()"></div>
                <div id="f_art_leistung_hint" class="art-leistung-hint"></div>
                <style>
                    .art-leistung-hint{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:.8rem;color:var(--gray-600);margin-top:4px;min-height:1px}
                    .art-leistung-hint .material-symbols-outlined{font-size:16px;color: var(--accent,var(--md-sys-color-primary))}
                    .art-lh-src{color:var(--gray-400)}
                    .art-lh-badge{font-size:.7rem;font-weight:600;padding:1px 8px;border-radius:999px;background:var(--gray-100);color:var(--gray-700);cursor:help}
                    .art-lh-sk{background: var(--md-ext-color-warning-container);color: var(--md-ext-color-on-warning-container)}
                </style>
            </div>
            <div class="form-group">
                <md-outlined-select id="f_art_modell" label="Abrechnungsmodell" onchange="recalcZuschlagPreview()">${modellOpts}</md-outlined-select>
            </div>
            <div class="form-group">
                <md-outlined-text-field type="number" step="0.01" min="0" id="f_art_preis_kunde" label="Preis Kunde (€) — Grundpreis" value="${art.preis_kunde || 0}" oninput="recalcZuschlagPreview()"></md-outlined-text-field>
            </div>
            <div class="form-group">
                <md-outlined-text-field type="number" step="0.01" min="0" id="f_art_nu_preis" label="NU-Preis (€)" value="${art.nu_preis || 0}"></md-outlined-text-field>
            </div>
            <div class="form-group">
                <md-outlined-text-field id="f_art_einheit" label="Einheit (optional)" value="${escapeHtml(art.einheit || '')}" placeholder="z.B. Stunde, Pauschale, Einsatz"></md-outlined-text-field>
            </div>
            <div class="form-group">
                <md-outlined-select id="f_art_aktiv" label="Aktiv">
                    <md-select-option value="1" ${art.aktiv == 1 || !artikelId ? 'selected' : ''}><div slot="headline">Ja</div></md-select-option>
                    <md-select-option value="0" ${art.aktiv == 0 && artikelId ? 'selected' : ''}><div slot="headline">Nein</div></md-select-option>
                </md-outlined-select>
            </div>
            ${artikelId ? `<div class="form-group acc-span-2">
                <md-outlined-text-field id="f_art_grund" label="Grund der Änderung (bei Preisänderung)" placeholder="z.B. Preisanpassung 2026"></md-outlined-text-field>
            </div>` : ''}
        </div>

        <!-- =========== ZUSCHLÄGE / SCHICHTAUFSCHLÄGE =========== -->
        <div class="zuschlag-section">
            <div class="zuschlag-header">
                <div class="zuschlag-title">
                    ${custIcon('bedtime')}
                    <div>
                        <div class="zuschlag-title-main">Schicht- &amp; Feiertagszuschläge</div>
                        <div class="zuschlag-title-sub">Automatische Berechnung der Aufschläge auf den Grundpreis nach deutschem Marktstandard</div>
                    </div>
                </div>
                <label class="zuschlag-toggle">
                    <md-switch id="f_art_zuschlaege_aktiv" ${art.zuschlaege_aktiv == 1 ? 'selected' : ''} onchange="toggleZuschlagSection()"></md-switch>
                    <span class="zuschlag-toggle-label">Aktivieren</span>
                </label>
            </div>

            <div class="zuschlag-body" id="zuschlagBody">
                <div class="zuschlag-night-window">
                    <span class="zuschlag-night-label">${custIcon('schedule')} Definition Nachtdienst:</span>
                    <md-outlined-text-field type="text" lang="de-DE" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="form-control zuschlag-time t24" id="f_art_nacht_von" value="${normalizeTime(art.nacht_von)}" oninput="recalcZuschlagPreview()"></md-outlined-text-field>
                    <span class="zuschlag-night-arrow">→</span>
                    <md-outlined-text-field type="text" lang="de-DE" inputmode="numeric" maxlength="5" placeholder="HH:MM" class="form-control zuschlag-time t24" id="f_art_nacht_bis" value="${normalizeTime(art.nacht_bis)}" oninput="recalcZuschlagPreview()"></md-outlined-text-field>
                    <span class="zuschlag-night-hint">(Standard: 22:00 – 06:00 Uhr nach § 6 Abs. 1 ArbZG)</span>
                </div>

                <div class="zuschlag-table-wrap">
                    <table class="zuschlag-table">
                        <thead>
                            <tr>
                                <th class="zt-col-art">Schichtart</th>
                                <th class="zt-col-pct">Zuschlag</th>
                                <th class="zt-col-auf">Aufschlag</th>
                                <th class="zt-col-end">Endpreis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="zt-row zt-row-base" data-key="tag">
                                <td><span class="zt-icon">${custIcon('light_mode')}</span><strong>Tagdienst Mo–Sa</strong> <span class="zt-tag">Grundpreis</span></td>
                                <td class="text-right">
                                    <div class="zt-pct-input">
                                        <md-outlined-text-field type="number" step="0.5" min="0" max="200" class="form-control" id="f_art_z_tag" value="${parseFloat(art.zuschlag_tag_pct || 0).toFixed(2)}" oninput="recalcZuschlagPreview()"></md-outlined-text-field>
                                        <span>%</span>
                                    </div>
                                </td>
                                <td class="text-right zt-mono zt-aufschlag" data-key="tag">–</td>
                                <td class="text-right zt-mono zt-end" data-key="tag">–</td>
                            </tr>
                            <tr class="zt-row" data-key="nacht">
                                <td><span class="zt-icon">${custIcon('bedtime')}</span><strong>Nachtdienst</strong> <span class="zt-tag" id="zt-nachtwindow">22:00 – 06:00 Uhr</span></td>
                                <td class="text-right">
                                    <div class="zt-pct-input">
                                        <md-outlined-text-field type="number" step="0.5" min="0" max="200" class="form-control" id="f_art_z_nacht" value="${parseFloat(art.zuschlag_nacht_pct).toFixed(2)}" oninput="recalcZuschlagPreview()"></md-outlined-text-field>
                                        <span>%</span>
                                    </div>
                                </td>
                                <td class="text-right zt-mono zt-aufschlag" data-key="nacht">–</td>
                                <td class="text-right zt-mono zt-end" data-key="nacht">–</td>
                            </tr>
                            <tr class="zt-row" data-key="sonntag">
                                <td><span class="zt-icon">${custIcon('event')}</span><strong>Sonntag</strong></td>
                                <td class="text-right">
                                    <div class="zt-pct-input">
                                        <md-outlined-text-field type="number" step="0.5" min="0" max="200" class="form-control" id="f_art_z_sonntag" value="${parseFloat(art.zuschlag_sonntag_pct).toFixed(2)}" oninput="recalcZuschlagPreview()"></md-outlined-text-field>
                                        <span>%</span>
                                    </div>
                                </td>
                                <td class="text-right zt-mono zt-aufschlag" data-key="sonntag">–</td>
                                <td class="text-right zt-mono zt-end" data-key="sonntag">–</td>
                            </tr>
                            <tr class="zt-row" data-key="feiertag">
                                <td><span class="zt-icon">${custIcon('star')}</span><strong>Feiertag</strong> <span class="zt-tag">gesetzlich</span></td>
                                <td class="text-right">
                                    <div class="zt-pct-input">
                                        <md-outlined-text-field type="number" step="0.5" min="0" max="200" class="form-control" id="f_art_z_feiertag" value="${parseFloat(art.zuschlag_feiertag_pct).toFixed(2)}" oninput="recalcZuschlagPreview()"></md-outlined-text-field>
                                        <span>%</span>
                                    </div>
                                </td>
                                <td class="text-right zt-mono zt-aufschlag" data-key="feiertag">–</td>
                                <td class="text-right zt-mono zt-end" data-key="feiertag">–</td>
                            </tr>
                            <tr class="zt-row zt-row-combo" data-key="nacht_sonntag">
                                <td><span class="zt-icon">${custIcon('bedtime')}${custIcon('add')}${custIcon('event')}</span><strong>Nacht + Sonntag</strong> <span class="zt-tag">kombiniert</span></td>
                                <td class="text-right">
                                    <div class="zt-pct-input">
                                        <md-outlined-text-field type="number" step="0.5" min="0" max="200" class="form-control" id="f_art_z_nachtsonntag" value="${parseFloat(art.zuschlag_nacht_sonntag_pct).toFixed(2)}" oninput="recalcZuschlagPreview()"></md-outlined-text-field>
                                        <span>%</span>
                                    </div>
                                </td>
                                <td class="text-right zt-mono zt-aufschlag" data-key="nacht_sonntag">–</td>
                                <td class="text-right zt-mono zt-end" data-key="nacht_sonntag">–</td>
                            </tr>
                            <tr class="zt-row zt-row-combo" data-key="nacht_feiertag">
                                <td><span class="zt-icon">${custIcon('bedtime')}${custIcon('add')}${custIcon('star')}</span><strong>Nacht + Feiertag</strong> <span class="zt-tag">kombiniert</span></td>
                                <td class="text-right">
                                    <div class="zt-pct-input">
                                        <md-outlined-text-field type="number" step="0.5" min="0" max="200" class="form-control" id="f_art_z_nachtfeiertag" value="${parseFloat(art.zuschlag_nacht_feiertag_pct).toFixed(2)}" oninput="recalcZuschlagPreview()"></md-outlined-text-field>
                                        <span>%</span>
                                    </div>
                                </td>
                                <td class="text-right zt-mono zt-aufschlag" data-key="nacht_feiertag">–</td>
                                <td class="text-right zt-mono zt-end" data-key="nacht_feiertag">–</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="zuschlag-foot-info">
                    ${custIcon('info')}
                    <div>
                        <strong>Wichtig:</strong> Diese Aufschläge werden <em>automatisch</em> auf jeden Einsatz angewendet basierend auf Datum + Uhrzeit. Bestätigte Einsätze behalten ihren ursprünglichen Aufschlag bei späteren Tarifänderungen, damit historische Auswertungen stabil bleiben.
                    </div>
                </div>
            </div>
        </div>
    `;

    const footer = `
        <md-text-button onclick="openObjectModal('${objId}')"><md-icon slot="icon">arrow_back</md-icon>Zurück</md-text-button>
        <md-filled-button onclick="saveArtikel('${objId}','${artikelId || ''}')"><md-icon slot="icon">save</md-icon>${artikelId ? 'Aktualisieren' : 'Erstellen'}</md-filled-button>
    `;
    openObjectsDialog(artikelId ? 'Artikel bearbeiten' : 'Neuer Artikel', body, footer, true);
    _artSubParents = {};
    _artMains = artMains;
    _artBereichStored = art.bereich || null;
    if (typeof renderArtenChecks === 'function') {
        let stored = art.arten;
        if (typeof stored === 'string') { try { stored = JSON.parse(stored) || []; } catch { stored = []; } }
        if (!Array.isArray(stored)) stored = [];
        if (parentObj && parentObj.kunden_id) {
            apiGet(API.objects + '/arten?subs_for_kunde=' + encodeURIComponent(parentObj.kunden_id) + '&with_parents=1')
                .then(subs => {
                    const names = [];
                    (Array.isArray(subs) ? subs : []).forEach(s => {
                        const n = (typeof s === 'string') ? s : s.name;
                        if (!n) return;
                        names.push(n);
                        if (s && s.parent) _artSubParents[String(n).trim().toLowerCase()] = s.parent;
                    });
                    return renderArtenChecks('f_art_arten', stored, null, names);
                })
                .catch(() => renderArtenChecks('f_art_arten', stored))
                .then(_artUpdateLeistungHint);
        } else {
            renderArtenChecks('f_art_arten', stored).then(_artUpdateLeistungHint);
        }
    }

    // Render the live preview right away.
    toggleZuschlagSection();
    recalcZuschlagPreview();
}

/**
 * Live recompute Aufschlag (€) and Endpreis (€/Std) for every Schichtart
 * based on the current Grundpreis + percentages. Updates the table in
 * place — zero re-render cost.
 */
function recalcZuschlagPreview() {
    const grundpreis = parseFloat(document.getElementById('f_art_preis_kunde')?.value) || 0;
    const fmt = (v) => v.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    const model = document.getElementById('f_art_modell')?.value || 'Pro Stunde';
    const suffix = model === 'Pro Einsatz' ? '/Einsatz' : (model === 'Monatspauschale' ? '/Monat' : '/Std.');
    const baseEnd = document.querySelector('.zt-end[data-base="true"]');
    if (baseEnd) baseEnd.textContent = grundpreis > 0 ? `${fmt(grundpreis)}${suffix}` : '–';

    // Update the night-window display under the Nachtdienst row.
    const von = document.getElementById('f_art_nacht_von')?.value || '22:00';
    const bis = document.getElementById('f_art_nacht_bis')?.value || '06:00';
    const nw = document.getElementById('zt-nachtwindow');
    if (nw) nw.textContent = `${von} – ${bis} Uhr`;

    const map = [
        { key: 'tag',             inputId: 'f_art_z_tag' },
        { key: 'nacht',           inputId: 'f_art_z_nacht' },
        { key: 'sonntag',         inputId: 'f_art_z_sonntag' },
        { key: 'feiertag',        inputId: 'f_art_z_feiertag' },
        { key: 'nacht_sonntag',   inputId: 'f_art_z_nachtsonntag' },
        { key: 'nacht_feiertag',  inputId: 'f_art_z_nachtfeiertag' },
    ];

    map.forEach(({ key, inputId }) => {
        const pct = parseFloat(document.getElementById(inputId)?.value) || 0;
        const aufschlag = grundpreis * (pct / 100);
        const endpreis  = grundpreis + aufschlag;

        const aufCell = document.querySelector(`.zt-aufschlag[data-key="${key}"]`);
        const endCell = document.querySelector(`.zt-end[data-key="${key}"]`);
        if (aufCell) aufCell.textContent = (grundpreis > 0 && pct > 0) ? `+ ${fmt(aufschlag)}` : '–';
        if (endCell) endCell.textContent = (grundpreis > 0) ? `${fmt(endpreis)}${suffix}` : '–';
    });
}

/**
 * Show / hide the Zuschläge body when the user toggles the master switch.
 * Also greys out percentage inputs to make it visually obvious that they
 * have no effect when the toggle is off.
 */
function toggleZuschlagSection() {
    const sw   = document.getElementById('f_art_zuschlaege_aktiv');
    const body = document.getElementById('zuschlagBody');
    if (!sw || !body) return;
    // md-switch exposes `selected`, not `checked`
    body.classList.toggle('zuschlag-disabled', !sw.selected);
}

let _artSubParents = {};
let _artMains = [];
let _artBereichStored = null;
// lowercased Einsatzart name → platform service type {name, sachkunde, hinweis}
let _artLeistungMap = {};
let _artObjArten = [];

async function _artLoadLeistungMap() {
    _artLeistungMap = {};
    try {
        const [cat, types] = await Promise.all([apiGet(API_BASE + '/bereich-catalog'), apiGet(API_BASE + '/sicherheitsleistungen')]);
        const byId = {};
        (Array.isArray(types) ? types : []).forEach(t => { byId[t.id] = t; });
        ((cat && cat.mains) || []).forEach(m => (m.subs || []).forEach(s => {
            const k = String(s.name || '').trim().toLowerCase();
            if (s.leistungsart_id && byId[s.leistungsart_id] && !_artLeistungMap[k]) _artLeistungMap[k] = byId[s.leistungsart_id];
        }));
    } catch (e) { /* hint stays empty */ }
}

// Shows which platform service type the chosen Einsatzart stands for
// (fallback: the object's Einsatzart) — same rule as the server.
function _artUpdateLeistungHint() {
    const el = document.getElementById('f_art_leistung_hint');
    if (!el) return;
    const sel = (typeof collectArtenChecks === 'function') ? collectArtenChecks('f_art_arten') : [];
    const find = list => list.map(n => _artLeistungMap[String(n).trim().toLowerCase()]).find(Boolean);
    const own = find(sel);
    const t = own || find(_artObjArten);
    if (!t) {
        el.innerHTML = '';
        return;
    }
    // Leistungsart + § 34a hint removed from the UI (user decision).
    void own;
    el.innerHTML = '';
}
window._artUpdateLeistungHint = _artUpdateLeistungHint;

async function saveArtikel(objId, artikelId) {
    const artenSel = (typeof collectArtenChecks === 'function') ? collectArtenChecks('f_art_arten') : [];
    // bereich has no UI — it is DERIVED: the primary chosen Unterbereich's
    // catalog parent (a sub names its own main). Fallbacks: the stored
    // bereich when still consistent, then the object's first main.
    const primSub = (typeof mselPrimary === 'function') ? mselPrimary('f_art_arten') : null;
    const firstSub = artenSel[0] || null;
    const lower = v => String(v || '').trim().toLowerCase();
    let bereich = _artSubParents[lower(primSub)] || _artSubParents[lower(firstSub)] || null;
    if (!bereich && _artBereichStored && artenSel.some(v => lower(v) === lower(_artBereichStored))) {
        bereich = _artBereichStored; // legacy rows where the stored value itself is picked
    }
    if (!bereich) bereich = _artMains[0] || _artBereichStored || null;
    const data = {
        obj_id:        objId,
        artikel_name:  document.getElementById('f_art_name').value,
        bereich:       bereich,
        arten:         artenSel,
        modell:        document.getElementById('f_art_modell').value,
        preis_kunde:   parseFloat(document.getElementById('f_art_preis_kunde').value) || 0,
        nu_preis:      parseFloat(document.getElementById('f_art_nu_preis').value) || 0,
        einheit:       document.getElementById('f_art_einheit').value || null,
        aktiv:         document.getElementById('f_art_aktiv').value,
        // Zuschläge configuration
        zuschlaege_aktiv:             document.getElementById('f_art_zuschlaege_aktiv')?.selected ? 1 : 0,
        zuschlag_tag_pct:             parseFloat(document.getElementById('f_art_z_tag')?.value)           || 0,
        zuschlag_nacht_pct:           parseFloat(document.getElementById('f_art_z_nacht')?.value)         || 0,
        zuschlag_sonntag_pct:         parseFloat(document.getElementById('f_art_z_sonntag')?.value)       || 0,
        zuschlag_feiertag_pct:        parseFloat(document.getElementById('f_art_z_feiertag')?.value)      || 0,
        zuschlag_nacht_sonntag_pct:   parseFloat(document.getElementById('f_art_z_nachtsonntag')?.value)  || 0,
        zuschlag_nacht_feiertag_pct:  parseFloat(document.getElementById('f_art_z_nachtfeiertag')?.value) || 0,
        nacht_von:                    document.getElementById('f_art_nacht_von')?.value || '22:00',
        nacht_bis:                    document.getElementById('f_art_nacht_bis')?.value || '06:00',
    };
    if (!data.artikel_name) { showToast('Bezeichnung erforderlich', 'error'); return; }
    if (!artikelId && !artenSel.length && document.querySelector('#f_art_arten input[type=checkbox]')) {
        showToast('Bitte mindestens eine Einsatzart wählen', 'error'); return;
    }
    if (!data.bereich) { showToast('Unterbereich wählen — der Bereich wird daraus abgeleitet', 'error'); return; }

    if (artikelId) data.grund = document.getElementById('f_art_grund')?.value || 'Aktualisierung';

    const result = artikelId
        ? await apiPut(API.artikel + '/' + artikelId, data)
        : await apiPost(API.artikel, data);

    if (result.success) {
        if (typeof invalidateBereichCache === 'function') invalidateBereichCache();
        showToast(artikelId ? 'Artikel aktualisiert' : 'Artikel angelegt');
        // Reopen object modal so the updated articles list is shown
        await openObjectModal(objId);
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function deleteArtikel(artikelId, objId) {
    if (!await mdConfirm('Artikel „' + artikelId + '“ deaktivieren?\n\n(Bei deaktiviertem Artikel: Erneut klicken für endgültiges Löschen — nur wenn keine Bestellungen/Einsätze damit verknüpft sind.)', { title: 'Artikel', confirmLabel: 'OK' })) return;

    // First call: deactivate. If already inactive, attempt permanent delete.
    const fetched = await apiGet(API.artikel + '/' + artikelId);
    const isActive = fetched && fetched.aktiv == 1;

    const result = isActive
        ? await apiDelete(API.artikel + '/' + artikelId)
        : await apiDelete(API.artikel + '/' + artikelId, { permanent: true });

    if (result.success) {
        showToast(result.mode === 'deleted' ? 'Artikel endgültig gelöscht' : 'Artikel deaktiviert');
        await openObjectModal(objId);
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

// ============================================================
// Adress-Autocomplete (Photon) for Objekt-Adresse
// Mirrors the Ort / Treffpunkt behaviour in incoming_orders.js
// but fills Adresse, PLZ and Stadt as separate fields.
// ============================================================
function objAdresseInput(e) {
    const q = ((e && e.target && e.target.value) || '').trim();
    const box = document.getElementById('f_adresse_suggest');
    if (!box) return;
    if (_objAdresseTimer) clearTimeout(_objAdresseTimer);
    if (_objAdresseAbort) { _objAdresseAbort.abort(); _objAdresseAbort = null; }
    if (q.length < 3) { box.style.display = 'none'; box.innerHTML = ''; return; }
    box.innerHTML = '<div class="obj-adr-empty">Suche…</div>';
    box.style.display = 'block';
    _objAdresseTimer = setTimeout(() => objAdresseFetch(q), 280);
}

function objAdresseHide() {
    setTimeout(() => { const box = document.getElementById('f_adresse_suggest'); if (box) { box.style.display = 'none'; box.innerHTML = ''; } }, 160);
}

function objAdressePhotonLabel(p) {
    const name = p.name || '';
    const street = [p.street, p.housenumber].filter(Boolean).join(' ');
    const city = p.city || p.town || p.village || p.county || '';
    const cityLine = [p.postcode, city].filter(Boolean).join(' ');
    const parts = [];
    if (name) parts.push(name);
    if (street && street !== name) parts.push(street);
    if (cityLine) parts.push(cityLine);
    if (!cityLine && p.state) parts.push(p.state);
    if (!parts.length && p.country) parts.push(p.country);
    return parts.join(', ');
}

async function objAdresseFetch(q) {
    const box = document.getElementById('f_adresse_suggest');
    if (!box) return;
    if (_objAdresseAbort) _objAdresseAbort.abort();
    _objAdresseAbort = new AbortController();
    try {
        const url = 'https://photon.komoot.io/api/?lang=de&limit=6&q=' + encodeURIComponent(q);
        const res = await fetch(url, { signal: _objAdresseAbort.signal });
        if (!res.ok) throw new Error('geocode http ' + res.status);
        const data = await res.json();
        const feats = (data && Array.isArray(data.features)) ? data.features : [];
        const seen = new Set();
        let html = '';
        for (const f of feats) {
            const p = f.properties || {};
            const label = objAdressePhotonLabel(p);
            if (!label || seen.has(label)) continue;
            seen.add(label);
            const street = [p.street, p.housenumber].filter(Boolean).join(' ') || p.name || '';
            const city = p.city || p.town || p.village || p.county || '';
            const postcode = p.postcode || '';
            const state = p.state || '';
            html += `<div class="obj-adr-item" data-street="${escapeHtml(street)}" data-postcode="${escapeHtml(postcode)}" data-city="${escapeHtml(city)}" data-state="${escapeHtml(state)}" data-label="${escapeHtml(label)}" onmousedown="objAdressePick(this)">
                <span class="material-symbols-outlined" style="color: var(--md-sys-color-primary);font-size:15px;margin-top:1px;flex-shrink:0">location_on</span>
                <span style="line-height:1.3">${escapeHtml(label)}</span>
            </div>`;
        }
        if (!html) {
            box.innerHTML = '<div class="obj-adr-empty">Keine Treffer</div>';
            box.style.display = 'block';
            return;
        }
        box.innerHTML = html;
        box.style.display = 'block';
    } catch (err) {
        if (err && err.name === 'AbortError') return;
        box.style.display = 'none'; box.innerHTML = '';
    }
}

// ============================================================
// Kontakt-Autocomplete for "Ansprechpartner" (Kontakt vor Ort)
// Suggests ONLY people already stored on objects of the SAME customer
// and fills Telefon + E-Mail on pick. Backend: GET /api/objects/contacts
// ============================================================
function objKontaktInput(e) {
    const q = ((e && e.target && e.target.value) || '').trim();
    const box = document.getElementById('f_kontakt_suggest');
    if (!box) return;
    if (_objKontaktTimer) clearTimeout(_objKontaktTimer);
    // Empty field on focus → show the most-used contacts as a starting point.
    _objKontaktTimer = setTimeout(() => objKontaktFetch(q), q ? 220 : 0);
}

function objKontaktHide() {
    setTimeout(() => {
        const box = document.getElementById('f_kontakt_suggest');
        if (box) { box.style.display = 'none'; box.innerHTML = ''; }
        _objKontaktItems = []; _objKontaktActive = -1;
    }, 160);
}

async function objKontaktFetch(q) {
    const box = document.getElementById('f_kontakt_suggest');
    if (!box) return;
    const seq = ++_objKontaktSeq;
    const kundenId = (document.getElementById('f_obj_kunden_id') || {}).value || '';
    const params = new URLSearchParams({ q, limit: '8' });
    if (kundenId) params.set('kunden_id', kundenId);
    const rows = await apiGet(API.objects + '/contacts?' + params.toString());
    if (seq !== _objKontaktSeq) return;                       // stale response
    if (document.activeElement && document.activeElement.id !== 'f_ansprechpartner') return; // user moved on
    const list = (Array.isArray(rows) ? rows : []).filter(r => r.same_kunde);
    // Don't offer the exact value that's already typed with nothing else to add.
    const current = ((document.getElementById('f_ansprechpartner') || {}).value || '').trim().toLowerCase();
    _objKontaktItems = list;
    _objKontaktActive = -1;
    if (!list.length) {
        box.innerHTML = q.length >= 2 ? '<div class="obj-adr-empty">Kein bekannter Kontakt – wird als neuer Ansprechpartner gespeichert</div>' : '';
        box.style.display = box.innerHTML ? 'block' : 'none';
        return;
    }
    box.innerHTML = list.map((r, i) => {
        const sub = [r.telefon, r.email].filter(Boolean).join(' · ');
        const where = r.objektnamen ? `${r.objekte} Objekt${r.objekte === 1 ? '' : 'e'}: ${r.objektnamen}` : '';
        const isCurrent = r.name.toLowerCase() === current;
        return `<div class="obj-adr-item obj-kontakt-item" data-i="${i}" onmousedown="objKontaktPick(${i})" title="${escapeHtml(where)}">
            <span class="material-symbols-outlined" style="color:${r.same_kunde ? '#10b981' : '#3b82f6'};font-size:16px;margin-top:1px;flex-shrink:0">${r.same_kunde ? 'person_check' : 'person'}</span>
            <span style="min-width:0;flex:1">
                <div style="font-weight:600;color:var(--gray-800)">${escapeHtml(r.name)}${isCurrent ? ' <small style="color:var(--gray-400);font-weight:400">(aktuell)</small>' : ''}</div>
                ${sub ? `<div style="font-size:.76rem;color:var(--gray-500)">${escapeHtml(sub)}</div>` : '<div style="font-size:.76rem;color:var(--gray-400)">keine Kontaktdaten hinterlegt</div>'}
                ${where ? `<div style="font-size:.7rem;color:var(--gray-400);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(where)}</div>` : ''}
            </span>
            ${r.same_kunde ? '<span style="font-size:.66rem;background: var(--md-ext-color-success-container);color: var(--md-ext-color-on-success-container);border-radius:999px;padding:1px 7px;align-self:center;white-space:nowrap">gleicher Kunde</span>' : ''}
        </div>`;
    }).join('');
    box.style.display = 'block';
}

function objKontaktPick(i) {
    const r = _objKontaktItems[i];
    if (!r) return;
    const fName = document.getElementById('f_ansprechpartner');
    const fTel = document.getElementById('f_kontakt_telefon');
    const fMail = document.getElementById('f_kontakt_email');
    if (fName) fName.value = r.name || '';
    // Fill phone/e-mail from the known contact; only overwrite when we actually have a value.
    if (fTel && r.telefon) fTel.value = r.telefon;
    if (fMail && r.email) fMail.value = r.email;
    const box = document.getElementById('f_kontakt_suggest');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
    _objKontaktItems = []; _objKontaktActive = -1;
    if (typeof showToast === 'function' && (r.telefon || r.email)) showToast('Kontaktdaten übernommen', 'success');
}

function objKontaktKeydown(e) {
    if (!_objKontaktItems.length) return;
    const box = document.getElementById('f_kontakt_suggest');
    if (!box || box.style.display === 'none') return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const n = _objKontaktItems.length;
        _objKontaktActive = e.key === 'ArrowDown' ? (_objKontaktActive + 1) % n : (_objKontaktActive - 1 + n) % n;
        box.querySelectorAll('.obj-kontakt-item').forEach((el, i) => el.style.background = i === _objKontaktActive ? '#eff6ff' : '');
    } else if (e.key === 'Enter' && _objKontaktActive >= 0) {
        e.preventDefault();
        objKontaktPick(_objKontaktActive);
    } else if (e.key === 'Escape') {
        box.style.display = 'none'; box.innerHTML = '';
    }
}

function objAdressePick(el) {
    const street = el.getAttribute('data-street') || '';
    const plz = el.getAttribute('data-postcode') || '';
    const stadt = el.getAttribute('data-city') || '';
    const state = el.getAttribute('data-state') || '';
    const stateCodes = {
        'Baden-Württemberg':'BW','Bayern':'BY','Berlin':'BE','Brandenburg':'BB','Bremen':'HB','Hamburg':'HH',
        'Hessen':'HE','Mecklenburg-Vorpommern':'MV','Niedersachsen':'NI','Nordrhein-Westfalen':'NW',
        'Rheinland-Pfalz':'RP','Saarland':'SL','Sachsen':'SN','Sachsen-Anhalt':'ST','Schleswig-Holstein':'SH','Thüringen':'TH',
    };
    const fAdresse = document.getElementById('f_adresse');
    const fPlz = document.getElementById('f_plz');
    const fStadt = document.getElementById('f_stadt');
    if (fAdresse) fAdresse.value = street;
    if (fPlz) fPlz.value = plz;
    if (fStadt) fStadt.value = stadt;
    const fBundesland = document.getElementById('f_bundesland');
    if (fBundesland && stateCodes[state]) fBundesland.value = stateCodes[state];
    const box = document.getElementById('f_adresse_suggest');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
}

// The Wachbuch lives in the QM module (Qualität › Wachbuch) — the list only links there.
const wbLabel = () => (typeof objektbuchLabel === 'function' ? objektbuchLabel() : 'Wachbuch');
function wachbuchBtnHtml(objId) {
    if (typeof hasPermission === 'function' && !hasPermission('qm.view')) return '–';
    return `<md-icon-button onclick="event.stopPropagation();loadPage('qm_wachbuch?obj=${encodeURIComponent(objId)}')" title="${escapeHtml(wbLabel())} öffnen"><md-icon>menu_book</md-icon></md-icon-button>`;
}
