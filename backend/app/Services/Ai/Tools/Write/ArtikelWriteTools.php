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

class ArtikelWriteTools implements WriteToolsProvider
{
    use ToolHelpers;

    /**
     * Whitelist of columns update_artikel may touch. gewinn is NOT in
     * the list: it is derived from preis_kunde − nu_preis — we
     * recalculate it whenever either price changes (same convention
     * as updateObjekt).
     */
    private const ARTIKEL_EDITABLE = [
        'artikel_name', 'obj_id', 'bereich', 'modell',
        'einheit', 'preis_kunde', 'nu_preis', 'aktiv',
    ];

    public function handles(string $name): bool
    {
        return match ($name) {
            'create_artikel' => true,
            'update_artikel' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('create_artikel',
                'Legt einen neuen Artikel/Position unter einem bestehenden Objekt an.',
                [
                    'obj_id'        => ['type' => 'string', 'description' => 'Pflicht — Format ohne Bindestrich, z.B. "O006"'],
                    'artikel_name'  => ['type' => 'string', 'description' => 'Pflicht'],
                    'bereich'       => ['type' => 'string'],
                    'modell'        => ['type' => 'string', 'enum' => ['Standard', 'Pro Einsatz', 'Pro Stunde', 'Monatspauschale']],
                    'preis_kunde'   => ['type' => 'number'],
                    'nu_preis'      => ['type' => 'number'],
                    'einheit'       => ['type' => 'string', 'description' => 'z.B. "Std", "Stk"'],
                ], ['obj_id', 'artikel_name']),

            $this->fn('update_artikel',
                'Ändert Daten eines bestehenden Artikels. Nur die übergebenen Felder werden geändert.',
                [
                    'artikel_id'   => ['type' => 'string', 'description' => 'Pflicht — Format mit Bindestrich, z.B. "A-008"'],
                    'artikel_name' => ['type' => 'string'],
                    'obj_id'       => ['type' => 'string', 'description' => 'optional — Artikel verschieben'],
                    'bereich'      => ['type' => 'string'],
                    'modell'       => ['type' => 'string', 'enum' => ['Standard', 'Pro Einsatz', 'Pro Stunde', 'Monatspauschale']],
                    'preis_kunde'  => ['type' => 'number'],
                    'nu_preis'     => ['type' => 'number'],
                    'einheit'      => ['type' => 'string'],
                    'aktiv'        => ['type' => 'integer', 'enum' => [0, 1]],
                ], ['artikel_id']),
        ];
    }

    public function preview(string $name, array $args): array
    {
        return match ($name) {
            'create_artikel' => $this->previewCreateArtikel($args),
            'update_artikel' => $this->previewUpdateArtikel($args),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    public function execute(string $name, array $args, int $userId): array
    {
        return match ($name) {
            'create_artikel' => $this->createArtikel($args, $userId),
            'update_artikel' => $this->updateArtikel($args, $userId),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    private function previewCreateArtikel(array $a): array
    {
        $name = trim((string)($a['artikel_name'] ?? ''));
        $obj_id = trim((string)($a['obj_id'] ?? ''));
        if ($name === '' || $obj_id === '') {
            throw new \InvalidArgumentException('artikel_name und obj_id sind Pflicht.');
        }
        $obj = DB::connection('tenant')->table('objekte')->where('obj_id', $obj_id)->first();
        if (!$obj || !BereichScope::allowsObjekt(request()->user(), $obj_id)) {
            throw new \InvalidArgumentException("Objekt {$obj_id} nicht gefunden.");
        }
        $b = $this->str($a, 'bereich');
        if ($b !== null && !BereichScope::bereichInputAllowed(request()->user(), $b)) {
            throw new \InvalidArgumentException("Bereich '{$b}' liegt außerhalb deiner Berechtigung.");
        }

        return [
            'titel'  => 'Neuen Artikel anlegen',
            'felder' => [
                ['Objekt',         $obj_id . ' — ' . $obj->objektname],
                ['Artikelname',    $name],
                ['Bereich',        trim((string)($a['bereich'] ?? '—')) ?: '—'],
                ['Modell',         trim((string)($a['modell'] ?? 'Pro Einsatz'))],
                ['Preis (Kunde)',  number_format((float)($a['preis_kunde'] ?? 0), 2, ',', '.') . ' €'],
                ['Preis (NU)',     number_format((float)($a['nu_preis'] ?? 0), 2, ',', '.') . ' €'],
                ['Einheit',        trim((string)($a['einheit'] ?? '—')) ?: '—'],
            ],
        ];
    }

    private function createArtikel(array $a, int $userId): array
    {
        // Prefix MUST include the trailing hyphen — the DB stores
        // artikel IDs as `A-001`, `A-002`, … and IdGenerator's
        // SUBSTRING/CAST logic only works if prefix length matches.
        // Using just 'A' caused SUBSTRING('A-001', 2) = '-001',
        // CAST AS UNSIGNED wrapped to BIGINT-max, +1 overflowed
        // into a PHP float, and the resulting candidate was
        // 'A9.2233720368548E+18'. ArtikelController already does
        // this correctly; this brings the AI path in line.
        $objId = trim((string) ($a['obj_id'] ?? ''));
        if ($objId !== '' && !BereichScope::allowsObjekt(request()->user(), $objId)) {
            throw new \InvalidArgumentException("Objekt {$objId} nicht gefunden.");
        }
        $b = $this->str($a, 'bereich');
        if ($b !== null && !BereichScope::bereichInputAllowed(request()->user(), $b)) {
            throw new \InvalidArgumentException("Bereich '{$b}' liegt außerhalb deiner Berechtigung.");
        }

        $artikel_id = IdGenerator::next('artikel', 'artikel_id', 'A-');
        $preis_k = (float)($a['preis_kunde'] ?? 0);
        $preis_n = (float)($a['nu_preis'] ?? 0);
        $row = array_filter([
            'artikel_id'   => $artikel_id,
            'obj_id'       => $this->str($a, 'obj_id'),
            'artikel_name' => $this->str($a, 'artikel_name'),
            'bereich'      => $this->str($a, 'bereich'),
            'modell'       => $this->str($a, 'modell') ?: 'Pro Einsatz',
            'preis_kunde'  => $preis_k,
            'nu_preis'     => $preis_n,
            'gewinn'       => max(0, $preis_k - $preis_n),
            'einheit'      => $this->str($a, 'einheit'),
            'aktiv'        => 1,
            'created_at'   => now(),
        ], fn($v) => $v !== null && $v !== '');
        DB::connection('tenant')->table('artikel')->insert($row);
        // Service type follows the object's Einsatzarten (no own arten via AI).
        \App\Support\SecurityServices::syncArtikel([$artikel_id]);
        return ['success' => true, 'artikel_id' => $artikel_id, 'artikel_name' => $a['artikel_name'] ?? ''];
    }

    private function previewUpdateArtikel(array $a): array
    {
        $existing = $this->resolveExistingId(
            'artikel', 'artikel_id', 'artikel_name',
            (string)($a['artikel_id'] ?? ''), 'artikel'
        );
        $id = $existing->artikel_id;
        if (!BereichScope::allowsArtikel(request()->user(), $id)) {
            throw new \InvalidArgumentException("Artikel {$id} nicht gefunden.");
        }

        // If the AI proposes moving the artikel to another object,
        // validate the target up front and show the human name.
        $newObjLabel = null;
        if (array_key_exists('obj_id', $a) && trim((string)$a['obj_id']) !== ''
            && (string)$a['obj_id'] !== (string)$existing->obj_id) {
            $newObj = $this->resolveExistingId(
                'objekte', 'obj_id', 'objektname',
                (string)$a['obj_id'], 'objekt'
            );
            if (!BereichScope::allowsObjekt(request()->user(), $newObj->obj_id)) {
                throw new \InvalidArgumentException('Ziel-Objekt liegt außerhalb deines Bereichs.');
            }
            $newObjLabel = $newObj->obj_id . ' — ' . $newObj->objektname;
            // Canonicalise so the preview diff and the execute step
            // see the same value.
            $a['obj_id'] = $newObj->obj_id;
        }

        $changes = [];
        foreach (self::ARTIKEL_EDITABLE as $col) {
            if (!array_key_exists($col, $a)) continue;
            $newVal = is_string($a[$col]) ? trim($a[$col]) : $a[$col];
            if ((string)$newVal !== (string)($existing->{$col} ?? '')) {
                $changes[$col] = ['old' => $existing->{$col} ?? '—', 'new' => $newVal];
            }
        }
        if (empty($changes)) throw new \InvalidArgumentException('Keine Änderungen erkannt.');
        if (isset($changes['bereich'])
            && !BereichScope::bereichWriteAllowed(request()->user(), $changes['bereich']['new'], $existing->bereich ?? null)) {
            throw new \InvalidArgumentException('Der neue Bereich liegt außerhalb deiner Berechtigung.');
        }

        $fmt = function ($col, $val) use ($newObjLabel) {
            if ($val === null || $val === '') return '—';
            if (in_array($col, ['preis_kunde', 'nu_preis'], true)) {
                return number_format((float)$val, 2, ',', '.') . ' €';
            }
            // For obj_id we want "O006 — Motocross WM" not just the bare ID
            if ($col === 'obj_id' && $newObjLabel !== null) {
                return $newObjLabel;
            }
            return (string)$val;
        };

        return [
            'titel'  => "Artikel {$id} ({$existing->artikel_name}) ändern",
            'felder' => array_map(
                fn($k, $v) => [$k, $fmt($k, $v['old']) . '  →  ' . $fmt($k, $v['new'])],
                array_keys($changes), array_values($changes)
            ),
            'hinweis' => count($changes) . ' Feld(er) werden geändert.',
        ];
    }

    private function updateArtikel(array $a, int $userId): array
    {
        $existing = $this->resolveExistingId(
            'artikel', 'artikel_id', 'artikel_name',
            (string)($a['artikel_id'] ?? ''), 'artikel'
        );
        $id = $existing->artikel_id;
        if (!BereichScope::allowsArtikel(request()->user(), $id)) {
            throw new \InvalidArgumentException("Artikel {$id} nicht gefunden.");
        }

        // Same target-object validation as in preview, so a direct
        // execute call (e.g. retry path) cannot bypass it.
        if (array_key_exists('obj_id', $a) && trim((string)$a['obj_id']) !== '') {
            $newObj = $this->resolveExistingId(
                'objekte', 'obj_id', 'objektname',
                (string)$a['obj_id'], 'objekt'
            );
            if (!BereichScope::allowsObjekt(request()->user(), $newObj->obj_id)) {
                throw new \InvalidArgumentException('Ziel-Objekt liegt außerhalb deines Bereichs.');
            }
            $a['obj_id'] = $newObj->obj_id;
        }

        $update = []; $rollback = [];
        foreach (self::ARTIKEL_EDITABLE as $col) {
            if (!array_key_exists($col, $a)) continue;
            $update[$col] = is_string($a[$col]) ? trim($a[$col]) : $a[$col];
            $rollback[$col] = $existing->{$col} ?? null;
        }
        if (empty($update)) throw new \InvalidArgumentException('Keine Felder zum Aktualisieren.');
        if (array_key_exists('bereich', $update)
            && !BereichScope::bereichWriteAllowed(request()->user(), $update['bereich'], $existing->bereich ?? null)) {
            throw new \InvalidArgumentException('Der neue Bereich liegt außerhalb deiner Berechtigung.');
        }

        if (array_key_exists('preis_kunde', $update) || array_key_exists('nu_preis', $update)) {
            $pk = (float)($update['preis_kunde'] ?? $existing->preis_kunde ?? 0);
            $np = (float)($update['nu_preis']    ?? $existing->nu_preis    ?? 0);
            $update['gewinn']   = max(0, $pk - $np);
            $rollback['gewinn'] = $existing->gewinn ?? null;
        }
        $update['updated_at'] = now();

        DB::connection('tenant')->table('artikel')->where('artikel_id', $id)->update($update);

        return [
            'success'      => true,
            'artikel_id'   => $id,
            'artikel_name' => $existing->artikel_name,
            '_rollback'    => ['table' => 'artikel', 'key' => ['artikel_id' => $id], 'data' => $rollback],
        ];
    }
}
