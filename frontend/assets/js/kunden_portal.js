/* ============================================
   Kundenportal — core (part of the QM module)
   Einsätze (plan + Ist, worker names) and Fotos live here; the QM sections
   (Wachbuch, Dienstanweisungen, Reklamationen & Maßnahmen, Passwort) are
   separate files in assets/js/kunden_portal/ and register in KP_TABS.
   ============================================ */

const KP_API = window.location.origin + '/api/kunden-portal';
const KP_DAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
const KP_DAYS_FULL = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
const KP_REFRESH_MS = 60000;

let kpTab = 'einsaetze';
let kpObLabel = 'Wachbuch'; // tenant name of the shift log (/me objektbuch_label)
let kpWbStart = '06:00';
let kpFolder = null; // open Objekt folder (obj_id) in Wachbuch / Fotos
let kpWeekStart = kpMonday(new Date());
let kpFotoUrls = [];
let kpRefreshTimer = null;
let kpLoadSeq = 0;
let kpMe = null;
/** QM tabs: KP_TABS[tab] = { week: false, load(box, seq) } — registered by kunden_portal/*.js. */
const KP_TABS = {};

// ---------- helpers ----------
function kpToken() { return localStorage.getItem('kp_token'); }
function kpEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function kpMonday(d) {
    const x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
    return x;
}
function kpAddDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }
function kpIso(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function kpDe(iso) { const p = String(iso || '').split('-'); return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : iso; }
function kpDayName(iso, full = false) {
    const d = new Date(iso + 'T00:00:00');
    return (full ? KP_DAYS_FULL : KP_DAYS)[d.getDay()];
}
function kpRange() { return { von: kpIso(kpWeekStart), bis: kpIso(kpAddDays(kpWeekStart, 6)) }; }
function kpIsCurrentWeek() { return kpIso(kpWeekStart) === kpIso(kpMonday(new Date())); }

// Inside the Clood-X app the shared app login is the way back in.
// kp_app_shell stays set: in-flight requests that 401 after logout must
// land on the same login page (idempotent, no second redirect).
let kpLeaving = false;
function kpGoLogin() {
    if (kpLeaving) return;
    kpLeaving = true;
    clearTimeout(kpRefreshTimer);
    localStorage.removeItem('kp_token');
    localStorage.removeItem('w_kp_token'); // remembered session of the shared app login (same origin)
    localStorage.removeItem('w_kp_slug');
    window.location.replace(localStorage.getItem('kp_app_shell') === '1' ? '/worker/login.php' : 'kunden_login.php');
}

// Native WebViews fall back to their old page history after the app login
// hands off to this remote portal. Keep Back inside the customer session so
// it cannot expose the unrelated main-system login.php.
function kpInstallAppBackGuard() {
    if (localStorage.getItem('kp_app_shell') !== '1') return;
    const guard = () => history.pushState({ kpPortalBack: true }, '', location.href);
    guard();
    window.addEventListener('popstate', () => {
        if (kpLeaving) return;
        guard();
        if (document.getElementById('kpLightbox')?.classList.contains('open')) { kpCloseLightbox(); return; }
        const dialog = document.getElementById('kpDlg');
        if (dialog) { dialog.querySelector('#kpDlgNo')?.click(); return; }
        if (kpActiveFolder()) { kpCloseFolder(); return; }
        if (kpTab !== 'einsaetze') { kpSwitchTab('einsaetze'); return; }
        // Android: same behavior as the worker feed. On iOS there is no
        // hardware Back; a swipe cannot leave the portal for stale web pages.
        try {
            window.androidBridge?.postMessage(JSON.stringify({
                callbackId: 'kpBack', pluginId: 'App', methodName: 'minimizeApp', options: {}
            }));
        } catch (e) { /* old bridge: stay in the portal */ }
    });
}

async function kpFetch(path, asBlob = false) {
    const res = await fetch(KP_API + path, {
        headers: { 'Accept': asBlob ? 'image/*' : 'application/json', 'Authorization': 'Bearer ' + kpToken() }
    });
    if (res.status === 401) { kpGoLogin(); return null; }
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return asBlob ? res.blob() : res.json();
}

function kpQuery() {
    const { von, bis } = kpRange();
    const obj = document.getElementById('kpObjekt').value;
    return `?von=${von}&bis=${bis}` + (obj ? '&obj_id=' + encodeURIComponent(obj) : '');
}

function kpEmpty(icon, text) {
    return `<div class="kp-empty"><i class="material-symbols-outlined">${icon}</i><p>${kpEsc(text)}</p></div>`;
}

// ---------- shell ----------
// Shows the tab name once known (hidden in the HTML to avoid a "Wachbuch" flash).
function kpShowObTab() {
    const el = document.getElementById('kpObTab');
    if (el) { el.textContent = kpObLabel; el.style.visibility = ''; }
}

async function kpInit() {
    try {
        const me = await kpFetch('/me');
        if (!me) return;
        document.getElementById('kpKunde').textContent = me.kunde?.kundenname || 'Kundenportal';
        document.getElementById('kpKunde').title = me.kunde?.kundenname || '';
        document.getElementById('kpFirma').textContent = me.firma ? 'Dienstleister: ' + me.firma : '';
        document.title = 'Kundenportal — ' + (me.kunde?.kundenname || '');
        kpMe = me;
        if (me.objektbuch_label) kpObLabel = me.objektbuch_label;
        kpWbStart = me.tag_beginn || '06:00';
        if (me.wachbuch_tag) kpWbDatum = me.wachbuch_tag;
        document.querySelectorAll('.kp-tab[data-qm]').forEach(b => { b.hidden = !me['portal_' + b.dataset.tab]; });
        document.querySelector('.kp-tab[data-tab="fotos"]').hidden = me.portal_fotos === false;
        // Wachbuch switched off for the company (QM-Einstellungen) → no tab.
        document.querySelectorAll('.kp-tab[data-tab="wachbuch"]').forEach(b => { b.hidden = me.wachbuch === false; });
        const sel = document.getElementById('kpObjekt');
        (me.objekte || []).forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.obj_id;
            opt.textContent = o.objektname || o.obj_id;
            sel.appendChild(opt);
        });
        if ((me.objekte || []).length <= 1) sel.style.display = 'none';
    } catch (e) {
        document.getElementById('kpContent').innerHTML = kpEmpty('warning', 'Verbindungsfehler. Bitte Seite neu laden.');
        return;
    } finally {
        kpShowObTab();
    }
    // QM live updates (Reverb): the open QM tab refreshes silently (never over a dialog).
    if (kpMe?.qm && window.CxQmLive) CxQmLive.start({ base: KP_API, headers: j => Object.assign({ Accept: 'application/json', Authorization: 'Bearer ' + kpToken() }, j ? { 'Content-Type': 'application/json' } : {}),
        onChange: () => { if (KP_TABS[kpTab] && !document.getElementById('kpDlg') && !document.getElementById('kpLightbox')?.classList.contains('open')) kpLoad(true); } });
    // A start password must be replaced before anything else is shown.
    if (kpMe?.passwort_aendern && typeof kpPasswortDialog === 'function') return kpPasswortDialog(true);
    kpLoad();
}

async function kpLogout() {
    try { await fetch(KP_API + '/logout', { method: 'POST', headers: { 'Authorization': 'Bearer ' + kpToken() } }); } catch (e) {}
    kpGoLogin();
}

function kpSwitchTab(tab) {
    if (document.querySelector(`.kp-tab[data-tab="${tab}"]`)?.hidden) return;
    kpTab = tab;
    kpFolder = null;
    document.querySelectorAll('.kp-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    kpLoad();
}
function kpShiftWeek(n) { kpWeekStart = kpAddDays(kpWeekStart, 7 * n); kpLoad(); }
function kpThisWeek() { kpWeekStart = kpMonday(new Date()); kpLoad(); }

function kpUpdateWeekLabel() {
    const { von, bis } = kpRange();
    // ISO week number (Thursday rule)
    const th = kpAddDays(new Date(von + 'T00:00:00'), 3);
    const wk = Math.ceil((((th - new Date(th.getFullYear(), 0, 1)) / 86400000) + 1) / 7);
    // Compact "05.10.–11.10.2026" (first year only when the week spans New Year).
    const vonTxt = von.slice(0, 4) === bis.slice(0, 4) ? kpDe(von).slice(0, 6) : kpDe(von);
    document.getElementById('kpWeekLabel').innerHTML =
        `<strong>KW ${wk}</strong> · ${vonTxt}–${kpDe(bis)}` + (kpIsCurrentWeek() ? ' <span class="kp-now">aktuell</span>' : '');
    // "Heute" only makes sense away from the current week — keeps the row narrow on phones.
    document.getElementById('kpTodayBtn').hidden = kpIsCurrentWeek();
}

async function kpLoad(silent = false) {
    clearTimeout(kpRefreshTimer);
    const seq = ++kpLoadSeq;
    const box = document.getElementById('kpContent');
    const ext = KP_TABS[kpTab];
    document.querySelector('.kp-week').hidden = !!(ext && !ext.week);
    if (!ext || ext.week) kpUpdateWeekLabel();
    if (!silent) box.innerHTML = kpEmpty('progress_activity', 'Lade...');
    try {
        if (ext) {
            await ext.load(box, seq);
        } else if (kpTab === 'einsaetze') {
            const data = await kpFetch('/einsaetze' + kpQuery());
            if (!data || seq !== kpLoadSeq) return;
            box.innerHTML = kpRenderEinsaetze(data.einsaetze || []);
            // Live status ("im Dienst") refreshes while the current week is open.
            if (kpIsCurrentWeek()) kpRefreshTimer = setTimeout(() => kpLoad(true), KP_REFRESH_MS);
        } else {
            const data = await kpFetch('/fotos' + kpQuery());
            if (!data || seq !== kpLoadSeq) return;
            const list = data.fotos || [];
            const objId = kpActiveFolder();
            if (objId) {
                kpRenderFotos(box, list.filter(f => f.obj_id === objId), seq, kpFolderHead(list, objId));
            } else {
                kpRevokeFotos();
                box.innerHTML = kpRenderFolders(list, 'fotos');
                kpLoadFolderCovers(box, seq);
            }
        }
    } catch (e) {
        if (seq === kpLoadSeq) box.innerHTML = kpEmpty('warning', 'Daten konnten nicht geladen werden.');
    }
}

// ---------- Einsätze ----------
function kpTimeRange(von, bis) { return von || bis ? `${von || '–'} – ${bis || '–'}` : '–'; }

function kpRenderEinsaetze(list) {
    if (!list.length) return kpEmpty('event_busy', 'Keine Einsätze in diesem Zeitraum.');
    // Shifts running right now (a worker is clocked in) come first.
    const isLive = e => (e.mitarbeiter || []).some(w => w.im_dienst);
    const live = list.filter(isLive);
    const liveHtml = live.length ? `
        <div class="kp-day kp-live-section">
            <div class="kp-day-head">
                <span class="kp-day-name"><span class="kp-live-dot"></span> Jetzt im Dienst</span>
                <span class="kp-day-date">${live.length} ${live.length === 1 ? 'Einsatz' : 'Einsätze'}</span>
            </div>
            ${live.map(kpShiftCard).join('')}
        </div>` : '';
    const byDay = {};
    list.filter(e => !isLive(e)).forEach(e => (byDay[e.datum] = byDay[e.datum] || []).push(e));
    const today = kpIso(new Date());
    return liveHtml + Object.keys(byDay).sort().map(day => `
        <div class="kp-day${day === today ? ' kp-day-today' : ''}">
            <div class="kp-day-head">
                <span class="kp-day-name">${kpDayName(day, true)}</span>
                <span class="kp-day-date">${kpDe(day)}</span>
                ${day === today ? '<span class="kp-now">Heute</span>' : ''}
            </div>
            ${byDay[day].map(kpShiftCard).join('')}
        </div>`).join('');
}

function kpShiftCard(e) {
    const workers = (e.mitarbeiter || []).map(w => {
        let ist;
        if (w.im_dienst) ist = `<span class="kp-live"><span class="kp-live-dot"></span>Im Dienst seit ${kpEsc(w.ist_von || '–')}</span>`;
        else if (w.ist_von || w.ist_bis) ist = `<span class="kp-ist">Ist ${kpEsc(kpTimeRange(w.ist_von, w.ist_bis))}</span>`;
        else ist = '<span class="kp-ist kp-muted">Noch nicht gestempelt</span>';
        return `<li class="kp-worker"><span class="kp-avatar">${kpEsc((w.name || '?').trim().charAt(0).toUpperCase())}</span>
                <span class="kp-worker-name">${kpEsc(w.name)}</span>${ist}</li>`;
    }).join('');
    return `
        <article class="kp-card">
            <div class="kp-card-head">
                <div>
                    <div class="kp-card-title"><i class="material-symbols-outlined">location_on</i> ${kpEsc(e.objektname || '')}</div>
                    ${e.leistung ? `<div class="kp-card-sub">${kpEsc(e.leistung)}</div>` : ''}
                </div>
                <div class="kp-plan"><span>Plan</span><strong>${kpEsc(kpTimeRange(e.plan_von, e.plan_bis))}</strong></div>
            </div>
            <ul class="kp-workers">${workers}</ul>
        </article>`;
}

// ---------- Objekt folders (Fotos) ----------
// A selected Objekt in the dropdown opens its folder directly.
function kpActiveFolder() { return document.getElementById('kpObjekt').value || kpFolder; }
function kpOpenFolder(objId) { kpFolder = objId; kpLoad(); }
function kpCloseFolder() {
    kpFolder = null;
    document.getElementById('kpObjekt').value = '';
    kpLoad();
}

function kpGroupByObjekt(list) {
    const groups = {};
    list.forEach(x => {
        const g = groups[x.obj_id] = groups[x.obj_id] || { obj_id: x.obj_id, name: x.objektname || x.obj_id, items: [], last: '' };
        g.items.push(x);
        const ts = x.datum || '';
        if (ts > g.last) g.last = ts;
    });
    // Most recent activity first, then alphabetical.
    return Object.values(groups).sort((a, b) => (b.last.localeCompare(a.last)) || a.name.localeCompare(b.name, 'de'));
}

function kpRenderFolders(list, kind) {
    if (!list.length) return kpEmpty('image', 'Keine Fotos in diesem Zeitraum.');
    const label = n => kind === 'fotos' ? `${n} ${n === 1 ? 'Foto' : 'Fotos'}` : `${n} ${n === 1 ? 'Eintrag' : 'Einträge'}`;
    return `<div class="kp-folder-grid">${kpGroupByObjekt(list).map(g => `
        <button class="kp-folder" data-obj="${kpEsc(g.obj_id)}" onclick="kpOpenFolder(this.dataset.obj)">
            <div class="kp-folder-cover"${kind === 'fotos' ? ` data-cover="${g.items[0].id}"` : ''}>
                <i class="material-symbols-outlined">${kind === 'fotos' ? 'photo_library' : 'folder'}</i>
            </div>
            <div class="kp-folder-info">
                <strong>${kpEsc(g.name)}</strong>
                <span>${label(g.items.length)} · zuletzt ${kpDe(g.last)}</span>
            </div>
            <i class="material-symbols-outlined kp-folder-arrow">chevron_right</i>
        </button>`).join('')}</div>`;
}

function kpFolderHead(list, objId) {
    const name = (list.find(x => x.obj_id === objId) || {}).objektname
        || [...document.getElementById('kpObjekt').options].find(o => o.value === objId)?.textContent || objId;
    return `<div class="kp-folder-head">
        <button class="kp-chip-btn" onclick="kpCloseFolder()"><i class="material-symbols-outlined">arrow_back</i> Alle Objekte</button>
        <span class="kp-folder-title"><i class="material-symbols-outlined">folder_open</i> ${kpEsc(name)}</span>
    </div>`;
}

async function kpLoadFolderCovers(box, seq) {
    for (const el of box.querySelectorAll('[data-cover]')) {
        try {
            const blob = await kpFetch('/fotos/' + el.dataset.cover, true);
            if (!blob || seq !== kpLoadSeq) return;
            const url = URL.createObjectURL(blob);
            kpFotoUrls.push(url);
            el.innerHTML = `<img src="${url}" alt="">`;
        } catch (e) { /* keep the folder icon */ }
    }
}

// ---------- Fotos ----------
function kpRevokeFotos() { kpFotoUrls.forEach(u => URL.revokeObjectURL(u)); kpFotoUrls = []; }

// "Foto_KAS-Golbitz_2026-10-05_22-56.jpg" — Objekt + capture time.
function kpFotoFileName(fig, blob) {
    const ext = ({ 'image/png': 'png', 'image/webp': 'webp' })[blob.type] || 'jpg';
    const obj = (fig.dataset.obj || 'Objekt').normalize('NFKD').replace(/[\/\\.,]+/g, ' ').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-') || 'Objekt';
    const m = (fig.dataset.taken || '').match(/^(\d{2})\.(\d{2})\.(\d{4}) (\d{2}):(\d{2})/);
    const ts = m ? `${m[3]}-${m[2]}-${m[1]}_${m[4]}-${m[5]}` : fig.dataset.id;
    return `Foto_${obj}_${ts}.${ext}`;
}

function kpDownload(url, name) {
    const a = document.createElement('a');
    a.href = url; a.download = name; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
}

// Downloads every loaded photo of the open folder one after another
// (short pause so browsers don't drop parallel downloads).
async function kpDownloadAll() {
    const figs = [...document.querySelectorAll('.kp-foto')].filter(f => f.dataset.url);
    if (!figs.length) return;
    for (const f of figs) {
        kpDownload(f.dataset.url, f.dataset.file);
        await new Promise(r => setTimeout(r, 350));
    }
}

function kpRenderFotos(box, list, seq, headHtml = '') {
    kpRevokeFotos();
    if (!list.length) { box.innerHTML = headHtml + kpEmpty('image', 'Keine Fotos in diesem Zeitraum.'); return; }
    const allBtn = list.length > 1
        ? `<div class="kp-foto-actions"><button class="kp-chip-btn" onclick="kpDownloadAll()"><i class="material-symbols-outlined">download</i> Alle herunterladen (${list.length})</button></div>`
        : '';
    box.innerHTML = headHtml + allBtn + `<div class="kp-foto-grid">${list.map(f => `
        <figure class="kp-foto" data-id="${f.id}" data-obj="${kpEsc(f.objektname || '')}" data-taken="${kpEsc(f.aufgenommen || '')}" data-caption="${kpEsc(f.aufgenommen + ' · ' + (f.objektname || '') + ' · ' + (f.mitarbeiter || ''))}">
            <div class="kp-foto-img"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress></div>
            <button class="kp-foto-dl" title="Herunterladen" disabled><i class="material-symbols-outlined">download</i></button>
            <figcaption><strong>${kpEsc(f.aufgenommen)}</strong><span>${kpEsc(f.objektname || '')}</span><span>${kpEsc(f.mitarbeiter || '')}</span></figcaption>
        </figure>`).join('')}</div>`;
    // Images are protected (Bearer) — fetch as blobs, a few at a time.
    const figs = [...box.querySelectorAll('.kp-foto')];
    let next = 0;
    const worker = async () => {
        while (next < figs.length && seq === kpLoadSeq) {
            const fig = figs[next++];
            try {
                const blob = await kpFetch('/fotos/' + fig.dataset.id, true);
                if (!blob || seq !== kpLoadSeq) return;
                const url = URL.createObjectURL(blob);
                kpFotoUrls.push(url);
                const holder = fig.querySelector('.kp-foto-img');
                holder.innerHTML = '';
                const img = document.createElement('img');
                img.src = url; img.alt = fig.dataset.caption; img.loading = 'lazy';
                holder.appendChild(img);
                fig.dataset.url = url;
                fig.dataset.file = kpFotoFileName(fig, blob);
                const dl = fig.querySelector('.kp-foto-dl');
                dl.disabled = false;
                dl.onclick = (ev) => { ev.stopPropagation(); kpDownload(url, fig.dataset.file); };
                fig.onclick = () => kpOpenLightbox(url, fig.dataset.caption, fig.dataset.file);
            } catch (e) {
                fig.querySelector('.kp-foto-img').innerHTML = '<i class="material-symbols-outlined">hide_image</i>';
            }
        }
    };
    for (let i = 0; i < 4; i++) worker();
}

function kpOpenLightbox(url, caption, fileName) {
    document.getElementById('kpLightboxImg').src = url;
    document.getElementById('kpLightboxCaption').textContent = caption;
    const dl = document.getElementById('kpLightboxDl');
    dl.href = url;
    dl.download = fileName || 'Foto.jpg';
    document.getElementById('kpLightbox').classList.add('open');
}
function kpCloseLightbox() { document.getElementById('kpLightbox').classList.remove('open'); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') kpCloseLightbox(); });

document.addEventListener('DOMContentLoaded', kpInstallAppBackGuard);
document.addEventListener('DOMContentLoaded', kpInit);
