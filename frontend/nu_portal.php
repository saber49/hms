<?php
// Token-based auth — check is done client-side now
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NU-Portal</title>
    <link rel="stylesheet" href="assets/css/md3-tokens.css?v=20261117md3l">
    <link rel="stylesheet" href="assets/css/nu_portal.css?v=20261117md3l">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>

    <div class="nu-topbar">
        <div class="nu-topbar-left">
            <span class="nu-logo" id="nuTenantLogo">NU-Portal</span>
            <span class="nu-badge">NU-PORTAL</span>
        </div>
        <div class="nu-topbar-right">
            <button class="nu-btn-logout" onclick="nuLogout()"><i class="material-symbols-outlined">logout</i> Abmelden</button>
        </div>
    </div>

    <div class="nu-nav">
        <button class="nu-nav-tab active" data-tab="shifts" onclick="nuSwitchTab('shifts')"><i class="material-symbols-outlined">calendar_month</i> Einsätze & Positionen</button>
        <button class="nu-nav-tab" data-tab="history" onclick="nuSwitchTab('history')"><i class="material-symbols-outlined">assignment_turned_in</i> Auftragshistorie</button>
        <button class="nu-nav-tab" data-tab="workers" onclick="nuSwitchTab('workers')"><i class="material-symbols-outlined">group</i> Meine Mitarbeiter</button>
    </div>

    <div class="nu-content" id="nuContent">
        <div class="nu-empty"><i class="material-symbols-outlined ms-spin">progress_activity</i><p>Lade...</p></div>
    </div>

    <footer style="position: fixed;bottom: 0;left: 0;width: 100%;text-align: center;padding: 10px;color: var(--md-sys-color-primary);font-size: 0.75rem;background: var(--md-sys-color-primary-container);border-top: 1px solid var(--md-sys-color-primary-container);">
        Powered by <a href="https://clood-x.com" target="_blank" style="color: var(--md-sys-color-primary);text-decoration: none;font-weight: 500;">clood-x.com</a>
    </footer>

    <script>
        if (!localStorage.getItem('nu_token')) { window.location.href = 'nu_login.php'; }
        const _nu = JSON.parse(localStorage.getItem('nu_user') || '{}');

        // Extract tenant slug from URL path (e.g. /hrd-gmbh/nu_portal.php)
        const _nuSlug = (function() {
            const m = window.location.pathname.match(/^\/([a-z0-9][-a-z0-9]*)\//);
            return m ? m[1] : null;
        })();

        document.addEventListener('DOMContentLoaded', () => {
            // Set tenant name from slug in logo and title
            if (_nuSlug) {
                const tenantName = _nuSlug.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                const logo = document.getElementById('nuTenantLogo');
                if (logo) logo.textContent = tenantName;
                document.title = 'NU-Portal \u2014 ' + tenantName;
            }
        });
    </script>
    <script defer src="assets/js/material-web.bundle.js?v=20261117md3l"></script>
    <script defer src="assets/js/ui-components.js?v=20261117md3l"></script>
    <script src="assets/js/nu_portal.js?v=20261117md3l"></script>
</body>
</html>
