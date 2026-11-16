<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Passwort vergessen — Clood-X</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="assets/css/md3-tokens.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block">
    <link rel="stylesheet" href="assets/css/auth-md3.css?v=20261116ui1">
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
            <div class="icon"><i class="material-symbols-outlined">vpn_key</i></div>
            <h1>Clood-X</h1>
            <p>Operations Management System</p>
        </div>

        <div class="card">
            <h2>Passwort vergessen</h2>
            <p class="lead">Geben Sie Ihre E-Mail-Adresse ein — wir senden Ihnen einen Link zum Zurücksetzen.</p>

            <div class="alert" id="alertBox"><i class="material-symbols-outlined">info</i><span id="alertText"></span></div>

            <form id="forgotForm" onsubmit="handleForgot(event)">
                <div class="form-group">
                    <label>E-Mail-Adresse</label>
                    <div class="input-wrapper">
                        <i class="material-symbols-outlined">mail</i>
                        <input type="email" id="email" placeholder="ihre@firma.de" required autocomplete="email" autofocus>
                    </div>
                </div>

                <button type="submit" class="btn" id="btnSubmit">
                    <i class="material-symbols-outlined">send</i>
                    <span>Link senden</span>
                </button>
            </form>

            <a href="login.php" class="back-link"><i class="material-symbols-outlined">arrow_back</i> Zurück zur Anmeldung</a>
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
            btn.innerHTML = '<i class="material-symbols-outlined ms-spin">progress_activity</i> Senden...';
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
                btn.innerHTML = '<i class="material-symbols-outlined">send</i> <span>Link senden</span>';
            }
        }
    </script>
</body>
</html>
