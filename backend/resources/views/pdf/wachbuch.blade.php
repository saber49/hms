<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>{{ $label }} — {{ $objekt->objektname }} — {{ \Carbon\Carbon::parse($datum)->format('d.m.Y') }}</title>
<style>
    @page { margin: 16mm 14mm 18mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2937; }
    .head { width: 100%; border-bottom: 2px solid #1a2744; padding-bottom: 8px; margin-bottom: 12px; }
    .head td { vertical-align: middle; }
    .firma { font-size: 13pt; font-weight: bold; color: #1a2744; }
    .firma-info { font-size: 7.5pt; color: #64748b; margin-top: 2px; }
    .logo { max-height: 48px; max-width: 150px; }
    h1 { font-size: 14pt; margin: 0 0 2px; color: #111827; }
    .sub { font-size: 8.5pt; color: #64748b; margin-bottom: 10px; }
    .meta { width: 100%; margin-bottom: 12px; font-size: 8.8pt; }
    .meta td { padding: 2px 0; vertical-align: top; }
    .meta .lbl { color: #64748b; width: 110px; }
    .blatt { border: 1px solid #cbd5e1; border-radius: 4px; padding: 8px 10px; margin-bottom: 12px; page-break-inside: avoid; }
    .blatt .t { font-weight: bold; color: #1a2744; }
    .blatt .m { font-size: 8pt; color: #64748b; margin-top: 2px; }
    .pill { display: inline-block; font-size: 7pt; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1px 6px; margin-left: 4px; color: #475569; }
    .pill.ok { border-color: #86efac; color: #15803d; }
    .pill.warn { border-color: #fcd34d; color: #b45309; }
    .pill.err { border-color: #fca5a5; color: #b91c1c; }
    .uebergabe { font-size: 8pt; color: #374151; background: #f1f5f9; padding: 4px 6px; border-radius: 3px; margin-top: 4px; }
    table.e { width: 100%; border-collapse: collapse; }
    table.e td { border-bottom: 1px solid #e5e7eb; padding: 5px 4px; vertical-align: top; }
    td.zeit { width: 52px; white-space: nowrap; font-weight: bold; }
    td.zeit .nr { font-weight: normal; color: #94a3b8; font-size: 7.5pt; }
    .art { font-weight: bold; }
    .art.wichtig { color: #b91c1c; }
    .txt { white-space: pre-wrap; margin-top: 1px; }
    .det { font-size: 8pt; color: #64748b; margin-top: 1px; }
    .von { font-size: 7.8pt; color: #94a3b8; margin-top: 2px; }
    .berichtigt { text-decoration: line-through; color: #9ca3af; }
    .berichtigung { border-left: 3px solid #f59e0b; padding-left: 6px; }
    .fotos img { max-width: 165px; max-height: 120px; margin: 4px 6px 0 0; border: 1px solid #e5e7eb; }
    .fotos .cap { font-size: 6.8pt; color: #94a3b8; }
    .leer { color: #64748b; padding: 10px 0; }
    .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
@php
    $fmt = fn ($ts) => $ts ? \Carbon\Carbon::parse($ts)->setTimezone('Europe/Berlin')->format('d.m.Y H:i') : null;
    $fmtT = fn ($ts) => $ts ? \Carbon\Carbon::parse($ts)->setTimezone('Europe/Berlin')->format('H:i') : null;
    $adresse = trim(($objekt->adresse ?? '') . ', ' . trim(($objekt->plz ?? '') . ' ' . ($objekt->stadt ?? '')), ', ');
@endphp
<div class="foot">{{ $firma }} · {{ $label }} {{ $objekt->objektname }} · {{ \Carbon\Carbon::parse($datum)->format('d.m.Y') }} · erstellt am {{ $erstellt }} Uhr</div>

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

<h1>{{ $label }} — {{ $objekt->objektname }}</h1>
<div class="sub">
    {{ \Carbon\Carbon::parse($datum)->locale('de')->isoFormat('dddd, DD.MM.YYYY') }} ({{ $label }}-Tag {{ $tagBeginn }} – {{ $tagBeginn }} Uhr)
    @if($kunde) · Kundenfassung @endif
</div>

<table class="meta" cellspacing="0" cellpadding="0">
    @if($adresse)<tr><td class="lbl">Adresse</td><td>{{ $adresse }}</td></tr>@endif
    <tr><td class="lbl">Kunde</td><td>{{ $objekt->kundenname ?? '–' }}</td></tr>
    @if(!$kunde && ($objekt->ansprechpartner || $objekt->kontakt_telefon))
        <tr><td class="lbl">Ansprechpartner</td><td>{{ trim(($objekt->ansprechpartner ?? '') . ' ' . ($objekt->kontakt_telefon ?? '')) }}</td></tr>
    @endif
    <tr><td class="lbl">Blätter / Einträge</td><td>{{ $blaetter->count() }} Blatt{{ $blaetter->count() === 1 ? '' : 'ter' }} · {{ $eintraege->count() }} Einträge</td></tr>
</table>

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

@if($eintraege->isEmpty())
    <div class="leer">Keine Einträge an diesem Tag.</div>
@else
<table class="e" cellspacing="0" cellpadding="0">
@foreach($eintraege as $e)
    <tr>
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
</body>
</html>
