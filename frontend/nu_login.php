<?php
// Token-based auth — check is done client-side now
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nachunternehmer-Portal — Clood-X</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Google Sans', 'Roboto', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #062e6f 0%, #0b57d0 55%, #0842a0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container { width: 100%; max-width: 420px; }
        .login-logo { text-align: center; margin-bottom: 32px; }
        .login-logo .icon {
            width: 64px; height: 64px;
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 16px;
        }
        .login-logo .icon i { font-size: 1.8rem; color: #f59e0b; }
        .login-logo h1 { font-size: 1.5rem; color: white; font-weight: 700; }
        .login-logo p { color: rgba(255,255,255,0.5); font-size: 0.85rem; margin-top: 4px; }
        .login-logo .portal-badge {
            display: inline-block; background: rgba(245,158,11,0.2); color: #f59e0b;
            padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;
            margin-top: 10px; letter-spacing: 0.5px; border: 1px solid rgba(245,158,11,0.3);
        }
        .login-card {
            background: white; border-radius: 12px; padding: 36px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .login-card h2 { font-size: 1.2rem; color: #1a2744; margin-bottom: 24px; text-align: center; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block; font-size: 0.8rem; font-weight: 600; color: #64748b;
            text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;
        }
        .input-wrapper { position: relative; }
        .input-wrapper i {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: #94a3b8; font-size: 0.9rem;
        }
        .input-wrapper input {
            width: 100%; padding: 12px 14px 12px 42px; border: 2px solid #e2e8f0;
            border-radius: 8px; font-size: 0.95rem; font-family: inherit;
            transition: all 0.2s ease; background: #f8fafc; color: #1e293b;
        }
        .input-wrapper input:focus {
            outline: none; border-color: #f59e0b; background: white;
            box-shadow: 0 0 0 4px rgba(245,158,11,0.1);
        }
        .btn-login {
            width: 100%; padding: 13px; background: #f59e0b; color: white;
            border: none; border-radius: 8px; font-size: 1rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            font-family: inherit;
        }
        .btn-login:hover { background: #d97706; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(217,119,6,0.4); }
        .btn-login:active { transform: translateY(0); }
        .btn-login:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }
        .error-message {
            background: #fef2f2; color: #dc2626; padding: 10px 14px; border-radius: 8px;
            font-size: 0.85rem; margin-bottom: 16px; display: none; border: 1px solid #fecaca;
        }
        .error-message.visible { display: flex; align-items: center; gap: 8px; }
        .login-footer {
            text-align: center; margin-top: 24px; color: rgba(255,255,255,0.4); font-size: 0.75rem;
        }
    </style>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <div class="icon"><i class="fas fa-hard-hat"></i></div>
            <h1 id="companyName">Clood-X</h1>
            <p>Operations Management System</p>
            <div class="portal-badge"><i class="fas fa-building"></i> NACHUNTERNEHMER-PORTAL</div>
        </div>

        <div class="login-card">
            <h2>Portal-Anmeldung</h2>

            <div class="error-message" id="errorMsg">
                <i class="fas fa-exclamation-circle"></i>
                <span id="errorText"></span>
            </div>

            <form id="loginForm" onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label>Benutzername</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" placeholder="Portal-Benutzername" required autocomplete="username" autofocus>
                    </div>
                </div>
                <div class="form-group">
                    <label>Passwort</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" placeholder="Passwort" required autocomplete="current-password">
                    </div>
                </div>
                <button type="submit" class="btn-login" id="btnLogin">
                    <i class="fas fa-sign-in-alt"></i>
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
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Anmelden...';
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
