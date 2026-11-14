<?php
// register.php is deprecated. The registration flow is unified on the 3-step
// wizard at register.html. Redirect there (preserving any ?plan= query) so old
// links, bookmarks and the previous login.php button keep working.
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /register.html' . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konto erstellen — Clood-X</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Google Sans','Roboto','Segoe UI',-apple-system,sans-serif;
            -webkit-font-smoothing:antialiased;
            background:linear-gradient(135deg,#062e6f 0%,#0b57d0 55%,#0842a0 100%);
            min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px;
        }
        .container { width:100%; max-width:480px; }
        .logo { text-align:center; margin-bottom:24px; }
        .logo .icon {
            width:64px; height:64px; background:rgba(255,255,255,0.1); border-radius:16px;
            display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;
        }
        .logo .icon i { font-size:1.8rem; color:#3b82f6; }
        .logo .icon img { width:100%; height:100%; object-fit:cover; border-radius:16px; display:block; }
        .logo h1 { font-size:1.5rem; color:white; font-weight:700; }
        .logo p  { color:rgba(255,255,255,0.5); font-size:0.85rem; margin-top:4px; }
        .card { background:white; border-radius:12px; padding:32px; box-shadow:0 20px 60px rgba(0,0,0,0.3); }
        .card h2 { font-size:1.2rem; color:#1a2744; margin-bottom:6px; text-align:center; }
        .card .lead { font-size:0.86rem; color:#64748b; text-align:center; margin-bottom:22px; }
        .form-group { margin-bottom:16px; }
        .form-row { display:flex; gap:12px; }
        .form-row .form-group { flex:1; }
        label { display:block; font-size:0.78rem; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; }
        .input-wrapper { position:relative; }
        .input-wrapper > i {
            position:absolute; left:14px; top:50%; transform:translateY(-50%);
            color:#94a3b8; font-size:0.9rem;
        }
        input, select {
            width:100%; padding:12px 14px 12px 42px; border:2px solid #e2e8f0;
            border-radius:8px; font-size:0.95rem; font-family:inherit;
            transition:all 0.2s ease; background:#f8fafc; color:#1e293b;
        }
        select { cursor:pointer; }
        input:focus, select:focus {
            outline:none; border-color:#3b82f6; background:white;
            box-shadow:0 0 0 4px rgba(59,130,246,0.1);
        }
        .toggle { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:transparent; border:0; cursor:pointer; color:#64748b; padding:6px; }
        .pw-hint { font-size:0.76rem; color:#64748b; margin-top:5px; padding-inline-start:4px; }
        .terms { display:flex; align-items:flex-start; gap:9px; margin:6px 0 18px; }
        .terms input { width:auto; padding:0; margin-top:2px; }
        .terms label { text-transform:none; letter-spacing:0; font-weight:400; font-size:0.82rem; color:#475569; margin:0; }
        .terms a { color:#3b82f6; text-decoration:none; }
        .btn {
            width:100%; padding:13px; background:#3b82f6; color:white; border:none;
            border-radius:8px; font-size:1rem; font-weight:600; cursor:pointer;
            transition:all 0.2s ease; display:flex; align-items:center; justify-content:center; gap:8px; font-family:inherit;
        }
        .btn:hover { background:#2563eb; transform:translateY(-1px); box-shadow:0 4px 12px rgba(37,99,235,0.4); }
        .btn:disabled { background:#94a3b8; cursor:not-allowed; transform:none; box-shadow:none; }
        .alert { padding:12px 16px; border-radius:8px; font-size:0.86rem; margin-bottom:18px; display:none; align-items:center; gap:10px; border:1px solid transparent; }
        .alert.visible { display:flex; }
        .alert.error   { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
        .alert.success { background:#f0fdf4; color:#15803d; border-color:#bbf7d0; }
        .back-link { display:block; text-align:center; margin-top:18px; color:#3b82f6; text-decoration:none; font-size:0.85rem; font-weight:500; }
        .back-link:hover { text-decoration:underline; }
        .footer { text-align:center; margin-top:22px; color:rgba(255,255,255,0.4); font-size:0.75rem; }
    </style>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body>
    <div class="container">
        <div class="logo">
            <div class="icon"><img src="assets/img/clood-x-logo.png" alt="Clood-X"></div>
            <h1>Clood-X</h1>
            <p>Operations Management System</p>
        </div>

        <div class="card">
            <h2>Konto erstellen</h2>
            <p class="lead">Starten Sie Ihre kostenlose Testphase in wenigen Sekunden.</p>

            <div class="alert" id="alertBox"><i class="fas fa-info-circle"></i><span id="alertText"></span></div>

            <form id="registerForm" onsubmit="handleRegister(event)">
                <div class="form-group">
                    <label>Firmenname</label>
                    <div class="input-wrapper">
                        <i class="fas fa-building"></i>
                        <input type="text" id="company_name" placeholder="Ihre Firma GmbH" required maxlength="200" autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label>Ihr Name</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="full_name" placeholder="Vor- und Nachname" required maxlength="100">
                    </div>
                </div>

                <div class="form-group">
                    <label>E-Mail-Adresse</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" placeholder="name@firma.de" required autocomplete="email">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Telefon (optional)</label>
                        <div class="input-wrapper">
                            <i class="fas fa-phone"></i>
                            <input type="tel" id="phone" placeholder="+49 …" maxlength="50">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Tarif</label>
                        <div class="input-wrapper">
                            <i class="fas fa-layer-group"></i>
                            <select id="plan"><option value="trial">Testphase</option></select>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Passwort</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" placeholder="Mind. 8 Zeichen" required minlength="8" autocomplete="new-password">
                        <button type="button" class="toggle" onclick="togglePw('password', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
                    </div>
                    <div class="pw-hint">Mindestens 8 Zeichen. Empfehlung: Buchstaben, Ziffern und Sonderzeichen mischen.</div>
                </div>

                <div class="form-group">
                    <label>Passwort wiederholen</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password_confirmation" placeholder="Passwort erneut eingeben" required minlength="8" autocomplete="new-password">
                        <button type="button" class="toggle" onclick="togglePw('password_confirmation', this)" tabindex="-1"><i class="fas fa-eye"></i></button>
                    </div>
                </div>

                <div class="terms">
                    <input type="checkbox" id="accept_terms" required>
                    <label for="accept_terms">Ich akzeptiere die <a href="#" target="_blank">AGB</a> und die <a href="#" target="_blank">Datenschutzerklärung</a>.</label>
                </div>

                <button type="submit" class="btn" id="btnSubmit">
                    <i class="fas fa-user-plus"></i>
                    <span>Konto erstellen</span>
                </button>
            </form>

            <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Bereits ein Konto? Anmelden</a>
        </div>

        <div class="footer">&copy; <?php echo date('Y'); ?> Clood-X — Alle Rechte vorbehalten</div>
    </div>

    <script>
        const API_BASE = window.location.origin + '/api';

        // Already logged in → leave the signup page.
        if (localStorage.getItem('hms_token')) {
            const u = JSON.parse(localStorage.getItem('hms_user') || '{}');
            window.location.href = (u.email_verified === false) ? 'verify-email.php' : '/';
        }

        // Populate the plan dropdown from the API (best-effort).
        (async () => {
            try {
                const res = await fetch(API_BASE + '/plans', { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data && Array.isArray(data.plans) && data.plans.length) {
                    const sel = document.getElementById('plan');
                    sel.innerHTML = '';
                    data.plans.forEach(p => {
                        const o = document.createElement('option');
                        o.value = p.id;
                        const price = (p.price_monthly === 0 || p.id === 'trial')
                            ? '' : ' — ' + p.price_monthly + ' €/Mon.';
                        o.textContent = p.name + price;
                        sel.appendChild(o);
                    });
                    if (data.plans.some(p => p.id === 'trial')) sel.value = 'trial';
                }
            } catch (e) { /* keep the default Testphase option */ }
        })();

        function togglePw(id, btn) {
            const i = document.getElementById(id);
            const showing = i.type === 'text';
            i.type = showing ? 'password' : 'text';
            btn.innerHTML = showing ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
        }

        function showError(msg) {
            const a = document.getElementById('alertBox');
            a.className = 'alert visible error';
            document.getElementById('alertText').textContent = msg;
            a.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        async function handleRegister(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmit');
            document.getElementById('alertBox').classList.remove('visible');

            const password = document.getElementById('password').value;
            const confirm  = document.getElementById('password_confirmation').value;

            if (password.length < 8) { showError('Passwort muss mindestens 8 Zeichen haben.'); return; }
            if (password !== confirm) { showError('Die Passwörter stimmen nicht überein.'); return; }
            if (!document.getElementById('accept_terms').checked) { showError('Bitte akzeptieren Sie die AGB.'); return; }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Konto wird erstellt…';

            const payload = {
                company_name: document.getElementById('company_name').value.trim(),
                full_name:    document.getElementById('full_name').value.trim(),
                email:        document.getElementById('email').value.trim(),
                phone:        document.getElementById('phone').value.trim() || null,
                plan:         document.getElementById('plan').value || 'trial',
                password:     password,
                accept_terms: true,
            };

            try {
                const res = await fetch(API_BASE + '/register', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();

                if (res.status === 201 && data.success && data.token) {
                    // Persist the session so the verification screen can use the
                    // authenticated "resend" endpoint, then send the user there
                    // (email confirmation is mandatory before app access).
                    localStorage.setItem('hms_token', data.token);
                    localStorage.setItem('hms_user', JSON.stringify(data.user || {}));
                    localStorage.setItem('hms_tenant', JSON.stringify(data.tenant || {}));
                    window.location.href = 'verify-email.php?email=' + encodeURIComponent(payload.email);
                    return;
                }

                if (res.status === 422 && data.errors) {
                    showError(Object.values(data.errors).flat().join(' · '));
                } else {
                    showError(data.error || data.message || 'Registrierung fehlgeschlagen.');
                }
            } catch (err) {
                showError('Verbindungsfehler. Bitte versuchen Sie es erneut.');
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-user-plus"></i> <span>Konto erstellen</span>';
        }
    </script>
</body>
</html>
