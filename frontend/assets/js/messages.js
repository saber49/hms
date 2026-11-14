/* ============================================
   HMS Gruppe — Chat / Messages Module
   Backend: api/messages.php
   ============================================ */

let chatOpen = false;
let chatCurrentConv = null;
let chatPollInterval = null;
let chatMessagePollInterval = null;
let chatConvParticipants = [];   // other members of the open conversation (for @mentions)
let chatConvIsGroup = false;
let chatConvData = null;         // open conversation record (participants, created_by, …)

function initChat() {
    updateChatBadge();
    chatPollInterval = setInterval(updateChatBadge, 15000);
    const mi = document.getElementById('chatMessageInput');
    if (mi) mi.addEventListener('input', () => chatMentionInput(mi));
}

function toggleChatPanel() {
    const panel = document.getElementById('chatPanel');
    const overlay = document.getElementById('chatOverlay');
    chatOpen = !chatOpen;

    if (chatOpen) {
        panel.classList.add('open');
        overlay.classList.add('active');
        showConversationList();
    } else {
        panel.classList.remove('open');
        overlay.classList.remove('active');
        chatCurrentConv = null;
        if (chatMessagePollInterval) {
            clearInterval(chatMessagePollInterval);
            chatMessagePollInterval = null;
        }
    }
}

async function updateChatBadge() {
    try {
        const data = await apiGet(API.messages + '/unread');
        const badge = document.getElementById('chatBadge');
        if (data && data.unread > 0) {
            badge.textContent = data.unread > 99 ? '99+' : data.unread;
            badge.classList.add('visible');
        } else {
            badge.classList.remove('visible');
        }
    } catch (e) { /* silent */ }
}

async function showConversationList() {
    chatCurrentConv = null;
    chatConvParticipants = [];
    chatConvIsGroup = false;
    chatConvData = null;
    const pop = document.getElementById('chatMentionPop');
    if (pop) pop.style.display = 'none';
    if (chatMessagePollInterval) {
        clearInterval(chatMessagePollInterval);
        chatMessagePollInterval = null;
    }

    document.getElementById('chatPanelTitle').innerHTML = '<i class="material-symbols-outlined">forum</i> <span class="chat-title-text">Nachrichten</span>';
    document.getElementById('chatNewBtn').style.display = '';
    document.getElementById('chatBackBtn').style.display = 'none';
    document.getElementById('chatDeleteBtn').style.display = 'none';
    document.getElementById('chatAddBtn').style.display = 'none';
    document.getElementById('chatInfoBtn').style.display = 'none';
    document.getElementById('chatInputArea').style.display = 'none';
    document.getElementById('chatAddPop').style.display = 'none';
    document.getElementById('chatMembersPop').style.display = 'none';

    const body = document.getElementById('chatPanelBody');
    body.innerHTML = '<div class="chat-loading"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Laden...</div>';

    const conversations = await apiGet(API.messages + '/conversations');
    if (!conversations || conversations.length === 0) {
        body.innerHTML = `
            <div class="chat-empty">
                <i class="material-symbols-outlined">forum</i>
                <p>Keine Nachrichten</p>
                <md-filled-button  class="md-btn-sm" onclick="openNewChat()"><i slot="icon" class="material-symbols-outlined">add</i> Neuen Chat starten</md-filled-button>
            </div>
        `;
        return;
    }

    body.innerHTML = `<ul class="chat-conv-list">${conversations.map(c => {
        const initials = getConvDisplayName(c).split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
        const timeStr = c.last_message_at ? formatChatTime(c.last_message_at) : '';
        const preview = c.last_message ? (c.last_sender_name ? c.last_sender_name.split(' ')[0] + ': ' : '') + c.last_message : 'Noch keine Nachrichten';
        const unread = parseInt(c.unread_count) || 0;
        return `
            <li class="chat-conv-item ${unread > 0 ? 'unread' : ''}" onclick="openConversation(${c.id})">
                <div class="chat-conv-avatar">${initials}</div>
                <div class="chat-conv-info">
                    <div class="chat-conv-name">
                        <span>${escapeHtml(getConvDisplayName(c))}</span>
                        <span class="chat-conv-time">${timeStr}</span>
                    </div>
                    <div class="chat-conv-preview">${escapeHtml(preview.substring(0, 60))}</div>
                </div>
                ${unread > 0 ? `<span class="chat-conv-unread-badge">${unread}</span>` : ''}
            </li>
        `;
    }).join('')}</ul>`;
}

// Is this participant row the signed-in user? The backend flags it via
// `is_me` (tenant users.id — a different table than CURRENT_USER.id, the
// central_users id, which is why we can't compare ids directly). Fall
// back to full_name for older payloads.
function chatIsMe(p) {
    if (p && typeof p.is_me !== 'undefined') return !!Number(p.is_me);
    return p.full_name === (typeof CURRENT_USER !== 'undefined' ? CURRENT_USER.full_name : '');
}

function getConvDisplayName(conv) {
    if (conv.title) return conv.title;
    const others = (conv.participants || []).filter(p => !chatIsMe(p));
    if (others.length > 0) return others.map(p => p.full_name).join(', ');
    return 'Chat #' + conv.id;
}

async function openConversation(convId) {
    chatCurrentConv = convId;

    document.getElementById('chatNewBtn').style.display = 'none';
    document.getElementById('chatBackBtn').style.display = '';
    document.getElementById('chatDeleteBtn').style.display = '';
    document.getElementById('chatAddBtn').style.display = '';
    document.getElementById('chatInfoBtn').style.display = '';
    document.getElementById('chatInputArea').style.display = 'flex';

    const body = document.getElementById('chatPanelBody');
    body.innerHTML = '<div class="chat-loading"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Laden...</div>';

    const messages = await apiGet(API.messages + '/messages?conversation_id=' + convId);

    // Update title from conversation info
    const convs = await apiGet(API.messages + '/conversations');
    const conv = (convs || []).find(c => c.id == convId);
    chatConvData = conv || null;
    if (conv) {
        document.getElementById('chatPanelTitle').innerHTML = '<i class="material-symbols-outlined">forum</i> <span class="chat-title-text">' + escapeHtml(getConvDisplayName(conv)) + '</span>';
        // @mentions only make sense in group chats — everyone else is a target.
        const all = conv.participants || [];
        chatConvIsGroup = all.length > 2;
        chatConvParticipants = all.filter(p => !chatIsMe(p));
        // Group rules: only the creator deletes; members leave instead.
        const delBtn = document.getElementById('chatDeleteBtn');
        if (chatConvIsGroup && !chatAmCreator()) delBtn.title = 'Gruppe verlassen';
        else delBtn.title = 'Chat löschen';
    } else {
        chatConvParticipants = [];
        chatConvIsGroup = false;
        document.getElementById('chatPanelTitle').innerHTML = '<i class="material-symbols-outlined">forum</i> <span class="chat-title-text">Chat</span>';
    }

    renderChatMessages(messages || [], true);
    updateChatBadge();

    // Start polling for new messages
    if (chatMessagePollInterval) clearInterval(chatMessagePollInterval);
    chatMessagePollInterval = setInterval(() => {
        if (chatCurrentConv === convId) pollMessages(convId);
    }, 5000);

    // Focus input
    setTimeout(() => document.getElementById('chatMessageInput').focus(), 200);
}

function renderChatMessages(messages, scrollToBottom) {
    const body = document.getElementById('chatPanelBody');
    if (messages.length === 0) {
        body.innerHTML = '<div class="chat-empty"><i class="material-symbols-outlined">send</i><p>Noch keine Nachrichten.<br>Schreibe die erste!</p></div>';
        return;
    }

    const currentUserId = typeof CURRENT_USER !== 'undefined' ? CURRENT_USER.id : null;
    let lastDate = '';
    let html = '<div class="chat-messages">';

    messages.forEach(m => {
        // created_at is stored in UTC — always render in Europe/Berlin,
        // the wall-clock the team actually works in.
        const d = chatParseUtc(m.created_at);
        const msgDate = d ? d.toLocaleDateString('en-CA', { timeZone: 'Europe/Berlin' }) : '';
        if (msgDate !== lastDate) {
            html += `<div class="chat-date-separator">${formatDate(msgDate)}</div>`;
            lastDate = msgDate;
        }
        const isSent = m.sender_id == currentUserId;
        const timeStr = d ? d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Berlin' }) : '';

        let bubbleContent = '';
        if (m.image_path) {
            bubbleContent += `<img src="${m.image_path}" class="chat-msg-image" onclick="window.open('${m.image_path}','_blank')" alt="Bild">`;
        }
        if (m.message) {
            bubbleContent = escapeHtml(m.message);
            // Highlight '@Firstname' mentions of any conversation member.
            const names = chatConvParticipants
                .map(p => (p.full_name || '').trim().split(/\s+/)[0])
                .filter(Boolean);
            if (typeof CURRENT_USER !== 'undefined' && CURRENT_USER.full_name) {
                names.push(CURRENT_USER.full_name.trim().split(/\s+/)[0]);
            }
            if (names.length && bubbleContent.indexOf('@') !== -1) {
                const set = new Set(names);
                bubbleContent = bubbleContent.replace(/@([\p{L}][\p{L}\p{N}._-]*)/gu,
                    (all, name) => set.has(name) ? `<span class="chat-mention">@${name}</span>` : all);
            }
        }

        html += `
            <div class="chat-msg ${isSent ? 'sent' : 'received'}">
                <span class="chat-msg-sender">${escapeHtml(m.sender_name)}</span>
                <div class="chat-msg-bubble">${bubbleContent}</div>
                <span class="chat-msg-time">${timeStr}</span>
            </div>
        `;
    });

    html += '</div>';
    // Snapshot the scroll position BEFORE replacing the DOM — a 5s poll
    // must not rip the user out of the history they are reading. Snap to
    // bottom only when explicitly asked (open/send) or when they were
    // already near the latest message.
    const nearBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 120;
    body.innerHTML = html;
    if (scrollToBottom || nearBottom) body.scrollTop = body.scrollHeight;
}

async function sendChatImage() {
    if (!chatCurrentConv) return;
    const fileInput = document.getElementById('chatImageInput');
    const file = fileInput.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
        showToast('Bild darf maximal 5 MB groß sein', 'error');
        fileInput.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('image', file);
    formData.append('conversation_id', chatCurrentConv);

    try {
        const res = await fetch(API.messages + '/upload-image', {
            method: 'POST',
            headers: getAuthHeadersNoContent(),
            body: formData
        });
        const result = await res.json();
        if (result.success) {
            const msgs = await apiGet(API.messages + '/messages?conversation_id=' + chatCurrentConv);
            renderChatMessages(msgs || [], true);
        } else {
            showToast(result.error || 'Upload fehlgeschlagen', 'error');
        }
    } catch (e) {
        showToast('Upload fehlgeschlagen', 'error');
    }
    fileInput.value = '';
}

// Am I the creator of the open conversation? The backend flags it via
// `is_creator` (compares tenant users.id — CURRENT_USER.id is the
// central_users id, a different table, so a client-side id compare
// would never match). Fall back to the old full_name heuristic.
function chatAmCreator() {
    if (!chatConvData) return false;
    if (typeof chatConvData.is_creator !== 'undefined') return !!Number(chatConvData.is_creator);
    const myName = typeof CURRENT_USER !== 'undefined' ? CURRENT_USER.full_name : '';
    const me = (chatConvData.participants || []).find(p => p.full_name === myName);
    return !!me && String(chatConvData.created_by) === String(me.id);
}

async function deleteChat() {
    if (!chatCurrentConv) return;
    // Group chats: only the creator deletes for everyone — other members
    // leave the group (backend mirrors this rule).
    const isCreator = chatAmCreator();
    const msg = chatConvIsGroup
        ? (isCreator
            ? 'Gruppe wirklich löschen? Alle Nachrichten werden für alle Teilnehmer gelöscht.'
            : 'Gruppe verlassen? Du erhältst keine Nachrichten mehr aus dieser Gruppe.')
        : 'Chat wirklich löschen? Alle Nachrichten werden gelöscht.';
    if (!confirm(msg)) return;

    const result = await apiPost(API.messages + '/delete', {
        conversation_id: chatCurrentConv
    });

    if (result && result.success) {
        showToast(result.left ? 'Gruppe verlassen' : 'Chat gelöscht');
        chatCurrentConv = null;
        chatConvData = null;
        showConversationList();
        updateChatBadge();
    } else {
        showToast(result.error || 'Fehler beim Löschen', 'error');
    }
}

async function pollMessages(convId) {
    if (!chatOpen || chatCurrentConv !== convId) return;
    try {
        const messages = await apiGet(API.messages + '/messages?conversation_id=' + convId);
        if (messages && chatCurrentConv === convId) {
            renderChatMessages(messages, false);
        }
    } catch (e) { /* silent */ }
}

async function sendChatMessage() {
    if (!chatCurrentConv) return;
    const input = document.getElementById('chatMessageInput');
    const msg = input.value.trim();
    if (!msg) return;

    input.value = '';
    const result = await apiPost(API.messages + '/send', {
        conversation_id: chatCurrentConv,
        message: msg
    });

    if (result && result.success) {
        const msgs2 = await apiGet(API.messages + '/messages?conversation_id=' + chatCurrentConv);
        renderChatMessages(msgs2 || [], true);
    } else {
        input.value = msg;   // restore so a failed send doesn't lose the text
        showToast('Nachricht konnte nicht gesendet werden', 'error');
    }
}

async function openNewChat() {
    document.getElementById('chatNewBtn').style.display = 'none';
    document.getElementById('chatBackBtn').style.display = '';
    document.getElementById('chatDeleteBtn').style.display = 'none';
    document.getElementById('chatAddBtn').style.display = 'none';
    document.getElementById('chatInfoBtn').style.display = 'none';
    document.getElementById('chatInputArea').style.display = 'none';
    document.getElementById('chatPanelTitle').innerHTML = '<i class="material-symbols-outlined">add</i> <span class="chat-title-text">Neuer Chat</span>';

    const body = document.getElementById('chatPanelBody');
    body.innerHTML = '<div class="chat-loading"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Laden...</div>';

    const users = await apiGet(API.messages + '/users');
    if (!users || users.length === 0) {
        body.innerHTML = '<div class="chat-empty"><i class="material-symbols-outlined">group</i><p>Keine anderen Benutzer vorhanden</p></div>';
        return;
    }

    body.innerHTML = `
        <div class="chat-new-form">
            <label>Chat-Titel (optional)</label>
            <md-outlined-text-field type="text" class="form-control" id="newChatTitle" placeholder="z.B. Projektbesprechung"></md-outlined-text-field>
            <label>Teilnehmer auswählen</label>
            <ul class="chat-user-list">
                ${users.map(u => `
                    <li class="chat-user-item" onclick="this.classList.toggle('selected');this.querySelector('input').checked=!this.querySelector('input').checked">
                        <md-checkbox value="${u.id}" class="chat-participant-cb"></md-checkbox>
                        <span>${escapeHtml(u.full_name)}</span>
                        <small style="color:var(--gray-400);margin-left:auto">${u.role}</small>
                    </li>
                `).join('')}
            </ul>
            <label>Erste Nachricht</label>
            <md-outlined-text-field class="form-control" id="newChatMessage" rows="3" placeholder="Nachricht schreiben..." type="textarea"></md-outlined-text-field>
            <md-filled-button  class="w-100 mt-1" onclick="createNewChat()"><i slot="icon" class="material-symbols-outlined">send</i> Chat starten</md-filled-button>
        </div>
    `;
}

async function createNewChat() {
    const title = document.getElementById('newChatTitle').value.trim();
    const message = document.getElementById('newChatMessage').value.trim();
    const checkboxes = document.querySelectorAll('.chat-participant-cb:checked, .chat-participant-cb[checked]');
    const participants = Array.from(checkboxes).map(cb => parseInt(cb.value));

    if (participants.length === 0) {
        showToast('Bitte mindestens einen Teilnehmer auswählen', 'error');
        return;
    }
    if (!message) {
        showToast('Bitte eine Nachricht eingeben', 'error');
        return;
    }

    const result = await apiPost(API.messages + '/create', {
        title: title,
        participants: participants,
        message: message
    });

    if (result && result.success) {
        showToast('Chat erstellt');
        openConversation(result.conversation_id);
        updateChatBadge();
    } else {
        showToast(result.error || 'Fehler beim Erstellen', 'error');
    }
}

// ============================================================
// @Mentions in group chats — '@' opens a member picker above the
// composer; picking inserts '@Firstname '. The backend notifies
// mentioned participants (chat.mention → '#chat?conv=<id>').
// ============================================================
function chatMentionInput(input) {
    const pop = document.getElementById('chatMentionPop');
    if (!pop) return;
    if (!chatConvIsGroup || !chatConvParticipants.length) { pop.style.display = 'none'; return; }
    const caret = input.selectionStart;
    const m = /(^|\s)@([^\s@]*)$/.exec(input.value.substring(0, caret));
    if (!m) { pop.style.display = 'none'; return; }
    const q = m[2].toLowerCase();
    const matches = chatConvParticipants.filter(p =>
        (p.full_name || '').toLowerCase().includes(q) ||
        ((p.full_name || '').trim().split(/\s+/)[0] || '').toLowerCase().startsWith(q)
    ).slice(0, 6);
    if (!matches.length) { pop.style.display = 'none'; return; }
    pop.innerHTML = matches.map(p => {
        const first = (p.full_name || '').trim().split(/\s+/)[0] || p.full_name;
        return `<div class="chat-mention-item" data-name="${escapeHtml(first)}" onmousedown="event.preventDefault();chatMentionPick(this.dataset.name)">
            <span class="chat-mention-name">@${escapeHtml(first)}</span>
            <span class="chat-mention-full">${escapeHtml(p.full_name)}</span>
        </div>`;
    }).join('');
    pop.style.display = 'block';
}

function chatMentionPick(first) {
    const input = document.getElementById('chatMessageInput');
    const pop = document.getElementById('chatMentionPop');
    const caret = input.selectionStart;
    const before = input.value.substring(0, caret).replace(/@[^\s@]*$/, '@' + first + ' ');
    input.value = before + input.value.substring(caret);
    input.focus();
    input.selectionStart = input.selectionEnd = before.length;
    if (pop) pop.style.display = 'none';
}

// ============================================================
// Show all members of the open conversation — names, roles, and
// a count in the header. Opens a read-only overlay over the body.
// ============================================================
function openChatMembers() {
    if (!chatConvData) return;
    const all = chatConvData.participants || [];
    const pop = document.getElementById('chatMembersPop');
    const list = document.getElementById('chatMembersList');
    const title = document.getElementById('chatMembersTitle');
    title.textContent = 'Mitglieder (' + all.length + ')';
    const creatorId = chatConvData.created_by;
    list.innerHTML = '<ul class="chat-user-list" style="border:none;border-radius:0">' + all.map(p => {
        const isMe = chatIsMe(p);
        const isCreator = String(p.id) === String(creatorId);
        const badge = isCreator ? '<span class="chat-member-badge creator">Ersteller</span>' : '';
        const meTag = isMe ? '<span class="chat-member-badge me">Du</span>' : '';
        return '<li class="chat-member-row">' +
            '<i class="material-symbols-outlined chat-member-avatar">account_circle</i>' +
            '<span class="chat-member-name">' + escapeHtml(p.full_name) + meTag + badge + '</span>' +
            '<small style="color:var(--md-sys-color-on-surface-variant);margin-left:auto">' + escapeHtml(p.role || '') + '</small>' +
        '</li>';
    }).join('') + '</ul>';
    pop.style.display = 'flex';
}

// ============================================================
// Add members to an existing conversation — overlay picker over
// the panel body. Any participant may add; the backend skips
// users who are already members.
// ============================================================
async function openAddMembers() {
    if (!chatCurrentConv) return;
    const pop = document.getElementById('chatAddPop');
    const list = document.getElementById('chatAddPopList');
    list.innerHTML = '<div class="chat-loading"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Laden...</div>';
    pop.style.display = 'flex';

    const users = await apiGet(API.messages + '/users');
    const existing = new Set(((chatConvData && chatConvData.participants) || []).map(p => String(p.id)));
    const candidates = (users || []).filter(u => !existing.has(String(u.id)));

    if (!candidates.length) {
        list.innerHTML = '<div class="chat-empty"><i class="material-symbols-outlined">how_to_reg</i><p>Alle Benutzer sind bereits in diesem Chat</p></div>';
        return;
    }
    list.innerHTML = `<ul class="chat-user-list" style="border:none;border-radius:0">` + candidates.map(u => `
        <li class="chat-user-item" onclick="this.classList.toggle('selected');this.querySelector('input').checked=!this.querySelector('input').checked">
            <md-checkbox value="${u.id}" class="chat-participant-cb"></md-checkbox>
            <span>${escapeHtml(u.full_name)}</span>
            <small style="color:var(--gray-400);margin-left:auto">${escapeHtml(u.role || '')}</small>
        </li>`).join('') + '</ul>';
}

async function chatAddMembers() {
    const ids = Array.from(document.querySelectorAll('#chatAddPopList .chat-participant-cb:checked, #chatAddPopList .chat-participant-cb[checked]'))
        .map(cb => parseInt(cb.value));
    if (!ids.length) { showToast('Bitte mindestens ein Mitglied auswählen', 'error'); return; }
    const r = await apiPost(API.messages + '/add-members', { conversation_id: chatCurrentConv, participants: ids });
    if (r && r.success) {
        showToast(r.added ? 'Mitglieder hinzugefügt' : 'Mitglied war bereits im Chat');
        document.getElementById('chatAddPop').style.display = 'none';
        openConversation(chatCurrentConv);
    } else {
        showToast((r && r.error) || 'Fehler', 'error');
    }
}

function chatInputKeydown(e) {
    const pop = document.getElementById('chatMentionPop');
    if (pop && pop.style.display !== 'none') {
        if (e.key === 'Escape') { e.preventDefault(); pop.style.display = 'none'; return; }
        if (e.key === 'Enter' || e.key === 'Tab') {
            const first = pop.querySelector('.chat-mention-item');
            if (first) { e.preventDefault(); chatMentionPick(first.dataset.name); return; }
        }
    }
    if (e.key === 'Enter') sendChatMessage();
}

// DB timestamps arrive as UTC ('YYYY-MM-DD HH:MM:SS' or ISO-8601).
// new Date() on the space-separated form would parse it as LOCAL time —
// force UTC parsing, then let the formatters emit Europe/Berlin.
function chatParseUtc(ts) {
    if (!ts) return null;
    let s = String(ts).trim();
    if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) s = s.replace(' ', 'T') + 'Z';
    const d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
}

function formatChatTime(dateStr) {
    const d = chatParseUtc(dateStr);
    if (!d) return '';
    const now = new Date();
    const diffMs = now - d;
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffDays === 0) return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Berlin' });
    if (diffDays === 1) return 'Gestern';
    if (diffDays < 7) return DAYS_DE[d.getDay()];
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', timeZone: 'Europe/Berlin' });
}

/* ---- Realtime hook: new chat activity refreshes badge + open conversation ---- */
if (window.HMSRealtime) {
    HMSRealtime.on('chat', () => {
        try {
            if (typeof updateChatBadge === 'function') updateChatBadge();
            if (typeof chatCurrentConv !== 'undefined' && chatCurrentConv && typeof pollMessages === 'function') {
                pollMessages(chatCurrentConv);
            }
        } catch (e) { /* best-effort */ }
    });
}
