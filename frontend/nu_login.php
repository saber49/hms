<?php
// Token-based auth — check is done client-side now
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nachunternehmer-Portal — Clood-X</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="assets/css/md3-tokens.css?v=20261114md3g">
    <link rel="stylesheet" href="assets/css/auth-md3.css?v=20261116ui1">
    <style>
        body { --auth-accent: var(--md-ext-color-warning); }
    </style>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <div class="icon"><i class="material-symbols-outlined">engineering</i></div>
            <h1 id="companyName">Clood-X</h1>
            <p>Operations Management System</p>
            <div class="portal-badge"><i class="material-symbols-outlined">apartment</i> NACHUNTERNEHMER-PORTAL</div>
        </div>

        <div class="login-card">
            <h2>Portal-Anmeldung</h2>

            <div class="error-message" id="errorMsg">
                <i class="material-symbols-outlined">error</i>
                <span id="errorText"></span>
            </div>

            <form id="loginForm" onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label>Benutzername</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">person</i>
                        <input type="text" id="username" placeholder="Portal-Benutzername" required autocomplete="username" autofocus>
                    </div>
                </div>
                <div class="form-group">
                    <label>Passwort</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">lock</i>
                        <input type="password" id="password" placeholder="Passwort" required autocomplete="current-password">
                    </div>
                </div>
                <button type="submit" class="btn-login" id="btnLogin">
                    <i class="material-symbols-outlined">login</i>
                    <span>Anmelden</span>
                </button>
            </form>
        </div>

        <div class="login-footer">
            &copy; <?php echo date('Y'); ?> Clood-X — Nachunternehmer-Portal
        </div>
    </div>

    <script>
        const NU_API_BASE = window.location.origin + '/api';
        // Auto-detect tenant slug from URL path (e.g. /hrd-gmbh/nu_login.php)
        const NU_TENANT_SLUG = (function() {
            const match = window.location.pathname.match(/^\/([a-z0-9_-]+)\/nu_login/);
            return match ? match[1] : null;
        })();

        // If already logged in, redirect
        if (localStorage.getItem('nu_token')) {
            window.location.href = 'nu_portal.php';
        }

        async function handleLogin(e) {
            e.preventDefault();
            const btn = document.getElementById('btnLogin');
            const errorDiv = document.getElementById('errorMsg');
            const errorText = document.getElementById('errorText');
            btn.disabled = true;
            btn.innerHTML = '<i class="material-symbols-outlined ms-spin">progress_activity</i> Anmelden...';
            errorDiv.classList.remove('visible');
            try {
                const res = await fetch(NU_API_BASE + '/nu-portal/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        username: document.getElementById('username').value,
                        password: document.getElementById('password').value,
                        ...(NU_TENANT_SLUG ? { tenant_slug: NU_TENANT_SLUG } : {})
                    })
                });
                const data = await res.json();
                if (data.success && data.token) {
                    localStorage.setItem('nu_token', data.token);
                    localStorage.setItem('nu_user', JSON.stringify(data.user || {}));
                    window.location.href = 'nu_portal.php';
                } else {
                    errorText.textContent = data.error || 'Anmeldung fehlgeschlagen';
                    errorDiv.classList.add('visible');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="material-symbols-outlined">login</i> Anmelden';
                }
            } catch (err) {
                errorText.textContent = 'Verbindungsfehler. Bitte versuchen Sie es erneut.';
                errorDiv.classList.add('visible');
                btn.disabled = false;
                btn.innerHTML = '<i class="material-symbols-outlined">login</i> Anmelden';
            }
        }
    </script>
</body>
</html>
