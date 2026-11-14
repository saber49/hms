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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Google Sans', 'Roboto', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, var(--md-sys-color-primary) 0%, var(--md-sys-color-primary) 55%, var(--md-sys-color-primary) 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .login-container { width: 100%; max-width: 420px; }
        .login-logo { text-align: center; margin-bottom: 32px; }
        .login-logo .icon {
            width: 64px; height: 64px; background: rgba(255,255,255,0.1); border-radius: 16px;
            display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;
        }
        .login-logo .icon i { font-size: 1.8rem; color: var(--md-ext-color-success); }
        .login-logo h1 { font-size: 1.5rem; color: white; font-weight: 700; }
        .login-logo p { color: rgba(255,255,255,0.6); font-size: 0.85rem; margin-top: 4px; }
        .login-logo .portal-badge {
            display: inline-block; background: color-mix(in srgb, var(--md-ext-color-success) 18%, transparent); color: var(--md-ext-color-success);
            padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;
            margin-top: 10px; letter-spacing: 0.5px; border: 1px solid color-mix(in srgb, var(--md-ext-color-success) 35%, transparent);
        }
        .login-card { background: white; border-radius: 12px; padding: 36px; box-shadow: 0 20px 60px color-mix(in srgb, var(--md-sys-color-scrim) 30%, transparent); }
        .login-card h2 { font-size: 1.2rem; color: var(--md-sys-color-on-primary-container); margin-bottom: 24px; text-align: center; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block; font-size: 0.8rem; font-weight: 600; color: var(--md-sys-color-primary);
            text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;
        }
        .input-wrapper { position: relative; }
        .input-wrapper i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--md-sys-color-primary); font-size: 0.9rem; }
        .input-wrapper input {
            width: 100%; padding: 12px 14px 12px 42px; border: 2px solid var(--md-sys-color-primary-container); border-radius: 8px;
            font-size: 0.95rem; font-family: inherit; transition: all 0.2s ease; background: var(--md-sys-color-primary-container); color: var(--md-sys-color-on-primary-container);
        }
        .input-wrapper input:focus { outline: none; border-color: var(--md-sys-color-primary); background: white; box-shadow: 0 0 0 4px color-mix(in srgb, var(--md-sys-color-primary) 10%, transparent); }
        .btn-login {
            width: 100%; padding: 13px; background: var(--md-sys-color-primary); color: white; border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease;
            display: flex; align-items: center; justify-content: center; gap: 8px; font-family: inherit;
        }
        .btn-login:hover { background: var(--md-sys-color-primary); }
        .btn-login:disabled { background: var(--md-sys-color-primary); cursor: not-allowed; }
        .error-message {
            background: var(--md-sys-color-error-container); color: var(--md-sys-color-error); padding: 10px 14px; border-radius: 8px;
            font-size: 0.85rem; margin-bottom: 16px; display: none; border: 1px solid var(--md-sys-color-error-container);
        }
        .error-message.visible { display: flex; align-items: center; gap: 8px; }
        .login-footer { text-align: center; margin-top: 24px; color: rgba(255,255,255,0.5); font-size: 0.75rem; }
    
.material-symbols-outlined.ms-spin, .ms-spin { animation: msSpin 1s linear infinite; }
@keyframes msSpin { to { transform: rotate(360deg); } }
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
