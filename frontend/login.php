<?php
// Token-based auth — check is done client-side now
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <script>
    /* Inside the Android app WebView this page is edge-to-edge: the login
       gradient sits under the system bars, so the bars need LIGHT icons.
       window.Capacitor isn't injected on remote pages, but the raw
       androidBridge WebMessageListener is — call the plugin directly. */
    (function () {
        var b = window.androidBridge;
        if (!b || typeof b.postMessage !== 'function') return;
        try {
            b.postMessage(JSON.stringify({
                callbackId: 'login1',
                pluginId: 'DocumentViewer',
                methodName: 'setSystemBarStyle',
                options: { darkIcons: false }
            }));
        } catch (e) {}
    })();
    </script>
    <title>Anmelden — Clood-X</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="assets/css/md3-tokens.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Google Sans', 'Roboto', 'Segoe UI', -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
            background: linear-gradient(135deg, var(--md-sys-color-primary) 0%, var(--md-sys-color-primary) 55%, var(--md-sys-color-primary) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            /* --safe-* is Android-app only (pushed natively); env() would
               double-pad on iOS — its injector already handles the insets. */
            padding: calc(20px + var(--safe-top, 0px)) 20px calc(20px + var(--safe-bottom, 0px));
        }
        .login-container {
            width: 100%;
            max-width: 420px;
        }
        .login-logo {
            text-align: center;
            margin-bottom: 32px;
        }
        .login-logo .icon {
            width: 64px;
            height: 64px;
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .login-logo .icon i {
            font-size: 1.8rem;
            color: var(--md-ref-palette-primary90, var(--md-sys-color-primary-container));
        }
        .login-logo .icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 16px;
            display: block;
        }
        .login-logo h1 {
            font-size: 1.5rem;
            color: white;
            font-weight: 700;
        }
        .login-logo p {
            color: rgba(255,255,255,0.5);
            font-size: 0.85rem;
            margin-top: 4px;
        }
        /* MD3 card: surface-container-lowest, corner-extra-large, elevation-3 */
        .login-card {
            background: var(--md-sys-color-surface-container-lowest, var(--md-sys-color-surface-container-lowest));
            border-radius: var(--md-sys-shape-corner-extra-large, 28px);
            padding: 36px;
            box-shadow: var(--md-sys-elevation-level3, 0 20px 60px color-mix(in srgb, var(--md-sys-color-scrim) 30%, transparent));
        }
        .login-card h2 {
            font-size: 1.2rem;
            font-weight: 500;
            color: var(--md-sys-color-on-surface, var(--md-sys-color-on-surface));
            margin-bottom: 24px;
            text-align: center;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--md-sys-color-on-surface-variant, var(--md-sys-color-on-surface));
            letter-spacing: 0.3px;
            margin-bottom: 6px;
        }
        .input-wrapper {
            position: relative;
        }
        .input-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--md-sys-color-on-surface-variant, var(--md-sys-color-on-surface));
            font-size: 0.9rem;
        }
        /* MD3 outlined text field */
        .input-wrapper input {
            width: 100%;
            padding: 14px 14px 14px 42px;
            border: 1px solid var(--md-sys-color-outline, var(--md-sys-color-outline));
            border-radius: var(--md-sys-shape-corner-extra-small, 4px);
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            background: transparent;
            color: var(--md-sys-color-on-surface, var(--md-sys-color-on-surface));
        }
        .input-wrapper input:focus {
            outline: none;
            border-color: var(--md-sys-color-primary, var(--md-sys-color-primary));
            box-shadow: inset 0 0 0 1px var(--md-sys-color-primary, var(--md-sys-color-primary));
        }
        .input-wrapper input:focus + i,
        .input-wrapper:focus-within i {
            color: var(--md-sys-color-primary, var(--md-sys-color-primary));
        }
        /* MD3 filled button: primary, full (pill) corner, state layers */
        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--md-sys-color-primary, var(--md-sys-color-primary));
            color: var(--md-sys-color-on-primary, #fff);
            border: none;
            border-radius: var(--md-sys-shape-corner-full, 9999px);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: box-shadow 0.15s ease, background 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: inherit;
        }
        .btn-login:hover {
            background: color-mix(in srgb, var(--md-sys-color-on-primary, var(--md-sys-color-surface-container-lowest)) 8%, var(--md-sys-color-primary, var(--md-sys-color-primary)));
            box-shadow: var(--md-sys-elevation-level1, 0 1px 2px color-mix(in srgb, var(--md-sys-color-scrim) 30%, transparent));
        }
        .btn-login:active {
            background: color-mix(in srgb, var(--md-sys-color-on-primary, var(--md-sys-color-surface-container-lowest)) 12%, var(--md-sys-color-primary, var(--md-sys-color-primary)));
            box-shadow: none;
        }
        .btn-login:disabled {
            background: color-mix(in srgb, var(--md-sys-color-on-surface, var(--md-sys-color-inverse-surface)) 12%, transparent);
            color: color-mix(in srgb, var(--md-sys-color-on-surface, var(--md-sys-color-on-surface)) 38%, transparent);
            cursor: not-allowed;
            box-shadow: none;
        }
        /* MD3 error container */
        .error-message {
            background: var(--md-sys-color-error-container, var(--md-sys-color-error-container));
            color: var(--md-sys-color-on-error-container, var(--md-sys-color-on-error-container));
            padding: 12px 14px;
            border-radius: var(--md-sys-shape-corner-medium, 12px);
            font-size: 0.85rem;
            margin-bottom: 16px;
            display: none;
        }
        .error-message.visible {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .login-footer {
            text-align: center;
            margin-top: 24px;
            color: rgba(255,255,255,0.4);
            font-size: 0.75rem;
        }
        /* Brand link (top-left) — back to the landing page */
        .brand-home {
            position: fixed;
            top: 20px;
            left: 24px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            padding: 8px 14px 8px 8px;
            border-radius: 9999px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.14);
            backdrop-filter: blur(8px);
            transition: background 0.2s, transform 0.2s;
            z-index: 10;
        }
        .brand-home:hover {
            background: rgba(255,255,255,0.16);
            transform: translateY(-1px);
        }
        .brand-home .brand-mark {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            overflow: hidden;
            display: inline-flex;
        }
        .brand-home .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .brand-home .brand-name {
            color: white;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.2px;
        }
        @media (max-width: 480px) {
            .brand-home { top: 12px; left: 12px; }
        }
    
.material-symbols-outlined.ms-spin, .ms-spin { animation: msSpin 1s linear infinite; }
@keyframes msSpin { to { transform: rotate(360deg); } }
</style>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <a href="/landing.html" class="brand-home" title="Zur Startseite">
        <span class="brand-mark"><img src="assets/img/clood-x-logo.png" alt="Clood-X Logo"></span>
        <span class="brand-name">Clood-X</span>
    </a>

    <div class="login-container">
        <div class="login-logo">
            <div class="icon"><img src="assets/img/clood-x-logo.png" alt="Clood-X"></div>
            <h1>Clood-X</h1>
            <p>Operations Management System</p>
        </div>

        <div class="login-card">
            <h2>Anmelden</h2>

            <div class="error-message" id="errorMsg">
                <i class="material-symbols-outlined">error</i>
                <span id="errorText"></span>
            </div>

            <form id="loginForm" onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label>E-Mail</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">person</i>
                        <input type="email" id="login" placeholder="E-Mail" required autocomplete="email" autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label>Passwort</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">lock</i>
                        <input type="password" id="password" placeholder="Passwort eingeben" required autocomplete="current-password">
                    </div>
                </div>

                <button type="submit" class="btn-login" id="btnLogin">
                    <i class="material-symbols-outlined">login</i>
                    <span>Anmelden</span>
                </button>

                <div style="text-align:center;margin-top:18px">
                    <a href="forgot-password.php" style="color: var(--md-sys-color-primary,var(--md-sys-color-primary));text-decoration: none;font-size: 0.85rem;font-weight: 500">
                        Passwort vergessen?
                    </a>
                </div>
                <div style="text-align: center;margin-top: 14px;padding-top: 14px;border-top: 1px solid var(--md-sys-color-outline-variant,var(--md-sys-color-outline));font-size: 0.85rem;color: var(--md-sys-color-on-surface-variant,var(--md-sys-color-on-surface))">
                    Noch kein Konto?
                    <a href="register.html" style="color: var(--md-sys-color-primary,var(--md-sys-color-primary));text-decoration: none;font-weight: 600">Jetzt registrieren</a>
                </div>
            </form>
        </div>

        <div class="login-footer">
            &copy; <?php echo date('Y'); ?> Clood-X — Alle Rechte vorbehalten<br>
            <a href="impressum.php" style="color: rgba(255,255,255,.55);text-decoration: none;margin: 0 6px">Impressum</a>·<a href="datenschutz.php" style="color: rgba(255,255,255,.55);text-decoration: none;margin: 0 6px">Datenschutz</a>·<a href="agb.php" style="color: rgba(255,255,255,.55);text-decoration: none;margin: 0 6px">AGB</a>
        </div>
    </div>

    <script>
        const API_BASE = window.location.origin + '/api';

        // SECURITY: never allow a real login from inside an iframe (the
        // landing-page demo embed). A session started there would replace
        // the sandboxed demo token with a fully privileged one inside the
        // public demo window. Visitors must open a new tab to log in.
        if (window.top !== window.self) {
            document.addEventListener('DOMContentLoaded', function () {
                document.body.innerHTML =
                    '<div style="min-height: 100vh;display: flex;align-items: center;justify-content: center;font-family: Roboto,sans-serif;background: var(--md-sys-color-primary-container);padding: 24px;text-align: center">' +
                    '<div style="background: var(--md-sys-color-surface-container-lowest);border-radius: 16px;padding: 40px;max-width: 420px;box-shadow: 0 10px 40px color-mix(in srgb, var(--md-sys-color-scrim) 12%, transparent)">' +
                    '<div style="font-size:2rem;margin-bottom:12px">&#128274;</div>' +
                    '<h2 style="margin: 0 0 8px;font-size: 1.2rem;color: var(--md-sys-color-on-surface)">Anmeldung hier nicht m&ouml;glich</h2>' +
                    '<p style="color: var(--md-sys-color-on-surface);font-size: .95rem;margin: 0 0 20px">Dies ist die eingebettete Live-Demo. Bitte &ouml;ffnen Sie die Anmeldung in einem neuen Tab.</p>' +
                    '<a href="/login.php" target="_blank" rel="noopener" style="display: inline-block;background: var(--md-sys-color-primary);color: #fff;padding: 12px 28px;border-radius: 24px;text-decoration: none;font-weight: 600">Login in neuem Tab &ouml;ffnen</a>' +
                    '</div></div>';
            });
            throw new Error('login blocked inside iframe');
        }

        let storedUser = {};
        try {
            storedUser = JSON.parse(localStorage.getItem('hms_user') || '{}');
        } catch (e) {}
        if (storedUser.username === 'demo-viewer') {
            localStorage.removeItem('hms_token');
            localStorage.removeItem('hms_user');
            localStorage.removeItem('hms_tenant');
            storedUser = {};
        }

        // If already logged in, redirect
        if (localStorage.getItem('hms_token')) {
            const tenant = JSON.parse(localStorage.getItem('hms_tenant') || '{}');
            const slugPrefix = tenant.slug ? '/' + tenant.slug : '';
            if (storedUser.email_verified === false) {
                window.location.href = 'verify-email.php';
            } else if (storedUser.must_change_password === true) {
                window.location.href = 'change-password.php';
            } else if (tenant.onboarding_completed === false) {
                window.location.href = slugPrefix + '/onboarding.html';
            } else {
                window.location.href = slugPrefix + '/';
            }
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
                const res = await fetch(API_BASE + '/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        login: document.getElementById('login').value.trim(),
                        password: document.getElementById('password').value
                    })
                });

                const data = await res.json();

                if (data.success && data.token) {
                    localStorage.setItem('hms_token', data.token);
                    localStorage.setItem('hms_user', JSON.stringify(data.user));
                    localStorage.setItem('hms_tenant', JSON.stringify(data.tenant || {}));

                    // Unconfirmed email → send the user to the verification
                    // screen instead of the app (server enforces this too).
                    if (data.user && data.user.email_verified === false) {
                        window.location.href = 'verify-email.php';
                        return;
                    }

                    // Freshly invited sub-user with a temporary password must
                    // pick their own before reaching the app (server enforces
                    // the flag; this avoids a flash of the app).
                    if (data.user && data.user.must_change_password === true) {
                        window.location.href = 'change-password.php';
                        return;
                    }

                    // Redirect to onboarding if not completed
                    const slugPrefix = data.tenant && data.tenant.slug ? '/' + data.tenant.slug : '';
                    if (data.tenant && data.tenant.onboarding_completed === false) {
                        window.location.href = slugPrefix + '/onboarding.html';
                    } else {
                        window.location.href = slugPrefix + '/';
                    }
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
