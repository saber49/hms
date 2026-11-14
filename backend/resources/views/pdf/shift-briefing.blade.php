<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; }
    .header { border-bottom: 3px solid #1d4ed8; padding-bottom: 10px; margin-bottom: 14px; }
    .header h1 { font-size: 15pt; color: #1d4ed8; }
    .header .sub { font-size: 8pt; color: #6b7280; margin-top: 3px; }
    .badge { display: inline-block; background: #1d4ed8; color: #fff; font-size: 8pt; padding: 2px 8px; border-radius: 8px; }
    table.info { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    table.info td { padding: 5px 8px; border: 1px solid #e5e7eb; vertical-align: top; }
    table.info td.lbl { width: 22%; background: #f3f4f6; font-weight: bold; color: #374151; font-size: 8.5pt; }
    .section { margin-top: 12px; }
    .section-title { font-size: 10.5pt; font-weight: bold; color: #1d4ed8; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; margin-bottom: 6px; }
    .note-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; padding: 7px 9px; margin-bottom: 6px; }
    .note-box .cap { font-weight: bold; font-size: 8.5pt; color: #166534; }
    table.team { width: 100%; border-collapse: collapse; }
    table.team th { background: #1d4ed8; color: #fff; font-size: 8pt; text-align: left; padding: 5px 6px; }
    table.team td { border: 1px solid #e5e7eb; padding: 5px 6px; font-size: 8.5pt; }
    table.team tr:nth-child(even) td { background: #f9fafb; }
    .chk { display: inline-block; width: 11px; height: 11px; border: 1.4px solid #6b7280; border-radius: 2px; }
    .anf-table th { background: #4f46e5; }
    .muted { color: #9ca3af; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 7.5pt; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 4px; }
    .sig { margin-top: 34px; width: 100%; }
    .sig td { width: 50%; padding: 0 18px; }
    .sig .line { border-top: 1px solid #6b7280; padding-top: 3px; font-size: 8pt; color: #6b7280; }
</style>
</head>
<body>

<div class="header">
    <table style="width:100%"><tr>
        <td>
            <h1>Einsatz-Briefing</h1>
            <div class="sub">Bestellung {{ $task->bestell_id }} · Einsatz {{ $task->einsatz_id }} · erstellt am {{ $generatedAt }}</div>
        </td>
        <td style="text-align:right; vertical-align:top">
            <span class="badge">{{ strtoupper($task->pruefung ?: ($task->hms_bestaetigt === 'ja' ? 'BESTÄTIGT' : 'AUSSTEHEND')) }}</span>
        </td>
    </tr></table>
</div>

<table class="info">
    <tr>
        <td class="lbl">Datum</td>
        <td>{{ $datumFmt }}</td>
        <td class="lbl">Zeit</td>
        <td>{{ $zeitVon }} – {{ $zeitBis }} Uhr @if($task->pause_minuten) · Pause {{ (int) $task->pause_minuten }} Min @endif</td>
    </tr>
    <tr>
        <td class="lbl">Kunde</td>
        <td>{{ $task->kundenname ?: $task->kunden_id }}</td>
        <td class="lbl">Objekt / Veranstaltung</td>
        <td>{{ $task->objektname ?: ($task->obj_id ?: '–') }}</td>
    </tr>
    <tr>
        <td class="lbl">Ort / Treffpunkt</td>
        <td>{{ $task->ort ?: '–' }}</td>
        <td class="lbl">Benötigte Mitarbeiter</td>
        <td>{{ $sollMenge ? (int) $sollMenge : '–' }}</td>
    </tr>
    @if(!empty($task->nu_name))
    <tr>
        <td class="lbl">Nachunternehmer</td>
        <td colspan="3">{{ $task->nu_name }}</td>
    </tr>
    @endif
</table>

@if(!empty($task->beschreibung))
<div class="note-box"><span class="cap">Schichtnotiz:</span> {{ $task->beschreibung }}</div>
@endif
@if(!empty($task->bemerkung))
<div class="note-box" style="background:#fffbeb;border-color:#fde68a"><span class="cap" style="color:#92400e">Bemerkung:</span> {{ $task->bemerkung }}</div>
@endif

@php
    $anf = is_array($task->anforderungen ?? null) ? $task->anforderungen : [];
    $mix = $anf['geschlecht_mix'] ?? null;
    $mixM = is_array($mix) ? (int) ($mix['maennlich'] ?? $mix['m'] ?? 0) : 0;
    $mixW = is_array($mix) ? (int) ($mix['weiblich'] ?? $mix['w'] ?? 0) : 0;
    $positionen = is_array($anf['positionen'] ?? null) ? $anf['positionen'] : [];
    $hasGeneral = !empty($anf['qualifikation']) || !empty($anf['kleidung']) || !empty($anf['skill']) || !empty($anf['geschlecht']) || $mixM || $mixW;
@endphp
@if($hasGeneral || count($positionen))
<div class="section">
    <div class="section-title">Anforderungen</div>
    @if($hasGeneral)
    <table class="info">
        <tr>
            <td class="lbl">Qualifikation</td>
            <td>{{ $anf['qualifikation'] ?? '–' }}</td>
            <td class="lbl">Kleidung</td>
            <td>{{ $anf['kleidung'] ?? '–' }}</td>
        </tr>
        <tr>
            <td class="lbl">Skill</td>
            <td>{{ $anf['skill'] ?? '–' }}</td>
            <td class="lbl">Geschlecht</td>
            <td>
                @if($mixM || $mixW) {{ $mixM }} männlich · {{ $mixW }} weiblich
                @else {{ $anf['geschlecht'] ?? '–' }}
                @endif
            </td>
        </tr>
    </table>
    @endif
    @if(count($positionen))
    <table class="team anf-table">
        <thead><tr><th style="width:8%">Anz.</th><th>Funktion</th><th>Geschlecht</th><th>Qualifikation</th><th>Kleidung</th></tr></thead>
        <tbody>
        @foreach($positionen as $p)
            <tr>
                <td>{{ $p['anzahl'] ?? '–' }}</td>
                <td>{{ $p['funktion'] ?? '–' }}</td>
                <td>{{ $p['geschlecht'] ?? '–' }}</td>
                <td>{{ $p['qualifikation'] ?? '–' }}</td>
                <td>{{ $p['kleidung'] ?? '–' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>
@endif

<div class="section">
    <div class="section-title">Eingeteilte Mitarbeiter ({{ count($workers) }}@if($sollMenge) / {{ (int) $sollMenge }}@endif)</div>
    @if(count($workers))
    <table class="team">
        <thead><tr>
            <th style="width:5%">✓</th>
            <th style="width:9%">Nr.</th>
            <th>Name</th>
            <th style="width:9%">Geschl.</th>
            <th style="width:15%">Qualifikation</th>
            <th style="width:15%">Telefon</th>
            <th>Aufgabe</th>
        </tr></thead>
        <tbody>
        @foreach($workers as $w)
            <tr>
                <td style="text-align:center"><span class="chk"></span></td>
                <td>{{ $w->dienst_nr }}</td>
                <td><strong>{{ trim(($w->vorname ?? '') . ' ' . ($w->name ?? '')) }}</strong>@if(!empty($w->funkrufname)) <span class="muted">({{ $w->funkrufname }})</span>@endif</td>
                <td>{{ $w->geschlecht_label }}</td>
                <td>@if(!empty($w->stadion_gesperrt))<strong style="color:#dc2626">GESPERRT</strong>@if($w->quali_label) · @endif @endif{{ $w->quali_label ?: (empty($w->stadion_gesperrt) ? '–' : '') }}</td>
                <td>{{ $w->telefon_label ?: '–' }}</td>
                <td>{{ $w->aufgabe ?? '–' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @else
    <div class="muted">Noch keine Mitarbeiter zugewiesen.</div>
    @endif
</div>

@if(count($nuWorkers))
<div class="section">
    <div class="section-title">Nachunternehmer-Mitarbeiter ({{ count($nuWorkers) }})</div>
    <table class="team">
        <thead><tr>
            <th style="width:5%">✓</th>
            <th>Name</th>
            <th style="width:12%">Personal-Nr.</th>
            <th>Firma</th>
            <th>Position</th>
        </tr></thead>
        <tbody>
        @foreach($nuWorkers as $nw)
            <tr>
                <td style="text-align:center"><span class="chk"></span></td>
                <td><strong>{{ trim(($nw->vorname ?? '') . ' ' . ($nw->name ?? '')) }}</strong></td>
                <td>{{ $nw->personal_nr ?: '–' }}</td>
                <td>{{ $nw->nu_firmenname ?: '–' }}</td>
                <td>{{ $nw->position_name ?: '–' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

<table class="sig"><tr>
    <td><div class="line">Teamleitung (Name, Unterschrift)</div></td>
    <td><div class="line">Datum, Uhrzeit</div></td>
</tr></table>

<div class="footer">Einsatz-Briefing {{ $task->bestell_id }} · vertraulich – nur für den internen Gebrauch</div>

</body>
</html>
