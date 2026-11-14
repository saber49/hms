/* ============================================
   Clood-X — Benutzerverwaltung Module
   Backend: /api/users, /api/users/permissions
   Requires: users.manage permission (server-enforced)
   ============================================ */

const USERS = {
    state: {
        all: [],
        filter: { search: '', role: 'all', status: 'all' },
        registry: null,        // { groups, presets } — fetched on first open
        roleLabels: {},        // key -> label map (built-ins + custom roles)
        customRoles: [],        // [{key,label,permissions,...}] for this tenant
    },
    api: API.users || (API_BASE + '/users'),
};

// The registry (incl. the tenant's Bereich list for the restriction
// picker) is cached for the whole session — drop it when the Bereich
// catalog changes so the picker shows fresh entries on next open.
if (window.HMSRealtime) HMSRealtime.on('bereiche', () => { USERS.state.registry = null; });

// Custom-roles API (tenant-defined, reusable permission bundles)
const ROLES_API = (typeof API_BASE !== 'undefined' ? API_BASE : '') + '/roles';

// ---------- Helpers ----------
function _isOwner(u)         { return !!u.is_owner; }
function _hasManagePerm()     {
    return typeof hasPermission === 'function' && hasPermission('users.manage');
}
function _initials(name) {
    if (!name) return '?';
    return name.trim().split(/\s+/).slice(0,2).map(s => s.charAt(0).toUpperCase()).join('');
}
function _roleLabel(role) {
    const builtin = {
        owner:'Inhaber', admin:'Administrator', manager:'Manager',
        dispo:'Disponent', buchhaltung:'Buchhaltung',
        viewer:'Viewer', custom:'Benutzerdefiniert',
    };
    return builtin[role]
        || (USERS.state.roleLabels && USERS.state.roleLabels[role])
        || role;
}

// Rebuild the role label map + custom-role list from the cached registry.
function _rebuildRoleMeta() {
    const presets = (USERS.state.registry && USERS.state.registry.presets) || [];
    const map = {};
    presets.forEach(p => { map[p.key] = p.label; });
    USERS.state.roleLabels  = map;
    USERS.state.customRoles = presets.filter(p => p.custom);
}
function _escape(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
// md-checkbox is a custom element — the CSS :checked pseudo-class never
// matches it, so "which boxes are ticked" is read from the .checked
// property instead of a :checked selector.
function _checkedBoxes(selector) {
    return [...document.querySelectorAll(selector)].filter(cb => cb.checked);
}

// ---------- MD3 dialogs (official <md-dialog>) ----------
// The users module no longer uses the legacy openModal() shell.
function openUsersDialog(title, bodyHtml, footerHtml, wide = false) {
    document.getElementById('usersMdDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'usersMdDialog';
    if (wide) dlg.classList.add('users-dialog-xl');
    dlg.innerHTML = `
      <div slot="headline">${title}</div>
      <div slot="content" class="users-dialog-content">${bodyHtml}</div>
      <div slot="actions">${footerHtml}</div>`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    dlg.show();
    return dlg;
}
function closeUsersDialog() { document.getElementById('usersMdDialog')?.close(); }

// Promise-based replacements for window.confirm / window.prompt.
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
// Promise-based info dialog with a single acknowledge button.
function mdAlert(message, title = 'Hinweis') {
    return new Promise(resolve => {
        const dlg = document.createElement('md-dialog');
        dlg.innerHTML = `
          <div slot="headline">${title}</div>
          <div slot="content" style="white-space:pre-line">${message}</div>
          <div slot="actions">
            <md-filled-button data-v="ok">Verstanden</md-filled-button>
          </div>`;
        document.body.appendChild(dlg);
        dlg.querySelector('[data-v="ok"]').onclick = () => dlg.close('ok');
        dlg.addEventListener('closed', () => { dlg.remove(); resolve(); });
        dlg.show();
    });
}
function mdPrompt(message, { title = 'Eingabe', type = 'password', label = '', value = '' } = {}) {
    return new Promise(resolve => {
        const dlg = document.createElement('md-dialog');
        dlg.innerHTML = `
          <div slot="headline">${title}</div>
          <div slot="content">
            <p style="margin:0 0 12px;white-space:pre-line">${message}</p>
            <md-outlined-text-field class="md-prompt-field" type="${type}" label="${label}" value="${escapeHtml(value)}" style="width:100%"></md-outlined-text-field>
          </div>
          <div slot="actions">
            <md-text-button data-v="cancel">Abbrechen</md-text-button>
            <md-filled-button data-v="ok">OK</md-filled-button>
          </div>`;
        document.body.appendChild(dlg);
        const field = dlg.querySelector('.md-prompt-field');
        dlg.querySelector('[data-v="cancel"]').onclick = () => dlg.close('cancel');
        dlg.querySelector('[data-v="ok"]').onclick = () => dlg.close('ok');
        dlg.addEventListener('closed', () => {
            const v = dlg.returnValue === 'ok' ? field.value : null;
            dlg.remove(); resolve(v);
        });
        dlg.show();
    });
}

// Material Symbols shorthand
function _ms(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}

function _fmtDate(d) {
    if (!d) return '–';
    const p = String(d).slice(0,10).split('-');
    if (p.length !== 3) return d;
    const t = String(d).length > 10 ? ' ' + String(d).slice(11,16) : '';
    return `${p[2]}.${p[1]}.${p[0]}${t}`;
}

// Short labels for the per-subject action toggles (e.g. Kunden → Ansehen/Bearbeiten).
const _PERM_ACTION_LABEL = {
    view: 'Ansehen', edit: 'Bearbeiten', send: 'Senden',
    export: 'Export', manage: 'Verwalten', view_all: 'Alle',
};

/**
 * Build the rows for one permission group as a compact TABLE: one row per
 * subject (e.g. "Kunden"), one fixed column per action (Ansehen, Bearbeiten,
 * Senden, …). Permissions sharing a base key collapse into the same row, so
 * `kunden.view` + `kunden.edit` render as a single line with two cells.
 *
 * Keeps the original <md-checkbox class="perm-cb|role-perm-cb" data-group
 * value> markup so the existing preset/counter/group-toggle handlers keep
 * working.
 */
const _PERM_ACTION_ORDER = ['view', 'edit', 'send', 'export', 'view_all', 'manage'];
const _PERM_SUBJECT_STRIP = /\s*(ansehen\s*&\s*zuweisen|bearbeiten\s*&\s*ausgeben|verbinden\s*&\s*trennen|ansehen|lesen|bearbeiten|senden|verwalten|ausgeben|exportieren)$/i;

const _PERM_GROUP_ICON = {
    Dashboard: 'space_dashboard', Vertrieb: 'trending_up', Stammdaten: 'database',
    Personal: 'groups', Disposition: 'event_note', Verkauf: 'shopping_cart',
    Kommunikation: 'forum', System: 'admin_panel_settings',
};
const _PERM_EXTRA_LABEL = { export: 'Export', send: 'Senden', manage: 'Verwalten' };

/**
 * ONE permission matrix for every group: rows = subject, columns =
 * Ansehen | Bearbeiten | Weitere. Keys sharing a base (`x.view`, `x.edit`,
 * `x.export`) form one row; a key without view/edit siblings (e.g.
 * users.manage, Vertriebsleitung) is its own row with the box in "Weitere".
 * Every registry key is rendered — nothing can be hidden by the layout.
 */
function _permMatrixHtml(groups, selSet, cbClass, disabled) {
    const dis = disabled ? 'disabled' : '';
    const cb = (g, it) => `<md-checkbox class="${cbClass}" data-group="${_escape(g)}" value="${_escape(it.key)}" ${selSet.has(it.key) ? 'checked' : ''} ${dis} aria-label="${_escape(it.label)}"></md-checkbox>`;
    const body = (groups || []).map(g => {
        const bases = {}, order = [];
        (g.items || []).forEach(it => {
            const dot = it.key.lastIndexOf('.');
            const base = it.key.slice(0, dot), action = it.key.slice(dot + 1);
            const plain = action === 'view' || action === 'edit';
            const k = plain || (bases[base] && (bases[base].view || bases[base].edit)) ? base : it.key;
            if (!bases[k]) { bases[k] = { extras: [] }; order.push(k); }
            if (plain) bases[k][action] = it; else bases[k].extras.push(Object.assign({ action }, it));
        });
        // A base whose view/edit appear AFTER an extra was split — merge back.
        order.slice().forEach(k => {
            const dot = k.lastIndexOf('.'); const base = k.slice(0, dot);
            if (bases[k].extras.length === 1 && !bases[k].view && !bases[k].edit && bases[base] && (bases[base].view || bases[base].edit) && k !== base) {
                bases[base].extras.push(bases[k].extras[0]); delete bases[k]; order.splice(order.indexOf(k), 1);
            }
        });
        const rows = order.map(k => {
            const r = bases[k];
            const solo = !r.view && !r.edit;
            const main = r.view || r.edit || r.extras[0];
            let subj = main.label, hint = '';
            if (solo) { const i = subj.indexOf(':'); if (i > 0) { hint = subj.slice(i + 1).trim(); subj = subj.slice(0, i); } }
            else subj = subj.replace(_PERM_SUBJECT_STRIP, '');
            const extras = r.extras.map(it => `<label class="pm-extra" title="${_escape(it.label)}">${cb(g.group, it)}${solo ? '' : `<span>${_escape(_PERM_EXTRA_LABEL[it.action] || it.label)}</span>`}</label>`).join('');
            return `<tr>
                <td class="pm-subj"><span>${_escape(subj)}</span>${hint ? `<small>${_escape(hint)}</small>` : ''}</td>
                <td class="pm-c">${r.view ? cb(g.group, r.view) : ''}</td>
                <td class="pm-c">${r.edit ? cb(g.group, r.edit) : ''}</td>
                <td class="pm-x">${extras}</td>
            </tr>`;
        }).join('');
        const note = g.group === 'Vertrieb'
            ? `<tr class="pm-note"><td colspan="4">${_ms('lock', 'font-size:15px')} <strong>Einstellungen</strong> (Listen, Fristen, Zuweisung, Webformular), Löschen und Export bleiben dem Inhaber und den Administratoren vorbehalten.</td></tr>` : '';
        return `<tbody class="pm-group" data-group="${_escape(g.group)}">
            <tr class="pm-ghead"><th colspan="3">${_ms(_PERM_GROUP_ICON[g.group] || 'folder', 'font-size:18px')} ${_escape(g.group)}</th>
                <th class="pm-gtoggle">${disabled ? '' : `<button type="button" class="pm-all" data-toggle="${_escape(g.group)}">Alle</button>`}</th></tr>
            ${rows}${note}
        </tbody>`;
    }).join('');
    return `<div class="pm-wrap"><table class="pm-table">
        <thead><tr><th class="pm-subj">Bereich</th><th class="pm-c">Ansehen</th><th class="pm-c">Bearbeiten</th><th class="pm-x">Weitere</th></tr></thead>
        ${body}</table></div>`;
}

// Short descriptions for the role cards.
const _ROLE_INFO = {
    admin:       ['shield_person', 'Vollzugriff auf alles, inkl. Benutzer und Vertrieb-Einstellungen'],
    manager:     ['manage_accounts', 'Operatives Tagesgeschäft, Vertrieb und Auswertung'],
    dispo:       ['event_note', 'Planung, Bestelleingang und MA-Nachrichten'],
    buchhaltung: ['account_balance', 'Bestellungen, Zeiterfassung und Controlling'],
    viewer:      ['visibility', 'Alles ansehen, nichts ändern'],
    custom:      ['tune', 'Rechte einzeln festlegen'],
};

// ---------- Entry point ----------
async function renderUsers() {
    const content = document.getElementById('content');

    if (!_hasManagePerm()) {
        content.innerHTML = `
          <div class="no-access">
            ${_ms('lock')}
            <h3>Kein Zugriff</h3>
            <p>Sie haben keine Berechtigung, die Benutzerverwaltung zu öffnen.<br>
               Bitte kontaktieren Sie den Inhaber Ihres Unternehmens.</p>
          </div>`;
        return;
    }

    // Show skeleton while loading (official MD3 progress indicator) — not
    // when the page is already on screen (refresh after a save).
    if (!document.getElementById('usersGrid')) content.innerHTML = `<div class="users-empty"><md-circular-progress indeterminate></md-circular-progress><p>Benutzer werden geladen…</p></div>`;

    // Load the permission/role registry (incl. this tenant's custom roles) so
    // the grid chips + role labels render correctly.
    if (!USERS.state.registry) {
        USERS.state.registry = await apiGet(API_BASE + '/users/permissions');
    }
    _rebuildRoleMeta();

    const users = await apiGet(USERS.api);
    USERS.state.all = Array.isArray(users) ? users : [];

    paintUsersPage();
}

function paintUsersPage() {
    const content = document.getElementById('content');
    const data = USERS.state.all;

    const totals = {
        total: data.length,
        active: data.filter(u => u.aktiv == 1).length,
        inactive: data.filter(u => u.aktiv != 1).length,
        owner: data.filter(_isOwner).length,
    };

    const roleKeys = ['all','owner','admin','manager','dispo','buchhaltung','viewer','custom'].concat((USERS.state.customRoles||[]).map(r => r.key));
    const html = `
      <div class="users-topbar">
        <div class="users-topbar-left">
          <h2>${_ms('shield_person')} Benutzerverwaltung</h2>
          <div class="sub">Verwalten Sie Benutzer und ihre Berechtigungen für Ihr Unternehmen.</div>
        </div>
        <div class="users-topbar-right">
          <md-outlined-button onclick="openRolesModal()">
            <md-icon slot="icon">manage_accounts</md-icon>Rollen verwalten
          </md-outlined-button>
          <md-filled-button onclick="openUserModal()">
            <md-icon slot="icon">person_add</md-icon>Neuer Benutzer
          </md-filled-button>
        </div>
      </div>

      <div class="users-stats">
        <div class="users-stat total">
          <span class="ic">${_ms('group')}</span>
          <div><div class="v">${totals.total}</div><div class="l">Gesamt</div></div>
        </div>
        <div class="users-stat active">
          <span class="ic">${_ms('check_circle')}</span>
          <div><div class="v">${totals.active}</div><div class="l">Aktiv</div></div>
        </div>
        <div class="users-stat inactive">
          <span class="ic">${_ms('pause_circle')}</span>
          <div><div class="v">${totals.inactive}</div><div class="l">Deaktiviert</div></div>
        </div>
        <div class="users-stat owner">
          <span class="ic">${_ms('workspace_premium')}</span>
          <div><div class="v">${totals.owner}</div><div class="l">Inhaber</div></div>
        </div>
      </div>

      <div class="users-filters">
        <md-outlined-text-field id="u_search" placeholder="Nach Name, Benutzername oder E-Mail suchen…" value="${_escape(USERS.state.filter.search)}">
          <md-icon slot="leading-icon">search</md-icon>
        </md-outlined-text-field>
        <md-chip-set class="filter-chips" id="u_role_chips" data-keys="${_escape(roleKeys.join(','))}">
          ${roleKeys
            .map(r => `<md-filter-chip data-role="${r}" label="${r==='all'?'Alle':_escape(_roleLabel(r))}" ${USERS.state.filter.role===r?'selected':''}></md-filter-chip>`).join('')}
        </md-chip-set>
        <md-chip-set class="filter-chips" id="u_status_chips">
          ${['all','active','inactive'].map(s => `<md-filter-chip data-status="${s}" label="${ {all:'Alle',active:'Aktiv',inactive:'Deaktiviert'}[s] }" ${USERS.state.filter.status===s?'selected':''}></md-filter-chip>`).join('')}
        </md-chip-set>
      </div>

      <div id="usersGrid"></div>
    `;

    // Same page + same role chips on screen: patch in place (filters keep
    // their listeners and the typed search). New/renamed roles → rebuild.
    if (document.getElementById('usersGrid') && document.getElementById('u_role_chips')?.dataset.keys === roleKeys.join(',') && typeof hmsMorph === 'function') {
        hmsMorph(content, html, { keep: el => el.id === 'usersGrid' || el.classList.contains('users-filters') });
    } else {
        content.innerHTML = html;
        bindFilterEvents();
    }
    paintUsersGrid();
}

function bindFilterEvents() {
    document.getElementById('u_search').addEventListener('input', (e) => {
        USERS.state.filter.search = e.target.value;
        paintUsersGrid();
    });
    // md-filter-chip toggles itself on click — enforce single-select
    // (radio behaviour) after the component's own toggle has run.
    const bindChipSet = (setId, key) => {
        const chips = document.querySelectorAll(`#${setId} md-filter-chip`);
        chips.forEach(c => {
            c.addEventListener('click', () => {
                USERS.state.filter[key] = c.dataset[key === 'role' ? 'role' : 'status'];
                requestAnimationFrame(() => {
                    chips.forEach(x => { x.selected = (x === c); });
                });
                paintUsersGrid();
            });
        });
    };
    bindChipSet('u_role_chips', 'role');
    bindChipSet('u_status_chips', 'status');
}

function paintUsersGrid() {
    const grid = document.getElementById('usersGrid');
    if (!grid) return;
    const f = USERS.state.filter;
    const q = (f.search || '').toLowerCase().trim();

    const rows = USERS.state.all.filter(u => {
        if (f.role !== 'all' && u.role !== f.role) return false;
        if (f.status === 'active'   && u.aktiv != 1) return false;
        if (f.status === 'inactive' && u.aktiv == 1) return false;
        if (q) {
            const haystack = [u.username, u.full_name, u.email].map(s => (s||'').toLowerCase()).join(' ');
            if (!haystack.includes(q)) return false;
        }
        return true;
    });

    if (rows.length === 0) {
        grid.innerHTML = `<div class="users-empty">${_ms('search_off')}<p>Keine Benutzer entsprechen dem Filter.</p></div>`;
        return;
    }

    const cards = `<div class="users-grid">${rows.map(userCardHtml).join('')}</div>`;
    if (typeof hmsMorph === 'function') hmsMorph(grid, cards); else grid.innerHTML = cards;
}

function _permCountFor(u) {
    const role = u.role || 'viewer';
    if (Array.isArray(u.permissions)) return u.permissions.length;
    if (role === 'owner' || role === 'admin') return '∞';
    // Custom-role users store no explicit list (live resolution) — look the
    // count up from the role definition instead of showing a bare "–".
    const cr = (USERS.state.customRoles || []).find(r => r.key === role);
    return cr ? cr.permissions.length : '–';
}

function userCardHtml(u) {
    const role = u.role || 'viewer';
    const permCount = _permCountFor(u);
    const isMe = window.CURRENT_USER && Number(window.CURRENT_USER.id) === Number(u.id);
    const ownerClass    = _isOwner(u) ? 'is-owner' : '';
    const inactiveClass = (u.aktiv == 1) ? '' : 'is-inactive';

    return `
      <div data-mk="u${u.id}" class="user-card ${ownerClass} is-${role} ${inactiveClass}">
        ${_isOwner(u) ? '<div class="owner-ribbon">Inhaber</div>' : ''}
        <div class="user-card-head">
          <div class="user-avatar">${_escape(_initials(u.full_name || u.username))}</div>
          <div class="user-card-meta">
            <div class="name">${_escape(u.full_name || u.username)}${isMe ? ' <span style="color:var(--gray-400);font-weight:400;font-size:0.78rem">(Sie)</span>' : ''}</div>
            <div class="email">${_escape(u.email || '–')}</div>
          </div>
          <span class="role-chip r-${role}">${_roleLabel(role)}</span>
        </div>
        <dl class="user-card-body">
          <dt>Benutzername</dt><dd>${_escape(u.username)}</dd>
          <dt>Status</dt><dd>${u.aktiv == 1 ? '<span style="color:var(--success)">● Aktiv</span>' : '<span style="color:var(--gray-400)">○ Deaktiviert</span>'}</dd>
          <dt>Berechtigungen</dt><dd><span class="perms-mini"><span class="pcount">${permCount}</span> ${permCount === '∞' ? 'voll' : 'Zugriffe'}</span></dd>
          <dt>Letzte Anmeldung</dt><dd>${_fmtDate(u.last_login)}</dd>
        </dl>
        <div class="user-card-actions">
          ${u.manageable === false
            ? `<span class="manage-lock" title="Gleiche Rangstufe wie Sie — nur der Inhaber kann dieses Konto verwalten">${_ms('lock')}<em>Geschützt</em></span>`
            : `
          <md-text-button onclick="openUserModal(${u.id})" title="Bearbeiten">
            <md-icon slot="icon">edit</md-icon>Bearbeiten
          </md-text-button>
          <md-icon-button class="danger" onclick="deactivateUser(${u.id}, ${_isOwner(u) ? 'true' : 'false'})" title="${u.aktiv == 1 ? 'Deaktivieren' : 'Aktivieren'}" ${_isOwner(u) ? 'disabled' : ''}>
            <md-icon>${u.aktiv == 1 ? 'block' : 'check'}</md-icon>
          </md-icon-button>
          <md-icon-button class="danger" onclick="deleteUser(${u.id}, '${_escape(u.username).replace(/'/g,"&#39;")}', ${_isOwner(u) ? 'true' : 'false'})" title="Löschen" ${_isOwner(u) ? 'disabled' : ''}>
            <md-icon>delete</md-icon>
          </md-icon-button>`}
        </div>
      </div>
    `;
}

// ---------- Modal: New / Edit user ----------
async function openUserModal(id = null) {
    let user = {};
    if (id) {
        user = await apiGet(USERS.api + '/' + id);
        if (user.error) { showToast(user.error, 'error'); return; }
    }

    // Fetch permission registry (cached)
    if (!USERS.state.registry) {
        USERS.state.registry = await apiGet(API_BASE + '/users/permissions');
    }
    const registry = USERS.state.registry || { groups: [], presets: [] };

    const isOwner   = _isOwner(user);
    const currentRole = user.role || 'viewer';
    // Owner editing themselves: only name + password allowed
    const lockMost = isOwner;

    const presetCards = (registry.presets || [])
        .filter(p => p.key !== 'owner')        // owner is implicit; cannot be assigned manually
        .map(p => {
            const info = _ROLE_INFO[p.key] || ['sell', 'Eigene Rolle Ihres Unternehmens'];
            const n = p.key === 'admin' ? 'Vollzugriff' : (p.key === 'custom' ? 'individuell' : `${(p.permissions || []).length} Rechte`);
            return `<button type="button" class="u-role-card${currentRole === p.key ? ' is-on' : ''}" data-preset="${_escape(p.key)}" aria-pressed="${currentRole === p.key}">
                ${_ms(info[0])}<span class="u-role-txt"><strong>${_escape(p.label)}</strong><small>${_escape(info[1])}</small></span><em>${n}</em></button>`;
        }).join('');

    const initialPerms = isOwner
        ? registry.presets.find(p => p.key === 'owner')?.permissions || []
        : (user.permissions && user.permissions.length
            ? user.permissions
            : (registry.presets.find(p => p.key === currentRole)?.permissions || []));
    const _initialSet = new Set(initialPerms);
    const fullAccess = lockMost || currentRole === 'admin';
    const permGridHtml = _permMatrixHtml(registry.groups, fullAccess ? new Set((registry.groups || []).flatMap(g => g.items.map(i => i.key))) : _initialSet, 'perm-cb', fullAccess);

    // Per-user Bereich restriction — only meaningful with more than one
    // Bereich in the catalog (security-only companies have exactly one).
    const _allowedBereicheSet = new Set((user.allowed_bereiche || []).map(s => String(s).toLowerCase()));
    const _tenantBereiche = registry.bereiche || [];
    const bereichSectionHtml = _tenantBereiche.length > 1 ? `
      <section class="u-sec">
        <div class="u-sec-head">${_ms('layers')}<div><strong>Bereichs-Einschränkung</strong><small>Keine Auswahl = alle Bereiche sichtbar</small></div></div>
        <div class="bereich-chips">${_tenantBereiche.map(b => `
              <label class="bereich-chip">
                <md-checkbox class="bereich-cb" value="${_escape(b)}" ${_allowedBereicheSet.has(String(b).toLowerCase()) ? 'checked' : ''}></md-checkbox>
                ${typeof bereichDotHtml === 'function' ? bereichDotHtml(b, false) : ''}<span>${_escape(b)}</span>
              </label>`).join('')}</div>
      </section>` : '';

    // Qualitätsmanagement: visible and grantable by the Inhaber only — never
    // part of a role preset (not even Administrator / Vollzugriff).
    const _qmSet = new Set(user.qm_rollen || []);
    const qmSectionHtml = window.CURRENT_USER && window.CURRENT_USER.is_owner && !lockMost ? `
      <section class="u-sec">
        <div class="u-sec-head">${_ms('verified')}<div><strong>Qualitätsmanagement &amp; Wachbuch</strong><small>Nur der Inhaber sieht und vergibt diese Rechte — sie gehören zu keiner Rolle</small></div></div>
        ${[['qm.qmb', 'QMB', 'Qualitätsmanagement komplett: Freigaben, Abschluss, Einstellungen'],
           ['qm.buero', 'Büro', 'Alle Objekte, Reklamationen erfassen & beantworten'],
           ['qm.objektleitung', 'Objektleiter', 'Nur zuständige Objekte: Wachbuch, Dienstanweisung, Reklamationen']].map(([k, l, d]) => `
          <label class="u-check"><md-checkbox class="qm-rolle-cb" value="${k}" ${_qmSet.has(k) ? 'checked' : ''}></md-checkbox>
            <span><strong>${l}</strong> — ${d}</span></label>`).join('')}
      </section>` : '';

    const dispName = user.full_name || user.username || 'Neuer Benutzer';
    const body = `
      <div class="u-head">
        <div class="u-head-av">${_escape(_initials(user.full_name || user.username || '+'))}</div>
        <div class="u-head-txt">
          <strong>${_escape(dispName)}</strong>
          <span>${_escape(user.email || (id ? '' : 'Zugang für einen Mitarbeiter Ihres Büros anlegen'))}</span>
        </div>
        ${id ? `<span class="role-chip r-${_escape(currentRole)}">${_escape(_roleLabel(currentRole))}</span>` : ''}
      </div>
      ${isOwner ? `<div class="u-banner is-owner">${_ms('workspace_premium')}<span>Dies ist der <strong>Firmen-Inhaber</strong>. Rolle, Status und Berechtigungen sind dauerhaft auf Vollzugriff gesetzt.</span></div>` : ''}
      <md-tabs class="u-modal-tabs" id="u_modal_tabs">
        <md-primary-tab data-panel="p_general"><md-icon slot="icon">badge</md-icon>Profil</md-primary-tab>
        <md-primary-tab data-panel="p_perms"><md-icon slot="icon">${lockMost ? 'lock' : 'shield'}</md-icon>Rolle &amp; Rechte</md-primary-tab>
      </md-tabs>

      <div class="u-modal-tab-panel active" id="p_general">
        <!--
          Hidden decoys defeat Chrome/Edge password-manager autofill, which
          would otherwise silently inject the *admin's own* login credentials
          into the password field below the moment this modal opens.
        -->
        <input type="text"     name="prevent_autofill" autocomplete="off" style="display:none" tabindex="-1" aria-hidden="true">
        <input type="password" name="password_fake"    autocomplete="off" style="display:none" tabindex="-1" aria-hidden="true">
        <section class="u-sec">
          <div class="u-sec-head">${_ms('person')}<div><strong>Konto</strong><small>Anmeldename und Kontaktdaten</small></div></div>
          <div class="u-grid">
            <md-outlined-text-field id="f_user_fullname" label="Vollständiger Name *" value="${_escape(user.full_name || '')}" autocomplete="off"></md-outlined-text-field>
            <md-outlined-text-field id="f_user_username" label="Benutzername *" value="${_escape(user.username || '')}" ${id ? 'readonly' : ''} autocomplete="off">
              ${id ? '<md-icon slot="trailing-icon" title="Der Benutzername ist fest">lock</md-icon>' : ''}
            </md-outlined-text-field>
            <md-outlined-text-field class="u-span2" type="email" id="f_user_email" label="E-Mail-Adresse${isOwner ? '' : ' *'}" value="${_escape(user.email || '')}" placeholder="user@firma.de" autocomplete="off" ${isOwner ? 'readonly' : ''}>
              <md-icon slot="leading-icon">mail</md-icon>
              ${isOwner ? '<md-icon slot="trailing-icon" title="Die E-Mail des Inhabers ist fest">lock</md-icon>' : ''}
            </md-outlined-text-field>
          </div>
        </section>
        <section class="u-sec">
          <div class="u-sec-head">${_ms('key')}<div><strong>Passwort</strong><small>${id ? 'Leer lassen = Passwort bleibt unverändert' : 'Leer lassen = sicheres Passwort wird erzeugt'}</small></div></div>
          <div class="u-grid">
            <md-outlined-text-field class="u-span2" type="password" id="f_user_password" label="${id ? 'Neues Passwort' : 'Passwort'}" supporting-text="mindestens 6 Zeichen" autocomplete="new-password">
              <md-icon-button toggle id="f_user_password_toggle" slot="trailing-icon" title="Passwort anzeigen / verbergen">
                <md-icon>visibility</md-icon>
                <md-icon slot="selected">visibility_off</md-icon>
              </md-icon-button>
            </md-outlined-text-field>
          </div>
          ${!id ? `
            <label class="u-check">
              <md-checkbox id="f_user_send_welcome" checked></md-checkbox>
              <span>Willkommens-E-Mail mit Zugangsdaten an den Benutzer senden</span>
            </label>` : ''}
        </section>
        <section class="u-sec u-sec-row">
          <div class="u-sec-head">${_ms('toggle_on')}<div><strong>Konto aktiv</strong><small>Deaktivierte Benutzer können sich nicht mehr anmelden</small></div></div>
          <md-switch id="f_user_aktiv" ${user.aktiv != 0 ? 'selected' : ''} ${lockMost ? 'disabled' : ''} aria-label="Konto aktiv"></md-switch>
        </section>
      </div>

      <div class="u-modal-tab-panel" id="p_perms">
        ${!lockMost ? `
          <section class="u-sec">
            <div class="u-sec-head">${_ms('workspaces')}<div><strong>Rolle</strong><small>Vorlage wählen — die Rechte darunter lassen sich danach feinjustieren</small></div></div>
            <div class="u-roles">${presetCards}</div>
            <input type="hidden" id="f_user_role" value="${_escape(currentRole)}">
          </section>
        ` : `<input type="hidden" id="f_user_role" value="owner">`}
        <div class="u-banner" id="u_perm_banner">${_ms('shield')}<span id="u_perm_banner_txt"></span></div>
        <div id="u_perm_matrix">${permGridHtml}</div>
        ${qmSectionHtml}
        ${!lockMost ? bereichSectionHtml : ''}
      </div>
    `;
    window._uInitialPerms = [..._initialSet];

    const footer = `
        <md-text-button onclick="closeUsersDialog()">Abbrechen</md-text-button>
        <md-filled-button onclick="saveUser(${id || 'null'})">${id ? 'Aktualisieren' : 'Erstellen'}</md-filled-button>
    `;
    openUsersDialog(id ? 'Benutzer bearbeiten' : 'Neuer Benutzer', body, footer, true);
    bindUserModal(lockMost);
}

function bindUserModal(lockMost) {
    // Tabs — md-tabs fires a single 'change' with the new activeTab
    const tabs = document.getElementById('u_modal_tabs');
    if (tabs) {
        tabs.addEventListener('change', () => {
            const panel = tabs.activeTab && tabs.activeTab.dataset.panel;
            if (!panel) return;
            document.querySelectorAll('.u-modal-tab-panel')
                .forEach(x => x.classList.toggle('active', x.id === panel));
        });
    }

    // Show / hide password — md-icon-button[toggle] flips .selected AFTER
    // the click event, so the type swap runs on the next frame.
    const pwdInput  = document.getElementById('f_user_password');
    const pwdToggle = document.getElementById('f_user_password_toggle');
    if (pwdInput && pwdToggle) {
        pwdToggle.addEventListener('click', () => {
            requestAnimationFrame(() => {
                pwdInput.type = pwdToggle.selected ? 'text' : 'password';
            });
        });
    }

    if (lockMost) return;

    const roleInput = document.getElementById('f_user_role');
    const matrix = document.getElementById('u_perm_matrix');
    const registry = USERS.state.registry || { groups: [], presets: [] };
    const allKeys = (registry.groups || []).flatMap(g => g.items.map(i => i.key));
    const setCards = key => document.querySelectorAll('.u-role-card').forEach(c => {
        const on = c.dataset.preset === key; c.classList.toggle('is-on', on); c.setAttribute('aria-pressed', on);
    });
    // Re-render the matrix (admin = everything ticked + locked).
    const paint = (keys, locked) => {
        matrix.innerHTML = _permMatrixHtml(registry.groups, new Set(locked ? allKeys : keys), 'perm-cb', locked);
        bindMatrix();
        refreshPermCounter();
    };
    const bindMatrix = () => {
        matrix.querySelectorAll('.perm-cb').forEach(cb => cb.addEventListener('change', () => {
            _permImplies(cb);
            setCards('custom'); roleInput.value = 'custom';
            refreshPermCounter();
        }));
        matrix.querySelectorAll('.pm-all').forEach(btn => btn.addEventListener('click', () => {
            const cbs = matrix.querySelectorAll(`.perm-cb[data-group="${CSS.escape(btn.dataset.toggle)}"]`);
            const allChecked = [...cbs].every(c => c.checked);
            cbs.forEach(c => { c.checked = !allChecked; });
            setCards('custom'); roleInput.value = 'custom';
            refreshPermCounter();
        }));
    };
    document.querySelectorAll('.u-role-card').forEach(card => card.addEventListener('click', () => {
        const key = card.dataset.preset;
        const preset = (registry.presets || []).find(p => p.key === key);
        if (!preset) return;
        const current = _checkedBoxes('.perm-cb').map(c => c.value);
        setCards(key); roleInput.value = key;
        // "Benutzerdefiniert" keeps the current ticks as starting point.
        paint(key === 'custom' ? (current.length ? current : window._uInitialPerms) : preset.permissions, key === 'admin');
    }));
    bindMatrix();
    refreshPermCounter();
}

// Bearbeiten without Ansehen makes no sense: ticking edit ticks view,
// unticking view unticks edit (same row).
function _permImplies(cb) {
    const k = cb.value;
    const row = cb.closest('tr');
    if (!row) return;
    if (k.endsWith('.edit') && cb.checked) {
        const v = row.querySelector(`.perm-cb[value="${CSS.escape(k.slice(0, -5) + '.view')}"], .role-perm-cb[value="${CSS.escape(k.slice(0, -5) + '.view')}"]`);
        if (v) v.checked = true;
    }
    if (k.endsWith('.view') && !cb.checked) {
        row.querySelectorAll('.perm-cb, .role-perm-cb').forEach(x => { if (x !== cb) x.checked = false; });
    }
}

function refreshPermCounter() {
    const t = document.getElementById('u_perm_banner_txt');
    if (!t) return;
    const role = document.getElementById('f_user_role')?.value;
    const n = _checkedBoxes('.perm-cb').length;
    const banner = document.getElementById('u_perm_banner');
    const full = role === 'owner' || role === 'admin';
    banner?.classList.toggle('is-full', full);
    t.innerHTML = full
        ? '<strong>Vollzugriff</strong> — alle Bereiche inkl. Benutzerverwaltung und Vertrieb-Einstellungen.'
        : `<strong>${n}</strong> ${n === 1 ? 'Berechtigung' : 'Berechtigungen'} ausgewählt`;
}

// ---------- Save ----------
async function saveUser(id) {
    const role = document.getElementById('f_user_role')?.value || 'viewer';
    const data = {
        full_name: document.getElementById('f_user_fullname').value.trim(),
        role,
        aktiv: document.getElementById('f_user_aktiv')?.selected ? '1' : '0',
    };

    // Collect explicit permission picks for non-owner/admin custom mode
    if (role !== 'owner' && role !== 'admin') {
        data.permissions = _checkedBoxes('.perm-cb').map(cb => cb.value);
    }

    // Bereich restriction — send ONLY when at least one checkbox rendered.
    // When the picker shows no chips (empty catalogue) the admin had no
    // way to express a restriction choice at all, and sending [] here
    // would silently WIPE the user's existing whitelist — any unrelated
    // edit (role, password, aktiv) would reset him to "all Bereiche".
    if (document.querySelector('.bereich-cb')) {
        data.allowed_bereiche = _checkedBoxes('.bereich-cb').map(cb => cb.value);
    }
    // QM grant — the block only exists for the Inhaber (server re-checks).
    if (document.querySelector('.qm-rolle-cb')) {
        data.qm_rollen = _checkedBoxes('.qm-rolle-cb').map(cb => cb.value);
    }

    const password = document.getElementById('f_user_password').value;
    if (!data.full_name) { showToast('Name ist erforderlich', 'error'); return; }

    if (id) {
        if (password) {
            if (password.length < 6) {
                showToast('Passwort muss mindestens 6 Zeichen haben', 'error');
                return;
            }
            data.password = password;
        }
        // Email handling:
        //  - Owner: the e-mail is immutable. Do NOT send the field at all
        //    so we don't trigger the backend's "email change rejected"
        //    branch when the user merely re-opens & re-saves the modal.
        //  - Anyone else: editable. Validate format and (when self-editing)
        //    require the actor's current password as a 2nd factor; the
        //    backend enforces the same rule server-side.
        const emailInput = document.getElementById('f_user_email');
        const ownerLocked = emailInput.hasAttribute('readonly');
        if (!ownerLocked) {
            const email = emailInput.value.trim();
            if (!email) { showToast('E-Mail-Adresse ist erforderlich', 'error'); return; }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showToast('Bitte eine gültige E-Mail eingeben', 'error');
                return;
            }
            data.email = email;

            const isSelf = window.CURRENT_USER
                        && Number(window.CURRENT_USER.id) === Number(id);
            const oldEmail = (window.CURRENT_USER && window.CURRENT_USER.email) || '';
            if (isSelf && email !== oldEmail) {
                const pw = await mdPrompt(
                    'Sie ändern Ihre eigene E-Mail-Adresse.\n' +
                    'Bitte geben Sie zur Sicherheit Ihr aktuelles Passwort ein:',
                    { title: 'E-Mail ändern', label: 'Aktuelles Passwort' }
                );
                if (pw === null) return;
                if (!pw) { showToast('Passwort erforderlich', 'error'); return; }
                data.current_password = pw;
            }
        }
    } else {
        data.username = document.getElementById('f_user_username').value.trim();
        data.email    = document.getElementById('f_user_email').value.trim();
        const sendWelcome = document.getElementById('f_user_send_welcome')?.checked ?? true;
        if (!data.username) { showToast('Benutzername erforderlich', 'error'); return; }
        if (!data.email) { showToast('E-Mail-Adresse ist erforderlich', 'error'); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) { showToast('Bitte eine gültige E-Mail eingeben', 'error'); return; }
        // Empty password = auto-generate on the server.
        if (password) {
            if (password.length < 6) { showToast('Passwort muss mindestens 6 Zeichen haben', 'error'); return; }
            data.password = password;
            data.auto_password = false;
        } else {
            data.auto_password = true;
        }
        data.send_welcome_email = sendWelcome;
    }

    const result = id ? await apiPut(USERS.api + '/' + id, data) : await apiPost(USERS.api, data);
    if (result.success) {
        // If the server auto-generated a password, show it once to the admin
        // so they can copy it (in case the email delivery is delayed).
        if (!id && result.temp_password) {
            _showTempPasswordDialog(result.temp_password, data.email, result.welcome_email_queued);
        } else {
            closeUsersDialog();
        }
        showToast(id ? 'Benutzer aktualisiert' : 'Benutzer erstellt');
        renderUsers();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

/**
 * One-time disclosure of an auto-generated password to the creating admin.
 * The temp password is intentionally also surfaced here (not only by email)
 * so the admin has a fallback channel if SMTP is flaky.
 */
function _showTempPasswordDialog(tempPassword, email, emailQueued) {
    const body = `
        <div style="padding:8px 0">
            <p style="margin:0 0 12px;color:var(--gray-600)">
                Ein sicheres temporäres Passwort wurde für <strong>${_escape(email)}</strong> erzeugt.
                ${emailQueued
                    ? `<span style="color:var(--success)">${_ms('check_circle', 'font-size:15px;vertical-align:-3px')} Die Willkommens-E-Mail wurde in die Warteschlange gestellt.</span>`
                    : `<span style="color:var(--danger)">${_ms('warning', 'font-size:15px;vertical-align:-3px')} Die E-Mail-Zustellung ist <strong>fehlgeschlagen</strong> — bitte das Passwort manuell weitergeben.</span>`
                }
            </p>
            <div style="background:var(--gray-100);border: 1px solid var(--md-sys-color-primary-container);border-radius:8px;padding:14px 16px;display:flex;align-items:center;gap:12px">
                <code id="tempPwValue" style="flex:1;font-size:16px;font-weight:700;letter-spacing:0.5px;font-family:ui-monospace,monospace;word-break:break-all">${_escape(tempPassword)}</code>
                <md-outlined-button onclick="navigator.clipboard.writeText(document.getElementById('tempPwValue').textContent).then(()=>showToast('Passwort kopiert','success'))" style="white-space:nowrap">
                    <md-icon slot="icon">content_copy</md-icon>Kopieren
                </md-outlined-button>
            </div>
            <small style="display:block;margin-top:12px;color:var(--gray-500)">
                Dieses Passwort wird Ihnen nur jetzt angezeigt. Bitten Sie den Benutzer, es nach der ersten Anmeldung über
                <em>Mein Konto → Passwort ändern</em> zu ersetzen.
            </small>
        </div>
    `;
    const footer = `<md-filled-button onclick="closeUsersDialog()">Verstanden</md-filled-button>`;
    openUsersDialog('Temporäres Passwort', body, footer);
}

// ---------- Activate / deactivate ----------
async function deactivateUser(id, isOwner = false) {
    if (isOwner) {
        showToast('Der Inhaber kann nicht deaktiviert werden.', 'error');
        return;
    }
    const u = USERS.state.all.find(x => Number(x.id) === Number(id));
    const willActivate = u && u.aktiv != 1;
    const msg = willActivate ? 'Benutzer wieder aktivieren?' : 'Benutzer deaktivieren?';
    if (!(await mdConfirm(msg, { confirmLabel: willActivate ? 'Aktivieren' : 'Deaktivieren' }))) return;

    // Re-activation: send a minimal payload (only the changed field +
    // role hint) so the backend can route it through the regular update
    // path without misinterpreting nullable fields like `permissions`.
    // Deactivation: still uses DELETE without `action=delete` so the
    // backend takes the soft-delete branch.
    const result = willActivate
        ? await apiPut(USERS.api + '/' + id, { aktiv: 1, role: u.role || 'viewer' })
        : await apiDelete(USERS.api + '/' + id, {});
    if (result.success) { showToast(willActivate ? 'Benutzer aktiviert' : 'Benutzer deaktiviert'); renderUsers(); }
    else { showToast(result.error || 'Fehler', 'error'); }
}

async function deleteUser(id, username, isOwner = false) {
    if (isOwner) {
        showToast('Der Inhaber kann nicht gelöscht werden.', 'error');
        return;
    }
    if (!(await mdConfirm(
        'Benutzer "' + _escape(username) + '" endgültig löschen?\n\nDer Benutzername und das Passwort werden unwiderruflich gelöscht. Der Benutzer kann sich danach nicht mehr anmelden.',
        { title: 'Benutzer löschen', confirmLabel: 'Löschen' }
    ))) return;
    const result = await apiDelete(USERS.api + '/' + id, { action: 'delete' });
    if (result.success) { showToast('Benutzer gelöscht'); renderUsers(); }
    else { showToast(result.error || 'Fehler', 'error'); }
}

/* ============================================
   Custom Roles management (tenant-defined roles)
   Backend: /api/roles  (gated by users.manage)
   ============================================ */

// List view — shows all custom roles with edit/delete + a "Neue Rolle" button.
async function openRolesModal() {
    const roles = await apiGet(ROLES_API);
    const list = Array.isArray(roles) ? roles : [];

    const rowsHtml = list.length
        ? list.map(r => `
            <div class="role-row" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border:1px solid var(--gray-200);border-radius:10px;margin-bottom:8px">
              <div>
                <div style="font-weight:600;color:var(--gray-800)">${_escape(r.label)}</div>
                <div style="font-size:0.8rem;color:var(--gray-500)">${r.permissions.length} Berechtigungen · ${r.user_count} Benutzer</div>
              </div>
              <div style="display:flex;gap:6px">
                <md-icon-button title="Bearbeiten" onclick="openRoleEditor(${r.id})"><md-icon>edit</md-icon></md-icon-button>
                <md-icon-button class="danger" title="Löschen" onclick="deleteRole(${r.id}, '${_escape(r.label).replace(/'/g,'&#39;')}', ${r.user_count})"><md-icon>delete</md-icon></md-icon-button>
              </div>
            </div>`).join('')
        : `<div class="users-empty" style="padding:24px">${_ms('sell')}<p>Noch keine eigenen Rollen.<br>Erstellen Sie eine, um Berechtigungen wiederverwendbar zu bündeln.</p></div>`;

    const body = `
      <p style="margin:0 0 14px;color:var(--gray-600);font-size:0.9rem">
        Eigene Rollen sind wiederverwendbare Berechtigungs-Vorlagen für Ihr Unternehmen.
        Änderungen an einer Rolle gelten <strong>sofort</strong> für alle Benutzer mit dieser Rolle.
      </p>
      <div id="rolesList">${rowsHtml}</div>
    `;
    const footer = `
      <md-text-button onclick="closeRolesAndRefresh()">Schließen</md-text-button>
      <md-filled-button onclick="openRoleEditor(null)"><md-icon slot="icon">add</md-icon>Neue Rolle</md-filled-button>
    `;
    openUsersDialog('Rollen verwalten', body, footer, true);
}

// Closing the roles modal refreshes the user grid so reassignments + new
// role labels are reflected immediately.
function closeRolesAndRefresh() {
    closeUsersDialog();
    USERS.state.registry = null; // force chip/label rebuild on next render
    renderUsers();
}

// Create / edit a single custom role (label + permission picks).
async function openRoleEditor(roleId) {
    if (!USERS.state.registry) {
        USERS.state.registry = await apiGet(API_BASE + '/users/permissions');
    }
    const registry = USERS.state.registry || { groups: [] };

    let role = { label: '', permissions: [] };
    if (roleId) {
        const roles = await apiGet(ROLES_API);
        role = (Array.isArray(roles) ? roles : []).find(r => Number(r.id) === Number(roleId)) || role;
    }
    const sel = new Set(role.permissions || []);

    const body = `
      <section class="u-sec">
        <md-outlined-text-field id="f_role_label" label="Rollenname *" value="${_escape(role.label || '')}" placeholder="z. B. Schichtleiter" autocomplete="off" style="width:100%"></md-outlined-text-field>
      </section>
      <div class="u-banner">${_ms('shield')}<span><strong id="rolePermCounter">${sel.size}</strong> Berechtigungen ausgewählt</span></div>
      <div id="u_role_matrix">${_permMatrixHtml(registry.groups, sel, 'role-perm-cb', false)}</div>
    `;
    const footer = `
      <md-text-button onclick="openRolesModal()">Zurück</md-text-button>
      <md-filled-button onclick="saveRole(${roleId || 'null'})">${roleId ? 'Speichern' : 'Erstellen'}</md-filled-button>
    `;
    openUsersDialog(roleId ? 'Rolle bearbeiten' : 'Neue Rolle', body, footer, true);
    bindRoleEditor();
}

function bindRoleEditor() {
    document.querySelectorAll('.role-perm-cb').forEach(cb => {
        cb.addEventListener('change', () => { _permImplies(cb); _refreshRolePermCounter(); });
    });
    document.querySelectorAll('#u_role_matrix .pm-all').forEach(g => {
        g.addEventListener('click', () => {
            const group = g.dataset.toggle;
            const cbs = document.querySelectorAll(`.role-perm-cb[data-group="${CSS.escape(group)}"]`);
            const allChecked = [...cbs].every(c => c.checked);
            cbs.forEach(c => { c.checked = !allChecked; });
            _refreshRolePermCounter();
        });
    });
}

function _refreshRolePermCounter() {
    const c = document.getElementById('rolePermCounter');
    if (c) c.textContent = _checkedBoxes('.role-perm-cb').length;
}

async function saveRole(id) {
    const label = (document.getElementById('f_role_label')?.value || '').trim();
    if (!label) { showToast('Rollenname ist erforderlich', 'error'); return; }
    const permissions = _checkedBoxes('.role-perm-cb').map(cb => cb.value);
    if (!permissions.length) { showToast('Bitte mindestens eine Berechtigung wählen', 'error'); return; }

    const payload = { label, permissions };
    const result = id ? await apiPut(ROLES_API + '/' + id, payload) : await apiPost(ROLES_API, payload);
    if (result.success) {
        showToast(id ? 'Rolle gespeichert' : 'Rolle erstellt');
        USERS.state.registry = null; // refresh chips/labels next render
        openRolesModal();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function deleteRole(id, label, userCount) {
    const warn = userCount > 0
        ? `\n\n${userCount} Benutzer hat/haben diese Rolle. Sie behalten ihre aktuellen Berechtigungen (als individuelle Auswahl), verlieren aber die Rollen-Zuordnung.`
        : '';
    if (!(await mdConfirm(`Rolle "${_escape(label)}" löschen?${warn}`, { title: 'Rolle löschen', confirmLabel: 'Löschen' }))) return;
    const result = await apiDelete(ROLES_API + '/' + id, {});
    if (result.success) {
        showToast('Rolle gelöscht');
        USERS.state.registry = null;
        openRolesModal();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}
