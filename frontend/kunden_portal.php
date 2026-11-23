<?php
// Token-based auth — check is done client-side
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Kundenportal</title>
    <link rel="stylesheet" href="assets/css/md3-tokens.css?v=20261114md3g">
    <link rel="stylesheet" href="assets/css/kunden_portal.css?v=20261118qmfix1">
    <link rel="stylesheet" href="assets/css/kunden_portal_qm.css?v=20261114md3g">
    <link rel="stylesheet" href="/assets/css/material-symbols.css?v=20261119ms1">
    <script>
    (function () {
        // App handoff: the shared Clood-X app login passes the portal session
        // in the URL fragment (#kp_sso=1&t=<token> — never sent to the server).
        if (location.hash.indexOf('kp_sso=1') !== -1) {
            try {
                var t = new URLSearchParams(location.hash.substring(1)).get('t');
                if (t) { localStorage.setItem('kp_token', t); localStorage.setItem('kp_app_shell', '1'); }
            } catch (e) {}
            history.replaceState(null, '', location.pathname + location.search);
        }
        if (localStorage.getItem('kp_app_shell') === '1') {
            document.documentElement.classList.add('app-shell');
            window.__cxSafeArea = 1; // iOS: page handles its own safe areas
        }
        if (!localStorage.getItem('kp_token')) {
            location.replace(localStorage.getItem('kp_app_shell') === '1' ? '/worker/login.php' : 'kunden_login.php');
        }
    })();
    // Android app WebView: light status-bar icons on the blue topbar + real bar heights.
    (function () {
        var b = window.androidBridge;
        if (!b || typeof b.postMessage !== 'function') return;
        var post = function (cb, method, opts) {
            try { b.postMessage(JSON.stringify({ callbackId: cb, pluginId: 'DocumentViewer', methodName: method, options: opts || {} })); } catch (e) {}
        };
        var extFallback = null;
        b.onmessage = function (e) {
            var d; try { d = JSON.parse(e && e.data !== undefined ? e.data : e); } catch (x) { return; }
            if (d && d.callbackId === 'kpInsets' && d.data) {
                var s = document.documentElement.style;
                if (typeof d.data.top === 'number') s.setProperty('--safe-top', d.data.top + 'px');
                if (typeof d.data.bottom === 'number') s.setProperty('--safe-bottom', d.data.bottom + 'px');
            }
            if (d && d.callbackId === 'kpExt' && extFallback) {
                var fb = extFallback; extFallback = null;
                if (d.success === false || d.error) fb();
            }
        };
        // External links → system browser (openExternal, app 7.0+). Older builds
        // reject the unknown method → open in the app as before.
        document.addEventListener('click', function (ev) {
            var a = ev.target.closest && ev.target.closest('a[target="_blank"]');
            if (!a || !/^https?:/.test(a.href)) return;
            ev.preventDefault();
            var href = a.href;
            extFallback = function () { location.href = href; };
            post('kpExt', 'openExternal', { url: href });
            // No reply (old bridge): fall back — unless the browser already took over.
            setTimeout(function () {
                if (extFallback && document.visibilityState === 'visible') extFallback();
                extFallback = null;
            }, 1500);
        });
        // Light glyphs on the blue topbar, dark ones over the light page bottom
        // (navDarkIcons is ignored by older app builds).
        post('kpBars', 'setSystemBarStyle', { darkIcons: false, navDarkIcons: true });
        post('kpInsets', 'getSafeAreaInsets');
    })();
    </script>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div class="kp-head">
    <header class="kp-topbar">
        <div class="kp-topbar-left">
            <i class="material-symbols-outlined kp-topbar-icon">shield</i>
            <div>
                <div class="kp-title" id="kpKunde">Kundenportal</div>
                <div class="kp-subtitle" id="kpFirma"></div>
            </div>
        </div>
        <button class="kp-btn-logout" onclick="kpPasswortDialog()" title="Passwort ändern"><i class="material-symbols-outlined">vpn_key</i><span>Passwort</span></button>
        <button class="kp-btn-logout" onclick="kpLogout()" title="Abmelden"><i class="material-symbols-outlined">logout</i><span>Abmelden</span></button>
    </header>

    <nav class="kp-nav">
        <button class="kp-tab active" data-tab="einsaetze" onclick="kpSwitchTab('einsaetze')"><i class="material-symbols-outlined">calendar_month</i> Einsätze</button>
        <button class="kp-tab" data-tab="wachbuch" onclick="kpSwitchTab('wachbuch')"><i class="material-symbols-outlined">menu_book</i> <span id="kpObTab" style="visibility:hidden">Wachbuch</span></button>
        <button class="kp-tab" data-tab="fotos" onclick="kpSwitchTab('fotos')"><i class="material-symbols-outlined">photo_library</i> Fotos</button>
        <button class="kp-tab" data-tab="dienstanweisungen" data-qm hidden onclick="kpSwitchTab('dienstanweisungen')"><i class="material-symbols-outlined">privacy_tip</i> Dienstanweisungen</button>
        <button class="kp-tab" data-tab="reklamationen" data-qm hidden onclick="kpSwitchTab('reklamationen')"><i class="material-symbols-outlined">forum</i> Reklamationen</button>
    </nav>
    </div>

    <section class="kp-toolbar">
        <div class="kp-week">
            <button class="kp-icon-btn" onclick="kpShiftWeek(-1)" title="Vorherige Woche"><i class="material-symbols-outlined">chevron_left</i></button>
            <div class="kp-week-label" id="kpWeekLabel"></div>
            <button class="kp-icon-btn" onclick="kpShiftWeek(1)" title="Nächste Woche"><i class="material-symbols-outlined">chevron_right</i></button>
            <button class="kp-chip-btn" id="kpTodayBtn" onclick="kpThisWeek()">Heute</button>
        </div>
        <select id="kpObjekt" class="kp-select" onchange="kpLoad()">
            <option value="">Alle Objekte</option>
        </select>
    </section>

    <main class="kp-content" id="kpContent">
        <div class="kp-empty"><i class="material-symbols-outlined ms-spin">progress_activity</i><p>Lade...</p></div>
    </main>

    <div class="kp-lightbox" id="kpLightbox" onclick="kpCloseLightbox()">
        <button class="kp-lightbox-close" title="Schließen"><i class="material-symbols-outlined">close</i></button>
        <img id="kpLightboxImg" alt="">
        <div class="kp-lightbox-caption" id="kpLightboxCaption"></div>
        <a class="kp-lightbox-dl" id="kpLightboxDl" href="#" download onclick="event.stopPropagation()"><i class="material-symbols-outlined">download</i> Herunterladen</a>
    </div>

    <footer class="kp-footer">
        Powered by <a href="https://clood-x.com" target="_blank" rel="noopener">clood-x.com</a>
    </footer>

    <script src="assets/js/kunden_portal.js?v=20261123appback1"></script>
    <!-- QM module sections (register in KP_TABS; order irrelevant, after the core) -->
    <script src="/worker/assets/js/qm-live.js?v=20261114md3g"></script>
    <script src="assets/js/kunden_portal/qm-konto.js?v=20261114md3g"></script>
    <script src="assets/js/kunden_portal/qm-wachbuch.js?v=20261123bericht1"></script>
    <script src="assets/js/kunden_portal/qm-qualitaet.js?v=20261114md3g"></script>
</body>
</html>
