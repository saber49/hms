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

class EinsatzWriteTools implements WriteToolsProvider
{
    use ToolHelpers;

    /** Hard cap for bulk_create_einsaetze — keeps one tool call atomic. */
    private const BULK_EINSATZ_MAX = 200;

    public function handles(string $name): bool
    {
        return match ($name) {
            'create_einsatz' => true,
            'update_einsatz' => true,
            'update_einsatz_zeit' => true,
            'bulk_create_einsaetze' => true,
            'assign_mitarbeiter' => true,
            'remove_mitarbeiter' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('create_einsatz',
                'Legt einen neuen Einsatz für eine bestehende Bestellung an.',
                [
                    'bestell_id'   => ['type' => 'string', 'description' => 'Pflicht'],
                    'datum'        => ['type' => 'string', 'description' => 'Pflicht — YYYY-MM-DD'],
                    'kunden_id'    => ['type' => 'string'],
                    'obj_id'       => ['type' => 'string'],
                    'artikel_id'   => ['type' => 'string'],
                    'nu_id'        => ['type' => 'string'],
                    'ist_start'    => ['type' => 'string', 'description' => 'HH:MM'],
                    'ist_ende'     => ['type' => 'string', 'description' => 'HH:MM'],
                    'schichtart'   => ['type' => 'string', 'enum' => ['tagdienst', 'nachtdienst', 'kombiniert']],
                    'beschreibung' => ['type' => 'string'],
                ], ['bestell_id', 'datum']),

            $this->fn('update_einsatz_zeit',
                'Aktualisiert Start- und/oder Endzeit eines bestehenden Einsatzes.',
                [
                    'einsatz_id' => ['type' => 'string', 'description' => 'Pflicht'],
                    'ist_start'  => ['type' => 'string', 'description' => 'HH:MM'],
                    'ist_ende'   => ['type' => 'string', 'description' => 'HH:MM'],
                ], ['einsatz_id']),

            $this->fn('update_einsatz',
                'Ändert Felder EINES bestehenden Einsatzes — der universelle Einsatz-Editor: '
                . 'Ort, Beschreibung, Bemerkung, Schichtart, Zeiten, Stunden-Menge, Pausen, Zuschlag, '
                . 'Betrag, Bestätigungs-Status, Plan-Status, NU. Nur übergebene Felder werden geändert. '
                . 'hms_bestaetigt auf "ja" = Schicht manuell bestätigen (wirkt auf Abrechnung!), '
                . '"nein" = ablehnen, "ausstehend" = zurück auf offen. '
                . 'Vorher IMMER einsatz_details (Tool) lesen.',
                [
                    'einsatz_id'    => ['type' => 'string', 'description' => 'Pflicht — z.B. "E-20260522-395"'],
                    'ort'           => ['type' => 'string'],
                    'beschreibung'  => ['type' => 'string'],
                    'bemerkung'     => ['type' => 'string'],
                    'schichtart'    => ['type' => 'string', 'enum' => ['tagdienst', 'nachtdienst', 'kombiniert', 'wochenende', 'feiertag']],
                    'ist_start'     => ['type' => 'string', 'description' => 'HH:MM'],
                    'ist_ende'      => ['type' => 'string', 'description' => 'HH:MM'],
                    'ist_std_menge' => ['type' => 'number', 'description' => 'geplante/fakturierbare Stunden'],
                    'ist_betrag'    => ['type' => 'number', 'description' => 'manueller Betrag — überschreibt Preis×Stunden'],
                    'pause_minuten' => ['type' => 'integer'],
                    'gesetzl_pause' => ['type' => 'integer', 'description' => 'gesetzliche Pause (Min.)'],
                    'zuschlag_pct'  => ['type' => 'number', 'description' => 'Zuschlag in %, z.B. 25'],
                    'hms_bestaetigt'=> ['type' => 'string', 'enum' => ['ausstehend', 'ja', 'nein']],
                    'pruefung'      => ['type' => 'string', 'enum' => ['geplant', ''], 'description' => '"geplant" = Plan veröffentlicht, "" = Plan zurückziehen'],
                    'nu_id'         => ['type' => 'string', 'description' => 'NU-ID oder leer zum Entfernen'],
                ], ['einsatz_id']),

            $this->fn('bulk_create_einsaetze',
                'Legt mehrere Einsätze in einer Transaktion an (Bulk-Import, z.B. aus einer CSV-Datei). '
                . 'Maximal 200 Zeilen pro Aufruf. Jede Zeile braucht mindestens bestell_id und datum. '
                . 'Wenn dienst_nr angegeben ist, wird der Mitarbeiter automatisch dem Einsatz zugewiesen. '
                . 'Erfordert Bestätigung — der Nutzer sieht eine Vorschau-Tabelle aller Zeilen vor dem Import.',
                [
                    'rows' => [
                        'type'        => 'array',
                        'description' => 'Array von Einsatz-Objekten',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'bestell_id'   => ['type' => 'string', 'description' => 'Pflicht — z.B. "BE-2026-0142"'],
                                'datum'        => ['type' => 'string', 'description' => 'Pflicht — YYYY-MM-DD oder DD.MM.YYYY'],
                                'kunden_id'    => ['type' => 'string'],
                                'obj_id'       => ['type' => 'string'],
                                'artikel_id'   => ['type' => 'string'],
                                'nu_id'        => ['type' => 'string'],
                                'dienst_nr'    => ['type' => 'string', 'description' => 'optional, weist Mitarbeiter zu'],
                                'ist_start'    => ['type' => 'string', 'description' => 'HH:MM'],
                                'ist_ende'     => ['type' => 'string', 'description' => 'HH:MM'],
                                'schichtart'   => ['type' => 'string', 'enum' => ['tagdienst', 'nachtdienst', 'wochenende', 'feiertag']],
                                'beschreibung' => ['type' => 'string'],
                            ],
                            'required' => ['bestell_id', 'datum'],
                        ],
                    ],
                ], ['rows']),

            $this->fn('assign_mitarbeiter',
                'Weist einen internen Mitarbeiter einem bestehenden Einsatz zu. '
                . 'Blockiert bei bereits BESTÄTIGTEN Einsätzen. Prüft Abwesenheiten und Überschneidungen '
                . 'und zeigt Warnungen in der Vorschau. Erfordert Bestätigung durch den Nutzer.',
                [
                    'einsatz_id' => ['type' => 'string', 'description' => 'Pflicht — z.B. "E-20260522-395"'],
                    'dienst_nr'  => ['type' => 'string', 'description' => 'Pflicht — z.B. "MA007"'],
                ], ['einsatz_id', 'dienst_nr']),

            $this->fn('remove_mitarbeiter',
                'Entfernt einen internen Mitarbeiter von einem Einsatz. '
                . 'Blockiert bei bereits BESTÄTIGTEN Einsätzen. Wenn der Einsatz bereits geplant war, '
                . 'wird der Mitarbeiter automatisch per App-Benachrichtigung informiert. Erfordert Bestätigung.',
                [
                    'einsatz_id' => ['type' => 'string', 'description' => 'Pflicht'],
                    'dienst_nr'  => ['type' => 'string', 'description' => 'Pflicht'],
                ], ['einsatz_id', 'dienst_nr']),
        ];
    }

    public function preview(string $name, array $args): array
    {
        return match ($name) {
            'create_einsatz' => $this->previewCreateEinsatz($args),
            'update_einsatz' => $this->previewUpdateEinsatz($args),
            'update_einsatz_zeit' => $this->previewUpdateEinsatzZeit($args),
            'bulk_create_einsaetze' => $this->previewBulkCreateEinsaetze($args),
            'assign_mitarbeiter' => $this->previewAssignMitarbeiter($args),
            'remove_mitarbeiter' => $this->previewRemoveMitarbeiter($args),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    public function execute(string $name, array $args, int $userId): array
    {
        return match ($name) {
            'create_einsatz' => $this->createEinsatz($args, $userId),
            'update_einsatz' => $this->updateEinsatz($args, $userId),
            'update_einsatz_zeit' => $this->updateEinsatzZeit($args, $userId),
            'bulk_create_einsaetze' => $this->bulkCreateEinsaetze($args, $userId),
            'assign_mitarbeiter' => $this->assignMitarbeiter($args, $userId),
            'remove_mitarbeiter' => $this->removeMitarbeiter($args, $userId),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    private function previewCreateEinsatz(array $a): array
    {
        $datum = trim((string)($a['datum'] ?? ''));
        if ($datum === '') {
            throw new \InvalidArgumentException('bestell_id und datum sind Pflicht.');
        }
        // Validate the bestellung up front so the FK problem is caught
        // at preview time — not as a raw SQL error after confirmation.
        $bestellung = $this->resolveBestellungForEinsatz((string)($a['bestell_id'] ?? ''));

        return [
            'titel'  => 'Neuen Einsatz anlegen',
            'felder' => [
                ['Bestell-ID',  $bestellung->bestell_id],
                ['Datum',       $datum],
                ['Schichtart',  trim((string)($a['schichtart'] ?? 'tagdienst'))],
                ['Start',       trim((string)($a['ist_start'] ?? '—')) ?: '—'],
                ['Ende',        trim((string)($a['ist_ende'] ?? '—')) ?: '—'],
                ['NU',          trim((string)($a['nu_id'] ?? '—')) ?: '—'],
                ['Beschreibung',trim((string)($a['beschreibung'] ?? '—')) ?: '—'],
            ],
        ];
    }

    private function createEinsatz(array $a, int $userId): array
    {
        $datum = $this->date($a, 'datum');
        if (!$datum) throw new \InvalidArgumentException('datum ist Pflicht (YYYY-MM-DD).');

        // Re-resolve at execute time (the LLM may retry with new args).
        $bestellung = $this->resolveBestellungForEinsatz((string)($a['bestell_id'] ?? ''));

        // Date-based einsatz ID (E-YYYYMMDD-NNN) — the same generator the
        // rest of the app uses. IdGenerator::next() is WRONG here: it
        // CASTs 'E-20260522-395' minus the prefix ('-20260522-395') to
        // UNSIGNED, which wraps negative → huge → PHP_INT_MAX overflow →
        // einsatz_id like 'E9.2233720368548E+18'.
        $einsatz_id = IdGenerator::einsatzId($datum);

        $row = array_filter([
            'einsatz_id'    => $einsatz_id,
            'bestell_id'    => $bestellung->bestell_id,
            // Inherit kunde/objekt/artikel/NU from the bestellung when the
            // LLM didn't pass them — keeps the einsatz consistent with its
            // parent order instead of silently NULL.
            'kunden_id'     => $this->str($a, 'kunden_id') ?? ($bestellung->kunden_id ?? null),
            'obj_id'        => $this->str($a, 'obj_id')    ?? ($bestellung->obj_id    ?? null),
            'artikel_id'    => $this->str($a, 'artikel_id')?? ($bestellung->artikel_id?? null),
            'nu_id'         => $this->str($a, 'nu_id')     ?? ($bestellung->nu_id     ?? null),
            'datum'         => $datum,
            'beschreibung'  => $this->str($a, 'beschreibung'),
            'ist_start'     => $this->str($a, 'ist_start'),
            'ist_ende'      => $this->str($a, 'ist_ende'),
            'schichtart'    => $this->str($a, 'schichtart') ?: 'tagdienst',
            'hms_bestaetigt'=> 'ausstehend',
            'created_at'    => now(),
        ], fn($v) => $v !== null && $v !== '');
        DB::connection('tenant')->table('einsaetze')->insert($row);
        return ['success' => true, 'einsatz_id' => $einsatz_id, 'bestell_id' => $bestellung->bestell_id];
    }

    private function previewUpdateEinsatzZeit(array $a): array
    {
        $id = trim((string)($a['einsatz_id'] ?? ''));
        if ($id === '') throw new \InvalidArgumentException('einsatz_id ist Pflicht.');
        $row = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->first();
        if (!$row || !BereichScope::allowsEinsatz(request()->user(), $id)) {
            throw new \InvalidArgumentException("Einsatz {$id} nicht gefunden.");
        }
        $newStart = trim((string)($a['ist_start'] ?? ''));
        $newEnde  = trim((string)($a['ist_ende'] ?? ''));
        if ($newStart === '' && $newEnde === '') {
            throw new \InvalidArgumentException('Mindestens ist_start oder ist_ende muss angegeben werden.');
        }
        return [
            'titel'  => "Einsatz {$id} — Zeit ändern",
            'felder' => [
                ['Datum',  (string)$row->datum],
                ['Start',  ($row->ist_start ?? '—') . ($newStart !== '' ? '  →  ' . $newStart : '')],
                ['Ende',   ($row->ist_ende  ?? '—') . ($newEnde  !== '' ? '  →  ' . $newEnde  : '')],
            ],
        ];
    }

    private function updateEinsatzZeit(array $a, int $userId): array
    {
        $id = trim((string)($a['einsatz_id'] ?? ''));
        $existing = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->first();
        if (!$existing || !BereichScope::allowsEinsatz(request()->user(), $id)) {
            throw new \InvalidArgumentException("Einsatz {$id} nicht gefunden.");
        }
        $update = [];
        if (!empty($a['ist_start'])) $update['ist_start'] = trim((string)$a['ist_start']);
        if (!empty($a['ist_ende']))  $update['ist_ende']  = trim((string)$a['ist_ende']);
        if (empty($update)) throw new \InvalidArgumentException('Nichts zu ändern.');
        $rollback = ['ist_start' => $existing->ist_start, 'ist_ende' => $existing->ist_ende];
        DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->update($update);
        return ['success' => true, 'einsatz_id' => $id, '_rollback' => ['table' => 'einsaetze', 'key' => ['einsatz_id' => $id], 'data' => $rollback]];
    }

    // ---------------------------------------------------------------
    // update_einsatz — the universal einsatz editor
    // ---------------------------------------------------------------
    private function previewUpdateEinsatz(array $a): array
    {
        [$row, $changes] = $this->resolveEinsatzChanges($a);

        $out = [
            'titel'   => "Einsatz {$row->einsatz_id} ({$row->datum}) ändern",
            'felder'  => array_map(
                fn ($k, $v) => [$k, ($v['old'] ?? '—') . '  →  ' . ($v['new'] ?? '—')],
                array_keys($changes), array_values($changes)
            ),
            'hinweis' => count($changes) . ' Feld(er) werden geändert.',
        ];
        if (($changes['hms_bestaetigt']['new'] ?? null) === 'ja') {
            $out['warnung'] = 'Bestätigung wirkt auf Abrechnung: die Schicht fließt in PDF/Controlling ein.';
        }
        return $out;
    }

    private function updateEinsatz(array $a, int $userId): array
    {
        [$row, $changes] = $this->resolveEinsatzChanges($a);

        $update = [];
        foreach ($changes as $col => $d) {
            $update[$col] = $d['new'] === '—' ? null : $d['new'];
        }

        $rollback = [];
        foreach (array_keys($changes) as $col) {
            $rollback[$col] = $row->{$col} ?? null;
        }

        DB::connection('tenant')->table('einsaetze')
            ->where('einsatz_id', $row->einsatz_id)->update($update);

        \App\Helpers\Realtime::changed('dispo');

        return [
            'success'    => true,
            'einsatz_id' => $row->einsatz_id,
            'changes'    => $changes,
            '_rollback'  => ['table' => 'einsaetze', 'key' => ['einsatz_id' => $row->einsatz_id], 'data' => $rollback],
        ];
    }

    private function resolveEinsatzChanges(array $a): array
    {
        $id = trim((string) ($a['einsatz_id'] ?? ''));
        if ($id === '') throw new \InvalidArgumentException('einsatz_id ist Pflicht.');

        $row = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->first();
        if (!$row || !BereichScope::allowsEinsatz(request()->user(), $id)) {
            throw new \InvalidArgumentException("Einsatz {$id} nicht gefunden.");
        }

        $changes = [];

        foreach (['ort', 'beschreibung', 'bemerkung', 'schichtart'] as $col) {
            if (!array_key_exists($col, $a)) continue;
            $new = $this->str($a, $col);
            $old = $row->{$col} !== null ? (string) $row->{$col} : null;
            if ((string) ($new ?? '') !== (string) ($old ?? '')) {
                $changes[$col] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
            }
        }

        foreach (['ist_start', 'ist_ende'] as $col) {
            if (!array_key_exists($col, $a)) continue;
            $new = $a[$col] === null || $a[$col] === '' ? null : $this->normalizeTime((string) $a[$col]);
            if ($a[$col] !== null && $a[$col] !== '' && $new === null) {
                throw new \InvalidArgumentException("Ungültige Zeit für {$col}: " . $a[$col]);
            }
            $old = $row->{$col} !== null ? substr((string) $row->{$col}, 0, 5) : null;
            if ($new !== $old) $changes[$col] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
        }

        foreach (['ist_std_menge', 'ist_betrag', 'zuschlag_pct'] as $col) {
            if (!array_key_exists($col, $a)) continue;
            $new = $a[$col] === null || $a[$col] === '' ? null : (float) $a[$col];
            $old = $row->{$col} !== null ? (float) $row->{$col} : null;
            if ($new !== $old) $changes[$col] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
        }

        foreach (['pause_minuten', 'gesetzl_pause'] as $col) {
            if (!array_key_exists($col, $a)) continue;
            $new = $a[$col] === null ? null : max(0, (int) $a[$col]);
            $old = $row->{$col} !== null ? (int) $row->{$col} : null;
            if ($new !== $old) $changes[$col] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
        }

        if (array_key_exists('hms_bestaetigt', $a)) {
            $new = strtolower(trim((string) $a['hms_bestaetigt']));
            if (!in_array($new, ['ausstehend', 'ja', 'nein'], true)) {
                throw new \InvalidArgumentException("Ungültiges hms_bestaetigt '{$a['hms_bestaetigt']}'.");
            }
            $old = (string) ($row->hms_bestaetigt ?? '');
            if ($new !== $old) $changes['hms_bestaetigt'] = ['old' => $old ?: '—', 'new' => $new];
        }

        if (array_key_exists('pruefung', $a)) {
            $raw = trim((string) ($a['pruefung'] ?? ''));
            $new = $raw === 'geplant' ? 'geplant' : null;
            $old = $row->pruefung !== null ? (string) $row->pruefung : null;
            if ($new !== $old) $changes['pruefung'] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
        }

        if (array_key_exists('nu_id', $a)) {
            $nuInput = $this->str($a, 'nu_id');
            if ($nuInput === null) {
                $new = null;
            } else {
                $nu = $this->resolveExistingId(
                    'nachunternehmer', 'nu_id', 'firmenname', $nuInput, 'nu'
                );
                if (!BereichScope::allowsNu(request()->user(), $nu->nu_id)) {
                    throw new \InvalidArgumentException("Nachunternehmer {$nuInput} nicht gefunden.");
                }
                $new = $nu->nu_id;
            }
            $old = $row->nu_id !== null ? (string) $row->nu_id : null;
            if ((string) ($new ?? '') !== (string) ($old ?? '')) {
                $changes['nu_id'] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
            }
        }

        if (empty($changes)) {
            throw new \InvalidArgumentException('Keine Änderungen erkannt.');
        }
        return [$row, $changes];
    }

    private function previewBulkCreateEinsaetze(array $a): array
    {
        $rows = $this->normaliseBulkEinsaetze($a);
        $count = count($rows);

        // Build the preview table — first 10 rows verbatim, the rest
        // collapsed so the modal doesn't become a wall of text.
        $sample = array_slice($rows, 0, 10);
        $tableRows = [];
        foreach ($sample as $i => $r) {
            $tableRows[] = [
                'idx'         => $i + 1,
                'bestell_id'  => $r['_valid'] ? $r['bestell_id'] : '⚠ ' . ($r['bestell_id'] ?? '—'),
                'datum'       => $r['datum'] ?? '—',
                'start'       => $r['ist_start'] ?? '—',
                'ende'        => $r['ist_ende'] ?? '—',
                'mitarbeiter' => $r['dienst_nr'] ?? '—',
                'fehler'      => $r['_valid'] ? '' : $r['_error'],
            ];
        }

        $valid   = count(array_filter($rows, fn($r) => $r['_valid']));
        $invalid = $count - $valid;

        $felder = [
            ['Anzahl Zeilen',  (string)$count],
            ['Gültig',         (string)$valid],
            ['Mit Fehlern',    (string)$invalid],
        ];
        if ($count > 10) {
            $felder[] = ['Hinweis', "Es werden nur die ersten 10 von {$count} Zeilen unten angezeigt."];
        }

        return [
            'titel'   => "Bulk-Import: {$count} Einsätze",
            'felder'  => $felder,
            'table'   => [
                'columns' => ['#', 'Bestell-ID', 'Datum', 'Start', 'Ende', 'Mitarbeiter', 'Fehler'],
                'rows'    => array_map(fn($r) => [
                    (string)$r['idx'], $r['bestell_id'], $r['datum'],
                    $r['start'], $r['ende'], $r['mitarbeiter'], $r['fehler'],
                ], $tableRows),
            ],
            'hinweis' => $invalid > 0
                ? "{$invalid} Zeile(n) haben Fehler und werden NICHT importiert. Bei Bestätigung werden nur die {$valid} gültigen Zeilen geschrieben — alles in einer Transaktion."
                : "Bei Bestätigung werden alle {$valid} Zeilen in einer einzigen Transaktion geschrieben. Falls eine einzelne Zeile fehlschlägt, wird der gesamte Import rückgängig gemacht.",
        ];
    }

    private function bulkCreateEinsaetze(array $a, int $userId): array
    {
        $rows = $this->normaliseBulkEinsaetze($a);
        $valid = array_values(array_filter($rows, fn($r) => $r['_valid']));

        if (count($valid) === 0) {
            throw new \InvalidArgumentException('Keine gültigen Zeilen zum Importieren.');
        }

        // Resolve every distinct bestell_id ONCE before the transaction —
        // an unknown ID must fail the whole batch with a readable message
        // instead of a raw FK violation halfway through the insert loop.
        $bestellCache = [];
        foreach ($valid as $r) {
            $key = $r['bestell_id'];
            if (!isset($bestellCache[$key])) {
                $bestellCache[$key] = $this->resolveBestellungForEinsatz($key);
            }
        }

        $created = [];
        DB::connection('tenant')->transaction(function () use ($valid, $bestellCache, &$created) {
            foreach ($valid as $r) {
                $bestellung = $bestellCache[$r['bestell_id']];
                // Date-based ID (E-YYYYMMDD-NNN) — see createEinsatz for why
                // IdGenerator::next() must not be used on einsatz IDs.
                $einsatz_id = IdGenerator::einsatzId($r['datum']);
                // Workers go into the einsatz_mitarbeiter join table —
                // einsaetze itself has no mitarbeiter_id column.
                $insert = array_filter([
                    'einsatz_id'     => $einsatz_id,
                    'bestell_id'     => $bestellung->bestell_id,
                    'kunden_id'      => $r['kunden_id']    ?? ($bestellung->kunden_id ?? null),
                    'obj_id'         => $r['obj_id']       ?? ($bestellung->obj_id    ?? null),
                    'artikel_id'     => $r['artikel_id']   ?? ($bestellung->artikel_id?? null),
                    'nu_id'          => $r['nu_id']        ?? ($bestellung->nu_id     ?? null),
                    'datum'          => $r['datum'],
                    'beschreibung'   => $r['beschreibung'] ?? null,
                    'ist_start'      => $r['ist_start']    ?? null,
                    'ist_ende'       => $r['ist_ende']     ?? null,
                    'schichtart'     => $r['schichtart']   ?? 'tagdienst',
                    'hms_bestaetigt' => 'ausstehend',
                    'created_at'     => now(),
                ], fn($v) => $v !== null && $v !== '');
                DB::connection('tenant')->table('einsaetze')->insert($insert);

                // Optional worker assignment via join table.
                if (!empty($r['dienst_nr'])) {
                    DB::connection('tenant')->table('einsatz_mitarbeiter')->insert([
                        'einsatz_id' => $einsatz_id,
                        'dienst_nr'  => $r['dienst_nr'],
                        'sortierung' => 0,
                    ]);
                }

                $created[] = $einsatz_id;
            }
        });

        return [
            'success'      => true,
            'imported'     => count($created),
            'einsatz_ids'  => $created,
            'skipped'      => count($rows) - count($valid),
        ];
    }

    private function previewAssignMitarbeiter(array $a): array
    {
        [$einsatz, $worker] = $this->loadEinsatzForAssignment($a);

        $already = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('einsatz_id', $einsatz->einsatz_id)
            ->where('dienst_nr', $worker->dienst_nr)
            ->exists();
        if ($already) {
            throw new \InvalidArgumentException(
                "Mitarbeiter {$worker->dienst_nr} ist Einsatz {$einsatz->einsatz_id} bereits zugewiesen."
            );
        }

        $warnings = $this->assignmentWarnings($einsatz, $worker);
        $zeit = substr((string)($einsatz->ist_start ?? ''), 0, 5) . ' – ' . substr((string)($einsatz->ist_ende ?? ''), 0, 5);

        return [
            'titel'  => "Mitarbeiter zu Einsatz {$einsatz->einsatz_id} zuweisen",
            'felder' => [
                ['Mitarbeiter', $worker->dienst_nr . ' — ' . trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? ''))],
                ['Datum',       (string)$einsatz->datum],
                ['Zeit',        trim($zeit, ' –') ?: '—'],
                ['Kunde',       $einsatz->kundenname ?? '—'],
                ['Objekt',      $einsatz->objektname ?? '—'],
                ['Status',      (string)($einsatz->hms_bestaetigt ?? 'ausstehend')],
            ],
            'hinweis' => $warnings
                ? implode(' ', $warnings) . ' Trotzdem zuweisen?'
                : 'Keine Konflikte gefunden.',
        ];
    }

    private function assignMitarbeiter(array $a, int $userId): array
    {
        [$einsatz, $worker] = $this->loadEinsatzForAssignment($a);

        $already = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('einsatz_id', $einsatz->einsatz_id)
            ->where('dienst_nr', $worker->dienst_nr)
            ->exists();
        if ($already) {
            throw new \InvalidArgumentException('Mitarbeiter ist diesem Einsatz bereits zugewiesen.');
        }

        $maxSort = (int) DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('einsatz_id', $einsatz->einsatz_id)->max('sortierung');

        DB::connection('tenant')->table('einsatz_mitarbeiter')->insert([
            'einsatz_id' => $einsatz->einsatz_id,
            'dienst_nr'  => $worker->dienst_nr,
            'sortierung' => $maxSort + 1,
        ]);

        // Re-attach an already-finished clock record (worker clocked out
        // before being re-assigned).
        \App\Http\Controllers\Api\WorkerAppZeitController::backfillWorkerActuals(
            (string) $einsatz->einsatz_id, (string) $worker->dienst_nr);

        return [
            'success'    => true,
            'einsatz_id' => $einsatz->einsatz_id,
            'dienst_nr'  => $worker->dienst_nr,
            'warnungen'  => $this->assignmentWarnings($einsatz, $worker),
            'note'       => 'Zugewiesen als Entwurf. Zum Versenden des Einsatzplans den Einsatz in der Disposition auf "geplant" setzen.',
            '_rollback'  => [
                'delete' => [
                    'table' => 'einsatz_mitarbeiter',
                    'where' => ['einsatz_id' => $einsatz->einsatz_id, 'dienst_nr' => $worker->dienst_nr],
                ],
            ],
        ];
    }

    private function previewRemoveMitarbeiter(array $a): array
    {
        [$einsatz, $worker] = $this->loadEinsatzForAssignment($a);

        $assigned = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('einsatz_id', $einsatz->einsatz_id)
            ->where('dienst_nr', $worker->dienst_nr)
            ->exists();
        if (!$assigned) {
            throw new \InvalidArgumentException(
                "Mitarbeiter {$worker->dienst_nr} ist Einsatz {$einsatz->einsatz_id} gar nicht zugewiesen."
            );
        }

        $planned = ($einsatz->pruefung ?? null) === 'geplant';

        return [
            'titel'  => "Mitarbeiter von Einsatz {$einsatz->einsatz_id} entfernen",
            'felder' => [
                ['Mitarbeiter', $worker->dienst_nr . ' — ' . trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? ''))],
                ['Datum',       (string)$einsatz->datum],
                ['Kunde',       $einsatz->kundenname ?? '—'],
                ['Objekt',      $einsatz->objektname ?? '—'],
                ['Status',      $planned ? 'geplant' : (string)($einsatz->hms_bestaetigt ?? 'ausstehend')],
            ],
            'hinweis' => $planned
                ? 'Der Einsatz ist bereits GEPLANT — der Mitarbeiter wird per App-Benachrichtigung über die Stornierung informiert.'
                : 'Entwurfs-Zuweisung — es wird keine Benachrichtigung versendet.',
        ];
    }

    private function removeMitarbeiter(array $a, int $userId): array
    {
        [$einsatz, $worker] = $this->loadEinsatzForAssignment($a);

        $deleted = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('einsatz_id', $einsatz->einsatz_id)
            ->where('dienst_nr', $worker->dienst_nr)
            ->delete();
        if ($deleted === 0) {
            throw new \InvalidArgumentException('Mitarbeiter war diesem Einsatz nicht zugewiesen.');
        }

        // Same rule as DispositionController::saveAssignment — revoke the
        // 'geplant' state only when NO workers remain at all, neither
        // internal nor NU.
        $remaining = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('einsatz_id', $einsatz->einsatz_id)->count();
        $hasNuWorkers = DB::connection('tenant')->table('nu_schicht_positionen as nsp')
            ->join('nu_position_zuweisungen as npz', 'npz.position_id', '=', 'nsp.id')
            ->where('nsp.einsatz_id', $einsatz->einsatz_id)
            ->exists();
        $pruefungCleared = false;
        if ($remaining === 0 && !$hasNuWorkers && ($einsatz->pruefung ?? null) === 'geplant') {
            $unplanUpdate = ['pruefung' => null];
            // Same "Plan aufgehoben" audit stamp as the planner path.
            if (Schema::connection('tenant')->hasColumn('einsaetze', 'plan_aufgehoben_at')) {
                $unplanUpdate['plan_aufgehoben_at'] = now();
            }
            DB::connection('tenant')->table('einsaetze')
                ->where('einsatz_id', $einsatz->einsatz_id)
                ->update($unplanUpdate);
            $pruefungCleared = true;
        }

        // Planned shift → the worker already got the "Neuer Einsatzplan"
        // notification, so tell them it was cancelled (in-app + push,
        // same payload shape as the planner's cancellation path).
        $notified = false;
        if (($einsatz->pruefung ?? null) === 'geplant') {
            try {
                $datumFmt = \Carbon\Carbon::parse($einsatz->datum)->format('d.m.Y');
                $zeit  = substr((string)($einsatz->ist_start ?? ''), 0, 5) . ' – '
                       . substr((string)($einsatz->ist_ende  ?? ''), 0, 5);
                $title = 'Einsatz storniert: ' . $datumFmt;
                $body  = (($einsatz->objektname ?? null) ?: (($einsatz->kundenname ?? null) ?: 'Einsatz')) . ' · ' . $zeit;
                DB::connection('tenant')->table('worker_notifications')->insert([
                    'mitarbeiter_id' => $worker->id,
                    'dienst_nr'      => $worker->dienst_nr,
                    'type'           => 'shift.storniert',
                    'title'          => $title,
                    'body'           => $body,
                    'payload'        => json_encode(['einsatz_id' => $einsatz->einsatz_id, 'datum' => $einsatz->datum], JSON_UNESCAPED_UNICODE),
                    'gelesen'        => 0,
                    'created_at'     => now(),
                ]);
                \App\Services\WorkerPushService::push((string) $worker->dienst_nr, $title, $body, 'plan');
                $notified = true;
            } catch (\Throwable $e) {
                \Log::warning('AI removeMitarbeiter notification failed: ' . $e->getMessage());
            }
        }

        return [
            'success'          => true,
            'einsatz_id'       => $einsatz->einsatz_id,
            'dienst_nr'        => $worker->dienst_nr,
            'benachrichtigt'   => $notified,
            'pruefung_zurueckgesetzt' => $pruefungCleared,
        ];
    }

    /**
     * Resolve the bestellung an einsatz should hang off. Accepts:
     *   - a real bestell_id  (B-YYYYMM-NNNN)
     *   - an eingang_id      (BE-YYYYMM-NNNN) — the LLM regularly
     *     confuses the two; we map it to the linked bestellung.
     * Throws with a helpful message when neither matches, BEFORE the
     * insert — previously this surfaced as a raw SQLSTATE[23000] FK
     * error in the chat (child row: einsaetze.bestell_id).
     */
    private function resolveBestellungForEinsatz(string $input): object
    {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('bestell_id ist Pflicht.');
        }

        $b = DB::connection('tenant')->table('bestellungen')
            ->where('bestell_id', $input)->first();
        // Bereich guard: an out-of-scope bestellung resolves to
        // "not found" so a restricted user learns nothing about
        // rows outside his Bereich.
        if ($b && !BereichScope::allowsBestellung(request()->user(), $b->bestell_id)) {
            $b = null;
        }
        if ($b) return $b;

        // BE-… → look up the bestellung created for that bestelleingang.
        $b = DB::connection('tenant')->table('bestellungen')
            ->where('eingang_id', $input)->first();
        if ($b && !BereichScope::allowsBestellung(request()->user(), $b->bestell_id)) {
            $b = null;
        }
        if ($b) return $b;

        throw new \InvalidArgumentException(
            "Bestellung '{$input}' wurde nicht gefunden. "
            . 'Erwartet wird eine Bestell-ID im Format B-YYYYMM-NNNN '
            . '(eine BE-… Eingang-ID wird automatisch aufgelöst, wenn eine verknüpfte Bestellung existiert). '
            . 'Nutze bestellungen_status oder bestelleingang_status, um die richtige ID zu finden.'
        );
    }

    /**
     * Normalise + validate the array of einsatz rows the LLM proposes.
     * Each returned row carries `_valid` (bool) and `_error` (string)
     * so both preview and execute can use the same shape.
     */
    private function normaliseBulkEinsaetze(array $a): array
    {
        $rows = $a['rows'] ?? $a['einsaetze'] ?? null;
        if (!is_array($rows) || count($rows) === 0) {
            throw new \InvalidArgumentException('Parameter "rows" muss ein Array von Einsätzen sein.');
        }
        if (count($rows) > self::BULK_EINSATZ_MAX) {
            throw new \InvalidArgumentException(
                'Zu viele Zeilen (' . count($rows) . '). Maximum pro Aufruf: ' . self::BULK_EINSATZ_MAX
            );
        }

        $out = [];
        foreach ($rows as $i => $raw) {
            if (!is_array($raw)) {
                $out[] = ['_valid' => false, '_error' => 'Zeile ist kein Objekt', 'bestell_id' => null, 'datum' => null];
                continue;
            }
            try {
                $bestell = trim((string)($raw['bestell_id'] ?? ''));
                if ($bestell === '') {
                    throw new \InvalidArgumentException('bestell_id fehlt');
                }
                $datum = $this->date($raw, 'datum');
                if (!$datum) {
                    throw new \InvalidArgumentException('datum fehlt');
                }
                // Time fields: accept HH:MM or HH:MM:SS, normalise to HH:MM:SS
                $start = $this->normaliseTime($raw['ist_start'] ?? $raw['start'] ?? null);
                $ende  = $this->normaliseTime($raw['ist_ende']  ?? $raw['ende']  ?? null);

                $schichtart = strtolower(trim((string)($raw['schichtart'] ?? 'tagdienst')));
                if (!in_array($schichtart, ['tagdienst', 'nachtdienst', 'wochenende', 'feiertag'], true)) {
                    $schichtart = 'tagdienst';
                }

                $out[] = [
                    '_valid'       => true,
                    '_error'       => '',
                    'bestell_id'   => $bestell,
                    'kunden_id'    => $this->str($raw, 'kunden_id'),
                    'obj_id'       => $this->str($raw, 'obj_id'),
                    'artikel_id'   => $this->str($raw, 'artikel_id'),
                    'nu_id'        => $this->str($raw, 'nu_id'),
                    'dienst_nr'    => $this->str($raw, 'dienst_nr')
                                  ?? $this->str($raw, 'mitarbeiter_id'),
                    'datum'        => $datum,
                    'ist_start'    => $start,
                    'ist_ende'     => $ende,
                    'schichtart'   => $schichtart,
                    'beschreibung' => $this->str($raw, 'beschreibung'),
                ];
            } catch (\Throwable $e) {
                $out[] = [
                    '_valid'     => false,
                    '_error'     => $e->getMessage(),
                    'bestell_id' => $raw['bestell_id'] ?? null,
                    'datum'      => $raw['datum']      ?? null,
                    'ist_start'  => $raw['ist_start']  ?? ($raw['start'] ?? null),
                    'ist_ende'   => $raw['ist_ende']   ?? ($raw['ende']  ?? null),
                    'dienst_nr'  => $raw['dienst_nr']  ?? ($raw['mitarbeiter_id'] ?? null),
                ];
            }
        }

        // Second pass: Bereich scope per distinct bestell_id — checked
        // once per ID (local per-call cache, never static) so a 200-row
        // import doesn't fire 200 identical queries.
        $user = request()->user();
        $scopeCache = [];
        foreach ($out as &$r) {
            if (!$r['_valid']) continue;
            $bid = (string) $r['bestell_id'];
            if (!array_key_exists($bid, $scopeCache)) {
                $scopeCache[$bid] = BereichScope::allowsBestellung($user, $bid);
            }
            if (!$scopeCache[$bid]) {
                $r['_valid'] = false;
                $r['_error'] = 'Bestellung außerhalb deines Bereichs';
            }
        }
        unset($r);

        return $out;
    }

    /** Shared loader + guard for both assignment tools. */
    private function loadEinsatzForAssignment(array $a): array
    {
        $einsatzId = trim((string)($a['einsatz_id'] ?? ''));
        $dienstNr  = trim((string)($a['dienst_nr'] ?? ''));
        if ($einsatzId === '' || $dienstNr === '') {
            throw new \InvalidArgumentException('einsatz_id und dienst_nr sind Pflicht.');
        }

        $einsatz = DB::connection('tenant')->table('einsaetze as e')
            ->leftJoin('kunden as k',  'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id',    '=', 'o.obj_id')
            ->where('e.einsatz_id', $einsatzId)
            ->select('e.*', 'k.kundenname', 'o.objektname')
            ->first();
        if (!$einsatz || !BereichScope::allowsEinsatz(request()->user(), $einsatzId)) {
            throw new \InvalidArgumentException("Einsatz {$einsatzId} nicht gefunden.");
        }

        if (($einsatz->hms_bestaetigt ?? '') === 'ja') {
            throw new \InvalidArgumentException(
                "Einsatz {$einsatzId} ist bereits BESTÄTIGT — Zuweisungen können nicht mehr geändert werden. "
                . 'Der Einsatz müsste zuerst in der Disposition zurückgesetzt werden.'
            );
        }

        $worker = $this->resolveExistingId(
            'mitarbeiter', 'dienst_nr', 'name', $dienstNr, 'mitarbeiter'
        );
        if (!BereichScope::allowsWorker(request()->user(), $worker->dienst_nr)) {
            throw new \InvalidArgumentException("Mitarbeiter {$dienstNr} nicht gefunden.");
        }

        return [$einsatz, $worker];
    }

    /** Advisory conflict checks: absence + overlapping einsatz on the same day. */
    private function assignmentWarnings($einsatz, $worker): array
    {
        $warnings = [];

        $absent = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->where('dienst_nr', $worker->dienst_nr)
            ->where('startdatum', '<=', $einsatz->datum)
            ->where('enddatum', '>=', $einsatz->datum)
            ->whereIn(DB::connection('tenant')->raw("COALESCE(status,'genehmigt')"), ['genehmigt', 'pending'])
            ->first();
        if ($absent) {
            $warnings[] = "⚠ Mitarbeiter ist am {$einsatz->datum} abwesend ({$absent->typ}).";
        }

        $overlap = DB::connection('tenant')->table('einsatz_mitarbeiter as em')
            ->join('einsaetze as e2', 'em.einsatz_id', '=', 'e2.einsatz_id')
            ->where('em.dienst_nr', $worker->dienst_nr)
            ->where('e2.datum', $einsatz->datum)
            ->where('e2.einsatz_id', '!=', $einsatz->einsatz_id)
            ->first(['e2.einsatz_id', 'e2.ist_start', 'e2.ist_ende']);
        if ($overlap) {
            $zeit = substr((string)($overlap->ist_start ?? ''), 0, 5) . '–' . substr((string)($overlap->ist_ende ?? ''), 0, 5);
            $warnings[] = "⚠ Mitarbeiter hat am selben Tag bereits Einsatz {$overlap->einsatz_id} ({$zeit}).";
        }

        if ((int)($worker->aktiv ?? 1) !== 1) {
            $warnings[] = '⚠ Mitarbeiter ist als INAKTIV markiert.';
        }

        return $warnings;
    }
}
