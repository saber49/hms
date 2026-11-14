/* ============================================
   QM 03 — page #qm (shell + tab "Übersicht")
   Tabs: Übersicht · Objekte & Dienstanweisungen · Reklamationen ·
   Maßnahmen · Einstellungen. Deep links: #qm?view=<tab>&id=<record>.
   ============================================ */

const QM_VIEWS = [
    ['uebersicht', 'space_dashboard', 'Übersicht'],
    ['objekte', 'domain', 'Objekte & Dienstanweisungen'],
    ['reklamationen', 'feedback', 'Reklamationen'],
    ['massnahmen', 'task_alt', 'Maßnahmen'],
];
// QM-Einstellungen live on the central page System › Einstellungen (einstellungen.js).

async function renderQm() {
    const content = document.getElementById('content');
    try { await qmLoadOpt(true); } catch (e) {
        content.innerHTML = qmEmpty('error', e.message, `<md-filled-button onclick="renderQm()">Erneut versuchen</md-filled-button>`);
        return;
    }
    const p = qmParams();
    if (p.get('view') === 'einstellungen') return loadPage('einstellungen?tab=qualitaet' + (p.get('tab') ? '&sub=' + p.get('tab') : ''));
    if (p.get('view') && QM_VIEWS.some(v => v[0] === p.get('view'))) qm.view = p.get('view');
    if (!QM_VIEWS.some(v => v[0] === qm.view)) qm.view = 'uebersicht';
    qm.detail = p.get('id') ? { typ: qm.view, id: +p.get('id') } : null;
    content.innerHTML = `
        <div class="crm-page qm-page">
            ${qmTabs('qmSetView', QM_VIEWS, qm.view)}
            <div id="qmBody"></div>
        </div>`;
    qmWire(content);
    qmRenderView();
}

function qmSetView(v, fromCode = false) {
    qm.view = v; qm.detail = null;
    if (fromCode) document.querySelectorAll('[data-qm-tabs="qmSetView"] md-primary-tab').forEach(t => { t.active = t.dataset.v === v; });
    qmRenderView();
}

function qmRenderView() {
    const body = document.getElementById('qmBody');
    if (!body) return;
    body.innerHTML = '';
    ({
        uebersicht: qmUebersichtRender, objekte: qmObjekteRender, reklamationen: qmRekRender,
        massnahmen: qmMnRender, einstellungen: qmEinstRender,
    }[qm.view] || qmUebersichtRender)();
}

/** Open a record of another tab (e.g. Maßnahme from a Reklamation). */
function qmGehe(view, id) {
    if (view === 'einstellungen') return loadPage('einstellungen?tab=qualitaet');
    if (currentPage !== 'qm') { window._pageParams = new URLSearchParams({ view, ...(id ? { id } : {}) }); return loadPage('qm'); }
    qmSetView(view, true);
    if (id) ({ reklamationen: qmRekDetail, massnahmen: qmMnDetail })[view]?.(id);
}

// ============================================
// ÜBERSICHT
// ============================================
async function qmUebersichtRender() {
    const body = document.getElementById('qmBody');
    body.innerHTML = `<div class="data-bento" id="qmUeBento"></div>
        <div class="crm-card"><div class="crm-card-title">${qmIcon('today')} Heute zu tun</div><div id="qmUeTodo">${qmLoading()}</div></div>
        <div class="crm-card qm-mt"><div class="crm-card-title">${qmIcon('verified_user')} Ihr Zugriff</div>
            <div class="crm-pad">${(qm.opt.ich.rollen || []).map(r => qmBadge({ qmb: 'QMB', buero: 'Büro', objektleiter: 'Objektleiter' }[r] || r, 'info')).join(' ')}
            · ${qm.opt.ich.alle_objekte ? 'alle Objekte' : (qm.opt.objekte.length + ' zuständige Objekte')}</div></div>`;
    const r = await qmGet(QM_API + '/uebersicht');
    if (qm.view !== 'uebersicht') return;
    if (!r.ok) { document.getElementById('qmUeTodo').innerHTML = qmFehlerBox(r, 'qmUebersichtRender()'); return; }
    const k = r.data.kpi;
    qmPaint(document.getElementById('qmUeBento'), [
        qmTile({ hero: true, cls: 'bento-tile-total', icon: 'feedback', label: 'Offene Reklamationen', n: k.reklamationen_offen, click: "qmGehe('reklamationen')",
            foot: k.reklamationen_ueber ? `<span class="bento-foot-pill">${qmIcon('alarm', 'font-size:14px')} ${k.reklamationen_ueber} über Frist</span>` : '' }),
        qmTile({ cls: k.massnahmen_ueber ? 'bento-tile-warn crm-tile-red' : '', icon: 'task_alt', label: 'Offene Maßnahmen', n: k.massnahmen_offen, click: "qmGehe('massnahmen')",
            meta: k.massnahmen_ueber ? k.massnahmen_ueber + ' überfällig' : '' }),
        qmTile({ cls: k.vorkommnisse_offen ? 'bento-tile-warn crm-tile-amber' : '', icon: 'report', label: 'Offene Vorkommnisse', n: k.vorkommnisse_offen, click: "loadPage('qm_wachbuch')" }),
        qmTile({ cls: k.da_entwurf ? 'crm-tile-amber' : '', icon: 'approval', label: 'Dienstanweisungen zur Freigabe', n: k.da_entwurf, click: "qmGehe('objekte')" }),
    ].join(''));
    const todo = r.data.heute_zu_tun || [];
    document.getElementById('qmUeTodo').innerHTML = todo.length ? todo.map(t => `
        <div class="qm-todo qm-todo-${qmE(t.ton)}" onclick="${t.link === 'qm_wachbuch' ? "loadPage('qm_wachbuch')" : `qmGehe('${qmE(t.link)}')`}">
            ${qmIcon(t.icon)} <span>${qmE(t.text)}</span> ${qmIcon('chevron_right')}
        </div>`).join('') : `<div class="crm-muted crm-pad">${qmIcon('check_circle')} Alles erledigt — nichts Dringendes offen.</div>`;
}
