<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color: #1e293b;">

<div style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: white; padding: 24px; border-radius: 12px 12px 0 0; text-align: center;">
    <h1 style="margin: 0; font-size: 20px;">Einsatz storniert</h1>
</div>

<div style="background: #fff; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 12px 12px; padding: 24px;">
    <p>Hallo <strong>{{ $firmenname }}</strong>,</p>

    <p>der folgende Einsatz wurde von <strong>{{ $company }}</strong> storniert und entfernt:</p>

    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #e2e8f0;">
            <td style="padding: 8px 0; color: #64748b; width: 120px;">Datum</td>
            <td style="padding: 8px 0; font-weight: 600;">{{ $datum }}</td>
        </tr>
        <tr style="border-bottom: 1px solid #e2e8f0;">
            <td style="padding: 8px 0; color: #64748b;">Zeit</td>
            <td style="padding: 8px 0;">{{ $zeit }}</td>
        </tr>
        <tr style="border-bottom: 1px solid #e2e8f0;">
            <td style="padding: 8px 0; color: #64748b;">Objekt</td>
            <td style="padding: 8px 0;">{{ $objekt }}</td>
        </tr>
        <tr>
            <td style="padding: 8px 0; color: #64748b;">Kunde</td>
            <td style="padding: 8px 0;">{{ $kunde }}</td>
        </tr>
    </table>

    <div style="background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px 16px; border-radius: 4px; margin: 16px 0;">
        <strong>Bitte beachten Sie:</strong> Falls Sie bereits Mitarbeiter für diesen Einsatz eingeplant haben, können diese freigestellt werden.
    </div>

    <p style="color: #64748b; font-size: 13px; margin-top: 24px;">
        Diese Nachricht wurde automatisch generiert.<br>
        Bei Fragen wenden Sie sich bitte direkt an {{ $company }}.
    </p>
</div>

</body>
</html>
