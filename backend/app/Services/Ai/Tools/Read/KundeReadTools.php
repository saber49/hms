<?php

namespace App\Services\Ai\Tools\Read;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class KundeReadTools implements ReadToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'kunde_details' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('kunde_details',
                'Detaillierte Übersicht zu einem Kunden: Objekte, Einsätze in den letzten 90 Tagen, Einnahmen, Top-Objekte.',
                [
                    'query' => ['type' => 'string', 'description' => 'Teil des Kundennamens'],
                ], ['query']),
        ];
    }

    public function execute(string $name, array $args): array
    {
        return match ($name) {
                'kunde_details' => $this->kundeDetails($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function kundeDetails(array $a): array
    {
        $q = trim((string) ($a['query'] ?? ''));
        if ($q === '') return ['error' => 'query is required'];

        [$whereSql, $kparams] = $this->tokenizedLike('kundenname', $q);
        [$scope, $scopeParams] = BereichScope::kundePredicate(request()->user(), 'kunden');
        $kunde = DB::connection('tenant')->selectOne("
            SELECT kunde_id, kundenname, bereich, email,
                   COALESCE(NULLIF(telefon1,''), mobil) AS telefon,
                   ort, plz, strasse, haupt_ap
              FROM kunden
             WHERE {$whereSql} AND {$scope}
             ORDER BY kundenname
             LIMIT 1
        ", array_merge($kparams, $scopeParams));
        if (!$kunde) return ['error' => 'Kein Kunde gefunden'];

        // Objects + last-90d activity per object (top 5)
        $objects = DB::connection('tenant')->select("
            SELECT o.obj_id, o.objektname,
                   (SELECT COUNT(*) FROM einsaetze e
                     WHERE e.obj_id=o.obj_id
                       AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) AS einsaetze_90t,
                   (SELECT COALESCE(SUM(a.preis_kunde*(1+COALESCE(e2.zuschlag_pct,0)/100)*COALESCE(e2.ist_std_menge,0)),0)
                      FROM einsaetze e2 LEFT JOIN artikel a ON e2.artikel_id=a.artikel_id
                     WHERE e2.obj_id=o.obj_id
                       AND e2.hms_bestaetigt='ja'
                       AND e2.datum >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) AS einnahmen_90t
              FROM objekte o
             WHERE o.kunden_id = ?
          ORDER BY einnahmen_90t DESC
             LIMIT 5
        ", [$kunde->kunde_id]);

        // Totals (last 90 days)
        $row = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS einsaetze,
                   COALESCE(SUM(a.preis_kunde*(1+COALESCE(e.zuschlag_pct,0)/100)*COALESCE(e.ist_std_menge,0)),0) AS einnahmen
              FROM einsaetze e
              LEFT JOIN artikel a ON e.artikel_id=a.artikel_id
             WHERE e.kunden_id = ?
               AND e.hms_bestaetigt='ja'
               AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        ", [$kunde->kunde_id]);

        return [
            'kunde' => [
                'id'         => $kunde->kunde_id,
                'name'       => $kunde->kundenname,
                'bereich'    => $kunde->bereich,
                'email'      => $kunde->email,
                'telefon'    => $kunde->telefon,
                'adresse'    => trim(($kunde->strasse ?? '') . ', ' . ($kunde->plz ?? '') . ' ' . ($kunde->ort ?? ''), ', '),
                'haupt_ansprechpartner' => $kunde->haupt_ap,
            ],
            'letzte_90_tage' => [
                'einsaetze'      => (int) $row->einsaetze,
                'einnahmen_eur'  => round((float) $row->einnahmen, 2),
            ],
            'top_objekte_90t' => array_map(fn($o) => [
                'objektname'     => $o->objektname,
                'einsaetze'      => (int) $o->einsaetze_90t,
                'einnahmen_eur'  => round((float) $o->einnahmen_90t, 2),
            ], $objects),
        ];
    }
}
