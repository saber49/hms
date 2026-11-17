<?php
// Token-based auth — check is done client-side now
// Never cache the HTML shell: it carries the ?v= cache-busters, so a stale
// copy would keep loading outdated CSS/JS even after deployments.
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Clood-X — System</title>
    <style>
        #hmsBoot{position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background: var(--md-sys-color-primary-container);color: var(--md-sys-color-on-primary-container);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;transition:opacity .2s ease}
        [data-theme="dark"] #hmsBoot{background: var(--md-sys-color-primary);color: var(--md-sys-color-primary-container)}
        #hmsBoot.hms-boot-done{opacity:0;pointer-events:none}
        .hms-boot-box{display:flex;flex-direction:column;align-items:center;gap:18px;text-align:center}
        .hms-boot-mark{width:92px;height:92px;border-radius:26px;display:grid;place-items:center;background: var(--md-sys-color-primary);color: #fff;font-size:25px;font-weight:800;box-shadow: 0 16px 36px color-mix(in srgb, var(--md-sys-color-primary) 24%, transparent)}
        .hms-boot-title{font-size:24px;font-weight:750}.hms-boot-text{font-size:14px;opacity:.7}
        .hms-boot-spinner{width:32px;height:32px;border: 3px solid color-mix(in srgb, var(--md-sys-color-primary) 18%, transparent);border-top-color: var(--md-sys-color-primary);border-radius:50%;animation:hmsBootSpin .75s linear infinite}
        @keyframes hmsBootSpin{to{transform:rotate(360deg)}}
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <!-- Theme bootstrap — set data-theme BEFORE stylesheets to avoid a
         flash. Light is the default; dark applies ONLY if the user
         explicitly chose it (hms_theme). OS preference is ignored. -->
    <script>
    (function () {
        var t = localStorage.getItem('hms_theme');
        document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
        // Inside the Mitarbeiter-App WebView the page is drawn edge-to-edge
        // (under the status bar / gesture bar). The SSO handoff sets
        // hms_app_shell — pad the fixed chrome by the device safe areas.
        if (localStorage.getItem('hms_app_shell') === '1') {
            document.documentElement.classList.add('app-shell');
            // iOS app: SceneDelegate injects a safe-area script at document
            // end (body padding + a heuristic that pads any fixed/sticky
            // element found at the viewport edge). That heuristic re-runs on
            // every DOM change and pads the STICKY topbar whenever it happens
            // to be scrolled to the top — permanently, on top of the body
            // padding → ~120px gap after navigating between pages. The
            // script bails when this flag is already set, so we opt out and
            // handle the insets ourselves via env() (see <style> below), the
            // same way the app's bundled pages do.
            window.__cxSafeArea = 1;
        }
        // App-SSO handoff (#app_sso=1): this first load only stores the token
        // and reloads once the profile is fetched — keep it invisible so the
        // half-booted dashboard doesn't flash before the real one.
        if (location.hash.indexOf('app_sso=1') !== -1) {
            document.documentElement.classList.add('sso-boot');
            // Safety net: never stay invisible if the profile fetch stalls.
            setTimeout(function () { document.documentElement.classList.remove('sso-boot'); }, 8000);
        }
    })();
    </script>
    <style>html.sso-boot body { visibility: hidden; }</style>
    <style>
    /* Native app WebView (html.app-shell): edge-to-edge on both platforms —
       the WebView draws UNDER the status + navigation bars and THIS page owns
       the insets (the iOS native injector is opted out above; Android never
       had one). Inset source: Android → --safe-top/--safe-bottom pushed by
       MainActivity (env() is always 0 in the Android WebView); iOS → env()
       (viewport-fit=cover is set). Backgrounds still stretch under the bars;
       the fixed/sticky chrome pads itself so nothing tappable hides behind
       a bar and the sticky topbar keeps covering the status bar on scroll. */
    html.app-shell { --sa-top: var(--safe-top, env(safe-area-inset-top, 0px)); --sa-bottom: var(--safe-bottom, env(safe-area-inset-bottom, 0px)); }
    html.app-shell .topbar {
        height: auto;
        min-height: calc(var(--topbar-height, 60px) + var(--sa-top));
        padding-top: var(--sa-top);
    }
    html.app-shell .sidebar {
        padding-top: var(--sa-top);
        padding-bottom: var(--sa-bottom);
    }
    html.app-shell .content {
        padding-bottom: calc(24px + var(--sa-bottom));
    }
    html.app-shell .ai-launcher {
        bottom: calc(24px + var(--sa-bottom));
    }
    html.app-shell .ai-panel {
        bottom: calc(94px + var(--sa-bottom));
    }
    html.app-shell md-fab.wm-new-chat-fab {
        bottom: calc(100px + var(--sa-bottom));
    }
    html.app-shell .chat-panel {
        padding-top: var(--sa-top);
        padding-bottom: var(--sa-bottom);
    }
    html.app-shell .toast-container {
        top: calc(20px + var(--sa-top));
    }
    </style>
    <script>
    /* App-shell system-bar sync (Android).
       The Capacitor JS bundle is NOT injected into remote pages, but the raw
       'androidBridge' WebMessageListener IS — allowNavigation origins get it
       (see MessageHandler.java). We can therefore still call plugins by
       posting the bridge message ourselves. Two jobs:
         1. setSystemBarStyle — status/nav bar icon colour follows data-theme
            (native defaults to the OS night mode, which the web theme
            doesn't necessarily match).
         2. getSafeAreaInsets — pull the bar heights into --safe-top /
            --safe-bottom (the native push can run before the document exists). */
    (function () {
        var ab = null;
        function bridge() {
            if (ab) return ab;
            var b = window.androidBridge;
            if (b && typeof b.postMessage === 'function') ab = b;
            return ab;
        }
        var pending = {};
        var cbSeq = 0;
        function nativeCall(pluginId, methodName, options, onReply) {
            var b = bridge();
            if (!b) return false;
            var cb = 'hms' + (++cbSeq);
            if (onReply) pending[cb] = onReply;
            try {
                b.postMessage(JSON.stringify({
                    callbackId: cb,
                    pluginId: pluginId,
                    methodName: methodName,
                    options: options || {}
                }));
                return true;
            } catch (e) { delete pending[cb]; return false; }
        }
        window.hmsNativeCall = nativeCall;
        function syncBars() {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            nativeCall('DocumentViewer', 'setSystemBarStyle', { darkIcons: !dark });
        }
        function pullInsets() {
            return nativeCall('DocumentViewer', 'getSafeAreaInsets', {}, function (d) {
                var data = d && d.data;
                if (!data) return;
                var s = document.documentElement.style;
                if (typeof data.top === 'number') s.setProperty('--safe-top', data.top + 'px');
                if (typeof data.bottom === 'number') s.setProperty('--safe-bottom', data.bottom + 'px');
            });
        }
        function boot() {
            var b = bridge();
            if (!b) return;
            // Replies arrive through the listener's onmessage — dispatch by callbackId.
            try {
                b.onmessage = function (e) {
                    var d;
                    try { d = JSON.parse(e && e.data !== undefined ? e.data : e); } catch (x) { return; }
                    var fn = d && pending[d.callbackId];
                    if (fn) { delete pending[d.callbackId]; try { fn(d); } catch (x) {} }
                };
            } catch (e) {}
            syncBars();
            // Keep bars in sync on every theme flip + when the app resurfaces
            // (a system night-mode flip natively re-applies the OS default).
            new MutationObserver(syncBars).observe(document.documentElement,
                { attributes: true, attributeFilter: ['data-theme'] });
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) syncBars();
            });
            window.addEventListener('focus', syncBars);
            // Insets pull — retry briefly in case the reply channel is slow.
            var tries = 0, got = false;
            var iv = setInterval(function () {
                if (got || ++tries > 6 || !pullInsets()) { clearInterval(iv); }
                if (getComputedStyle(document.documentElement).getPropertyValue('--safe-top')) got = true;
            }, 400);
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
        else boot();
    })();
    </script>
    <!-- Material Design 3 — official design tokens (must load BEFORE all module sheets) -->
    <link rel="stylesheet" href="assets/css/md3-tokens.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/style.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/customers.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/interessenten.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/qm.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/einstellungen.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/lager.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/subcontractors.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/price_history.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/workers.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/absences.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/notifications.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/tasks.css?v=20261117nu3">
    <link rel="stylesheet" href="assets/css/monatsabschluss.css?v=20261117abwhrs1">
    <link rel="stylesheet" href="assets/css/incoming_orders.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/order_confirmations.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/orders.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/messages.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/worker_messages.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/worker-panels.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/controlling.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/users.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/pdf_design.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/company_documents.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/ai-assistant.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/theme-unified.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/aufgaben.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/ui-components.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/search-field.css?v=20261117md3l">
    <script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <!-- Markdown rendering + sanitization for the AI assistant's rich replies.
         Defer so they don't block parsing; both will be ready before DOMContentLoaded. -->
    <script defer src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js"></script>
    <!-- Material Design 3 — official @material/web web components (md-*) -->
    <script defer src="assets/js/material-web.bundle.js?v=20261117md3l"></script>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div id="hmsBoot" role="status" aria-live="polite">
        <div class="hms-boot-box">
            <div class="hms-boot-mark">Clood-X</div>
            <div class="hms-boot-title">Clood-X</div>
            <div class="hms-boot-spinner"></div>
            <div class="hms-boot-text">Arbeitsbereich wird geladen…</div>
        </div>
    </div>
    <script>
    (function () {
        var finished = false;
        function finishBoot() {
            if (finished) return;
            finished = true;
            var el = document.getElementById('hmsBoot');
            if (!el) return;
            el.classList.add('hms-boot-done');
            setTimeout(function () { el.remove(); }, 220);
        }
        document.addEventListener('hms:page-ready', finishBoot, { once: true });
        window.addEventListener('error', function () { setTimeout(finishBoot, 800); }, { once: true });
        setTimeout(finishBoot, 12000);
    })();
    </script>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo" id="sidebarLogo">
                <span class="logo-mark" id="sidebarLogoMark"><img src="assets/img/clood-x-logo.png" alt="Clood-X"></span>
                <span class="logo-name" id="sidebarLogoName">Clood-X</span>
            </div>
            <button class="sidebar-close" id="sidebarClose" onclick="document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('active')">
                <i class="material-symbols-outlined">close</i>
            </button>
            <small class="sidebar-subtitle">Management System</small>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Dashboard</div>
                <a href="#" class="nav-item active" data-page="dashboard">
                    <i class="material-symbols-outlined">speed</i><span>Übersicht</span>
                </a>
            </div>
            <div class="nav-section nav-section-fav" id="navFavSection">
                <div class="nav-section-title">Favoriten</div>
                <div class="nav-fav-list" id="navFavList"></div>
                <div class="nav-fav-hint" id="navFavHint"><i class="material-symbols-outlined">star</i> Stern neben einem Menüpunkt anklicken, um ihn hier anzuheften</div>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Aufgaben</div>
                <a href="#" class="nav-item" data-page="aufgaben">
                    <i class="material-symbols-outlined">checklist</i><span>Aufgaben</span>
                    <span class="badge" id="badge-aufgaben" style="display:none">0</span>
                </a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Stammdaten</div>
                <a href="#" class="nav-item" data-page="interessenten">
                    <i class="material-symbols-outlined">person_add</i><span>Interessenten</span>
                </a>
                <a href="#" class="nav-item" data-page="customers">
                    <i class="material-symbols-outlined">group</i><span>Kunden</span>
                </a>
                <a href="#" class="nav-item" data-page="objects">
                    <i class="material-symbols-outlined">location_on</i><span>Objekte & Artikel</span>
                </a>
                <a href="#" class="nav-item" data-page="price_history">
                    <i class="material-symbols-outlined">history</i><span>Preishistorie</span>
                </a>
                <a href="#" class="nav-item" data-page="lager">
                    <i class="material-symbols-outlined">inventory_2</i><span>Lager &amp; Ausrüstung</span>
                </a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Personal</div>
                <a href="#" class="nav-item" data-page="workers">
                    <i class="material-symbols-outlined">badge</i><span>Mitarbeiter</span>
                </a>
                <a href="#" class="nav-item" data-page="subcontractors">
                    <i class="material-symbols-outlined">engineering</i><span>Nachunternehmer</span>
                </a>
                <a href="#" class="nav-item" data-page="absences">
                    <i class="material-symbols-outlined">event_available</i><span>Anträge &amp; Urlaub</span>
                    <span class="badge" id="badge-absences" style="display:none">0</span>
                </a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Disposition</div>
                <a href="#" class="nav-item" data-page="tasks">
                    <i class="material-symbols-outlined">task_alt</i><span>Planung</span>
                </a>
                <a href="#" class="nav-item" data-page="monatsabschluss">
                    <i class="material-symbols-outlined">assignment_turned_in</i><span>Zeiterfassung</span>
                </a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Qualität</div>
                <a href="#" class="nav-item" data-page="qm_wachbuch">
                    <i class="material-symbols-outlined">menu_book</i><span id="navQmWachbuchLabel">Wachbuch</span>
                </a>
                <a href="#" class="nav-item" data-page="qm">
                    <i class="material-symbols-outlined">workspace_premium</i><span>Qualitätsmanagement</span>
                </a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Verkauf</div>
                <a href="#" class="nav-item" data-page="incoming_orders">
                    <i class="material-symbols-outlined">inbox</i><span>Bestelleingang</span>
                </a>
                <a href="#" class="nav-item" data-page="auftragsbestaetigung">
                    <i class="material-symbols-outlined">edit_document</i><span>Auftragsbestätigung</span>
                </a>
                <a href="#" class="nav-item" data-page="orders">
                    <i class="material-symbols-outlined">receipt_long</i><span>Bestellungen</span>
                </a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Finanzen</div>
                <a href="#" class="nav-item" data-page="reports">
                    <i class="material-symbols-outlined">monitoring</i><span>Auswertung</span>
                </a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">Kommunikation</div>
                <a href="#" class="nav-item" data-page="worker_messages">
                    <i class="material-symbols-outlined">comment</i><span>MA-Nachrichten</span>
                    <span class="badge" id="badge-worker-messages" style="display:none">0</span>
                </a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">System</div>
                <a href="#" class="nav-item" data-page="users" id="nav-users" style="display:none">
                    <i class="material-symbols-outlined">admin_panel_settings</i><span>Benutzerverwaltung</span>
                </a>
                <a href="#" class="nav-item" data-page="einstellungen" id="nav-einstellungen">
                    <i class="material-symbols-outlined">tune</i><span>Einstellungen</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-user">
            <div class="user-info" onclick="openAccountModal()" style="cursor:pointer" title="Konto-Einstellungen">
                <i class="material-symbols-outlined">account_circle</i>
                <div>
                    <div class="user-name" id="sidebarUserName">Benutzer</div>
                    <div class="user-role" id="sidebarUserRole">viewer</div>
                </div>
            </div>
            <button class="btn-logout" onclick="handleLogout()" title="Abmelden">
                <i class="material-symbols-outlined">logout</i>
            </button>
        </div>
    </aside>

    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('active')"></div>

    <!-- Main Content -->
    <main class="main-content">
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle">
                <i class="material-symbols-outlined">menu</i>
            </button>
            <h1 class="page-title" id="pageTitle">Übersicht</h1>
            <div class="topbar-right">
                <span class="current-date" id="currentDate"></span>
                <button class="topbar-icon-btn" id="themeToggleBtn" onclick="toggleTheme()" title="Dunkles Design">
                    <i class="material-symbols-outlined">dark_mode</i>
                </button>
                <button class="topbar-icon-btn" id="chatToggleBtn" onclick="toggleChatPanel()" title="Nachrichten">
                    <i class="material-symbols-outlined">forum</i>
                    <span class="chat-badge" id="chatBadge"></span>
                </button>
            </div>
        </header>

        <div class="content" id="content">
            <!-- Dynamic content loaded here -->
        </div>
    </main>

    <!-- Modal -->
    <md-dialog id="modalOverlay">
        <div slot="content" class="modal" id="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Modal</h2>
                <md-icon-button class="modal-close" id="modalClose" title="Schließen" aria-label="Schließen"><md-icon>close</md-icon></md-icon-button>
            </div>
            <div class="modal-body" id="modalBody"></div>
            <div class="modal-footer" id="modalFooter"></div>
        </div>
    </md-dialog>

    <!-- Chat Panel -->
    <div class="chat-overlay" id="chatOverlay" onclick="toggleChatPanel()"></div>
    <div class="chat-panel" id="chatPanel">
        <div class="chat-panel-header">
            <button class="chat-header-btn" id="chatBackBtn" onclick="showConversationList()" title="Zurück" style="display:none"><i class="material-symbols-outlined">arrow_back</i></button>
            <h3 id="chatPanelTitle"><i class="material-symbols-outlined">forum</i> <span class="chat-title-text">Nachrichten</span></h3>
            <div class="chat-panel-actions">
                <button class="chat-header-btn" id="chatNewBtn" onclick="openNewChat()" title="Neuer Chat"><i class="material-symbols-outlined">add</i></button>
                <button class="chat-header-btn" id="chatAddBtn" onclick="openAddMembers()" title="Mitglied hinzufügen" style="display:none"><i class="material-symbols-outlined">person_add</i></button>
                <button class="chat-header-btn" id="chatInfoBtn" onclick="openChatMembers()" title="Mitglieder" style="display:none"><i class="material-symbols-outlined">group</i></button>
                <button class="chat-header-btn chat-delete-btn" id="chatDeleteBtn" onclick="deleteChat()" title="Chat löschen" style="display:none"><i class="material-symbols-outlined">delete</i></button>
                <button class="chat-header-btn" onclick="toggleChatPanel()" title="Schließen"><i class="material-symbols-outlined">close</i></button>
            </div>
        </div>
        <div class="chat-panel-body" id="chatPanelBody">
            <div class="chat-loading"><md-circular-progress indeterminate class="ms-progress-sm"></md-circular-progress> Laden...</div>
        </div>
        <div class="chat-mention-pop" id="chatMentionPop" style="display:none"></div>
        <div class="chat-addpop" id="chatAddPop" style="display:none">
            <div class="chat-addpop-head">
                <span><i class="material-symbols-outlined">person_add</i> Mitglieder hinzufügen</span>
                <button class="chat-header-btn" onclick="document.getElementById('chatAddPop').style.display='none'" title="Schließen"><i class="material-symbols-outlined">close</i></button>
            </div>
            <div class="chat-addpop-list" id="chatAddPopList"></div>
            <div class="chat-addpop-foot">
                <md-filled-button style="width:100%" onclick="chatAddMembers()"><i slot="icon" class="material-symbols-outlined">person_add</i> Hinzufügen</md-filled-button>
            </div>
        </div>
        <div class="chat-addpop" id="chatMembersPop" style="display:none">
            <div class="chat-addpop-head">
                <span><i class="material-symbols-outlined">group</i> <span id="chatMembersTitle">Mitglieder</span></span>
                <button class="chat-header-btn" onclick="document.getElementById('chatMembersPop').style.display='none'" title="Schließen"><i class="material-symbols-outlined">close</i></button>
            </div>
            <div class="chat-addpop-list" id="chatMembersList"></div>
        </div>
        <div class="chat-input-area" id="chatInputArea" style="display:none">
            <input type="file" id="chatImageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="sendChatImage()">
            <button class="chat-img-btn" onclick="document.getElementById('chatImageInput').click()" title="Bild senden"><i class="material-symbols-outlined">image</i></button>
            <input type="text" class="chat-input" id="chatMessageInput" placeholder="Nachricht schreiben..." onkeydown="chatInputKeydown(event)" autocomplete="off">
            <button class="chat-send-btn" onclick="sendChatMessage()"><i class="material-symbols-outlined">send</i></button>
        </div>
    </div>

    <!-- Toast Notifications -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        // ---- App-SSO: the Mitarbeiter-App hands an admin session over via
        // the URL fragment (#app_sso=1&t=<token> — fragments are never sent
        // to the server or logged). Store the token synchronously so the
        // auth guard below passes, hydrate user/tenant from /api/auth/check,
        // then strip the fragment from the address bar. ----
        (function () {
            if (window.location.hash.indexOf('app_sso=1') === -1) return;
            try {
                const p = new URLSearchParams(window.location.hash.substring(1));
                const t = p.get('t');
                if (t) {
                    localStorage.setItem('hms_token', t);
                    // Remember that we run inside the app WebView so the UI
                    // keeps its safe-area padding after the reload below.
                    localStorage.setItem('hms_app_shell', '1');
                    document.documentElement.classList.add('app-shell');
                    window.__cxSafeArea = 1; // opt out of the iOS injector (see head)
                    // Clear any stale profile from a previous session; it is
                    // re-fetched for THIS token right below.
                    localStorage.removeItem('hms_user');
                    localStorage.removeItem('hms_tenant');
                    fetch('/api/auth/check', { headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + t } })
                        .then(r => r.json())
                        .then(d => {
                            if (d && d.authenticated) {
                                localStorage.setItem('hms_user', JSON.stringify(d.user));
                                localStorage.setItem('hms_tenant', JSON.stringify(d.tenant || {}));
                                window.location.reload();
                            } else {
                                localStorage.removeItem('hms_token');
                                window.location.href = 'login.php';
                            }
                        })
                        .catch(() => {
                            /* offline — app boots with token only */
                            document.documentElement.classList.remove('sso-boot');
                        });
                } else {
                    document.documentElement.classList.remove('sso-boot');
                }
            } catch (e) {
                /* malformed fragment — ignore */
                document.documentElement.classList.remove('sso-boot');
            }
            history.replaceState(null, '', window.location.pathname + window.location.search);
        })();

        // Redirect if not logged in. Exception: ?demo=1 fetches a read-only
        // token for the public demo tenant (landing-page embed) — no login.
        if (!localStorage.getItem('hms_token')) {
            if (new URLSearchParams(window.location.search).has('demo')) {
                fetch('/api/auth/demo-login', { method: 'POST', headers: { 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : Promise.reject())
                    .then(data => {
                        if (!data || !data.token) throw new Error('no token');
                        localStorage.setItem('hms_token', data.token);
                        localStorage.setItem('hms_user', JSON.stringify(data.user));
                        localStorage.setItem('hms_tenant', JSON.stringify(data.tenant || {}));
                        window.location.reload();
                    })
                    .catch(() => { window.location.href = 'login.php'; });
            } else {
                window.location.href = 'login.php';
            }
        }

        // ---------------------------------------------------------------
        // DEMO SANDBOX — active only for the public demo account.
        // All write requests (POST/PUT/PATCH/DELETE) are intercepted BEFORE
        // they leave the browser and simulated in sessionStorage: the
        // visitor can add customers / shifts / orders and sees them in the
        // UI, but nothing is ever sent to or stored in the backend. Closing
        // the tab wipes everything; the seeded demo data stays untouched.
        // (Server-side the demo user is a read-only viewer anyway — double
        // protection.)
        // ---------------------------------------------------------------
        (function () {
            const demoUser = JSON.parse(localStorage.getItem('hms_user') || '{}');
            if (demoUser.username !== 'demo-viewer') return;

            const OVERLAY_KEY = 'hms_demo_overlay';
            const readOverlay = () => { try { return JSON.parse(sessionStorage.getItem(OVERLAY_KEY) || '{}'); } catch (e) { return {}; } };
            const saveOverlay = (o) => sessionStorage.setItem(OVERLAY_KEY, JSON.stringify(o));
            let idSeq = Date.now() % 1000000;
            const jsonResponse = (obj, status = 200) =>
                new Response(JSON.stringify(obj), { status, headers: { 'Content-Type': 'application/json' } });

            const origFetch = window.fetch.bind(window);
            window.fetch = async function (input, opts = {}) {
                const url = typeof input === 'string' ? input : (input && input.url) || '';
                const method = ((opts && opts.method) || (typeof input !== 'string' && input && input.method) || 'GET').toUpperCase();
                const isApi = url.startsWith('/api') || url.indexOf('/api/') !== -1;

                // Auth endpoints and non-API traffic pass through untouched.
                if (!isApi || url.indexOf('/auth/') !== -1) return origFetch(input, opts);

                const path = url.split('?')[0].replace(/\/$/, '');

                if (method !== 'GET' && method !== 'HEAD') {
                    let body = {};
                    try { if (opts.body && typeof opts.body === 'string') body = JSON.parse(opts.body); } catch (e) { /* FormData etc. */ }
                    const o = readOverlay();

                    if (method === 'POST') {
                        const id = 'DEMO-' + (++idSeq);
                        const rec = Object.assign({ id, demo: true, created_at: new Date().toISOString() }, body);
                        (o[path] = o[path] || []).push(rec);
                        saveOverlay(o);
                        return jsonResponse({ success: true, demo: true, id, data: rec }, 201);
                    }

                    // PUT/PATCH/DELETE: locate an overlay record by trailing id
                    const tail = path.split('/').pop();
                    const listPath = path.substring(0, path.lastIndexOf('/'));
                    if (o[listPath]) {
                        const idx = o[listPath].findIndex(r => String(r.id) === tail);
                        if (idx !== -1) {
                            if (method === 'DELETE') o[listPath].splice(idx, 1);
                            else o[listPath][idx] = Object.assign(o[listPath][idx], body);
                            saveOverlay(o);
                        }
                    }
                    return jsonResponse({ success: true, demo: true });
                }

                // GET: pass through, then append overlay records for this path
                const res = await origFetch(input, opts);

                // Never let a 401 bounce the demo to login.php: app.js clears
                // the token and redirects on 401. Instead we silently fetch a
                // fresh demo token and reload the sandbox.
                if (res.status === 401) {
                    try {
                        const dl = await origFetch('/api/auth/demo-login', { method: 'POST', headers: { 'Accept': 'application/json' } });
                        const data = await dl.json();
                        if (data && data.token) {
                            localStorage.setItem('hms_token', data.token);
                            localStorage.setItem('hms_user', JSON.stringify(data.user));
                            localStorage.setItem('hms_tenant', JSON.stringify(data.tenant || {}));
                            window.location.reload();
                        }
                    } catch (e) { /* ignore */ }
                    return jsonResponse({}, 200);
                }

                const o = readOverlay();
                const items = o[path];
                if (!items || !items.length || !res.ok) return res;
                try {
                    const data = await res.clone().json();
                    let merged = data;
                    if (Array.isArray(data)) {
                        merged = data.concat(items);
                    } else if (data && typeof data === 'object') {
                        let done = false;
                        for (const k of Object.keys(data)) {
                            if (Array.isArray(data[k])) { data[k] = data[k].concat(items); done = true; break; }
                        }
                        if (!done) return res;
                        merged = data;
                    } else {
                        return res;
                    }
                    return jsonResponse(merged, res.status);
                } catch (e) {
                    return res;
                }
            };

            // Logout in the demo restarts the demo instead of exposing the
            // login screen (app.js's handleLogout is resolved at click time,
            // so overriding the global here is enough).
            window.addEventListener('load', () => {
                window.handleLogout = function () {
                    sessionStorage.removeItem(OVERLAY_KEY);
                    localStorage.removeItem('hms_token');
                    localStorage.removeItem('hms_user');
                    localStorage.removeItem('hms_tenant');
                    window.location.href = '/ddr/?demo=1#dashboard';
                };
            });

            // Small persistent notice so visitors know nothing is saved.
            document.addEventListener('DOMContentLoaded', () => {
                const badge = document.createElement('div');
                badge.textContent = 'Demo-Modus — Änderungen werden nicht gespeichert';
                badge.style.cssText = 'position:fixed;bottom:12px;left:12px;z-index:99999;background:#1e40af;color:#fff;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;box-shadow:0 4px 14px rgba(0,0,0,.3);pointer-events:none;';
                document.body.appendChild(badge);
            });
        })();

        // Redirect to the verification screen if the email is unconfirmed.
        // The backend enforces this too (EnsureEmailVerified), but bouncing
        // here avoids a flash of the app + a barrage of 403s on load.
        const _vu = JSON.parse(localStorage.getItem('hms_user') || '{}');
        if (_vu.email_verified === false) { window.location.href = 'verify-email.php'; }

        // Freshly invited sub-users must set their own password first. The
        // backend keeps the flag until a new password is stored, so a direct
        // navigation to the app gets bounced here too.
        if (_vu.must_change_password === true) { window.location.href = 'change-password.php'; }

        // Redirect to onboarding if not completed
        const _t = JSON.parse(localStorage.getItem('hms_tenant') || '{}');
        if (_t.onboarding_completed === false) {
            const _slugPrefix = _t.slug ? '/' + _t.slug : '';
            window.location.href = _slugPrefix + '/onboarding.html';
        }

        const _u = JSON.parse(localStorage.getItem('hms_user') || '{}');
        const CURRENT_USER = {
            id: _u.id || 0,
            username: _u.username || '',
            email: _u.email || '',
            full_name: _u.full_name || 'Benutzer',
            role: _u.role || 'viewer',
            is_owner: !!_u.is_owner,
            permissions: Array.isArray(_u.permissions) ? _u.permissions : [],
        };
        // Make CURRENT_USER addressable on window so app.js helpers can read it
        window.CURRENT_USER = CURRENT_USER;
        const CURRENT_TENANT = {
            name: _t.name || '',
            slug: _t.slug || '',
            plan: _t.plan || '',
            plan_name: _t.plan_name || '',
            is_trial: _t.is_trial || false,
            trial_days_remaining: _t.trial_days_remaining || 0,
            subscription_active: _t.subscription_active !== false,
            // logo_path was being dropped here, which made the sidebar fall
            // back to the generic building icon on every page reload even
            // though the tenant had uploaded a logo. Pass it through so
            // applyTenantBranding() picks it up on init.
            logo_path: _t.logo_path || null,
        };

        // Render (or remove) the trial banner from a tenant payload. Pulled
        // out of DOMContentLoaded so it can be re-run after /auth/check
        // refreshes the cached subscription data — otherwise a tenant whose
        // trial was just extended / upgraded would keep seeing a stale
        // "endet in 0 Tagen" banner until the next re-login.
        window.renderTrialBanner = function (tenant) {
            const existing = document.getElementById('trialBanner');
            const shouldShow = tenant && tenant.is_trial && Number(tenant.trial_days_remaining) <= 7;
            if (!shouldShow) {
                if (existing) {
                    existing.remove();
                    document.documentElement.style.setProperty('--trial-banner-h', '0px');
                    const sb0 = document.querySelector('.sidebar');
                    const mc0 = document.querySelector('.main-content');
                    if (sb0) sb0.style.top = '0px';
                    if (mc0) mc0.style.marginTop = '0px';
                }
                return;
            }
            const banner = existing || document.createElement('div');
            banner.id = 'trialBanner';
            // body uses display:flex (sidebar + main), so we pull the banner
            // out of the flex flow with position:fixed and shift the
            // sidebar/main down by its height. Otherwise the banner gets
            // squeezed into a vertical sliver showing only "…upgraden".
            banner.style.cssText = [
                'position:fixed', 'top:0', 'left:0', 'right:0',
                'z-index:1000',
                'background:#fef3c7', 'color:#92400e',
                'text-align:center', 'padding:8px 16px',
                'font-size:0.85rem', 'font-weight:600',
                'box-shadow:0 1px 3px rgba(0,0,0,0.08)',
            ].join(';');
            const days = Number(tenant.trial_days_remaining);
            banner.innerHTML = '<i class="material-symbols-outlined">warning</i> Testphase endet in ' + days + ' Tagen. <a href="#" style="color: var(--md-ext-color-on-warning-container);text-decoration: underline">Jetzt upgraden</a>';
            if (!existing) document.body.appendChild(banner);
            const applyOffset = () => {
                const h = banner.offsetHeight || 36;
                document.documentElement.style.setProperty('--trial-banner-h', h + 'px');
                const sb = document.querySelector('.sidebar');
                const mc = document.querySelector('.main-content');
                if (sb) sb.style.top = h + 'px';
                if (mc) mc.style.marginTop = h + 'px';
            };
            applyOffset();
            window.addEventListener('resize', applyOffset);
        };

        // Refresh the cached tenant/user payload from the server so a
        // subscription change (extension, upgrade, expiry) is reflected
        // without forcing a re-login. /auth/check returns the same tenant
        // shape as login (AuthController::buildTenantPayload).
        window.refreshTenantState = async function () {
            const token = localStorage.getItem('hms_token');
            if (!token) return;
            try {
                const res = await fetch('/api/auth/check', {
                    headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
                });
                if (!res.ok) return;
                const data = await res.json();
                if (!data || data.authenticated !== true || !data.tenant) return;
                localStorage.setItem('hms_tenant', JSON.stringify(data.tenant));
                if (data.user) localStorage.setItem('hms_user', JSON.stringify(data.user));
                if (typeof CURRENT_TENANT !== 'undefined') Object.assign(CURRENT_TENANT, data.tenant);
                window.renderTrialBanner(data.tenant);
            } catch (e) { /* offline / transient — keep cached banner */ }
        };

        // Populate UI
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('sidebarUserName').textContent = CURRENT_USER.full_name;
            // Friendlier role labels (German)
            const _roleLabels = {
                owner: 'Inhaber', admin: 'Administrator', manager: 'Manager',
                dispo: 'Disponent', buchhaltung: 'Buchhaltung',
                viewer: 'Viewer', custom: 'Benutzerdefiniert',
                tenant_admin: 'Administrator', super_admin: 'Super-Admin', user: 'Benutzer',
            };
            document.getElementById('sidebarUserRole').textContent =
                _roleLabels[CURRENT_USER.role] || CURRENT_USER.role;
            // Permission-based nav visibility is applied by app.js → applyPermissionGating()
            // (called inside initNavigation). The legacy role-based nav-users toggle is
            // no longer needed.
            // Show tenant name in sidebar and page title
            // Ensure tenant slug is in the browser URL
            if (CURRENT_TENANT.slug && !window.location.pathname.startsWith('/' + CURRENT_TENANT.slug)) {
                window.history.replaceState(null, '', '/' + CURRENT_TENANT.slug + '/');
            }
            if (CURRENT_TENANT.name) {
                window.applyTenantBranding && window.applyTenantBranding(CURRENT_TENANT);
                const sub = document.querySelector('.sidebar-subtitle');
                if (sub) sub.textContent = CURRENT_TENANT.plan_name || CURRENT_TENANT.plan;
                document.title = CURRENT_TENANT.name + ' — Clood-X';
            }
            // Trial banner — first paint from the cached payload (instant),
            // then refresh from /auth/check so a just-extended / upgraded
            // tenant no longer sees a stale "endet in 0 Tagen" banner.
            window.renderTrialBanner(CURRENT_TENANT);
            window.refreshTenantState();
        });
    </script>
    <script defer src="assets/js/dom-morph.js?v=20261117md3l"></script>
    <script defer src="assets/js/app.js?v=20261117abwhrs1"></script>
    <script defer src="assets/js/ui-components.js?v=20261117md3l"></script>
    <script defer src="assets/js/dashboard.js?v=20261117md3l"></script>
    <script defer src="assets/js/incoming_orders.js?v=20261117md3l"></script>
    <script defer src="assets/js/messages.js?v=20261117md3l"></script>
    <script defer src="assets/js/worker_messages.js?v=20261117md3l"></script>
    <script defer src="assets/js/notifications.js?v=20261117md3l"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/pusher-js@8/dist/web/pusher.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/laravel-echo@1/dist/echo.iife.js"></script>
    <script defer src="assets/js/realtime.js?v=20261117md3l"></script>
    <script defer src="assets/js/call.js?v=20261117md3l"></script>
    <script defer src="assets/js/ai-assistant.js?v=20261117md3l"></script>
</body>
</html>
