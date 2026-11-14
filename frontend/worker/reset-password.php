<?php
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
    . "font-src 'self' https://cdnjs.cloudflare.com data:; "
    . "img-src 'self' data: blob:; "
    . "connect-src 'self' https://clood-x.com; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self';");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Passwort zurücksetzen — Clood-X</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
            background:#f1f5f9; min-height:100vh; display:flex; align-items:center;
            justify-content:center; padding:20px; -webkit-font-smoothing:antialiased;
        }
        .wrap { width:100%; max-width:380px; }
        .head { text-align:center; margin-bottom:32px; }
        .head .icon {
            width:64px; height:64px; background:#0b57d0; border-radius:16px;
            display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;
        }
        .head .icon i { font-size:1.8rem; color:white; }
        .head h1 { font-size:1.3rem; color:#0b57d0; font-weight:700; }
        .head p { color:#64748b; font-size:0.85rem; margin-top:4px; }
        .card { background:white; border-radius:14px; padding:24px; box-shadow:0 6px 20px rgba(0,0,0,0.05); }
        .card .lead { font-size:0.88rem; color:#64748b; margin-bottom:20px; text-align:center; }
        .form-group { margin-bottom:16px; }
        .form-group label { display:block; font-size:0.85rem; font-weight:600; color:#0b57d0; margin-bottom:6px; }
        .input-wrap { position:relative; }
        .input-wrap i {
            position:absolute; left:14px; top:50%; transform:translateY(-50%);
            color:#94a3b8; font-size:0.9rem;
        }
        .input-wrap input {
            width:100%; padding:12px 14px 12px 42px; border:1.5px solid #e2e8f0;
            border-radius:10px; font-size:0.95rem; font-family:inherit; background:#f8fafc; color:#1e293b;
        }
        .input-wrap input:focus { outline:none; border-color:#0b57d0; background:white; }
        .btn {
            width:100%; padding:13px; background:#0b57d0; color:white; border:none;
            border-radius:10px; font-size:1rem; font-weight:600; cursor:pointer;
            display:flex; align-items:center; justify-content:center; gap:8px; font-family:inherit;
        }
        .btn:disabled { background:#94a3b8; cursor:not-allowed; }
        .alert { padding:10px 14px; border-radius:10px; font-size:0.86rem; margin-bottom:16px;
            display:none; align-items:center; gap:8px; border:1px solid transparent; }
        .alert.visible { display:flex; }
        .alert.success { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; }
        .alert.error   { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
        .back-link { display:block; text-align:center; margin-top:16px;
            color:#3b82f6; text-decoration:none; font-size:0.88rem; font-weight:500; }
        .back-link:hover { text-decoration:underline; }
        .email-ro { color:#64748b; font-size:0.88rem; padding:10px 14px; background:#f1f5f9;
            border-radius:8px; margin-bottom:16px; text-align:center; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="head">
            <div class="icon"><i class="fas fa-lock"></i></div>
            <h1>Neues Passwort</h1>
            <p>Clood-X</p>
        </div>
        <div class="card">
            <p class="lead">Wählen Sie ein neues Passwort.</p>
            <div class="alert" id="alertBox"><i class="fas fa-info-circle"></i><span id="alertText"></span></div>
            <div class="email-ro" id="emailDisplay"></div>

            <form id="resetForm" onsubmit="handleReset(event)">
                <div class="form-group">
                    <label>Neues Passwort (mind. 6 Zeichen)</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" minlength="6" required autocomplete="new-password">
                    </div>
                </div>
                <div class="form-group">
                    <label>Passwort bestätigen</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password2" minlength="6" required autocomplete="new-password">
                    </div>
                </div>
                <button type="submit" class="btn" id="btnSubmit">
                    <i class="fas fa-check"></i><span>Passwort speichern</span>
                </button>
            </form>
            <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Zurück zur Anmeldung</a>
        </div>
    </div>

    <script>
        const API_BASE = window.location.origin + '/api';
        const params   = new URLSearchParams(window.location.search);
        const TOKEN    = params.get('token') || '';
        const EMAIL    = params.get('email') || '';

        document.getElementById('emailDisplay').textContent = EMAIL || '—';

        if (!TOKEN || !EMAIL) {
            const a = document.getElementById('alertBox');
            const t = document.getElementById('alertText');
            a.classList.add('visible', 'error');
            t.textContent = 'Ungültiger Link. Bitte fordern Sie einen neuen an.';
            document.getElementById('resetForm').style.display = 'none';
        }

        async function handleReset(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmit');
            const alertBox = document.getElementById('alertBox');
            const alertTxt = document.getElementById('alertText');
            const pw  = document.getElementById('password').value;
            const pw2 = document.getElementById('password2').value;

            alertBox.classList.remove('visible', 'success', 'error');

            if (pw !== pw2) {
                alertBox.classList.add('visible', 'error');
                alertTxt.textContent = 'Die Passwörter stimmen nicht überein.';
                return;
            }
            if (pw.length < 6) {
                alertBox.classList.add('visible', 'error');
                alertTxt.textContent = 'Passwort muss mindestens 6 Zeichen lang sein.';
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Speichern...';

            try {
                const res = await fetch(API_BASE + '/worker-app/auth/reset-password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        email: EMAIL, token: TOKEN,
                        password: pw, password_confirmation: pw2
                    })
                });
                const data = await res.json();

                if (data.success) {
                    alertBox.classList.add('visible', 'success');
                    alertTxt.textContent = data.message || 'Passwort gespeichert. Sie können sich jetzt anmelden.';
                    document.getElementById('resetForm').style.display = 'none';
                    setTimeout(() => { window.location.href = 'login.php'; }, 2500);
                } else {
                    alertBox.classList.add('visible', 'error');
                    alertTxt.textContent = data.error || 'Fehler. Bitte versuchen Sie es erneut.';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i><span>Passwort speichern</span>';
                }
            } catch (err) {
                alertBox.classList.add('visible', 'error');
                alertTxt.textContent = 'Verbindungsfehler. Bitte versuchen Sie es erneut.';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i><span>Passwort speichern</span>';
            }
        }
    </script>
</body>
</html>
