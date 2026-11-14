<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Neues Passwort festlegen — Clood-X</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="assets/css/md3-tokens.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Google Sans','Roboto','Segoe UI',-apple-system,sans-serif;
            -webkit-font-smoothing:antialiased;
            background: linear-gradient(135deg,var(--md-sys-color-primary) 0%,var(--md-sys-color-primary) 55%,var(--md-sys-color-primary) 100%);
            min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px;
        }
        .container { width:100%; max-width:460px; }
        .logo { text-align:center; margin-bottom:32px; }
        .logo .icon {
            width:64px; height:64px; background: rgba(255,255,255,0.1); border-radius:16px;
            display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;
        }
        .logo .icon i { font-size:1.8rem; color: var(--md-ref-palette-primary90,var(--md-sys-color-primary-container)); }
        .logo h1 { font-size:1.5rem; color:white; font-weight:700; }
        .logo p  { color: rgba(255,255,255,0.5); font-size:0.85rem; margin-top:4px; }
        .card {
            background: var(--md-sys-color-surface-container-lowest,var(--md-sys-color-surface-container-lowest));
            border-radius:var(--md-sys-shape-corner-extra-large,28px);
            padding:36px;
            box-shadow: var(--md-sys-elevation-level3,0 20px 60px color-mix(in srgb, var(--md-sys-color-scrim) 30%, transparent));
        }
        .card h2 { font-size:1.2rem; font-weight:500; color: var(--md-sys-color-on-surface,var(--md-sys-color-on-surface)); margin-bottom:8px; text-align:center; }
        .card .lead { font-size:0.88rem; color: var(--md-sys-color-on-surface-variant,var(--md-sys-color-on-surface)); text-align:center; margin-bottom:24px; }
        .form-group { margin-bottom:18px; }
        .form-group label {
            display:block; font-size:0.8rem; font-weight:500;
            color: var(--md-sys-color-on-surface-variant,var(--md-sys-color-on-surface));
            letter-spacing:0.3px; margin-bottom:6px;
        }
        .input-wrapper { position:relative; }
        .input-wrapper > i.material-symbols-outlined {
            position:absolute; left:14px; top:50%; transform:translateY(-50%);
            color: var(--md-sys-color-on-surface-variant,var(--md-sys-color-on-surface)); font-size:0.9rem;
        }
        /* MD3 outlined text field */
        .input-wrapper input {
            width:100%; padding:14px 42px 14px 42px;
            border: 1px solid var(--md-sys-color-outline,var(--md-sys-color-outline));
            border-radius:var(--md-sys-shape-corner-extra-small,4px);
            font-size:0.95rem; font-family:inherit;
            transition:border-color 0.15s ease, box-shadow 0.15s ease;
            background:transparent; color: var(--md-sys-color-on-surface,var(--md-sys-color-on-surface));
        }
        .input-wrapper input:focus {
            outline:none;
            border-color: var(--md-sys-color-primary,var(--md-sys-color-primary));
            box-shadow: inset 0 0 0 1px var(--md-sys-color-primary,var(--md-sys-color-primary));
        }
        .input-wrapper input[readonly] {
            background: var(--md-sys-color-surface-container-high,var(--md-sys-color-primary-container));
            color: var(--md-sys-color-on-surface-variant,var(--md-sys-color-on-surface));
            border-color:transparent;
        }
        .toggle {
            position:absolute; right:10px; top:50%; transform:translateY(-50%);
            background:transparent; border:0; cursor:pointer; color: var(--md-sys-color-primary); padding:6px;
        }
        /* MD3 filled button */
        .btn {
            width:100%; padding:14px;
            background: var(--md-sys-color-primary,var(--md-sys-color-primary));
            color: var(--md-sys-color-on-primary,#fff); border:none;
            border-radius:var(--md-sys-shape-corner-full,9999px);
            font-size:0.95rem; font-weight:500; cursor:pointer;
            transition:box-shadow 0.15s ease, background 0.15s ease;
            display:flex; align-items:center; justify-content:center; gap:8px; font-family:inherit;
        }
        .btn:hover {
            background: color-mix(in srgb, var(--md-sys-color-on-primary,var(--md-sys-color-surface-container-lowest)) 8%, var(--md-sys-color-primary,var(--md-sys-color-primary)));
            box-shadow: var(--md-sys-elevation-level1,0 1px 2px color-mix(in srgb, var(--md-sys-color-scrim) 30%, transparent));
        }
        .btn:disabled {
            background: color-mix(in srgb, var(--md-sys-color-on-surface,var(--md-sys-color-inverse-surface)) 12%, transparent);
            color: color-mix(in srgb, var(--md-sys-color-on-surface,var(--md-sys-color-on-surface)) 38%, transparent);
            cursor:not-allowed; box-shadow:none;
        }
        /* MD3 containers for alerts */
        .alert {
            padding:12px 16px; border-radius:var(--md-sys-shape-corner-medium,12px);
            font-size:0.88rem; margin-bottom:18px;
            display:none; align-items:center; gap:10px;
        }
        .alert.visible { display:flex; }
        .alert.success { background: var(--md-ext-color-success-container,var(--md-ext-color-success-container)); color: var(--md-ext-color-on-success-container,var(--md-ext-color-on-success-container)); }
        .alert.error   { background: var(--md-sys-color-error-container,var(--md-sys-color-error-container)); color: var(--md-sys-color-on-error-container,var(--md-sys-color-on-error-container)); }
        .back-link {
            display:block; text-align:center; margin-top:20px;
            color: var(--md-sys-color-primary,var(--md-sys-color-primary)); text-decoration:none; font-size:0.85rem; font-weight:500;
        }
        .back-link:hover { text-decoration:underline; }
        .footer { text-align:center; margin-top:24px; color: rgba(255,255,255,0.4); font-size:0.75rem; }
        .pw-rules {
            font-size:0.78rem; color: var(--md-sys-color-on-surface-variant,var(--md-sys-color-on-surface)); margin-top:6px; padding-inline-start:4px;
        }
    
.material-symbols-outlined.ms-spin, .ms-spin { animation: msSpin 1s linear infinite; }
@keyframes msSpin { to { transform: rotate(360deg); } }
</style>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="icon"><i class="material-symbols-outlined">shield</i></div>
            <h1>Clood-X</h1>
            <p>Operations Management System</p>
        </div>

        <div class="card">
            <h2>Neues Passwort festlegen</h2>
            <p class="lead">Wählen Sie ein neues, sicheres Passwort für Ihr Konto.</p>

            <div class="alert" id="alertBox"><i class="material-symbols-outlined">info</i><span id="alertText"></span></div>

            <form id="resetForm" onsubmit="handleReset(event)">
                <div class="form-group">
                    <label>E-Mail-Adresse</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">mail</i>
                        <input type="email" id="email" readonly>
                    </div>
                </div>

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
                    <span>Passwort speichern</span>
                </button>
            </form>

            <a href="login.php" class="back-link"><i class="material-symbols-outlined">arrow_back</i> Zurück zur Anmeldung</a>
        </div>

        <div class="footer">&copy; <?php echo date('Y'); ?> Clood-X</div>
    </div>

    <script>
        const API_BASE = window.location.origin + '/api';

        // Extract token + email from URL on load. Both are required.
        const params = new URLSearchParams(window.location.search);
        const token  = params.get('token') || '';
        const email  = params.get('email') || '';

        document.getElementById('email').value = email;

        if (!token || !email) {
            const a = document.getElementById('alertBox');
            a.classList.add('visible', 'error');
            document.getElementById('alertText').textContent =
                'Ungültiger Link. Bitte fordern Sie einen neuen Link über „Passwort vergessen" an.';
            document.getElementById('resetForm').style.display = 'none';
        }

        function togglePw(inputId, btn) {
            const i = document.getElementById(inputId);
            const showing = i.type === 'text';
            i.type = showing ? 'password' : 'text';
            btn.innerHTML = showing ? '<i class="material-symbols-outlined">visibility</i>' : '<i class="material-symbols-outlined">visibility_off</i>';
        }

        async function handleReset(e) {
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
                const res = await fetch(API_BASE + '/auth/reset-password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        email: email,
                        token: token,
                        password: password,
                        password_confirmation: confirm,
                    })
                });

                const data = await res.json();

                if (res.status === 429) {
                    alertBox.classList.add('visible', 'error');
                    alertTxt.textContent = 'Zu viele Versuche. Bitte warten Sie und versuchen Sie es erneut.';
                } else if (data.success) {
                    alertBox.classList.add('visible', 'success');
                    alertTxt.textContent = data.message || 'Passwort geändert.';
                    document.getElementById('resetForm').style.display = 'none';
                    setTimeout(() => window.location.href = 'login.php', 2500);
                } else {
                    alertBox.classList.add('visible', 'error');
                    alertTxt.textContent = data.error
                        || (data.errors ? Object.values(data.errors).flat().join(' · ') : 'Fehler beim Speichern.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="material-symbols-outlined">check</i> <span>Passwort speichern</span>';
                }
            } catch (err) {
                alertBox.classList.add('visible', 'error');
                alertTxt.textContent = 'Verbindungsfehler. Bitte versuchen Sie es erneut.';
                btn.disabled = false;
                btn.innerHTML = '<i class="material-symbols-outlined">check</i> <span>Passwort speichern</span>';
            }
        }
    </script>
</body>
</html>
