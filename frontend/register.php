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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="assets/css/md3-tokens.css?v=20261114md3g">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="assets/css/auth-md3.css?v=20261116ui1">
    <style>
        .form-row { display: flex; gap: 12px; }
        .form-row .form-group { flex: 1; }
        .input-wrapper select { cursor: pointer; }
        .terms { display: flex; align-items: flex-start; gap: 9px; margin: 6px 0 18px; }
        .terms input { margin-top: 3px; accent-color: var(--md-sys-color-primary); }
        .terms label { margin: 0; font-size: var(--md-sys-typescale-body-small-size); color: var(--md-sys-color-on-surface-variant); }
        .terms a { color: var(--md-sys-color-primary); text-decoration: none; }
        @media (max-width: 480px) { .form-row { flex-direction: column; gap: 0; } }
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

            <div class="alert" id="alertBox"><i class="material-symbols-outlined">info</i><span id="alertText"></span></div>

            <form id="registerForm" onsubmit="handleRegister(event)">
                <div class="form-group">
                    <label>Firmenname</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">apartment</i>
                        <input type="text" id="company_name" placeholder="Ihre Firma GmbH" required maxlength="200" autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label>Ihr Name</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">person</i>
                        <input type="text" id="full_name" placeholder="Vor- und Nachname" required maxlength="100">
                    </div>
                </div>

                <div class="form-group">
                    <label>E-Mail-Adresse</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">mail</i>
                        <input type="email" id="email" placeholder="name@firma.de" required autocomplete="email">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Telefon (optional)</label>
                        <div class="input-wrapper">
                            <i class="material-symbols-outlined">call</i>
                            <input type="tel" id="phone" placeholder="+49 …" maxlength="50">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Tarif</label>
                        <div class="input-wrapper">
                            <i class="material-symbols-outlined">layers</i>
                            <select id="plan"><option value="trial">Testphase</option></select>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Passwort</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">lock</i>
                        <input type="password" id="password" placeholder="Mind. 8 Zeichen" required minlength="8" autocomplete="new-password">
                        <button type="button" class="toggle" onclick="togglePw('password', this)" tabindex="-1"><i class="material-symbols-outlined">visibility</i></button>
                    </div>
                    <div class="pw-hint">Mindestens 8 Zeichen. Empfehlung: Buchstaben, Ziffern und Sonderzeichen mischen.</div>
                </div>

                <div class="form-group">
                    <label>Passwort wiederholen</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">lock</i>
                        <input type="password" id="password_confirmation" placeholder="Passwort erneut eingeben" required minlength="8" autocomplete="new-password">
                        <button type="button" class="toggle" onclick="togglePw('password_confirmation', this)" tabindex="-1"><i class="material-symbols-outlined">visibility</i></button>
                    </div>
                </div>

                <div class="terms">
                    <input type="checkbox" id="accept_terms" required>
                    <label for="accept_terms">Ich akzeptiere die <a href="#" target="_blank">AGB</a> und die <a href="#" target="_blank">Datenschutzerklärung</a>.</label>
                </div>

                <button type="submit" class="btn" id="btnSubmit">
                    <i class="material-symbols-outlined">person_add</i>
                    <span>Konto erstellen</span>
                </button>
            </form>

            <a href="login.php" class="back-link"><i class="material-symbols-outlined">arrow_back</i> Bereits ein Konto? Anmelden</a>
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
            btn.innerHTML = showing ? '<i class="material-symbols-outlined">visibility</i>' : '<i class="material-symbols-outlined">visibility_off</i>';
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
            btn.innerHTML = '<i class="material-symbols-outlined ms-spin">progress_activity</i> Konto wird erstellt…';

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
            btn.innerHTML = '<i class="material-symbols-outlined">person_add</i> <span>Konto erstellen</span>';
        }
    </script>
</body>
</html>
