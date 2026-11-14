<?php

namespace App\Services\Ai\Tools\Write;

use App\Helpers\IdGenerator;
use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\WriteToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * NU (Nachunternehmer) write tools — create + update subcontractor
 * master data. Same preview/execute contract as every other write
 * provider; Bereich-pool semantics apply (untagged NU = shared,
 * visible to all restricted users).
 */
class NuWriteTools implements WriteToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return in_array($name, ['create_nu', 'update_nu'], true);
    }

    public function schemas(): array
    {
        return [
            $this->fn('create_nu',
                'Legt einen NEUEN Nachunternehmer (Subunternehmer-Firma) an. '
                . 'Die NU-ID (Format NU…) wird automatisch generiert. Erfordert Nutzer-Bestätigung.',
                [
                    'firmenname'      => ['type' => 'string', 'description' => 'Pflicht — Name der Firma'],
                    'ansprechpartner' => ['type' => 'string'],
                    'telefon'         => ['type' => 'string'],
                    'mobil'           => ['type' => 'string'],
                    'email'           => ['type' => 'string'],
                    'strasse'         => ['type' => 'string'],
                    'plz'             => ['type' => 'string'],
                    'ort'             => ['type' => 'string'],
                    'land'            => ['type' => 'string', 'description' => 'default DE'],
                    'bereich'         => ['type' => 'string', 'description' => 'z.B. "Sicherheit" — leer = für alle Bereiche nutzbar'],
                    'notiz'           => ['type' => 'string'],
                ], ['firmenname']),

            $this->fn('update_nu',
                'Ändert Stammdaten eines bestehenden Nachunternehmers. Nur übergebene Felder werden geändert.',
                [
                    'nu_id'           => ['type' => 'string', 'description' => 'Pflicht — z.B. "NU001"'],
                    'firmenname'      => ['type' => 'string'],
                    'ansprechpartner' => ['type' => 'string'],
                    'telefon'         => ['type' => 'string'],
                    'mobil'           => ['type' => 'string'],
                    'email'           => ['type' => 'string'],
                    'strasse'         => ['type' => 'string'],
                    'plz'             => ['type' => 'string'],
                    'ort'             => ['type' => 'string'],
                    'bereich'         => ['type' => 'string'],
                    'notiz'           => ['type' => 'string'],
                    'aktiv'           => ['type' => 'integer', 'enum' => [0, 1]],
                ], ['nu_id']),
        ];
    }

    public function preview(string $name, array $args): array
    {
        return match ($name) {
            'create_nu' => $this->previewCreateNu($args),
            'update_nu' => $this->previewUpdateNu($args),
            default     => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    public function execute(string $name, array $args, int $userId): array
    {
        return match ($name) {
            'create_nu' => $this->createNu($args, $userId),
            'update_nu' => $this->updateNu($args, $userId),
            default     => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    private function previewCreateNu(array $a): array
    {
        $firma = trim((string) ($a['firmenname'] ?? ''));
        if ($firma === '') {
            throw new \InvalidArgumentException('firmenname ist Pflicht.');
        }
        $bereich = $this->str($a, 'bereich');
        if ($bereich !== null && !BereichScope::bereichInputAllowed(request()->user(), $bereich)) {
            throw new \InvalidArgumentException("Bereich '{$bereich}' liegt außerhalb deiner Berechtigung.");
        }

        return [
            'titel'  => 'Neuen Nachunternehmer anlegen',
            'felder' => [
                ['Firmenname',      $firma],
                ['Ansprechpartner', trim((string) ($a['ansprechpartner'] ?? '')) ?: '—'],
                ['Telefon',         trim((string) ($a['telefon'] ?? '')) ?: '—'],
                ['Mobil',           trim((string) ($a['mobil'] ?? '')) ?: '—'],
                ['E-Mail',          trim((string) ($a['email'] ?? '')) ?: '—'],
                ['Adresse',         $this->joinAddress($a)],
                ['Bereich',         $bereich ?? '—'],
            ],
            'hinweis' => 'Es wird eine NU-ID (NU…) generiert.',
        ];
    }

    private function createNu(array $a, int $userId): array
    {
        $firma = trim((string) ($a['firmenname'] ?? ''));
        if ($firma === '') {
            throw new \InvalidArgumentException('firmenname ist Pflicht.');
        }
        $bereich = $this->str($a, 'bereich');
        if ($bereich !== null && !BereichScope::bereichInputAllowed(request()->user(), $bereich)) {
            throw new \InvalidArgumentException("Bereich '{$bereich}' liegt außerhalb deiner Berechtigung.");
        }

        $nuId = IdGenerator::next('nachunternehmer', 'nu_id', 'NU');

        $row = array_filter([
            'nu_id'           => $nuId,
            'firmenname'      => $firma,
            'ansprechpartner' => $this->str($a, 'ansprechpartner'),
            'telefon1'        => $this->str($a, 'telefon'),
            'mobil'           => $this->str($a, 'mobil'),
            'email'           => $this->str($a, 'email'),
            'strasse'         => $this->str($a, 'strasse'),
            'plz'             => $this->str($a, 'plz'),
            'ort'             => $this->str($a, 'ort'),
            'land'            => $this->str($a, 'land') ?: 'DE',
            'bereich'         => $bereich,
            'notiz'           => $this->str($a, 'notiz'),
            'aktiv'           => 1,
            'created_at'      => now(),
        ], fn ($v) => $v !== null && $v !== '');

        DB::connection('tenant')->table('nachunternehmer')->insert($row);

        return [
            'success' => true,
            'nu_id'   => $nuId,
            'firma'   => $firma,
        ];
    }

    private function previewUpdateNu(array $a): array
    {
        [$id, $existing, $changes] = $this->resolveNuChanges($a);

        return [
            'titel'   => "Nachunternehmer {$id} ({$existing->firmenname}) ändern",
            'felder'  => array_map(
                fn ($k, $v) => [$k, ($v['old'] ?? '—') . '  →  ' . ($v['new'] ?? '—')],
                array_keys($changes), array_values($changes)
            ),
            'hinweis' => count($changes) . ' Feld(er) werden geändert.',
        ];
    }

    private function updateNu(array $a, int $userId): array
    {
        [$id, $existing, $changes] = $this->resolveNuChanges($a);

        $update = [];
        foreach ($changes as $col => $diff) {
            $update[$col] = $diff['new'] === '—' ? null : $diff['new'];
        }
        $update['updated_at'] = now();

        $rollback = [];
        foreach (array_keys($changes) as $col) {
            $rollback[$col] = $existing->{$col} ?? null;
        }

        DB::connection('tenant')->table('nachunternehmer')->where('nu_id', $id)->update($update);

        return [
            'success'   => true,
            'nu_id'     => $id,
            'changes'   => $changes,
            '_rollback' => ['table' => 'nachunternehmer', 'key' => ['nu_id' => $id], 'data' => $rollback],
        ];
    }

    /**
     * Shared validation for preview+execute: resolves the NU row,
     * enforces Bereich pool scope, and computes the change set.
     * Returns [nu_id, existingRow, changes].
     */
    private function resolveNuChanges(array $a): array
    {
        $id = trim((string) ($a['nu_id'] ?? ''));
        if ($id === '') {
            throw new \InvalidArgumentException('nu_id ist Pflicht für Update.');
        }
        $existing = $this->resolveExistingId(
            'nachunternehmer', 'nu_id', 'firmenname', $id, 'nu'
        );
        $id = $existing->nu_id; // normalised (hyphen tolerance)

        if (!BereichScope::allowsNu(request()->user(), $id)) {
            throw new \InvalidArgumentException("Nachunternehmer {$id} nicht gefunden.");
        }

        $map = [
            'firmenname'      => 'firmenname',
            'ansprechpartner' => 'ansprechpartner',
            'telefon'         => 'telefon1',
            'mobil'           => 'mobil',
            'email'           => 'email',
            'strasse'         => 'strasse',
            'plz'             => 'plz',
            'ort'             => 'ort',
            'bereich'         => 'bereich',
            'notiz'           => 'notiz',
            'aktiv'           => 'aktiv',
        ];
        $changes = [];
        foreach ($map as $arg => $col) {
            if (!array_key_exists($arg, $a)) continue;
            $newVal = is_string($a[$arg]) ? trim($a[$arg]) : $a[$arg];
            $oldVal = $existing->{$col} ?? null;
            if ((string) ($newVal ?? '') !== (string) ($oldVal ?? '')) {
                $changes[$col] = ['old' => $oldVal ?? '—', 'new' => $newVal === '' ? '—' : $newVal];
            }
        }
        if (isset($changes['bereich'])
            && !BereichScope::bereichWriteAllowed(request()->user(),
                $changes['bereich']['new'] === '—' ? null : $changes['bereich']['new'],
                $existing->bereich)) {
            throw new \InvalidArgumentException('Der neue Bereich liegt außerhalb deiner Berechtigung.');
        }
        if (empty($changes)) {
            throw new \InvalidArgumentException('Keine Änderungen erkannt.');
        }
        return [$id, $existing, $changes];
    }
}
