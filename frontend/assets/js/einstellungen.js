/* ============================================
   HMS — System › Einstellungen (central settings page)
   One place for every module setting. Each tab is rendered by its own
   module into a host element — no duplicated settings UI:
     dokumente  Dokumentvorlagen        pdf_design.js  renderPdfDesign(host)
     vertrieb   Vertrieb (Lead-Modul)   crm.js         crmRenderSettings() → #crmBody
     qualitaet  Qualität & Wachbuch     qm/*.js        qmEinstRender()     → #qmBody
   Deep links: #einstellungen?tab=qualitaet&sub=kunden
   ============================================ */

const EST_TABS = [
    { key: 'dokumente', icon: 'description', label: 'Dokumentvorlagen', perm: 'system.dokument_design.manage',
      text: 'Aussehen Ihrer PDF-Dokumente: Leistungsnachweis, Auftragsbestätigung, Stundennachweis.' },
    { key: 'vertrieb', icon: 'person_search', label: 'Vertrieb', perm: 'stammdaten.interessenten.admin',
      text: 'Fristen, Listen, Zuweisungsregeln und Webformular des Lead-Moduls.' },
    { key: 'qualitaet', icon: 'verified', label: 'Qualität & Wachbuch', perm: 'qm.view',
      text: 'Wachbuch, Reklamationen und Maßnahmen, Rechte, Objekt-Terminals, Mitarbeiter-PIN und Kundenzugänge.' },
];
let estTab = null;

function estIcon(n, st = '') { return `<span class="material-symbols-outlined"${st ? ` style="${st}"` : ''}>${n}</span>`; }

async function renderEinstellungen() {
    const content = document.getElementById('content');
    const p = window._pageParams instanceof URLSearchParams ? window._pageParams : new URLSearchParams();
    window._pageParams = null;
    const tabs = EST_TABS.filter(t => hasPermission(t.perm));
    if (!tabs.length) { content.innerHTML = `<div class="empty-state">${estIcon('lock')}<p>Keine Einstellungen freigegeben.</p></div>`; return; }
    if (p.get('tab') && tabs.some(t => t.key === p.get('tab'))) estTab = p.get('tab');
    if (!tabs.some(t => t.key === estTab)) estTab = tabs[0].key;
    content.innerHTML = `
        <div class="est-page">
            <div class="est-nav" role="tablist">${tabs.map(t => `
                <button type="button" class="est-nav-item${t.key === estTab ? ' is-on' : ''}" role="tab" data-est="${t.key}" onclick="estSetTab('${t.key}')">
                    <span class="est-nav-icon">${estIcon(t.icon)}</span>
                    <span class="est-nav-text"><strong>${escapeHtml(t.label)}</strong><small>${escapeHtml(t.text)}</small></span>
                </button>`).join('')}
            </div>
            <div class="est-body" id="estBody"></div>
        </div>`;
    estRender(p.get('sub'));
}

function estSetTab(key) {
    if (key === estTab) return;
    estTab = key;
    document.querySelectorAll('.est-nav-item').forEach(b => b.classList.toggle('is-on', b.dataset.est === key));
    estRender();
}

async function estRender(sub) {
    const body = document.getElementById('estBody');
    if (!body) return;
    const tab = estTab;
    body.innerHTML = `<div class="crm-loading">${estIcon('progress_activity')} Laden…</div>`;
    try {
        if (tab === 'dokumente') {
            await ensurePageScripts('pdf_design');
            body.innerHTML = '<div id="estDoc"></div>';
            renderPdfDesign(document.getElementById('estDoc'));
        } else if (tab === 'vertrieb') {
            await ensurePageScripts('interessenten');
            await crmLoadOpt(true);
            if (estTab !== tab) return;
            body.innerHTML = '<div class="crm-page"><div id="crmStaleBar" class="crm-stale"></div><div id="crmBody"></div></div>';
            crm.view = 'settings'; crm.detail = null; crm.dirty = false;
            crmRenderSettings();
        } else {
            await ensurePageScripts('qm');
            await qmLoadOpt(true);
            if (estTab !== tab) return;
            body.innerHTML = '<div class="crm-page qm-page"><div id="qmBody"></div></div>';
            qm.view = 'einstellungen'; qm.detail = null;
            if (sub) qm.st.tab = sub;
            qmEinstRender();
        }
    } catch (e) {
        body.innerHTML = `<div class="empty-state">${estIcon('error')}<p>${escapeHtml(e.message || 'Konnte nicht geladen werden')}</p>
            <md-filled-button onclick="estRender()">Erneut versuchen</md-filled-button></div>`;
    }
}

// Same page, new deep link (#einstellungen?tab=…&sub=…).
window.estOnPageParams = function (params) {
    if (params.get('tab')) { estTab = null; window._pageParams = params; renderEinstellungen(); }
};
