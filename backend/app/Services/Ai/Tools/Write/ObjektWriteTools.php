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

class ObjektWriteTools implements WriteToolsProvider
{
    use ToolHelpers;

    private const BUNDESLAENDER = ['BW','BY','BE','BB','HB','HH','HE','MV','NI','NW','RP','SL','SN','ST','SH','TH'];

    /**
     * Whitelist of columns update_objekt may touch. gewinn is NOT in
     * the list: it is derived from preis_kunde − nu_preis, we always
     * recalculate it when either price changes.
     */
    private const OBJEKT_EDITABLE = [
        'objektname', 'adresse', 'plz', 'stadt', 'bundesland',
        'ansprechpartner', 'kontakt_telefon', 'kontakt_email',
        'hinweise', 'bereich', 'modell',
        'preis_kunde', 'nu_preis', 'aktiv',
    ];

    public function handles(string $name): bool
    {
        return match ($name) {
            'create_objekt' => true,
            'update_objekt' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('create_objekt',
                'Legt ein neues Objekt für einen bestehenden Kunden an.',
                [
                    'kunden_id'       => ['type' => 'string', 'description' => 'Pflicht — Format ohne Bindestrich, z.B. "K028"'],
                    'objektname'      => ['type' => 'string', 'description' => 'Pflicht'],
                    'adresse'         => ['type' => 'string'],
                    'plz'             => ['type' => 'string'],
                    'stadt'           => ['type' => 'string'],
                    'bundesland'      => ['type' => 'string', 'enum' => ['BW','BY','BE','BB','HB','HH','HE','MV','NI','NW','RP','SL','SN','ST','SH','TH']],
                    'ansprechpartner' => ['type' => 'string'],
                    'kontakt_telefon' => ['type' => 'string'],
                    'kontakt_email'   => ['type' => 'string'],
                    'bereich'         => ['type' => 'string'],
                    'modell'          => ['type' => 'string', 'enum' => ['Standard', 'Pro Einsatz', 'Pro Stunde', 'Monatspauschale']],
                    'preis_kunde'     => ['type' => 'number'],
                    'nu_preis'        => ['type' => 'number'],
                    'hinweise'        => ['type' => 'string'],
                ], ['kunden_id', 'objektname', 'bundesland']),

            $this->fn('update_objekt',
                'Ändert Daten eines bestehenden Objekts. Nur die übergebenen Felder werden geändert.',
                [
                    'obj_id'          => ['type' => 'string', 'description' => 'Pflicht — Format ohne Bindestrich, z.B. "O006"'],
                    'objektname'      => ['type' => 'string'],
                    'adresse'         => ['type' => 'string'],
                    'plz'             => ['type' => 'string'],
                    'stadt'           => ['type' => 'string'],
                    'bundesland'      => ['type' => 'string', 'enum' => ['BW','BY','BE','BB','HB','HH','HE','MV','NI','NW','RP','SL','SN','ST','SH','TH']],
                    'ansprechpartner' => ['type' => 'string'],
                    'kontakt_telefon' => ['type' => 'string'],
                    'kontakt_email'   => ['type' => 'string'],
                    'bereich'         => ['type' => 'string'],
                    'modell'          => ['type' => 'string', 'enum' => ['Standard', 'Pro Einsatz', 'Pro Stunde', 'Monatspauschale']],
                    'preis_kunde'     => ['type' => 'number'],
                    'nu_preis'        => ['type' => 'number'],
                    'hinweise'        => ['type' => 'string'],
                    'aktiv'           => ['type' => 'integer', 'enum' => [0, 1]],
                ], ['obj_id']),
        ];
    }

    public function preview(string $name, array $args): array
    {
        return match ($name) {
            'create_objekt' => $this->previewCreateObjekt($args),
            'update_objekt' => $this->previewUpdateObjekt($args),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    public function execute(string $name, array $args, int $userId): array
    {
        return match ($name) {
            'create_objekt' => $this->createObjekt($args, $userId),
            'update_objekt' => $this->updateObjekt($args, $userId),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    private function previewCreateObjekt(array $a): array
    {
        $name = trim((string)($a['objektname'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('Objektname ist Pflichtfeld.');
        $state = strtoupper(trim((string) ($a['bundesland'] ?? '')));
        if (!in_array($state, self::BUNDESLAENDER, true)) {
            throw new \InvalidArgumentException('Bundesland ist Pflicht und muss ein gültiger 2-stelliger Code sein.');
        }

        $kunde    = $this->resolveExistingId(
            'kunden', 'kunde_id', 'kundenname',
            (string)($a['kunden_id'] ?? ''), 'kunde'
        );
        $kunde_id = $kunde->kunde_id;
        if (!BereichScope::allowsKunde(request()->user(), $kunde_id)) {
            throw new \InvalidArgumentException("Kunde nicht gefunden.");
        }
        $b = $this->str($a, 'bereich');
        if ($b !== null && !BereichScope::bereichInputAllowed(request()->user(), $b)) {
            throw new \InvalidArgumentException("Bereich '{$b}' liegt außerhalb deiner Berechtigung.");
        }

        // Compute the obj_nr_intern up-front so we can show it in the
        // preview. The actual create may pick a slightly different
        // number if rows were inserted in between, but the format is
        // identical so the user knows what to expect.
        $previewObjNrIntern = trim((string)($a['obj_nr_intern'] ?? '')) ?: IdGenerator::objNrIntern();

        return [
            'titel'  => 'Neues Objekt anlegen',
            'felder' => [
                ['Kunde',           $kunde_id . ' — ' . $kunde->kundenname],
                ['Objektname',      $name],
                ['Obj-Nr. intern',  $previewObjNrIntern],
                ['Adresse',         trim((string)($a['adresse'] ?? '—')) ?: '—'],
                ['PLZ / Stadt',     trim(((string)($a['plz'] ?? '')) . ' ' . ((string)($a['stadt'] ?? ''))) ?: '—'],
                ['Bundesland',      $state],
                ['Ansprechpartner', trim((string)($a['ansprechpartner'] ?? '—')) ?: '—'],
                ['Telefon',         trim((string)($a['kontakt_telefon'] ?? '—')) ?: '—'],
                ['Bereich',         trim((string)($a['bereich'] ?? '—')) ?: '—'],
                ['Modell',          trim((string)($a['modell'] ?? 'Pro Einsatz'))],
                ['Preis (Kunde)',   number_format((float)($a['preis_kunde'] ?? 0), 2, ',', '.') . ' €'],
                ['Preis (NU)',      number_format((float)($a['nu_preis'] ?? 0), 2, ',', '.') . ' €'],
            ],
            'hinweis' => 'Es wird automatisch eine neue Objekt-ID generiert.',
        ];
    }

    private function createObjekt(array $a, int $userId): array
    {
        $name = trim((string)($a['objektname'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Objektname ist Pflicht.');
        }
        $state = strtoupper(trim((string) ($a['bundesland'] ?? '')));
        if (!in_array($state, self::BUNDESLAENDER, true)) {
            throw new \InvalidArgumentException('Bundesland ist Pflicht und ungültig.');
        }
        // Re-resolve the kunde here too — a malformed ID will already
        // have been caught at preview time, but the LLM can resubmit a
        // different value when it retries an execute.
        $kunde    = $this->resolveExistingId(
            'kunden', 'kunde_id', 'kundenname',
            (string)($a['kunden_id'] ?? ''), 'kunde'
        );
        $kunde_id = $kunde->kunde_id;
        if (!BereichScope::allowsKunde(request()->user(), $kunde_id)) {
            throw new \InvalidArgumentException("Kunde nicht gefunden.");
        }
        $b = $this->str($a, 'bereich');
        if ($b !== null && !BereichScope::bereichInputAllowed(request()->user(), $b)) {
            throw new \InvalidArgumentException("Bereich '{$b}' liegt außerhalb deiner Berechtigung.");
        }
        $obj_id = IdGenerator::next('objekte', 'obj_id', 'O');
        // Always populate the internal HMS-OBJ-… number. The web form
        // (ObjectController@store) does the same; without it the
        // record is created but stays orphaned from any UI that
        // displays "Obj-Nr. intern".
        $obj_nr_intern = $this->str($a, 'obj_nr_intern') ?: IdGenerator::objNrIntern();
        $preis_k = (float)($a['preis_kunde'] ?? 0);
        $preis_n = (float)($a['nu_preis'] ?? 0);
        $row = array_filter([
            'obj_id'         => $obj_id,
            'kunden_id'      => $kunde_id,
            'obj_nr_intern'  => $obj_nr_intern,
            'objektname'     => $name,
            'adresse'        => $this->str($a, 'adresse'),
            'plz'            => $this->str($a, 'plz'),
            'stadt'          => $this->str($a, 'stadt'),
            'bundesland'     => $state,
            'ansprechpartner'=> $this->str($a, 'ansprechpartner'),
            'kontakt_telefon'=> $this->str($a, 'kontakt_telefon'),
            'kontakt_email'  => $this->str($a, 'kontakt_email'),
            'hinweise'       => $this->str($a, 'hinweise'),
            'bereich'        => $this->str($a, 'bereich'),
            'modell'         => $this->str($a, 'modell') ?: 'Pro Einsatz',
            'preis_kunde'    => $preis_k,
            'nu_preis'       => $preis_n,
            'gewinn'         => max(0, $preis_k - $preis_n),
            'aktiv'          => 1,
            'created_at'     => now(),
        ], fn($v) => $v !== null && $v !== '');
        DB::connection('tenant')->table('objekte')->insert($row);
        return [
            'success'       => true,
            'obj_id'        => $obj_id,
            'obj_nr_intern' => $obj_nr_intern,
            'objektname'    => $name,
        ];
    }

    private function previewUpdateObjekt(array $a): array
    {
        $existing = $this->resolveExistingId(
            'objekte', 'obj_id', 'objektname',
            (string)($a['obj_id'] ?? ''), 'objekt'
        );
        $id = $existing->obj_id;
        if (!BereichScope::allowsObjekt(request()->user(), $id)) {
            throw new \InvalidArgumentException("Objekt {$id} nicht gefunden.");
        }
        if (array_key_exists('bundesland', $a)) {
            $a['bundesland'] = strtoupper(trim((string) $a['bundesland']));
            if (!in_array($a['bundesland'], self::BUNDESLAENDER, true)) {
                throw new \InvalidArgumentException('Ungültiges Bundesland.');
            }
        }

        $changes = [];
        foreach (self::OBJEKT_EDITABLE as $col) {
            if (!array_key_exists($col, $a)) continue;
            $newVal = is_string($a[$col]) ? trim($a[$col]) : $a[$col];
            if ((string)$newVal !== (string)($existing->{$col} ?? '')) {
                $changes[$col] = ['old' => $existing->{$col} ?? '—', 'new' => $newVal];
            }
        }
        if (empty($changes)) throw new \InvalidArgumentException('Keine Änderungen erkannt.');
        $this->assertObjektBereichWrite($changes, $existing);

        // Human-friendly value formatter so prices show as "25,00 €"
        // rather than the raw decimal string from the DB.
        $fmt = function ($col, $val) {
            if ($val === null || $val === '') return '—';
            if (in_array($col, ['preis_kunde', 'nu_preis'], true)) {
                return number_format((float)$val, 2, ',', '.') . ' €';
            }
            return (string)$val;
        };

        return [
            'titel'  => "Objekt {$id} ({$existing->objektname}) ändern",
            'felder' => array_map(
                fn($k, $v) => [$k, $fmt($k, $v['old']) . '  →  ' . $fmt($k, $v['new'])],
                array_keys($changes), array_values($changes)
            ),
            'hinweis' => count($changes) . ' Feld(er) werden geändert.',
        ];
    }

    private function updateObjekt(array $a, int $userId): array
    {
        $existing = $this->resolveExistingId(
            'objekte', 'obj_id', 'objektname',
            (string)($a['obj_id'] ?? ''), 'objekt'
        );
        $id = $existing->obj_id;
        if (!BereichScope::allowsObjekt(request()->user(), $id)) {
            throw new \InvalidArgumentException("Objekt {$id} nicht gefunden.");
        }
        if (array_key_exists('bundesland', $a)) {
            $a['bundesland'] = strtoupper(trim((string) $a['bundesland']));
            if (!in_array($a['bundesland'], self::BUNDESLAENDER, true)) {
                throw new \InvalidArgumentException('Ungültiges Bundesland.');
            }
        }

        $update = []; $rollback = [];
        foreach (self::OBJEKT_EDITABLE as $col) {
            if (!array_key_exists($col, $a)) continue;
            $update[$col] = is_string($a[$col]) ? trim($a[$col]) : $a[$col];
            $rollback[$col] = $existing->{$col} ?? null;
        }
        if (empty($update)) throw new \InvalidArgumentException('Keine Felder zum Aktualisieren.');
        if (array_key_exists('bereich', $update)
            && !BereichScope::bereichWriteAllowed(request()->user(), $update['bereich'], $existing->bereich ?? null)) {
            throw new \InvalidArgumentException('Der neue Bereich liegt außerhalb deiner Berechtigung.');
        }

        // gewinn is derived — recompute whenever either price was
        // touched, otherwise the displayed margin would drift.
        if (array_key_exists('preis_kunde', $update) || array_key_exists('nu_preis', $update)) {
            $pk = (float)($update['preis_kunde'] ?? $existing->preis_kunde ?? 0);
            $np = (float)($update['nu_preis']    ?? $existing->nu_preis    ?? 0);
            $update['gewinn']   = max(0, $pk - $np);
            $rollback['gewinn'] = $existing->gewinn ?? null;
        }

        // Self-heal: legacy rows (and rows the AI created before the
        // create_objekt fix shipped) may be missing obj_nr_intern.
        // Generate one transparently on the next edit — same logic
        // ObjectController@update uses for the web form.
        if (empty($existing->obj_nr_intern)) {
            $update['obj_nr_intern']   = IdGenerator::objNrIntern();
            $rollback['obj_nr_intern'] = $existing->obj_nr_intern ?? null;
        }

        DB::connection('tenant')->table('objekte')->where('obj_id', $id)->update($update);

        return [
            'success'       => true,
            'obj_id'        => $id,
            'obj_nr_intern' => $update['obj_nr_intern'] ?? $existing->obj_nr_intern,
            'objektname'    => $existing->objektname,
            '_rollback'     => ['table' => 'objekte', 'key' => ['obj_id' => $id], 'data' => $rollback],
        ];
    }

    private function assertObjektBereichWrite(array $changes, object $existing): void
    {
        if (!isset($changes['bereich'])) return;
        $new = $changes['bereich']['new'];
        if (!BereichScope::bereichWriteAllowed(request()->user(),
            $new === '—' ? null : $new, $existing->bereich ?? null)) {
            throw new \InvalidArgumentException('Der neue Bereich liegt außerhalb deiner Berechtigung.');
        }
    }
}
