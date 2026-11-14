<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;margin:0;padding:0;background:#f4f6f8">
<div style="max-width:600px;margin:20px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#0ea5e9,#0369a1);padding:24px 32px;color:#fff">
        <h1 style="margin:0;font-size:20px;font-weight:700">
            @if($action === 'updated')
                &#9998; Wunschfrei-Antrag aktualisiert
            @else
                &#128221; Neuer Wunschfrei-Antrag
            @endif
        </h1>
        <p style="margin:6px 0 0;font-size:13px;opacity:0.9">{{ $company }}</p>
    </div>

    {{-- Body --}}
    <div style="padding:28px 32px">
        <p style="margin:0 0 12px">Guten Tag,</p>
        <p style="margin:0 0 16px">
            @if($action === 'updated')
                <strong>{{ $workerName }}</strong> (Dienst-Nr. <strong>{{ $dienstNr }}</strong>)
                hat einen bestehenden Wunschfrei-Antrag <strong>aktualisiert</strong>. Bitte prüfen Sie die geänderten Details.
            @else
                <strong>{{ $workerName }}</strong> (Dienst-Nr. <strong>{{ $dienstNr }}</strong>)
                hat über die Mitarbeiter-App einen <strong>Wunschfrei-Antrag eingereicht</strong>
                und wartet auf Ihre Entscheidung.
            @endif
        </p>

        {{-- Details --}}
        <table style="width:100%;border-collapse:collapse;margin:16px 0">
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;width:140px">Mitarbeiter</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $workerName }} <span style="color:#94a3b8">({{ $dienstNr }})</span></td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Zeitraum</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $zeitraum }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Zeit</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $timeLabel }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Tage</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $tage }} {{ ((float)$tage == 1.0) ? 'Tag' : 'Tage' }}</td>
            </tr>
            @if(!empty($bemerkung))
                <tr>
                    <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;vertical-align:top">Bemerkung</td>
                    <td style="padding:8px 12px;border:1px solid #e2e8f0;white-space:pre-wrap">{{ $bemerkung }}</td>
                </tr>
            @endif
        </table>

        {{-- Pending highlight --}}
        <div style="background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:6px;padding:14px 18px;margin:16px 0">
            <p style="margin:0;font-weight:700;color:#92400e;font-size:13px">&#9200; Status: Wartet auf Ihre Entscheidung</p>
            <p style="margin:6px 0 0;color:#92400e;font-size:13px">
                Sie können den Antrag <strong>genehmigen</strong> oder <strong>ablehnen</strong>.
                Der Mitarbeiter erhält automatisch eine Benachrichtigung mit Ihrem Beschluss.
            </p>
        </div>

        {{-- CTA --}}
        @if(!empty($portalUrl))
            <p style="margin:24px 0;text-align:center">
                <a href="{{ $portalUrl }}" style="display:inline-block;background:linear-gradient(135deg,#0ea5e9,#0369a1);color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;font-size:14px;box-shadow:0 4px 8px rgba(14,165,233,0.25)">
                    Antrag im Portal öffnen &rarr;
                </a>
            </p>
        @endif

        <p style="margin-top:20px;font-size:13px;color:#64748b">
            Pfad im System: <strong>Mitarbeiter</strong> &rarr; <strong>{{ $workerName }}</strong> &rarr; <strong>Abwesenheiten</strong>
        </p>
    </div>

    {{-- Footer --}}
    <div style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center">
        Diese E-Mail wurde automatisch von <strong>{{ $company }}</strong> über Clood-x generiert.<br>
        Bitte antworten Sie nicht auf diese E-Mail.
    </div>
</div>
</body>
</html>
