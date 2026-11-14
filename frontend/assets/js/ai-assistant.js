/* ============================================================
   HMS / Clood-X — AI Assistant floating widget (v2)
   ------------------------------------------------------------
   Renders a launcher button + chat panel on every page. Talks
   to POST /api/ai/chat which already enforces tenant isolation
   on the backend (auth:sanctum + ResolveTenant middleware).

   v3 capabilities (read + write + inline multimodal):
   • Read tools     — analytics questions, KPIs, search, lists.
   • Write tools    — create/update worker/customer/object/...
                      via a confirmation card so the user must
                      explicitly approve every mutation.
   • Inline files   — attach a PDF / image; it appears as a chip
                      above the input, then renders inline in the
                      user's chat bubble after Send. Gemini reads
                      the image natively and decides what to do
                      (chat reply OR propose a write action).

   Tenant isolation: chat history bucketed by (tenant_id, user_id)
   in localStorage so different logins on the same browser never
   see each other's history.
   ============================================================ */

(function () {
    'use strict';

    if (typeof window.getAuthToken !== 'function') return;

    const MAX_HISTORY_FOR_API = 16;
    const STORAGE_KEY_PREFIX  = 'hms_ai_chat_v1_';

    // Mapping write-tool name → German label for the confirmation card title.
    const WRITE_TOOL_LABELS = {
        create_worker:          'Mitarbeiter anlegen',
        update_worker:          'Mitarbeiter ändern',
        create_kunde:           'Kunden anlegen',
        update_kunde:           'Kunden ändern',
        create_objekt:          'Objekt anlegen',
        create_artikel:         'Artikel anlegen',
        create_einsatz:         'Einsatz anlegen',
        update_einsatz_zeit:    'Einsatz-Zeit ändern',
        update_anforderungen:   'Anforderungen ändern',
        bulk_create_einsaetze:  'Einsätze importieren',
        assign_mitarbeiter:     'Mitarbeiter zuweisen',
        remove_mitarbeiter:     'Mitarbeiter entfernen',
    };

    // Quick-start suggestions shown on an empty chat — one click sends
    // the prompt. The first is the proactive "anything wrong?" check.
    const QUICK_PROMPTS = [
        { icon: 'warning', label: 'Probleme prüfen', text: 'Gibt es Probleme in der Disposition in den nächsten 7 Tagen? Prüfe unbesetzte, unterbesetzte und unbestätigte Einsätze.' },
        { icon: 'monitoring',           label: 'KPIs diesen Monat', text: 'Zeig mir die wichtigsten Kennzahlen für diesen Monat.' },
        { icon: 'calendar_today',         label: 'Heute',             text: 'Gib mir einen Überblick über heute.' },
    ];

    // Per-attachment limits — match backend (see config/ai.php).
    const MAX_ATTACH_BYTES   = 10 * 1024 * 1024;          // 10 MB per file
    const MAX_ATTACHMENTS    = 4;                          // sanity cap per turn
    // Accepted prefixes / exact mimes. Images + PDFs (vision OCR), and
    // CSV / XLSX / XLS / plain-text files (bulk-import flow). Excel
    // emits a surprising variety of MIME types — we accept all of them
    // and let the server-side FileExtractorService route by extension.
    const ALLOWED_MIME_PREFIXES = [
        'image/', 'application/pdf',
        'text/csv', 'application/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];

    let isOpen = false;
    let isSending = false;
    let history = []; // [{role, content, ts, pending_actions?, attachments?}]
    let userKey = '';
    let pendingAttachments = []; // [{ name, mime, size, dataUrl }] awaiting Send

    document.addEventListener('DOMContentLoaded', initAi);

    // ----------------------------------------------------------
    // Bootstrap — inject DOM, bind events, restore history.
    // ----------------------------------------------------------
    function initAi() {
        if (!getAuthToken()) return;
        purgeLegacyAnonBucket();
        userKey = readUserKey();

        const launcher = document.createElement('button');
        launcher.className = 'ai-launcher';
        launcher.id = 'aiLauncher';
        launcher.title = 'KI-Assistent öffnen';
        launcher.innerHTML = '<i class="material-symbols-outlined">smart_toy</i><span class="ai-launcher-dot"></span>';
        document.body.appendChild(launcher);

        const panel = document.createElement('div');
        panel.className = 'ai-panel';
        panel.id = 'aiPanel';
        panel.innerHTML = `
            <div class="ai-panel-header">
                <div class="ai-avatar"><i class="material-symbols-outlined">smart_toy</i></div>
                <div class="ai-title">
                    <strong>KI-Assistent <span class="ai-badge-pro">Pro</span></strong>
                    <small><i class="material-symbols-outlined ai-status-dot">circle</i> Multimodal • sieht Bilder &amp; PDFs</small>
                </div>
                <button class="ai-expand" id="aiExpand" title="Vergrößern/Verkleinern"><i class="material-symbols-outlined">open_in_full</i></button>
                <button class="ai-clear" id="aiClear" title="Verlauf löschen"><i class="material-symbols-outlined">delete</i></button>
                <button class="ai-close" id="aiClose" title="Schließen"><i class="material-symbols-outlined">close</i></button>
            </div>
            <div class="ai-messages" id="aiMessages"></div>
            <div class="ai-attachments" id="aiAttachments"></div>
            <div class="ai-input-row">
                <button class="ai-attach" id="aiAttach" title="PDF / Bild anhängen">
                    <i class="material-symbols-outlined">attach_file</i>
                </button>
                <textarea class="ai-input" id="aiInput" rows="1"
                    placeholder=""
                    maxlength="2000"></textarea>
                <button class="ai-send" id="aiSend" title="Senden"><i class="material-symbols-outlined">send</i></button>
                <input type="file" id="aiFileInput" accept="application/pdf,image/jpeg,image/png,image/webp" style="display:none">
            </div>
            <div class="ai-footnote">KI-generierte Antworten — kritische Aktionen erfordern Bestätigung.</div>
        `;
        document.body.appendChild(panel);

        // Bindings
        launcher.addEventListener('click', toggle);
        panel.querySelector('#aiClose').addEventListener('click', close);
        panel.querySelector('#aiExpand').addEventListener('click', toggleExpand);
        // Restore the user's preferred size (persists across sessions).
        if (localStorage.getItem('hms_ai_expanded') === '1') applyExpanded(true);
        panel.querySelector('#aiClear').addEventListener('click', clearHistory);
        panel.querySelector('#aiSend').addEventListener('click', send);
        panel.querySelector('#aiAttach').addEventListener('click', () =>
            panel.querySelector('#aiFileInput').click()
        );
        panel.querySelector('#aiFileInput').addEventListener('change', onFilePicked);

        const input = panel.querySelector('#aiInput');
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
        });
        input.addEventListener('input', () => {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 90) + 'px';
        });

        history = loadHistory();
        renderMessages();
    }

    // ----------------------------------------------------------
    // Open/close
    // ----------------------------------------------------------
    function toggle() { isOpen ? close() : open(); }
    function open() {
        document.getElementById('aiPanel').classList.add('open');
        isOpen = true;
        setTimeout(() => document.getElementById('aiInput').focus(), 150);
    }
    function close() {
        document.getElementById('aiPanel').classList.remove('open');
        isOpen = false;
    }
    function toggleExpand() {
        const expanded = !document.getElementById('aiPanel').classList.contains('expanded');
        applyExpanded(expanded);
        try { localStorage.setItem('hms_ai_expanded', expanded ? '1' : '0'); } catch (e) { /* ignore */ }
    }

    function applyExpanded(on) {
        const panel = document.getElementById('aiPanel');
        panel.classList.toggle('expanded', on);
        const icon = panel.querySelector('#aiExpand i');
        if (icon) { icon.className = 'material-symbols-outlined'; icon.textContent = on ? 'close_fullscreen' : 'open_in_full'; }
        // Keep the newest messages in view after the size jump.
        const box = document.getElementById('aiMessages');
        if (box) box.scrollTop = box.scrollHeight;
    }

    function clearHistory() {
        if (!confirm('Verlauf wirklich löschen?')) return;
        history = [];
        saveHistory();
        renderMessages();
    }

    // ----------------------------------------------------------
    // Send a chat message
    // ----------------------------------------------------------
    async function send() {
        if (isSending) return;
        const input = document.getElementById('aiInput');
        const text = input.value.trim();
        const atts = pendingAttachments.slice(); // snapshot
        if (!text && atts.length === 0) return;

        // Default the user text when only an image is sent so Gemini has
        // something to anchor on (and the chat bubble isn't empty).
        const displayText = text || (atts.length
            ? 'Hier ist ' + (atts.length === 1 ? 'eine Datei' : atts.length + ' Dateien') + '.'
            : '');

        history.push({
            role: 'user',
            content: displayText,
            ts: Date.now(),
            attachments: atts.length ? atts.map(a => ({
                name: a.name, mime: a.mime, size: a.size, dataUrl: a.dataUrl,
            })) : undefined,
        });
        input.value = '';
        input.style.height = 'auto';
        pendingAttachments = [];
        renderAttachmentChips();
        saveHistory();
        renderMessages();
        showTyping(true);
        setSending(true);

        try {
            // History sent to the API is text-only — Gemini only needs
            // the previous *summary* of past turns, not raw images again
            // (which would explode token usage).
            const historyForApi = history
                .slice(-MAX_HISTORY_FOR_API - 1, -1)
                .map(m => ({ role: m.role, content: m.content }));

            const result = await postChat(displayText, historyForApi, atts);
            const reply = (result && result.reply) ? result.reply : 'Keine Antwort.';
            const pending = (result && result.pending_actions) || [];
            history.push({
                role: 'assistant',
                content: reply,
                ts: Date.now(),
                pending_actions: pending.length ? pending.map(a => ({ ...a, status: 'pending' })) : undefined,
            });
        } catch (e) {
            const msg = (e && e.message) ? e.message : 'Unbekannter Fehler';
            history.push({ role: 'assistant', content: '⚠️ ' + msg, ts: Date.now(), isError: true });
        }

        showTyping(false);
        setSending(false);
        saveHistory();
        renderMessages();
    }

    async function postChat(message, historyForApi, attachments) {
        const body = { message, history: historyForApi };
        if (attachments && attachments.length) {
            body.attachments = attachments.map(a => ({
                name: a.name, mime: a.mime, dataUrl: a.dataUrl,
            }));
        }
        const resp = await fetch(API_BASE + '/ai/chat', {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'Accept':        'application/json',
                'Authorization': 'Bearer ' + getAuthToken(),
            },
            body: JSON.stringify(body),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) {
            const msg = (data && data.message) ? data.message
                      : (data && data.error)   ? data.error
                      : ('HTTP ' + resp.status);
            throw new Error(msg);
        }
        return data;
    }

    // ----------------------------------------------------------
    // Inline attachments — picked file → chip → sent with next /ai/chat
    // ----------------------------------------------------------
    function onFilePicked(e) {
        const files = Array.from(e.target.files || []);
        e.target.value = ''; // allow re-picking the same filename
        files.forEach(addAttachment);
    }

    function addAttachment(file) {
        if (pendingAttachments.length >= MAX_ATTACHMENTS) {
            alert(`Maximal ${MAX_ATTACHMENTS} Anhänge pro Nachricht.`);
            return;
        }
        if (file.size > MAX_ATTACH_BYTES) {
            alert(`Datei zu groß (${Math.round(file.size/1024/1024)} MB). Max ${MAX_ATTACH_BYTES/1024/1024} MB.`);
            return;
        }
        // The browser sometimes labels spreadsheet files just by
        // extension and returns an empty MIME — patch it from the name.
        let mime = file.type || '';
        const name = file.name || '';
        if (mime === '') {
            if (/\.csv$/i.test(name))       mime = 'text/csv';
            else if (/\.xlsx$/i.test(name))  mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            else if (/\.xls$/i.test(name))   mime = 'application/vnd.ms-excel';
        }
        if (!ALLOWED_MIME_PREFIXES.some(p => mime.startsWith(p))) {
            alert('Dateityp nicht unterstützt. Erlaubt: PDF, JPG, PNG, WEBP, CSV, XLSX.');
            return;
        }
        const reader = new FileReader();
        reader.onload = () => {
            // The browser may have produced `data:;base64,...` when the
            // file's type was empty (some Windows builds do this for
            // .csv files). The backend's regex requires a non-empty MIME
            // segment, so patch it in from our resolved `mime` variable.
            let dataUrl = reader.result;
            if (typeof dataUrl === 'string' && dataUrl.startsWith('data:;')) {
                dataUrl = 'data:' + mime + dataUrl.slice('data:'.length);
            }
            pendingAttachments.push({
                name: file.name,
                mime: mime,
                size: file.size,
                dataUrl: dataUrl, // "data:<mime>;base64,..."
            });
            renderAttachmentChips();
        };
        reader.onerror = () => alert('Datei konnte nicht gelesen werden.');
        reader.readAsDataURL(file);
    }

    function removeAttachmentAt(idx) {
        pendingAttachments.splice(idx, 1);
        renderAttachmentChips();
    }

    function renderAttachmentChips() {
        const wrap = document.getElementById('aiAttachments');
        if (!wrap) return;
        if (pendingAttachments.length === 0) {
            wrap.innerHTML = '';
            wrap.classList.remove('has-items');
            return;
        }
        wrap.classList.add('has-items');
        wrap.innerHTML = pendingAttachments.map((a, i) => {
            const isImg = a.mime.startsWith('image/');
            const thumb = isImg
                ? `<img src="${a.dataUrl}" alt="${escapeHtml(a.name)}">`
                : `<i class="material-symbols-outlined">picture_as_pdf</i>`;
            const sizeKB = Math.round(a.size / 1024);
            return `
                <div class="ai-att-chip">
                    <div class="ai-att-thumb">${thumb}</div>
                    <div class="ai-att-meta">
                        <strong>${escapeHtml(a.name)}</strong>
                        <span>${sizeKB} KB</span>
                    </div>
                    <button type="button" class="ai-att-remove" data-idx="${i}" title="Entfernen">
                        <i class="material-symbols-outlined">close</i>
                    </button>
                </div>
            `;
        }).join('');
        wrap.querySelectorAll('.ai-att-remove').forEach(btn => {
            btn.addEventListener('click', () => removeAttachmentAt(parseInt(btn.dataset.idx, 10)));
        });
    }

    // ----------------------------------------------------------
    // Confirm / cancel a pending write action
    // ----------------------------------------------------------
    async function onConfirmAction(actionId) {
        const turn = findActionTurn(actionId);
        if (!turn) return;
        const card = document.querySelector(`.ai-action-card[data-action-id="${cssEscape(actionId)}"]`);
        if (card) card.classList.add('busy');

        try {
            const resp = await fetch(API_BASE + '/ai/actions/' + encodeURIComponent(actionId) + '/confirm', {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + getAuthToken(), 'Accept': 'application/json' },
            });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok) {
                turn.action.status = 'failed';
                turn.action.error_message = data.message || data.error || ('HTTP ' + resp.status);
            } else {
                turn.action.status = 'executed';
                turn.action.result = data.result || null;
                // Append a friendly success message as a new turn
                const prettySuccess = describeResult(turn.action.tool_name, data.result);
                if (prettySuccess) {
                    history.push({
                        role: 'assistant',
                        content: '✅ ' + prettySuccess,
                        ts: Date.now(),
                    });
                }
            }
        } catch (e) {
            turn.action.status = 'failed';
            turn.action.error_message = e.message || 'Netzwerkfehler';
        }

        saveHistory();
        renderMessages();
    }

    async function onCancelAction(actionId) {
        const turn = findActionTurn(actionId);
        if (!turn) return;

        try {
            await fetch(API_BASE + '/ai/actions/' + encodeURIComponent(actionId), {
                method: 'DELETE',
                headers: { 'Authorization': 'Bearer ' + getAuthToken() },
            });
        } catch (e) { /* best-effort */ }

        turn.action.status = 'cancelled';
        saveHistory();
        renderMessages();
    }

    function findActionTurn(actionId) {
        for (const m of history) {
            if (!m.pending_actions) continue;
            for (const a of m.pending_actions) {
                if (a.action_id === actionId) return { msg: m, action: a };
            }
        }
        return null;
    }

    function describeResult(tool, result) {
        if (!result || typeof result !== 'object') return '';
        switch (tool) {
            case 'create_worker':
                return `Mitarbeiter ${result.dienst_nr || ''} angelegt. Initial-Passwort: ${result.initial_password || '(siehe Mitarbeiter-Detail)'}.`;
            case 'create_kunde':
                return `Kunde ${result.kunde_id || ''} angelegt.`;
            case 'create_objekt':
                return `Objekt ${result.obj_id || ''} angelegt.`;
            case 'create_artikel':
                return `Artikel ${result.artikel_id || ''} angelegt.`;
            case 'create_einsatz':
                return `Einsatz ${result.einsatz_id || ''} angelegt.`;
            case 'assign_mitarbeiter':
                return `Mitarbeiter ${result.dienst_nr || ''} dem Einsatz ${result.einsatz_id || ''} zugewiesen.`;
            case 'remove_mitarbeiter':
                return `Mitarbeiter ${result.dienst_nr || ''} vom Einsatz ${result.einsatz_id || ''} entfernt`
                    + (result.benachrichtigt ? ' (Mitarbeiter wurde benachrichtigt).' : '.');
            case 'update_anforderungen':
                return `Anforderungen aktualisiert (${result.updated_tage || 0} Tage, ${result.updated_einsaetze || 0} Einsätze).`;
            case 'update_worker':
            case 'update_kunde':
            case 'update_einsatz_zeit':
                return 'Daten aktualisiert.';
            default:
                return 'Aktion ausgeführt.';
        }
    }

    // ----------------------------------------------------------
    // Rendering
    // ----------------------------------------------------------
    function renderMessages() {
        const box = document.getElementById('aiMessages');
        if (!box) return;
        if (history.length === 0) {
            box.innerHTML = renderQuickPrompts();
            bindQuickPrompts(box);
            return;
        }
        box.innerHTML = history.map((m, idx) => {
            const cls  = m.isError ? 'error' : m.role;
            const atts = (m.attachments || []).map(a => {
                if (a.mime && a.mime.startsWith('image/') && a.dataUrl) {
                    return `<a class="ai-msg-img" href="${a.dataUrl}" target="_blank" rel="noopener"><img src="${a.dataUrl}" alt="${escapeHtml(a.name)}"></a>`;
                }
                return `<div class="ai-msg-file"><i class="material-symbols-outlined">picture_as_pdf</i> ${escapeHtml(a.name)}</div>`;
            }).join('');
            // Assistant replies are rendered as Markdown (headings, tables,
            // lists, bold, code, …). User messages and error bubbles stay
            // plain-text-escaped so a user pasting `<script>` or markdown
            // syntax doesn't get unexpected formatting.
            const isAssistant = m.role === 'assistant' && !m.isError;
            const rendered = isAssistant
                ? `<div class="ai-msg-text ai-md">${renderMarkdown(m.content || '')}</div>`
                : (m.content ? `<div class="ai-msg-text">${escapeHtml(m.content)}</div>` : '');
            const body = (atts ? atts : '') + rendered;
            // Feedback row (👍/👎) under every real assistant reply — not on
            // error bubbles. Once rated, the chosen side stays highlighted.
            const feedback = isAssistant ? renderFeedbackRow(m, idx) : '';
            const text = `<div class="ai-msg ${cls}${atts ? ' has-att' : ''}">${body}${feedback}</div>`;
            if (m.pending_actions && Array.isArray(m.pending_actions)) {
                return text + m.pending_actions.map(a => renderActionCard(a)).join('');
            }
            return text;
        }).join('');
        box.scrollTop = box.scrollHeight;

        box.querySelectorAll('.ai-action-confirm').forEach(btn => {
            btn.addEventListener('click', () => onConfirmAction(btn.dataset.actionId));
        });
        box.querySelectorAll('.ai-action-cancel').forEach(btn => {
            btn.addEventListener('click', () => onCancelAction(btn.dataset.actionId));
        });
        box.querySelectorAll('.ai-fb-btn').forEach(btn => {
            btn.addEventListener('click', () =>
                onFeedback(parseInt(btn.dataset.idx, 10), btn.dataset.rating));
        });
    }

    // ----------------------------------------------------------
    // Quick-start prompts (empty chat state)
    // ----------------------------------------------------------
    function renderQuickPrompts() {
        const chips = QUICK_PROMPTS.map((q, i) =>
            `<button type="button" class="ai-quick-chip" data-qi="${i}">
                <i class="material-symbols-outlined">${q.icon}</i> ${escapeHtml(q.label)}
            </button>`
        ).join('');
        return `
            <div class="ai-welcome">
                <div class="ai-welcome-title">Wie kann ich helfen?</div>
                <div class="ai-quick-chips">${chips}</div>
            </div>
        `;
    }

    function bindQuickPrompts(box) {
        box.querySelectorAll('.ai-quick-chip').forEach(btn => {
            btn.addEventListener('click', () => {
                const q = QUICK_PROMPTS[parseInt(btn.dataset.qi, 10)];
                if (!q) return;
                const input = document.getElementById('aiInput');
                input.value = q.text;
                send();
            });
        });
    }

    // ----------------------------------------------------------
    // Feedback (👍/👎) on assistant replies
    // ----------------------------------------------------------
    function renderFeedbackRow(m, idx) {
        const rated = m.feedback || '';
        if (rated) {
            const icon = rated === 'up' ? 'thumb_up' : 'thumb_down';
            return `<div class="ai-feedback rated"><i class="material-symbols-outlined">${icon}</i> Danke für dein Feedback</div>`;
        }
        return `
            <div class="ai-feedback">
                <button type="button" class="ai-fb-btn" data-idx="${idx}" data-rating="up" title="Hilfreich"><i class="material-symbols-outlined">thumb_up</i></button>
                <button type="button" class="ai-fb-btn" data-idx="${idx}" data-rating="down" title="Nicht hilfreich"><i class="material-symbols-outlined">thumb_down</i></button>
            </div>
        `;
    }

    async function onFeedback(idx, rating) {
        const m = history[idx];
        if (!m || m.role !== 'assistant' || m.feedback) return;
        m.feedback = rating;

        // Optional short comment on a downvote — helps triage bad answers.
        let comment = null;
        if (rating === 'down') {
            comment = prompt('Was war an der Antwort nicht hilfreich? (optional)') || null;
        }

        // Question = nearest preceding user turn.
        let question = '';
        for (let i = idx - 1; i >= 0; i--) {
            if (history[i].role === 'user') { question = history[i].content || ''; break; }
        }
        const toolsUsed = (m.pending_actions || []).map(a => a.tool_name).filter(Boolean);

        saveHistory();
        renderMessages();

        // Fire-and-forget — telemetry must never disturb the chat.
        try {
            await fetch(API_BASE + '/ai/feedback', {
                method: 'POST',
                headers: {
                    'Content-Type':  'application/json',
                    'Accept':        'application/json',
                    'Authorization': 'Bearer ' + getAuthToken(),
                },
                body: JSON.stringify({
                    rating,
                    comment,
                    question: question.slice(0, 1000),
                    answer: (m.content || '').slice(0, 2000),
                    tools_used: toolsUsed.length ? toolsUsed : undefined,
                }),
            });
        } catch (e) { /* best-effort */ }
    }

    function renderActionCard(action) {
        if (action.status === 'executed') {
            const desc = describeResult(action.tool_name, action.result);
            return `<div class="ai-action-card done"><i class="material-symbols-outlined">check_circle</i> ${escapeHtml(desc || 'Aktion ausgeführt.')}</div>`;
        }
        if (action.status === 'cancelled') {
            return `<div class="ai-action-card cancelled"><i class="material-symbols-outlined">cancel</i> Aktion abgebrochen.</div>`;
        }
        if (action.status === 'failed') {
            return `<div class="ai-action-card failed"><i class="material-symbols-outlined">warning</i> Fehlgeschlagen: ${escapeHtml(action.error_message || 'Unbekannter Fehler')}</div>`;
        }
        const p = action.preview || {};
        const title = p.titel || WRITE_TOOL_LABELS[action.tool_name] || action.tool_name;
        const rows = (p.felder || []).map(([k, v]) =>
            `<div class="ai-action-row"><span>${escapeHtml(k)}</span><strong>${escapeHtml(String(v ?? '—'))}</strong></div>`
        ).join('');

        // Optional `table` block for bulk previews (e.g. bulk_create_einsaetze).
        // The backend ships `{ columns: [...], rows: [[..],[..],...] }`.
        let tableHtml = '';
        if (p.table && Array.isArray(p.table.columns) && Array.isArray(p.table.rows)) {
            const head = p.table.columns.map(c => `<th>${escapeHtml(String(c))}</th>`).join('');
            const body = p.table.rows.map(r =>
                '<tr>' + r.map(c => `<td>${escapeHtml(String(c ?? ''))}</td>`).join('') + '</tr>'
            ).join('');
            tableHtml = `<div class="ai-action-table-wrap"><table class="ai-action-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
        }

        const hint = p.hinweis ? `<div class="ai-action-hint">${escapeHtml(p.hinweis)}</div>` : '';
        return `
            <div class="ai-action-card pending" data-action-id="${escapeHtml(action.action_id)}">
                <div class="ai-action-card-title"><i class="material-symbols-outlined">bolt</i> ${escapeHtml(title)}</div>
                <div class="ai-action-card-body">${rows}</div>
                ${tableHtml}
                ${hint}
                <div class="ai-action-card-buttons">
                    <button type="button" class="ai-action-cancel" data-action-id="${escapeHtml(action.action_id)}">
                        <i class="material-symbols-outlined">close</i> Abbrechen
                    </button>
                    <button type="button" class="ai-action-confirm" data-action-id="${escapeHtml(action.action_id)}">
                        <i class="material-symbols-outlined">check</i> Bestätigen
                    </button>
                </div>
            </div>
        `;
    }

    function showTyping(on) {
        const box = document.getElementById('aiMessages');
        if (!box) return;
        let el = box.querySelector('.ai-msg.typing');
        if (on && !el) {
            el = document.createElement('div');
            el.className = 'ai-msg typing';
            el.innerHTML = `<span class="ai-dot"></span><span class="ai-dot"></span><span class="ai-dot"></span>`;
            box.appendChild(el);
            box.scrollTop = box.scrollHeight;
        } else if (!on && el) {
            el.remove();
        }
    }

    function setSending(on) {
        isSending = on;
        const btn = document.getElementById('aiSend');
        const input = document.getElementById('aiInput');
        const attach = document.getElementById('aiAttach');
        if (btn)    btn.disabled    = on;
        if (input)  input.disabled  = on;
        if (attach) attach.disabled = on;
    }

    // Trim attachment dataUrls from saved history (localStorage quota +
    // we already have a thumbnail-sized inline render). Keeps the
    // metadata so the bubble can still show "📎 file.pdf".
    function stripAttachmentsForStorage(items) {
        return items.map(m => {
            if (!m.attachments) return m;
            return {
                ...m,
                attachments: m.attachments.map(a => ({
                    name: a.name, mime: a.mime, size: a.size,
                    // keep small images inline; drop large/PDF blobs
                    dataUrl: (a.mime && a.mime.startsWith('image/') && a.size <= 512 * 1024) ? a.dataUrl : null,
                })),
            };
        });
    }

    // ----------------------------------------------------------
    // Persistence (localStorage, scoped by user)
    // ----------------------------------------------------------
    function readUserKey() {
        try {
            const u = JSON.parse(localStorage.getItem('hms_user') || '{}');
            const t = JSON.parse(localStorage.getItem('hms_tenant') || '{}');
            const uid = u && u.id ? u.id : 'u';
            const tid = t && t.id ? t.id : 't';
            return STORAGE_KEY_PREFIX + tid + '_' + uid;
        } catch (e) {
            return STORAGE_KEY_PREFIX + 'anon';
        }
    }
    function purgeLegacyAnonBucket() {
        try { localStorage.removeItem(STORAGE_KEY_PREFIX + 'anon'); }
        catch (e) { /* ignore */ }
    }
    function loadHistory() {
        try {
            const raw = localStorage.getItem(userKey);
            if (!raw) return [];
            const arr = JSON.parse(raw);
            return Array.isArray(arr) ? arr.slice(-40) : [];
        } catch (e) { return []; }
    }
    function saveHistory() {
        try {
            const trimmed = stripAttachmentsForStorage(history.slice(-40));
            localStorage.setItem(userKey, JSON.stringify(trimmed));
        } catch (e) { /* ignore quota errors */ }
    }

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ----------------------------------------------------------
    // Safe Markdown → HTML rendering for assistant replies
    // ----------------------------------------------------------
    // Uses `marked` (GitHub-flavoured: tables, lists, code fences,
    // strikethrough, autolinks) and runs the result through
    // `DOMPurify` with a strict allow-list so a creative LLM reply
    // cannot smuggle <script>, <iframe>, or javascript:-URLs into
    // the chat panel.
    //
    // If either library hasn't loaded yet (e.g. very fast first
    // render under a slow CDN), we gracefully fall back to escaped
    // text with line breaks preserved, so the UI never blocks.
    const MD_ALLOWED_TAGS = [
        'h1','h2','h3','h4','h5','h6',
        'p','br','hr',
        'strong','em','b','i','u','s','del','ins','mark',
        'ul','ol','li','blockquote',
        'code','pre','kbd','samp',
        'table','thead','tbody','tr','th','td',
        'a','span','div',
    ];
    const MD_ALLOWED_ATTR = ['href','title','target','rel','align'];

    // GFM tables are block-level: marked only detects them when there's a
    // blank line BEFORE the header row. LLM replies often glue the table
    // straight onto the preceding sentence ("Hier sind die Daten:\n|…|"),
    // which breaks detection. We pre-process by injecting an empty line
    // before a `|…|` row that's preceded by non-table text, and after the
    // last `|…|` row before a non-table line. Idempotent: already-blank
    // surroundings stay untouched.
    function normaliseMarkdown(text) {
        return text
            // Blank line BEFORE a pipe row when preceded by non-pipe, non-blank text
            .replace(/([^\n|])\n(\|[^\n]*\|)/g, '$1\n\n$2')
            // Blank line AFTER a pipe row when followed by non-pipe, non-blank text
            .replace(/(\|[^\n]*\|)\n([^\n|\s])/g, '$1\n\n$2');
    }

    function renderMarkdown(src) {
        const text = String(src ?? '');
        if (!text.trim()) return '';
        // Defensive: log once if the CDN libs didn't load (ad-blocker /
        // CSP / offline). The fallback below still keeps the chat usable.
        if (typeof window.marked === 'undefined' || typeof window.marked.parse !== 'function') {
            if (!window.__ai_md_warned) {
                window.__ai_md_warned = true;
                console.warn('[AI] marked.js not available — falling back to plain text rendering.');
            }
            return escapeHtml(text).replace(/\n/g, '<br>');
        }
        try {
            const raw = window.marked.parse(normaliseMarkdown(text), {
                breaks: true,       // \n inside a paragraph → <br>
                gfm: true,          // tables, strikethrough, autolinks
                headerIds: false,   // no auto-generated id attrs (XSS surface)
                mangle: false,      // don't obfuscate email addresses
            });
            const clean = (typeof window.DOMPurify !== 'undefined')
                ? window.DOMPurify.sanitize(raw, {
                    ALLOWED_TAGS: MD_ALLOWED_TAGS,
                    ALLOWED_ATTR: MD_ALLOWED_ATTR,
                })
                : raw;
            // Force every link to open safely in a new tab.
            return clean.replace(/<a\s+/gi, '<a target="_blank" rel="noopener noreferrer" ');
        } catch (e) {
            console.warn('[AI] markdown render failed:', e);
            return escapeHtml(text).replace(/\n/g, '<br>');
        }
    }
    function cssEscape(s) {
        return String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }
})();
