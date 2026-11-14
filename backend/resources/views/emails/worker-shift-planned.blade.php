<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;margin:0;padding:0;background:#f4f6f8">
<div style="max-width:600px;margin:20px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:24px 32px;color:#fff">
        <h1 style="margin:0;font-size:20px;font-weight:700">Ihr Einsatzplan ist da</h1>
        <p style="margin:6px 0 0;font-size:13px;opacity:0.85">{{ $company }}</p>
    </div>

    {{-- Body --}}
    <div style="padding:28px 32px">
        <p>Hallo <strong>{{ $workerName }}</strong>,</p>
        <p>Sie wurden für folgenden Einsatz eingeplant:</p>

        <table style="width:100%;border-collapse:collapse;margin:16px 0">
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;width:140px">Datum</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $datum }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Zeit</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0;font-weight:700;color:#1d4ed8">{{ $zeit }} Uhr</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Kunde</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $kunde }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Objekt</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $objekt }}</td>
            </tr>
            @if(!empty($ort))
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Ort / Treffpunkt</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $ort }}</td>
            </tr>
            @endif
            @if(!empty($aufgabe))
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Ihre Aufgabe</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $aufgabe }}</td>
            </tr>
            @endif
        </table>

        <p style="margin-top:20px;font-size:13px;color:#64748b">Alle Details finden Sie wie gewohnt in Ihrer Mitarbeiter-App.</p>
    </div>

    {{-- Footer --}}
    <div style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center">
        Diese E-Mail wurde automatisch von {{ $company }} &uuml;ber Clood-x generiert.<br>Bitte antworten Sie nicht auf diese E-Mail.
    </div>
</div>
</body>
</html>
