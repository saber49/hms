/* ============================================
   HMS Gruppe — Lager & Ausrüstung
   - Artikel: stock + min level + Zustand badges
   - Ausgabe/Rückgabe an Mitarbeiter (issue log)
   - Kategorien-Verwaltung, CSV-Export
   - Tabs: Artikel | Ausgaben (offen) | Verlauf
   ============================================ */

let _lgArtikel = [];
let _lgKategorien = [];
let _lgStats = { artikel: 0, bestand: 0, ausgegeben: 0, niedrig: 0, lagerwert: 0, wert_ausgegeben: 0 };
let _lgTab = 'artikel';
let _lgAusgaben = [];
let _lgAusgabenGroups = [];
let _lgOpenMaKey = null;
let _lgVerlauf = [];
let _lgMaOpts = null;

function lgFmtEur(v) {
    if (v === null || v === undefined || v === '' || !isFinite(+v)) return '–';
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(+v);
}

function lgIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}

const LG_ZUSTAND = {
    neu:       { label: 'Neu',       cls: 'lg-z-neu' },
    gut:       { label: 'Gut',       cls: 'lg-z-gut' },
    abgenutzt: { label: 'Abgenutzt', cls: 'lg-z-abgenutzt' },
    defekt:    { label: 'Defekt',    cls: 'lg-z-defekt' },
    reparatur: { label: 'Reparatur', cls: 'lg-z-reparatur' },
};
const LG_KAT_ICONS = ['category', 'checkroom', 'settings_input_antenna', 'badge', 'construction', 'radio', 'flashlight_on', 'watch', 'smartphone', 'local_shipping', 'inventory_2', 'security'];
const LG_KAT_FARBEN = ['#2563eb', '#059669', '#b45309', '#7c3aed', '#dc2626', '#0e7490', '#be185d', '#475569'];

function lgFotoUrl(a) {
    if (!a || !a.foto) return null;
    return a.foto.startsWith('/') ? a.foto : '/' + a.foto;
}

function lgThumb(a, size = 38) {
    const src = lgFotoUrl(a);
    if (src) return `<img class="lg-thumb" src="${escapeHtml(src)}" alt="" style="width:${size}px;height:${size}px">`;
    const col = a && a.kategorie_farbe ? a.kategorie_farbe : '#475569';
    return `<span class="lg-thumb lg-thumb-ph" style="width:${size}px;height:${size}px;background:${col}1f;color:${col}">${lgIcon(a && a.kategorie_icon || 'inventory_2', `font-size:${Math.round(size * 0.45)}px`)}</span>`;
}

function lgKatBadge(a) {
    if (!a.kategorie_name) return '<span class="lg-muted">–</span>';
    const col = a.kategorie_farbe || '#475569';
    return `<span class="lg-kat" style="background:${col}1f;color:${col}">${lgIcon(a.kategorie_icon || 'category', 'font-size:13px')} ${escapeHtml(a.kategorie_name)}</span>`;
}

function lgZustandBadge(z) {
    const d = LG_ZUSTAND[z] || { label: z || '–', cls: '' };
    return `<span class="lg-zustand ${d.cls}">${escapeHtml(d.label)}</span>`;
}

// ---------- Rechnung (invoice attachment) ----------
let _lgPendingRech = null;
let _lgDlgA = {};
let _lgDlgId = null;

function lgRechIcon(a) {
    const isPdf = a && a.rechnung && /\.pdf$/i.test(a.rechnung);
    return lgIcon(isPdf ? 'picture_as_pdf' : 'receipt_long', 'font-size:28px');
}
// Invoices and signed return protocols are private (personal data) — they
// are fetched with the login token and shown from a blob, never via a
// public URL. The tab is opened synchronously so popup blockers allow it.
async function lgOpenSecure(url, name) {
    const win = window.open('', '_blank');
    try {
        const res = await fetch(url, { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || '') } });
        if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Datei nicht gefunden');
        const href = URL.createObjectURL(await res.blob());
        if (win) win.location.href = href;
        else { const a = document.createElement('a'); a.href = href; a.download = name || 'datei'; a.click(); }
        setTimeout(() => URL.revokeObjectURL(href), 60000);
    } catch (e) {
        if (win) win.close();
        showToast(e.message || 'Datei konnte nicht geöffnet werden', 'error');
    }
}
function lgOpenRechnung(artikelId) {
    const a = _lgArtikel.find(x => x.artikel_id === artikelId) || (_lgDlgId === artikelId ? _lgDlgA : null);
    if (a && a.rechnung) lgOpenSecure(API.lager + '/' + artikelId + '/rechnung', 'Rechnung');
}
function lgOpenProtokoll(ausgabeId) {
    lgOpenSecure(API.lager + '/ausgaben/' + ausgabeId + '/protokoll', 'Rueckgabeprotokoll');
}
function lgRechBoxHtml() {
    const a = _lgDlgA || {};
    const pend = _lgPendingRech;
    const isPdf = a.rechnung && /\.pdf$/i.test(a.rechnung);
    const hasFile = a.rechnung || pend;
    return `
        <input type="file" id="f_lg_rech" accept="image/jpeg,image/png,image/webp,application/pdf" style="display:none" onchange="lgRechnungChosen(this)">
        <md-outlined-button onclick="document.getElementById('f_lg_rech').click()"><md-icon slot="icon">receipt_long</md-icon>${hasFile ? 'Rechnung ändern' : 'Rechnung anhängen'}</md-outlined-button>
        ${a.rechnung ? `<md-icon-button onclick="lgOpenRechnung(${_lgDlgId})" title="Rechnung ansehen"><md-icon>open_in_new</md-icon></md-icon-button>` : ''}
        ${a.rechnung && _lgDlgId ? `<md-icon-button onclick="lgRechnungDelete()" title="Rechnung entfernen" class="lg-danger"><md-icon>delete</md-icon></md-icon-button>` : ''}
        <div class="lg-muted" style="margin-top:4px">${pend ? 'Ausgewählt: ' + escapeHtml(pend.name) + ' — wird beim Speichern hochgeladen' : a.rechnung ? (isPdf ? 'PDF-Rechnung hinterlegt' : 'Rechnung hinterlegt') : 'JPG, PNG, WebP oder PDF · max. 10 MB'}</div>`;
}
function lgRechRender() {
    const box = document.getElementById('lgRechBox');
    if (box) box.innerHTML = lgRechBoxHtml();
    const ic = document.getElementById('lgRechIcon');
    if (ic) ic.innerHTML = lgRechIcon(_lgDlgA);
}
async function lgUploadRechnung(artikelId, file) {
    const okTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    if (!okTypes.includes(file.type)) { showToast('Nur JPG, PNG, WebP oder PDF erlaubt', 'error'); return false; }
    if (file.size > 10 * 1024 * 1024) { showToast('Max. 10 MB', 'error'); return false; }
    const fd = new FormData();
    fd.append('rechnung', file);
    const r = await apiUpload(API.lager + '/' + artikelId + '/rechnung', fd);
    if (!r || !r.success) { showToast(r?.error || 'Upload fehlgeschlagen', 'error'); return false; }
    const a = _lgArtikel.find(x => x.artikel_id === artikelId);
    if (a) a.rechnung = r.rechnung;
    _lgDlgA.rechnung = r.rechnung;
    return true;
}
async function lgRechnungChosen(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    if (_lgDlgId) {
        if (await lgUploadRechnung(_lgDlgId, file)) showToast('Rechnung gespeichert');
    } else {
        const okTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!okTypes.includes(file.type)) { showToast('Nur JPG, PNG, WebP oder PDF erlaubt', 'error'); input.value = ''; return; }
        if (file.size > 10 * 1024 * 1024) { showToast('Max. 10 MB', 'error'); input.value = ''; return; }
        _lgPendingRech = file;
    }
    input.value = '';
    lgRechRender();
}
async function lgRechnungDelete() {
    const r = await apiDelete(API.lager + '/' + _lgDlgId + '/rechnung');
    if (r && r.error) return showToast(r.error, 'error');
    const a = _lgArtikel.find(x => x.artikel_id === _lgDlgId);
    if (a) a.rechnung = null;
    _lgDlgA.rechnung = null;
    lgRechRender();
    showToast('Rechnung entfernt');
}

function lgBestandCell(a) {
    const offen = +a.ausgegeben_menge || 0;
    const verf = Math.max(0, +a.verfuegbar);
    const niedrig = a.mindestbestand > 0 && verf < a.mindestbestand;
    const wert = a.einzelpreis != null ? ` · Lagerwert ${lgFmtEur(a.einzelpreis * a.menge_gesamt)}` : '';
    return `<span class="lg-bestand ${niedrig ? 'lg-niedrig' : verf === 0 ? 'lg-leer' : ''}" title="Verfügbar ${verf} / Gesamt ${a.menge_gesamt}${niedrig ? ' · unter Mindestbestand ' + a.mindestbestand : ''}${wert}">
        ${verf}<span class="lg-bestand-total">/${a.menge_gesamt}</span></span>
        ${offen > 0 ? `<span class="lg-offen" title="${offen} ausgegeben">${lgIcon('logout', 'font-size:12px')} ${offen}</span>` : ''}`;
}

// ============================================
// MAIN RENDER
// ============================================
let _lgRtBound = false;
/** Patch rows in place (dom-morph.js); innerHTML fallback for an old index.php. */
function lgPaint(el, html) {
    if (typeof hmsMorph === 'function') hmsMorph(el, html); else el.innerHTML = html;
}

async function renderLager() {
    const content = document.getElementById('content');
    const editable = typeof canEdit === 'function' ? canEdit('lager') : false;

    // Live updates: another user's issue/return/edit re-renders this page.
    if (!_lgRtBound && window.HMSRealtime && typeof HMSRealtime.on === 'function') {
        _lgRtBound = true;
        HMSRealtime.on('lager', () => {
            if (document.getElementById('lgPaneArtikel')) renderLager();
        });
    }

    const [statsRes, katRes, artRes] = await Promise.all([
        apiGet(API.lager + '/stats').catch(() => null),
        apiGet(API.lager + '/kategorien').catch(() => []),
        apiGet(API.lager).catch(() => null),
    ]);
    _lgStats = (statsRes && Number.isFinite(+statsRes.artikel)) ? statsRes : _lgStats;
    _lgKategorien = Array.isArray(katRes) ? katRes : [];
    _lgArtikel = Array.isArray(artRes?.data) ? artRes.data : [];

    const niedrigPct = _lgStats.artikel > 0 ? Math.round((_lgStats.niedrig / _lgStats.artikel) * 100) : 0;
    const lagerwert = +_lgStats.lagerwert || 0;
    const wertAusgegeben = +_lgStats.wert_ausgegeben || 0;
    // Page already on screen (save, realtime): keep search / category /
    // low-stock / period and patch only tiles, chips and rows.
    const soft = !!document.getElementById('lgPaneArtikel') && typeof hmsMorph === 'function';
    let curKat = soft ? lgSelectedKat() : '';
    if (curKat && !_lgKategorien.some(k => String(k.id) === curKat)) curKat = '';
    const katChips = [{ id: '', name: 'Alle' }, ..._lgKategorien].map(k =>
        `<md-filter-chip data-mk="k${k.id}" label="${escapeHtml(k.name)}" data-kat="${k.id}"${String(k.id) === curKat ? ' selected' : ''}></md-filter-chip>`).join('');

    const bentoHtml = `
            <div class="bento-tile bento-tile-hero bento-tile-total">
                <div class="bento-tile-head">${lgIcon('inventory_2')} Artikel gesamt</div>
                <div class="bento-tile-number">${_lgStats.artikel}</div>
                <div class="bento-tile-foot">
                    <span class="bento-foot-pill">${lgIcon('category')} ${_lgKategorien.length} Kategorien</span>
                    <span class="bento-foot-pill">${lgIcon('warehouse')} ${_lgStats.bestand} Einheiten</span>
                    <span class="bento-foot-pill" title="Gesamtwert des Lagerbestands">${lgIcon('payments')} ${lgFmtEur(lagerwert)}</span>
                </div>
            </div>
            <div class="bento-tile" title="Im Lager verfügbar">
                <div class="bento-tile-icon">${lgIcon('warehouse')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${_lgStats.bestand - _lgStats.ausgegeben}</div>
                    <div class="bento-tile-label">Verfügbar</div>
                </div>
            </div>
            <div class="bento-tile" onclick="lgSetTab('ausgaben')" title="Derzeit bei Mitarbeitern${wertAusgegeben ? ' · Wert ' + lgFmtEur(wertAusgegeben) : ''}" style="cursor:pointer">
                <div class="bento-tile-icon">${lgIcon('logout')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${_lgStats.ausgegeben}</div>
                    <div class="bento-tile-label">Ausgegeben${wertAusgegeben ? ' · ' + lgFmtEur(wertAusgegeben) : ''}</div>
                </div>
            </div>
            <div class="bento-tile ${_lgStats.niedrig > 0 ? 'bento-tile-warn' : ''}" onclick="lgSetTab('artikel', true)" title="Unter Mindestbestand" style="cursor:pointer">
                <div class="bento-tile-icon">${lgIcon('production_quantity_limits')}</div>
                <div class="bento-tile-body">
                    <div class="bento-tile-number-sm">${_lgStats.niedrig}</div>
                    <div class="bento-tile-label">Niedrigbestand</div>
                </div>
                <div class="bento-progress" title="${niedrigPct}% der Artikel niedrig">
                    <div class="bento-progress-bar" style="width:${Math.min(100, niedrigPct)}%"></div>
                </div>
            </div>`;

    if (soft) {
        hmsMorph(content.querySelector('.lg-page > .data-bento'), bentoHtml);
        hmsMorph(document.getElementById('lgKatChips'), katChips);
        lgRenderRows();
        lgRefreshAusgaben();
        return;
    }

    content.innerHTML = `
    <div class="lg-page">
        <div class="data-bento">${bentoHtml}
        </div>

        <md-tabs class="lg-tabs">
            <md-secondary-tab data-seg inline-icon class="lg-tab ${_lgTab === 'artikel' ? 'active' : ''}" onclick="lgSetTab('artikel')"><md-icon slot="icon">inventory_2</md-icon>Artikel</md-secondary-tab>
            <md-secondary-tab data-seg inline-icon class="lg-tab ${_lgTab === 'ausgaben' ? 'active' : ''}" onclick="lgSetTab('ausgaben')"><md-icon slot="icon">logout</md-icon>Ausgaben <span class="lg-tab-count" id="lgAusgabenCount"></span></md-secondary-tab>
            <md-secondary-tab data-seg inline-icon class="lg-tab ${_lgTab === 'verlauf' ? 'active' : ''}" onclick="lgSetTab('verlauf')"><md-icon slot="icon">history</md-icon>Verlauf</md-secondary-tab>
        </md-tabs>

        <div class="data-toolbar lg-pane-only" data-pane="artikel" ${_lgTab !== 'artikel' ? 'style="display:none"' : ''}>
            <div class="data-toolbar-left">
                <md-outlined-text-field class="cust-search" id="lgSearch" label="Suchen" placeholder="Name, Art.-Nr., Serien-Nr., Ort…">
                    <md-icon slot="leading-icon">search</md-icon>
                </md-outlined-text-field>
                <md-chip-set id="lgKatChips">${katChips}</md-chip-set>
                <md-filter-chip id="lgNiedrig" label="Nur Niedrigbestand"></md-filter-chip>
            </div>
            <div class="data-toolbar-right">
                <md-outlined-button onclick="lgExportCsv()"><md-icon slot="icon">download</md-icon>CSV</md-outlined-button>
                ${editable ? `
                <md-outlined-button onclick="openLgKategorien()"><md-icon slot="icon">category</md-icon>Kategorien</md-outlined-button>
                <md-filled-button onclick="openLgArtikelDialog()"><md-icon slot="icon">add</md-icon>Neuer Artikel</md-filled-button>` : ''}
            </div>
        </div>

        <div class="data-table-card" id="lgPaneArtikel" ${_lgTab !== 'artikel' ? 'style="display:none"' : ''}>
            <div class="table-container">
                <table>
                    <thead><tr>
                        <th></th><th>Artikel</th><th>Kategorie</th><th>Größe</th><th>Serien-Nr.</th>
                        <th>Zustand</th><th>Lagerort</th><th>Bestand</th><th></th>
                    </tr></thead>
                    <tbody id="lgArtikelTable"></tbody>
                </table>
                <div class="empty-state" id="lgArtikelEmpty" style="display:none">${lgIcon('inventory_2')}<p id="lgArtikelEmptyMsg">Keine Artikel gefunden</p><md-text-button id="lgArtikelReset" style="display:none" onclick="lgResetFilters()">Filter zurücksetzen</md-text-button></div>
            </div>
            <div id="lgPagerArt" class="be-pager"></div>
        </div>

        <div class="data-table-card" id="lgPaneAusgaben" ${_lgTab !== 'ausgaben' ? 'style="display:none"' : ''}>
            <div class="table-container">
                <table>
                    <thead><tr><th>Mitarbeiter</th><th>Artikel</th><th>Wert</th><th></th></tr></thead>
                    <tbody id="lgAusgabenTable"></tbody>
                </table>
                <div class="empty-state" id="lgAusgabenEmpty" style="display:none">${lgIcon('logout')}<p>Keine offenen Ausgaben</p></div>
            </div>
            <div id="lgPagerAus" class="be-pager"></div>
        </div>

        <div class="data-toolbar lg-pane-only" data-pane="verlauf" ${_lgTab !== 'verlauf' ? 'style="display:none"' : ''}>
            <div class="data-toolbar-left">
                <md-outlined-select id="lgZeitraum" label="Zeitraum" style="min-width:220px">
                    <md-select-option value="7"><div slot="headline">Letzte 7 Tage</div></md-select-option>
                    <md-select-option value="30" selected><div slot="headline">Letzte 30 Tage</div></md-select-option>
                    <md-select-option value="60"><div slot="headline">Letzte 60 Tage</div></md-select-option>
                    <md-select-option value="90"><div slot="headline">Letzte 90 Tage</div></md-select-option>
                    <md-select-option value="365"><div slot="headline">Letztes Jahr</div></md-select-option>
                    <md-select-option value="custom"><div slot="headline">Benutzerdefiniert…</div></md-select-option>
                    <md-select-option value="all"><div slot="headline">Gesamter Zeitraum</div></md-select-option>
                </md-outlined-select>
                <span id="lgZeitraumCustom" style="display:none;align-items:center;gap:8px">
                    <md-outlined-text-field id="lgVon" label="Von" type="date" style="width:150px"></md-outlined-text-field>
                    <md-outlined-text-field id="lgBis" label="Bis" type="date" style="width:150px"></md-outlined-text-field>
                </span>
            </div>
            <div class="data-toolbar-right">
                <md-outlined-button onclick="lgExportVerlaufCsv()"><md-icon slot="icon">download</md-icon>CSV</md-outlined-button>
            </div>
        </div>

        <div class="data-table-card" id="lgPaneVerlauf" ${_lgTab !== 'verlauf' ? 'style="display:none"' : ''}>
            <div class="table-container">
                <table>
                    <thead><tr><th>Mitarbeiter</th><th>Artikel</th><th>Menge</th><th>Ausgegeben</th><th>Zurück</th><th>Protokoll</th><th>Status</th></tr></thead>
                    <tbody id="lgVerlaufTable"></tbody>
                </table>
                <div class="empty-state" id="lgVerlaufEmpty" style="display:none">${lgIcon('history')}<p>Noch keine Buchungen</p></div>
            </div>
            <div id="lgPagerVer" class="be-pager"></div>
        </div>
    </div>`;

    document.getElementById('lgSearch')?.addEventListener('input', lgRenderRows);
    document.getElementById('lgKatChips')?.addEventListener('click', e => {
        const chip = e.target.closest('md-filter-chip'); if (!chip) return;
        document.querySelectorAll('#lgKatChips md-filter-chip').forEach(c => c.selected = c === chip);
        lgRenderRows();
    });
    document.getElementById('lgNiedrig')?.addEventListener('click', function () { this.selected = !this.selected; lgRenderRows(); });
    const zs = document.getElementById('lgZeitraum');
    zs?.addEventListener('change', () => {
        document.getElementById('lgZeitraumCustom').style.display = zs.value === 'custom' ? 'inline-flex' : 'none';
        if (zs.value !== 'custom') lgRefreshAusgaben();
    });
    ['lgVon', 'lgBis'].forEach(id => document.getElementById(id)?.addEventListener('change', lgRefreshAusgaben));
    lgRenderRows();
    lgRefreshAusgaben();
}

function lgSetTab(tab, niedrigOnly) {
    _lgTab = tab;
    document.querySelectorAll('.lg-tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.lg-tab[onclick*="'${tab}'"]`)?.classList.add('active');
    ['artikel', 'ausgaben', 'verlauf'].forEach(p => {
        const el = document.getElementById('lgPane' + p[0].toUpperCase() + p.slice(1));
        if (el) el.style.display = p === tab ? '' : 'none';
    });
    document.querySelectorAll('.lg-pane-only').forEach(el => {
        el.style.display = el.dataset.pane === tab ? '' : 'none';
    });
    if (tab === 'artikel' && niedrigOnly) {
        // KPI-tile shortcut: reset the other filters so the low-stock
        // list isn't further narrowed (which looked like items vanished).
        const s = document.getElementById('lgSearch'); if (s) s.value = '';
        document.querySelectorAll('#lgKatChips md-filter-chip').forEach(c => { c.selected = c.dataset.kat === ''; });
        const n = document.getElementById('lgNiedrig'); if (n) n.selected = true;
        lgRenderRows();
    }
    if (tab === 'ausgaben' || tab === 'verlauf') lgRefreshAusgaben();
}

// ---------- Artikel rows ----------
function lgRenderRows() {
    const tbody = document.getElementById('lgArtikelTable');
    if (!tbody) return;
    const q = String(document.getElementById('lgSearch')?.value ?? '').trim().toLowerCase();
    const kat = lgSelectedKat();
    const nurNiedrig = document.getElementById('lgNiedrig')?.selected;
    const editable = typeof canEdit === 'function' ? canEdit('lager') : false;

    const rows = _lgArtikel.filter(a => {
        if (kat && String(a.kategorie_id) !== String(kat)) return false;
        if (nurNiedrig && !(a.mindestbestand > 0 && +a.verfuegbar < a.mindestbestand)) return false;
        if (q) {
            const hay = [a.name, a.art_nr, a.seriennummer, a.lagerort, a.kategorie_name].filter(Boolean).join(' ').toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    });

    const _pa = hmsPagerDef('lgPagerArt', 'Artikel', () => lgRenderRows());
    const pga = hmsPage(rows, _pa.st);
    hmsPagerFill('lgPagerArt', pga);
    lgPaint(tbody, pga.rows.map(a => `
        <tr data-mk="a${a.artikel_id}">
            <td style="width:46px;cursor:${a.foto ? 'zoom-in' : 'default'}" ${a.foto ? `onclick="openLgFotoView(${a.artikel_id})" title="Foto vergrößern"` : ''}>${lgThumb(a)}</td>
            <td><strong>${escapeHtml(a.name)}</strong>${a.art_nr ? ` <span class="lg-muted">· ${escapeHtml(a.art_nr)}</span>` : ''}${a.einzelpreis != null ? ` <span class="lg-muted">· ${lgFmtEur(a.einzelpreis)}</span>` : ''}</td>
            <td>${lgKatBadge(a)}</td>
            <td data-label="Größe">${escapeHtml(a.groesse || '–')}</td>
            <td data-label="Serien-Nr."><span class="lg-mono">${escapeHtml(a.seriennummer || '–')}</span></td>
            <td>${lgZustandBadge(a.zustand)}</td>
            <td data-label="Lagerort">${escapeHtml(a.lagerort || '–')}</td>
            <td>${lgBestandCell(a)}</td>
            <td class="lg-act" onclick="event.stopPropagation()">
                <md-icon-button onclick="openLgFotoView(${a.artikel_id})" title="Foto ansehen"><md-icon>visibility</md-icon></md-icon-button>
                ${a.rechnung ? `<md-icon-button onclick="lgOpenRechnung(${a.artikel_id})" title="Rechnung ansehen"><md-icon>receipt_long</md-icon></md-icon-button>` : ''}
                ${editable ? `<md-icon-button onclick="openLgAusgabeDialog(${a.artikel_id})" title="Ausgeben" ${+a.verfuegbar <= 0 ? 'disabled' : ''}><md-icon>logout</md-icon></md-icon-button>
                <md-icon-button onclick="openLgArtikelDialog(${a.artikel_id})" title="Bearbeiten"><md-icon>edit</md-icon></md-icon-button>
                <md-icon-button onclick="openLgVerlauf(${a.artikel_id},'${escapeHtml(a.name).replace(/'/g, '')}')" title="Verlauf"><md-icon>history</md-icon></md-icon-button>
                <md-icon-button onclick="lgDeleteArtikel(${a.artikel_id},'${escapeHtml(a.name).replace(/'/g, '')}')" title="Löschen" class="lg-danger"><md-icon>delete</md-icon></md-icon-button>` : ''}
            </td>
        </tr>`).join(''));
    const empty = document.getElementById('lgArtikelEmpty');
    const filteredEmpty = rows.length === 0 && _lgArtikel.length > 0 && (kat || q || nurNiedrig);
    const msg = document.getElementById('lgArtikelEmptyMsg');
    if (msg) msg.textContent = filteredEmpty ? 'Keine Artikel für die gewählten Filter' : 'Keine Artikel gefunden';
    const rst = document.getElementById('lgArtikelReset');
    if (rst) rst.style.display = filteredEmpty ? '' : 'none';
    empty.style.display = rows.length ? 'none' : '';
}

// The `selected` PROPERTY — the MD3 chip reflects it to the attribute only
// after its next render, so `[selected]` still showed the old chip right
// after a click (category click did not filter).
function lgSelectedKat() {
    return [...document.querySelectorAll('#lgKatChips md-filter-chip')].find(c => c.selected)?.dataset.kat || '';
}

function lgResetFilters() {
    const s = document.getElementById('lgSearch'); if (s) s.value = '';
    document.querySelectorAll('#lgKatChips md-filter-chip').forEach(c => { c.selected = c.dataset.kat === ''; });
    const n = document.getElementById('lgNiedrig'); if (n) n.selected = false;
    lgRenderRows();
}

// ---------- Ausgaben / Verlauf ----------
function lgZeitraumParams() {
    const zs = document.getElementById('lgZeitraum');
    const v = zs?.value || '30';
    const fmt = d => d.toISOString().slice(0, 10);
    const now = new Date();
    if (v === 'all') return '';
    if (v === 'custom') {
        const von = document.getElementById('lgVon')?.value;
        const bis = document.getElementById('lgBis')?.value;
        return (von ? `&from=${von}` : '') + (bis ? `&to=${bis}` : '');
    }
    const from = new Date(now); from.setDate(from.getDate() - (+v));
    return `&from=${fmt(from)}&to=${fmt(now)}`;
}

async function lgRefreshAusgaben() {
    const zp = lgZeitraumParams();
    const [offen, alle] = await Promise.all([
        apiGet(API.lager + '/ausgaben?status=offen').catch(() => null),
        _lgTab === 'verlauf' ? apiGet(API.lager + '/ausgaben?x=1' + zp).catch(() => null) : Promise.resolve(null),
    ]);
    _lgAusgaben = Array.isArray(offen?.data) ? offen.data : [];
    const verlauf = Array.isArray(alle?.data) ? alle.data : [];
    if (alle) _lgVerlauf = verlauf;

    const cnt = document.getElementById('lgAusgabenCount');
    if (cnt) cnt.textContent = _lgAusgaben.length ? `(${_lgAusgaben.length})` : '';

    const editable = typeof canEdit === 'function' ? canEdit('lager') : false;
    const at = document.getElementById('lgAusgabenTable');
    if (at) {
        // One row per worker — click expands the individual issues.
        const gmap = new Map();
        _lgAusgaben.forEach(o => {
            const k = (o.mitarbeiter_dienst_nr || '') + '|' + (o.mitarbeiter_name || '');
            if (!gmap.has(k)) gmap.set(k, []);
            gmap.get(k).push(o);
        });
        const groups = [...gmap.values()].sort((a, b) =>
            (a[0].mitarbeiter_name || '').localeCompare(b[0].mitarbeiter_name || '', 'de'));
        _lgAusgabenGroups = groups;
        const _pu = hmsPagerDef('lgPagerAus', 'Mitarbeiter', () => lgRenderRows());
        const pgu = hmsPage(groups, _pu.st);
        hmsPagerFill('lgPagerAus', pgu);
        lgPaint(at, pgu.rows.map((rows, gi) => {
            const o = rows[0];
            const wert = rows.reduce((s, r) => s + (r.einzelpreis != null ? r.menge * r.einzelpreis : 0), 0);
            const anzahl = rows.reduce((s, r) => s + (+r.menge || 0), 0);
            return `
            <tr data-mk="g${escapeHtml((o.mitarbeiter_dienst_nr || '') + '|' + (o.mitarbeiter_name || ''))}" class="lg-grp" onclick="lgOpenMaAusgaben(${pgu.start + gi})">
                <td><strong>${escapeHtml(o.mitarbeiter_name)}</strong> <span class="lg-muted">${escapeHtml(o.mitarbeiter_dienst_nr)}</span></td>
                <td>${rows.length > 1 ? rows.length + ' Artikel' : '1 Artikel'} · ${anzahl}×</td>
                <td>${wert > 0 ? lgFmtEur(wert) : '–'}</td>
                <td class="lg-act"><md-icon class="lg-chev">open_in_new</md-icon></td>
            </tr>`;
        }).join(''));
        document.getElementById('lgAusgabenEmpty').style.display = _lgAusgaben.length ? 'none' : '';
    }
    const vt = document.getElementById('lgVerlaufTable');
    if (vt && _lgTab === 'verlauf') {
        const _pv = hmsPagerDef('lgPagerVer', 'Buchungen', () => lgRenderRows());
        const pgv = hmsPage(verlauf, _pv.st);
        hmsPagerFill('lgPagerVer', pgv);
        lgPaint(vt, pgv.rows.map(o => `
            <tr data-mk="v${parseInt(o.ausgabe_id, 10)}">
                <td><strong>${escapeHtml(o.mitarbeiter_name)}</strong> <span class="lg-muted">${escapeHtml(o.mitarbeiter_dienst_nr)}</span></td>
                <td>${escapeHtml(o.artikel_name || '–')}</td>
                <td>${o.menge}×${o.einzelpreis != null ? ` <span class="lg-muted">${lgFmtEur(o.menge * o.einzelpreis)}</span>` : ''}</td>
                <td data-label="Ausgegeben">${formatDateTimeLocal(o.ausgegeben_am)}${o.ausgegeben_von_name ? `<div class="lg-muted" style="font-size:.74rem">von ${escapeHtml(o.ausgegeben_von_name)}</div>` : ''}</td>
                <td data-label="Zurück">${o.rueckgabe_am ? formatDateTimeLocal(o.rueckgabe_am) : '–'}${o.rueckgabe_von_name ? `<div class="lg-muted" style="font-size:.74rem">von ${escapeHtml(o.rueckgabe_von_name)}</div>` : ''}</td>
                <td data-label="Protokoll">${o.rueckgabe_protokoll ? `<a href="#" onclick="event.preventDefault();lgOpenProtokoll(${parseInt(o.ausgabe_id, 10)})" title="Rückgabeprotokoll öffnen" style="color:var(--accent)">${lgIcon(o.rueckgabe_protokoll.endsWith('.pdf') ? 'picture_as_pdf' : 'image', 'font-size:19px;vertical-align:-4px')}</a>` : '–'}</td>
                <td><span class="lg-zustand ${o.status === 'offen' ? 'lg-z-reparatur' : 'lg-z-gut'}">${o.status === 'offen' ? 'Offen' : 'Zurück'}</span></td>
            </tr>`).join(''));
        document.getElementById('lgVerlaufEmpty').style.display = verlauf.length ? 'none' : '';
    }
}

function lgExportCsv() {
    const kat = lgSelectedKat();
    const q = String(document.getElementById('lgSearch')?.value ?? '').trim().toLowerCase();
    const nurNiedrig = document.getElementById('lgNiedrig')?.selected;
    const rows = _lgArtikel.filter(a => {
        if (kat && String(a.kategorie_id) !== String(kat)) return false;
        if (nurNiedrig && !(a.mindestbestand > 0 && +a.verfuegbar < a.mindestbestand)) return false;
        if (q) {
            const hay = [a.name, a.art_nr, a.seriennummer, a.lagerort, a.kategorie_name].filter(Boolean).join(' ').toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    });
    const esc = v => {
        if (v === null || v === undefined) return '';
        const s = String(v).replace(/"/g, '""');
        return /[";\n\r]/.test(s) ? `"${s}"` : s;
    };
    const lines = [['Art.-Nr.', 'Name', 'Kategorie', 'Größe', 'Serien-Nr.', 'Zustand', 'Lagerort', 'Gesamt', 'Ausgegeben', 'Verfügbar', 'Mindestbestand', 'Einzelpreis', 'Lagerwert', 'Rechnung'].join(';')];
    rows.forEach(a => {
        const zl = (LG_ZUSTAND[a.zustand] || {}).label || a.zustand || '';
        lines.push([a.art_nr, a.name, a.kategorie_name, a.groesse, a.seriennummer, zl,
            a.lagerort, a.menge_gesamt, a.ausgegeben_menge, a.verfuegbar, a.mindestbestand,
            a.einzelpreis != null ? String(a.einzelpreis).replace('.', ',') : '',
            a.einzelpreis != null ? (a.einzelpreis * a.menge_gesamt).toFixed(2).replace('.', ',') : '',
            a.rechnung ? 'ja' : ''].map(esc).join(';'));
    });
    lgDownloadCsv(lines, `lager_artikel_${new Date().toISOString().slice(0, 10)}.csv`);
    showToast(`${rows.length} Artikel exportiert`);
}

async function lgExportVerlaufCsv() {
    const zp = lgZeitraumParams();
    const r = await apiGet(API.lager + '/ausgaben?x=1' + zp).catch(() => null);
    const rows = Array.isArray(r?.data) ? r.data : [];
    if (!rows.length) return showToast('Keine Buchungen im gewählten Zeitraum', 'error');
    const esc = v => {
        if (v === null || v === undefined) return '';
        const s = String(v).replace(/"/g, '""');
        return /[";\n\r]/.test(s) ? `"${s}"` : s;
    };
    const eur = v => (v === null || v === undefined || v === '') ? '' : (+v).toFixed(2).replace('.', ',');
    const lines = [['Mitarbeiter', 'Dienst-Nr.', 'Artikel', 'Art.-Nr.', 'Menge', 'Einzelpreis', 'Wert', 'Ausgegeben am', 'Zurück am', 'Status', 'Bemerkung'].join(';')];
    rows.forEach(o => {
        lines.push([o.mitarbeiter_name, o.mitarbeiter_dienst_nr, o.artikel_name, o.art_nr, o.menge,
            eur(o.einzelpreis), o.einzelpreis != null ? eur(o.menge * o.einzelpreis) : '',
            o.ausgegeben_am ? o.ausgegeben_am.slice(0, 16).replace('T', ' ') : '',
            o.rueckgabe_am ? o.rueckgabe_am.slice(0, 16).replace('T', ' ') : '',
            o.status === 'offen' ? 'Offen' : 'Zurück', o.bemerkung].map(esc).join(';'));
    });
    lgDownloadCsv(lines, `lager_verlauf_${new Date().toISOString().slice(0, 10)}.csv`);
    showToast(`${rows.length} Buchungen exportiert`);
}

function lgDownloadCsv(lines, filename) {
    const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
}

// ============================================
// DIALOGS
// ============================================
function lgShowDialog(title, bodyHtml, footerHtml = '') {
    document.getElementById('lagerMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'lagerMdDialog';
    dlg.classList.add('customers-dialog-xl');
    dlg.innerHTML = `
      <div slot="headline">${title}</div>
      <div slot="content" class="customers-dialog-content lg-dialog-content">${bodyHtml}</div>
      <div slot="actions">${footerHtml}</div>`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();
    return dlg;
}
function closeLagerDialog() { _lgOpenMaKey = null; document.getElementById('lagerMdDialog')?.close(); }

// ---------- Artikel create/edit ----------
async function openLgArtikelDialog(id = null) {
    let a = {};
    if (id) {
        const r = await apiGet(API.lager + '/' + id);
        if (!r || !r.artikel) { showToast(r?.error || 'Artikel nicht gefunden', 'error'); return; }
        a = r.artikel;
    }
    const katOpts = _lgKategorien.map(k =>
        `<md-select-option value="${k.id}" ${String(a.kategorie_id) === String(k.id) ? 'selected' : ''}><div slot="headline">${escapeHtml(k.name)}</div></md-select-option>`).join('');
    const zustOpts = Object.entries(LG_ZUSTAND).map(([v, d]) =>
        `<md-select-option value="${v}" ${a.zustand === v || (!a.zustand && v === 'neu') ? 'selected' : ''}><div slot="headline">${d.label}</div></md-select-option>`).join('');

    _lgPendingFoto = null;
    _lgPendingRech = null;
    _lgDlgId = id;
    _lgDlgA = a;
    lgShowDialog(
        (id ? 'Artikel bearbeiten' : 'Neuer Artikel'),
        `<div class="customer-form-layout">
            <div class="customer-form-col">
                <div class="lg-foto-row">
                    <div id="lgFotoPreview">${lgThumb(a, 64)}</div>
                    <div style="flex:1;min-width:0">
                        <input type="file" id="f_lg_foto" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="lgFotoChosen(this,${id || 'null'})">
                        <md-outlined-button onclick="document.getElementById('f_lg_foto').click()"><md-icon slot="icon">photo_camera</md-icon>${a.foto ? 'Foto ändern' : 'Foto hinzufügen'}</md-outlined-button>
                        ${a.foto && id ? `<md-icon-button onclick="lgFotoDelete(${id})" title="Foto entfernen" class="lg-danger"><md-icon>delete</md-icon></md-icon-button>` : ''}
                        <div class="lg-muted" style="margin-top:4px">JPG, PNG oder WebP · max. 5 MB</div>
                    </div>
                </div>
                <div class="lg-foto-row">
                    <span class="lg-thumb lg-thumb-ph" id="lgRechIcon" style="width:64px;height:64px;background: color-mix(in srgb, var(--md-sys-color-primary) 12%, transparent);color:var(--gray-500)">${lgRechIcon(a)}</span>
                    <div style="flex:1;min-width:0" id="lgRechBox">${lgRechBoxHtml()}</div>
                </div>
                <div class="customer-form-section-title">${lgIcon('inventory_2')} Artikeldaten</div>
                <div class="form-group">
                    <md-outlined-text-field id="f_lg_name" label="Name *" value="${escapeHtml(a.name || '')}" placeholder="z.B. Funkgerät Motorola DP4800"></md-outlined-text-field>
                </div>
                <div class="customer-form-row">
                    <div class="form-group" style="flex:1">
                        <md-outlined-select id="f_lg_kategorie" label="Kategorie">
                            <md-select-option value=""><div slot="headline">— ohne —</div></md-select-option>${katOpts}
                        </md-outlined-select>
                    </div>
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_lg_artnr" label="Art.-Nr." value="${escapeHtml(a.art_nr || '')}"></md-outlined-text-field>
                    </div>
                </div>
                <div class="customer-form-row">
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_lg_groesse" label="Größe" value="${escapeHtml(a.groesse || '')}" placeholder="z.B. XL, 52…"></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_lg_serie" label="Serien-Nr." value="${escapeHtml(a.seriennummer || '')}"></md-outlined-text-field>
                    </div>
                </div>
                <div class="form-group">
                    <md-outlined-select id="f_lg_zustand" label="Zustand">${zustOpts}</md-outlined-select>
                </div>
            </div>
            <div class="customer-form-col">
                <div class="customer-form-section-title">${lgIcon('warehouse')} Bestand</div>
                <div class="customer-form-row">
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_lg_menge" label="Gesamtbestand *" type="number" value="${a.menge_gesamt ?? 1}"></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_lg_min" label="Mindestbestand" type="number" value="${a.mindestbestand ?? 0}"></md-outlined-text-field>
                    </div>
                </div>
                <div class="form-group">
                    <md-outlined-text-field id="f_lg_grund" label="Grund der Bestandsänderung" placeholder="z.B. Zugang Lieferung, Inventur, defekt ausgesondert" maxlength="500" supporting-text="Wird mit Datum und Benutzer im Bestandsprotokoll gespeichert"></md-outlined-text-field>
                </div>
                <div class="lg-hint">${lgIcon('info', 'font-size:15px')} Bei Unterschreiten des Mindestbestands wird der Artikel in der Übersicht markiert.</div>
                <div class="customer-form-row">
                    <div class="form-group" style="flex:0 0 160px">
                        <md-outlined-text-field id="f_lg_preis" label="Einzelpreis" type="number" step="0.01" min="0" value="${a.einzelpreis ?? ''}" placeholder="0,00"><md-icon slot="trailing-icon">euro</md-icon></md-outlined-text-field>
                    </div>
                    <div class="form-group" style="flex:1">
                        <md-outlined-text-field id="f_lg_ort" label="Lagerort" value="${escapeHtml(a.lagerort || '')}" placeholder="z.B. Büro, Regal 3"><md-icon slot="trailing-icon">location_on</md-icon></md-outlined-text-field>
                    </div>
                </div>
                <div class="form-group">
                    <md-outlined-text-field id="f_lg_bem" label="Bemerkung" type="textarea" rows="2" value="${escapeHtml(a.bemerkung || '')}"></md-outlined-text-field>
                </div>
            </div>
        </div>`,
        `<md-outlined-button onclick="closeLagerDialog()">Abbrechen</md-outlined-button>
         <md-filled-button onclick="saveLgArtikel(${id || 'null'})"><md-icon slot="icon">save</md-icon>Speichern</md-filled-button>`);
}

async function saveLgArtikel(id) {
    const val = el => document.getElementById(el)?.value?.trim() ?? '';
    const menge = parseInt(val('f_lg_menge'), 10);
    if (!val('f_lg_name')) return showToast('Name ist erforderlich', 'error');
    if (isNaN(menge) || menge < 0) return showToast('Gesamtbestand muss ≥ 0 sein', 'error');
    const preisRaw = val('f_lg_preis').replace(',', '.');
    const einzelpreis = preisRaw === '' ? null : parseFloat(preisRaw);
    if (einzelpreis !== null && (isNaN(einzelpreis) || einzelpreis < 0)) return showToast('Einzelpreis ungültig', 'error');
    const body = {
        name: val('f_lg_name'),
        kategorie_id: val('f_lg_kategorie') || null,
        art_nr: val('f_lg_artnr') || null,
        groesse: val('f_lg_groesse') || null,
        seriennummer: val('f_lg_serie') || null,
        zustand: val('f_lg_zustand') || 'neu',
        menge_gesamt: menge,
        einzelpreis,
        mindestbestand: parseInt(val('f_lg_min'), 10) || 0,
        lagerort: val('f_lg_ort') || null,
        bemerkung: val('f_lg_bem') || null,
        bestand_grund: val('f_lg_grund') || null,
    };
    const r = id ? await apiPut(API.lager + '/' + id, body) : await apiPost(API.lager, body);
    if (r && r.error) return showToast(r.error, 'error');
    const savedId = id || r.artikel_id;
    if (_lgPendingFoto && savedId) {
        await lgUploadFoto(savedId, _lgPendingFoto);
        _lgPendingFoto = null;
    }
    if (_lgPendingRech && savedId) {
        await lgUploadRechnung(savedId, _lgPendingRech);
        _lgPendingRech = null;
    }
    showToast(id ? 'Artikel gespeichert' : 'Artikel angelegt');
    closeLagerDialog();
    renderLager();
}

// ---------- Foto ----------
let _lgPendingFoto = null;
async function lgUploadFoto(artikelId, file) {
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type)) { showToast('Nur JPG, PNG oder WebP erlaubt', 'error'); return false; }
    if (file.size > 5 * 1024 * 1024) { showToast('Max. 5 MB', 'error'); return false; }
    const fd = new FormData();
    fd.append('foto', file);
    const r = await apiUpload(API.lager + '/' + artikelId + '/foto', fd);
    if (!r || !r.success) { showToast(r?.error || 'Upload fehlgeschlagen', 'error'); return false; }
    const a = _lgArtikel.find(x => x.artikel_id === artikelId);
    if (a) a.foto = r.foto;
    return true;
}

async function lgFotoChosen(input, artikelId) {
    const file = input.files && input.files[0];
    if (!file) return;
    const preview = document.getElementById('lgFotoPreview');
    if (artikelId) {
        if (await lgUploadFoto(artikelId, file)) {
            const a = _lgArtikel.find(x => x.artikel_id === artikelId);
            if (preview && a) preview.innerHTML = lgThumb(a, 64);
            showToast('Foto gespeichert');
        }
    } else {
        _lgPendingFoto = file;
        if (preview) preview.innerHTML = `<img class="lg-thumb" src="${URL.createObjectURL(file)}" style="width:64px;height:64px">`;
    }
    input.value = '';
}

async function lgFotoDelete(artikelId) {
    const r = await apiDelete(API.lager + '/' + artikelId + '/foto');
    if (r && r.error) return showToast(r.error, 'error');
    const a = _lgArtikel.find(x => x.artikel_id === artikelId);
    if (a) a.foto = null;
    const preview = document.getElementById('lgFotoPreview');
    if (preview && a) preview.innerHTML = lgThumb(a, 64);
    showToast('Foto entfernt');
}

async function lgDeleteArtikel(id, name) {
    if (!(await mdConfirm(`Artikel <strong>${escapeHtml(name)}</strong> wirklich löschen?`, { title: 'Artikel löschen', confirmLabel: 'Löschen', danger: true }))) return;
    const r = await apiDelete(API.lager + '/' + id);
    if (r && r.error) return showToast(r.error, 'error');
    showToast('Artikel gelöscht');
    renderLager();
}

// ---------- Foto-Anzeige ----------
function openLgFotoView(artikelId) {
    const a = _lgArtikel.find(x => x.artikel_id === artikelId);
    if (!a) return;
    const src = lgFotoUrl(a);
    lgShowDialog(
        escapeHtml(a.name),
        src
            ? `<img class="lg-foto-big" src="${escapeHtml(src)}" alt="${escapeHtml(a.name)}">`
            : `<div class="lg-foto-none">${lgThumb(a, 120)}<p class="lg-muted">Kein Foto hinterlegt</p></div>`,
        `<md-outlined-button onclick="closeLagerDialog()">Schließen</md-outlined-button>`);
}

function lgOpenMaAusgaben(gi) {
    const rows = (_lgAusgabenGroups || [])[gi] || [];
    if (!rows.length) return;
    const o = rows[0];
    const editable = typeof canEdit === 'function' ? canEdit('lager') : false;
    _lgOpenMaKey = (o.mitarbeiter_dienst_nr || '') + '|' + (o.mitarbeiter_name || '');
    lgShowDialog(
        escapeHtml(o.mitarbeiter_name) + ` <span class="lg-muted" style="font-size:.8rem">${escapeHtml(o.mitarbeiter_dienst_nr)}</span>`,
        `<div class="lg-verlauf-list">${rows.map(r => `
            <div class="lg-verlauf-row">
                <span style="flex:1">
                    <strong>${escapeHtml(r.artikel_name || '–')}</strong> · ${r.menge}×${r.einzelpreis != null ? ` <span class="lg-muted">${lgFmtEur(r.menge * r.einzelpreis)}</span>` : ''}
                    <div class="lg-muted" style="font-size:.76rem">${formatDateTimeLocal(r.ausgegeben_am)}${r.ausgegeben_von_name ? ' · von ' + escapeHtml(r.ausgegeben_von_name) : ''}${r.bemerkung ? ' · ' + escapeHtml(r.bemerkung) : ''}</div>
                </span>
                ${editable ? `<md-outlined-button onclick="lgRueckgabe(${r.ausgabe_id},${r.menge})"><md-icon slot="icon">keyboard_return</md-icon>Rückgabe</md-outlined-button>` : ''}
            </div>`).join('')}</div>`,
        `<md-outlined-button onclick="closeLagerDialog()">Schließen</md-outlined-button>`);
}

// ---------- Ausgabe ----------
async function openLgAusgabeDialog(artikelId) {
    const a = _lgArtikel.find(x => x.artikel_id === artikelId);
    if (!a) return;
    if (!_lgMaOpts) {
        _lgMaOpts = await apiGet(API.lager + '/mitarbeiter-options').catch(() => []);
    }
    const verf = Math.max(0, +a.verfuegbar);
    lgShowDialog(
        'Ausgeben — ' + escapeHtml(a.name),
        `<div class="lg-ausgabe-meta">
            ${lgThumb(a, 40)} ${lgKatBadge(a)} <span class="lg-bestand">${verf}<span class="lg-bestand-total">/${a.menge_gesamt} verfügbar</span></span>
        </div>
        <md-outlined-text-field id="f_lg_asearch" label="Mitarbeiter suchen" style="width:100%;margin-bottom:8px" oninput="lgRenderMaList(${artikelId})"><md-icon slot="leading-icon">search</md-icon></md-outlined-text-field>
        <div class="lg-ma-list" id="lgMaList"></div>
        <div class="customer-form-row" style="margin-top:10px">
            <div class="form-group" style="flex:0 0 120px">
                <md-outlined-text-field id="f_lg_amenge" label="Menge" type="number" value="1"></md-outlined-text-field>
            </div>
            <div class="form-group" style="flex:1">
                <md-outlined-text-field id="f_lg_abem" label="Bemerkung (optional)"></md-outlined-text-field>
            </div>
        </div>`,
        `<md-outlined-button onclick="closeLagerDialog()">Abbrechen</md-outlined-button>
         <md-filled-button onclick="lgDoAusgabe(${artikelId})"><md-icon slot="icon">logout</md-icon>Ausgeben</md-filled-button>`);
    lgRenderMaList(artikelId);
}

let _lgMaSelected = null;
function lgRenderMaList(artikelId) {
    const q = String(document.getElementById('f_lg_asearch')?.value ?? '').trim().toLowerCase();
    const a = _lgArtikel.find(x => x.artikel_id === artikelId);
    const list = document.getElementById('lgMaList');
    if (!list) return;
    const rows = _lgMaOpts.filter(w => !q || `${w.vorname || ''} ${w.name || ''} ${w.dienst_nr || ''} ${w.bereich || ''}`.toLowerCase().includes(q));
    list.innerHTML = rows.map(w => {
        const sel = _lgMaSelected === String(w.dienst_nr);
        const initial = (w.vorname || w.name || w.dienst_nr || '?').charAt(0).toUpperCase();
        const avatar = w.foto
            ? `<img src="${w.foto}" alt="" style="width:34px;height:34px;border-radius:50%;object-fit:cover">`
            : `<span class="lg-ma-av">${escapeHtml(initial)}</span>`;
        return `<div class="lg-ma-row ${sel ? 'selected' : ''}" onclick="lgPickMa('${escapeHtml(w.dienst_nr)}',${artikelId})">
            ${avatar}
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;color:var(--gray-800)">${escapeHtml(w.vorname || '')} ${escapeHtml(w.name || '')}</div>
                <div style="font-size:.78rem;color:var(--gray-500)">${escapeHtml(w.dienst_nr)}${w.bereich ? ' · ' + escapeHtml(w.bereich) : ''}</div>
            </div>
            ${sel ? lgIcon('check_circle', 'color:var(--accent);font-size:20px') : ''}
        </div>`;
    }).join('') || '<div class="lg-muted" style="padding:12px;text-align:center">Keine Mitarbeiter gefunden</div>';
}

function lgPickMa(dienstNr, artikelId) {
    _lgMaSelected = _lgMaSelected === String(dienstNr) ? null : String(dienstNr);
    lgRenderMaList(artikelId);
}

async function lgDoAusgabe(artikelId) {
    if (!_lgMaSelected) { showToast('Bitte zuerst einen Mitarbeiter wählen', 'error'); return; }
    const a = _lgArtikel.find(x => x.artikel_id === artikelId);
    const w = _lgMaOpts.find(x => String(x.dienst_nr) === _lgMaSelected);
    const menge = parseInt(document.getElementById('f_lg_amenge')?.value || '1', 10) || 1;
    const verf = Math.max(0, +a.verfuegbar);
    if (menge > verf) { showToast(`Nur ${verf}× verfügbar`, 'error'); lgRenderMaList(artikelId); return; }
    const bemerkung = document.getElementById('f_lg_abem')?.value?.trim() || null;
    const r = await apiPost(API.lager + '/' + artikelId + '/ausgabe', {
        mitarbeiter_dienst_nr: _lgMaSelected, menge, bemerkung,
    });
    if (r && r.error) { showToast(r.error, 'error'); return; }
    showToast(`${menge}× ${a.name} → ${w ? (w.vorname + ' ' + w.name) : _lgMaSelected}`);
    _lgMaSelected = null;
    closeLagerDialog();
    renderLager();
}

// ---------- Rückgabe ----------
async function lgRueckgabe(ausgabeId, maxMenge) {
    lgShowDialog(
        'Rückgabe erfassen',
        `<div class="form-group" style="max-width:200px">
            <md-outlined-text-field id="f_lg_rmenge" label="Menge (max. ${maxMenge})" type="number" value="${maxMenge}"></md-outlined-text-field>
        </div>
        <div class="form-group">
            <div class="lg-prot-label">${lgIcon('upload_file', 'font-size:16px')} Rückgabeprotokoll (PDF oder Foto) *</div>
            <input type="file" id="f_lg_protokoll" accept=".pdf,image/jpeg,image/png,image/webp" class="lg-prot-input" onchange="document.getElementById('lgProtName').textContent = this.files[0] ? this.files[0].name : ''">
            <div id="lgProtName" class="lg-muted" style="font-size:.78rem;margin-top:4px"></div>
        </div>
        <div class="lg-hint">${lgIcon('info', 'font-size:15px')} Teilrückgaben werden automatisch gesplittet.</div>`,
        `<md-outlined-button onclick="closeLagerDialog()">Abbrechen</md-outlined-button>
         <md-filled-button onclick="lgDoRueckgabe(${ausgabeId},${maxMenge})"><md-icon slot="icon">keyboard_return</md-icon>Rückgabe</md-filled-button>`);
}

async function lgDoRueckgabe(ausgabeId, maxMenge) {
    let menge = parseInt(document.getElementById('f_lg_rmenge')?.value || '0', 10);
    if (!menge || menge < 1) menge = maxMenge;
    if (menge > maxMenge) return showToast(`Maximal ${maxMenge} möglich`, 'error');
    const file = document.getElementById('f_lg_protokoll')?.files?.[0];
    if (!file) return showToast('Bitte Rückgabeprotokoll hochladen (PDF oder Foto)', 'error');
    const okTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    if (!okTypes.includes(file.type)) return showToast('Nur PDF, JPG, PNG oder WebP erlaubt', 'error');
    if (file.size > 10 * 1024 * 1024) return showToast('Max. 10 MB', 'error');
    const fd = new FormData();
    fd.append('menge', menge);
    fd.append('protokoll', file);
    const r = await apiUpload(API.lager + '/ausgaben/' + ausgabeId + '/rueckgabe', fd);
    if (!r || !(r.ok || r.success)) return showToast(r?.error || r?.message || 'Rückgabe fehlgeschlagen', 'error');
    showToast(menge === maxMenge ? 'Rückgabe erfasst' : `${menge}× zurückgegeben`);
    const reopen = _lgOpenMaKey;
    closeLagerDialog();
    await renderLager();
    // If the worker's item list was open, show it again (unless nothing is left).
    if (reopen) {
        const gi = (_lgAusgabenGroups || []).findIndex(g =>
            ((g[0].mitarbeiter_dienst_nr || '') + '|' + (g[0].mitarbeiter_name || '')) === reopen);
        if (gi >= 0) lgOpenMaAusgaben(gi);
    }
}

// ---------- Artikel-Verlauf ----------
async function openLgVerlauf(artikelId, name) {
    const r = await apiGet(API.lager + '/' + artikelId);
    const rows = Array.isArray(r?.ausgaben) ? r.ausgaben : [];
    const log = Array.isArray(r?.bestand_log) ? r.bestand_log : [];
    // Bestandsprotokoll: every change of the owned quantity (who / when / why).
    const logHtml = log.length ? `<div class="customer-form-section-title" style="margin:14px 0 6px">${lgIcon('inventory')} Bestandsänderungen</div>
        <div class="lg-verlauf-list">${log.map(l => {
            const diff = (+l.menge_neu) - (+l.menge_alt);
            return `<div class="lg-verlauf-row">
                <span class="lg-zustand ${diff >= 0 ? 'lg-z-gut' : 'lg-z-reparatur'}">${diff >= 0 ? '+' : '−'}${Math.abs(diff)}</span>
                <span style="flex:1"><strong>${+l.menge_alt} → ${+l.menge_neu}</strong>${l.grund ? ' · ' + escapeHtml(l.grund) : ''}</span>
                <span class="lg-muted" style="font-size:.78rem">${formatDateTimeLocal(l.created_at)}${l.user_name ? ' · ' + escapeHtml(l.user_name) : ''}</span>
            </div>`;
        }).join('')}</div>` : '';
    lgShowDialog(
        'Verlauf — ' + escapeHtml(name),
        (rows.length ? `<div class="lg-verlauf-list">${rows.map(o => `
            <div class="lg-verlauf-row">
                <span class="lg-zustand ${o.status === 'offen' ? 'lg-z-reparatur' : 'lg-z-gut'}">${o.status === 'offen' ? 'Offen' : 'Zurück'}</span>
                <span style="flex:1"><strong>${escapeHtml(o.mitarbeiter_name)}</strong> · ${o.menge}×</span>
                <span class="lg-muted" style="font-size:.78rem">${formatDateTimeLocal(o.ausgegeben_am)}${o.rueckgabe_am ? ' → ' + formatDateTimeLocal(o.rueckgabe_am) : ''}</span>
            </div>`).join('')}</div>`
        : '<div class="lg-muted" style="padding:14px;text-align:center">Noch keine Ausgaben für diesen Artikel</div>') + logHtml,
        `<md-outlined-button onclick="closeLagerDialog()">Schließen</md-outlined-button>`);
}

// ---------- Kategorien ----------
async function openLgKategorien() {
    const rows = _lgKategorien.map(k => `
        <div class="lg-kat-row">
            <span class="lg-kat" style="background:${(k.farbe || '#475569')}1f;color:${k.farbe || '#475569'}">${lgIcon(k.icon || 'category', 'font-size:13px')} ${escapeHtml(k.name)}</span>
            <span class="lg-muted" style="font-size:.78rem">${k.artikel_count} Artikel</span>
            <span style="flex:1"></span>
            <md-icon-button onclick="openLgKatForm(${k.id},'${escapeHtml(k.name).replace(/'/g, '')}','${k.icon || 'category'}','${k.farbe || ''}')" title="Bearbeiten"><md-icon>edit</md-icon></md-icon-button>
            <md-icon-button onclick="lgDeleteKat(${k.id},'${escapeHtml(k.name).replace(/'/g, '')}',${k.artikel_count})" title="Löschen" class="lg-danger"><md-icon>delete</md-icon></md-icon-button>
        </div>`).join('') || '<div class="lg-muted" style="padding:10px">Noch keine Kategorien</div>';
    lgShowDialog(
        'Kategorien verwalten',
        `<div class="lg-kat-list">${rows}</div>`,
        `<md-filled-button onclick="openLgKatForm()"><md-icon slot="icon">add</md-icon>Neue Kategorie</md-filled-button>
         <md-outlined-button onclick="closeLagerDialog()">Schließen</md-outlined-button>`);
}

function openLgKatForm(id = null, name = '', icon = 'category', farbe = '') {
    const iconBtns = LG_KAT_ICONS.map(ic =>
        `<button type="button" class="lg-icon-pick ${ic === icon ? 'selected' : ''}" data-icon="${ic}" onclick="lgPickIcon(this)">${lgIcon(ic, 'font-size:18px')}</button>`).join('');
    const farbBtns = LG_KAT_FARBEN.map(c =>
        `<button type="button" class="lg-farb-pick ${c === farbe ? 'selected' : ''}" data-farbe="${c}" onclick="lgPickFarbe(this)" style="background:${c}"></button>`).join('');
    lgShowDialog(
        id ? 'Kategorie bearbeiten' : 'Neue Kategorie',
        `<div class="form-group"><md-outlined-text-field id="f_lg_kname" label="Name *" value="${escapeHtml(name)}"></md-outlined-text-field></div>
         <div class="lg-pick-label">Symbol</div><div class="lg-pick-row" id="lgIconPicks" data-sel="${icon}">${iconBtns}</div>
         <div class="lg-pick-label">Farbe</div><div class="lg-pick-row" id="lgFarbPicks" data-sel="${farbe}">${farbBtns}</div>`,
        `<md-outlined-button onclick="openLgKategorien()"><md-icon slot="icon">arrow_back</md-icon>Zurück</md-outlined-button>
         <md-filled-button onclick="saveLgKat(${id || 'null'})"><md-icon slot="icon">save</md-icon>Speichern</md-filled-button>`);
}

function lgPickIcon(btn) {
    const p = document.getElementById('lgIconPicks');
    p.querySelectorAll('.lg-icon-pick').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected'); p.dataset.sel = btn.dataset.icon;
}
function lgPickFarbe(btn) {
    const p = document.getElementById('lgFarbPicks');
    p.querySelectorAll('.lg-farb-pick').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected'); p.dataset.sel = btn.dataset.farbe;
}

async function saveLgKat(id) {
    const name = document.getElementById('f_lg_kname')?.value?.trim();
    if (!name) return showToast('Name ist erforderlich', 'error');
    const body = {
        name,
        icon: document.getElementById('lgIconPicks')?.dataset.sel || 'category',
        farbe: document.getElementById('lgFarbPicks')?.dataset.sel || null,
    };
    const r = id ? await apiPut(API.lager + '/kategorien/' + id, body) : await apiPost(API.lager + '/kategorien', body);
    if (r && r.error) return showToast(r.error, 'error');
    showToast(id ? 'Kategorie gespeichert' : 'Kategorie angelegt');
    _lgKategorien = await apiGet(API.lager + '/kategorien').catch(() => []);
    openLgKategorien();
}

async function lgDeleteKat(id, name, count) {
    if (count > 0) return showToast(`Kategorie wird von ${count} Artikel(n) verwendet`, 'error');
    if (!(await mdConfirm(`Kategorie <strong>${escapeHtml(name)}</strong> löschen?`, { title: 'Kategorie löschen', confirmLabel: 'Löschen', danger: true }))) return;
    const r = await apiDelete(API.lager + '/kategorien/' + id);
    if (r && r.error) return showToast(r.error, 'error');
    showToast('Kategorie gelöscht');
    _lgKategorien = await apiGet(API.lager + '/kategorien').catch(() => []);
    openLgKategorien();
}
