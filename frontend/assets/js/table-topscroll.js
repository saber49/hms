/* ============================================================
   HMS — global top scrollbar for wide tables
   ------------------------------------------------------------
   On laptop screens most section tables (Kunden, Objekte, NU,
   Bestellungen, …) are wider than the viewport. Their native
   horizontal scrollbar sits at the BOTTOM of the scroll container,
   which for long lists is below the fold — so there is no visible
   way to scroll sideways.

   This utility watches the DOM and, for every known table wrapper,
   injects a slim scrollbar strip ABOVE the table that is kept in
   sync with the container in both directions. It only shows itself
   when the table actually overflows.

   Auto-attaches via MutationObserver, so it works for all sections
   without touching their render functions — including future ones,
   as long as they use one of the wrapper classes below.
   ============================================================ */

(function () {
    'use strict';

    // Guard against the script being executed twice (double include /
    // stale cached copy) — two observers would inject two strips.
    if (window.__hmsTopScrollLoaded) return;
    window.__hmsTopScrollLoaded = true;

    // Every table wrapper class used across the app's sections.
    const SELECTOR = '.table-container, .or-table-wrap, .or-sub-table-wrap';

    function attach(cont) {
        if (cont.dataset.hmsTopscroll) return;

        // Skip wrappers that ship their OWN top scrollbar (wizard days
        // table uses #wizDaysTopScroll).
        const prev = cont.previousElementSibling;
        if (prev && /TopScroll$/.test(prev.id || '')) {
            cont.dataset.hmsTopscroll = '1';
            return;
        }

        // Defensive dedup: remove any orphaned strips already sitting
        // directly above (e.g. left behind by an older cached script
        // version) so there is never more than one.
        while (cont.previousElementSibling
               && cont.previousElementSibling.classList.contains('hms-topscroll')) {
            cont.previousElementSibling.remove();
        }

        cont.dataset.hmsTopscroll = '1';

        const bar = document.createElement('div');
        bar.className = 'hms-topscroll';
        bar.style.cssText = 'display:none;overflow-x:auto;overflow-y:hidden;height:14px';
        const inner = document.createElement('div');
        inner.style.height = '1px';
        bar.appendChild(inner);
        cont.parentNode.insertBefore(bar, cont);

        let lock = false;
        bar.addEventListener('scroll', () => {
            if (lock) return;
            lock = true; cont.scrollLeft = bar.scrollLeft; lock = false;
        });
        cont.addEventListener('scroll', () => {
            if (lock) return;
            lock = true; bar.scrollLeft = cont.scrollLeft; lock = false;
        });

        const sync = () => {
            inner.style.width = cont.scrollWidth + 'px';
            bar.style.display = cont.scrollWidth > cont.clientWidth + 2 ? 'block' : 'none';
        };
        sync();

        // Re-sync when the table content or viewport changes size —
        // rows are re-rendered on every filter/search keystroke.
        if (window.ResizeObserver) {
            const ro = new ResizeObserver(sync);
            ro.observe(cont);
            const table = cont.querySelector('table');
            if (table) ro.observe(table);
        }
        window.addEventListener('resize', sync);
    }

    function scan(root) {
        if (root.nodeType !== 1) return;
        if (root.matches && root.matches(SELECTOR)) attach(root);
        if (root.querySelectorAll) root.querySelectorAll(SELECTOR).forEach(attach);
    }

    document.addEventListener('DOMContentLoaded', () => {
        scan(document.body);
        new MutationObserver(muts => {
            muts.forEach(m => m.addedNodes.forEach(scan));
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
