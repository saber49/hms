<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort festlegen — Clood-X</title>
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
        }
        .card h2 { font-size:1.2rem; font-weight:500; color:var(--md-sys-color-on-surface,#1f1f1f); margin-bottom:8px; text-align:center; }
        .card .lead { font-size:0.88rem; color:var(--md-sys-color-on-surface-variant,#44474e); text-align:center; margin-bottom:24px; }
        /* MD3 warning container */
        .notice {
            background:var(--md-ext-color-warning-container,#ffdf9e);
            border-radius:var(--md-sys-shape-corner-medium,12px);
            padding:12px 16px; margin-bottom:22px; font-size:0.85rem;
            color:var(--md-ext-color-on-warning-container,#261a00);
            display:flex; gap:10px; align-items:flex-start;
        }
        .form-group { margin-bottom:18px; }
        .form-group label {
            display:block; font-size:0.8rem; font-weight:500;
            color:var(--md-sys-color-on-surface-variant,#44474e);
            letter-spacing:0.3px; margin-bottom:6px;
        }
        .input-wrapper { position:relative; }
        .input-wrapper i.fa-lock {
            position:absolute; left:14px; top:50%; transform:translateY(-50%);
            color:var(--md-sys-color-on-surface-variant,#44474e); font-size:0.9rem;
        }
        /* MD3 outlined text field */
        .input-wrapper input {
            width:100%; padding:14px 42px 14px 42px;
            border:1px solid var(--md-sys-color-outline,#75777f);
            border-radius:var(--md-sys-shape-corner-extra-small,4px);
            font-size:0.95rem; font-family:inherit;
            transition:border-color 0.15s ease, box-shadow 0.15s ease;
            background:transparent; color:var(--md-sys-color-on-surface,#1f1f1f);
        }
        .input-wrapper input:focus {
            outline:none;
            border-color:var(--md-sys-color-primary,#0b57d0);
            box-shadow:inset 0 0 0 1px var(--md-sys-color-primary,#0b57d0);
        }
        .toggle {
            position:absolute; right:10px; top:50%; transform:translateY(-50%);
            background:transparent; border:0; cursor:pointer; color:#64748b; padding:6px;
        }
        /* MD3 filled button */
        .btn {
            width:100%; padding:14px;
            background:var(--md-sys-color-primary,#0b57d0);
            color:var(--md-sys-color-on-primary,#fff); border:none;
            border-radius:var(--md-sys-shape-corner-full,9999px);
            font-size:0.95rem; font-weight:500; cursor:pointer;
            transition:box-shadow 0.15s ease, background 0.15s ease;
            display:flex; align-items:center; justify-content:center; gap:8px; font-family:inherit;
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
        /* MD3 containers for alerts */
        .alert {
            padding:12px 16px; border-radius:var(--md-sys-shape-corner-medium,12px);
            font-size:0.88rem; margin-bottom:18px;
            display:none; align-items:center; gap:10px;
        }
        .alert.visible { display:flex; }
        .alert.success { background:var(--md-ext-color-success-container,#c4eed0); color:var(--md-ext-color-on-success-container,#072711); }
        .alert.error   { background:var(--md-sys-color-error-container,#f9dedc); color:var(--md-sys-color-on-error-container,#410e0b); }
        .logout-link {
            display:block; text-align:center; margin-top:20px;
            color:var(--md-sys-color-on-surface-variant,#44474e); text-decoration:none; font-size:0.85rem; font-weight:500;
        }
        .logout-link:hover { text-decoration:underline; }
        .footer { text-align:center; margin-top:24px; color:rgba(255,255,255,0.4); font-size:0.75rem; }
        .pw-rules { font-size:0.78rem; color:var(--md-sys-color-on-surface-variant,#44474e); margin-top:6px; padding-inline-start:4px; }
    </style>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="icon"><i class="fas fa-key"></i></div>
            <h1>Clood-X</h1>
            <p>Operations Management System</p>
        </div>

        <div class="card">
            <h2>Passwort festlegen</h2>
            <p class="lead">Bitte legen Sie ein eigenes, sicheres Passwort fest, bevor Sie fortfahren.</p>

            <div class="notice">
                <i class="fas fa-shield-alt" style="margin-top:2px"></i>
                <span>Ihr Konto wurde mit einem temporären Passwort eingerichtet. Aus Sicherheitsgründen müssen Sie es jetzt durch ein eigenes Passwort ersetzen.</span>
            </div>

            <div class="alert" id="alertBox"><i class="fas fa-info-circle"></i><span id="alertText"></span></div>

            <form id="changeForm" onsubmit="handleChange(event)">
                <div class="form-group">
                    <label>Neues Passwort</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" placeholder="Mind. 8 Zeichen" required minlength="8" autocomplete="new-password">
                        <button type="button" class="toggle" onclick="togglePw('password', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
                    </div>
                    <div class="pw-rules">Mindestens 8 Zeichen. Empfehlung: Buchstaben, Ziffern und Sonderzeichen mischen.</div>
                </div>

                <div class="form-group">
                    <label>Passwort wiederholen</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password_confirmation" placeholder="Passwort erneut eingeben" required minlength="8" autocomplete="new-password">
                        <button type="button" class="toggle" onclick="togglePw('password_confirmation', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
                    </div>
                </div>

                <button type="submit" class="btn" id="btnSubmit">
                    <i class="fas fa-check"></i>
                    <span>Passwort speichern &amp; fortfahren</span>
                </button>
            </form>

            <a href="#" class="logout-link" onclick="logout(event)"><i class="fas fa-sign-out-alt"></i> Abmelden</a>
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
            btn.innerHTML = showing ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
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
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Speichern...';

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
                    btn.innerHTML = '<i class="fas fa-check"></i> <span>Passwort speichern &amp; fortfahren</span>';
                }
            } catch (err) {
                alertBox.classList.add('visible', 'error');
                alertTxt.textContent = 'Verbindungsfehler. Bitte versuchen Sie es erneut.';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> <span>Passwort speichern &amp; fortfahren</span>';
            }
        }
    </script>
</body>
</html>
