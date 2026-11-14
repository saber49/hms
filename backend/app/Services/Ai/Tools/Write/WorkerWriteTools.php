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

class WorkerWriteTools implements WriteToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'create_worker' => true,
            'update_worker' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('create_worker',
                'Legt einen NEUEN Mitarbeiter an. Erfordert Bestätigung durch den Nutzer.',
                [
                    'vorname'     => ['type' => 'string', 'description' => 'Pflicht'],
                    'name'        => ['type' => 'string', 'description' => 'Pflicht (Nachname)'],
                    'anrede'      => ['type' => 'string', 'enum' => ['Herr', 'Frau', 'Divers']],
                    'geschlecht'  => ['type' => 'string', 'enum' => ['m', 'w', 'd']],
                    'geburtstag'  => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'telefon'     => ['type' => 'string'],
                    'mobil'       => ['type' => 'string'],
                    'email'       => ['type' => 'string'],
                    'strasse'     => ['type' => 'string'],
                    'plz'         => ['type' => 'string'],
                    'ort'         => ['type' => 'string'],
                    'land'        => ['type' => 'string', 'description' => 'ISO-Code, default DE'],
                    'bereich'     => ['type' => 'string', 'description' => 'z.B. "Sicherheit", "Reinigung"'],
                    'eintritt'    => ['type' => 'string', 'description' => 'Eintrittsdatum YYYY-MM-DD'],
                    'stundenlohn' => ['type' => 'number', 'description' => '€/Std., optional'],
                ], ['vorname', 'name']),

            $this->fn('update_worker',
                'Ändert Daten eines bestehenden Mitarbeiters. Nur die übergebenen Felder werden geändert.',
                [
                    'dienst_nr'   => ['type' => 'string', 'description' => 'Pflicht — Format ohne Bindestrich, z.B. "MA007"'],
                    'vorname'     => ['type' => 'string'],
                    'name'        => ['type' => 'string'],
                    'telefon'     => ['type' => 'string'],
                    'mobil'       => ['type' => 'string'],
                    'email'       => ['type' => 'string'],
                    'strasse'     => ['type' => 'string'],
                    'plz'         => ['type' => 'string'],
                    'ort'         => ['type' => 'string'],
                    'bereich'     => ['type' => 'string'],
                    'stundenlohn' => ['type' => 'number'],
                    'aktiv'       => ['type' => 'integer', 'enum' => [0, 1]],
                ], ['dienst_nr']),
        ];
    }

    public function preview(string $name, array $args): array
    {
        return match ($name) {
            'create_worker' => $this->previewCreateWorker($args),
            'update_worker' => $this->previewUpdateWorker($args),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    public function execute(string $name, array $args, int $userId): array
    {
        return match ($name) {
            'create_worker' => $this->createWorker($args, $userId),
            'update_worker' => $this->updateWorker($args, $userId),
            default => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    private function previewCreateWorker(array $a): array
    {
        $vorname = trim((string)($a['vorname'] ?? ''));
        $name    = trim((string)($a['name'] ?? ''));
        if ($vorname === '' || $name === '') {
            throw new \InvalidArgumentException('Vorname und Name sind Pflichtfelder.');
        }
        $this->assertBereichInput($a);
        return [
            'titel'   => 'Neuen Mitarbeiter anlegen',
            'felder'  => [
                ['Vorname',  $vorname],
                ['Name',     $name],
                ['Telefon',  trim((string)($a['telefon'] ?? '—')) ?: '—'],
                ['Mobil',    trim((string)($a['mobil'] ?? '—')) ?: '—'],
                ['E-Mail',   trim((string)($a['email'] ?? '—')) ?: '—'],
                ['Bereich',  trim((string)($a['bereich'] ?? '—')) ?: '—'],
                ['Adresse',  $this->joinAddress($a)],
                ['Eintritt', trim((string)($a['eintritt'] ?? '—')) ?: '—'],
            ],
            'hinweis' => 'Es wird eine Dienstnummer (MA-…) generiert und ein Initial-Passwort erzeugt.',
        ];
    }

    private function createWorker(array $a, int $userId): array
    {
        $vorname = trim((string)($a['vorname'] ?? ''));
        $name    = trim((string)($a['name'] ?? ''));
        if ($vorname === '' || $name === '') {
            throw new \InvalidArgumentException('Vorname und Name sind Pflichtfelder.');
        }
        $this->assertBereichInput($a);

        $dienst_nr = IdGenerator::next('mitarbeiter', 'dienst_nr', 'MA');
        $initialPw = $this->generatePassword();

        $row = array_filter([
            'dienst_nr'    => $dienst_nr,
            'vorname'      => $vorname,
            'name'         => $name,
            'anrede'       => $this->str($a, 'anrede'),
            'geschlecht'   => $this->str($a, 'geschlecht'),
            'geburtstag'   => $this->date($a, 'geburtstag'),
            'telefon'      => $this->str($a, 'telefon'),
            'mobil'        => $this->str($a, 'mobil'),
            'strasse'      => $this->str($a, 'strasse'),
            'plz'          => $this->str($a, 'plz'),
            'ort'          => $this->str($a, 'ort'),
            'land'         => $this->str($a, 'land') ?: 'DE',
            'email'        => $this->str($a, 'email'),
            'bereich'      => $this->str($a, 'bereich'),
            'eintritt'     => $this->date($a, 'eintritt'),
            'stundenlohn'  => isset($a['stundenlohn']) ? (float)$a['stundenlohn'] : null,
            'aktiv'        => 1,
            'app_zugang'   => 1,
            'password_hash' => Hash::make($initialPw),
            'created_at'   => now(),
        ], fn($v) => $v !== null && $v !== '');

        DB::connection('tenant')->table('mitarbeiter')->insert($row);

        return [
            'success'   => true,
            'dienst_nr' => $dienst_nr,
            'vorname'   => $vorname,
            'name'      => $name,
            'initial_password' => $initialPw,
            'note'      => 'Bitte Initial-Passwort kopieren und an den Mitarbeiter übermitteln — wird nicht erneut angezeigt.',
        ];
    }

    private function previewUpdateWorker(array $a): array
    {
        $id = trim((string)($a['dienst_nr'] ?? ''));
        if ($id === '') throw new \InvalidArgumentException('dienst_nr ist Pflicht für Update.');

        $existing = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->first();
        if (!$existing || !BereichScope::allowsWorker(request()->user(), $id)) {
            throw new \InvalidArgumentException("Mitarbeiter {$id} nicht gefunden.");
        }

        $changes = $this->collectWorkerChanges($a, $existing);
        if (empty($changes)) {
            throw new \InvalidArgumentException('Keine Änderungen erkannt.');
        }
        $this->assertBereichWrite($changes, $existing);

        return [
            'titel'    => "Mitarbeiter {$id} ({$existing->vorname} {$existing->name}) ändern",
            'felder'   => array_map(
                fn($k, $v) => [$k, ($v['old'] ?? '—') . '  →  ' . $v['new']],
                array_keys($changes), array_values($changes)
            ),
            'hinweis'  => count($changes) . ' Feld(er) werden geändert.',
        ];
    }

    private function updateWorker(array $a, int $userId): array
    {
        $id = trim((string)($a['dienst_nr'] ?? ''));
        $existing = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->first();
        if (!$existing || !BereichScope::allowsWorker(request()->user(), $id)) {
            throw new \InvalidArgumentException("Mitarbeiter {$id} nicht gefunden.");
        }

        $changes = $this->collectWorkerChanges($a, $existing);
        if (empty($changes)) throw new \InvalidArgumentException('Keine Änderungen erkannt.');
        $this->assertBereichWrite($changes, $existing);

        $update = [];
        foreach ($changes as $col => $diff) {
            $update[$col] = $diff['new'] === '—' ? null : $diff['new'];
        }
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'geaendert_am')) {
            $update['geaendert_am'] = now();
        }

        $rollback = [];
        foreach (array_keys($changes) as $col) {
            $rollback[$col] = $existing->{$col} ?? null;
        }

        DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->update($update);

        return [
            'success'   => true,
            'dienst_nr' => $id,
            'changes'   => $changes,
            '_rollback' => ['table' => 'mitarbeiter', 'key' => ['dienst_nr' => $id], 'data' => $rollback],
        ];
    }

    private function collectWorkerChanges(array $a, $existing): array
    {
        $editable = ['vorname','name','anrede','telefon','mobil','strasse','plz','ort','email','bereich','stundenlohn','aktiv'];
        $changes = [];
        foreach ($editable as $col) {
            if (!array_key_exists($col, $a)) continue;
            $newVal = is_string($a[$col]) ? trim($a[$col]) : $a[$col];
            $oldVal = $existing->{$col} ?? null;
            if ((string)$newVal !== (string)$oldVal) {
                $changes[$col] = ['old' => $oldVal ?? '—', 'new' => $newVal];
            }
        }
        return $changes;
    }

    private function assertBereichInput(array $a): void
    {
        $b = $this->str($a, 'bereich');
        if ($b !== null && !BereichScope::bereichInputAllowed(request()->user(), $b)) {
            throw new \InvalidArgumentException("Bereich '{$b}' liegt außerhalb deiner Berechtigung.");
        }
    }

    private function assertBereichWrite(array $changes, object $existing): void
    {
        if (!isset($changes['bereich'])) return;
        $new = $changes['bereich']['new'];
        if (!BereichScope::bereichWriteAllowed(request()->user(),
            $new === '—' ? null : $new, $existing->bereich ?? null)) {
            throw new \InvalidArgumentException('Der neue Bereich liegt außerhalb deiner Berechtigung.');
        }
    }
}
