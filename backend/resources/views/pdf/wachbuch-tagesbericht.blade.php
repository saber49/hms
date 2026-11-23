@php
    $primary = $settings['primary_color'] ?? '#172b42';
    $accent = $settings['accent_color'] ?? '#b45333';
    $font = $settings['font_family'] ?? 'DejaVu Sans, sans-serif';
    $zeit = fn ($v) => $v ? \Carbon\Carbon::parse($v)->setTimezone('Europe/Berlin')->format('d.m.Y H:i') : '–';
    $uhr = fn ($v) => $v ? \Carbon\Carbon::parse($v)->setTimezone('Europe/Berlin')->format('H:i') : '–';
    $zahl = fn ($v) => $v === null ? '–' : ($v >= 0 ? '+' : '') . $v . ' Min.';
    $no = 'TB-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $daten['obj_id']) . '-'
        . str_replace('-', '', $daten['datum']) . ($version ? '-v' . $version->version : '-ENTWURF');
    $voll = $daten['schluessel_vollstaendig'];
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>{{ $label }} · Tagesbericht {{ $no }}</title>
<style>
    @page { margin: 32px 38px 142px 38px; }
    * { box-sizing: border-box; }
    body { font-family: {{ $font }}; font-size: 9px; color: #263443; line-height: 1.4; }
    .head { border-bottom: 3px solid {{ $primary }}; padding-bottom: 10px; margin-bottom: 15px; }
    .logo { max-width: 105px; max-height: 48px; float: right; }
    .firma { color: {{ $primary }}; font-size: 15px; font-weight: bold; }
    .muted { color: #64748b; font-size: 8px; }
    h1 { color: {{ $primary }}; font-size: 19px; margin: 5px 0; }
    h2 { color: {{ $primary }}; font-size: 12px; margin: 20px 0 7px; border-bottom: 1px solid #d7e1e8; padding-bottom: 4px; }
    .pill { display: inline-block; background: #e8eef2; color: {{ $primary }}; padding: 3px 8px; border-radius: 3px; font-weight: bold; }
    .pill.warn { background: #fff1dc; color: #86500a; }
    .meta { width: 100%; border-collapse: collapse; margin: 9px 0 14px; }
    .meta td { vertical-align: top; padding: 3px 10px 3px 0; }
    .meta .label { width: 84px; color: #64748b; }
    .tiles { width: 100%; border-collapse: separate; border-spacing: 5px; margin: 0 -5px; }
    .tiles td { border-top: 2px solid {{ $accent }}; background: #f3f6f7; width: 33%; padding: 7px; vertical-align: top; }
    .tiles strong { display: block; color: {{ $primary }}; font-size: 11px; }
    table.list { width: 100%; border-collapse: collapse; margin: 4px 0 12px; }
    .list th { background: {{ $primary }}; color: #fff; font-weight: bold; padding: 5px; text-align: left; }
    .list td { border-bottom: 1px solid #e3e9ed; padding: 5px; vertical-align: top; }
    .list tr:nth-child(even) td { background: #f7f9fa; }
    .warning { padding: 7px 9px; background: #fff6e8; border-left: 3px solid #d69542; margin: 6px 0; }
    .ok { color: #186a4a; font-weight: bold; }
    .bad { color: #a6402c; font-weight: bold; }
    .photos img { max-width: 150px; max-height: 100px; margin: 3px; }
    .small { font-size: 7.5px; color: #64748b; }
    .footer { position: fixed; bottom: 21px; left: 38px; right: 38px; border-top: 2px solid {{ $primary }};
        padding-top: 7px; font-size: 7px; color: #566575; }
    .checksum { word-break: break-all; font-size: 6.5px; }
    .clear { clear: both; }
</style>
</head>
<body>
<div class="head">
    @if($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
    <div class="firma">{{ $daten['firma'] }}</div>
    <div class="muted">{{ $label }} · Tagesbericht · {{ $no }}</div>
    <div class="clear"></div>
</div>
<h1>{{ $label }} · Tagesbericht</h1>
<span class="pill {{ $version ? '' : 'warn' }}">{{ $version ? 'Freigegeben · Version ' . $version->version : 'ENTWURF · nicht freigegeben' }}</span>
@if($kunde)<span class="pill">Kundenfassung</span>@endif
<table class="meta">
    <tr><td class="label">Objekt</td><td>{{ $daten['objektname'] }} · {{ $daten['adresse'] }}</td><td class="label">Kunde</td><td>{{ $daten['kundenname'] }}</td></tr>
    <tr><td class="label">Zeitraum</td><td>{{ $zeit($daten['von']) }} bis {{ $zeit($daten['bis']) }}</td>
        <td class="label">Bericht-Nr.</td><td>{{ $no }}</td></tr>
    <tr><td class="label">{{ array_key_exists('dienstanweisung', $daten) ? 'Dienstanweisung' : '' }}</td><td>{{ array_key_exists('dienstanweisung', $daten) ? (isset($daten['dienstanweisung']) ? 'Version ' . $daten['dienstanweisung']['version'] : 'nicht nachgewiesen') : '' }}</td>
        <td class="label">Freigabe</td><td>{{ $version ? $zeit($version->freigegeben_am) : 'ausstehend' }}</td></tr>
</table>

<h2>Auf einen Blick</h2>
<table class="tiles"><tr>
    <td>Besetzung<strong>{{ !$daten['durchgehend_bewacht'] ? '24-h-Dienst nicht vereinbart' : ($daten['deckung']['lueckenlos'] ? 'Lückenlos nachgewiesen' : 'Nicht lückenlos nachgewiesen') }}</strong>
        <span class="small">Aus dokumentierten Check-in/Check-out-Zeiten</span></td>
    <td>Wachpersonen<strong>{{ $daten['wachpersonen'] }}</strong>mit Anwesenheitsnachweis</td>
    <td>Besucher<strong>{{ count($daten['besucher']) }}</strong>registrierte Anmeldungen</td>
</tr><tr>
    <td>Lieferungen / Fremdfirmen<strong>{{ count($daten['lieferungen']) }}</strong>dokumentierte Vorgänge</td>
    <td>Schlüsselbestand<strong>{{ $voll ? $daten['schluessel_vorhanden'] . ' / ' . $daten['schluessel_gesamt'] : 'Nicht vollständig nachgewiesen' }}</strong>
        @if($voll)<span class="small">gegen das geprüfte Objektregister</span>@endif</td>
    <td>Vorkommnisse / Alarme<strong>{{ count($daten['vorkommnisse']) }}</strong>im digitalen Wachbuch erfasst</td>
</tr></table>

@if(!$daten['deckung']['lueckenlos'] && $daten['durchgehend_bewacht'])
    <div class="warning">Eine lückenlose Besetzung ist nicht nachgewiesen.
        @foreach($daten['deckung']['luecken'] as $gap) {{ $zeit($gap['von']) }}–{{ $zeit($gap['bis']) }}; @endforeach
    </div>
@endif
@if(!$daten['waechterkontrolle_aktiv'])
    <p class="small">Für dieses Objekt ist keine Wächterkontrolle im System aktiviert. Der Anwesenheitsnachweis beruht auf dokumentierten Zeiterfassungsdaten, nicht auf Kontrollgängen.</p>
@endif

@if(!$kunde)
<h2>Anwesenheit · Plan und Ist</h2>
<table class="list">
    <thead><tr><th>Wachperson</th><th>Plan</th><th>Check-in</th><th>Check-out</th><th>Dauer</th><th>Abweichung</th><th>Dienstanweisung</th></tr></thead>
    <tbody>
    @forelse($daten['anwesenheit'] as $a)
        <tr><td>{{ $a['name'] }}</td><td>{{ $uhr($a['plan_von']) }}–{{ $uhr($a['plan_bis']) }}</td>
            <td>{{ $zeit($a['check_in']) }}</td><td>{{ $zeit($a['check_out']) }}</td>
            <td>{{ $a['dauer_min'] === null ? '–' : floor($a['dauer_min'] / 60) . ':' . sprintf('%02d', $a['dauer_min'] % 60) . ' Std.' }}</td>
            <td>{{ $zahl($a['abweichung_in_min']) }} / {{ $zahl($a['abweichung_out_min']) }}</td>
            <td>{{ !empty($a['da']['bestaetigt']) ? 'V' . $a['da']['version'] . ' bestätigt' : 'nicht nachgewiesen' }}</td></tr>
    @empty
        <tr><td colspan="7">Keine Check-in-Daten für dieses Objekt und diesen Zeitraum.</td></tr>
    @endforelse
    </tbody>
</table>
@endif

<h2>Besucher · {{ count($daten['besucher']) }} Anmeldungen</h2>
<table class="list">
    <thead><tr><th>Zeit</th><th>Name</th><th>Ziel</th><th>Angemeldet</th>@if(!$kunde)<th>Ausweis geprüft</th>@endif<th>Abgemeldet</th></tr></thead>
    <tbody>
    @forelse($daten['besucher'] as $b)
        <tr><td>{{ $zeit($b['zeit']) }}</td><td>{{ $b['name'] ?: '–' }}</td><td>{{ $b['ziel'] ?: '–' }}</td>
            <td>{{ $b['angemeldet'] === true ? 'ja' : 'nicht bestätigt' }}</td>
            @if(!$kunde)<td>{{ $b['ausweis_geprueft'] === true ? 'ja' : 'nicht nachgewiesen' }}</td>@endif
            <td>{{ $zeit($b['gegangen']) }}</td></tr>
    @empty
        <tr><td colspan="{{ $kunde ? 5 : 6 }}">Keine Besucheranmeldung dokumentiert.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Lieferungen und Fremdfirmen</h2>
<table class="list">
    <thead><tr><th>Zeit</th><th>Firma</th><th>Zweck</th>@if(!$kunde)<th>Freigabe erfasst</th>@endif</tr></thead>
    <tbody>
    @forelse($daten['lieferungen'] as $l)
        <tr><td>{{ $zeit($l['zeit']) }}</td><td>{{ $l['firma'] }}</td><td>{{ $l['zweck'] }}</td>
            @if(!$kunde)<td>{{ !empty($l['freigabe_bestaetigt']) ? ($l['freigabe_name'] ?: 'ja') : 'nicht nachgewiesen' }}</td>@endif</tr>
    @empty
        <tr><td colspan="{{ $kunde ? 3 : 4 }}">Keine Lieferung dokumentiert.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Schlüssel · {{ $daten['schluessel_gesamt'] }} registriert</h2>
<table class="list">
    <thead><tr><th>Kennung</th><th>Bezeichnung</th><th>Bestandsprüfung</th><th>Stand zum Tagesende</th></tr></thead>
    <tbody>
    @forelse($daten['schluessel'] as $k)
        <tr><td>{{ $k['kennung'] }}</td><td>{{ $k['bezeichnung'] }}</td>
            <td>{{ $k['bestand_geprueft'] ? 'vor Tagesbeginn geprüft' : 'nicht nachgewiesen' }}</td>
            <td>{{ !$k['bestand_geprueft'] ? 'unbekannt' : ($k['ausgegeben'] ? 'ausgegeben' : 'vorhanden') }}</td></tr>
    @empty
        <tr><td colspan="4">Kein geprüftes Schlüsselregister für dieses Objekt hinterlegt.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Vorkommnisse</h2>
@forelse($daten['vorkommnisse'] as $e)
    <div class="warning"><strong>{{ $zeit($e['zeit']) }} · {{ $e['art_label'] }}</strong> — {{ $e['text'] }}</div>
@empty
    <p>Keine Vorkommnisse dokumentiert. Dies ist kein Nachweis, dass keine Ereignisse stattgefunden haben.</p>
@endforelse

@if(!$kunde)
<h2>Schichtübergaben</h2>
@forelse($daten['uebergaben'] as $e)
    <p>{{ $zeit($e['zeit']) }} · {{ $e['erfasst_von'] }} ·
        {{ ($e['details']['vorgang'] ?? '') === 'uebernommen' ? 'Übernahme' : 'Übergabe' }}
        · Schlüssel {{ !empty($e['details']['schluessel_ok']) ? 'ok' : 'Abweichung' }}
        · Funk {{ !empty($e['details']['funk_ok']) ? 'ok' : 'Abweichung' }}
        · Objekt {{ !empty($e['details']['objekt_ok']) ? 'ok' : 'Abweichung' }}
        @if($e['text']) · {{ $e['text'] }} @endif</p>
@empty
    <p>Keine beidseitig dokumentierte Übernahme-Checkliste.</p>
@endforelse
@endif

<h2>Wachbuch chronologisch</h2>
<table class="list">
    <thead><tr><th>Nr.</th><th>Zeit</th><th>Art</th><th>Eintrag</th><th>Erfasst von</th></tr></thead>
    <tbody>
    @forelse($daten['eintraege'] as $e)
        <tr><td>{{ $e['nr'] }}</td><td>{{ $zeit($e['zeit']) }}</td><td>{{ $e['art_label'] }}</td>
            <td>{{ $e['text'] }} @if($e['ort']) · {{ $e['ort'] }} @endif
                @if(!empty($fotos[$e['id']]))
                    <div class="photos">@foreach($fotos[$e['id']] as $foto)<img src="{{ $foto }}" alt="">@endforeach</div>
                @endif</td>
            <td>{{ $e['erfasst_von'] }}</td></tr>
    @empty
        <tr><td colspan="5">Keine Einträge für diesen Tag vorhanden.</td></tr>
    @endforelse
    </tbody>
</table>
@if($fotosNichtGezeigt)<p class="small">+ {{ $fotosNichtGezeigt }} weitere Fotos im System einsehbar.</p>@endif

@if(!$kunde && !empty($daten['pruefung']))
<h2>Prüfprotokoll · Abweichungen</h2>
@foreach($daten['pruefung'] as $issue)<div class="warning">{{ $issue }}</div>@endforeach
@if(!empty($daten['ausnahme']))<p><strong>Begründung zur Freigabe:</strong> {{ $daten['ausnahme'] }}</p>@endif
@elseif($kunde && !empty($daten['pruefung_hinweis']))
<p class="warning">{{ $daten['pruefung_hinweis'] }}</p>
@endif

@if($version)
<p><strong>Digital freigegeben:</strong> {{ $zeit($version->freigegeben_am) }} · Version {{ $version->version }}.</p>
<p class="small">Prüfsumme des gespeicherten Berichtsdatenstands (SHA-256): <span class="checksum">{{ $version->pruefsumme }}</span></p>
@else
<p class="warning">ENTWURF. Diese Ansicht ist nicht freigegeben und kann sich durch nachgereichte Einträge noch ändern.</p>
@endif
<div class="footer">
    {{ $daten['firma'] }} · {{ $label }} · {{ $daten['objektname'] }} · {{ \Carbon\Carbon::parse($daten['datum'])->format('d.m.Y') }}
    @if($version) · v{{ $version->version }} · Daten-Prüfsumme <span class="checksum">{{ substr($version->pruefsumme, 0, 16) }}</span>@endif
</div>
</body>
</html>
