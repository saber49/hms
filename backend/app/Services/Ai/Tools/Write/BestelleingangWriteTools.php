<?php

namespace App\Services\Ai\Tools\Write;

use App\Helpers\IdGenerator;
use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\WriteToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BestelleingangWriteTools implements WriteToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'create_bestelleingang' => true,
            'update_anforderungen' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('create_bestelleingang',
                'Legt einen NEUEN Bestelleingang (eingehenden Auftrag) MIT Tagen an. '
                . 'Erzeugt in EINER Transaktion: 1) Eintrag in `bestelleingang` (BE-YYYYMM-NNNN), '
                . '2) automatisch verknüpfte Bestellung (B-YYYYMM-NNNN), '
                . '3) automatisch alle Einsätze in der Disposition (einer pro Tag). '
                . 'Nutze diesen Tool wenn der Nutzer einen neuen Auftrag/Bestelleingang/Bestellungseingang erfassen möchte und Tage/Schichten dabei sind. '
                . 'NICHT verwenden wenn nur eine reine Bestellung (ohne Tage) angelegt werden soll — dafür ist `create_bestellung`. '
                . 'WICHTIG: Rufe diese Funktion GENAU EINMAL auf und packe ALLE Tage in das `tage[]`-Array. '
                . 'NIEMALS einen Aufruf pro Tag. '
                . 'WICHTIG — AGGREGATION: Ein Eintrag in `tage[]` = EIN Tag/Schicht, NICHT ein Mitarbeiter! '
                . 'Wenn mehrere Mitarbeiter am SELBEN Tag zur SELBEN Zeit arbeiten (z.B. eine Einsatzliste mit 9 Personen am 29.08.), '
                . 'erzeuge EINEN Tag mit menge=9 — NIEMALS 9 separate Tage mit menge=1. '
                . 'Geschlechter-Verteilung (z.B. 8 männlich, 1 weiblich) gibst du pro Tag über `maennlich`/`weiblich` an. '
                . 'BEISPIEL für 9 Personen (8 M, 1 W) an einem Tag + 2 Personen am Folgetag: '
                . '{"kunden_id":"K028","obj_id":"O006","monat":8,"jahr":2026,"tage":['
                . '{"datum":"2026-08-29","zeit_von":"12:00","zeit_bis":"18:00","beschreibung":"Veranstaltungsschutz","menge":9,"maennlich":8,"weiblich":1},'
                . '{"datum":"2026-08-30","zeit_von":"07:00","zeit_bis":"18:00","beschreibung":"Tunnel","menge":2}'
                . ']}',
                [
                    'kunden_id'   => ['type' => 'string', 'description' => 'Pflicht — z.B. "K028". MUSS der Kunde sein, dem das Objekt gehört (prüfe mit search_objekt).'],
                    'obj_id'      => ['type' => 'string', 'description' => 'Pflicht — z.B. "O006". Das Objekt MUSS zum angegebenen Kunden gehören, sonst wird abgelehnt.'],
                    'monat'       => ['type' => 'integer', 'description' => 'Pflicht — 1-12'],
                    'jahr'        => ['type' => 'integer', 'description' => 'Pflicht — z.B. 2026'],
                    'artikel_id'  => ['type' => 'string', 'description' => 'Hat das Objekt genau EINEN aktiven Artikel, wird er (inkl. Modell + Preis) automatisch übernommen. Bei MEHREREN Artikeln lehnt das Tool ab und listet die Auswahl — frage dann den Nutzer, welcher Artikel gelten soll, und rufe erneut auf.'],
                    'zeit_von'    => ['type' => 'string', 'description' => 'HH:MM (Default-Startzeit für Tage ohne eigene Zeit)'],
                    'zeit_bis'    => ['type' => 'string', 'description' => 'HH:MM'],
                    'modell'      => ['type' => 'string', 'enum' => ['Standard', 'Pro Einsatz', 'Pro Stunde', 'Monatspauschale']],
                    'kundenpreis' => ['type' => 'number'],
                    'nu_id'       => ['type' => 'string'],
                    'nu_preis'    => ['type' => 'number'],
                    'bemerkung'   => ['type' => 'string'],
                    'tage'        => [
                        'type'        => 'array',
                        'description' => 'Pflicht — alle Tage des Bestelleingangs. Pro Tag wird automatisch ein Einsatz in der Disposition angelegt.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'datum'         => ['type' => 'string', 'description' => 'Pflicht — YYYY-MM-DD oder DD.MM.YYYY'],
                                'zeit_von'      => ['type' => 'string', 'description' => 'HH:MM (überschreibt Default oben)'],
                                'zeit_bis'      => ['type' => 'string', 'description' => 'HH:MM'],
                                'soll_std'      => ['type' => 'number'],
                                'pause_minuten' => ['type' => 'integer'],
                                'gesetzl_pause' => ['type' => 'integer'],
                                'menge'         => ['type' => 'number', 'description' => 'Anzahl Mitarbeiter / Einheiten für diesen Tag (z.B. 9 wenn 9 Personen gleichzeitig arbeiten)'],
                                'maennlich'     => ['type' => 'integer', 'description' => 'Anzahl männliche Mitarbeiter (Anforderung für Dispo)'],
                                'weiblich'      => ['type' => 'integer', 'description' => 'Anzahl weibliche Mitarbeiter (Anforderung für Dispo)'],
                                'beschreibung'  => ['type' => 'string'],
                                'bemerkung'     => ['type' => 'string'],
                                'ort'           => ['type' => 'string', 'description' => 'Einsatzort/Adresse (z.B. "Red Bull Arena, Leipzig"). Aus dem Dokument extrahieren: Treffpunkt, Veranstaltungsort, Adresse. Steht KEIN Ort im Dokument, frage den Nutzer VOR dem Tool-Aufruf, ob ein Einsatzort gesetzt werden soll.'],
                                'qualifikation' => ['type' => 'string', 'description' => 'Geforderte Qualifikation der Mitarbeiter (z.B. "§34a Sachkunde", "QuaSOD", "Erste Hilfe") — aus dem Dokument extrahieren, für die Dispo sichtbar'],
                                'kleidung'      => ['type' => 'string', 'description' => 'Kleidungs-Vorgabe (z.B. "schwarze Hose, weißes Hemd")'],
                                'skill'         => ['type' => 'string', 'description' => 'Sonstige Fähigkeiten/Anforderungen (z.B. "Englisch", "Führerschein")'],
                            ],
                            'required' => ['datum'],
                        ],
                    ],
                ], ['kunden_id', 'obj_id', 'monat', 'jahr', 'tage']),

            $this->fn('update_anforderungen',
                'Ändert die Personal-Anforderungen (Geschlechter-Verteilung, Qualifikation, Kleidung, Skill, optional Menge) '
                . 'eines BESTEHENDEN Bestelleingangs, einer Bestellung oder eines einzelnen Einsatzes. '
                . 'Akzeptiert als id: BE-… (Bestelleingang, ändert ALLE Tage), B-… (Bestellung, ändert alle Einsätze) '
                . 'oder E-… (einzelner Einsatz). Mit datum kann auf einen bestimmten Tag eingeschränkt werden. '
                . 'Nutze dieses Tool wenn der Nutzer z.B. "8 männlich, 1 weiblich" oder "alle brauchen §34a" '
                . 'an einer bereits angelegten Bestellung korrigieren möchte. '
                . 'Leerer String bei qualifikation/kleidung/skill LÖSCHT das jeweilige Feld.',
                [
                    'id'            => ['type' => 'string', 'description' => 'Pflicht — BE-…, B-… oder E-…'],
                    'datum'         => ['type' => 'string', 'description' => 'optional — YYYY-MM-DD, nur diesen Tag ändern'],
                    'maennlich'     => ['type' => 'integer', 'description' => 'Anzahl männliche Mitarbeiter'],
                    'weiblich'      => ['type' => 'integer', 'description' => 'Anzahl weibliche Mitarbeiter'],
                    'menge'         => ['type' => 'number', 'description' => 'optional — neue Gesamt-Anzahl Mitarbeiter'],
                    'qualifikation' => ['type' => 'string', 'description' => 'Geforderte Qualifikation (z.B. "§34a Sachkunde") — leerer String löscht'],
                    'kleidung'      => ['type' => 'string', 'description' => 'Kleidungs-Vorgabe — leerer String löscht'],
                    'skill'         => ['type' => 'string', 'description' => 'Sonstige Fähigkeiten — leerer String löscht'],
                ], ['id']),
        ];
    }

    public function preview(string $name, array $args): array
    {
        return match ($name) {
            'create_bestelleingang' => $this->previewCreateBestelleingang($args),
            'update_anforderungen' => $this->previewUpdateAnforderungen($args),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    public function execute(string $name, array $args, int $userId): array
    {
        return match ($name) {
            'create_bestelleingang' => $this->createBestelleingang($args, $userId),
            'update_anforderungen' => $this->updateAnforderungen($args, $userId),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    private function previewCreateBestelleingang(array $a): array
    {
        $kunde  = $this->resolveExistingId('kunden',  'kunde_id', 'kundenname',
            (string)($a['kunden_id'] ?? ''), 'kunde');
        $objekt = $this->resolveExistingId('objekte', 'obj_id',   'objektname',
            (string)($a['obj_id']    ?? ''), 'objekt');
        $this->assertObjektBelongsToKunde($objekt, $kunde);
        $a = $this->applyArtikelDefaults($a, $objekt->obj_id);
        $this->assertEingangScope($a, $kunde, $objekt);

        $monat = (int)($a['monat'] ?? 0);
        $jahr  = (int)($a['jahr']  ?? 0);
        if ($monat < 1 || $monat > 12 || $jahr < 2000 || $jahr > 2100) {
            throw new \InvalidArgumentException('monat (1-12) und jahr (YYYY) sind Pflicht.');
        }

        $tage = $this->normaliseBestelleingangTage(
            (array)($a['tage'] ?? []),
            $this->str($a, 'zeit_von'),
            $this->str($a, 'zeit_bis'),
        );

        $sample = array_slice($tage, 0, 5);
        $tableRows = [];
        foreach ($sample as $t) {
            $tableRows[] = [
                (string)$t['tag_nr'],
                \Carbon\Carbon::parse($t['datum'])->format('d.m.Y'),
                substr($t['wochentag'] ?? '', 0, 2),
                ($t['zeit_von'] ?? '—') . ' – ' . ($t['zeit_bis'] ?? '—'),
                isset($t['soll_std']) ? number_format((float)$t['soll_std'], 2, ',', '.') . ' Std' : '—',
                isset($t['menge']) ? number_format((float)$t['menge'], 2, ',', '.') : '—',
                ($t['maennlich'] > 0 || $t['weiblich'] > 0) ? "♂{$t['maennlich']} ♀{$t['weiblich']}" : '—',
            ];
        }

        return [
            'titel'  => 'Neuen Bestelleingang anlegen (+ Bestellung + Einsätze)',
            'felder' => [
                ['Kunde',        $kunde->kunde_id . ' — ' . $kunde->kundenname],
                ['Objekt',       $objekt->obj_id . ' — ' . $objekt->objektname],
                ['Zeitraum',     str_pad((string)$monat, 2, '0', STR_PAD_LEFT) . '/' . $jahr],
                ['Artikel',      trim((string)($a['artikel_id'] ?? '')) ?: '—'],
                ['Default-Zeit', trim(($a['zeit_von'] ?? '') . ' – ' . ($a['zeit_bis'] ?? ''), ' –') ?: '—'],
                ['Modell',       trim((string)($a['modell'] ?? '')) ?: '—'],
                ['Kundenpreis',  isset($a['kundenpreis']) ? number_format((float)$a['kundenpreis'], 2, ',', '.') . ' €' : '—'],
                ['Anzahl Tage',  (string)count($tage) . ' (= Anzahl neuer Einsätze in der Dispo)'],
                ['Bemerkung',    trim((string)($a['bemerkung'] ?? '')) ?: '—'],
            ],
            'table'  => [
                'columns' => ['#', 'Datum', 'Wt', 'Zeit', 'Soll', 'Menge', '♂/♀'],
                'rows'    => $tableRows,
            ],
            'hinweis' => count($tage) > 5 ? '… und ' . (count($tage) - 5) . ' weitere Tage' : '',
        ];
    }

    private function createBestelleingang(array $a, int $userId): array
    {
        $kunde  = $this->resolveExistingId('kunden',  'kunde_id', 'kundenname',
            (string)($a['kunden_id'] ?? ''), 'kunde');
        $objekt = $this->resolveExistingId('objekte', 'obj_id',   'objektname',
            (string)($a['obj_id']    ?? ''), 'objekt');
        $this->assertObjektBelongsToKunde($objekt, $kunde);
        $a = $this->applyArtikelDefaults($a, $objekt->obj_id);
        $this->assertEingangScope($a, $kunde, $objekt);

        $monat = (int)($a['monat'] ?? 0);
        $jahr  = (int)($a['jahr']  ?? 0);
        if ($monat < 1 || $monat > 12 || $jahr < 2000 || $jahr > 2100) {
            throw new \InvalidArgumentException('monat (1-12) und jahr (YYYY) sind Pflicht.');
        }

        $tage = $this->normaliseBestelleingangTage(
            (array)($a['tage'] ?? []),
            $this->str($a, 'zeit_von'),
            $this->str($a, 'zeit_bis'),
        );

        $eingang_id = IdGenerator::uniqueId('bestelleingang', 'eingang_id', 'BE-');
        $bestell_id = IdGenerator::uniqueId('bestellungen',   'bestell_id', 'B-');

        $createdEinsaetze = 0;
        $createdEinsatzIds = [];

        DB::connection('tenant')->transaction(function () use (
            &$createdEinsaetze, &$createdEinsatzIds,
            $a, $userId, $kunde, $objekt, $monat, $jahr, $tage, $eingang_id, $bestell_id
        ) {
            // 1) header in bestelleingang
            DB::connection('tenant')->table('bestelleingang')->insert(array_filter([
                'eingang_id'  => $eingang_id,
                'kunden_id'   => $kunde->kunde_id,
                'obj_id'      => $objekt->obj_id,
                'artikel_id'  => $this->str($a, 'artikel_id'),
                'monat'       => $monat,
                'jahr'        => $jahr,
                'zeit_von'    => $this->str($a, 'zeit_von'),
                'zeit_bis'    => $this->str($a, 'zeit_bis'),
                'modell'      => $this->str($a, 'modell'),
                'kundenpreis' => isset($a['kundenpreis']) ? (float)$a['kundenpreis'] : null,
                'nu_id'       => $this->str($a, 'nu_id'),
                'nu_preis'    => isset($a['nu_preis']) ? (float)$a['nu_preis'] : null,
                'bemerkung'   => $this->str($a, 'bemerkung'),
                'status'      => 'unbearbeitet',
                'created_by'  => $userId,
                'updated_by'  => $userId,
                'created_at'  => now(),
            ], fn($v) => $v !== null && $v !== ''));

            // 2) days
            $hasAnfTage = Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'anforderungen');
            foreach ($tage as $t) {
                DB::connection('tenant')->table('bestelleingang_tage')->insert(array_filter([
                    'eingang_id'    => $eingang_id,
                    'tag_nr'        => $t['tag_nr'],
                    'datum'         => $t['datum'],
                    'wochentag'     => $t['wochentag'],
                    'zeit_von'      => $t['zeit_von'],
                    'zeit_bis'      => $t['zeit_bis'],
                    'soll_std'      => $t['soll_std'],
                    'pause_minuten' => $t['pause_minuten'],
                    'gesetzl_pause' => $t['gesetzl_pause'],
                    'menge'         => $t['menge'],
                    'beschreibung'  => $t['beschreibung'],
                    'bemerkung'     => $t['bemerkung'],
                    'ort'           => $t['ort'] ?? null,
                    'anforderungen' => $hasAnfTage ? $this->buildAnforderungenJson($t) : null,
                    'status'        => 'offen',
                    'created_at'    => now(),
                ], fn($v) => $v !== null && $v !== ''));
            }

            // 3) auto-create Bestellung (linked)
            DB::connection('tenant')->table('bestellungen')->insert(array_filter([
                'bestell_id'      => $bestell_id,
                'eingang_id'      => $eingang_id,
                'kunden_id'       => $kunde->kunde_id,
                'obj_id'          => $objekt->obj_id,
                'artikel_id'      => $this->str($a, 'artikel_id'),
                'monat'           => $monat,
                'jahr'            => $jahr,
                'zeit_von'        => $this->str($a, 'zeit_von'),
                'zeit_bis'        => $this->str($a, 'zeit_bis'),
                'status'          => 'offen',
                'nu_id'           => $this->str($a, 'nu_id'),
                'created_at'      => now(),
            ], fn($v) => $v !== null && $v !== ''));

            // 4) auto-create einsaetze (one per day) — same shape that
            //    IncomingOrderController writes, including schichtart.
            $hasSchichtCols = \Illuminate\Support\Facades\Schema::connection('tenant')
                ->hasColumn('einsaetze', 'schichtart');
            $hasAnfEinsatz = Schema::connection('tenant')->hasColumn('einsaetze', 'anforderungen');
            $artikelRow = null;
            if (!empty($a['artikel_id'])) {
                $artikelRow = DB::connection('tenant')->table('artikel')
                    ->where('artikel_id', $a['artikel_id'])->first();
            }
            $feiertage = $hasSchichtCols
                ? \App\Helpers\SchichtartCalculator::cachedFeiertage($a['obj_id'] ?? null)
                : [];

            foreach ($tage as $t) {
                $einsatz_id = IdGenerator::einsatzId($t['datum']);
                $row = [
                    'einsatz_id'    => $einsatz_id,
                    'bestell_id'    => $bestell_id,
                    'kunden_id'     => $kunde->kunde_id,
                    'obj_id'        => $objekt->obj_id,
                    'artikel_id'    => $this->str($a, 'artikel_id'),
                    'nu_id'         => $this->str($a, 'nu_id'),
                    'datum'         => $t['datum'],
                    'tag_nr'        => $t['tag_nr'],
                    'beschreibung'  => $this->buildEinsatzBeschreibung($t['beschreibung'] ?? null, $t['menge'] ?? null),
                    'ist_start'     => $t['zeit_von'],
                    'ist_ende'      => $t['zeit_bis'],
                    'ist_std_menge' => $t['soll_std'],
                    'pause_minuten' => $t['pause_minuten'],
                    'gesetzl_pause' => $t['gesetzl_pause'],
                    'ort'           => $t['ort'] ?? null,
                    'anforderungen' => $hasAnfEinsatz ? $this->buildAnforderungenJson($t) : null,
                    'hms_bestaetigt'=> 'ausstehend',
                ];
                if ($hasSchichtCols) {
                    $cls = \App\Helpers\SchichtartCalculator::compute(
                        $t['datum'], $t['zeit_von'], $t['zeit_bis'],
                        $artikelRow, $feiertage
                    );
                    $row['schichtart']   = $cls['schichtart'];
                    $row['zuschlag_pct'] = $cls['zuschlag_pct'];
                }
                DB::connection('tenant')->table('einsaetze')->insert(
                    array_filter($row, fn($v) => $v !== null && $v !== '')
                );
                $createdEinsaetze++;
                $createdEinsatzIds[] = $einsatz_id;
            }
        });

        return [
            'success'        => true,
            'eingang_id'     => $eingang_id,
            'bestell_id'     => $bestell_id,
            'tasks_created'  => $createdEinsaetze,
            // Rollback removes ALL three related rows. Cascade order
            // matters: einsaetze → bestellungen → bestelleingang_tage
            // → bestelleingang (children before parents).
            '_rollback'      => [
                'cascade' => [
                    ['table' => 'einsaetze',           'where' => ['bestell_id' => $bestell_id]],
                    ['table' => 'bestellungen',        'where' => ['bestell_id' => $bestell_id]],
                    ['table' => 'bestelleingang_tage', 'where' => ['eingang_id' => $eingang_id]],
                    ['table' => 'bestelleingang',      'where' => ['eingang_id' => $eingang_id]],
                ],
            ],
        ];
    }

    private function previewUpdateAnforderungen(array $a): array
    {
        $target = $this->resolveAnforderungenTarget($a);
        $m = isset($a['maennlich']) ? max(0, (int)$a['maennlich']) : null;
        $w = isset($a['weiblich'])  ? max(0, (int)$a['weiblich'])  : null;
        $menge = isset($a['menge']) ? (float)$a['menge'] : null;
        [, , $touchAny] = $this->anforderungenChanges($a);
        if (!$touchAny && $menge === null) {
            throw new \InvalidArgumentException('Mindestens maennlich/weiblich, qualifikation, kleidung, skill oder menge muss angegeben werden.');
        }
        $datum = $this->date($a, 'datum') ?? $target['datum'];

        // Count affected rows for an honest preview.
        $db = DB::connection('tenant');
        [$set, $unset, ] = $this->anforderungenChanges($a);
        $tageCount = 0;
        if ($target['eingang_id']) {
            $q = $db->table('bestelleingang_tage')->where('eingang_id', $target['eingang_id']);
            if ($datum) $q->where('datum', $datum);
            $rows = $q->get();
            foreach ($rows as $row) $this->assertMixFitsMenge($row, $set, $unset, $menge);
            $tageCount = count($rows);
        }
        $einsatzCount = 0;
        if ($target['einsatz_id']) {
            $einsatzCount = 1;
        } elseif ($target['bestell_id']) {
            $q = $db->table('einsaetze')->where('bestell_id', $target['bestell_id']);
            if ($datum) $q->where('datum', $datum);
            $einsatzCount = $q->count();
        }
        if ($tageCount === 0 && $einsatzCount === 0) {
            throw new \InvalidArgumentException(
                "Keine Tage/Einsätze gefunden für {$target['label']}" . ($datum ? " am {$datum}" : '') . '.'
            );
        }

        $mixStr = ($m !== null || $w !== null) ? ('♂ ' . ($m ?? 0) . '  ·  ♀ ' . ($w ?? 0)) : 'unverändert';
        $txt = fn(string $f) => array_key_exists($f, $a)
            ? (trim((string)$a[$f]) === '' ? '(wird gelöscht)' : trim((string)$a[$f]))
            : 'unverändert';
        return [
            'titel'  => $target['label'] . ' — Anforderungen ändern',
            'felder' => [
                ['Ziel',                $target['label']],
                ['Datum',               $datum ?: 'alle Tage'],
                ['Neue Verteilung',     $mixStr],
                ['Qualifikation',       $txt('qualifikation')],
                ['Kleidung',            $txt('kleidung')],
                ['Skill',               $txt('skill')],
                ['Neue Menge',          $menge !== null ? number_format($menge, 2, ',', '.') : 'unverändert'],
                ['Betroffene Tage',     (string)$tageCount],
                ['Betroffene Einsätze', (string)$einsatzCount],
            ],
        ];
    }

    private function updateAnforderungen(array $a, int $userId): array
    {
        $target = $this->resolveAnforderungenTarget($a);
        $menge = isset($a['menge']) ? (float)$a['menge'] : null;
        [$set, $unset, $touchAny] = $this->anforderungenChanges($a);
        if (!$touchAny && $menge === null) {
            throw new \InvalidArgumentException('Mindestens maennlich/weiblich, qualifikation, kleidung, skill oder menge muss angegeben werden.');
        }
        $datum = $this->date($a, 'datum') ?? $target['datum'];

        $db = DB::connection('tenant');
        $hasAnfTage    = Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'anforderungen');
        $hasAnfEinsatz = Schema::connection('tenant')->hasColumn('einsaetze', 'anforderungen');

        $updatedTage = 0;
        $updatedEinsaetze = 0;
        $rollbackRows = [];

        $db->transaction(function () use (
            $db, $target, $datum, $set, $unset, $menge, $touchAny,
            $hasAnfTage, $hasAnfEinsatz, &$updatedTage, &$updatedEinsaetze, &$rollbackRows
        ) {
            // 1) bestelleingang_tage
            if ($target['eingang_id'] && ($hasAnfTage || $menge !== null)) {
                $q = $db->table('bestelleingang_tage')->where('eingang_id', $target['eingang_id']);
                if ($datum) $q->where('datum', $datum);
                foreach ($q->get() as $row) {
                    $this->assertMixFitsMenge($row, $set, $unset, $menge);
                    $update = [];
                    if ($touchAny && $hasAnfTage) {
                        $update['anforderungen'] = $this->mergeAnforderungenJson($row->anforderungen ?? null, $set, $unset);
                    }
                    if ($menge !== null) $update['menge'] = $menge;
                    if (!$update) continue;
                    $rollbackRows[] = [
                        'table' => 'bestelleingang_tage',
                        'key'   => ['id' => $row->id ?? null, 'eingang_id' => $row->eingang_id, 'tag_nr' => $row->tag_nr],
                        'data'  => ['anforderungen' => $row->anforderungen ?? null, 'menge' => $row->menge ?? null],
                    ];
                    $db->table('bestelleingang_tage')
                        ->where('eingang_id', $row->eingang_id)->where('tag_nr', $row->tag_nr)
                        ->update($update);
                    $updatedTage++;
                }
            }

            // 2) einsaetze
            if ((($hasAnfEinsatz && $touchAny) || $menge !== null) && ($target['einsatz_id'] || $target['bestell_id'])) {
                $q = $db->table('einsaetze');
                if ($target['einsatz_id']) {
                    $q->where('einsatz_id', $target['einsatz_id']);
                } else {
                    $q->where('bestell_id', $target['bestell_id']);
                    if ($datum) $q->where('datum', $datum);
                }
                foreach ($q->get() as $row) {
                    $update = [];
                    if ($touchAny && $hasAnfEinsatz) {
                        $update['anforderungen'] = $this->mergeAnforderungenJson($row->anforderungen ?? null, $set, $unset);
                    }
                    // The Menge is also baked into the einsatz beschreibung
                    // ("… // Menge: 9,00") — keep that text in sync.
                    if ($menge !== null) {
                        $besch  = (string)($row->beschreibung ?? '');
                        $newTag = 'Menge: ' . number_format($menge, 2, ',', '.');
                        $newB   = preg_match('/Menge:\s*[\d.,]+/u', $besch)
                            ? preg_replace('/Menge:\s*[\d.,]+/u', $newTag, $besch)
                            : ($besch !== '' ? $besch . ' // ' . $newTag : $newTag);
                        if ($newB !== $besch) $update['beschreibung'] = $newB;
                    }
                    if (!$update) continue;
                    $rollbackRows[] = [
                        'table' => 'einsaetze',
                        'key'   => ['einsatz_id' => $row->einsatz_id],
                        'data'  => [
                            'anforderungen' => $row->anforderungen ?? null,
                            'beschreibung'  => $row->beschreibung ?? null,
                        ],
                    ];
                    $db->table('einsaetze')->where('einsatz_id', $row->einsatz_id)->update($update);
                    $updatedEinsaetze++;
                }
            }
        });

        if ($updatedTage === 0 && $updatedEinsaetze === 0) {
            throw new \InvalidArgumentException(
                "Keine Tage/Einsätze gefunden für {$target['label']}" . ($datum ? " am {$datum}" : '') . '.'
            );
        }

        return [
            'success'            => true,
            'ziel'               => $target['label'],
            'updated_tage'       => $updatedTage,
            'updated_einsaetze'  => $updatedEinsaetze,
            'gesetzt'            => $set ?: null,
            'geloescht'          => $unset ?: null,
            'menge'              => $menge,
            '_rollback'          => ['updates' => $rollbackRows],
        ];
    }

    /** Normalise raw `tage` rows into a uniform array.
     *  Returns [{datum, zeit_von, zeit_bis, soll_std, …}, …]. */
    private function normaliseBestelleingangTage(array $tageRaw, ?string $defaultStart, ?string $defaultEnd): array
    {
        if (empty($tageRaw)) {
            throw new \InvalidArgumentException('Mindestens ein Tag (datum) ist Pflicht.');
        }
        $out = [];
        foreach ($tageRaw as $i => $raw) {
            if (!is_array($raw)) {
                throw new \InvalidArgumentException("tage[$i] ist kein Objekt.");
            }
            $datum = $this->date($raw, 'datum');
            if (!$datum) {
                throw new \InvalidArgumentException("tage[$i].datum fehlt oder ist ungültig.");
            }
            $start = trim((string)($raw['zeit_von'] ?? $defaultStart ?? '')) ?: null;
            $end   = trim((string)($raw['zeit_bis'] ?? $defaultEnd   ?? '')) ?: null;
            $out[] = [
                'tag_nr'        => $i + 1,
                'datum'         => $datum,
                'wochentag'     => \Carbon\Carbon::parse($datum)->isoFormat('dddd'),
                'zeit_von'      => $start,
                'zeit_bis'      => $end,
                'soll_std'      => isset($raw['soll_std']) ? (float) $raw['soll_std'] : null,
                'pause_minuten' => isset($raw['pause_minuten']) ? (int) $raw['pause_minuten'] : null,
                'gesetzl_pause' => isset($raw['gesetzl_pause']) ? (int) $raw['gesetzl_pause'] : null,
                'menge'         => isset($raw['menge']) ? (float) $raw['menge'] : null,
                'maennlich'     => isset($raw['maennlich']) ? max(0, (int) $raw['maennlich']) : 0,
                'weiblich'      => isset($raw['weiblich'])  ? max(0, (int) $raw['weiblich'])  : 0,
                'qualifikation' => $this->str($raw, 'qualifikation'),
                'kleidung'      => $this->str($raw, 'kleidung'),
                'skill'         => $this->str($raw, 'skill'),
                'beschreibung'  => $this->str($raw, 'beschreibung'),
                'bemerkung'     => $this->str($raw, 'bemerkung'),
                'ort'           => $this->str($raw, 'ort'),
            ];
        }
        return $this->mergeDuplicateTage($out);
    }

    /**
     * Collapse rows that share the same (datum, zeit_von, zeit_bis) into
     * ONE day, summing menge and the ♂/♀ counts.
     *
     * Why: when the LLM extracts an "Einsatzliste" PDF that lists 9
     * workers for the same shift, it tends to emit 9 identical day rows
     * (menge=1 each) instead of one row with menge=9. Without this
     * defence the Dispo ends up with 9 separate one-person Einsätze for
     * the same shift — exactly what a human dispatcher would never do.
     * The schema description tells the model to aggregate, but we
     * enforce it server-side too because prompts are advisory.
     */
    private function mergeDuplicateTage(array $tage): array
    {
        $merged = [];
        foreach ($tage as $t) {
            $key = $t['datum'] . '|' . ($t['zeit_von'] ?? '') . '|' . ($t['zeit_bis'] ?? '');
            if (!isset($merged[$key])) {
                $merged[$key] = $t;
                continue;
            }
            $m = &$merged[$key];
            // Sum quantities — a row without menge counts as 1 person.
            $m['menge'] = (float)($m['menge'] ?? 1) + (float)($t['menge'] ?? 1);
            $m['maennlich'] += $t['maennlich'];
            $m['weiblich']  += $t['weiblich'];
            // Keep the longest beschreibung/bemerkung rather than losing info.
            foreach (['beschreibung', 'bemerkung', 'ort'] as $f) {
                if (($t[$f] ?? null) && !str_contains((string)($m[$f] ?? ''), (string)$t[$f])) {
                    $m[$f] = trim(($m[$f] ? $m[$f] . ' / ' : '') . $t[$f]);
                }
            }
            if (!isset($m['soll_std']) && isset($t['soll_std'])) $m['soll_std'] = $t['soll_std'];
            // Requirements: first non-empty value wins (identical shift → identical reqs).
            foreach (['qualifikation', 'kleidung', 'skill'] as $f) {
                if (empty($m[$f]) && !empty($t[$f])) $m[$f] = $t[$f];
            }
            unset($m);
        }
        // Renumber tag_nr after merging.
        $out = array_values($merged);
        foreach ($out as $i => &$t) { $t['tag_nr'] = $i + 1; }
        return $out;
    }

    /**
     * Build the anforderungen JSON string from a normalised day row — the
     * exact shape the Bestelleingang wizard writes:
     * {"qualifikation":"§34a","kleidung":"…","skill":"…",
     *  "geschlecht_mix":{"maennlich":8,"weiblich":1}}
     * Returns null when the day has no requirements at all.
     */
    private function buildAnforderungenJson(array $t): ?string
    {
        $anf = [];
        if (!empty($t['qualifikation'])) $anf['qualifikation'] = $t['qualifikation'];
        if (!empty($t['kleidung']))      $anf['kleidung']      = $t['kleidung'];
        if (!empty($t['skill']))         $anf['skill']         = $t['skill'];
        $m = (int)($t['maennlich'] ?? 0);
        $w = (int)($t['weiblich']  ?? 0);
        if ($m > 0 || $w > 0) {
            $anf['geschlecht_mix'] = ['maennlich' => $m, 'weiblich' => $w];
        }
        return $anf
            ? json_encode($anf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
    }

    /**
     * Guard: the objekt MUST belong to the given kunde. Without this the
     * LLM can pair any kunde with any objekt (both exist → both resolve),
     * producing a Bestelleingang whose edit wizard shows an empty Objekt
     * dropdown (it filters objekte by kunde) — a real bug we hit in prod.
     */
    private function assertObjektBelongsToKunde(object $objekt, object $kunde): void
    {
        if ((string)($objekt->kunden_id ?? '') !== (string)$kunde->kunde_id) {
            throw new \InvalidArgumentException(
                "Objekt {$objekt->obj_id} ({$objekt->objektname}) gehört zum Kunden "
                . "{$objekt->kunden_id}, NICHT zu {$kunde->kunde_id} ({$kunde->kundenname}). "
                . 'Bitte kunden_id oder obj_id korrigieren (nutze search_objekt / kunde_details).'
            );
        }
    }

    /**
     * Artikel/Preis resolution for create_bestelleingang:
     * - artikel_id given → must belong to the objekt (else reject).
     * - none given, objekt has exactly ONE active artikel → adopt it.
     * - none given, 2+ artikel → reject with the list, forcing the LLM
     *   to ask the user which one applies (the edit wizard requires one).
     * - none given, 0 artikel → pass through (wizard warns separately).
     * The chosen artikel donates modell / kundenpreis / nu_preis unless
     * the caller supplied them explicitly.
     */
    private function applyArtikelDefaults(array $a, string $objId): array
    {
        $rows = DB::connection('tenant')->table('artikel')
            ->where('obj_id', $objId)->where('aktiv', 1)->get();

        // A supplied artikel_id must actually belong to this objekt —
        // otherwise price/modell would come from a foreign artikel.
        if (!empty($a['artikel_id'])) {
            $art = $rows->first(fn($r) => (string)$r->artikel_id === (string)$a['artikel_id']);
            if (!$art) {
                throw new \InvalidArgumentException(
                    "Artikel {$a['artikel_id']} gehört nicht zum Objekt {$objId} (oder ist inaktiv). "
                    . 'Verfügbar: ' . ($rows->isEmpty() ? 'KEINE aktiven Artikel' : $this->artikelListe($rows)) . '.'
                );
            }
        } elseif (count($rows) === 1) {
            // Exactly one active artikel → adopt it, like a human would.
            $art = $rows[0];
            $a['artikel_id'] = $art->artikel_id;
        } elseif (count($rows) > 1) {
            // Ambiguous — force the LLM to ask the user instead of creating
            // an order without Artikel/Preis (the edit wizard requires one).
            throw new \InvalidArgumentException(
                "Objekt {$objId} hat " . count($rows) . ' aktive Artikel — bitte frage den Nutzer, '
                . 'welcher gelten soll, und übergib artikel_id. Zur Auswahl: '
                . $this->artikelListe($rows) . '.'
            );
        } else {
            return $a; // no artikel at all — allowed, wizard warns separately
        }

        // Inherit modell / prices from the chosen artikel when not given.
        if (empty($a['modell']) && !empty($art->modell))                $a['modell']      = $art->modell;
        if (!isset($a['kundenpreis']) && $art->preis_kunde !== null)    $a['kundenpreis'] = (float)$art->preis_kunde;
        if (!isset($a['nu_preis']) && ($art->nu_preis ?? null) !== null) $a['nu_preis']   = (float)$art->nu_preis;
        return $a;
    }

    /** "A-001 (SMA/Veranstaltungsschutz, Pro Stunde, 25.00 €), A-002 (…)" */
    private function artikelListe($rows): string
    {
        return $rows->map(fn($r) =>
            $r->artikel_id . ' (' . $r->artikel_name
            . ($r->modell ? ', ' . $r->modell : '')
            . ($r->preis_kunde !== null ? ', ' . number_format((float)$r->preis_kunde, 2, ',', '.') . ' €' : '')
            . ')'
        )->implode(', ');
    }

    /** Mirrors IncomingOrderController::buildBeschreibung so AI-created
     *  einsaetze look identical to manually-created ones. */
    private function buildEinsatzBeschreibung(?string $beschreibung, $menge): ?string
    {
        $b = trim((string)($beschreibung ?? ''));
        $m = $menge !== null && $menge !== '' ? (float)$menge : null;
        $parts = [];
        if ($b !== '')              $parts[] = $b;
        if ($m !== null && $m > 0)  $parts[] = 'Menge: ' . number_format($m, 2, ',', '.');
        return $parts ? implode(' // ', $parts) : null;
    }

    /**
     * Bereich guard for create_bestelleingang: kunde/objekt/artikel/nu
     * must all be inside the caller's scope — otherwise a restricted
     * user could create orders that reference out-of-scope entities.
     */
    private function assertEingangScope(array $a, object $kunde, object $objekt): void
    {
        $user = request()->user();
        if (!BereichScope::allowsKunde($user, $kunde->kunde_id)) {
            throw new \InvalidArgumentException("Kunde {$kunde->kunde_id} liegt außerhalb deines Bereichs.");
        }
        if (!BereichScope::allowsObjekt($user, $objekt->obj_id)) {
            throw new \InvalidArgumentException("Objekt {$objekt->obj_id} liegt außerhalb deines Bereichs.");
        }
        $artikelId = $this->str($a, 'artikel_id');
        if ($artikelId !== null && !BereichScope::allowsArtikel($user, $artikelId)) {
            throw new \InvalidArgumentException("Artikel {$artikelId} liegt außerhalb deines Bereichs.");
        }
        $nuId = $this->str($a, 'nu_id');
        if ($nuId !== null && !BereichScope::allowsNu($user, $nuId)) {
            throw new \InvalidArgumentException("Nachunternehmer {$nuId} liegt außerhalb deines Bereichs.");
        }
    }

    /**
     * Resolve a user-supplied id (BE-… / B-… / E-…) to the affected
     * bestelleingang_tage + einsaetze row sets.
     *
     * @return array{eingang_id: ?string, bestell_id: ?string, einsatz_id: ?string, label: string}
     */
    private function resolveAnforderungenTarget(array $a): array
    {
        $id = trim((string)($a['id'] ?? $a['eingang_id'] ?? $a['bestell_id'] ?? $a['einsatz_id'] ?? ''));
        if ($id === '') throw new \InvalidArgumentException('id (BE-…, B-… oder E-…) ist Pflicht.');
        $db = DB::connection('tenant');

        if (str_starts_with(strtoupper($id), 'E-')) {
            $einsatz = $db->table('einsaetze')->where('einsatz_id', $id)->first();
            if (!$einsatz || !BereichScope::allowsEinsatz(request()->user(), $id)) {
                throw new \InvalidArgumentException("Einsatz {$id} nicht gefunden.");
            }
            $bestellung = $einsatz->bestell_id
                ? $db->table('bestellungen')->where('bestell_id', $einsatz->bestell_id)->first()
                : null;
            return [
                'eingang_id' => $bestellung->eingang_id ?? null,
                'bestell_id' => $einsatz->bestell_id,
                'einsatz_id' => $id,
                'datum'      => (string) $einsatz->datum,
                'label'      => "Einsatz {$id}",
            ];
        }

        if (str_starts_with(strtoupper($id), 'BE-')) {
            $eingang = $db->table('bestelleingang')->where('eingang_id', $id)->first();
            if (!$eingang || !BereichScope::allowsEingang(request()->user(), $id)) {
                throw new \InvalidArgumentException("Bestelleingang {$id} nicht gefunden.");
            }
            $bestellung = $db->table('bestellungen')->where('eingang_id', $id)->first();
            return [
                'eingang_id' => $id,
                'bestell_id' => $bestellung->bestell_id ?? null,
                'einsatz_id' => null,
                'datum'      => null,
                'label'      => "Bestelleingang {$id}",
            ];
        }

        $bestellung = $db->table('bestellungen')->where('bestell_id', $id)->first();
        if (!$bestellung || !BereichScope::allowsBestellung(request()->user(), $id)) {
            throw new \InvalidArgumentException("Bestellung {$id} nicht gefunden.");
        }
        return [
            'eingang_id' => $bestellung->eingang_id ?? null,
            'bestell_id' => $id,
            'einsatz_id' => null,
            'datum'      => null,
            'label'      => "Bestellung {$id}",
        ];
    }

    /**
     * Merge changes into an existing anforderungen JSON string, preserving
     * untouched keys (positionen, …). $set overwrites keys, $unset removes
     * them. Returns null when the result would be empty.
     */
    private function mergeAnforderungenJson(?string $existingJson, array $set, array $unset): ?string
    {
        $anf = [];
        if ($existingJson) {
            $decoded = json_decode($existingJson, true);
            if (is_array($decoded)) $anf = $decoded;
        }
        foreach ($set as $k => $v) $anf[$k] = $v;
        foreach ($unset as $k) unset($anf[$k]);
        return empty($anf)
            ? null
            : json_encode($anf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Translate update_anforderungen args into merge instructions.
     * Returns [set, unset, touchAny]. Text fields: absent = untouched,
     * empty string = delete, non-empty = overwrite. Mix: touched as soon
     * as maennlich or weiblich is present; 0/0 deletes the mix.
     */
    private function anforderungenChanges(array $a): array
    {
        $set = [];
        $unset = [];
        $touchMix = isset($a['maennlich']) || isset($a['weiblich']);
        if ($touchMix) {
            $m = max(0, (int)($a['maennlich'] ?? 0));
            $w = max(0, (int)($a['weiblich']  ?? 0));
            if ($m > 0 || $w > 0) $set['geschlecht_mix'] = ['maennlich' => $m, 'weiblich' => $w];
            else                  $unset[] = 'geschlecht_mix';
        }
        foreach (['qualifikation', 'kleidung', 'skill'] as $f) {
            if (!array_key_exists($f, $a)) continue;
            $v = trim((string)$a[$f]);
            if ($v === '') $unset[] = $f;
            else           $set[$f] = $v;
        }
        return [$set, $unset, $touchMix || $set || $unset];
    }

    /**
     * Wizard rule: the ♂/♀ mix may never exceed the Menge of a day.
     * $row is a bestelleingang_tage record, $set/$unset/$menge the pending
     * changes — throws when the FINAL state would violate the rule, so the
     * LLM asks the user to adjust maennlich/weiblich or menge together.
     */
    private function assertMixFitsMenge(object $row, array $set, array $unset, ?float $menge): void
    {
        $finalMenge = $menge !== null ? $menge : (float)($row->menge ?? 0);
        if ($finalMenge <= 0) return;
        if (in_array('geschlecht_mix', $unset, true)) return;
        $mix = $set['geschlecht_mix'] ?? null;
        if (!$mix) {
            $existing = json_decode((string)($row->anforderungen ?? ''), true);
            $mix = is_array($existing) ? ($existing['geschlecht_mix'] ?? null) : null;
        }
        if (!$mix) return;
        $m = (int)($mix['maennlich'] ?? 0);
        $w = (int)($mix['weiblich'] ?? 0);
        if ($m + $w > $finalMenge) {
            throw new \InvalidArgumentException(
                "♂{$m} + ♀{$w} = " . ($m + $w) . " überschreitet die Menge "
                . number_format($finalMenge, 2, ',', '.') . " am {$row->datum}. "
                . 'Bitte maennlich/weiblich UND menge zusammen angeben, damit sie zusammenpassen.'
            );
        }
    }
}
