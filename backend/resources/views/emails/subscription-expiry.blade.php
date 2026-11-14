<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1a1a;margin:0;padding:0;background:#f4f6f8">
<div style="max-width:640px;margin:20px auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,{{ $expired ? '#ef4444,#991b1b' : '#f59e0b,#b45309' }});padding:24px 32px;color:#fff">
        <h1 style="margin:0;font-size:20px;font-weight:700">
            @if($expired)
                &#9888; Ihr Abonnement ist abgelaufen
            @else
                &#9200; Ihr Abonnement läuft bald ab
            @endif
        </h1>
        <p style="margin:6px 0 0;font-size:13px;opacity:0.9">{{ $company }}</p>
    </div>

    {{-- Body --}}
    <div style="padding:28px 32px">
        <p style="margin:0 0 12px">Guten Tag,</p>
        <p style="margin:0 0 16px">
            @if($expired)
                das <strong>{{ $planLabel }}</strong>-Abonnement für <strong>{{ $company }}</strong> ist am
                <strong>{{ $endDate }}</strong> abgelaufen. Der Zugriff auf Ihre Daten ist derzeit gesperrt —
                Ihre Daten bleiben selbstverständlich vollständig erhalten.
            @elseif($daysLeft === 0)
                das <strong>{{ $planLabel }}</strong>-Abonnement für <strong>{{ $company }}</strong> läuft
                <strong>heute</strong> ({{ $endDate }}) ab.
            @elseif($daysLeft === 1)
                das <strong>{{ $planLabel }}</strong>-Abonnement für <strong>{{ $company }}</strong> läuft
                <strong>morgen</strong> ({{ $endDate }}) ab.
            @else
                das <strong>{{ $planLabel }}</strong>-Abonnement für <strong>{{ $company }}</strong> läuft in
                <strong>{{ $daysLeft }} Tagen</strong> ({{ $endDate }}) ab.
            @endif
        </p>

        {{-- Urgency highlight --}}
        @if($expired)
            <div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #ef4444;border-radius:6px;padding:14px 18px;margin:16px 0">
                <p style="margin:0;font-weight:700;color:#991b1b;font-size:13px">&#128274; Zugriff gesperrt seit {{ $endDate }}</p>
                <p style="margin:6px 0 0;color:#991b1b;font-size:13px">Verlängern Sie jetzt, um sofort wieder auf alle Daten und Funktionen zuzugreifen.</p>
            </div>
        @else
            <div style="background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:6px;padding:14px 18px;margin:16px 0">
                <p style="margin:0;font-weight:700;color:#92400e;font-size:13px">&#9888; Ohne Verlängerung wird der Zugriff am {{ $endDate }} gesperrt</p>
                <p style="margin:6px 0 0;color:#92400e;font-size:13px">Ihre Daten bleiben erhalten — aber Dashboard, Disposition und Mitarbeiter-App sind dann nicht mehr erreichbar.</p>
            </div>
        @endif

        {{-- CTA --}}
        <p style="margin:24px 0;text-align:center">
            <a href="mailto:{{ $billingEmail }}?subject=Verl%C3%A4ngerung%20Abonnement%20{{ rawurlencode($company) }}"
               style="display:inline-block;background:linear-gradient(135deg,#0ea5e9,#0369a1);color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;font-size:14px;box-shadow:0 4px 8px rgba(14,165,233,0.25)">
                Jetzt verlängern &rarr;
            </a>
        </p>

        <p style="margin-top:20px;font-size:13px;color:#64748b">
            Fragen zur Abrechnung? Schreiben Sie uns einfach an
            <a href="mailto:{{ $billingEmail }}" style="color:#0369a1">{{ $billingEmail }}</a>.
        </p>
    </div>

    {{-- Footer --}}
    <div style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center">
        Diese E-Mail wurde automatisch von <strong>Clood-x</strong> generiert.<br>
        Bitte antworten Sie nicht direkt auf diese E-Mail.
    </div>
</div>
</body>
</html>
