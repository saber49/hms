/* ============================================
   hmsMorph — update a DOM subtree IN PLACE from new HTML
   ============================================

   hmsMorph(target, html, { keep })

   Instead of `target.innerHTML = html` (which destroys every node and
   rebuilds the whole area → visible flash, MD3 components re-upgrade,
   scroll / open <details> / focus are lost) the new markup is compared with
   the existing DOM and only the differences are applied:
   - unchanged nodes stay the same objects (no repaint, listeners kept)
   - changed text / attributes are patched, new rows inserted, old removed
   - children are matched by `data-mk="<key>"` or `id` (stable rows, cards),
     otherwise by position
   - the focused field keeps what the user is typing (value untouched)
   - <details open> keeps the user's open/closed state
   - opts.keep(oldEl, newEl) → true leaves that old subtree untouched

   Use it for every re-render of an area that is ALREADY on screen
   (after a save, realtime refresh, filter change). First paint of a new
   page can keep using innerHTML.
*/
(function () {
    function keyOf(n) {
        return n.nodeType === 1 ? (n.getAttribute('data-mk') || n.id || null) : null;
    }

    // State that app-wide enhancers (table top scrollbar / scroll buttons in
    // app.js) put on rendered elements — never strip it, or they re-attach.
    const RUNTIME_ATTRS = { 'data-top-scroll': 1, 'data-hnav': 1 };
    const RUNTIME_CLASSES = ['has-top-scroll'];

    function syncAttrs(o, n, focused) {
        const isDetails = o.nodeName === 'DETAILS';
        const skip = name => RUNTIME_ATTRS[name] || (isDetails && name === 'open') || (focused && name === 'value');
        for (const a of Array.from(o.attributes)) {
            if (!n.hasAttribute(a.name) && !skip(a.name)) o.removeAttribute(a.name);
        }
        for (const a of Array.from(n.attributes)) {
            if (skip(a.name)) continue;
            let v = a.value;
            if (a.name === 'class') RUNTIME_CLASSES.forEach(c => { if (o.classList.contains(c) && !n.classList.contains(c)) v += ' ' + c; });
            if (o.getAttribute(a.name) !== v) o.setAttribute(a.name, v);
        }
    }

    // Properties diverge from attributes once the user interacts — re-assert
    // the rendered state (e.g. a rejected save reloads the old select value).
    function syncProps(o, n, focused) {
        if (focused) return;
        const tag = o.nodeName;
        if (tag === 'INPUT') {
            if (o.type === 'checkbox' || o.type === 'radio') o.checked = n.hasAttribute('checked');
            else if (o.type !== 'file') {
                const v = n.getAttribute('value') ?? '';
                if (o.value !== v) o.value = v;
            }
        } else if (tag === 'TEXTAREA') {
            if (o.value !== n.textContent) o.value = n.textContent;
        } else if (tag === 'OPTION') {
            o.selected = n.hasAttribute('selected');
        } else if (tag.indexOf('-') > 0) {
            // MD3 / Lit components only sync attribute → property on CHANGE.
            if (typeof o.selected === 'boolean') o.selected = n.hasAttribute('selected');
            if (typeof o.checked === 'boolean') o.checked = n.hasAttribute('checked');
            if (/-SELECT$/.test(tag)) {
                const opt = n.querySelector(':scope > [selected]');
                const v = opt ? (opt.getAttribute('value') ?? '') : null;
                if (v !== null && o.value !== v) o.value = v;
            } else if (n.hasAttribute('value') && typeof o.value === 'string') {
                const v = n.getAttribute('value');
                if (o.value !== v) o.value = v;
            }
        }
    }

    function morphNode(o, n, opts) {
        if (o.nodeType !== 1) {
            if (o.nodeValue !== n.nodeValue) o.nodeValue = n.nodeValue;
            return;
        }
        if (opts.keep && opts.keep(o, n)) return;
        // A focused MD field reports its host as document.activeElement.
        const focused = o === document.activeElement;
        syncAttrs(o, n, focused);
        if (o.nodeName !== 'TEXTAREA') morphChildren(o, n, opts);
        syncProps(o, n, focused);
    }

    // Nodes injected by app-wide enhancers (table top scrollbar) are not part
    // of the rendered markup — leave them where they are.
    const TABLE_WRAP = '.table-container, .or-table-wrap, .or-sub-table-wrap';
    const BAR = n => n.classList.contains('table-top-scroll') || n.classList.contains('hms-topscroll');
    function ignored(n) {
        return n.nodeType === 1 && (BAR(n) || n.hasAttribute('data-morph-ignore'));
    }

    function morphChildren(op, np, opts) {
        const keyed = new Map();
        for (let c = op.firstChild; c; c = c.nextSibling) {
            const k = !ignored(c) && keyOf(c);
            if (k && !keyed.has(k)) keyed.set(k, c);
        }
        let cur = op.firstChild;
        const skip = () => { while (cur && ignored(cur)) cur = cur.nextSibling; };
        for (let nn = np.firstChild; nn; nn = nn.nextSibling) {
            skip();
            const k = keyOf(nn);
            let match = null;
            if (k) {
                const m = keyed.get(k);
                if (m && m.nodeName === nn.nodeName) { match = m; keyed.delete(k); }
            } else if (cur && !keyOf(cur) && cur.nodeType === nn.nodeType && cur.nodeName === nn.nodeName) {
                match = cur;
            }
            if (match) {
                if (match === cur) cur = cur.nextSibling;
                else op.insertBefore(match, cur);
                morphNode(match, nn, opts);
            } else {
                op.insertBefore(document.importNode(nn, true), cur);
            }
        }
        while (cur) {
            const nx = cur.nextSibling;
            if (!ignored(cur)) op.removeChild(cur);
            cur = nx;
        }
        // An enhancer bar whose table is gone would be left orphaned.
        Array.from(op.children).forEach(c => {
            if (BAR(c) && !c.nextElementSibling?.matches(TABLE_WRAP)) c.remove();
        });
    }

    window.hmsMorph = function (target, html, opts) {
        if (!target) return target;
        const tpl = document.createElement('template');
        tpl.innerHTML = html == null ? '' : String(html);
        morphChildren(target, tpl.content, opts || {});
        return target;
    };
})();
