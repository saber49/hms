@php
    // Resolve template settings with the same defaults as pdf/order-form —
    // the confirmation inherits the tenant's Dokument-Design branding.
    $primary    = $settings['primary_color']   ?? '#1a2744';
    $accent     = $settings['accent_color']    ?? '#3b82f6';
    $fontFamily = $settings['font_family']     ?? 'DejaVu Sans, sans-serif';
    $fontSizeKey= $settings['font_size']       ?? 'medium';
    $layout     = $settings['layout_variant']  ?? 'classic';
    $headerStyle= $settings['header_style']    ?? 'border-bottom-thick';
    $tableStyle = $settings['table_style']     ?? 'striped';
    $bodyFontSize  = ['small' => '10px', 'medium' => '11px', 'large' => '13px'][$fontSizeKey] ?? '11px';
    $tableFontSize = ['small' => '9px',  'medium' => '10px', 'large' => '12px'][$fontSizeKey] ?? '10px';
    $headerBorder = match($headerStyle) {
        'underline-thin' => '1px solid ' . $primary,
        'double-line'    => '3px double ' . $primary,
        'flat'           => '0',
        default          => '3px solid ' . $primary,
    };

    $senderLine = implode(' · ', array_filter([
        $company['name'],
        trim(($company['address'] ?? '') . ', ' . trim(($company['zip'] ?? '') . ' ' . ($company['city'] ?? '')), ', '),
    ]));
    $fmtMoney = fn ($v) => number_format((float) $v, 2, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: {{ $fontFamily }}; font-size: {{ $bodyFontSize }}; color: #1e293b; line-height: 1.4; }
        @page { margin: 30px 0 110px 0; }
        .page { padding: 30px 40px 0 40px; }

        .header { border-bottom: {{ $headerBorder }}; padding-bottom: 14px; margin-bottom: 26px; display: table; width: 100%; }
        .header-left { display: table-cell; vertical-align: middle; }
        .header-right { display: table-cell; vertical-align: middle; text-align: right; width: 130px; }
        .header-right img { max-width: 110px; max-height: 64px; }
        .company-name { font-size: 18px; font-weight: bold; color: {{ $primary }}; }
        .company-info { font-size: 9px; color: #64748b; margin-top: 3px; }

        .sender-line { font-size: 8px; color: #64748b; text-decoration: underline; margin-bottom: 6px; }
        .addr-wrap { display: table; width: 100%; margin-bottom: 26px; }
        .addr-recipient { display: table-cell; width: 55%; vertical-align: top; }
        .addr-sender { display: table-cell; width: 45%; vertical-align: top; font-size: 10px; color: #475569; }
        .recipient-name { font-size: 12px; font-weight: bold; }
        .sender-name { font-weight: bold; color: #1e293b; }

        .doc-title { font-size: 17px; font-weight: bold; color: {{ $primary }}; margin: 8px 0 12px; @if($layout==='elegant') font-variant: small-caps; letter-spacing: 1px; @endif }

        .meta-wrap { display: table; width: 100%; margin-bottom: 18px; }
        .meta-col { display: table-cell; width: 50%; vertical-align: top; }
        .meta-row { display: table; width: 100%; font-size: 10px; margin-bottom: 3px; }
        .meta-label { display: table-cell; width: 110px; color: #64748b; }
        .meta-value { display: table-cell; font-weight: bold; }

        .lieferadresse { font-size: 10px; margin-bottom: 14px; }
        .lieferadresse .lbl { color: #64748b; }
        .intro { font-size: 11px; margin-bottom: 12px; }

        table.positions { width: 100%; border-collapse: collapse; font-size: {{ $tableFontSize }}; }
        table.positions thead { background: {{ $primary }}; @if($layout==='minimal') background: white; @endif }
        table.positions th { padding: 6px 8px; text-align: left; color: white; font-weight: bold; font-size: 9px; @if($layout==='minimal') color: {{ $primary }}; border-bottom: 2px solid {{ $accent }}; @endif }
        table.positions th.num, table.positions td.num { text-align: right; }
        table.positions td { padding: {{ $layout==='compact' ? '3px 6px' : '5px 8px' }}; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        @if($tableStyle==='striped')
        table.positions tr:nth-child(even) { background: #f8fafc; }
        @elseif($tableStyle==='lines-only')
        table.positions td { border-bottom: 1px solid #f1f5f9; }
        @elseif($tableStyle==='bordered')
        table.positions td, table.positions th { border: 1px solid #e2e8f0; }
        @endif
        .pos-name { font-weight: bold; }
        .pos-sub { font-size: 8.5px; color: #64748b; margin-top: 2px; }

        .totals-wrap { margin-top: 10px; }
        .totals { width: 45%; margin-left: 55%; border-collapse: collapse; font-size: 10px; }
        .totals td { padding: 4px 8px; }
        .totals .t-label { color: #475569; }
        .totals .t-value { text-align: right; }
        .totals tr.grand td { border-top: 2px solid {{ $primary }}; font-weight: bold; font-size: 11px; color: {{ $primary }}; }

        .section-note { margin: 18px 0; }
        .section-note-title { background: {{ $primary }}; color: white; padding: 5px 12px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; @if($layout==='minimal') background: white; color: {{ $primary }}; border-bottom: 2px solid {{ $accent }}; @endif }
        .section-note-body { padding: 8px 12px; border: 1px solid #e2e8f0; border-top: none; font-size: 9.5px; color: #334155; white-space: pre-line; @if($layout==='minimal') border: none; padding: 6px 0; @endif }

        .closing { margin-top: 26px; font-size: 11px; }
        .closing .sig { margin-top: 34px; font-weight: bold; }

        /* Three-column legal footer pinned to the bottom of every page —
         * identical contract to pdf/order-form. */
        .footer-legal {
            position: fixed;
            left: 40px; right: 40px; bottom: 8px;
            padding-top: 4px;
            border-top: 4px solid #000;
            font-size: 6px !important; color: #000; line-height: 1.2;
        }
        .footer-legal table,
        .footer-legal td,
        .footer-legal .footer-line { font-size: 6px !important; line-height: 1.2 !important; }
        .footer-legal-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .footer-legal-col { vertical-align: top; padding: 0 12px; width: 33.33%; }
        .footer-legal-col:first-child { padding-left: 0; }
        .footer-legal-col:last-child  { padding-right: 0; }
        .footer-line { padding: 0; margin: 0; word-break: break-word; }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="header-left">
            <div class="company-name">{{ $company['name'] }}</div>
            <div class="company-info">
                {{ implode(' | ', array_filter([
                    $company['address'],
                    trim(($company['zip'] ?? '') . ' ' . ($company['city'] ?? '')),
                    !empty($company['phone']) ? 'Tel: ' . $company['phone'] : '',
                    $company['email'],
                ])) }}
            </div>
        </div>
        @if(!empty($logoDataUri))
        <div class="header-right"><img src="{{ $logoDataUri }}" alt="Logo"></div>
        @endif
    </div>

    <div class="sender-line">{{ $senderLine }}</div>
    <div class="addr-wrap">
        <div class="addr-recipient">
            <div class="recipient-name">{{ $kunde['name'] }}</div>
            @if(!empty($kunde['zusatz']))<div>{{ $kunde['zusatz'] }}</div>@endif
            @if(!empty($kunde['strasse']))<div>{{ $kunde['strasse'] }}</div>@endif
            @if(!empty($kunde['plz_ort']))<div>{{ $kunde['plz_ort'] }}</div>@endif
            @if(!empty($kunde['land']))<div>{{ $kunde['land'] }}</div>@endif
        </div>
        <div class="addr-sender">
            <div class="sender-name">{{ $company['name'] }}</div>
            @if(!empty($company['address']))<div>{{ $company['address'] }}</div>@endif
            @if(trim(($company['zip'] ?? '') . ' ' . ($company['city'] ?? '')) !== '')
                <div>{{ trim(($company['zip'] ?? '') . ' ' . ($company['city'] ?? '')) }}</div>
            @endif
            @if(!empty($company['phone']))<div>{{ $company['phone'] }}</div>@endif
            @if(!empty($company['email']))<div>{{ $company['email'] }}</div>@endif
            @if(!empty($company['website']))<div>{{ $company['website'] }}</div>@endif
        </div>
    </div>

    <div class="doc-title">Auftragsbestätigung</div>

    <div class="meta-wrap">
        <div class="meta-col">
            <div class="meta-row"><div class="meta-label">Auftrags-Nr.:</div><div class="meta-value">{{ $meta['auftrags_nr'] }}</div></div>
            <div class="meta-row"><div class="meta-label">Kunden-Nr.:</div><div class="meta-value">{{ $meta['kunden_nr'] }}</div></div>
            <div class="meta-row"><div class="meta-label">Bestell-Nr.:</div><div class="meta-value">{{ $meta['bestell_nr'] ?? '–' }}</div></div>
            <div class="meta-row"><div class="meta-label">Leistungszeitraum:</div><div class="meta-value">{{ $meta['zeitraum'] }}</div></div>
        </div>
        <div class="meta-col">
            <div class="meta-row"><div class="meta-label">Datum:</div><div class="meta-value">{{ $meta['datum'] }}</div></div>
            @if(!empty($meta['bearbeiter']))
            <div class="meta-row"><div class="meta-label">Bearbeiter:</div><div class="meta-value">{{ $meta['bearbeiter'] }}</div></div>
            @endif
            @if(!empty($meta['telefon']))
            <div class="meta-row"><div class="meta-label">Telefon:</div><div class="meta-value">{{ $meta['telefon'] }}</div></div>
            @endif
            @if(!empty($meta['email']))
            <div class="meta-row"><div class="meta-label">E-Mail:</div><div class="meta-value">{{ $meta['email'] }}</div></div>
            @endif
        </div>
    </div>

    @if(!empty($lieferadresse))
    <div class="lieferadresse"><span class="lbl">Leistungsort:&nbsp;&nbsp;</span><strong>{{ $lieferadresse }}</strong></div>
    @endif

    {{-- Einleitung = Textbaustein of the AB template ($abTexts, OrderConfirmationController::abTextbausteine). --}}
    @if(!isset($abTexts))
    <div class="intro">
        Gerne bestätigen wir hiermit Ihren Auftrag für {{ $meta['zeitraum'] }}{{ !empty($meta['leistungszeit']) ? ' (' . $meta['leistungszeit'] . ')' : '' }} zu folgenden Positionen:
    </div>
    @elseif(!empty($abTexts['intro']))
    <div class="intro">{!! nl2br(e($abTexts['intro'])) !!}</div>
    @endif

    <table class="positions">
        <thead>
            <tr>
                <th style="width:28px">Pos.</th>
                <th>Bezeichnung</th>
                <th class="num" style="width:55px">Menge</th>
                <th style="width:48px">Einheit</th>
            </tr>
        </thead>
        <tbody>
            @foreach($positions as $i => $p)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>
                    <div class="pos-name">{{ $p['name'] }}</div>
                    <div class="pos-sub">
                        {{ $p['modell'] }}
                        @if($p['tage_count'] > 0)
                            · {{ $p['tage_count'] }} {{ $p['tage_count'] === 1 ? 'Einsatztag' : 'Einsatztage' }}
                        @endif
                        @if($p['datum_von'])
                            · {{ \Carbon\Carbon::parse($p['datum_von'])->format('d.m.') }}–{{ \Carbon\Carbon::parse($p['datum_bis'])->format('d.m.Y') }}
                        @endif
                        @if($p['modell'] === 'Pro Stunde' && $p['tage_count'] > 0)
                            · {{ number_format($p['stunden'], 2, ',', '.') }} Std. gesamt
                        @endif
                        @if(!empty($p['bemerkung']))
                            · {{ $p['bemerkung'] }}
                        @endif
                    </div>
                </td>
                <td class="num">{{ number_format($p['menge'], 2, ',', '.') }}</td>
                <td>{{ $p['einheit'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- No prices on the Auftragsbestätigung (user decision 2026-10-19): days + hours only. --}}
    <div class="totals-wrap">
        <table class="totals">
            <tr>
                <td class="t-label">Einsatztage</td>
                <td class="t-value">{{ collect($positions)->sum('tage_count') }}</td>
            </tr>
            <tr class="grand">
                <td class="t-label">Gesamtstunden</td>
                <td class="t-value">{{ number_format(collect($positions)->sum('stunden'), 2, ',', '.') }} Std.</td>
            </tr>
        </table>
    </div>

    @if(!empty($bemerkungen))
    <div class="section-note">
        <div class="section-note-title">Bemerkungen</div>
        <div class="section-note-body">{{ implode("\n\n", $bemerkungen) }}</div>
    </div>
    @endif

    <div class="closing">
        @if(!isset($abTexts))
        <div>Wir bedanken uns herzlich für Ihren Auftrag!</div>
        <div style="margin-top:14px">Mit freundlichen Grüßen</div>
        @elseif(!empty($abTexts['closing']))
        <div>{!! nl2br(e($abTexts['closing'])) !!}</div>
        @endif
        <div class="sig">{{ $company['name'] }}</div>
    </div>
</div>

@php
    // Three-column legal footer (Impressum) — same contract as order-form.
    $col1 = array_filter([
        $company['name'] ?? null,
        !empty($company['geschaeftsfuehrer']) ? 'Geschäftsführer: ' . $company['geschaeftsfuehrer'] : null,
        '',
        trim(($company['address'] ?? '') . (($company['zip'] || $company['city']) ? ', ' . trim(($company['zip'] ?? '') . ' ' . ($company['city'] ?? '')) : '')) ?: null,
        !empty($company['phone']) ? 'Telefon: ' . $company['phone'] : null,
    ], fn($v) => $v !== null);

    $registerLine = null;
    if (!empty($company['registergericht']) && !empty($company['handelsregister_nr'])) {
        $registerLine = $company['registergericht'] . ': ' . $company['handelsregister_nr'];
    } elseif (!empty($company['handelsregister_nr'])) {
        $registerLine = 'HR-Nr.: ' . $company['handelsregister_nr'];
    }

    $col2 = array_filter([
        !empty($company['email'])        ? 'E-Mail: '     . $company['email']        : null,
        !empty($company['website'])      ? 'Internet: '   . $company['website']      : null,
        $registerLine,
        !empty($company['steuernummer']) ? 'Ust.-Nr.: '   . $company['steuernummer'] : null,
        !empty($company['ust_id'])       ? 'Ust.-IdNr.: ' . $company['ust_id']       : null,
    ], fn($v) => $v !== null);

    $col3 = array_filter([
        $company['bank_name'] ?? null,
        !empty($company['iban']) ? 'IBAN: ' . $company['iban'] : null,
        !empty($company['bic'])  ? 'BIC: '  . $company['bic']  : null,
    ], fn($v) => $v !== null);
@endphp
<div class="footer-legal">
    <table class="footer-legal-table">
        <tr>
            <td class="footer-legal-col">
                @foreach($col1 as $line)
                    <div class="footer-line">{!! $line === '' ? '&nbsp;' : e($line) !!}</div>
                @endforeach
            </td>
            <td class="footer-legal-col">
                @foreach($col2 as $line)
                    <div class="footer-line">{{ $line }}</div>
                @endforeach
            </td>
            <td class="footer-legal-col">
                @foreach($col3 as $line)
                    <div class="footer-line">{{ $line }}</div>
                @endforeach
            </td>
        </tr>
    </table>
</div>
</body>
</html>
