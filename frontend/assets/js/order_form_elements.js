/* =====================================================================
   order_form_elements.js — Free-form element editor
   ─────────────────────────────────────────────────────────────────────
   - Insert: Linie, Rechteck, Textbox, Tabelle, Bild
   - Drag any element (existing or inserted) when edit mode is ON
   - Resize via 8 handles
   - Delete via overlay button
   - Z-order: bring to front / send to back
   - Serialize/deserialize for snapshots
===================================================================== */

window.FE = (() => {
    const state = {
        elements: new Map(),   // id → element data
        selected: null,
        editMode: false,
        page: null,            // #orderForm element
        draggedElement: null,
        dragOffset: { x: 0, y: 0 },
        resizing: null,
        resizeStart: null,
        nextZ: 100,
    };

    let pageRect = null;       // cached page bounds during drag
    let pageScale = 1;         // fit-to-viewport scale of .page during drag

    // The sheet may be shrunk with transform:scale() on narrow screens
    // (order_form.js fitPageToViewport). Element coordinates are stored in
    // unscaled document px, so every client-px delta is divided by it.
    function currentScale() {
        const m = state.page && (state.page.style.transform || '').match(/scale\(([\d.]+)\)/);
        return m ? (parseFloat(m[1]) || 1) : 1;
    }

    // ═══════════════════════════════════════════════════════════════
    // INIT
    // ═══════════════════════════════════════════════════════════════
    function init(pageEl) {
        state.page = pageEl;
        // Ensure page is positioning context
        if (getComputedStyle(pageEl).position === 'static') pageEl.style.position = 'relative';

        // Listeners for drag/resize/click-to-select. Pointer events cover
        // mouse AND touch/pen (iPad, phones) — mouse events never fire for
        // a touch drag.
        document.addEventListener('pointerdown',   onMouseDown);
        document.addEventListener('pointermove',   onMouseMove);
        document.addEventListener('pointerup',     onMouseUp);
        document.addEventListener('pointercancel', onMouseUp);
        document.addEventListener('keydown',   onKeyDown);

        // Click anywhere outside elements deselects
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.floating-element') && !e.target.closest('.insert-menu')
                && !e.target.closest('.fe-inspector') && !e.target.closest('.fe-context')) {
                deselect();
            }
        });
    }

    function setEditMode(on) {
        state.editMode = !!on;
        state.page.classList.toggle('fe-edit', on);
        if (!on) deselect();
        // Update visibility of element handles/decorations
        state.page.querySelectorAll('.floating-element').forEach(el => {
            el.classList.toggle('fe-locked', !on);
        });
        // Textbox text + table cells are only editable in edit mode.
        state.page.querySelectorAll('.floating-element .fe-text, .floating-element.fe-table th, .floating-element.fe-table td')
            .forEach(c => { c.contentEditable = on ? 'true' : 'false'; });
    }

    // ═══════════════════════════════════════════════════════════════
    // CREATE / INSERT
    // ═══════════════════════════════════════════════════════════════
    function insert(type, opts = {}) {
        if (!state.page) return null;
        const id = 'fe_' + Math.random().toString(36).slice(2, 10);
        const s = currentScale();
        const pr = state.page.getBoundingClientRect();
        const pageRect = { width: pr.width / s, top: pr.top / s };
        const defaults = {
            line: { width: Math.min(400, pageRect.width - 100), height: 2, style: { color: '#1e293b', thickness: 2, orientation: 'horizontal' } },
            rect: { width: 180, height: 100, style: { fill: 'transparent', border: '2px solid #1a2744', radius: 6 } },
            textbox: { width: 220, height: 70, style: { fontSize: 14, color: '#1e293b', fontWeight: '400', textAlign: 'left' }, content: TEXT_PLACEHOLDER },
            table: { width: 320, height: 120, style: { rows: 3, cols: 3, headerBg: '#1a2744', headerColor: '#ffffff', borderColor: '#e2e8f0' }, content: null },
            image: { width: 160, height: 110, style: {}, content: opts.dataUrl || null },
        };
        const d = defaults[type];
        if (!d) return null;
        const w = opts.width ?? d.width, h = opts.height ?? d.height;
        // Drop new elements into the middle of the visible part of the sheet
        // (below the fixed top bar); repeated inserts step down-right so
        // they never land exactly on top of each other.
        const barBottom = (document.querySelector('.topbar') || { getBoundingClientRect: () => ({ bottom: 0 }) }).getBoundingClientRect().bottom;
        const visTop = (Math.max(pr.top, barBottom) - pr.top) / s;
        const visBot = (Math.min(window.innerHeight, pr.bottom) - pr.top) / s;
        let x = opts.x ?? Math.round(pageRect.width / 2 - w / 2);
        let y = opts.y ?? Math.round(Math.max(20, visBot > visTop ? (visTop + visBot) / 2 - h / 2 : visTop + 40));
        if (opts.x == null && opts.y == null) {
            const taken = (px, py) => [...state.elements.values()].some(({ data: o }) => Math.abs(o.x - px) < 10 && Math.abs(o.y - py) < 10);
            for (let i = 0; i < 20 && taken(x, y); i++) { x += 24; y += 24; }
        }

        record();
        const data = {
            id,
            type,
            x, y,
            width: w,
            height: h,
            zIndex: state.nextZ++,
            style: { ...d.style, ...(opts.style || {}) },
            content: opts.content ?? d.content,
        };

        const el = renderElement(data);
        state.page.appendChild(el);
        state.elements.set(id, { data, el });
        select(id);
        // New textbox: ready to type — the placeholder is selected.
        if (type === 'textbox') focusText(el, true);
        return id;
    }

    const TEXT_PLACEHOLDER = 'Klicke hier zum Bearbeiten';

    // Put the caret into a textbox; selectAll also when only the
    // placeholder is there, so typing replaces it.
    function focusText(el, selectAll) {
        const t = el && el.querySelector('.fe-text');
        if (!t || t.contentEditable !== 'true') return;
        t.focus({ preventScroll: true });
        if (selectAll || t.textContent.trim() === TEXT_PLACEHOLDER) {
            const r = document.createRange();
            r.selectNodeContents(t);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(r);
        }
    }

    function renderElement(data) {
        // Vertical lines used to be stored as a 2×2 px dot (insert ignored
        // the requested size) — give such a line its intended length.
        if (data.type === 'line' && (data.style || {}).orientation === 'vertical' && data.height < 20) {
            data.height = 140;
            data.width = data.style.thickness || 2;
        }
        const div = document.createElement('div');
        div.className = 'floating-element fe-' + data.type + (state.editMode ? '' : ' fe-locked');
        div.dataset.feId = data.id;
        div.dataset.feType = data.type;
        Object.assign(div.style, {
            position: 'absolute',
            left:   data.x + 'px',
            top:    data.y + 'px',
            width:  data.width + 'px',
            height: data.height + 'px',
            zIndex: data.zIndex,
        });

        applyTypeStyle(div, data);
        renderInner(div, data);
        attachOverlay(div, data);
        return div;
    }

    function applyTypeStyle(div, data) {
        const s = data.style || {};
        switch (data.type) {
            case 'line':
                div.dataset.orient = s.orientation === 'vertical' ? 'v' : 'h';
                if (s.orientation === 'vertical') {
                    div.style.background = s.color || '#1e293b';
                    div.style.width = (s.thickness || 2) + 'px';
                } else {
                    div.style.background = s.color || '#1e293b';
                    div.style.height = (s.thickness || 2) + 'px';
                }
                div.style.borderRadius = '1px';
                break;
            case 'rect':
                div.style.background = s.fill || 'transparent';
                div.style.border = s.border || '2px solid #1a2744';
                div.style.borderRadius = (s.radius ?? 6) + 'px';
                if (s.opacity != null) div.style.opacity = s.opacity;
                break;
            case 'textbox':
                div.style.fontSize = (s.fontSize || 14) + 'px';
                div.style.color = s.color || '#1e293b';
                div.style.fontWeight = s.fontWeight || '400';
                div.style.fontStyle = s.fontStyle || 'normal';
                div.style.textAlign = s.textAlign || 'left';
                div.style.padding = '8px 12px';
                div.style.background = s.background || 'transparent';
                div.style.border = s.border || 'none';
                div.style.borderRadius = (s.radius ?? 4) + 'px';
                if (s.fontFamily) div.style.fontFamily = s.fontFamily;
                break;
            case 'table':
            case 'image':
                // no outer style needed
                break;
        }
    }

    function renderInner(div, data) {
        div.innerHTML = '';
        switch (data.type) {
            case 'textbox': {
                // The text lives in its own editable child — the resize
                // handles + toolbar are siblings, so typing / Select-All can
                // never delete them, and the padding stays a grab zone.
                const t = document.createElement('div');
                t.className = 'fe-text';
                t.contentEditable = state.editMode ? 'true' : 'false';
                // Template content is sanitised server-side too (PdfHtmlSanitizer).
                t.innerHTML = window.DOMPurify
                    ? window.DOMPurify.sanitize(data.content || 'Text...', { ALLOWED_TAGS: ['b', 'strong', 'i', 'em', 'u', 'br', 'span', 'div', 'p', 'font', 'sup', 'sub'], ALLOWED_ATTR: ['style'] })
                    : (data.content || 'Text...');
                t.addEventListener('input', () => { data.content = t.innerHTML; });
                // Deferred: a mouse click places its caret after the focus event.
                t.addEventListener('focus', () => setTimeout(() => {
                    if (document.activeElement === t && t.textContent.trim() === TEXT_PLACEHOLDER) focusText(div, true);
                }, 0));
                div.appendChild(t);
                break;
            }
            case 'table':
                div.appendChild(buildTable(data));
                break;
            case 'image':
                if (data.content) {
                    const img = document.createElement('img');
                    img.src = data.content;
                    Object.assign(img.style, { width: '100%', height: '100%', objectFit: 'contain', display: 'block', pointerEvents: 'none' });
                    div.appendChild(img);
                }
                break;
            // line + rect have no inner content
        }
    }

    function buildTable(data) {
        const s = data.style;
        const rows = s.rows || 3, cols = s.cols || 3;
        const cellData = Array.isArray(data.content) ? data.content : [];
        const table = document.createElement('table');
        Object.assign(table.style, {
            width: '100%', height: '100%', borderCollapse: 'collapse',
            fontSize: '0.85rem', background: '#fff',
        });
        const syncCells = () => {
            const out = [];
            table.querySelectorAll('tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('th,td').forEach(c => row.push(c.textContent));
                out.push(row);
            });
            data.content = out;
        };
        for (let r = 0; r < rows; r++) {
            const tr = document.createElement('tr');
            for (let c = 0; c < cols; c++) {
                const cell = document.createElement(r === 0 ? 'th' : 'td');
                const val = (cellData[r] && cellData[r][c]) ?? (r === 0 ? `Spalte ${c+1}` : '');
                cell.textContent = val;
                cell.contentEditable = state.editMode ? 'true' : 'false';
                Object.assign(cell.style, {
                    border: '1px solid ' + (s.borderColor || '#e2e8f0'),
                    padding: '6px 8px',
                    textAlign: 'left',
                });
                if (r === 0) {
                    cell.style.background = s.headerBg || '#1a2744';
                    cell.style.color = s.headerColor || '#ffffff';
                    cell.style.fontWeight = '700';
                }
                // Live-sync cell content into data.content so resizing the table later
                // (rows/cols change via Inspector) preserves the user's text
                cell.addEventListener('input', syncCells);
                cell.addEventListener('blur',  syncCells);
                tr.appendChild(cell);
            }
            table.appendChild(tr);
        }
        // Run once so data.content is populated immediately after build
        setTimeout(syncCells, 0);
        return table;
    }

    function attachOverlay(div, data) {
        // Add resize handles (only visible when selected + edit mode)
        const handles = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
        handles.forEach(h => {
            const dot = document.createElement('div');
            dot.className = 'fe-handle fe-handle-' + h;
            dot.dataset.dir = h;
            div.appendChild(dot);
        });

        // Toolbar (appears above element when selected)
        const tb = document.createElement('div');
        tb.className = 'fe-toolbar';
        tb.innerHTML = `
            <button data-act="move" class="fe-grip" title="Gedrückt halten und ziehen zum Verschieben"><i class="material-symbols-outlined">drag_pan</i></button>
            <button data-act="up" title="Nach oben (Z+)"><i class="material-symbols-outlined">arrow_upward</i></button>
            <button data-act="down" title="Nach unten (Z-)"><i class="material-symbols-outlined">arrow_downward</i></button>
            <button data-act="dup" title="Duplizieren"><i class="material-symbols-outlined">content_copy</i></button>
            <button data-act="del" class="danger" title="Löschen"><i class="material-symbols-outlined">delete</i></button>
        `;
        div.appendChild(tb);
        tb.addEventListener('click', (e) => {
            const btn = e.target.closest('button');
            if (!btn) return;
            e.stopPropagation();
            const act = btn.dataset.act;
            if (act === 'del') remove(data.id);
            else if (act === 'dup') duplicate(data.id);
            else if (act === 'up')   bringForward(data.id);
            else if (act === 'down') sendBackward(data.id);
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // SELECTION
    // ═══════════════════════════════════════════════════════════════
    function select(id) {
        deselect();
        const item = state.elements.get(id);
        if (!item) return;
        item.el.classList.add('selected');
        state.selected = id;
        if (window.openInspector) window.openInspector(item.data);
    }
    function deselect() {
        if (state.selected) {
            const item = state.elements.get(state.selected);
            if (item) item.el.classList.remove('selected');
            state.selected = null;
        }
        if (window.closeInspector) window.closeInspector();
    }

    // ═══════════════════════════════════════════════════════════════
    // DRAG / RESIZE — pointer events on document
    // ═══════════════════════════════════════════════════════════════
    function onMouseDown(e) {
        if (!state.editMode) return;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        // Signature/stamp assets have their own pointer-based drag layer
        // (order_form.js) — never treat a press inside them as an FE drag
        // of the [data-draggable] signature-block parent.
        if (e.target.closest('.doc-asset-wrap')) return;
        const handle = e.target.closest('.fe-handle');
        const inToolbar = e.target.closest('.fe-toolbar');
        const fe = e.target.closest('.floating-element');
        const draggableExisting = e.target.closest('[data-draggable]');

        const grip = e.target.closest('.fe-grip');

        if (handle && fe) {
            // Start resize
            e.preventDefault(); e.stopPropagation();
            const id = fe.dataset.feId;
            const item = state.elements.get(id);
            if (!item) return;
            beginChange();
            item.data.anchor = null;
            state.resizing = { id, dir: handle.dataset.dir };
            state.resizeStart = {
                mouseX: e.clientX, mouseY: e.clientY,
                x: item.data.x, y: item.data.y,
                w: item.data.width, h: item.data.height,
            };
            pageRect = state.page.getBoundingClientRect();
            pageScale = currentScale();
            return;
        }

        // Move grip in the element toolbar: always drags, even a textbox
        // that is being typed in.
        if (grip && fe) {
            beginChange();
            startDrag(e, fe);
            return;
        }

        if (inToolbar) return; // let button handler run

        if (fe) {
            const id = fe.dataset.feId;
            if (state.selected !== id) select(id);
            // If click landed on/inside a contenteditable child (textbox text, table cell)
            // → defer drag until pointer moves >5px, so a plain click still focuses for editing.
            // While that text is already being edited, a drag selects text instead
            // (move it with the grip or the padding around the text).
            const editableChild = e.target.closest('[contenteditable="true"]');
            if (editableChild && editableChild.contains(document.activeElement)) return;
            beginChange();
            if (editableChild) {
                state.potentialDrag = { el: fe, startX: e.clientX, startY: e.clientY };
            } else {
                startDrag(e, fe);
            }
            return;
        }

        // Drag existing document element (logo, company-name, etc.).
        // Always deferred until the pointer moves >5px: a plain click must
        // never detach an original from the flow (a click on the logo used
        // to pin it absolutely, after which "Logo-Position" had no effect).
        if (draggableExisting) {
            const editableChild = e.target.closest('[contenteditable="true"]');
            if (editableChild && editableChild.contains(document.activeElement)) return;
            // No native image drag — but a press on an editable label inside a
            // movable block must still place the caret.
            if (!editableChild) e.preventDefault();
            beginChange();
            state.potentialDrag = { el: draggableExisting, startX: e.clientX, startY: e.clientY, existing: true };
        }
    }

    // The user placed it by hand → a stored flow anchor must not move it later.
    function dropAnchor(el) {
        const item = el && el.dataset.feId && state.elements.get(el.dataset.feId);
        if (item) item.data.anchor = null;
        if (el) { delete el.dataset.anchorA; delete el.dataset.anchorDy; }
    }

    function startDrag(e, el) {
        e.preventDefault();
        dropAnchor(el);
        state.draggedElement = el;
        pageScale = currentScale();
        const r = el.getBoundingClientRect();
        state.dragOffset = { x: (e.clientX - r.left) / pageScale, y: (e.clientY - r.top) / pageScale };
        // Coordinates are relative to the element's positioning ancestor
        // (the page for inserted elements).
        pageRect = (el.offsetParent || state.page).getBoundingClientRect();
        el.classList.add('fe-dragging');
    }

    function startDragExisting(e, el) {
        // First time: convert to absolute positioning (doc px, unscaled),
        // measured against the element's own positioning ancestor so the
        // stored coords re-apply identically after a re-render.
        if (!el.dataset.feFloated) {
            const s = currentScale();
            const r = el.getBoundingClientRect();
            const pr = (el.offsetParent || state.page).getBoundingClientRect();
            el.dataset.feFloated = '1';
            el.style.position = 'absolute';
            el.style.left = ((r.left - pr.left) / s) + 'px';
            el.style.top = ((r.top - pr.top) / s) + 'px';
            el.style.width = (r.width / s) + 'px';
            el.style.zIndex = state.nextZ++;
            el.style.margin = '0';
        }
        startDrag(e, el);
    }

    function onMouseMove(e) {
        // Promote potential drag to real drag once movement exceeds 5px
        if (state.potentialDrag) {
            const dx = e.clientX - state.potentialDrag.startX;
            const dy = e.clientY - state.potentialDrag.startY;
            if (Math.sqrt(dx*dx + dy*dy) > 5) {
                const pd = state.potentialDrag;
                state.potentialDrag = null;
                // Grab offset from the press point, so the element doesn't
                // jump by the 5px threshold.
                const at = { clientX: pd.startX, clientY: pd.startY, preventDefault() {} };
                if (pd.existing) startDragExisting(at, pd.el);
                else startDrag(at, pd.el);
                try { window.getSelection().removeAllRanges(); } catch (_) {}
            }
            return;
        }

        if (state.resizing) {
            const { id, dir } = state.resizing;
            const item = state.elements.get(id);
            if (!item) return;
            const s = state.resizeStart;
            const dx = (e.clientX - s.mouseX) / pageScale;
            const dy = (e.clientY - s.mouseY) / pageScale;
            let nx = s.x, ny = s.y, nw = s.w, nh = s.h;
            if (dir.includes('e')) nw = Math.max(20, s.w + dx);
            if (dir.includes('s')) nh = Math.max(8, s.h + dy);
            if (dir.includes('w')) { nw = Math.max(20, s.w - dx); nx = s.x + (s.w - nw); }
            if (dir.includes('n')) { nh = Math.max(8, s.h - dy); ny = s.y + (s.h - nh); }
            // A line only changes its length; its thickness is a style.
            if (item.data.type === 'line') {
                if ((item.data.style || {}).orientation === 'vertical') { nw = s.w; nx = s.x; }
                else { nh = s.h; ny = s.y; }
            }
            item.data.x = nx; item.data.y = ny; item.data.width = nw; item.data.height = nh;
            Object.assign(item.el.style, { left: nx+'px', top: ny+'px', width: nw+'px', height: nh+'px' });
            if (item.data.type === 'line') applyTypeStyle(item.el, item.data);
            return;
        }

        if (state.draggedElement) {
            const newX = (e.clientX - pageRect.left) / pageScale - state.dragOffset.x;
            const newY = (e.clientY - pageRect.top) / pageScale - state.dragOffset.y;
            state.draggedElement.style.left = newX + 'px';
            state.draggedElement.style.top  = newY + 'px';
            // If it's a tracked floating element, sync data
            const id = state.draggedElement.dataset.feId;
            if (id && state.elements.has(id)) {
                const item = state.elements.get(id);
                item.data.x = newX;
                item.data.y = newY;
            }
        }
    }

    function onMouseUp() {
        if (state.draggedElement) {
            state.draggedElement.classList.remove('fe-dragging');
            state.draggedElement = null;
        }
        state.resizing = null;
        state.resizeStart = null;
        state.potentialDrag = null;
        endChange();
    }

    function onKeyDown(e) {
        if (!state.editMode) return;
        // ignore while typing — contenteditable text OR a form field (the
        // Inspector's X/Y/size inputs: Backspace there must edit the
        // number, not delete the element)
        const ae = document.activeElement;
        if (ae && (ae.isContentEditable || /^(INPUT|TEXTAREA|SELECT|MD-OUTLINED-TEXT-FIELD|MD-FILLED-TEXT-FIELD|MD-OUTLINED-SELECT|MD-FILLED-SELECT|MD-CHECKBOX|MD-RADIO)$/.test(ae.tagName))) return;
        const mod = e.ctrlKey || e.metaKey;
        if (mod && (e.key === 'z' || e.key === 'Z')) { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
        if (mod && (e.key === 'y' || e.key === 'Y')) { e.preventDefault(); redo(); return; }
        if (!state.selected) return;
        const item = state.elements.get(state.selected);
        if (!item) return;
        const step = e.shiftKey ? 10 : 1;
        if (e.key.startsWith('Arrow')) { record('nudge:' + item.data.id); item.data.anchor = null; }
        let handled = true;
        switch (e.key) {
            case 'ArrowLeft':  item.data.x -= step; item.el.style.left = item.data.x + 'px'; break;
            case 'ArrowRight': item.data.x += step; item.el.style.left = item.data.x + 'px'; break;
            case 'ArrowUp':    item.data.y -= step; item.el.style.top  = item.data.y + 'px'; break;
            case 'ArrowDown':  item.data.y += step; item.el.style.top  = item.data.y + 'px'; break;
            case 'Delete':
            case 'Backspace':  remove(state.selected); break;
            case 'd':
                if (e.ctrlKey || e.metaKey) { e.preventDefault(); duplicate(state.selected); }
                else handled = false;
                break;
            default: handled = false;
        }
        if (handled) e.preventDefault();
    }

    // ═══════════════════════════════════════════════════════════════
    // OPERATIONS
    // ═══════════════════════════════════════════════════════════════
    function remove(id) {
        const item = state.elements.get(id);
        if (!item) return;
        record();
        item.el.remove();
        state.elements.delete(id);
        if (state.selected === id) state.selected = null;
        if (window.closeInspector) window.closeInspector();
    }

    function duplicate(id) {
        const item = state.elements.get(id);
        if (!item) return;
        record();
        const copy = JSON.parse(JSON.stringify(item.data));
        copy.id = 'fe_' + Math.random().toString(36).slice(2, 10);
        copy.x += 20; copy.y += 20;
        copy.zIndex = state.nextZ++;
        const el = renderElement(copy);
        state.page.appendChild(el);
        state.elements.set(copy.id, { data: copy, el });
        select(copy.id);
    }

    function bringForward(id) {
        const item = state.elements.get(id);
        if (!item) return;
        record();
        item.data.zIndex = state.nextZ++;
        item.el.style.zIndex = item.data.zIndex;
    }
    function sendBackward(id) {
        const item = state.elements.get(id);
        if (!item) return;
        record();
        item.data.zIndex = Math.max(1, (item.data.zIndex || 10) - 5);
        item.el.style.zIndex = item.data.zIndex;
    }

    function updateStyle(id, patch) {
        const item = state.elements.get(id);
        if (!item) return;
        // Colour pickers fire continuously — one undo step per element+field.
        record('style:' + id + ':' + Object.keys(patch || {}).join(','));
        item.data.style = { ...(item.data.style || {}), ...patch };
        applyTypeStyle(item.el, item.data);
        // A textbox keeps its (typed) text — only its look changes.
        if (item.data.type === 'textbox') return;
        renderInner(item.el, item.data);
        // Re-attach overlay (innerHTML reset)
        attachOverlay(item.el, item.data);
    }

    // Re-apply the type style after a direct size edit (Inspector), so a
    // line keeps its thickness.
    function refresh(id) {
        const item = state.elements.get(id);
        if (item) applyTypeStyle(item.el, item.data);
    }

    // ═══════════════════════════════════════════════════════════════
    // UNDO / REDO — snapshots of inserted + moved elements
    // ═══════════════════════════════════════════════════════════════
    const hist = { past: [], future: [], pending: null, lastKey: null, lastAt: 0 };
    const HIST_MAX = 60;
    let _histListener = null;
    const snap = () => JSON.stringify(serialize());
    function notifyHistory() { if (_histListener) _histListener({ canUndo: hist.past.length > 0, canRedo: hist.future.length > 0 }); }
    function pushPast(s) {
        if (hist.past[hist.past.length - 1] === s) return;
        hist.past.push(s);
        if (hist.past.length > HIST_MAX) hist.past.shift();
        hist.future = [];
        notifyHistory();
    }
    // Snapshot BEFORE a change. Same key within 800 ms = same step.
    function record(key) {
        if (!state.page) return;
        const now = Date.now();
        if (key && key === hist.lastKey && now - hist.lastAt < 800) { hist.lastAt = now; return; }
        hist.lastKey = key || null; hist.lastAt = now;
        pushPast(snap());
    }
    // Drag / resize / Inspector field: the step only counts if something changed.
    function beginChange() { if (state.page) hist.pending = snap(); }
    function endChange() {
        if (hist.pending === null) return;
        const before = hist.pending;
        hist.pending = null;
        if (snap() !== before) { hist.lastKey = null; pushPast(before); }
    }
    function restore(s) {
        deselect();
        resetMoved();
        deserialize(JSON.parse(s));
    }
    function undo() {
        if (!hist.past.length) return false;
        hist.future.push(snap());
        restore(hist.past.pop());
        hist.lastKey = null;
        notifyHistory();
        return true;
    }
    function redo() {
        if (!hist.future.length) return false;
        hist.past.push(snap());
        restore(hist.future.pop());
        hist.lastKey = null;
        notifyHistory();
        return true;
    }
    function resetHistory() { hist.past = []; hist.future = []; hist.pending = null; hist.lastKey = null; notifyHistory(); }

    function updateContent(id, content) {
        const item = state.elements.get(id);
        if (!item) return;
        item.data.content = content;
    }

    // ═══════════════════════════════════════════════════════════════
    // FLOW ANCHORS
    // Absolutely placed things (inserted elements, moved originals,
    // signature/stamp) are stored relative to the nearest document block
    // ABOVE them ("24px below the signature section") instead of a fixed
    // page y. A template designed on the 4-row sample therefore follows the
    // content of a real document with 40 rows (and the other way round).
    // Block keys: id / data-key / data-move / first class + occurrence.
    // ═══════════════════════════════════════════════════════════════
    function anchorBlocks() {
        const out = [];
        const seen = {};
        if (!state.page) return out;
        Array.from(state.page.children).forEach(c => {
            if (c.classList.contains('floating-element') || c.classList.contains('doc-asset-wrap') || c.classList.contains('export-pad')) return;
            const base = c.id || c.dataset.key || c.dataset.move || String(c.className || '').split(' ')[0] || c.tagName.toLowerCase();
            const n = seen[base] = (seen[base] || 0) + 1;
            if (c.dataset.feFloated || c.offsetParent === null) return;   // floated / hidden: no flow position
            if (getComputedStyle(c).position === 'absolute') return;
            out.push({ key: base + '#' + (n - 1), el: c });
        });
        return out;
    }

    // { a, dy } for a page-y coordinate (doc px).
    function anchorFor(y) {
        const blocks = anchorBlocks();
        if (!blocks.length) return null;
        let pick = blocks[0];
        blocks.forEach(b => { if (b.el.offsetTop <= y) pick = b; });
        return { a: pick.key, dy: Math.round((y - pick.el.offsetTop) * 100) / 100 };
    }

    // Page-y of an anchor, or null if the block does not exist here.
    function resolveAnchor(a, dy) {
        if (!a || typeof dy !== 'number') return null;
        const b = anchorBlocks().find(x => x.key === a);
        return b ? b.el.offsetTop + dy : null;
    }

    // Re-apply stored anchors (after late layout changes, e.g. the logo
    // image finished loading and pushed everything down).
    function reanchor() {
        state.elements.forEach(({ data, el }) => {
            if (!data.anchor) return;
            const y = resolveAnchor(data.anchor.a, data.anchor.dy);
            if (y === null) return;
            data.y = y;
            el.style.top = y + 'px';
        });
        if (!state.page) return;
        state.page.querySelectorAll('[data-fe-floated="1"][data-anchor-a]').forEach(el => {
            const y = resolveAnchor(el.dataset.anchorA, parseFloat(el.dataset.anchorDy));
            if (y !== null) el.style.top = y + 'px';
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // SERIALIZE / DESERIALIZE (for save / load)
    // ═══════════════════════════════════════════════════════════════
    function serialize() {
        // Capture both inserted floating elements AND existing-elements that were moved
        const inserted = [];
        state.elements.forEach(({ data, el }) => {
            const clone = JSON.parse(JSON.stringify(data));
            // For textbox, persist current innerHTML — WITHOUT the editor
            // overlay (resize handles / toolbar live inside the same div).
            if (data.type === 'textbox') {
                const t = el.querySelector('.fe-text');
                if (t) clone.content = t.innerHTML;
                else {
                    const c = el.cloneNode(true);
                    c.querySelectorAll('.fe-handle, .fe-toolbar').forEach(n => n.remove());
                    clone.content = c.innerHTML;
                }
            }
            // For table, persist current cell values
            if (data.type === 'table') {
                const cells = [];
                el.querySelectorAll('tr').forEach(tr => {
                    const row = [];
                    tr.querySelectorAll('th,td').forEach(c => row.push(c.textContent));
                    cells.push(row);
                });
                clone.content = cells;
            }
            clone.anchor = anchorFor(data.y);
            inserted.push(clone);
        });

        // Capture moved existing elements (with selectors)
        // NOTE: dataset.feFloated is the attribute data-fe-floated.
        const moved = [];
        state.page.querySelectorAll('[data-fe-floated="1"]').forEach(el => {
            const key = describeElement(el);
            if (!key) return;
            const y = parseFloat(el.style.top) || 0;
            // Only page-level coordinates need a flow anchor; an original
            // inside a positioned block (e.g. .header) moves with it anyway.
            const anc = el.offsetParent === state.page ? anchorFor(y) : null;
            moved.push({
                key,
                x: parseFloat(el.style.left) || 0,
                y,
                width: parseFloat(el.style.width) || 0,
                zIndex: parseInt(el.style.zIndex, 10) || 100,
                ...(anc ? anc : {}),
            });
        });

        return { inserted, moved };
    }

    // Stable template key of a movable original (order_form.js renders
    // data-key / data-move on every [data-draggable]).
    // data-mkey: template key of blocks that became movable later — kept
    // out of anchorBlocks() so anchors of saved templates stay valid.
    function describeElement(el) {
        return el.dataset.move || el.dataset.mkey || el.dataset.key || null;
    }

    function findMoved(m) {
        if (m.key) {
            const k = window.CSS && CSS.escape ? CSS.escape(m.key) : m.key;
            return state.page.querySelector('[data-move="' + k + '"], [data-draggable][data-mkey="' + k + '"], [data-draggable][data-key="' + k + '"]');
        }
        try { return m.selector ? state.page.querySelector(m.selector) : null; } catch (e) { return null; }
    }

    function deserialize(state_) {
        if (!state_) return;
        // Clear current floating elements
        state.elements.forEach(({ el }) => el.remove());
        state.elements.clear();

        // Restore inserted elements
        (state_.inserted || []).forEach(data => {
            const copy = JSON.parse(JSON.stringify(data));
            const el = renderElement(copy);
            state.page.appendChild(el);
            state.elements.set(copy.id, { data: copy, el });
            if (copy.zIndex >= state.nextZ) state.nextZ = copy.zIndex + 1;
        });

        // Restore moved existing elements
        (state_.moved || []).forEach(m => {
            const el = findMoved(m);
            if (!el) return;
            el.dataset.feFloated = '1';
            el.style.position = 'absolute';
            el.style.margin = '0';
            el.style.left = m.x + 'px';
            el.style.top  = m.y + 'px';
            if (m.width) el.style.width = m.width + 'px';
            if (m.zIndex) {
                el.style.zIndex = m.zIndex;
                if (m.zIndex >= state.nextZ) state.nextZ = m.zIndex + 1;
            }
            if (m.a && typeof m.dy === 'number') { el.dataset.anchorA = m.a; el.dataset.anchorDy = String(m.dy); }
        });
        // Resolve flow anchors only once every original is floated — taking
        // blocks out of the flow shifts the ones below them.
        reanchor();
    }

    function resetMoved() {
        state.page.querySelectorAll('[data-fe-floated="1"]').forEach(el => {
            el.style.position = '';
            el.style.left = '';
            el.style.top = '';
            el.style.width = '';
            el.style.zIndex = '';
            el.style.margin = '';
            delete el.dataset.feFloated;
            delete el.dataset.anchorA;
            delete el.dataset.anchorDy;
        });
    }

    // Put one moved original back into the document flow.
    function unfloat(el) {
        if (!el || !el.dataset.feFloated) return;
        record();
        ['position', 'left', 'top', 'width', 'zIndex', 'margin'].forEach(p => { el.style[p] = ''; });
        delete el.dataset.feFloated;
        delete el.dataset.anchorA;
        delete el.dataset.anchorDy;
    }

    function clearAll() {
        record();
        state.elements.forEach(({ el }) => el.remove());
        state.elements.clear();
        resetMoved();
        state.selected = null;
    }

    // ═══════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════
    return {
        init, setEditMode,
        insert,
        remove, duplicate,
        bringForward, sendBackward,
        select, deselect,
        updateStyle, updateContent, refresh,
        serialize, deserialize,
        resetMoved, clearAll, unfloat,
        anchorFor, resolveAnchor, reanchor,
        undo, redo, resetHistory, beginChange, endChange,
        onHistory: (fn) => { _histListener = fn; notifyHistory(); },
        getSelected: () => state.selected,
        getElement: (id) => state.elements.get(id)?.data,
    };
})();
