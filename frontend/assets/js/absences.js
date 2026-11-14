/* ============================================
   HMS Gruppe — Anträge & Urlaub (central absence management)
   Backend: /api/worker-absences/requests, /calendar, /{id}/approve|reject
   Design: Google Material Design 3
   ============================================ */

function abIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}

const AB_API = window.location.origin + '/api/worker-absences';

const AB_KATEGORIEN = {
    urlaub:    { label: 'Urlaub',      icon: 'beach_access',    color: '#22c55e' },
    krank:     { label: 'Krankmeldung',icon: 'medical_services',color: '#f97316' },
    sonstiges: { label: 'Sonstiges',   icon: 'event_note',      color: '#6366f1' },
};

let _abView = 'requests';          // 'requests' | 'calendar'
let _abCalMonth = null;            // Date anchored to the 1st of the shown month
let _abFocusRouted = false;        // deep-link already redirected once (requests → history)

async function renderAbsencesPage() {
    const content = document.getElementById('content');
    // Deep-link from a notification targets a specific request — land on the
    // requests inbox so the card can be scrolled to + highlighted. Skipped
    // once the link was already re-routed to Verlauf (decided request).
    try {
        if (!_abFocusRouted && sessionStorage.getItem('hms_focus_absences')) _abView = 'requests';
    } catch (e) { /* sessionStorage unavailable — ignore */ }
    if (!_abCalMonth) {
        const now = new Date();
        _abCalMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    }
    // Same view already on screen (after approve / reject): keep the list,
    // no spinner — the loader below patches it.
    const page = document.querySelector('#content > .ab-page');
    if (page && page.dataset.view === _abView && typeof hmsMorph === 'function') {
        page.querySelectorAll('.ab-segment').forEach(b => b.classList.toggle('active', (b.getAttribute('onclick') || '').includes(`'${_abView}'`)));
        if (_abView === 'requests') abLoadRequests();
        else if (_abView === 'history') abLoadHistory();
        else abLoadCalendar();
        return;
    }
    content.innerHTML = `
        <div class="ab-page" data-view="${_abView}">
            <div class="ab-toolbar">
                <div class="ab-segments" role="tablist">
                    <button class="ab-segment ${_abView === 'requests' ? 'active' : ''}" onclick="abSwitchView('requests')">
                        ${abIcon('inbox')} Offene Anträge <span class="ab-segment-count" id="abReqCount"></span>
                    </button>
                    <button class="ab-segment ${_abView === 'calendar' ? 'active' : ''}" onclick="abSwitchView('calendar')">
                        ${abIcon('calendar_month')} Teamkalender
                    </button>
                    <button class="ab-segment ${_abView === 'history' ? 'active' : ''}" onclick="abSwitchView('history')">
                        ${abIcon('history')} Verlauf
                    </button>
                </div>
            </div>
            <div id="abBody"><div class="ph-loading" style="padding:60px;text-align:center">${abIcon('progress_activity')}</div></div>
        </div>
    `;
    if (_abView === 'requests') abLoadRequests();
    else if (_abView === 'history') abLoadHistory();
    else abLoadCalendar();
}

function abSwitchView(view) {
    _abView = view;
    renderAbsencesPage();
}

// ============================================================
// Offene Anträge — central approvals inbox
// ============================================================
let _abReqData = null;
async function abLoadRequests(useCache) {
    const body = document.getElementById('abBody');
    const data = (useCache && _abReqData) ? _abReqData : await apiGet(AB_API + '/requests');
    if (!data) { body.innerHTML = `<div class="empty-state">${abIcon('error')}<p>Fehler beim Laden</p></div>`; return; }
    _abReqData = data;

    const reqs = data.requests || [];
    abUpdateBadge(reqs.length);
    const countEl = document.getElementById('abReqCount');
    if (countEl) countEl.textContent = reqs.length > 0 ? reqs.length : '';

    if (reqs.length === 0) {
        // Deep-link target already decided? It lives in Verlauf — go there.
        if (abConsumeFocusId()) { _abFocusRouted = true; _abView = 'history'; renderAbsencesPage(); return; }
        body.innerHTML = `
            <div class="empty-state" style="padding:70px 20px">
                ${abIcon('task_alt')}
                <p><strong>Alles erledigt!</strong><br>Keine offenen Anträge — neue Anträge aus der Mitarbeiter-App erscheinen hier automatisch.</p>
            </div>`;
        return;
    }

    const _p = hmsPagerDef('abReqPager', 'Anträge', () => abLoadRequests(true));
    const pg = hmsPage(reqs, _p.st);
    const list = `<div class="ab-req-list">${pg.rows.map(r => abRequestCard(r)).join('')}</div><div id="abReqPager" class="be-pager">${hmsPagerHtml('abReqPager', pg, _p.unit)}</div>`;
    if (typeof hmsMorph === 'function') hmsMorph(body, list); else body.innerHTML = list;

    // Deep-link from a notification: '#absences?id=<request_id>' scrolls to
    // and highlights the exact request card it came from. If the request was
    // already decided it is no longer pending — fall back to Verlauf.
    const focusId = abConsumeFocusId();
    if (focusId && !abHighlightCard(focusId)) {
        _abFocusRouted = true;   // don't let renderAbsencesPage bounce back
        _abView = 'history';
        renderAbsencesPage();
    }
}

// Pending deep-link target id from a notification click, or null.
function abConsumeFocusId() {
    try {
        const fq = sessionStorage.getItem('hms_focus_absences');
        return fq ? new URLSearchParams(fq).get('id') : null;
    } catch (e) { return null; }
}

// Scroll to + highlight the card rendered under 'abReq<id>'; clears the stash
// on a hit and returns whether the card was found in the current view.
function abHighlightCard(id) {
    const el = id ? document.getElementById('abReq' + id) : null;
    if (!el) return false;
    try { sessionStorage.removeItem('hms_focus_absences'); } catch (e) {}
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('ab-focus');
    setTimeout(() => el.classList.remove('ab-focus'), 4000);
    return true;
}

function abRequestCard(r) {
    const kat = AB_KATEGORIEN[r.kategorie] || AB_KATEGORIEN.sonstiges;
    const initials = escapeHtml((r.worker_name || 'MA').substring(0, 2).toUpperCase());
    const avatar = r.foto ? `<img src="${escapeHtml(r.foto)}" alt="">` : initials;
    const range = formatDate(r.startdatum) + (r.enddatum && r.enddatum !== r.startdatum ? ' – ' + formatDate(r.enddatum) : '');
    const zeit = !Number(r.ganzer_tag) && r.startzeit ? ` · ${r.startzeit.substring(0,5)}–${r.endzeit ? r.endzeit.substring(0,5) : '?'}` : '';
    const konto = r.urlaubskonto || {};
    const kontoHtml = r.kategorie === 'urlaub' ? `
        <div class="ab-konto">
            <span title="Urlaubsanspruch">${abIcon('workspace_premium', 'font-size:14px')} ${konto.anspruch ?? '–'}</span>
            <span title="Genommen" style="color: var(--md-ext-color-success)">${abIcon('check_circle', 'font-size:14px')} ${konto.genommen ?? '–'}</span>
            <span title="Beantragt (inkl. dieses Antrags)" style="color: var(--md-ext-color-warning)">${abIcon('hourglass_top', 'font-size:14px')} ${konto.beantragt ?? '–'}</span>
            <span title="Rest nach allen Anträgen" style="color:${(konto.rest ?? 0) < 0 ? '#dc2626' : 'var(--text)'}">${abIcon('account_balance_wallet', 'font-size:14px')} ${konto.rest ?? '–'} Rest</span>
        </div>` : '';
    const canDecide = canEdit('absences');
    return `
        <div class="ab-req-card" id="abReq${r.id}">
            <div class="ab-req-avatar">${avatar}</div>
            <div class="ab-req-main">
                <div class="ab-req-top">
                    <span class="ab-req-name">${escapeHtml(r.worker_name)}</span>
                    <span class="ab-kat-chip" style="--kat-color:${kat.color}">${abIcon(kat.icon, 'font-size:14px')} ${kat.label}</span>
                    ${r.bereich ? bereichBadgeHtml(r.bereich, 'ab-req-bereich') : ''}
                </div>
                <div class="ab-req-details">
                    <span>${abIcon('calendar_today', 'font-size:14px')} ${range}${zeit}</span>
                    <span>${abIcon('timelapse', 'font-size:14px')} ${r.tage} ${r.tage == 1 ? 'Tag' : 'Tage'}</span>
                    ${r.created_at ? `<span class="ab-req-created">${abIcon('schedule', 'font-size:14px')} eingereicht ${formatDate(r.created_at)}</span>` : ''}
                </div>
                ${r.beschreibung ? `<div class="ab-req-note">${abIcon('comment', 'font-size:13px')} ${escapeHtml(r.beschreibung)}</div>` : ''}
                ${kontoHtml}
            </div>
            ${canDecide ? `
            <div class="ab-req-actions">
                <md-filled-button style="--md-filled-button-container-color:#10b981" onclick="abApprove(${r.id})"><md-icon slot="icon">check</md-icon>Genehmigen</md-filled-button>
                <md-outlined-button style="--md-outlined-button-label-text-color:#ef4444;--md-outlined-button-outline-color:#ef4444" onclick="abReject(${r.id})"><md-icon slot="icon">close</md-icon>Ablehnen</md-outlined-button>
            </div>` : ''}
        </div>
    `;
}

async function abApprove(id) {
    const r = await apiPost(AB_API + '/' + id + '/approve', {});
    if (r && r.success) {
        showToast('Antrag genehmigt');
        abLoadRequests();
        if (window.HMSNotifications) window.HMSNotifications.refresh();
    } else {
        showToast((r && r.error) || 'Fehler', 'error');
    }
}

function abReject(id) {
    const card = document.getElementById('abReq' + id);
    if (!card || card.querySelector('.ab-reject-inline')) return;
    const box = document.createElement('div');
    box.className = 'ab-reject-inline';
    box.innerHTML = `
        <md-outlined-text-field id="abRejectGrund${id}" label="Begründung (optional)" maxlength="500"
            placeholder="z. B. Personalengpass an diesem Tag..." style="flex:1"></md-outlined-text-field>
        <md-filled-button style="--md-filled-button-container-color:#ef4444" onclick="abRejectConfirm(${id})"><md-icon slot="icon">close</md-icon>Ablehnen</md-filled-button>
        <md-text-button onclick="this.parentElement.remove()">Abbrechen</md-text-button>
    `;
    card.appendChild(box);
    setTimeout(() => document.getElementById('abRejectGrund' + id)?.focus(), 60);
}

async function abRejectConfirm(id) {
    const grund = (document.getElementById('abRejectGrund' + id)?.value || '').trim();
    const r = await apiPost(AB_API + '/' + id + '/reject', { grund });
    if (r && r.success) {
        showToast('Antrag abgelehnt');
        abLoadRequests();
        if (window.HMSNotifications) window.HMSNotifications.refresh();
    } else {
        showToast((r && r.error) || 'Fehler', 'error');
    }
}

// ============================================================
// Verlauf — decision log (who decided what, when)
// ============================================================
let _abHistData = null;
async function abLoadHistory(useCache) {
    const body = document.getElementById('abBody');
    const data = (useCache && _abHistData) ? _abHistData : await apiGet(AB_API + '/history');
    if (!data) { body.innerHTML = `<div class="empty-state">${abIcon('error')}<p>Fehler beim Laden</p></div>`; return; }
    _abHistData = data;

    const rows = data.history || [];
    if (rows.length === 0) {
        body.innerHTML = `
            <div class="empty-state" style="padding:70px 20px">
                ${abIcon('history')}
                <p>Noch keine Entscheidungen — genehmigte und abgelehnte Anträge erscheinen hier mit Entscheider und Zeitpunkt.</p>
            </div>`;
        return;
    }

    const _ph = hmsPagerDef('abHistPager', 'Einträge', () => abLoadHistory(true));
    const pgh = hmsPage(rows, _ph.st);
    body.innerHTML = `<div class="ab-req-list">${pgh.rows.map(r => abHistoryCard(r)).join('')}</div><div id="abHistPager" class="be-pager">${hmsPagerHtml('abHistPager', pgh, _ph.unit)}</div>`;

    // Deep-link fallback: a decided request surfaces here instead of the
    // pending inbox. Clear the stash either way so we never loop views.
    const focusId = abConsumeFocusId();
    if (focusId && !abHighlightCard(focusId)) {
        try { sessionStorage.removeItem('hms_focus_absences'); } catch (e) {}
    }
    _abFocusRouted = false;      // deep-link cycle complete
}

function abHistoryCard(r) {
    const kat = AB_KATEGORIEN[r.kategorie] || AB_KATEGORIEN.sonstiges;
    const approved = r.status === 'genehmigt';
    const initials = escapeHtml((r.worker_name || 'MA').substring(0, 2).toUpperCase());
    const avatar = r.foto ? `<img src="${escapeHtml(r.foto)}" alt="">` : initials;
    const range = formatDate(r.startdatum) + (r.enddatum && r.enddatum !== r.startdatum ? ' – ' + formatDate(r.enddatum) : '');
    const decidedAt = r.entschieden_am ? new Date(r.entschieden_am.replace(' ', 'T') + 'Z').toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '';
    return `
        <div class="ab-req-card ab-hist-card ${approved ? 'ab-hist-approved' : 'ab-hist-rejected'}" id="abReq${r.id}">
            <div class="ab-req-avatar">${avatar}</div>
            <div class="ab-req-main">
                <div class="ab-req-top">
                    <span class="ab-req-name">${escapeHtml(r.worker_name)}</span>
                    <span class="ab-kat-chip" style="--kat-color:${kat.color}">${abIcon(kat.icon, 'font-size:14px')} ${kat.label}</span>
                    <span class="ab-status-chip ${approved ? 'ab-status-approved' : 'ab-status-rejected'}">
                        ${abIcon(approved ? 'check_circle' : 'cancel', 'font-size:14px')} ${approved ? 'Genehmigt' : 'Abgelehnt'}
                    </span>
                </div>
                <div class="ab-req-details">
                    <span>${abIcon('calendar_today', 'font-size:14px')} ${range}</span>
                    <span>${abIcon('timelapse', 'font-size:14px')} ${r.tage} ${r.tage == 1 ? 'Tag' : 'Tage'}</span>
                </div>
                ${!approved && r.ablehnungsgrund ? `<div class="ab-req-note">${abIcon('comment', 'font-size:13px')} ${escapeHtml(r.ablehnungsgrund)}</div>` : ''}
                <div class="ab-hist-decision">
                    ${abIcon('gavel', 'font-size:13px')} Entschieden von <strong>${escapeHtml(r.entschieden_von_name || 'System')}</strong>${decidedAt ? ` am ${decidedAt}` : ''}
                </div>
            </div>
        </div>
    `;
}

// Sidebar badge with the number of open requests.
function abUpdateBadge(count) {
    const badge = document.getElementById('badge-absences');
    if (!badge) return;
    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
    }
}

// ============================================================
// Teamkalender — who is out when (month view)
// ============================================================
async function abLoadCalendar() {
    const body = document.getElementById('abBody');
    const y = _abCalMonth.getFullYear();
    const m = _abCalMonth.getMonth();
    const von = `${y}-${String(m + 1).padStart(2, '0')}-01`;
    const lastDay = new Date(y, m + 1, 0).getDate();
    const bis = `${y}-${String(m + 1).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;

    const data = await apiGet(AB_API + `/calendar?von=${von}&bis=${bis}`);
    if (!data) { body.innerHTML = `<div class="empty-state">${abIcon('error')}<p>Fehler beim Laden</p></div>`; return; }

    const entries = data.eintraege || [];
    const monthLabel = _abCalMonth.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' });
    _abCalEntries = entries;

    if (_abCalMode === 'month') {
        body.innerHTML = abCalCardHtml(monthLabel, abCalMonthHtml(entries, y, m, lastDay, monthLabel));
        return;
    }

    // Group entries per worker.
    const byWorker = {};
    for (const e of entries) {
        (byWorker[e.dienst_nr] = byWorker[e.dienst_nr] || { name: e.worker_name, foto: e.foto, bereich: e.bereich, items: [] }).items.push(e);
    }
    const workers = Object.entries(byWorker).sort((a, b) => a[1].name.localeCompare(b[1].name));

    const today = new Date();
    const isThisMonth = today.getFullYear() === y && today.getMonth() === m;

    // Day header cells.
    let headCells = '';
    for (let d = 1; d <= lastDay; d++) {
        const dow = new Date(y, m, d).getDay();
        const we = dow === 0 || dow === 6;
        headCells += `<div class="ab-cal-daycell ${we ? 'ab-cal-we' : ''} ${isThisMonth && d === today.getDate() ? 'ab-cal-today' : ''}">${d}</div>`;
    }

    const rows = workers.map(([nr, w]) => {
        let cells = '';
        for (let d = 1; d <= lastDay; d++) {
            const iso = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const dow = new Date(y, m, d).getDay();
            const we = dow === 0 || dow === 6;
            const hit = w.items.find(e => e.startdatum <= iso && (e.enddatum || e.startdatum) >= iso);
            if (hit) {
                const kat = AB_KATEGORIEN[hit.kategorie] || AB_KATEGORIEN.sonstiges;
                const pending = hit.status === 'pending';
                cells += `<div class="ab-cal-cell ${we ? 'ab-cal-we' : ''}"><span class="ab-cal-dot ${pending ? 'ab-cal-dot-pending' : ''}" style="--kat-color:${kat.color}" title="${escapeHtml(w.name)} · ${kat.label}${pending ? ' (wartet auf Entscheidung)' : ''} · ${formatDate(hit.startdatum)}${hit.enddatum && hit.enddatum !== hit.startdatum ? ' – ' + formatDate(hit.enddatum) : ''}"></span></div>`;
            } else {
                cells += `<div class="ab-cal-cell ${we ? 'ab-cal-we' : ''}"></div>`;
            }
        }
        const initials = escapeHtml((w.name || 'MA').substring(0, 2).toUpperCase());
        return `
            <div class="ab-cal-row">
                <div class="ab-cal-worker">
                    <div class="ab-cal-avatar">${w.foto ? `<img src="${escapeHtml(w.foto)}" alt="">` : initials}</div>
                    <div class="ab-cal-worker-info">
                        <span class="ab-cal-worker-name">${escapeHtml(w.name)}</span>
                        ${w.bereich ? `<span class="ab-cal-worker-meta">${escapeHtml(w.bereich)}</span>` : ''}
                    </div>
                </div>
                <div class="ab-cal-days">${cells}</div>
            </div>`;
    }).join('');

    body.innerHTML = abCalCardHtml(monthLabel, workers.length === 0
                ? `<div class="empty-state" style="padding:60px 20px">${abIcon('event_available')}<p>Keine Abwesenheiten im ${monthLabel}</p></div>`
                : `
                <div class="ab-cal-scroll"><div class="ab-cal-grid">
                    <div class="ab-cal-row ab-cal-header-row">
                        <div class="ab-cal-worker" style="font-weight:700;font-size:12px;color:var(--text-muted)">MITARBEITER</div>
                        <div class="ab-cal-days">${headCells}</div>
                    </div>
                    ${rows}
                </div></div>`);
}

// Card shell shared by both calendar layouts: month nav, layout toggle, legend.
function abCalCardHtml(monthLabel, inner) {
    return `
        <div class="ab-cal-card">
            <div class="ab-cal-head">
                <md-icon-button onclick="abCalNav(-1)" title="Vorheriger Monat"><md-icon>chevron_left</md-icon></md-icon-button>
                <span class="ab-cal-month">${monthLabel}</span>
                <md-icon-button onclick="abCalNav(1)" title="Nächster Monat"><md-icon>chevron_right</md-icon></md-icon-button>
                <button type="button" class="ab-cal-todaybtn" onclick="abCalToday()">Heute</button>
                <div class="ab-cal-mode" role="tablist">
                    <button type="button" class="${_abCalMode === 'month' ? 'active' : ''}" onclick="abCalSetMode('month')">${abIcon('calendar_view_month')} Monat</button>
                    <button type="button" class="${_abCalMode === 'rows' ? 'active' : ''}" onclick="abCalSetMode('rows')">${abIcon('view_timeline')} Mitarbeiter</button>
                </div>
                <div class="ab-cal-legend">
                    ${Object.values(AB_KATEGORIEN).map(k => `<span class="ab-legend-item"><span class="ab-legend-dot" style="--kat-color:${k.color}"></span>${k.label}</span>`).join('')}
                    <span class="ab-legend-item"><span class="ab-legend-dot ab-cal-dot-pending" style="--kat-color:#94a3b8"></span>Wartet auf Entscheidung</span>
                </div>
            </div>
            ${inner}
        </div>`;
}

// ---------- Month grid: one box per day, absent workers inside ----------
let _abCalMode = 'month';          // 'month' (default) | 'rows' (per-worker timeline)
let _abCalEntries = [];
const AB_DAY_MAX = 4;              // chips per day box before "+N weitere"
const AB_WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

function abIso(y, m, d) { return `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`; }
function abShortName(full) {
    const parts = String(full || '').trim().split(/\s+/);
    return parts.length > 1 ? `${parts[0]} ${parts[parts.length - 1].charAt(0)}.` : (parts[0] || 'MA');
}
function abEntriesOn(entries, iso) {
    return entries
        .filter(e => e.startdatum <= iso && (e.enddatum || e.startdatum) >= iso)
        .sort((a, b) => String(a.worker_name).localeCompare(String(b.worker_name)));
}
function abAvatar(e, cls = 'ab-day-av') {
    const ini = escapeHtml(String(e.worker_name || 'MA').split(/\s+/).map(p => p.charAt(0)).join('').substring(0, 2).toUpperCase());
    return `<span class="${cls}">${e.foto ? `<img src="${escapeHtml(e.foto)}" alt="" loading="lazy">` : ini}</span>`;
}
function abRangeText(e) {
    return formatDate(e.startdatum) + (e.enddatum && e.enddatum !== e.startdatum ? ' – ' + formatDate(e.enddatum) : '');
}
function abChip(e) {
    const kat = AB_KATEGORIEN[e.kategorie] || AB_KATEGORIEN.sonstiges;
    const pending = e.status === 'pending';
    return `<span class="ab-day-chip ${pending ? 'is-pending' : ''}" style="--kat-color:${kat.color}"
        title="${escapeHtml(e.worker_name)} · ${kat.label}${pending ? ' (wartet auf Entscheidung)' : ''} · ${abRangeText(e)}">
        ${abAvatar(e)}<span class="ab-day-name">${escapeHtml(abShortName(e.worker_name))}</span>
        <span class="material-symbols-outlined ab-day-kat">${kat.icon}</span></span>`;
}

function abCalMonthHtml(entries, y, m, lastDay, monthLabel) {
    const today = new Date();
    const todayIso = abIso(today.getFullYear(), today.getMonth(), today.getDate());
    const lead = (new Date(y, m, 1).getDay() + 6) % 7;            // Monday-first offset
    const total = Math.ceil((lead + lastDay) / 7) * 7;

    let cells = '';
    let agenda = '';
    for (let i = 0; i < total; i++) {
        const d = i - lead + 1;
        if (d < 1 || d > lastDay) { cells += `<div class="ab-day ab-day-out"></div>`; continue; }
        const iso = abIso(y, m, d);
        const dow = (i % 7);
        const list = abEntriesOn(entries, iso);
        const cls = ['ab-day', dow >= 5 ? 'ab-day-we' : '', iso === todayIso ? 'ab-day-today' : '', list.length ? 'has-items' : ''].join(' ');
        const shown = list.slice(0, AB_DAY_MAX).map(abChip).join('');
        const more = list.length > AB_DAY_MAX ? `<span class="ab-day-more">+${list.length - AB_DAY_MAX} weitere</span>` : '';
        cells += `<div class="${cls}" ${list.length ? `onclick="abCalOpenDay('${iso}')" role="button" tabindex="0" title="${list.length} abwesend — Details anzeigen"` : ''}>
            <div class="ab-day-head"><span class="ab-day-num">${d}</span>${list.length ? `<span class="ab-day-count">${list.length}</span>` : ''}</div>
            <div class="ab-day-list">${shown}${more}</div>
        </div>`;
        if (list.length) {
            const wd = new Date(y, m, d).toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' });
            agenda += `<div class="ab-agenda-day ${iso === todayIso ? 'ab-day-today' : ''}" onclick="abCalOpenDay('${iso}')">
                <div class="ab-agenda-date">${wd}<span class="ab-day-count">${list.length}</span></div>
                <div class="ab-agenda-list">${list.map(abChip).join('')}</div>
            </div>`;
        }
    }
    if (!entries.length) {
        return `<div class="empty-state" style="padding:60px 20px">${abIcon('event_available')}<p>Keine Abwesenheiten im ${monthLabel}</p></div>`;
    }
    return `
        <div class="ab-month">
            <div class="ab-month-wd">${AB_WEEKDAYS.map((w, i) => `<div class="${i >= 5 ? 'ab-day-we' : ''}">${w}</div>`).join('')}</div>
            <div class="ab-month-grid">${cells}</div>
        </div>
        <div class="ab-agenda">${agenda}</div>`;
}

function abCalOpenDay(iso) {
    const list = abEntriesOn(_abCalEntries, iso);
    if (!list.length) return;
    const title = new Date(iso + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    const rows = list.map(e => {
        const kat = AB_KATEGORIEN[e.kategorie] || AB_KATEGORIEN.sonstiges;
        const pending = e.status === 'pending';
        return `<div class="ab-dd-row" style="--kat-color:${kat.color}">
            ${abAvatar(e, 'ab-cal-avatar')}
            <div class="ab-dd-main">
                <div class="ab-dd-name">${escapeHtml(e.worker_name)}</div>
                <div class="ab-dd-meta">${e.bereich ? escapeHtml(e.bereich) + ' · ' : ''}${abRangeText(e)}${e.tage ? ` · ${escapeHtml(String(e.tage))} Tag(e)` : ''}</div>
                ${e.beschreibung ? `<div class="ab-dd-desc">${escapeHtml(e.beschreibung)}</div>` : ''}
            </div>
            <span class="ab-dd-kat ${pending ? 'is-pending' : ''}">${abIcon(kat.icon, 'font-size:15px')} ${kat.label}${pending ? ' · offen' : ''}</span>
        </div>`;
    }).join('');
    document.getElementById('abDayDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'abDayDialog';
    dlg.innerHTML = `
        <div slot="headline">${escapeHtml(title)} <span class="ab-dd-count">${list.length} abwesend</span></div>
        <div slot="content" class="ab-dd-list">${rows}</div>
        <div slot="actions"><md-text-button onclick="document.getElementById('abDayDialog')?.close()">Schließen</md-text-button></div>`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();
}

function abCalSetMode(mode) {
    _abCalMode = mode;
    try { localStorage.setItem('hms_ab_cal_mode', mode); } catch (e) {}
    abLoadCalendar();
}
try { const sm = localStorage.getItem('hms_ab_cal_mode'); if (sm === 'rows' || sm === 'month') _abCalMode = sm; } catch (e) {}

function abCalToday() {
    const now = new Date();
    _abCalMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    abLoadCalendar();
}

function abCalNav(delta) {
    _abCalMonth = new Date(_abCalMonth.getFullYear(), _abCalMonth.getMonth() + delta, 1);
    abLoadCalendar();
}

// Poll the open-request count for the sidebar badge (piggybacks on page loads).
async function abRefreshSidebarBadge() {
    try {
        const data = await apiGet(AB_API + '/requests');
        if (data) abUpdateBadge((data.requests || []).length);
    } catch (e) {}
}
