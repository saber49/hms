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

class KundeWriteTools implements WriteToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'create_kunde' => true,
            'update_kunde' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('create_kunde',
                'Legt einen NEUEN Kunden an. Erfordert Bestätigung.',
                [
                    'kundenname'      => ['type' => 'string', 'description' => 'Pflicht'],
                    'anschrift_typ'   => ['type' => 'string', 'enum' => ['Firma', 'Privat']],
                    'kunden_nr_kunde' => ['type' => 'string', 'description' => 'Externe Nr.'],
                    'strasse'         => ['type' => 'string'],
                    'plz'             => ['type' => 'string'],
                    'ort'             => ['type' => 'string'],
                    'land'            => ['type' => 'string'],
                    'telefon1'        => ['type' => 'string'],
                    'mobil'           => ['type' => 'string'],
                    'email'           => ['type' => 'string'],
                    'website'         => ['type' => 'string'],
                    'haupt_ap'        => ['type' => 'string', 'description' => 'Hauptansprechpartner'],
                    'bereich'         => ['type' => 'string'],
                    'notiz'           => ['type' => 'string'],
                ], ['kundenname']),

            $this->fn('update_kunde',
                'Ändert Daten eines bestehenden Kunden.',
                [
                    'kunde_id'   => ['type' => 'string', 'description' => 'Pflicht — Format ohne Bindestrich, z.B. "K028"'],
                    'kundenname' => ['type' => 'string'],
                    'strasse'    => ['type' => 'string'],
                    'plz'        => ['type' => 'string'],
                    'ort'        => ['type' => 'string'],
                    'telefon1'   => ['type' => 'string'],
                    'mobil'      => ['type' => 'string'],
                    'email'      => ['type' => 'string'],
                    'website'    => ['type' => 'string'],
                    'haupt_ap'   => ['type' => 'string'],
                    'bereich'    => ['type' => 'string'],
                    'notiz'      => ['type' => 'string'],
                    'aktiv'      => ['type' => 'integer', 'enum' => [0, 1]],
                ], ['kunde_id']),
        ];
    }

    public function preview(string $name, array $args): array
    {
        return match ($name) {
            'create_kunde' => $this->previewCreateKunde($args),
            'update_kunde' => $this->previewUpdateKunde($args),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    public function execute(string $name, array $args, int $userId): array
    {
        return match ($name) {
            'create_kunde' => $this->createKunde($args, $userId),
            'update_kunde' => $this->updateKunde($args, $userId),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    private function previewCreateKunde(array $a): array
    {
        $name = trim((string)($a['kundenname'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('Kundenname ist Pflichtfeld.');
        $this->assertKundeBereichInput($a);
        return [
            'titel'  => 'Neuen Kunden anlegen',
            'felder' => [
                ['Kundenname',     $name],
                ['Anschriftstyp',  trim((string)($a['anschrift_typ'] ?? 'Firma'))],
                ['Adresse',        $this->joinAddress($a)],
                ['Telefon',        trim((string)($a['telefon1'] ?? $a['telefon'] ?? '—')) ?: '—'],
                ['E-Mail',         trim((string)($a['email'] ?? '—')) ?: '—'],
                ['Hauptansprechp.',trim((string)($a['haupt_ap'] ?? '—')) ?: '—'],
                ['Bereich',        trim((string)($a['bereich'] ?? '—')) ?: '—'],
            ],
            'hinweis' => 'Es wird eine interne Kunden-ID (K-…) generiert.',
        ];
    }

    private function createKunde(array $a, int $userId): array
    {
        $name = trim((string)($a['kundenname'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('Kundenname ist Pflichtfeld.');
        $this->assertKundeBereichInput($a);

        $kunde_id = IdGenerator::next('kunden', 'kunde_id', 'K');

        $row = array_filter([
            'kunde_id'        => $kunde_id,
            'kundenname'      => $name,
            'kunden_nr_kunde' => $this->str($a, 'kunden_nr_kunde'),
            'kunden_nr_intern'=> $this->str($a, 'kunden_nr_intern'),
            'abrechnungstyp'  => $this->str($a, 'abrechnungstyp') ?: 'Pro Einheit',
            'anschrift_typ'   => $this->str($a, 'anschrift_typ') ?: 'Firma',
            'zusatz'          => $this->str($a, 'zusatz'),
            'strasse'         => $this->str($a, 'strasse'),
            'plz'             => $this->str($a, 'plz'),
            'ort'             => $this->str($a, 'ort'),
            'land'            => $this->str($a, 'land') ?: 'D',
            'telefon1'        => $this->str($a, 'telefon1') ?: $this->str($a, 'telefon'),
            'mobil'           => $this->str($a, 'mobil'),
            'email'           => $this->str($a, 'email'),
            'rechnung_email'  => $this->str($a, 'rechnung_email'),
            'website'         => $this->str($a, 'website'),
            'haupt_ap'        => $this->str($a, 'haupt_ap'),
            'notiz'           => $this->str($a, 'notiz'),
            'bereich'         => $this->str($a, 'bereich'),
            'aktiv'           => 1,
            'created_at'      => now(),
        ], fn($v) => $v !== null && $v !== '');

        DB::connection('tenant')->table('kunden')->insert($row);

        return [
            'success'    => true,
            'kunde_id'   => $kunde_id,
            'kundenname' => $name,
        ];
    }

    private function previewUpdateKunde(array $a): array
    {
        $existing = $this->resolveExistingId(
            'kunden', 'kunde_id', 'kundenname',
            (string)($a['kunde_id'] ?? ''), 'kunde'
        );
        $id = $existing->kunde_id;
        if (!BereichScope::allowsKunde(request()->user(), $id)) {
            throw new \InvalidArgumentException("Kunde {$id} nicht gefunden.");
        }

        $editable = ['kundenname','strasse','plz','ort','telefon1','mobil','email','rechnung_email','website','haupt_ap','bereich','notiz','aktiv'];
        $changes = [];
        foreach ($editable as $col) {
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

        return [
            'titel'   => "Kunde {$id} ({$existing->kundenname}) ändern",
            'felder'  => array_map(fn($k,$v) => [$k, ($v['old'] ?? '—').'  →  '.$v['new']], array_keys($changes), array_values($changes)),
            'hinweis' => count($changes).' Feld(er) werden geändert.',
        ];
    }

    private function updateKunde(array $a, int $userId): array
    {
        $existing = $this->resolveExistingId(
            'kunden', 'kunde_id', 'kundenname',
            (string)($a['kunde_id'] ?? ''), 'kunde'
        );
        $id = $existing->kunde_id;
        if (!BereichScope::allowsKunde(request()->user(), $id)) {
            throw new \InvalidArgumentException("Kunde {$id} nicht gefunden.");
        }

        $editable = ['kundenname','strasse','plz','ort','telefon1','mobil','email','rechnung_email','website','haupt_ap','bereich','notiz','aktiv'];
        $update = []; $rollback = [];
        foreach ($editable as $col) {
            if (!array_key_exists($col, $a)) continue;
            $update[$col] = is_string($a[$col]) ? trim($a[$col]) : $a[$col];
            $rollback[$col] = $existing->{$col} ?? null;
        }
        if (empty($update)) throw new \InvalidArgumentException('Keine Felder zum Aktualisieren.');
        if (array_key_exists('bereich', $update)
            && !BereichScope::bereichWriteAllowed(request()->user(), $update['bereich'], $existing->bereich ?? null)) {
            throw new \InvalidArgumentException('Der neue Bereich liegt außerhalb deiner Berechtigung.');
        }
        $update['updated_at'] = now();

        DB::connection('tenant')->table('kunden')->where('kunde_id', $id)->update($update);

        return [
            'success'  => true,
            'kunde_id' => $id,
            '_rollback' => ['table' => 'kunden', 'key' => ['kunde_id' => $id], 'data' => $rollback],
        ];
    }

    private function assertKundeBereichInput(array $a): void
    {
        $b = $this->str($a, 'bereich');
        if ($b !== null && !BereichScope::bereichInputAllowed(request()->user(), $b)) {
            throw new \InvalidArgumentException("Bereich '{$b}' liegt außerhalb deiner Berechtigung.");
        }
    }
}
