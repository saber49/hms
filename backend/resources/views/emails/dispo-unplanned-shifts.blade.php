<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;margin:0;padding:0;background:#f4f6f8">
<div style="max-width:640px;margin:20px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#0ea5e9,#0369a1);padding:24px 32px;color:#fff">
        <h1 style="margin:0;font-size:20px;font-weight:700">&#128197; Einsätze noch nicht geplant</h1>
        <p style="margin:6px 0 0;font-size:13px;opacity:0.9">{{ $company }}</p>
    </div>

    {{-- Body --}}
    <div style="padding:28px 32px">
        <p style="margin:0 0 12px">Guten Tag,</p>
        <p style="margin:0 0 16px">
            @if($total === 1)
                es gibt aktuell <strong>1 offenen Einsatz</strong> ab heute, der noch <strong>nicht eingeplant</strong> wurde.
            @else
                es gibt aktuell <strong>{{ $total }} offene Einsätze</strong> ab heute, die noch <strong>nicht eingeplant</strong> wurden.
            @endif
            Bitte schließen Sie die Planung ab, damit die zugewiesenen Mitarbeiter informiert werden.
        </p>

        {{-- Urgency highlight --}}
        <div style="background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:6px;padding:14px 18px;margin:16px 0">
            <p style="margin:0;font-weight:700;color:#92400e;font-size:13px">&#9888;
                @if($total === 1) 1 offener Einsatz ohne Planung
                @else {{ $total }} offene Einsätze ohne Planung @endif
            </p>
            <p style="margin:6px 0 0;color:#92400e;font-size:13px">Frühester Termin: <strong>{{ $earliest }}</strong></p>
        </div>

        {{-- Shift table --}}
        <table style="width:100%;border-collapse:collapse;margin:16px 0">
            <thead>
                <tr>
                    <th style="padding:8px 12px;background:#0f172a;color:#fff;text-align:left;font-size:12px;border:1px solid #0f172a">Datum</th>
                    <th style="padding:8px 12px;background:#0f172a;color:#fff;text-align:left;font-size:12px;border:1px solid #0f172a">Zeit</th>
                    <th style="padding:8px 12px;background:#0f172a;color:#fff;text-align:left;font-size:12px;border:1px solid #0f172a">Kunde</th>
                    <th style="padding:8px 12px;background:#0f172a;color:#fff;text-align:left;font-size:12px;border:1px solid #0f172a">Objekt</th>
                </tr>
            </thead>
            <tbody>
                @foreach($shifts as $s)
                    <tr>
                        <td style="padding:8px 12px;border:1px solid #e2e8f0;white-space:nowrap">{{ $s['datum'] }}</td>
                        <td style="padding:8px 12px;border:1px solid #e2e8f0;white-space:nowrap">{{ $s['zeit'] }}</td>
                        <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $s['kunde'] }}</td>
                        <td style="padding:8px 12px;border:1px solid #e2e8f0">{{ $s['objekt'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($total > count($shifts))
            <p style="margin:0 0 16px;font-size:13px;color:#64748b">
                … und <strong>{{ $total - count($shifts) }}</strong> weitere offene Einsätze (hier werden die nächsten {{ $previewLimit }} angezeigt).
            </p>
        @endif

        {{-- CTA --}}
        @if(!empty($portalUrl))
            <p style="margin:24px 0;text-align:center">
                <a href="{{ $portalUrl }}" style="display:inline-block;background:linear-gradient(135deg,#0ea5e9,#0369a1);color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;font-size:14px;box-shadow:0 4px 8px rgba(14,165,233,0.25)">
                    Einsätze &amp; Dispo öffnen &rarr;
                </a>
            </p>
        @endif

        <p style="margin-top:20px;font-size:13px;color:#64748b">
            Pfad im System: <strong>Aufträge</strong> &rarr; <strong>Einsätze &amp; Dispo</strong>
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
