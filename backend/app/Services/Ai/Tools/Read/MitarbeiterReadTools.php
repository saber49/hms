<?php

namespace App\Services\Ai\Tools\Read;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class MitarbeiterReadTools implements ReadToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'mitarbeiter_count' => true,
            'mitarbeiter_abwesenheiten' => true,
            'mitarbeiter_arbeitszeit' => true,
            'mitarbeiter_einsaetze' => true,
            'verfuegbare_mitarbeiter' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('mitarbeiter_count',
                'Zählt aktive und inaktive Mitarbeiter im System.',
                [], []),

            $this->fn('mitarbeiter_abwesenheiten',
                'Aktuelle/geplante Mitarbeiter-Abwesenheiten (Urlaub, Krank, …) in einem Zeitraum. Optional nur ein Mitarbeiter.',
                [
                    'from'     => ['type' => 'string'],
                    'to'       => ['type' => 'string'],
                    'dienst_nr'=> ['type' => 'string', 'description' => 'optional, einzelner Mitarbeiter'],
                ], ['from','to']),

            $this->fn('mitarbeiter_arbeitszeit',
                'Ist-Arbeitsstunden eines einzelnen Mitarbeiters in einem Zeitraum, inkl. Anzahl Einsätze und summierter Lohnkosten (falls Stundenlohn hinterlegt).',
                [
                    'dienst_nr_oder_name' => ['type' => 'string', 'description' => 'Dienstnummer oder Teil des Namens'],
                    'from' => ['type' => 'string'],
                    'to'   => ['type' => 'string'],
                ], ['dienst_nr_oder_name','from','to']),

            $this->fn('mitarbeiter_einsaetze',
                'Listet die geplanten und bestätigten Einsätze eines bestimmten internen Mitarbeiters in einem Zeitraum (Datum, Kunde, Objekt, Zeit, Status). Hilfreich für die Frage "wo arbeitet X heute / diese Woche / im Monat".',
                [
                    'dienst_nr_oder_name' => ['type' => 'string', 'description' => 'Dienstnummer oder Teil des Namens'],
                    'from' => ['type' => 'string', 'description' => 'Start-Datum YYYY-MM-DD'],
                    'to'   => ['type' => 'string', 'description' => 'End-Datum YYYY-MM-DD'],
                ], ['dienst_nr_oder_name','from','to']),

            $this->fn('verfuegbare_mitarbeiter',
                'Listet Mitarbeiter, die an einem bestimmten Datum (optional zu einer Uhrzeit) verfügbar sind: aktive Mitarbeiter, nicht im Urlaub/Krank, nicht bereits einem überlappenden Einsatz zugewiesen. Mit optionalem Bereich-Filter (z.B. "Sicherheit", "Reinigung").',
                [
                    'datum'      => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'zeit_von'   => ['type' => 'string', 'description' => 'optional, HH:MM (z.B. "07:00")'],
                    'zeit_bis'   => ['type' => 'string', 'description' => 'optional, HH:MM (z.B. "15:00"). Wenn nur zeit_von angegeben ist, wird +8h angenommen.'],
                    'bereich'    => ['type' => 'string', 'description' => 'optional, Bereich/Aufgabe (z.B. "Sicherheit", "Schutz", "Reinigung")'],
                ], ['datum']),
        ];
    }

    public function execute(string $name, array $args): array
    {
        return match ($name) {
                'mitarbeiter_count' => $this->mitarbeiterCount($args),
                'mitarbeiter_abwesenheiten' => $this->mitarbeiterAbwesenheiten($args),
                'mitarbeiter_arbeitszeit' => $this->mitarbeiterArbeitszeit($args),
                'mitarbeiter_einsaetze' => $this->mitarbeiterEinsaetze($args),
                'verfuegbare_mitarbeiter' => $this->verfuegbareMitarbeiter($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function mitarbeiterCount(): array
    {
        [$scope, $scopeParams] = BereichScope::workerPredicate(request()->user(), 'm');
        $row = DB::connection('tenant')->selectOne("
            SELECT
                SUM(CASE WHEN aktiv=1 THEN 1 ELSE 0 END) AS aktiv,
                SUM(CASE WHEN aktiv=0 THEN 1 ELSE 0 END) AS inaktiv,
                COUNT(*) AS gesamt
              FROM mitarbeiter m
             WHERE {$scope}
        ", $scopeParams);
        return [
            'aktiv'   => (int) $row->aktiv,
            'inaktiv' => (int) $row->inaktiv,
            'gesamt'  => (int) $row->gesamt,
        ];
    }

    private function mitarbeiterAbwesenheiten(array $a): array
    {
        // Custom range — we don't clamp to 2 years like dateRange because
        // showing future planned absences is useful (e.g. Urlaub im Sommer).
        $from = $a['from'] ?? date('Y-m-d');
        $to   = $a['to']   ?? date('Y-m-d', strtotime('+30 days'));
        if (!strtotime($from) || !strtotime($to)) {
            return ['error' => 'Ungültiges Datum.'];
        }

        $where  = "ab.startdatum <= ? AND ab.enddatum >= ?";
        $params = [$to, $from];
        if (!empty($a['dienst_nr'])) {
            $where .= ' AND ab.dienst_nr = ?';
            $params[] = $a['dienst_nr'];
        }
        // Worker-pool semantics: absences of untagged workers stay
        // visible; tagged out-of-scope workers are hidden.
        [$scope, $scopeParams] = BereichScope::workerPredicate(request()->user(), 'm');
        $where .= " AND {$scope}";
        $params = array_merge($params, $scopeParams);

        $rows = DB::connection('tenant')->select("
            SELECT ab.id, ab.dienst_nr, ab.typ, ab.bezahlt, ab.startdatum, ab.enddatum,
                   ab.tage, ab.status,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(m.vorname,''), ' ', COALESCE(m.name,''))), ''), ab.dienst_nr) AS name
              FROM mitarbeiter_abwesenheiten ab
              LEFT JOIN mitarbeiter m ON ab.dienst_nr=m.dienst_nr
             WHERE {$where}
          ORDER BY ab.startdatum ASC
             LIMIT 30
        ", $params);

        return [
            'zeitraum' => "$from – $to",
            'anzahl'   => count($rows),
            'eintraege' => array_map(fn($r) => [
                'name'       => $r->name,
                'dienst_nr'  => $r->dienst_nr,
                'typ'        => $r->typ,
                'von'        => $r->startdatum,
                'bis'        => $r->enddatum,
                'tage'       => $r->tage,
                'bezahlt'    => (int) ($r->bezahlt ?? 0) === 1,
                'status'     => $r->status,
            ], $rows),
        ];
    }

    private function mitarbeiterArbeitszeit(array $a): array
    {
        [$from, $to] = $this->dateRange($a);
        $q = trim((string) ($a['dienst_nr_oder_name'] ?? ''));
        if ($q === '') return ['error' => 'Name oder Dienstnummer erforderlich.'];

        // Resolve worker — token-aware so "Sahil Ahmadi" matches vorname+name.
        $haystack = "CONCAT_WS(' ', COALESCE(m.vorname,''), COALESCE(m.name,''), COALESCE(m.dienst_nr,''))";
        [$whereSql, $params] = $this->tokenizedLike($haystack, $q);
        $hasLohnCols = \Schema::connection('tenant')->hasColumn('mitarbeiter','stundenlohn');
        $hasTypCols  = \Schema::connection('tenant')->hasColumn('mitarbeiter','lohn_typ')
            && \Schema::connection('tenant')->hasColumn('mitarbeiter','arbeitsstunden')
            && \Schema::connection('tenant')->hasColumn('mitarbeiter','arbeitsstunden_typ');
        [$scope, $scopeParams] = BereichScope::workerPredicate(request()->user(), 'm');
        $worker = DB::connection('tenant')->selectOne("
            SELECT m.dienst_nr, m.vorname, m.name, m.aktiv,
                   " . ($hasLohnCols ? "COALESCE(m.stundenlohn,0)" : "0") . " AS stundenlohn,
                   " . ($hasTypCols ? "m.lohn_typ, m.arbeitsstunden, m.arbeitsstunden_typ" : "NULL AS lohn_typ, NULL AS arbeitsstunden, NULL AS arbeitsstunden_typ") . "
              FROM mitarbeiter m
             WHERE {$whereSql} AND {$scope}
             ORDER BY m.aktiv DESC
             LIMIT 1
        ", array_merge($params, $scopeParams));

        if (!$worker) return ['error' => 'Kein Mitarbeiter mit dieser Bezeichnung gefunden.'];

        // Hours only count in-scope einsätze — a pool worker may also
        // run shifts in Bereiche the dispatcher cannot see.
        $hWhere  = ['em.dienst_nr = ?', 'e.datum BETWEEN ? AND ?'];
        $hParams = [$worker->dienst_nr, $from, $to];
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('e'), $hWhere, $hParams);
        $row = DB::connection('tenant')->selectOne("
            SELECT COUNT(DISTINCT em.einsatz_id) AS einsaetze,
                   COALESCE(SUM(COALESCE(NULLIF(em.ist_std,0), e.ist_std_menge)), 0) AS stunden
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id=e.einsatz_id
             WHERE " . implode(' AND ', $hWhere) . "
        ", $hParams);

        $stunden = (float) $row->stunden;
        // lohn_typ='gehalt' stores a MONTHLY salary in stundenlohn — convert
        // to the hourly equivalent (same formula as ControllingAggregationService).
        $lohn = (float) $worker->stundenlohn;
        $lohnTyp = $worker->lohn_typ ?? null;
        if ($lohnTyp === 'gehalt' && $lohn > 0) {
            $std = (float) ($worker->arbeitsstunden ?? 0);
            $lohn = ($worker->arbeitsstunden_typ ?? null) === 'monatlich'
                ? $lohn / ($std > 0 ? $std : 173.33)
                : $lohn / (($std > 0 ? $std : 40) * 13 / 3);
        }
        return [
            'mitarbeiter' => [
                'dienst_nr' => $worker->dienst_nr,
                'name'      => trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? '')) ?: $worker->dienst_nr,
                'aktiv'     => (int) ($worker->aktiv ?? 0) === 1,
                'lohn_typ'  => $lohnTyp,
                'stundenlohn_eur' => round($lohn, 2),
            ],
            'zeitraum'         => "$from – $to",
            'einsaetze'        => (int) $row->einsaetze,
            'stunden'          => round($stunden, 2),
            'lohnkosten_eur'   => round($stunden * $lohn, 2),
        ];
    }

    /**
     * Resolves a worker by name/dienst_nr and lists their einsätze in a
     * date range — same data the Disposition planner shows, but tabular
     * so the LLM can read it. Crucial for "wo arbeitet X heute?" type
     * questions which the bare arbeitszeit tool can't answer.
     */
    private function mitarbeiterEinsaetze(array $a): array
    {
        [$from, $to] = $this->dateRange($a);
        $q = trim((string) ($a['dienst_nr_oder_name'] ?? ''));
        if ($q === '') return ['error' => 'Name oder Dienstnummer erforderlich.'];

        // Resolve worker first (token-aware so "Saber Al Khalouf" works
        // even with vorname='Saber', name='Al Khalouf').
        $haystack = "CONCAT_WS(' ', COALESCE(m.vorname,''), COALESCE(m.name,''), COALESCE(m.dienst_nr,''))";
        [$whereSql, $params] = $this->tokenizedLike($haystack, $q);
        [$scope, $scopeParams] = BereichScope::workerPredicate(request()->user(), 'm');
        $worker = DB::connection('tenant')->selectOne("
            SELECT m.dienst_nr, m.vorname, m.name, m.aktiv, m.bereich
              FROM mitarbeiter m
             WHERE {$whereSql} AND {$scope}
             ORDER BY m.aktiv DESC
             LIMIT 1
        ", array_merge($params, $scopeParams));
        if (!$worker) return ['error' => 'Kein Mitarbeiter gefunden.'];

        // Pull einsätze for that dienst_nr in window — includes both
        // bestätigte und ausstehende, so the user sees what's planned vs
        // what's already confirmed. Capped at 50 rows so a hallucinated
        // 5-year window doesn't drown the model in tokens. Bereich
        // scope applies to the shift list (a pool worker may also run
        // out-of-scope shifts).
        $eWhere  = ['em.dienst_nr = ?', 'e.datum BETWEEN ? AND ?'];
        $eParams = [$worker->dienst_nr, $from, $to];
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('e'), $eWhere, $eParams);
        $rows = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.datum, e.hms_bestaetigt, e.pruefung,
                   COALESCE(em.ist_start, e.ist_start) AS start_zeit,
                   COALESCE(em.ist_ende,  e.ist_ende)  AS end_zeit,
                   COALESCE(NULLIF(em.ist_std,0), e.ist_std_menge) AS std,
                   k.kundenname, o.objektname, a.artikel_name
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
              LEFT JOIN kunden  k ON e.kunden_id  = k.kunde_id
              LEFT JOIN objekte o ON e.obj_id     = o.obj_id
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
             WHERE " . implode(' AND ', $eWhere) . "
          ORDER BY e.datum ASC, start_zeit ASC
             LIMIT 50
        ", $eParams);

        $list = array_map(fn($r) => [
            'datum'      => $r->datum,
            'kunde'      => $r->kundenname,
            'objekt'     => $r->objektname,
            'artikel'    => $r->artikel_name,
            'start'      => $r->start_zeit ? substr($r->start_zeit, 0, 5) : null,
            'ende'       => $r->end_zeit   ? substr($r->end_zeit,   0, 5) : null,
            'stunden'    => $r->std !== null ? (float) $r->std : null,
            'status'     => $r->hms_bestaetigt === 'ja'
                                ? 'bestätigt'
                                : ((string) ($r->pruefung ?? '') === 'geplant'
                                    ? 'geplant'
                                    : $r->hms_bestaetigt),
        ], $rows);

        return [
            'mitarbeiter' => [
                'dienst_nr' => $worker->dienst_nr,
                'name'      => trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? '')) ?: $worker->dienst_nr,
                'bereich'   => $worker->bereich,
            ],
            'zeitraum'  => "$from – $to",
            'anzahl'    => count($list),
            'einsaetze' => $list,
            'hinweis'   => count($list) === 0
                ? 'Für diesen Zeitraum sind keine Einsätze für diesen Mitarbeiter eingeplant.'
                : null,
        ];
    }

    /**
     * Lists workers available for a given date (and optionally a time
     * window + bereich). Mirrors the Disposition planner's "free workers"
     * logic but condensed for the LLM: aktiv + not absent + no overlapping
     * einsatz on the same date/time.
     *
     * Time-window logic: if the caller only gives zeit_von, we assume an
     * 8-hour shift to detect overlaps. If neither is given, we just check
     * that the worker has no einsatz at all on that day (whole-day check).
     */
    private function verfuegbareMitarbeiter(array $a): array
    {
        $datum = (string) ($a['datum'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || !strtotime($datum)) {
            return ['error' => 'Bitte ein gültiges Datum im Format YYYY-MM-DD angeben.'];
        }
        $datum = date('Y-m-d', strtotime($datum));

        $zeitVon = $this->normalizeTime($a['zeit_von'] ?? null);
        $zeitBis = $this->normalizeTime($a['zeit_bis'] ?? null);
        if ($zeitVon && !$zeitBis) {
            // Assume 8h shift so the overlap check still works.
            $zeitBis = date('H:i', strtotime($datum . ' ' . $zeitVon . ' +8 hours'));
        }
        $bereich = trim((string) ($a['bereich'] ?? ''));

        // Map common Arabic / English terms to the bereich values used in
        // the schema, so a user asking for "حماية" still finds Sicherheit.
        // The mapping is intentionally fuzzy — we OR all hits into a LIKE.
        $bereichAliases = $this->bereichSynonyms($bereich);

        // Build the worker query: active, optionally matching bereich.
        $where  = ['m.aktiv = 1'];
        $params = [];
        if (!empty($bereichAliases)) {
            $or = [];
            foreach ($bereichAliases as $term) {
                $or[]     = 'm.bereich LIKE ?';
                $params[] = '%' . $term . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }

        // Exclude workers absent that day (status=genehmigt by default OR
        // pending — we treat both as "unavailable" for planning purposes).
        $where[] = "NOT EXISTS (
            SELECT 1 FROM mitarbeiter_abwesenheiten ab
             WHERE ab.dienst_nr = m.dienst_nr
               AND ? BETWEEN ab.startdatum AND ab.enddatum
               AND COALESCE(ab.status, 'genehmigt') IN ('genehmigt','pending')
        )";
        $params[] = $datum;

        // Exclude workers with an overlapping einsatz on the same date.
        // If we have a time window, check time overlap; otherwise mark as
        // "potentially busy" if they have ANY einsatz that day.
        if ($zeitVon && $zeitBis) {
            $where[] = "NOT EXISTS (
                SELECT 1 FROM einsatz_mitarbeiter em
                  JOIN einsaetze e2 ON em.einsatz_id = e2.einsatz_id
                 WHERE em.dienst_nr = m.dienst_nr
                   AND e2.datum = ?
                   AND COALESCE(em.ist_start, e2.ist_start, '00:00') < ?
                   AND COALESCE(em.ist_ende,  e2.ist_ende,  '23:59') > ?
            )";
            $params[] = $datum;
            $params[] = $zeitBis;
            $params[] = $zeitVon;
        } else {
            $where[] = "NOT EXISTS (
                SELECT 1 FROM einsatz_mitarbeiter em
                  JOIN einsaetze e2 ON em.einsatz_id = e2.einsatz_id
                 WHERE em.dienst_nr = m.dienst_nr
                   AND e2.datum = ?
            )";
            $params[] = $datum;
        }

        // Worker pool semantics — untagged workers stay visible to
        // restricted dispatchers (shared staffing resource).
        [$scope, $scopeParams] = BereichScope::workerPredicate(request()->user(), 'm');
        $where[]  = $scope;
        $params   = array_merge($params, $scopeParams);

        $whereSql = implode(' AND ', $where);
        $workers = DB::connection('tenant')->select("
            SELECT m.dienst_nr, m.vorname, m.name, m.bereich,
                   m.arbeitsstunden, m.arbeitsstunden_typ
              FROM mitarbeiter m
             WHERE {$whereSql}
          ORDER BY m.vorname ASC, m.name ASC
             LIMIT 30
        ", $params);

        return [
            'datum'    => $datum,
            'zeit_von' => $zeitVon,
            'zeit_bis' => $zeitBis,
            'bereich'  => $bereich ?: null,
            'anzahl'   => count($workers),
            'verfuegbar' => array_map(fn($w) => [
                'dienst_nr' => $w->dienst_nr,
                'name'      => trim(($w->vorname ?? '') . ' ' . ($w->name ?? '')) ?: $w->dienst_nr,
                'bereich'   => $w->bereich,
                'soll_stunden_pro_woche' => $w->arbeitsstunden !== null ? (float) $w->arbeitsstunden : null,
                'beschaeftigung'         => $w->arbeitsstunden_typ,
            ], $workers),
            'hinweis' => count($workers) === 0
                ? 'Keine verfügbaren Mitarbeiter für die angegebenen Kriterien.'
                : null,
        ];
    }
}
