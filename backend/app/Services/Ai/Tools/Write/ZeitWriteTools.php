<?php

namespace App\Services\Ai\Tools\Write;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\WriteToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Time-correction write tools — the "small but critical" edits the
 * Monatsabschluss / Dispo workflow does every day:
 *
 * - update_mitarbeiter_zeit: correct an assigned worker's recorded
 *   times (ist_start/ist_ende/ist_std/pause_minuten/aufgabe) on
 *   einsatz_mitarbeiter.
 * - update_nu_position_zeit: same for an NU worker's times on
 *   nu_position_zuweisungen.
 * - set_zeit_freigabe: the green check that approves a worker's
 *   recorded time for billing (zeit_freigabe + zeit_freigabe_am),
 *   identical semantics to DispositionController::zeitFreigabe.
 *
 * All of them need einsatz scope (BereichScope::allowsEinsatz) and
 * return a _rollback snapshot so the action can be undone.
 */
class ZeitWriteTools implements WriteToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return in_array($name, [
            'update_mitarbeiter_zeit',
            'update_nu_position_zeit',
            'set_zeit_freigabe',
        ], true);
    }

    public function schemas(): array
    {
        return [
            $this->fn('update_mitarbeiter_zeit',
                'Korrigiert die erfasste Arbeitszeit EINES zugewiesenen Mitarbeiters auf EINEM Einsatz '
                . '(Tabelle einsatz_mitarbeiter: Start, Ende, Stunden, Pause, Aufgabe). '
                . 'Das ist die Korrektur, die der Monatsabschluss vor der Abrechnung braucht — '
                . 'vorher IMMER einsatz_mitarbeiter (Tool) lesen. '
                . 'Wenn Start UND Ende übergeben werden, Stunden aber nicht, werden sie automatisch '
                . 'berechnet (Ende−Start−Pause, Nachtschicht über Mitternacht unterstützt).',
                [
                    'einsatz_id'    => ['type' => 'string', 'description' => 'Pflicht — z.B. "E-20260522-395"'],
                    'dienst_nr'     => ['type' => 'string', 'description' => 'Pflicht — z.B. "MA007"'],
                    'ist_start'     => ['type' => 'string', 'description' => 'HH:MM'],
                    'ist_ende'      => ['type' => 'string', 'description' => 'HH:MM'],
                    'ist_std'       => ['type' => 'number', 'description' => 'Stunden, z.B. 7.5 — optional wenn Start+Ende gesetzt'],
                    'pause_minuten' => ['type' => 'integer', 'description' => 'Pause in Minuten'],
                    'aufgabe'       => ['type' => 'string', 'description' => 'Aufgabe/Notiz für diesen Mitarbeiter'],
                ], ['einsatz_id', 'dienst_nr']),

            $this->fn('update_nu_position_zeit',
                'Korrigiert die erfasste Arbeitszeit eines NU-Mitarbeiters '
                . '(Tabelle nu_position_zuweisungen: Start, Ende, Stunden, Pause). '
                . 'Die zuweisung_id steht in einsatz_mitarbeiter → nu_mitarbeiter.',
                [
                    'zuweisung_id'  => ['type' => 'integer', 'description' => 'Pflicht — id aus nu_position_zuweisungen'],
                    'ist_start'     => ['type' => 'string', 'description' => 'HH:MM'],
                    'ist_ende'      => ['type' => 'string', 'description' => 'HH:MM'],
                    'ist_std'       => ['type' => 'number'],
                    'pause_minuten' => ['type' => 'integer'],
                ], ['zuweisung_id']),

            $this->fn('set_zeit_freigabe',
                'Setzt oder entfernt die Zeit-Freigabe (grüner Haken) für die Arbeitszeit eines '
                . 'Mitarbeiters — exakt wie die Freigabe im Monatsabschluss. '
                . 'freigabe=true bestätigt die Zeit für die Abrechnung, false hebt sie auf. '
                . 'Akzeptiert einen oder mehrere Einsätze.',
                [
                    'dienst_nr'   => ['type' => 'string', 'description' => 'Pflicht — z.B. "MA007"'],
                    'einsatz_id'  => ['type' => 'string', 'description' => 'ein Einsatz — ODER einsatz_ids nutzen'],
                    'einsatz_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'mehrere Einsätze auf einmal'],
                    'freigabe'    => ['type' => 'boolean', 'description' => 'true = freigeben, false = Freigabe aufheben (default true)'],
                ], ['dienst_nr']),
        ];
    }

    public function preview(string $name, array $args): array
    {
        return match ($name) {
            'update_mitarbeiter_zeit' => $this->previewUpdateMitarbeiterZeit($args),
            'update_nu_position_zeit' => $this->previewUpdateNuPositionZeit($args),
            'set_zeit_freigabe'       => $this->previewSetZeitFreigabe($args),
            default                   => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    public function execute(string $name, array $args, int $userId): array
    {
        return match ($name) {
            'update_mitarbeiter_zeit' => $this->updateMitarbeiterZeit($args, $userId),
            'update_nu_position_zeit' => $this->updateNuPositionZeit($args, $userId),
            'set_zeit_freigabe'       => $this->setZeitFreigabe($args, $userId),
            default                   => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    // ---------------------------------------------------------------
    // update_mitarbeiter_zeit
    // ---------------------------------------------------------------
    private function previewUpdateMitarbeiterZeit(array $a): array
    {
        [$einsatz, $row, $changes] = $this->resolveMaZeitChanges($a);

        $name = trim(($row->vorname ?? '') . ' ' . ($row->name ?? '')) ?: $row->dienst_nr;
        $out = [
            'titel'   => "Arbeitszeit korrigieren — {$name} am {$einsatz->datum}",
            'felder'  => array_map(
                fn ($k, $v) => [$k, ($v['old'] ?? '—') . '  →  ' . ($v['new'] ?? '—')],
                array_keys($changes), array_values($changes)
            ),
            'hinweis' => 'Einsatz ' . $einsatz->einsatz_id
                . ($einsatz->hms_bestaetigt === 'ja'
                    ? ' ist bereits bestätigt — die Korrektur ändert die Abrechnungsgrundlage.'
                    : ' (noch nicht bestätigt).'),
        ];
        if ($einsatz->hms_bestaetigt === 'ja') {
            $out['warnung'] = 'Einsatz ist bestätigt: geänderte Zeiten fließen direkt in Abrechnung/PDF ein.';
        }
        return $out;
    }

    private function updateMitarbeiterZeit(array $a, int $userId): array
    {
        [$einsatz, $row, $changes] = $this->resolveMaZeitChanges($a);

        $update = [];
        foreach ($changes as $col => $d) {
            $update[$col] = $d['new'] === '—' ? null : $d['new'];
        }
        $rollback = [];
        foreach (array_keys($changes) as $col) {
            $rollback[$col] = $row->{$col} ?? null;
        }

        // Same rule as the office edit (TaskController::update): changing an
        // approved row's time revokes its Freigabe.
        $revoked = false;
        $zeitFields = ['ist_start', 'ist_ende', 'ist_std', 'pause_minuten'];
        if ((int) ($row->zeit_freigabe ?? 0) === 1 && array_intersect($zeitFields, array_keys($changes))
            && Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe')) {
            $update['zeit_freigabe'] = 0;
            $update['zeit_freigabe_am'] = null;
            $rollback['zeit_freigabe'] = 1;
            $rollback['zeit_freigabe_am'] = $row->zeit_freigabe_am ?? null;
            $revoked = true;
        }

        DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('id', $row->id)->update($update);

        $out = [
            'success'   => true,
            'einsatz_id'=> $einsatz->einsatz_id,
            'dienst_nr' => $row->dienst_nr,
            'changes'   => $changes,
            '_rollback' => ['table' => 'einsatz_mitarbeiter', 'key' => ['id' => $row->id], 'data' => $rollback],
        ];
        if ($revoked) $out['hinweis'] = 'Die Zeit-Freigabe wurde wegen der Änderung zurückgenommen — erneut im Monatsabschluss freigeben.';
        return $out;
    }

    private function resolveMaZeitChanges(array $a): array
    {
        $einsatzId = trim((string) ($a['einsatz_id'] ?? ''));
        $dienstNr  = trim((string) ($a['dienst_nr'] ?? ''));
        if ($einsatzId === '' || $dienstNr === '') {
            throw new \InvalidArgumentException('einsatz_id und dienst_nr sind Pflicht.');
        }

        $einsatz = DB::connection('tenant')->table('einsaetze')
            ->where('einsatz_id', $einsatzId)->first(['einsatz_id', 'datum', 'hms_bestaetigt']);
        if (!$einsatz || !BereichScope::allowsEinsatz(request()->user(), $einsatzId)) {
            throw new \InvalidArgumentException("Einsatz {$einsatzId} nicht gefunden.");
        }

        $row = DB::connection('tenant')->selectOne("
            SELECT em.*, m.vorname, m.name
              FROM einsatz_mitarbeiter em
              LEFT JOIN mitarbeiter m ON em.dienst_nr = m.dienst_nr
             WHERE em.einsatz_id = ? AND em.dienst_nr = ? LIMIT 1
        ", [$einsatzId, $dienstNr]);
        if (!$row) {
            throw new \InvalidArgumentException(
                "Mitarbeiter {$dienstNr} ist dem Einsatz {$einsatzId} nicht zugewiesen."
            );
        }

        $changes = $this->zeitChanges($a, $row, /*withAufgabe*/ true);
        if (empty($changes)) {
            throw new \InvalidArgumentException('Keine Änderungen erkannt.');
        }
        return [$einsatz, $row, $changes];
    }

    // ---------------------------------------------------------------
    // update_nu_position_zeit
    // ---------------------------------------------------------------
    private function previewUpdateNuPositionZeit(array $a): array
    {
        [$row, $changes] = $this->resolveNuZeitChanges($a);

        return [
            'titel'   => "NU-Arbeitszeit korrigieren — {$row->vorname} {$row->name} am {$row->datum}",
            'felder'  => array_map(
                fn ($k, $v) => [$k, ($v['old'] ?? '—') . '  →  ' . ($v['new'] ?? '—')],
                array_keys($changes), array_values($changes)
            ),
            'hinweis' => 'Einsatz ' . $row->einsatz_id . ' / Position ' . $row->position_id,
        ];
    }

    private function updateNuPositionZeit(array $a, int $userId): array
    {
        [$row, $changes] = $this->resolveNuZeitChanges($a);

        $update = [];
        foreach ($changes as $col => $d) {
            $update[$col] = $d['new'] === '—' ? null : $d['new'];
        }
        $rollback = [];
        foreach (array_keys($changes) as $col) {
            $rollback[$col] = $row->{$col} ?? null;
        }

        DB::connection('tenant')->table('nu_position_zuweisungen')
            ->where('id', $row->id)->update($update);

        return [
            'success'      => true,
            'zuweisung_id' => $row->id,
            'changes'      => $changes,
            '_rollback'    => ['table' => 'nu_position_zuweisungen', 'key' => ['id' => $row->id], 'data' => $rollback],
        ];
    }

    private function resolveNuZeitChanges(array $a): array
    {
        $id = (int) ($a['zuweisung_id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('zuweisung_id ist Pflicht.');
        }

        $row = DB::connection('tenant')->selectOne("
            SELECT z.*, p.einsatz_id, p.position_id, e.datum,
                   npm.vorname, npm.name
              FROM nu_position_zuweisungen z
              JOIN nu_schicht_positionen p ON z.position_id = p.id
              JOIN einsaetze e ON p.einsatz_id = e.einsatz_id
              LEFT JOIN nu_portal_mitarbeiter npm ON z.nu_mitarbeiter_id = npm.id
             WHERE z.id = ? LIMIT 1
        ", [$id]);
        if (!$row || !BereichScope::allowsEinsatz(request()->user(), $row->einsatz_id)) {
            throw new \InvalidArgumentException("Zuweisung {$id} nicht gefunden.");
        }

        $changes = $this->zeitChanges($a, $row, /*withAufgabe*/ false);
        if (empty($changes)) {
            throw new \InvalidArgumentException('Keine Änderungen erkannt.');
        }
        return [$row, $changes];
    }

    // ---------------------------------------------------------------
    // set_zeit_freigabe — mirrors DispositionController::zeitFreigabe
    // ---------------------------------------------------------------
    private function previewSetZeitFreigabe(array $a): array
    {
        [$dienstNr, $ids, $freigabe, $worker] = $this->resolveFreigabeTarget($a);

        $name = trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? '')) ?: $dienstNr;
        return [
            'titel'   => ($freigabe ? 'Zeit freigeben' : 'Zeit-Freigabe aufheben') . " — {$name}",
            'felder'  => [
                ['Mitarbeiter', "{$dienstNr} — {$name}"],
                ['Einsätze',    count($ids) . ' → ' . implode(', ', array_slice($ids, 0, 5))
                                . (count($ids) > 5 ? ' …' : '')],
                ['Aktion',      $freigabe ? 'freigeben (Abrechnung)' : 'Freigabe aufheben'],
            ],
        ];
    }

    private function setZeitFreigabe(array $a, int $userId): array
    {
        if (!Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe')) {
            throw new \InvalidArgumentException('Zeit-Freigabe ist auf diesem System nicht eingerichtet.');
        }
        [$dienstNr, $ids, $freigabe] = $this->resolveFreigabeTarget($a);

        $before = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('dienst_nr', $dienstNr)->whereIn('einsatz_id', $ids)
            ->get(['id', 'einsatz_id', 'zeit_freigabe', 'zeit_freigabe_am']);

        $updated = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('dienst_nr', $dienstNr)
            ->whereIn('einsatz_id', $ids)
            ->update([
                'zeit_freigabe'    => $freigabe ? 1 : 0,
                'zeit_freigabe_am' => $freigabe ? now() : null,
            ]);

        \App\Helpers\Realtime::changed('dispo');

        return [
            'success'   => true,
            'dienst_nr' => $dienstNr,
            'freigabe'  => $freigabe,
            'updated'   => $updated,
            '_rollback' => [
                'table' => 'einsatz_mitarbeiter',
                'key'   => ['dienst_nr' => $dienstNr, 'einsatz_id' => $ids],
                'data'  => $before->map(fn ($r) => (array) $r)->all(),
            ],
        ];
    }

    private function resolveFreigabeTarget(array $a): array
    {
        $dienstNr = trim((string) ($a['dienst_nr'] ?? ''));
        if ($dienstNr === '') {
            throw new \InvalidArgumentException('dienst_nr ist Pflicht.');
        }

        $ids = [];
        if (!empty($a['einsatz_id']))  $ids[] = trim((string) $a['einsatz_id']);
        if (!empty($a['einsatz_ids']) && is_array($a['einsatz_ids'])) {
            foreach ($a['einsatz_ids'] as $x) $ids[] = trim((string) $x);
        }
        $ids = array_values(array_unique(array_filter($ids, fn ($x) => $x !== '')));
        if (empty($ids)) throw new \InvalidArgumentException('einsatz_id oder einsatz_ids ist Pflicht.');
        $ids = array_slice($ids, 0, 500);

        $worker = DB::connection('tenant')->table('mitarbeiter')
            ->where('dienst_nr', $dienstNr)->first(['dienst_nr', 'vorname', 'name']);
        if (!$worker || !BereichScope::allowsWorker(request()->user(), $dienstNr)) {
            throw new \InvalidArgumentException("Mitarbeiter {$dienstNr} nicht gefunden.");
        }

        // Scope: only in-scope einsatz ids pass (same rule as the
        // controller's scopeEinsatzIds).
        $allowed = array_values(array_filter(
            $ids,
            fn ($id) => BereichScope::allowsEinsatz(request()->user(), $id)
        ));
        if (empty($allowed)) {
            throw new \InvalidArgumentException('Keine der Einsätze liegt in deinem Bereich.');
        }

        $freigabe = array_key_exists('freigabe', $a) ? (bool) $a['freigabe'] : true;
        return [$dienstNr, $allowed, $freigabe, $worker];
    }

    // ---------------------------------------------------------------
    // shared: compute {ist_start, ist_ende, ist_std, pause_minuten[, aufgabe]}
    // diff + auto-compute ist_std from start/ende/pause when omitted.
    // ---------------------------------------------------------------
    private function zeitChanges(array $a, object $row, bool $withAufgabe): array
    {
        $changes = [];

        foreach (['ist_start', 'ist_ende'] as $col) {
            if (!array_key_exists($col, $a)) continue;
            $new = $a[$col] === null || $a[$col] === '' ? null : $this->normalizeTime((string) $a[$col]);
            if ($a[$col] !== null && $a[$col] !== '' && $new === null) {
                throw new \InvalidArgumentException("Ungültige Zeit für {$col}: " . $a[$col]);
            }
            $old = $row->{$col} !== null ? substr((string) $row->{$col}, 0, 5) : null;
            if ($new !== $old) $changes[$col] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
        }

        if (array_key_exists('pause_minuten', $a)) {
            $new = $a['pause_minuten'] === null ? null : max(0, (int) $a['pause_minuten']);
            $old = $row->pause_minuten !== null ? (int) $row->pause_minuten : null;
            if ($new !== $old) $changes['pause_minuten'] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
        }

        if (array_key_exists('ist_std', $a)) {
            $new = $a['ist_std'] === null || $a['ist_std'] === '' ? null : round((float) $a['ist_std'], 2);
            if ($new !== null && ($new < 0 || $new > 24)) {
                throw new \InvalidArgumentException('ist_std muss zwischen 0 und 24 liegen.');
            }
            $old = $row->ist_std !== null ? (float) $row->ist_std : null;
            if ($new !== $old) $changes['ist_std'] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
        } elseif (isset($changes['ist_start']) || isset($changes['ist_ende']) || isset($changes['pause_minuten'])) {
            // Auto-compute hours when times changed but no explicit
            // ist_std was sent — keeps ist_std consistent with the
            // displayed start/end. Night shifts (ende < start) roll
            // over midnight.
            $start = $changes['ist_start']['new'] ?? ($row->ist_start !== null ? substr((string) $row->ist_start, 0, 5) : null);
            $ende  = $changes['ist_ende']['new']  ?? ($row->ist_ende  !== null ? substr((string) $row->ist_ende,  0, 5) : null);
            $pause = $changes['pause_minuten']['new'] ?? ($row->pause_minuten ?? 0);
            if ($start !== '—' && $ende !== '—' && $start !== null && $ende !== null) {
                [$sh, $sm] = array_map('intval', explode(':', $start));
                [$eh, $em] = array_map('intval', explode(':', $ende));
                $mins = ($eh * 60 + $em) - ($sh * 60 + $sm);
                if ($mins <= 0) $mins += 24 * 60;
                $mins -= (int) $pause;
                if ($mins > 0) {
                    $new = round($mins / 60, 2);
                    $old = $row->ist_std !== null ? (float) $row->ist_std : null;
                    if ($new !== $old) {
                        $changes['ist_std'] = ['old' => $old ?? '—', 'new' => $new];
                    }
                }
            }
        }

        if ($withAufgabe && array_key_exists('aufgabe', $a)
            && Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'aufgabe')) {
            $new = $this->str($a, 'aufgabe');
            $old = $row->aufgabe ?? null;
            if ((string) ($new ?? '') !== (string) ($old ?? '')) {
                $changes['aufgabe'] = ['old' => $old ?? '—', 'new' => $new ?? '—'];
            }
        }

        return $changes;
    }
}
