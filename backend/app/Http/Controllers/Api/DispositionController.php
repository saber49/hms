<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NuNotificationMail;
use App\Services\AbrechnungDataService;
use App\Services\TenantService;
use App\Support\BereichScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class DispositionController extends Controller
{
    public function planner(Request $request)
    {
        $view = $request->query('view', 'week');

        if ($view === 'month') {
            $month = intval($request->query('month', date('n')));
            $year = intval($request->query('year', date('Y')));
            $rangeStart = new \DateTime("$year-$month-01");
            $rangeEnd = (clone $rangeStart)->modify('last day of this month');
        } else {
            $rangeStart = $this->getWeekStartDate($request->query('week_start'));
            $rangeEnd = (clone $rangeStart)->modify('+6 days');
        }

        $startStr = $rangeStart->format('Y-m-d');
        $endStr = $rangeEnd->format('Y-m-d');

        $where = ['e.datum BETWEEN ? AND ?'];
        $params = [$startStr, $endStr];

        if ($request->filled('kunden_id')) { $where[] = 'e.kunden_id = ?'; $params[] = $request->kunden_id; }
        if ($request->filled('nu_id')) { $where[] = 'e.nu_id = ?'; $params[] = $request->nu_id; }
        if ($request->filled('status')) { $where[] = 'e.hms_bestaetigt = ?'; $params[] = $request->status; }

        // Per-user Bereich restriction (no-op for admins / unrestricted users).
        // Applied at SQL level so cards, overview counts and totals stay in
        // sync. Bereich = article Bereich, falling back to the customer's.
        BereichScope::applySql(
            $request->user(),
            "COALESCE(NULLIF(TRIM(a.bereich), ''), NULLIF(TRIM(k.bereich), ''))",
            $where,
            $params
        );

        // Customer color — tenants that haven't run the farbe migration yet
        // simply get NULL so the planner keeps working.
        $farbeSelect = Schema::connection('tenant')->hasColumn('kunden', 'farbe')
            ? 'k.farbe AS kunden_farbe'
            : 'NULL AS kunden_farbe';

        $sql = "
            SELECT e.*, {$farbeSelect}, k.kundenname, k.bereich AS kunden_bereich, o.objektname,
                   a.artikel_name, a.bereich AS artikel_bereich,
                   b.eingang_id, b.status AS bestell_status,
                   n.firmenname AS nu_name,
                   d.tag_nr AS plan_tag_nr, d.wochentag, d.zeit_von AS plan_start, d.zeit_bis AS plan_end,
                   d.soll_std AS plan_std, d.gesetzl_pause AS plan_gesetzl_pause,
                   d.beschreibung AS plan_beschreibung, d.menge AS plan_menge
            FROM einsaetze e
            LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
            LEFT JOIN objekte o ON e.obj_id = o.obj_id
            LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN nachunternehmer n ON e.nu_id = n.nu_id
            LEFT JOIN bestelleingang_tage d
                   ON d.eingang_id = b.eingang_id
                  AND d.datum = e.datum
                  AND (
                        (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                     OR (e.tag_nr IS NULL AND d.tag_nr = (
                            SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2
                             WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum
                        ))
                      )
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.datum ASC, COALESCE(e.ist_start, d.zeit_von) ASC, e.id ASC
            LIMIT 2000
        ";

        $tasks = DB::connection('tenant')->select($sql, $params);
        $einsatzIds = array_values(array_filter(array_map(fn($t) => $t->einsatz_id, $tasks)));

        $assignmentsByTask = $this->fetchTaskAssignments($einsatzIds);

        // Worker/NU pickers follow the same Bereich scope: untagged people
        // form the shared pool; tagged ones need a matching Bereich or an
        // in-scope Einsatz they already serve.
        [$pSql, $pParams] = BereichScope::workerPredicate($request->user(), 'mitarbeiter');
        $workers = DB::connection('tenant')->table('mitarbeiter')
            ->where('aktiv', 1)->whereRaw($pSql, $pParams)
            ->orderBy('vorname')->orderBy('name')
            ->select('dienst_nr', 'vorname', 'name', 'foto', 'bereich', 'arbeitsstunden', 'arbeitsstunden_typ', 'aktiv')
            ->get();

        [$sSql, $sParams] = BereichScope::nuPredicate($request->user(), 'nachunternehmer');
        $subcontractors = DB::connection('tenant')->table('nachunternehmer')
            ->where('aktiv', 1)->whereRaw($sSql, $sParams)->orderBy('firmenname')
            ->select('nu_id', 'firmenname', 'bereich')
            ->get();

        // Load per-NU statuses for all einsatz IDs
        $nuStatusesByTask = [];
        $nuWorkerCountByTask = [];
        if (!empty($einsatzIds)) {
            $allNuStatuses = DB::connection('tenant')->table('einsatz_nu_status')
                ->whereIn('einsatz_id', $einsatzIds)
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('nu_schicht_positionen')
                        ->whereColumn('nu_schicht_positionen.einsatz_id', 'einsatz_nu_status.einsatz_id')
                        ->whereColumn('nu_schicht_positionen.nu_id', 'einsatz_nu_status.nu_id');
                })
                ->get();
            foreach ($allNuStatuses as $ns) {
                $nuStatusesByTask[$ns->einsatz_id][$ns->nu_id] = $ns->status;
            }

            // Count distinct NU workers per einsatz
            $nuCounts = DB::connection('tenant')->table('nu_position_zuweisungen as npz')
                ->join('nu_schicht_positionen as nsp', 'npz.position_id', '=', 'nsp.id')
                ->whereIn('nsp.einsatz_id', $einsatzIds)
                ->selectRaw('nsp.einsatz_id, COUNT(DISTINCT npz.nu_mitarbeiter_id) as nu_count')
                ->groupBy('nsp.einsatz_id')
                ->get();
            foreach ($nuCounts as $nc) {
                $nuWorkerCountByTask[$nc->einsatz_id] = (int) $nc->nu_count;
            }
        }

        $days = [];
        $dayNames = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $cursor = clone $rangeStart;
        while ($cursor <= $rangeEnd) {
            $dayIndex = intval($cursor->format('N')) - 1;
            $days[] = [
                'date' => $cursor->format('Y-m-d'),
                'day_name' => $dayNames[$dayIndex],
                'label' => $cursor->format('d.m.'),
                'is_today' => $cursor->format('Y-m-d') === date('Y-m-d'),
            ];
            $cursor->modify('+1 day');
        }

        $cards = [];
        foreach ($tasks as $task) {
            $displayStart = $task->ist_start ?: ($task->plan_start ?? null);
            $displayEnd = $task->ist_ende ?: ($task->plan_end ?? null);

            // Subtitle: Object name + Article (service) when present
            $subtitle = $task->objektname ?: $task->obj_id;
            if (!empty($task->artikel_name)) {
                $subtitle .= ' · ' . $task->artikel_name;
            }

            $cards[] = [
                'einsatz_id' => $task->einsatz_id,
                'bestell_id' => $task->bestell_id,
                'eingang_id' => $task->eingang_id ?? null,
                'date' => $task->datum,
                'start' => $displayStart,
                'end' => $displayEnd,
                'title' => $task->kundenname ?: $task->bestell_id,
                'kunde_farbe' => $task->kunden_farbe ?? null,
                'subtitle' => $subtitle,
                'artikel_name' => $task->artikel_name ?? null,
                'artikel_bereich' => $task->artikel_bereich ?? null,
                // Bereich shown in the Dispo filter — sourced from the article
                // (the service performed), falling back to the customer Bereich
                // when the article has none.
                'bereich' => (trim((string) ($task->artikel_bereich ?? '')) !== '')
                    ? $task->artikel_bereich
                    : ($task->kunden_bereich ?? null),
                'description' => $task->beschreibung ?: ($task->plan_beschreibung ?? null),
                'status' => $task->hms_bestaetigt,
                'planner_state' => $task->hms_bestaetigt === 'ja' ? 'confirmed' :
                    ($task->hms_bestaetigt === 'nein' ? 'rejected' :
                    (($task->pruefung ?? '') === 'geplant' ? 'planned' : 'new')),
                'worker_count' => count($assignmentsByTask[$task->einsatz_id] ?? []) + ($nuWorkerCountByTask[$task->einsatz_id] ?? 0),
                'workers' => $assignmentsByTask[$task->einsatz_id] ?? [],
                'nu_id' => $task->nu_id,
                'nu_statuses' => $nuStatusesByTask[$task->einsatz_id] ?? [],
                'nu_name' => $task->nu_name,
                'gesetzl_pause' => $task->gesetzl_pause ?: ($task->plan_gesetzl_pause ?? null),
                'soll_menge' => $task->plan_menge ?? $this->sollMengeFromAnforderungen($task->anforderungen ?? null),
                'anforderungen' => isset($task->anforderungen) ? json_decode($task->anforderungen, true) : null,
                'bestell_storno_at' => $task->bestell_storno_at ?? null,
            ];
        }

        $overview = [
            'ausstehend' => count(array_filter($tasks, fn($t) => $t->hms_bestaetigt === 'ausstehend' && ($t->pruefung ?? '') !== 'geplant')),
            'geplant' => count(array_filter($tasks, fn($t) => $t->hms_bestaetigt !== 'ja' && $t->hms_bestaetigt !== 'nein' && ($t->pruefung ?? '') === 'geplant')),
            'bestaetigt' => count(array_filter($tasks, fn($t) => $t->hms_bestaetigt === 'ja')),
            'abgelehnt' => count(array_filter($tasks, fn($t) => $t->hms_bestaetigt === 'nein')),
            'heute_faellig' => count(array_filter($tasks, fn($t) => $t->datum === date('Y-m-d'))),
            'gesamt' => count($tasks),
            'truncated' => count($tasks) >= 2000,
        ];

        // Distinct Bereiche that actually have Einsätze in the Dispo — across ALL
        // dates, not just the shown range. A Bereich box only appears once an
        // order has been sent to the Dispo (i.e. an Einsatz exists for it).
        // Source matches the per-card logic: article Bereich, customer fallback.
        $allBereicheRows = DB::connection('tenant')->select("
            SELECT DISTINCT COALESCE(NULLIF(TRIM(a.bereich), ''), NULLIF(TRIM(k.bereich), '')) AS bereich
            FROM einsaetze e
            LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
            LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
        ");
        $allowedBereiche = BereichScope::for($request->user());
        $allBereiche = collect($allBereicheRows)
            ->map(fn($r) => trim((string) ($r->bereich ?? '')))
            ->filter()
            ->unique(fn($v) => mb_strtolower($v))
            // Restricted users only see their own Bereich boxes in the picker.
            ->filter(fn($v) => BereichScope::listAllows($allowedBereiche, $v))
            ->sort()
            ->values();

        return response()->json([
            'view' => $view,
            'week_start' => $startStr,
            'week_end' => $endStr,
            'month' => intval($rangeStart->format('n')),
            'year' => intval($rangeStart->format('Y')),
            'days' => $days,
            'overview' => $overview,
            'cards' => $cards,
            'workers' => $workers,
            'subcontractors' => $subcontractors,
            'all_bereiche' => $allBereiche,
        ]);
    }

    /**
     * Global lookup for the planner search: returns the date span of all
     * einsätze matching the same fields the client-side card filter uses,
     * so the UI can jump to the month that actually contains the hits.
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['von' => null, 'bis' => null]);
        }
        $s = '%' . $q . '%';

        // b.eingang_id makes the Bestelleingang number (BE-…) searchable —
        // that is the ID employees actually see and copy from the Eingang list.
        $where = ["(e.bestell_id LIKE ? OR e.einsatz_id LIKE ?
                    OR b.eingang_id LIKE ?
                    OR k.kundenname LIKE ? OR o.objektname LIKE ?
                    OR a.artikel_name LIKE ? OR n.firmenname LIKE ?
                    OR m.vorname LIKE ? OR m.name LIKE ?
                    OR CONCAT(m.vorname, ' ', m.name) LIKE ?)"];
        $params = array_fill(0, 10, $s);

        BereichScope::applySql(
            $request->user(),
            "COALESCE(NULLIF(TRIM(a.bereich), ''), NULLIF(TRIM(k.bereich), ''))",
            $where,
            $params
        );

        $sql = "
            SELECT MIN(e.datum) AS von, MAX(e.datum) AS bis
            FROM einsaetze e
            LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
            LEFT JOIN objekte o ON e.obj_id = o.obj_id
            LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN nachunternehmer n ON e.nu_id = n.nu_id
            LEFT JOIN einsatz_mitarbeiter em ON em.einsatz_id = e.einsatz_id
            LEFT JOIN mitarbeiter m ON em.dienst_nr = m.dienst_nr
            WHERE " . implode(' AND ', $where);

        $row = DB::connection('tenant')->select($sql, $params)[0] ?? null;
        return response()->json([
            'von' => $row->von ?? null,
            'bis' => $row->bis ?? null,
        ]);
    }

    public function saveAssignment(Request $request)
    {
        $data = $request->all();
        $einsatzId = $data['einsatz_id'] ?? '';
        if (!$einsatzId) return response()->json(['error' => 'einsatz_id erforderlich'], 400);

        // Fetch the einsatz WITH its originally-planned time (plan_start/plan_ende)
        // resolved from bestelleingang_tage, mirroring TaskController::show so the
        // time-change detection below can compare the actual time against the plan
        // (the "Ursprünglich geplant" baseline shown in the planner UI). e.* keeps
        // every einsaetze column the rest of this method relies on.
        $task = DB::connection('tenant')
            ->table('einsaetze as e')
            ->leftJoin('bestellungen as b', 'e.bestell_id', '=', 'b.bestell_id')
            ->leftJoin('bestelleingang_tage as d', function ($join) {
                $join->on('d.eingang_id', '=', 'b.eingang_id')
                     ->on('d.datum', '=', 'e.datum')
                     ->whereRaw('(
                         (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                      OR (e.tag_nr IS NULL AND d.tag_nr = (
                             SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2
                              WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum
                         ))
                     )');
            })
            ->where('e.einsatz_id', $einsatzId)
            ->select('e.*', 'd.zeit_von as plan_start', 'd.zeit_bis as plan_ende', 'd.menge as plan_menge')
            ->first();
        if (!$task || !BereichScope::allowsEinsatz($request->user(), $einsatzId)) {
            return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        }

        // Confirmed shifts are immutable through the Dispo planner. The UI opens
        // them read-only, but a stale modal (opened before another user confirmed)
        // or a direct API call could otherwise strip workers from a confirmed
        // shift — sending bogus cancellation emails while the einsatz stays 'ja'.
        // A repeated confirm from a stale modal is blocked too, since it would
        // silently apply that modal's outdated worker list and times.
        if (($task->hms_bestaetigt ?? null) === 'ja') {
            return response()->json(['error' => 'Einsatz ist bereits bestätigt und kann nicht mehr bearbeitet werden.'], 409);
        }

        // Storno-locked shifts (day removed from Bestelleingang) are immutable
        // too: the card is grayed-out in the UI, but a stale modal or direct
        // API call could otherwise modify the shift. The day must be re-added
        // to the Bestelleingang first to reactivated the shift.
        if (($task->bestell_storno_at ?? null) !== null) {
            return response()->json(['error' => 'Einsatz ist gesperrt (Tag wurde vom Bestelleingang entfernt). Bitte den Tag im Bestelleingang erneut hinzufügen, um den Einsatz zu reaktivieren.'], 409);
        }

        $workerIds = array_values(array_unique(array_filter($data['worker_ids'] ?? [])));
        $nuId = $data['nu_id'] ?? null;
        $note = array_key_exists('bemerkung', $data) ? $data['bemerkung'] : $task->bemerkung;
        $beschreibung = array_key_exists('beschreibung', $data) ? $data['beschreibung'] : $task->beschreibung;
        $normalizeTime = fn($value) => trim((string) ($value ?? '')) === '' ? null : substr(trim((string) $value), 0, 5);
        foreach (['ist_start', 'ist_ende'] as $field) {
            if (array_key_exists($field, $data) && $normalizeTime($data[$field]) !== $normalizeTime($task->{$field})) {
                return response()->json(['error' => 'Schichtzeiten können nur im Bestelleingang geändert werden.'], 409);
            }
        }
        $istStart = $task->ist_start;
        $istEnde = $task->ist_ende;
        $shouldConfirm = !empty($data['confirm']);

        // Validate time format (real clock values only — 00-23:00-59)
        $timeRe = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
        if ($istStart && !preg_match($timeRe, $istStart)) {
            return response()->json(['error' => 'Ungültiges Zeitformat für ist_start'], 400);
        }
        if ($istEnde && !preg_match($timeRe, $istEnde)) {
            return response()->json(['error' => 'Ungültiges Zeitformat für ist_ende'], 400);
        }

        // Validate worker IDs exist, are active AND inside the caller's
        // Bereich scope — an out-of-scope worker is rejected like an
        // invalid one so no cross-Bereich assignment can be forced via API.
        if (!empty($workerIds)) {
            [$waSql, $waParams] = BereichScope::workerPredicate($request->user(), 'mitarbeiter');
            $validWorkers = DB::connection('tenant')->table('mitarbeiter')
                ->whereIn('dienst_nr', $workerIds)->where('aktiv', 1)
                ->whereRaw($waSql, $waParams)
                ->pluck('dienst_nr')->toArray();
            $invalidIds = array_diff($workerIds, $validWorkers);
            if (!empty($invalidIds)) {
                return response()->json(['error' => 'Ungültige oder inaktive Mitarbeiter: ' . implode(', ', $invalidIds)], 400);
            }
        }

        // Validate NU exists, is active and inside the caller's Bereich scope.
        if ($nuId) {
            $nuExists = DB::connection('tenant')->table('nachunternehmer')
                ->where('nu_id', $nuId)->where('aktiv', 1)->exists();
            if (!$nuExists || !BereichScope::allowsNu($request->user(), (string) $nuId)) {
                return response()->json(['error' => 'Nachunternehmer nicht gefunden oder inaktiv'], 400);
            }
        }

        // NU workers assigned via positions — used by the confirm guard below AND
        // by the pruefung reset: a shift staffed purely by NU workers must keep
        // its 'geplant' state on a plain save (worker_ids is empty for NU-only).
        $hasNuWorkers = DB::connection('tenant')->table('nu_schicht_positionen as nsp')
            ->join('nu_position_zuweisungen as npz', 'npz.position_id', '=', 'nsp.id')
            ->where('nsp.einsatz_id', $einsatzId)->exists();

        // Confirm guard: require workers or NU workers
        if ($shouldConfirm && empty($workerIds) && !$hasNuWorkers) {
            return response()->json(['error' => 'Bestätigung erfordert zugewiesene Mitarbeiter oder NU-Mitarbeiter'], 400);
        }

        // Short-staffing guard: confirming a shift with fewer workers than the
        // required Menge is almost always a dispatcher oversight. Instead of
        // silently confirming we report the gap — the client asks the user and
        // re-sends with confirm_short=true to confirm intentionally.
        if ($shouldConfirm && empty($data['confirm_short'])) {
            $soll = (float) ($task->plan_menge
                ?? $this->sollMengeFromAnforderungen($task->anforderungen ?? null)
                ?? 0);
            if ($soll > 0) {
                $nuAssigned = DB::connection('tenant')->table('nu_schicht_positionen as nsp')
                    ->join('nu_position_zuweisungen as npz', 'npz.position_id', '=', 'nsp.id')
                    ->where('nsp.einsatz_id', $einsatzId)->count();
                $assignedTotal = count($workerIds) + $nuAssigned;
                if ($assignedTotal < $soll) {
                    return response()->json([
                        'short_staffed' => true,
                        'required'      => (int) $soll,
                        'assigned'      => $assignedTotal,
                    ]);
                }
            }
        }

        DB::connection('tenant')->beginTransaction();
        try {
            // Sync worker assignments — only add/remove what changed (preserves existing row data)
            $currentIds = DB::connection('tenant')->table('einsatz_mitarbeiter')
                ->where('einsatz_id', $einsatzId)->pluck('dienst_nr')->toArray();

            $toRemove = array_diff($currentIds, $workerIds);
            $toAdd = array_diff($workerIds, $currentIds);

            if (!empty($toRemove)) {
                DB::connection('tenant')->table('einsatz_mitarbeiter')
                    ->where('einsatz_id', $einsatzId)
                    ->whereIn('dienst_nr', $toRemove)
                    ->delete();
                // A removed worker may still have a RUNNING clock-in on this
                // shift — end it now so the timer never keeps counting on an
                // assignment that no longer exists (Arbeitszeitaufzeichnung).
                WorkerAppZeitController::closeOpenShiftsForEinsatz(
                    (string) $einsatzId,
                    array_map('strval', $toRemove),
                    'Aus Einsatzplanung entfernt — Schicht automatisch beendet'
                );
            }

            $workerHours = $data['worker_hours'] ?? [];
            // Per-worker task ("was macht dieser Mitarbeiter") sent to the
            // worker's app. Only authoritative when the client sends the key,
            // so older clients that omit it never wipe already-saved tasks.
            $tasksProvided = array_key_exists('worker_tasks', $data) && is_array($data['worker_tasks']);
            $workerTasks = $tasksProvided ? $data['worker_tasks'] : [];
            $hasAufgabe = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'aufgabe');
            foreach ($workerIds as $index => $workerId) {
                // worker_hours is authoritative ONLY where the key exists —
                // the client omits workers whose hour field was left blank,
                // and a missing key must never wipe already-recorded
                // actuals (ist_std) on the assignment row.
                $hoursProvided = is_array($workerHours) && array_key_exists($workerId, $workerHours);
                $std = $hoursProvided && $workerHours[$workerId] !== '' ? floatval($workerHours[$workerId]) : null;
                $aufgabe = isset($workerTasks[$workerId]) && trim((string) $workerTasks[$workerId]) !== ''
                    ? trim((string) $workerTasks[$workerId])
                    : null;
                if (in_array($workerId, $toAdd)) {
                    $row = [
                        'einsatz_id' => $einsatzId,
                        'dienst_nr' => $workerId,
                        'ist_std' => $std,
                        'sortierung' => $index,
                    ];
                    if ($hasAufgabe) $row['aufgabe'] = $aufgabe;
                    DB::connection('tenant')->table('einsatz_mitarbeiter')->insert($row);
                } else {
                    $upd = ['sortierung' => $index];
                    if ($hoursProvided) $upd['ist_std'] = $std;
                    if ($hasAufgabe && $tasksProvided) $upd['aufgabe'] = $aufgabe;
                    DB::connection('tenant')->table('einsatz_mitarbeiter')
                        ->where('einsatz_id', $einsatzId)
                        ->where('dienst_nr', $workerId)
                        ->update($upd);
                }
                // The worker may have clocked in/out BEFORE this save —
                // e.g. his assignment row was re-created after an automatic
                // clock-out, or a previous save wiped ist_std to NULL.
                // Restore the real Arbeitszeit from zeiterfassung unless the
                // planner just typed the hours explicitly in this request.
                if (!$hoursProvided) {
                    WorkerAppZeitController::backfillWorkerActuals((string) $einsatzId, (string) $workerId);
                }
            }

            $update = [
                // The NU link mirrors the NU positions (server side). The
                // client's hidden field can be stale (e.g. the NU rejected
                // while this modal was open) — it never overrides positions,
                // and without positions the stored value is kept.
                'nu_id' => \App\Support\NuLink::resolve((string) $einsatzId, $task->nu_id ?? null),
                'bemerkung' => $note,
                'beschreibung' => $beschreibung,
                'ist_start' => $istStart,
                'ist_ende' => $istEnde,
            ];
            // Only revoke the planning state when NO workers remain at all —
            // neither internal nor NU. Previously an NU-only planned shift lost
            // its 'geplant' state on every plain save (worker_ids always empty).
            if (empty($workerIds) && !$hasNuWorkers) {
                $update['pruefung'] = null;
                // "Plan aufheben" — record when the published plan was
                // withdrawn so the planner modal can show an audit notice
                // above the assignment list. Only a previously 'geplant'
                // shift counts as withdrawn; a draft just stays a draft.
                if (($task->pruefung ?? null) === 'geplant'
                    && Schema::connection('tenant')->hasColumn('einsaetze', 'plan_aufgehoben_at')) {
                    $update['plan_aufgehoben_at'] = now();
                }
            }
            if ($shouldConfirm) $update['hms_bestaetigt'] = 'ja';

            DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->update($update);

            DB::connection('tenant')->commit();

            // Workers removed from an ALREADY-planned shift previously received
            // the "Neuer Einsatzplan" email, so tell them their assignment was
            // cancelled. This is the "Plan aufheben" case (every assignee
            // removed) as well as removing a single worker from a planned
            // shift. Skipped for draft shifts (pruefung != 'geplant') where no
            // planning email ever went out, so no cancellation is warranted.
            if (!empty($toRemove) && ($task->pruefung ?? null) === 'geplant') {
                $this->notifyWorkersShiftCancelled($einsatzId, array_map('strval', $toRemove), $task);
            }

            // Detect time change and notify NU companies + assigned workers.
            //
            // Two distinct triggers (the previous code only handled the first,
            // which is why confirming a shift whose time already deviated from
            // the plan sent no email):
            //   1) editedNow        — the actual time changed in THIS save
            //                         (baseline = previously-saved ist time).
            //   2) deviatesFromPlan — the actual time differs from what was
            //                         originally planned, surfaced at confirm
            //                         time (baseline = planned time). This
            //                         mirrors the "Ursprünglich geplant" hint
            //                         shown in the planner UI.
            $prevStart = substr($task->ist_start ?? '', 0, 5);
            $prevEnd   = substr($task->ist_ende ?? '', 0, 5);
            $planStart = substr($task->plan_start ?? '', 0, 5);
            $planEnd   = substr($task->plan_ende ?? '', 0, 5);
            $newStart  = substr($istStart ?? '', 0, 5);
            $newEnd    = substr($istEnde ?? '', 0, 5);

            $editedNow        = ($prevStart !== $newStart) || ($prevEnd !== $newEnd);
            $deviatesFromPlan = ($planStart && $planStart !== $newStart)
                             || ($planEnd && $planEnd !== $newEnd);

            if (($editedNow || ($shouldConfirm && $deviatesFromPlan)) && ($newStart || $newEnd)) {
                // For the "old" column in the email, use the previous actual
                // time on a live edit, otherwise the originally planned time.
                $baseStart = $editedNow ? $prevStart : $planStart;
                $baseEnd   = $editedNow ? $prevEnd   : $planEnd;

                \Log::info('SaveAssignment: time change, notifying NU + workers', [
                    'einsatz_id' => $einsatzId,
                    'reason'     => $editedNow ? 'edited' : 'confirm_deviates_from_plan',
                    'old'        => $baseStart . '–' . $baseEnd,
                    'new'        => $newStart . '–' . $newEnd,
                ]);
                $this->notifyNuTimeChange($einsatzId, $task, $baseStart, $baseEnd, $newStart, $newEnd);
                $this->notifyWorkersTimeChange($einsatzId, $baseStart, $baseEnd, $newStart, $newEnd);
            }

            \App\Helpers\Realtime::changed('dispo');
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            DB::connection('tenant')->rollBack();
            return response()->json(['error' => 'Speichern fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }

    public function bulkMarkPlanned(Request $request)
    {
        $ids = $request->input('einsatz_ids', []);
        if (!is_array($ids) || empty($ids)) return response()->json(['error' => 'Keine Einsätze angegeben'], 400);

        // Bereich restriction: only in-scope Einsätze may be planned.
        $ids = $this->scopeEinsatzIds($request->user(), $ids);
        if (empty($ids)) return response()->json(['error' => 'Keine Einsätze im erlaubten Bereich'], 403);

        // Exclude storno-locked shifts: their day was removed from the
        // Bestelleingang and they cannot be re-planned until reactivated.
        if (Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')) {
            $lockedIds = DB::connection('tenant')->table('einsaetze')
                ->whereIn('einsatz_id', $ids)
                ->whereNotNull('bestell_storno_at')
                ->pluck('einsatz_id')->all();
            $ids = array_values(array_diff($ids, $lockedIds));
            if (empty($ids)) return response()->json(['error' => 'Ausgewählte Einsätze sind gesperrt (Tag wurde vom Bestelleingang entfernt).'], 409);
        }

        // Exclude ended shifts: publishing a plan for a shift whose scheduled
        // end already passed only spams workers about a past Einsatz and
        // leaves a stale "geplant" card in Dispo. An ended shift is finished —
        // it can be confirmed, never (re-)planned.
        $endedIds = DB::connection('tenant')->table('einsaetze')
            ->whereIn('einsatz_id', $ids)
            ->get(['einsatz_id', 'datum', 'ist_start', 'ist_ende'])
            ->filter(fn ($e) => $this->einsatzEnded($e->datum, $e->ist_start, $e->ist_ende))
            ->pluck('einsatz_id')->all();
        $ids = array_values(array_diff($ids, $endedIds));
        if (empty($ids)) {
            return response()->json(['error' => 'Ausgewählte Einsätze sind bereits abgelaufen und können nicht mehr geplant werden.'], 409);
        }

        // Eligible = has internal worker(s) OR has NU worker(s) assigned.
        // Compute the set in 2 grouped queries instead of N×2 exists() calls.
        $eligibleIds = $this->einsatzIdsWithAnyWorker($ids);
        $toUpdate = array_values(array_intersect($ids, $eligibleIds));

        if (!empty($toUpdate)) {
            // Split into shifts being planned for the FIRST time vs shifts that
            // were ALREADY 'geplant'. Re-marking an already-planned shift (e.g.
            // after only the work time was changed) must NOT re-send the "Neuer
            // Einsatzplan" email — the worker already received it, and only the
            // time-change email from saveAssignment should go out in that case.
            $alreadyPlanned = DB::connection('tenant')->table('einsaetze')
                ->whereIn('einsatz_id', $toUpdate)
                ->where('pruefung', 'geplant')
                ->pluck('einsatz_id')->all();
            $newlyPlanned = array_values(array_diff($toUpdate, $alreadyPlanned));

            $planUpdate = ['pruefung' => 'geplant'];
            // Audit stamps for the plan-cycle notice: (re-)publishing starts
            // a fresh cycle, so any previous withdrawal stamp is cleared and
            // newly-published shifts get their publish timestamp.
            $hasPlanCycleCols = Schema::connection('tenant')->hasColumn('einsaetze', 'geplant_at');
            if ($hasPlanCycleCols) $planUpdate['plan_aufgehoben_at'] = null;
            DB::connection('tenant')->table('einsaetze')
                ->whereIn('einsatz_id', $toUpdate)
                ->update($planUpdate);
            if ($hasPlanCycleCols && !empty($newlyPlanned)) {
                DB::connection('tenant')->table('einsaetze')
                    ->whereIn('einsatz_id', $newlyPlanned)
                    ->update(['geplant_at' => now()]);
            }

            // First-time planning → notify every assigned worker.
            $this->notifyWorkersShiftPlanned($newlyPlanned);

            // Already-planned shifts → only notify workers who were newly added
            // since the planner modal opened (current assignees minus
            // previous_worker_ids). Without previous_worker_ids (the bulk
            // "mark week" action) these are skipped entirely so already-notified
            // workers are never re-spammed.
            $previousWorkerIds = $request->input('previous_worker_ids');
            if (!empty($alreadyPlanned) && is_array($previousWorkerIds)) {
                $this->notifyWorkersShiftPlanned($alreadyPlanned, array_map('strval', $previousWorkerIds));
            }
        }

        \App\Helpers\Realtime::changed('dispo');
        return response()->json([
            'success' => true,
            'updated' => count($toUpdate),
            'skipped' => count($ids) - count($toUpdate),
        ]);
    }

    /**
     * Whether the shift's scheduled end already passed (Europe/Berlin wall
     * clock — the dispatch timezone). Overnight shifts (end < start) end on
     * the following day; a missing end time falls back to end-of-day. Missing
     * or unparseable datum → not ended (never block on an unknown).
     * Mirrors plannerShiftEnded() in the planner frontend.
     */
    protected function einsatzEnded($datum, $istStart, $istEnde): bool
    {
        $d = substr((string) ($datum ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        $s = substr((string) ($istStart ?? ''), 0, 5);
        $e = substr((string) ($istEnde ?? ''), 0, 5);
        $endDay = \Carbon\Carbon::parse($d, 'Europe/Berlin')->startOfDay();
        if (preg_match('/^\d{2}:\d{2}$/', $s) && preg_match('/^\d{2}:\d{2}$/', $e) && $e < $s) {
            $endDay->addDay(); // overnight
        }
        $endDay->setTimeFromTimeString((preg_match('/^\d{2}:\d{2}$/', $e) ? $e : '23:59') . ':00');
        return now()->greaterThanOrEqualTo($endDay);
    }

    /**
     * Email every assigned internal worker (with a valid email) that their
     * shift plan was sent. Called AFTER the DB update in bulkMarkPlanned so
     * an SMTP/queue hiccup never affects the actual planning state.
     *
     * @param  array<int|string>  $einsatzIds
     */
    protected function notifyWorkersShiftPlanned(array $einsatzIds, ?array $excludeWorkerIds = null): void
    {
        if (empty($einsatzIds)) return;

        $hasAufgabe = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'aufgabe');

        // In-app notification for ALL newly assigned workers — independent of
        // email, so the worker app (bell + push-style banner) always fires.
        try {
            $inAppRows = DB::connection('tenant')
                ->table('einsatz_mitarbeiter as em')
                ->join('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
                ->join('einsaetze as e', 'em.einsatz_id', '=', 'e.einsatz_id')
                ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
                ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                ->whereIn('em.einsatz_id', $einsatzIds)
                ->when(!empty($excludeWorkerIds), fn($q) => $q->whereNotIn('em.dienst_nr', $excludeWorkerIds))
                ->select('m.id as m_id', 'em.dienst_nr', 'e.einsatz_id', 'e.datum',
                         'e.ist_start', 'e.ist_ende', 'e.ort', 'k.kundenname', 'o.objektname')
                ->get();

            $inserts = [];
            foreach ($inAppRows as $n) {
                $zeitN = substr($n->ist_start ?? '', 0, 5) . ' – ' . substr($n->ist_ende ?? '', 0, 5);
                $datumN = $n->datum ? \Carbon\Carbon::parse($n->datum)->format('d.m.Y') : '–';
                $inserts[] = [
                    'mitarbeiter_id' => $n->m_id,
                    'dienst_nr'      => $n->dienst_nr,
                    'type'           => 'shift.geplant',
                    'title'          => 'Neuer Einsatz: ' . $datumN,
                    'body'           => ($n->objektname ?: ($n->kundenname ?: 'Einsatz')) . ' · ' . $zeitN
                                        . ($n->ort ? "\nOrt: " . $n->ort : ''),
                    'payload'        => json_encode(['einsatz_id' => $n->einsatz_id, 'datum' => $n->datum], JSON_UNESCAPED_UNICODE),
                    'gelesen'        => 0,
                    'created_at'     => now(),
                ];
            }
            if ($inserts) {
                DB::connection('tenant')->table('worker_notifications')->insert($inserts);
                // Real push to each worker's devices (works with app closed).
                // Queued: each push is a slow external HTTP call — sending
                // inline froze bulk actions ("Woche planen") for many seconds.
                $tenantId = (int) TenantService::current()->id;
                foreach ($inserts as $ins) {
                    \App\Jobs\SendWorkerPush::dispatch(
                        $tenantId, (string) $ins['dienst_nr'], $ins['title'], $ins['body'], 'plan'
                    );
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('notifyWorkersShiftPlanned in-app insert failed: ' . $e->getMessage());
        }

        $query = DB::connection('tenant')
            ->table('einsatz_mitarbeiter as em')
            ->join('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
            ->join('einsaetze as e', 'em.einsatz_id', '=', 'e.einsatz_id')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->whereIn('em.einsatz_id', $einsatzIds)
            ->whereNotNull('m.email')
            ->where('m.email', '!=', '');

        // Skip workers who were already assigned (and thus already notified) on
        // a re-plan, so only genuinely new assignees get the "Neuer Einsatzplan".
        if (!empty($excludeWorkerIds)) {
            $query->whereNotIn('em.dienst_nr', $excludeWorkerIds);
        }

        $rows = $query
            ->select(
                'm.vorname', 'm.name', 'm.email',
                'e.einsatz_id', 'e.datum', 'e.ist_start', 'e.ist_ende', 'e.ort',
                'k.kundenname', 'o.objektname',
                $hasAufgabe ? 'em.aufgabe' : DB::raw('NULL as aufgabe')
            )
            ->get();

        if ($rows->isEmpty()) return;

        $tenant = TenantService::current();
        $companyName = $tenant->name ?? config('app.name', 'Clood-x');

        foreach ($rows as $r) {
            if (!filter_var($r->email, FILTER_VALIDATE_EMAIL)) continue;

            $workerName = trim(($r->vorname ?? '') . ' ' . ($r->name ?? ''));
            $zeit = substr($r->ist_start ?? '', 0, 5) . ' – ' . substr($r->ist_ende ?? '', 0, 5);

            try {
                Mail::to($r->email)->queue(new NuNotificationMail(
                    blade: 'emails.worker-shift-planned',
                    subjectLine: "Neuer Einsatzplan — {$r->datum} | {$companyName}",
                    payload: [
                        'company'    => $companyName,
                        'workerName' => $workerName,
                        'kunde'      => $r->kundenname ?? '–',
                        'objekt'     => $r->objektname ?? '–',
                        'datum'      => $r->datum ?? '–',
                        'zeit'       => $zeit,
                        'ort'        => $r->ort ?? null,
                        'aufgabe'    => $r->aufgabe ?? null,
                    ],
                ));
            } catch (\Exception $e) {
                \Log::error("Worker shift-planned email failed ({$r->email}): " . $e->getMessage());
            }
        }
    }

    /**
     * Email every assigned internal worker about a start/end time change.
     * Mirrors notifyNuTimeChange but addresses the worker directly.
     */
    protected function notifyWorkersTimeChange(string $einsatzId, string $oldStart, string $oldEnd, string $newStart, string $newEnd): void
    {
        $workers = DB::connection('tenant')
            ->table('einsatz_mitarbeiter as em')
            ->join('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
            ->where('em.einsatz_id', $einsatzId)
            ->select('m.id', 'm.dienst_nr', 'm.vorname', 'm.name', 'm.email')
            ->get();

        if ($workers->isEmpty()) {
            \Log::info('notifyWorkersTimeChange: no assigned workers for einsatz ' . $einsatzId);
            return;
        }

        $einsatz = DB::connection('tenant')
            ->table('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->where('e.einsatz_id', $einsatzId)
            ->select('e.datum', 'k.kundenname', 'o.objektname')
            ->first();

        $tenant = TenantService::current();
        $companyName = $tenant->name ?? config('app.name', 'Clood-x');
        $datum = $einsatz->datum ?? '–';

        // In-app notification + real device push (works with the app closed)
        // for EVERY assigned worker — independent of email. The 'zeit'
        // channel routes the tap to the Zeit tab, and the running shift card
        // refreshes so the new "Endet um" shows immediately.
        try {
            $datumFmt = ($datum && $datum !== '–') ? \Carbon\Carbon::parse($datum)->format('d.m.Y') : '–';
            $where = ($einsatz->objektname ?? null) ?: (($einsatz->kundenname ?? null) ?: 'Einsatz');
            $title = 'Schichtzeit geändert: ' . $datumFmt;
            $body  = $where . "\nNeue Zeit: {$newStart} – {$newEnd} Uhr"
                   . (($oldStart || $oldEnd) ? " (vorher {$oldStart} – {$oldEnd})" : '');
            foreach ($workers as $pw) {
                DB::connection('tenant')->table('worker_notifications')->insert([
                    'mitarbeiter_id' => $pw->id,
                    'dienst_nr'      => $pw->dienst_nr,
                    'type'           => 'shift.zeit_geaendert',
                    'title'          => $title,
                    'body'           => $body,
                    'payload'        => json_encode(['einsatz_id' => $einsatzId, 'datum' => $datum, 'action' => 'zeit'], JSON_UNESCAPED_UNICODE),
                    'gelesen'        => 0,
                    'created_at'     => now(),
                ]);
                \App\Jobs\SendWorkerPush::dispatch(
                    (int) $tenant->id, (string) $pw->dienst_nr, $title, $body, 'zeit'
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('notifyWorkersTimeChange push failed: ' . $e->getMessage());
        }

        foreach ($workers as $w) {
            if (!filter_var($w->email, FILTER_VALIDATE_EMAIL)) continue;

            $workerName = trim(($w->vorname ?? '') . ' ' . ($w->name ?? ''));

            try {
                Mail::to($w->email)->queue(new NuNotificationMail(
                    blade: 'emails.worker-time-change',
                    subjectLine: "Zeitänderung Ihres Einsatzes — {$datum} | {$companyName}",
                    payload: [
                        'company'    => $companyName,
                        'workerName' => $workerName,
                        'kunde'      => $einsatz->kundenname ?? '–',
                        'objekt'     => $einsatz->objektname ?? '–',
                        'datum'      => $datum,
                        'oldStart'   => $oldStart,
                        'oldEnd'     => $oldEnd,
                        'newStart'   => $newStart,
                        'newEnd'     => $newEnd,
                    ],
                ));
            } catch (\Exception $e) {
                \Log::error("Worker time-change email failed ({$w->email}): " . $e->getMessage());
            }
        }
    }

    /**
     * Email workers who were removed from an already-planned shift that their
     * assignment was cancelled ("Plan aufheben"). Workers are looked up in
     * `mitarbeiter` by id because their `einsatz_mitarbeiter` rows have already
     * been deleted by the time this runs (after the saveAssignment commit).
     *
     * @param  array<int, string>  $workerIds  dienst_nr of the removed workers
     * @param  object  $task  the einsatz row fetched before the update
     */
    protected function notifyWorkersShiftCancelled(string $einsatzId, array $workerIds, $task): void
    {
        if (empty($workerIds)) return;

        $workers = DB::connection('tenant')->table('mitarbeiter')
            ->whereIn('dienst_nr', $workerIds)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->select('vorname', 'name', 'email')
            ->get();

        // Resolve client/object names for the email context.
        $einsatz = DB::connection('tenant')
            ->table('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->where('e.einsatz_id', $einsatzId)
            ->select('e.datum', 'e.ist_start', 'e.ist_ende', 'k.kundenname', 'o.objektname')
            ->first();

        $tenant = TenantService::current();
        $companyName = $tenant->name ?? config('app.name', 'Clood-x');
        $datum = $einsatz->datum ?? ($task->datum ?? '–');
        $start = $einsatz->ist_start ?? $task->ist_start ?? '';
        $ende  = $einsatz->ist_ende ?? $task->ist_ende ?? '';
        $zeit  = substr($start, 0, 5) . ' – ' . substr($ende, 0, 5);

        // In-app notification + real push (works with app closed) to ALL
        // removed workers — independent of whether they have an email.
        try {
            $datumFmt = ($datum && $datum !== '–') ? \Carbon\Carbon::parse($datum)->format('d.m.Y') : '–';
            $title = 'Einsatz storniert: ' . $datumFmt;
            $body  = (($einsatz->objektname ?? null) ?: (($einsatz->kundenname ?? null) ?: 'Einsatz')) . ' · ' . $zeit;
            $pushWorkers = DB::connection('tenant')->table('mitarbeiter')
                ->whereIn('dienst_nr', $workerIds)
                ->get(['id', 'dienst_nr']);
            foreach ($pushWorkers as $pw) {
                DB::connection('tenant')->table('worker_notifications')->insert([
                    'mitarbeiter_id' => $pw->id,
                    'dienst_nr'      => $pw->dienst_nr,
                    'type'           => 'shift.storniert',
                    'title'          => $title,
                    'body'           => $body,
                    'payload'        => json_encode(['einsatz_id' => $einsatzId, 'datum' => $datum], JSON_UNESCAPED_UNICODE),
                    'gelesen'        => 0,
                    'created_at'     => now(),
                ]);
                \App\Jobs\SendWorkerPush::dispatch(
                    (int) TenantService::current()->id, (string) $pw->dienst_nr, $title, $body, 'plan'
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('notifyWorkersShiftCancelled push failed: ' . $e->getMessage());
        }

        foreach ($workers as $w) {
            if (!filter_var($w->email, FILTER_VALIDATE_EMAIL)) continue;

            $workerName = trim(($w->vorname ?? '') . ' ' . ($w->name ?? ''));

            try {
                Mail::to($w->email)->queue(new NuNotificationMail(
                    blade: 'emails.worker-shift-cancelled',
                    subjectLine: "Einsatz storniert — {$datum} | {$companyName}",
                    payload: [
                        'company'    => $companyName,
                        'workerName' => $workerName,
                        'kunde'      => $einsatz->kundenname ?? '–',
                        'objekt'     => $einsatz->objektname ?? '–',
                        'datum'      => $datum,
                        'zeit'       => $zeit,
                    ],
                ));
            } catch (\Exception $e) {
                \Log::error("Worker shift-cancelled email failed ({$w->email}): " . $e->getMessage());
            }
        }
    }

    /**
     * POST — Admin confirmation (Freigabe) of a worker's recorded time.
     * Sets/clears einsatz_mitarbeiter.zeit_freigabe for the given worker and
     * shifts. Used by the Monatsabschluss page; the worker app shows a green
     * check on every fully confirmed day.
     * Payload: { dienst_nr, einsatz_ids: [...], freigabe: true|false }
     */
    public function zeitFreigabe(Request $request)
    {
        $dienstNr = trim((string) $request->input('dienst_nr', ''));
        $ids      = $request->input('einsatz_ids', []);
        $freigabe = (bool) $request->input('freigabe', true);

        if ($dienstNr === '' || !is_array($ids) || empty($ids)) {
            return response()->json(['error' => 'dienst_nr und einsatz_ids erforderlich'], 400);
        }
        if (!BereichScope::allowsWorker($request->user(), $dienstNr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }
        // Bereich restriction: freigabe only on in-scope Einsätze.
        $ids = $this->scopeEinsatzIds($request->user(), $ids);
        if (empty($ids)) return response()->json(['error' => 'Keine Einsätze im erlaubten Bereich'], 403);
        if (!Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe')) {
            return response()->json(['error' => 'Zeit-Freigabe ist auf diesem System noch nicht eingerichtet'], 500);
        }
        // Storno-locked shifts (day removed from the Bestelleingang) cannot be
        // approved; revoking stays possible.
        if ($freigabe) {
            $ids = $this->withoutStornoLocked($ids);
            if (empty($ids)) return response()->json(['error' => 'Ausgewählte Einsätze sind gesperrt (Tag wurde vom Bestelleingang entfernt).'], 409);
        }

        $updated = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('dienst_nr', $dienstNr)
            ->whereIn('einsatz_id', array_slice($ids, 0, 500))
            ->update([
                'zeit_freigabe'    => $freigabe ? 1 : 0,
                'zeit_freigabe_am' => $freigabe ? now() : null,
            ]);

        \App\Helpers\Realtime::changed('dispo', ['zeiterfassung' => true]);
        return response()->json(['success' => true, 'updated' => $updated]);
    }

    /**
     * POST — Kunden-Freigabe (Monatsabschluss, Modus "Kunde"): approves the
     * customer-facing values of assignments for the Leistungsnachweis in
     * Bestellungen. Independent of zeit_freigabe (worker app).
     * Approving snapshots the shown values into kunden_* (customer value if
     * already set, else the worker's Ist, else the plan) — later Ist changes
     * never alter an approved customer row. Revoking keeps the values.
     * Payload internal: { dienst_nr, einsatz_ids: [...], freigabe }
     * Payload NU:       { nu_worker_id, position_ids: [...], freigabe }
     */
    public function kundenFreigabe(Request $request)
    {
        $freigabe = (bool) $request->input('freigabe', true);
        $dienstNr = trim((string) $request->input('dienst_nr', ''));
        $nuWorker = (int) $request->input('nu_worker_id', 0);
        $db = DB::connection('tenant');

        if ($dienstNr !== '') {
            $ids = $request->input('einsatz_ids', []);
            if (!is_array($ids) || empty($ids)) {
                return response()->json(['error' => 'einsatz_ids erforderlich'], 400);
            }
            if (!BereichScope::allowsWorker($request->user(), $dienstNr)) {
                return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
            }
            $table = 'einsatz_mitarbeiter';
            $q = $db->table('einsatz_mitarbeiter as k')
                ->join('einsaetze as e', 'e.einsatz_id', '=', 'k.einsatz_id')
                ->where('k.dienst_nr', $dienstNr);
            $scoped = $this->scopeEinsatzIds($request->user(), array_slice(array_map('strval', $ids), 0, 500));
        } elseif ($nuWorker > 0) {
            $pids = array_values(array_filter(array_map('intval', (array) $request->input('position_ids', []))));
            if (empty($pids)) return response()->json(['error' => 'position_ids erforderlich'], 400);
            $table = 'nu_position_zuweisungen';
            $q = $db->table('nu_position_zuweisungen as k')
                ->join('nu_schicht_positionen as nsp', 'nsp.id', '=', 'k.position_id')
                ->join('einsaetze as e', 'e.einsatz_id', '=', 'nsp.einsatz_id')
                ->where('k.nu_mitarbeiter_id', $nuWorker)
                ->whereIn('k.position_id', array_slice($pids, 0, 500));
            $scoped = $this->scopeEinsatzIds($request->user(),
                $db->table('nu_schicht_positionen')->whereIn('id', $pids)->pluck('einsatz_id')->map(fn ($v) => (string) $v)->all());
        } else {
            return response()->json(['error' => 'dienst_nr oder nu_worker_id erforderlich'], 400);
        }

        if (empty($scoped)) return response()->json(['error' => 'Keine Einsätze im erlaubten Bereich'], 403);
        if (!Schema::connection('tenant')->hasColumn($table, 'kunden_freigabe')) {
            return response()->json(['error' => 'Kunden-Freigabe ist auf diesem System noch nicht eingerichtet'], 500);
        }

        if ($freigabe) {
            $scoped = $this->withoutStornoLocked($scoped);
            if (empty($scoped)) return response()->json(['error' => 'Ausgewählte Einsätze sind gesperrt (Tag wurde vom Bestelleingang entfernt).'], 409);

            // Internal rows WITHOUT any recorded time (no app clock, no office
            // entry, no customer value) would be snapshotted with the PLAN
            // hours — a no-show billed in full. The office decides explicitly:
            //   ohne_zeit = 'fragen' (default) → 409 + list, nothing changed
            //             = 'auslassen' → approve only rows with recorded time
            //             = 'plan'      → approve all, missing ones with plan
            // NU rows are entered by the office only, so they are not checked.
            if ($table === 'einsatz_mitarbeiter') {
                $mode = (string) $request->input('ohne_zeit', 'fragen');
                $missing = (clone $q)->whereIn('e.einsatz_id', $scoped)
                    ->where(fn ($w) => $w->whereNull('k.kunden_freigabe')->orWhere('k.kunden_freigabe', '<>', 1))
                    ->whereNull('k.kunden_std')
                    ->where(fn ($w) => $w->whereNull('k.ist_std')->orWhere('k.ist_std', '<=', 0))
                    ->orderBy('e.datum')
                    ->get(['e.einsatz_id', 'e.datum']);
                if ($missing->isNotEmpty() && $mode === 'auslassen') {
                    $scoped = array_values(array_diff($scoped, $missing->pluck('einsatz_id')->map(fn ($v) => (string) $v)->all()));
                    if (empty($scoped)) {
                        return response()->json(['success' => true, 'updated' => 0, 'ausgelassen' => $missing->count()]);
                    }
                } elseif ($missing->isNotEmpty() && $mode !== 'plan') {
                    return response()->json([
                        'error'     => $missing->count() . ' Einsatz/Einsätze ohne erfasste Zeit — bitte prüfen (sonst würden die Planstunden abgerechnet).',
                        'code'      => 'ohne_zeit',
                        'ohne_zeit' => $missing->values(),
                    ], 409);
                }
                $skipped = $mode === 'auslassen' ? $missing->count() : 0;
            }
        }

        $upd = $freigabe
            ? [
                'k.kunden_start'       => DB::raw('COALESCE(k.kunden_start, k.ist_start, e.ist_start)'),
                'k.kunden_ende'        => DB::raw('COALESCE(k.kunden_ende, k.ist_ende, e.ist_ende)'),
                // One customer pause: the one set in Kunde mode, else the
                // Planung pause. A worker Ist taken over is re-based onto the
                // Planung pause (same gross Von–Bis time), so an office change
                // of the WORKER pause never reaches the customer.
                'k.kunden_std'         => DB::raw('COALESCE(k.kunden_std, GREATEST(0, NULLIF(k.ist_std, 0) + (COALESCE(k.pause_minuten, e.pause_minuten, 0) - COALESCE(e.pause_minuten, 0)) / 60), e.ist_std_menge)'),
                'k.kunden_pause'       => DB::raw('COALESCE(k.kunden_pause, e.pause_minuten, 0)'),
                'k.kunden_freigabe'    => 1,
                'k.kunden_freigabe_am' => now(),
            ]
            : ['k.kunden_freigabe' => 0, 'k.kunden_freigabe_am' => null];

        $updated = $q->whereIn('e.einsatz_id', $scoped)->update($upd);

        \App\Helpers\Realtime::changed('dispo', ['zeiterfassung' => true]);
        return response()->json(['success' => true, 'updated' => $updated, 'ausgelassen' => $skipped ?? 0,
            'einsatz_ids' => array_values(array_map('strval', $scoped))]);
    }

    /** Drops Einsätze whose day was removed from the Bestelleingang (grey tombstones). */
    private function withoutStornoLocked(array $ids): array
    {
        if (empty($ids) || !Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')) return array_values($ids);
        $locked = DB::connection('tenant')->table('einsaetze')
            ->whereIn('einsatz_id', $ids)->whereNotNull('bestell_storno_at')
            ->pluck('einsatz_id')->map(fn ($v) => (string) $v)->all();
        return array_values(array_diff(array_map('strval', $ids), $locked));
    }

    public function bulkConfirm(Request $request)
    {
        $ids = $request->input('einsatz_ids', []);
        $status = $request->input('hms_bestaetigt', 'ja');

        if (!is_array($ids) || empty($ids)) return response()->json(['error' => 'Keine Einsätze angegeben'], 400);
        if (!in_array($status, ['ja', 'nein', 'ausstehend'])) return response()->json(['error' => 'Ungültiger Status'], 400);

        // Bereich restriction: only in-scope Einsätze may be confirmed/rejected.
        $ids = $this->scopeEinsatzIds($request->user(), $ids);
        if (empty($ids)) return response()->json(['error' => 'Keine Einsätze im erlaubten Bereich'], 403);

        // Exclude storno-locked shifts: their day was removed from the
        // Bestelleingang and they cannot be confirmed/rejected until reactivated.
        if (Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')) {
            $lockedIds = DB::connection('tenant')->table('einsaetze')
                ->whereIn('einsatz_id', $ids)
                ->whereNotNull('bestell_storno_at')
                ->pluck('einsatz_id')->all();
            $ids = array_values(array_diff($ids, $lockedIds));
            if (empty($ids)) return response()->json(['error' => 'Ausgewählte Einsätze sind gesperrt (Tag wurde vom Bestelleingang entfernt).'], 409);
        }

        // For 'ja' (confirm), only update einsätze that have at least one
        // worker or NU worker assigned. For other statuses, update unconditionally.
        if ($status === 'ja') {
            $eligibleIds = $this->einsatzIdsWithAnyWorker($ids);
            $toUpdate = array_values(array_intersect($ids, $eligibleIds));
        } else {
            $toUpdate = $ids;
        }

        $updateData = ['hms_bestaetigt' => $status];
        // Fix state contradiction: clear pruefung when rejecting
        if ($status === 'nein') $updateData['pruefung'] = null;

        // Rejecting shifts that were already 'geplant' must tell the assigned
        // workers — they received the "Neuer Einsatzplan" email and would
        // otherwise show up for a shift the dispatcher rejected. Snapshot the
        // affected shifts BEFORE the update wipes pruefung.
        $plannedBeforeReject = [];
        if ($status === 'nein' && !empty($toUpdate)) {
            $plannedBeforeReject = DB::connection('tenant')->table('einsaetze')
                ->whereIn('einsatz_id', $toUpdate)
                ->where('pruefung', 'geplant')
                ->get()
                ->keyBy('einsatz_id');
        }

        if (!empty($toUpdate)) {
            DB::connection('tenant')->table('einsaetze')
                ->whereIn('einsatz_id', $toUpdate)
                ->update($updateData);
        }

        // A rejected 'geplant' shift is the same "Plan aufgehoben" event —
        // stamp the withdrawal time so the audit notice stays consistent no
        // matter which path pulled the plan back.
        $rejectedPlannedIds = collect($plannedBeforeReject)->keys()->all();
        if (!empty($rejectedPlannedIds)
            && Schema::connection('tenant')->hasColumn('einsaetze', 'plan_aufgehoben_at')) {
            DB::connection('tenant')->table('einsaetze')
                ->whereIn('einsatz_id', $rejectedPlannedIds)
                ->update(['plan_aufgehoben_at' => now()]);
        }

        // Post-update: cancellation emails for rejected planned shifts.
        foreach ($plannedBeforeReject as $einsatzId => $taskRow) {
            $assignedIds = DB::connection('tenant')->table('einsatz_mitarbeiter')
                ->where('einsatz_id', $einsatzId)
                ->pluck('dienst_nr')->map(fn($v) => (string) $v)->all();
            // Rejecting a shift cancels it for the assignees — any worker
            // still clocked in gets their timer ended at this moment.
            WorkerAppZeitController::closeOpenShiftsForEinsatz(
                (string) $einsatzId, [],
                'Einsatz abgelehnt — Schicht automatisch beendet'
            );
            if (!empty($assignedIds)) {
                $this->notifyWorkersShiftCancelled((string) $einsatzId, $assignedIds, $taskRow);
            }
        }

        // Report how many of the confirmed einsätze ended up below the
        // required Menge — the dispatcher may want to revisit those cards.
        $shortCount = 0;
        if ($status === 'ja' && !empty($toUpdate)) {
            $shortCount = $this->countShortStaffed($toUpdate);
        }

        \App\Helpers\Realtime::changed('dispo');
        return response()->json([
            'success' => true,
            'updated' => count($toUpdate),
            'skipped' => count($ids) - count($toUpdate),
            'short'   => $shortCount,
        ]);
    }

    /**
     * How many of the given einsätze have fewer assigned workers (internal +
     * NU) than the required Menge (bestelleingang_tage.menge, falling back to
     * the anforderungen JSON). Used to flag under-staffed bulk confirmations.
     */
    protected function countShortStaffed(array $einsatzIds): int
    {
        if (empty($einsatzIds)) return 0;
        $placeholders = implode(',', array_fill(0, count($einsatzIds), '?'));

        $internal = DB::connection('tenant')
            ->table('einsatz_mitarbeiter')
            ->whereIn('einsatz_id', $einsatzIds)
            ->groupBy('einsatz_id')
            ->pluck(DB::raw('COUNT(*)'), 'einsatz_id')
            ->toArray();

        $nu = DB::connection('tenant')
            ->table('nu_schicht_positionen as nsp')
            ->join('nu_position_zuweisungen as npz', 'npz.position_id', '=', 'nsp.id')
            ->whereIn('nsp.einsatz_id', $einsatzIds)
            ->groupBy('nsp.einsatz_id')
            ->pluck(DB::raw('COUNT(*)'), 'nsp.einsatz_id')
            ->toArray();

        // Required count per einsatz — same plan join as the planner query.
        $sollRows = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.anforderungen, d.menge AS plan_menge
            FROM einsaetze e
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN bestelleingang_tage d
                   ON d.eingang_id = b.eingang_id
                  AND d.datum = e.datum
                  AND (
                        (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                     OR (e.tag_nr IS NULL AND d.tag_nr = (
                             SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2
                              WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum
                         ))
                     )
            WHERE e.einsatz_id IN ({$placeholders})
        ", $einsatzIds);

        $short = 0;
        foreach ($sollRows as $row) {
            $soll = (float) ($row->plan_menge ?? $this->sollMengeFromAnforderungen($row->anforderungen ?? null) ?? 0);
            if ($soll <= 0) continue;
            $assigned = (int) ($internal[$row->einsatz_id] ?? 0) + (int) ($nu[$row->einsatz_id] ?? 0);
            if ($assigned < $soll) $short++;
        }
        return $short;
    }

    /**
     * Given a list of einsatz IDs, return the subset that has at least one
     * internal worker OR at least one NU worker assigned. Uses two grouped
     * queries instead of N×2 exists() calls — collapses the previous N+1
     * pattern in bulkMarkPlanned/bulkConfirm.
     *
     * @param  array<int|string>  $einsatzIds
     * @return array<int, string>
     */
    protected function einsatzIdsWithAnyWorker(array $einsatzIds): array
    {
        if (empty($einsatzIds)) return [];

        $withInternal = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->whereIn('einsatz_id', $einsatzIds)
            ->distinct()
            ->pluck('einsatz_id')
            ->toArray();

        $withNu = DB::connection('tenant')->table('nu_schicht_positionen as nsp')
            ->join('nu_position_zuweisungen as npz', 'npz.position_id', '=', 'nsp.id')
            ->whereIn('nsp.einsatz_id', $einsatzIds)
            ->distinct()
            ->pluck('nsp.einsatz_id')
            ->toArray();

        return array_values(array_unique(array_merge($withInternal, $withNu)));
    }

    public function activeWorkers(Request $request)
    {
        $weekStart = $request->query('week_start', date('Y-m-d', strtotime('monday this week')));
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

        // Optional single-day mode: ?date=YYYY-MM-DD limits the list to that
        // day — plus overnight carry-over (a shift that started yesterday but
        // ends after midnight is still "today's" live shift).
        $date = trim((string) $request->query('date', ''));

        // Exclude confirmed ('ja') AND rejected ('nein') shifts — a worker on a
        // rejected shift is not "active", matching attachWorkerWarnings which
        // also ignores rejected shifts for conflicts/hours.
        $where  = ['m.aktiv = 1', "e.hms_bestaetigt != 'ja'", "e.hms_bestaetigt != 'nein'"];
        $params = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $prevDay = date('Y-m-d', strtotime($date . ' -1 day'));
            $where[] = "(e.datum = ? OR (e.datum = ? AND e.ist_ende < e.ist_start))";
            $params[] = $date;
            $params[] = $prevDay;
        } else {
            $where[] = 'e.datum BETWEEN ? AND ?';
            $params[] = $weekStart;
            $params[] = $weekEnd;
        }
        BereichScope::applySql($request->user(),
            "COALESCE(NULLIF(TRIM(a.bereich), ''), NULLIF(TRIM(k.bereich), ''))", $where, $params);

        // Live clock state from the worker app: aktiv/pause/beendet or NULL
        // when the worker never pressed "Start" for that Einsatz. clock_in/out
        // timestamps let the frontend show "arbeitet seit …"/"beendet um …".
        $clockSelect = Schema::connection('tenant')->hasTable('zeiterfassung')
            ? "(SELECT z.status FROM zeiterfassung z
                 WHERE z.einsatz_id = e.einsatz_id AND z.dienst_nr = m.dienst_nr
                 ORDER BY z.clock_in DESC LIMIT 1) AS clock_status,
                (SELECT z.clock_in FROM zeiterfassung z
                 WHERE z.einsatz_id = e.einsatz_id AND z.dienst_nr = m.dienst_nr
                 ORDER BY z.clock_in DESC LIMIT 1) AS clock_in,
                (SELECT z.clock_out FROM zeiterfassung z
                 WHERE z.einsatz_id = e.einsatz_id AND z.dienst_nr = m.dienst_nr
                 ORDER BY z.clock_in DESC LIMIT 1) AS clock_out,
                " . (Schema::connection('tenant')->hasColumn('zeiterfassung', 'clock_in_real')
                    ? "(SELECT z.clock_in_real FROM zeiterfassung z
                 WHERE z.einsatz_id = e.einsatz_id AND z.dienst_nr = m.dienst_nr
                 ORDER BY z.clock_in DESC LIMIT 1)" : 'NULL') . " AS clock_in_real,
                (SELECT z.pause_total + CASE
                     WHEN z.status = 'pause' AND z.pause_start IS NOT NULL
                     THEN TIMESTAMPDIFF(MINUTE, z.pause_start, UTC_TIMESTAMP())
                     ELSE 0 END
                 FROM zeiterfassung z
                 WHERE z.einsatz_id = e.einsatz_id AND z.dienst_nr = m.dienst_nr
                 ORDER BY z.clock_in DESC LIMIT 1) AS app_pause"
            : "NULL AS clock_status, NULL AS clock_in, NULL AS clock_out, NULL AS clock_in_real, NULL AS app_pause";

        // Per-worker actual times (app clock-in/out or Dispo edit on
        // einsatz_mitarbeiter) beat the shift-level plan times — same
        // precedence as completedWorkers, so the shown Von/Bis explain
        // the per-worker ist_std instead of the shared shift times.
        $rows = DB::connection('tenant')->select("
            SELECT m.dienst_nr, m.vorname, m.name, m.foto, m.bereich,
                   e.einsatz_id, e.bestell_id, e.datum,
                   COALESCE(em.ist_start, e.ist_start) AS ist_start,
                   COALESCE(em.ist_ende, e.ist_ende) AS ist_ende,
                   em.ist_start AS em_ist_start, em.ist_ende AS em_ist_ende,
                   e.ist_start AS plan_start, e.ist_ende AS plan_end,
                   e.ist_std_menge, e.pause_minuten, e.gesetzl_pause, em.ist_std,
                   e.beschreibung, e.hms_bestaetigt, e.pruefung, k.kundenname, o.objektname,
                   {$clockSelect}
            FROM einsatz_mitarbeiter em
            INNER JOIN mitarbeiter m ON em.dienst_nr = m.dienst_nr
            INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
            LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
            LEFT JOIN objekte o ON e.obj_id = o.obj_id
            LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.datum ASC, m.vorname ASC, m.name ASC, e.ist_start ASC
        ", $params);

        // ist_std <= 0 counts as "not recorded" (same convention as the
        // Controlling billing side: NULLIF(ist_std,0) → plan fallback).
        $totalStd = array_sum(array_map(function ($r) {
            return $r->ist_std !== null && $r->ist_std !== '' && (float) $r->ist_std > 0
                ? floatval($r->ist_std)
                : ($r->ist_std_menge ? floatval($r->ist_std_menge) : 0);
        }, $rows));

        return response()->json([
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'date' => $date !== '' ? $date : null,
            'server_now' => date('Y-m-d H:i:s'),
            'total_workers' => count(array_unique(array_column($rows, 'dienst_nr'))),
            'total_entries' => count($rows),
            'total_std' => round($totalStd, 2),
            'entries' => $rows,
        ]);
    }

    public function completedWorkers(Request $request)
    {
        // Two modes:
        //   1) Single month (default, back-compat): ?monat=5&jahr=2026
        //   2) Custom range:                        ?von=2026-04-01&bis=2026-06-30
        // When both `von` and `bis` are supplied (and both look like ISO dates), they win.
        $von = trim((string) $request->query('von', ''));
        $bis = trim((string) $request->query('bis', ''));
        $isoRe = '/^\d{4}-\d{2}-\d{2}$/';

        $validDate = fn (string $d) => preg_match($isoRe, $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));

        if ($von !== '' && $bis !== '' && $validDate($von) && $validDate($bis)) {
            // Use the supplied range (swap if reversed)
            if (strcmp($von, $bis) > 0) { [$von, $bis] = [$bis, $von]; }
            // Cap: the export dialog offers at most a year (+ ISO-week overlap).
            if (Carbon::parse($von)->diffInDays(Carbon::parse($bis)) > 400) {
                return response()->json(['error' => 'Zeitraum zu groß (max. 400 Tage)'], 422);
            }
            $startDate = $von;
            $endDate   = $bis;
            // For back-compat we still report monat/jahr of the start day.
            $monat = intval(substr($startDate, 5, 2));
            $jahr  = intval(substr($startDate, 0, 4));
        } else {
            $monat = intval($request->query('monat', date('n')));
            $jahr  = intval($request->query('jahr',  date('Y')));
            if ($monat < 1 || $monat > 12 || $jahr < 2000 || $jahr > 2100) {
                return response()->json(['error' => 'Ungültiger Monat'], 422);
            }
            $startDate = sprintf('%04d-%02d-01', $jahr, $monat);
            $endDate = date('Y-m-t', strtotime($startDate));
        }

        $settlement = AbrechnungDataService::completedAssignments($request->user(), $startDate, $endDate);

        return response()->json(array_merge([
            'monat' => $monat,
            'jahr' => $jahr,
            'von' => $startDate,
            'bis' => $endDate,
        ], $settlement));
    }

    /**
     * Derive a fallback soll_menge (required worker count) from the anforderungen
     * JSON stored on einsaetze. Used when plan_menge (bestelleingang_tage.menge)
     * is NULL — which happens for shifts created directly in Dispo without a
     * Bestellung. Priority: sum of positionen[].anzahl, then geschlecht_mix
     * (maennlich + weiblich). Returns null when no count can be derived.
     */
    protected function sollMengeFromAnforderungen(?string $anforderungenJson): ?float
    {
        if (!$anforderungenJson || $anforderungenJson === '') return null;
        $anf = json_decode($anforderungenJson, true);
        if (!is_array($anf)) return null;

        // Sum from positionen[].anzahl
        if (!empty($anf['positionen']) && is_array($anf['positionen'])) {
            $sum = 0;
            foreach ($anf['positionen'] as $pos) {
                $sum += (float) ($pos['anzahl'] ?? 0);
            }
            if ($sum > 0) return $sum;
        }

        // Sum from geschlecht_mix (maennlich + weiblich)
        if (!empty($anf['geschlecht_mix']) && is_array($anf['geschlecht_mix'])) {
            $m = (float) ($anf['geschlecht_mix']['maennlich'] ?? 0);
            $w = (float) ($anf['geschlecht_mix']['weiblich'] ?? 0);
            $sum = $m + $w;
            if ($sum > 0) return $sum;
        }

        return null;
    }

    protected function fetchTaskAssignments(array $einsatzIds): array
    {
        if (empty($einsatzIds)) return [];

        $rows = DB::connection('tenant')
            ->table('einsatz_mitarbeiter as em')
            ->join('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
            ->whereIn('em.einsatz_id', $einsatzIds)
            ->select('em.einsatz_id', 'em.dienst_nr', 'em.sortierung', 'em.ist_std',
                     'm.vorname', 'm.name', 'm.foto', 'm.bereich', 'm.aktiv')
            ->orderBy('em.einsatz_id')->orderBy('em.sortierung')
            ->orderBy('m.vorname')->orderBy('m.name')->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->einsatz_id][] = $row;
        }
        return $grouped;
    }

    /**
     * GET /disposition/nu-positions — Load NU (subcontractor) positions for a task.
     * If nu_id is provided, returns positions for that NU only.
     * If nu_id is omitted, returns all positions grouped by NU.
     */
    public function nuPositions(Request $request)
    {
        $einsatzId = $request->input('einsatz_id');
        $nuId      = $request->input('nu_id');

        if (!$einsatzId) {
            return response()->json(['error' => 'einsatz_id required'], 400);
        }
        if (!BereichScope::allowsEinsatz($request->user(), $einsatzId)) {
            return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        }

        // If nu_id provided, return positions for that single NU (legacy behavior)
        if ($nuId) {
            $positions = DB::connection('tenant')
                ->table('nu_schicht_positionen')
                ->where('einsatz_id', $einsatzId)
                ->where('nu_id', $nuId)
                ->get();

            $firma = DB::connection('tenant')
                ->table('nachunternehmer')
                ->where('nu_id', $nuId)
                ->value('firmenname');

            return response()->json([
                'positions'  => $positions,
                'firmenname' => $firma ?? '',
            ]);
        }

        // No nu_id: return ALL positions for this einsatz, grouped by NU
        $positions = DB::connection('tenant')
            ->table('nu_schicht_positionen')
            ->where('einsatz_id', $einsatzId)
            ->orderBy('nu_id')
            ->orderBy('position_nr')
            ->get();

        $nuIds = $positions->pluck('nu_id')->unique()->values()->toArray();
        $firms = [];
        if (!empty($nuIds)) {
            $firms = DB::connection('tenant')
                ->table('nachunternehmer')
                ->whereIn('nu_id', $nuIds)
                ->pluck('firmenname', 'nu_id')
                ->toArray();
        }

        // Load zuweisungen for these positions
        $positionIds = $positions->pluck('id')->toArray();
        $zuweisungen = [];
        if (!empty($positionIds)) {
            $zuweisungen = DB::connection('tenant')
                ->table('nu_position_zuweisungen as z')
                ->join('nu_portal_mitarbeiter as m', 'z.nu_mitarbeiter_id', '=', 'm.id')
                ->whereIn('z.position_id', $positionIds)
                ->select('z.*', 'm.vorname', 'm.name', 'm.personal_nr')
                ->get()
                ->groupBy('position_id')
                ->toArray();
        }

        // Load per-NU statuses
        $nuStatuses = [];
        if (!empty($nuIds)) {
            $nuStatuses = DB::connection('tenant')
                ->table('einsatz_nu_status')
                ->where('einsatz_id', $einsatzId)
                ->whereIn('nu_id', $nuIds)
                ->pluck('status', 'nu_id')
                ->toArray();
        }

        // Group by NU
        $grouped = [];
        foreach ($positions as $pos) {
            if (!isset($grouped[$pos->nu_id])) {
                $grouped[$pos->nu_id] = [
                    'nu_id' => $pos->nu_id,
                    'firmenname' => $firms[$pos->nu_id] ?? '',
                    'nu_status' => $nuStatuses[$pos->nu_id] ?? null,
                    'positions' => [],
                    'total_anzahl' => 0,
                    'total_zugewiesen' => 0,
                ];
            }
            $posZuweisungen = $zuweisungen[$pos->id] ?? [];
            $pos->zuweisungen = array_map(fn($z) => [
                'nu_mitarbeiter_id' => $z->nu_mitarbeiter_id,
                'vorname' => $z->vorname,
                'name' => $z->name,
                'personal_nr' => $z->personal_nr,
            ], $posZuweisungen);
            $grouped[$pos->nu_id]['positions'][] = $pos;
            $grouped[$pos->nu_id]['total_anzahl'] += $pos->anzahl;
            $grouped[$pos->nu_id]['total_zugewiesen'] += count($posZuweisungen);
        }

        // Get einsatz info for location
        $einsatz = DB::connection('tenant')
            ->table('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->where('e.einsatz_id', $einsatzId)
            ->select('k.kundenname', 'o.objektname')
            ->first();

        return response()->json([
            'groups' => array_values($grouped),
            'kundenname' => $einsatz->kundenname ?? '',
            'objektname' => $einsatz->objektname ?? '',
        ]);
    }

    /**
     * POST /disposition/save-nu-positions — Save NU positions for a task.
     */
    public function saveNuPositions(Request $request)
    {
        $einsatzId = $request->input('einsatz_id');
        $nuId      = $request->input('nu_id');
        $positions = $request->input('positions', []);

        if (!$einsatzId || !$nuId) {
            return response()->json(['error' => 'einsatz_id and nu_id required'], 400);
        }
        if (!is_array($positions)) {
            return response()->json(['error' => 'positions muss ein Array sein'], 400);
        }

        // Validate einsatz exists and is inside the caller's Bereich scope
        $task = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->first();
        if (!$task || !BereichScope::allowsEinsatz($request->user(), $einsatzId)) {
            return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        }

        // Confirmed shifts are part of the billing/history record — their NU
        // lineup must not change anymore (mirrors the saveAssignment guard).
        if (($task->hms_bestaetigt ?? null) === 'ja') {
            return response()->json(['error' => 'Einsatz ist bereits bestätigt — NU-Positionen können nicht mehr geändert werden.'], 409);
        }
        // Grey = day removed from the order (same lock as saveAssignment).
        if (!empty($task->bestell_storno_at ?? null) && !empty($positions)) {
            return response()->json(['error' => 'Einsatz ist gesperrt (Tag wurde vom Bestelleingang entfernt). Bitte den Tag im Bestelleingang erneut hinzufügen.'], 409);
        }

        // Validate NU exists, is active and inside the caller's Bereich scope
        $nu = DB::connection('tenant')->table('nachunternehmer')
            ->where('nu_id', $nuId)->where('aktiv', 1)->first();
        if (!$nu || !BereichScope::allowsNu($request->user(), $nuId)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden oder inaktiv'], 400);
        }

        // A position cannot ask for fewer people than the NU already assigned
        // to it — the dispatcher removes the surplus NU workers first.
        foreach ($positions as $pos) {
            $pid = isset($pos['id']) ? (int) $pos['id'] : 0;
            if (!$pid) continue;
            $assignedCount = DB::connection('tenant')->table('nu_position_zuweisungen as z')
                ->join('nu_schicht_positionen as p', 'p.id', '=', 'z.position_id')
                ->where('p.id', $pid)->where('p.einsatz_id', $einsatzId)->where('p.nu_id', $nuId)->count();
            if ((int) ($pos['anzahl'] ?? 1) < $assignedCount) {
                return response()->json([
                    'error' => 'Position „' . ($pos['position_name'] ?? '') . '“: Anzahl ' . (int) ($pos['anzahl'] ?? 1)
                        . ' ist kleiner als die bereits zugewiesenen NU-Mitarbeiter (' . $assignedCount . '). Bitte zuerst Mitarbeiter abmelden.',
                ], 409);
            }
        }

        // NU workers that lose their place through this save (removed
        // positions / full cancellation) — informed after commit.
        $keepIds = array_filter(array_map(fn ($p) => isset($p['id']) ? (int) $p['id'] : 0, $positions));
        $removedWorkers = DB::connection('tenant')->table('nu_position_zuweisungen as z')
            ->join('nu_schicht_positionen as p', 'p.id', '=', 'z.position_id')
            ->join('nu_portal_mitarbeiter as m', 'm.id', '=', 'z.nu_mitarbeiter_id')
            ->where('p.einsatz_id', $einsatzId)->where('p.nu_id', $nuId)
            ->when($keepIds, fn ($q) => $q->whereNotIn('p.id', $keepIds))
            ->get(['m.vorname', 'm.name']);

        // ── All DB writes for this save go through a single transaction ──
        // Previously the delete/update/insert sequence ran without atomicity —
        // a mid-flight failure could leave the tenant DB inconsistent
        // (positions removed but status still 'pending', or einsatz linked
        // to a NU with no positions). Wrapping in a transaction guarantees
        // all-or-nothing semantics. Email dispatch stays OUTSIDE the
        // transaction so a queue/SMTP hiccup never rolls back actual data.
        $newCount = 0; // positions genuinely added in this save (not edits of existing ones)
        try {
            DB::connection('tenant')->transaction(function () use ($einsatzId, $nuId, $positions, &$newCount) {
                if (empty($positions)) {
                    // Full cancellation: drop this NU's positions AND zuweisungen.
                    $oldPositionIds = DB::connection('tenant')
                        ->table('nu_schicht_positionen')
                        ->where('einsatz_id', $einsatzId)
                        ->where('nu_id', $nuId)
                        ->pluck('id')
                        ->toArray();

                    if (!empty($oldPositionIds)) {
                        DB::connection('tenant')
                            ->table('nu_position_zuweisungen')
                            ->whereIn('position_id', $oldPositionIds)
                            ->delete();
                    }

                    DB::connection('tenant')
                        ->table('nu_schicht_positionen')
                        ->where('einsatz_id', $einsatzId)
                        ->where('nu_id', $nuId)
                        ->delete();

                    // Cancellation path: clean up status + unlink einsatz if no
                    // other NU still has positions for it.
                    DB::connection('tenant')->table('einsatz_nu_status')
                        ->where('einsatz_id', $einsatzId)
                        ->where('nu_id', $nuId)
                        ->delete();

                    $otherNuPositions = DB::connection('tenant')
                        ->table('nu_schicht_positionen')
                        ->where('einsatz_id', $einsatzId)
                        ->exists();

                    if (!$otherNuPositions) {
                        DB::connection('tenant')
                            ->table('einsaetze')
                            ->where('einsatz_id', $einsatzId)
                            ->where('nu_id', $nuId)
                            ->update(['nu_id' => null]);
                        // A planned shift left without any staff is no longer
                        // planned (same as an NU rejection / Plan aufheben).
                        $cur = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->first(['pruefung']);
                        $hasInternal = DB::connection('tenant')->table('einsatz_mitarbeiter')->where('einsatz_id', $einsatzId)->exists();
                        if (($cur->pruefung ?? null) === 'geplant' && !$hasInternal) {
                            $upd = ['pruefung' => null];
                            if (Schema::connection('tenant')->hasColumn('einsaetze', 'plan_aufgehoben_at')) $upd['plan_aufgehoben_at'] = now();
                            DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->update($upd);
                        }
                    } else {
                        \App\Support\NuLink::sync((string) $einsatzId);
                    }
                    return;
                }

                // Update path: DIFF against the existing positions instead of
                // delete-all + re-insert. The old approach silently wiped every
                // nu_position_zuweisung (workers the NU had already assigned)
                // whenever the dispatcher merely edited or added a position.
                // Existing positions (matched by id) are updated in place so
                // their zuweisungen survive; removed ones are deleted together
                // with their zuweisungen; new ones are inserted.
                // Link the shift to this NU unless another NU with positions
                // already holds the link (NuLink keeps it consistent).
                $curNu = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->value('nu_id');
                $curHasPos = $curNu && DB::connection('tenant')->table('nu_schicht_positionen')
                    ->where('einsatz_id', $einsatzId)->where('nu_id', $curNu)->exists();
                if (!$curHasPos || $curNu === $nuId) {
                    DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->update(['nu_id' => $nuId]);
                }

                $existingIds = DB::connection('tenant')
                    ->table('nu_schicht_positionen')
                    ->where('einsatz_id', $einsatzId)
                    ->where('nu_id', $nuId)
                    ->pluck('id')
                    ->map(fn ($v) => (int) $v)
                    ->all();

                $keptIds = [];
                $hasGeschlechtCol = Schema::connection('tenant')->hasColumn('nu_schicht_positionen', 'geschlecht');
                $hasGeschlechtMixCol = Schema::connection('tenant')->hasColumn('nu_schicht_positionen', 'geschlecht_mix');
                foreach ($positions as $idx => $pos) {
                    $fields = [
                        'position_nr'   => $idx + 1,
                        'position_name' => $pos['position_name'] ?? '',
                        'anzahl'        => $pos['anzahl'] ?? 1,
                        'qualifikation' => $pos['qualifikation'] ?? null,
                        'skills'        => $pos['skills'] ?? null,
                        'kleidung'      => $pos['kleidung'] ?? null,
                        'checkin_info'  => $pos['checkin_info'] ?? null,
                        'bemerkung'     => $pos['bemerkung'] ?? null,
                    ];
                    if ($hasGeschlechtCol) {
                        $fields['geschlecht'] = $pos['geschlecht'] ?? null;
                    }
                    if ($hasGeschlechtMixCol) {
                        $mix = $pos['geschlecht_mix'] ?? null;
                        $fields['geschlecht_mix'] = is_array($mix) ? json_encode($mix) : ($mix ?: null);
                    }
                    $pid = isset($pos['id']) ? (int) $pos['id'] : 0;
                    if ($pid && in_array($pid, $existingIds, true)) {
                        DB::connection('tenant')->table('nu_schicht_positionen')
                            ->where('id', $pid)->update($fields);
                        $keptIds[] = $pid;
                    } else {
                        DB::connection('tenant')->table('nu_schicht_positionen')->insert(
                            array_merge(['einsatz_id' => $einsatzId, 'nu_id' => $nuId], $fields)
                        );
                        $newCount++;
                    }
                }

                $toDelete = array_values(array_diff($existingIds, $keptIds));
                if (!empty($toDelete)) {
                    DB::connection('tenant')->table('nu_position_zuweisungen')
                        ->whereIn('position_id', $toDelete)->delete();
                    DB::connection('tenant')->table('nu_schicht_positionen')
                        ->whereIn('id', $toDelete)->delete();
                }

                // Status: only fall back to 'pending' when something NEW was
                // requested or no status exists yet — re-saving unchanged
                // positions must not revoke an NU's 'accepted' response.
                $hasStatus = DB::connection('tenant')->table('einsatz_nu_status')
                    ->where('einsatz_id', $einsatzId)->where('nu_id', $nuId)->exists();
                if ($newCount > 0 || !$hasStatus) {
                    DB::connection('tenant')->table('einsatz_nu_status')->updateOrInsert(
                        ['einsatz_id' => $einsatzId, 'nu_id' => $nuId],
                        ['status' => 'pending']
                    );
                }
            });
        } catch (\Throwable $e) {
            \Log::error('saveNuPositions transaction failed: ' . $e->getMessage(), [
                'einsatz_id' => $einsatzId,
                'nu_id'      => $nuId,
            ]);
            return response()->json(['error' => 'Speichern fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        // ── DB committed — now safely dispatch email notification(s) ──
        // Partial removal: positions with assigned NU workers were dropped →
        // tell the NU who lost their place (full cancellation has its own mail).
        if (!empty($positions) && $removedWorkers->isNotEmpty() && !empty($nu->email) && filter_var($nu->email, FILTER_VALIDATE_EMAIL)) {
            $einsatzRow = DB::connection('tenant')->table('einsaetze as e')
                ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
                ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                ->where('e.einsatz_id', $einsatzId)->select('e.*', 'k.kundenname', 'o.objektname')->first();
            $companyName = TenantService::current()->name ?? config('app.name', 'Clood-x');
            foreach ($removedWorkers as $rw) {
                $workerName = trim(($rw->vorname ?? '') . ' ' . ($rw->name ?? ''));
                try {
                    Mail::to($nu->email)->queue(new NuNotificationMail(
                        blade: 'emails.nu-worker-removal',
                        subjectLine: "Mitarbeiter-Abmeldung: {$workerName} — " . ($einsatzRow->datum ?? '–') . " | {$companyName}",
                        payload: $this->buildRemovalEmailData([
                            'workerName' => $workerName, 'firmenname' => $nu->firmenname ?? 'Nachunternehmer',
                            'reason' => 'Position wurde vom Disponenten entfernt',
                            'datum' => $einsatzRow->datum ?? '–', 'objekt' => $einsatzRow->objektname ?? '–', 'kunde' => $einsatzRow->kundenname ?? '–',
                            'zeit' => ($einsatzRow->ist_start ?? '–') . ' – ' . ($einsatzRow->ist_ende ?? '–'),
                            'company' => $companyName, 'recipient' => 'nu',
                        ]),
                    ));
                } catch (\Exception $e) {
                    \Log::error('NU position removal email failed: ' . $e->getMessage());
                }
            }
        }
        if (empty($positions)) {
            // Cancellation email to NU company
            $nuEmailSent = false;
            $nuEmail = $nu->email ?? null;
            if ($nuEmail && filter_var($nuEmail, FILTER_VALIDATE_EMAIL)) {
                $einsatz = DB::connection('tenant')
                    ->table('einsaetze as e')
                    ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
                    ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                    ->where('e.einsatz_id', $einsatzId)
                    ->select('e.*', 'k.kundenname', 'o.objektname')
                    ->first();

                $tenant = TenantService::current();
                $companyName = $tenant->name ?? config('app.name', 'Clood-x');
                $firmenname = $nu->firmenname ?? 'Nachunternehmer';
                $datum = $einsatz->datum ?? '–';
                $objekt = $einsatz->objektname ?? '–';
                $kunde = $einsatz->kundenname ?? '–';
                $zeit = ($einsatz->ist_start ?? '–') . ' – ' . ($einsatz->ist_ende ?? '–');

                try {
                    Mail::to($nuEmail)->queue(new NuNotificationMail(
                        blade: 'emails.nu-shift-cancelled',
                        subjectLine: "Einsatz storniert — {$datum} | {$companyName}",
                        payload: [
                            'company'    => $companyName,
                            'firmenname' => $firmenname,
                            'datum'      => $datum,
                            'zeit'       => $zeit,
                            'objekt'     => $objekt,
                            'kunde'      => $kunde,
                        ],
                    ));
                    $nuEmailSent = true;
                } catch (\Exception $e) {
                    \Log::error('NU shift cancellation email failed: ' . $e->getMessage());
                }
            }

            return response()->json(['success' => true, 'count' => 0, 'email_sent' => $nuEmailSent]);
        }

        // Update path: positions were upserted inside the transaction above.
        $count = count($positions);

        // Send the "Neue Einsatzanfrage" email only when NEW positions were
        // added — in-place edits of already-sent positions must not re-spam
        // the NU with a fresh request for work they already accepted.
        $nuEmailSent = false;
        $nuEmail = $nu->email ?? null;
        if ($nuEmail && filter_var($nuEmail, FILTER_VALIDATE_EMAIL) && $newCount > 0) {
            $einsatz = DB::connection('tenant')
                ->table('einsaetze as e')
                ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
                ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                ->where('e.einsatz_id', $einsatzId)
                ->select('e.*', 'k.kundenname', 'o.objektname', 'o.adresse', 'o.plz', 'o.stadt', 'o.ansprechpartner', 'o.kontakt_telefon')
                ->first();

            $tenant = TenantService::current();
            $companyName = $tenant->name ?? config('app.name', 'Clood-x');
            $firmenname = $nu->firmenname ?? 'Nachunternehmer';
            $datum = $einsatz->datum ?? '–';
            $objekt = $einsatz->objektname ?? '–';
            $kunde = $einsatz->kundenname ?? '–';
            $zeit = ($einsatz->ist_start ?? '–') . ' – ' . ($einsatz->ist_ende ?? '–');
            $adresse = trim(implode(', ', array_filter([
                $einsatz->adresse ?? null,
                trim(($einsatz->plz ?? '') . ' ' . ($einsatz->stadt ?? '')),
            ]))) ?: null;
            $portalUrl = rtrim(config('app.url', ''), '/');
            $portalUrl = $portalUrl ? $portalUrl . '/nu_login.php' : null;

            try {
                Mail::to($nuEmail)->queue(new NuNotificationMail(
                    blade: 'emails.nu-request',
                    subjectLine: "Neue Einsatzanfrage — {$datum} | {$companyName}",
                    payload: [
                        'company'       => $companyName,
                        'firmenname'    => $firmenname,
                        'kunde'         => $kunde,
                        'objekt'        => $objekt,
                        'adresse'       => $adresse,
                        'ort'           => $einsatz->ort ?? null,
                        'ansprechpartner' => $einsatz->ansprechpartner ?? null,
                        'kontakt_telefon' => $einsatz->kontakt_telefon ?? null,
                        'datum'         => $datum,
                        'zeit'          => $zeit,
                        'positions'     => $positions,
                        'positionCount' => $count,
                        'portalUrl'     => $portalUrl,
                    ],
                ));
                $nuEmailSent = true;
            } catch (\Exception $e) {
                \Log::error('NU request notification email failed: ' . $e->getMessage());
            }
        }

        \App\Helpers\Realtime::changed('dispo');
        return response()->json(['success' => true, 'count' => $count, 'email_sent' => $nuEmailSent]);
    }

    /**
     * POST /disposition/remove-nu-worker — Remove a NU worker from assignment and notify NU via email.
     */
    public function removeNuWorker(Request $request)
    {
        $einsatzId      = $request->input('einsatz_id');
        $nuMitarbeiterId = $request->input('nu_mitarbeiter_id');
        $positionId     = $request->input('position_id');
        $reason         = $request->input('reason', '');

        if (!$einsatzId || !$nuMitarbeiterId) {
            return response()->json(['error' => 'einsatz_id and nu_mitarbeiter_id required'], 400);
        }
        if (!BereichScope::allowsEinsatz($request->user(), $einsatzId)) {
            return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        }

        // Get worker info before deleting
        $worker = DB::connection('tenant')
            ->table('nu_portal_mitarbeiter')
            ->where('id', $nuMitarbeiterId)
            ->first();

        if (!$worker) {
            return response()->json(['error' => 'NU-Mitarbeiter nicht gefunden'], 404);
        }

        // Get the NU company info (for email)
        $nu = DB::connection('tenant')
            ->table('nachunternehmer')
            ->where('nu_id', $worker->nu_id)
            ->first();

        // Get task info
        $einsatz = DB::connection('tenant')
            ->table('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->where('e.einsatz_id', $einsatzId)
            ->select('e.*', 'k.kundenname', 'o.objektname')
            ->first();

        // Delete the assignment. Track the number of deleted rows — if nothing
        // was actually removed (double-click, stale UI), no "Abmeldung" emails
        // may go out claiming a removal that never happened.
        $deleted = 0;
        if ($positionId) {
            // Guard: the position must belong to THIS einsatz, otherwise a stale
            // position_id could delete an assignment on a different shift.
            $positionValid = DB::connection('tenant')
                ->table('nu_schicht_positionen')
                ->where('id', $positionId)
                ->where('einsatz_id', $einsatzId)
                ->exists();
            if (!$positionValid) {
                return response()->json(['error' => 'Position gehört nicht zu diesem Einsatz'], 400);
            }
            $deleted = DB::connection('tenant')
                ->table('nu_position_zuweisungen')
                ->where('position_id', $positionId)
                ->where('nu_mitarbeiter_id', $nuMitarbeiterId)
                ->delete();
        } else {
            // Delete from all positions for this einsatz
            $posIds = DB::connection('tenant')
                ->table('nu_schicht_positionen')
                ->where('einsatz_id', $einsatzId)
                ->where('nu_id', $worker->nu_id)
                ->pluck('id')
                ->toArray();

            if (!empty($posIds)) {
                $deleted = DB::connection('tenant')
                    ->table('nu_position_zuweisungen')
                    ->whereIn('position_id', $posIds)
                    ->where('nu_mitarbeiter_id', $nuMitarbeiterId)
                    ->delete();
            }
        }

        if ($deleted === 0) {
            return response()->json([
                'success' => true,
                'email_sent' => false,
                'employee_emails_sent' => 0,
                'message' => 'Mitarbeiter war nicht (mehr) zugewiesen — keine E-Mails gesendet.',
            ]);
        }

        // Common data for emails
        $workerName = trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? ''));
        $firmenname = $nu->firmenname ?? 'Nachunternehmer';
        $datum = $einsatz->datum ?? '–';
        $objekt = $einsatz->objektname ?? '–';
        $kunde = $einsatz->kundenname ?? '–';
        $zeit = ($einsatz->ist_start ?? '–') . ' – ' . ($einsatz->ist_ende ?? '–');

        // Get tenant company name (e.g. "hrd GmbH")
        $tenant = TenantService::current();
        $companyName = $tenant->name ?? config('app.name', 'Clood-x');

        // ── 1) Send email to NU company ──
        $nuEmailSent = false;
        $nuEmail = $nu->email ?? null;

        if ($nuEmail && filter_var($nuEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::to($nuEmail)->queue(new NuNotificationMail(
                    blade: 'emails.nu-worker-removal',
                    subjectLine: "Mitarbeiter-Abmeldung: {$workerName} — {$datum} | {$companyName}",
                    payload: $this->buildRemovalEmailData([
                        'workerName' => $workerName,
                        'firmenname' => $firmenname,
                        'reason'     => $reason,
                        'datum'      => $datum,
                        'objekt'     => $objekt,
                        'kunde'      => $kunde,
                        'zeit'       => $zeit,
                        'company'    => $companyName,
                        'recipient'  => 'nu',
                    ]),
                ));
                $nuEmailSent = true;
            } catch (\Exception $e) {
                \Log::error('NU worker removal email failed: ' . $e->getMessage());
            }
        }

        // ── 2) Send email to assigned internal employees (key workers) ──
        $employeeEmailsSent = 0;
        $assignedWorkers = DB::connection('tenant')
            ->table('einsatz_mitarbeiter as em')
            ->join('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
            ->where('em.einsatz_id', $einsatzId)
            ->whereNotNull('m.email')
            ->where('m.email', '!=', '')
            ->select('m.dienst_nr', 'm.vorname', 'm.name', 'm.email')
            ->get();

        foreach ($assignedWorkers as $emp) {
            $empEmail = $emp->email;
            if (!filter_var($empEmail, FILTER_VALIDATE_EMAIL)) continue;

            $empName = trim(($emp->vorname ?? '') . ' ' . ($emp->name ?? ''));
            try {
                Mail::to($empEmail)->queue(new NuNotificationMail(
                    blade: 'emails.nu-worker-removal',
                    subjectLine: "Einsatz-Änderung: NU-Mitarbeiter abgemeldet — {$datum} | {$companyName}",
                    payload: $this->buildRemovalEmailData([
                        'workerName' => $workerName,
                        'firmenname' => $firmenname,
                        'reason'     => $reason,
                        'datum'      => $datum,
                        'objekt'     => $objekt,
                        'kunde'      => $kunde,
                        'zeit'       => $zeit,
                        'company'    => $companyName,
                        'recipient'  => 'employee',
                        'empName'    => $empName,
                    ]),
                ));
                $employeeEmailsSent++;
            } catch (\Exception $e) {
                \Log::error("Employee removal notification failed ({$empEmail}): " . $e->getMessage());
            }
        }

        $messages = [];
        if ($nuEmailSent) $messages[] = "E-Mail an {$firmenname} ({$nuEmail}) gesendet";
        if ($employeeEmailsSent > 0) $messages[] = "{$employeeEmailsSent} Mitarbeiter benachrichtigt";
        if (empty($messages)) $messages[] = 'Keine E-Mails gesendet (keine gültigen Adressen)';

        \App\Helpers\Realtime::changed('dispo');
        return response()->json([
            'success'              => true,
            'email_sent'           => $nuEmailSent,
            'employee_emails_sent' => $employeeEmailsSent,
            'nu_email'             => $nuEmail,
            'message'              => "Mitarbeiter {$workerName} entfernt. " . implode('. ', $messages) . '.',
        ]);
    }

    protected function notifyNuTimeChange(string $einsatzId, $task, string $oldStart, string $oldEnd, string $newStart, string $newEnd): void
    {
        // Find all NU companies linked to this shift (via positions or direct nu_id)
        $nuCompanies = DB::connection('tenant')
            ->table('nu_schicht_positionen as nsp')
            ->join('nachunternehmer as n', 'nsp.nu_id', '=', 'n.nu_id')
            ->where('nsp.einsatz_id', $einsatzId)
            ->whereNotNull('n.email')
            ->where('n.email', '!=', '')
            ->select('n.nu_id', 'n.firmenname', 'n.email')
            ->distinct()
            ->get();

        // Fallback: if no positions exist but einsatz has a direct nu_id, notify that NU
        if ($nuCompanies->isEmpty() && !empty($task->nu_id)) {
            $fallbackNu = DB::connection('tenant')->table('nachunternehmer')
                ->where('nu_id', $task->nu_id)
                ->whereNotNull('email')->where('email', '!=', '')
                ->select('nu_id', 'firmenname', 'email')
                ->first();
            if ($fallbackNu) {
                $nuCompanies = collect([$fallbackNu]);
            }
        }

        if ($nuCompanies->isEmpty()) {
            \Log::info('notifyNuTimeChange: No NU companies found for einsatz ' . $einsatzId);
            return;
        }

        \Log::info('notifyNuTimeChange: Sending to ' . $nuCompanies->count() . ' NU companies');

        $einsatz = DB::connection('tenant')
            ->table('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->where('e.einsatz_id', $einsatzId)
            ->select('e.*', 'k.kundenname', 'o.objektname')
            ->first();

        $tenant = TenantService::current();
        $companyName = $tenant->name ?? config('app.name', 'Clood-x');
        $datum = $einsatz->datum ?? $task->datum ?? '–';
        $objekt = $einsatz->objektname ?? '–';
        $kunde = $einsatz->kundenname ?? '–';

        foreach ($nuCompanies as $nu) {
            if (!filter_var($nu->email, FILTER_VALIDATE_EMAIL)) continue;

            try {
                Mail::to($nu->email)->queue(new NuNotificationMail(
                    blade: 'emails.nu-time-change',
                    subjectLine: "Zeitänderung Einsatz — {$datum} | {$companyName}",
                    payload: [
                        'company'    => $companyName,
                        'firmenname' => $nu->firmenname,
                        'kunde'      => $kunde,
                        'objekt'     => $objekt,
                        'datum'      => $datum,
                        'oldStart'   => $oldStart,
                        'oldEnd'     => $oldEnd,
                        'newStart'   => $newStart,
                        'newEnd'     => $newEnd,
                    ],
                ));
            } catch (\Exception $e) {
                \Log::error("NU time change email failed ({$nu->email}): " . $e->getMessage());
            }
        }
    }

    /**
     * Build the data payload for the emails.nu-worker-removal blade.
     * Returns a plain array suitable for queue serialization (no models).
     */
    protected function buildRemovalEmailData(array $d): array
    {
        $isNu = ($d['recipient'] ?? 'nu') === 'nu';
        $company = $d['company'] ?? 'Unternehmen';
        $workerName = $d['workerName'] ?? '–';
        $firmenname = $d['firmenname'] ?? '–';

        if ($isNu) {
            $greeting = "Sehr geehrte Damen und Herren von <strong>" . e($firmenname) . "</strong>,";
            $intro = "hiermit teilen wir Ihnen im Namen von <strong>" . e($company) . "</strong> mit, dass folgender Mitarbeiter von einem Einsatz abgemeldet wurde:";
            $footerNote = "Bitte sorgen Sie gegebenenfalls für einen Ersatz.";
        } else {
            $empName = $d['empName'] ?? 'Mitarbeiter';
            $greeting = "Hallo <strong>" . e($empName) . "</strong>,";
            $intro = "wir möchten Sie informieren, dass ein NU-Mitarbeiter von Ihrem Einsatz abgemeldet wurde:";
            $footerNote = "Bitte beachten Sie die Änderung in der Einsatzplanung.";
        }

        return [
            'isNu'        => $isNu,
            'company'     => $company,
            'workerName'  => $workerName,
            'firmenname'  => $firmenname,
            'datum'       => $d['datum'] ?? '–',
            'objekt'      => $d['objekt'] ?? '–',
            'kunde'       => $d['kunde'] ?? '–',
            'zeit'        => $d['zeit'] ?? '–',
            'reason'      => $d['reason'] ?? 'Kein Grund angegeben.',
            'greeting'    => $greeting,
            'intro'       => $intro,
            'footerNote'  => $footerNote,
        ];
    }

    protected function getWeekStartDate(?string $input): \DateTime
    {
        try {
            $date = $input ? new \DateTime($input) : new \DateTime();
        } catch (\Exception $e) {
            $date = new \DateTime();
        }
        $day = intval($date->format('N'));
        if ($day !== 1) $date->modify('-' . ($day - 1) . ' days');
        return $date;
    }

    /**
     * Intersect a list of einsatz_ids with the caller's Bereich scope.
     * Returns the input untouched for unrestricted users; restricted users
     * get back only ids whose derived Bereich (artikel → kunde) is allowed.
     */
    private function scopeEinsatzIds($user, array $ids): array
    {
        $allowed = BereichScope::for($user);
        if ($allowed === null) {
            return array_values($ids);
        }
        if (empty($allowed) || empty($ids)) {
            return [];
        }
        [$sql, $params] = BereichScope::inList($allowed, BereichScope::derivedExprSub('e'));
        return DB::connection('tenant')->table('einsaetze as e')
            ->whereIn('e.einsatz_id', $ids)
            ->whereRaw($sql, $params)
            ->pluck('e.einsatz_id')
            ->all();
    }
}
