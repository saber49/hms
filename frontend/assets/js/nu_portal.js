/* ============================================
   NU Portal — Nachunternehmer Portal JavaScript
   ============================================ */

const NU_API_BASE = window.location.origin + '/api';
const NU_API = NU_API_BASE + '/nu-portal';

function nuGetToken() { return localStorage.getItem('nu_token'); }
function nuSetToken(token) { localStorage.setItem('nu_token', token); }
function nuClearToken() { localStorage.removeItem('nu_token'); localStorage.removeItem('nu_user'); }
function nuAuthHeaders() {
    const h = { 'Accept': 'application/json' };
    const t = nuGetToken();
    if (t) h['Authorization'] = 'Bearer ' + t;
    return h;
}
function nuAuthHeadersJson() {
    const h = nuAuthHeaders();
    h['Content-Type'] = 'application/json';
    return h;
}
let nuCurrentTab = 'shifts';
let nuWeekStart = null;

const NU_DAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
const NU_DAYS_FULL = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

// ============================================
// Toast
// ============================================
function nuShowToast(msg, type = 'info') {
    let container = document.getElementById('nuToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'nuToastContainer';
        container.className = 'nu-toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = 'nu-toast ' + type;
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 4000);
}

// ============================================
// API Helpers
// ============================================
async function nuGet(url) {
    try {
        const res = await fetch(url, { headers: nuAuthHeaders() });
        if (res.status === 401) { nuClearToken(); window.location.href = 'nu_login.php'; return null; }
        return await res.json();
    } catch (e) { nuShowToast('Verbindungsfehler', 'error'); return null; }
}

async function nuPost(url, data) {
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: nuAuthHeadersJson(),
            body: JSON.stringify(data)
        });
        if (res.status === 401) { nuClearToken(); window.location.href = 'nu_login.php'; return null; }
        return await res.json();
    } catch (e) { nuShowToast('Verbindungsfehler', 'error'); return null; }
}

function nuEscape(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function nuFormatDate(dateStr) {
    if (!dateStr) return '–';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function nuFormatTime(timeStr) {
    if (!timeStr) return '–';
    return timeStr.substring(0, 5);
}

function nuInitials(vorname, name) {
    return ((vorname || '')[0] || '').toUpperCase() + ((name || '')[0] || '').toUpperCase() || '?';
}

// Render a position's gender requirement — the m/w counts are the source of
// truth; fall back to the coarse geschlecht label when no mix is stored.
function nuGeschlechtLabel(pos) {
    const g = pos.geschlecht || '';
    let mix = pos.geschlecht_mix;
    if (typeof mix === 'string') { try { mix = JSON.parse(mix); } catch (e) { mix = null; } }
    if (mix && ((mix.maennlich || 0) > 0 || (mix.weiblich || 0) > 0)) {
        const parts = [];
        if ((mix.maennlich || 0) > 0) parts.push(`♂ ${mix.maennlich}`);
        if ((mix.weiblich || 0) > 0) parts.push(`♀ ${mix.weiblich}`);
        return parts.join(' · ');
    }
    if (g === 'maennlich') return '♂ Männlich';
    if (g === 'weiblich') return '♀ Weiblich';
    if (g === 'gemischt') return '⚥ Gemischt';
    return '–';
}

// ============================================
// Navigation
// ============================================
function nuSwitchTab(tab) {
    nuCurrentTab = tab;
    document.querySelectorAll('.nu-nav-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    if (tab === 'shifts') nuLoadShifts();
    else if (tab === 'history') nuLoadHistory();
    else if (tab === 'workers') nuLoadWorkers();
}

// ============================================
// Week Navigation
// ============================================
function nuGetWeekStart() {
    const d = new Date();
    const day = d.getDay();
    const diff = d.getDate() - day + (day === 0 ? -6 : 1);
    d.setDate(diff);
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function nuChangeWeek(offset) {
    const d = new Date(nuWeekStart + 'T00:00:00');
    d.setDate(d.getDate() + (offset * 7));
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    nuWeekStart = `${yyyy}-${mm}-${dd}`;
    nuLoadShifts();
}

function nuGoCurrentWeek() {
    nuWeekStart = nuGetWeekStart();
    nuLoadShifts();
}

function nuFormatWeekLabel(start) {
    const s = new Date(start + 'T00:00:00');
    const e = new Date(s);
    e.setDate(e.getDate() + 6);
    return s.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' }) + ' – ' + e.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// ============================================
// Shifts Tab
// ============================================
async function nuLoadShifts() {
    if (!nuWeekStart) nuWeekStart = nuGetWeekStart();
    const content = document.getElementById('nuContent');
    content.innerHTML = `
        <div class="nu-week-nav">
            <button class="nu-btn nu-btn-outline nu-btn-sm" onclick="nuChangeWeek(-1)"><i class="material-symbols-outlined">chevron_left</i></button>
            <span class="nu-week-label">${nuFormatWeekLabel(nuWeekStart)}</span>
            <button class="nu-btn nu-btn-outline nu-btn-sm" onclick="nuChangeWeek(1)"><i class="material-symbols-outlined">chevron_right</i></button>
            <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="nuGoCurrentWeek()">Diese Woche</button>
        </div>
        <div id="nuShiftsList"><div class="nu-empty"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress><p>Lade Einsätze...</p></div></div>
    `;

    const data = await nuGet(NU_API + '/shifts?week_start=' + nuWeekStart);
    const container = document.getElementById('nuShiftsList');
    if (!data || !container) return;

    const tasks = data.tasks || [];
    if (tasks.length === 0) {
        container.innerHTML = '<div class="nu-empty"><i class="material-symbols-outlined">event_busy</i><p>Keine Einsätze in dieser Woche</p></div>';
        return;
    }

    let html = '';
    tasks.forEach(task => {
        const d = new Date(task.datum + 'T00:00:00');
        const dayName = NU_DAYS_FULL[d.getDay()];
        const nuStatus = task.nu_status || null;
        const isPending = nuStatus === 'pending';
        const isAccepted = nuStatus === 'accepted';
        let statusBadge;
        if (task.hms_bestaetigt === 'ja') {
            statusBadge = '<span class="nu-badge confirmed"><i class="material-symbols-outlined">check</i> Bestätigt</span>';
        } else if (isPending) {
            statusBadge = '<span class="nu-badge nu-pending"><i class="material-symbols-outlined">schedule</i> Neue Anfrage</span>';
        } else if (isAccepted || task.pruefung === 'geplant') {
            statusBadge = '<span class="nu-badge planned"><i class="material-symbols-outlined">assignment</i> Angenommen</span>';
        } else {
            statusBadge = '<span class="nu-badge pending"><i class="material-symbols-outlined">hourglass_top</i> Ausstehend</span>';
        }

        const positions = task.positionen || [];
        const totalAnzahl = positions.reduce((s, p) => s + (p.anzahl || 0), 0);
        const totalZugewiesen = positions.reduce((s, p) => s + (p.zugewiesen || 0), 0);
        // Full address of the Objekt — so the NU never has to type it.
        const adresse = [task.adresse, [task.plz, task.stadt].filter(Boolean).join(' ')].filter(Boolean).join(', ');
        const ort = (task.ort || '').trim();

        html += `
        <div class="nu-shift-card">
            <div class="nu-shift-header">
                <div class="nu-shift-header-left">
                    <span class="nu-shift-date">${dayName}, ${nuFormatDate(task.datum)}</span>
                    <span class="nu-shift-time"><i class="material-symbols-outlined">schedule</i> ${nuFormatTime(task.ist_start)} – ${nuFormatTime(task.ist_ende)} Uhr</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    ${statusBadge}
                    ${totalAnzahl > 0 ? `<span class="nu-badge ${totalZugewiesen >= totalAnzahl ? 'full' : 'open'}">${totalZugewiesen}/${totalAnzahl}</span>` : ''}
                </div>
            </div>
            <div class="nu-shift-meta">
                <span><strong>Objekt:</strong> ${nuEscape(task.objektname || '–')}</span>
                ${adresse ? `<span><strong>Adresse:</strong> ${nuEscape(adresse)}</span>` : ''}
                ${ort ? `<span><strong>Ort/Halle:</strong> ${nuEscape(ort)}</span>` : ''}
                ${task.ansprechpartner ? `<span><strong>Ansprechpartner:</strong> ${nuEscape(task.ansprechpartner)}${task.kontakt_telefon ? ' · ' + nuEscape(task.kontakt_telefon) : ''}</span>` : ''}
                <span><strong>Bestellung:</strong> ${nuEscape(task.bestell_id)}</span>
                ${task.beschreibung ? `<span><strong>Notiz:</strong> ${nuEscape(task.beschreibung)}</span>` : ''}
            </div>
            ${isPending ? `
            <div class="nu-shift-accept-bar">
                <div class="nu-shift-accept-msg"><i class="material-symbols-outlined">notifications</i> Neue Schichtanfrage — bitte bestätigen oder ablehnen</div>
                <div class="nu-shift-accept-actions">
                    <button class="nu-btn nu-btn-success" onclick="nuAcceptShift('${task.einsatz_id}')"><i class="material-symbols-outlined">check</i> Annehmen</button>
                    <button class="nu-btn nu-btn-danger" onclick="nuRejectShift('${task.einsatz_id}')"><i class="material-symbols-outlined">close</i> Ablehnen</button>
                </div>
            </div>` : ''}`;

        if (positions.length > 0) {
            html += `
            <div style="padding:0 0 10px 0;overflow-x:auto">
                <table class="nu-pos-table">
                    <thead><tr>
                        <th>#</th><th>Position</th><th>Anzahl</th><th>Geschlecht</th><th>Qualifikation</th><th>Skills</th><th>Kleidung</th><th>Check-in</th><th>Zugewiesen</th><th>Bemerkung</th><th>Aktionen</th>
                    </tr></thead>
                    <tbody>`;
            positions.forEach((pos, idx) => {
                const zuweisungen = pos.zuweisungen || [];
                const assignedBadges = zuweisungen.length > 0
                    ? '<div class="nu-assigned-badges">' + zuweisungen.map(z => `<span class="nu-assigned-badge"><i class="material-symbols-outlined">person</i> ${nuEscape(((z.vorname || '') + ' ' + (z.name || '')).trim())}${task.hms_bestaetigt !== 'ja' ? ` <i class="material-symbols-outlined nu-badge-remove" onclick="nuDoUnassign(${pos.id}, ${z.nu_mitarbeiter_id})" title="Entfernen">close</i>` : ''}</span>`).join('') + '</div>'
                    : '<em style="color:var(--nu-gray-400)">—</em>';

                html += `<tr>
                    <td>${pos.position_nr || (idx + 1)}</td>
                    <td><strong>${nuEscape(pos.position_name)}</strong></td>
                    <td>${pos.zugewiesen || 0} / ${pos.anzahl}</td>
                    <td>${nuGeschlechtLabel(pos)}</td>
                    <td>${nuEscape(pos.qualifikation || '–')}</td>
                    <td>${nuEscape(pos.skills || '–')}</td>
                    <td>${nuEscape(pos.kleidung || '–')}</td>
                    <td>${nuEscape(pos.checkin_info || '–')}</td>
                    <td>${assignedBadges}</td>
                    <td>${nuEscape(pos.bemerkung || '–')}</td>
                    <td>
                        ${isPending ? '<span style="color:var(--nu-gray-400);font-size:0.75rem">Erst annehmen</span>' : (task.hms_bestaetigt !== 'ja' ? `
                        <button class="nu-btn-icon success" onclick="nuOpenAssign(${pos.id}, '${nuEscape(pos.position_name)}', ${pos.anzahl}, ${pos.zugewiesen || 0})" title="Mitarbeiter zuweisen"><i class="material-symbols-outlined">person_add</i></button>
                        ${zuweisungen.length > 0 ? `<button class="nu-btn-icon danger" onclick="nuManageAssigned(${pos.id}, '${nuEscape(pos.position_name)}')" title="Zuweisungen verwalten"><i class="material-symbols-outlined">manage_accounts</i></button>` : ''}
                        ` : '<span style="color:var(--nu-gray-400);font-size:0.75rem">Gesperrt</span>')}
                    </td>
                </tr>`;
            });
            html += '</tbody></table></div>';
        } else {
            html += '<div style="padding:12px 20px;color:var(--nu-gray-400);font-size:0.82rem"><i class="material-symbols-outlined">info</i> Keine Positionen definiert — bitte warten Sie auf die Anforderungen.</div>';
        }

        html += '</div>';
    });

    container.innerHTML = html;
}

// ============================================
// Accept / Reject Shift Request
// ============================================
async function nuAcceptShift(einsatzId) {
    if (!confirm('Schichtanfrage annehmen?')) return;
    const res = await nuPost(NU_API + '/accept-shift', { einsatz_id: einsatzId });
    if (res && res.success) {
        nuShowToast('Schicht angenommen', 'success');
        nuLoadShifts();
    } else {
        nuShowToast(res?.error || 'Fehler beim Annehmen', 'error');
    }
}

async function nuRejectShift(einsatzId) {
    if (!confirm('Schichtanfrage wirklich ablehnen? Die Anfrage wird entfernt.')) return;
    const res = await nuPost(NU_API + '/reject-shift', { einsatz_id: einsatzId });
    if (res && res.success) {
        nuShowToast('Schicht abgelehnt', 'info');
        nuLoadShifts();
    } else {
        nuShowToast(res?.error || 'Fehler beim Ablehnen', 'error');
    }
}

// ============================================
// Assign / Manage Workers for a Position
// ============================================
async function nuOpenAssign(positionId, posName, maxCount, currentCount) {
    nuOpenPositionManager(positionId);
}

async function nuManageAssigned(positionId, posName) {
    nuOpenPositionManager(positionId);
}

async function nuOpenPositionManager(positionId) {
    const data = await nuGet(NU_API + '/positions?einsatz_id=' + positionId);
    if (!data || !data.position) return;

    const pos = data.position;
    const assigned = data.assigned || [];
    const available = data.available || [];
    const posName = pos.position_name || 'Position';
    const maxCount = pos.anzahl || 0;

    let body = `
        <div class="nu-pm-header">
            <strong>${nuEscape(posName)}</strong>
            <span class="nu-badge ${assigned.length >= maxCount ? 'full' : 'open'}">${assigned.length} / ${maxCount}</span>
        </div>

        <div class="nu-pm-section-title"><i class="material-symbols-outlined">group</i> Zugewiesene Mitarbeiter</div>
        <div class="nu-pm-list" id="nuPmAssigned">
    `;

    if (assigned.length === 0) {
        body += '<div class="nu-empty" style="padding:16px"><i class="material-symbols-outlined">person_off</i><p>Keine Mitarbeiter zugewiesen</p></div>';
    } else {
        assigned.forEach(w => {
            const name = ((w.vorname || '') + ' ' + (w.name || '')).trim();
            body += `
            <div class="nu-worker-item">
                <div class="nu-worker-avatar">${nuInitials(w.vorname, w.name)}</div>
                <div class="nu-worker-info">
                    <div class="nu-worker-name">${nuEscape(name)}</div>
                    <div class="nu-worker-meta">${nuEscape(w.personal_nr || '')}${w.qualifikation ? ' · ' + nuEscape(w.qualifikation) : ''}</div>
                </div>
                <button class="nu-btn nu-btn-outline nu-btn-sm" style="color: var(--md-sys-color-error);border-color: var(--md-sys-color-error)" onclick="nuDoUnassign(${positionId}, ${w.nu_mitarbeiter_id})"><i class="material-symbols-outlined">person_remove</i> Entfernen</button>
            </div>`;
        });
    }
    body += '</div>';

    if (assigned.length < maxCount) {
        body += `
        <div class="nu-pm-section-title" style="margin-top:18px"><i class="material-symbols-outlined">person_add</i> Mitarbeiter hinzufügen</div>
        <div class="nu-form-group">
            <md-outlined-text-field type="text" class="nu-form-control" id="nuAssignSearch" placeholder="Mitarbeiter suchen..." oninput="nuFilterAssignList()"></md-outlined-text-field>
        </div>
        <div class="nu-worker-list" id="nuAssignWorkerList">`;

        if (available.length === 0) {
            body += '<div class="nu-empty" style="padding:12px"><p style="font-size:0.82rem;color:var(--nu-gray-400)">Keine weiteren Mitarbeiter verfügbar</p></div>';
        } else {
            available.forEach(w => {
                const name = ((w.vorname || '') + ' ' + (w.name || '')).trim();
                body += `
                <div class="nu-worker-item" data-name="${nuEscape(name.toLowerCase())}">
                    <div class="nu-worker-avatar">${nuInitials(w.vorname, w.name)}</div>
                    <div class="nu-worker-info">
                        <div class="nu-worker-name">${nuEscape(name)}</div>
                        <div class="nu-worker-meta">${nuEscape(w.personal_nr || '')}${w.qualifikation ? ' · ' + nuEscape(w.qualifikation) : ''}</div>
                    </div>
                    <button class="nu-btn nu-btn-success nu-btn-sm" onclick="nuDoAssign(${positionId}, ${w.id})"><i class="material-symbols-outlined">add</i> Zuweisen</button>
                </div>`;
            });
        }
        body += '</div>';
    }

    nuOpenModal('Position verwalten', body, `<button class="nu-btn nu-btn-outline" onclick="nuCloseModal()">Schließen</button>`);
}

function nuFilterAssignList() {
    const q = (document.getElementById('nuAssignSearch')?.value || '').toLowerCase();
    document.querySelectorAll('#nuAssignWorkerList .nu-worker-item').forEach(el => {
        el.style.display = !q || (el.dataset.name || '').includes(q) ? '' : 'none';
    });
}

async function nuDoAssign(positionId, workerId) {
    const result = await nuPost(NU_API + '/assign-worker', { position_id: positionId, nu_mitarbeiter_id: workerId });
    if (result && result.success) {
        nuShowToast('Mitarbeiter zugewiesen', 'success');
        nuOpenPositionManager(positionId);
        nuLoadShifts();
    } else {
        nuShowToast(result?.error || 'Fehler beim Zuweisen', 'error');
    }
}

async function nuDoUnassign(positionId, workerId) {
    if (!confirm('Mitarbeiter von dieser Position entfernen?')) return;
    const result = await nuPost(NU_API + '/unassign-worker', { position_id: positionId, nu_mitarbeiter_id: workerId });
    if (result && result.success) {
        nuShowToast('Zuweisung entfernt', 'success');
        nuOpenPositionManager(positionId);
        nuLoadShifts();
    } else {
        nuShowToast(result?.error || 'Fehler', 'error');
    }
}

// ============================================
// Workers Tab
// ============================================
async function nuLoadWorkers() {
    const content = document.getElementById('nuContent');
    content.innerHTML = `
        <div class="nu-card">
            <div class="nu-card-header">
                <h3><i class="material-symbols-outlined">group</i> Meine Mitarbeiter</h3>
                <button class="nu-btn nu-btn-primary" onclick="nuOpenWorkerModal()"><i class="material-symbols-outlined">add</i> Neuer Mitarbeiter</button>
            </div>
            <div class="nu-card-body no-pad" id="nuWorkersList">
                <div class="nu-empty"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress><p>Lade...</p></div>
            </div>
        </div>`;

    const workers = await nuGet(NU_API + '/workers');
    const container = document.getElementById('nuWorkersList');
    if (!workers || !container) return;

    if (workers.length === 0) {
        container.innerHTML = '<div class="nu-empty"><i class="material-symbols-outlined">group_off</i><p>Noch keine Mitarbeiter angelegt</p></div>';
        return;
    }

    const today = new Date(); today.setHours(0,0,0,0);
    let html = '<div class="nu-wcard-grid">';
    workers.forEach(w => {
        const name = ((w.vorname || '') + ' ' + (w.name || '')).trim();
        const genderIcon = w.geschlecht === 'm' ? '♂' : w.geschlecht === 'w' ? '♀' : w.geschlecht === 'd' ? '⚧' : '';
        const photo = w.foto
            ? `<img src="${nuEscape(w.foto)}" alt="">`
            : `<div class="nu-wcard-initials">${nuInitials(w.vorname, w.name)}</div>`;

        // ID validity status
        let ausweisStatus = '';
        if (w.ausweis_gueltig_bis) {
            const bis = new Date(w.ausweis_gueltig_bis);
            const diffDays = Math.round((bis - today) / (1000*60*60*24));
            if (diffDays < 0) {
                ausweisStatus = `<span class="nu-wcard-status expired"><i class="material-symbols-outlined">warning</i> Ausweis abgelaufen</span>`;
            } else if (diffDays < 60) {
                ausweisStatus = `<span class="nu-wcard-status warn"><i class="material-symbols-outlined">hourglass_top</i> Läuft in ${diffDays} T. ab</span>`;
            } else {
                ausweisStatus = `<span class="nu-wcard-status ok"><i class="material-symbols-outlined">check</i> Ausweis gültig</span>`;
            }
        }

        html += `
        <div class="nu-wcard ${w.aktiv == 0 ? 'inactive' : ''}">
            <div class="nu-wcard-photo">${photo}</div>
            <div class="nu-wcard-body">
                <div class="nu-wcard-name">${nuEscape(name || '—')} ${genderIcon ? `<span class="nu-wcard-gender">${genderIcon}</span>` : ''}</div>
                ${w.personal_nr ? `<div class="nu-wcard-id">${nuEscape(w.personal_nr)}</div>` : ''}
                <div class="nu-wcard-meta">
                    ${w.qualifikation ? `<span><i class="material-symbols-outlined">verified</i> ${nuEscape(w.qualifikation)}</span>` : ''}
                    ${w.mobil ? `<span><i class="material-symbols-outlined">smartphone</i> ${nuEscape(w.mobil)}</span>` : (w.telefon ? `<span><i class="material-symbols-outlined">call</i> ${nuEscape(w.telefon)}</span>` : '')}
                    ${w.ort ? `<span><i class="material-symbols-outlined">location_on</i> ${nuEscape(w.ort)}</span>` : ''}
                </div>
                <div class="nu-wcard-footer">
                    ${ausweisStatus}
                    ${w.aktiv == 1 ? '<span class="nu-wcard-status ok"><i class="material-symbols-outlined">how_to_reg</i> Aktiv</span>' : '<span class="nu-wcard-status expired"><i class="material-symbols-outlined">person_off</i> Inaktiv</span>'}
                </div>
            </div>
            <div class="nu-wcard-actions">
                <button class="nu-btn-icon" onclick="nuOpenWorkerModal(${w.id})" title="Bearbeiten"><i class="material-symbols-outlined">edit</i></button>
                <button class="nu-btn-icon danger" onclick="nuDeleteWorker(${w.id}, '${nuEscape(name)}')" title="Löschen"><i class="material-symbols-outlined">delete</i></button>
            </div>
        </div>`;
    });
    html += '</div>';
    container.innerHTML = html;
}

// ============================================
// Worker Modal (Create/Edit)
// ============================================
let _nuSecFlags = null;
async function nuSecFlags() {
    if (_nuSecFlags) return _nuSecFlags;
    const d = await nuGet(NU_API + '/config');
    // Unknown (old server / error) → show everything, as before.
    _nuSecFlags = (d && typeof d.bewachung === 'boolean') ? d : { bewachung: true, stadiondienst: true };
    return _nuSecFlags;
}

async function nuOpenWorkerModal(id = null) {
    let w = {};
    if (id) {
        const all = await nuGet(NU_API + '/workers');
        if (all) w = all.find(x => x.id == id) || {};
    }
    // Security blocks only for tenants using them (Bereich switches) or
    // when this worker already carries such data. Hidden inputs keep values.
    const sec = await nuSecFlags();
    const showStd = sec.stadiondienst || !!(w.ordner_nr || w.barcode || w.dfb_pin);
    const showBew = sec.bewachung || showStd || !!(w.bewacher_id || w.funkrufname);

    const photoBlock = id
        ? (w.foto
            ? `<img src="${nuEscape(w.foto)}" alt="Foto" id="nuw_photo_img">`
            : `<div class="nu-photo-placeholder" id="nuw_photo_img"><i class="material-symbols-outlined">person</i></div>`)
        : `<div class="nu-photo-placeholder" id="nuw_photo_img"><i class="material-symbols-outlined">person_add</i><small>Erst speichern,<br>dann Foto hochladen</small></div>`;

    const body = `
        <div class="nu-worker-form">
            <div class="nu-worker-form-left">
                <div class="nu-photo-card">
                    <div class="nu-photo-box">${photoBlock}</div>
                    ${id ? `
                    <input type="file" id="nuw_photo_input" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="nuUploadPhoto(${id})">
                    <div class="nu-photo-actions">
                        <button class="nu-btn nu-btn-outline nu-btn-sm" type="button" onclick="document.getElementById('nuw_photo_input').click()"><i class="material-symbols-outlined">photo_camera</i> Bild ändern</button>
                        ${w.foto ? `<button class="nu-btn nu-btn-outline nu-btn-sm" type="button" onclick="nuDeletePhoto(${id})"><i class="material-symbols-outlined">delete</i></button>` : ''}
                    </div>` : ''}
                </div>
                ${id ? `
                <div class="nu-form-group" style="margin-top:14px">
                    <label>Status</label>
                    <md-outlined-select class="nu-form-control" id="nuw_aktiv">
                        <md-select-option value="1" ${w.aktiv == 1 ? 'selected' : ''}>Aktiv</md-select-option>
                        <md-select-option value="0" ${w.aktiv == 0 ? 'selected' : ''}>Inaktiv</md-select-option>
                    </md-outlined-select>
                </div>` : ''}
            </div>

            <div class="nu-worker-form-right">
                <!-- PERSÖNLICHE DATEN -->
                <div class="nu-form-section-title"><i class="material-symbols-outlined">person</i> Persönliche Daten</div>
                <div class="nu-form-row">
                    <div class="nu-form-group"><label>Vorname *</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_vorname" value="${nuEscape(w.vorname || '')}"></md-outlined-text-field></div>
                    <div class="nu-form-group"><label>Nachname *</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_name" value="${nuEscape(w.name || '')}"></md-outlined-text-field></div>
                </div>
                <div class="nu-form-row">
                    <div class="nu-form-group"><label>Geschlecht</label>
                        <md-outlined-select class="nu-form-control" id="nuw_geschlecht">
                            <md-select-option value="">—</md-select-option>
                            <md-select-option value="m" ${w.geschlecht === 'm' ? 'selected' : ''}>Männlich</md-select-option>
                            <md-select-option value="w" ${w.geschlecht === 'w' ? 'selected' : ''}>Weiblich</md-select-option>
                            <md-select-option value="d" ${w.geschlecht === 'd' ? 'selected' : ''}>Divers</md-select-option>
                        </md-outlined-select>
                    </div>
                    <div class="nu-form-group"><label>Geburtsname</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_geburtsname" value="${nuEscape(w.geburtsname || '')}"></md-outlined-text-field></div>
                </div>
                <div class="nu-form-row">
                    <div class="nu-form-group"><label>Geburtstag</label><md-outlined-text-field type="date" class="nu-form-control" id="nuw_geburtstag" value="${nuEscape(w.geburtstag || '')}"></md-outlined-text-field></div>
                    <div class="nu-form-group"><label>Geburtsort</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_geburtsort" value="${nuEscape(w.geburtsort || '')}"></md-outlined-text-field></div>
                </div>
                <div class="nu-form-group"><label>Geburtsland</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_geburtsland" value="${nuEscape(w.geburtsland || '')}" placeholder="z.B. Deutschland"></md-outlined-text-field></div>

                <!-- IDENTIFIKATION -->
                <div class="nu-form-section-title"><i class="material-symbols-outlined">badge</i> Identifikation</div>
                <div class="nu-form-group"><label>Personal-Nr.</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_personal_nr" value="${nuEscape(w.personal_nr || '')}" placeholder="z.B. MA-00004"></md-outlined-text-field></div>
                <div class="nu-form-row"${showBew ? '' : ' style="display:none"'}>
                    <div class="nu-form-group"><label>Bewacherregister-ID</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_bewacher_id" value="${nuEscape(w.bewacher_id || '')}"></md-outlined-text-field></div>
                    <div class="nu-form-group"><label>Funkrufname</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_funkrufname" value="${nuEscape(w.funkrufname || '')}"></md-outlined-text-field></div>
                </div>
                <div class="nu-form-row"${showStd ? '' : ' style="display:none"'}>
                    <div class="nu-form-group"><label>Ordnernummer</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_ordner_nr" value="${nuEscape(w.ordner_nr || '')}"></md-outlined-text-field></div>
                    <div class="nu-form-group"><label>Barcode</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_barcode" value="${nuEscape(w.barcode || '')}"></md-outlined-text-field></div>
                </div>
                <div class="nu-form-row"${showStd ? '' : ' style="display:none"'}>
                    <div class="nu-form-group"><label>DFB-Pin</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_dfb_pin" value="${nuEscape(w.dfb_pin || '')}"></md-outlined-text-field></div>
                </div>
                <div class="nu-form-group"><label>Sozialversicherungsnr.</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_sv_nr" value="${nuEscape(w.sv_nr || '')}"></md-outlined-text-field></div>

                <!-- PERSONALAUSWEIS -->
                <div class="nu-form-section-title"><i class="material-symbols-outlined">id_card</i> Personalausweis</div>
                <div class="nu-form-group"><label>Ausweis-Nummer</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_ausweis_nr" value="${nuEscape(w.ausweis_nr || '')}"></md-outlined-text-field></div>
                <div class="nu-form-row">
                    <div class="nu-form-group"><label>Gültig ab</label><md-outlined-text-field type="date" class="nu-form-control" id="nuw_ausweis_gueltig_ab" value="${nuEscape(w.ausweis_gueltig_ab || '')}"></md-outlined-text-field></div>
                    <div class="nu-form-group"><label>Gültig bis</label><md-outlined-text-field type="date" class="nu-form-control" id="nuw_ausweis_gueltig_bis" value="${nuEscape(w.ausweis_gueltig_bis || '')}"></md-outlined-text-field></div>
                </div>

                <!-- ANSCHRIFT -->
                <div class="nu-form-section-title"><i class="material-symbols-outlined">location_on</i> Anschrift</div>
                <div class="nu-form-group"><label>Straße &amp; Hausnr.</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_strasse" value="${nuEscape(w.strasse || '')}"></md-outlined-text-field></div>
                <div class="nu-form-row">
                    <div class="nu-form-group" style="flex:0 0 30%"><label>PLZ</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_plz" value="${nuEscape(w.plz || '')}"></md-outlined-text-field></div>
                    <div class="nu-form-group"><label>Ort</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_ort" value="${nuEscape(w.ort || '')}"></md-outlined-text-field></div>
                </div>
                <div class="nu-form-row">
                    <div class="nu-form-group"><label>Bundesland</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_bundesland" value="${nuEscape(w.bundesland || '')}" placeholder="z.B. Sachsen-Anhalt"></md-outlined-text-field></div>
                    <div class="nu-form-group"><label>Land</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_land" value="${nuEscape(w.land || 'Deutschland')}"></md-outlined-text-field></div>
                </div>

                <!-- KONTAKT -->
                <div class="nu-form-section-title"><i class="material-symbols-outlined">call</i> Kontakt</div>
                <div class="nu-form-row">
                    <div class="nu-form-group"><label>Telefon</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_telefon" value="${nuEscape(w.telefon || '')}"></md-outlined-text-field></div>
                    <div class="nu-form-group"><label>Mobil</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_mobil" value="${nuEscape(w.mobil || '')}" placeholder="+49 ..."></md-outlined-text-field></div>
                </div>
                <div class="nu-form-group"><label>E-Mail</label><md-outlined-text-field type="email" class="nu-form-control" id="nuw_email" value="${nuEscape(w.email || '')}"></md-outlined-text-field></div>

                <!-- QUALIFIKATION -->
                <div class="nu-form-section-title"><i class="material-symbols-outlined">workspace_premium</i> Qualifikation</div>
                <div class="nu-form-row">
                    <div class="nu-form-group"><label>Qualifikation</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_qualifikation" value="${nuEscape(w.qualifikation || '')}" placeholder="z.B. Erste Hilfe, Staplerschein"></md-outlined-text-field></div>
                    <div class="nu-form-group"><label>Skills</label><md-outlined-text-field type="text" class="nu-form-control" id="nuw_skills" value="${nuEscape(w.skills || '')}" placeholder="z.B. Englisch, Brandschutz"></md-outlined-text-field></div>
                </div>
                <div class="nu-form-group"><label>Bemerkung</label><textarea class="nu-form-control" id="nuw_bemerkung" rows="2">${nuEscape(w.bemerkung || '')}</textarea></div>
            </div>
        </div>
    `;
    const footer = `
        <button class="nu-btn nu-btn-outline" onclick="nuCloseModal()">Abbrechen</button>
        <button class="nu-btn nu-btn-primary" onclick="nuSaveWorker(${id || 'null'})">${id ? 'Aktualisieren' : 'Erstellen'}</button>
    `;
    nuOpenModal(id ? 'Mitarbeiter bearbeiten' : 'Neuer Mitarbeiter', body, footer, 'wide');
}

async function nuSaveWorker(id) {
    const val = (eId) => document.getElementById(eId)?.value || null;
    const data = {
        action: 'save_worker',
        vorname: val('nuw_vorname'),
        name: val('nuw_name'),
        personal_nr: val('nuw_personal_nr'),
        geschlecht: val('nuw_geschlecht'),
        qualifikation: val('nuw_qualifikation'),
        skills: val('nuw_skills'),
        telefon: val('nuw_telefon'),
        mobil: val('nuw_mobil'),
        email: val('nuw_email'),
        barcode: val('nuw_barcode'),
        ordner_nr: val('nuw_ordner_nr'),
        bewacher_id: val('nuw_bewacher_id'),
        dfb_pin: val('nuw_dfb_pin'),
        funkrufname: val('nuw_funkrufname'),
        strasse: val('nuw_strasse'),
        plz: val('nuw_plz'),
        ort: val('nuw_ort'),
        bundesland: val('nuw_bundesland'),
        land: val('nuw_land'),
        geburtstag: val('nuw_geburtstag'),
        geburtsort: val('nuw_geburtsort'),
        geburtsname: val('nuw_geburtsname'),
        geburtsland: val('nuw_geburtsland'),
        sv_nr: val('nuw_sv_nr'),
        ausweis_nr: val('nuw_ausweis_nr'),
        ausweis_gueltig_ab: val('nuw_ausweis_gueltig_ab'),
        ausweis_gueltig_bis: val('nuw_ausweis_gueltig_bis'),
        bemerkung: val('nuw_bemerkung'),
    };
    if (id) {
        data.id = id;
        const aktivEl = document.getElementById('nuw_aktiv');
        if (aktivEl) data.aktiv = aktivEl.value;
    }
    if (!data.vorname && !data.name) {
        nuShowToast('Vor- oder Nachname ist erforderlich', 'error');
        return;
    }
    const result = id ? await nuPost(NU_API + '/workers/' + id, data) : await nuPost(NU_API + '/workers', data);
    if (result && result.success) {
        nuShowToast(id ? 'Mitarbeiter aktualisiert' : 'Mitarbeiter erstellt', 'success');
        if (!id && result.id) {
            // Reopen modal in edit mode so user can now upload photo
            nuCloseModal();
            nuLoadWorkers();
            setTimeout(() => nuOpenWorkerModal(result.id), 250);
        } else {
            nuCloseModal();
            nuLoadWorkers();
        }
    } else {
        nuShowToast(result?.error || 'Fehler', 'error');
    }
}

// ============================================
// Photo upload / delete
// ============================================
async function nuUploadPhoto(workerId) {
    const input = document.getElementById('nuw_photo_input');
    if (!input || !input.files.length) return;
    const file = input.files[0];

    const fd = new FormData();
    fd.append('worker_id', workerId);
    fd.append('foto', file);

    try {
        const headers = nuAuthHeaders();
        const res = await fetch(NU_API + '/workers/' + workerId + '/photo', { method: 'POST', headers: headers, body: fd });
        if (res.status === 401) { nuClearToken(); window.location.href = 'nu_login.php'; return; }
        const result = await res.json();
        if (result && result.success) {
            nuShowToast('Foto hochgeladen', 'success');
            nuCloseModal();
            nuLoadWorkers();
            setTimeout(() => nuOpenWorkerModal(workerId), 200);
        } else {
            nuShowToast(result?.error || 'Upload fehlgeschlagen', 'error');
        }
    } catch (e) {
        nuShowToast('Upload-Fehler', 'error');
    }
}

async function nuDeletePhoto(workerId) {
    if (!confirm('Foto entfernen?')) return;
    const result = await nuPost(NU_API + '/workers/' + workerId + '/delete-photo', {});
    if (result && result.success) {
        nuShowToast('Foto entfernt', 'success');
        nuCloseModal();
        nuLoadWorkers();
        setTimeout(() => nuOpenWorkerModal(workerId), 200);
    } else {
        nuShowToast(result?.error || 'Fehler', 'error');
    }
}

async function nuDeleteWorker(id, name) {
    if (!confirm('Mitarbeiter "' + name + '" endgültig löschen?')) return;
    const result = await nuPost(NU_API + '/workers/' + id + '/delete', {});
    if (result && result.success) {
        nuShowToast('Mitarbeiter gelöscht', 'success');
        nuLoadWorkers();
    } else {
        nuShowToast(result?.error || 'Fehler', 'error');
    }
}

// ============================================
// History Tab (Auftragshistorie)
// ============================================
let nuHistoryMonat = new Date().getMonth() + 1;
let nuHistoryJahr = new Date().getFullYear();

function nuChangeHistoryMonth(offset) {
    nuHistoryMonat += offset;
    if (nuHistoryMonat > 12) { nuHistoryMonat = 1; nuHistoryJahr++; }
    if (nuHistoryMonat < 1) { nuHistoryMonat = 12; nuHistoryJahr--; }
    nuLoadHistory();
}

function nuGoCurrentMonth() {
    nuHistoryMonat = new Date().getMonth() + 1;
    nuHistoryJahr = new Date().getFullYear();
    nuLoadHistory();
}

const NU_MONTH_NAMES = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

async function nuLoadHistory() {
    const content = document.getElementById('nuContent');
    content.innerHTML = `
        <div class="nu-week-nav">
            <button class="nu-btn nu-btn-outline nu-btn-sm" onclick="nuChangeHistoryMonth(-1)"><i class="material-symbols-outlined">chevron_left</i></button>
            <span class="nu-week-label">${NU_MONTH_NAMES[nuHistoryMonat - 1]} ${nuHistoryJahr}</span>
            <button class="nu-btn nu-btn-outline nu-btn-sm" onclick="nuChangeHistoryMonth(1)"><i class="material-symbols-outlined">chevron_right</i></button>
            <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="nuGoCurrentMonth()">Aktueller Monat</button>
        </div>
        <div id="nuHistoryList"><div class="nu-empty"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress><p>Lade Auftragshistorie...</p></div></div>
    `;

    const data = await nuGet(NU_API + '/completed-shifts?monat=' + nuHistoryMonat + '&jahr=' + nuHistoryJahr);
    const container = document.getElementById('nuHistoryList');
    if (!data || !container) return;

    const shifts = data.shifts || [];

    // Summary bar
    let summaryHtml = `
        <div class="nu-history-summary">
            <div class="nu-history-stat"><span class="nu-history-stat-value">${data.total_shifts}</span><span class="nu-history-stat-label">Einsätze</span></div>
            <div class="nu-history-stat"><span class="nu-history-stat-value">${data.total_workers}</span><span class="nu-history-stat-label">MA-Einsätze</span></div>
            <div class="nu-history-stat"><span class="nu-history-stat-value">${data.total_std > 0 ? data.total_std.toFixed(1) + ' Std' : '–'}</span><span class="nu-history-stat-label">Gesamtstunden</span></div>
        </div>
    `;

    if (shifts.length === 0) {
        container.innerHTML = summaryHtml + '<div class="nu-empty"><i class="material-symbols-outlined">assignment_turned_in</i><p>Keine bestätigten Einsätze in diesem Monat</p></div>';
        return;
    }

    let html = summaryHtml;
    shifts.forEach(shift => {
        const d = new Date(shift.datum + 'T00:00:00');
        const dayName = NU_DAYS_FULL[d.getDay()];
        const workers = shift.workers_assigned || [];

        html += `
        <div class="nu-shift-card nu-history-card">
            <div class="nu-shift-header">
                <div class="nu-shift-header-left">
                    <span class="nu-shift-date">${dayName}, ${nuFormatDate(shift.datum)}</span>
                    <span class="nu-shift-time"><i class="material-symbols-outlined">schedule</i> ${nuFormatTime(shift.ist_start)} – ${nuFormatTime(shift.ist_ende)} Uhr</span>
                </div>
                <span class="nu-badge confirmed"><i class="material-symbols-outlined">check_circle</i> Bestätigt</span>
            </div>
            <div class="nu-shift-meta">
                <span><strong>Objekt:</strong> ${nuEscape(shift.objektname || '–')}</span>
                <span><strong>Kunde:</strong> ${nuEscape(shift.kundenname || '–')}</span>
                <span><strong>Bestellung:</strong> ${nuEscape(shift.bestell_id || '–')}</span>
                ${shift.beschreibung ? `<span><strong>Notiz:</strong> ${nuEscape(shift.beschreibung)}</span>` : ''}
            </div>`;

        if (workers.length > 0) {
            html += `<div class="nu-history-workers"><div class="nu-history-workers-title"><i class="material-symbols-outlined">how_to_reg</i> Eingesetzte Mitarbeiter (${workers.length})</div><div class="nu-history-workers-list">`;
            workers.forEach(w => {
                const name = ((w.vorname || '') + ' ' + (w.name || '')).trim();
                const stdDisplay = w.ist_std !== null ? w.ist_std.toFixed(1) + ' Std' : '–';
                html += `
                    <div class="nu-history-worker-row">
                        <div class="nu-worker-avatar sm">${nuInitials(w.vorname, w.name)}</div>
                        <span class="nu-history-worker-name">${nuEscape(name)}</span>
                        ${w.personal_nr ? `<span class="nu-history-worker-nr">${nuEscape(w.personal_nr)}</span>` : ''}
                        <span class="nu-history-worker-pos">${nuEscape(w.position_name || '')}</span>
                        <span class="nu-history-worker-std">${stdDisplay}</span>
                    </div>`;
            });
            html += '</div></div>';
        } else {
            html += '<div style="padding:8px 20px;color:var(--nu-gray-400);font-size:0.82rem"><i class="material-symbols-outlined">info</i> Keine Mitarbeiter zugewiesen</div>';
        }

        html += '</div>';
    });

    container.innerHTML = html;
}

// ============================================
// Modal
// ============================================
function nuOpenModal(title, body, footer, size) {
    let overlay = document.getElementById('nuModalOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'nuModalOverlay';
        overlay.className = 'nu-modal-overlay';
        overlay.innerHTML = `<div class="nu-modal"><div class="nu-modal-header"><h3 id="nuModalTitle"></h3><button class="nu-modal-close" onclick="nuCloseModal()">&times;</button></div><div class="nu-modal-body" id="nuModalBody"></div><div class="nu-modal-footer" id="nuModalFooter"></div></div>`;
        document.body.appendChild(overlay);
    }
    document.getElementById('nuModalTitle').textContent = title;
    document.getElementById('nuModalBody').innerHTML = body;
    document.getElementById('nuModalFooter').innerHTML = footer || '';
    const modalEl = overlay.querySelector('.nu-modal');
    modalEl.classList.toggle('wide', size === 'wide');
    overlay.classList.add('active');
}

function nuCloseModal() {
    const overlay = document.getElementById('nuModalOverlay');
    if (overlay) overlay.classList.remove('active');
}

// ============================================
// Logout
// ============================================
async function nuLogout() {
    nuClearToken();
    window.location.href = 'nu_login.php';
}

// ============================================
// Init
// ============================================
document.addEventListener('DOMContentLoaded', () => {
    nuWeekStart = nuGetWeekStart();
    nuLoadShifts();
});
