/* ============================================
   HMS — Qualitätsmanagement & Wachbuch (office)
   Classic scripts sharing ONE global scope, loaded in the fixed order of
   QM_MODULE_SCRIPTS in app.js (01 core → 99 live). Pages:
     qm_wachbuch  Wachbuch (Leitstand + Wachbuchblätter)       02-qm-wachbuch.js
     qm           Übersicht · Objekte & Dienstanweisungen ·
                  Reklamationen · Maßnahmen · Einstellungen    03 … 07
   Shared design system only (md-tabs, .data-bento, .data-toolbar,
   .data-table-card, md buttons); legacy modal dialogs use .crm-input.
   ============================================ */

const QM_API = API_BASE + '/qm';
const qm = {
    opt: null,                 // GET /qm/optionen (rights, objects, lists)
    view: 'uebersicht',        // tab of the qm page
    detail: null,              // {typ, id} — open record inside a tab
    lastWrite: 0,
    seq: 0,
    rf: { tab: 'offen', obj_id: '', schwere: '', q: '' },              // Reklamationen
    mf: { tab: 'offen', quelle: '', obj_id: '', q: '' },               // Maßnahmen
    wb: { tab: 'leitstand', obj: '', monat: '', tag: '' },             // Wachbuch
    st: { tab: 'allgemein' },                                          // Einstellungen
    cache: {},
};

// ---------- small helpers ----------
function qmIcon(n, st = '') { return `<span class="material-symbols-outlined"${st ? ` style="${st}"` : ''}>${n}</span>`; }
const qmE = v => escapeHtml(v == null ? '' : String(v));
function qmDate(d) { return d ? formatDate(String(d).slice(0, 10)) : '–'; }
function qmDT(ts) { return ts ? formatDateTimeLocal(ts) : '–'; }
function qmTime(ts) { if (!ts) return ''; const d = new Date(ts); return isNaN(d) ? '' : d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }); }
function qmHeute() { return qm.opt?.heute || new Date().toISOString().slice(0, 10); }
function qmAddDays(n, from) { const d = new Date((from || qmHeute()) + 'T12:00:00'); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); }
function qmCan(aktion) { return !!qm.opt?.ich?.rechte?.[aktion]; }
function qmUser(id) { return (qm.opt?.benutzer || []).find(u => +u.id === +id)?.name || '–'; }
function qmObj(id) { return (qm.opt?.objekte || []).find(o => o.obj_id === id); }
function qmLabel() { return qm.opt?.label || (typeof objektbuchLabel === 'function' ? objektbuchLabel() : 'Wachbuch'); }
function qmV(id) { const el = document.getElementById(id); if (!el) return undefined; return el.type === 'checkbox' ? el.checked : String(el.value ?? '').trim(); }
function qmBadge(text, tone = '') { return `<span class="qm-badge${tone ? ' qm-' + tone : ''}">${qmE(text)}</span>`; }
function qmListe(key) { return qm.opt?.listen?.[key] || {}; }

function qmPaint(el, html, opts) {
    if (!el) return;
    if (typeof hmsMorph === 'function') hmsMorph(el, html, opts);
    else el.innerHTML = html;
}

// ---------- HTTP (errors are NOT masked as empty lists) ----------
async function qmReq(method, url, body) {
    const isForm = body instanceof FormData;
    if (method !== 'GET') qm.lastWrite = Date.now();
    try {
        const res = await fetch(url, {
            method,
            headers: isForm || method === 'GET' ? getAuthHeadersNoContent() : getAuthHeaders(),
            body: method === 'GET' ? undefined : (isForm ? body : JSON.stringify(body || {})),
        });
        if (res.status === 401) { clearAuthToken(); window.location.href = 'login.php'; return { ok: false, status: 401, data: {} }; }
        const data = await res.json().catch(() => ({}));
        return { ok: res.ok, status: res.status, data };
    } catch (e) {
        return { ok: false, status: 0, data: { error: 'Netzwerkfehler: ' + e.message } };
    }
}
const qmGet = url => qmReq('GET', url);
function qmQs(o) { const p = new URLSearchParams(); Object.entries(o).forEach(([k, v]) => { if (v !== '' && v != null) p.set(k, v); }); const s = p.toString(); return s ? '?' + s : ''; }

/** Toast + mark fields (data-f="key"). Returns true if it was an error. */
function qmFehler(r, fallback = 'Speichern fehlgeschlagen') {
    if (r.ok) return false;
    document.querySelectorAll('.crm-invalid').forEach(el => el.classList.remove('crm-invalid'));
    document.querySelectorAll('.crm-err').forEach(el => el.remove());
    const errs = r.data?.errors || {};
    Object.entries(errs).forEach(([k, m]) => {
        const el = document.querySelector(`[data-f="${CSS.escape(k)}"]`);
        if (!el) return;
        el.classList.add('crm-invalid');
        el.insertAdjacentHTML('afterend', `<div class="crm-err">${qmE([].concat(m).join(' '))}</div>`);
    });
    showToast(r.data?.error || Object.values(errs).flat()[0] || fallback, 'error');
    return true;
}

async function qmLoadOpt(force = false) {
    if (qm.opt && !force) return qm.opt;
    const r = await qmGet(QM_API + '/optionen');
    if (!r.ok) throw new Error(r.data?.error || 'Qualitätsmanagement konnte nicht geladen werden');
    qm.opt = r.data;
    return qm.opt;
}

/** Bytes behind auth (photos, PDFs) → object URL. */
async function qmBlobUrl(url) {
    const res = await fetch(url, { headers: getAuthHeadersNoContent() });
    if (!res.ok) throw new Error('Datei konnte nicht geladen werden');
    return URL.createObjectURL(await res.blob());
}
async function qmOpenPdf(url) {
    const win = window.open('', '_blank');
    try {
        const u = await qmBlobUrl(url);
        if (win) win.location.href = u; else window.location.href = u;
    } catch (e) { if (win) win.close(); showToast(e.message, 'error'); }
}
/** Fill every <img data-qm-src> below root (auth fetch, once). */
function qmLoadImages(root) {
    (root || document).querySelectorAll('img[data-qm-src]:not([src])').forEach(async img => {
        try { img.src = await qmBlobUrl(img.dataset.qmSrc); } catch (e) { img.alt = 'Foto nicht verfügbar'; }
    });
}
function qmFotoGross(url, text = '') {
    openModal('Foto', `<div class="qm-foto-gross"><img data-qm-src="${qmE(url)}" alt="">${text ? `<p>${qmE(text)}</p>` : ''}</div>`,
        `<button class="btn btn-outline" onclick="closeModal()">Schließen</button>`, 'modal-lg');
    qmLoadImages(document.getElementById('modalOverlay'));
}

function qmCopy(text, msg = 'Kopiert') {
    navigator.clipboard?.writeText(text).then(() => showToast(msg), () => showToast(text));
}

/** Secrets (PIN, start password) are shown exactly once. */
function qmGeheim(title, rows, hint) {
    openModal(title, `
        <div class="qm-geheim">
            ${rows.map(([k, v]) => `<div class="qm-geheim-row"><span>${qmE(k)}</span><strong>${qmE(v)}</strong>
                <md-icon-button onclick="qmCopy('${qmE(v)}')" title="Kopieren"><md-icon>content_copy</md-icon></md-icon-button></div>`).join('')}
            <div class="crm-banner crm-banner-amber">${qmIcon('visibility_off')} ${qmE(hint || 'Wird nur jetzt angezeigt — bitte notieren und persönlich weitergeben.')}</div>
        </div>`, `<button class="btn btn-primary" onclick="closeModal()">Verstanden</button>`);
}

// ---------- shared design-system building blocks ----------
function qmTile({ hero = false, cls = '', on = false, icon, label, n, foot = '', meta = '', click = '', title = '' }) {
    if (hero) return `
        <div class="bento-tile bento-tile-hero ${cls} ${on ? 'crm-tile-on' : ''}" ${click ? `onclick="${click}"` : ''} title="${qmE(title)}">
            <div class="bento-tile-head">${qmIcon(icon)} ${qmE(label)}</div>
            <div class="bento-tile-number">${n}</div>
            ${foot ? `<div class="bento-tile-foot">${foot}</div>` : ''}
        </div>`;
    return `
        <div class="bento-tile ${cls} ${on ? 'crm-tile-on' : ''}" ${click ? `onclick="${click}"` : ''} title="${qmE(title)}">
            <div class="bento-tile-icon">${qmIcon(icon)}</div>
            <div class="bento-tile-body">
                <div class="bento-tile-number-sm">${n}</div>
                <div class="bento-tile-label">${qmE(label)}</div>
            </div>
            ${meta ? `<div class="bento-tile-meta">${meta}</div>` : ''}
        </div>`;
}

function qmMdOpts(list, cur, placeholder) {
    const opt = (v, n) => `<md-select-option value="${qmE(v)}" ${String(cur ?? '') === String(v) ? 'selected' : ''}><div slot="headline">${qmE(n)}</div></md-select-option>`;
    const items = Array.isArray(list) ? list.map(o => typeof o === 'string' ? [o, o] : [o.key ?? o.id ?? o.obj_id, o.name ?? o.objektname])
        : Object.entries(list);
    return (placeholder !== undefined ? opt('', placeholder) : '') + items.map(([v, n]) => opt(v, n)).join('');
}
/** Filter select bound to qm[scope][key]; changes call qmOnFilter(scope). */
function qmMdFilter(scope, key, label, list, placeholder) {
    return `<md-outlined-select class="cust-bereich-select" label="${qmE(label)}" data-qm-scope="${scope}" data-qm-key="${key}">${qmMdOpts(list, qm[scope][key], placeholder)}</md-outlined-select>`;
}
function qmMdSearch(scope, label, placeholder) {
    return `<md-outlined-text-field class="cust-search" label="${qmE(label)}" placeholder="${qmE(placeholder)}" value="${qmE(qm[scope].q)}" data-qm-search="${scope}">
        <md-icon slot="leading-icon">search</md-icon></md-outlined-text-field>`;
}
/** MD3 components re-dispatch input/change from their shadow DOM — wire once per element. */
let _qmSearchT = null;
function qmWire(root) {
    const on = (sel, ev, fn) => root.querySelectorAll(sel).forEach(el => {
        if (el._qmWired) return;
        el._qmWired = true;
        el.addEventListener(ev, () => fn(el));
    });
    on('md-outlined-select[data-qm-scope]', 'change', el => { qm[el.dataset.qmScope][el.dataset.qmKey] = el.value; qmOnFilter(el.dataset.qmScope); });
    on('md-outlined-text-field[data-qm-search]', 'input', el => {
        clearTimeout(_qmSearchT);
        _qmSearchT = setTimeout(() => { qm[el.dataset.qmSearch].q = el.value; qmOnFilter(el.dataset.qmSearch); }, 300);
    });
    on('md-tabs[data-qm-tabs]', 'change', el => {
        const v = el.activeTab?.dataset?.v;
        if (v && typeof window[el.dataset.qmTabs] === 'function') window[el.dataset.qmTabs](v);
    });
}
function qmOnFilter(scope) {
    if (scope === 'rf') return qmRekLaden();
    if (scope === 'mf') return qmMnLaden();
}

function qmTabs(fn, list, cur, cls = 'crm-tabs-bar') {
    return `<md-tabs data-qm-tabs="${fn}" class="${cls}">${list.map(([k, icon, label]) => `
        <md-primary-tab data-v="${k}" ${cur === k ? 'active' : ''}><span class="material-symbols-outlined" slot="icon">${icon}</span>${qmE(label)}</md-primary-tab>`).join('')}
    </md-tabs>`;
}

function qmEmpty(icon, text, action = '') {
    return `<div class="empty-state">${qmIcon(icon)}<p>${qmE(text)}</p>${action}</div>`;
}
function qmLoading() { return `<div class="crm-loading">${qmIcon('progress_activity')} Laden…</div>`; }
function qmFehlerBox(r, retry) {
    return qmEmpty('error', r.data?.error || 'Daten konnten nicht geladen werden', `<md-filled-button onclick="${retry}">Erneut versuchen</md-filled-button>`);
}

/** Objekt select for dialogs (only objects inside the user's QM scope). */
function qmObjOptions(cur, nurWachbuch = false) {
    return (qm.opt?.objekte || []).filter(o => !nurWachbuch || o.wachbuch_aktiv)
        .map(o => `<option value="${qmE(o.obj_id)}" ${o.obj_id === cur ? 'selected' : ''}>${qmE(o.objektname)}${o.kundenname ? ' — ' + qmE(o.kundenname) : ''}</option>`).join('');
}
function qmUserOptions(cur, placeholder = '— wählen —') {
    return `<option value="">${qmE(placeholder)}</option>` + (qm.opt?.benutzer || [])
        .map(u => `<option value="${u.id}" ${+u.id === +cur ? 'selected' : ''}>${qmE(u.name)}</option>`).join('');
}
function qmSelectOptions(map, cur, placeholder) {
    return (placeholder !== undefined ? `<option value="">${qmE(placeholder)}</option>` : '')
        + Object.entries(map).map(([k, v]) => `<option value="${qmE(k)}" ${k === cur ? 'selected' : ''}>${qmE(v)}</option>`).join('');
}

/** Change log ("Verlauf") of a record — every QM detail page shows it. */
function qmVerlaufHtml(list) {
    if (!list?.length) return '<div class="crm-muted crm-pad">Keine Änderungen</div>';
    const fmt = o => o && typeof o === 'object' ? Object.entries(o).map(([k, v]) => `${k}: ${v ?? '–'}`).join(', ') : '';
    return `<table class="crm-proto"><tbody>${list.map(v => `<tr>
        <td class="crm-muted">${qmE(qmDT(v.zeit))}</td><td>${qmE(v.von || '')}</td>
        <td><strong>${qmE(v.aktion)}</strong>${v.text ? ' — ' + qmE(v.text) : ''}${v.neu ? `<div class="crm-sub">${qmE(fmt(v.alt))}${v.alt ? ' → ' : ''}${qmE(fmt(v.neu))}</div>` : ''}</td>
    </tr>`).join('')}</tbody></table>`;
}

/** Page params from a deep link (#qm?view=reklamationen&id=5) — set by app.js loadPage(). */
function qmParams() {
    const p = window._pageParams; window._pageParams = null;
    return p instanceof URLSearchParams ? p : new URLSearchParams();
}
