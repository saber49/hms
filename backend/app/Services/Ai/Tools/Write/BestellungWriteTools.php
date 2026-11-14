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

class BestellungWriteTools implements WriteToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'create_bestellung' => true,
            'update_bestellung' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('create_bestellung',
                'Legt eine reine Bestellung (Auftrag) OHNE Tage/Einsätze an. '
                . 'Die Bestell-ID (Format B-YYYYMM-NNNN) wird automatisch generiert — du brauchst sie nicht anzugeben. '
                . 'Nutze diesen Aufruf NUR wenn der Nutzer ausschließlich eine Bestellung (ohne Tage) anlegen möchte. '
                . 'Wenn ein vollständiger Bestelleingang mit Schichten/Tagen gemeint ist, nutze stattdessen `create_bestelleingang`.',
                [
                    'kunden_id'       => ['type' => 'string', 'description' => 'Pflicht — z.B. "K028"'],
                    'obj_id'          => ['type' => 'string', 'description' => 'Pflicht — z.B. "O006"'],
                    'datum_von'       => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'datum_bis'       => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'zeit_von'        => ['type' => 'string', 'description' => 'HH:MM'],
                    'zeit_bis'        => ['type' => 'string', 'description' => 'HH:MM'],
                    'artikel_id'      => ['type' => 'string'],
                    'menge_einheit'   => ['type' => 'string', 'description' => 'z.B. "Std"'],
                    'soll_std_betrag' => ['type' => 'number'],
                    'kunden_obj_nr'   => ['type' => 'string'],
                    'nu_id'           => ['type' => 'string'],
                ], ['kunden_id', 'obj_id']),

            $this->fn('update_bestellung',
                'Ändert Felder EINER bestehenden Bestellung (Zeitraum, Zeiten, Menge/Einheit, '
                . 'Kunden-Objekt-Nr., NU, Status). Nur übergebene Felder werden geändert. '
                . 'Status "storniert" storniert die Bestellung — die Einsätze behalten ihren eigenen '
                . 'Status (für Einsatz-Stornos update_einsatz nutzen).',
                [
                    'bestell_id'      => ['type' => 'string', 'description' => 'Pflicht — z.B. "B-202605-4493"'],
                    'datum_von'       => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'datum_bis'       => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'zeit_von'        => ['type' => 'string', 'description' => 'HH:MM'],
                    'zeit_bis'        => ['type' => 'string', 'description' => 'HH:MM'],
                    'menge_einheit'   => ['type' => 'string', 'description' => 'z.B. "Std"'],
                    'soll_std_betrag' => ['type' => 'number'],
                    'kunden_obj_nr'   => ['type' => 'string'],
                    'nu_id'           => ['type' => 'string', 'description' => 'NU-ID oder leer zum Entfernen'],
                    'status'          => ['type' => 'string', 'enum' => ['offen', 'abgeschlossen', 'storniert']],
                ], ['bestell_id']),
        ];
    }

    public function preview(string $name, array $args): array
    {
        return match ($name) {
            'create_bestellung' => $this->previewCreateBestellung($args),
            'update_bestellung' => $this->previewUpdateBestellung($args),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    public function execute(string $name, array $args, int $userId): array
    {
        return match ($name) {
            'create_bestellung' => $this->createBestellung($args, $userId),
            'update_bestellung' => $this->updateBestellung($args, $userId),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    private function previewCreateBestellung(array $a): array
    {
        // Validate that the referenced kunde and objekt actually exist
        // up front. NOTE the column-name asymmetry: `kunden.kunde_id`
        // is the PK (singular), but `bestellungen.kunden_id` is the FK
        // (plural) — same convention as `objekte.kunden_id`.
        $kunde = $this->resolveExistingId(
            'kunden', 'kunde_id', 'kundenname',
            (string)($a['kunden_id'] ?? ''), 'kunde'
        );
        $objekt = $this->resolveExistingId(
            'objekte', 'obj_id', 'objektname',
            (string)($a['obj_id'] ?? ''), 'objekt'
        );
        $this->assertBestellungRefsInScope($kunde->kunde_id, $objekt->obj_id, $a);

        $datumVon = $this->date($a, 'datum_von');
        $datumBis = $this->date($a, 'datum_bis');
        $fmtDate  = fn($d) => $d ? \Carbon\Carbon::parse($d)->format('d.m.Y') : '—';

        return [
            'titel'  => 'Neue Bestellung anlegen',
            'felder' => [
                ['Kunde',          $kunde->kunde_id . ' — ' . $kunde->kundenname],
                ['Objekt',         $objekt->obj_id . ' — ' . $objekt->objektname],
                ['Zeitraum',       $fmtDate($datumVon) . ' – ' . $fmtDate($datumBis)],
                ['Zeit',           trim(($a['zeit_von'] ?? '') . ' – ' . ($a['zeit_bis'] ?? ''), ' –') ?: '—'],
                ['Artikel',        trim((string)($a['artikel_id'] ?? '')) ?: '—'],
                ['Soll-Stunden',   isset($a['soll_std_betrag']) ? number_format((float)$a['soll_std_betrag'], 2, ',', '.') . ' Std' : '—'],
                ['Kunden-Obj-Nr.', trim((string)($a['kunden_obj_nr'] ?? '')) ?: '—'],
                ['Bestell-ID',     'wird automatisch generiert (B-…)'],
            ],
        ];
    }

    private function createBestellung(array $a, int $userId): array
    {
        $kunde  = $this->resolveExistingId('kunden',  'kunde_id', 'kundenname',
            (string)($a['kunden_id'] ?? ''), 'kunde');
        $objekt = $this->resolveExistingId('objekte', 'obj_id', 'objektname',
            (string)($a['obj_id']   ?? ''), 'objekt');
        $this->assertBestellungRefsInScope($kunde->kunde_id, $objekt->obj_id, $a);

        // Same generator and "B-YYYYMM-NNNN" shape that OrderController
        // and IncomingOrderController already use, so AI-created orders
        // are indistinguishable from manually-created ones.
        $bestell_id = IdGenerator::uniqueId('bestellungen', 'bestell_id', 'B-');

        $datumVon = $this->date($a, 'datum_von');
        $datumBis = $this->date($a, 'datum_bis');

        $row = array_filter([
            'bestell_id'      => $bestell_id,
            'kunden_id'       => $kunde->kunde_id,   // FK is plural, PK is singular — bridge here
            'obj_id'          => $objekt->obj_id,
            'artikel_id'      => $this->str($a, 'artikel_id'),
            'kunden_obj_nr'   => $this->str($a, 'kunden_obj_nr'),
            'monat'           => $datumVon ? (int) \Carbon\Carbon::parse($datumVon)->format('n') : null,
            'jahr'            => $datumVon ? (int) \Carbon\Carbon::parse($datumVon)->format('Y') : null,
            'datum_von'       => $datumVon,
            'datum_bis'       => $datumBis,
            'zeit_von'        => $this->str($a, 'zeit_von'),
            'zeit_bis'        => $this->str($a, 'zeit_bis'),
            'menge_einheit'   => $this->str($a, 'menge_einheit'),
            'soll_std_betrag' => isset($a['soll_std_betrag']) ? (float) $a['soll_std_betrag'] : null,
            'status'          => 'offen',
            'nu_id'           => $this->str($a, 'nu_id'),
            'created_at'      => now(),
        ], fn($v) => $v !== null && $v !== '');

        DB::connection('tenant')->table('bestellungen')->insert($row);

        return [
            'success'    => true,
            'bestell_id' => $bestell_id,
            'kunden_id'  => $kunde->kunde_id,
            'obj_id'     => $objekt->obj_id,
        ];
    }

    // ---------------------------------------------------------------
    // update_bestellung
    // ---------------------------------------------------------------
    private function previewUpdateBestellung(array $a): array
    {
        [$b, $changes] = $this->resolveBestellungChanges($a);

        return [
            'titel'   => "Bestellung {$b->bestell_id} ändern",
            'felder'  => array_map(
                fn ($k, $v) => [$k, ($v['old'] ?? '—') . '  →  ' . ($v['new'] ?? '—')],
                array_keys($changes), array_values($changes)
            ),
            'hinweis' => count($changes) . ' Feld(er) werden geändert.'
                . (($changes['status']['new'] ?? null) === 'storniert'
                    ? ' ACHTUNG: Storno der Bestellung — Einsätze behalten ihren eigenen Status.'
                    : ''),
        ];
    }

    private function updateBestellung(array $a, int $userId): array
    {
        [$b, $changes] = $this->resolveBestellungChanges($a);

        $update = [];
        foreach ($changes as $col => $d) {
            $update[$col] = $d['new'] === '—' ? null : $d['new'];
        }
        $update['updated_at'] = now();

        $rollback = [];
        foreach (array_keys($changes) as $col) {
            $rollback[$col] = $b->{$col} ?? null;
        }

        DB::connection('tenant')->table('bestellungen')
            ->where('bestell_id', $b->bestell_id)->update($update);

        return [
            'success'    => true,
            'bestell_id' => $b->bestell_id,
            'changes'    => $changes,
            '_rollback'  => ['table' => 'bestellungen', 'key' => ['bestell_id' => $b->bestell_id], 'data' => $rollback],
        ];
    }

    /**
     * Shared validation for update preview+execute. Returns
     * [bestellungRow, changes] — same "old → new" shape the other
     * write providers use.
     */
    private function resolveBestellungChanges(array $a): array
    {
        $id = trim((string) ($a['bestell_id'] ?? ''));
        if ($id === '') throw new \InvalidArgumentException('bestell_id ist Pflicht.');

        $b = DB::connection('tenant')->table('bestellungen')->where('bestell_id', $id)->first();
        if (!$b || !BereichScope::allowsBestellung(request()->user(), $id)) {
            throw new \InvalidArgumentException("Bestellung {$id} nicht gefunden.");
        }

        $changes = [];

        foreach (['datum_von', 'datum_bis'] as $col) {
            if (!array_key_exists($col, $a)) continue;
            $new = $a[$col] === null || $a[$col] === '' ? null : $this->date($a, $col);
            $old = $b->{$col} !== null ? (string) $b->{$col} : null;
            if ((string) ($new ?? '') !== (string) ($old ?? '')) {
                $changes[$col] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
            }
        }

        foreach (['zeit_von', 'zeit_bis'] as $col) {
            if (!array_key_exists($col, $a)) continue;
            $new = $a[$col] === null || $a[$col] === '' ? null : $this->normalizeTime((string) $a[$col]);
            $old = $b->{$col} !== null ? substr((string) $b->{$col}, 0, 5) : null;
            if ($new !== $old) $changes[$col] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
        }

        foreach (['menge_einheit', 'kunden_obj_nr'] as $col) {
            if (!array_key_exists($col, $a)) continue;
            $new = $this->str($a, $col);
            $old = $b->{$col} !== null ? (string) $b->{$col} : null;
            if ((string) ($new ?? '') !== (string) ($old ?? '')) {
                $changes[$col] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
            }
        }

        if (array_key_exists('soll_std_betrag', $a)) {
            $new = $a['soll_std_betrag'] === null || $a['soll_std_betrag'] === ''
                ? null : round((float) $a['soll_std_betrag'], 2);
            $old = $b->soll_std_betrag !== null ? (float) $b->soll_std_betrag : null;
            if ($new !== $old) $changes['soll_std_betrag'] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
        }

        if (array_key_exists('status', $a)) {
            $new = strtolower(trim((string) $a['status']));
            if (!in_array($new, ['offen', 'abgeschlossen', 'storniert'], true)) {
                throw new \InvalidArgumentException(
                    "Ungültiger status '{$a['status']}' (erlaubt: offen, abgeschlossen, storniert)."
                );
            }
            $old = (string) ($b->status ?? '');
            if ($new !== $old) $changes['status'] = ['old' => $old ?: '—', 'new' => $new];
        }

        if (array_key_exists('nu_id', $a)) {
            $nuInput = $this->str($a, 'nu_id');
            if ($nuInput === null) {
                $new = null; // explicit removal
            } else {
                $nu = $this->resolveExistingId(
                    'nachunternehmer', 'nu_id', 'firmenname', $nuInput, 'nu'
                );
                if (!BereichScope::allowsNu(request()->user(), $nu->nu_id)) {
                    throw new \InvalidArgumentException("Nachunternehmer {$nuInput} nicht gefunden.");
                }
                $new = $nu->nu_id;
            }
            $old = $b->nu_id !== null ? (string) $b->nu_id : null;
            if ((string) ($new ?? '') !== (string) ($old ?? '')) {
                $changes['nu_id'] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
            }
        }

        if (empty($changes)) {
            throw new \InvalidArgumentException('Keine Änderungen erkannt.');
        }
        return [$b, $changes];
    }

    /**
     * Bereich guard for create_bestellung: every referenced entity must
     * be inside the caller's scope, otherwise the order would silently
     * leak out-of-scope kunden/objekte/artikel/nu into his data.
     */
    private function assertBestellungRefsInScope(string $kundeId, string $objId, array $a): void
    {
        $user = request()->user();
        if (!BereichScope::allowsKunde($user, $kundeId)) {
            throw new \InvalidArgumentException("Kunde {$kundeId} liegt außerhalb deines Bereichs.");
        }
        if (!BereichScope::allowsObjekt($user, $objId)) {
            throw new \InvalidArgumentException("Objekt {$objId} liegt außerhalb deines Bereichs.");
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
}
