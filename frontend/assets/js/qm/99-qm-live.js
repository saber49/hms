/* ============================================
   QM 99 — live updates (Reverb area "qm"). Last part on purpose: it may
   reference every function of the earlier parts at load time.
   Never wipes an open dialog or a detail the user is working in.
   ============================================ */

if (window.HMSRealtime && typeof HMSRealtime.on === 'function') {
    let t = null;
    HMSRealtime.on('qm', () => {
        if (!['qm', 'qm_wachbuch'].includes(typeof currentPage !== 'undefined' ? currentPage : '')) return;
        if (Date.now() - (qm.lastWrite || 0) < 4000) return;               // echo of my own save
        if (document.getElementById('modalOverlay')?.classList.contains('active')) return;
        clearTimeout(t);
        t = setTimeout(() => {
            if (currentPage === 'qm_wachbuch') {
                if (qm.wb.tab === 'leitstand') qmLeitstandLaden();
                else if (qm.wb.tag) qmTagLaden(qm.wb.tag, true);
                return;
            }
            if (qm.detail) return;                                           // don't yank an open record
            ({ uebersicht: qmUebersichtRender, reklamationen: qmRekLaden, massnahmen: qmMnLaden })[qm.view]?.();
        }, 600);
    });
}

// Deep link while already on the page (#qm?view=reklamationen&id=5 from a notification).
window.qmOnPageParams = function (page, params) {
    window._pageParams = params;
    if (page === 'qm') renderQm();
    if (page === 'qm_wachbuch') renderQmWachbuch();
};
