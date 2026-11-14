<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort vergessen — Clood-X</title>
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
        .form-group { margin-bottom:20px; }
        .form-group label {
            display:block; font-size:0.8rem; font-weight:500;
            color:var(--md-sys-color-on-surface-variant,#44474e);
            letter-spacing:0.3px; margin-bottom:6px;
        }
        .input-wrapper { position:relative; }
        .input-wrapper i {
            position:absolute; left:14px; top:50%; transform:translateY(-50%);
            color:var(--md-sys-color-on-surface-variant,#44474e); font-size:0.9rem;
        }
        /* MD3 outlined text field */
        .input-wrapper input {
            width:100%; padding:14px 14px 14px 42px;
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
        .input-wrapper:focus-within i { color:var(--md-sys-color-primary,#0b57d0); }
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
        .back-link {
            display:block; text-align:center; margin-top:20px;
            color:var(--md-sys-color-primary,#0b57d0); text-decoration:none; font-size:0.85rem; font-weight:500;
        }
        .back-link:hover { text-decoration:underline; }
        .footer { text-align:center; margin-top:24px; color:rgba(255,255,255,0.4); font-size:0.75rem; }
        /* Brand link (top-left) — back to the landing page */
        .brand-home {
            position:fixed; top:20px; left:24px;
            display:inline-flex; align-items:center; gap:10px;
            text-decoration:none; padding:8px 14px 8px 8px; border-radius:9999px;
            background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.14);
            backdrop-filter:blur(8px); transition:background 0.2s, transform 0.2s; z-index:10;
        }
        .brand-home:hover { background:rgba(255,255,255,0.16); transform:translateY(-1px); }
        .brand-home .brand-mark { width:30px; height:30px; border-radius:9px; overflow:hidden; display:inline-flex; }
        .brand-home .brand-mark img { width:100%; height:100%; object-fit:cover; display:block; }
        .brand-home .brand-name { color:white; font-size:0.95rem; font-weight:700; letter-spacing:0.2px; }
        @media (max-width:480px) { .brand-home { top:12px; left:12px; } }
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

    <div class="container">
        <div class="logo">
            <div class="icon"><i class="fas fa-key"></i></div>
            <h1>Clood-X</h1>
            <p>Operations Management System</p>
        </div>

        <div class="card">
            <h2>Passwort vergessen</h2>
            <p class="lead">Geben Sie Ihre E-Mail-Adresse ein — wir senden Ihnen einen Link zum Zurücksetzen.</p>

            <div class="alert" id="alertBox"><i class="fas fa-info-circle"></i><span id="alertText"></span></div>

            <form id="forgotForm" onsubmit="handleForgot(event)">
                <div class="form-group">
                    <label>E-Mail-Adresse</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" placeholder="ihre@firma.de" required autocomplete="email" autofocus>
                    </div>
                </div>

                <button type="submit" class="btn" id="btnSubmit">
                    <i class="fas fa-paper-plane"></i>
                    <span>Link senden</span>
                </button>
            </form>

            <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Zurück zur Anmeldung</a>
        </div>

        <div class="footer">&copy; <?php echo date('Y'); ?> Clood-X</div>
    </div>

    <script>
        const API_BASE = window.location.origin + '/api';

        async function handleForgot(e) {
            e.preventDefault();
            const btn      = document.getElementById('btnSubmit');
            const alertBox = document.getElementById('alertBox');
            const alertTxt = document.getElementById('alertText');
            const emailEl  = document.getElementById('email');

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Senden...';
            alertBox.classList.remove('visible', 'success', 'error');

            try {
                const res = await fetch(API_BASE + '/auth/forgot-password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ email: emailEl.value.trim() })
                });

                const data = await res.json();

                if (res.status === 429) {
                    alertBox.classList.add('visible', 'error');
                    alertTxt.textContent = 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.';
                } else if (data.success) {
                    alertBox.classList.add('visible', 'success');
                    alertTxt.textContent = data.message
                        || 'Falls die E-Mail bei uns hinterlegt ist, erhalten Sie in Kürze eine Nachricht.';
                    emailEl.value = '';
                } else {
                    alertBox.classList.add('visible', 'error');
                    alertTxt.textContent = data.error || 'Fehler. Bitte versuchen Sie es erneut.';
                }
            } catch (err) {
                alertBox.classList.add('visible', 'error');
                alertTxt.textContent = 'Verbindungsfehler. Bitte versuchen Sie es erneut.';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> <span>Link senden</span>';
            }
        }
    </script>
</body>
</html>
