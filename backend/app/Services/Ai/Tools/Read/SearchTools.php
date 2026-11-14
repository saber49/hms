<?php

namespace App\Services\Ai\Tools\Read;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SearchTools implements ReadToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'search_kunde' => true,
            'search_mitarbeiter' => true,
            'search_objekt' => true,
            'search_nu' => true,
            'search_artikel' => true,
            'search_bestellung' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('search_kunde',
                'Sucht einen Kunden nach Name. Liefert Stammdaten, Anzahl Objekte und letzte Einsatz-Aktivität.',
                ['query' => ['type' => 'string']], ['query']),

            $this->fn('search_mitarbeiter',
                'Sucht einen internen Mitarbeiter nach Name oder Dienstnummer. Liefert Stammdaten inkl. Stundenlohn falls hinterlegt.',
                ['query' => ['type' => 'string']], ['query']),

            $this->fn('search_objekt',
                'Sucht ein Objekt nach Name. Liefert Adresse, Kunde, Anzahl Einsätze in den letzten 30 Tagen.',
                ['query' => ['type' => 'string']], ['query']),

            $this->fn('search_nu',
                'Sucht einen Nachunternehmer (NU/Subunternehmer) nach Firmenname oder Ansprechpartner. Liefert Stammdaten und Einsatz-Aktivität der letzten 90 Tage.',
                ['query' => ['type' => 'string']], ['query']),

            $this->fn('search_artikel',
                'Sucht einen Artikel/Leistung nach Name oder ID. Liefert Abrechnungsmodell, Kundenpreis, NU-Preis, zugehöriges Objekt und Kunde sowie Bereich.',
                ['query' => ['type' => 'string']], ['query']),

            $this->fn('search_bestellung',
                'Sucht eine Bestellung (Auftrag) nach Bestell-ID, Kundenname oder Objektname. Liefert ID, Kunde, Objekt, Zeitraum, Status und Anzahl Einsätze.',
                ['query' => ['type' => 'string']], ['query']),
        ];
    }

    public function execute(string $name, array $args): array
    {
        return match ($name) {
                'search_kunde' => $this->searchKunde($args),
                'search_mitarbeiter' => $this->searchMitarbeiter($args),
                'search_objekt' => $this->searchObjekt($args),
                'search_nu' => $this->searchNu($args),
                'search_artikel' => $this->searchArtikel($args),
                'search_bestellung' => $this->searchBestellung($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function searchKunde(array $a): array
    {
        $q = $this->searchQueryArg($a);
        if ($q === '') return ['error' => 'query is required'];

        // Haystack includes the kunde_id so a bare "K034" also matches —
        // users paste IDs from the UI at least as often as names.
        [$whereSql, $params] = $this->tokenizedLike(
            "CONCAT_WS(' ', COALESCE(k.kundenname,''), COALESCE(k.kunde_id,''))",
            $q
        );
        [$scope, $scopeParams] = BereichScope::kundePredicate(request()->user(), 'k');

        $rows = DB::connection('tenant')->select("
            SELECT k.kunde_id, k.kundenname, k.bereich, k.email,
                   COALESCE(NULLIF(k.telefon1,''), k.mobil) AS telefon,
                   k.ort, k.plz,
                   (SELECT COUNT(*) FROM objekte o WHERE o.kunden_id=k.kunde_id) AS objekte_count,
                   (SELECT COUNT(*) FROM einsaetze e
                     WHERE e.kunden_id=k.kunde_id
                       AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) AS einsaetze_letzte_90t
              FROM kunden k
             WHERE {$whereSql} AND {$scope}
             ORDER BY k.kundenname
             LIMIT 5
        ", array_merge($params, $scopeParams));

        return ['ergebnisse' => $rows ?: []];
    }

    private function searchMitarbeiter(array $a): array
    {
        $q = $this->searchQueryArg($a);
        if ($q === '') return ['error' => 'query is required'];

        $hasStundenlohn = \Schema::connection('tenant')->hasColumn('mitarbeiter', 'stundenlohn');
        $select = "m.dienst_nr, m.vorname, m.name, m.telefon, m.email, m.bereich,
                   m.art_der_beschaeftigung, m.aktiv, m.arbeitsstunden, m.arbeitsstunden_typ";
        if ($hasStundenlohn) $select .= ", m.stundenlohn";

        // Token-aware search (see tokenizedLike()) — makes "Sahil Ahmadi"
        // match a row where vorname='Sahil' and name='Ahmadi'.
        $haystack = "CONCAT_WS(' ', COALESCE(m.vorname,''), COALESCE(m.name,''), COALESCE(m.dienst_nr,''))";
        [$whereSql, $params] = $this->tokenizedLike($haystack, $q);
        [$scope, $scopeParams] = BereichScope::workerPredicate(request()->user(), 'm');

        $rows = DB::connection('tenant')->select("
            SELECT {$select}
              FROM mitarbeiter m
             WHERE {$whereSql} AND {$scope}
             ORDER BY m.aktiv DESC, m.name ASC
             LIMIT 5
        ", array_merge($params, $scopeParams));

        return ['ergebnisse' => $rows ?: []];
    }

    private function searchObjekt(array $a): array
    {
        $q = $this->searchQueryArg($a);
        if ($q === '') return ['error' => 'query is required'];

        [$whereSql, $params] = $this->tokenizedLike(
            "CONCAT_WS(' ', COALESCE(o.objektname,''), COALESCE(o.stadt,''), COALESCE(o.adresse,''), COALESCE(o.obj_id,''))",
            $q
        );
        [$scope, $scopeParams] = BereichScope::objektPredicate(request()->user(), 'o');

        $rows = DB::connection('tenant')->select("
            SELECT o.obj_id, o.objektname, o.adresse AS strasse, o.plz, o.stadt AS ort,
                   k.kundenname,
                   (SELECT COUNT(*) FROM einsaetze e
                     WHERE e.obj_id=o.obj_id
                       AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS einsaetze_30t
              FROM objekte o
              LEFT JOIN kunden k ON o.kunden_id=k.kunde_id
             WHERE {$whereSql} AND {$scope}
             ORDER BY o.objektname
             LIMIT 5
        ", array_merge($params, $scopeParams));

        return ['ergebnisse' => $rows ?: []];
    }

    /**
     * NU (subcontractor) search — mirrors searchKunde but on the
     * nachunternehmer table. Includes 90-day einsatz activity so the
     * LLM can answer "is this NU still active?" without a second call.
     */
    private function searchNu(array $a): array
    {
        $q = $this->searchQueryArg($a);
        if ($q === '') return ['error' => 'query is required'];

        [$whereSql, $params] = $this->tokenizedLike(
            "CONCAT_WS(' ', COALESCE(n.firmenname,''), COALESCE(n.ansprechpartner,''), COALESCE(n.nu_id,''))",
            $q
        );
        [$scope, $scopeParams] = BereichScope::nuPredicate(request()->user(), 'n');

        $rows = DB::connection('tenant')->select("
            SELECT n.nu_id, n.firmenname, n.ansprechpartner, n.bereich,
                   COALESCE(NULLIF(n.telefon1,''), n.mobil) AS telefon,
                   n.email, n.ort, n.plz, n.aktiv,
                   (SELECT COUNT(*) FROM einsaetze e
                     WHERE e.nu_id = n.nu_id
                       AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) AS einsaetze_letzte_90t
              FROM nachunternehmer n
             WHERE {$whereSql} AND {$scope}
             ORDER BY n.aktiv DESC, n.firmenname
             LIMIT 5
        ", array_merge($params, $scopeParams));

        return ['ergebnisse' => $rows ?: []];
    }

    /**
     * Tolerant search-argument reader — the LLM regularly sends 'name',
     * 'id' or the entity ID key instead of the documented 'query'.
     */
    private function searchQueryArg(array $a): string
    {
        foreach (['query', 'name', 'id', 'kunde_id', 'obj_id', 'nu_id', 'dienst_nr', 'artikel_id', 'bestell_id'] as $k) {
            $v = trim((string) ($a[$k] ?? ''));
            if ($v !== '') return $v;
        }
        return '';
    }

    /**
     * Article/service search — needed whenever the LLM must resolve an
     * Artikel-ID before create/update calls (prices, model, NU price
     * and owning objekt+kunde ride along so the model does not need a
     * second lookup round-trip).
     */
    private function searchArtikel(array $a): array
    {
        $q = $this->searchQueryArg($a);
        if ($q === '') return ['error' => 'query is required'];

        [$like, $params] = $this->tokenizedLike(
            "CONCAT_WS(' ', COALESCE(a.artikel_name,''), COALESCE(a.artikel_id,''), COALESCE(a.bereich,''))",
            $q
        );
        [$scope, $scopeParams] = BereichScope::artikelPredicate(request()->user(), 'a');

        $rows = DB::connection('tenant')->select("
            SELECT a.artikel_id, a.artikel_name, a.modell, a.einheit,
                   a.preis_kunde, a.nu_preis, a.bereich, a.aktiv,
                   a.obj_id, o.objektname,
                   k.kunde_id, k.kundenname
              FROM artikel a
              LEFT JOIN objekte o ON a.obj_id = o.obj_id
              LEFT JOIN kunden  k ON o.kunden_id = k.kunde_id
             WHERE {$like} AND {$scope}
             ORDER BY a.aktiv DESC, a.artikel_name
             LIMIT 8
        ", array_merge($params, $scopeParams));

        return ['ergebnisse' => $rows ?: []];
    }

    /**
     * Order search — resolves a Bestellung by ID fragment, customer or
     * object name. Includes einsatz counts so the model can answer
     * "welche Bestellung gehört zum Einsatz E-…?" chains.
     */
    private function searchBestellung(array $a): array
    {
        $q = $this->searchQueryArg($a);
        if ($q === '') return ['error' => 'query is required'];

        [$like, $params] = $this->tokenizedLike(
            "CONCAT_WS(' ', COALESCE(b.bestell_id,''), COALESCE(b.eingang_id,''), COALESCE(k.kundenname,''), COALESCE(o.objektname,''))",
            $q
        );
        $where  = [$like];
        BereichScope::applySql(request()->user(), BereichScope::derivedExpr('a', 'k'), $where, $params);

        $rows = DB::connection('tenant')->select("
            SELECT b.bestell_id, b.eingang_id, b.status,
                   b.datum_von, b.datum_bis, b.zeit_von, b.zeit_bis,
                   b.kunden_id, k.kundenname,
                   b.obj_id, o.objektname,
                   b.artikel_id, a.artikel_name,
                   (SELECT COUNT(*) FROM einsaetze e WHERE e.bestell_id = b.bestell_id) AS einsaetze,
                   (SELECT COUNT(*) FROM einsaetze e2 WHERE e2.bestell_id = b.bestell_id AND e2.hms_bestaetigt='ja') AS bestaetigt
              FROM bestellungen b
              LEFT JOIN kunden  k ON b.kunden_id = k.kunde_id
              LEFT JOIN objekte o ON b.obj_id   = o.obj_id
              LEFT JOIN artikel a ON b.artikel_id = a.artikel_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY b.datum_von DESC
             LIMIT 8
        ", $params);

        return ['ergebnisse' => $rows ?: []];
    }
}
