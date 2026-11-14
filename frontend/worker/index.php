<?php
// Token-based auth — the actual check happens client-side via the
// w_token in localStorage (WStore). The page itself is public HTML so it
// can be served from a CDN edge if desired. We still set strong security
// headers from PHP because the file IS executed by PHP-FPM.
//
// UNIFIED SHELL: this markup mirrors worker-app/www/index.html — the
// Capacitor app and this PWA share the exact same app.js/app.css
// (synced via worker-app/sync-from-pwa.ps1). Do not edit assets here;
// edit worker-app/www/assets/* and run the sync script.

// Content Security Policy — fully self-hosted now (Material Symbols
// replaced the Font Awesome CDN), so no third-party origins needed.
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline'; "
    . "font-src 'self' data:; "
    . "img-src 'self' data: blob: https://clood-x.com; "
    . "connect-src 'self' https://clood-x.com wss://clood-x.com; "
    . "manifest-src 'self'; "
    . "worker-src 'self'; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=()');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="#f3f4f8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Clood-X</title>
    <link rel="manifest" href="manifest.json">
    <!-- Dark mode — apply saved/system theme BEFORE first paint -->
    <script>
    (function () {
        try {
            var t = localStorage.getItem('worker_theme');
            if (!t) t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', t);
            // Admin session → this page only bridges to the web system; hide
            // the worker UI before first paint so it never flashes through
            // (same as the bundled app's index.html).
            if (localStorage.getItem('w_is_admin') === '1') {
                document.documentElement.classList.add('admin-boot');
            }
        } catch (e) { /* default: light */ }
    })();
    </script>
    <style>html.admin-boot #app { visibility: hidden; }</style>
    <!-- MD3 design tokens — must load BEFORE app.css (legacy alias bridge) -->
    <link rel="stylesheet" href="assets/css/md3-tokens.css?v=20260818uni2">
    <link rel="stylesheet" href="assets/css/app.css?v=20261111sig4">
    <!-- Material Symbols (self-hosted subset) — replaces Font Awesome -->
    <link rel="stylesheet" href="assets/css/ms-icons.css?v=20261109ico1">
    <link rel="stylesheet" href="assets/css/qm-wachbuch.css?v=20261109dark1">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
</head>
<body>
    <div id="app">
        <main id="content">
            <div class="loading"><i class="fas fa-spinner fa-spin"></i></div>
        </main>

        <nav id="bottomNav">
            <button class="nav-tab active" data-tab="feed">
                <i class="fas fa-th-large"></i>
                <span>Feed</span>
            </button>
            <button class="nav-tab" data-tab="plan">
                <i class="fas fa-calendar-alt"></i>
                <span>Plan</span>
            </button>
            <button class="nav-tab" data-tab="zeit">
                <i class="fas fa-clock"></i>
                <span>Zeit</span>
            </button>
            <button class="nav-tab" data-tab="dienst">
                <i class="fas fa-book"></i>
                <span>Dienst</span>
            </button>
            <button class="nav-tab" data-tab="frei">
                <i class="fas fa-paper-plane"></i>
                <span>Frei</span>
            </button>
            <button class="nav-tab" data-tab="chat">
                <i class="fas fa-comment-dots"></i>
                <span>Chat</span>
            </button>
            <button class="nav-tab" data-tab="profil">
                <i class="fas fa-user"></i>
                <span>Profil</span>
            </button>
        </nav>
    </div>

    <!-- Session is loaded from secure storage by storage.js and consumed by
         app.js' async bootstrap (which redirects to login if no valid token). -->
    <script src="assets/js/storage.js?v=20260818uni2"></script>
    <script src="assets/js/qm-wachbuch.js?v=20261109live1"></script>
    <script src="assets/js/qm-live.js?v=20261109live1"></script>
    <script src="assets/js/app.js?v=20261111sig4"></script>
    <script src="assets/js/call.js?v=20260929call6"></script>
    <script src="assets/js/capacitor-bridge.js?v=20261111sig4"></script>

    <script>
        // Boot watchdog: app.js sets window.__wAppLoaded the moment it runs
        // and switchTab() fills #content on boot. If a script fetch stalls
        // (flaky network, stale SW cache) the static shell paints but the
        // app never boots — show a retry instead of a silent black screen.
        setTimeout(function () {
            var c = document.getElementById('content');
            if (window.__wAppLoaded && window.__wBooted) return;
            if (document.getElementById('wBootStuck')) return;
            var d = document.createElement('div');
            d.id = 'wBootStuck';
            d.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;flex-direction:column;'
                + 'align-items:center;justify-content:center;gap:16px;background:#0b0f1a;color:#fff;'
                + 'text-align:center;padding:32px';
            d.innerHTML = '<div style="font-size:17px;font-weight:600">Verbindung langsam</div>'
                + '<div style="font-size:14px;opacity:.7">Die App konnte nicht geladen werden.</div>'
                + '<button onclick="location.reload()" style="padding:12px 32px;border:0;border-radius:10px;'
                + 'background:#3b82f6;color:#fff;font-size:15px;font-weight:600">Erneut versuchen</button>';
            document.body.appendChild(d);
        }, 12000);
    </script>

    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js?v=84').catch(() => {});
        }
    </script>
</body>
</html>
