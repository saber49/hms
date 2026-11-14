<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;margin:0;padding:0;background:#f4f6f8">
<div style="max-width:600px;margin:20px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">

    {{-- Header — green for approved, red for rejected --}}
    @if($approved)
        <div style="background:linear-gradient(135deg,#10b981,#059669);padding:24px 32px;color:#fff">
            <h1 style="margin:0;font-size:20px;font-weight:700">&#10003; Antrag genehmigt</h1>
            <p style="margin:6px 0 0;font-size:13px;opacity:0.9">{{ $company }}</p>
        </div>
    @else
        <div style="background:linear-gradient(135deg,#ef4444,#dc2626);padding:24px 32px;color:#fff">
            <h1 style="margin:0;font-size:20px;font-weight:700">&#10007; Antrag abgelehnt</h1>
            <p style="margin:6px 0 0;font-size:13px;opacity:0.9">{{ $company }}</p>
        </div>
    @endif

    {{-- Body --}}
    <div style="padding:28px 32px">
        <p style="margin:0 0 12px">Hallo <strong>{{ $workerName }}</strong>,</p>

        @if($approved)
            <p style="margin:0 0 16px">Ihr Antrag bei <strong>{{ $company }}</strong> wurde <strong style="color:#059669">genehmigt</strong>.
                Sie können sich auf den freien Tag freuen — er ist nun fest in der Planung eingetragen.</p>
        @else
            <p style="margin:0 0 16px">Leider konnte Ihrem Antrag bei <strong>{{ $company }}</strong> dieses Mal <strong style="color:#dc2626">nicht stattgegeben</strong> werden.
                Die Details finden Sie unten.</p>
        @endif

        {{-- Details table --}}
        <table style="width:100%;border-collapse:collapse;margin:16px 0">
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;width:140px">Art</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $typ }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Zeitraum</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $zeitraum }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Tage</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $tage }}</td>
            </tr>
            @if(!empty($beschreibung))
                <tr>
                    <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;vertical-align:top">Ihr Hinweis</td>
                    <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $beschreibung }}</td>
                </tr>
            @endif
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Entschieden am</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $decidedAt }}</td>
            </tr>
        </table>

        {{-- Highlight box: approved → success / rejected → reason --}}
        @if($approved)
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-left:4px solid #10b981;border-radius:6px;padding:14px 18px;margin:16px 0">
                <p style="margin:0;font-weight:700;color:#065f46;font-size:13px">&#10003; Status: Genehmigt</p>
                <p style="margin:6px 0 0;color:#065f46;font-size:13px">Der Antrag ist verbindlich. Bei Rückfragen wenden Sie sich bitte an Ihren Vorgesetzten.</p>
            </div>
        @else
            <div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:6px;padding:14px 18px;margin:16px 0">
                <p style="margin:0 0 4px;font-weight:700;color:#991b1b;font-size:13px">&#9888; Begründung der Ablehnung:</p>
                <p style="margin:0;color:#1a1a1a;white-space:pre-wrap">{{ $grund ?: 'Es wurde keine Begründung hinterlegt. Wenden Sie sich bei Fragen bitte an Ihren Vorgesetzten.' }}</p>
            </div>
        @endif

        <p style="margin-top:20px;font-size:13px;color:#64748b">
            Diese Benachrichtigung finden Sie ebenfalls in Ihrer <strong>Mitarbeiter-App</strong> unter dem Reiter „Frei".
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
