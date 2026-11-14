<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationDispatcher;
use App\Http\Controllers\Controller;
use App\Mail\PlainNotificationMail;
use App\Models\CentralUser;
use App\Services\TenantService;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Aufgaben (tasks) — admin side.
 *
 * Office-only (user decision): recipients are the company's office users
 * (Benutzerverwaltung / central_users), never field workers. Every office
 * user may create tasks and sees the tasks assigned to him plus the ones he
 * created; owners / administrators see all tasks of the company.
 * Recipients get bell + Clood-X app push + e-mail; each has his own status.
 */
class AufgabenController extends Controller
{
    public const PRIORITAETEN = ['niedrig', 'normal', 'hoch', 'dringend'];
    public const STATUS = ['offen', 'in_arbeit', 'erledigt'];

    private function t(string $table)
    {
        return DB::connection('tenant')->table($table);
    }

    private function ready(): bool
    {
        return Schema::connection('tenant')->hasTable('aufgaben')
            && Schema::connection('tenant')->hasTable('aufgabe_empfaenger');
    }

    private function isAdmin($u): bool
    {
        return $u && ($u->is_owner || in_array($u->role, ['tenant_admin', 'super_admin', 'admin'], true));
    }

    private static function userName($u): string
    {
        return trim((string) ($u->full_name ?? '')) ?: ((string) ($u->name ?? '') ?: (string) ($u->email ?? ''));
    }

    /** Task visible to this user? (creator, recipient, or admin) */
    private function canSee($u, $aufgabe): bool
    {
        if ($this->isAdmin($u) || (int) $aufgabe->created_by === (int) $u->id) return true;
        return $this->t('aufgabe_empfaenger')->where('aufgabe_id', $aufgabe->id)
            ->where('typ', 'user')->where('user_id', $u->id)->exists();
    }

    /** Recipients grouped per task + overall progress. */
    private function attachEmpfaenger($rows)
    {
        $ids = collect($rows)->pluck('id')->all();
        $emp = $ids ? $this->t('aufgabe_empfaenger')->whereIn('aufgabe_id', $ids)->orderBy('id')->get()->groupBy('aufgabe_id') : collect();
        foreach ($rows as $r) {
            $list = ($emp[$r->id] ?? collect())->values();
            $r->empfaenger = $list;
            $r->anzahl = $list->count();
            $r->erledigt = $list->where('status', 'erledigt')->count();
            $r->status = $r->anzahl && $r->erledigt === $r->anzahl ? 'erledigt'
                : ($list->whereIn('status', ['in_arbeit', 'erledigt'])->count() ? 'in_arbeit' : 'offen');
        }
        return $rows;
    }

    // ================= LIST =================

    /** GET /aufgaben?ansicht=meine|erstellt|alle&status=offen|erledigt|alle&q= */
    public function index(Request $request)
    {
        if (!$this->ready()) return response()->json(['data' => [], 'is_admin' => false]);
        $u = $request->user();
        $ansicht = $request->query('ansicht', 'meine');
        $q = $this->t('aufgaben as a')->select('a.*');

        if ($ansicht === 'erstellt') {
            $q->where('a.created_by', $u->id);
        } elseif ($ansicht === 'alle' && $this->isAdmin($u)) {
            // everything
        } elseif ($ansicht === 'alle') {
            $q->where(function ($w) use ($u) {
                $w->where('a.created_by', $u->id)->orWhereExists(fn ($x) => $x->select(DB::raw(1))
                    ->from('aufgabe_empfaenger as e')->whereColumn('e.aufgabe_id', 'a.id')
                    ->where('e.typ', 'user')->where('e.user_id', $u->id));
            });
        } else { // meine = assigned to me
            $q->whereExists(fn ($x) => $x->select(DB::raw(1))->from('aufgabe_empfaenger as e')
                ->whereColumn('e.aufgabe_id', 'a.id')->where('e.typ', 'user')->where('e.user_id', $u->id));
        }
        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $q->where(fn ($w) => $w->where('a.titel', 'like', $s)->orWhere('a.beschreibung', 'like', $s)->orWhere('a.bereich', 'like', $s));
        }
        $rows = $this->attachEmpfaenger($q->orderByDesc('a.id')->limit(500)->get());

        // My own status on tasks assigned to me (for the "Meine" view).
        foreach ($rows as $r) {
            $mine = collect($r->empfaenger)->first(fn ($e) => $e->typ === 'user' && (int) $e->user_id === (int) $u->id);
            $r->mein_empfaenger_id = $mine->id ?? null;
            $r->mein_status = $mine->status ?? null;
        }
        $status = $request->query('status', 'offen');
        if ($status !== 'alle') {
            $rows = $rows->filter(function ($r) use ($status, $ansicht) {
                $st = ($ansicht === 'meine' && $r->mein_status) ? $r->mein_status : $r->status;
                return $status === 'erledigt' ? $st === 'erledigt' : $st !== 'erledigt';
            })->values();
        }
        return response()->json(['data' => $rows, 'is_admin' => $this->isAdmin($u)]);
    }

    /** GET /aufgaben/counts — open tasks assigned to me (nav badge). */
    public function counts(Request $request)
    {
        if (!$this->ready()) return response()->json(['offen' => 0]);
        $n = $this->t('aufgabe_empfaenger')->where('typ', 'user')
            ->where('user_id', $request->user()->id)->where('status', '!=', 'erledigt')->count();
        return response()->json(['offen' => $n]);
    }

    /** GET /aufgaben/options — Bereiche + possible recipients. */
    public function options(Request $request)
    {
        $u = $request->user();
        $bereiche = Schema::connection('tenant')->hasTable('bereiche_katalog')
            ? $this->t('bereiche_katalog')->whereNull('parent_id')->where('aktiv', 1)->orderBy('sortierung')->orderBy('name')->pluck('name')->all()
            : [];
        $allowed = BereichScope::for($u);
        if (is_array($allowed)) $bereiche = array_values(array_filter($bereiche, fn ($b) => BereichScope::listAllows($allowed, $b)));

        // Office users only; their Bereich whitelist lets the UI put the
        // users responsible for the chosen Bereich first.
        $users = CentralUser::where('tenant_id', $u->tenant_id)->where('aktiv', 1)
            ->where('name', 'not like', 'deleted\\_%')->where('name', '!=', 'demo-viewer')
            ->orderBy('full_name')->get(['id', 'full_name', 'name', 'email', 'role', 'is_owner', 'allowed_bereiche'])
            ->map(fn ($x) => [
                'id' => (int) $x->id, 'name' => self::userName($x), 'ich' => (int) $x->id === (int) $u->id,
                'rolle' => $x->is_owner ? 'Inhaber' : (in_array($x->role, ['tenant_admin', 'admin', 'super_admin'], true) ? 'Administrator' : 'Benutzer'),
                'bereiche' => array_values(array_filter((array) (is_array($x->allowed_bereiche) ? $x->allowed_bereiche : (json_decode((string) $x->allowed_bereiche, true) ?: [])))),
            ]);

        return response()->json(['bereiche' => $bereiche, 'users' => $users]);
    }

    public function show(Request $request, $id)
    {
        if (!$this->ready()) return response()->json(['error' => 'Aufgaben noch nicht verfügbar'], 409);
        $a = $this->t('aufgaben')->where('id', $id)->first();
        if (!$a || !$this->canSee($request->user(), $a)) return response()->json(['error' => 'Aufgabe nicht gefunden'], 404);
        $a = $this->attachEmpfaenger(collect([$a]))->first();
        $mine = collect($a->empfaenger)->first(fn ($e) => $e->typ === 'user' && (int) $e->user_id === (int) $request->user()->id);
        if ($mine && !$mine->gelesen_am) {
            $this->t('aufgabe_empfaenger')->where('id', $mine->id)->update(['gelesen_am' => now()]);
        }
        $a->mein_empfaenger_id = $mine->id ?? null;
        $a->darf_loeschen = $this->isAdmin($request->user()) || (int) $a->created_by === (int) $request->user()->id;
        return response()->json($a);
    }

    // ================= CREATE =================

    public function store(Request $request)
    {
        if (!$this->ready()) return response()->json(['error' => 'Aufgaben noch nicht verfügbar (Migration ausstehend)'], 409);
        $d = $request->validate([
            'titel'              => 'required|string|max:200',
            'beschreibung'       => 'nullable|string|max:5000',
            'bereich'            => 'nullable|string|max:190',
            'prioritaet'         => 'nullable|in:' . implode(',', self::PRIORITAETEN),
            'faellig_am'         => 'nullable|date',
            'empfaenger'         => 'required|array|min:1|max:200',
            'empfaenger.*.typ'   => 'required|in:user',
            'empfaenger.*.id'    => 'required',
        ]);
        $u = $request->user();
        $tenant = TenantService::current();

        // Resolve + validate every recipient (scope for workers, same company for users).
        $workers = []; $users = [];
        foreach ($d['empfaenger'] as $e) {
            if ($e['typ'] === 'mitarbeiter') $workers[(string) $e['id']] = true; else $users[(int) $e['id']] = true;
        }
        $workerRows = collect();
        if ($workers) {
            [$wSql, $wParams] = BereichScope::workerPredicate($u, 'mitarbeiter');
            $workerRows = $this->t('mitarbeiter')->whereIn('dienst_nr', array_keys($workers))
                ->where('aktiv', 1)->whereRaw($wSql, $wParams)->get();
            if ($workerRows->count() !== count($workers)) return response()->json(['error' => 'Ungültiger oder inaktiver Mitarbeiter ausgewählt'], 422);
        }
        $userRows = collect();
        if ($users) {
            $userRows = CentralUser::where('tenant_id', $u->tenant_id)->where('aktiv', 1)->whereIn('id', array_keys($users))->get();
            if ($userRows->count() !== count($users)) return response()->json(['error' => 'Ungültiger Benutzer ausgewählt'], 422);
        }

        $aufgabeId = DB::connection('tenant')->transaction(function () use ($d, $u, $workerRows, $userRows) {
            $id = $this->t('aufgaben')->insertGetId([
                'titel'           => trim($d['titel']),
                'beschreibung'    => isset($d['beschreibung']) ? trim($d['beschreibung']) : null,
                'bereich'         => $d['bereich'] ?? null,
                'prioritaet'      => $d['prioritaet'] ?? 'normal',
                'faellig_am'      => $d['faellig_am'] ?? null,
                'created_by'      => $u->id,
                'created_by_name' => self::userName($u),
                'created_at'      => now(), 'updated_at' => now(),
            ]);
            $rows = [];
            foreach ($workerRows as $w) {
                $rows[] = ['aufgabe_id' => $id, 'typ' => 'mitarbeiter', 'dienst_nr' => $w->dienst_nr, 'user_id' => null,
                    'name' => trim($w->vorname . ' ' . $w->name), 'status' => 'offen', 'created_at' => now(), 'updated_at' => now()];
            }
            foreach ($userRows as $x) {
                $rows[] = ['aufgabe_id' => $id, 'typ' => 'user', 'dienst_nr' => null, 'user_id' => $x->id,
                    'name' => self::userName($x), 'status' => 'offen', 'created_at' => now(), 'updated_at' => now()];
            }
            $this->t('aufgabe_empfaenger')->insert($rows);
            return $id;
        });

        $this->notifyNew($aufgabeId, $u, $workerRows, $userRows, $tenant);
        \App\Helpers\Realtime::changed('aufgaben');
        return response()->json(['id' => $aufgabeId, 'empfaenger' => $workerRows->count() + $userRows->count()], 201);
    }

    /**
     * Task linked to a Lead-Modul record (bezug_typ lead|deal|kontakt) for
     * one office user. Notifies the assignee unless he created it himself.
     * Completion runs through the normal recipient status (updateStatus).
     */
    public static function createLinked(array $d, $creator, int $assigneeId, bool $notify = true): int
    {
        $self = new self();
        $assignee = CentralUser::where('tenant_id', $creator->tenant_id)->where('id', $assigneeId)->first();
        $id = DB::connection('tenant')->transaction(function () use ($self, $d, $creator, $assignee, $assigneeId) {
            $id = $self->t('aufgaben')->insertGetId([
                'titel'           => trim($d['titel']),
                'beschreibung'    => $d['beschreibung'] ?? null,
                'bereich'         => $d['bereich'] ?? null,
                'bezug_typ'       => $d['bezug_typ'] ?? null,
                'bezug_id'        => $d['bezug_id'] ?? null,
                'typ'             => $d['typ'] ?? null,
                'prioritaet'      => $d['prioritaet'] ?? 'normal',
                'faellig_am'      => $d['faellig_am'] ?? null,
                'faellig_zeit'    => $d['faellig_zeit'] ?? null,
                'created_by'      => $creator->id,
                'created_by_name' => self::userName($creator),
                'created_at'      => now(), 'updated_at' => now(),
            ]);
            $self->t('aufgabe_empfaenger')->insert([
                'aufgabe_id' => $id, 'typ' => 'user', 'dienst_nr' => null, 'user_id' => $assigneeId,
                'name' => $assignee ? self::userName($assignee) : null, 'status' => 'offen',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return $id;
        });
        if ($notify && $assignee && (int) $assigneeId !== (int) $creator->id) {
            try {
                $self->notifyNew($id, $creator, collect(), collect([$assignee]), TenantService::current());
            } catch (\Throwable $e) {
                Log::warning('Linked Aufgabe notify failed', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }
        \App\Helpers\Realtime::changed('aufgaben');
        return $id;
    }

    /** Text block shared by app notification, push and e-mail. */
    private function taskText($a): string
    {
        $lines = [];
        if ($a->bereich) $lines[] = 'Bereich: ' . $a->bereich;
        if ($a->prioritaet && $a->prioritaet !== 'normal') $lines[] = 'Priorität: ' . ucfirst($a->prioritaet);
        if ($a->faellig_am) $lines[] = 'Fällig: ' . \Carbon\Carbon::parse($a->faellig_am)->format('d.m.Y');
        if ($a->beschreibung) $lines[] = "\n" . $a->beschreibung;
        return implode("\n", $lines);
    }

    private function notifyNew(int $aufgabeId, $creator, $workerRows, $userRows, $tenant): void
    {
        $a = $this->t('aufgaben')->where('id', $aufgabeId)->first();
        $from = self::userName($creator);
        $title = 'Neue Aufgabe: ' . $a->titel;
        $text = $this->taskText($a);
        $company = $tenant->name ?? null;

        foreach ($workerRows as $w) {
            // In-app row + device push + e-mail (if the worker has one).
            NotificationDispatcher::notifyWorker($w, 'aufgabe.neu', $title,
                "Von {$from}" . ($text ? "\n" . $text : '') . "\n\nIn der App unter Start → Aufgaben als erledigt markieren.",
                ['aufgabe_id' => $aufgabeId]);
        }

        if ($userRows->isNotEmpty() && $tenant) {
            $ids = $userRows->pluck('id')->map(fn ($i) => (int) $i)->all();
            // Bell + realtime + app push (Clood-X app) …
            NotificationDispatcher::notifyUserIds((int) $tenant->id, $ids, 'aufgabe.neu', $title,
                "Von {$from}" . ($text ? ' · ' . str_replace("\n", ' · ', trim($text)) : ''), '#aufgaben', ['aufgabe_id' => $aufgabeId]);
            // … and an e-mail (user decision: tasks are e-mailed too).
            $url = rtrim((string) config('app.url', ''), '/');
            foreach ($userRows as $x) {
                if (empty($x->email) || !$x->wantsNotification('aufgabe.neu', 'email')) continue;
                try {
                    Mail::to($x->email, self::userName($x))->queue(new PlainNotificationMail(
                        $title . ($company ? ' | ' . $company : ''),
                        "Hallo " . self::userName($x) . ",\n\n{$from} hat Ihnen eine Aufgabe zugewiesen:\n\n" . $a->titel
                        . ($text ? "\n" . $text : '') . ($url ? "\n\nÖffnen: {$url}/index.php#aufgaben" : '')
                    ));
                } catch (\Throwable $e) {
                    Log::warning('Aufgabe mail failed', ['to' => $x->email, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    // ================= STATUS =================

    /** PUT /aufgaben/empfaenger/{id}  { status, kommentar } — by the recipient (or creator/admin). */
    public function updateStatus(Request $request, $empfaengerId)
    {
        if (!$this->ready()) return response()->json(['error' => 'Aufgaben noch nicht verfügbar'], 409);
        $d = $request->validate([
            'status'    => 'required|in:' . implode(',', self::STATUS),
            'kommentar' => 'nullable|string|max:2000',
        ]);
        $u = $request->user();
        $e = $this->t('aufgabe_empfaenger')->where('id', $empfaengerId)->first();
        $a = $e ? $this->t('aufgaben')->where('id', $e->aufgabe_id)->first() : null;
        if (!$e || !$a) return response()->json(['error' => 'Aufgabe nicht gefunden'], 404);
        $isRecipient = $e->typ === 'user' && (int) $e->user_id === (int) $u->id;
        if (!$isRecipient && !$this->isAdmin($u) && (int) $a->created_by !== (int) $u->id) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }
        self::applyStatus($e, $a, $d['status'], $d['kommentar'] ?? null, self::userName($u), (int) $u->id);
        return response()->json(['ok' => true]);
    }

    /**
     * Shared by admin + worker app: store the status and tell the creator
     * when a recipient finished (bell + app push).
     */
    public static function applyStatus($e, $a, string $status, ?string $kommentar, string $byName, ?int $byUserId = null): void
    {
        $upd = ['status' => $status, 'updated_at' => now(), 'erledigt_am' => $status === 'erledigt' ? now() : null];
        if ($kommentar !== null) $upd['kommentar'] = trim($kommentar) !== '' ? trim($kommentar) : null;
        if (!$e->gelesen_am) $upd['gelesen_am'] = now();
        DB::connection('tenant')->table('aufgabe_empfaenger')->where('id', $e->id)->update($upd);
        \App\Helpers\Realtime::changed('aufgaben');

        if ($status === 'erledigt' && $e->status !== 'erledigt' && $a->created_by && (int) $a->created_by !== (int) $byUserId) {
            try {
                $tenant = TenantService::current();
                if ($tenant) {
                    NotificationDispatcher::notifyUserIds((int) $tenant->id, [(int) $a->created_by], 'aufgabe.erledigt',
                        'Aufgabe erledigt: ' . $a->titel,
                        $byName . ' hat die Aufgabe erledigt' . (!empty($upd['kommentar']) ? ': ' . $upd['kommentar'] : '.'),
                        '#aufgaben', ['aufgabe_id' => (int) $a->id]);
                }
            } catch (\Throwable $ex) { /* best effort */ }
        }
    }

    public function destroy(Request $request, $id)
    {
        if (!$this->ready()) return response()->json(['error' => 'Aufgaben noch nicht verfügbar'], 409);
        $u = $request->user();
        $a = $this->t('aufgaben')->where('id', $id)->first();
        if (!$a) return response()->json(['error' => 'Aufgabe nicht gefunden'], 404);
        if (!$this->isAdmin($u) && (int) $a->created_by !== (int) $u->id) {
            return response()->json(['error' => 'Nur der Ersteller oder ein Administrator kann die Aufgabe löschen'], 403);
        }
        $this->t('aufgabe_empfaenger')->where('aufgabe_id', $id)->delete();
        $this->t('aufgaben')->where('id', $id)->delete();
        \App\Helpers\Realtime::changed('aufgaben');
        return response()->json(['ok' => true]);
    }
}
