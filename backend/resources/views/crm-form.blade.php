<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titel }} — Anfrage</title>
<style>
    * { box-sizing: border-box; margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    body { background: #f1f5f9; color: #1e293b; display: flex; justify-content: center; padding: 24px 12px; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 28px; width: 100%; max-width: 460px; box-shadow: 0 4px 18px rgba(15,23,42,.06); }
    h1 { font-size: 1.25rem; margin-bottom: 4px; }
    .sub { color: #64748b; font-size: .85rem; margin-bottom: 20px; }
    label { display: block; font-size: .82rem; font-weight: 600; color: #475569; margin: 12px 0 4px; }
    input, textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 9px; padding: 10px 12px; font-size: .95rem; }
    input:focus, textarea:focus { outline: 2px solid #4f46e5; border-color: #4f46e5; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .check { display: flex; gap: 8px; align-items: flex-start; margin-top: 14px; font-size: .8rem; color: #475569; }
    .check input { width: auto; margin-top: 2px; }
    button { margin-top: 20px; width: 100%; background: #4f46e5; color: #fff; border: 0; border-radius: 999px; padding: 12px; font-size: 1rem; font-weight: 700; cursor: pointer; }
    button:disabled { opacity: .6; cursor: default; }
    .err { color: #dc2626; font-size: .8rem; margin-top: 8px; }
    .ok { text-align: center; padding: 30px 0; }
    .ok .ico { font-size: 44px; }
    .hp { position: absolute; left: -9999px; opacity: 0; height: 0; }
    @media (max-width: 420px) { .row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="card">
    <div id="f">
        <h1>{{ $titel }}</h1>
        <div class="sub">Anfrage — wir melden uns schnellstmöglich bei Ihnen.</div>
        <form onsubmit="return senden(event)">
            <div class="row">
                <div><label for="vorname">Vorname</label><input id="vorname" maxlength="100" autocomplete="given-name"></div>
                <div><label for="nachname">Nachname *</label><input id="nachname" maxlength="100" required autocomplete="family-name"></div>
            </div>
            <label for="firma">Firma / Organisation</label><input id="firma" maxlength="200" autocomplete="organization">
            <div class="row">
                <div><label for="email">E-Mail *</label><input id="email" type="email" maxlength="200" autocomplete="email"></div>
                <div><label for="telefon">Telefon *</label><input id="telefon" maxlength="60" autocomplete="tel"></div>
            </div>
            <div class="row">
                <div><label for="plz">PLZ</label><input id="plz" maxlength="10" inputmode="numeric" autocomplete="postal-code"></div>
                <div><label for="ort">Ort</label><input id="ort" maxlength="120" autocomplete="address-level2"></div>
            </div>
            <label for="nachricht">Ihre Nachricht</label><textarea id="nachricht" rows="3" maxlength="5000"></textarea>
            <input id="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true"><!-- honeypot -->
            <label class="check"><input id="dsgvo" type="checkbox" required>
                <span>Ich stimme zu, dass meine Angaben zur Bearbeitung der Anfrage gespeichert und verarbeitet werden (DSGVO). *</span></label>
            <div class="err" id="err"></div>
            <button type="submit" id="btn">Anfrage senden</button>
        </form>
        <div class="sub" style="margin-top:14px;font-size:.72rem">* E-Mail oder Telefon reicht aus — mindestens eines bitte angeben.</div>
    </div>
    <div id="danke" class="ok" hidden>
        <div class="ico">✅</div>
        <h1>Vielen Dank!</h1>
        <div class="sub">Ihre Anfrage ist bei uns eingegangen. Wir melden uns zeitnah bei Ihnen.</div>
    </div>
</div>
<script>
async function senden(e) {
    e.preventDefault();
    const err = document.getElementById('err'), btn = document.getElementById('btn');
    err.textContent = '';
    const v = id => document.getElementById(id).value.trim();
    if (!v('email') && !v('telefon')) { err.textContent = 'Bitte E-Mail oder Telefon angeben.'; return false; }
    btn.disabled = true;
    try {
        const r = await fetch(location.pathname, {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ vorname: v('vorname'), nachname: v('nachname'), firma: v('firma'), email: v('email'),
                telefon: v('telefon'), plz: v('plz'), ort: v('ort'), nachricht: v('nachricht'),
                dsgvo: document.getElementById('dsgvo').checked, website: v('website') })
        });
        const j = await r.json().catch(() => ({}));
        if (j.ok) { document.getElementById('f').hidden = true; document.getElementById('danke').hidden = false; }
        else err.textContent = Object.values(j.errors || {}).flat()[0] || 'Senden fehlgeschlagen — bitte später erneut versuchen.';
    } catch (x) { err.textContent = 'Verbindung fehlgeschlagen — bitte später erneut versuchen.'; }
    btn.disabled = false;
    return false;
}
</script>
</body>
</html>
