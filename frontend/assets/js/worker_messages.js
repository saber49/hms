/* ============================================
   HMS Gruppe — MA-Nachrichten (Worker Messages) Module
   Backend: api/worker_messages.php
   ============================================ */

// ---------- MD3 helper (Material Symbols) for the MA-Nachrichten page ----------
function wmIcon(name, style = '') {
    return `<span class="material-symbols-outlined"${style ? ` style="${style}"` : ''}>${name}</span>`;
}

let wmCurrentThread = null;
let wmPollTimer = null;
// Inbox state — kept across re-renders so the search query and poll-driven
// updates don't reset each other. Backend already returns threads sorted by
// MAX(created_at) DESC, so the most recently active worker is always on top
// (WhatsApp-style behaviour).
let _wmAllThreads = [];
let _wmSearchQuery = '';
let _wmInboxPollTimer = null;

async function renderWorkerMessages() {
    const content = document.getElementById('content');
    wmCurrentThread = null;
    stopWmPoll();
    stopWmInboxPoll();

    const data = await apiGet(API.worker_messages + '/inbox');
    if (!data) { content.innerHTML = `<div class="empty-state">${wmIcon('error')}<p>Fehler beim Laden</p></div>`; return; }

    _wmAllThreads = data.threads || [];

    content.innerHTML = `
        <div class="wm-page">
            <div class="wm-inbox" id="wmInbox">
                <div class="wm-inbox-header">
                    <h3>Mitarbeiter Nachrichten</h3>
                    <span class="wm-unread-total" id="wmUnreadTotal">${data.total_unread > 0 ? data.total_unread + ' ungelesen' : ''}</span>
                </div>
                <div class="wm-search-wrap">
                    <md-outlined-text-field class="wm-search-md" id="wmSearchInput"
                        placeholder="Mitarbeiter suchen (Name, Dienst-Nr., Bereich)..."
                        oninput="wmFilterThreads(this.value)"
                        value="${escapeHtml(_wmSearchQuery)}">
                        <md-icon slot="leading-icon">search</md-icon>
                    </md-outlined-text-field>
                    ${_wmSearchQuery ? `<md-icon-button class="wm-search-clear" onclick="wmClearSearch()" title="Suche zurücksetzen"><md-icon slot="icon">close</md-icon></md-icon-button>` : ''}
                </div>
                <div class="wm-thread-list" id="wmThreadList">
                    ${wmRenderThreadList(_wmAllThreads, _wmSearchQuery)}
                </div>
            </div>
            ${canEdit('worker_messages') ? `
            <md-fab class="wm-new-chat-fab" variant="primary" label="Neuer Chat" title="Neue Nachricht an einen Mitarbeiter" onclick="openWmNewChat()">
                <md-icon slot="icon">edit_square</md-icon>
            </md-fab>
            <md-dialog id="wmNewChatDialog" class="wm-new-chat-dialog">
                <div slot="headline">Neue Nachricht</div>
                <div slot="content">
                    <md-outlined-text-field class="wm-picker-search" id="wmPickerSearch"
                        placeholder="Mitarbeiter suchen (Name, Dienst-Nr., Bereich)..."
                        oninput="wmFilterPicker(this.value)">
                        <md-icon slot="leading-icon">search</md-icon>
                    </md-outlined-text-field>
                    <div class="wm-picker-list" id="wmPickerList">
                        <div class="empty-state" style="padding:24px">${wmIcon('progress_activity')}<p>Lade Mitarbeiter...</p></div>
                    </div>
                </div>
                <div slot="actions">
                    <md-text-button onclick="document.getElementById('wmNewChatDialog').close()">Abbrechen</md-text-button>
                </div>
            </md-dialog>` : ''}
            <div class="wm-chat" id="wmChat" style="display:none">
                <div class="wm-chat-header" id="wmChatHeader"></div>
                <div class="wm-chat-messages" id="wmChatMessages"></div>
                <div class="wm-chat-input-bar">
                    <input type="text" class="wm-chat-input" id="wmChatInput" placeholder="Antwort schreiben..." maxlength="2000"
                        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendWmReply()}">
                    <md-icon-button class="wm-chat-send" onclick="sendWmReply()"><md-icon slot="icon">send</md-icon></md-icon-button>
                </div>
            </div>
        </div>
    `;
    updateWmBadge();
    startWmInboxPoll();
}

// Apply the current search query to the threads list.
function wmApplyFilter(threads, query) {
    if (!query || !query.trim()) return threads;
    const q = query.trim().toLowerCase();
    return threads.filter(t =>
        (t.worker_name || '').toLowerCase().includes(q) ||
        (t.dienst_nr || '').toLowerCase().includes(q) ||
        (t.bereich || '').toLowerCase().includes(q)
    );
}

function wmRenderThreadList(threads, query) {
    const filtered = wmApplyFilter(threads, query);
    if (filtered.length === 0) {
        if (query) {
            return `<div class="empty-state" style="padding:40px 20px">${wmIcon('search')}<p>Kein Mitarbeiter gefunden für "${escapeHtml(query)}"</p></div>`;
        }
        return `<div class="empty-state" style="padding:40px 20px">${wmIcon('chat')}<p>Keine Nachrichten von Mitarbeitern</p></div>`;
    }
    return filtered.map(t => wmRenderThreadRow(t)).join('');
}

function wmRenderThreadRow(t) {
    const initials = escapeHtml((t.worker_name || 'MA').substring(0, 2).toUpperCase());
    const avatar = t.foto
        ? `<img src="${escapeHtml(t.foto)}" alt="">`
        : initials;
    const preview = (t.letzte_richtung === 'admin' ? '<span class="wm-thread-prefix">Du: </span>' : '') +
        escapeHtml((t.letzte_text || '').substring(0, 60));
    return `
        <div class="wm-thread ${t.unread > 0 ? 'wm-thread-unread' : ''}" onclick="openWmThread('${t.dienst_nr}')">
            <div class="wm-thread-avatar">${avatar}</div>
            <div class="wm-thread-body">
                <div class="wm-thread-top">
                    <span class="wm-thread-name">${escapeHtml(t.worker_name)}</span>
                    <span class="wm-thread-time">${formatChatTime(t.letzte_nachricht)}</span>
                </div>
                <div class="wm-thread-preview">${preview}</div>
            </div>
            ${t.unread > 0 ? `<span class="wm-thread-badge">${t.unread}</span>` : ''}
        </div>
    `;
}

// Called by oninput on the search field. Re-renders only the list.
function wmFilterThreads(query) {
    _wmSearchQuery = query || '';
    const list = document.getElementById('wmThreadList');
    if (list) list.innerHTML = wmRenderThreadList(_wmAllThreads, _wmSearchQuery);

    // Toggle the clear ("×") button without re-rendering the input itself,
    // which would steal focus away from the user.
    const wrap = document.querySelector('.wm-search-wrap');
    if (!wrap) return;
    let clearBtn = wrap.querySelector('.wm-search-clear');
    if (_wmSearchQuery) {
        if (!clearBtn) {
            clearBtn = document.createElement('button');
            clearBtn.className = 'wm-search-clear';
            clearBtn.title = 'Suche zurücksetzen';
            clearBtn.innerHTML = wmIcon('close', 'font-size:14px');
            clearBtn.onclick = wmClearSearch;
            wrap.appendChild(clearBtn);
        }
    } else if (clearBtn) {
        clearBtn.remove();
    }
}

function wmClearSearch() {
    _wmSearchQuery = '';
    const input = document.getElementById('wmSearchInput');
    if (input) { input.value = ''; input.focus(); }
    wmFilterThreads('');
}

// ---------- New chat picker: list ALL active workers (MD3 dialog) ----------
let _wmAllWorkers = [];

async function openWmNewChat() {
    const dialog = document.getElementById('wmNewChatDialog');
    if (!dialog) return;
    dialog.show();
    const search = document.getElementById('wmPickerSearch');
    if (search) { search.value = ''; setTimeout(() => search.focus(), 150); }

    const data = await apiGet(API.worker_messages + '/workers');
    _wmAllWorkers = (data && data.workers) || [];
    wmFilterPicker('');
}

function wmFilterPicker(query) {
    const list = document.getElementById('wmPickerList');
    if (!list) return;
    const q = (query || '').trim().toLowerCase();
    const filtered = !q ? _wmAllWorkers : _wmAllWorkers.filter(w =>
        (w.worker_name || '').toLowerCase().includes(q) ||
        (w.dienst_nr || '').toLowerCase().includes(q) ||
        (w.bereich || '').toLowerCase().includes(q)
    );
    if (filtered.length === 0) {
        list.innerHTML = `<div class="empty-state" style="padding:24px">${wmIcon('person_search')}<p>Kein Mitarbeiter gefunden</p></div>`;
        return;
    }
    list.innerHTML = filtered.map(w => {
        const initials = escapeHtml((w.worker_name || 'MA').substring(0, 2).toUpperCase());
        const avatar = w.foto ? `<img src="${escapeHtml(w.foto)}" alt="">` : initials;
        return `
            <div class="wm-picker-row" onclick="wmStartChat('${escapeHtml(w.dienst_nr)}')">
                <div class="wm-thread-avatar">${avatar}</div>
                <div class="wm-picker-info">
                    <span class="wm-picker-name">${escapeHtml(w.worker_name)}</span>
                    <span class="wm-picker-meta">${escapeHtml(w.dienst_nr)}${w.bereich ? ' \u00b7 ' + bereichDotHtml(w.bereich) + ' ' + escapeHtml(w.bereich) : ''}</span>
                </div>
                ${Number(w.has_thread) ? `<span class="wm-picker-chip">${wmIcon('forum', 'font-size:14px')}Chat vorhanden</span>` : ''}
            </div>`;
    }).join('');
}

function wmStartChat(dienstNr) {
    const dialog = document.getElementById('wmNewChatDialog');
    if (dialog) dialog.close();
    openWmThread(dienstNr);
}

// Poll the inbox every 8s while the inbox view is active. New messages from
// any worker will bubble that thread to the top because the backend always
// orders by `MAX(created_at) DESC`.
function startWmInboxPoll() {
    stopWmInboxPoll();
    _wmInboxPollTimer = setInterval(async () => {
        if (currentPage !== 'worker_messages' || wmCurrentThread !== null) {
            stopWmInboxPoll();
            return;
        }
        const data = await apiGet(API.worker_messages + '/inbox');
        if (!data || !data.threads) return;
        _wmAllThreads = data.threads;
        const list = document.getElementById('wmThreadList');
        if (list) list.innerHTML = wmRenderThreadList(_wmAllThreads, _wmSearchQuery);
        const totalEl = document.getElementById('wmUnreadTotal');
        if (totalEl) totalEl.textContent = data.total_unread > 0 ? data.total_unread + ' ungelesen' : '';
        updateWmBadge();
    }, 8000);
}

function stopWmInboxPoll() {
    if (_wmInboxPollTimer) {
        clearInterval(_wmInboxPollTimer);
        _wmInboxPollTimer = null;
    }
}

async function openWmThread(dienstNr) {
    wmCurrentThread = dienstNr;
    stopWmInboxPoll();
    const data = await apiGet(API.worker_messages + '/thread?dienst_nr=' + dienstNr);
    if (!data) return;

    document.getElementById('wmInbox').style.display = 'none';
    document.getElementById('wmChat').style.display = 'flex';
    const fab = document.querySelector('.wm-new-chat-fab');
    if (fab) fab.style.display = 'none';

    const w = data.worker || {};
    const name = ((w.vorname || '') + ' ' + (w.name || '')).trim() || dienstNr;

    const canCall = typeof hasPermission === 'function' && hasPermission('auftraege.nachrichten.send')
        && typeof CloodCall !== 'undefined';
    document.getElementById('wmChatHeader').innerHTML = `
        <md-icon-button class="wm-back-btn" onclick="closeWmThread()"><md-icon slot="icon">arrow_back</md-icon></md-icon-button>
        <div class="wm-chat-header-info">
            <strong>${escapeHtml(name)}</strong>
            <small>${escapeHtml(w.dienst_nr || '')}${w.bereich ? ' · ' + escapeHtml(w.bereich) : ''}</small>
        </div>
        ${canCall ? `<md-icon-button class="wm-back-btn wm-call-btn" title="Anrufen" onclick="CloodCall.start('${escapeHtml(dienstNr)}', '${escapeHtml(name).replace(/'/g, "\\'")}')"><i class="material-symbols-outlined">call</i></md-icon-button>` : ''}
    `;

    const msgs = data.nachrichten || [];
    document.getElementById('wmChatMessages').innerHTML = msgs.length
        ? msgs.map(m => wmBubble(m)).join('')
        : `<div class="empty-state" style="margin:auto;padding:40px 20px">${wmIcon('waving_hand')}<p>Noch keine Nachrichten — starte die Konversation!</p></div>`;
    wmScrollBottom();
    startWmPoll();
    updateWmBadge();
}

// DB timestamps are UTC ('YYYY-MM-DD HH:MM:SS'); a bare new Date() on
// that format parses as LOCAL time — force UTC, render Europe/Berlin.
function wmParseUtc(ts) {
    if (!ts) return null;
    let s = String(ts).trim();
    if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) s = s.replace(' ', 'T') + 'Z';
    const d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
}

function wmBubble(msg) {
    const isAdmin = msg.richtung === 'admin';
    const _cd = wmParseUtc(msg.created_at);
    const time = _cd ? _cd.toLocaleString('de-DE', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit', timeZone:'Europe/Berlin'}) : '';
    const fileUrl = msg.datei_pfad ? `${API_BASE}/serve?path=${encodeURIComponent(msg.datei_pfad)}` : '';
    // Site photos from the worker app chat — show the picture inline.
    const attachment = msg.datei_pfad && /\.(jpe?g|png|webp)$/i.test(msg.datei_pfad) ? `
        <a href="${fileUrl}" target="_blank" rel="noopener" style="display:block;margin-top:6px">
            <img src="${fileUrl}" alt="Foto" loading="lazy" style="display:block;width:100%;max-width:280px;border-radius:10px;background: color-mix(in srgb, var(--md-sys-color-scrim) 6%, transparent)">
        </a>` : msg.datei_pfad ? `
        <a href="#" onclick="hmsOpenFile('${hmsFileArg(msg.datei_pfad)}');return false" style="display:flex;align-items:center;gap:8px;padding:8px 12px;margin-top:6px;background: color-mix(in srgb, var(--md-sys-color-scrim) 5%, transparent);border-radius:8px;text-decoration:none;color:inherit;border: 1px solid color-mix(in srgb, var(--md-sys-color-scrim) 10%, transparent)">
            ${wmIcon('picture_as_pdf', 'font-size:1.2rem;color:#dc2626')}
            <div style="flex:1;min-width:0">
                <div style="font-size:0.8rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(msg.datei_name || 'Dokument')}</div>
                <div style="font-size:0.7rem;color: var(--md-sys-color-on-surface)">Dokument öffnen</div>
            </div>
            ${wmIcon('open_in_new', 'font-size:0.8rem;color:#888')}
        </a>` : '';
    return `
        <div class="wm-bubble ${isAdmin ? 'wm-bubble-out' : 'wm-bubble-in'}">
            ${isAdmin && msg.admin_name ? `<div class="wm-bubble-sender">${escapeHtml(msg.admin_name)}</div>` : ''}
            <div class="wm-bubble-text">${escapeHtml(msg.nachricht)}</div>
            ${attachment}
            <div class="wm-bubble-time">${time}</div>
        </div>
    `;
}

function wmScrollBottom() {
    const el = document.getElementById('wmChatMessages');
    if (el) setTimeout(() => el.scrollTop = el.scrollHeight, 50);
}

function closeWmThread() {
    stopWmPoll();
    wmCurrentThread = null;
    document.getElementById('wmChat').style.display = 'none';
    document.getElementById('wmInbox').style.display = 'block';
    // Re-render the inbox so the just-replied thread bubbles up correctly,
    // and re-arm inbox polling.
    renderWorkerMessages();
}

async function sendWmReply() {
    const input = document.getElementById('wmChatInput');
    if (!input || !wmCurrentThread) return;
    const text = input.value.trim();
    if (!text) return;

    input.disabled = true;
    const result = await apiPost(API.worker_messages + '/reply', {
        dienst_nr: wmCurrentThread,
        nachricht: text
    });
    input.disabled = false;

    if (result && result.success) {
        input.value = '';
        const el = document.getElementById('wmChatMessages');
        const hint = el.querySelector('.empty-state');
        if (hint) hint.remove();
        el.insertAdjacentHTML('beforeend', wmBubble({
            richtung: 'admin', nachricht: text, created_at: new Date().toISOString(), admin_name: 'Du'
        }));
        wmScrollBottom();
    } else {
        showToast((result && result.error) || 'Fehler beim Senden', 'error');
    }
}

function startWmPoll() {
    stopWmPoll();
    wmPollTimer = setInterval(async () => {
        if (!wmCurrentThread || currentPage !== 'worker_messages') { stopWmPoll(); return; }
        const data = await apiGet(API.worker_messages + '/thread?dienst_nr=' + wmCurrentThread);
        if (data && data.nachrichten && data.nachrichten.length) {
            document.getElementById('wmChatMessages').innerHTML = data.nachrichten.map(m => wmBubble(m)).join('');
            wmScrollBottom();
        }
    }, 8000);
}

function stopWmPoll() {
    if (wmPollTimer) { clearInterval(wmPollTimer); wmPollTimer = null; }
}

async function updateWmBadge() {
    try {
        const data = await apiGet(API.worker_messages + '/unread');
        const badge = document.getElementById('badge-worker-messages');
        if (badge && data) {
            if (data.unread > 0) {
                badge.textContent = data.unread;
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        }
    } catch(e) {}
}
