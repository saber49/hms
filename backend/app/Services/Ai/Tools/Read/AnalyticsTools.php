<?php

namespace App\Services\Ai\Tools\Read;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Services\ControllingCockpitService as Cockpit;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class AnalyticsTools implements ReadToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'get_kpi' => true,
            'count_einsaetze' => true,
            'top_kunden' => true,
            'top_objekte' => true,
            'top_nu' => true,
            'monatsabschluss' => true,
            'today_overview' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('get_kpi',
                'Liefert die Finanz-Kennzahlen wie im Controlling (Umsatz, NU-Kosten, Personal, Lohnnebenkosten, Deckungsbeitrag I/II, Marge, Betriebsergebnis, Einsätze nach Status) für einen Zeitraum.',
                [
                    'from' => ['type' => 'string', 'description' => 'Start-Datum YYYY-MM-DD'],
                    'to'   => ['type' => 'string', 'description' => 'End-Datum YYYY-MM-DD'],
                ], ['from', 'to']),

            $this->fn('count_einsaetze',
                'Zählt Einsätze in einem Zeitraum, optional gefiltert nach Status (ja/nein/ausstehend) oder Kunde/Objekt/NU.',
                [
                    'from'      => ['type' => 'string'],
                    'to'        => ['type' => 'string'],
                    'status'    => ['type' => 'string', 'enum' => ['ja','nein','ausstehend','alle']],
                    'kunde'     => ['type' => 'string', 'description' => 'Teil des Kundennamens'],
                    'objekt'    => ['type' => 'string', 'description' => 'Teil des Objektnamens'],
                ], ['from','to']),

            $this->fn('top_kunden',
                'Top-Kunden nach Einnahmen in einem Zeitraum.',
                [
                    'from'  => ['type' => 'string'],
                    'to'    => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => '1–20, default 5'],
                ], ['from','to']),

            $this->fn('top_objekte',
                'Top-Objekte nach Einnahmen in einem Zeitraum.',
                [
                    'from'  => ['type' => 'string'],
                    'to'    => ['type' => 'string'],
                    'limit' => ['type' => 'integer'],
                ], ['from','to']),

            $this->fn('top_nu',
                'Top-Nachunternehmer nach Einnahmen in einem Zeitraum.',
                [
                    'from'  => ['type' => 'string'],
                    'to'    => ['type' => 'string'],
                    'limit' => ['type' => 'integer'],
                ], ['from','to']),

            $this->fn('monatsabschluss',
                'Monatsabschluss-Bericht für einen Monat wie im Controlling: geleistete und für den Kunden freigegebene Einsätze/Stunden/Umsatz, Personal, Lohnnebenkosten, Deckungsbeitrag II sowie Anzahl Kunden/Objekte/Mitarbeiter.',
                [
                    'monat' => ['type' => 'integer', 'description' => '1–12'],
                    'jahr'  => ['type' => 'integer', 'description' => 'z.B. 2026'],
                    'kunden_id' => ['type' => 'string', 'description' => 'optional, Kunden-ID zur Filterung'],
                ], ['monat','jahr']),

            $this->fn('today_overview',
                'Tagesüberblick: Einsätze heute (bestätigt/offen), heutige Abwesenheiten, offene Tasks.',
                [], []),
        ];
    }

    public function execute(string $name, array $args): array
    {
        return match ($name) {
                'get_kpi' => $this->getKpi($args),
                'count_einsaetze' => $this->countEinsaetze($args),
                'top_kunden' => $this->topDimension($args, 'kunde'),
                'top_objekte' => $this->topDimension($args, 'objekt'),
                'top_nu' => $this->topDimension($args, 'nu'),
                'monatsabschluss' => $this->monatsabschluss($args),
                'today_overview' => $this->todayOverview($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    /**
     * Financial figures — the SAME calculation as the Controlling cockpit
     * (hours from the Zeiterfassung, DB I / DB II after Lohnnebenkosten).
     * Bereich scope is applied inside the service (whereClause).
     */
    private function getKpi(array $a): array
    {
        [$from, $to] = $this->dateRange($a);
        $ctx = Cockpit::context();
        $t = Cockpit::totals($ctx, $from, $to);
        $op = Cockpit::result($ctx, $t, Cockpit::statusesFor('operativ'));
        $ab = Cockpit::result($ctx, $t, Cockpit::statusesFor('abrechnung'));
        $fixOn = Cockpit::fixApplies([]);
        $fix = $fixOn ? Cockpit::fixkosten($from, min($to, Cockpit::today()))['total'] : null;

        return [
            'zeitraum'  => "$from – $to",
            'hinweis'   => 'Werte wie im Controlling (operativ = freigegeben + geleistet). "gewinn_eur" ist der Deckungsbeitrag II nach Lohnnebenkosten.',
            'einnahmen_eur'          => $op['umsatz'],
            'nu_kosten_eur'          => $op['nu_kosten'],
            'ma_kosten_eur'          => $op['personal'],
            'lohnnebenkosten_eur'    => $op['lohnnebenkosten'],
            'deckungsbeitrag_1_eur'  => $op['db1'],
            'gewinn_eur'             => $op['db2'],
            'marge_pct'              => $op['marge_db2'] ?? 0,
            'fixkosten_eur'          => $fix,
            'betriebsergebnis_eur'   => $fix === null ? null : round($op['db2'] - $fix, 2),
            'einnahmen_kunde_freigegeben_eur' => $ab['umsatz'],
            'stunden'                => $op['stunden'],
            'einsaetze_geleistet'    => $op['einsaetze'],
            'einsaetze_kunde_freigegeben' => $t['freigegeben']['einsaetze'],
            'einsaetze_nicht_freigegeben' => $t['offen']['einsaetze'],
            'einsaetze_unbesetzt'    => $t['unbesetzt']['einsaetze'],
            'einsaetze_geplant'      => $t['geplant']['einsaetze'],
        ];
    }

    private function countEinsaetze(array $a): array
    {
        [$from, $to] = $this->dateRange($a);
        $whereA = ['e.datum BETWEEN ? AND ?'];
        $params = [$from, $to];

        $status = $a['status'] ?? 'alle';
        if (in_array($status, ['ja','nein','ausstehend'], true)) {
            $whereA[] = 'e.hms_bestaetigt = ?';
            $params[] = $status;
        }
        if (!empty($a['kunde'])) {
            $whereA[] = 'k.kundenname LIKE ?';
            $params[] = '%' . $a['kunde'] . '%';
        }
        if (!empty($a['objekt'])) {
            $whereA[] = 'o.objektname LIKE ?';
            $params[] = '%' . $a['objekt'] . '%';
        }
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('e'), $whereA, $params);
        $where = implode(' AND ', $whereA);

        $row = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS c
              FROM einsaetze e
              LEFT JOIN kunden k ON e.kunden_id=k.kunde_id
              LEFT JOIN objekte o ON e.obj_id=o.obj_id
             WHERE {$where}
        ", $params);

        return [
            'zeitraum' => "$from – $to",
            'status'   => $status,
            'anzahl'   => (int) $row->c,
        ];
    }

    private function topDimension(array $a, string $dim): array
    {
        [$from, $to] = $this->dateRange($a);
        $limit = max(1, min(20, (int) ($a['limit'] ?? 5)));

        // Map abstract dimension to (id col, join table, name col)
        $cfg = [
            'kunde'  => ['e.kunden_id', 'kunden',           'k.kundenname',  'k', 'k.kunde_id'],
            'objekt' => ['e.obj_id',    'objekte',          'o.objektname',  'o', 'o.obj_id'],
            'nu'     => ['e.nu_id',     'nachunternehmer',  'n.firmenname',  'n', 'n.nu_id'],
        ][$dim];
        [$idCol, $tbl, $nameCol, $alias, $joinId] = $cfg;

        // Same calculation as the Controlling cockpit (operative view);
        // Bereich scope is applied inside the service before GROUP BY.
        $ctx = Cockpit::context();
        $rows = Cockpit::rows($ctx, "{$idCol} AS dim_id, COALESCE(MAX({$nameCol}), '–') AS name", $idCol,
            "LEFT JOIN {$tbl} {$alias} ON {$idCol} = {$joinId}", $from, $to, [], Cockpit::statusesFor('operativ'),
            $dim === 'nu' ? "AND e.nu_id IS NOT NULL AND e.nu_id <> ''" : '');
        usort($rows, fn ($x, $y) => $y['umsatz'] <=> $x['umsatz']);

        return [
            'zeitraum' => "$from – $to",
            'hinweis'  => 'Umsatz wie im Controlling (freigegeben + geleistet); deckungsbeitrag_2_eur nach Lohnnebenkosten.',
            'top'      => array_map(fn ($r) => [
                'name'                 => $r['name'],
                'einsaetze'            => $r['einsaetze'],
                'einnahmen_eur'        => $r['umsatz'],
                'deckungsbeitrag_2_eur'=> $r['db2'],
                'marge_pct'            => $r['marge_db2'],
            ], array_slice($rows, 0, $limit)),
        ];
    }

    private function monatsabschluss(array $a): array
    {
        $monat = max(1, min(12, (int) ($a['monat'] ?? date('n'))));
        $jahr  = max(2000, (int) ($a['jahr']  ?? date('Y')));
        $from  = sprintf('%04d-%02d-01', $jahr, $monat);
        $to    = date('Y-m-t', strtotime($from));

        $filters = !empty($a['kunden_id']) ? ['kunden_id' => (string) $a['kunden_id']] : [];

        // Same calculation as the Controlling cockpit / Zeiterfassung:
        // "abgerechnet" = released for the customer, "operativ" = + geleistet.
        $ctx = Cockpit::context();
        $t = Cockpit::totals($ctx, $from, $to, $filters);
        $op = Cockpit::result($ctx, $t, Cockpit::statusesFor('operativ'));
        $ab = Cockpit::result($ctx, $t, Cockpit::statusesFor('abrechnung'));

        // Distinct customers / objects / workers of the performed shifts.
        [$where, $params] = \App\Services\ControllingAggregationService::whereClause($from, min($to, Cockpit::today()), $filters);
        $row = DB::connection('tenant')->selectOne("
            SELECT COUNT(DISTINCT e.kunden_id) AS kunden, COUNT(DISTINCT e.obj_id) AS objekte
              FROM einsaetze e
             WHERE {$where} AND e.hms_bestaetigt <> 'nein'
        ", $params);
        $workerRow = DB::connection('tenant')->selectOne("
            SELECT COUNT(DISTINCT em.dienst_nr) AS aktive_ma
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
             WHERE {$where} AND e.hms_bestaetigt <> 'nein'
        ", $params);

        return [
            'monat'   => sprintf('%02d/%d', $monat, $jahr),
            'hinweis' => 'Werte wie im Controlling. "gewinn_eur" = Deckungsbeitrag II nach Lohnnebenkosten (operativ).',
            'einsaetze_geleistet'           => $op['einsaetze'],
            'einsaetze_kunde_freigegeben'   => $t['freigegeben']['einsaetze'],
            'einsaetze_nicht_freigegeben'   => $t['offen']['einsaetze'],
            'gesamtstunden'                 => $op['stunden'],
            'stunden_kunde_freigegeben'     => $ab['stunden'],
            'einnahmen_eur'                 => $op['umsatz'],
            'einnahmen_kunde_freigegeben_eur' => $ab['umsatz'],
            'nu_kosten_eur'                 => $op['nu_kosten'],
            'ma_kosten_eur'                 => $op['personal'],
            'lohnnebenkosten_eur'           => $op['lohnnebenkosten'],
            'deckungsbeitrag_1_eur'         => $op['db1'],
            'gewinn_eur'                    => $op['db2'],
            'marge_pct'                     => $op['marge_db2'] ?? 0,
            'kunden_aktiv'                  => (int) $row->kunden,
            'objekte_aktiv'                 => (int) $row->objekte,
            'mitarbeiter_aktiv'             => (int) $workerRow->aktive_ma,
        ];
    }

    private function todayOverview(): array
    {
        $today = date('Y-m-d');
        $user  = request()->user();

        $where  = ['datum = ?'];
        $params = [$today];
        BereichScope::applySql($user, BereichScope::derivedExprSub('einsaetze'), $where, $params);
        $e = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS gesamt,
                   SUM(CASE WHEN hms_bestaetigt='ja' THEN 1 ELSE 0 END) AS bestaetigt,
                   SUM(CASE WHEN hms_bestaetigt='ausstehend' THEN 1 ELSE 0 END) AS offen,
                   SUM(CASE WHEN hms_bestaetigt='nein' THEN 1 ELSE 0 END) AS abgelehnt
              FROM einsaetze
             WHERE " . implode(' AND ', $where) . "
        ", $params);

        // Absences follow worker-pool semantics: untagged workers stay
        // visible to restricted dispatchers (shared staffing pool).
        $awhere  = ['startdatum <= ?', 'enddatum >= ?'];
        $aparams = [$today, $today];
        BereichScope::applySqlOrEmpty($user,
            "(SELECT m.bereich FROM mitarbeiter m WHERE m.dienst_nr = mitarbeiter_abwesenheiten.dienst_nr)",
            $awhere, $aparams);
        $abw = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS anzahl
              FROM mitarbeiter_abwesenheiten
             WHERE " . implode(' AND ', $awhere) . "
        ", $aparams);

        return [
            'heute'      => $today,
            'einsaetze'  => [
                'gesamt'     => (int) $e->gesamt,
                'bestaetigt' => (int) $e->bestaetigt,
                'offen'      => (int) $e->offen,
                'abgelehnt'  => (int) $e->abgelehnt,
            ],
            'abwesenheiten' => (int) $abw->anzahl,
        ];
    }
}
