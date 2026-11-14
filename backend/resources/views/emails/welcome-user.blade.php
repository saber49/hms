<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;margin:0;padding:0;background:#f4f6f8">
<div style="max-width:600px;margin:20px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#1e3a5f,#2563eb);padding:28px 32px;color:#fff">
        <h1 style="margin:0;font-size:22px;font-weight:700">Willkommen bei {{ $company }}</h1>
        <p style="margin:8px 0 0;font-size:13px;opacity:0.85">Ihr Zugang zum Clood-X System wurde eingerichtet</p>
    </div>

    {{-- Body --}}
    <div style="padding:28px 32px">
        <p>Hallo <strong>{{ $full_name }}</strong>,</p>

        <p>
            ein Administrator von <strong>{{ $company }}</strong> hat ein Benutzerkonto
            für Sie im Clood-X System erstellt. Sie können sich ab sofort anmelden und
            Ihre zugewiesenen Aufgaben bearbeiten.
        </p>

        {{-- Credentials box --}}
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-left:4px solid #2563eb;border-radius:6px;padding:18px 22px;margin:20px 0">
            <p style="margin:0 0 12px;font-weight:700;color:#1e3a5f;font-size:13px">Ihre Zugangsdaten</p>
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                <tr>
                    <td style="padding:6px 0;font-weight:600;width:130px;vertical-align:top">Benutzername</td>
                    <td style="padding:6px 0;font-family:'Courier New',monospace">{{ $username }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 0;font-weight:600;vertical-align:top">E-Mail</td>
                    <td style="padding:6px 0">{{ $email }}</td>
                </tr>
                @if(!empty($temp_password))
                <tr>
                    <td style="padding:6px 0;font-weight:600;vertical-align:top">Temporäres Passwort</td>
                    <td style="padding:6px 0">
                        <code style="background:#fff;border:1px solid #bfdbfe;padding:4px 10px;border-radius:4px;font-size:15px;font-weight:700;letter-spacing:0.5px">{{ $temp_password }}</code>
                    </td>
                </tr>
                @endif
            </table>
        </div>

        @if(!empty($temp_password))
        <div style="background:#fef3c7;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:6px;padding:14px 18px;margin:16px 0;font-size:13px;color:#78350f">
            <strong>Wichtig:</strong> Dieses Passwort ist nur vorübergehend. Bei Ihrer
            ersten Anmeldung werden Sie automatisch aufgefordert, ein eigenes, sicheres
            Passwort festzulegen. Bewahren Sie diese E-Mail sicher auf, bis Sie sich
            angemeldet und Ihr Passwort geändert haben.
        </div>
        @endif

        {{-- CTA --}}
        <div style="text-align:center;margin:28px 0">
            <a href="{{ $login_url }}"
               style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:600;font-size:14px">
                Jetzt anmelden
            </a>
            <p style="margin:10px 0 0;font-size:12px;color:#64748b">
                oder kopieren Sie: <span style="font-family:monospace">{{ $login_url }}</span>
            </p>
        </div>

        <p style="font-size:12px;color:#64748b;margin-top:24px">
            Sie erhalten diese E-Mail, weil ein Administrator Ihr Konto bei Clood-X
            angelegt hat. Falls Sie diese E-Mail unerwartet erhalten haben, ignorieren
            Sie sie bitte oder kontaktieren Sie den Administrator von {{ $company }}.
        </p>
    </div>

    {{-- Footer --}}
    <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 32px;font-size:11px;color:#64748b;text-align:center">
        Diese Nachricht wurde automatisch von Clood-X gesendet · {{ $company }}
    </div>
</div>
</body>
</html>
