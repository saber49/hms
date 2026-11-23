@php
    // Same branding contract as pdf/auftragsbestaetigung — the day report
    // inherits the tenant's Dokument-Design (colours, font, logo position).
    $primary    = $settings['primary_color']   ?? '#1a2744';
    $accent     = $settings['accent_color']    ?? '#3b82f6';
    $fontFamily = $settings['font_family']     ?? 'DejaVu Sans, sans-serif';
    $fontSizeKey= $settings['font_size']       ?? 'medium';
    $headerStyle= $settings['header_style']    ?? 'border-bottom-thick';
    $tableStyle = $settings['table_style']     ?? 'striped';
    $logoPos    = $settings['logo_position']   ?? 'right';
    $bodyFontSize  = ['small' => '9px',  'medium' => '10.5px', 'large' => '12.5px'][$fontSizeKey] ?? '10.5px';
    $tableFontSize = ['small' => '8.5px','medium' => '9.5px',  'large' => '11.5px'][$fontSizeKey] ?? '9.5px';
    $headerBorder = match($headerStyle) {
        'underline-thin' => '1px solid ' . $primary,
        'double-line'    => '3px double ' . $primary,
        'flat'           => '0',
        default          => '3px solid ' . $primary,
    };
    $fmt  = fn ($ts) => $ts ? \Carbon\Carbon::parse($ts)->setTimezone('Europe/Berlin')->format('d.m.Y H:i') : null;
    $fmtT = fn ($ts) => $ts ? \Carbon\Carbon::parse($ts)->setTimezone('Europe/Berlin')->format('H:i') : null;
    $adresse = trim(($objekt->adresse ?? '') . ', ' . trim(($objekt->plz ?? '') . ' ' . ($objekt->stadt ?? '')), ', ');
    $firmaInfo = trim(implode(' · ', array_filter([
        trim(($company['address'] ?? '') . ', ' . trim(($company['zip'] ?? '') . ' ' . ($company['city'] ?? '')), ', '),
        !empty($company['phone']) ? 'Tel: ' . $company['phone'] : '',
        $company['email'] ?? '',
    ])));
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>{{ $label }} — {{ $objekt->objektname }} — {{ \Carbon\Carbon::parse($datum)->format('d.m.Y') }}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    @page { margin: 30px 0 105px 0; }
    body { font-family: {{ $fontFamily }}; font-size: {{ $bodyFontSize }}; color: #1e293b; line-height: 1.4; }
    .page { padding: 26px 40px 0 40px; }

    .header { border-bottom: {{ $headerBorder }}; padding-bottom: 12px; margin-bottom: 22px; display: table; width: 100%; }
    .header-left { display: table-cell; vertical-align: middle; }
    .header-right { display: table-cell; vertical-align: middle; text-align: right; width: 130px; }
    .header-right img, .header-center img { max-width: 115px; max-height: 64px; }
    .header-center { text-align: center; margin-bottom: 10px; }
    .company-name { font-size: 17px; font-weight: bold; color: {{ $primary }}; }
    .company-info { font-size: 8.5px; color: #64748b; margin-top: 3px; }

    .doc-title { font-size: 16px; font-weight: bold; color: {{ $primary }}; margin: 4px 0 3px; }
    .doc-sub { font-size: 9px; color: #64748b; margin-bottom: 14px; }
    .doc-sub .kunde-fassung { color: {{ $accent }}; font-weight: bold; }

    .meta-wrap { width: 100%; margin-bottom: 16px; }
    .meta-row { display: table; width: 100%; font-size: 9.5px; margin-bottom: 3px; }
    .meta-label { display: table-cell; width: 130px; color: #64748b; }
    .meta-value { display: table-cell; font-weight: bold; }

    .blatt { border: 1px solid #e2e8f0; border-left: 3px solid {{ $primary }}; border-radius: 3px; padding: 7px 10px; margin-bottom: 10px; page-break-inside: avoid; background: #f8fafc; }
    .blatt .t { font-weight: bold; color: {{ $primary }}; font-size: 10px; }
    .blatt .m { font-size: 8px; color: #64748b; margin-top: 2px; }
    .pill { display: inline-block; font-size: 7px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1px 6px; margin-left: 4px; color: #475569; }
    .pill.ok { border-color: #86efac; color: #15803d; }
    .pill.warn { border-color: #fcd34d; color: #b45309; }
    .pill.err { border-color: #fca5a5; color: #b91c1c; }
    .uebergabe { font-size: 8.5px; color: #374151; background: #fff; padding: 4px 6px; border-radius: 3px; margin-top: 4px; border: 1px solid #e2e8f0; }

    table.e { width: 100%; border-collapse: collapse; font-size: {{ $tableFontSize }}; }
    table.e td { border-bottom: 1px solid #e2e8f0; padding: 5px 6px; vertical-align: top; }
    table.e tr.stripe td { background: #f8fafc; }
    td.zeit { width: 56px; white-space: nowrap; font-weight: bold; color: {{ $primary }}; }
    td.zeit .nr { font-weight: normal; color: #94a3b8; font-size: 7.5px; }
    .art { font-weight: bold; }
    .art.wichtig { color: #b91c1c; }
    .txt { white-space: pre-wrap; margin-top: 1px; }
    .det { font-size: 8.5px; color: #64748b; margin-top: 1px; }
    .von { font-size: 7.8px; color: #94a3b8; margin-top: 2px; }
    .berichtigt { text-decoration: line-through; color: #9ca3af; }
    .berichtigung { border-left: 3px solid {{ $accent }}; padding-left: 6px; }
    .fotos img { max-width: 165px; max-height: 120px; margin: 5px 6px 0 0; border: 1px solid #e2e8f0; }
    .fotos .cap { font-size: 6.8px; color: #94a3b8; }
    .leer { color: #64748b; padding: 10px 0; }

    /* Dompdf positions fixed elements relative to the content box. Keep both
       footers in the reserved @page bottom margin, below the entry table. */
    .footer-doc { position: fixed; left: 40px; right: 40px; bottom: -14px; font-size: 7px; color: #94a3b8; text-align: center; }
    .footer-legal { position: fixed; left: 40px; right: 40px; bottom: -97px; padding-top: 4px; border-top: 4px solid #000; font-size: 6px !important; color: #000; line-height: 1.2; }
    .footer-legal table, .footer-legal td, .footer-legal .footer-line { font-size: 6px !important; line-height: 1.2 !important; }
    .footer-legal-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .footer-legal-col { vertical-align: top; padding: 0 12px; width: 33.33%; }
    .footer-legal-col:first-child { padding-left: 0; }
    .footer-legal-col:last-child { padding-right: 0; }
    .footer-line { padding: 0; margin: 0; word-break: break-word; }
</style>
</head>
<body>
<div class="page">

@if($logo && $logoPos === 'center')
    <div class="header-center"><img src="{{ $logo }}" alt=""></div>
@endif
<div class="header">
    @if($logo && $logoPos === 'left')
        <div class="header-right" style="text-align:left"><img src="{{ $logo }}" alt=""></div>
    @endif
    <div class="header-left" @if($logoPos === 'center') style="text-align:center" @endif>
        <div class="company-name">{{ $company['name'] }}</div>
        @if($firmaInfo)<div class="company-info">{{ $firmaInfo }}</div>@endif
    </div>
    @if($logo && $logoPos !== 'left' && $logoPos !== 'center')
        <div class="header-right"><img src="{{ $logo }}" alt=""></div>
    @endif
</div>

<div class="doc-title">{{ $label }} — {{ $objekt->objektname }}</div>
<div class="doc-sub">
    {{ \Carbon\Carbon::parse($datum)->locale('de')->isoFormat('dddd, DD.MM.YYYY') }} · {{ $label }}-Tag {{ $tagBeginn }} – {{ $tagBeginn }} Uhr
    @if($kunde) · <span class="kunde-fassung">Kundenfassung</span>@endif
</div>

<div class="meta-wrap">
    @if($adresse)<div class="meta-row"><div class="meta-label">Adresse</div><div class="meta-value">{{ $adresse }}</div></div>@endif
    <div class="meta-row"><div class="meta-label">Kunde</div><div class="meta-value">{{ $objekt->kundenname ?? '–' }}</div></div>
    @if(!$kunde && ($objekt->ansprechpartner || $objekt->kontakt_telefon))
        <div class="meta-row"><div class="meta-label">Ansprechpartner</div><div class="meta-value">{{ trim(($objekt->ansprechpartner ?? '') . ' ' . ($objekt->kontakt_telefon ?? '')) }}</div></div>
    @endif
    <div class="meta-row"><div class="meta-label">{{ $kunde ? 'Einträge' : 'Blätter / Einträge' }}</div><div class="meta-value">@if(!$kunde){{ $blaetter->count() }} {{ $blaetter->count() === 1 ? 'Blatt' : 'Blätter' }} · @endif{{ $eintraege->count() }} Einträge</div></div>
</div>

@if(!$kunde)
@foreach($blaetter as $b)
    <div class="blatt">
        <span class="t">{{ $b->name }}</span>
        @if($b->plan)<span class="pill">Plan {{ $b->plan }}</span>@endif
        <span class="pill {{ $b->kette_ok ? 'ok' : 'err' }}">Prüfkette {{ $b->kette_ok ? 'ok' : 'VERLETZT' }}</span>
        @if($b->gegengezeichnet_am)
            <span class="pill ok">gegengezeichnet{{ $b->gegengezeichnet_von ? ' · ' . $b->gegengezeichnet_von : '' }} · {{ $fmt($b->gegengezeichnet_am) }}</span>
        @elseif($b->geschlossen_am)
            <span class="pill">abgeschlossen</span>
        @else
            <span class="pill warn">läuft</span>
        @endif
        <div class="m">
            @if($b->geoeffnet_am)geöffnet {{ $fmt($b->geoeffnet_am) }}@endif
            @if($b->geschlossen_am) · geschlossen {{ $fmt($b->geschlossen_am) }}@endif
        </div>
        @if($b->hinweis_nachfolger)
            <div class="uebergabe"><strong>Übergabe an Folgeschicht:</strong> {{ $b->hinweis_nachfolger }}</div>
        @endif
    </div>
@endforeach
@endif

@if($eintraege->isEmpty())
    <div class="leer">Keine Einträge an diesem Tag.</div>
@else
<table class="e" cellspacing="0" cellpadding="0">
@foreach($eintraege as $e)
    <tr{{ $tableStyle === 'striped' && $loop->even ? ' class="stripe"' : '' }}>
        <td class="zeit">{{ $fmtT($e['zeit']) }}<div class="nr">#{{ $e['nr'] }}</div></td>
        <td>
            <div class="{{ $e['berichtigt_durch'] ? 'berichtigt' : '' }} {{ $e['art'] === 'berichtigung' ? 'berichtigung' : '' }}">
                <span class="art {{ $e['wichtig'] ? 'wichtig' : '' }}">{{ $e['art_label'] }}</span>
                @if($e['ort']) <span class="det">· {{ $e['ort'] }}</span>@endif
                @if($e['art'] === 'berichtigung')<span class="pill warn">Berichtigung{{ $e['ref_nr'] ? ' zu #' . $e['ref_nr'] : '' }}</span>@endif
                @if($e['nachgereicht'])<span class="pill warn">nachgereicht</span>@endif
                @if($e['status'] === 'offen')<span class="pill err">offen</span>@endif
                @if($e['status'] === 'erledigt')<span class="pill ok">erledigt</span>@endif
                @if($e['text'])<div class="txt">{{ $e['text'] }}</div>@endif
                @if($e['details_text'])<div class="det">{{ $e['details_text'] }}</div>@endif
            </div>
            <div class="von">{{ $e['blatt_name'] }}{{ $e['erfasst_von'] && $e['erfasst_von'] !== $e['blatt_name'] ? ' · ' . $e['erfasst_von'] : '' }}{{ $e['quelle'] === 'kunde' ? ' · Kundenportal' : '' }}</div>
            @if(!empty($fotos[$e['id']]))
                <div class="fotos">
                    @foreach($fotos[$e['id']] as $f)
                        <span style="display:inline-block; vertical-align:top">
                            <img src="{{ $f['uri'] }}" alt=""><div class="cap">{{ $f['cap'] }}</div>
                        </span>
                    @endforeach
                </div>
            @endif
        </td>
    </tr>
@endforeach
</table>
@endif

@if($fotosNichtGezeigt > 0)
    <div class="leer">+ {{ $fotosNichtGezeigt }} weitere Foto(s) — vollständig im System{{ $kunde ? '/Kundenportal' : '' }} einsehbar.</div>
@endif
</div>

<div class="footer-doc">{{ $company['name'] }} · {{ $label }} {{ $objekt->objektname }} · {{ \Carbon\Carbon::parse($datum)->format('d.m.Y') }} · erstellt am {{ $erstellt }} Uhr</div>
@php
    // Three-column legal footer (Impressum) — same contract as order-form.
    $col1 = array_filter([
        $company['name'] ?? null,
        !empty($company['geschaeftsfuehrer']) ? 'Geschäftsführer: ' . $company['geschaeftsfuehrer'] : null,
        '',
        trim(($company['address'] ?? '') . (($company['zip'] || $company['city']) ? ', ' . trim(($company['zip'] ?? '') . ' ' . ($company['city'] ?? '')) : '')) ?: null,
        !empty($company['phone']) ? 'Telefon: ' . $company['phone'] : null,
    ], fn ($v) => $v !== null);
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
    ], fn ($v) => $v !== null);
    $col3 = array_filter([
        $company['bank_name'] ?? null,
        !empty($company['iban']) ? 'IBAN: ' . $company['iban'] : null,
        !empty($company['bic'])  ? 'BIC: '  . $company['bic']  : null,
    ], fn ($v) => $v !== null);
@endphp
<div class="footer-legal">
    <table class="footer-legal-table">
        <tr>
            <td class="footer-legal-col">@foreach($col1 as $line)<div class="footer-line">{!! $line === '' ? '&nbsp;' : e($line) !!}</div>@endforeach</td>
            <td class="footer-legal-col">@foreach($col2 as $line)<div class="footer-line">{{ $line }}</div>@endforeach</td>
            <td class="footer-legal-col">@foreach($col3 as $line)<div class="footer-line">{{ $line }}</div>@endforeach</td>
        </tr>
    </table>
</div>
</body>
</html>
