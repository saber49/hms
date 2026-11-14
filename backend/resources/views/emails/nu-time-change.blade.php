<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;margin:0;padding:0;background:#f4f6f8">
<div style="max-width:600px;margin:20px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#f59e0b,#d97706);padding:24px 32px;color:#fff">
        <h1 style="margin:0;font-size:20px;font-weight:700">Einsatzzeiten ge&auml;ndert</h1>
        <p style="margin:6px 0 0;font-size:13px;opacity:0.85">{{ $company }}</p>
    </div>

    {{-- Body --}}
    <div style="padding:28px 32px">
        <p>Sehr geehrte Damen und Herren von <strong>{{ $firmenname }}</strong>,</p>
        <p>im Namen von <strong>{{ $company }}</strong> möchten wir Sie informieren, dass die Einsatzzeiten für folgenden Einsatz geändert wurden:</p>

        <table style="width:100%;border-collapse:collapse;margin:16px 0">
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;width:140px">Kunde</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $kunde }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Objekt</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $objekt }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Datum</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $datum }}</td>
            </tr>
        </table>

        {{-- Time change highlight --}}
        <div style="background:#fffbeb;border:1px solid #fcd34d;border-left:4px solid #f59e0b;border-radius:6px;padding:14px 18px;margin:16px 0">
            <p style="margin:0 0 8px;font-weight:700;color:#92400e;font-size:13px">&#9200; Zeitänderung:</p>
            <table style="width:100%;border-collapse:collapse">
                <tr>
                    <td style="padding:4px 8px;width:60px"></td>
                    <td style="padding:4px 8px;font-weight:600;font-size:12px;color:#64748b">Beginn</td>
                    <td style="padding:4px 8px;font-weight:600;font-size:12px;color:#64748b">Ende</td>
                </tr>
                <tr>
                    <td style="padding:4px 8px;font-size:12px;color:#94a3b8;font-weight:600">Vorher</td>
                    <td style="padding:4px 8px;text-decoration:line-through;color:#94a3b8">{{ $oldStart ?: '–' }}</td>
                    <td style="padding:4px 8px;text-decoration:line-through;color:#94a3b8">{{ $oldEnd ?: '–' }}</td>
                </tr>
                <tr>
                    <td style="padding:4px 8px;font-size:12px;color:#166534;font-weight:600">Jetzt</td>
                    <td style="padding:4px 8px;font-weight:700;color:#166534">{{ $newStart ?: '–' }}</td>
                    <td style="padding:4px 8px;font-weight:700;color:#166534">{{ $newEnd ?: '–' }}</td>
                </tr>
            </table>
        </div>

        <p style="margin-top:20px;font-size:13px;color:#64748b">Bitte informieren Sie Ihre zugewiesenen Mitarbeiter &uuml;ber die ge&auml;nderten Zeiten.</p>
    </div>

    {{-- Footer --}}
    <div style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center">
        Diese E-Mail wurde automatisch von {{ $company }} &uuml;ber Clood-x generiert.<br>Bitte antworten Sie nicht auf diese E-Mail.
    </div>
</div>
</body>
</html>
