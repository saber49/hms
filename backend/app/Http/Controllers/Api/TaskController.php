<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\IdGenerator;
use App\Helpers\NotificationDispatcher;
use App\Helpers\SchichtartCalculator;
use App\Support\BereichScope;
use App\Mail\NuNotificationMail;
use App\Services\TenantService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('id')) {
            return $this->show($request->id);
        }

        $query = DB::connection('tenant')
            ->table('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->leftJoin('artikel as a', 'e.artikel_id', '=', 'a.artikel_id')
            ->leftJoin('nachunternehmer as n', 'e.nu_id', '=', 'n.nu_id')
            ->select('e.*', 'k.kundenname', 'o.objektname', 'n.firmenname as nu_name',
                     'a.artikel_name', 'a.bereich as artikel_bereich',
                     'a.preis_kunde', 'a.nu_preis as obj_nu_preis');

        if ($request->filled('bestell_id')) $query->where('e.bestell_id', $request->bestell_id);
        if ($request->filled('kunden_id')) $query->where('e.kunden_id', $request->kunden_id);
        if ($request->filled('obj_id')) $query->where('e.obj_id', $request->obj_id);
        if ($request->filled('nu_id')) $query->where('e.nu_id', $request->nu_id);
        if ($request->filled('monat') && $request->filled('jahr')) {
            // SARGable date range so MySQL can use the idx_einsaetze_datum index.
            // Using MONTH()/YEAR() functions on the column would force a full table scan.
            $monat = max(1, min(12, (int) $request->monat));
            $jahr  = max(1900, (int) $request->jahr);
            $startDate = sprintf('%04d-%02d-01', $jahr, $monat);
            $endDate   = date('Y-m-t', strtotime($startDate));
            $query->whereBetween('e.datum', [$startDate, $endDate]);
        }
        if ($request->filled('status')) $query->where('e.hms_bestaetigt', $request->status);

        // Per-user Bereich restriction (article Bereich, customer fallback).
        BereichScope::applyBuilder($request->user(), $query, BereichScope::derivedExpr('a', 'k'));

        $tasks = $query->orderBy('e.datum')->orderBy('e.id')->get();

        $einsatzIds = $tasks->pluck('einsatz_id')->filter()->values()->toArray();
        $assignments = $this->fetchAssignments($einsatzIds);

        foreach ($tasks as &$task) {
            $task->assigned_workers = $assignments[$task->einsatz_id] ?? [];
        }

        $overview = DB::connection('tenant')
            ->table('einsaetze as e')
            ->selectRaw("
                COUNT(CASE WHEN e.hms_bestaetigt = 'ausstehend' THEN 1 END) AS ausstehend,
                COUNT(CASE WHEN e.hms_bestaetigt = 'ja' THEN 1 END) AS bestaetigt,
                COUNT(CASE WHEN e.hms_bestaetigt = 'nein' THEN 1 END) AS nicht_ok,
                COUNT(CASE WHEN e.datum = CURDATE() THEN 1 END) AS heute_faellig
            ");

        if ($request->filled('kunden_id')) $overview->where('e.kunden_id', $request->kunden_id);
        if ($request->filled('obj_id')) $overview->where('e.obj_id', $request->obj_id);
        if ($request->filled('nu_id')) $overview->where('e.nu_id', $request->nu_id);
        if ($request->filled('monat') && $request->filled('jahr')) {
            // SARGable date range (see note in index() above).
            $monat = max(1, min(12, (int) $request->monat));
            $jahr  = max(1900, (int) $request->jahr);
            $startDate = sprintf('%04d-%02d-01', $jahr, $monat);
            $endDate   = date('Y-m-t', strtotime($startDate));
            $overview->whereBetween('e.datum', [$startDate, $endDate]);
        }
        if ($request->filled('status')) $overview->where('e.hms_bestaetigt', $request->status);

        // Same Bereich restriction for the KPI counters (no joins here →
        // correlated-subquery variant of the derivation).
        BereichScope::applyBuilder($request->user(), $overview, BereichScope::derivedExprSub('e'));

        return response()->json([
            'overview' => $overview->first(),
            'tasks' => $tasks,
        ]);
    }

    public function show(string $id)
    {
        $task = DB::connection('tenant')
            ->table('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->leftJoin('nachunternehmer as n', 'e.nu_id', '=', 'n.nu_id')
            ->leftJoin('bestellungen as b', 'e.bestell_id', '=', 'b.bestell_id')
            // Match the bestelleingang_tag by tag_nr when available — this matters when a single
            // Bestelleingang has multiple tage on the same calendar day (e.g. day shift +
            // night shift). Without this, plan_start/plan_ende would arbitrarily pick the
            // first matching tag and the UI would show a misleading "Ursprünglich geplant" hint.
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
            ->select('e.*', 'k.kundenname', 'o.objektname', 'n.firmenname as nu_name',
                     'd.menge as soll_menge', 'd.zeit_von as plan_start', 'd.zeit_bis as plan_ende', 'b.eingang_id as order_eingang_id')
            // Current Anforderungen of the order day — read-only, lets the
            // planner flag a staffed shift whose copy is older than the order.
            ->when(Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'anforderungen'),
                fn ($q) => $q->addSelect('d.anforderungen as plan_anforderungen'))
            ->where('e.einsatz_id', $id)->first();

        if (!$task || !BereichScope::allowsEinsatz(request()->user(), $id)) {
            return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        }

        // Decode structured staffing requirements (set in Bestelleingang) so the
        // Dispo detail shows exactly who to pick without re-opening the order.
        if (isset($task->anforderungen)) {
            $task->anforderungen = json_decode($task->anforderungen, true);
        }
        if (isset($task->plan_anforderungen)) {
            $task->plan_anforderungen = json_decode($task->plan_anforderungen, true);
        }
        // Stadium/DFB shift → the planner shows the stadium-ban chip only here.
        $task->ist_stadion = \App\Services\SchichtboerseService::isStadiumShift($task->anforderungen ?? null);

        $assignments = $this->fetchAssignments([$id]);
        $task->assigned_workers = $assignments[$id] ?? [];

        // NU workers assigned to this einsatz via nu_schicht_positionen + nu_position_zuweisungen
        $nuWorkers = DB::connection('tenant')->select("
            SELECT npm.id AS nu_worker_id, npm.vorname, npm.name, npm.personal_nr, npm.foto,
                   n.firmenname AS nu_firmenname, nsp.position_name, nsp.id AS position_id
            FROM nu_position_zuweisungen npz
            INNER JOIN nu_portal_mitarbeiter npm ON npz.nu_mitarbeiter_id = npm.id
            INNER JOIN nu_schicht_positionen nsp ON npz.position_id = nsp.id
            LEFT JOIN nachunternehmer n ON nsp.nu_id = n.nu_id
            WHERE nsp.einsatz_id = ?
            ORDER BY n.firmenname ASC, npm.vorname ASC, npm.name ASC
        ", [$id]);
        $task->assigned_nu_workers = $nuWorkers;

        // Authoritative NU acceptance status lives in einsatz_nu_status — the
        // legacy einsaetze.nu_status column is never written on accept, so the
        // raw e.* value would always be stale/NULL in the planner UI.
        if (!empty($task->nu_id)) {
            $task->nu_status = DB::connection('tenant')->table('einsatz_nu_status')
                ->where('einsatz_id', $id)
                ->where('nu_id', $task->nu_id)
                ->value('status');
        }

        [$wSql, $wParams] = BereichScope::workerPredicate(request()->user(), 'mitarbeiter');
        $workerCols = ['dienst_nr', 'vorname', 'name', 'foto', 'bereich', 'arbeitsstunden', 'arbeitsstunden_typ', 'aktiv'];
        // Gender feeds the ♂/♀ coverage chip in the planner (display only).
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'geschlecht')) $workerCols[] = 'geschlecht';
        // Catalog qualifications + stadium ban — matched against the shift's
        // Anforderungen on the planner worker cards (display only).
        foreach (['qualifikationen', 'stadion_gesperrt'] as $c) {
            if (Schema::connection('tenant')->hasColumn('mitarbeiter', $c)) $workerCols[] = $c;
        }
        $workers = DB::connection('tenant')->table('mitarbeiter')
            ->where('aktiv', 1)->whereRaw($wSql, $wParams)
            ->orderBy('vorname')->orderBy('name')
            ->select($workerCols)
            ->get();
        $qualNames = Schema::connection('tenant')->hasTable('bereich_qualifikationen')
            ? DB::connection('tenant')->table('bereich_qualifikationen')
                ->whereNotNull('parent_id')->where('typ', 'qualifikation')->pluck('name', 'id')->all()
            : [];
        foreach ($workers as $w) {
            $ids = array_map('intval', (array) (json_decode((string) ($w->qualifikationen ?? ''), true) ?: []));
            $w->qual_names = array_values(array_filter(array_map(fn ($i) => $qualNames[$i] ?? null, $ids)));
            unset($w->qualifikationen);
        }

        // Enrich workers with planning warnings (time conflicts, absences, weekly hours overload)
        $workers = $this->attachWorkerWarnings($workers, $task);

        // Flag this customer's favorite workers (Stamm-Team) so the Dispo planner can
        // surface them first. Schema-guarded for tenants without the migration yet.
        $favoriteSet = [];
        if (!empty($task->kunden_id) && Schema::connection('tenant')->hasTable('kunden_favoriten')) {
            $favoriteSet = array_flip(
                DB::connection('tenant')->table('kunden_favoriten')
                    ->where('kunde_id', $task->kunden_id)
                    ->pluck('dienst_nr')->toArray()
            );
        }
        foreach ($workers as $w) {
            $w->is_favorite = isset($favoriteSet[$w->dienst_nr]) ? 1 : 0;
        }

        [$nSql, $nParams] = BereichScope::nuPredicate(request()->user(), 'nachunternehmer');
        $subcontractors = DB::connection('tenant')->table('nachunternehmer')
            ->where('aktiv', 1)->whereRaw($nSql, $nParams)->orderBy('firmenname')
            ->select('nu_id', 'firmenname', 'bereich')
            ->get();

        return response()->json([
            'task' => $task,
            'workers' => $workers,
            'subcontractors' => $subcontractors,
            // All catalog qualification names: a required name from the catalog
            // can be judged ✓/✗, free text only as "bitte prüfen".
            'qual_catalog' => array_values(array_unique(array_values($qualNames))),
        ]);
    }

    /**
     * GET /api/tasks/{id}/briefing-pdf
     *
     * Printable field briefing for the team lead: shift facts, meeting point,
     * staffing requirements, notes and the full roster (own + NU workers) with
     * gender, qualification and phone — plus check-off boxes and a signature
     * line, so the lead can run the shift on paper without system access.
     *
     * Optional query params (sent by the open planner modal so the PDF matches
     * the CURRENT screen state, including unsaved changes):
     *   workers  — comma-separated dienst_nr list overriding the saved roster
     *   aufgaben — JSON map dienst_nr → task text
     */
    public function briefingPdf(Request $request, string $id)
    {
        $task = DB::connection('tenant')
            ->table('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->leftJoin('nachunternehmer as n', 'e.nu_id', '=', 'n.nu_id')
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
            ->select('e.*', 'k.kundenname', 'o.objektname', 'n.firmenname as nu_name',
                     'd.menge as soll_menge', 'd.zeit_von as plan_start', 'd.zeit_bis as plan_ende')
            ->where('e.einsatz_id', $id)->first();

        if (!$task || !BereichScope::allowsEinsatz($request->user(), $id)) {
            return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        }

        $task->anforderungen = isset($task->anforderungen)
            ? json_decode($task->anforderungen, true)
            : null;

        // Assigned own workers enriched with briefing-relevant master data.
        // Column list is schema-guarded — tenants without the security-guard
        // migration simply get those cells empty instead of a 500.
        $mCols = ['em.dienst_nr', 'm.vorname', 'm.name', 'm.telefon', 'm.bereich'];
        foreach (['geschlecht', 'qualifikationen', 'stadion_gesperrt', 'mobil', 'funkrufname'] as $c) {
            if (Schema::connection('tenant')->hasColumn('mitarbeiter', $c)) $mCols[] = 'm.' . $c;
        }
        if (Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'aufgabe')) {
            $mCols[] = 'em.aufgabe';
        }
        $overrideIds = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('workers', '')))));
        if ($overrideIds) {
            // Live roster from the open modal — read straight from mitarbeiter
            // so workers added but not yet saved appear on the briefing too.
            $aufgaben = json_decode((string) $request->query('aufgaben', ''), true);
            $aufgaben = is_array($aufgaben) ? $aufgaben : [];
            $liveCols = array_values(array_filter($mCols, fn ($c) => !str_starts_with($c, 'em.')));
            $workers = DB::connection('tenant')->table('mitarbeiter as m')
                ->whereIn('m.dienst_nr', $overrideIds)
                ->select(array_merge(['m.dienst_nr'], $liveCols))
                ->get();
            $order = array_flip($overrideIds);
            $workers = $workers->sortBy(fn ($w) => $order[$w->dienst_nr] ?? PHP_INT_MAX)->values();
            foreach ($workers as $w) {
                $w->aufgabe = $aufgaben[$w->dienst_nr] ?? null;
            }
        } else {
            $workers = DB::connection('tenant')
                ->table('einsatz_mitarbeiter as em')
                ->join('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
                ->where('em.einsatz_id', $id)
                ->select($mCols)
                ->orderBy('em.sortierung')->orderBy('m.vorname')->orderBy('m.name')
                ->get();
        }

        // Qualifikation column = the worker's catalog qualifications
        // (Konto & Firma → Bereiche → Qualifikationen), in catalog order.
        $qualNames = Schema::connection('tenant')->hasTable('bereich_qualifikationen')
            ? DB::connection('tenant')->table('bereich_qualifikationen')->whereNotNull('parent_id')
                ->get(['id', 'parent_id', 'sortierung', 'name'])
                ->sort(fn ($x, $y) => [$x->parent_id, $x->sortierung] <=> [$y->parent_id, $y->sortierung]
                    ?: strnatcasecmp($x->name, $y->name))
                ->pluck('name', 'id')->all()
            : [];
        $geschlechtMap = ['m' => 'M', 'w' => 'W', 'd' => 'D'];
        $istStadion = \App\Services\SchichtboerseService::isStadiumShift($task->anforderungen ?? null);
        foreach ($workers as $w) {
            $w->geschlecht_label = $geschlechtMap[strtolower((string) ($w->geschlecht ?? ''))] ?? '–';
            $quali = [];
            $ids = array_map('intval', (array) (json_decode((string) ($w->qualifikationen ?? ''), true) ?: []));
            foreach ($qualNames as $qid => $qname) {
                if (in_array((int) $qid, $ids, true) && !in_array($qname, $quali, true)) $quali[] = $qname;
            }
            $w->quali_label   = implode(', ', $quali);
            $w->telefon_label = ($w->mobil ?? null) ?: ($w->telefon ?? null);
            // "GESPERRT" (stadium ban) only matters on stadium/DFB shifts.
            if (!$istStadion) $w->stadion_gesperrt = 0;
        }

        $nuWorkers = DB::connection('tenant')->select("
            SELECT npm.vorname, npm.name, npm.personal_nr,
                   n.firmenname AS nu_firmenname, nsp.position_name
            FROM nu_position_zuweisungen npz
            INNER JOIN nu_portal_mitarbeiter npm ON npz.nu_mitarbeiter_id = npm.id
            INNER JOIN nu_schicht_positionen nsp ON npz.position_id = nsp.id
            LEFT JOIN nachunternehmer n ON nsp.nu_id = n.nu_id
            WHERE nsp.einsatz_id = ?
            ORDER BY n.firmenname ASC, npm.vorname ASC, npm.name ASC
        ", [$id]);

        // Required headcount: order line, falling back to the note text —
        // identical logic to the planner frontend.
        $sollMenge = $task->soll_menge ? (int) $task->soll_menge : 0;
        if (!$sollMenge && !empty($task->beschreibung)
            && preg_match('/(\d+)\s*Mitarbeiter\s*ben/i', $task->beschreibung, $m)) {
            $sollMenge = (int) $m[1];
        }

        $fmtTime = fn ($t) => $t ? substr((string) $t, 0, 5) : '–';
        $pdf = Pdf::loadView('pdf.shift-briefing', [
            'task'        => $task,
            'workers'     => $workers,
            'nuWorkers'   => $nuWorkers,
            'sollMenge'   => $sollMenge,
            'datumFmt'    => $task->datum ? date('d.m.Y', strtotime($task->datum)) : '–',
            'zeitVon'     => $fmtTime($task->ist_start ?: $task->plan_start),
            'zeitBis'     => $fmtTime($task->ist_ende ?: $task->plan_ende),
            'generatedAt' => now()->format('d.m.Y H:i'),
        ]);
        $pdf->setPaper('A4', 'portrait');

        $datumSlug = $task->datum ? date('Y-m-d', strtotime($task->datum)) : 'ohne-datum';
        return $pdf->download('Einsatz-Briefing_' . $task->bestell_id . '_' . $datumSlug . '.pdf');
    }

    public function store(Request $request)
    {
        $data = $request->all();
        $errors = $this->validateOperationalFields($data, true);
        if ($errors) return response()->json(['error' => implode(', ', $errors)], 400);
        // Einsätze of a Bestelleingang order are generated from its day rows
        // only — a hand-made one would have no plan row and vanish on the
        // next order save.
        if (!empty($data['bestell_id']) && $this->eingangOfBestellung((string) $data['bestell_id'])) {
            return response()->json(['error' => 'Einsätze dieser Bestellung werden im Bestelleingang angelegt (Tag dort hinzufügen).'], 409);
        }
        $einsatz_id = IdGenerator::einsatzId($data['datum']);

        // Resolve artikel → classify Schichtart (Tag/Nacht/Sonntag/Feiertag/...)
        $artikelId = $data['artikel_id'] ?? $this->resolveArtikelIdFromBestellung($data['bestell_id'] ?? null);

        // Bereich restriction: a restricted user may only create Einsätze
        // whose derived Bereich (artikel → kunde fallback) is in his scope.
        $allowed = BereichScope::for($request->user());
        if (is_array($allowed)) {
            $bArtikel = $artikelId
                ? DB::connection('tenant')->table('artikel')->where('artikel_id', $artikelId)->value('bereich')
                : null;
            $bKunde = !empty($data['kunden_id'])
                ? DB::connection('tenant')->table('kunden')->where('kunde_id', $data['kunden_id'])->value('bereich')
                : null;
            $derived = trim((string) $bArtikel) !== '' ? $bArtikel : $bKunde;
            if (!BereichScope::listAllows($allowed, $derived)) {
                return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
            }
        }

        [$schichtart, $zuschlagPct] = $this->classifyEinsatz(
            $data['datum']     ?? null,
            $data['ist_start'] ?? null,
            $data['ist_ende']  ?? null,
            $artikelId
        );

        $row = [
            'einsatz_id' => $einsatz_id,
            'bestell_id' => $data['bestell_id'],
            'kunden_id' => $data['kunden_id'] ?? null,
            'obj_id' => $data['obj_id'] ?? null,
            'nu_id' => $data['nu_id'] ?? null,
            'datum' => $data['datum'],
            'tag_nr' => $data['tag_nr'] ?? null,
            'beschreibung' => $data['beschreibung'] ?? null,
            'ist_start' => $data['ist_start'] ?? null,
            'ist_ende' => $data['ist_ende'] ?? null,
            'ist_std_menge' => $data['ist_std_menge'] ?? null,
            'pause_minuten' => $data['pause_minuten'] ?? null,
            'gesetzl_pause' => $data['gesetzl_pause'] ?? null,
            'hms_bestaetigt' => $data['hms_bestaetigt'] ?? 'ausstehend',
        ];

        if (Schema::connection('tenant')->hasColumn('einsaetze', 'artikel_id') && $artikelId) {
            $row['artikel_id'] = $artikelId;
        }
        if (Schema::connection('tenant')->hasColumn('einsaetze', 'schichtart')) {
            $row['schichtart']   = $schichtart;
            $row['zuschlag_pct'] = $zuschlagPct;
        }

        DB::connection('tenant')->table('einsaetze')->insert($row);

        return response()->json(['success' => true, 'einsatz_id' => $einsatz_id], 201);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->all();
        $errors = $this->validateOperationalFields($data, false);
        if ($errors) return response()->json(['error' => implode(', ', $errors)], 400);
        $response_revoked = false;
        $kunden_revoked = false;

        if (!BereichScope::allowsEinsatz($request->user(), $id)) {
            return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        }

        if (empty($data['dienst_nr']) && empty($data['nu_worker_id'])) {
            $current = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->first();
            if (!$current) return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
            // Structure of an order-linked Einsatz (date, order link, hours,
            // pauses) is owned by its Bestelleingang day row — single source.
            $linkedEingang = !empty($current->bestell_id) && empty($current->bestell_storno_at ?? null)
                ? $this->eingangOfBestellung((string) $current->bestell_id) : null;
            if ($linkedEingang) {
                $same = static function ($a, $b): bool {
                    $a = trim((string) ($a ?? '')); $b = trim((string) ($b ?? ''));
                    if (is_numeric($a) && is_numeric($b)) return abs((float) $a - (float) $b) < 0.001;
                    return substr($a, 0, 10) === substr($b, 0, 10);
                };
                foreach (['bestell_id' => 'Bestellung', 'kunden_id' => 'Kunde', 'obj_id' => 'Objekt', 'datum' => 'Datum',
                          'tag_nr' => 'Tag', 'ist_std_menge' => 'Soll-Stunden', 'pause_minuten' => 'MA-Pause',
                          'gesetzl_pause' => 'Kunden-Pause'] as $f => $label) {
                    if (array_key_exists($f, $data) && !$same($data[$f], $current->{$f} ?? null)) {
                        return response()->json([
                            'error' => $label . ' wird im Bestelleingang ' . $linkedEingang . ' gepflegt und kann hier nicht geändert werden.',
                            'eingang_id' => $linkedEingang,
                        ], 409);
                    }
                }
            }
            $normalizeTime = fn($value) => trim((string) ($value ?? '')) === '' ? null : substr(trim((string) $value), 0, 5);
            foreach (['ist_start', 'ist_ende'] as $field) {
                if (array_key_exists($field, $data) && $normalizeTime($data[$field]) !== $normalizeTime($current->{$field})) {
                    return response()->json(['error' => 'Schichtzeiten können nur im Bestelleingang geändert werden.'], 409);
                }
                unset($data[$field]);
            }
        }

        $update = [];
        $fields = ['bestell_id', 'kunden_id', 'obj_id', 'nu_id', 'datum', 'tag_nr',
                    'beschreibung', 'ist_start', 'ist_ende', 'ist_std_menge', 'ist_betrag',
                    'pause_minuten', 'gesetzl_pause', 'hms_bestaetigt', 'pruefung', 'bemerkung'];

        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) $update[$f] = $data[$f];
        }

        // Per-worker update: save all editable fields to einsatz_mitarbeiter
        if (!empty($data['dienst_nr']) || !empty($data['nu_worker_id'])) {
            $res = $this->saveAssignmentRow($request->user(), $id, $data);
            if (isset($res['error'])) return response()->json(['error' => $res['error']], $res['status']);
            $response_revoked = $res['zeit_freigabe_revoked'];
            $kunden_revoked = $res['kunden_freigabe_revoked'];
        } else {
            if (!empty($update)) {
                // If datum / ist_start / ist_ende changed, reclassify the
                // schichtart so Controlling stays accurate.
                $needReclass = array_intersect_key($update, array_flip(['datum', 'ist_start', 'ist_ende']));
                if (!empty($needReclass) && Schema::connection('tenant')->hasColumn('einsaetze', 'schichtart')) {
                    $current = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->first();
                    if ($current && ($current->hms_bestaetigt ?? null) !== 'ja') {
                        // only reclassify open einsaetze; bestaetigte stay snapshotted
                        $artikelId = $current->artikel_id ?? $this->resolveArtikelIdFromBestellung($current->bestell_id ?? null);
                        [$schichtart, $zuschlagPct] = $this->classifyEinsatz(
                            $update['datum']     ?? $current->datum,
                            $update['ist_start'] ?? $current->ist_start,
                            $update['ist_ende']  ?? $current->ist_ende,
                            $artikelId
                        );
                        $update['schichtart']   = $schichtart;
                        $update['zuschlag_pct'] = $zuschlagPct;
                    }
                }
                // Pause edit AFTER workers clocked: rows whose stored
                // deduction mirrored the old plan value get re-derived so
                // ist_std never silently contradicts the new pause.
                $pauseTouched = array_key_exists('pause_minuten', $update);
                $beforePause = $pauseTouched
                    ? DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->first()
                    : null;

                DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->update($update);

                if ($beforePause) {
                    $oldEff = $beforePause->pause_minuten;
                    $newEff = array_key_exists('pause_minuten', $update)
                        ? $update['pause_minuten'] : $beforePause->pause_minuten;
                    WorkerAppZeitController::reconcilePauseAfterPlanChange(
                        (string) $id,
                        $oldEff !== null ? (int) $oldEff : null,
                        $newEff !== null ? (int) $newEff : null
                    );
                }
            }
        }

        return response()->json(['success' => true, 'zeit_freigabe_revoked' => $response_revoked,
            'kunden_freigabe_revoked' => $kunden_revoked]);
    }

    /**
     * POST /disposition/zeiten-speichern — Zeiterfassung (Monatsabschluss)
     * saves all changed rows of a worker block in ONE transaction: either
     * every row is stored or none (no half-saved block after a network drop
     * or a validation error in row 17). Only per-worker time fields are
     * accepted — the Einsatz plan is never touched from here.
     * Payload: { rows: [{ einsatz_id, dienst_nr | nu_worker_id+position_id,
     *            ist_start, ist_ende, pause_minuten, ist_std_menge
     *            | kunden_start, kunden_ende, kunden_pause, kunden_std }] }
     */
    public function saveZeiten(Request $request)
    {
        $rows = $request->input('rows');
        if (!is_array($rows) || empty($rows) || count($rows) > 500) {
            return response()->json(['error' => 'rows (1–500) erforderlich'], 400);
        }
        $allowed = array_flip(['dienst_nr', 'nu_worker_id', 'position_id',
            'ist_start', 'ist_ende', 'pause_minuten', 'ist_std_menge',
            'kunden_start', 'kunden_ende', 'kunden_pause', 'kunden_std']);
        $user = $request->user();
        $db = DB::connection('tenant');
        $results = [];

        try {
            $db->transaction(function () use ($rows, $allowed, $user, $db, &$results) {
                foreach (array_values($rows) as $i => $raw) {
                    $fail = fn (string $msg, int $status = 400) => throw new \DomainException('Zeile ' . ($i + 1) . ': ' . $msg, $status);
                    if (!is_array($raw)) $fail('Ungültige Daten');
                    $id = trim((string) ($raw['einsatz_id'] ?? ''));
                    $data = array_intersect_key($raw, $allowed);
                    $isNu = empty($data['dienst_nr']);
                    if ($id === '' || ($isNu && (empty($data['nu_worker_id']) || empty($data['position_id'])))) {
                        $fail('einsatz_id und dienst_nr bzw. nu_worker_id + position_id erforderlich');
                    }
                    if ($isNu) unset($data['dienst_nr']); else unset($data['nu_worker_id'], $data['position_id']);
                    if ($errors = $this->validateOperationalFields($data, false)) $fail(implode(', ', $errors));
                    if (!BereichScope::allowsEinsatz($user, $id)) $fail('Einsatz nicht gefunden', 404);
                    $exists = $isNu
                        ? $db->table('nu_position_zuweisungen as npz')
                            ->join('nu_schicht_positionen as nsp', 'nsp.id', '=', 'npz.position_id')
                            ->where('nsp.einsatz_id', $id)->where('npz.position_id', (int) $data['position_id'])
                            ->where('npz.nu_mitarbeiter_id', (int) $data['nu_worker_id'])->exists()
                        : $db->table('einsatz_mitarbeiter')->where('einsatz_id', $id)
                            ->where('dienst_nr', (string) $data['dienst_nr'])->exists();
                    if (!$exists) $fail('Zuweisung nicht gefunden', 404);

                    $res = $this->saveAssignmentRow($user, $id, $data);
                    if (isset($res['error'])) $fail($res['error'], $res['status']);
                    $results[] = ['einsatz_id' => $id] + $res;
                }
            });
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }

        \App\Helpers\Realtime::changed('dispo', ['zeiterfassung' => true]);
        return response()->json(['success' => true, 'saved' => count($results), 'rows' => $results]);
    }

    /**
     * Per-worker values of ONE assignment row (internal: einsatz_mitarbeiter,
     * NU: nu_position_zuweisungen) — the worker's Ist and/or the customer
     * values (kunden_*). Shared by PUT /tasks/{id} and saveZeiten().
     * Returns ['error', 'status'] or the two revoke flags.
     */
    private function saveAssignmentRow(object $user, string $id, array $data): array
    {
        $response_revoked = false;
        $kunden_revoked = false;
        if ($err = $this->implausibleHours($id, $data)) return ['error' => $err, 'status' => 422];
        if (!empty($data['dienst_nr'])) {
            if (!BereichScope::allowsWorker($user, (string) $data['dienst_nr'])) {
                return ['error' => 'Mitarbeiter nicht gefunden', 'status' => 404];
            }
            $emUpdate = [];
            if (array_key_exists('ist_std_menge', $data)) $emUpdate['ist_std'] = $data['ist_std_menge'];
            if (array_key_exists('ist_start', $data)) $emUpdate['ist_start'] = $data['ist_start'];
            if (array_key_exists('ist_ende', $data)) $emUpdate['ist_ende'] = $data['ist_ende'];
            if (array_key_exists('pause_minuten', $data)) $emUpdate['pause_minuten'] = $data['pause_minuten'];

            if (!empty($emUpdate)) {
                $emRow = DB::connection('tenant')->table('einsatz_mitarbeiter')
                    ->where('einsatz_id', $id)->where('dienst_nr', $data['dienst_nr'])
                    ->first();
                // An approved row whose recorded time changes loses its
                // Freigabe: only the values the office approved may reach
                // the customer-facing Leistungsnachweis.
                $revoked = false;
                // Korrekturbuchung: audit trail + remember which Ist fields
                // the office set, so a later app clock-out keeps them.
                $corrected = $emRow ? $this->auditKorrektur($user, $id, ['dienst_nr' => (string) $data['dienst_nr']], 'ist',
                    (array) $emRow, $emUpdate) : [];
                if ($corrected && Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_korrektur')) {
                    $prev = json_decode((string) ($emRow->zeit_korrektur ?? ''), true) ?: [];
                    $emUpdate['zeit_korrektur'] = json_encode(array_values(array_unique(array_merge($prev, $corrected))));
                    $emUpdate['zeit_korrektur_am'] = now();
                    $emUpdate['zeit_korrektur_von'] = mb_substr((string) ($user->name ?? $user->username ?? $user->email ?? ''), 0, 150) ?: null;
                }
                if ($emRow && (int) ($emRow->zeit_freigabe ?? 0) === 1) {
                    $changed = false;
                    foreach (['ist_start', 'ist_ende'] as $f) {
                        if (array_key_exists($f, $emUpdate)
                            && substr((string) ($emUpdate[$f] ?? ''), 0, 5) !== substr((string) ($emRow->{$f} ?? ''), 0, 5)) $changed = true;
                    }
                    if (array_key_exists('ist_std', $emUpdate)
                        && round((float) $emUpdate['ist_std'], 2) !== round((float) ($emRow->ist_std ?? 0), 2)) $changed = true;
                    if (array_key_exists('pause_minuten', $emUpdate)
                        && (int) $emUpdate['pause_minuten'] !== (int) ($emRow->pause_minuten ?? 0)) $changed = true;
                    if ($changed) {
                        $emUpdate['zeit_freigabe'] = 0;
                        $emUpdate['zeit_freigabe_am'] = null;
                        $revoked = true;
                    }
                }
                DB::connection('tenant')->table('einsatz_mitarbeiter')
                    ->where('einsatz_id', $id)
                    ->where('dienst_nr', $data['dienst_nr'])
                    ->update($emUpdate);
                if ($revoked) $response_revoked = true;
            }
            $kKey = ['einsatz_id' => $id, 'dienst_nr' => (string) $data['dienst_nr']];
            $kBefore = $this->kundenSnapshot('einsatz_mitarbeiter', $kKey);
            // Office touch freezes the customer row (Kunde stays
            // independent): kunden_* fields that were still inheriting get
            // the values the Kunde view shows right now — the Mitarbeiter
            // edit above never changes what the customer sees. Runs after
            // the $kBefore snapshot so the materialisation itself is
            // audited as a 'kunde' Korrekturbuchung.
            $emRow ??= DB::connection('tenant')->table('einsatz_mitarbeiter')
                ->where('einsatz_id', $id)->where('dienst_nr', (string) $data['dienst_nr'])->first();
            if ($emRow) {
                $this->freezeKundenWerte('einsatz_mitarbeiter', $kKey, $emRow,
                    DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)
                        ->first(['ist_start', 'ist_ende', 'pause_minuten', 'ist_std_menge']),
                    $emUpdate ?? []);
            }
            $kunden_revoked = $this->saveKundenWerte('einsatz_mitarbeiter', $kKey, $data, $this->planPause($id));
            if ($kBefore) $this->auditKorrektur($user, $id, ['dienst_nr' => (string) $data['dienst_nr']], 'kunde',
                $kBefore, $this->kundenSnapshot('einsatz_mitarbeiter', $kKey));
        } elseif (!empty($data['nu_worker_id'])) {
            // NU worker per-worker update via nu_position_zuweisungen
            $npzUpdate = [];
            if (array_key_exists('ist_std_menge', $data)) $npzUpdate['ist_std'] = $data['ist_std_menge'];
            if (array_key_exists('ist_start', $data)) $npzUpdate['ist_start'] = $data['ist_start'];
            if (array_key_exists('ist_ende', $data)) $npzUpdate['ist_ende'] = $data['ist_ende'];
            if (array_key_exists('pause_minuten', $data)) $npzUpdate['pause_minuten'] = $data['pause_minuten'];
            $nuIdent = ['nu_worker_id' => (int) $data['nu_worker_id'], 'position_id' => (int) ($data['position_id'] ?? 0)];
            $nuKey = ['position_id' => (int) ($data['position_id'] ?? 0), 'nu_mitarbeiter_id' => (int) $data['nu_worker_id']];

            if (!empty($npzUpdate) && !empty($data['position_id'])) {
                $npzRow = DB::connection('tenant')->table('nu_position_zuweisungen')->where($nuKey)->first();
                if ($npzRow) $this->auditKorrektur($user, $id, $nuIdent, 'ist', (array) $npzRow, $npzUpdate);
                DB::connection('tenant')->table('nu_position_zuweisungen')
                    ->where('position_id', $data['position_id'])
                    ->where('nu_mitarbeiter_id', $data['nu_worker_id'])
                    ->update($npzUpdate);
            }
            if (!empty($data['position_id'])) {
                $kBefore = $this->kundenSnapshot('nu_position_zuweisungen', $nuKey);
                $npzRow ??= DB::connection('tenant')->table('nu_position_zuweisungen')->where($nuKey)->first();
                if ($npzRow) {
                    $this->freezeKundenWerte('nu_position_zuweisungen', $nuKey, $npzRow,
                        DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)
                            ->first(['ist_start', 'ist_ende', 'pause_minuten', 'ist_std_menge']),
                        $npzUpdate ?? []);
                }
                $kunden_revoked = $this->saveKundenWerte('nu_position_zuweisungen', $nuKey, $data, $this->planPause($id));
                if ($kBefore) $this->auditKorrektur($user, $id, $nuIdent, 'kunde', $kBefore, $this->kundenSnapshot('nu_position_zuweisungen', $nuKey));
            }
        }

        return ['zeit_freigabe_revoked' => $response_revoked, 'kunden_freigabe_revoked' => $kunden_revoked];
    }

    /**
     * Plausibility guard for hours typed in the Monatsabschluss: the office
     * may type Std by hand (paid pause, agreements), but never MORE than the
     * time between Von and Bis — that can only be a typo and goes straight
     * into wages / the invoice. Checked for the worker's Ist and for the
     * customer values. Von/Bis missing from the payload fall back to the
     * stored row. Von = Bis is ambiguous (0 h or 24 h) and is not checked.
     * The span uses the real clock on the shift's date (DST nights).
     */
    private function implausibleHours(string $id, array $data): ?string
    {
        $isNu = empty($data['dienst_nr']);
        $row = null;
        $loadRow = function () use (&$row, $isNu, $id, $data) {
            if ($row !== null) return $row;
            $q = $isNu
                ? DB::connection('tenant')->table('nu_position_zuweisungen')
                    ->where('position_id', (int) ($data['position_id'] ?? 0))->where('nu_mitarbeiter_id', (int) ($data['nu_worker_id'] ?? 0))
                : DB::connection('tenant')->table('einsatz_mitarbeiter')
                    ->where('einsatz_id', $id)->where('dienst_nr', (string) $data['dienst_nr']);
            return $row = ($q->first() ?: (object) []);
        };
        $datum = null;
        foreach ([['ist_std_menge', 'ist_start', 'ist_ende', 'ist_start', 'ist_ende', 'Std'],
                  ['kunden_std', 'kunden_start', 'kunden_ende', 'kunden_start', 'kunden_ende', 'Std (Kunde)']] as [$stdKey, $sKey, $eKey, $rowS, $rowE, $label]) {
            if (!array_key_exists($stdKey, $data) || $data[$stdKey] === null || $data[$stdKey] === '') continue;
            $std = (float) $data[$stdKey];
            $von = array_key_exists($sKey, $data) ? $data[$sKey] : ($loadRow()->{$rowS} ?? null);
            $bis = array_key_exists($eKey, $data) ? $data[$eKey] : ($loadRow()->{$rowE} ?? null);
            $von = $von ? substr(trim((string) $von), 0, 5) : null;
            $bis = $bis ? substr(trim((string) $bis), 0, 5) : null;
            if (!$von || !$bis || !preg_match('/^\d{2}:\d{2}$/', $von) || !preg_match('/^\d{2}:\d{2}$/', $bis)) continue;
            // Von = Bis is "no working time" (0 h) — never guessed as 24 h.
            if ($von === $bis) {
                if ($std > 0) return "{$label}: Beginn und Ende sind gleich ({$von}) – das ergibt 0 Std. Bitte Zeiten prüfen.";
                continue;
            }
            $datum ??= (string) DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->value('datum');
            $spanMin = \App\Support\ShiftSpan::minutes($datum, $von, $bis);
            if ($std * 60 > $spanMin + 1) {
                return sprintf('%s %s h ist größer als der Zeitraum %s–%s (%s h). Bitte Von/Bis oder Std prüfen.',
                    $label, number_format($std, 2, ',', ''), $von, $bis, number_format($spanMin / 60, 2, ',', ''));
            }
        }
        return null;
    }

    /**
     * Append-only audit row for an office correction (Korrekturbuchung):
     * who changed which working-time values of one assignment, before and
     * after. Only real changes are written.
     */
    private function auditKorrektur(object $user, string $id, array $ident, string $modus, array $before, array $after): array
    {
        $norm = function (string $k, $v) {
            if ($v === null || $v === '') return null;
            if (str_ends_with($k, '_start') || str_ends_with($k, '_ende')) return substr(trim((string) $v), 0, 5);
            if (str_contains($k, 'pause')) return (int) $v;
            return round((float) $v, 2);
        };
        $vorher = []; $nachher = [];
        foreach ($after as $k => $v) {
            $old = $norm($k, $before[$k] ?? null);
            $new = $norm($k, $v);
            if ($old !== $new) { $vorher[$k] = $old; $nachher[$k] = $new; }
        }
        if (!$nachher || !Schema::connection('tenant')->hasTable('zeit_korrekturen')) return array_keys($nachher);
        DB::connection('tenant')->table('zeit_korrekturen')->insert([
            'einsatz_id'   => $id,
            'dienst_nr'    => $ident['dienst_nr'] ?? null,
            'nu_worker_id' => $ident['nu_worker_id'] ?? null,
            'position_id'  => $ident['position_id'] ?? null,
            'modus'        => $modus,
            'vorher'       => json_encode($vorher, JSON_UNESCAPED_UNICODE),
            'nachher'      => json_encode($nachher, JSON_UNESCAPED_UNICODE),
            'user_id'      => $user->id ?? null,
            'user_name'    => mb_substr((string) ($user->name ?? $user->username ?? $user->email ?? ''), 0, 150) ?: null,
            'created_at'   => now(),
        ]);
        return array_keys($nachher);
    }

    /** Planung pause (einsaetze.pause_minuten) of one Einsatz — the ONE pause. */
    private function planPause(string $id): int
    {
        return (int) (DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $id)->value('pause_minuten') ?? 0);
    }

    /** Current customer values of one assignment row (for the audit trail). */
    private function kundenSnapshot(string $table, array $key): array
    {
        if (!Schema::connection('tenant')->hasColumn($table, 'kunden_std')) return [];
        $row = DB::connection('tenant')->table($table)->where($key)->first(['kunden_start', 'kunden_ende', 'kunden_pause', 'kunden_std']);
        return $row ? (array) $row : [];
    }

    /**
     * Customer-facing values (Monatsabschluss, Modus "Kunde") of one
     * assignment row — never touch the worker's Ist. A changed value on a
     * row with Kunden-Freigabe revokes that approval (re-approve to send the
     * new values to Bestellungen). Returns whether it was revoked.
     */
    private function saveKundenWerte(string $table, array $key, array $data, ?int $planPause = null): bool
    {
        $fields = ['kunden_start', 'kunden_ende', 'kunden_pause', 'kunden_std'];
        if (!array_intersect($fields, array_keys($data))
            || !Schema::connection('tenant')->hasColumn($table, 'kunden_freigabe')) return false;

        $time = fn ($v) => trim((string) ($v ?? '')) === '' ? null : substr(trim((string) $v), 0, 5);
        $upd = [];
        foreach (['kunden_start', 'kunden_ende'] as $f) {
            if (array_key_exists($f, $data)) $upd[$f] = $time($data[$f]); // validated in validateOperationalFields
        }
        // ONE pause on the customer row: the office may edit it in Kunde
        // mode; without a value it starts from the Planung pause.
        if (array_key_exists('kunden_pause', $data) && $data['kunden_pause'] !== null && $data['kunden_pause'] !== '') {
            $upd['kunden_pause'] = max(0, min(1440, (int) $data['kunden_pause']));
        }
        $row0 = DB::connection('tenant')->table($table)->where($key)->first(['kunden_pause']);
        if (!isset($upd['kunden_pause']) && $planPause !== null && $row0 && $row0->kunden_pause === null) {
            $upd['kunden_pause'] = $planPause;
        }
        if (array_key_exists('kunden_std', $data)) {
            $upd['kunden_std'] = $data['kunden_std'] === null || $data['kunden_std'] === ''
                ? null : round(max(0, min(24, (float) $data['kunden_std'])), 2);
        }

        $q = fn () => DB::connection('tenant')->table($table)->where($key);
        $row = $q()->first();
        if (!$row) return false;

        $revoked = false;
        if ((int) ($row->kunden_freigabe ?? 0) === 1) {
            $changed = false;
            foreach ($upd as $f => $v) {
                $old = $row->{$f};
                $same = in_array($f, ['kunden_start', 'kunden_ende'], true)
                    ? $time($old) === $v
                    : ($old === null ? $v === null : ($v !== null && round((float) $old, 2) === round((float) $v, 2)));
                if (!$same) $changed = true;
            }
            if ($changed) {
                $upd['kunden_freigabe'] = 0;
                $upd['kunden_freigabe_am'] = null;
                $revoked = true;
            }
        }
        $q()->update($upd);
        return $revoked;
    }

    /**
     * Kunde decoupling — freeze on office save: every kunden_* field that
     * was still inheriting is filled with the value the Kunde view
     * currently shows (kunden ?? Ist ?? plan). After that the customer
     * numbers are their own row: later Ist changes (office edit or app
     * clock) never move them; the office edits them in Kunde mode.
     * Pure-plan rows (no Ist anywhere, nothing written) stay inheriting
     * so the no-time check in kundenFreigabe keeps seeing "no time".
     */
    private function freezeKundenWerte(string $table, array $key, object $row, ?object $plan, array $istUpdate): void
    {
        if (!Schema::connection('tenant')->hasColumn($table, 'kunden_start')) return;
        $hasIst = false;
        foreach ($istUpdate as $v) {
            if ($v !== null && $v !== '') { $hasIst = true; break; }
        }
        if (!$hasIst) {
            foreach (['ist_start', 'ist_ende', 'ist_std'] as $f) {
                if (($row->{$f} ?? null) !== null && $row->{$f} !== '') { $hasIst = true; break; }
            }
        }
        if (!$hasIst) return;
        $map = [
            'kunden_start' => 'ist_start',
            'kunden_ende'  => 'ist_ende',
            'kunden_pause' => 'pause_minuten',
            'kunden_std'   => 'ist_std',
        ];
        $upd = [];
        $planPause = (int) ($plan->pause_minuten ?? 0);
        unset($map['kunden_pause']); // one pause: always the Planung pause (below)
        foreach ($map as $kf => $istF) {
            if (($row->{$kf} ?? null) !== null) continue;
            $v = $row->{$istF} ?? null;
            if ($v === null || $v === '') {
                $pf = $istF === 'ist_std' ? 'ist_std_menge' : $istF;
                $v = $plan ? ($plan->{$pf} ?? null) : null;
            }
            if ($v === null || $v === '') continue;
            $upd[$kf] = str_ends_with($kf, '_start') || str_ends_with($kf, '_ende')
                ? substr((string) $v, 0, 5) : $v;
            // Worker Std re-based onto the Planung pause (same Von–Bis).
            if ($kf === 'kunden_std' && ($row->ist_std ?? null) !== null && $row->ist_std !== '') {
                $wp = ($row->pause_minuten ?? null) !== null ? (int) $row->pause_minuten : $planPause;
                $upd[$kf] = round(max(0, (float) $v + ($wp - $planPause) / 60), 2);
            }
        }
        // Customer pause starts from the Planung pause (editable afterwards in Kunde mode).
        if (($row->kunden_pause ?? null) === null) $upd['kunden_pause'] = $planPause;
        if ($upd) DB::connection('tenant')->table($table)->where($key)->update($upd);
    }

    /**
     * Classify a single einsatz into a schichtart + percentage. Resolves the
     * relevant artikel and Feiertage cache lazily so a tight loop of
     * insertions only pays the cost once per request.
     *
     * @return array{0:string,1:float} [schichtart, zuschlag_pct]
     */
    protected function classifyEinsatz(?string $datum, ?string $istStart, ?string $istEnde, ?string $artikelId): array
    {
        if (!$artikelId || !Schema::connection('tenant')->hasTable('artikel')) {
            return ['tagdienst', 0.00];
        }
        if (!Schema::connection('tenant')->hasColumn('artikel', 'zuschlaege_aktiv')) {
            return ['tagdienst', 0.00];
        }
        $artikel = DB::connection('tenant')->table('artikel')->where('artikel_id', $artikelId)->first();
        if (!$artikel) return ['tagdienst', 0.00];

        $cls = SchichtartCalculator::compute(
            $datum ? substr((string) $datum, 0, 10) : null,
            $istStart,
            $istEnde,
            $artikel,
            SchichtartCalculator::cachedFeiertage($artikel->obj_id ?? null)
        );
        return [$cls['schichtart'], $cls['zuschlag_pct']];
    }

    /**
     * If an einsatz didn't carry artikel_id directly, fall back to its
     * parent bestellung. Returns null if neither has it.
     */
    protected function resolveArtikelIdFromBestellung(?string $bestellId): ?string
    {
        if (!$bestellId) return null;
        return DB::connection('tenant')->table('bestellungen')
            ->where('bestell_id', $bestellId)
            ->value('artikel_id');
    }

    /** eingang_id of a Bestellung that belongs to a (still existing) Bestelleingang, else null. */
    private function eingangOfBestellung(string $bestellId): ?string
    {
        return DB::connection('tenant')->table('bestellungen as b')
            ->join('bestelleingang as be', 'be.eingang_id', '=', 'b.eingang_id')
            ->where('b.bestell_id', $bestellId)->value('be.eingang_id');
    }

    /** Order day row of a deleted confirmed Einsatz + Verlauf entry (best effort). */
    protected function removeOrderDayOfDeletedShift(object $einsatz): void
    {
        try {
            $db = DB::connection('tenant');
            $eingangId = $db->table('bestellungen')->where('bestell_id', $einsatz->bestell_id)->value('eingang_id');
            if (!$eingangId) return;
            $q = $db->table('bestelleingang_tage')->where('eingang_id', $eingangId);
            if (!empty($einsatz->tag_uid ?? null) && Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'tag_uid')) {
                $q->where('tag_uid', $einsatz->tag_uid);
            } elseif (!empty($einsatz->tag_nr)) {
                $q->where('datum', $einsatz->datum)->where('tag_nr', $einsatz->tag_nr);
            } else {
                return;
            }
            $removed = $q->delete();
            if (Schema::connection('tenant')->hasTable('bestelleingang_log')) {
                $u = request()->user();
                $zeit = \App\Support\StaffedShifts::hhmm($einsatz->ist_start) . '–' . \App\Support\StaffedShifts::hhmm($einsatz->ist_ende);
                $db->table('bestelleingang_log')->insert([
                    'eingang_id' => $eingangId,
                    'user_id' => $u->id ?? null,
                    'user_name' => $u ? (trim((string) ($u->full_name ?? '')) !== '' ? $u->full_name : ($u->name ?? null)) : null,
                    'aktion' => 'geaendert',
                    'aenderungen' => json_encode([[
                        'feld' => 'Bestätigter Einsatz gelöscht (Planung)',
                        'von' => date('d.m.Y', strtotime((string) $einsatz->datum)) . ' ' . $zeit . ' · ' . $einsatz->einsatz_id,
                        'nach' => $removed ? 'Tag aus der Bestellung entfernt' : 'gelöscht',
                    ]], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('removeOrderDayOfDeletedShift failed: ' . $e->getMessage());
        }
    }

    public function destroy(string $id)
    {
        $einsatz = DB::connection('tenant')->table('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->where('e.einsatz_id', $id)
            ->select('e.*', 'k.kundenname', 'o.objektname')
            ->first();

        if (!$einsatz || !BereichScope::allowsEinsatz(request()->user(), $id)) {
            return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        }

        // Confirmed shifts: only Inhaber / Administrator, only after an
        // explicit confirmation, never while hours are approved in the
        // Zeiterfassung (worker or customer side) — those feed payroll and
        // customer documents. The order day goes with it (see below).
        $confirmedDelete = false;
        if (($einsatz->hms_bestaetigt ?? null) === 'ja') {
            $u = request()->user();
            if (!$u || !method_exists($u, 'isAdminLevel') || !$u->isAdminLevel()) {
                return response()->json(['error' => 'Bestätigte Einsätze darf nur der Inhaber oder ein Administrator löschen.'], 403);
            }
            if (!request()->boolean('bestaetigt_loeschen')) {
                return response()->json(['error' => 'Bestätigten Einsatz wirklich löschen? Bitte bestätigen.', 'needs_confirm' => true], 409);
            }
            $db = DB::connection('tenant');
            $hasCol = fn ($t, $c) => Schema::connection('tenant')->hasColumn($t, $c);
            $approved = $db->table('einsatz_mitarbeiter')->where('einsatz_id', $id)
                ->where(function ($q) use ($hasCol) {
                    $q->where('zeit_freigabe', 1);
                    if ($hasCol('einsatz_mitarbeiter', 'kunden_freigabe')) $q->orWhere('kunden_freigabe', 1);
                })->exists();
            if (!$approved && $hasCol('nu_position_zuweisungen', 'kunden_freigabe')) {
                $approved = $db->table('nu_position_zuweisungen as z')
                    ->join('nu_schicht_positionen as p', 'p.id', '=', 'z.position_id')
                    ->where('p.einsatz_id', $id)->where('z.kunden_freigabe', 1)->exists();
            }
            if ($approved) {
                return response()->json([
                    'error' => 'Die Stunden dieses Einsatzes sind in der Zeiterfassung freigegeben. Bitte zuerst die Freigabe (Mitarbeiter und Kunde) zurücknehmen, dann löschen.',
                    'freigegeben' => true,
                ], 409);
            }
            $confirmedDelete = true;
        }

        // The Bestelleingang order is the source of every linked shift: a
        // shift deleted here would silently come back on the next order save
        // (and order, Auftragsbestätigung and billing would disagree with
        // Dispo). Linked live shifts are removed from the order instead.
        // Grey tombstones (day already removed) and shifts without an order
        // stay deletable here.
        if (!$confirmedDelete && !empty($einsatz->bestell_id) && empty($einsatz->bestell_storno_at ?? null)) {
            $eingangId = DB::connection('tenant')->table('bestellungen as b')
                ->join('bestelleingang as be', 'be.eingang_id', '=', 'b.eingang_id')
                ->where('b.bestell_id', $einsatz->bestell_id)->value('be.eingang_id');
            if ($eingangId) {
                return response()->json([
                    'error' => 'Dieser Einsatz gehört zur Bestellung ' . $eingangId . '. Bitte den Tag '
                        . date('d.m.Y', strtotime((string) $einsatz->datum)) . ' '
                        . \App\Support\StaffedShifts::hhmm($einsatz->ist_start) . '–' . \App\Support\StaffedShifts::hhmm($einsatz->ist_ende)
                        . ' im Bestelleingang entfernen (bei eingeplanten Mitarbeitern zuerst den Plan hier aufheben).',
                    'eingang_id' => $eingangId,
                ], 409);
            }
        }

        // Collect NU info for cancellation emails before deleting — EVERY NU
        // that has positions on this shift, not just the one in einsaetze.nu_id
        // (with multiple NU groups the others were previously deleted silently).
        $nuIdsToNotify = DB::connection('tenant')->table('nu_schicht_positionen')
            ->where('einsatz_id', $id)
            ->distinct()->pluck('nu_id')->filter()->all();
        if (!empty($einsatz->nu_id) && !in_array($einsatz->nu_id, $nuIdsToNotify, true)) {
            $nuIdsToNotify[] = $einsatz->nu_id;
        }

        $nuNotifications = [];
        if (!empty($nuIdsToNotify)) {
            $nuRows = DB::connection('tenant')->table('nachunternehmer')
                ->whereIn('nu_id', $nuIdsToNotify)->get();
            foreach ($nuRows as $nu) {
                if (empty($nu->email) || !filter_var($nu->email, FILTER_VALIDATE_EMAIL)) continue;
                $nuNotifications[] = [
                    'email' => $nu->email,
                    'firmenname' => $nu->firmenname ?? 'Nachunternehmer',
                    'datum' => $einsatz->datum ?? '-',
                    'zeit' => ($einsatz->ist_start ?? '-') . ' - ' . ($einsatz->ist_ende ?? '-'),
                    'objekt' => $einsatz->objektname ?? '-',
                    'kunde' => $einsatz->kundenname ?? '-',
                ];
            }
        }

        // Collect internal workers BEFORE their assignment rows are deleted.
        // Workers of a PLANNED shift received the "Neuer Einsatzplan" email and
        // must get a cancellation — deleting the shift previously dropped their
        // rows silently (contradicting the saveAssignment removal path, which
        // does send "Einsatz storniert"). Storno-locked shifts already sent
        // their cancellation when the Bestelleingang day was removed — a
        // second notice here would be a duplicate.
        $workersToNotify = [];
        if (($einsatz->pruefung ?? null) === 'geplant' && empty($einsatz->bestell_storno_at)) {
            $workersToNotify = DB::connection('tenant')
                ->table('einsatz_mitarbeiter as em')
                ->join('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
                ->where('em.einsatz_id', $id)
                ->select('m.id', 'm.dienst_nr', 'm.vorname', 'm.name', 'm.email')
                ->get()
                ->all();
        }

        // Clean up NU positions + zuweisungen
        $posIds = DB::connection('tenant')->table('nu_schicht_positionen')
            ->where('einsatz_id', $id)->pluck('id')->toArray();
        if (!empty($posIds)) {
            DB::connection('tenant')->table('nu_position_zuweisungen')
                ->whereIn('position_id', $posIds)->delete();
        }
        DB::connection('tenant')->table('nu_schicht_positionen')
            ->where('einsatz_id', $id)->delete();
        DB::connection('tenant')->table('einsatz_nu_status')
            ->where('einsatz_id', $id)->delete();

        // End any still-running clock-ins before the assignment rows go —
        // a deleted shift must not leave live timers counting (the finished
        // zeiterfassung record stays for payroll/audit).
        WorkerAppZeitController::closeOpenShiftsForEinsatz(
            (string) $id, [], 'Einsatz gelöscht — Schicht automatisch beendet'
        );

        // Clean up internal worker assignments
        DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('einsatz_id', $id)->delete();

        // Delete the einsatz
        DB::connection('tenant')->table('einsaetze')
            ->where('einsatz_id', $id)->delete();

        // A deleted CONFIRMED shift also leaves its order: remove the day row
        // (by tag_uid, else datum + tag_nr) so the next order save cannot
        // bring the shift back, and record it in the order's Verlauf.
        if ($confirmedDelete && !empty($einsatz->bestell_id)) {
            $this->removeOrderDayOfDeletedShift($einsatz);
        }

        // Flag the originating Bestelleingang (order) + notify the back-office
        // so the deletion is visible on the order card in Bestellungseingang.
        $this->notifyOrderOfDeletedShift($einsatz);

        $tenant = TenantService::current();
        $companyName = $tenant->name ?? config('app.name', 'Clood-x');

        // Send NU cancellation emails (all affected NU companies) — queued:
        // synchronous SMTP sends in a loop blocked the delete response.
        foreach ($nuNotifications as $nuNotification) {
            try {
                Mail::to($nuNotification['email'])->queue(new NuNotificationMail(
                    blade: 'emails.nu-shift-cancelled',
                    subjectLine: "Einsatz storniert - {$nuNotification['datum']} | {$companyName}",
                    payload: [
                        'company'    => $companyName,
                        'firmenname' => $nuNotification['firmenname'],
                        'datum'      => $nuNotification['datum'],
                        'zeit'       => $nuNotification['zeit'],
                        'objekt'     => $nuNotification['objekt'],
                        'kunde'      => $nuNotification['kunde'],
                    ],
                ));
            } catch (\Exception $mailEx) {
                \Log::error('NU cancellation email failed: ' . $mailEx->getMessage());
            }
        }

        // Notify the internal workers of a planned shift: in-app + real push
        // (works with app closed) for everyone, email where available.
        $zeit = substr($einsatz->ist_start ?? '', 0, 5) . ' – ' . substr($einsatz->ist_ende ?? '', 0, 5);
        foreach ($workersToNotify as $w) {
            try {
                $datumFmt = $einsatz->datum ? \Carbon\Carbon::parse($einsatz->datum)->format('d.m.Y') : '–';
                $title = 'Einsatz storniert: ' . $datumFmt;
                $body  = (($einsatz->objektname ?? null) ?: (($einsatz->kundenname ?? null) ?: 'Einsatz')) . ' · ' . $zeit;
                DB::connection('tenant')->table('worker_notifications')->insert([
                    'mitarbeiter_id' => $w->id,
                    'dienst_nr'      => $w->dienst_nr,
                    'type'           => 'shift.storniert',
                    'title'          => $title,
                    'body'           => $body,
                    'payload'        => json_encode(['einsatz_id' => $id, 'datum' => $einsatz->datum ?? null], JSON_UNESCAPED_UNICODE),
                    'gelesen'        => 0,
                    'created_at'     => now(),
                ]);
                \App\Jobs\SendWorkerPush::dispatch(
                    (int) TenantService::current()->id, (string) $w->dienst_nr, $title, $body, 'plan'
                );
            } catch (\Throwable $e) {
                \Log::warning('Worker shift-cancelled push failed: ' . $e->getMessage());
            }

            if (!filter_var($w->email, FILTER_VALIDATE_EMAIL)) continue;
            try {
                Mail::to($w->email)->queue(new NuNotificationMail(
                    blade: 'emails.worker-shift-cancelled',
                    subjectLine: "Einsatz storniert — {$einsatz->datum} | {$companyName}",
                    payload: [
                        'company'    => $companyName,
                        'workerName' => trim(($w->vorname ?? '') . ' ' . ($w->name ?? '')),
                        'kunde'      => $einsatz->kundenname ?? '–',
                        'objekt'     => $einsatz->objektname ?? '–',
                        'datum'      => $einsatz->datum ?? '–',
                        'zeit'       => $zeit,
                    ],
                ));
            } catch (\Exception $e) {
                \Log::error("Worker shift-cancelled email failed ({$w->email}): " . $e->getMessage());
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * After an Einsatz is deleted in Dispo, flag the originating Bestelleingang
     * and push an in-app + email notification to the Bestelleingang managers so
     * the deletion surfaces on the order card in Bestellungseingang.
     *
     * Best-effort: any failure is logged and swallowed so it can never break
     * the deletion itself.
     *
     * @param  object  $einsatz  The einsatz row fetched before deletion (has bestell_id, datum, etc.).
     */
    protected function notifyOrderOfDeletedShift($einsatz): void
    {
        try {
            $bestellId = $einsatz->bestell_id ?? null;
            if (!$bestellId) return;

            // einsatz.bestell_id -> bestellungen.eingang_id -> bestelleingang
            $eingangId = DB::connection('tenant')->table('bestellungen')
                ->where('bestell_id', $bestellId)
                ->value('eingang_id');
            if (!$eingangId) return;

            // Persist a marker on the order card (Schema-guarded so tenants that
            // haven't run the migration yet simply skip the badge).
            if (Schema::connection('tenant')->hasColumn('bestelleingang', 'dispo_storno_count')) {
                $update = [
                    'dispo_storno_count' => DB::raw('dispo_storno_count + 1'),
                    'dispo_storno_at'    => now(),
                ];

                // Append this shift to the log so the clickable badge can list
                // exactly which Einsätze were deleted.
                if (Schema::connection('tenant')->hasColumn('bestelleingang', 'dispo_storno_log')) {
                    $existingLog = DB::connection('tenant')->table('bestelleingang')
                        ->where('eingang_id', $eingangId)->value('dispo_storno_log');
                    $log = json_decode($existingLog ?? '[]', true);
                    if (!is_array($log)) $log = [];
                    $log[] = [
                        'einsatz_id'  => $einsatz->einsatz_id ?? null,
                        'bestell_id'  => $bestellId,
                        'datum'       => $einsatz->datum ?? null,
                        'von'         => $einsatz->ist_start ?? null,
                        'bis'         => $einsatz->ist_ende ?? null,
                        'beschreibung'=> $einsatz->beschreibung ?? null,
                        'deleted_at'  => now()->format('Y-m-d H:i:s'),
                    ];
                    // Cap the log so a runaway loop can never bloat the row.
                    if (count($log) > 50) $log = array_slice($log, -50);
                    $update['dispo_storno_log'] = json_encode($log, JSON_UNESCAPED_UNICODE);
                }

                DB::connection('tenant')->table('bestelleingang')
                    ->where('eingang_id', $eingangId)
                    ->update($update);
            }

            $tenant = TenantService::current();
            if (!$tenant || empty($tenant->id)) return;

            $datum = !empty($einsatz->datum) ? date('d.m.Y', strtotime($einsatz->datum)) : '-';
            $kunde = $einsatz->kundenname ?? '-';
            $objekt = $einsatz->objektname ?? '-';

            $title = 'Schicht aus Dispo gelöscht';
            $body  = "Eine Schicht wurde in der Dispo gelöscht.\n"
                   . "Bestellung: {$bestellId}\n"
                   . "Datum: {$datum}\n"
                   . "Kunde: {$kunde}\n"
                   . "Objekt: {$objekt}";

            NotificationDispatcher::notifyEingangManagers(
                (int) $tenant->id,
                'dispo.einsatz.deleted',
                $title,
                $body,
                '#incoming_orders?id=' . $eingangId,
                [
                    'eingang_id' => $eingangId,
                    'bestell_id' => $bestellId,
                    'einsatz_id' => $einsatz->einsatz_id ?? null,
                    'datum'      => $einsatz->datum ?? null,
                ]
            );
        } catch (\Throwable $e) {
            \Log::warning('notifyOrderOfDeletedShift failed: ' . $e->getMessage());
        }
    }

    public function confirm(Request $request)
    {
        $data = $request->all();
        $einsatzId = $data['einsatz_id'] ?? '';
        $status = $data['hms_bestaetigt'] ?? 'ja';

        if (!$einsatzId) return response()->json(['error' => 'einsatz_id erforderlich'], 400);
        if (!BereichScope::allowsEinsatz($request->user(), $einsatzId)) {
            return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        }

        DB::connection('tenant')->table('einsaetze')
            ->where('einsatz_id', $einsatzId)
            ->update(['hms_bestaetigt' => $status]);

        return response()->json(['success' => true]);
    }

    protected function fetchAssignments(array $einsatzIds): array
    {
        if (empty($einsatzIds)) return [];

        $cols = ['em.einsatz_id', 'em.dienst_nr', 'em.sortierung', 'em.ist_std',
                 'm.vorname', 'm.name', 'm.foto', 'm.bereich', 'm.aktiv'];
        if (Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'aufgabe')) {
            $cols[] = 'em.aufgabe';
        }
        if (Schema::connection('tenant')->hasTable('zeiterfassung')) {
            $cols[] = DB::raw('(SELECT z.clock_in FROM zeiterfassung z WHERE z.einsatz_id = em.einsatz_id AND z.dienst_nr = em.dienst_nr ORDER BY z.clock_in DESC LIMIT 1) AS app_clock_in');
            $cols[] = DB::raw('(SELECT z.clock_out FROM zeiterfassung z WHERE z.einsatz_id = em.einsatz_id AND z.dienst_nr = em.dienst_nr ORDER BY z.clock_in DESC LIMIT 1) AS app_clock_out');
            $cols[] = DB::raw('(SELECT z.status FROM zeiterfassung z WHERE z.einsatz_id = em.einsatz_id AND z.dienst_nr = em.dienst_nr ORDER BY z.clock_in DESC LIMIT 1) AS app_clock_status');
            if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'verlaengert_bis')) {
                $cols[] = DB::raw('(SELECT z.verlaengert_bis FROM zeiterfassung z WHERE z.einsatz_id = em.einsatz_id AND z.dienst_nr = em.dienst_nr ORDER BY z.clock_in DESC LIMIT 1) AS app_extended_until');
                $cols[] = DB::raw('(SELECT z.verlaengerungen FROM zeiterfassung z WHERE z.einsatz_id = em.einsatz_id AND z.dienst_nr = em.dienst_nr ORDER BY z.clock_in DESC LIMIT 1) AS app_extensions');
            }
        }

        $rows = DB::connection('tenant')
            ->table('einsatz_mitarbeiter as em')
            ->join('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
            ->whereIn('em.einsatz_id', $einsatzIds)
            ->select($cols)
            ->orderBy('em.einsatz_id')
            ->orderBy('em.sortierung')
            ->orderBy('m.vorname')
            ->orderBy('m.name')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->einsatz_id][] = $row;
        }
        return $grouped;
    }

    /**
     * Compute and attach planning warnings to each worker entry.
     *
     * Warning types (consumed by tasks.js plannerToggleWarningDetails):
     *   - 'assignment' → worker is assigned to another einsatz overlapping in real time (overnight-aware)
     *   - 'absence'    → worker has a registered Abwesenheit covering any part of the einsatz time
     *   - 'hours'      → worker would exceed configured weekly arbeitsstunden when added to this einsatz
     *
     * Implementation notes:
     *   - All time math uses full DateTime (date + time) to correctly handle overnight shifts
     *     (e.g. einsatz on 11.05 19:00 → 07:00 ends on 12.05 07:00).
     *   - Conflict scan covers [datum-1 .. datum+1] so neighbouring-day shifts that bleed into
     *     each other are caught.
     *   - 3 bulk queries (no N+1) keyed by dienst_nr, then merged in PHP.
     *   - Self-referential conflicts excluded (einsatz_id != current).
     *   - Rejected einsaetze (hms_bestaetigt='nein') excluded from conflict + hours sums.
     *   - Falls back to plan_start/plan_ende from bestelleingang_tage if ist_start/ist_ende unset.
     */
    protected function attachWorkerWarnings($workers, $task)
    {
        if (!$task || empty($task->datum) || $workers->isEmpty()) {
            return $workers->map(function ($w) { $w->warnings = []; return $w; });
        }

        $datum     = $task->datum;
        $einsatzId = $task->einsatz_id;
        $istStart  = $task->ist_start ?: ($task->plan_start ?? null);
        $istEnde   = $task->ist_ende  ?: ($task->plan_ende  ?? null);
        $dienstNrs = $workers->pluck('dienst_nr')->all();

        if (empty($dienstNrs)) {
            return $workers->map(function ($w) { $w->warnings = []; return $w; });
        }

        // Compute the current einsatz as a real (date+time, date+time) interval.
        // For overnight shifts (end <= start) we push the end into the next day.
        $thisStart = $thisEnd = null;
        if ($istStart && $istEnde) {
            $thisStart = $this->makeShiftDateTime($datum, $istStart);
            $thisEnd   = $this->makeShiftDateTime($datum, $istEnde);
            if ($thisStart && $thisEnd && $thisEnd <= $thisStart) {
                $thisEnd->modify('+1 day');
            }
        }
        $thisIsOvernight = ($thisStart && $thisEnd && $thisStart->format('Y-m-d') !== $thisEnd->format('Y-m-d'));

        // ── 1) Time conflicts (overnight-aware, scans neighbouring days) ──
        $conflictsByWorker  = [];
        $multiShiftByWorker = []; // soft notice: another shift on the SAME day, but non-overlapping
        // ArbZG (display only, per-tenant switch + limits): §5 rest between
        // shifts, §3 work per day.
        $arbzg              = \App\Support\TenantProfile::arbzg(\App\Services\TenantService::current());
        $restByWorker       = [];
        $dayWorkByWorker    = []; // other shifts on the same day: hours worked (minus break)
        if ($thisStart && $thisEnd) {
            try {
                $dt   = new \DateTime($datum);
                $prev = (clone $dt)->modify('-1 day')->format('Y-m-d');
                $next = (clone $dt)->modify('+1 day')->format('Y-m-d');
            } catch (\Exception $e) {
                $prev = $next = $datum;
            }

            $conflictRows = DB::connection('tenant')
                ->table('einsatz_mitarbeiter as em')
                ->join('einsaetze as e', 'em.einsatz_id', '=', 'e.einsatz_id')
                ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
                ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                ->whereIn('em.dienst_nr', $dienstNrs)
                ->whereBetween('e.datum', [$prev, $next])
                ->where('e.einsatz_id', '!=', $einsatzId)
                ->where('e.hms_bestaetigt', '!=', 'nein')
                ->select(
                    'em.dienst_nr', 'e.einsatz_id', 'e.datum',
                    DB::raw('COALESCE(em.ist_start, e.ist_start) AS conf_start'),
                    DB::raw('COALESCE(em.ist_ende,  e.ist_ende)  AS conf_end'),
                    DB::raw('COALESCE(em.pause_minuten, e.pause_minuten, 0) AS conf_pause'),
                    'k.kundenname', 'o.objektname'
                )
                ->get();

            foreach ($conflictRows as $row) {
                if (!$row->conf_start || !$row->conf_end) continue;
                $oStart = $this->makeShiftDateTime($row->datum, $row->conf_start);
                $oEnd   = $this->makeShiftDateTime($row->datum, $row->conf_end);
                if (!$oStart || !$oEnd) continue;
                if ($oEnd <= $oStart) $oEnd->modify('+1 day');

                // Half-open interval overlap: [thisStart,thisEnd) ∩ [oStart,oEnd) ≠ ∅
                if ($thisStart < $oEnd && $oStart < $thisEnd) {
                    $conflictsByWorker[$row->dienst_nr][] = $row;
                } else {
                    if ($row->datum === $datum) {
                        // Same day, but no time overlap → soft "multi-shift" notice
                        // (helps planners notice that the worker already has another shift today)
                        $multiShiftByWorker[$row->dienst_nr][] = $row;
                        $dayWorkByWorker[$row->dienst_nr] = ($dayWorkByWorker[$row->dienst_nr] ?? 0)
                            + max(0, ($oEnd->getTimestamp() - $oStart->getTimestamp()) / 3600 - ((int) $row->conf_pause) / 60);
                    }
                    $gapH = ($oEnd <= $thisStart ? $thisStart->getTimestamp() - $oEnd->getTimestamp()
                                                 : $oStart->getTimestamp() - $thisEnd->getTimestamp()) / 3600;
                    if ($arbzg['aktiv'] && $gapH >= 0 && $gapH < $arbzg['ruhezeit']) {
                        $row->gap_h = $gapH;
                        $restByWorker[$row->dienst_nr][] = $row;
                    }
                }
            }
        }

        // ── 2) Absences covering the einsatz interval (overnight-aware) ──
        // If the einsatz spans 2 days, include absences for either day.
        $absDates = [$datum];
        if ($thisIsOvernight) {
            $absDates[] = $thisEnd->format('Y-m-d');
        }

        $absRows = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->whereIn('dienst_nr', $dienstNrs)
            ->where('startdatum', '<=', max($absDates))
            ->where(function ($q) use ($absDates) {
                $q->whereNull('enddatum')->orWhere('enddatum', '>=', min($absDates));
            })
            ->get();

        $absencesByWorker = [];
        foreach ($absRows as $abs) {
            // For partial-day absences, only warn if the einsatz time overlaps the absence window
            if (!$abs->ganzer_tag && $abs->startzeit && $abs->endzeit && $thisStart && $thisEnd) {
                $absStartDay = $abs->startdatum;
                $absEndDay   = $abs->enddatum ?: $absStartDay;
                // Build absence interval on whichever day intersects the einsatz
                $checkDay = in_array($absStartDay, $absDates, true) ? $absStartDay
                          : (in_array($absEndDay, $absDates, true) ? $absEndDay : $absStartDay);
                $aStart = $this->makeShiftDateTime($checkDay, $abs->startzeit);
                $aEnd   = $this->makeShiftDateTime($checkDay, $abs->endzeit);
                if ($aStart && $aEnd && $aEnd <= $aStart) $aEnd->modify('+1 day');
                if (!$aStart || !$aEnd || !($thisStart < $aEnd && $aStart < $thisEnd)) continue;
            }
            $absencesByWorker[$abs->dienst_nr][] = $abs;
        }

        // ── 3) Weekly hours overload ──────────────────────────────────────
        // Compute Mon-Sun week boundaries for the einsatz date
        try {
            $dt = new \DateTime($datum);
            $dayN = (int) $dt->format('N'); // 1=Mon..7=Sun
            $weekStart = (clone $dt)->modify('-' . ($dayN - 1) . ' days')->format('Y-m-d');
            $weekEnd   = (clone $dt)->modify('+' . (7 - $dayN) . ' days')->format('Y-m-d');
        } catch (\Exception $e) {
            $weekStart = $weekEnd = $datum;
        }

        $hoursRows = DB::connection('tenant')
            ->table('einsatz_mitarbeiter as em')
            ->join('einsaetze as e', 'em.einsatz_id', '=', 'e.einsatz_id')
            ->whereIn('em.dienst_nr', $dienstNrs)
            ->whereBetween('e.datum', [$weekStart, $weekEnd])
            ->where('e.hms_bestaetigt', '!=', 'nein')
            ->where('e.einsatz_id', '!=', $einsatzId)
            ->groupBy('em.dienst_nr')
            ->selectRaw('em.dienst_nr, SUM(COALESCE(em.ist_std, e.ist_std_menge, 0)) AS total_std')
            ->get();

        $hoursByWorker = [];
        foreach ($hoursRows as $r) {
            $hoursByWorker[$r->dienst_nr] = (float) $r->total_std;
        }

        // Estimate hours of the current einsatz (for projection)
        $thisHours = 0.0;
        if (!empty($task->ist_std_menge) && is_numeric($task->ist_std_menge)) {
            $thisHours = (float) $task->ist_std_menge;
        } elseif ($istStart && $istEnde) {
            $thisHours = $this->durationHours($istStart, $istEnde);
        }

        // ── Compose warnings per worker ───────────────────────────────────
        // Work hours of THIS shift (span minus break) for the 10-h day check.
        $thisWork = ($thisStart && $thisEnd)
            ? max(0, ($thisEnd->getTimestamp() - $thisStart->getTimestamp()) / 3600 - ((int) ($task->pause_minuten ?? 0)) / 60)
            : 0.0;

        return $workers->map(function ($w) use ($conflictsByWorker, $multiShiftByWorker, $absencesByWorker, $hoursByWorker, $weekStart, $weekEnd, $thisHours, $datum, $restByWorker, $dayWorkByWorker, $thisWork, $arbzg) {
            $warnings = [];

            // §5 ArbZG — less than 11 h rest between this and a neighbouring shift.
            foreach ($restByWorker[$w->dienst_nr] ?? [] as $c) {
                $warnings[] = [
                    'type'  => 'rest',
                    'label' => sprintf('Ruhezeit unter %s Std (%s Std)', number_format($arbzg['ruhezeit'], 0, ',', ''), number_format($c->gap_h, 1, ',', '')),
                    'details' => [
                        'startdatum'   => $c->datum,
                        'enddatum'     => $c->datum,
                        'startzeit'    => $c->conf_start,
                        'endzeit'      => $c->conf_end,
                        'beschreibung' => trim('Einsatz ' . $c->einsatz_id . ' · ' . ($c->kundenname ?? '') . ' · ' . ($c->objektname ?? ''), ' ·')
                            . sprintf(' — Mindestruhezeit zwischen zwei Schichten: %s Std (§ 5 ArbZG).', number_format($arbzg['ruhezeit'], 1, ',', '')),
                    ],
                ];
            }

            // §3 ArbZG — more than 10 h work on this day (all shifts, breaks deducted).
            $dayTotal = ($dayWorkByWorker[$w->dienst_nr] ?? 0) + $thisWork;
            if ($arbzg['aktiv'] && $thisWork > 0 && $dayTotal > $arbzg['max_tag']) {
                $warnings[] = [
                    'type'  => 'daily',
                    'label' => 'Tageshöchstarbeitszeit überschritten',
                    'details' => [
                        'startdatum'   => $datum,
                        'enddatum'     => $datum,
                        'startzeit'    => null,
                        'endzeit'      => null,
                        'beschreibung' => sprintf('%s Std Arbeitszeit an diesem Tag (ohne Pausen) — eingestellte Höchstgrenze: %s Std (§ 3 ArbZG).', number_format($dayTotal, 1, ',', ''), number_format($arbzg['max_tag'], 1, ',', '')),
                    ],
                ];
            }

            // Time conflicts (overnight-aware; include datum so the UI can show
            // shifts that start on a neighbouring day, e.g. a night shift on the previous day).
            foreach ($conflictsByWorker[$w->dienst_nr] ?? [] as $c) {
                $isOtherDay = ($c->datum !== $datum);
                $warnings[] = [
                    'type'  => 'assignment',
                    'label' => $isOtherDay
                        ? 'Zeitkonflikt: ' . $c->einsatz_id . ' (' . $c->datum . ')'
                        : 'Zeitkonflikt: ' . $c->einsatz_id,
                    'details' => [
                        'kundenname' => $c->kundenname ?? '',
                        'objektname' => $c->objektname ?? '',
                        'datum'      => $c->datum,
                        'start'      => $c->conf_start,
                        'end'        => $c->conf_end,
                    ],
                ];
            }

            // Multi-shift soft notices: another shift on the SAME day without time overlap.
            // (info-level; helps the planner notice double-booking on the calendar even if
            // the times themselves do not collide.)
            foreach ($multiShiftByWorker[$w->dienst_nr] ?? [] as $c) {
                $warnings[] = [
                    'type'  => 'multi_shift',
                    'label' => 'Weiterer Einsatz am selben Tag: ' . $c->einsatz_id,
                    'details' => [
                        'kundenname' => $c->kundenname ?? '',
                        'objektname' => $c->objektname ?? '',
                        'datum'      => $c->datum,
                        'start'      => $c->conf_start,
                        'end'        => $c->conf_end,
                    ],
                ];
            }

            // Absences
            foreach ($absencesByWorker[$w->dienst_nr] ?? [] as $a) {
                $warnings[] = [
                    'type'  => 'absence',
                    'label' => ($a->typ ?? 'Abwesenheit') . ($a->bezahlt ? '' : ' (unbezahlt)'),
                    'details' => [
                        'startdatum'   => $a->startdatum,
                        'enddatum'     => $a->enddatum,
                        'startzeit'    => $a->ganzer_tag ? null : $a->startzeit,
                        'endzeit'      => $a->ganzer_tag ? null : $a->endzeit,
                        'beschreibung' => $a->beschreibung,
                    ],
                ];
            }

            // Weekly hours overload (only if worker has wöchentlich limit configured)
            if (!empty($w->arbeitsstunden) && (float) $w->arbeitsstunden > 0
                && ($w->arbeitsstunden_typ ?? null) === 'wöchentlich') {
                $existing  = $hoursByWorker[$w->dienst_nr] ?? 0.0;
                $projected = $existing + $thisHours;
                if ($projected > (float) $w->arbeitsstunden) {
                    $warnings[] = [
                        'type'  => 'hours',
                        'label' => 'Wochenstunden überschritten',
                        'details' => [
                            'startdatum'   => $weekStart,
                            'enddatum'     => $weekEnd,
                            'startzeit'    => null,
                            'endzeit'      => null,
                            'beschreibung' => sprintf(
                                'Bisher %.1f Std + dieser Einsatz %.1f Std = %.1f Std (Limit: %.1f Std/Woche)',
                                $existing, $thisHours, $projected, (float) $w->arbeitsstunden
                            ),
                        ],
                    ];
                }
            }

            $w->warnings = $warnings;
            return $w;
        });
    }

    private function validateOperationalFields(array $data, bool $creating): array
    {
        $errors = [];
        if ($creating) {
            if (empty($data['bestell_id'])) $errors[] = 'Bestellung ist erforderlich';
            if (empty($data['datum'])) $errors[] = 'Datum ist erforderlich';
        }
        if (array_key_exists('datum', $data) && $data['datum'] !== null && $data['datum'] !== ''
            && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['datum']) || !strtotime((string) $data['datum']))) {
            $errors[] = 'Ungültiges Datum';
        }
        foreach (['ist_start', 'ist_ende', 'kunden_start', 'kunden_ende'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== ''
                && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $data[$field])) {
                $errors[] = 'Ungültige Uhrzeit';
            }
        }
        foreach (['ist_std_menge' => [0, 24, 'Ist-Stunden'], 'ist_betrag' => [0, 99999999.99, 'Ist-Betrag'],
                  'pause_minuten' => [0, 1440, 'MA-Pause'], 'gesetzl_pause' => [0, 1440, 'Kunden-Pause'],
                  'kunden_std' => [0, 24, 'Kunden-Stunden'], 'kunden_pause' => [0, 1440, 'Pause (Kunde)']] as $field => [$min, $max, $label]) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') continue;
            if (!is_numeric($data[$field]) || (float) $data[$field] < $min || (float) $data[$field] > $max) {
                $errors[] = 'Ungültige ' . $label;
            }
        }
        if (!empty($data['hms_bestaetigt']) && !in_array($data['hms_bestaetigt'], ['ja', 'ausstehend', 'nein'], true)) {
            $errors[] = 'Ungültiger Bestätigungsstatus';
        }
        return $errors;
    }

    /**
     * Check whether two time intervals overlap. Times can be HH:MM or HH:MM:SS.
     * Handles overnight wrap (end < start → +24h).
     */
    protected function timesOverlap(?string $s1, ?string $e1, ?string $s2, ?string $e2): bool
    {
        if (!$s1 || !$e1 || !$s2 || !$e2) return false;
        $toMin = function (string $t): int {
            $h = (int) substr($t, 0, 2);
            $m = strlen($t) >= 5 ? (int) substr($t, 3, 2) : 0;
            return $h * 60 + $m;
        };
        $a1 = $toMin($s1); $b1 = $toMin($e1); if ($b1 <= $a1) $b1 += 1440;
        $a2 = $toMin($s2); $b2 = $toMin($e2); if ($b2 <= $a2) $b2 += 1440;
        return max($a1, $a2) < min($b1, $b2);
    }

    /**
     * Compute interval duration in hours (handles overnight wrap).
     */
    protected function durationHours(string $start, string $end): float
    {
        $toMin = function (string $t): int {
            $h = (int) substr($t, 0, 2);
            $m = strlen($t) >= 5 ? (int) substr($t, 3, 2) : 0;
            return $h * 60 + $m;
        };
        $diff = $toMin($end) - $toMin($start);
        if ($diff < 0) $diff += 1440;
        return $diff / 60.0;
    }

    /**
     * Build a DateTime from a date string ('Y-m-d') and a time ('HH:MM' or 'HH:MM:SS').
     * Returns null on invalid input.
     */
    protected function makeShiftDateTime(?string $date, ?string $time): ?\DateTime
    {
        if (!$date || !$time) return null;
        $date = substr($date, 0, 10);
        $time = substr($time, 0, 5); // normalize HH:MM:SS → HH:MM
        try {
            return new \DateTime($date . ' ' . $time . ':00');
        } catch (\Exception $e) {
            return null;
        }
    }
}
