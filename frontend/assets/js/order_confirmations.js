/* ============================================================
   HMS / Clood-X — Auftragsbestätigungen Module
   Commercial order confirmations (AB) generated from assembled
   Bestelleingang orders. Preview + PDF + sent-tracking.
   Backend: /api/order-confirmations  (auftraege.bestaetigung.*)
   ============================================================ */

const _abState = {
    rows: [],
    search: '',
    filterMonth: '',      // 'YYYY-MM' or ''
    filterStatus: 'alle', // 'alle' | 'bereit' | 'versendet' | 'geaendert'
    filterKunde: '',      // kunde_id or ''
    filterObjekt: '',     // obj_id or ''
};
// Option lists for the Kunde/Objekt filter selects — loaded once per page
// open (active rows only); the Objekt list cascades to the chosen Kunde.
const _abOpts = { kunden: null, objekte: null };

const _abMonths = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

function abIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}
const abSpinner = (size = 32) => `<md-circular-progress indeterminate style="--md-circular-progress-size:${size}px"></md-circular-progress>`;
// The Auftragsbestätigungen page shows hours and service details only —
// no prices (they stay in the customer PDF).
const abHours = (v) => (parseFloat(v) || 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function openAbDialog(title, bodyHtml, footerHtml = '', wide = false) {
    document.getElementById('abMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'abMdDialog';
    dlg.classList.add('oc-context');
    if (wide) dlg.classList.add('oc-dialog-xl');
    dlg.innerHTML = `
        <div slot="headline">${title}</div>
        <div slot="content" class="oc-dialog-content" id="abDialogBody">${bodyHtml}</div>
        ${footerHtml ? `<div slot="actions">${footerHtml}</div>` : ''}`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();
    return dlg;
}
function closeAbDialog() { document.getElementById('abMdDialog')?.close(); }

// ============================================================
// ENTRY POINT
// ============================================================
async function renderOrderConfirmations() {
    const content = document.getElementById('content');
    if (!content) return;

    // Styled topbar title (icon chip + title + sub) replacing the plain
    // text PAGE_TITLES sets — self-cleans on the next navigation because
    // renderPage re-assigns pageTitle.textContent.
    const pt = document.getElementById('pageTitle');
    if (pt) pt.innerHTML = `
        <span class="oc-pt-wrap">
            <span class="oc-pt-stack">
                <span class="oc-pt-title">Auftragsbestätigungen</span>
                <span class="oc-pt-sub">Bestätigte Aufträge an Kunden</span>
            </span>
        </span>`;

    content.innerHTML = `
        <div class="oc-page">
            <div id="abHeaderWrap"></div>
            ${abBuildToolbar()}
            <div id="abMainBody"><div class="oc-loading">${abSpinner(28)} Lade Auftragsbestätigungen...</div></div>
        </div>`;

    abBindToolbar();
    abLoadFilterOptions();
    await abReload();
}

// 'geaendert' is computed per row (content-hash vs. the sent version), so
// it filters the loaded rows client-side; the other chips go to the API.
function abVisibleRows() {
    return _abState.filterStatus === 'geaendert'
        ? _abState.rows.filter(r => r.changed_since_sent)
        : _abState.rows;
}

async function abLoadFilterOptions() {
    try {
        const [k, o] = await Promise.all([
            apiGet(API.customers + '?aktiv=1'),
            apiGet(API.objects + '?aktiv=1'),
        ]);
        _abOpts.kunden  = Array.isArray(k) ? k : [];
        _abOpts.objekte = Array.isArray(o) ? o : [];
    } catch (e) { _abOpts.kunden = _abOpts.objekte = []; }
    abFillFilterSelects();
}

function abFillFilterSelects() {
    const ks = document.getElementById('abKunde');
    if (ks) {
        ks.innerHTML = uiOption('', 'Alle Kunden', !_abState.filterKunde) + (_abOpts.kunden || [])
            .map(c => uiOption(c.kunde_id, c.kundenname || c.kunde_id, c.kunde_id === _abState.filterKunde)).join('');
        ks.value = _abState.filterKunde || '';
    }
    const os = document.getElementById('abObjekt');
    if (os) {
        const list = _abState.filterKunde
            ? (_abOpts.objekte || []).filter(o => o.kunden_id === _abState.filterKunde)
            : (_abOpts.objekte || []);
        if (_abState.filterObjekt && !list.some(o => o.obj_id === _abState.filterObjekt)) _abState.filterObjekt = '';
        os.innerHTML = uiOption('', 'Alle Objekte', !_abState.filterObjekt) + list
            .map(o => uiOption(o.obj_id, o.objektname || o.obj_id, o.obj_id === _abState.filterObjekt)).join('');
        os.value = _abState.filterObjekt || '';
    }
    abUpdateResetBtn();
}

function abHasFilters() {
    return !!(_abState.search || _abState.filterMonth || _abState.filterKunde
        || _abState.filterObjekt || _abState.filterStatus !== 'alle');
}

function abUpdateResetBtn() {
    const b = document.getElementById('abResetFilters');
    if (b) b.hidden = !abHasFilters();
}

async function abReload() {
    let rows = [];
    try {
        const params = new URLSearchParams();
        if (_abState.filterMonth) {
            const [y, m] = _abState.filterMonth.split('-');
            params.set('jahr', y); params.set('monat', parseInt(m, 10));
        }
        if (_abState.filterStatus !== 'alle' && _abState.filterStatus !== 'geaendert') params.set('ab_status', _abState.filterStatus);
        if (_abState.filterKunde) params.set('kunden_id', _abState.filterKunde);
        if (_abState.filterObjekt) params.set('obj_id', _abState.filterObjekt);
        if (_abState.search) params.set('search', _abState.search);
        const qs = params.toString();
        rows = await apiGet(API.order_confirmations + (qs ? '?' + qs : ''));
    } catch (e) {
        document.getElementById('abMainBody').innerHTML =
            uiEmptyState('warning', '', { title: 'Fehler beim Laden' });
        return;
    }
    _abState.rows = Array.isArray(rows) ? rows : [];
    document.getElementById('abHeaderWrap').innerHTML = abBuildHeader();
    abRenderTable();
}

// ============================================================
// HEADER + TOOLBAR
// ============================================================
function abBuildHeader() {
    const rows     = abVisibleRows();
    const total    = rows.length;
    const sent     = rows.filter(r => r.ab_sent_at).length;
    const ready    = total - sent;
    const changed  = rows.filter(r => r.changed_since_sent).length;
    const stdSum   = rows.reduce((s, r) => s + (parseFloat(r.stunden) || 0), 0);
    return `
        <div class="oc-header">
            <div class="oc-header-badges">
                <div class="oc-header-badge">${abIcon('description', 'font-size:12px;vertical-align:-2px')} ${total} gesamt</div>
                <div class="oc-header-badge ready">${abIcon('schedule', 'font-size:12px;vertical-align:-2px')} ${ready} bereit</div>
                <div class="oc-header-badge sent">${abIcon('send', 'font-size:12px;vertical-align:-2px')} ${sent} versendet</div>
                ${changed ? `<div class="oc-header-badge changed">${abIcon('edit_note', 'font-size:12px;vertical-align:-2px')} ${changed} geändert</div>` : ''}
                <div class="oc-header-badge">${abIcon('timer', 'font-size:12px;vertical-align:-2px')} ${abHours(stdSum)} Std. geplant</div>
            </div>
        </div>`;
}

function abBuildToolbar() {
    const label = { alle: 'Alle', bereit: 'Bereit', versendet: 'Versendet', geaendert: 'Geändert' };
    return `
        <div class="oc-toolbar">
            ${uiTextField({ id: 'abSearch', type: 'search', icon: 'search', cls: 'oc-search', value: _abState.search, placeholder: 'Suchen (Nr., Kunde, Objekt, Leistung)...' })}
            ${uiSelect({ id: 'abKunde', cls: 'oc-select', label: 'Kunde', optionsHtml: uiOption('', 'Alle Kunden', true) })}
            ${uiSelect({ id: 'abObjekt', cls: 'oc-select', label: 'Objekt', optionsHtml: uiOption('', 'Alle Objekte', true) })}
            ${uiTextField({ id: 'abMonth', type: 'month', cls: 'oc-month', label: 'Monat', value: _abState.filterMonth })}
            ${uiChips(['alle', 'bereit', 'versendet', 'geaendert'].map(st => uiChip(label[st], { kind: 'filter', selected: _abState.filterStatus === st, attrs: `data-status="${st}"` })).join(''), { cls: 'oc-status-filter' })}
            ${uiIconButton('filter_alt_off', { id: 'abResetFilters', title: 'Alle Filter zurücksetzen', attrs: 'hidden' })}
            ${uiIconButton('refresh', { id: 'abRefresh', title: 'Aktualisieren' })}
        </div>`;
}

function abBindToolbar() {
    let debounce;
    document.getElementById('abSearch')?.addEventListener('input', (e) => {
        clearTimeout(debounce);
        debounce = setTimeout(() => { _abState.search = e.target.value.trim(); abUpdateResetBtn(); abReload(); }, 350);
    });
    document.getElementById('abMonth')?.addEventListener('change', (e) => {
        _abState.filterMonth = e.target.value; abUpdateResetBtn(); abReload();
    });
    document.getElementById('abKunde')?.addEventListener('change', (e) => {
        _abState.filterKunde = e.target.value;
        abFillFilterSelects();
        abReload();
    });
    document.getElementById('abObjekt')?.addEventListener('change', (e) => {
        _abState.filterObjekt = e.target.value; abUpdateResetBtn(); abReload();
    });
    document.querySelectorAll('.oc-status-filter md-filter-chip').forEach(c => c.addEventListener('click', () => {
        _abState.filterStatus = c.dataset.status;
        document.querySelectorAll('.oc-status-filter md-filter-chip').forEach(x => { x.selected = x === c; });
        abUpdateResetBtn();
        abReload();
    }));
    document.getElementById('abResetFilters')?.addEventListener('click', () => {
        Object.assign(_abState, { search: '', filterMonth: '', filterStatus: 'alle', filterKunde: '', filterObjekt: '' });
        const s = document.getElementById('abSearch'); if (s) s.value = '';
        const m = document.getElementById('abMonth');  if (m) m.value = '';
        document.querySelectorAll('.oc-status-filter md-filter-chip').forEach(x => { x.selected = x.dataset.status === 'alle'; });
        abFillFilterSelects();
        abReload();
    });
    document.getElementById('abRefresh')?.addEventListener('click', abReload);
}

// ============================================================
// TABLE
// ============================================================
function abRenderTable() {
    const body = document.getElementById('abMainBody');
    const _p = hmsPagerDef('abPager', 'Auftragsbestätigungen', () => abRenderTable());
    const pg = hmsPage(abVisibleRows(), _p.st);
    const rows = pg.rows;
    const editable = canEdit('auftragsbestaetigung');

    if (!rows.length) {
        body.innerHTML = uiEmptyState('description', abHasFilters() ? 'Für die gewählten Filter gibt es keine Einträge.' : 'Bestellungen erscheinen hier, sobald sie im Bestelleingang angelegt wurden.', { title: 'Keine Auftragsbestätigungen gefunden' });
        return;
    }

    body.innerHTML = `
        <div class="oc-table-card">
            <div class="table-container">
                <table class="oc-table">
                    <thead><tr>
                        <th>Nr.</th><th>Kunde / Objekt</th><th>Zeitraum</th>
                        <th>Leistungen</th><th class="num">Pos.</th><th class="num">Tage</th>
                        <th class="num">Stunden</th>
                        <th>Status</th><th class="acts">Aktionen</th>
                    </tr></thead>
                    <tbody>${rows.map(r => abRowHtml(r, editable)).join('')}</tbody>
                </table>
            </div>
            <div id="abPager" class="be-pager">${hmsPagerHtml('abPager', pg, _p.unit)}</div>
        </div>`;
}

function abRowHtml(r, editable) {
    const sent = !!r.ab_sent_at;
    const sentTitle = sent
        ? `Versendet am ${new Date(r.ab_sent_at).toLocaleString('de-DE')}${r.ab_sent_by_name ? ' von ' + r.ab_sent_by_name : ''}`
        : 'Noch nicht versendet';
    const zeitraum = `${_abMonths[r.monat] || r.monat} ${r.jahr}`;

    return `
        <tr class="${sent ? 'oc-row-sent' : ''}">
            <td>
                <div class="oc-id">${escapeHtml(r.eingang_id)}</div>
                ${r.bestell_id ? `<div class="oc-id-sub">${escapeHtml(r.bestell_id)}</div>` : ''}
            </td>
            <td>
                <div class="oc-kunde">${escapeHtml(r.kundenname || '—')}</div>
                <div class="oc-obj">${escapeHtml(r.objektname || '')}</div>
            </td>
            <td data-label="Zeitraum">${zeitraum}</td>
            <td data-label="Leistungen"><div class="oc-leistungen" title="${escapeHtml(r.leistungen || '')}">${escapeHtml(r.leistungen || '—')}</div></td>
            <td class="num" data-label="Pos.">${r.positions}</td>
            <td class="num" data-label="Tage">${r.tage_count}</td>
            <td class="num" data-label="Stunden"><strong>${parseFloat(r.stunden) > 0 ? abHours(r.stunden) : '–'}</strong></td>
            <td data-label="Status">
                <span class="oc-badges" title="${escapeHtml(sentTitle)}">${uiBadge(sent ? 'Versendet' + (r.ab_version > 1 ? ' · V' + r.ab_version : '') : 'Bereit', sent ? 'success' : 'info', sent ? 'send' : 'schedule')}</span>
                ${sent && r.changed_since_sent ? `<span class="oc-badges" title="Der Auftrag wurde nach dem Versand geändert — die versendete Version bleibt archiviert">${uiBadge('Geändert', 'warning', 'edit_note')}</span>` : ''}
            </td>
            <td class="acts">
                ${uiIconButton('visibility', { title: 'Vorschau', onclick: `abPreview('${r.eingang_id}')` })}
                ${uiIconButton('picture_as_pdf', { title: 'PDF ansehen', onclick: `abViewPdf('${r.eingang_id}')` })}
                ${uiIconButton('download', { title: 'PDF herunterladen', onclick: `abDownloadPdf('${r.eingang_id}')` })}
                ${editable ? (sent
                    ? (r.changed_since_sent ? uiIconButton('forward_to_inbox', { tone: 'success', title: `Neue Version senden (V${(r.ab_version || 1) + 1})`, onclick: `abMarkSent('${r.eingang_id}')` }) : '')
                      + uiIconButton('undo', { tone: 'warning', title: 'Versand zurücknehmen', onclick: `abUnmarkSent('${r.eingang_id}')` })
                    : uiIconButton('send', { tone: 'success', title: 'Als versendet markieren', onclick: `abMarkSent('${r.eingang_id}')` }))
                : ''}
            </td>
        </tr>`;
}

const _abDm = (s) => s ? String(s).slice(8, 10) + '.' + String(s).slice(5, 7) + '.' + String(s).slice(0, 4) : '';

// Summary markup shared by the desktop md-dialog and the mobile sheet.
function _ocDocHtml(d) {
    const m = d.meta || {};
    const k = d.kunde || {};
    const sent = !!d.ab_sent_at;
    return `
        <div class="oc-doc">
            <div class="oc-doc-meta">
                <div><span>Auftrags-Nr.:</span><strong>${escapeHtml(m.auftrags_nr || '—')}</strong></div>
                <div><span>Kunden-Nr.:</span><strong>${escapeHtml(m.kunden_nr || '—')}</strong></div>
                <div><span>Bestell-Nr.:</span><strong>${escapeHtml(m.bestell_nr || '–')}</strong></div>
                <div><span>Zeitraum:</span><strong>${escapeHtml(m.zeitraum || '—')}</strong></div>
                ${m.leistungszeit ? `<div><span>Leistungszeit:</span><strong>${escapeHtml(m.leistungszeit)}</strong></div>` : ''}
                <div><span>Datum:</span><strong>${escapeHtml(m.datum || '—')}${d.frozen ? '' : ' <small style="font-weight:400;color:var(--gray-500)">(Entwurf — beim Versand festgeschrieben)</small>'}</strong></div>
                <div class="oc-doc-bearb"><span>Bearbeiter:</span>${_ocBearbeiterHtml(d)}</div>
                ${d.frozen || (d.versions || []).length ? `<div><span>Version:</span><strong>${d.frozen ? (d.version || 1) + ' (versendet, archiviert)' : (d.next_version || 1) + ' (Entwurf)'}</strong></div>` : ''}
            </div>
            ${d.changed_since_sent ? `<div class="oc-doc-changed">${abIcon('edit_note')} Der Auftrag wurde nach dem Versand geändert. Die versendete Version ${d.version || ''} bleibt unverändert archiviert — „Neue Version senden“ erstellt Version ${((d.version || (d.versions || [])[0]?.version || 0) + 1)}.</div>` : ''}
            <div class="oc-doc-addr">
                <div class="oc-doc-block">
                    <div class="oc-doc-block-title">Empfänger</div>
                    <strong>${escapeHtml(k.name || '')}</strong><br>
                    ${[k.zusatz, k.strasse, k.plz_ort].filter(Boolean).map(escapeHtml).join('<br>')}
                </div>
                ${d.lieferadresse ? `<div class="oc-doc-block">
                    <div class="oc-doc-block-title">Leistungsort</div>${escapeHtml(d.lieferadresse)}</div>` : ''}
            </div>
            <div class="table-container oc-pos-wrap">
            <table class="oc-pos-table">
                <thead><tr><th>Pos.</th><th>Bezeichnung</th><th>Zeitraum</th><th class="num">Tage</th><th class="num">Stunden</th><th class="num">Menge</th><th>Einheit</th></tr></thead>
                <tbody>${(d.positions || []).map((p, i) => `
                    <tr>
                        <td class="oc-pos-nr">${i + 1}</td>
                        <td class="oc-pos-name"><strong>${escapeHtml(p.name)}</strong>
                            <div class="oc-pos-sub">${escapeHtml(p.modell)}${p.bemerkung ? ' · ' + escapeHtml(p.bemerkung) : ''}</div></td>
                        <td data-label="Zeitraum">${p.datum_von ? escapeHtml(_abDm(p.datum_von) + ' – ' + _abDm(p.datum_bis)) : '–'}</td>
                        <td class="num" data-label="Tage">${p.tage_count || '–'}</td>
                        <td class="num" data-label="Stunden"><strong>${parseFloat(p.stunden) > 0 ? abHours(p.stunden) : '–'}</strong></td>
                        <td class="num" data-label="Menge">${abHours(p.menge)}</td>
                        <td data-label="Einheit">${escapeHtml(p.einheit)}</td>
                    </tr>`).join('')}
                </tbody>
            </table>
            </div>
            <div class="oc-doc-totals">
                <div><span>Einsatztage</span><span>${(d.positions || []).reduce((s, p) => s + (parseInt(p.tage_count, 10) || 0), 0)}</span></div>
                <div class="grand"><span>Gesamtstunden</span><span>${abHours((d.positions || []).reduce((s, p) => s + (parseFloat(p.stunden) || 0), 0))} Std.</span></div>
            </div>
            ${_ocNotesHtml(d)}
            <div class="oc-doc-status ${sent ? 'sent' : 'ready'}">
                ${abIcon(sent ? 'send' : 'schedule')}
                ${sent ? `Versendet am ${new Date(d.ab_sent_at).toLocaleString('de-DE')}${d.ab_sent_by_name ? ' von ' + escapeHtml(d.ab_sent_by_name) : ''}` : 'Noch nicht an den Kunden versendet'}
            </div>
            ${(d.versions || []).length ? `<div class="oc-doc-versions">
                <div class="oc-doc-block-title">Archivierte Versionen</div>
                ${d.versions.map(v => `<div class="oc-doc-version">
                    ${abIcon('inventory_2', 'font-size:16px')}
                    <span><strong>Version ${v.version}</strong> · versendet am ${new Date(String(v.sent_at).replace(' ', 'T')).toLocaleString('de-DE')}${v.sent_by_name ? ' von ' + escapeHtml(v.sent_by_name) : ''}</span>
                    ${uiIconButton('picture_as_pdf', { title: 'Archivierte PDF öffnen', onclick: `abOpenArchive('${escapeHtml(d.eingang_id)}', ${parseInt(v.version, 10)})` })}
                </div>`).join('')}
            </div>` : ''}
        </div>`;
}

// "Bearbeiter" = responsible person of the order. Automatic default: the
// customer's Kundenbetreuer (Stammdaten → Kunden), else the order's creator.
// Editable for Auftragsbestätigung editors; a sent version stays frozen —
// a change only takes effect with the next version.
const _AB_BEARB_SOURCE = { kundenbetreuer: 'Kundenbetreuer', ersteller: 'Ersteller des Auftrags', aktuell: 'Ersteller des Auftrags' };
let _abBearbeiterOpts = null;
async function _abLoadBearbeiter() {
    if (_abBearbeiterOpts) return _abBearbeiterOpts;
    try {
        const r = await apiGet(API_BASE + '/order-confirmation-bearbeiter');
        _abBearbeiterOpts = Array.isArray(r) ? r : [];
    } catch (e) { _abBearbeiterOpts = []; }
    return _abBearbeiterOpts;
}

function _ocBearbeiterHtml(d) {
    const name = escapeHtml((d.meta && d.meta.bearbeiter) || '—');
    if (!canEdit('auftragsbestaetigung') || !Array.isArray(_abBearbeiterOpts) || !_abBearbeiterOpts.length) return `<strong>${name}</strong>`;
    const cur = d.bearbeiter_chosen ? String(d.bearbeiter_id || '') : '';
    // Automatic option names its live source (Kundenbetreuer / Ersteller).
    const src = _AB_BEARB_SOURCE[d.bearbeiter_source] || 'Kundenbetreuer bzw. Ersteller';
    const autoName = !cur && !d.frozen && d.meta && d.meta.bearbeiter ? ': ' + name : '';
    return `${uiSelect({ cls: 'oc-bearb-select', attrs: `title="Zuständiger Bearbeiter dieses Auftrags — automatisch: Kundenbetreuer des Kunden, sonst Ersteller" onchange="abSetBearbeiter('${escapeHtml(d.eingang_id)}', this.value)"`,
        optionsHtml: `<md-select-option value=""${cur ? '' : ' selected'}><div slot="headline">Automatisch (${escapeHtml(cur ? 'Kundenbetreuer bzw. Ersteller' : src)}${autoName})</div></md-select-option>`
            + _abBearbeiterOpts.map(o => uiOption(parseInt(o.id, 10), o.name, String(o.id) === cur)).join('') })}${d.frozen ? ` <small style="color:var(--gray-500)">versendet mit: ${name}</small>` : ''}`;
}

async function abSetBearbeiter(id, userId) {
    const res = await apiPut(API.order_confirmations + '/' + encodeURIComponent(id) + '/bearbeiter', { user_id: userId ? parseInt(userId, 10) : null });
    if (!res || !res.success) { showToast((res && res.error) || 'Bearbeiter konnte nicht gespeichert werden', 'error'); return; }
    _abPdfCache.id = null;
    showToast('Bearbeiter gespeichert');
    if (document.getElementById('ocPdfViewer')?.open) ocOpenSummary(id); else abPreview(id);
    abReload();
}

// Bemerkungen of the Auftragsbestätigung — editable for AB editors. The
// text replaces the automatic notes (Bestelleingang Bemerkung + Objekt
// Bestellbeschreibung); emptying it switches back to them. A sent version
// stays frozen — the change shows up as "geändert seit Versand".
function _ocNotesHtml(d) {
    const live = Array.isArray(d.bemerkungen_live) ? d.bemerkungen_live : (d.bemerkungen || []);
    const fmt = arr => arr.map(b => escapeHtml(b).replace(/\n/g, '<br>')).join('<br><br>');
    if (!canEdit('auftragsbestaetigung')) {
        return (d.bemerkungen || []).length ? `<div class="oc-doc-notes"><div class="oc-doc-block-title">Bemerkungen</div>${fmt(d.bemerkungen)}</div>` : '';
    }
    return `<div class="oc-doc-notes">
        <div class="oc-doc-block-title">Bemerkungen</div>
        <md-outlined-text-field id="ocNotesInput" class="oc-notes-input" type="textarea" rows="4" maxlength="5000" placeholder="Bemerkungen für den Kunden…"
            value="${escapeHtml(live.join('\n\n'))}" oninput="document.getElementById('ocNotesSave').disabled=false"></md-outlined-text-field>
        <div class="oc-notes-actions">
            <span>${d.frozen ? 'Gilt ab der nächsten Version — die versendete bleibt archiviert.' : 'Erscheint so in der Auftragsbestätigung (PDF).'}</span>
            ${uiButton('Speichern', { variant: 'tonal', size: 'sm', id: 'ocNotesSave', disabled: true, onclick: `abSaveBemerkung('${escapeHtml(d.eingang_id)}')` })}
        </div>
    </div>`;
}

async function abSaveBemerkung(id) {
    const ta = document.getElementById('ocNotesInput');
    if (!ta) return;
    const res = await apiPut(API.order_confirmations + '/' + encodeURIComponent(id) + '/bemerkung', { text: ta.value });
    if (!res || !res.success) { showToast((res && res.error) || 'Bemerkungen konnten nicht gespeichert werden', 'error'); return; }
    _abPdfCache.id = null;
    showToast(res.automatic ? 'Bemerkungen: automatischer Text aus dem Auftrag' : 'Bemerkungen gespeichert');
    if (document.getElementById('ocPdfViewer')?.open) ocOpenSummary(id); else abPreview(id);
    abReload();
}

function _abRow(id) { return _abState.rows.find(r => r.eingang_id === id) || null; }

// Archived PDF (exactly as sent) — opened in a tab / the in-app viewer.
async function _abFetchArchive(id, version) {
    const res = await fetch(API.order_confirmations + '/' + encodeURIComponent(id) + '/archive' + (version ? '?version=' + version : ''),
        { headers: { 'Authorization': 'Bearer ' + localStorage.getItem('hms_token'), 'Accept': 'application/pdf' } });
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'Archiv nicht gefunden');
    return res.blob();
}
async function abOpenArchive(id, version) {
    const tab = _ocIsMobile() ? null : window.open('', '_blank');
    try {
        if (_ocIsMobile()) {
            const res = await apiGet(API.order_confirmations + '/' + encodeURIComponent(id) + '/pdf-link?archive=1');
            if (!res || !res.url) throw new Error((res && res.error) || 'Link konnte nicht erzeugt werden');
            if (document.documentElement.classList.contains('app-shell') && _ocNativeOpen(res.url)) return;
            window.location.href = res.url;
            return;
        }
        const url = URL.createObjectURL(await _abFetchArchive(id, version));
        if (tab) tab.location.href = url; else window.open(url, '_blank');
    } catch (e) {
        if (tab) tab.close();
        showToast(e.message || 'PDF-Fehler', 'error');
    }
}

// ============================================================
// PREVIEW MODAL — same data the PDF renders
// ============================================================
async function abPreview(id) {
    // Touch devices: the same summary as a fullscreen in-app sheet (plain
    // scroll container) instead of the md-dialog.
    if (_ocIsMobile()) return ocOpenSummary(id);
    const dlg = openAbDialog('Auftragsbestätigung — ' + escapeHtml(id),
        `<div class="oc-loading">${abSpinner(24)} Lade...</div>`, '', true);
    let d;
    try {
        d = await apiGet(API.order_confirmations + '/' + encodeURIComponent(id));
    } catch (e) {
        document.getElementById('abDialogBody').innerHTML = uiEmptyState('warning', '', { title: 'Nicht gefunden' });
        return;
    }
    if (!d || d.error) {
        document.getElementById('abDialogBody').innerHTML = uiEmptyState('warning', '', { title: d?.error || 'Nicht gefunden' });
        return;
    }

    const editable = canEdit('auftragsbestaetigung');
    const sent = !!d.ab_sent_at;
    if (editable) await _abLoadBearbeiter();

    document.getElementById('abDialogBody').innerHTML = _ocDocHtml(d);

    const footer = `
        <md-text-button onclick="closeAbDialog()">Schließen</md-text-button>
        ${editable ? (sent
            ? `${d.changed_since_sent ? `<md-outlined-button onclick="abMarkSent('${d.eingang_id}')"><md-icon slot="icon">forward_to_inbox</md-icon>Neue Version senden</md-outlined-button>` : ''}<md-outlined-button onclick="abUnmarkSent('${d.eingang_id}')"><md-icon slot="icon">undo</md-icon><span class="oc-lbl-long">Versand zurücknehmen</span><span class="oc-lbl-short">Zurücknehmen</span></md-outlined-button>`
            : `<md-outlined-button onclick="abMarkSent('${d.eingang_id}')"><md-icon slot="icon">send</md-icon><span class="oc-lbl-long">Als versendet markieren</span><span class="oc-lbl-short">Versendet</span></md-outlined-button>`)
        : ''}
        <md-outlined-button onclick="abShowArchiv('${d.eingang_id}')"><md-icon slot="icon">history</md-icon>Verlauf</md-outlined-button>
        <md-outlined-button onclick="abDownloadPdf('${d.eingang_id}')"><md-icon slot="icon">download</md-icon>Download</md-outlined-button>
        <md-filled-button onclick="abViewPdf('${d.eingang_id}')"><md-icon slot="icon">picture_as_pdf</md-icon>PDF ansehen</md-filled-button>`;
    dlg.querySelector('[slot="actions"]')?.remove();
    const actions = document.createElement('div');
    actions.setAttribute('slot', 'actions');
    actions.className = 'oc-dlg-actions';
    actions.innerHTML = footer;
    dlg.appendChild(actions);
}

// ============================================================
// PDF actions — fetch blob with Bearer token (same pattern as
// downloadSavedPdfFromList in orders.js)
// ============================================================
// Phones + app WebView: blob: PDFs can't be shown there and a navigation
// can't carry the Bearer header — use a 10-minute signed link instead and
// hand it to the system viewer (app: Capacitor Browser plugin = Chrome
// Custom Tab / SFSafariViewController; browser: new tab / download).
const _ocIsMobile = () => window.matchMedia('(max-width: 900px)').matches
    || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

function _ocNativeOpen(url) {
    if (typeof window.hmsNativeCall === 'function'
        && window.hmsNativeCall('Browser', 'open', { url })) return true;
    const ios = window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.bridge;
    if (ios) {
        try {
            ios.postMessage({ type: 'message', callbackId: 'oc' + Date.now(), pluginId: 'Browser', methodName: 'open', options: { url } });
            return true;
        } catch (e) { /* fall back to plain navigation */ }
    }
    return false;
}

async function _ocOpenSigned(id, download) {
    // Pre-open the tab synchronously — popup blockers only allow window.open
    // inside the click handler, not after the awaited fetch.
    const inApp = document.documentElement.classList.contains('app-shell');
    const tab = (!download && !inApp) ? window.open('', '_blank') : null;
    try {
        // Sent → signed link straight to the archived version.
        const row = _abRow(id);
        if (row && row.ab_sent_at && row.ab_version) {
            const res = await apiGet(API.order_confirmations + '/' + encodeURIComponent(id) + '/pdf-link?archive=1' + (download ? '&download=1' : ''));
            if (!res || !res.url) throw new Error(res?.error || 'Link konnte nicht erzeugt werden');
            if (inApp && _ocNativeOpen(res.url)) return;
            if (tab) { tab.location.href = res.url; return; }
            window.location.href = res.url;
            return;
        }
        // The PDF is rendered client-side by the editor (company template),
        // then parked on the server behind a short-lived signed link.
        const blob = await _abFetchPdf(id);
        if (download) _abArchive(id, blob, 'download');
        const fd = new FormData();
        fd.append('pdf', blob, 'Auftragsbestaetigung.pdf');
        if (download) fd.append('download', '1');
        const r = await fetch(window.location.origin + '/api/order-confirmations/' + encodeURIComponent(id) + '/pdf-link', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + localStorage.getItem('hms_token'), 'Accept': 'application/json' },
            body: fd,
        });
        let res = await r.json().catch(() => null);
        // Upload refused (e.g. read-only demo account) → server-rendered link.
        if (!r.ok || !res || !res.url) {
            res = await apiGet(API.order_confirmations + '/' + encodeURIComponent(id) + '/pdf-link' + (download ? '?download=1' : ''));
        }
        if (!res || !res.url) throw new Error(res?.error || 'Link konnte nicht erzeugt werden');
        if (inApp && _ocNativeOpen(res.url)) return;
        if (tab) { tab.location.href = res.url; return; }
        window.location.href = res.url;
    } catch (e) {
        if (tab) tab.close();
        showToast(e.message || 'PDF-Fehler', 'error');
    }
}

// Files a downloaded / shared (not yet sent) AB in the PDF archive —
// sent versions are archived by abMarkSent already. Best-effort.
function _abArchive(id, blob, aktion) {
    const row = _abRow(id);
    if (!blob || (row && row.ab_sent_at && row.ab_version)) return;
    const fd = new FormData();
    fd.append('pdf', blob, 'Auftragsbestaetigung.pdf');
    fd.append('doc_type', 'ab');
    fd.append('ref_id', id);
    fd.append('aktion', aktion);
    fd.append('label', 'Auftragsbestätigung ' + (row?.bestell_id || id));
    fetch(window.location.origin + '/api/dokument-archiv', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + localStorage.getItem('hms_token'), 'Accept': 'application/json' },
        body: fd,
    }).catch(() => {});
}

// Verlauf: every downloaded / shared / sent PDF of this order (dokument_archiv
// + the sent versions), newest first, each downloadable.
const _AB_AKTION = { download: 'Heruntergeladen', druck: 'Gedruckt', teilen: 'Geteilt', senden: 'Gesendet', versendet: 'An Kunden versendet' };
let _abArchivRows = [];
async function abShowArchiv(id) {
    const row = _abRow(id);
    openAbDialog('Verlauf · ' + escapeHtml(row?.bestell_id || id), `<div class="oc-loading">${abSpinner(28)} Lade…</div>`,
        `<md-text-button onclick="closeAbDialog()">Schließen</md-text-button>`);
    const body = document.getElementById('abDialogBody');
    try {
        const r = await fetch(window.location.origin + '/api/dokument-archiv?doc_type=ab&ref_id=' + encodeURIComponent(id),
            { headers: { 'Authorization': 'Bearer ' + localStorage.getItem('hms_token'), 'Accept': 'application/json' } });
        const d = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(d.error || ('HTTP ' + r.status));
        _abArchivRows = d.entries || [];
        if (!body) return;
        body.innerHTML = _abArchivRows.length ? _abArchivRows.map((e, i) => `
            <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom: 1px solid var(--md-sys-color-outline-variant,var(--md-sys-color-primary-container))">
                ${abIcon('picture_as_pdf')}
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600">${e.version ? 'Version ' + parseInt(e.version, 10) + ' · ' : ''}${escapeHtml(_AB_AKTION[e.aktion] || e.aktion)}</div>
                    <div style="font-size:.8rem;opacity:.7">${e.at ? escapeHtml(new Date(String(e.at).replace(' ', 'T')).toLocaleString('de-DE')) : '–'}${e.by ? ' · ' + escapeHtml(e.by) : ''}</div>
                </div>
                ${uiIconButton('download', { title: 'Herunterladen', onclick: `abDownloadArchiv(${i})` })}
            </div>`).join('')
            : uiEmptyState('history', 'Jeder Download, jede Weitergabe und jeder Versand wird hier automatisch gesichert.', { title: 'Noch keine archivierten PDFs' });
    } catch (e) {
        if (body) body.innerHTML = uiEmptyState('warning', '', { title: e.message || 'Verlauf konnte nicht geladen werden' });
    }
}
async function abDownloadArchiv(i) {
    const e = _abArchivRows[i];
    if (!e || typeof e.download !== 'string' || !/^\/(dokument-archiv|order-confirmations)\/[A-Za-z0-9_%-]+(\/[a-z]+)?(\?[A-Za-z0-9=&_-]*)?$/.test(e.download)) return;
    try {
        const r = await fetch(window.location.origin + '/api' + e.download, { headers: { 'Authorization': 'Bearer ' + localStorage.getItem('hms_token') } });
        if (!r.ok) throw new Error('Datei nicht gefunden');
        const url = URL.createObjectURL(await r.blob());
        const a = document.createElement('a');
        a.href = url;
        a.download = 'Auftragsbestaetigung_' + String(e.label || '').replace(/[^A-Za-z0-9_-]+/g, '_').slice(0, 60) + (e.version ? '_V' + e.version : '') + '.pdf';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    } catch (err) { showToast(err.message || 'Download fehlgeschlagen', 'error'); }
}

// Renders the Auftragsbestätigung with the PDF editor (headless) — the same
// renderer + company layout template as "Bearbeiten", so the list's view /
// download / share PDF always matches the editor. Short cache so view →
// download / share does not render twice.
const _abPdfCache = { id: null, blob: null, at: 0 };
async function _abFetchPdf(id) {
    // Sent → the archived binary that went to the customer (never re-rendered).
    const row = _abRow(id);
    if (row && row.ab_sent_at && row.ab_version) return _abFetchArchive(id, row.ab_version);
    if (_abPdfCache.id === id && _abPdfCache.blob && Date.now() - _abPdfCache.at < 20000) return _abPdfCache.blob;
    const blob = await hmsWithHeadlessEditor('/order_form.php?ab=' + encodeURIComponent(id), api => api.pdfBlob());
    Object.assign(_abPdfCache, { id, blob, at: Date.now() });
    return blob;
}

// ============================================================
// PDF editor (order_form.php?ab=<id>) — the same editor as the
// Leistungsnachweis: edit texts, insert elements, signature/stamp,
// branding, download/save. Opens in a fullscreen in-app <dialog> with an
// iframe (same contract as orders.js openOrderFormModal: #orFormModal /
// #orFormFrame + window.closeOrderFormModal for the editor's back arrow).
// ============================================================
// No "Bearbeiten" entry on this page any more: the Auftragsbestätigung
// layout is edited centrally under System → Dokument-Design (company
// template). Kept for that page / deep links.
function ocOpenEditor(id) {
    _abPdfCache.id = null;   // edits / template changes → never serve the old render
    openPdfEditorModal('/order_form.php?ab=' + encodeURIComponent(id) + '&view=1&embed=1');
}

// ============================================================
// In-app PDF viewer (phones / tablets / app WebView)
// The real PDF rendered page-by-page with PDF.js into a fullscreen native
// <dialog> (same top-layer pattern as orders.js ensureOrderFormModal) —
// no external tab, no link, its own plain scroll container.
// ============================================================
const _OC_PDFJS = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/legacy/build/';
let _ocPdfJsPromise = null;
function _ocLoadPdfJs() {
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (_ocPdfJsPromise) return _ocPdfJsPromise;
    _ocPdfJsPromise = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = _OC_PDFJS + 'pdf.min.js';
        s.onload = () => {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = _OC_PDFJS + 'pdf.worker.min.js';
            resolve(window.pdfjsLib);
        };
        s.onerror = () => { _ocPdfJsPromise = null; reject(new Error('PDF-Viewer konnte nicht geladen werden')); };
        document.head.appendChild(s);
    });
    return _ocPdfJsPromise;
}

// mode: 'summary' (data overview) | 'pdf' (rendered PDF); fromSummary lets
// the back arrow in the PDF return to the overview it was opened from.
const _ocViewer = { id: null, pdf: null, zoom: 1, blob: null, token: 0, mode: 'pdf', fromSummary: false };

function _ocEnsureViewer() {
    let dlg = document.getElementById('ocPdfViewer');
    if (dlg) return dlg;
    dlg = document.createElement('dialog');
    dlg.id = 'ocPdfViewer';
    dlg.className = 'oc-viewer';
    dlg.innerHTML = `
        <div class="oc-viewer-bar">
            ${uiIconButton('arrow_back', { title: 'Schließen', attrs: 'data-act="close"' })}
            <div class="oc-viewer-title"><span id="ocViewerTitle">Auftragsbestätigung</span><small id="ocViewerSub"></small></div>
            ${uiIconButton('zoom_out', { title: 'Verkleinern', attrs: 'data-act="zoom-out"' })}
            ${uiIconButton('zoom_in', { title: 'Vergrößern', attrs: 'data-act="zoom-in"' })}
        </div>
        <div class="oc-viewer-body" id="ocViewerBody"></div>
        <div class="oc-viewer-actions" id="ocViewerActions"></div>`;
    dlg.addEventListener('click', (e) => {
        const b = e.target.closest('[data-act]');
        if (!b) return;
        const act = b.dataset.act;
        if (act === 'close') {
            if (_ocViewer.mode === 'pdf' && _ocViewer.fromSummary) ocOpenSummary(_ocViewer.id);
            else dlg.close();
        }
        else if (act === 'pdf') ocOpenPdfViewer(_ocViewer.id, true);
        else if (act === 'edit') ocOpenEditor(_ocViewer.id);
        else if (act === 'zoom-in' || act === 'zoom-out') {
            _ocViewer.zoom = Math.min(3, Math.max(1, _ocViewer.zoom + (act === 'zoom-in' ? 0.5 : -0.5)));
            _ocRenderPages();
        }
        else if (act === 'download') abDownloadPdf(_ocViewer.id);
        else if (act === 'share') _ocSharePdf();
        else if (act === 'sent') abMarkSent(_ocViewer.id);
        else if (act === 'unsent') abUnmarkSent(_ocViewer.id);
    });
    dlg.addEventListener('close', () => {
        _ocViewer.token++;
        if (_ocViewer.pdf) { try { _ocViewer.pdf.destroy(); } catch (e) {} }
        _ocViewer.pdf = null; _ocViewer.blob = null;
        document.getElementById('ocViewerBody').innerHTML = '';
    });
    document.body.appendChild(dlg);
    return dlg;
}

function _ocViewerActionsHtml(row) {
    const editable = canEdit('auftragsbestaetigung');
    const sent = !!(row && row.ab_sent_at);
    const act = (label, icon, a, primary = false) => uiButton(label, { variant: primary ? 'filled' : 'outlined', icon, attrs: `data-act="${a}"` });
    if (_ocViewer.mode === 'summary') {
        return (editable ? (sent
            ? (row.changed_since_sent ? act('Neue Version', 'forward_to_inbox', 'sent') : '') + act('Zurücknehmen', 'undo', 'unsent')
            : act('Versendet', 'send', 'sent')) : '')
            + act('PDF ansehen', 'picture_as_pdf', 'pdf', true);
    }
    const canShare = !!(navigator.canShare && window.File
        && navigator.canShare({ files: [new File([''], 'x.pdf', { type: 'application/pdf' })] }));
    return (editable ? (sent ? act('Zurücknehmen', 'undo', 'unsent') : act('Versendet', 'send', 'sent')) : '')
        + (canShare ? act('Teilen', 'share', 'share') : '')
        + act('Download', 'download', 'download', true);
}

function _ocViewerReset(id, mode, fromSummary) {
    const dlg = _ocEnsureViewer();
    _ocViewer.token++;
    if (_ocViewer.pdf) { try { _ocViewer.pdf.destroy(); } catch (e) {} }
    Object.assign(_ocViewer, { id, mode, fromSummary: !!fromSummary, zoom: 1, pdf: null, blob: null });
    dlg.classList.toggle('mode-summary', mode === 'summary');
    const body = document.getElementById('ocViewerBody');
    body.classList.remove('zoomed');
    body.scrollTop = 0;
    return { dlg, row: _abState.rows.find(r => r.eingang_id === id) || null, token: _ocViewer.token };
}

async function ocOpenSummary(id) {
    const { dlg, row, token } = _ocViewerReset(id, 'summary');
    document.getElementById('ocViewerTitle').textContent = 'Übersicht';
    document.getElementById('ocViewerSub').textContent = [row?.bestell_id || id, row?.kundenname].filter(Boolean).join(' · ');
    document.getElementById('ocViewerActions').innerHTML = _ocViewerActionsHtml(row);
    const body = document.getElementById('ocViewerBody');
    body.innerHTML = `<div class="oc-loading">${abSpinner(28)} Lade...</div>`;
    if (!dlg.open) dlg.showModal();
    let d;
    try { d = await apiGet(API.order_confirmations + '/' + encodeURIComponent(id)); } catch (e) { d = null; }
    if (canEdit('auftragsbestaetigung')) await _abLoadBearbeiter();
    if (token !== _ocViewer.token) return;
    if (!d || d.error) {
        body.innerHTML = uiEmptyState('warning', '', { title: d?.error || 'Nicht gefunden' });
        return;
    }
    body.innerHTML = `<div class="oc-viewer-sheet">${_ocDocHtml(d)}</div>`;
    document.getElementById('ocViewerActions').innerHTML = _ocViewerActionsHtml(d);
}

async function ocOpenPdfViewer(id, fromSummary) {
    const { dlg, row, token } = _ocViewerReset(id, 'pdf', fromSummary);
    document.getElementById('ocViewerTitle').textContent = 'Auftragsbestätigung';
    document.getElementById('ocViewerSub').textContent = [row?.bestell_id || id, row?.kundenname].filter(Boolean).join(' · ');
    document.getElementById('ocViewerActions').innerHTML = _ocViewerActionsHtml(row);
    document.getElementById('ocViewerBody').innerHTML = `<div class="oc-loading">${abSpinner(28)} PDF wird geladen...</div>`;
    if (!dlg.open) dlg.showModal();
    try {
        const [lib, blob] = await Promise.all([_ocLoadPdfJs(), _abFetchPdf(id, false)]);
        const pdf = await lib.getDocument({ data: await blob.arrayBuffer() }).promise;
        if (token !== _ocViewer.token) { pdf.destroy(); return; }
        Object.assign(_ocViewer, { pdf, blob });
        await _ocRenderPages();
    } catch (e) {
        if (token !== _ocViewer.token) return;
        document.getElementById('ocViewerBody').innerHTML =
            uiEmptyState('warning', '', { title: e.message || 'PDF konnte nicht geladen werden' });
    }
}

async function _ocRenderPages() {
    const pdf = _ocViewer.pdf;
    const body = document.getElementById('ocViewerBody');
    if (!pdf || !body) return;
    const token = _ocViewer.token;
    const dpr = Math.min(window.devicePixelRatio || 1, 3);
    const fitWidth = Math.max(240, body.clientWidth - 16);
    const frag = document.createDocumentFragment();
    const canvases = [];
    for (let n = 1; n <= pdf.numPages; n++) {
        const page = await pdf.getPage(n);
        const base = page.getViewport({ scale: 1 });
        const cssW = fitWidth * _ocViewer.zoom;
        const vp = page.getViewport({ scale: (cssW / base.width) * dpr });
        const canvas = document.createElement('canvas');
        canvas.className = 'oc-viewer-page';
        canvas.width = Math.floor(vp.width);
        canvas.height = Math.floor(vp.height);
        canvas.style.width = Math.floor(cssW) + 'px';
        canvas.style.height = Math.floor(vp.height / dpr) + 'px';
        canvases.push([page, vp, canvas]);
        frag.appendChild(canvas);
    }
    if (token !== _ocViewer.token) return;
    body.innerHTML = '';
    body.classList.toggle('zoomed', _ocViewer.zoom > 1);
    body.appendChild(frag);
    for (const [page, vp, canvas] of canvases) {
        if (token !== _ocViewer.token) return;
        await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    }
}

async function _ocSharePdf() {
    const row = _abState.rows.find(r => r.eingang_id === _ocViewer.id);
    const name = 'Auftragsbestaetigung_' + (row?.bestell_id || _ocViewer.id) + '.pdf';
    try {
        const blob = _ocViewer.blob || await _abFetchPdf(_ocViewer.id, false);
        _abArchive(_ocViewer.id, blob, 'teilen');
        await navigator.share({ files: [new File([blob], name, { type: 'application/pdf' })], title: name });
    } catch (e) {
        if (e && e.name !== 'AbortError') showToast(e.message || 'Teilen fehlgeschlagen', 'error');
    }
}

async function abViewPdf(id) {
    if (_ocIsMobile()) return ocOpenPdfViewer(id);
    // Pre-open the tab inside the click (popup blockers), fill it after the render.
    const tab = window.open('', '_blank');
    try {
        showToast('PDF wird erstellt…');
        const url = URL.createObjectURL(await _abFetchPdf(id));
        if (tab) tab.location.href = url; else window.open(url, '_blank');
    } catch (e) {
        if (tab) tab.close();
        showToast(e.message || 'PDF-Fehler', 'error');
    }
}

async function abDownloadPdf(id) {
    if (_ocIsMobile()) return _ocOpenSigned(id, true);
    try {
        showToast('PDF wird erstellt…');
        const blob = await _abFetchPdf(id);
        _abArchive(id, blob, 'download');
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'Auftragsbestaetigung_' + id + '.pdf';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    } catch (e) { showToast(e.message || 'PDF-Fehler', 'error'); }
}

// ============================================================
// Sent flag
// ============================================================
function _ocRefreshViewerActions(id, sent) {
    const dlg = document.getElementById("ocPdfViewer");
    if (!dlg || !dlg.open || _ocViewer.id !== id) return;
    // Overview shows the sent status block too → re-render it entirely.
    if (_ocViewer.mode === 'summary') return ocOpenSummary(id);
    document.getElementById("ocViewerActions").innerHTML =
        _ocViewerActionsHtml({ ab_sent_at: sent ? 1 : null });
}

// Sending freezes the document: the editor renders the CURRENT data
// (?live=1) with the company template, the server archives that exact PDF
// + the data (date of issue, Bearbeiter, positions) as a new version.
// Later downloads always return the archived file.
async function abMarkSent(id) {
    const row = _abRow(id);
    const resend = !!(row && row.ab_sent_at);
    const ok = await mdConfirm(resend
        ? 'Neue Version der Auftragsbestätigung erstellen und als versendet markieren?<br><br>Die bisher versendete Version bleibt unverändert im Archiv.'
        : 'Auftragsbestätigung als an den Kunden versendet markieren?<br><br>Das Dokument wird dabei <b>archiviert</b> (Datum, Bearbeiter, Positionen) und bleibt danach unverändert — auch wenn sich der Auftrag später ändert.',
        resend ? 'Neue Version senden' : 'Als versendet markieren');
    if (!ok) return;
    showToast('Dokument wird archiviert…');
    let res;
    try {
        const r = await hmsWithHeadlessEditor('/order_form.php?ab=' + encodeURIComponent(id) + '&live=1', async api => {
            const b = await api.pdfBlob();
            // Copy into THIS window before the iframe is removed (see app.js).
            return { blob: new Blob([await b.arrayBuffer()], { type: 'application/pdf' }), data: api.data() };
        });
        const fd = new FormData();
        fd.append('pdf', r.blob, 'Auftragsbestaetigung.pdf');
        fd.append('content_hash', (r.data && r.data.content_hash) || '');
        const http = await fetch(API.order_confirmations + '/' + encodeURIComponent(id) + '/sent', {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + localStorage.getItem('hms_token'), 'Accept': 'application/json' },
            body: fd,
        });
        res = await http.json().catch(() => ({ error: 'HTTP ' + http.status }));
    } catch (e) {
        res = { error: e.message || 'PDF konnte nicht erstellt werden' };
    }
    _abPdfCache.id = null;
    if (res && res.success) {
        showToast(res.version ? 'Versendet — Version ' + res.version + ' archiviert' : 'Als versendet markiert');
        closeAbDialog();
        await abReload();
        _ocRefreshViewerActions(id, true);
    } else {
        showToast(res?.error || 'Fehler', 'error');
    }
}

async function abUnmarkSent(id) {
    const row = _abRow(id);
    const ok = await mdConfirm('Versand wirklich zurücknehmen?' + (row && row.ab_version
        ? '<br><br>Nur für einen versehentlich markierten Versand: die archivierte <b>Version ' + row.ab_version + '</b> wird verworfen'
          + (row.ab_version > 1 ? ', Version ' + (row.ab_version - 1) + ' gilt wieder als versendet.' : '.')
        : ''), 'Versand zurücknehmen');
    if (!ok) return;
    const res = await apiDelete(API.order_confirmations + '/' + encodeURIComponent(id) + '/sent');
    _abPdfCache.id = null;
    if (res && res.success) {
        showToast(res.still_sent_version ? 'Version zurückgenommen — Version ' + res.still_sent_version + ' gilt als versendet' : 'Versand-Status zurückgenommen');
        closeAbDialog();
        await abReload();
        _ocRefreshViewerActions(id, false);
    } else {
        showToast(res?.error || 'Fehler', 'error');
    }
}

/* ---- Realtime: refresh the list when Bestelleingang changes elsewhere.
   Guarded like incoming_orders.js — never while a dialog is open. ---- */
if (window.HMSRealtime) {
    HMSRealtime.on('eingang', () => {
        try {
            const dlg = document.getElementById('abMdDialog');
            if (dlg && dlg.open) return;
            if (document.querySelector('#content .oc-table-card')) abReload();
        } catch (e) { /* best-effort */ }
    });
}
