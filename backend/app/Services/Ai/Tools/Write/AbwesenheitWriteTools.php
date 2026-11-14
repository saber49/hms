<?php

namespace App\Services\Ai\Tools\Write;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\WriteToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;

/**
 * Worker absence write tools (mitarbeiter_abwesenheiten):
 * - create_abwesenheit: office-side absence entry (Urlaub, Krank,
 *   Wunschfrei, …). Office-created entries default to 'genehmigt';
 *   pass status='pending' to mimic a worker's request instead.
 * - cancel_abwesenheit: rejects an existing absence request/entry —
 *   sets status='abgelehnt' + decision metadata (reversible via
 *   rollback, never hard-deletes).
 *
 * Worker-pool scope applies: a restricted user may only touch
 * absences of workers inside his Bereich.
 */
class AbwesenheitWriteTools implements WriteToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return in_array($name, ['create_abwesenheit', 'cancel_abwesenheit'], true);
    }

    public function schemas(): array
    {
        return [
            $this->fn('create_abwesenheit',
                'Trägt eine Abwesenheit für einen Mitarbeiter ein (Urlaub, Krank, Wunschfrei, …). '
                . 'Vom Büro angelegte Einträge sind standardmäßig genehmigt; status="pending" legt sie '
                . 'als offene Anfrage an. Halbe Tage über ganzer_tag=false + startzeit/endzeit.',
                [
                    'dienst_nr'   => ['type' => 'string', 'description' => 'Pflicht — z.B. "MA007"'],
                    'typ'         => ['type' => 'string', 'description' => 'Pflicht — z.B. "Urlaub", "Krank", "Wunschfrei"'],
                    'startdatum'  => ['type' => 'string', 'description' => 'Pflicht — YYYY-MM-DD'],
                    'enddatum'    => ['type' => 'string', 'description' => 'YYYY-MM-DD, default = startdatum'],
                    'ganzer_tag'  => ['type' => 'boolean', 'description' => 'default true'],
                    'startzeit'   => ['type' => 'string', 'description' => 'HH:MM — nur bei ganzer_tag=false'],
                    'endzeit'     => ['type' => 'string', 'description' => 'HH:MM — nur bei ganzer_tag=false'],
                    'bezahlt'     => ['type' => 'boolean', 'description' => 'default true bei Urlaub, sonst false'],
                    'beschreibung'=> ['type' => 'string'],
                    'status'      => ['type' => 'string', 'enum' => ['genehmigt', 'pending'], 'description' => 'default genehmigt'],
                ], ['dienst_nr', 'typ', 'startdatum']),

            $this->fn('cancel_abwesenheit',
                'Lehnt eine Abwesenheit ab (Status → "abgelehnt") — für offene Anfragen UND bereits '
                . 'genehmigte Einträge. Der Eintrag bleibt zur Dokumentation erhalten. '
                . 'Die Abwesenheits-ID steht in mitarbeiter_abwesenheiten (Tool).',
                [
                    'id'             => ['type' => 'integer', 'description' => 'Pflicht — id der Abwesenheit'],
                    'ablehnungsgrund'=> ['type' => 'string', 'description' => 'optionaler Grund'],
                ], ['id']),
        ];
    }

    public function preview(string $name, array $args): array
    {
        return match ($name) {
            'create_abwesenheit' => $this->previewCreateAbwesenheit($args),
            'cancel_abwesenheit' => $this->previewCancelAbwesenheit($args),
            default              => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    public function execute(string $name, array $args, int $userId): array
    {
        return match ($name) {
            'create_abwesenheit' => $this->createAbwesenheit($args, $userId),
            'cancel_abwesenheit' => $this->cancelAbwesenheit($args, $userId),
            default              => throw new \InvalidArgumentException("Unknown write tool: {$name}"),
        };
    }

    // ---------------------------------------------------------------
    // create_abwesenheit
    // ---------------------------------------------------------------
    private function previewCreateAbwesenheit(array $a): array
    {
        [$worker, $start, $ende, $ganz, $status] = $this->resolveAbwesenheitInput($a);

        return [
            'titel'   => 'Abwesenheit eintragen',
            'felder'  => [
                ['Mitarbeiter', $worker->dienst_nr . ' — ' . trim($worker->vorname . ' ' . $worker->name)],
                ['Typ',         trim((string) $a['typ'])],
                ['Zeitraum',    $start . ($ende !== $start ? ' bis ' . $ende : '')],
                ['Dauer',       $ganz ? $this->tage($start, $ende) . ' Tag(e)'
                                      : 'Teilzeit ' . ($a['startzeit'] ?? '?') . '–' . ($a['endzeit'] ?? '?')],
                ['Status',      $status],
                ['Beschreibung',$this->str($a, 'beschreibung') ?? '—'],
            ],
        ];
    }

    private function createAbwesenheit(array $a, int $userId): array
    {
        [$worker, $start, $ende, $ganz, $status] = $this->resolveAbwesenheitInput($a);

        $typ = trim((string) $a['typ']);
        $kategorie = $this->str($a, 'kategorie')
            ?? (mb_stripos($typ, 'urlaub') !== false ? 'urlaub' : 'sonstiges');

        $bezahlt = array_key_exists('bezahlt', $a)
            ? (bool) $a['bezahlt']
            : ($kategorie === 'urlaub');

        $row = [
            'dienst_nr'   => $worker->dienst_nr,
            'typ'         => $typ,
            'kategorie'   => $kategorie,
            'status'      => $status,
            'bezahlt'     => $bezahlt ? 1 : 0,
            'ganzer_tag'  => $ganz ? 1 : 0,
            'startdatum'  => $start,
            'enddatum'    => $ende,
            'startzeit'   => $ganz ? null : $this->normaliseTime($a['startzeit'] ?? null),
            'endzeit'     => $ganz ? null : $this->normaliseTime($a['endzeit'] ?? null),
            'tage'        => $ganz ? $this->tage($start, $ende) : null,
            'beschreibung'=> $this->str($a, 'beschreibung'),
            'entschieden_von' => $status === 'genehmigt' ? $userId : null,
            'entschieden_am'  => $status === 'genehmigt' ? now() : null,
            'created_at'  => now(),
        ];

        $id = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->insertGetId($row);

        \App\Helpers\Realtime::changed('workers');

        return [
            'success' => true,
            'id'      => $id,
            'dienst_nr' => $worker->dienst_nr,
            'typ'     => $typ,
            'zeitraum'=> "$start – $ende",
            'status'  => $status,
        ];
    }

    // ---------------------------------------------------------------
    // cancel_abwesenheit — status transition to 'abgelehnt' (reversible)
    // ---------------------------------------------------------------
    private function previewCancelAbwesenheit(array $a): array
    {
        $row = $this->resolveAbwesenheitRow($a);

        $name = DB::connection('tenant')->table('mitarbeiter')
            ->where('dienst_nr', $row->dienst_nr)
            ->selectRaw("TRIM(CONCAT(COALESCE(vorname,''),' ',COALESCE(name,''))) AS n")
            ->value('n');

        return [
            'titel'   => "Abwesenheit #{$row->id} ablehnen",
            'felder'  => [
                ['Mitarbeiter', $row->dienst_nr . ($name ? " — {$name}" : '')],
                ['Typ',         $row->typ],
                ['Zeitraum',    $row->startdatum . ' – ' . $row->enddatum],
                ['Bisheriger Status', $row->status],
                ['Ablehnungsgrund',   $this->str($a, 'ablehnungsgrund') ?? '—'],
            ],
            'hinweis' => 'Der Eintrag wird als "abgelehnt" markiert und bleibt dokumentiert.',
        ];
    }

    private function cancelAbwesenheit(array $a, int $userId): array
    {
        $row = $this->resolveAbwesenheitRow($a);
        if ($row->status === 'abgelehnt') {
            throw new \InvalidArgumentException('Diese Abwesenheit ist bereits abgelehnt.');
        }

        $update = [
            'status'          => 'abgelehnt',
            'ablehnungsgrund' => $this->str($a, 'ablehnungsgrund'),
            'entschieden_von' => $userId,
            'entschieden_am'  => now(),
        ];

        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->where('id', $row->id)->update($update);

        \App\Helpers\Realtime::changed('workers');

        return [
            'success' => true,
            'id'      => $row->id,
            'status'  => 'abgelehnt',
            '_rollback' => [
                'table' => 'mitarbeiter_abwesenheiten',
                'key'   => ['id' => $row->id],
                'data'  => [
                    'status'          => $row->status,
                    'ablehnungsgrund' => $row->ablehnungsgrund,
                    'entschieden_von' => $row->entschieden_von,
                    'entschieden_am'  => $row->entschieden_am,
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------
    private function resolveAbwesenheitInput(array $a): array
    {
        $dienstNr = trim((string) ($a['dienst_nr'] ?? ''));
        if ($dienstNr === '') throw new \InvalidArgumentException('dienst_nr ist Pflicht.');

        $worker = DB::connection('tenant')->table('mitarbeiter')
            ->where('dienst_nr', $dienstNr)->first(['dienst_nr', 'vorname', 'name']);
        if (!$worker || !BereichScope::allowsWorker(request()->user(), $dienstNr)) {
            throw new \InvalidArgumentException("Mitarbeiter {$dienstNr} nicht gefunden.");
        }

        $typ = trim((string) ($a['typ'] ?? ''));
        if ($typ === '') throw new \InvalidArgumentException('typ ist Pflicht (z.B. Urlaub, Krank, Wunschfrei).');

        $start = $this->date($a, 'startdatum');
        if (!$start) throw new \InvalidArgumentException('startdatum ist Pflicht (YYYY-MM-DD).');
        $ende = $this->date($a, 'enddatum') ?? $start;
        if (strtotime($ende) < strtotime($start)) {
            throw new \InvalidArgumentException('enddatum darf nicht vor startdatum liegen.');
        }

        $ganz = array_key_exists('ganzer_tag', $a) ? (bool) $a['ganzer_tag'] : true;
        if (!$ganz) {
            $sz = $this->normaliseTime($a['startzeit'] ?? null);
            $ez = $this->normaliseTime($a['endzeit'] ?? null);
            if (!$sz || !$ez) {
                throw new \InvalidArgumentException('Bei ganzer_tag=false sind startzeit und endzeit (HH:MM) Pflicht.');
            }
        }

        $status = strtolower((string) ($a['status'] ?? 'genehmigt'));
        if (!in_array($status, ['genehmigt', 'pending'], true)) $status = 'genehmigt';

        return [$worker, $start, $ende, $ganz, $status];
    }

    private function resolveAbwesenheitRow(array $a): object
    {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) throw new \InvalidArgumentException('id ist Pflicht.');

        $row = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->where('id', $id)->first();
        if (!$row || !BereichScope::allowsWorker(request()->user(), $row->dienst_nr)) {
            throw new \InvalidArgumentException("Abwesenheit {$id} nicht gefunden.");
        }
        return $row;
    }

    private function tage(string $start, string $ende): float
    {
        return (float) ((strtotime($ende) - strtotime($start)) / 86400 + 1);
    }
}
