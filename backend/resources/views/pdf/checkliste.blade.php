<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>{{ $p['vorlage_name'] }} — {{ $p['objektname'] ?? '' }}</title>
<style>
    @page { margin: 18mm 15mm 18mm 15mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2937; }
    .head { width: 100%; border-bottom: 2px solid #1a2744; padding-bottom: 8px; margin-bottom: 14px; }
    .head td { vertical-align: middle; }
    .firma { font-size: 13pt; font-weight: bold; color: #1a2744; }
    .firma-info { font-size: 7.5pt; color: #64748b; margin-top: 2px; }
    .logo { max-height: 48px; max-width: 150px; }
    h1 { font-size: 14pt; margin: 0 0 2px; color: #111827; }
    .kat { font-size: 8pt; color: #1d4ed8; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px; }
    .meta { width: 100%; margin-bottom: 12px; font-size: 8.8pt; }
    .meta td { padding: 2px 0; vertical-align: top; }
    .meta .lbl { color: #64748b; width: 95px; }
    .score { border: 1px solid #d1d5db; border-radius: 4px; padding: 8px 10px; margin-bottom: 12px; width: 100%; }
    .score td { text-align: center; }
    .score .v { font-size: 15pt; font-weight: bold; }
    .score .l { font-size: 7.5pt; color: #64748b; }
    .ok { color: #15803d; } .bad { color: #b91c1c; } .na { color: #64748b; }
    table.f { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    table.f td { border-bottom: 1px solid #e5e7eb; padding: 5px 4px; vertical-align: top; }
    table.f td.lbl { width: 55%; }
    table.f td.val { font-weight: bold; }
    tr.sec td { background: #f1f5f9; font-weight: bold; color: #1a2744; border-bottom: 1px solid #cbd5e1; padding: 6px 4px; }
    tr.mangel td { background: #fef2f2; }
    .notiz { font-weight: normal; color: #374151; font-size: 8.2pt; margin-top: 2px; white-space: pre-wrap; }
    .hint { font-weight: normal; color: #94a3b8; font-size: 7.5pt; }
    .fotos img { max-width: 160px; max-height: 120px; margin: 3px 4px 0 0; border: 1px solid #e5e7eb; }
    .sig { width: 100%; margin-top: 16px; page-break-inside: avoid; }
    .sig td { width: 50%; vertical-align: bottom; padding-right: 14px; }
    .sig img { max-height: 60px; max-width: 220px; }
    .sig .line { border-top: 1px solid #1f2937; padding-top: 3px; font-size: 7.8pt; color: #64748b; }
    .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
@php
    $fmt = fn ($ts) => $ts ? \Carbon\Carbon::parse($ts, 'UTC')->setTimezone('Europe/Berlin')->format('d.m.Y H:i') : null;
@endphp
<div class="foot">{{ $firma }} · {{ $p['vorlage_name'] }} · Protokoll Nr. {{ $p['id'] }} · erstellt am {{ now('Europe/Berlin')->format('d.m.Y H:i') }} Uhr</div>

<table class="head" cellspacing="0" cellpadding="0">
    <tr>
        <td>
            <div class="firma">{{ $firma }}</div>
            @if($firmaInfo)<div class="firma-info">{{ $firmaInfo }}</div>@endif
        </td>
        @if($logo)
            <td style="text-align:right"><img class="logo" src="{{ $logo }}" alt=""></td>
        @endif
    </tr>
</table>

<h1>{{ $p['vorlage_name'] }}</h1>
<div class="kat">{{ $p['kategorie_label'] }}</div>
<table class="meta" cellspacing="0" cellpadding="0">
    <tr><td class="lbl">{{ $termObjekt }}:</td><td><b>{{ $p['objektname'] }}</b>@if($adresse)<br>{{ $adresse }}@endif</td></tr>
    @if(!empty($p['kundenname']))<tr><td class="lbl">{{ $termKunde }}:</td><td>{{ $p['kundenname'] }}</td></tr>@endif
    <tr><td class="lbl">Datum:</td><td>{{ \Carbon\Carbon::parse($p['datum'])->locale('de')->isoFormat('dd, DD.MM.YYYY') }}</td></tr>
    @if(!empty($p['mitarbeiter']))<tr><td class="lbl">Mitarbeiter:</td><td>{{ $p['mitarbeiter'] }}</td></tr>@endif
    <tr><td class="lbl">Erfasst:</td><td>@if($gestartet){{ $fmt($gestartet) }} – @endif{{ $fmt($abgeschlossen) }} Uhr</td></tr>
    @if($gps)<tr><td class="lbl">Standort:</td><td>GPS {{ number_format($gps['lat'], 5, ',', '') }}, {{ number_format($gps['lng'], 5, ',', '') }}@if($gps['genau']) (± {{ $gps['genau'] }} m)@endif</td></tr>@endif
</table>

@if($p['ergebnis_prozent'] !== null || $p['maengel'] > 0)
<table class="score" cellspacing="0" cellpadding="0">
    <tr>
        @if($p['ergebnis_prozent'] !== null)
            <td><div class="v {{ $p['ergebnis_prozent'] >= 90 ? 'ok' : ($p['ergebnis_prozent'] < 70 ? 'bad' : '') }}">{{ number_format($p['ergebnis_prozent'], 0, ',', '.') }} %</div><div class="l">Ergebnis</div></td>
        @endif
        <td><div class="v {{ $p['maengel'] ? 'bad' : 'ok' }}">{{ $p['maengel'] }}</div><div class="l">{{ $p['maengel'] === 1 ? 'Mangel' : 'Mängel' }}</div></td>
    </tr>
</table>
@endif

<table class="f" cellspacing="0" cellpadding="0">
@foreach($felder as $f)
    @if($f['typ'] === 'abschnitt')
        <tr class="sec"><td colspan="2">{{ $f['label'] }}</td></tr>
        @continue
    @endif
    @php
        $a = $antworten[$f['id']] ?? [];
        $w = $a['wert'] ?? null;
        $mangel = ($f['typ'] === 'ok_nok' && $w === 'nio') || ($f['typ'] === 'bewertung' && is_int($w) && $w <= 2);
        switch ($f['typ']) {
            case 'check': $txt = $w ? '✔ erledigt' : '✘ nicht erledigt'; $cls = $w ? 'ok' : 'na'; break;
            case 'ok_nok': $txt = ['io' => 'i. O.', 'nio' => 'n. i. O.', 'na' => 'entfällt'][$w] ?? '–'; $cls = ['io' => 'ok', 'nio' => 'bad', 'na' => 'na'][$w] ?? 'na'; break;
            case 'bewertung': $txt = is_int($w) ? str_repeat('★', $w) . str_repeat('☆', 5 - $w) . "  ($w/5)" : '–'; $cls = $mangel ? 'bad' : ''; break;
            case 'zahl': $txt = $w !== null ? rtrim(rtrim(number_format((float) $w, 3, ',', '.'), '0'), ',') . (!empty($f['einheit']) ? ' ' . $f['einheit'] : '') : '–'; $cls = ''; break;
            case 'zeit': $txt = $w ? $w . ' Uhr' : '–'; $cls = ''; break;
            case 'foto': $txt = empty($fotos[$f['id']]) ? '–' : ''; $cls = ''; break;
            default: $txt = ($w !== null && $w !== '') ? $w : '–'; $cls = '';
        }
    @endphp
    <tr class="{{ $mangel ? 'mangel' : '' }}">
        <td class="lbl">{{ $f['label'] }}@if(!empty($f['hinweis']))<div class="hint">{{ $f['hinweis'] }}</div>@endif</td>
        <td class="val {{ $cls }}">
            {{ $txt }}
            @if(!empty($a['notiz']))<div class="notiz">{{ $a['notiz'] }}</div>@endif
            @if(!empty($fotos[$f['id']]))
                <div class="fotos">@foreach($fotos[$f['id']] as $img)<img src="{{ $img }}" alt="">@endforeach</div>
            @endif
        </td>
    </tr>
@endforeach
</table>

@if($sigKunde || $sigMa)
<table class="sig" cellspacing="0" cellpadding="0">
    <tr>
        <td>@if($sigMa)<img src="{{ $sigMa }}" alt=""><div class="line">Unterschrift Mitarbeiter{{ !empty($p['mitarbeiter']) ? ' · ' . $p['mitarbeiter'] : '' }}</div>@endif</td>
        <td>@if($sigKunde)<img src="{{ $sigKunde }}" alt=""><div class="line">Unterschrift {{ $termKunde }}{{ $sigKundeName ? ' · ' . $sigKundeName : '' }}</div>@endif</td>
    </tr>
</table>
@endif
</body>
</html>
