<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;margin:0;padding:0;background:#f4f6f8">
<div style="max-width:600px;margin:20px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#1e3a5f,#2563eb);padding:24px 32px;color:#fff">
        <h1 style="margin:0;font-size:20px;font-weight:700">Neue Einsatzanfrage</h1>
        <p style="margin:6px 0 0;font-size:13px;opacity:0.85">{{ $company }}</p>
    </div>

    {{-- Body --}}
    <div style="padding:28px 32px">
        <p>Sehr geehrte Damen und Herren von <strong>{{ $firmenname }}</strong>,</p>
        <p>im Namen von <strong>{{ $company }}</strong> möchten wir Ihnen eine neue Einsatzanfrage übermitteln:</p>

        <table style="width:100%;border-collapse:collapse;margin:16px 0">
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600;width:140px">Kunde</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $kunde }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Objekt</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $objekt }}</td>
            </tr>
            @if(!empty($adresse))
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Adresse</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $adresse }}</td>
            </tr>
            @endif
            @if(!empty($ort))
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Ort/Halle</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $ort }}</td>
            </tr>
            @endif
            @if(!empty($ansprechpartner))
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Ansprechpartner</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $ansprechpartner }}@if(!empty($kontakt_telefon)) &middot; {{ $kontakt_telefon }}@endif</td>
            </tr>
            @endif
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Datum</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $datum }}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;font-weight:600">Zeit</td>
                <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $zeit }}</td>
            </tr>
        </table>

        {{-- Positions --}}
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-left:4px solid #2563eb;border-radius:6px;padding:14px 18px;margin:16px 0">
            <p style="margin:0 0 8px;font-weight:700;color:#1e3a5f;font-size:13px"><i>&#128203;</i> Angefragte Positionen ({{ $positionCount }}):</p>
            @foreach($positions as $pos)
                @php
                    $gMix = $pos['geschlecht_mix'] ?? null;
                    if (is_string($gMix)) { $gMix = json_decode($gMix, true); }
                    $gLabel = null;
                    if (is_array($gMix) && (($gMix['maennlich'] ?? 0) > 0 || ($gMix['weiblich'] ?? 0) > 0)) {
                        $gParts = [];
                        if (($gMix['maennlich'] ?? 0) > 0) $gParts[] = '&#9794; ' . $gMix['maennlich'];
                        if (($gMix['weiblich'] ?? 0) > 0) $gParts[] = '&#9792; ' . $gMix['weiblich'];
                        $gLabel = implode(' &middot; ', $gParts);
                    } elseif (!empty($pos['geschlecht'])) {
                        $gLabel = ['maennlich' => '&#9794; M&auml;nnlich', 'weiblich' => '&#9792; Weiblich', 'gemischt' => 'Gemischt'][$pos['geschlecht']] ?? $pos['geschlecht'];
                    }
                @endphp
                <div style="margin:6px 0;padding:6px 10px;background:white;border-radius:4px;border:1px solid #dbeafe">
                    <strong>{{ $pos['position_name'] }}</strong> &mdash; {{ $pos['anzahl'] }}x
                    @if($gLabel)
                        <br><span style="font-size:12px;color:#64748b">Geschlecht: {!! $gLabel !!}</span>
                    @endif
                    @if(!empty($pos['qualifikation']))
                        <br><span style="font-size:12px;color:#64748b">Qualifikation: {{ $pos['qualifikation'] }}</span>
                    @endif
                    @if(!empty($pos['skills']))
                        <br><span style="font-size:12px;color:#64748b">Skills: {{ $pos['skills'] }}</span>
                    @endif
                    @if(!empty($pos['kleidung']))
                        <br><span style="font-size:12px;color:#64748b">Kleidung: {{ $pos['kleidung'] }}</span>
                    @endif
                    @if(!empty($pos['checkin_info']))
                        <br><span style="font-size:12px;color:#64748b">Check-in: {{ $pos['checkin_info'] }}</span>
                    @endif
                    @if(!empty($pos['bemerkung']))
                        <br><span style="font-size:12px;color:#64748b">Hinweis: {{ $pos['bemerkung'] }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        <p style="margin-top:20px;font-size:13px;color:#64748b">Bitte melden Sie sich im NU-Portal an, um die Anfrage zu bearbeiten und Mitarbeiter zuzuweisen.</p>

        @if($portalUrl)
            <div style="text-align:center;margin:20px 0">
                <a href="{{ $portalUrl }}" style="display:inline-block;background:#2563eb;color:white;text-decoration:none;padding:10px 24px;border-radius:6px;font-weight:600;font-size:14px">Zum NU-Portal &rarr;</a>
            </div>
        @endif
    </div>

    {{-- Footer --}}
    <div style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center">
        Diese E-Mail wurde automatisch von {{ $company }} &uuml;ber Clood-x generiert.<br>Bitte antworten Sie nicht auf diese E-Mail.
    </div>
</div>
</body>
</html>
