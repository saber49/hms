<?php

namespace App\Services\Ai\Tools\Read;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class DispoTools implements ReadToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'list_offene_einsaetze' => true,
            'disposition_uebersicht' => true,
            'einsatz_details' => true,
            'einsatz_mitarbeiter' => true,
            'zeiterfassung_live' => true,
            'dispo_warnungen' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('list_offene_einsaetze',
                'Listet offene (ausstehend bestätigte) Einsätze, optional ab einem Datum.',
                [
                    'from'  => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => 'max 25'],
                ], []),

            $this->fn('disposition_uebersicht',
                'Disposition-Übersicht für eine Woche oder einen Monat: wie viele Einsätze sind offen, geplant, bestätigt, abgelehnt.',
                [
                    'from' => ['type' => 'string', 'description' => 'Start-Datum YYYY-MM-DD'],
                    'to'   => ['type' => 'string', 'description' => 'End-Datum YYYY-MM-DD'],
                ], ['from','to']),

            $this->fn('einsatz_details',
                'Vollständige Details zu EINEM Einsatz per Einsatz-ID: Datum, Zeiten, Kunde, Objekt, Artikel, NU, Status, Beschreibung und alle zugewiesenen Mitarbeiter. Nutze das bevor du einen Einsatz änderst oder Mitarbeiter zuweist.',
                ['einsatz_id' => ['type' => 'string', 'description' => 'z.B. "E-20260522-395"']], ['einsatz_id']),

            $this->fn('einsatz_mitarbeiter',
                'Listet die ZUGEWIESENEN Mitarbeiter eines Einsatzes MIT ihren erfassten Zeiten: '
                . 'ist_start/ist_ende, ist_std, pause_minuten, zeit_freigabe (grüner Haken) — plus NU-Positionen '
                . 'und deren Zuweisungen. IMMER dieses Tool nutzen bevor Arbeitszeiten korrigiert werden.',
                ['einsatz_id' => ['type' => 'string', 'description' => 'Pflicht — z.B. "E-20260522-395"']], ['einsatz_id']),

            $this->fn('zeiterfassung_live',
                'Wer ist JETZT eingestempelt? Liefert alle laufenden Zeiterfassungen (status aktiv/pause) '
                . 'mit Mitarbeiter, Einsatz, seit wann, und Pause. Auch nutzbar für "wer arbeitet gerade".',
                [
                    'datum' => ['type' => 'string', 'description' => 'optional — YYYY-MM-DD; ohne Angabe nur laufende Stempel'],
                ], []),

            $this->fn('dispo_warnungen',
                'Proaktive Dispositions-Warnungen für einen Zeitraum (Default: heute + 7 Tage): '
                . '1) UNBESETZTE Einsätze (keine Mitarbeiter und kein NU), '
                . '2) UNTERBESETZTE Einsätze (weniger zugewiesene Mitarbeiter als geplant), '
                . '3) UNBESTÄTIGTE Einsätze die bald starten. '
                . 'Nutze das bei Fragen wie "gibt es Probleme?", "was fehlt noch?", "ist alles abgedeckt?".',
                [
                    'from' => ['type' => 'string', 'description' => 'optional — Start-Datum YYYY-MM-DD, Default heute'],
                    'to'   => ['type' => 'string', 'description' => 'optional — End-Datum YYYY-MM-DD, Default heute+7 Tage'],
                ], []),
        ];
    }

    public function execute(string $name, array $args): array
    {
        return match ($name) {
                'list_offene_einsaetze' => $this->listOffeneEinsaetze($args),
                'disposition_uebersicht' => $this->dispositionUebersicht($args),
                'einsatz_details' => $this->einsatzDetails($args),
                'einsatz_mitarbeiter' => $this->einsatzMitarbeiter($args),
                'zeiterfassung_live' => $this->zeiterfassungLive($args),
                'dispo_warnungen' => $this->dispoWarnungen($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function listOffeneEinsaetze(array $a): array
    {
        $from   = $a['from'] ?? date('Y-m-d', strtotime('-30 days'));
        $limit  = max(1, min(25, (int) ($a['limit'] ?? 10)));

        $where  = ["e.hms_bestaetigt = 'ausstehend'", 'e.datum >= ?'];
        $params = [$from];
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('e'), $where, $params);

        $rows = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.datum, e.hms_bestaetigt,
                   k.kundenname, o.objektname, a.artikel_name, e.ist_std_menge
              FROM einsaetze e
              LEFT JOIN kunden  k ON e.kunden_id=k.kunde_id
              LEFT JOIN objekte o ON e.obj_id=o.obj_id
              LEFT JOIN artikel a ON e.artikel_id=a.artikel_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY e.datum DESC
             LIMIT {$limit}
        ", $params);

        return ['einsaetze' => $rows ?: []];
    }

    private function dispositionUebersicht(array $a): array
    {
        [$from, $to] = $this->dateRange($a);

        $where  = ['datum BETWEEN ? AND ?'];
        $params = [$from, $to];
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('einsaetze'), $where, $params);
        $whereSql = implode(' AND ', $where);

        $row = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS gesamt,
                   SUM(CASE WHEN hms_bestaetigt='ja' THEN 1 ELSE 0 END) AS bestaetigt,
                   SUM(CASE WHEN hms_bestaetigt='nein' THEN 1 ELSE 0 END) AS abgelehnt,
                   SUM(CASE WHEN hms_bestaetigt='ausstehend' AND COALESCE(pruefung,'')='geplant' THEN 1 ELSE 0 END) AS geplant,
                   SUM(CASE WHEN hms_bestaetigt='ausstehend' AND COALESCE(pruefung,'')<>'geplant' THEN 1 ELSE 0 END) AS offen,
                   COUNT(DISTINCT obj_id) AS objekte_betroffen
              FROM einsaetze
             WHERE {$whereSql}
        ", $params);

        // Workers actually scheduled in this window (distinct)
        $where2  = ['e.datum BETWEEN ? AND ?'];
        $params2 = [$from, $to];
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('e'), $where2, $params2);
        $w = DB::connection('tenant')->selectOne("
            SELECT COUNT(DISTINCT em.dienst_nr) AS ma_geplant
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id=e.einsatz_id
             WHERE " . implode(' AND ', $where2) . "
        ", $params2);

        return [
            'zeitraum'         => "$from – $to",
            'einsaetze_gesamt' => (int) $row->gesamt,
            'bestaetigt'       => (int) $row->bestaetigt,
            'geplant'          => (int) $row->geplant,
            'offen'            => (int) $row->offen,
            'abgelehnt'        => (int) $row->abgelehnt,
            'objekte_betroffen'=> (int) $row->objekte_betroffen,
            'mitarbeiter_eingeplant' => (int) $w->ma_geplant,
        ];
    }

    /**
     * Full detail view of ONE einsatz — everything the disposition card
     * shows, so the LLM can verify state before proposing a write
     * (assign_mitarbeiter etc.).
     */
    private function einsatzDetails(array $a): array
    {
        $id = trim((string) ($a['einsatz_id'] ?? ''));
        if ($id === '') return ['error' => 'einsatz_id erforderlich.'];

        $e = DB::connection('tenant')->selectOne("
            SELECT e.einsatz_id, e.bestell_id, e.datum, e.ist_start, e.ist_ende,
                   e.ist_std_menge, e.pause_minuten, e.schichtart, e.beschreibung,
                   e.hms_bestaetigt, e.pruefung, e.ort,
                   e.kunden_id, k.kundenname,
                   e.obj_id, o.objektname,
                   e.artikel_id, a.artikel_name,
                   e.nu_id, n.firmenname AS nu_name,
                   d.menge AS soll_menge, d.soll_std,
                   d.zeit_von AS plan_start, d.zeit_bis AS plan_ende
              FROM einsaetze e
              LEFT JOIN kunden  k ON e.kunden_id  = k.kunde_id
              LEFT JOIN objekte o ON e.obj_id     = o.obj_id
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              LEFT JOIN nachunternehmer n ON e.nu_id = n.nu_id
              LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
              LEFT JOIN bestelleingang_tage d
                     ON d.eingang_id = b.eingang_id AND d.datum = e.datum
             WHERE e.einsatz_id = ?
             LIMIT 1
        ", [$id]);
        if (!$e || !BereichScope::allowsEinsatz(request()->user(), $id)) {
            return ['error' => "Einsatz {$id} nicht gefunden."];
        }

        $workers = DB::connection('tenant')->select("
            SELECT em.dienst_nr,
                   TRIM(CONCAT(COALESCE(m.vorname,''), ' ', COALESCE(m.name,''))) AS name,
                   em.ist_start, em.ist_ende, em.ist_std
              FROM einsatz_mitarbeiter em
              LEFT JOIN mitarbeiter m ON em.dienst_nr = m.dienst_nr
             WHERE em.einsatz_id = ?
          ORDER BY em.sortierung ASC
        ", [$id]);

        return [
            'einsatz' => [
                'einsatz_id' => $e->einsatz_id,
                'bestell_id' => $e->bestell_id,
                'datum'      => $e->datum,
                'start'      => $e->ist_start ? substr($e->ist_start, 0, 5) : null,
                'ende'       => $e->ist_ende  ? substr($e->ist_ende,  0, 5) : null,
                'stunden'    => $e->ist_std_menge !== null ? (float) $e->ist_std_menge : null,
                'schichtart' => $e->schichtart,
                'ort'        => $e->ort,
                'status'     => $e->hms_bestaetigt === 'ja'
                                    ? 'bestätigt'
                                    : ((string) ($e->pruefung ?? '') === 'geplant' ? 'geplant' : $e->hms_bestaetigt),
                'kunde'      => $e->kunden_id ? "{$e->kunden_id} — {$e->kundenname}" : null,
                'objekt'     => $e->obj_id ? "{$e->obj_id} — {$e->objektname}" : null,
                'artikel'    => $e->artikel_id ? "{$e->artikel_id} — {$e->artikel_name}" : null,
                'nu'         => $e->nu_id ? "{$e->nu_id} — {$e->nu_name}" : null,
                'beschreibung' => $e->beschreibung,
                'soll_mitarbeiter' => $e->soll_menge !== null ? (int) round((float) $e->soll_menge) : null,
            ],
            'mitarbeiter' => array_map(fn($w) => [
                'dienst_nr' => $w->dienst_nr,
                'name'      => $w->name ?: $w->dienst_nr,
                'start'     => $w->ist_start ? substr($w->ist_start, 0, 5) : null,
                'ende'      => $w->ist_ende  ? substr($w->ist_ende,  0, 5) : null,
            ], $workers),
            'anzahl_mitarbeiter' => count($workers),
        ];
    }

    /**
     * Proactive planning warnings — the "is everything covered?" tool.
     * Three checks over the window (default: today → +7 days):
     *   unbesetzt      no internal workers AND no NU
     *   unterbesetzt   assigned internal workers < planned menge
     *   unbestaetigt   status still 'ausstehend'
     * Each list is capped so a chaotic week doesn't flood the model.
     */
    private function dispoWarnungen(array $a): array
    {
        $from = (!empty($a['from']) && strtotime($a['from'])) ? date('Y-m-d', strtotime($a['from'])) : date('Y-m-d');
        $to   = (!empty($a['to'])   && strtotime($a['to']))   ? date('Y-m-d', strtotime($a['to']))   : date('Y-m-d', strtotime($from . ' +7 days'));
        if (strtotime($from) > strtotime($to)) [$from, $to] = [$to, $from];

        $base = "
            FROM einsaetze e
            LEFT JOIN kunden  k ON e.kunden_id = k.kunde_id
            LEFT JOIN objekte o ON e.obj_id    = o.obj_id
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN bestelleingang_tage d
                   ON d.eingang_id = b.eingang_id AND d.datum = e.datum
            LEFT JOIN (
                SELECT einsatz_id, COUNT(*) AS ma_count
                  FROM einsatz_mitarbeiter GROUP BY einsatz_id
            ) mc ON mc.einsatz_id = e.einsatz_id
           WHERE e.datum BETWEEN ? AND ?
             AND e.hms_bestaetigt <> 'nein'
        ";
        // Bereich scope must be part of $base so ALL three warning
        // lists stay inside the caller's scope — and its bound params
        // ride along into every query below.
        $scopeWhere  = ['1=1'];
        $scopeParams = [];
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('e'), $scopeWhere, $scopeParams);
        if (count($scopeWhere) > 1) {
            $base .= ' AND (' . implode(' AND ', array_slice($scopeWhere, 1)) . ')';
        }
        $baseParams = array_merge([$from, $to], $scopeParams);
        $select = "
            SELECT e.einsatz_id, e.datum, e.ist_start, e.ist_ende, e.hms_bestaetigt,
                   k.kundenname, o.objektname,
                   COALESCE(mc.ma_count, 0) AS ma_count,
                   d.menge AS soll_menge
        ";

        $fmt = fn($r) => [
            'einsatz_id' => $r->einsatz_id,
            'datum'      => $r->datum,
            'zeit'       => ($r->ist_start ? substr($r->ist_start, 0, 5) : '—') . '–'
                          . ($r->ist_ende  ? substr($r->ist_ende,  0, 5) : '—'),
            'kunde'      => $r->kundenname,
            'objekt'     => $r->objektname,
            'mitarbeiter_zugewiesen' => (int) $r->ma_count,
            'mitarbeiter_geplant'    => $r->soll_menge !== null ? (int) round((float) $r->soll_menge) : null,
        ];

        // 1) Fully unstaffed: no internal workers AND no NU assigned.
        $unbesetzt = DB::connection('tenant')->select(
            "{$select} {$base}
               AND COALESCE(mc.ma_count, 0) = 0
               AND (e.nu_id IS NULL OR e.nu_id = '')
             ORDER BY e.datum ASC LIMIT 20",
            $baseParams
        );

        // 2) Understaffed: fewer internal workers than planned menge.
        //    NU-covered shifts are excluded — NU workers are tracked in
        //    nu_position_zuweisungen, not einsatz_mitarbeiter, so the
        //    internal count would produce false positives there.
        $unterbesetzt = DB::connection('tenant')->select(
            "{$select} {$base}
               AND (e.nu_id IS NULL OR e.nu_id = '')
               AND d.menge IS NOT NULL AND d.menge > 0
               AND COALESCE(mc.ma_count, 0) > 0
               AND COALESCE(mc.ma_count, 0) < ROUND(d.menge)
             ORDER BY e.datum ASC LIMIT 20",
            $baseParams
        );

        // 3) Still unconfirmed (ausstehend) in the window.
        $unbestaetigt = DB::connection('tenant')->select(
            "{$select} {$base}
               AND e.hms_bestaetigt = 'ausstehend'
             ORDER BY e.datum ASC LIMIT 20",
            $baseParams
        );

        return [
            'zeitraum'     => "$from – $to",
            'unbesetzt'    => ['anzahl' => count($unbesetzt),    'einsaetze' => array_map($fmt, $unbesetzt)],
            'unterbesetzt' => ['anzahl' => count($unterbesetzt), 'einsaetze' => array_map($fmt, $unterbesetzt)],
            'unbestaetigt' => ['anzahl' => count($unbestaetigt), 'einsaetze' => array_map($fmt, $unbestaetigt)],
            'alles_ok'     => empty($unbesetzt) && empty($unterbesetzt) && empty($unbestaetigt),
        ];
    }

    /**
     * Assigned workers of ONE einsatz INCLUDING their recorded times.
     * This is the read companion of update_mitarbeiter_zeit — the LLM
     * must look here first to learn which dienst_nr rows exist and
     * what ist_std/pause/zeiten are currently stored.
     */
    private function einsatzMitarbeiter(array $a): array
    {
        $id = trim((string) ($a['einsatz_id'] ?? ''));
        if ($id === '') return ['error' => 'einsatz_id erforderlich.'];

        $e = DB::connection('tenant')->selectOne(
            "SELECT einsatz_id, datum, hms_bestaetigt, ist_start, ist_ende, ist_std_menge, pause_minuten
               FROM einsaetze WHERE einsatz_id = ? LIMIT 1",
            [$id]
        );
        if (!$e || !BereichScope::allowsEinsatz(request()->user(), $id)) {
            return ['error' => "Einsatz {$id} nicht gefunden."];
        }

        $hasFreigabe = \Illuminate\Support\Facades\Schema::connection('tenant')
            ->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe');

        $workers = DB::connection('tenant')->select("
            SELECT em.dienst_nr,
                   TRIM(CONCAT(COALESCE(m.vorname,''),' ',COALESCE(m.name,''))) AS name,
                   m.aktiv AS mitarbeiter_aktiv,
                   em.ist_start, em.ist_ende, em.ist_std, em.pause_minuten,
                   " . ($hasFreigabe ? 'em.zeit_freigabe' : '0 AS zeit_freigabe') . "
              FROM einsatz_mitarbeiter em
              LEFT JOIN mitarbeiter m ON em.dienst_nr = m.dienst_nr
             WHERE em.einsatz_id = ?
          ORDER BY em.sortierung ASC
        ", [$id]);

        $nuPositions = DB::connection('tenant')->select("
            SELECT p.id AS position_id, p.position_name, p.anzahl, p.geschlecht_mix,
                   n.nu_id, n.firmenname,
                   (SELECT COUNT(*) FROM nu_position_zuweisungen z WHERE z.position_id = p.id) AS zugewiesen
              FROM nu_schicht_positionen p
              LEFT JOIN nachunternehmer n ON p.nu_id = n.nu_id
             WHERE p.einsatz_id = ?
          ORDER BY p.position_nr ASC
        ", [$id]);

        $nuWorkers = DB::connection('tenant')->select("
            SELECT z.position_id, z.id AS zuweisung_id,
                   npm.vorname, npm.name, npm.personal_nr,
                   z.ist_start, z.ist_ende, z.ist_std, z.pause_minuten
              FROM nu_position_zuweisungen z
              JOIN nu_schicht_positionen p ON z.position_id = p.id
              JOIN nu_portal_mitarbeiter npm ON z.nu_mitarbeiter_id = npm.id
             WHERE p.einsatz_id = ?
          ORDER BY z.id ASC
        ", [$id]);

        return [
            'einsatz' => [
                'einsatz_id' => $e->einsatz_id,
                'datum'      => $e->datum,
                'status'     => $e->hms_bestaetigt,
                'plan_start' => $e->ist_start,
                'plan_ende'  => $e->ist_ende,
                'plan_std'   => $e->ist_std_menge !== null ? (float) $e->ist_std_menge : null,
                'pause'      => (int) ($e->pause_minuten ?? 0),
            ],
            'mitarbeiter'   => $workers,
            'nu_positionen' => $nuPositions,
            'nu_mitarbeiter'=> $nuWorkers,
        ];
    }

    /**
     * Live time tracking — who is clocked in right now (or on a given
     * date). Powers questions like "who is working right now",
     * "wer ist eingestempelt", "من يعمل الآن".
     */
    private function zeiterfassungLive(array $a): array
    {
        $user  = request()->user();
        $datum = trim((string) ($a['datum'] ?? ''));

        $where  = ['1=1'];
        $params = [];
        if ($datum !== '' && strtotime($datum)) {
            $where[]  = 'DATE(z.clock_in) = ?';
            $params[] = date('Y-m-d', strtotime($datum));
        } else {
            $where[] = "z.status IN ('aktiv','pause')";
        }
        BereichScope::applySql(
            $user,
            BereichScope::derivedExpr('a', 'k'),
            $where,
            $params
        );

        $rows = DB::connection('tenant')->select("
            SELECT z.dienst_nr,
                   TRIM(CONCAT(COALESCE(m.vorname,''),' ',COALESCE(m.name,''))) AS name,
                   z.einsatz_id, z.status, z.clock_in, z.pause_total,
                   e.datum, k.kundenname, o.objektname
              FROM zeiterfassung z
              LEFT JOIN mitarbeiter m ON z.dienst_nr = m.dienst_nr
              LEFT JOIN einsaetze   e ON z.einsatz_id = e.einsatz_id
              LEFT JOIN kunden      k ON e.kunden_id  = k.kunde_id
              LEFT JOIN objekte     o ON e.obj_id     = o.obj_id
              LEFT JOIN artikel     a ON e.artikel_id = a.artikel_id
             WHERE " . implode(' AND ', $where) . "
          ORDER BY z.clock_in ASC
             LIMIT 100
        ", $params);

        return [
            'datum'      => $datum !== '' ? date('Y-m-d', strtotime($datum)) : 'live',
            'anzahl'     => count($rows),
            'eingestempelt' => $rows,
        ];
    }
}
