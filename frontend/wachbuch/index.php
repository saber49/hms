<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Wachbuch — Objekt-Terminal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Shared Wachbuch/Dienstanweisung UI — same files as the worker app -->
    <link rel="stylesheet" href="/worker/assets/css/qm-wachbuch.css?v=20261118qmfix2">
    <link rel="stylesheet" href="terminal.css?v=20261109qm1">
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
</head>
<body>
    <header class="t-top">
        <div><i class="fas fa-shield-halved"></i> <strong id="tObjekt">Objekt-Terminal</strong><div class="t-sub" id="tSub"></div></div>
        <div class="t-top-actions" id="tTopActions"></div>
    </header>
    <main id="tMain"><div class="wqm-loading"><i class="fas fa-spinner fa-spin"></i></div></main>
    <script src="/worker/assets/js/qm-wachbuch.js?v=20261118qmfix2"></script>
    <script src="/worker/assets/js/qm-live.js?v=20261109live1"></script>
    <script src="terminal.js?v=20261118qmfix2"></script>
</body>
</html>
