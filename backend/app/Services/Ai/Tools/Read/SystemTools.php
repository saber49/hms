<?php

namespace App\Services\Ai\Tools\Read;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Services\Ai\Tools\ToolRegistry;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System / meta tools — the assistant's "self-knowledge" layer.
 *
 * - system_capabilities: lists every tool the current user may call,
 *   grouped by domain. The model can call this when unsure what it can
 *   do instead of answering "das kann ich nicht".
 * - describe_table: schema introspection for whitelisted tenant tables.
 * - query_table: structured, safe SELECT against whitelisted tables —
 *   the universal fallback so the assistant can answer ANY data
 *   question even when no dedicated tool exists.
 * - bereich_katalog: the tenant's managed Bereich list.
 *
 * query_table is deliberately NOT raw SQL: table + columns are checked
 * against a whitelist, filters are parameter-bound, secret columns are
 * stripped, and BereichScope predicates are applied per table — the
 * same isolation rules the rest of the app uses.
 */
class SystemTools implements ReadToolsProvider
{
    use ToolHelpers;

    /**
     * Tables the assistant may inspect. For each table we declare how
     * BereichScope applies:
     *   'expr'  — SQL expression yielding the row's Bereich; restricted
     *             users only see matching rows (strict).
     *   'pool'  — like 'expr', but untagged rows stay visible (shared
     *             staffing pool semantics: workers/NU without a tag can
     *             be planned by every restricted dispatcher).
     *   null    — no Bereich concept; visible to every tenant user.
     */
    private const TABLES = [
        'kunden'                   => ['expr' => 'kunden.bereich'],
        'objekte'                  => ['expr' => 'objekte.bereich'],
        'artikel'                  => ['expr' => 'artikel.bereich'],
        'mitarbeiter'              => ['pool' => 'mitarbeiter.bereich'],
        'nachunternehmer'          => ['pool' => 'nachunternehmer.bereich'],
        'nu_portal_mitarbeiter'    => ['pool' => "(SELECT n.bereich FROM nachunternehmer n WHERE n.nu_id = nu_portal_mitarbeiter.nu_id)"],
        'einsaetze'                => ['expr' => self::DERIVED_EINSATZ],
        'einsatz_mitarbeiter'      => ['expr' => self::DERIVED_VIA_EINSATZ],
        'zeiterfassung'            => ['expr' => self::DERIVED_VIA_EINSATZ],
        'nu_schicht_positionen'    => ['expr' => self::DERIVED_VIA_EINSATZ],
        'nu_position_zuweisungen'  => ['expr' => self::DERIVED_VIA_POSITION],
        'bestellungen'             => ['expr' => self::DERIVED_BESTELLUNG],
        'bestelleingang'           => ['expr' => self::DERIVED_BESTELLEINGANG],
        'bestelleingang_tage'      => ['expr' => self::DERIVED_VIA_EINGANG],
        'mitarbeiter_abwesenheiten'=> ['pool' => "(SELECT m.bereich FROM mitarbeiter m WHERE m.dienst_nr = mitarbeiter_abwesenheiten.dienst_nr)"],
        'bereiche_katalog'         => ['expr' => 'bereiche_katalog.name'],
        'preishistorie'            => null,
        'bestellung_pdfs'          => null,
        'feiertage'                => null,
        'listen'                   => null,
        'ai_feedback'              => null,
    ];

    /** Columns never returned by query_table/describe_table results. */
    private const SECRET_COLUMNS = [
        'password', 'passwort', 'password_hash', 'passwort_hash',
        'portal_password', 'remember_token', 'api_token', 'token',
        'secret', 'api_key', 'dfb_pin', 'private_key',
    ];

    private const DERIVED_EINSATZ = "COALESCE(
        (SELECT NULLIF(TRIM(_a.bereich), '') FROM artikel _a WHERE _a.artikel_id = einsaetze.artikel_id),
        (SELECT NULLIF(TRIM(_k.bereich), '') FROM kunden  _k WHERE _k.kunde_id   = einsaetze.kunden_id)
    )";

    private const DERIVED_VIA_EINSATZ = "(SELECT " . self::DERIVED_EINSATZ . " FROM einsaetze WHERE einsaetze.einsatz_id = %s.einsatz_id)";

    private const DERIVED_VIA_POSITION = "(SELECT " . self::DERIVED_EINSATZ . " FROM nu_schicht_positionen _p JOIN einsaetze ON einsaetze.einsatz_id = _p.einsatz_id WHERE _p.id = nu_position_zuweisungen.position_id)";

    private const DERIVED_BESTELLUNG = "COALESCE(
        (SELECT NULLIF(TRIM(_a.bereich), '') FROM artikel _a WHERE _a.artikel_id = bestellungen.artikel_id),
        (SELECT NULLIF(TRIM(_k.bereich), '') FROM kunden  _k WHERE _k.kunde_id   = bestellungen.kunden_id)
    )";

    private const DERIVED_BESTELLEINGANG = "COALESCE(
        (SELECT NULLIF(TRIM(_a.bereich), '') FROM artikel _a WHERE _a.artikel_id = bestelleingang.artikel_id),
        (SELECT NULLIF(TRIM(_k.bereich), '') FROM kunden  _k WHERE _k.kunde_id   = bestelleingang.kunden_id)
    )";

    private const DERIVED_VIA_EINGANG = "(SELECT " . self::DERIVED_BESTELLEINGANG . " FROM bestelleingang WHERE bestelleingang.eingang_id = bestelleingang_tage.eingang_id)";

    public function handles(string $name): bool
    {
        return in_array($name, [
            'system_capabilities',
            'describe_table',
            'query_table',
            'bereich_katalog',
        ], true);
    }

    public function schemas(): array
    {
        return [
            $this->fn('system_capabilities',
                'Liefert eine Übersicht ALLER Fähigkeiten des Assistenten für diesen Nutzer: '
                . 'welche Datenbereiche gelesen und welche Aktionen geschrieben werden können. '
                . 'Nutze dieses Tool wenn du unsicher bist, ob du etwas kannst — NIEMALS "das kann ich nicht" sagen ohne es geprüft zu haben.',
                [], []),

            $this->fn('describe_table',
                'Zeigt die Spalten (Name, Typ) einer Datenbanktabelle und die ungefähre Zeilenzahl. '
                . 'Nutze es zusammen mit query_table, wenn du eine Frage beantworten musst, für die es kein spezialisiertes Tool gibt.',
                [
                    'table' => ['type' => 'string', 'description' => 'Tabellenname, z.B. "einsaetze", "mitarbeiter", "bestellungen"'],
                ], ['table']),

            $this->fn('query_table',
                'Universelle Lese-Abfrage auf eine Tabelle — der Fallback für JEDE Datenfrage ohne eigenes Tool. '
                . 'Nur SELECT, nur erlaubte Tabellen, max. 100 Zeilen, sensible Spalten (Passwörter/Tokens) werden automatisch entfernt. '
                . 'Bereich-Einschränkungen des Nutzers werden automatisch angewendet. '
                . 'BEISPIEL "Welche Einsätze hat Kunde K028 im Mai?": '
                . '{"table":"einsaetze","where":[{"column":"kunden_id","op":"=","value":"K028"},{"column":"datum","op":"like","value":"2026-05%"}],"limit":50}',
                [
                    'table'    => ['type' => 'string', 'description' => 'Pflicht — siehe describe_table/system_capabilities'],
                    'columns'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'optional — Spaltenliste, sonst alle nicht-sensiblen'],
                    'where'    => [
                        'type' => 'array',
                        'description' => 'optional — Liste von Bedingungen, alle werden UND-verknüpft',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'column' => ['type' => 'string', 'description' => 'Pflicht — muss echte Spalte der Tabelle sein'],
                                'op'     => ['type' => 'string', 'enum' => ['=','!=','>','>=','<','<=','like','in','null','notnull'], 'description' => 'Default "="'],
                                'value'  => ['type' => 'string', 'description' => 'Vergleichswert (Zahlen als Text sind ok); bei like darf % als Platzhalter genutzt werden'],
                                'values' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'nur bei op="in" — Liste der Werte'],
                            ],
                            'required' => ['column'],
                        ],
                    ],
                    'order_by' => ['type' => 'string', 'description' => 'optional — echte Spalte'],
                    'order_dir'=> ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'optional, default asc'],
                    'limit'    => ['type' => 'integer', 'description' => 'optional, 1–100, default 25'],
                ], ['table']),

            $this->fn('bereich_katalog',
                'Listet den Bereiche-Katalog des Mandanten (Hauptbereiche und Unterbereiche/Objektarten).',
                [], []),
        ];
    }

    public function execute(string $name, array $args): array
    {
        return match ($name) {
            'system_capabilities' => $this->systemCapabilities(),
            'describe_table'      => $this->describeTable($args),
            'query_table'         => $this->queryTable($args),
            'bereich_katalog'     => $this->bereichKatalog(),
            default               => ['error' => "Unknown tool: {$name}"],
        };
    }

    // ---------------------------------------------------------------
    // system_capabilities — the assistant's self-knowledge
    // ---------------------------------------------------------------
    private function systemCapabilities(): array
    {
        $user = request()->user();
        $schemas = app(ToolRegistry::class)->schemasForUser($user);

        $domains = [
            'kennzahlen'     => 'KPIs, Umsätze, Top-Listen, Monatsabschluss, Tagesübersicht',
            'suche'          => 'Kunden / Mitarbeiter / Objekte / NU / Artikel / Bestellungen finden',
            'dispo'          => 'Einsätze, Besetzung, Warnungen, Live-Zeiterfassung',
            'mitarbeiter'    => 'Stammdaten, Abwesenheiten, Arbeitszeiten, Verfügbarkeit',
            'auftraege'      => 'Bestelleingang, Bestellungen, Abrechnung (PDF/Controlling-Zahlen)',
            'stammdaten'     => 'Kunden-, Objekt-, Artikel-, NU-Pflege',
            'abrechnung'     => 'Abrechnungsdaten exakt wie Kunden-PDF und Controlling',
            'export'         => 'CSV/XLSX-Exporte',
            'system'         => 'Schema-Intropektion und universelle Abfragen',
        ];

        $byDomain = [
            'kennzahlen'  => ['get_kpi','count_einsaetze','top_kunden','top_objekte','top_nu','monatsabschluss','today_overview'],
            'suche'       => ['search_kunde','search_mitarbeiter','search_objekt','search_nu','search_artikel','search_bestellung'],
            'dispo'       => ['list_offene_einsaetze','disposition_uebersicht','einsatz_details','einsatz_mitarbeiter','dispo_warnungen','zeiterfassung_live'],
            'mitarbeiter' => ['mitarbeiter_count','mitarbeiter_abwesenheiten','mitarbeiter_arbeitszeit','mitarbeiter_einsaetze','verfuegbare_mitarbeiter','create_worker','update_worker','create_abwesenheit','cancel_abwesenheit'],
            'auftraege'   => ['aktive_bestellungen','bestelleingang_status','bestellungen_status','bestellung_details','create_bestelleingang','create_bestellung','update_bestellung','create_einsatz','update_einsatz','update_einsatz_zeit','bulk_create_einsaetze','assign_mitarbeiter','remove_mitarbeiter','update_anforderungen'],
            'stammdaten'  => ['create_kunde','update_kunde','create_objekt','update_objekt','create_artikel','update_artikel','create_nu','update_nu'],
            'abrechnung'  => ['abrechnung_bestellung','abrechnung_monat','update_mitarbeiter_zeit','update_nu_position_zeit','set_zeit_freigabe'],
            'export'      => ['export_einsaetze_csv','export_bestelleingang_csv'],
            'system'      => ['system_capabilities','describe_table','query_table','bereich_katalog'],
        ];

        $available = [];
        foreach ($schemas as $s) {
            $n = $s['function']['name'] ?? '';
            if ($n !== '') $available[$n] = true;
        }

        $out = [];
        foreach ($byDomain as $key => $names) {
            $granted = array_values(array_filter($names, fn ($n) => isset($available[$n])));
            if ($granted === []) continue;
            $out[] = [
                'bereich_domain' => $key,
                'zweck'          => $domains[$key],
                'tools'          => $granted,
            ];
        }

        return [
            'rolle'  => 'KI-Assistent mit Lese- und (nach Nutzer-Bestätigung) Schreibzugriff auf diesen Mandanten',
            'regeln' => [
                'Lesen: dedizierte Tools bevorzugen; query_table/describe_table als universeller Fallback.',
                'Schreiben: nur über *_Tools mit Bestätigungs-Dialog — niemals behaupten etwas sei geändert bevor der Nutzer bestätigt hat.',
                'Tabellen für query_table: ' . implode(', ', array_keys(self::TABLES)),
            ],
            'faehigkeiten' => $out,
        ];
    }

    // ---------------------------------------------------------------
    // describe_table / query_table — universal data access
    // ---------------------------------------------------------------
    private function describeTable(array $a): array
    {
        $table = strtolower(trim((string) ($a['table'] ?? '')));
        if (!isset(self::TABLES[$table])) {
            return [
                'error' => "Tabelle '{$table}' ist nicht erlaubt.",
                'erlaubte_tabellen' => array_keys(self::TABLES),
            ];
        }

        $cols = DB::connection('tenant')->select("SHOW COLUMNS FROM `{$table}`");
        $count = (int) (DB::connection('tenant')
            ->selectOne("SELECT COUNT(*) AS c FROM `{$table}`")->c ?? 0);

        $fields = [];
        foreach ($cols as $c) {
            $fields[] = [
                'column' => $c->Field,
                'type'   => $c->Type,
                'null'   => $c->Null === 'YES',
                'key'    => $c->Key ?: null,
                'sensitiv' => in_array(strtolower($c->Field), self::SECRET_COLUMNS, true) ?: null,
            ];
        }

        return [
            'table'    => $table,
            'zeilen'   => $count,
            'spalten'  => $fields,
            'hinweis'  => 'query_table nutzt diese Spaltennamen in where/columns/order_by.',
        ];
    }

    private function queryTable(array $a): array
    {
        $user  = request()->user();
        $table = strtolower(trim((string) ($a['table'] ?? '')));
        if (!isset(self::TABLES[$table])) {
            return [
                'error' => "Tabelle '{$table}' ist nicht erlaubt.",
                'erlaubte_tabellen' => array_keys(self::TABLES),
            ];
        }

        // Real column list — columns + where/order_by are validated
        // against it so nothing the LLM sends can become SQL.
        $realCols = [];
        foreach (DB::connection('tenant')->select("SHOW COLUMNS FROM `{$table}`") as $c) {
            if (!in_array(strtolower($c->Field), self::SECRET_COLUMNS, true)) {
                $realCols[$c->Field] = true;
            }
        }
        if ($realCols === []) {
            return ['error' => "Keine lesbaren Spalten in {$table}."];
        }

        // --- SELECT list -------------------------------------------------
        $select = array_keys($realCols);
        if (!empty($a['columns']) && is_array($a['columns'])) {
            $select = [];
            foreach ($a['columns'] as $col) {
                $col = (string) $col;
                if (isset($realCols[$col])) {
                    $select[] = $col;
                }
            }
            if ($select === []) {
                return ['error' => 'Keine der angefragten Spalten existiert (oder ist sensibel).'];
            }
        }
        $selectSql = implode(', ', array_map(fn ($c) => "`{$c}`", $select));

        // --- WHERE --------------------------------------------------------
        $where  = ['1=1'];
        $params = [];
        $ops = ['=','!=','>','>=','<','<=','like','in','null','notnull'];
        foreach ((array) ($a['where'] ?? []) as $cond) {
            if (!is_array($cond)) continue;
            $col = (string) ($cond['column'] ?? '');
            if (!isset($realCols[$col])) {
                return ['error' => "Spalte '{$col}' existiert nicht in {$table}."];
            }
            $op = strtolower((string) ($cond['op'] ?? '='));
            if (!in_array($op, $ops, true)) {
                return ['error' => "Operator '{$op}' nicht erlaubt."];
            }
            $v = $cond['value'] ?? null;
            switch ($op) {
                case 'null':    $where[] = "`{$col}` IS NULL"; break;
                case 'notnull': $where[] = "`{$col}` IS NOT NULL"; break;
                case 'in':
                    $rawVals = $cond['values'] ?? $v;
                    $vals = array_values(array_filter((array) $rawVals, fn ($x) => is_scalar($x)));
                    if ($vals === []) return ['error' => "IN-Liste für '{$col}' ist leer."];
                    $where[] = "`{$col}` IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
                    foreach ($vals as $x) $params[] = $x;
                    break;
                case 'like':
                    $where[] = "`{$col}` LIKE ?";
                    $params[] = (string) $v;
                    break;
                default:
                    $where[] = "`{$col}` {$op} ?";
                    $params[] = is_numeric($v) ? $v + 0 : (string) $v;
            }
        }

        // --- Bereich scope ------------------------------------------------
        $scope = self::TABLES[$table];
        if ($scope !== null) {
            if (isset($scope['expr'])) {
                $expr = $scope['expr'];
                if (str_contains($expr, '%s')) {
                    $expr = sprintf($expr, $table);
                }
                BereichScope::applySql($user, $expr, $where, $params);
            } elseif (isset($scope['pool'])) {
                $expr = $scope['pool'];
                if (str_contains($expr, '%s')) {
                    $expr = sprintf($expr, $table);
                }
                BereichScope::applySqlOrEmpty($user, $expr, $where, $params);
            }
        }

        // --- ORDER / LIMIT -------------------------------------------------
        $orderSql = '';
        $orderBy = trim((string) ($a['order_by'] ?? ''));
        if ($orderBy !== '') {
            if (!isset($realCols[$orderBy])) {
                return ['error' => "order_by-Spalte '{$orderBy}' existiert nicht."];
            }
            $dir = strtolower((string) ($a['order_dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $orderSql = " ORDER BY `{$orderBy}` {$dir}";
        }
        $limit = max(1, min(100, (int) ($a['limit'] ?? 25)));

        $rows = DB::connection('tenant')->select(
            "SELECT {$selectSql} FROM `{$table}` WHERE " . implode(' AND ', $where)
            . "{$orderSql} LIMIT {$limit}",
            $params
        );

        return [
            'table'   => $table,
            'count'   => count($rows),
            'limit'   => $limit,
            'gekuerzt'=> count($rows) === $limit,
            'rows'    => $rows,
        ];
    }

    // ---------------------------------------------------------------
    // bereich_katalog
    // ---------------------------------------------------------------
    private function bereichKatalog(): array
    {
        if (!Schema::connection('tenant')->hasTable('bereiche_katalog')) {
            return ['bereiche' => []];
        }
        $rows = DB::connection('tenant')->table('bereiche_katalog')
            ->where('aktiv', 1)
            ->orderBy('sortierung')->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        // Restricted users see only their whitelisted Bereiche —
        // same rule as BereichCatalogController ("read: scope-filtered").
        $allowed = BereichScope::for(request()->user());
        $mains = [];
        $subs  = [];
        foreach ($rows as $r) {
            if ($r->parent_id === null) {
                if (BereichScope::listAllows($allowed, $r->name)) {
                    $mains[$r->id] = $r->name;
                }
            } else {
                $subs[$r->parent_id][] = $r->name;
            }
        }
        $out = [];
        foreach ($mains as $id => $name) {
            $out[] = [
                'name' => $name,
                'unterbereiche' => $subs[$id] ?? [],
            ];
        }
        return ['bereiche' => $out];
    }
}
