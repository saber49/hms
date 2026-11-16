/* ============================================
   Clood-X — E-Mail (shared company mailboxes)
   Backend: MailController (/api/mail/*). Messages are read live from the
   provider (IMAP / Microsoft Graph) — nothing is stored locally.
   Connecting: e-mail address → auto-discovery → Microsoft/Google sign-in
   (OAuth popup) or password only. Users never enter server names unless
   discovery fails.
   ============================================ */

const ML_API = API_BASE + '/mail';
const ML_MAX_TOTAL = 10 * 1024 * 1024;
const ML_POLL_MS = 60000;

const _ml = {
    accounts: [], oauth: {}, accountId: null,
    folder: 'inbox', q: '', cursor: null, next: null,
    items: [], unread: null,
    current: null, message: null, remote: false,
    files: [], replyTo: null, forwardOf: null, popup: null, discovered: null, poll: null,
};

// Live updates: the backend broadcasts 'mail' on the tenant channel when a
// mailbox changes — from our own mutations and from the server-side
// watcher (mail:poll), which also catches arrivals/deletes/read flips made
// in other clients (Outlook, phone …). The 60 s poll below stays as
// fallback. Reloads are debounced so a burst of hints refetches once.
let _mlRtT = null;
if (window.HMSRealtime && typeof HMSRealtime.on === 'function') {
    HMSRealtime.on('mail', (hint) => {
        if (hint && hint.konto && hint.konto !== _ml.accountId) return;
        if (hint && typeof hint.unread === 'number') {
            _ml.unread = hint.unread;
            mlSetBadge(hint.unread);
        }
        clearTimeout(_mlRtT);
        _mlRtT = setTimeout(() => {
            if (currentPage === 'mail' && _ml.folder === 'inbox' && !_ml.q && document.getElementById('mlList')) {
                mlLoadInbox(true);
            }
        }, 400);
    });
}

function mlIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}
function mlEsc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function mlInitials(name) {
    const parts = String(name || '?').replace(/[^\p{L}\p{N} ]/gu, ' ').trim().split(/\s+/);
    return mlEsc(((parts[0] || '?')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase());
}
function mlTime(iso, full = false) {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d)) return '';
    if (full) return d.toLocaleString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    const now = new Date();
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString('de-DE', { day: 'numeric', month: 'short' });
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' });
}
function mlSize(b) {
    if (!b) return '';
    if (b < 1024) return b + ' B';
    if (b < 1048576) return Math.round(b / 1024) + ' KB';
    return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
}
function mlCanManage() { return hasPermission('kommunikation.email.manage'); }
function mlAccount() { return _ml.accounts.find(a => a.id === _ml.accountId) || null; }
function mlProviderLabel(a) {
    return a.provider === 'microsoft' ? 'Microsoft 365 / Outlook' : a.provider === 'google' ? 'Google / Gmail' : (a.server || 'IMAP');
}
function mlProviderIcon(p) {
    return p === 'microsoft' ? 'grid_view' : p === 'google' ? 'g_mobiledata' : 'mail';
}
// apiGet returns [] on abort/timeout; Laravel errors come as {error} /
// {message, errors} / {message, exception}.
function mlBad(r) {
    return !r || Array.isArray(r) || !!r.error || !!r.errors
        || (typeof r.message === 'string' && (r.exception !== undefined || Object.keys(r).length === 1));
}
function mlErr(r, fallback) {
    if (Array.isArray(r) || !r) return 'Zeitüberschreitung — bitte erneut versuchen.';
    if (r.errors) return Object.values(r.errors).flat()[0] || fallback;
    return r.error || r.message || fallback;
}

// ============================================
// PAGE
// ============================================
async function renderMail() {
    const content = document.getElementById('content');
    stopMlPoll();
    _ml.current = null; _ml.message = null;
    _ml.folder = 'inbox'; _ml.q = ''; _ml.cursor = null; _ml.next = null;
    await mlHandleOAuthReturn();

    const data = await apiGet(ML_API + '/accounts');
    if (!data || !Array.isArray(data.accounts)) {
        content.innerHTML = `<div class="empty-state">${mlIcon('error')}<p>${mlEsc(mlErr(data, 'Fehler beim Laden'))}</p><md-filled-button  onclick="loadPage('mail')">Erneut versuchen</md-filled-button></div>`;
        return;
    }
    _ml.accounts = data.accounts;
    _ml.oauth = data.oauth || {};
    const saved = parseInt(localStorage.getItem('ml_account') || '0', 10);
    _ml.accountId = (_ml.accounts.find(a => a.id === saved) || _ml.accounts[0] || {}).id || null;

    if (!_ml.accountId) {
        content.innerHTML = `
            <div class="ml-empty">
                <div class="ml-empty-icon">${mlIcon('forward_to_inbox')}</div>
                <h3>Noch kein E-Mail-Postfach verbunden</h3>
                ${mlCanManage()
                    ? `<p>Verbinden Sie das Firmenpostfach (z.&nbsp;B. info@…) — Microsoft&nbsp;365, Gmail oder jeder andere Anbieter. Sie benötigen nur Ihre E-Mail-Adresse.</p>
                       <md-filled-button onclick="mlOpenAccounts(true)"><md-icon slot="icon">add_link</md-icon>Postfach verbinden</md-filled-button>`
                    : `<p>Ein Administrator muss zuerst ein Postfach verbinden.</p>`}
            </div>`;
        mlSetBadge(null);
        return;
    }

    const acc = mlAccount();
    const picker = _ml.accounts.length > 1
        ? `<md-outlined-select class="ml-account-select" id="mlAccountSelect" onchange="mlSwitchAccount(this.value)">
               ${_ml.accounts.map(a => `<md-select-option value="${a.id}" ${a.id === _ml.accountId ? 'selected' : ''}><div slot="headline">${mlEsc(a.email)}</div></md-select-option>`).join('')}
           </md-outlined-select>`
        : `<div class="ml-account-label"><strong>${mlEsc(acc.anzeige_name || acc.email)}</strong>${acc.anzeige_name ? `<small>${mlEsc(acc.email)}</small>` : ''}</div>`;

    content.innerHTML = `
        <div class="ml-page" id="mlPage">
            <section class="ml-list-pane">
                <div class="ml-list-header">
                    <div class="ml-account">${mlIcon(mlProviderIcon(acc.provider), 'color:var(--accent)')}${picker}</div>
                    <div class="ml-actions">
                        <md-icon-button title="Aktualisieren" onclick="mlLoadInbox(true)"><md-icon>refresh</md-icon></md-icon-button>
                        ${mlCanManage() ? `<md-icon-button title="Postfächer verwalten" onclick="mlOpenAccounts()"><md-icon>manage_accounts</md-icon></md-icon-button>` : ''}
                    </div>
                </div>
                <md-tabs class="ml-tabs">
                    <md-secondary-tab data-seg inline-icon class="ml-tab ml-tab-active" id="mlTabInbox" onclick="mlSetFolder('inbox')"><md-icon slot="icon">inbox</md-icon>Posteingang<span class="wm-unread-total" id="mlUnread"></span></md-secondary-tab>
                    <md-secondary-tab data-seg inline-icon class="ml-tab" id="mlTabSent" onclick="mlSetFolder('sent')"><md-icon slot="icon">send</md-icon>Gesendet</md-secondary-tab>
                </md-tabs>
                <div class="ml-search">
                    ${mlIcon('search', 'font-size:18px')}
                    <input id="mlSearch" type="search" placeholder="Betreff, Absender suchen…" autocomplete="off"
                        onkeydown="if(event.key==='Enter')event.preventDefault()" oninput="mlSearchInput()">
                    <md-icon-button id="mlSearchClear" onclick="mlClearSearch()" title="Suche löschen" aria-label="Suche löschen" hidden><md-icon>close</md-icon></md-icon-button>
                </div>
                ${acc.last_error ? mlErrorBanner(acc.last_error) : ''}
                <div class="ml-list" id="mlList"><div class="empty-state" style="padding:40px 20px">${mlIcon('progress_activity', 'animation:ml-spin 1s linear infinite')}<p>Lade Nachrichten…</p></div></div>
            </section>
            <section class="ml-reader" id="mlReader">
                <div class="empty-state ml-reader-empty">${mlIcon('drafts')}<p>Nachricht auswählen</p></div>
            </section>
        </div>
        ${canEdit('mail') ? `
        <md-fab class="wm-new-chat-fab ml-fab" variant="primary" label="Neue E-Mail" title="Neue E-Mail schreiben" onclick="mlCompose(false)">
            <md-icon slot="icon">edit_square</md-icon>
        </md-fab>` : ''}`;

    await mlLoadInbox(true);
    startMlPoll();
}

function mlErrorBanner(msg) {
    return `<div class="ml-banner ml-banner-error">${mlIcon('link_off')}<span>${mlEsc(msg)}</span>
        ${mlCanManage() ? `<md-text-button onclick="mlOpenAccounts(true)">Neu verbinden</md-text-button>` : ''}</div>`;
}

function mlSwitchAccount(id) {
    localStorage.setItem('ml_account', String(id));
    renderMail();
}

function mlSetFolder(f) {
    if (_ml.folder === f) return;
    _ml.folder = f;
    _ml.q = '';
    const s = document.getElementById('mlSearch');
    if (s) { s.value = ''; document.getElementById('mlSearchClear').hidden = true; }
    mlCloseReader();
    mlLoadInbox(true);
}

let _mlSearchT = null;
function mlSearchInput() {
    const el = document.getElementById('mlSearch');
    if (!el) return;
    document.getElementById('mlSearchClear').hidden = !el.value;
    clearTimeout(_mlSearchT);
    _mlSearchT = setTimeout(() => {
        _ml.q = el.value.trim();
        mlCloseReader();
        mlLoadInbox(true);
    }, 450);
}
function mlClearSearch() {
    const el = document.getElementById('mlSearch');
    if (el) { el.value = ''; el.focus(); }
    mlSearchInput();
}

async function mlLoadInbox(reset) {
    const list = document.getElementById('mlList');
    if (!list) return;
    if (reset) { _ml.cursor = null; _ml.items = []; }
    const id = _ml.accountId;
    const folder = _ml.folder, q = _ml.q;
    const url = `${ML_API}/accounts/${id}/inbox?folder=${folder}`
        + (_ml.cursor ? `&cursor=${encodeURIComponent(_ml.cursor)}` : '')
        + (q ? `&q=${encodeURIComponent(q)}` : '');
    const r = await apiGet(url);
    if (id !== _ml.accountId || folder !== _ml.folder || q !== _ml.q || !document.getElementById('mlList')) return;
    if (mlBad(r)) {
        if (reset) list.innerHTML = `<div class="empty-state" style="padding:40px 20px">${mlIcon(r && r.reconnect ? 'link_off' : 'cloud_off')}<p>${mlEsc(mlErr(r, 'Postfach konnte nicht geladen werden.'))}</p>
            ${r && r.reconnect && mlCanManage() ? `<md-filled-tonal-button onclick="mlOpenAccounts(true)">Neu verbinden</md-filled-tonal-button>` : `<md-text-button onclick="mlLoadInbox(true)">Erneut versuchen</md-text-button>`}</div>`;
        else showToast(mlErr(r, 'Weitere Nachrichten konnten nicht geladen werden.'), 'error');
        return;
    }
    r.items.forEach(i => { i.pg = _ml.cursor ? 2 : 1; });
    _ml.items = reset ? r.items : _ml.items.concat(r.items.filter(i => !_ml.items.some(x => x.id === i.id)));
    _ml.next = r.next || null;
    _ml.unread = r.unread;
    mlRenderList();
}

function mlLoadMore() {
    if (!_ml.next) return;
    _ml.cursor = _ml.next;
    const btn = document.getElementById('mlMoreBtn');
    if (btn) btn.disabled = true;
    mlLoadInbox(false);
}

function mlRenderList() {
    const list = document.getElementById('mlList');
    if (!list) return;
    const u = document.getElementById('mlUnread');
    if (u) u.textContent = _ml.unread > 0 ? _ml.unread : '';
    document.getElementById('mlTabInbox')?.classList.toggle('ml-tab-active', _ml.folder === 'inbox');
    document.getElementById('mlTabSent')?.classList.toggle('ml-tab-active', _ml.folder === 'sent');
    mlSetBadge(_ml.unread);
    if (!_ml.items.length) {
        const txt = _ml.q ? 'Keine Treffer für diese Suche' : _ml.folder === 'sent' ? 'Keine gesendeten Nachrichten' : 'Der Posteingang ist leer';
        list.innerHTML = `<div class="empty-state" style="padding:40px 20px">${mlIcon(_ml.q ? 'search_off' : 'inbox')}<p>${txt}</p></div>`;
        return;
    }
    list.innerHTML = _ml.items.map(mlRowHtml).join('')
        + (_ml.next ? `<div class="ml-more"><md-outlined-button id="mlMoreBtn" onclick="mlLoadMore()">Ältere Nachrichten laden</md-outlined-button></div>` : '');
}

function mlRowHtml(m) {
    const sent = _ml.folder === 'sent';
    const name = (sent ? (m.to_name || m.to_email) : (m.from_name || m.from_email)) || 'Unbekannt';
    return `
        <div class="ml-row${m.seen ? '' : ' ml-row-unread'}${_ml.current === m.id ? ' ml-row-active' : ''}" data-id="${mlEsc(m.id)}" onclick="mlOpen(this.dataset.id)">
            <div class="wm-thread-avatar">${mlInitials(name)}</div>
            <div class="ml-row-body">
                <div class="ml-row-top">
                    <span class="ml-row-from">${sent ? `An: ${mlEsc(name)}` : mlEsc(name)}</span>
                    <span class="ml-row-time">${mlTime(m.date)}</span>
                </div>
                <div class="ml-row-subject">${m.has_attachments ? mlIcon('attach_file', 'font-size:15px') : ''}<span>${mlEsc(m.subject || '(kein Betreff)')}</span></div>
                ${m.preview ? `<div class="ml-row-preview">${mlEsc(m.preview)}</div>` : ''}
            </div>
        </div>`;
}

function mlSetBadge(n) {
    const b = document.getElementById('badge-mail');
    if (!b) return;
    b.textContent = n > 99 ? '99+' : String(n || 0);
    b.style.display = n > 0 ? '' : 'none';
}

function startMlPoll() {
    stopMlPoll();
    _ml.poll = setInterval(async () => {
        if (currentPage !== 'mail') { stopMlPoll(); return; }
        if (document.visibilityState !== 'visible' || !document.getElementById('mlList')) return;
        if (_ml.folder !== 'inbox' || _ml.q) return;
        const id = _ml.accountId;
        const r = await apiGet(`${ML_API}/accounts/${id}/inbox?folder=inbox`);
        if (mlBad(r) || id !== _ml.accountId || _ml.folder !== 'inbox' || _ml.q) return;
        const known = new Map(_ml.items.map(i => [i.id, i]));
        const fresh = r.items.filter(i => !known.has(i.id));
        r.items.forEach(i => { if (known.has(i.id)) known.get(i.id).seen = i.seen; });
        if (!_ml.cursor) {
            // Reconcile page 1: rows the provider no longer returns were
            // deleted from another client — drop them locally too.
            const serverIds = new Set(r.items.map(i => i.id));
            _ml.items = _ml.items.filter(i => !(i.pg === 1 && !serverIds.has(i.id)));
            _ml.next = r.next || null;
        }
        _ml.items = fresh.map(i => (i.pg = 1, i)).concat(_ml.items);
        _ml.unread = r.unread;
        mlRenderList();
    }, ML_POLL_MS);
}
function stopMlPoll() {
    if (_ml.poll) clearInterval(_ml.poll);
    _ml.poll = null;
}

// ============================================
// READER
// ============================================
async function mlOpen(id) {
    _ml.current = id;
    _ml.remote = false;
    document.querySelectorAll('.ml-row').forEach(r => r.classList.toggle('ml-row-active', r.dataset.id === id));
    document.getElementById('mlPage')?.classList.add('ml-reading');
    const reader = document.getElementById('mlReader');
    reader.innerHTML = `<div class="empty-state ml-reader-empty">${mlIcon('progress_activity', 'animation:ml-spin 1s linear infinite')}<p>Lade Nachricht…</p></div>`;

    const r = await apiGet(`${ML_API}/accounts/${_ml.accountId}/message?mid=${encodeURIComponent(id)}&folder=${_ml.folder}`);
    if (_ml.current !== id || !document.getElementById('mlReader')) return;
    if (mlBad(r)) {
        reader.innerHTML = `<div class="empty-state ml-reader-empty">${mlIcon('error')}<p>${mlEsc(mlErr(r, 'Nachricht konnte nicht geladen werden.'))}</p></div>`;
        return;
    }
    _ml.message = r;
    const item = _ml.items.find(i => i.id === id);
    if (item && !item.seen && _ml.folder === 'inbox') {
        item.seen = true;
        if (_ml.unread > 0) _ml.unread--;
        mlRenderList();
    }
    mlRenderReader();
}

function mlCloseReader() {
    _ml.current = null;
    document.getElementById('mlPage')?.classList.remove('ml-reading');
    document.querySelectorAll('.ml-row-active').forEach(r => r.classList.remove('ml-row-active'));
}

function mlRenderReader() {
    const m = _ml.message;
    const reader = document.getElementById('mlReader');
    if (!m || !reader) return;
    const name = m.from.name || m.from.email || 'Unbekannt';
    const list = arr => arr.map(a => mlEsc(a.name ? `${a.name} <${a.email}>` : a.email)).join(', ');
    const hasRemote = m.html != null && /(<img[^>]+src\s*=\s*["']?\s*https?:|url\(\s*["']?\s*https?:)/i.test(m.html);

    reader.innerHTML = `
        <div class="ml-reader-head">
            <div class="ml-reader-top">
                <md-icon-button class="wm-back-btn ml-back" onclick="mlCloseReader()" title="Zurück"><md-icon slot="icon">arrow_back</md-icon></md-icon-button>
                <h3 class="ml-subject">${mlEsc(m.subject || '(kein Betreff)')}</h3>
                ${canEdit('mail') ? `<div class="ml-reader-act">
                    <md-filled-tonal-button onclick="mlCompose('reply')"><md-icon slot="icon">reply</md-icon>Antworten</md-filled-tonal-button>
                    ${_ml.folder === 'inbox' ? `<md-icon-button title="Allen antworten" onclick="mlCompose('replyAll')"><md-icon>reply_all</md-icon></md-icon-button>` : ''}
                    <md-icon-button title="Weiterleiten" onclick="mlCompose('forward')"><md-icon>forward</md-icon></md-icon-button>
                    ${_ml.folder === 'inbox' ? `<md-icon-button title="Als ungelesen markieren" onclick="mlToggleUnread()"><md-icon>mark_email_unread</md-icon></md-icon-button>` : ''}
                    <md-icon-button title="Löschen" onclick="mlDeleteMessage()"><md-icon>delete</md-icon></md-icon-button>
                </div>` : ''}
            </div>
            <div class="ml-meta">
                <div class="wm-thread-avatar">${mlInitials(name)}</div>
                <div class="ml-meta-text">
                    <div class="ml-meta-from"><strong>${mlEsc(name)}</strong>${m.from.name ? ` <span>&lt;${mlEsc(m.from.email)}&gt;</span>` : ''}</div>
                    ${m.to.length ? `<div class="ml-meta-to">An: ${list(m.to)}</div>` : ''}
                    ${m.cc.length ? `<div class="ml-meta-to">CC: ${list(m.cc)}</div>` : ''}
                </div>
                <div class="ml-meta-date">${mlTime(m.date, true)}</div>
            </div>
            ${m.attachments.length ? `<div class="ml-atts">${m.attachments.map((a, i) => `
                <md-outlined-button class="ml-att" onclick="mlDownload(${i})" title="Herunterladen"><md-icon slot="icon">description</md-icon><span class="ml-att-name">${mlEsc(a.name)}</span><small>${mlSize(a.size)}</small>
                </md-outlined-button>`).join('')}</div>` : ''}
            ${hasRemote ? `<div class="ml-banner" id="mlRemoteBanner">${mlIcon('image_not_supported')}<span>Externe Bilder wurden zum Schutz Ihrer Privatsphäre blockiert.</span><md-text-button onclick="mlShowRemote()">Bilder anzeigen</md-text-button></div>` : ''}
        </div>
        <iframe class="ml-body" id="mlBody" title="Nachricht" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe>`;
    document.getElementById('mlBody').srcdoc = mlBodyDoc(m, _ml.remote);
}

function mlShowRemote() {
    _ml.remote = true;
    document.getElementById('mlRemoteBanner')?.remove();
    const f = document.getElementById('mlBody');
    if (f && _ml.message) f.srcdoc = mlBodyDoc(_ml.message, true);
}

// Rendered in a sandboxed iframe without allow-scripts / allow-same-origin:
// mail HTML can neither run code nor reach the app's token. The CSP blocks
// remote content (tracking pixels) until the user opts in.
function mlBodyDoc(m, remote) {
    const csp = `default-src 'none'; style-src 'unsafe-inline'; font-src data:; img-src data:${remote ? ' https: http:' : ''}`;
    const inner = m.html != null
        ? m.html
        : `<pre>${mlEsc(m.text || '').replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1">$1</a>')}</pre>`;
    return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${csp}"><base target="_blank">`
        + `<style>html,body{margin:0;background: var(--md-sys-color-surface-container-lowest)}body{padding:18px 22px;font:14px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color: var(--md-sys-color-on-primary-container);overflow-wrap:anywhere}img{max-width:100%;height:auto}pre{white-space:pre-wrap;font:inherit;margin:0}a{color: var(--md-sys-color-primary)}blockquote{margin:0 0 0 8px;padding-left:10px;border-left: 3px solid var(--md-sys-color-primary-container);color: var(--md-sys-color-primary)}</style>`
        + `</head><body>${inner}</body></html>`;
}

async function mlDownload(i) {
    const a = _ml.message && _ml.message.attachments[i];
    if (!a) return;
    try {
        const res = await fetch(`${ML_API}/accounts/${_ml.accountId}/attachment?mid=${encodeURIComponent(_ml.message.id)}&aid=${encodeURIComponent(a.id)}&folder=${_ml.folder}`, { headers: getAuthHeadersNoContent() });
        if (!res.ok) {
            let msg = '';
            try { msg = (await res.json()).error; } catch (e) {}
            showToast(msg || 'Download fehlgeschlagen', 'error');
            return;
        }
        const url = URL.createObjectURL(await res.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = a.name || 'anhang';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 5000);
    } catch (e) {
        showToast('Download fehlgeschlagen', 'error');
    }
}

async function mlDeleteMessage() {
    const m = _ml.message;
    if (!m) return;
    const ok = await mdConfirm('Nachricht löschen? Sie wird beim Anbieter in den Papierkorb verschoben und kann dort wiederhergestellt werden.', { title: 'Nachricht löschen', confirmLabel: 'Löschen' });
    if (!ok) return;
    const r = await apiDelete(`${ML_API}/accounts/${_ml.accountId}/message?mid=${encodeURIComponent(m.id)}&folder=${_ml.folder}`);
    if (!r || !r.success) { showToast(mlErr(r, 'Löschen fehlgeschlagen.'), 'error'); return; }
    _ml.items = _ml.items.filter(i => i.id !== m.id);
    mlCloseReader();
    mlRenderList();
    showToast('Nachricht gelöscht');
}

async function mlToggleUnread() {
    const m = _ml.message;
    if (!m) return;
    const r = await apiPost(`${ML_API}/accounts/${_ml.accountId}/seen`, { mid: m.id, folder: _ml.folder, seen: false });
    if (!r || !r.success) { showToast(mlErr(r, 'Konnte nicht markiert werden.'), 'error'); return; }
    const item = _ml.items.find(i => i.id === m.id);
    if (item) item.seen = false;
    _ml.unread = (_ml.unread || 0) + 1;
    mlCloseReader();
    mlRenderList();
    showToast('Als ungelesen markiert');
}

// ============================================
// COMPOSE
// ============================================
function mlCompose(mode) {
    const m = mode && mode !== 'new' ? _ml.message : null;
    const acc = mlAccount();
    if (!acc || (mode && !m)) return;
    _ml.files = [];
    _ml.replyTo = (mode === 'reply' || mode === 'replyAll') ? m.id : null;
    _ml.forwardOf = mode === 'forward' ? m.id : null;
    document.getElementById('mlComposeDialog')?.remove();

    // Recipients: reply → sender; replyAll → sender + all To/CC minus self.
    const own = (acc.email || '').toLowerCase();
    const others = arr => {
        const out = [], seen = new Set();
        for (const a of arr || []) {
            const e = (a.email || '').toLowerCase();
            if (!e || e === own || seen.has(e)) continue;
            seen.add(e); out.push(a.email);
        }
        return out;
    };
    let to = '', cc = '';
    if (mode === 'reply') to = m.from.email || '';
    if (mode === 'replyAll') { to = others([m.from, ...m.to]).join(', '); cc = others(m.cc).join(', '); }
    const subject = m
        ? (mode === 'forward'
            ? (/^(wg|fwd?|weit):/i.test(m.subject || '') ? m.subject : 'WG: ' + (m.subject || ''))
            : (/^(re|aw|antw):/i.test(m.subject || '') ? m.subject : 'AW: ' + (m.subject || '')))
        : '';
    const headline = mode === 'replyAll' ? 'Allen antworten' : mode === 'forward' ? 'Weiterleiten' : m ? 'Antworten' : 'Neue E-Mail';

    const dlg = document.createElement('md-dialog');
    dlg.id = 'mlComposeDialog';
    dlg.className = 'ml-compose-dialog';
    dlg.innerHTML = `
        <div slot="headline">${headline}</div>
        <div slot="content" class="ml-compose">
            <div class="ml-compose-from">Von: <strong>${mlEsc(acc.anzeige_name ? `${acc.anzeige_name} <${acc.email}>` : acc.email)}</strong></div>
            <md-outlined-text-field label="An" id="mlTo" placeholder="name@firma.de, …"></md-outlined-text-field>
            <div id="mlCcToggle"><md-text-button onclick="mlShowCcBcc()"><md-icon slot="icon">add</md-icon>CC / BCC</md-text-button></div>
            <div class="ml-ccrow" id="mlCcRow" hidden>
                <md-outlined-text-field label="CC" id="mlCc"></md-outlined-text-field>
                <md-outlined-text-field label="BCC" id="mlBcc"></md-outlined-text-field>
            </div>
            <md-outlined-text-field label="Betreff" id="mlSubject"></md-outlined-text-field>
            <md-outlined-text-field label="Nachricht" id="mlText" type="textarea" rows="10"></md-outlined-text-field>
            ${m ? `<div class="ml-hint">${mlIcon(mode === 'forward' ? 'forward' : 'format_quote', 'font-size:16px')}${mode === 'forward'
                ? (m.attachments.length ? `Die Originalnachricht und ${m.attachments.length} Anhänge werden übernommen.` : 'Die Originalnachricht wird übernommen.')
                : 'Die ursprüngliche Nachricht wird automatisch angehängt.'}</div>` : ''}
            <div class="ml-files" id="mlFiles"></div>
            <input type="file" id="mlFileInput" multiple hidden onchange="mlAddFiles(this)">
        </div>
        <div slot="actions" class="ml-compose-actions">
            <md-text-button onclick="document.getElementById('mlFileInput').click()"><md-icon slot="icon">attach_file</md-icon>Anhang</md-text-button>
            <span class="ml-spacer"></span>
            <md-text-button onclick="document.getElementById('mlComposeDialog').close()">Abbrechen</md-text-button>
            <md-filled-button id="mlSendBtn" onclick="mlSend()"><md-icon slot="icon">send</md-icon>Senden</md-filled-button>
        </div>`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    document.getElementById('mlTo').value = to;
    document.getElementById('mlSubject').value = subject;
    if (cc) { mlShowCcBcc(); document.getElementById('mlCc').value = cc; }
    dlg.show();
    setTimeout(() => document.getElementById(m ? 'mlText' : 'mlTo')?.focus(), 200);
}

function mlShowCcBcc() {
    document.getElementById('mlCcRow')?.removeAttribute('hidden');
    const t = document.getElementById('mlCcToggle');
    if (t) t.hidden = true;
}

function mlAddFiles(input) {
    for (const f of input.files) {
        const total = _ml.files.reduce((s, x) => s + x.size, 0) + f.size;
        if (total > ML_MAX_TOTAL) { showToast('Anhänge dürfen zusammen höchstens 10 MB groß sein.', 'error'); break; }
        _ml.files.push(f);
    }
    input.value = '';
    mlRenderFiles();
}
function mlRemoveFile(i) {
    _ml.files.splice(i, 1);
    mlRenderFiles();
}
function mlRenderFiles() {
    const el = document.getElementById('mlFiles');
    if (el) el.innerHTML = _ml.files.map((f, i) => `
        <span class="ml-file">${mlIcon('attach_file', 'font-size:15px')}<span class="ml-att-name">${mlEsc(f.name)}</span><small>${mlSize(f.size)}</small>
            <md-icon-button onclick="mlRemoveFile(${i})" title="Entfernen" aria-label="Entfernen"><md-icon>close</md-icon></md-icon-button></span>`).join('');
}

async function mlSend() {
    const to = document.getElementById('mlTo').value.trim();
    if (!to) { showToast('Bitte einen Empfänger angeben.', 'error'); return; }
    const btn = document.getElementById('mlSendBtn');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('to', to);
    fd.append('cc', document.getElementById('mlCc')?.value.trim() || '');
    fd.append('bcc', document.getElementById('mlBcc')?.value.trim() || '');
    fd.append('subject', document.getElementById('mlSubject').value.trim());
    fd.append('text', document.getElementById('mlText').value);
    fd.append('src_folder', _ml.folder);
    if (_ml.replyTo) fd.append('reply_to', _ml.replyTo);
    if (_ml.forwardOf) fd.append('forward_of', _ml.forwardOf);
    _ml.files.forEach(f => fd.append('files[]', f, f.name));

    const r = await apiUpload(`${ML_API}/accounts/${_ml.accountId}/send`, fd);
    if (r && r.success) {
        showToast('E-Mail gesendet');
        document.getElementById('mlComposeDialog')?.close();
        return;
    }
    btn.disabled = false;
    showToast(mlErr(r, 'Senden fehlgeschlagen.'), 'error');
}

// ============================================
// ACCOUNTS (connect / remove)
// ============================================
function mlOpenAccounts(focusConnect) {
    document.getElementById('mlAccDialog')?.remove();
    const dlg = document.createElement('md-dialog');
    dlg.id = 'mlAccDialog';
    dlg.className = 'ml-acc-dialog';
    dlg.innerHTML = `
        <div slot="headline">E-Mail-Postfächer</div>
        <div slot="content">
            ${_ml.accounts.length ? `<div class="ml-acc-list">${_ml.accounts.map(a => `
                <div class="ml-acc-row">
                    <div class="wm-thread-avatar">${mlIcon(mlProviderIcon(a.provider))}</div>
                    <div class="ml-acc-info">
                        <strong>${mlEsc(a.email)}</strong>
                        <small>${mlEsc(mlProviderLabel(a))}</small>
                        ${a.last_error ? `<small class="ml-acc-error">${mlEsc(a.last_error)}</small>` : ''}
                    </div>
                    <md-icon-button title="Trennen" onclick="mlRemoveAccount(${a.id})"><md-icon>link_off</md-icon></md-icon-button>
                </div>`).join('')}</div><md-divider></md-divider>` : ''}
            <div class="ml-connect" id="mlConnect"></div>
        </div>
        <div slot="actions"><md-text-button onclick="document.getElementById('mlAccDialog').close()">Schließen</md-text-button></div>`;
    document.body.appendChild(dlg);
    dlg.addEventListener('closed', () => dlg.remove());
    mlConnectStep1();
    dlg.show();
    if (focusConnect) setTimeout(() => document.getElementById('mlConnEmail')?.focus(), 200);
}

function mlConnectStep1(email = '') {
    const el = document.getElementById('mlConnect');
    if (!el) return;
    el.innerHTML = `
        <h4>Postfach verbinden</h4>
        <p class="ml-muted">Geben Sie die E-Mail-Adresse ein — die Einstellungen werden automatisch erkannt.</p>
        <div class="ml-connect-row">
            <md-outlined-text-field id="mlConnEmail" type="email" label="E-Mail-Adresse" placeholder="info@firma.de"
                onkeydown="if(event.key==='Enter'){event.preventDefault();mlDiscover()}"></md-outlined-text-field>
            <md-filled-button id="mlDiscoverBtn" onclick="mlDiscover()">Weiter</md-filled-button>
        </div>
        <div id="mlConnectMsg"></div>`;
    document.getElementById('mlConnEmail').value = email;
}

function mlConnectMsg(text, type = 'error', hint = null) {
    const el = document.getElementById('mlConnectMsg');
    if (el) el.innerHTML = text || hint ? `
        ${text ? `<div class="ml-banner ${type === 'error' ? 'ml-banner-error' : ''}">${mlIcon(type === 'error' ? 'error' : 'info')}<span>${mlEsc(text)}</span></div>` : ''}
        ${hint ? `<div class="ml-banner">${mlIcon('lightbulb')}<span>${mlEsc(hint)}</span></div>` : ''}` : '';
}

async function mlDiscover() {
    const email = document.getElementById('mlConnEmail').value.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { mlConnectMsg('Bitte eine gültige E-Mail-Adresse eingeben.'); return; }
    const btn = document.getElementById('mlDiscoverBtn');
    btn.disabled = true;
    mlConnectMsg('Einstellungen werden ermittelt…', 'info');
    const r = await apiPost(ML_API + '/discover', { email });
    btn.disabled = false;
    if (mlBad(r) || !r.provider) { mlConnectMsg(mlErr(r, 'Die Adresse konnte nicht geprüft werden.')); return; }
    _ml.discovered = r;
    mlConnectStep2(r);
}

function mlConnectStep2(d, forcePassword = false) {
    const el = document.getElementById('mlConnect');
    if (!el) return;
    const head = `
        <div class="ml-connect-email">
            ${mlIcon(mlProviderIcon(d.provider))}<strong>${mlEsc(d.email)}</strong>
            <md-text-button onclick="mlConnectStep1(_ml.discovered.email)">Ändern</md-text-button>
        </div>`;

    if (d.provider === 'microsoft' && !forcePassword) {
        el.innerHTML = head + (d.oauth
            ? `<p class="ml-muted">Dieses Postfach gehört zu Microsoft 365 / Outlook. Melden Sie sich bei Microsoft an und erlauben Sie den Zugriff.</p>
               <md-outlined-button class="ml-oauth-btn ml-oauth-ms" onclick="mlOAuth('microsoft')">${mlMsLogo()}<span>Mit Microsoft anmelden</span></md-outlined-button>
               <div id="mlConnectMsg"></div>`
            : `<div id="mlConnectMsg"></div>`);
        if (!d.oauth) mlConnectMsg('Die Microsoft-Anmeldung ist auf diesem Server noch nicht eingerichtet.');
        return;
    }
    if (d.provider === 'google' && d.oauth && !forcePassword) {
        el.innerHTML = head + `
            <p class="ml-muted">Dieses Postfach gehört zu Google. Melden Sie sich bei Google an und erlauben Sie den Zugriff.</p>
            <md-outlined-button class="ml-oauth-btn ml-oauth-google" onclick="mlOAuth('google')">${mlGoogleLogo()}<span>Mit Google anmelden</span></md-outlined-button>
            <md-text-button class="ml-alt" onclick="mlConnectStep2(_ml.discovered, true)">Stattdessen mit App-Passwort verbinden</md-text-button>
            <div id="mlConnectMsg"></div>`;
        return;
    }

    const server = d.imap ? `${mlEsc(d.imap.host)} · ${d.imap.encryption === 'ssl' ? 'SSL/TLS' : 'STARTTLS'}` : '';
    el.innerHTML = head + `
        ${server ? `<p class="ml-muted">${mlIcon('check_circle', 'font-size:16px;color:var(--success,#16a34a);vertical-align:-3px')} Server erkannt: ${server}</p>` : ''}
        <md-outlined-text-field id="mlConnPw" type="password" label="${d.provider === 'google' ? 'App-Passwort' : 'Passwort des Postfachs'}"
            onkeydown="if(event.key==='Enter'){event.preventDefault();mlConnectImap()}"></md-outlined-text-field>
        <md-outlined-text-field id="mlConnName" label="Absendername (optional)" placeholder="z. B. HMS Gruppe — Büro"></md-outlined-text-field>
        <div id="mlManual"></div>
        <div class="ml-connect-actions">
            ${d.imap ? `<md-text-button onclick="mlShowManual()">Einstellungen manuell</md-text-button>` : ''}
            <span class="ml-spacer"></span>
            <md-filled-button id="mlConnectBtn" onclick="mlConnectImap()"><md-icon slot="icon">link</md-icon>Verbinden</md-filled-button>
        </div>
        <div id="mlConnectMsg"></div>`;
    if (!d.imap) {
        mlShowManual();
        mlConnectMsg('Die Servereinstellungen konnten nicht automatisch ermittelt werden. Bitte die Angaben Ihres Anbieters eintragen.', 'info', d.hint);
    } else if (d.hint) {
        mlConnectMsg(null, 'info', d.hint);
    }
    setTimeout(() => document.getElementById('mlConnPw')?.focus(), 100);
}

function mlShowManual() {
    const el = document.getElementById('mlManual');
    if (!el || el.innerHTML) return;
    const d = _ml.discovered || {};
    const domain = (d.email || '').split('@')[1] || '';
    el.innerHTML = `
        <div class="ml-manual">
            <md-outlined-text-field id="mlImapHost" label="Posteingang (IMAP)" placeholder="imap.${mlEsc(domain)}"></md-outlined-text-field>
            <md-outlined-select id="mlImapMode" label="Verschlüsselung">
                <md-select-option value="993|ssl" selected><div slot="headline">993 · SSL/TLS</div></md-select-option>
                <md-select-option value="143|starttls"><div slot="headline">143 · STARTTLS</div></md-select-option>
            </md-outlined-select>
            <md-outlined-text-field id="mlSmtpHost" label="Postausgang (SMTP)" placeholder="smtp.${mlEsc(domain)}"></md-outlined-text-field>
            <md-outlined-select id="mlSmtpMode" label="Verschlüsselung">
                <md-select-option value="465|ssl" selected><div slot="headline">465 · SSL/TLS</div></md-select-option>
                <md-select-option value="587|starttls"><div slot="headline">587 · STARTTLS</div></md-select-option>
            </md-outlined-select>
            <md-outlined-text-field id="mlUser" label="Benutzername (falls abweichend)" class="ml-manual-wide"></md-outlined-text-field>
        </div>`;
    if (d.imap) {
        document.getElementById('mlImapHost').value = d.imap.host;
        document.getElementById('mlSmtpHost').value = d.smtp.host;
        customElements.whenDefined('md-outlined-select').then(() => {
            document.getElementById('mlImapMode').value = d.imap.port + '|' + d.imap.encryption;
            document.getElementById('mlSmtpMode').value = d.smtp.port + '|' + d.smtp.encryption;
        });
    }
}

async function mlConnectImap() {
    const d = _ml.discovered;
    const password = document.getElementById('mlConnPw').value;
    if (!password) { mlConnectMsg('Bitte das Passwort eingeben.'); return; }
    const body = { email: d.email, password, anzeige_name: document.getElementById('mlConnName').value.trim() || null };
    const host = document.getElementById('mlImapHost');
    if (host) {
        const [ip, ie] = document.getElementById('mlImapMode').value.split('|');
        const [sp, se] = document.getElementById('mlSmtpMode').value.split('|');
        body.manual = {
            imap_host: host.value.trim(), imap_port: +ip, imap_encryption: ie,
            smtp_host: document.getElementById('mlSmtpHost').value.trim(), smtp_port: +sp, smtp_encryption: se,
            username: document.getElementById('mlUser').value.trim() || null,
        };
        if (!body.manual.imap_host || !body.manual.smtp_host) { mlConnectMsg('Bitte beide Serveradressen eintragen.'); return; }
    }
    const btn = document.getElementById('mlConnectBtn');
    btn.disabled = true;
    mlConnectMsg('Verbindung wird geprüft…', 'info');
    const r = await apiPost(ML_API + '/accounts', body);
    btn.disabled = false;
    if (r && r.account) { mlConnected(r.account); return; }
    if (r && r.need_manual) mlShowManual();
    mlConnectMsg(mlErr(r, 'Verbindung fehlgeschlagen.'), 'error', r && r.hint);
}

async function mlOAuth(provider) {
    // Open the popup synchronously inside the click — browsers block
    // window.open() after an await.
    const w = window.open('', 'clood_mail_oauth', 'width=520,height=720');
    if (w) w.document.write('<p style="font-family:system-ui;padding:24px">Weiterleitung zur Anmeldung…</p>');
    mlConnectMsg('Anmeldefenster geöffnet — bitte dort anmelden und den Zugriff erlauben.', 'info');
    const r = await apiPost(ML_API + '/oauth/start', { provider, email: _ml.discovered && _ml.discovered.email });
    if (!r || !r.url) {
        if (w) w.close();
        mlConnectMsg(mlErr(r, 'Die Anmeldung konnte nicht gestartet werden.'));
        return;
    }
    if (w && !w.closed) {
        _ml.popup = w;
        w.location.href = r.url;
    } else {
        // Popup blocked (or app WebView): full-page redirect; the callback
        // returns to index.php?mail_oauth=… and renderMail() finishes it.
        window.location.href = r.url;
    }
}

window.addEventListener('message', (e) => {
    if (!e.data || e.data.source !== 'clood-mail-oauth' || !_ml.popup || e.source !== _ml.popup) return;
    _ml.popup = null;
    if (!e.data.ok) { mlConnectMsg(e.data.error || 'Die Anmeldung ist fehlgeschlagen.'); return; }
    mlOAuthFinish(e.data.key);
});

async function mlOAuthFinish(key) {
    mlConnectMsg('Postfach wird verbunden…', 'info');
    const r = await apiPost(ML_API + '/oauth/finish', { key });
    if (r && r.account) { mlConnected(r.account); return; }
    mlConnectMsg(mlErr(r, 'Verbindung fehlgeschlagen.'));
    if (!document.getElementById('mlConnect')) showToast(mlErr(r, 'Verbindung fehlgeschlagen.'), 'error');
}

async function mlHandleOAuthReturn() {
    const p = new URLSearchParams(window.location.search);
    const key = p.get('mail_oauth');
    const err = p.get('mail_oauth_error');
    if (!key && !err) return;
    history.replaceState(null, '', window.location.pathname + '#mail');
    if (err) { showToast(err, 'error'); return; }
    const r = await apiPost(ML_API + '/oauth/finish', { key });
    if (r && r.account) {
        localStorage.setItem('ml_account', String(r.account.id));
        showToast('Postfach verbunden: ' + r.account.email);
    } else {
        showToast(mlErr(r, 'Verbindung fehlgeschlagen.'), 'error');
    }
}

function mlConnected(account) {
    showToast('Postfach verbunden: ' + account.email);
    localStorage.setItem('ml_account', String(account.id));
    document.getElementById('mlAccDialog')?.close();
    renderMail();
}

async function mlRemoveAccount(id) {
    const a = _ml.accounts.find(x => x.id === id);
    if (!a) return;
    const ok = await mdConfirm(`Postfach „${a.email}“ trennen? Die E-Mails bleiben beim Anbieter erhalten — sie werden hier nur nicht mehr angezeigt.`, { title: 'Postfach trennen', confirmLabel: 'Trennen' });
    if (!ok) return;
    const r = await apiDelete(`${ML_API}/accounts/${id}`);
    if (!r || !r.success) { showToast(mlErr(r, 'Trennen fehlgeschlagen.'), 'error'); return; }
    showToast('Postfach getrennt');
    if (localStorage.getItem('ml_account') === String(id)) localStorage.removeItem('ml_account');
    document.getElementById('mlAccDialog')?.close();
    renderMail();
}

function mlMsLogo() {
    return '<svg width="20" height="20" viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>';
}
function mlGoogleLogo() {
    return '<svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3 0 5.8 1.1 7.9 3l5.7-5.7C34.1 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3 0 5.8 1.1 7.9 3l5.7-5.7C34.1 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';
}
