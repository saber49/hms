<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;margin:0;padding:0;background:#f4f6f8">
<div style="max-width:600px;margin:20px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#062e6f,#0b57d0);padding:28px 32px;color:#fff">
        <h1 style="margin:0;font-size:22px;font-weight:700">E-Mail bestätigen</h1>
        <p style="margin:8px 0 0;font-size:13px;opacity:0.85">{{ $company ?: 'Clood-X' }}</p>
    </div>

    {{-- Body --}}
    <div style="padding:28px 32px">
        <p>Hallo <strong>{{ $full_name }}</strong>,</p>

        <p>
            willkommen bei Clood-X! Bitte bestätigen Sie Ihre E-Mail-Adresse
            <code style="background:#f1f5f9;padding:2px 6px;border-radius:3px;font-size:13px">{{ $email }}</code>,
            um Ihr Konto zu aktivieren.
        </p>

        <p>Klicken Sie dazu einfach auf den folgenden Button:</p>

        {{-- CTA --}}
        <div style="text-align:center;margin:32px 0">
            <a href="{{ $verify_url }}"
               style="display:inline-block;background:#0b57d0;color:#fff;text-decoration:none;padding:14px 32px;border-radius:6px;font-weight:600;font-size:15px">
                E-Mail-Adresse bestätigen
            </a>
            <p style="margin:14px 0 0;font-size:12px;color:#64748b">
                Funktioniert der Button nicht? Kopieren Sie diesen Link in Ihren Browser:<br>
                <span style="font-family:monospace;word-break:break-all;color:#475569">{{ $verify_url }}</span>
            </p>
        </div>

        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-left:4px solid #0b57d0;border-radius:6px;padding:14px 18px;margin:18px 0;font-size:13px;color:#1e3a8a">
            <strong>Sie haben sich nicht registriert?</strong> Dann ignorieren Sie diese
            E-Mail einfach — ohne Bestätigung wird das Konto nicht aktiviert.
        </div>
    </div>

    {{-- Footer --}}
    <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 32px;font-size:11px;color:#64748b;text-align:center">
        Diese Nachricht wurde automatisch von Clood-X gesendet
    </div>
</div>
</body>
</html>
