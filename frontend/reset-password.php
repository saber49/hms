<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Neues Passwort festlegen — Clood-X</title>
    <link rel="stylesheet" href="/assets/css/roboto.css?v=20261119ms1">
    <link rel="stylesheet" href="assets/css/md3-tokens.css">
    <link rel="stylesheet" href="/assets/css/material-symbols.css?v=20261119ms1">
    <link rel="stylesheet" href="assets/css/auth-md3.css?v=20261116ui1">
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
