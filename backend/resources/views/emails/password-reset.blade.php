<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;margin:0;padding:0;background:#f4f6f8">
<div style="max-width:600px;margin:20px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#7c2d12,#dc2626);padding:28px 32px;color:#fff">
        <h1 style="margin:0;font-size:22px;font-weight:700">Passwort zurücksetzen</h1>
        <p style="margin:8px 0 0;font-size:13px;opacity:0.85">{{ $company ?: 'Clood-X' }}</p>
    </div>

    {{-- Body --}}
    <div style="padding:28px 32px">
        <p>Hallo <strong>{{ $full_name }}</strong>,</p>

        <p>
            wir haben eine Anfrage erhalten, Ihr Passwort für das Clood-X-Konto
            <code style="background:#f1f5f9;padding:2px 6px;border-radius:3px;font-size:13px">{{ $email }}</code>
            zurückzusetzen.
        </p>

        <p>
            Klicken Sie auf den folgenden Button, um ein neues Passwort zu vergeben.
            Der Link ist <strong>60 Minuten lang gültig</strong> und kann nur einmal
            verwendet werden.
        </p>

        {{-- CTA --}}
        <div style="text-align:center;margin:32px 0">
            <a href="{{ $reset_url }}"
               style="display:inline-block;background:#dc2626;color:#fff;text-decoration:none;padding:14px 32px;border-radius:6px;font-weight:600;font-size:15px">
                Neues Passwort festlegen
            </a>
            <p style="margin:14px 0 0;font-size:12px;color:#64748b">
                Funktioniert der Button nicht? Kopieren Sie diesen Link in Ihren Browser:<br>
                <span style="font-family:monospace;word-break:break-all;color:#475569">{{ $reset_url }}</span>
            </p>
        </div>

        <div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:6px;padding:14px 18px;margin:18px 0;font-size:13px;color:#7f1d1d">
            <strong>Sie haben das nicht angefordert?</strong> Dann ignorieren Sie diese
            E-Mail einfach — Ihr Passwort bleibt unverändert. Falls Sie aber besorgt sind,
            dass jemand unbefugt Zugriff versucht, kontaktieren Sie bitte einen
            Administrator.
        </div>

        <p style="font-size:12px;color:#64748b;margin-top:20px">
            Aus Sicherheitsgründen zeigen wir nicht an, ob die E-Mail-Adresse bei uns
            registriert ist. Wenn Sie diese Mail nicht erwartet haben, ignorieren Sie sie.
        </p>
    </div>

    {{-- Footer --}}
    <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 32px;font-size:11px;color:#64748b;text-align:center">
        Diese Nachricht wurde automatisch von Clood-X gesendet
    </div>
</div>
</body>
</html>
