<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort festlegen — Clood-X</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="assets/css/md3-tokens.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="assets/css/auth-md3.css?v=20261116ui1">
    <style>
        .notice {
            margin-bottom: 18px; padding: 12px 16px;
            display: flex; align-items: flex-start; gap: 10px;
            border-radius: var(--md-sys-shape-corner-medium);
            background: var(--md-ext-color-warning-container);
            color: var(--md-ext-color-on-warning-container);
            font-size: var(--md-sys-typescale-body-medium-size);
        }
        .logout-link {
            display: block; margin-top: 20px; text-align: center; text-decoration: none;
            color: var(--md-sys-color-on-surface-variant);
            font-size: var(--md-sys-typescale-label-large-size); font-weight: 500;
        }
        .logout-link:hover { text-decoration: underline; }
    </style>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="icon"><i class="material-symbols-outlined">vpn_key</i></div>
            <h1>Clood-X</h1>
            <p>Operations Management System</p>
        </div>

        <div class="card">
            <h2>Passwort festlegen</h2>
            <p class="lead">Bitte legen Sie ein eigenes, sicheres Passwort fest, bevor Sie fortfahren.</p>

            <div class="notice">
                <i class="material-symbols-outlined" style="margin-top:2px">shield</i>
                <span>Ihr Konto wurde mit einem temporären Passwort eingerichtet. Aus Sicherheitsgründen müssen Sie es jetzt durch ein eigenes Passwort ersetzen.</span>
            </div>

            <div class="alert" id="alertBox"><i class="material-symbols-outlined">info</i><span id="alertText"></span></div>

            <form id="changeForm" onsubmit="handleChange(event)">
                <div class="form-group">
                    <label>Neues Passwort</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">lock</i>
                        <input type="password" id="password" placeholder="Mind. 8 Zeichen" required minlength="8" autocomplete="new-password">
                        <button type="button" class="toggle" onclick="togglePw('password', this)" tabindex="-1"><i class="material-symbols-outlined">visibility</i></button>
                    </div>
                    <div class="pw-rules">Mindestens 8 Zeichen. Empfehlung: Buchstaben, Ziffern und Sonderzeichen mischen.</div>
                </div>

                <div class="form-group">
                    <label>Passwort wiederholen</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">lock</i>
                        <input type="password" id="password_confirmation" placeholder="Passwort erneut eingeben" required minlength="8" autocomplete="new-password">
                        <button type="button" class="toggle" onclick="togglePw('password_confirmation', this)" tabindex="-1"><i class="material-symbols-outlined">visibility</i></button>
                    </div>
                </div>

                <button type="submit" class="btn" id="btnSubmit">
                    <i class="material-symbols-outlined">check</i>
                    <span>Passwort speichern &amp; fortfahren</span>
                </button>
            </form>

            <a href="#" class="logout-link" onclick="logout(event)"><i class="material-symbols-outlined">logout</i> Abmelden</a>
        </div>

        <div class="footer">&copy; <?php echo date('Y'); ?> Clood-X</div>
    </div>

    <script>
        const API_BASE = window.location.origin + '/api';
        const token = localStorage.getItem('hms_token');

        // Must be authenticated to reach this screen.
        if (!token) { window.location.href = 'login.php'; }

        function togglePw(inputId, btn) {
            const i = document.getElementById(inputId);
            const showing = i.type === 'text';
            i.type = showing ? 'password' : 'text';
            btn.innerHTML = showing ? '<i class="material-symbols-outlined">visibility</i>' : '<i class="material-symbols-outlined">visibility_off</i>';
        }

        // Send the user into the app, respecting tenant slug + onboarding state.
        function continueToApp() {
            const tenant = JSON.parse(localStorage.getItem('hms_tenant') || '{}');
            const slugPrefix = tenant.slug ? '/' + tenant.slug : '';
            if (tenant.onboarding_completed === false) {
                window.location.href = slugPrefix + '/onboarding.html';
            } else {
                window.location.href = slugPrefix + '/';
            }
        }

        async function logout(e) {
            if (e) e.preventDefault();
            try {
                await fetch(API_BASE + '/auth/logout', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + (token || '') }
                });
            } catch (err) { /* ignore */ }
            localStorage.removeItem('hms_token');
            localStorage.removeItem('hms_user');
            localStorage.removeItem('hms_tenant');
            window.location.href = 'login.php';
        }

        async function handleChange(e) {
            e.preventDefault();
            const btn      = document.getElementById('btnSubmit');
            const alertBox = document.getElementById('alertBox');
            const alertTxt = document.getElementById('alertText');

            const password = document.getElementById('password').value;
            const confirm  = document.getElementById('password_confirmation').value;

            alertBox.classList.remove('visible', 'success', 'error');

            if (password.length < 8) {
                alertBox.classList.add('visible', 'error');
                alertTxt.textContent = 'Passwort muss mindestens 8 Zeichen haben.';
                return;
            }
            if (password !== confirm) {
                alertBox.classList.add('visible', 'error');
                alertTxt.textContent = 'Die Passwörter stimmen nicht überein.';
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="material-symbols-outlined ms-spin">progress_activity</i> Speichern...';

            try {
                const res = await fetch(API_BASE + '/auth/change-password', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    // old_password is intentionally omitted: the backend skips
                    // the check while must_change_password is set (the user just
                    // authenticated with the temporary password at login).
                    body: JSON.stringify({ new_password: password })
                });

                if (res.status === 401) {
                    // Token expired/invalid — back to login.
                    window.location.href = 'login.php';
                    return;
                }

                const data = await res.json();

                if (data.success) {
                    // Clear the flag locally so index.php doesn't bounce us back.
                    const u = JSON.parse(localStorage.getItem('hms_user') || '{}');
                    u.must_change_password = false;
                    localStorage.setItem('hms_user', JSON.stringify(u));

                    alertBox.classList.add('visible', 'success');
                    alertTxt.textContent = 'Passwort gespeichert. Sie werden weitergeleitet...';
                    document.getElementById('changeForm').style.display = 'none';
                    setTimeout(continueToApp, 1200);
                } else {
                    alertBox.classList.add('visible', 'error');
                    alertTxt.textContent = data.error
                        || (data.errors ? Object.values(data.errors).flat().join(' · ') : 'Fehler beim Speichern.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="material-symbols-outlined">check</i> <span>Passwort speichern &amp; fortfahren</span>';
                }
            } catch (err) {
                alertBox.classList.add('visible', 'error');
                alertTxt.textContent = 'Verbindungsfehler. Bitte versuchen Sie es erneut.';
                btn.disabled = false;
                btn.innerHTML = '<i class="material-symbols-outlined">check</i> <span>Passwort speichern &amp; fortfahren</span>';
            }
        }
    </script>
</body>
</html>
