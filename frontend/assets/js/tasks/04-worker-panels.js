// tasks/04-worker-panels.js — Panels Aktive/Abgeschlossene Mitarbeiter (+ DAYS_SHORT/MONTHS_FULL, wp*-Helfer)
// Teil 4/10 des früheren tasks.js. Alle Teile teilen den globalen Scope und MÜSSEN in dieser Reihenfolge geladen werden (PAGE_MODULE_SCRIPTS in app.js).

// ============================================
// WORKER PANELS — Active & Completed
// ============================================
const DAYS_SHORT = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
const MONTHS_FULL = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

// Build a Google-Maps-safe query from an Ort / Treffpunkt value.
// Addresses pass through unchanged; pure coordinate input is normalised
// to decimal "lat,lng". Supported coordinate styles:
//   51.3402, 12.3601          (decimal)
//   51,3402, 12,3601          (German decimal comma)
//   52°08'20.3"N 11°31'53.3"E (DMS — the ° ' " symbols confuse the Maps
//                              search, so we convert to decimal ourselves;
//                              O is accepted for German "Ost" = east)
function ortMapsQuery(ort) {
    const s = (ort || '').trim();

    // DMS: degrees° minutes' seconds" + hemisphere letter, twice.
    const dmsPart = `(\\d{1,3})\\s*[°:d]\\s*(\\d{1,2})\\s*['′:\\s]\\s*(\\d{1,2}(?:[.,]\\d+)?)\\s*["″”'']*`;
    const dmsRe = new RegExp(`^${dmsPart}\\s*([NSns])[,;\\s]+${dmsPart}\\s*([EWOewo])$`);
    const m = s.match(dmsRe);
    if (m) {
        const toDec = (d, min, sec) => parseFloat(d) + parseFloat(min) / 60 + parseFloat(String(sec).replace(',', '.')) / 3600;
        let lat = toDec(m[1], m[2], m[3]);
        let lng = toDec(m[5], m[6], m[7]);
        if (/s/i.test(m[4])) lat = -lat;
        if (/w/i.test(m[8])) lng = -lng;
        if (isFinite(lat) && isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) {
            return lat.toFixed(6) + ',' + lng.toFixed(6);
        }
    }

    // Decimal pair (incl. German decimal commas).
    if (/^[\d\s.,;+-]+$/.test(s)) {
        const nums = s.match(/-?\d+(?:[.,]\d+)?/g) || [];
        if (nums.length === 2) {
            const lat = parseFloat(nums[0].replace(',', '.'));
            const lng = parseFloat(nums[1].replace(',', '.'));
            if (isFinite(lat) && isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) {
                return lat + ',' + lng;
            }
        }
    }
    return s;
}

function wpFormatDate(d) {
    if (!d) return '–';
    const p = d.split('-');
    return p[2] + '.' + p[1] + '.' + p[0];
}
function wpFormatTime(t) {
    if (!t) return '–';
    return t.substring(0, 5);
}
function wpWorkerInitials(v, n) {
    return ((v || '')[0] || '') + ((n || '')[0] || '') || '?';
}
function wpShortDate(d) {
    if (!d) return '';
    const p = d.split('-');
    return p[2] + '.' + p[1] + '.';
}
function wpAddDays(dateIso, days) {
    const d = new Date(dateIso + 'T00:00:00');
    d.setDate(d.getDate() + days);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
// Small muted "original plan" line shown above app-recorded times:
// shift date + planned start / (+1 date + planned end for overnight),
// so an actual 19:26 ● under a faint "15.09. 17:00" is self-explanatory.
function wpPlanTimeHtml(e) {
    const mk = (d, t) => `<span class="wp-plan-time" title="Geplante Schichtzeit">${wpShortDate(d)} ${wpFormatTime(t)}</span>`;
    const planOver = (e.plan_end || '99:99') < (e.plan_start || '00:00');
    const endDate = planOver && e.datum ? wpAddDays(e.datum, 1) : e.datum;
    return { start: mk(e.datum, e.plan_start), end: mk(endDate, e.plan_end) };
}
function wpCalcPause(start, end, std, storedPause) {
    // Use stored pause_minuten if available
    if (storedPause != null && storedPause !== '' && parseInt(storedPause) >= 0) return parseInt(storedPause);
    if (!start || !end || !std) return 0;
    const sv = start.split(':').map(Number);
    const ev = end.split(':').map(Number);
    let total = (ev[0] * 60 + (ev[1] || 0)) - (sv[0] * 60 + (sv[1] || 0));
    if (total < 0) total += 24 * 60;
    const calculated = total - Math.round(parseFloat(std) * 60);
    // Cap fallback calculation at 120 min to avoid absurd values
    return Math.max(0, Math.min(calculated, 120));
}

function openWorkerPanel(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('active');
    document.body.classList.add('wp-open');
    // Esc closes the dialog (bound once per panel element)
    if (!el._wpEsc) {
        el._wpEsc = e => { if (e.key === 'Escape' && el.classList.contains('active')) closeWorkerPanel(id); };
        document.addEventListener('keydown', el._wpEsc);
    }
}
function closeWorkerPanel(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
    document.body.classList.remove('wp-open');
    // Closing the live "Aktive Mitarbeiter" view refreshes the dashboard's
    // "Jetzt im Einsatz" tile so the snapshot matches what the panel showed.
    if (id === 'wpActiveOverlay' && typeof dashRefreshLive === 'function') dashRefreshLive();
}

// ---- ACTIVE WORKERS PANEL ----
// Single-day LIVE view of TODAY only: shows today's einsätze with a live
// status per row (starts in …, working since …, finished at …). Shifts that
// started today and run past midnight are included, and yesterday's overnight
// shifts ending today carry over. No day navigation — always "Heute".

async function openActiveWorkersPanel() {
    let overlay = document.getElementById('wpActiveOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'wpActiveOverlay';
        overlay.className = 'wp-overlay';
        document.body.appendChild(overlay);
    }
    // The shell is built once — the periodic refresh patches .wp-body in
    // place, so the list never flashes empty and the search input keeps its
    // value and focus.
    if (!overlay.querySelector('.wp-panel')) {
        overlay.innerHTML = `
        <div class="wp-overlay-bg" onclick="closeWorkerPanel('wpActiveOverlay')" title="Schließen"></div>
        <div class="wp-panel" role="dialog" aria-modal="true" aria-label="Aktive Mitarbeiter">
            <div class="wp-header">
                <div class="wp-daynav">
                    <div class="wp-header-title"><span class="material-symbols-outlined">engineering</span> Aktive Mitarbeiter — <span id="wpActiveDayLabel"></span></div>
                </div>
                <button class="wp-header-close" onclick="closeWorkerPanel('wpActiveOverlay')"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="wp-toolbar">
                <span class="material-symbols-outlined" style="color:var(--gray-400)">search</span>
                <input type="text" id="wpActiveSearch" placeholder="Mitarbeiter, Kunde oder Objekt suchen..." oninput="wpFilterTable('wpActiveSearch','wpActiveTableBody')" style="flex:1">
            </div>
            <div class="wp-body"><div class="wp-empty"><md-circular-progress indeterminate style="--md-circular-progress-size:30px"></md-circular-progress><p>Lade Daten...</p></div></div>
        </div>`;
    }
    openWorkerPanel('wpActiveOverlay');
    await wpActiveReload();

    // Live refresh while the panel is open — keeps "arbeitet seit …" and the
    // clock badges current. Skipped while the user is typing in the search.
    if (window._wpActiveTimer) clearInterval(window._wpActiveTimer);
    window._wpActiveTimer = setInterval(() => {
        const ov = document.getElementById('wpActiveOverlay');
        if (!ov || !ov.classList.contains('active')) {
            clearInterval(window._wpActiveTimer); window._wpActiveTimer = null; return;
        }
        if (document.activeElement && document.activeElement.id === 'wpActiveSearch') return;
        wpActiveReload();
    }, 60000);
}

// Fetch today's active workers and patch the panel body in place.
// A failed refresh keeps the last good render; the loading error only shows
// when nothing has been rendered yet.
async function wpActiveReload() {
    const overlay = document.getElementById('wpActiveOverlay');
    const body = overlay ? overlay.querySelector('.wp-body') : null;
    if (!body) return;

    // Always today — pinned to Europe/Berlin (the dispatch timezone) so a
    // browser in a different timezone still lands on the correct day.
    const dateIso = new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Berlin' });
    const dayObj = new Date(dateIso + 'T00:00:00');
    const dayLabel = `Heute — ${DAYS_SHORT[dayObj.getDay()]}, ${wpFormatDate(dateIso)}`;

    const data = await apiGet(API.disposition + '/active-workers?date=' + dateIso);
    if (!data || data.error) {
        if (!body.querySelector('.wp-table')) {
            body.innerHTML = '<div class="wp-empty"><span class="material-symbols-outlined">warning</span><p>Fehler beim Laden</p></div>';
        }
        return;
    }

    const titleLabel = overlay.querySelector('#wpActiveDayLabel');
    if (titleLabel) titleLabel.textContent = dayLabel;

    const entries = data.entries || [];
    const serverNow = data.server_now || null;

    if (entries.length === 0) {
        body.innerHTML = '<div class="wp-empty"><span class="material-symbols-outlined">coffee</span><p>Keine Mitarbeiter an diesem Tag im Einsatz</p></div>';
        return;
    }

    // Preserve scroll position + an active search across the re-render.
    const scrollTop = body.scrollTop;
    const search = overlay.querySelector('#wpActiveSearch')?.value || '';

    // Priority order: what needs attention first (Nicht gestartet → Arbeitet
    // → Beginnt bald → Beendet → Vorbei), then by time inside each group,
    // then by name so the order is stable between refreshes.
    const rows = entries.map(e => ({ e, st: wpLiveState(e, serverNow) }));
    rows.sort((a, b) => a.st.rank - b.st.rank || a.st.sort - b.st.sort
        || ((a.e.name || '') + (a.e.vorname || '')).localeCompare((b.e.name || '') + (b.e.vorname || ''), 'de'));
    const counts = {};
    rows.forEach(r => { counts[r.st.key] = (counts[r.st.key] || 0) + 1; });
    // A chosen chip whose group emptied out (e.g. last "Nicht gestartet"
    // worker clocked in) falls back to "Alle" instead of an empty table.
    const activeFilter = (window._wpActiveFilter === 'all' || counts[window._wpActiveFilter]) ? window._wpActiveFilter : 'all';
    window._wpActiveFilter = activeFilter;

    let html = `
        <div class="wp-stats">
            <div class="wp-stat-box"><div class="wp-stat-value">${data.total_workers}</div><div class="wp-stat-label">Mitarbeiter</div></div>
            <div class="wp-stat-box"><div class="wp-stat-value">${data.total_entries}</div><div class="wp-stat-label">Einsätze</div></div>
            <div class="wp-stat-box"><div class="wp-stat-value">${data.total_std}</div><div class="wp-stat-label">Gesamt Std</div></div>
            <div class="wp-stat-box"><div class="wp-stat-value">${wpFormatDate(dateIso)}</div><div class="wp-stat-label">Heute</div></div>
        </div>
        <div class="wp-chips" id="wpActiveChips">
            ${_wpActiveFilterDefs.map(f => {
                const n = f.key === 'all' ? rows.length : (counts[f.key] || 0);
                if (f.key !== 'all' && n === 0) return '';
                return `<button type="button" class="wp-chip wp-chip-${f.key}${f.key === activeFilter ? ' active' : ''}" data-key="${f.key}" onclick="wpActiveSetFilter('${f.key}')">
                    <span class="material-symbols-outlined">${f.icon}</span>${f.label}<span class="wp-chip-count">${n}</span></button>`;
            }).join('')}
        </div>
        <div class="wp-table-wrap">
        <table class="wp-table">
            <thead><tr><th>#</th><th>Mitarbeiter</th><th>Objekt / Kunde</th><th>Von</th><th>Bis</th><th>Pause</th><th class="num">Std</th><th>Status</th><th>Live</th></tr></thead>
            <tbody id="wpActiveTableBody">`;

    rows.forEach(({ e, st }, i) => {
        const name = ((e.vorname || '') + ' ' + (e.name || '')).trim() || e.dienst_nr;
        const initials = wpWorkerInitials(e.vorname, e.name);
        const std = maCalcEntryStd(e);
        const pause = wpCalcPause(e.ist_start, e.ist_ende, std, e.pause_minuten ?? 0);
        // ist_start/ist_ende already carry the worker's own times via SQL
        // COALESCE; the em_* flags mark them as app-recorded (●) so a row
        // like 19:26→05:00 next to a 19:27→05:00 row explains the 0.01 Std
        // difference instead of looking like a rounding bug.
        const liveCell = (val, isLive) => isLive
            ? `<span class="wp-pause-live" title="Ist-Zeit des Mitarbeiters (App oder Dispo)">${wpFormatTime(val)} ●</span>`
            : wpFormatTime(val);
        const planT = wpPlanTimeHtml(e);
        const startLive = e.em_ist_start != null && e.em_ist_start !== '';
        const endLive = e.em_ist_ende != null && e.em_ist_ende !== '';
        // Actual pause recorded by the worker app beats the planned value.
        const appPause = e.app_pause != null ? parseInt(e.app_pause) : null;
        const pauseCell = appPause !== null
            ? `<span class="wp-pause-live" title="Tatsächlich erfasste Pause (App)">${appPause} Min ●</span>`
            : (pause > 0 ? pause + ' Min' : '–');
        const statusClass = e.hms_bestaetigt === 'ja' ? 'completed' : (e.pruefung === 'geplant' ? 'active' : 'pending');
        const statusLabel = e.hms_bestaetigt === 'ja' ? 'Bestätigt' : (e.pruefung === 'geplant' ? 'Geplant' : 'Ausstehend');
        const overnight = (e.ist_ende || '') < (e.ist_start || '00:00');
        const clockBadge = st.html;
        html += `<tr class="wp-row-${st.key}" data-live="${st.key}" data-worker-name="${escapeHtml((name + ' ' + (e.kundenname || '') + ' ' + (e.objektname || '')).toLowerCase())}">
            <td>${i + 1}</td>
            <td><div class="wp-worker-name"><div class="wp-worker-avatar">${initials}</div>${escapeHtml(name)}</div></td>
            <td class="wp-col-obj" title="${escapeHtml((e.kundenname || '–') + ' / ' + (e.objektname || '–'))}">${escapeHtml(e.objektname || '–')}<span class="wp-kunde">${escapeHtml(e.kundenname || '–')}</span></td>
            <td>${startLive ? planT.start : ''}${wpStartCell(e, startLive, liveCell)}</td>
            <td>${endLive ? planT.end : ''}${liveCell(e.ist_ende, endLive)}${overnight ? '<span class="wp-overnight" title="endet am Folgetag">+1</span>' : ''}</td>
            <td>${pauseCell}</td>
            <td class="num">${std ? std.toFixed(2) : '–'}</td>
            <td><span class="wp-badge ${statusClass}">${statusLabel}</span></td>
            <td>${clockBadge}</td>
        </tr>`;
    });

    html += '</tbody></table></div>';
    body.innerHTML = html;
    body.scrollTop = scrollTop;
    if (search || activeFilter !== 'all') wpFilterTable('wpActiveSearch', 'wpActiveTableBody');
}

// Compact relative duration, e.g. "3 Std 20 Min".
function wpRelTime(mins) {
    const m = Math.abs(Math.round(mins));
    const h = Math.floor(m / 60), r = m % 60;
    if (h > 0 && r > 0) return `${h} Std ${r} Min`;
    if (h > 0) return `${h} Std`;
    return `${r} Min`;
}

// Live status for the "Live" column: app clock state wins; without a clock-in
// the schedule decides — "Beginnt in …" (upcoming), "Nicht gestartet" (should
// be running but the worker never clocked in), or "–" (already over).
// Timestamps from the backend come in two zones: real instants (server_now,
// zeiterfassung.clock_in/out) are UTC, while einsaetze.ist_start/ist_ende are
// nominal Europe/Berlin wall times. Parse each accordingly — never as
// browser-local — so the live countdown is correct in any timezone.
function wpUtcDate(mysqlTs) {
    return mysqlTs ? new Date(mysqlTs.replace(' ', 'T') + 'Z') : null;
}

const _wpBerlinDtf = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Europe/Berlin', hourCycle: 'h23',
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
});
function _wpBerlinOffsetMs(instant) {
    const p = {};
    _wpBerlinDtf.formatToParts(instant).forEach(x => { p[x.type] = x.value; });
    return Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second) - instant;
}
// Absolute instant of a "Europe/Berlin" wall time ("YYYY-MM-DD", "HH:MM").
function wpBerlinInstant(dateIso, hhmm) {
    const [y, mo, d] = (dateIso || '1970-01-01').split('-').map(Number);
    const [h, mi] = (hhmm || '00:00').split(':').map(Number);
    const naiveUtc = Date.UTC(y, mo - 1, d, h, mi, 0);
    // Second pass settles the offset across DST-transition edges.
    return new Date(naiveUtc - _wpBerlinOffsetMs(naiveUtc - _wpBerlinOffsetMs(naiveUtc)));
}

// Resolves the live state of one entry. `key` drives the status filter,
// `rank` the list order (what needs the Dispo's attention comes first),
// `sort` the order inside a group:
//   0 missed   — should be running, never clocked in  (longest overdue first)
//   1 running  — Arbeitet / Pause                     (earliest clock-in first)
//   2 upcoming — Beginnt in …                         (soonest start first)
//   3 done     — Beendet (clocked out)                (latest first)
//   4 over     — Vorbei, never clocked in             (latest first)
function wpLiveState(e, serverNow) {
    const now = wpUtcDate(serverNow) || new Date();
    const start = wpBerlinInstant(e.datum, e.ist_start);
    let end = wpBerlinInstant(e.datum, e.ist_ende);
    if (end <= start) end = new Date(end.getTime() + 24 * 3600 * 1000); // overnight shift

    if (e.clock_status === 'aktiv' || e.clock_status === 'pause') {
        const clockIn = wpUtcDate(e.clock_in_real || e.clock_in);
        const since = wpRealInHm(e);
        const working = e.clock_status === 'aktiv';
        return { key: 'running', rank: 1, sort: clockIn ? clockIn.getTime() : start.getTime(),
            html: `<span class="wp-clock ${working ? 'working' : 'paused'} wp-clock-2l"><span class="wp-clock-dot"></span><span class="wp-clock-lines">${working ? 'Arbeitet' : 'Pause'}${since ? `<small>seit ${since} Uhr</small>` : ''}</span></span>` };
    }
    if (e.clock_status === 'beendet') {
        const clockOut = wpUtcDate(e.clock_out);
        const out = clockOut ? ' um ' + clockOut.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' }) : '';
        return { key: 'done', rank: 3, sort: -(clockOut ? clockOut.getTime() : end.getTime()),
            html: `<span class="wp-clock done"><span class="material-symbols-outlined">check</span>Beendet${out}</span>` };
    }
    if (now < start) return { key: 'upcoming', rank: 2, sort: start.getTime(),
        html: `<span class="wp-clock none">Beginnt in ${wpRelTime((start - now) / 60000)}</span>` };
    if (now <= end) return { key: 'missed', rank: 0, sort: start.getTime(),
        html: `<span class="wp-clock missed wp-clock-2l"><span class="material-symbols-outlined">warning</span><span class="wp-clock-lines">Nicht gestartet<small>Start ${start.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' })} Uhr</small></span></span>` };
    return { key: 'over', rank: 4, sort: -end.getTime(),
        html: '<span class="wp-clock none"><span class="material-symbols-outlined">event_busy</span>Vorbei</span>' };
}

// Real tap time (Europe/Berlin HH:MM) of the app clock-in, or ''.
function wpRealInHm(e) {
    const d = wpUtcDate(e.clock_in_real || e.clock_in);
    return d ? d.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin', hour: '2-digit', minute: '2-digit' }) : '';
}

// Von cell: blue = when the worker really tapped "Start" (an early tap is
// paid from the planned start — that counted time stays in the tooltip).
function wpStartCell(e, startLive, liveCell) {
    const real = e.clock_in_real ? wpRealInHm(e) : '';
    const counted = wpFormatTime(e.ist_start);
    if (!startLive || !real || real === counted) return liveCell(e.ist_start, startLive);
    return `<span class="wp-pause-live" title="Gestempelt um ${real} Uhr · Arbeitszeit zählt ab ${counted} Uhr">${real} ●</span>`;
}

function wpLiveBadge(e, serverNow) {
    return wpLiveState(e, serverNow).html;
}

// Status filter chips above the Aktive-Mitarbeiter table.
const _wpActiveFilterDefs = [
    { key: 'all',      label: 'Alle',            icon: 'list' },
    { key: 'missed',   label: 'Nicht gestartet', icon: 'warning' },
    { key: 'running',  label: 'Arbeitet',        icon: 'play_circle' },
    { key: 'upcoming', label: 'Beginnt bald',    icon: 'schedule' },
    { key: 'done',     label: 'Beendet',         icon: 'check_circle' },
    { key: 'over',     label: 'Vorbei',          icon: 'event_busy' },
];
window._wpActiveFilter = window._wpActiveFilter || 'all';

function wpActiveSetFilter(key) {
    window._wpActiveFilter = key;
    document.querySelectorAll('#wpActiveChips .wp-chip').forEach(c => c.classList.toggle('active', c.dataset.key === key));
    wpFilterTable('wpActiveSearch', 'wpActiveTableBody');
}

// Live clock state from the worker app (zeiterfassung.status per Einsatz)
function wpClockBadge(status) {
    if (status === 'aktiv')   return '<span class="wp-clock working"><span class="wp-clock-dot"></span>Arbeitet</span>';
    if (status === 'pause')   return '<span class="wp-clock paused"><span class="wp-clock-dot"></span>Pause</span>';
    if (status === 'beendet') return '<span class="wp-clock done"><span class="material-symbols-outlined">check</span>Beendet</span>';
    return '<span class="wp-clock none">–</span>';
}

// Search filter for Active workers table (row-based)
function wpFilterTable(inputId, tbodyId) {
    const query = (document.getElementById(inputId)?.value || '').toLowerCase().trim();
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    // The Aktive-Mitarbeiter table also has a status chip filter; other
    // panels only have the text search.
    const liveFilter = tbodyId === 'wpActiveTableBody' ? (window._wpActiveFilter || 'all') : 'all';
    tbody.querySelectorAll('tr').forEach(row => {
        const name = row.getAttribute('data-worker-name') || '';
        const matchText = !query || name.includes(query);
        const matchLive = liveFilter === 'all' || row.getAttribute('data-live') === liveFilter;
        row.style.display = matchText && matchLive ? '' : 'none';
    });
}
