<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Mail bestätigen — Clood-X</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="assets/css/md3-tokens.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Google Sans','Roboto','Segoe UI',-apple-system,sans-serif;
            -webkit-font-smoothing:antialiased;
            background:linear-gradient(135deg,#062e6f 0%,#0b57d0 55%,#0842a0 100%);
            min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px;
        }
        .container { width:100%; max-width:460px; }
        .logo { text-align:center; margin-bottom:32px; }
        .logo .icon {
            width:64px; height:64px; background:rgba(255,255,255,0.1); border-radius:16px;
            display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;
        }
        .logo .icon i { font-size:1.8rem; color:var(--md-ref-palette-primary90,#d3e3fd); }
        .logo h1 { font-size:1.5rem; color:white; font-weight:700; }
        .logo p  { color:rgba(255,255,255,0.5); font-size:0.85rem; margin-top:4px; }
        .card {
            background:var(--md-sys-color-surface-container-lowest,#fff);
            border-radius:var(--md-sys-shape-corner-extra-large,28px);
            padding:36px;
            box-shadow:var(--md-sys-elevation-level3,0 20px 60px rgba(0,0,0,0.3));
            text-align:center;
        }
        .state-icon { font-size:3rem; margin-bottom:18px; }
        .state-icon.busy   { color:var(--md-sys-color-primary,#0b57d0); }
        .state-icon.ok     { color:var(--md-ext-color-success,#146c2e); }
        .state-icon.err    { color:var(--md-sys-color-error,#b3261e); }
        .state-icon.info   { color:var(--md-sys-color-primary,#0b57d0); }
        .card h2 { font-size:1.25rem; font-weight:500; color:var(--md-sys-color-on-surface,#1f1f1f); margin-bottom:10px; }
        .card .lead { font-size:0.92rem; color:var(--md-sys-color-on-surface-variant,#44474e); margin-bottom:24px; line-height:1.5; }
        .card .lead strong { color:var(--md-sys-color-on-surface,#1f1f1f); }
        /* MD3 filled + tonal buttons */
        .btn {
            width:100%; padding:14px;
            background:var(--md-sys-color-primary,#0b57d0);
            color:var(--md-sys-color-on-primary,#fff); border:none;
            border-radius:var(--md-sys-shape-corner-full,9999px);
            font-size:0.95rem; font-weight:500; cursor:pointer;
            transition:box-shadow 0.15s ease, background 0.15s ease;
            display:flex; align-items:center; justify-content:center; gap:8px;
            font-family:inherit; text-decoration:none; margin-bottom:12px;
        }
        .btn:hover {
            background:color-mix(in srgb, var(--md-sys-color-on-primary,#fff) 8%, var(--md-sys-color-primary,#0b57d0));
            box-shadow:var(--md-sys-elevation-level1,0 1px 2px rgba(0,0,0,0.3));
        }
        .btn:disabled {
            background:color-mix(in srgb, var(--md-sys-color-on-surface,#1f1f1f) 12%, transparent);
            color:color-mix(in srgb, var(--md-sys-color-on-surface,#1f1f1f) 38%, transparent);
            cursor:not-allowed; box-shadow:none;
        }
        .btn.secondary {
            background:var(--md-sys-color-secondary-container,#dbe2f9);
            color:var(--md-sys-color-on-secondary-container,#131c2b);
        }
        .btn.secondary:hover {
            background:color-mix(in srgb, var(--md-sys-color-on-secondary-container,#131c2b) 8%, var(--md-sys-color-secondary-container,#dbe2f9));
            box-shadow:none;
        }
        /* MD3 containers for alerts */
        .alert {
            padding:12px 16px; border-radius:var(--md-sys-shape-corner-medium,12px);
            font-size:0.86rem; margin-bottom:18px;
            display:none; align-items:center; gap:10px; text-align:left;
        }
        .alert.visible { display:flex; }
        .alert.success { background:var(--md-ext-color-success-container,#c4eed0); color:var(--md-ext-color-on-success-container,#072711); }
        .alert.error   { background:var(--md-sys-color-error-container,#f9dedc); color:var(--md-sys-color-on-error-container,#410e0b); }
        .back-link { display:block; text-align:center; margin-top:8px; color:var(--md-sys-color-primary,#0b57d0); text-decoration:none; font-size:0.85rem; font-weight:500; }
        .back-link:hover { text-decoration:underline; }
        .footer { text-align:center; margin-top:24px; color:rgba(255,255,255,0.4); font-size:0.75rem; }
    </style>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="icon"><i class="fas fa-envelope-circle-check"></i></div>
            <h1>Clood-X</h1>
            <p>Operations Management System</p>
        </div>

        <div class="card">
            <div class="state-icon busy" id="stateIcon"><i class="fas fa-spinner fa-spin"></i></div>
            <h2 id="title">Einen Moment…</h2>
            <p class="lead" id="lead">Ihre E-Mail-Adresse wird bestätigt.</p>

            <div class="alert" id="alertBox"><i class="fas fa-info-circle"></i><span id="alertText"></span></div>

            <div id="actions"></div>
            <a href="login.php" class="back-link" onclick="backToLogin(event)"><i class="fas fa-arrow-left"></i> Zurück zur Anmeldung</a>
        </div>

        <div class="footer">&copy; <?php echo date('Y'); ?> Clood-X</div>
    </div>

    <script>
        const API_BASE = window.location.origin + '/api';
        const params = new URLSearchParams(window.location.search);
        const token  = params.get('token') || '';
        const email  = params.get('email') || '';

        const $ = (id) => document.getElementById(id);
        function setState(icon, cls, title, lead) {
            $('stateIcon').className = 'state-icon ' + cls;
            $('stateIcon').innerHTML = '<i class="fas ' + icon + '"></i>';
            $('title').textContent = title;
            $('lead').innerHTML = lead;
        }
        function showAlert(type, msg) {
            const a = $('alertBox');
            a.className = 'alert visible ' + type;
            $('alertText').textContent = msg;
        }

        // Patch the cached user so the app shell stops redirecting back here.
        function markVerifiedLocally() {
            try {
                const u = JSON.parse(localStorage.getItem('hms_user') || '{}');
                if (u && typeof u === 'object') {
                    u.email_verified = true;
                    localStorage.setItem('hms_user', JSON.stringify(u));
                }
            } catch (e) { /* ignore */ }
        }

        function goToApp() {
            const tenant = JSON.parse(localStorage.getItem('hms_tenant') || '{}');
            const slugPrefix = tenant.slug ? '/' + tenant.slug : '';
            window.location.href = slugPrefix + '/';
        }

        // ── Mode A: confirmation link clicked (token present) ──
        async function verifyWithToken() {
            try {
                const res = await fetch(API_BASE + '/verify-email?token=' + encodeURIComponent(token), {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                if (res.ok && (data.success || data.message)) {
                    markVerifiedLocally();
                    setState('fa-circle-check', 'ok', 'E-Mail bestätigt!',
                        'Ihr Konto ist jetzt aktiv. Sie können sich anmelden und loslegen.');
                    const loggedIn = !!localStorage.getItem('hms_token');
                    $('actions').innerHTML = loggedIn
                        ? '<button class="btn" onclick="goToApp()"><i class="fas fa-arrow-right"></i> Zur Anwendung</button>'
                        : '<a class="btn" href="login.php"><i class="fas fa-sign-in-alt"></i> Jetzt anmelden</a>';
                } else {
                    setState('fa-circle-xmark', 'err', 'Link ungültig oder abgelaufen',
                        (data.error || 'Dieser Bestätigungslink ist nicht mehr gültig.') +
                        '<br>Bitte fordern Sie unten einen neuen Link an.');
                    renderResend();
                }
            } catch (e) {
                setState('fa-triangle-exclamation', 'err', 'Verbindungsfehler',
                    'Bitte versuchen Sie es in einem Moment erneut.');
                renderResend();
            }
        }

        // ── Mode B: pending screen (no token — user landed here unverified) ──
        function renderPending() {
            const target = email || (JSON.parse(localStorage.getItem('hms_user') || '{}').email) || 'Ihre E-Mail-Adresse';
            setState('fa-envelope', 'info', 'Bitte bestätigen Sie Ihre E-Mail',
                'Wir haben einen Bestätigungslink an <strong>' + target + '</strong> gesendet. ' +
                'Öffnen Sie die E-Mail und klicken Sie auf den Button, um Ihr Konto zu aktivieren.');
            renderResend();
        }

        function renderResend() {
            const loggedIn = !!localStorage.getItem('hms_token');
            let html = '';
            if (loggedIn) {
                html += '<button class="btn" id="resendBtn" onclick="resend()"><i class="fas fa-paper-plane"></i> E-Mail erneut senden</button>';
                html += '<button class="btn secondary" onclick="logoutAndLogin()"><i class="fas fa-right-from-bracket"></i> Abmelden</button>';
            } else {
                html += '<a class="btn" href="login.php"><i class="fas fa-sign-in-alt"></i> Zur Anmeldung</a>';
                html += '<p class="lead" style="margin-top:8px;font-size:0.82rem">Melden Sie sich an, um einen neuen Bestätigungslink anzufordern.</p>';
            }
            $('actions').innerHTML = html;
        }

        async function resend() {
            const btn = $('resendBtn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Senden…'; }
            try {
                const res = await fetch(API_BASE + '/resend-verification', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || '')
                    }
                });
                const data = await res.json();
                if (res.status === 429) {
                    showAlert('error', 'Zu viele Anfragen. Bitte warten Sie einen Moment.');
                } else if (res.ok) {
                    showAlert('success', data.message || 'Bestätigungs-E-Mail wurde erneut gesendet.');
                } else {
                    showAlert('error', data.error || 'Senden fehlgeschlagen.');
                }
            } catch (e) {
                showAlert('error', 'Verbindungsfehler. Bitte versuchen Sie es erneut.');
            }
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> E-Mail erneut senden'; }
        }

        // "Zurück zur Anmeldung": if a (still-unverified) session exists, clear it
        // first — otherwise login.php would bounce the user right back here.
        function backToLogin(e) {
            if (e) e.preventDefault();
            if (localStorage.getItem('hms_token')) {
                logoutAndLogin();
            } else {
                window.location.href = 'login.php';
            }
        }

        async function logoutAndLogin() {
            try {
                await fetch(API_BASE + '/auth/logout', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('hms_token') || '') }
                });
            } catch (e) { /* ignore */ }
            localStorage.removeItem('hms_token');
            localStorage.removeItem('hms_user');
            localStorage.removeItem('hms_tenant');
            window.location.href = 'login.php';
        }

        if (token) { verifyWithToken(); } else { renderPending(); }
    </script>
</body>
</html>
