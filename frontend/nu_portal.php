<?php
// Token-based auth — check is done client-side now
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NU-Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/md3-tokens.css?v=20260729mob8">
    <link rel="stylesheet" href="assets/css/nu_portal.css?v=20260911md31">
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
            <button class="nu-btn-logout" onclick="nuLogout()"><i class="fas fa-sign-out-alt"></i> Abmelden</button>
        </div>
    </div>

    <div class="nu-nav">
        <button class="nu-nav-tab active" data-tab="shifts" onclick="nuSwitchTab('shifts')"><i class="fas fa-calendar-alt"></i> Einsätze & Positionen</button>
        <button class="nu-nav-tab" data-tab="history" onclick="nuSwitchTab('history')"><i class="fas fa-clipboard-check"></i> Auftragshistorie</button>
        <button class="nu-nav-tab" data-tab="workers" onclick="nuSwitchTab('workers')"><i class="fas fa-users"></i> Meine Mitarbeiter</button>
    </div>

    <div class="nu-content" id="nuContent">
        <div class="nu-empty"><i class="fas fa-spinner fa-spin"></i><p>Lade...</p></div>
    </div>

    <footer style="position:fixed;bottom:0;left:0;width:100%;text-align:center;padding:10px;color:#94a3b8;font-size:0.75rem;background:#f8fafc;border-top:1px solid #e2e8f0;">
        Powered by <a href="https://clood-x.com" target="_blank" style="color:#3b82f6;text-decoration:none;font-weight:500;">clood-x.com</a>
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
    <script src="assets/js/nu_portal.js?v=20261020br1"></script>
</body>
</html>
