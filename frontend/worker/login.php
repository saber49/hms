<?php
// Token-based auth — the actual check happens client-side via the
// w_token in localStorage. Set strong security headers; this page is
// the first thing every worker hits, so a misconfigured CSP would
// break the whole app.
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
    . "font-src 'self' https://cdnjs.cloudflare.com data:; "
    . "img-src 'self' data: blob:; "
    . "connect-src 'self' https://clood-x.com; "
    . "manifest-src 'self'; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0b57d0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Clood-X — Anmelden</title>
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --primary: #0b57d0;
            --primary-dark: #0842a0;
            --accent: #0b57d0;
            --accent-hover: #0842a0;
            --bg: #f6f8fc;
            --card: #ffffff;
            --text: #1f1f1f;
            --text-secondary: #5f6368;
            --border: #e8eaed;
            --danger: #d93025;
            --danger-bg: #fce8e6;
            --radius: 12px;
            --font: 'Google Sans', 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            -webkit-font-smoothing: antialiased;
        }
        .login-container {
            width: 100%;
            max-width: 380px;
        }
        .login-logo {
            text-align: center;
            margin-bottom: 32px;
        }
        .login-logo-icon {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            overflow: hidden;
        }
        .login-logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .login-logo h1 {
            font-size: 22px;
            font-weight: 700;
        }
        .login-logo p {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 4px;
        }
        .login-card {
            background: var(--card);
            border-radius: var(--radius);
            padding: 28px 24px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }
        .input-wrap {
            position: relative;
        }
        .input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 15px;
        }
        .input-wrap input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 15px;
            font-family: var(--font);
            color: var(--text);
            background: var(--bg);
            outline: none;
            transition: border-color 0.2s;
        }
        .input-wrap input:focus {
            border-color: var(--accent);
            background: var(--card);
        }
        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: var(--md-sys-color-on-primary);
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            font-family: var(--font);
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 4px;
        }
        .btn-login:hover { background: var(--accent-hover); }
        .btn-login:disabled { opacity: 0.6; cursor: not-allowed; }
        .error-msg {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid var(--md-sys-color-error-container);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 16px;
            display: none;
            align-items: center;
            gap: 8px;
        }
        .error-msg.visible { display: flex; }
        .pw-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 16px;
            cursor: pointer;
            padding: 4px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <div class="login-logo-icon"><img src="assets/icons/clood-x-logo.png" alt="Clood-X"></div>
            <h1 id="companyName">Clood-X</h1>
            <p>Melden Sie sich mit Ihrer E-Mail-Adresse an</p>
        </div>
        <div class="login-card">
            <div class="error-msg" id="errorMsg">
                <i class="fas fa-exclamation-circle"></i>
                <span id="errorText"></span>
            </div>
            <form onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label>E-Mail oder Benutzername</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope"></i>
                        <input type="text" id="emailInput" placeholder="name@firma.de" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required autofocus>
                    </div>
                </div>
                <div class="form-group">
                    <label>Passwort</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" placeholder="Passwort eingeben" autocomplete="current-password" required style="padding-right:42px">
                        <button type="button" class="pw-toggle" onclick="togglePw()" tabindex="-1"><i class="fas fa-eye" id="pwIcon"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn-login" id="btnLogin">
                    <i class="fas fa-sign-in-alt"></i> Anmelden
                </button>
            </form>
            <a href="forgot-password.php" style="display:block;text-align:center;margin-top:16px;color:#3b82f6;text-decoration:none;font-size:0.88rem;font-weight:500">Passwort vergessen?</a>
        </div>
    </div>

    <script>
        function togglePw() {
            const inp = document.getElementById('password');
            const icon = document.getElementById('pwIcon');
            if (inp.type === 'password') { inp.type = 'text'; icon.className = 'fas fa-eye-slash'; }
            else { inp.type = 'password'; icon.className = 'fas fa-eye'; }
        }

        const W_API_BASE = window.location.origin + '/api';

        // Kundenportal session (customer login) — the token travels in the
        // URL fragment only.
        const KP_BASE = W_API_BASE.replace(/\/api$/, '');
        function openKundenPortal(token, slug) {
            window.location.replace(KP_BASE + '/' + (slug ? encodeURIComponent(slug) + '/' : '')
                + 'kunden_portal.php#kp_sso=1&t=' + encodeURIComponent(token));
        }

        // If already logged in, redirect straight to the app.
        if (localStorage.getItem('w_token')) {
            window.location.href = 'index.php';
        } else if (localStorage.getItem('w_kp_token')) {
            // Remembered customer session: reopen the portal if still valid.
            const kpt = localStorage.getItem('w_kp_token');
            fetch(W_API_BASE + '/kunden-portal/me', { headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + kpt } })
                .then(r => {
                    if (r.ok) openKundenPortal(kpt, localStorage.getItem('w_kp_slug'));
                    else if (r.status === 401) { localStorage.removeItem('w_kp_token'); localStorage.removeItem('w_kp_slug'); }
                })
                .catch(() => { /* offline — stay on login */ });
        }

        // Clean up legacy tenant-slug state from previous app versions
        // so it can't leak into other features by accident.
        localStorage.removeItem('w_tenant_slug');

        async function handleLogin(e) {
            e.preventDefault();
            const btn = document.getElementById('btnLogin');
            const errorDiv = document.getElementById('errorMsg');
            const errorText = document.getElementById('errorText');

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Anmelden...';
            errorDiv.classList.remove('visible');

            try {
                const res = await fetch(W_API_BASE + '/worker-app/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        email: document.getElementById('emailInput').value.trim(),
                        password: document.getElementById('password').value
                    })
                });

                const data = await res.json();

                if (data.success && data.token) {
                    localStorage.removeItem('w_is_admin');
                    localStorage.setItem('w_token', data.token);
                    localStorage.setItem('w_user', JSON.stringify(data.user || {}));
                    window.location.href = 'index.php';
                } else {
                    // Zweiter Versuch: Admin-Konto des Hauptsystems. Erfolgreiche
                    // Admins sehen in der App ein Dashboard statt der Worker-Screens.
                    let admin = null;
                    try {
                        const res2 = await fetch(W_API_BASE + '/auth/login', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({
                                email: document.getElementById('emailInput').value.trim(),
                                password: document.getElementById('password').value
                            })
                        });
                        const d2 = await res2.json();
                        if (d2 && d2.success && d2.token) admin = d2;
                    } catch (e2) { /* Verbindungsfehler — Original-Fehler anzeigen */ }

                    if (admin) {
                        const u = admin.user || {};
                        // Pre-paint hint: index.php hides the worker UI for admins.
                        localStorage.setItem('w_is_admin', '1');
                        localStorage.setItem('w_token', admin.token);
                        localStorage.setItem('w_user', JSON.stringify({
                            is_admin: true,
                            name: u.full_name || u.username || '',
                            email: u.email || '',
                            role: u.role || '',
                            tenant_name: (admin.tenant && admin.tenant.name) ? admin.tenant.name : ''
                        }));
                        window.location.href = 'index.php';
                        return;
                    }

                    // Dritter Versuch: Kundenportal-Zugang (Benutzername des Kunden).
                    try {
                        const res3 = await fetch(W_API_BASE + '/kunden-portal/login', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({
                                username: document.getElementById('emailInput').value.trim(),
                                password: document.getElementById('password').value
                            })
                        });
                        const d3 = await res3.json();
                        if (d3 && d3.success && d3.token) {
                            localStorage.setItem('w_kp_token', d3.token);
                            localStorage.setItem('w_kp_slug', d3.tenant_slug || '');
                            openKundenPortal(d3.token, d3.tenant_slug);
                            return;
                        }
                    } catch (e3) { /* Verbindungsfehler — Original-Fehler anzeigen */ }

                    errorText.textContent = data.error || 'Anmeldung fehlgeschlagen';
                    errorDiv.classList.add('visible');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Anmelden';
                }
            } catch (err) {
                errorText.textContent = 'Verbindungsfehler. Bitte versuchen Sie es erneut.';
                errorDiv.classList.add('visible');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Anmelden';
            }
        }
    </script>
</body>
</html>
