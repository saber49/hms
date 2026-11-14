<?php

namespace App\Services\Ai\Tools\Read;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class NuReadTools implements ReadToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'nu_einsaetze' => true,
            'nu_details' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('nu_einsaetze',
                'Listet die Einsätze eines bestimmten Nachunternehmers in einem Zeitraum (Datum, Kunde, Objekt, Zeit, Status). Hilfreich für "welche Einsätze hat NU X diese Woche".',
                [
                    'nu_id_oder_name' => ['type' => 'string', 'description' => 'NU-ID (z.B. "NU001") oder Teil des Firmennamens'],
                    'from' => ['type' => 'string', 'description' => 'Start-Datum YYYY-MM-DD'],
                    'to'   => ['type' => 'string', 'description' => 'End-Datum YYYY-MM-DD'],
                ], ['nu_id_oder_name', 'from', 'to']),

            $this->fn('nu_details',
                'Komplette Stammdaten eines Nachunternehmers plus Aktivität: Portal-Mitarbeiter, '
                . 'Einsätze der letzten 90 Tage und offene Positionen.',
                ['nu_id_oder_name' => ['type' => 'string', 'description' => 'NU-ID (z.B. "NU001") oder Teil des Firmennamens']],
                ['nu_id_oder_name']),
        ];
    }

    public function execute(string $name, array $args): array
    {
        return match ($name) {
                'nu_einsaetze' => $this->nuEinsaetze($args),
                'nu_details' => $this->nuDetails($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    /**
     * Einsätze of one NU in a date range — the NU counterpart of
     * mitarbeiterEinsaetze. Accepts an NU-ID or a name fragment.
     */
    private function nuEinsaetze(array $a): array
    {
        [$from, $to] = $this->dateRange($a);
        $q = trim((string) ($a['nu_id_oder_name'] ?? ''));
        if ($q === '') return ['error' => 'NU-ID oder Firmenname erforderlich.'];

        // Resolve by exact ID first (with hyphen tolerance), then by name.
        $candidates = [$q, str_replace('-', '', $q)];
        $nu = null;
        foreach (array_unique($candidates) as $cand) {
            $nu = DB::connection('tenant')->selectOne(
                "SELECT nu_id, firmenname, aktiv FROM nachunternehmer WHERE nu_id = ? LIMIT 1", [$cand]
            );
            if ($nu) break;
        }
        if (!$nu) {
            [$whereSql, $params] = $this->tokenizedLike('firmenname', $q);
            [$nScope, $nParams] = BereichScope::nuPredicate(request()->user(), 'nachunternehmer');
            $nu = DB::connection('tenant')->selectOne("
                SELECT nu_id, firmenname, aktiv FROM nachunternehmer
                 WHERE {$whereSql} AND {$nScope} ORDER BY aktiv DESC LIMIT 1
            ", array_merge($params, $nParams));
        }
        if (!$nu || !BereichScope::allowsNu(request()->user(), $nu->nu_id)) {
            return ['error' => 'Kein Nachunternehmer gefunden.'];
        }

        $eWhere  = ['e.nu_id = ?', 'e.datum BETWEEN ? AND ?'];
        $eParams = [$nu->nu_id, $from, $to];
        BereichScope::applySql(request()->user(), BereichScope::derivedExprSub('e'), $eWhere, $eParams);
        $rows = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.datum, e.hms_bestaetigt, e.pruefung,
                   e.ist_start, e.ist_ende, e.ist_std_menge,
                   k.kundenname, o.objektname, a.artikel_name
              FROM einsaetze e
              LEFT JOIN kunden  k ON e.kunden_id  = k.kunde_id
              LEFT JOIN objekte o ON e.obj_id     = o.obj_id
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
             WHERE " . implode(' AND ', $eWhere) . "
          ORDER BY e.datum ASC
             LIMIT 50
        ", $eParams);

        return [
            'nu' => ['nu_id' => $nu->nu_id, 'firmenname' => $nu->firmenname],
            'zeitraum'  => "$from – $to",
            'anzahl'    => count($rows),
            'einsaetze' => array_map(fn($r) => [
                'einsatz_id' => $r->einsatz_id,
                'datum'      => $r->datum,
                'kunde'      => $r->kundenname,
                'objekt'     => $r->objektname,
                'artikel'    => $r->artikel_name,
                'start'      => $r->ist_start ? substr($r->ist_start, 0, 5) : null,
                'ende'       => $r->ist_ende  ? substr($r->ist_ende,  0, 5) : null,
                'stunden'    => $r->ist_std_menge !== null ? (float) $r->ist_std_menge : null,
                'status'     => $r->hms_bestaetigt === 'ja'
                                    ? 'bestätigt'
                                    : ((string) ($r->pruefung ?? '') === 'geplant' ? 'geplant' : $r->hms_bestaetigt),
            ], $rows),
            'hinweis' => count($rows) === 0
                ? 'Für diesen Zeitraum sind keine Einsätze für diesen NU vorhanden.'
                : null,
        ];
    }

    /**
     * Full NU master record + activity — the read side the LLM needs
     * before update_nu calls and for "was kann Firma X" questions.
     */
    private function nuDetails(array $a): array
    {
        $q = trim((string) ($a['nu_id_oder_name'] ?? $a['nu_id'] ?? $a['query'] ?? ''));
        if ($q === '') return ['error' => 'NU-ID oder Firmenname erforderlich.'];

        $nu = null;
        foreach (array_unique([$q, str_replace('-', '', $q)]) as $cand) {
            $nu = DB::connection('tenant')->selectOne(
                "SELECT * FROM nachunternehmer WHERE nu_id = ? LIMIT 1", [$cand]
            );
            if ($nu) break;
        }
        if (!$nu) {
            [$whereSql, $params] = $this->tokenizedLike('firmenname', $q);
            [$nScope, $nParams] = BereichScope::nuPredicate(request()->user(), 'nachunternehmer');
            $nu = DB::connection('tenant')->selectOne("
                SELECT * FROM nachunternehmer WHERE {$whereSql} AND {$nScope} ORDER BY aktiv DESC LIMIT 1
            ", array_merge($params, $nParams));
        }
        if (!$nu || !BereichScope::allowsNu(request()->user(), $nu->nu_id)) {
            return ['error' => 'Kein Nachunternehmer gefunden.'];
        }

        $workers = DB::connection('tenant')->select("
            SELECT id, vorname, name, personal_nr, qualifikation, aktiv
              FROM nu_portal_mitarbeiter WHERE nu_id = ? ORDER BY name LIMIT 30
        ", [$nu->nu_id]);

        $activity = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS einsaetze_90t,
                   SUM(CASE WHEN hms_bestaetigt='ja' THEN 1 ELSE 0 END) AS bestaetigt_90t
              FROM einsaetze WHERE nu_id = ? AND datum >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        ", [$nu->nu_id]);

        $openPositions = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS offen
              FROM nu_schicht_positionen p
              JOIN einsaetze e ON p.einsatz_id = e.einsatz_id
             WHERE p.nu_id = ? AND e.datum >= CURDATE() AND e.hms_bestaetigt <> 'ja'
        ", [$nu->nu_id]);

        return [
            'nu' => [
                'nu_id'          => $nu->nu_id,
                'firmenname'     => $nu->firmenname,
                'ansprechpartner'=> $nu->ansprechpartner,
                'telefon'        => $nu->telefon1 ?: $nu->mobil,
                'email'          => $nu->email,
                'adresse'        => trim(implode(', ', array_filter([$nu->strasse, trim(($nu->plz ?? '') . ' ' . ($nu->ort ?? ''))]))),
                'bereich'        => $nu->bereich,
                'bereiche'       => $nu->bereiche ? json_decode($nu->bereiche, true) : null,
                'aktiv'          => (bool) $nu->aktiv,
                'portal_aktiv'   => (bool) ($nu->portal_aktiv ?? 0),
                'notiz'          => $nu->notiz,
            ],
            'mitarbeiter'      => $workers,
            'aktivitaet_90t'   => $activity,
            'offene_positionen'=> (int) ($openPositions->offen ?? 0),
        ];
    }
}
