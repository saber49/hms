@php
    // Resolve template settings with safe defaults
    $primary    = $settings['primary_color']   ?? '#1a2744';
    $accent     = $settings['accent_color']    ?? '#3b82f6';
    $fontFamily = $settings['font_family']     ?? 'DejaVu Sans, sans-serif';
    $fontSizeKey= $settings['font_size']       ?? 'medium';
    $layout     = $settings['layout_variant']  ?? 'classic';
    $headerStyle= $settings['header_style']    ?? 'border-bottom-thick';
    $tableStyle = $settings['table_style']     ?? 'striped';
    $bodyFontSize = ['small' => '10px', 'medium' => '11px', 'large' => '13px'][$fontSizeKey] ?? '11px';
    $tableFontSize = ['small' => '9px',  'medium' => '10px', 'large' => '12px'][$fontSizeKey] ?? '10px';
    $headerBorder = match($headerStyle) {
        'underline-thin' => '1px solid ' . $primary,
        'double-line'    => '3px double ' . $primary,
        'flat'           => '0',
        default          => '3px solid ' . $primary,
    };
    $anonymize  = $anonymize_workers ?? false;
    $showZusch  = $settings['show_zuschlaege'] ?? true;
    $hideExtra  = $settings['hide_extras']     ?? [];

    $parts = array_filter([
        $company['address'] ?? '',
        trim(($company['zip'] ?? '') . ' ' . ($company['city'] ?? '')),
        !empty($company['phone']) ? 'Tel: ' . $company['phone'] : '',
        $company['email'] ?? '',
    ]);
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: {{ $fontFamily }}; font-size: {{ $bodyFontSize }}; color: #1e293b; line-height: 1.4; }
        /* Reserve room at the bottom of every page for the fixed legal
         * footer so the flowing content (tables, signature block, …)
         * never collides with it. The footer itself is positioned with
         * position:fixed which DomPDF supports for page-level elements. */
        @page { margin: 30px 0 110px 0; }
        .page { padding: 30px 40px 0 40px; }
        .header { border-bottom: {{ $headerBorder }}; padding-bottom: 14px; margin-bottom: 20px; display: table; width: 100%; }
        .header-left { display: table-cell; vertical-align: middle; }
        .header-right { display: table-cell; vertical-align: middle; text-align: right; width: 120px; }
        .header-right img { max-width: 100px; max-height: 60px; }
        .company-name { font-size: 18px; font-weight: bold; color: {{ $primary }}; }
        .company-info { font-size: 9px; color: #64748b; margin-top: 3px; }
        .doc-title { font-size: 15px; font-weight: bold; margin: 16px 0 12px; color: {{ $primary }}; @if($layout==='elegant') font-variant: small-caps; letter-spacing: 1px; @endif }
        .meta-row { margin-bottom: 6px; font-size: 10px; display: table; width: 100%; }
        .meta-left { display: table-cell; }
        .meta-right { display: table-cell; text-align: right; }
        .meta-label { color: #64748b; font-weight: bold; }
        .section { margin: 18px 0; }
        .section-title { background: {{ $primary }}; color: white; padding: 6px 12px; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; @if($layout==='minimal') background: white; color: {{ $primary }}; border-bottom: 2px solid {{ $accent }}; @endif }
        .section-body { padding: 10px 12px; border: 1px solid #e2e8f0; border-top: none; @if($layout==='minimal') border: none; padding: 8px 0; @endif }
        .detail-row { display: table; width: 100%; padding: 4px 0; border-bottom: 1px solid #f1f5f9; }
        .detail-label { display: table-cell; width: 120px; font-weight: bold; color: #64748b; font-size: 10px; }
        .detail-value { display: table-cell; font-size: 11px; }
        .highlight { background: #fef9c3; padding: 1px 6px; }
        table { width: 100%; border-collapse: collapse; font-size: {{ $tableFontSize }}; margin-top: 0; }
        thead { background: {{ $primary }}; @if($layout==='minimal') background: white; @endif }
        th { padding: 6px 8px; text-align: left; color: white; font-weight: bold; font-size: 9px; @if($layout==='minimal') color: {{ $primary }}; border-bottom: 2px solid {{ $accent }}; @endif }
        td { padding: {{ $layout==='compact' ? '3px 6px' : '5px 8px' }}; border-bottom: 1px solid #e2e8f0; }
        @if($tableStyle==='striped')
        tr:nth-child(even) { background: #f8fafc; }
        @elseif($tableStyle==='lines-only')
        td { border-bottom: 1px solid #f1f5f9; }
        @elseif($tableStyle==='bordered')
        td, th { border: 1px solid #e2e8f0; }
        @endif
        tfoot td { font-weight: bold; border-top: 2px solid {{ $primary }}; background: white; }
        .badge { display: inline-block; padding: 1px 5px; border-radius: 8px; font-size: 8px; font-weight: bold; }
        .badge-feiertag { background: #fef3c7; color: #b45309; }
        .badge-sonntag  { background: #dbeafe; color: #1e40af; }
        .badge-nacht    { background: #ede9fe; color: #6d28d9; }
        .badge-tag      { background: #f1f5f9; color: #475569; }
        .summary-grid { display: table; width: 100%; margin-top: 14px; }
        .summary-box { display: table-cell; width: 25%; text-align: center; padding: 6px 6px; border: 1px solid #e2e8f0; }
        .summary-label { font-size: 7px; color: #64748b; font-weight: bold; text-transform: uppercase; }
        .summary-value { font-size: 12px; font-weight: bold; color: {{ $primary }}; margin-top: 3px; }
        .summary-grid-std { margin-top: 6px; }
        .summary-box.sb6 { width: 16.666%; padding: 5px 3px; }
        .summary-box.sb6 .summary-label { font-size: 6.5px; }
        .summary-box.sb6 .summary-value { font-size: 11px; }
        .signature-section { display: table; width: 100%; margin-top: 40px; }
        .signature-block { display: table-cell; width: 50%; text-align: center; padding: 0 30px; }
        .signature-line { border-top: 1px solid {{ $primary }}; margin-top: 50px; padding-top: 6px; font-size: 9px; color: #64748b; }
        .footer-note { margin-top: 30px; padding-top: 10px; border-top: 1px solid #e2e8f0; font-size: 8px; color: #94a3b8; text-align: center; }
        /* Three-column legal footer (Impressum) — pinned to the bottom of
         * EVERY page via position:fixed. A thick top border separates it
         * from the flowing content above (matches the printed-letter
         * standard used by HMS Gruppe). All values are sourced from the
         * tenants table — nothing is hard-coded. */
        .footer-legal {
            position: fixed;
            left: 40px; right: 40px; bottom: 8px;
            padding-top: 4px;
            border-top: 4px solid #000;
            /* Compact Impressum — kept deliberately tiny so the three
             * legal columns sit comfortably at the bottom of every page
             * without competing with the order content above. Mirrors
             * assets/css/order_form.css `.footer-legal`. */
            font-size: 6px !important; color: #000; line-height: 1.2;
        }
        /* Explicit child overrides — the global `table { font-size: … }`
         * declaration above wins on specificity and would otherwise blow
         * the legal text back up to ~10px (DomPDF respects specificity
         * the same way browsers do). */
        .footer-legal table,
        .footer-legal td,
        .footer-legal .footer-line { font-size: 6px !important; line-height: 1.2 !important; }
        .footer-legal-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .footer-legal-col { vertical-align: top; padding: 0 12px; width: 33.33%; }
        .footer-legal-col:first-child { padding-left: 0; }
        .footer-legal-col:last-child  { padding-right: 0; }
        .footer-line { padding: 0; margin: 0; word-break: break-word; }
        .anon-notice { background: #fef3c7; color: #78350f; padding: 4px 8px; font-size: 9px; border-radius: 3px; margin-top: 8px; display: inline-block; }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="header-left">
            <div class="company-name">{{ $company['name'] }}</div>
            <div class="company-info">{{ implode(' | ', $parts) }}</div>
        </div>
        @if(!empty($logoDataUri))
        <div class="header-right"><img src="{{ $logoDataUri }}" alt="Logo"></div>
        @endif
    </div>

    <div class="doc-title">LEISTUNGSNACHWEIS / EINSATZPLAN</div>

    <div class="meta-row">
        <div class="meta-left"><span class="meta-label">AN:</span> {{ $order->kundenname ?? $order->kunden_id }}</div>
    </div>
    <div class="meta-row">
        <div class="meta-left"><span class="meta-label">AUFTRAGSDATUM:</span> {{ now()->format('d.m.Y') }}</div>
        <div class="meta-right"><span class="meta-label">AUFTRAGS-NR:</span> {{ $order->bestell_id }}</div>
    </div>

    @if(!in_array('details', $hideExtra))
    <div class="section">
        <div class="section-title">AUFTRAGSDETAILS</div>
        <div class="section-body">
            <div class="detail-row"><div class="detail-label">KUNDE:</div><div class="detail-value">{{ $order->kundenname ?? $order->kunden_id }}</div></div>
            <div class="detail-row"><div class="detail-label">OBJEKT:</div><div class="detail-value">{{ $order->objektname ?? $order->obj_id }}</div></div>
            <div class="detail-row"><div class="detail-label">MONAT:</div><div class="detail-value highlight">
                @php $months = ['', 'Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember']; @endphp
                {{ $months[$order->monat] ?? '' }} {{ $order->jahr ?? '' }}
            </div></div>
            <div class="detail-row"><div class="detail-label">ARBEITSZEIT:</div><div class="detail-value highlight">{{ $order->zeit_von ? substr($order->zeit_von,0,5) : '–' }} – {{ $order->zeit_bis ? substr($order->zeit_bis,0,5) : '–' }}</div></div>
            @if(!in_array('nu', $hideExtra))
            <div class="detail-row"><div class="detail-label">NU:</div><div class="detail-value">{{ $order->nu_name ?? $order->nu_id ?? '–' }}</div></div>
            @endif
        </div>
    </div>
    @endif

    @php
        $rowFn = function($w) use ($anonymize) {
            $name = $anonymize ? ($w->vorname . ' ' . $w->name)
                               : trim(($w->vorname ?? '') . ' ' . ($w->name ?? ''));
            if (!$name) $name = $w->dienst_nr ?? 'Mitarbeiter';
            return (object)[
                'name'    => $name,
                'label'   => (!$anonymize && !empty($w->nu_firmenname)) ? ' (' . $w->nu_firmenname . ')' : '',
                'datum'   => $w->datum,
                'schichtart'  => $w->schichtart ?? null,
                'zuschlag_pct'=> isset($w->zuschlag_pct) ? (float)$w->zuschlag_pct : 0,
                'ist_start'   => $w->ist_start,
                'ist_ende'    => $w->ist_ende,
                'pause_minuten' => $w->pause_minuten,
                'ist_std' => isset($w->kundenstunden) ? (float)$w->kundenstunden : ($w->ist_std !== null && $w->ist_std !== '' && (float)$w->ist_std > 0 ? (float)$w->ist_std : (float)($w->ist_std_menge ?? 0)),
                'preis_kunde' => isset($w->preis_kunde) ? (float)$w->preis_kunde : 0,
                'einnahmen' => isset($w->einnahmen) ? (float)$w->einnahmen : null,
                'aufschlag' => isset($w->aufschlag) ? (float)$w->aufschlag : null,
            ];
        };
        $allWorkers = collect($confirmedWorkers)->map($rowFn)
            ->merge(collect($confirmedNu)->map($rowFn));

        // kundenstunden already excludes the pause, with or without the Pause column.
        $pauseHidden = in_array('col.th.pause', (array) $hideExtra, true);
        $allWorkers = $allWorkers->map(function ($w) {
            $w->std_view = $w->ist_std;
            return $w;
        });

        $totalStd   = $allWorkers->sum('std_view');
        $totalPause = $allWorkers->sum(fn($w) => (int)($w->pause_minuten ?? 0));
        $totalEinnahmen = $allWorkers->sum(fn($w) => $w->einnahmen !== null ? $w->einnahmen : $w->preis_kunde * $w->ist_std * (1 + $w->zuschlag_pct / 100));
        $totalAufschlag = $allWorkers->sum(fn($w) => $w->aufschlag !== null ? $w->aufschlag : $w->preis_kunde * $w->ist_std * ($w->zuschlag_pct / 100));
        $uniqueDays = $allWorkers->pluck('datum')->filter()->unique()->count();
        $days = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];

        $stdByArt = ['tagdienst'=>0.0,'nachtdienst'=>0.0,'sonntag'=>0.0,'nacht_sonntag'=>0.0,'feiertag'=>0.0,'nacht_feiertag'=>0.0];
        $artAlias = ['nacht'=>'nachtdienst','sonntag_nacht'=>'nacht_sonntag','feiertag_nacht'=>'nacht_feiertag'];
        foreach ($allWorkers as $w) {
            $art = $w->schichtart ?: 'tagdienst';
            $art = $artAlias[$art] ?? $art;
            if (!array_key_exists($art, $stdByArt)) $art = 'tagdienst';
            $stdByArt[$art] += $w->std_view;
        }

        $badgeMap = [
            'feiertag'        => ['Feiertag', 'badge-feiertag'],
            'sonntag'         => ['Sonntag',  'badge-sonntag'],
            'nacht'           => ['Nacht',    'badge-nacht'],
            'nachtdienst'     => ['Nacht',    'badge-nacht'],
            'nacht_sonntag'   => ['Nacht+So', 'badge-nacht'],
            'sonntag_nacht'   => ['Nacht+So', 'badge-nacht'],
            'nacht_feiertag'  => ['Nacht+FT', 'badge-nacht'],
            'feiertag_nacht'  => ['Nacht+FT', 'badge-nacht'],
            'tagdienst'       => ['',         'badge-tag'],
        ];
    @endphp

    <div class="section" style="page-break-inside: avoid;">
        <div class="section-title">
            BESTÄTIGTE MITARBEITER-EINSÄTZE ({{ $allWorkers->count() }} Einträge)
            @if($anonymize)<span class="anon-notice">🔒 Mitarbeiternamen anonymisiert</span>@endif
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:24px">Nr.</th>
                    <th>Mitarbeiter</th>
                    <th style="width:60px">Datum</th>
                    <th style="width:55px">Tag</th>
                    <th style="width:38px">Von</th>
                    <th style="width:38px">Bis</th>
                    @unless($pauseHidden)<th style="width:38px">Pause</th>@endunless
                    <th style="width:36px">Std</th>
                    @if($showZusch)
                    <th style="width:60px">Schicht</th>
                    <th style="width:50px">Zuschl.</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($allWorkers as $i => $w)
                @php
                    [$badgeLabel, $badgeClass] = $badgeMap[$w->schichtart ?? 'tagdienst'] ?? ['', 'badge-tag'];
                @endphp
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $w->name }}{{ $w->label }}</td>
                    <td>{{ $w->datum ? \Carbon\Carbon::parse($w->datum)->format('d.m.Y') : '–' }}</td>
                    <td>{{ $w->datum ? $days[\Carbon\Carbon::parse($w->datum)->dayOfWeek] : '–' }}</td>
                    <td>{{ $w->ist_start ? substr($w->ist_start,0,5) : '–' }}</td>
                    <td>{{ $w->ist_ende ? substr($w->ist_ende,0,5) : '–' }}</td>
                    @unless($pauseHidden)<td>{{ (int)($w->pause_minuten ?? 0) > 0 ? (int)$w->pause_minuten . ' Min' : '–' }}</td>@endunless
                    <td>{{ $w->std_view ? number_format($w->std_view, 2, ',', '') : '–' }}</td>
                    @if($showZusch)
                    <td>@if($badgeLabel)<span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>@else–@endif</td>
                    <td>{{ $w->zuschlag_pct > 0 ? '+' . number_format($w->zuschlag_pct, 0) . ' %' : '–' }}</td>
                    @endif
                </tr>
                @empty
                <tr><td colspan="{{ ($showZusch ? 10 : 8) - ($pauseHidden ? 1 : 0) }}" style="text-align:center;color:#94a3b8;padding:16px">Keine freigegebenen Einsätze (Kunden-Freigabe im Monatsabschluss)</td></tr>
                @endforelse
            </tbody>
            @if($allWorkers->count() > 0)
            <tfoot>
                <tr>
                    <td colspan="6" style="text-align:right">Gesamt:</td>
                    @unless($pauseHidden)<td>{{ $totalPause }} Min</td>@endunless
                    <td>{{ number_format($totalStd, 2, ',', '') }}</td>
                    @if($showZusch)<td colspan="2"></td>@endif
                </tr>
            </tfoot>
            @endif
        </table>
    </div>

    @if(!in_array('summary', $hideExtra))
    <div class="summary-grid">
        <div class="summary-box">
            <div class="summary-label">Einsatztage</div>
            <div class="summary-value">{{ $uniqueDays }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">MA-Einsätze</div>
            <div class="summary-value">{{ $allWorkers->count() }}</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Gesamt-Std</div>
            <div class="summary-value">{{ number_format($totalStd, 2, ',', '') }}</div>
        </div>
    </div>
    <div class="summary-grid summary-grid-std">
        <div class="summary-box sb6">
            <div class="summary-label">Std. normal</div>
            <div class="summary-value">{{ number_format($stdByArt['tagdienst'], 2, ',', '') }}</div>
        </div>
        <div class="summary-box sb6">
            <div class="summary-label">Nacht</div>
            <div class="summary-value">{{ number_format($stdByArt['nachtdienst'], 2, ',', '') }}</div>
        </div>
        <div class="summary-box sb6">
            <div class="summary-label">Sonntag</div>
            <div class="summary-value">{{ number_format($stdByArt['sonntag'], 2, ',', '') }}</div>
        </div>
        <div class="summary-box sb6">
            <div class="summary-label">Sonntag Nacht</div>
            <div class="summary-value">{{ number_format($stdByArt['nacht_sonntag'], 2, ',', '') }}</div>
        </div>
        <div class="summary-box sb6">
            <div class="summary-label">Feiertag</div>
            <div class="summary-value">{{ number_format($stdByArt['feiertag'], 2, ',', '') }}</div>
        </div>
        <div class="summary-box sb6">
            <div class="summary-label">Feiertag Nacht</div>
            <div class="summary-value">{{ number_format($stdByArt['nacht_feiertag'], 2, ',', '') }}</div>
        </div>
    </div>
    @endif

    @if(!in_array('signature', $hideExtra))
    <div class="signature-section">
        <div class="signature-block">
            <div class="signature-line">Datum, Unterschrift Auftraggeber</div>
        </div>
        <div class="signature-block">
            <div class="signature-line">Datum, Unterschrift {{ $company['name'] }}</div>
        </div>
    </div>
    @endif
</div>

@if(!in_array('footer', $hideExtra))
    @php
        // Three-column legal footer (Impressum-style).
        // Each line is rendered only if the corresponding tenant field is set,
        // so partial profiles don't leave empty lines.
        $col1 = array_filter([
            $company['name'] ?? null,
            !empty($company['geschaeftsfuehrer']) ? 'Geschäftsführer: ' . $company['geschaeftsfuehrer'] : null,
            '',  // visual gap (rendered as &nbsp;)
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
            !empty($company['email'])       ? 'E-Mail: '      . $company['email']         : null,
            !empty($company['website'])     ? 'Internet: '    . $company['website']       : null,
            $registerLine,
            !empty($company['steuernummer'])? 'Ust.-Nr.: '    . $company['steuernummer']  : null,
            !empty($company['ust_id'])      ? 'Ust.-IdNr.: '  . $company['ust_id']        : null,
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
@endif

<div class="page">
    @if(!empty($settings['extra_note']))
    <div style="margin-top:16px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; font-size:10px; color:#475569;">
        {{ $settings['extra_note'] }}
    </div>
    @endif
</div>
</body>
</html>
