<?php
// Token-based auth — check is done client-side
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Kundenportal — Clood-X</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="assets/css/md3-tokens.css?v=20261114md3g">
    <link rel="stylesheet" href="assets/css/auth-md3.css?v=20261116ui1">
    <style>
        body { --auth-accent: var(--md-ext-color-success); }
    </style>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <div class="icon"><i class="material-symbols-outlined">shield</i></div>
            <h1 id="companyName">Clood-X</h1>
            <p>Transparenz für Ihre Objekte</p>
            <div class="portal-badge"><i class="material-symbols-outlined">apartment</i> KUNDENPORTAL</div>
        </div>

        <div class="login-card">
            <h2>Anmeldung</h2>
            <div class="error-message" id="errorMsg">
                <i class="material-symbols-outlined">error</i>
                <span id="errorText"></span>
            </div>
            <form id="loginForm" onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label for="username">Benutzername</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">person</i>
                        <input type="text" id="username" placeholder="Benutzername" required autocomplete="username" autofocus>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Passwort</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">lock</i>
                        <input type="password" id="password" placeholder="Passwort" required autocomplete="current-password">
                    </div>
                </div>
                <button type="submit" class="btn-login" id="btnLogin">
                    <i class="material-symbols-outlined">login</i><span>Anmelden</span>
                </button>
            </form>
        </div>

        <div class="login-footer">&copy; <?php echo date('Y'); ?> Clood-X — Kundenportal</div>
    </div>

    <script>
        const KP_API_BASE = window.location.origin + '/api';
        // Tenant slug from the URL path (e.g. /hms-gruppe-gmbh-co-kg/kunden_login.php)
        const KP_TENANT_SLUG = (function () {
            const m = window.location.pathname.match(/^\/([a-z0-9][-a-z0-9]*)\/kunden_login/);
            return m ? m[1] : null;
        })();
        if (KP_TENANT_SLUG) {
            document.getElementById('companyName').textContent =
                KP_TENANT_SLUG.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }
        if (localStorage.getItem('kp_token')) window.location.href = 'kunden_portal.php';

        async function handleLogin(e) {
            e.preventDefault();
            const btn = document.getElementById('btnLogin');
            const errorDiv = document.getElementById('errorMsg');
            const errorText = document.getElementById('errorText');
            const resetBtn = () => { btn.disabled = false; btn.innerHTML = '<i class="material-symbols-outlined">login</i><span>Anmelden</span>'; };
            btn.disabled = true;
            btn.innerHTML = '<i class="material-symbols-outlined ms-spin">progress_activity</i> Anmelden...';
            errorDiv.classList.remove('visible');
            try {
                const res = await fetch(KP_API_BASE + '/kunden-portal/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        username: document.getElementById('username').value,
                        password: document.getElementById('password').value,
                        ...(KP_TENANT_SLUG ? { tenant_slug: KP_TENANT_SLUG } : {})
                    })
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.success && data.token) {
                    localStorage.setItem('kp_token', data.token);
                    window.location.href = 'kunden_portal.php';
                    return;
                }
                errorText.textContent = res.status === 429
                    ? 'Zu viele Versuche. Bitte warten Sie einen Moment.'
                    : (data.error || 'Anmeldung fehlgeschlagen');
                errorDiv.classList.add('visible');
                resetBtn();
            } catch (err) {
                errorText.textContent = 'Verbindungsfehler. Bitte versuchen Sie es erneut.';
                errorDiv.classList.add('visible');
                resetBtn();
            }
        }
    </script>
</body>
</html>
