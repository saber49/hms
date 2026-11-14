<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\IdGenerator;
use App\Helpers\NotificationDispatcher;
use App\Helpers\SchichtartCalculator;
use App\Services\TenantService;
use App\Support\BereichScope;
use App\Support\StaffedShifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class IncomingOrderController extends Controller
{
    /**
     * Per-request memoized artikel lookup used by Schichtart classification.
     * Instance properties are scoped to a single request (controllers are
     * resolved per-request even under Octane), so this is safe across
     * tenants — unlike the previous `static $cache` which leaked.
     *
     * @var array<string, object|null>
     */
    protected array $artikelClassificationCache = [];

    protected ?bool $tagUidCols = null;

    protected array $colCache = [];

    /** Crew lookup preloaded for the Einsätze of the current save (null = query per shift). */
    protected ?array $crewSet = null;

    public function index(Request $request)
    {
        if ($request->has('id')) {
            return $this->show($request->id, $request);
        }

        $query = DB::connection('tenant')
            ->table('bestelleingang as be')
            ->leftJoin('kunden as k', 'be.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'be.obj_id', '=', 'o.obj_id')
            ->leftJoin('artikel as a', 'be.artikel_id', '=', 'a.artikel_id')
            ->leftJoin('nachunternehmer as n', 'be.nu_id', '=', 'n.nu_id')
            ->select('be.*', 'k.kundenname', 'k.bereich as kunden_bereich',
                     'o.objektname', 'o.obj_nr_intern',
                     'a.artikel_name', 'a.bereich as artikel_bereich',
                     'a.modell as artikel_modell',
                     'a.preis_kunde as artikel_preis_kunde', 'a.nu_preis as artikel_nu_preis',
                     'n.firmenname as nu_name',
                     DB::raw("(SELECT b.bestell_id FROM bestellungen b WHERE b.eingang_id = be.eingang_id ORDER BY b.id LIMIT 1) as bestell_id"));

        // Multi-service list display: resolve all selected service names.
        // Guarded — tenants without the migration keep the single-name join.
        if ($this->col('bestelleingang', 'artikel_ids')) {
            $query->addSelect(DB::raw(
                "(SELECT GROUP_CONCAT(a2.artikel_name SEPARATOR ' · ') FROM artikel a2
                  WHERE JSON_CONTAINS(be.artikel_ids, JSON_QUOTE(a2.artikel_id))) as artikel_namen"
            ));
        }

        $paged = $request->boolean('paged');
        $applyFilters = function ($q) use ($request) {
            if ($request->filled('kunden_id')) $q->where('be.kunden_id', $request->kunden_id);
            if ($request->filled('monat') && $request->filled('jahr')) {
                $m = (int) $request->monat;
                $y = (int) $request->jahr;
                if ($m >= 1 && $m <= 12 && $y >= 2000 && $y <= 2100) {
                    [$sql, $bind] = $this->monthMatchSql($m, $y);
                    $q->whereRaw($sql, $bind);
                } else {
                    $q->whereRaw('1 = 0');
                }
            }
            if ($request->filled('status') && $request->status !== 'alle') $q->where('be.status', $request->status);
        };
        if (!$paged) $applyFilters($query);

        // Per-user Bereich restriction (article Bereich, customer fallback).
        // Multi-service orders: visible when the PRIMARY artikel OR any
        // artikel of the stored artikel_ids list derives into scope.
        $allowedBe = BereichScope::for($request->user());
        if ($allowedBe !== null) {
            $mainExpr = "COALESCE(NULLIF(TRIM(a.bereich), ''), NULLIF(TRIM(k.bereich), ''))";
            if (empty($allowedBe)) {
                $query->whereRaw('1 = 0');
            } else {
                [$mainSql, $mainP] = BereichScope::inList($allowedBe, $mainExpr);
                if ($this->col('bestelleingang', 'artikel_ids')) {
                    $subExpr = "COALESCE(NULLIF(TRIM(_ax2.bereich), ''), NULLIF(TRIM(k.bereich), ''))";
                    [$subSql, $subP] = BereichScope::inList($allowedBe, $subExpr);
                    $query->whereRaw(
                        "($mainSql OR EXISTS (SELECT 1 FROM artikel _ax2
                            WHERE JSON_CONTAINS(be.artikel_ids, JSON_QUOTE(_ax2.artikel_id))
                              AND $subSql))",
                        array_merge($mainP, $subP)
                    );
                } else {
                    $query->whereRaw($mainSql, $mainP);
                }
            }
        }

        if ($paged) {
            return response()->json($this->indexPage($query, $request, $applyFilters));
        }

        // Legacy (non-paged callers): newest 500 + offset for older rows.
        $limit = max(1, min(intval($request->query('limit', 500)), 1000));
        $offset = max(0, intval($request->query('offset', 0)));
        return response()->json($query->orderByDesc('be.id')->offset($offset)->limit($limit)->get());
    }

    /**
     * One page of the list (`paged=1`). KPI counts and the month list cover
     * every order the user may see (Bereich scope only, never the current
     * filter); status / month / search narrow the rows and `total`.
     */
    protected function indexPage($query, Request $request, callable $applyFilters): array
    {
        $perPage = max(10, min((int) $request->query('per_page', 100), 500));
        $now = now('Europe/Berlin');
        $bare = fn ($q) => $q->cloneWithout(['columns'])->cloneWithoutBindings(['select']);

        [$curSql, $curBind] = $this->monthMatchSql($now->month, $now->year);
        $c = $bare($query)->selectRaw(
            "COUNT(*) AS alle,
             COALESCE(SUM(be.status = 'unbearbeitet'), 0) AS unbearbeitet,
             COALESCE(SUM(be.status = 'bearbeitet'), 0) AS bearbeitet,
             COALESCE(SUM(be.status = 'abgeschlossen'), 0) AS abgeschlossen,
             COALESCE(SUM(be.status = 'abgesagt'), 0) AS abgesagt,
             COALESCE(SUM($curSql), 0) AS diesen_monat",
            $curBind
        )->first();
        $counts = [];
        foreach (['alle', 'unbearbeitet', 'bearbeitet', 'abgeschlossen', 'abgesagt', 'diesen_monat'] as $k) {
            $counts[$k] = (int) ($c->$k ?? 0);
        }

        $months = $bare($query)->select('be.jahr', 'be.monat')->distinct()->get()
            ->filter(fn ($m) => (int) $m->jahr > 0 && (int) $m->monat >= 1 && (int) $m->monat <= 12)
            ->map(fn ($m) => sprintf('%04d-%02d', (int) $m->jahr, (int) $m->monat))
            ->merge(
                $bare($query)->join('bestelleingang_tage as bt_m', 'bt_m.eingang_id', '=', 'be.eingang_id')
                    ->whereNotNull('bt_m.datum')
                    ->selectRaw("DISTINCT DATE_FORMAT(bt_m.datum, '%Y-%m') AS ym")
                    ->pluck('ym')
            )
            ->filter(fn ($ym) => is_string($ym) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym))
            ->unique()->sortDesc()->values()->all();

        $filtered = clone $query;
        $applyFilters($filtered);
        $tokens = array_slice(array_filter(preg_split('/\s+/u', trim(mb_substr((string) $request->query('q', ''), 0, 100))), 'strlen'), 0, 6);
        foreach ($tokens as $tok) {
            $like = '%' . addcslashes($tok, '%_\\') . '%';
            $filtered->where(function ($w) use ($like) {
                foreach (['be.eingang_id', 'be.kunden_id', 'k.kundenname', 'be.obj_id', 'o.objektname',
                          'o.obj_nr_intern', 'be.bemerkung', 'k.bereich', 'be.artikel_id', 'a.artikel_name'] as $col) {
                    $w->orWhere($col, 'like', $like);
                }
                $w->orWhereExists(fn ($s) => $s->selectRaw('1')->from('bestellungen as bs')
                    ->whereColumn('bs.eingang_id', 'be.eingang_id')->where('bs.bestell_id', 'like', $like));
            });
        }

        $total = $bare($filtered)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $request->query('page', 1)), $lastPage);

        return [
            'data' => $filtered->orderByDesc('be.id')->offset(($page - 1) * $perPage)->limit($perPage)->get(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'counts' => $counts,
            'months' => $months,
        ];
    }

    public function show(string $id, ?\Illuminate\Http\Request $request = null)
    {
        $entry = DB::connection('tenant')
            ->table('bestelleingang as be')
            ->leftJoin('kunden as k', 'be.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'be.obj_id', '=', 'o.obj_id')
            ->leftJoin('artikel as a', 'be.artikel_id', '=', 'a.artikel_id')
            ->leftJoin('nachunternehmer as n', 'be.nu_id', '=', 'n.nu_id')
            ->select('be.*', 'k.kundenname', 'k.bereich as kunden_bereich',
                     'o.objektname',
                     'a.artikel_name', 'a.bereich as artikel_bereich',
                     'a.modell as artikel_modell',
                     'a.preis_kunde as artikel_preis_kunde', 'a.nu_preis as artikel_nu_preis',
                     'n.firmenname as nu_name')
            ->where('be.eingang_id', $id)->first();

        if (!$entry) return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);

        // Multi-service orders: decode the selected-services list (JSON) and
        // resolve names/prices for each so edit + detail views can restore
        // every service — not only the primary be.artikel_id join.
        $artikelIds = $this->normalizeArtikelIds((array) $entry);
        if (empty($artikelIds) && !empty($entry->artikel_id)) {
            $artikelIds = [$entry->artikel_id];
        }
        if (!empty($artikelIds)) {
            $entry->artikel_liste = DB::connection('tenant')->table('artikel')
                ->whereIn('artikel_id', $artikelIds)
                ->get(['artikel_id', 'artikel_name', 'bereich', 'modell', 'preis_kunde', 'nu_preis']);
        } else {
            $entry->artikel_liste = [];
        }

        // Enforce the per-user Bereich restriction on direct access too.
        // Multi-service semantics: allowed when ANY selected artikel (own
        // Bereich, kunde fallback) OR the bare kunde Bereich is in scope —
        // identical to the index() predicate.
        $actor = $request ? $request->user() : auth()->user();
        $bereiche = [];
        $bereiche[] = trim((string) ($entry->artikel_bereich ?? '')) !== ''
            ? $entry->artikel_bereich
            : ($entry->kunden_bereich ?? null);
        foreach ($entry->artikel_liste as $art) {
            $bereiche[] = trim((string) ($art->bereich ?? '')) !== ''
                ? $art->bereich
                : ($entry->kunden_bereich ?? null);
        }
        $visible = false;
        foreach ($bereiche as $b) {
            if (BereichScope::userAllows($actor, $b)) { $visible = true; break; }
        }
        if (!$visible) {
            return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        }
        $entry->artikel_ids = $artikelIds;

        $days = DB::connection('tenant')->table('bestelleingang_tage')
            ->where('eingang_id', $id)->orderBy('tag_nr')->get();

        $entry->tage = $days;
        // Per-service pricing/details blob (modell/kundenpreis/bemerkung keyed
        // by artikel_id) — passthrough for the wizard's Step 4 panels.
        $rawPreise = $entry->artikel_preise ?? null;
        $entry->artikel_preise = [];
        if ($this->col('bestelleingang', 'artikel_preise')
            && !empty($rawPreise)) {
            $decoded = json_decode((string) $rawPreise, true);
            $entry->artikel_preise = is_array($decoded) ? $decoded : [];
        }

        // Resolve actor names from the central DB. Cross-DB join is impractical
        // (different connections), so we do a small in-memory lookup. created_by /
        // updated_by are nullable for legacy rows pre-migration.
        $actorIds = array_values(array_unique(array_filter([
            $entry->created_by ?? null,
            $entry->updated_by ?? null,
        ])));
        $actors = [];
        if (!empty($actorIds)) {
            // Defense-in-depth: scope the lookup to the active tenant so
            // a stale/corrupt created_by id from another tenant cannot
            // disclose names cross-tenant. Under normal flow this is a
            // no-op (created_by is always written from auth()->id() of
            // the same tenant), but we never trust upstream invariants.
            // `tenant.boundary` middleware guarantees auth()->user()
            // matches the active tenant connection.
            $actorTenantId = $request && $request->user()
                ? (int) $request->user()->tenant_id
                : (auth()->user() ? (int) auth()->user()->tenant_id : 0);

            $rows = DB::connection('mysql')->table('central_users')
                ->where('tenant_id', $actorTenantId)
                ->whereIn('id', $actorIds)
                ->whereRaw("name NOT LIKE 'deleted\\_%'")
                ->select('id', 'full_name', 'name')
                ->get();
            foreach ($rows as $r) {
                $actors[$r->id] = !empty($r->full_name) ? $r->full_name : $r->name;
            }
        }
        $entry->created_by_name = $entry->created_by ? ($actors[$entry->created_by] ?? null) : null;
        $entry->updated_by_name = $entry->updated_by ? ($actors[$entry->updated_by] ?? null) : null;

        // Change log for the "Änderungshistorie" block in the view dialog —
        // newest first; empty while the tenant migration is pending.
        $entry->history = [];
        if ($this->col('bestelleingang_log', 'id')) {
            $entry->history = DB::connection('tenant')->table('bestelleingang_log')
                ->where('eingang_id', $id)->orderByDesc('id')->limit(30)->get()
                ->map(fn ($h) => [
                    'datum' => (string) $h->created_at,
                    'user' => $h->user_name,
                    'aktion' => $h->aktion,
                    'aenderungen' => json_decode((string) ($h->aenderungen ?? ''), true) ?: [],
                ])->all();
        }

        // Check if any downstream einsätze are planned/confirmed (edit-locked)
        $bestellung = DB::connection('tenant')->table('bestellungen')
            ->where('eingang_id', $id)->first();
        $entry->has_planned_tasks = false;
        $entry->bestell_id = $bestellung->bestell_id ?? null;
        if ($bestellung) {
            // Legacy orders (created before the header `ort` column) carry
            // their Treffpunkt only on the generated Einsätze. Surface it so
            // the copy / edit wizard can pre-fill the (now mandatory) field.
            if (trim((string) ($entry->ort ?? '')) === ''
                && $this->col('einsaetze', 'ort')) {
                $entry->einsatz_ort = DB::connection('tenant')->table('einsaetze')
                    ->where('bestell_id', $bestellung->bestell_id)
                    ->whereNotNull('ort')->where('ort', '<>', '')
                    ->orderBy('datum')->value('ort');
            }

            // Storno-locked shifts (day previously removed from the order)
            // do not lock editing: re-adding their day is the reactivation
            // path that clears bestell_storno_at again.
            $hasStornoColShow = $this->col('einsaetze', 'bestell_storno_at');
            $liveEinsaetze = fn () => DB::connection('tenant')->table('einsaetze')
                ->where('bestell_id', $bestellung->bestell_id)
                ->where(function ($q) {
                    $q->where('hms_bestaetigt', 'ja')
                      ->orWhere('pruefung', 'geplant');
                })
                ->when($hasStornoColShow, fn ($q) => $q->whereNull('bestell_storno_at'));

            $entry->has_planned_tasks = $liveEinsaetze()->exists();
            // Confirmed shifts dominate the lock dialog: when at least one
            // hms_bestaetigt='ja' Einsatz exists the order is final — there is
            // no self-service "retract the plan" path like for geplant shifts,
            // so the frontend shows a dedicated "confirmed in Dispo" message
            // instead of the unlock instructions.
            $entry->has_confirmed_tasks = $liveEinsaetze()
                ->where('hms_bestaetigt', 'ja')->exists();

            // Per-day status for the edit wizard: which days have planned
            // (warning) or confirmed (blocked) Einsätze? The frontend uses
            // this to show ⚠ / 🚫 badges on individual days before removal.
            // Keyed by datum+artikel so the same date under two different
            // services reports each service's real Dispo state; a datum-only
            // fallback covers rows/einsaetze without an artikel tag.
            $dayRows = $liveEinsaetze()
                ->selectRaw('datum, artikel_id, ist_start, ist_ende, MAX(CASE WHEN hms_bestaetigt = \'ja\' THEN 1 ELSE 0 END) AS is_confirmed, MAX(CASE WHEN hms_bestaetigt <> \'ja\' AND pruefung = \'geplant\' AND (EXISTS (SELECT 1 FROM einsatz_mitarbeiter em WHERE em.einsatz_id = einsaetze.einsatz_id) OR EXISTS (SELECT 1 FROM nu_schicht_positionen np JOIN nu_position_zuweisungen nz ON nz.position_id = np.id WHERE np.einsatz_id = einsaetze.einsatz_id)) THEN 1 ELSE 0 END) AS is_planned')
                ->groupBy('datum', 'artikel_id', 'ist_start', 'ist_ende')
                ->get();
            // Keyed strictly by datum+artikel — a day belongs to exactly ONE
            // service's shift. A datum-only aggregate would bleed the status
            // of artikel A's planned shift onto artikel B's unplanned day on
            // the same date (badge + edit-lock on the wrong row). With two
            // shifts on one date (06–18 + 18–06) the start/end time picks the
            // row; the datum+artikel status is only used when the date has a
            // single row of that service.
            $hhmm = static fn ($v) => ($v !== null && preg_match('/(\d{1,2}):(\d{2})/', (string) $v, $m))
                ? str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] : '';
            $merge = static fn (?string $a, string $b) => $a === 'confirmed' ? 'confirmed' : $b;
            $statusByDateArt = [];
            $statusByTime = [];
            foreach ($dayRows as $r) {
                $d = substr((string) $r->datum, 0, 10);
                $st = $r->is_confirmed ? 'confirmed' : ($r->is_planned ? 'planned' : null);
                if (!$st) continue;
                $key = $d . '|' . (string) ($r->artikel_id ?? '');
                $statusByDateArt[$key] = $merge($statusByDateArt[$key] ?? null, $st);
                $tk = $key . '|' . $hhmm($r->ist_start) . '|' . $hhmm($r->ist_ende);
                $statusByTime[$tk] = $merge($statusByTime[$tk] ?? null, $st);
            }

            // Exact per-shift status through the stable key: each day row
            // gets the state of ITS Einsatz (tag_uid). The datum/time keys
            // below cannot tell two identical shifts on one date apart — a
            // confirmed one locked its withdrawn twin. '' = free.
            $statusByUid = [];
            $einsatzByUid = [];
            if ($this->hasTagUid()) {
                $uidRows = DB::connection('tenant')->table('einsaetze')
                    ->where('bestell_id', $bestellung->bestell_id)->whereNotNull('tag_uid')
                    ->when($hasStornoColShow, fn ($q) => $q->whereNull('bestell_storno_at'))
                    ->selectRaw("tag_uid, einsatz_id, CASE WHEN hms_bestaetigt = 'ja' THEN 'confirmed'
                        WHEN pruefung = 'geplant' AND (EXISTS (SELECT 1 FROM einsatz_mitarbeiter em WHERE em.einsatz_id = einsaetze.einsatz_id)
                            OR EXISTS (SELECT 1 FROM nu_schicht_positionen np JOIN nu_position_zuweisungen nz ON nz.position_id = np.id WHERE np.einsatz_id = einsaetze.einsatz_id))
                        THEN 'planned' ELSE '' END AS st")
                    ->get();
                foreach ($uidRows as $ur) {
                    $statusByUid[strtolower((string) $ur->tag_uid)] = $ur->st;
                    $einsatzByUid[strtolower((string) $ur->tag_uid)] = $ur->einsatz_id;
                }
            }

            // Attach status to each day in $entry->tage
            $primaryArtikel = $entry->artikel_id ?? null;
            if (isset($entry->tage) && is_iterable($entry->tage)) {
                $rowsPerDateArt = [];
                foreach ($entry->tage as $day) {
                    $k = substr((string) $day->datum, 0, 10) . '|' . (string) ($day->artikel_id ?? $primaryArtikel ?? '');
                    $rowsPerDateArt[$k] = ($rowsPerDateArt[$k] ?? 0) + 1;
                }
                foreach ($entry->tage as $day) {
                    $uid = strtolower((string) ($day->tag_uid ?? ''));
                    // Planung deep link for this exact shift (view dialog).
                    $day->einsatz_id = $uid !== '' ? ($einsatzByUid[$uid] ?? null) : null;
                    if ($uid !== '' && array_key_exists($uid, $statusByUid)) {
                        $day->dispo_status = $statusByUid[$uid] !== '' ? $statusByUid[$uid] : null;
                        continue;
                    }
                    $d = substr((string) $day->datum, 0, 10);
                    $dayArt = (string) ($day->artikel_id ?? $primaryArtikel ?? '');
                    $k = $d . '|' . $dayArt;
                    $byTime = $statusByTime[$k . '|' . $hhmm($day->zeit_von ?? null) . '|' . $hhmm($day->zeit_bis ?? null)]
                        ?? $statusByTime[$d . '||' . $hhmm($day->zeit_von ?? null) . '|' . $hhmm($day->zeit_bis ?? null)]
                        ?? null;
                    $day->dispo_status = $byTime
                        ?? (($rowsPerDateArt[$k] ?? 0) <= 1
                            ? ($statusByDateArt[$k] ?? $statusByDateArt[$d . '|'] ?? null)
                            : null);
                }
            }
        }

        // Acknowledge the "shift deleted from Dispo" marker when the order
        // detail is explicitly opened (frontend passes ack=1). The values
        // already fetched into $entry are still returned so the detail view
        // can show the badge once before it clears.
        if ($request && $request->boolean('ack')
            && $this->col('bestelleingang', 'dispo_storno_count')
            && (int) ($entry->dispo_storno_count ?? 0) > 0
        ) {
            $clear = ['dispo_storno_count' => 0, 'dispo_storno_at' => null];
            if ($this->col('bestelleingang', 'dispo_storno_log')) {
                $clear['dispo_storno_log'] = null;
            }
            DB::connection('tenant')->table('bestelleingang')
                ->where('eingang_id', $id)
                ->update($clear);
        }

        return response()->json($entry);
    }

    public function store(Request $request)
    {
        $data = $request->all();

        // Multi-service orders: artikel_ids lists every selected Leistung;
        // [0] is the primary, mirrored into the legacy artikel_id column.
        $artikelIds = $this->normalizeArtikelIds($data);
        $data['artikel_id'] = $artikelIds[0] ?? ($data['artikel_id'] ?? null);

        // Input validation
        $errors = $this->validateIncomingData($data);
        if (!empty($errors)) {
            return response()->json(['error' => implode(', ', $errors)], 400);
        }

        // FK existence validation
        $fkError = $this->validateForeignKeys($data);
        if ($fkError) return $fkError;

        // Days belong to the order month (Dispo, AB and billing group by it).
        if ($err = $this->monthError($data['tage'] ?? [], (int) $data['monat'], (int) $data['jahr'], [])) {
            return response()->json(['error' => $err], 400);
        }

        // Bereich restriction: EVERY selected artikel's derived Bereich
        // (artikel → kunde fallback) must be inside the caller's whitelist —
        // otherwise a restricted user could attach an out-of-scope service
        // and its Einsätze would slip into Dispo under this order.
        $allowed = BereichScope::for($request->user());
        if (is_array($allowed)) {
            $bKunde = !empty($data['kunden_id'])
                ? DB::connection('tenant')->table('kunden')->where('kunde_id', $data['kunden_id'])->value('bereich')
                : null;
            $idsToCheck = !empty($artikelIds) ? $artikelIds : [null];
            foreach ($idsToCheck as $aid) {
                $bArtikel = $aid !== null
                    ? DB::connection('tenant')->table('artikel')->where('artikel_id', $aid)->value('bereich')
                    : null;
                $derived = trim((string) $bArtikel) !== '' ? $bArtikel : $bKunde;
                if (!BereichScope::listAllows($allowed, $derived)) {
                    return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
                }
            }
        }

        $eingang_id = IdGenerator::uniqueId('bestelleingang', 'eingang_id', 'BE-');

        DB::connection('tenant')->beginTransaction();
        try {
            $insert = [
                'eingang_id' => $eingang_id,
                'kunden_id' => $data['kunden_id'],
                'obj_id' => $data['obj_id'],
                'artikel_id' => $data['artikel_id'] ?? null,
                'monat' => intval($data['monat']),
                'jahr' => intval($data['jahr']),
                'zeit_von' => $data['zeit_von'] ?? null,
                'zeit_bis' => $data['zeit_bis'] ?? null,
                'modell' => $data['modell'] ?? null,
                'kundenpreis' => isset($data['kundenpreis']) ? floatval($data['kundenpreis']) : null,
                'nu_id' => $data['nu_id'] ?? null,
                'nu_preis' => isset($data['nu_preis']) ? floatval($data['nu_preis']) : null,
                'bemerkung' => $data['bemerkung'] ?? null,
                'status' => in_array($data['status'] ?? null, ['unbearbeitet', 'bearbeitet'], true) ? $data['status'] : 'unbearbeitet',
                'created_by' => optional($request->user())->id,
                'updated_by' => optional($request->user())->id,
            ];
            if ($this->col('bestelleingang', 'ort')) {
                $insert['ort'] = $this->normalizeOrt($data['ort'] ?? null);
            }
            if ($this->col('bestelleingang', 'artikel_ids')) {
                $insert['artikel_ids'] = json_encode($artikelIds);
            }
            if ($this->col('bestelleingang', 'artikel_preise')) {
                $insert['artikel_preise'] = $this->normalizeArtikelPreise($data['artikel_preise'] ?? null, $artikelIds);
            }
            DB::connection('tenant')->table('bestelleingang')->insert($insert);

            if (!empty($data['tage']) && is_array($data['tage'])) {
                // Stored day rows carry the RESOLVED artikel (same value the
                // einsatz gets) — never a raw foreign artikel_id from the
                // payload.
                foreach ($data['tage'] as &$tag) {
                    if (is_array($tag)) {
                        $tag['artikel_id'] = $this->tagArtikelId($tag, $artikelIds, $data['artikel_id'] ?? null);
                    }
                }
                unset($tag);
                $data['tage'] = $this->assignTagUids($data['tage']);
                $this->saveDays($eingang_id, $data['tage']);
            }

            // Auto-create order
            $bestell_id = IdGenerator::uniqueId('bestellungen', 'bestell_id', 'B-');
            $bestellInsert = [
                'bestell_id' => $bestell_id,
                'eingang_id' => $eingang_id,
                'kunden_id' => $data['kunden_id'],
                'obj_id' => $data['obj_id'],
                'artikel_id' => $data['artikel_id'] ?? null,
                'monat' => $data['monat'],
                'jahr' => $data['jahr'],
                'zeit_von' => $data['zeit_von'] ?? null,
                'zeit_bis' => $data['zeit_bis'] ?? null,
                'soll_std_betrag' => $data['soll_std_betrag'] ?? null,
                'status' => 'offen',
                'nu_id' => $data['nu_id'] ?? null,
            ];
            if ($this->col('bestellungen', 'artikel_ids')) {
                $bestellInsert['artikel_ids'] = json_encode($artikelIds);
            }
            DB::connection('tenant')->table('bestellungen')->insert($bestellInsert);

            // Auto-create tasks from days
            $tasksCreated = 0;
            if (!empty($data['tage']) && is_array($data['tage'])) {
                $feiertage  = SchichtartCalculator::cachedFeiertage($data['obj_id'] ?? null);
                $hasSchichtCols = $this->col('einsaetze', 'schichtart');
                $hasAnfEinsatz  = $this->col('einsaetze', 'anforderungen');
                $hasOrtEinsatz  = $this->col('einsaetze', 'ort');
                $headerOrt      = $this->normalizeOrt($data['ort'] ?? null);

                foreach ($data['tage'] as $tag) {
                    if (empty($tag['datum'])) continue;
                    $einsatz_id = IdGenerator::einsatzId($tag['datum']);
                    $istStart   = $tag['zeit_von'] ?? $data['zeit_von'] ?? null;
                    $istEnde    = $tag['zeit_bis'] ?? $data['zeit_bis'] ?? null;
                    $tagArtikel = $this->tagArtikelId($tag, $artikelIds, $data['artikel_id'] ?? null);

                    $row = [
                        'einsatz_id' => $einsatz_id,
                        'bestell_id' => $bestell_id,
                        'kunden_id' => $data['kunden_id'],
                        'obj_id' => $data['obj_id'],
                        'artikel_id' => $tagArtikel,
                        'nu_id' => $data['nu_id'] ?? null,
                        'datum' => $tag['datum'],
                        'tag_nr' => $tag['tag_nr'] ?? null,
                        'beschreibung' => $this->buildBeschreibung($tag['beschreibung'] ?? null, $tag['menge'] ?? null),
                        'ist_start' => $istStart,
                        'ist_ende' => $istEnde,
                        'ist_std_menge' => $tag['soll_std'] ?? null,
                        'pause_minuten' => $tag['pause_minuten'] ?? null,
                        'gesetzl_pause' => $tag['gesetzl_pause'] ?? null,
                        'hms_bestaetigt' => 'ausstehend',
                    ];

                    if ($hasSchichtCols) {
                        // Classification must use the TAG's artikel — a
                        // multi-service order can carry shifts of different
                        // Zuschlag rules under one bestellung.
                        $tagArtikelRow = $this->loadArtikelForClassification($tagArtikel);
                        $cls = SchichtartCalculator::compute($tag['datum'], $istStart, $istEnde, $tagArtikelRow, $feiertage);
                        $row['schichtart']   = $cls['schichtart'];
                        $row['zuschlag_pct'] = $cls['zuschlag_pct'];
                    }
                    if ($hasAnfEinsatz) {
                        $row['anforderungen'] = $this->normalizeAnforderungen($tag['anforderungen'] ?? null);
                    }
                    if ($hasOrtEinsatz) {
                        $row['ort'] = $this->resolveEinsatzOrt($tag, $headerOrt);
                    }
                    if ($this->hasTagUid()) {
                        $row['tag_uid'] = $tag['tag_uid'] ?? null;
                    }

                    DB::connection('tenant')->table('einsaetze')->insert($row);
                    $tasksCreated++;
                }
            }

            $this->logEingangChange($eingang_id, 'erstellt', [[
                'feld' => 'Angelegt',
                'von' => '–',
                'nach' => count((array) ($data['tage'] ?? [])) . ' Schicht(en)',
            ]], $request->user());

            DB::connection('tenant')->commit();
            \App\Helpers\Realtime::changed('eingang');
            \App\Helpers\Realtime::changed('dispo');
            return response()->json([
                'success' => true,
                'eingang_id' => $eingang_id,
                'bestell_id' => $bestell_id,
                'tasks_created' => $tasksCreated,
            ], 201);
        } catch (\Throwable $e) {
            DB::connection('tenant')->rollBack();
            \Log::error('Bestelleingang create failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Speichern fehlgeschlagen. Bitte erneut versuchen.'], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        $data = $request->all();

        // Existence check + Bereich scope
        $existing = DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->first();
        if (!$existing || !BereichScope::allowsEingang($request->user(), $id)) {
            return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        }
        // Cancelled / closed orders are read-only: a save would re-create
        // Einsätze in Dispo for an order the customer no longer has.
        if (in_array($existing->status, ['abgesagt', 'abgeschlossen'], true)) {
            return response()->json([
                'error' => 'Die Bestellung ist ' . $existing->status . ' und schreibgeschützt. '
                    . 'Zum Bearbeiten zuerst unter „Bestellungen“ wieder öffnen.',
            ], 409);
        }
        // Optimistic lock: the wizard sends the updated_at it loaded. Someone
        // else saved in between → refuse instead of silently overwriting.
        if ($this->isStaleEdit($data, $existing)) {
            return $this->staleEditResponse();
        }
        // Multi-service orders: normalize the full selection; [0] = primary
        // (mirrored into artikel_id below).
        $artikelIds = $this->normalizeArtikelIds($data);
        if (!empty($artikelIds)) $data['artikel_id'] = $artikelIds[0];

        // Kunde and Objekt are FIXED once an order exists: its Bestellung,
        // Einsätze (Dispo), Auftragsbestätigung and billing all hang on them.
        // A different customer/object = a new order (or a copy).
        foreach (['kunden_id' => 'Kunde', 'obj_id' => 'Objekt'] as $field => $label) {
            $stored = (string) ($existing->{$field} ?? '');
            if ($stored !== '' && array_key_exists($field, $data) && (string) ($data[$field] ?? '') !== $stored) {
                return response()->json([
                    'error' => "{$label} kann bei einer bestehenden Bestellung nicht geändert werden. "
                        . 'Bitte die Bestellung kopieren oder neu anlegen.',
                ], 409);
            }
        }
        // EVERY newly added artikel must be inside the caller's Bereich
        // whitelist — otherwise an out-of-scope service could be attached
        // and its Einsätze would land in Dispo under this order. Artikel
        // already stored on this order may be re-submitted unchanged: a
        // restricted user editing via a secondary tag cannot remove what he
        // cannot see, so re-sending it is not a scope write.
        $existingArtikelIds = $this->normalizeArtikelIds((array) $existing);
        foreach ($artikelIds as $aid) {
            if (in_array((string) $aid, $existingArtikelIds, true)) continue;
            if (!BereichScope::allowsArtikel($request->user(), (string) $aid)) {
                return response()->json(['error' => 'Artikel nicht gefunden'], 404);
            }
        }

        // Stored artikel outside the caller's Bereich are "hidden" from him:
        // his wizard cannot render them. They may be re-submitted unchanged,
        // but he may not (a) drop them — they are re-merged below — or
        // (b) attach NEW days to them (checked against the stored day keys).
        // Admins bypass allowsArtikel → the list stays empty for them and
        // deliberate removal still works.
        $outOfScopeIds = [];
        foreach ($existingArtikelIds as $eid) {
            if (!BereichScope::allowsArtikel($request->user(), $eid)) {
                $outOfScopeIds[] = $eid;
            }
        }
        $remergedIds = [];
        $mergedIds = [];
        foreach ($existingArtikelIds as $eid) {
            if (in_array($eid, $artikelIds, true)) {
                $mergedIds[] = $eid;
            } elseif (in_array($eid, $outOfScopeIds, true)) {
                $mergedIds[] = $eid;
                $remergedIds[] = $eid;
            }
        }
        foreach ($artikelIds as $sid) {
            if (!in_array($sid, $mergedIds, true)) $mergedIds[] = $sid;
        }
        $artikelIds = $mergedIds;
        if (!empty($artikelIds)) $data['artikel_id'] = $artikelIds[0];
        // Feed the MERGED selection downstream: syncDownstream re-normalizes
        // artikel_ids from $data — without this it would resolve day rows,
        // einsatz matching and bestellungen.artikel_ids against the raw
        // payload (which misses re-merged hidden services).
        $data['artikel_ids'] = $artikelIds;

        // A service (Leistung) whose shifts are booked in Dispo cannot be
        // dropped from the order — same rule as removing a booked day.
        $removedArtikel = array_values(array_diff($existingArtikelIds, $artikelIds));
        if ($removedArtikel) {
            $bestellIdsForArt = DB::connection('tenant')->table('bestellungen')->where('eingang_id', $id)->pluck('bestell_id')->all();
            $artShifts = $bestellIdsForArt ? DB::connection('tenant')->table('einsaetze')
                ->whereIn('bestell_id', $bestellIdsForArt)->whereIn('artikel_id', $removedArtikel)->get() : collect();
            $booked = StaffedShifts::booked($artShifts);
            if ($booked) {
                $names = DB::connection('tenant')->table('artikel')->whereIn('artikel_id', $removedArtikel)->pluck('artikel_name', 'artikel_id');
                $list = array_column($booked, 'label');
                return response()->json([
                    'error' => 'Die Leistung „' . $names->values()->implode('“, „') . '“ hat eingeplante bzw. bestätigte Einsätze und kann nicht entfernt werden. '
                        . 'Bitte zuerst den Plan in der Disposition aufheben für: ' . implode('; ', $list),
                    'blocked_shifts' => $list,
                ], 409);
            }
        }

        // Preserve the hidden services' stored day rows so the delete+reinsert
        // below does not wipe them, and carry over their stored pricing.
        $hiddenDays = collect();
        if (!empty($outOfScopeIds)) {
            if ($this->col('bestelleingang_tage', 'artikel_id')) {
                $hiddenDays = DB::connection('tenant')->table('bestelleingang_tage')
                    ->where('eingang_id', $id)->whereIn('artikel_id', $outOfScopeIds)->get();
            }
            $storedPreise = [];
            try {
                $p = json_decode($existing->artikel_preise ?? '', true);
                if (is_array($p)) $storedPreise = $p;
            } catch (\Throwable $e) { /* malformed JSON → ignore */ }
            if (!empty($storedPreise)) {
                if (!isset($data['artikel_preise']) || !is_array($data['artikel_preise'])) {
                    $data['artikel_preise'] = [];
                }
                foreach ($remergedIds as $rid) {
                    if (isset($storedPreise[$rid]) && !isset($data['artikel_preise'][$rid])) {
                        $data['artikel_preise'][$rid] = $storedPreise[$rid];
                    }
                }
            }
        }

        // No order-level hard lock: a CONFIRMED Einsatz freezes only its own
        // day (removal guard + day-row freeze below; the shift itself is
        // frozen in syncDownstream). 'geplant' shifts are likewise protected
        // per-day — planned+workers keep times/crew and a removed day
        // tombstones its planned shift. Storno-locked shifts don't lock:
        // re-adding their day is exactly how they get reactivated.
        // The dispatcher edits every other day normally.

        // Input validation
        $errors = $this->validateIncomingData($data);
        if (!empty($errors)) {
            return response()->json(['error' => implode(', ', $errors)], 400);
        }

        // FK existence validation
        $fkError = $this->validateForeignKeys($data);
        if ($fkError) return $fkError;

        // NEW days must lie in the order month; days already stored outside
        // it (older multi-month orders) stay valid.
        $storedDates = DB::connection('tenant')->table('bestelleingang_tage')->where('eingang_id', $id)
            ->pluck('datum')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        if ($err = $this->monthError($data['tage'] ?? [], (int) $data['monat'], (int) $data['jahr'], $storedDates)) {
            return response()->json(['error' => $err], 400);
        }

        DB::connection('tenant')->beginTransaction();
        try {
            // Lock the order row and its Einsätze for the whole save: Dispo
            // cannot plan / confirm a shift of this order while we decide
            // which days are frozen (closes the "planned while editing" gap).
            $lockedOrder = DB::connection('tenant')->table('bestelleingang')
                ->where('eingang_id', $id)->lockForUpdate()->first();
            if ($this->isStaleEdit($data, $lockedOrder)) {
                DB::connection('tenant')->rollBack();
                return $this->staleEditResponse();
            }

            // Booked days (confirmed, or planned with crew) are FROZEN: the
            // day cannot be removed and its stored row is re-applied — the
            // plan record never diverges from the shift Dispo/workers see.
            // A changed time on such a day is refused (no silent revert).
            // Tombstoned shifts neither block nor freeze — re-adding their
            // day reactivates them in syncDownstream.
            $frozenRows = []; // payload index → ['stored' => day row|null, 'einsatz' => row]
            $assignedIdx = []; // payload index → matched existing Einsatz
            if (isset($data['tage']) && is_array($data['tage'])) {
                $newDatums = array_unique(array_filter(array_map(
                    fn ($t) => isset($t['datum']) ? substr((string) $t['datum'], 0, 10) : null, $data['tage'])));

                $bestellung = DB::connection('tenant')->table('bestellungen')
                    ->where('eingang_id', $id)->first();

                if ($bestellung) {
                    $hasStornoUpd = $this->col('einsaetze', 'bestell_storno_at');
                    $allEinsaetze = DB::connection('tenant')->table('einsaetze')
                        ->where('bestell_id', $bestellung->bestell_id)->lockForUpdate()->get();
                    $liveEinsaetze = $allEinsaetze->filter(fn ($e) => !$hasStornoUpd || $e->bestell_storno_at === null);
                    $this->preloadCrew($allEinsaetze->pluck('einsatz_id')->all());

                    $confirmed = $liveEinsaetze
                        ->filter(fn ($e) => $e->hms_bestaetigt === 'ja'
                            && !in_array(substr((string) $e->datum, 0, 10), $newDatums, true))
                        ->map(fn ($e) => date('d.m.Y', strtotime($e->datum)))
                        ->unique()->values()->all();
                    if (!empty($confirmed)) {
                        DB::connection('tenant')->rollBack();
                        return response()->json([
                            'error' => 'Bestätigte Einsätze können nicht entfernt werden. '
                                . 'Bitte zuerst die Bestätigung in der Disposition aufheben für folgende Tage: '
                                . implode(', ', $confirmed),
                        ], 409);
                    }

                    // Same day→shift pairing as syncDownstream, so removing
                    // one of several shifts on a date hits exactly the right
                    // one. Checked PER SHIFT incl. confirmed ones — the date
                    // check above misses a confirmed shift whose date keeps
                    // another shift.
                    [$assignedIdx, $keptIds] = $this->matchDaysToEinsaetze($data['tage'], $allEinsaetze, $data, $this->normalizeArtikelIds($data));
                    $removed = $liveEinsaetze->filter(fn ($e) => !in_array($e->einsatz_id, $keptIds, true));
                    $list = array_column(StaffedShifts::booked($removed), 'label');
                    if (!empty($list)) {
                        DB::connection('tenant')->rollBack();
                        return response()->json([
                            'error' => 'Eingeplante bzw. bestätigte Einsätze können nicht entfernt werden. '
                                . 'Bitte zuerst den Plan in der Disposition aufheben für: ' . implode('; ', $list),
                            'blocked_shifts' => $list,
                        ], 409);
                    }

                    $storedByTagNr = DB::connection('tenant')->table('bestelleingang_tage')
                        ->where('eingang_id', $id)->get()->keyBy(fn ($r) => (int) $r->tag_nr);
                    $timeConflicts = [];
                    foreach ($assignedIdx as $i => $e) {
                        if ($hasStornoUpd && $e->bestell_storno_at !== null) continue;
                        if (!$this->isStaffedShift($e)) continue;
                        $row = $data['tage'][$i];
                        $von = self::hhmm($row['zeit_von'] ?? $data['zeit_von'] ?? null);
                        $bis = self::hhmm($row['zeit_bis'] ?? $data['zeit_bis'] ?? null);
                        if ($von !== self::hhmm($e->ist_start) || $bis !== self::hhmm($e->ist_ende)) {
                            $timeConflicts = array_merge($timeConflicts, array_column(StaffedShifts::booked([$e]), 'label'));
                        }
                        $frozenRows[$i] = ['stored' => $storedByTagNr[(int) $e->tag_nr] ?? null, 'einsatz' => $e];
                    }
                    if (!empty($timeConflicts)) {
                        DB::connection('tenant')->rollBack();
                        return response()->json([
                            'error' => 'Zeiten von eingeplanten bzw. bestätigten Einsätzen können nicht geändert werden. '
                                . 'Bitte zuerst den Plan in der Disposition aufheben für: ' . implode('; ', $timeConflicts),
                            'blocked_shifts' => $timeConflicts,
                        ], 409);
                    }
                }
            }

            $update = [
                'kunden_id' => $data['kunden_id'],
                'obj_id' => $data['obj_id'],
                'artikel_id' => $data['artikel_id'] ?? null,
                'monat' => intval($data['monat']),
                'jahr' => intval($data['jahr']),
                'zeit_von' => $data['zeit_von'] ?? null,
                'zeit_bis' => $data['zeit_bis'] ?? null,
                'modell' => $data['modell'] ?? null,
                'kundenpreis' => isset($data['kundenpreis']) ? floatval($data['kundenpreis']) : null,
                'nu_id' => $data['nu_id'] ?? null,
                'nu_preis' => isset($data['nu_preis']) ? floatval($data['nu_preis']) : null,
                'bemerkung' => $data['bemerkung'] ?? null,
                // Saving in the wizard = processed. Any other status is only
                // changed through the lifecycle (Bestellungen: stornieren /
                // abschließen / wieder öffnen) — never reset by an edit.
                'status' => ($lockedOrder->status ?? 'unbearbeitet') === 'unbearbeitet' ? 'bearbeitet' : $lockedOrder->status,
                'updated_at' => now(),
                'updated_by' => optional($request->user())->id,
            ];
            // Header Ort is only written when the client sent the key — older
            // clients (or partial updates) must not wipe it.
            if (array_key_exists('ort', $data) && $this->col('bestelleingang', 'ort')) {
                $update['ort'] = $this->normalizeOrt($data['ort']);
            }
            if ($this->col('bestelleingang', 'artikel_ids')) {
                $update['artikel_ids'] = json_encode($artikelIds);
            }
            if ($this->col('bestelleingang', 'artikel_preise')) {
                $update['artikel_preise'] = $this->normalizeArtikelPreise($data['artikel_preise'] ?? null, $artikelIds);
            }
            DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->update($update);

            if (isset($data['tage']) && is_array($data['tage'])) {
                // Resolve each day's artikel against the merged selection —
                // stored day rows must carry the SAME resolved artikel the
                // generated einsatz gets, never a raw foreign value.
                foreach ($data['tage'] as &$tag) {
                    if (!is_array($tag)) continue;
                    $tag['artikel_id'] = $this->tagArtikelId($tag, $artikelIds, $data['artikel_id'] ?? null);
                }
                unset($tag);

                // A crafted payload could point a NEW day at a hidden artikel
                // — that would write an Einsatz into a Bereich the caller
                // cannot touch. Hidden artikel are only valid on day rows
                // that already exist (same datum+times+artikel); anything
                // else resolves to the caller's first IN-SCOPE artikel (the
                // stored primary may itself be hidden).
                if (!empty($outOfScopeIds)) {
                    $demoteTarget = $data['artikel_id'];
                    foreach ($artikelIds as $aid) {
                        if (!in_array($aid, $outOfScopeIds, true)) { $demoteTarget = $aid; break; }
                    }
                    $hiddenKeys = [];
                    foreach ($hiddenDays as $sd) {
                        $hiddenKeys[substr((string) $sd->datum, 0, 10) . '|'
                            . substr((string) ($sd->zeit_von ?? ''), 0, 5) . '|'
                            . substr((string) ($sd->zeit_bis ?? ''), 0, 5) . '|'
                            . (string) ($sd->artikel_id ?? '')] = true;
                    }
                    foreach ($data['tage'] as &$tag) {
                        $ta = trim((string) ($tag['artikel_id'] ?? ''));
                        if ($ta !== '' && in_array($ta, $outOfScopeIds, true)) {
                            $key = substr((string) ($tag['datum'] ?? ''), 0, 10) . '|'
                                . substr((string) ($tag['zeit_von'] ?? ''), 0, 5) . '|'
                                . substr((string) ($tag['zeit_bis'] ?? ''), 0, 5) . '|' . $ta;
                            if (!isset($hiddenKeys[$key])) $tag['artikel_id'] = $demoteTarget;
                        }
                    }
                    unset($tag);
                }

                // Frozen (booked) days: re-apply the stored day row, with the
                // Einsatz as the truth for times / pauses / service / Ort, so
                // the plan record stays identical to what Dispo and the crew
                // see. Ort is pinned explicitly — a later header-Ort change
                // must not reach a booked shift either.
                foreach ($frozenRows as $i => $fz) {
                    if (!isset($data['tage'][$i]) || !is_array($data['tage'][$i])) continue;
                    $tag = $data['tage'][$i];
                    $e = $fz['einsatz'];
                    $frozen = $fz['stored'] ? (array) $fz['stored'] : $tag;
                    unset($frozen['id'], $frozen['created_at'], $frozen['eingang_id']);
                    $frozen['datum'] = substr((string) $e->datum, 0, 10);
                    $frozen['zeit_von'] = self::hhmm($e->ist_start) ?? ($frozen['zeit_von'] ?? null);
                    $frozen['zeit_bis'] = self::hhmm($e->ist_ende) ?? ($frozen['zeit_bis'] ?? null);
                    $frozen['pause_minuten'] = $e->pause_minuten;
                    $frozen['gesetzl_pause'] = $e->gesetzl_pause;
                    if (!empty($e->artikel_id)) $frozen['artikel_id'] = $e->artikel_id;
                    if (property_exists($e, 'ort')) $frozen['ort'] = $e->ort;
                    // Numbering follows the payload (days may be renumbered).
                    $frozen['tag_nr'] = $tag['tag_nr'] ?? ($frozen['tag_nr'] ?? null);
                    $frozen['tag_uid'] = $e->tag_uid ?? ($frozen['tag_uid'] ?? ($tag['tag_uid'] ?? null));
                    $data['tage'][$i] = $frozen;
                }

                // Re-append the hidden services' stored days so they survive
                // the replace-all below — skipping keys the caller already
                // re-submitted verbatim (read-only rows are still sent back).
                $submittedKeys = [];
                foreach ($data['tage'] as $tag) {
                    $submittedKeys[substr((string) ($tag['datum'] ?? ''), 0, 10) . '|'
                        . substr((string) ($tag['zeit_von'] ?? ''), 0, 5) . '|'
                        . substr((string) ($tag['zeit_bis'] ?? ''), 0, 5) . '|'
                        . trim((string) ($tag['artikel_id'] ?? ''))] = true;
                }
                foreach ($hiddenDays as $sd) {
                    $k = substr((string) $sd->datum, 0, 10) . '|'
                        . substr((string) ($sd->zeit_von ?? ''), 0, 5) . '|'
                        . substr((string) ($sd->zeit_bis ?? ''), 0, 5) . '|'
                        . (string) ($sd->artikel_id ?? '');
                    if (!isset($submittedKeys[$k])) $data['tage'][] = (array) $sd;
                }

                // Stable day key: rows keep their tag_uid across saves; a row
                // from an old client without one inherits it from its Einsatz.
                $data['tage'] = $this->assignTagUids($data['tage'], $assignedIdx);

                $daysBefore = DB::connection('tenant')->table('bestelleingang_tage')->where('eingang_id', $id)->get();
                DB::connection('tenant')->table('bestelleingang_tage')->where('eingang_id', $id)->delete();
                $this->saveDays($id, $data['tage']);
                $daysAfter = DB::connection('tenant')->table('bestelleingang_tage')->where('eingang_id', $id)->get();
            }

            $this->logEingangChange($id, 'bearbeitet',
                $this->diffEingangChanges($lockedOrder, $update, $existingArtikelIds, $artikelIds, $daysBefore ?? null, $daysAfter ?? null),
                $request->user());

            // Sync downstream bestellung + einsaetze
            $this->nuCancellations = []; $this->nuTimeChanges = [];
            $sync = $this->syncDownstream($id, $data);

            DB::connection('tenant')->commit();
            $this->flushNuCancellations();
            \App\Helpers\Realtime::changed('eingang');
            \App\Helpers\Realtime::changed('dispo');
            return response()->json([
                'success' => true,
                'bestell_id' => $sync['bestell_id'],
                'tasks_created' => $sync['tasks_created'],
                'tasks_updated' => $sync['tasks_updated'],
                'tasks_protected' => $sync['tasks_protected'] ?? 0,
                'updated_at' => (string) DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->value('updated_at'),
            ]);
        } catch (\Throwable $e) {
            DB::connection('tenant')->rollBack();
            $this->nuCancellations = []; $this->nuTimeChanges = [];
            \Log::error('Bestelleingang update failed: ' . $e->getMessage(), ['eingang_id' => $id, 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Aktualisierung fehlgeschlagen. Bitte erneut versuchen.'], 500);
        }
    }

    public function destroy(string $id)
    {
        $existing = DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->first();
        if (!$existing || !BereichScope::allowsEingang(request()->user(), $id)) {
            return response()->json(['error' => 'Bestelleingang nicht gefunden'], 404);
        }
        // Deleting a multi-service order deletes EVERY service's Einsätze —
        // including ones outside the caller's Bereich. Refuse for restricted
        // users; an admin (or a dispatcher who can see all tags) must do it.
        foreach ($this->normalizeArtikelIds((array) $existing) as $aid) {
            if (!BereichScope::allowsArtikel(request()->user(), $aid)) {
                return response()->json([
                    'error' => 'Diese Bestellung enthält Leistungen außerhalb Ihres Bereichs und kann von Ihnen nicht gelöscht werden.',
                ], 403);
            }
        }

        // Same rule as removing single days: planned shifts with workers / NU
        // workers are withdrawn in Dispo first. Confirmed shifts are kept as
        // history (unlinked below) and do not block.
        $blocked = array_column(StaffedShifts::bookedForBestellungen(
            DB::connection('tenant')->table('bestellungen')->where('eingang_id', $id)->pluck('bestell_id')->all(), false
        ), 'label');
        if ($blocked) {
            return response()->json([
                'error' => 'Die Bestellung hat geplante Einsätze mit Mitarbeitern und kann nicht gelöscht werden. '
                    . 'Bitte zuerst den Plan in der Disposition aufheben für: ' . implode('; ', $blocked),
                'blocked_shifts' => $blocked,
            ], 409);
        }

        DB::connection('tenant')->beginTransaction();
        try {
            $bestell_ids = DB::connection('tenant')->table('bestellungen')
                ->where('eingang_id', $id)->pluck('bestell_id')->toArray();

            $protectedCount = 0;
            $deletedCount = 0;
            $nuNotifications = [];
            $hasStornoDel = $this->col('einsaetze', 'bestell_storno_at');

            if (!empty($bestell_ids)) {
                $allEinsaetze = DB::connection('tenant')->table('einsaetze as e')
                    ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
                    ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                    ->whereIn('e.bestell_id', $bestell_ids)
                    ->select('e.*', 'k.kundenname', 'o.objektname')
                    ->get();

                foreach ($allEinsaetze as $e) {
                    // Only protect confirmed tasks (hard confirm)
                    $isProtected = $e->hms_bestaetigt === 'ja';

                    if ($isProtected) {
                        // Confirmed = billing history. Same tombstone as a
                        // removed day (grey, read-only in Dispo) — the shift
                        // KEEPS its bestell_id, so Kunde/Objekt/billing joins
                        // stay intact. Its Bestellung is kept (storniert) below.
                        if ($hasStornoDel) {
                            DB::connection('tenant')->table('einsaetze')
                                ->where('einsatz_id', $e->einsatz_id)
                                ->update(['bestell_storno_at' => $e->bestell_storno_at ?? now()]);
                        } else {
                            DB::connection('tenant')->table('einsaetze')
                                ->where('einsatz_id', $e->einsatz_id)
                                ->update(['bestell_id' => null]);
                        }
                        $protectedCount++;
                    } else {
                        // Collect NU info for cancellation notification
                        if (!empty($e->nu_id)) {
                            $nu = DB::connection('tenant')->table('nachunternehmer')
                                ->where('nu_id', $e->nu_id)->first();
                            if ($nu && !empty($nu->email) && filter_var($nu->email, FILTER_VALIDATE_EMAIL)) {
                                $key = $nu->nu_id . '|' . $e->datum;
                                if (!isset($nuNotifications[$key])) {
                                    $nuNotifications[$key] = [
                                        'email' => $nu->email,
                                        'firmenname' => $nu->firmenname ?? 'Nachunternehmer',
                                        'datum' => $e->datum ?? '-',
                                        'zeit' => ($e->ist_start ?? '-') . ' - ' . ($e->ist_ende ?? '-'),
                                        'objekt' => $e->objektname ?? '-',
                                        'kunde' => $e->kundenname ?? '-',
                                    ];
                                }
                            }
                        }

                        // Clean up NU-related records
                        $posIds = DB::connection('tenant')->table('nu_schicht_positionen')
                            ->where('einsatz_id', $e->einsatz_id)->pluck('id')->toArray();
                        if (!empty($posIds)) {
                            DB::connection('tenant')->table('nu_position_zuweisungen')
                                ->whereIn('position_id', $posIds)->delete();
                        }
                        DB::connection('tenant')->table('nu_schicht_positionen')
                            ->where('einsatz_id', $e->einsatz_id)->delete();
                        DB::connection('tenant')->table('einsatz_nu_status')
                            ->where('einsatz_id', $e->einsatz_id)->delete();

                        // End running clock-ins before the rows vanish —
                        // worked time stays as a finished zeiterfassung
                        // record even when the order is deleted.
                        WorkerAppZeitController::closeOpenShiftsForEinsatz(
                            (string) $e->einsatz_id, [],
                            'Bestelleingang gelöscht — Schicht automatisch beendet'
                        );

                        // Delete internal worker assignments and the einsatz
                        DB::connection('tenant')->table('einsatz_mitarbeiter')
                            ->where('einsatz_id', $e->einsatz_id)->delete();
                        DB::connection('tenant')->table('einsaetze')
                            ->where('einsatz_id', $e->einsatz_id)->delete();
                        $deletedCount++;
                    }
                }

                if ($protectedCount > 0 && $hasStornoDel) {
                    // Keep the Bestellung for the confirmed history — the FK
                    // (einsaetze.bestell_id → bestellungen, cascade on delete)
                    // would otherwise delete those shifts. eingang_id becomes
                    // NULL automatically when the Bestelleingang row goes.
                    DB::connection('tenant')->table('bestellungen')->where('eingang_id', $id)
                        ->update(['status' => 'storniert', 'updated_at' => now()]);
                } else {
                    DB::connection('tenant')->table('bestellungen')->where('eingang_id', $id)->delete();
                }
            }

            DB::connection('tenant')->table('bestelleingang_tage')->where('eingang_id', $id)->delete();
            DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $id)->delete();

            DB::connection('tenant')->commit();

            // Send NU cancellation emails after successful commit
            if (!empty($nuNotifications)) {
                $tenant = TenantService::current();
                $companyName = $tenant->name ?? config('app.name', 'Clood-x');

                // Queued — synchronous SMTP sends in a loop blocked the response.
                foreach ($nuNotifications as $notif) {
                    try {
                        Mail::to($notif['email'])->queue(new \App\Mail\NuNotificationMail(
                            blade: 'emails.nu-shift-cancelled',
                            subjectLine: "Einsatz storniert - {$notif['datum']} | {$companyName}",
                            payload: [
                                'company'    => $companyName,
                                'firmenname' => $notif['firmenname'],
                                'datum'      => $notif['datum'],
                                'zeit'       => $notif['zeit'],
                                'objekt'     => $notif['objekt'],
                                'kunde'      => $notif['kunde'],
                            ],
                        ));
                    } catch (\Exception $mailEx) {
                        \Log::error('NU cancellation email failed: ' . $mailEx->getMessage());
                    }
                }
            }

            // Notify Dispo managers + owners that an order (and its planned
            // Einsätze) was removed from Bestellungseingang. Best-effort.
            $this->notifyOrderDeleted($existing, $deletedCount, $protectedCount);

            \App\Helpers\Realtime::changed('eingang');
            \App\Helpers\Realtime::changed('dispo');
            return response()->json([
                'success' => true,
                'deleted_tasks' => $deletedCount,
                'protected_tasks' => $protectedCount,
                'nu_notified' => count($nuNotifications),
            ]);
        } catch (\Throwable $e) {
            DB::connection('tenant')->rollBack();
            \Log::error('Bestelleingang delete failed: ' . $e->getMessage(), ['eingang_id' => $id]);
            return response()->json(['error' => 'Löschen fehlgeschlagen. Bitte erneut versuchen.'], 500);
        }
    }

    /**
     * Lifecycle (one vocabulary for both screens):
     *   bestelleingang.status  unbearbeitet → bearbeitet → abgeschlossen | abgesagt
     *   bestellungen.status    offen                    → abgeschlossen | storniert
     *
     * Cancel = Bestellung "storniert" + Bestelleingang "abgesagt". Refused
     * while shifts are booked (planned with crew / confirmed). Unbooked
     * Einsätze are removed from Dispo (NU notified); the day rows stay so
     * the order can be re-opened. Runs inside the caller's transaction.
     *
     * @return array{error?: string, status?: int, blocked_shifts?: array, deleted_tasks?: int}
     */
    public function cancelOrder(string $eingangId): array
    {
        $db = DB::connection('tenant');
        $bestellIds = $db->table('bestellungen')->where('eingang_id', $eingangId)->pluck('bestell_id')->all();
        $einsaetze = $bestellIds ? $db->table('einsaetze')->whereIn('bestell_id', $bestellIds)->lockForUpdate()->get() : collect();
        if (!$this->col('einsaetze', 'bestell_storno_at')) {
            $einsaetze = $einsaetze->map(function ($e) { $e->bestell_storno_at = null; return $e; });
        }
        $blocked = array_column(StaffedShifts::booked($einsaetze), 'label');
        if ($blocked) {
            return [
                'error' => 'Die Bestellung hat eingeplante bzw. bestätigte Einsätze und kann nicht storniert werden. '
                    . 'Bitte zuerst den Plan in der Disposition aufheben für: ' . implode('; ', $blocked),
                'status' => 409, 'blocked_shifts' => $blocked,
            ];
        }
        $this->nuCancellations = []; $this->nuTimeChanges = [];
        $deleted = 0;
        foreach ($einsaetze as $e) {
            if (($e->hms_bestaetigt ?? null) === 'ja') continue; // grey legacy tombstone = history
            $this->collectNuCancellation($e);
            $posIds = $db->table('nu_schicht_positionen')->where('einsatz_id', $e->einsatz_id)->pluck('id')->all();
            if ($posIds) $db->table('nu_position_zuweisungen')->whereIn('position_id', $posIds)->delete();
            $db->table('nu_schicht_positionen')->where('einsatz_id', $e->einsatz_id)->delete();
            $db->table('einsatz_nu_status')->where('einsatz_id', $e->einsatz_id)->delete();
            WorkerAppZeitController::closeOpenShiftsForEinsatz((string) $e->einsatz_id, [], 'Bestellung storniert — Schicht automatisch beendet');
            $db->table('einsatz_mitarbeiter')->where('einsatz_id', $e->einsatz_id)->delete();
            $db->table('einsaetze')->where('einsatz_id', $e->einsatz_id)->delete();
            $deleted++;
        }
        if ($bestellIds) $db->table('bestellungen')->whereIn('bestell_id', $bestellIds)->update(['status' => 'storniert', 'updated_at' => now()]);
        $db->table('bestelleingang')->where('eingang_id', $eingangId)->update([
            'status' => 'abgesagt', 'updated_at' => now(), 'updated_by' => optional(auth()->user())->id,
        ]);
        $this->logEingangChange($eingangId, 'abgesagt', [], auth()->user());
        return ['deleted_tasks' => $deleted];
    }

    /**
     * Re-open a cancelled or closed order (→ bearbeitet / offen). A cancelled
     * order gets its Einsätze rebuilt from the stored day rows — the same
     * sync the wizard save uses. Runs inside the caller's transaction.
     */
    public function reopenOrder(string $eingangId): array
    {
        $db = DB::connection('tenant');
        $order = $db->table('bestelleingang')->where('eingang_id', $eingangId)->lockForUpdate()->first();
        if (!$order) return ['error' => 'Bestelleingang nicht gefunden', 'status' => 404];
        $created = 0;
        if ($order->status === 'abgesagt') {
            $tage = $db->table('bestelleingang_tage')->where('eingang_id', $eingangId)->orderBy('tag_nr')->get()
                ->map(fn ($t) => (array) $t)->all();
            $data = [
                'kunden_id' => $order->kunden_id, 'obj_id' => $order->obj_id,
                'artikel_id' => $order->artikel_id ?? null,
                'artikel_ids' => $this->normalizeArtikelIds((array) $order),
                'monat' => $order->monat, 'jahr' => $order->jahr,
                'zeit_von' => $order->zeit_von, 'zeit_bis' => $order->zeit_bis,
                'nu_id' => $order->nu_id ?? null,
                'tage' => $tage,
            ];
            if (property_exists($order, 'ort')) $data['ort'] = $order->ort;
            $this->nuCancellations = []; $this->nuTimeChanges = [];
            $sync = $this->syncDownstream($eingangId, $data);
            $created = $sync['tasks_created'];
        }
        $db->table('bestellungen')->where('eingang_id', $eingangId)->update(['status' => 'offen', 'updated_at' => now()]);
        $db->table('bestelleingang')->where('eingang_id', $eingangId)->update([
            'status' => 'bearbeitet', 'updated_at' => now(), 'updated_by' => optional(auth()->user())->id,
        ]);
        $this->logEingangChange($eingangId, 'wieder_geoeffnet', [], auth()->user());
        return ['tasks_created' => $created];
    }

    /** Close an order: both screens "abgeschlossen", Einsätze untouched. */
    public function closeOrder(string $eingangId): array
    {
        $db = DB::connection('tenant');
        $db->table('bestellungen')->where('eingang_id', $eingangId)->update(['status' => 'abgeschlossen', 'updated_at' => now()]);
        $db->table('bestelleingang')->where('eingang_id', $eingangId)->update([
            'status' => 'abgeschlossen', 'updated_at' => now(), 'updated_by' => optional(auth()->user())->id,
        ]);
        $this->logEingangChange($eingangId, 'abgeschlossen', [], auth()->user());
        return [];
    }

    /** NU mails collected by cancelOrder — call AFTER commit. */
    public function flushLifecycleNotifications(): void
    {
        $this->flushNuCancellations();
    }

    /**
     * Push an in-app + email notification to Dispo managers (+ owners) after a
     * Bestelleingang is deleted, so removed planned Einsätze never vanish
     * silently. Best-effort: failures are logged and swallowed.
     *
     * @param  object  $existing        The bestelleingang row fetched before deletion.
     * @param  int     $deletedCount    Number of (open) Einsätze deleted with the order.
     * @param  int     $protectedCount  Number of confirmed Einsätze kept (detached).
     */
    protected function notifyOrderDeleted($existing, int $deletedCount, int $protectedCount): void
    {
        try {
            $tenant = TenantService::current();
            if (!$tenant || empty($tenant->id)) return;

            $kunde = DB::connection('tenant')->table('kunden')
                ->where('kunde_id', $existing->kunden_id ?? null)->value('kundenname') ?? ($existing->kunden_id ?? '-');
            $objekt = DB::connection('tenant')->table('objekte')
                ->where('obj_id', $existing->obj_id ?? null)->value('objektname') ?? ($existing->obj_id ?? '-');

            $title = 'Bestelleingang gelöscht';
            $body  = "Ein Bestelleingang wurde gelöscht.\n"
                   . "Eingang: " . ($existing->eingang_id ?? '-') . "\n"
                   . "Kunde: {$kunde}\n"
                   . "Objekt: {$objekt}\n"
                   . "Gelöschte Einsätze: {$deletedCount}";
            if ($protectedCount > 0) {
                $body .= "\nGeschützte (bestätigte) Einsätze: {$protectedCount}";
            }

            NotificationDispatcher::notifyDispoManagers(
                (int) $tenant->id,
                'eingang.order.deleted',
                $title,
                $body,
                '#tasks',
                [
                    'eingang_id'      => $existing->eingang_id ?? null,
                    'deleted_tasks'   => $deletedCount,
                    'protected_tasks' => $protectedCount,
                ]
            );
        } catch (\Throwable $e) {
            \Log::warning('notifyOrderDeleted failed: ' . $e->getMessage());
        }
    }

    /**
     * Append a row to the order change log (bestelleingang_log). Runs inside
     * the caller's transaction, swallows every failure — audit must never
     * break a save. A 'bearbeitet' call with an empty diff writes nothing.
     */
    protected function logEingangChange(string $eingangId, string $aktion, array $changes, $user): void
    {
        try {
            if ($aktion === 'bearbeitet' && empty($changes)) return; // no-op save → nothing to log
            if (!$this->col('bestelleingang_log', 'id')) return;     // migration not run on this tenant yet
            DB::connection('tenant')->table('bestelleingang_log')->insert([
                'eingang_id'  => $eingangId,
                'user_id'     => $user?->id,
                'user_name'   => $user ? (trim((string) ($user->full_name ?? '')) !== '' ? $user->full_name : $user->name) : null,
                'aktion'      => $aktion,
                'aenderungen' => json_encode($changes, JSON_UNESCAPED_UNICODE),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('bestelleingang_log insert failed', ['eingang_id' => $eingangId, 'err' => $e->getMessage()]);
        }
    }

    /**
     * Before/after diff of one wizard save → list of {feld, von, nach}
     * entries: header fields, the Leistung selection and per-day adds /
     * removes / field changes (matched by tag_uid, datum+times fallback).
     * Frozen (booked) days appear unchanged because $daysAfter already holds
     * their re-applied stored values — no false positives.
     */
    protected function diffEingangChanges($before, array $update, array $artsBefore, array $artsAfter, $daysBefore, $daysAfter): array
    {
        $changes = [];
        $eq = fn ($a, $b) => trim((string) ($a ?? '')) === trim((string) ($b ?? ''));

        $periode = fn ($m, $y) => str_pad((string) $m, 2, '0', STR_PAD_LEFT) . '/' . $y;
        if (!$eq($before->monat ?? null, $update['monat'] ?? null) || !$eq($before->jahr ?? null, $update['jahr'] ?? null)) {
            $changes[] = ['feld' => 'Periode', 'von' => $periode($before->monat, $before->jahr), 'nach' => $periode($update['monat'], $update['jahr'])];
        }
        $zeit = fn ($v, $b) => (trim((string) self::hhmm($v) . '–' . (string) self::hhmm($b), '–') ?: '–');
        if (!$eq(self::hhmm($before->zeit_von ?? null), self::hhmm($update['zeit_von'] ?? null)) || !$eq(self::hhmm($before->zeit_bis ?? null), self::hhmm($update['zeit_bis'] ?? null))) {
            $changes[] = ['feld' => 'Zeit', 'von' => $zeit($before->zeit_von ?? null, $before->zeit_bis ?? null), 'nach' => $zeit($update['zeit_von'] ?? null, $update['zeit_bis'] ?? null)];
        }
        $numFmt = fn ($v) => is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : null;
        foreach ([['ort', 'Ort / Treffpunkt', 'str'], ['modell', 'Modell', 'str'], ['kundenpreis', 'Kundenpreis', 'num'],
                  ['nu_preis', 'NU-Preis', 'num'], ['bemerkung', 'Bemerkung', 'str']] as [$f, $lbl, $kind]) {
            $aV = $before->{$f} ?? null;
            $bV = $update[$f] ?? null;
            $same = $kind === 'num' && is_numeric($aV) && is_numeric($bV)
                ? (float) $aV === (float) $bV
                : $eq($aV, $bV);
            if (array_key_exists($f, $update) && !$same) {
                $fmt = fn ($v) => $kind === 'num' && is_numeric($v)
                    ? $numFmt($v)
                    : (trim((string) $v) !== '' ? (string) $v : '–');
                $changes[] = ['feld' => $lbl, 'von' => $fmt($aV), 'nach' => $fmt($bV)];
            }
        }
        $ab = array_values(array_map('strval', $artsBefore)); sort($ab);
        $aa = array_values(array_map('strval', $artsAfter)); sort($aa);
        if ($ab !== $aa) {
            $changes[] = ['feld' => 'Leistungen', 'von' => $ab ? implode(', ', $ab) : '–', 'nach' => $aa ? implode(', ', $aa) : '–'];
        }

        if ($daysBefore !== null && $daysAfter !== null) {
            $key = fn ($d) => trim((string) ($d->tag_uid ?? '')) !== ''
                ? 'u:' . $d->tag_uid
                : 'k:' . substr((string) ($d->datum ?? ''), 0, 10) . '|' . (string) self::hhmm($d->zeit_von ?? null)
                    . '|' . (string) self::hhmm($d->zeit_bis ?? null) . '|' . (string) ($d->artikel_id ?? '') . '|' . (string) ($d->tag_nr ?? '');
            $mapB = []; foreach ($daysBefore as $d) $mapB[$key($d)] = $d;
            $mapA = []; foreach ($daysAfter as $d) $mapA[$key($d)] = $d;
            $dayLine = fn ($d) => (string) self::hhmm($d->zeit_von ?? null) . '–' . (string) self::hhmm($d->zeit_bis ?? null)
                . ' · ' . (float) ($d->menge ?? 0) . ' Pers.';
            $dayName = fn ($d) => 'Tag ' . date('d.m.', strtotime((string) ($d->datum ?? '')));
            $dayDiff = [];
            foreach ($mapA as $k => $d) {
                if (!isset($mapB[$k])) {
                    $dayDiff[] = ['feld' => $dayName($d), 'von' => '–', 'nach' => $dayLine($d) . ' — hinzugefügt'];
                    continue;
                }
                $o = $mapB[$k];
                $flds = [];
                foreach ([['zeit_von', 'Zeit', 'zeit'], ['zeit_bis', 'Zeit', 'zeit'], ['soll_std', 'Soll-Std', 'num'],
                          ['pause_minuten', 'Pause', 'num'], ['gesetzl_pause', 'K-Pause', 'num'], ['menge', 'Menge', 'num'],
                          ['ort', 'Ort', 'str'], ['beschreibung', 'Beschreibung', 'str'], ['artikel_id', 'Leistung', 'str']] as [$f, $lbl, $kind]) {
                    $norm = $kind === 'num'
                        ? fn ($v) => (float) ($v ?? 0)
                        : ($kind === 'zeit' ? fn ($v) => (string) self::hhmm($v) : fn ($v) => trim((string) ($v ?? '')));
                    if ($norm($o->{$f} ?? null) !== $norm($d->{$f} ?? null) && !in_array($lbl, $flds, true)) $flds[] = $lbl;
                }
                // anforderungen is a JSON blob — loose-compare the decoded
                // payload so key order never produces a phantom change.
                if (json_decode((string) ($o->anforderungen ?? ''), true) != json_decode((string) ($d->anforderungen ?? ''), true)) {
                    $flds[] = 'Anforderungen';
                }
                if ($flds) {
                    $dayDiff[] = ['feld' => $dayName($d), 'von' => $dayLine($o), 'nach' => $dayLine($d) . ' (' . implode(', ', $flds) . ')'];
                }
            }
            foreach ($mapB as $k => $d) {
                if (!isset($mapA[$k])) $dayDiff[] = ['feld' => $dayName($d), 'von' => $dayLine($d), 'nach' => 'entfernt'];
            }
            usort($dayDiff, fn ($x, $y) => strcmp($x['feld'], $y['feld']));
            $changes = array_merge($changes, array_slice($dayDiff, 0, 15));
            if (count($dayDiff) > 15) {
                $changes[] = ['feld' => 'Tage', 'von' => '–', 'nach' => '… und ' . (count($dayDiff) - 15) . ' weitere Tag-Änderungen'];
            }
        }
        return $changes;
    }

    protected function saveDays(string $eingangId, array $tage): void
    {
        $hasAnf = $this->col('bestelleingang_tage', 'anforderungen');
        $hasOrt = $this->col('bestelleingang_tage', 'ort');
        $hasTagArtikel = $this->col('bestelleingang_tage', 'artikel_id');
        foreach ($tage as $tag) {
            $insert = [
                'eingang_id' => $eingangId,
                'tag_nr' => $tag['tag_nr'] ?? 1,
                'datum' => $tag['datum'],
                'wochentag' => $tag['wochentag'] ?? null,
                'zeit_von' => $tag['zeit_von'] ?? null,
                'zeit_bis' => $tag['zeit_bis'] ?? null,
                'soll_std' => $tag['soll_std'] ?? null,
                'pause_minuten' => isset($tag['pause_minuten']) && $tag['pause_minuten'] !== '' ? intval($tag['pause_minuten']) : null,
                'gesetzl_pause' => $tag['gesetzl_pause'] ?? null,
                'menge' => $tag['menge'] ?? null,
                'beschreibung' => $tag['beschreibung'] ?? null,
                'status' => $tag['status'] ?? 'offen',
                'bemerkung' => $tag['bemerkung'] ?? null,
            ];
            if ($hasAnf) {
                $insert['anforderungen'] = $this->normalizeAnforderungen($tag['anforderungen'] ?? null);
            }
            if ($hasOrt) {
                $insert['ort'] = isset($tag['ort']) && trim((string) $tag['ort']) !== '' ? trim((string) $tag['ort']) : null;
            }
            if ($hasTagArtikel) {
                $insert['artikel_id'] = isset($tag['artikel_id']) && trim((string) $tag['artikel_id']) !== ''
                    ? trim((string) $tag['artikel_id']) : null;
            }
            if ($this->hasTagUid()) {
                $insert['tag_uid'] = $tag['tag_uid'] ?? null;
            }
            DB::connection('tenant')->table('bestelleingang_tage')->insert($insert);
        }
    }

    /**
     * Memoized Schema::hasColumn (each call is an information_schema query —
     * a save used to run several per day row). Per controller instance =
     * per request, keyed by tenant DB so Octane can never leak across tenants.
     */
    protected function col(string $table, string $column): bool
    {
        $k = (string) DB::connection('tenant')->getDatabaseName() . '.' . $table . '.' . $column;
        return $this->colCache[$k] ??= Schema::connection('tenant')->hasColumn($table, $column);
    }

    /** Preload crew for these Einsätze so isStaffedShift() needs no query per shift. */
    protected function preloadCrew(array $ids): void
    {
        $ids = array_map('strval', $ids);
        $this->crewSet = $this->crewIds($ids) + ['__ids' => array_flip($ids)];
    }

    /** einsatz_ids among $ids that have crew (internal or NU workers) — ONE query each. */
    protected function crewIds(array $ids): array
    {
        if (!$ids) return [];
        return array_flip(array_map('strval', array_merge(
            DB::connection('tenant')->table('einsatz_mitarbeiter')
                ->whereIn('einsatz_id', $ids)->distinct()->pluck('einsatz_id')->all(),
            DB::connection('tenant')->table('nu_schicht_positionen as p')
                ->join('nu_position_zuweisungen as z', 'z.position_id', '=', 'p.id')
                ->whereIn('p.einsatz_id', $ids)->distinct()->pluck('p.einsatz_id')->all()
        )));
    }

    /** tag_uid columns present (migration 2026_10_27_120000)? Memoized per request. */
    protected function hasTagUid(): bool
    {
        return $this->tagUidCols ??= $this->col('bestelleingang_tage', 'tag_uid')
            && $this->col('einsaetze', 'tag_uid');
    }

    /**
     * Give every day row a stable tag_uid (the fixed key between day row and
     * Einsatz). Keeps a valid client uid, else inherits the uid of the
     * matched Einsatz, else generates one. Duplicates (e.g. a duplicated row
     * that copied its uid) get a fresh one — first occurrence wins.
     */
    protected function assignTagUids(array $tage, array $assignedIdx = []): array
    {
        if (!$this->hasTagUid()) return $this->uniqueTagNrs($tage, $assignedIdx);
        $seen = [];
        foreach ($tage as $i => &$tag) {
            if (!is_array($tag)) continue;
            $uid = strtolower(trim((string) ($tag['tag_uid'] ?? '')));
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uid)) {
                $uid = strtolower(trim((string) (($assignedIdx[$i] ?? null)->tag_uid ?? '')));
            }
            if ($uid === '' || isset($seen[$uid])) $uid = (string) \Illuminate\Support\Str::uuid();
            $seen[$uid] = true;
            $tag['tag_uid'] = $uid;
        }
        unset($tag);
        return $this->uniqueTagNrs($tage, $assignedIdx);
    }

    /**
     * tag_nr must be unique per order: Dispo, Zeiterfassung, worker app and
     * Abrechnung join bestelleingang_tage ON datum + tag_nr, so two rows with
     * the same number multiply every Einsatz of that day (one shift shown 3×,
     * one edit "applied" to all copies, hours counted 3×). A duplicated row
     * kept its source's number — first occurrence wins, copies and rows
     * without a number get max+1 (the Einsatz sync then takes the new number).
     * Rows whose matched Einsatz already carries their number claim it first,
     * so a planned Einsatz never loses its number to a copy.
     */
    protected function uniqueTagNrs(array $tage, array $assignedIdx = []): array
    {
        $max = 0;
        foreach ($tage as $tag) {
            if (is_array($tag) && (int) ($tag['tag_nr'] ?? 0) > $max) $max = (int) $tag['tag_nr'];
        }
        $seen = [];
        $keep = [];
        foreach ($tage as $i => $tag) {
            $nr = is_array($tag) ? (int) ($tag['tag_nr'] ?? 0) : 0;
            $e = $assignedIdx[$i] ?? null;
            if ($nr > 0 && !isset($seen[$nr]) && $e && (int) ($e->tag_nr ?? 0) === $nr) { $seen[$nr] = true; $keep[$i] = true; }
        }
        foreach ($tage as $i => &$tag) {
            if (!is_array($tag) || isset($keep[$i])) continue;
            $nr = (int) ($tag['tag_nr'] ?? 0);
            if ($nr <= 0 || isset($seen[$nr])) $nr = ++$max;
            $seen[$nr] = true;
            $tag['tag_nr'] = $nr;
        }
        unset($tag);
        return $tage;
    }

    /** Order belongs to a month by its period OR by any Einsatztag in it (multi-month orders). */
    protected function monthMatchSql(int $monat, int $jahr): array
    {
        $from = sprintf('%04d-%02d-01', $jahr, $monat);
        $to = date('Y-m-t', strtotime($from));
        return [
            '((be.monat = ? AND be.jahr = ?) OR EXISTS (SELECT 1 FROM bestelleingang_tage bt_f
                WHERE bt_f.eingang_id = be.eingang_id AND bt_f.datum BETWEEN ? AND ?))',
            [$monat, $jahr, $from, $to],
        ];
    }

    /** Error text when payload days that are not yet stored fall outside monat/jahr, else null. */
    protected function monthError($tage, int $monat, int $jahr, array $storedDates): ?string
    {
        if (!is_array($tage) || $monat < 1 || $jahr < 1) return null;
        $stored = array_flip($storedDates);
        $outside = [];
        foreach ($tage as $t) {
            $d = is_array($t) ? substr((string) ($t['datum'] ?? ''), 0, 10) : '';
            if ($d === '' || isset($stored[$d])) continue;
            if ((int) substr($d, 5, 2) !== $monat || (int) substr($d, 0, 4) !== $jahr) $outside[$d] = true;
        }
        if (!$outside) return null;
        $list = array_map(fn ($d) => date('d.m.Y', strtotime($d)), array_keys($outside));
        sort($list);
        return 'Folgende Tage liegen außerhalb des Bestellmonats ' . sprintf('%02d/%04d', $monat, $jahr) . ': '
            . implode(', ', array_slice($list, 0, 8)) . (count($list) > 8 ? ' …' : '')
            . '. Bitte für einen anderen Monat eine eigene Bestellung anlegen (Kopieren).';
    }

    /** Optimistic lock: the client's loaded updated_at no longer matches. */
    protected function isStaleEdit(array $data, ?object $row): bool
    {
        if (!$row || !array_key_exists('expected_updated_at', $data)) return false;
        $norm = static fn ($v) => $v === null || $v === '' ? '' : date('Y-m-d H:i:s', strtotime((string) $v));
        return $norm($data['expected_updated_at']) !== $norm($row->updated_at ?? null);
    }

    protected function staleEditResponse()
    {
        return response()->json([
            'error' => 'Die Bestellung wurde inzwischen von jemand anderem geändert. '
                . 'Bitte schließen und neu öffnen — Ihre Änderungen wurden NICHT gespeichert.',
            'code' => 'version_conflict',
        ], 409);
    }

    /** Trimmed Ort string or null for blank input. */
    protected function normalizeOrt($val): ?string
    {
        $t = trim((string) ($val ?? ''));
        return $t === '' ? null : $t;
    }

    /**
     * All selected artikel_ids of a multi-service order, deduped, strings.
     * artikel_ids[0] is the primary (mirrored into the legacy artikel_id
     * column). Falls back to the single artikel_id for old clients.
     */
    protected function normalizeArtikelIds(array $data): array
    {
        $raw = $data['artikel_ids'] ?? null;
        if (is_string($raw)) { // stored JSON column when reading a row
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $raw = is_array($raw) ? $raw : [];
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) $v), $raw)
        )));
        if (empty($ids) && !empty($data['artikel_id'])) {
            $ids = [trim((string) $data['artikel_id'])];
        }
        return $ids;
    }

    /**
     * Per-artikel pricing map for the artikel_preise JSON column. Only
     * selected artikel ids are kept — a crafted payload cannot smuggle
     * pricing for artikel that are not part of this order.
     */
    protected function normalizeArtikelPreise($raw, array $artikelIds): ?string
    {
        if (empty($artikelIds)) return null;
        $raw = is_array($raw) ? $raw : [];
        $masters = DB::connection('tenant')->table('artikel')
            ->whereIn('artikel_id', $artikelIds)->get()->keyBy('artikel_id');
        $out = [];
        foreach ($artikelIds as $aid) {
            $p = is_array($raw[$aid] ?? null) ? $raw[$aid] : [];
            $master = $masters->get($aid);
            $out[$aid] = [
                'modell'      => isset($p['modell']) && trim((string) $p['modell']) !== ''
                    ? trim((string) $p['modell']) : ($master->modell ?? null),
                'kundenpreis' => isset($p['kundenpreis']) && $p['kundenpreis'] !== ''
                    ? (float) $p['kundenpreis'] : ($master !== null ? (float) $master->preis_kunde : null),
                'nu_preis'    => isset($p['nu_preis']) && $p['nu_preis'] !== ''
                    ? (float) $p['nu_preis'] : ($master !== null ? (float) $master->nu_preis : null),
                'bemerkung'   => isset($p['bemerkung']) && trim((string) $p['bemerkung']) !== ''
                    ? trim((string) $p['bemerkung']) : null,
            ];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The artikel a single day row belongs to. A day may only reference a
     * SELECTED artikel of this order — anything else falls back to the
     * primary so a crafted tag cannot create an einsatz under a foreign
     * artikel/bereich.
     */
    protected function tagArtikelId(array $tag, array $artikelIds, ?string $fallback): ?string
    {
        $a = trim((string) ($tag['artikel_id'] ?? ''));
        return ($a !== '' && in_array($a, $artikelIds, true)) ? $a : $fallback;
    }

    /**
     * The Ort that reaches the Einsatz (Dispo + worker app):
     *   1. the day's own Tag-Ort, if set
     *   2. otherwise the order-level Ort / Treffpunkt (Schicht-Vorlage)
     *   3. otherwise none.
     * bestelleingang_tage.ort keeps ONLY the Tag-Ort so the two stay separable.
     */
    protected function resolveEinsatzOrt(array $tag, ?string $headerOrt): ?string
    {
        return $this->normalizeOrt($tag['ort'] ?? null) ?? $headerOrt;
    }

    /**
     * Normalize the incoming anforderungen payload to a JSON string for storage.
     * Accepts an array (json_encode) or a pre-encoded JSON string. Returns null
     * for empty / blank / empty-container values so the column stays clean.
     */
    protected function normalizeAnforderungen($val): ?string
    {
        if ($val === null) return null;
        if (is_array($val)) {
            $clean = array_filter($val, fn($v) => $v !== null && $v !== '' && $v !== []);
            return empty($clean) ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_string($val)) {
            $t = trim($val);
            return ($t === '' || $t === 'null' || $t === '{}' || $t === '[]') ? null : $val;
        }
        return null;
    }

    private static function hhmm($v): ?string
    {
        return ($v !== null && preg_match('/(\d{1,2}):(\d{2})/', (string) $v, $m))
            ? str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] : null;
    }

    /**
     * Pair every day row with an existing einsatz of the same date. Several
     * shifts on one date (e.g. 06–18 and 18–06) must each find THEIR einsatz:
     * pass 1 only pairs rows with an einsatz of the same start/end time,
     * pass 2 pairs the rest. Without the time pass a day shift could grab the
     * (protected, staffed) night shift and the night row would create a
     * duplicate. Used by syncDownstream AND the update() removal check, so
     * "which shift does a removed row hit" is answered the same way.
     *
     * @return array{0: array<int, object>, 1: string[]} [row index → einsatz, matched einsatz_ids]
     */
    protected function matchDaysToEinsaetze(array $tage, $existing, array $data, array $artikelIds): array
    {
        $byDatum = [];
        foreach ($existing as $e) $byDatum[substr((string) $e->datum, 0, 10)][] = $e;
        // Protected = confirmed, or planned with workers (times frozen). Same
        // time → the protected shift wins (it cannot move); different time →
        // an unprotected shift is preferred, it can take the new times.
        $ids = collect($existing)->pluck('einsatz_id')->all();
        $staffed = $ids ? array_flip(array_merge(
            DB::connection('tenant')->table('einsatz_mitarbeiter')
                ->whereIn('einsatz_id', $ids)->distinct()->pluck('einsatz_id')->all(),
            DB::connection('tenant')->table('nu_schicht_positionen as p')
                ->join('nu_position_zuweisungen as z', 'z.position_id', '=', 'p.id')
                ->whereIn('p.einsatz_id', $ids)->distinct()->pluck('p.einsatz_id')->all()
        )) : [];
        $isFrozen = static fn ($e) => $e->hms_bestaetigt === 'ja'
            || (($e->pruefung ?? '') === 'geplant' && isset($staffed[$e->einsatz_id]));

        $assigned = [];
        $matchedIds = [];
        // Pass 0: the stable key. A row that carries the tag_uid of an
        // Einsatz on the same date IS that Einsatz — no heuristics needed.
        $byUid = [];
        foreach ($existing as $e) {
            if (!empty($e->tag_uid ?? null)) $byUid[strtolower((string) $e->tag_uid)] = $e;
        }
        foreach ($tage as $i => $tag) {
            $uid = strtolower(trim((string) ($tag['tag_uid'] ?? '')));
            if ($uid === '' || empty($tag['datum']) || !isset($byUid[$uid])) continue;
            $e = $byUid[$uid];
            if (substr((string) $e->datum, 0, 10) !== substr((string) $tag['datum'], 0, 10)) continue;
            if (in_array($e->einsatz_id, $matchedIds, true)) continue;
            $assigned[$i] = $e;
            $matchedIds[] = $e->einsatz_id;
        }
        // Legacy rows without a key: date + time + service heuristics.
        foreach ([true, false] as $exactTimePass) {
            foreach ($tage as $i => $tag) {
                if (isset($assigned[$i]) || empty($tag['datum'])) continue;
                $datum = substr($tag['datum'], 0, 10);
                if (empty($byDatum[$datum])) continue;
                $von = self::hhmm($tag['zeit_von'] ?? $data['zeit_von'] ?? null);
                $bis = self::hhmm($tag['zeit_bis'] ?? $data['zeit_bis'] ?? null);
                // With multi-service orders the same datum can appear once per
                // artikel — prefer the einsatz of the row's own artikel
                // (same artikel+tag_nr > same artikel > same tag_nr).
                $tagArtikelForMatch = $this->tagArtikelId($tag, $artikelIds, $data['artikel_id'] ?? null);
                $best = null;
                $bestScore = PHP_INT_MIN;
                foreach ($byDatum[$datum] as $candidate) {
                    if (in_array($candidate->einsatz_id, $matchedIds, true)) continue;
                    $sameTime = $von !== null && $bis !== null
                        && self::hhmm($candidate->ist_start) === $von && self::hhmm($candidate->ist_ende) === $bis;
                    if ($exactTimePass && !$sameTime) continue;
                    $score = $isFrozen($candidate) ? ($exactTimePass ? 4 : -4) : 0;
                    $candArt = (string) ($candidate->artikel_id ?? '');
                    if ($tagArtikelForMatch !== null && $candArt === (string) $tagArtikelForMatch) $score += 2;
                    elseif ($tagArtikelForMatch !== null && $candArt !== '' && $candArt !== (string) $tagArtikelForMatch) $score -= 2;
                    if (!empty($tag['tag_nr']) && $candidate->tag_nr == $tag['tag_nr']) $score += 1;
                    if ($score > $bestScore) { $bestScore = $score; $best = $candidate; }
                }
                if ($best) {
                    $assigned[$i] = $best;
                    $matchedIds[] = $best->einsatz_id;
                }
            }
        }
        return [$assigned, $matchedIds];
    }

    protected function syncDownstream(string $eingangId, array $data): array
    {
        $tage = $data['tage'] ?? [];
        // Selected services of this order — day rows may carry their own
        // artikel_id (multi-service order); validated against this list.
        $artikelIds = $this->normalizeArtikelIds($data);

        // Order-level Ort (Schicht-Vorlage). Prefer the request value; if the
        // client did not send the key, use what is stored on the order.
        $headerOrt = array_key_exists('ort', $data)
            ? $this->normalizeOrt($data['ort'])
            : ($this->col('bestelleingang', 'ort')
                ? $this->normalizeOrt(DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $eingangId)->value('ort'))
                : null);

        // Find or re-create bestellung
        $bestellung = DB::connection('tenant')->table('bestellungen')
            ->where('eingang_id', $eingangId)->first();

        $bestellArtikelCols = $this->col('bestellungen', 'artikel_ids');
        if ($bestellung) {
            $bestell_id = $bestellung->bestell_id;
            $bestellUpdate = [
                'kunden_id' => $data['kunden_id'],
                'obj_id' => $data['obj_id'],
                'artikel_id' => $data['artikel_id'] ?? null,
                'monat' => intval($data['monat']),
                'jahr' => intval($data['jahr']),
                'zeit_von' => $data['zeit_von'] ?? null,
                'zeit_bis' => $data['zeit_bis'] ?? null,
                'nu_id' => $data['nu_id'] ?? null,
                'updated_at' => now(),
            ];
            if ($bestellArtikelCols) $bestellUpdate['artikel_ids'] = json_encode($artikelIds);
            DB::connection('tenant')->table('bestellungen')->where('bestell_id', $bestell_id)->update($bestellUpdate);
        } else {
            $bestell_id = IdGenerator::uniqueId('bestellungen', 'bestell_id', 'B-');
            $bestellInsert = [
                'bestell_id' => $bestell_id,
                'eingang_id' => $eingangId,
                'kunden_id' => $data['kunden_id'],
                'obj_id' => $data['obj_id'],
                'artikel_id' => $data['artikel_id'] ?? null,
                'monat' => intval($data['monat']),
                'jahr' => intval($data['jahr']),
                'zeit_von' => $data['zeit_von'] ?? null,
                'zeit_bis' => $data['zeit_bis'] ?? null,
                'nu_id' => $data['nu_id'] ?? null,
                'status' => 'offen',
            ];
            if ($bestellArtikelCols) $bestellInsert['artikel_ids'] = json_encode($artikelIds);
            DB::connection('tenant')->table('bestellungen')->insert($bestellInsert);
        }

        if (empty($tage)) {
            return ['bestell_id' => $bestell_id, 'tasks_created' => 0, 'tasks_updated' => 0];
        }

        // Get existing einsaetze grouped by datum + tag_nr for better matching
        $existing = DB::connection('tenant')->table('einsaetze')
            ->where('bestell_id', $bestell_id)->get();

        $existingByDatum = [];
        foreach ($existing as $e) {
            $existingByDatum[$e->datum][] = $e;
        }

        $tasksCreated = 0;
        $tasksUpdated = 0;
        $tasksProtected = 0;
        $matchedIds = [];
        $hasAnfEinsatz = $this->col('einsaetze', 'anforderungen');
        $hasOrtEinsatz = $this->col('einsaetze', 'ort');

        $this->preloadCrew($existing->pluck('einsatz_id')->all());
        [$assigned, $matchedIds] = $this->matchDaysToEinsaetze($tage, $existing, $data, $artikelIds);

        foreach ($tage as $i => $tag) {
            if (empty($tag['datum'])) continue;
            $datum = substr($tag['datum'], 0, 10);
            $newBeschreibung = $this->buildBeschreibung($tag['beschreibung'] ?? null, $tag['menge'] ?? null);
            $newOrt = $this->resolveEinsatzOrt($tag, $headerOrt);
            $matched = $assigned[$i] ?? null;

            if ($matched) {
                // Protect confirmed tasks and planned tasks with workers / NU workers
                $isProtected = $this->isStaffedShift($matched);

                if ($isProtected) {
                    $tasksProtected++;
                    // Booked shift: frozen (its day row was re-applied in
                    // update()). Only the link metadata follows the order —
                    // tag_nr (Dispo reads plan data through datum + tag_nr)
                    // and the stable tag_uid.
                    $meta = [];
                    if (!empty($tag['tag_nr']) && (int) $matched->tag_nr !== (int) $tag['tag_nr']) {
                        $meta['tag_nr'] = (int) $tag['tag_nr'];
                    }
                    if ($this->hasTagUid() && !empty($tag['tag_uid']) && ($matched->tag_uid ?? null) !== $tag['tag_uid']) {
                        $meta['tag_uid'] = $tag['tag_uid'];
                    }
                    // Reactivate a previously-locked shift (day re-added to
                    // Bestelleingang after being removed). Clear the tombstone
                    // so the card returns to normal in Dispo.
                    if ($this->col('einsaetze', 'bestell_storno_at')
                        && ($matched->bestell_storno_at ?? null) !== null) {
                        $meta['bestell_storno_at'] = null;
                    }
                    if ($meta) {
                        DB::connection('tenant')->table('einsaetze')
                            ->where('einsatz_id', $matched->einsatz_id)->update($meta);
                    }
                    continue;
                }

                $istStart = $tag['zeit_von'] ?? $data['zeit_von'] ?? $matched->ist_start;
                $istEnde  = $tag['zeit_bis'] ?? $data['zeit_bis'] ?? $matched->ist_ende;

                $tagArtikel = $this->tagArtikelId($tag, $artikelIds, $data['artikel_id'] ?? null);
                $update = [
                    'kunden_id' => $data['kunden_id'],
                    'obj_id' => $data['obj_id'],
                    'artikel_id' => $tagArtikel,
                    'nu_id' => $data['nu_id'] ?? null,
                    'tag_nr' => $tag['tag_nr'] ?? $matched->tag_nr,
                    // An explicitly cleared pause (null) must reach Dispo —
                    // only a missing key keeps the stored value.
                    'gesetzl_pause' => array_key_exists('gesetzl_pause', $tag) ? $tag['gesetzl_pause'] : $matched->gesetzl_pause,
                    'ist_start' => $istStart,
                    'ist_ende' => $istEnde,
                    'ist_std_menge' => $tag['soll_std'] ?? $matched->ist_std_menge,
                    'pause_minuten' => array_key_exists('pause_minuten', $tag) ? $tag['pause_minuten'] : $matched->pause_minuten,
                    'beschreibung' => $newBeschreibung,
                ];
                if ($this->hasTagUid() && !empty($tag['tag_uid'])) {
                    $update['tag_uid'] = $tag['tag_uid'];
                }
                if ($hasAnfEinsatz) {
                    $update['anforderungen'] = $this->normalizeAnforderungen($tag['anforderungen'] ?? null);
                }
                if ($hasOrtEinsatz) {
                    $update['ort'] = $newOrt;
                }

                if ($this->col('einsaetze', 'schichtart')) {
                    $tagArtikelRow = $this->loadArtikelForClassification($tagArtikel);
                    $cls = SchichtartCalculator::compute($datum, $istStart, $istEnde, $tagArtikelRow, SchichtartCalculator::cachedFeiertage($data['obj_id'] ?? null));
                    $update['schichtart']   = $cls['schichtart'];
                    $update['zuschlag_pct'] = $cls['zuschlag_pct'];
                }

                DB::connection('tenant')->table('einsaetze')
                    ->where('einsatz_id', $matched->einsatz_id)->update($update);

                // Times are only changed here (Dispo cannot) — an NU that got
                // the request must learn about it (mail after commit).
                $oldS = self::hhmm($matched->ist_start); $oldE = self::hhmm($matched->ist_ende);
                $newS = self::hhmm($istStart); $newE = self::hhmm($istEnde);
                if (($oldS !== $newS || $oldE !== $newE) && $newS && $newE) {
                    $this->collectNuTimeChange($matched, (string) $oldS, (string) $oldE, $newS, $newE);
                }

                // Plan-mirrored worker actuals follow the new pause so
                // ist_std never contradicts it.
                $oldEff = $matched->pause_minuten;
                $newEff = $update['pause_minuten'];
                WorkerAppZeitController::reconcilePauseAfterPlanChange(
                    (string) $matched->einsatz_id,
                    $oldEff !== null ? (int) $oldEff : null,
                    $newEff !== null ? (int) $newEff : null
                );
                $tasksUpdated++;
            } else {
                $einsatz_id = IdGenerator::einsatzId($datum);
                $istStart   = $tag['zeit_von'] ?? $data['zeit_von'] ?? null;
                $istEnde    = $tag['zeit_bis'] ?? $data['zeit_bis'] ?? null;
                $tagArtikel = $this->tagArtikelId($tag, $artikelIds, $data['artikel_id'] ?? null);
                $row = [
                    'einsatz_id' => $einsatz_id,
                    'bestell_id' => $bestell_id,
                    'kunden_id' => $data['kunden_id'],
                    'obj_id' => $data['obj_id'],
                    'artikel_id' => $tagArtikel,
                    'nu_id' => $data['nu_id'] ?? null,
                    'datum' => $datum,
                    'tag_nr' => $tag['tag_nr'] ?? null,
                    'beschreibung' => $newBeschreibung,
                    'ist_start' => $istStart,
                    'ist_ende' => $istEnde,
                    'ist_std_menge' => $tag['soll_std'] ?? null,
                    'pause_minuten' => $tag['pause_minuten'] ?? null,
                    'gesetzl_pause' => $tag['gesetzl_pause'] ?? null,
                    'hms_bestaetigt' => 'ausstehend',
                ];
                if ($this->hasTagUid()) {
                    $row['tag_uid'] = $tag['tag_uid'] ?? null;
                }
                if ($hasAnfEinsatz) {
                    $row['anforderungen'] = $this->normalizeAnforderungen($tag['anforderungen'] ?? null);
                }
                if ($hasOrtEinsatz) {
                    $row['ort'] = $newOrt;
                }
                if ($this->col('einsaetze', 'schichtart')) {
                    $tagArtikelRow = $this->loadArtikelForClassification($tagArtikel);
                    $cls = SchichtartCalculator::compute($datum, $istStart, $istEnde, $tagArtikelRow, SchichtartCalculator::cachedFeiertage($data['obj_id'] ?? null));
                    $row['schichtart']   = $cls['schichtart'];
                    $row['zuschlag_pct'] = $cls['zuschlag_pct'];
                }
                DB::connection('tenant')->table('einsaetze')->insert($row);
                $tasksCreated++;
            }
        }

        // Remove einsaetze no longer in the day list — protect confirmed and planned-with-workers
        $hasStornoCol = $this->col('einsaetze', 'bestell_storno_at');
        foreach ($existing as $e) {
            if (in_array($e->einsatz_id, $matchedIds)) continue;

            // Confirmed / staffed planned shifts block day removal — the
            // caller (IncomingOrderController::update) pre-checks this and
            // aborts with an error; this is a defense-in-depth guard.
            $isProtected = $this->isStaffedShift($e);

            if ($isProtected) {
                // LOCK the shift instead of unlinking: keep bestell_id so
                // plan_menge (required worker count) stays available via the
                // bestelleingang_tage join. Set the tombstone timestamp so
                // the planner card renders grayed-out and read-only in Dispo.
                // If the day is re-added later, the reactivation block above
                // clears bestell_storno_at and the shift returns to normal.
                $update = [];
                if ($hasStornoCol) {
                    $update['bestell_storno_at'] = now();
                } else {
                    // Fallback for tenants without the migration: preserve
                    // the old behavior (unlink) so the shift survives.
                    $update['bestell_id'] = null;
                }
                if (!empty($update)) {
                    DB::connection('tenant')->table('einsaetze')
                        ->where('einsatz_id', $e->einsatz_id)
                        ->update($update);
                }
                // A tombstoned 'geplant' shift is dead for its crew but the
                // workers' phones still show a live plan. Send the same
                // storniert notice the planner sends on plan withdrawal so
                // worker app and Dispo never silently diverge. (Confirmed
                // tombstones stay quiet: they only occur via defense paths.)
                if (($e->pruefung ?? '') === 'geplant') {
                    $this->notifyWorkersTombstoneStorno($e);
                }
            } else {
                // Same cleanup as deleting the shift in Dispo / deleting the
                // order: NU positions (requested from a subcontractor) must
                // not stay behind orphaned, and the NU learns about it.
                $this->collectNuCancellation($e);
                $posIds = DB::connection('tenant')->table('nu_schicht_positionen')
                    ->where('einsatz_id', $e->einsatz_id)->pluck('id')->all();
                if ($posIds) {
                    DB::connection('tenant')->table('nu_position_zuweisungen')->whereIn('position_id', $posIds)->delete();
                }
                DB::connection('tenant')->table('nu_schicht_positionen')->where('einsatz_id', $e->einsatz_id)->delete();
                DB::connection('tenant')->table('einsatz_nu_status')->where('einsatz_id', $e->einsatz_id)->delete();
                WorkerAppZeitController::closeOpenShiftsForEinsatz(
                    (string) $e->einsatz_id, [], 'Tag im Bestelleingang entfernt — Schicht automatisch beendet'
                );
                DB::connection('tenant')->table('einsatz_mitarbeiter')
                    ->where('einsatz_id', $e->einsatz_id)->delete();
                DB::connection('tenant')->table('einsaetze')
                    ->where('einsatz_id', $e->einsatz_id)->delete();
            }
        }

        return ['bestell_id' => $bestell_id, 'tasks_created' => $tasksCreated, 'tasks_updated' => $tasksUpdated, 'tasks_protected' => $tasksProtected];
    }

    /**
     * Staffed = its times/crew are frozen against order edits and its day
     * cannot be removed: confirmed, or planned with internal workers OR NU
     * workers (a NU-only shift is just as booked as one with own staff).
     */
    protected function isStaffedShift(object $e): bool
    {
        if (($e->hms_bestaetigt ?? null) === 'ja') return true;
        if (($e->pruefung ?? '') !== 'geplant') return false;
        $id = (string) $e->einsatz_id;
        if ($this->crewSet !== null && array_key_exists('__ids', $this->crewSet) && isset($this->crewSet['__ids'][$id])) {
            return isset($this->crewSet[$id]);
        }
        return DB::connection('tenant')->table('einsatz_mitarbeiter')->where('einsatz_id', $id)->exists()
            || DB::connection('tenant')->table('nu_schicht_positionen as p')
                ->join('nu_position_zuweisungen as z', 'z.position_id', '=', 'p.id')
                ->where('p.einsatz_id', $id)->exists();
    }

    /** NU cancellation notices of shifts removed by an order edit — sent after commit. */
    private array $nuCancellations = [];

    private function collectNuCancellation(object $e): void
    {
        $nuIds = DB::connection('tenant')->table('nu_schicht_positionen')
            ->where('einsatz_id', $e->einsatz_id)->distinct()->pluck('nu_id')->filter()->all();
        if (!empty($e->nu_id) && !in_array($e->nu_id, $nuIds, true)) $nuIds[] = $e->nu_id;
        if (!$nuIds) return;
        $names = DB::connection('tenant')->table('einsaetze as x')
            ->leftJoin('kunden as k', 'x.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'x.obj_id', '=', 'o.obj_id')
            ->where('x.einsatz_id', $e->einsatz_id)->first(['k.kundenname', 'o.objektname']);
        foreach (DB::connection('tenant')->table('nachunternehmer')->whereIn('nu_id', $nuIds)->get() as $nu) {
            if (empty($nu->email) || !filter_var($nu->email, FILTER_VALIDATE_EMAIL)) continue;
            $this->nuCancellations[] = [
                'email' => $nu->email, 'firmenname' => $nu->firmenname ?? 'Nachunternehmer',
                'datum' => $e->datum ?? '-', 'zeit' => ($e->ist_start ?? '-') . ' - ' . ($e->ist_ende ?? '-'),
                'objekt' => $names->objektname ?? '-', 'kunde' => $names->kundenname ?? '-',
            ];
        }
    }

    /** NU time-change notices of shifts re-timed by an order edit — sent after commit. */
    private array $nuTimeChanges = [];

    private function collectNuTimeChange(object $e, string $oldS, string $oldE, string $newS, string $newE): void
    {
        $nus = DB::connection('tenant')->table('nu_schicht_positionen as p')
            ->join('nachunternehmer as n', 'n.nu_id', '=', 'p.nu_id')
            ->where('p.einsatz_id', $e->einsatz_id)->distinct()->get(['n.firmenname', 'n.email']);
        if ($nus->isEmpty()) return;
        $names = DB::connection('tenant')->table('einsaetze as x')
            ->leftJoin('kunden as k', 'x.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'x.obj_id', '=', 'o.obj_id')
            ->where('x.einsatz_id', $e->einsatz_id)->first(['k.kundenname', 'o.objektname']);
        foreach ($nus as $nu) {
            if (empty($nu->email) || !filter_var($nu->email, FILTER_VALIDATE_EMAIL)) continue;
            $this->nuTimeChanges[] = [
                'email' => $nu->email, 'firmenname' => $nu->firmenname, 'kunde' => $names->kundenname ?? '-',
                'objekt' => $names->objektname ?? '-', 'datum' => $e->datum ?? '-',
                'oldStart' => $oldS, 'oldEnd' => $oldE, 'newStart' => $newS, 'newEnd' => $newE,
            ];
        }
    }

    private function flushNuCancellations(): void
    {
        $companyName = TenantService::current()->name ?? config('app.name', 'Clood-x');
        foreach ($this->nuTimeChanges as $n) {
            try {
                Mail::to($n['email'])->queue(new \App\Mail\NuNotificationMail(
                    blade: 'emails.nu-time-change',
                    subjectLine: "Zeitänderung Einsatz — {$n['datum']} | {$companyName}",
                    payload: ['company' => $companyName] + array_diff_key($n, ['email' => 1]),
                ));
            } catch (\Exception $mailEx) {
                \Log::error('NU time change email failed: ' . $mailEx->getMessage());
            }
        }
        $this->nuTimeChanges = [];
        if (!$this->nuCancellations) return;
        foreach ($this->nuCancellations as $n) {
            try {
                Mail::to($n['email'])->queue(new \App\Mail\NuNotificationMail(
                    blade: 'emails.nu-shift-cancelled',
                    subjectLine: "Einsatz storniert - {$n['datum']} | {$companyName}",
                    payload: ['company' => $companyName] + array_diff_key($n, ['email' => 1]),
                ));
            } catch (\Exception $mailEx) {
                \Log::error('NU cancellation email failed: ' . $mailEx->getMessage());
            }
        }
        $this->nuCancellations = [];
    }

    /**
     * Crew notice when a day removal tombstones a 'geplant' shift: same
     * shape as DispositionController::notifyWorkersShiftCancelled so the
     * worker app renders it identically.
     */
    protected function notifyWorkersTombstoneStorno($einsatz): void
    {
        try {
            $workerIds = DB::connection('tenant')->table('einsatz_mitarbeiter')
                ->where('einsatz_id', $einsatz->einsatz_id)
                ->pluck('dienst_nr')->all();
            if (empty($workerIds)) return;

            $workers = DB::connection('tenant')->table('mitarbeiter')
                ->whereIn('dienst_nr', $workerIds)
                ->get(['id', 'dienst_nr', 'vorname', 'name', 'email']);
            if ($workers->isEmpty()) return;

            $ort = null;
            if (!empty($einsatz->obj_id)) {
                $ort = DB::connection('tenant')->table('objekte')
                    ->where('obj_id', $einsatz->obj_id)->value('objektname');
            }
            if (!$ort && !empty($einsatz->kunden_id)) {
                $ort = DB::connection('tenant')->table('kunden')
                    ->where('kunde_id', $einsatz->kunden_id)->value('kundenname');
            }

            $datumFmt = $einsatz->datum ? \Carbon\Carbon::parse($einsatz->datum)->format('d.m.Y') : '–';
            $zeit  = substr((string) $einsatz->ist_start, 0, 5) . ' – ' . substr((string) $einsatz->ist_ende, 0, 5);
            $title = 'Einsatz storniert: ' . $datumFmt;
            $body  = ($ort ?: 'Einsatz') . ' · ' . $zeit;

            foreach ($workers as $w) {
                NotificationDispatcher::notifyWorker(
                    $w, 'shift.storniert', $title, $body,
                    ['einsatz_id' => $einsatz->einsatz_id, 'datum' => $einsatz->datum]
                );
            }
        } catch (\Throwable $err) {
            \Log::warning('notifyWorkersTombstoneStorno failed: ' . $err->getMessage());
        }
    }

    /**
     * Memoized artikel lookup used by Schichtart classification. A single
     * incoming-order save may create dozens of einsätze for the same artikel
     * — only hit the DB once.
     */
    protected function loadArtikelForClassification(?string $artikelId)
    {
        if (!$artikelId) return null;
        $cache = &$this->artikelClassificationCache;
        if (array_key_exists($artikelId, $cache)) return $cache[$artikelId];
        if (!Schema::connection('tenant')->hasTable('artikel')) return $cache[$artikelId] = null;
        if (!$this->col('artikel', 'zuschlaege_aktiv')) return $cache[$artikelId] = null;
        return $cache[$artikelId] = DB::connection('tenant')->table('artikel')
            ->where('artikel_id', $artikelId)->first();
    }

    protected function buildBeschreibung(?string $beschreibung, $menge): ?string
    {
        $mengeVal = intval($menge);
        $parts = [];
        if ($mengeVal > 0) {
            $parts[] = $mengeVal . ' Mitarbeiter benötigt';
        }
        if ($beschreibung && trim($beschreibung) !== '') {
            $parts[] = trim($beschreibung);
        }
        return !empty($parts) ? implode(' · ', $parts) : null;
    }

    protected function validateIncomingData(array $data): array
    {
        $errors = [];

        if (empty($data['kunden_id'])) $errors[] = 'Kunde ist erforderlich';
        if (empty($data['obj_id'])) $errors[] = 'Objekt ist erforderlich';
        // Ort / Treffpunkt is the fallback location for every day without its
        // own Tag-Ort — the worker app relies on it, so it must not be empty.
        if (trim((string)($data['ort'] ?? '')) === '') $errors[] = 'Ort / Treffpunkt ist erforderlich';

        $monat = intval($data['monat'] ?? 0);
        if ($monat < 1 || $monat > 12) $errors[] = 'Ungültiger Monat';

        $jahr = intval($data['jahr'] ?? 0);
        if ($jahr < 2020 || $jahr > 2099) $errors[] = 'Ungültiges Jahr';

        // Validate time format if provided
        if (!empty($data['zeit_von']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $data['zeit_von'])) {
            $errors[] = 'Ungültiges Zeitformat für Von';
        }
        if (!empty($data['zeit_bis']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $data['zeit_bis'])) {
            $errors[] = 'Ungültiges Zeitformat für Bis';
        }

        $models = ['Standard', 'Pro Einsatz', 'Pro Stunde', 'Monatspauschale'];
        if (!empty($data['modell']) && !in_array($data['modell'], $models, true)) {
            $errors[] = 'Ungültiges Abrechnungsmodell';
        }

        foreach (['kundenpreis' => 'Kundenpreis', 'nu_preis' => 'NU-Preis'] as $field => $label) {
            if (isset($data[$field]) && $data[$field] !== null && $data[$field] !== '') {
                if (!is_numeric($data[$field]) || (float) $data[$field] < 0 || (float) $data[$field] > 99999999.99) {
                    $errors[] = 'Ungültiger ' . $label;
                }
            }
        }

        // Same range rule for the per-service price map — a crafted payload
        // must not park a negative price under an artikel key.
        if (!empty($data['artikel_preise']) && is_array($data['artikel_preise'])) {
            foreach ($data['artikel_preise'] as $aid => $p) {
                if (!is_array($p)) {
                    $errors[] = 'Ungültige Preisdaten für Artikel ' . $aid;
                    continue;
                }
                if (!empty($p['modell']) && !in_array($p['modell'], $models, true)) {
                    $errors[] = 'Ungültiges Abrechnungsmodell für Artikel ' . $aid;
                }
                foreach (['kundenpreis' => 'Kundenpreis', 'nu_preis' => 'NU-Preis'] as $field => $label) {
                    if (isset($p[$field]) && $p[$field] !== null && $p[$field] !== ''
                        && (!is_numeric($p[$field]) || (float) $p[$field] < 0 || (float) $p[$field] > 99999999.99)) {
                        $errors[] = 'Ungültiger ' . $label . ' für Artikel ' . $aid;
                    }
                }
            }
        }

        // Validate days if provided
        if (!empty($data['tage'])) {
            if (!is_array($data['tage'])) {
                $errors[] = 'Tage muss ein Array sein';
            } else {
                foreach ($data['tage'] as $idx => $tag) {
                    if (empty($tag['datum'])) {
                        $errors[] = "Tag " . ($idx + 1) . ": Datum fehlt";
                    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', substr($tag['datum'], 0, 10))
                        || !strtotime(substr($tag['datum'], 0, 10))) {
                        $errors[] = "Tag " . ($idx + 1) . ": Ungültiges Datumsformat";
                    }
                    // Beschreibung is Pflicht — the header text is the shared
                    // fallback for every day without its own (wizard rule).
                    if (trim((string) ($tag['beschreibung'] ?? '')) === '') {
                        $errors[] = "Tag " . ($idx + 1) . ": Beschreibung fehlt";
                    }
                    foreach (['zeit_von', 'zeit_bis'] as $timeField) {
                        if (!empty($tag[$timeField])
                            && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $tag[$timeField])) {
                            $errors[] = 'Tag ' . ($idx + 1) . ': Ungültige Uhrzeit';
                        }
                    }
                    foreach (['soll_std' => [0, 24, 'Soll-Stunden'], 'pause_minuten' => [0, 1440, 'MA-Pause'],
                              'gesetzl_pause' => [0, 1440, 'Kunden-Pause'], 'menge' => [1, 100, 'Menge']] as $field => [$min, $max, $label]) {
                        if (!isset($tag[$field]) || $tag[$field] === '') continue;
                        if (!is_numeric($tag[$field]) || (float) $tag[$field] < $min || (float) $tag[$field] > $max) {
                            $errors[] = 'Tag ' . ($idx + 1) . ': Ungültige ' . $label;
                        }
                    }
                }
            }
        }

        return $errors;
    }

    protected function validateForeignKeys(array $data)
    {
        // Validate customer exists
        $customerExists = DB::connection('tenant')->table('kunden')
            ->where('kunde_id', $data['kunden_id'])->exists();
        if (!$customerExists) {
            return response()->json(['error' => 'Kunde nicht gefunden: ' . $data['kunden_id']], 400);
        }

        // Validate object exists AND belongs to customer (strict)
        $objectExists = DB::connection('tenant')->table('objekte')
            ->where('obj_id', $data['obj_id'])
            ->where('kunden_id', $data['kunden_id'])
            ->exists();
        if (!$objectExists) {
            $objAny = DB::connection('tenant')->table('objekte')->where('obj_id', $data['obj_id'])->exists();
            if (!$objAny) {
                return response()->json(['error' => 'Objekt nicht gefunden: ' . $data['obj_id']], 400);
            }
            return response()->json(['error' => 'Objekt gehört nicht zu diesem Kunden'], 400);
        }

        // Validate EVERY selected artikel — each must belong to the chosen
        // object (multi-service orders submit artikel_ids; the normalized
        // primary is already in artikel_id).
        $artikelIds = $this->normalizeArtikelIds($data);
        foreach ($artikelIds as $aid) {
            $artikelOk = DB::connection('tenant')->table('artikel')
                ->where('artikel_id', $aid)
                ->where('obj_id', $data['obj_id'])
                ->exists();
            if (!$artikelOk) {
                return response()->json(['error' => 'Artikel nicht gefunden oder gehört nicht zum gewählten Objekt: ' . $aid], 400);
            }
        }

        // Validate NU if provided
        if (!empty($data['nu_id'])) {
            $nuExists = DB::connection('tenant')->table('nachunternehmer')
                ->where('nu_id', $data['nu_id'])->exists();
            if (!$nuExists) {
                return response()->json(['error' => 'Nachunternehmer nicht gefunden: ' . $data['nu_id']], 400);
            }
        }

        return null; // No error
    }
}
