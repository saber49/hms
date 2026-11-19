<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Mail bestätigen — Clood-X</title>
    <link rel="stylesheet" href="/assets/css/roboto.css?v=20261119ms1">
    <link rel="stylesheet" href="assets/css/md3-tokens.css">
    <link rel="stylesheet" href="/assets/css/material-symbols.css?v=20261119ms1">
    <link rel="stylesheet" href="assets/css/auth-md3.css?v=20261116ui1">
    <style>
        .card { text-align: center; }
        .state-icon { margin-bottom: 18px; }
        .state-icon i { font-size: 48px; }
        .state-icon.busy, .state-icon.info { color: var(--md-sys-color-primary); }
        .state-icon.ok  { color: var(--md-ext-color-success); }
        .state-icon.err { color: var(--md-sys-color-error); }
        .card .lead strong { color: var(--md-sys-color-on-surface); }
    </style>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="icon"><i class="material-symbols-outlined">mark_email_read</i></div>
            <h1>Clood-X</h1>
            <p>Operations Management System</p>
        </div>

        <div class="card">
            <div class="state-icon busy" id="stateIcon"><i class="material-symbols-outlined ms-spin">progress_activity</i></div>
            <h2 id="title">Einen Moment…</h2>
            <p class="lead" id="lead">Ihre E-Mail-Adresse wird bestätigt.</p>

            <div class="alert" id="alertBox"><i class="material-symbols-outlined">info</i><span id="alertText"></span></div>

            <div id="actions"></div>
            <a href="login.php" class="back-link" onclick="backToLogin(event)"><i class="material-symbols-outlined">arrow_back</i> Zurück zur Anmeldung</a>
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
            $('stateIcon').innerHTML = '<i class="material-symbols-outlined">' + icon + '</i>';
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
                    setState('check_circle', 'ok', 'E-Mail bestätigt!',
                        'Ihr Konto ist jetzt aktiv. Sie können sich anmelden und loslegen.');
                    const loggedIn = !!localStorage.getItem('hms_token');
                    $('actions').innerHTML = loggedIn
                        ? '<button class="btn" onclick="goToApp()"><i class="material-symbols-outlined">arrow_forward</i> Zur Anwendung</button>'
                        : '<a class="btn" href="login.php"><i class="material-symbols-outlined">login</i> Jetzt anmelden</a>';
                } else {
                    setState('cancel', 'err', 'Link ungültig oder abgelaufen',
                        (data.error || 'Dieser Bestätigungslink ist nicht mehr gültig.') +
                        '<br>Bitte fordern Sie unten einen neuen Link an.');
                    renderResend();
                }
            } catch (e) {
                setState('warning', 'err', 'Verbindungsfehler',
                    'Bitte versuchen Sie es in einem Moment erneut.');
                renderResend();
            }
        }

        // ── Mode B: pending screen (no token — user landed here unverified) ──
        function renderPending() {
            const target = email || (JSON.parse(localStorage.getItem('hms_user') || '{}').email) || 'Ihre E-Mail-Adresse';
            setState('mail', 'info', 'Bitte bestätigen Sie Ihre E-Mail',
                'Wir haben einen Bestätigungslink an <strong>' + target + '</strong> gesendet. ' +
                'Öffnen Sie die E-Mail und klicken Sie auf den Button, um Ihr Konto zu aktivieren.');
            renderResend();
        }

        function renderResend() {
            const loggedIn = !!localStorage.getItem('hms_token');
            let html = '';
            if (loggedIn) {
                html += '<button class="btn" id="resendBtn" onclick="resend()"><i class="material-symbols-outlined">send</i> E-Mail erneut senden</button>';
                html += '<button class="btn secondary" onclick="logoutAndLogin()"><i class="material-symbols-outlined">logout</i> Abmelden</button>';
            } else {
                html += '<a class="btn" href="login.php"><i class="material-symbols-outlined">login</i> Zur Anmeldung</a>';
                html += '<p class="lead" style="margin-top:8px;font-size:0.82rem">Melden Sie sich an, um einen neuen Bestätigungslink anzufordern.</p>';
            }
            $('actions').innerHTML = html;
        }

        async function resend() {
            const btn = $('resendBtn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="material-symbols-outlined ms-spin">progress_activity</i> Senden…'; }
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
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="material-symbols-outlined">send</i> E-Mail erneut senden'; }
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
