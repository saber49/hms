<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class NuPortalController extends Controller
{
    /**
     * NU Portal login — public, no auth required.
     * Hybrid: if tenant_slug provided (from URL), use only that tenant.
     * Otherwise, auto-detect by searching all tenants (rejects duplicates).
     */
    public function login(Request $request)
    {
        $request->validate([
            'username'    => 'required|string',
            'password'    => 'required|string',
            'tenant_slug' => 'nullable|string',
        ]);

        $matchedTenant = null;
        $matchedNu = null;

        // Path A: Slug provided (from URL) — use only that tenant (fast & secure)
        if ($request->filled('tenant_slug')) {
            $tenant = \App\Models\Tenant::where('slug', $request->tenant_slug)->where('aktiv', true)->first();
            if (!$tenant) {
                return response()->json(['error' => 'Ungültige Anmeldedaten oder Portal nicht aktiviert'], 401);
            }

            \App\Services\TenantService::switchToTenant($tenant);

            $nu = DB::connection('tenant')->table('nachunternehmer')
                ->where('portal_username', $request->username)
                ->first();

            if ($nu && $nu->portal_aktiv && Hash::check($request->password, $nu->portal_password)) {
                $matchedTenant = $tenant;
                $matchedNu = $nu;
            }
        }
        // Path B: No slug — search all tenants, reject if duplicate username
        else {
            $matches = [];
            $tenants = \App\Models\Tenant::where('aktiv', true)->get();

            foreach ($tenants as $tenant) {
                try {
                    \App\Services\TenantService::switchToTenant($tenant);

                    $nu = DB::connection('tenant')->table('nachunternehmer')
                        ->where('portal_username', $request->username)
                        ->first();

                    if ($nu && $nu->portal_aktiv && Hash::check($request->password, $nu->portal_password)) {
                        $matches[] = ['tenant' => $tenant, 'nu' => $nu];
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (count($matches) > 1) {
                return response()->json([
                    'error' => 'Dieser Benutzername existiert in mehreren Firmen. Bitte verwenden Sie den direkten Link Ihrer Firma.'
                ], 409);
            }

            if (count($matches) === 1) {
                $matchedTenant = $matches[0]['tenant'];
                $matchedNu = $matches[0]['nu'];
            }
        }

        if (!$matchedTenant || !$matchedNu) {
            return response()->json(['error' => 'Ungültige Anmeldedaten oder Portal nicht aktiviert'], 401);
        }

        \App\Services\TenantService::switchToTenant($matchedTenant);

        // Create a custom token for NU portal user
        $tokenPlain = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $tokenPlain);

        DB::connection('mysql')->table('personal_access_tokens')->insert([
            'tokenable_type' => 'nu_portal',
            'tokenable_id'   => $matchedNu->id,
            'name'           => 'nu-portal',
            'token'          => $tokenHash,
            'abilities'      => json_encode(['nu-portal', 'tenant_id' => $matchedTenant->id]),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json([
            'success' => true,
            'token'   => $tokenPlain,
            'user'    => [
                'nu_id'       => $matchedNu->nu_id,
                'firmenname'  => $matchedNu->firmenname,
                'username'    => $matchedNu->portal_username,
            ],
        ]);
    }

    public function shifts(Request $request)
    {
        $nuId = $request->attributes->get('nu_id') ?? $request->query('nu_id');
        if (!$nuId) return response()->json(['error' => 'nu_id erforderlich'], 400);

        // Determine week range
        $weekStart = $request->query('week_start');
        try {
            $start = $weekStart ? new \DateTime($weekStart) : new \DateTime();
        } catch (\Exception $e) {
            $start = new \DateTime();
        }
        $day = intval($start->format('N'));
        if ($day !== 1) $start->modify('-' . ($day - 1) . ' days');
        $end = (clone $start)->modify('+6 days');
        $startStr = $start->format('Y-m-d');
        $endStr = $end->format('Y-m-d');

        // Find einsatz IDs where this NU has positions (explicitly created by dispatcher)
        $nuEinsatzIds = DB::connection('tenant')
            ->table('nu_schicht_positionen')
            ->where('nu_id', $nuId)
            ->pluck('einsatz_id')
            ->unique()
            ->values()
            ->toArray();

        // Also include einsatz IDs where this NU has an explicit status (request was sent)
        // but only if positions still exist (to avoid stale/orphaned status records)
        $nuStatusEinsatzIds = DB::connection('tenant')
            ->table('einsatz_nu_status')
            ->where('nu_id', $nuId)
            ->whereExists(function ($query) use ($nuId) {
                $query->select(DB::raw(1))
                    ->from('nu_schicht_positionen')
                    ->whereColumn('nu_schicht_positionen.einsatz_id', 'einsatz_nu_status.einsatz_id')
                    ->where('nu_schicht_positionen.nu_id', $nuId);
            })
            ->pluck('einsatz_id')
            ->unique()
            ->values()
            ->toArray();

        // Clean up any orphaned status records (no positions remaining)
        DB::connection('tenant')
            ->table('einsatz_nu_status')
            ->where('nu_id', $nuId)
            ->whereNotExists(function ($query) use ($nuId) {
                $query->select(DB::raw(1))
                    ->from('nu_schicht_positionen')
                    ->whereColumn('nu_schicht_positionen.einsatz_id', 'einsatz_nu_status.einsatz_id')
                    ->where('nu_schicht_positionen.nu_id', $nuId);
            })
            ->delete();

        $allNuEinsatzIds = array_values(array_unique(array_merge($nuEinsatzIds, $nuStatusEinsatzIds)));

        // Only show shifts explicitly sent to this NU (not just because nu_id is set)
        if (empty($allNuEinsatzIds)) {
            return response()->json([
                'tasks'      => [],
                'week_start' => $startStr,
                'week_end'   => $endStr,
            ]);
        }

        $shifts = DB::connection('tenant')
            ->table('einsaetze as e')
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->whereIn('e.einsatz_id', $allNuEinsatzIds)
            ->whereBetween('e.datum', [$startStr, $endStr])
            ->where(function ($q) {
                $q->whereNull('e.hms_bestaetigt')
                  ->orWhere('e.hms_bestaetigt', '!=', 'ja');
            })
            // Grey (day removed from the order) = cancelled, not an open request.
            ->whereNull('e.bestell_storno_at')
            ->select('e.*', 'k.kundenname', 'o.objektname', 'o.adresse', 'o.plz', 'o.stadt', 'o.ansprechpartner', 'o.kontakt_telefon')
            ->orderBy('e.datum')->orderBy('e.ist_start')
            ->get();

        // Load positions + zuweisungen for each task
        $einsatzIds = $shifts->pluck('einsatz_id')->unique()->values()->toArray();

        // Load per-NU status for this NU
        $nuStatusMap = [];
        if (!empty($einsatzIds)) {
            $nuStatusMap = DB::connection('tenant')
                ->table('einsatz_nu_status')
                ->where('nu_id', $nuId)
                ->whereIn('einsatz_id', $einsatzIds)
                ->pluck('status', 'einsatz_id')
                ->toArray();
        }

        $positions = [];
        $zuweisungen = [];
        if (!empty($einsatzIds)) {
            $positions = DB::connection('tenant')
                ->table('nu_schicht_positionen')
                ->where('nu_id', $nuId)
                ->whereIn('einsatz_id', $einsatzIds)
                ->orderBy('position_nr')
                ->get();

            $positionIds = $positions->pluck('id')->toArray();
            if (!empty($positionIds)) {
                $zuweisungen = DB::connection('tenant')
                    ->table('nu_position_zuweisungen as z')
                    ->join('nu_portal_mitarbeiter as m', 'z.nu_mitarbeiter_id', '=', 'm.id')
                    ->whereIn('z.position_id', $positionIds)
                    ->select('z.*', 'm.vorname', 'm.name', 'm.personal_nr')
                    ->get();
            }
        }

        // Group positions by einsatz_id, zuweisungen by position_id
        $posByEinsatz = [];
        foreach ($positions as $pos) {
            $posByEinsatz[$pos->einsatz_id][] = $pos;
        }
        $zByPos = [];
        foreach ($zuweisungen as $z) {
            $zByPos[$z->position_id][] = $z;
        }

        // Build task list with nested positionen
        $tasks = $shifts->map(function ($task) use ($posByEinsatz, $zByPos, $nuStatusMap) {
            $task->nu_status = $nuStatusMap[$task->einsatz_id] ?? null;
            $taskPositions = $posByEinsatz[$task->einsatz_id] ?? [];
            $enriched = [];
            foreach ($taskPositions as $pos) {
                $posZuweisungen = $zByPos[$pos->id] ?? [];
                $enriched[] = [
                    'id'            => $pos->id,
                    'position_nr'   => $pos->position_nr,
                    'position_name' => $pos->position_name,
                    'anzahl'        => $pos->anzahl,
                    'geschlecht'    => $pos->geschlecht ?? null,
                    'geschlecht_mix' => isset($pos->geschlecht_mix) ? (json_decode($pos->geschlecht_mix, true) ?: $pos->geschlecht_mix) : null,
                    'qualifikation' => $pos->qualifikation,
                    'skills'        => $pos->skills,
                    'kleidung'      => $pos->kleidung,
                    'checkin_info'  => $pos->checkin_info,
                    'bemerkung'     => $pos->bemerkung,
                    'zugewiesen'    => count($posZuweisungen),
                    'zuweisungen'   => array_map(fn($z) => [
                        'nu_mitarbeiter_id' => $z->nu_mitarbeiter_id,
                        'vorname'           => $z->vorname,
                        'name'              => $z->name,
                        'personal_nr'       => $z->personal_nr,
                    ], $posZuweisungen),
                ];
            }
            $task->positionen = $enriched;
            return $task;
        });

        return response()->json([
            'tasks'      => $tasks->values(),
            'week_start' => $startStr,
            'week_end'   => $endStr,
        ]);
    }

    /** GET /nu-portal/config — which optional worker-form blocks this tenant uses. */
    public function config()
    {
        return response()->json(\App\Support\TenantProfile::securityFlags());
    }

    public function workers(Request $request)
    {
        $nuId = $request->attributes->get('nu_id') ?? $request->query('nu_id');
        if (!$nuId) return response()->json(['error' => 'nu_id erforderlich'], 400);

        return response()->json(
            DB::connection('tenant')->table('nu_portal_mitarbeiter')
                ->where('nu_id', $nuId)->orderByDesc('aktiv')->orderBy('vorname')->orderBy('name')->get()
        );
    }

    public function storeWorker(Request $request)
    {
        $data = $request->all();
        $data['nu_id'] = $request->attributes->get('nu_id') ?? ($data['nu_id'] ?? null);
        if (empty($data['nu_id'])) return response()->json(['error' => 'nu_id erforderlich'], 400);

        $id = DB::connection('tenant')->table('nu_portal_mitarbeiter')->insertGetId([
            'nu_id' => $data['nu_id'],
            'vorname' => $data['vorname'] ?? null,
            'name' => $data['name'] ?? null,
            'personal_nr' => $data['personal_nr'] ?? null,
            'geschlecht' => $data['geschlecht'] ?? null,
            'qualifikation' => $data['qualifikation'] ?? null,
            'skills' => $data['skills'] ?? null,
            'telefon' => $data['telefon'] ?? null,
            'email' => $data['email'] ?? null,
            'bemerkung' => $data['bemerkung'] ?? null,
            'aktiv' => $data['aktiv'] ?? 1,
        ]);

        return response()->json(['success' => true, 'id' => $id], 201);
    }

    public function updateWorker(Request $request, int $id)
    {
        // Ownership: only workers of the logged-in NU (same rule as photo/delete).
        $nuId = $request->attributes->get('nu_id');
        $exists = DB::connection('tenant')->table('nu_portal_mitarbeiter')
            ->where('id', $id)->where('nu_id', $nuId)->exists();
        if (!$exists) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        // Partial update: only the fields actually sent — a missing key must
        // not wipe the stored value.
        $update = [];
        foreach (['vorname', 'name', 'personal_nr', 'geschlecht', 'qualifikation', 'skills', 'telefon', 'email', 'bemerkung', 'aktiv'] as $f) {
            if ($request->has($f)) $update[$f] = $request->input($f);
        }
        if (array_key_exists('aktiv', $update)) $update['aktiv'] = (int) (bool) $update['aktiv'];
        if ($update) {
            DB::connection('tenant')->table('nu_portal_mitarbeiter')->where('id', $id)->update($update);
        }

        return response()->json(['success' => true]);
    }

    public function positions(Request $request)
    {
        $positionId = $request->query('einsatz_id'); // frontend sends position_id as einsatz_id
        if (!$positionId) return response()->json(['error' => 'einsatz_id erforderlich'], 400);

        $nuId = $request->attributes->get('nu_id') ?? $request->query('nu_id');

        $position = DB::connection('tenant')->table('nu_schicht_positionen')
            ->where('id', $positionId)->first();

        if (!$position || ($nuId && (string) $position->nu_id !== (string) $nuId)) {
            // Another NU's position (incl. its assigned workers) is not visible.
            return response()->json(['error' => 'Position nicht gefunden'], 404);
        }

        // Get assigned workers for this position
        $assigned = DB::connection('tenant')
            ->table('nu_position_zuweisungen as z')
            ->join('nu_portal_mitarbeiter as m', 'z.nu_mitarbeiter_id', '=', 'm.id')
            ->where('z.position_id', $positionId)
            ->select('m.*', 'z.id as zuweisung_id')
            ->get();

        $assignedIds = $assigned->pluck('id')->toArray();

        // Get available workers (not yet assigned to this position)
        $available = DB::connection('tenant')
            ->table('nu_portal_mitarbeiter')
            ->where('nu_id', $nuId ?? $position->nu_id)
            ->where('aktiv', 1)
            ->when(!empty($assignedIds), fn($q) => $q->whereNotIn('id', $assignedIds))
            ->orderBy('vorname')->orderBy('name')
            ->get();

        return response()->json([
            'position'  => $position,
            'assigned'  => $assigned,
            'available' => $available,
        ]);
    }

    /**
     * Assign a worker to a position.
     */
    public function assignWorker(Request $request)
    {
        $positionId = $request->input('position_id');
        $workerId   = $request->input('nu_mitarbeiter_id');
        $authNuId   = $request->attributes->get('nu_id');

        if (!$positionId || !$workerId) {
            return response()->json(['error' => 'position_id und nu_mitarbeiter_id erforderlich'], 400);
        }

        // Check position exists
        $position = DB::connection('tenant')->table('nu_schicht_positionen')->where('id', $positionId)->first();
        if (!$position) {
            return response()->json(['error' => 'Position nicht gefunden'], 404);
        }

        // Ownership: the position AND the worker must belong to the logged-in NU.
        // Without this, any NU could staff another NU's positions by guessing IDs.
        if ($authNuId && (string) $position->nu_id !== (string) $authNuId) {
            return response()->json(['error' => 'Position gehört nicht zu Ihrem Unternehmen'], 403);
        }
        $workerRow = DB::connection('tenant')->table('nu_portal_mitarbeiter')
            ->where('id', $workerId)->first(['nu_id', 'aktiv']);
        $workerNuId = $workerRow->nu_id ?? null;
        if ($workerNuId === null || ($authNuId && (string) $workerNuId !== (string) $authNuId)) {
            return response()->json(['error' => 'Mitarbeiter gehört nicht zu Ihrem Unternehmen'], 403);
        }
        if (!(int) ($workerRow->aktiv ?? 0)) {
            return response()->json(['error' => 'Mitarbeiter ist deaktiviert und kann nicht eingeplant werden'], 409);
        }

        // Confirmed shifts are locked — the lineup is part of the billing record.
        if ($this->einsatzConfirmed($position->einsatz_id)) {
            return response()->json(['error' => 'Einsatz ist bereits bestätigt — Zuweisungen können nicht mehr geändert werden'], 409);
        }
        $einsatz = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $position->einsatz_id)->first();
        if (!$einsatz || !empty($einsatz->bestell_storno_at ?? null)) {
            return response()->json(['error' => 'Einsatz wurde storniert — keine Zuweisung mehr möglich'], 409);
        }
        // The same person cannot work two overlapping shifts.
        $clash = \App\Support\NuLink::overlaps((int) $workerId, $einsatz);
        if ($clash) {
            return response()->json(['error' => 'Mitarbeiter ist zur gleichen Zeit bereits eingeplant: ' . implode('; ', $clash)], 409);
        }

        // Check not already assigned
        $exists = DB::connection('tenant')->table('nu_position_zuweisungen')
            ->where('position_id', $positionId)
            ->where('nu_mitarbeiter_id', $workerId)
            ->exists();
        if ($exists) {
            return response()->json(['error' => 'Mitarbeiter ist bereits zugewiesen'], 409);
        }

        // Check capacity
        $currentCount = DB::connection('tenant')->table('nu_position_zuweisungen')
            ->where('position_id', $positionId)->count();
        if ($currentCount >= $position->anzahl) {
            return response()->json(['error' => 'Position ist bereits voll besetzt'], 409);
        }

        DB::connection('tenant')->table('nu_position_zuweisungen')->insert([
            'position_id'       => $positionId,
            'nu_mitarbeiter_id' => $workerId,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Unassign a worker from a position.
     */
    public function unassignWorker(Request $request)
    {
        $positionId = $request->input('position_id');
        $workerId   = $request->input('nu_mitarbeiter_id');
        $authNuId   = $request->attributes->get('nu_id');

        if (!$positionId || !$workerId) {
            return response()->json(['error' => 'position_id und nu_mitarbeiter_id erforderlich'], 400);
        }

        // Ownership: position must belong to the logged-in NU (see assignWorker).
        $position = DB::connection('tenant')->table('nu_schicht_positionen')->where('id', $positionId)->first();
        if (!$position) {
            return response()->json(['error' => 'Position nicht gefunden'], 404);
        }
        if ($authNuId && (string) $position->nu_id !== (string) $authNuId) {
            return response()->json(['error' => 'Position gehört nicht zu Ihrem Unternehmen'], 403);
        }

        // Confirmed shifts are locked — the lineup is part of the billing record.
        if ($this->einsatzConfirmed($position->einsatz_id)) {
            return response()->json(['error' => 'Einsatz ist bereits bestätigt — Zuweisungen können nicht mehr geändert werden'], 409);
        }

        DB::connection('tenant')->table('nu_position_zuweisungen')
            ->where('position_id', $positionId)
            ->where('nu_mitarbeiter_id', $workerId)
            ->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Whether an einsatz is already confirmed (hms_bestaetigt = 'ja').
     */
    protected function einsatzConfirmed(?string $einsatzId): bool
    {
        if (!$einsatzId) return false;
        return DB::connection('tenant')->table('einsaetze')
            ->where('einsatz_id', $einsatzId)
            ->where('hms_bestaetigt', 'ja')
            ->exists();
    }

    /**
     * Upload photo for an NU portal worker.
     * Tenant-isolated path: nu_workers/{tenant_slug}/{worker_id}/foto_{timestamp}.{ext}
     * Old photos are deleted automatically (no orphans).
     */
    public function uploadPhoto(Request $request, int $id)
    {
        $request->validate(['foto' => 'required|image|max:5120']);

        // Verify the worker belongs to this NU (tenant + nu_id)
        $nuId = $request->attributes->get('nu_id');
        $worker = DB::connection('tenant')->table('nu_portal_mitarbeiter')
            ->where('id', $id)->where('nu_id', $nuId)->first();
        if (!$worker) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        $tenant = \App\Services\TenantService::current();
        $tenantSlug = $tenant ? $tenant->slug : 'default';

        $file = $request->file('foto');
        $ext = $file->getClientOriginalExtension();
        $filename = 'foto_' . time() . '.' . $ext;
        $storagePath = 'nu_workers/' . $tenantSlug . '/' . $id;
        $relativePath = $storagePath . '/' . $filename;

        $stored = $file->storeAs($storagePath, $filename, 'public');
        if (!$stored) {
            return response()->json(['error' => 'Datei konnte nicht gespeichert werden'], 500);
        }

        // Remove old photo (if any) — accept both legacy 'storage/...' and new 'storage/...'
        if ($worker->foto) {
            $oldRel = ltrim(preg_replace('#^storage/#', '', $worker->foto), '/');
            if ($oldRel && $oldRel !== $relativePath) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($oldRel);
            }
        }

        // DB stores 'storage/<path>' — keeps frontend URL prefix logic unchanged
        $dbValue = 'storage/' . $relativePath;
        DB::connection('tenant')->table('nu_portal_mitarbeiter')
            ->where('id', $id)
            ->update(['foto' => $dbValue]);

        return response()->json(['success' => true, 'path' => $dbValue, 'foto' => $dbValue]);
    }

    /**
     * Delete photo for an NU portal worker — also removes the physical file.
     */
    public function deletePhoto(Request $request, int $id)
    {
        $nuId = $request->attributes->get('nu_id');
        $worker = DB::connection('tenant')->table('nu_portal_mitarbeiter')
            ->where('id', $id)->where('nu_id', $nuId)->first();
        if (!$worker) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        if ($worker->foto) {
            $rel = ltrim(preg_replace('#^storage/#', '', $worker->foto), '/');
            if ($rel) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($rel);
            }
        }

        DB::connection('tenant')->table('nu_portal_mitarbeiter')
            ->where('id', $id)
            ->update(['foto' => null]);

        return response()->json(['success' => true]);
    }

    /**
     * Delete an NU portal worker — also cleans up their photo folder.
     */
    public function deleteWorker(Request $request, int $id)
    {
        $nuId = $request->attributes->get('nu_id');
        $worker = DB::connection('tenant')->table('nu_portal_mitarbeiter')
            ->where('id', $id)->where('nu_id', $nuId)->first();
        if (!$worker) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        // A worker with assignments carries billing/planning history (his
        // confirmed hours would vanish from the Abrechnung). Deactivate instead.
        $assigned = DB::connection('tenant')->table('nu_position_zuweisungen')
            ->where('nu_mitarbeiter_id', $id)->count();
        if ($assigned > 0) {
            return response()->json([
                'error' => 'Der Mitarbeiter ist ' . $assigned . ' Einsatz-Position(en) zugeordnet und kann nicht gelöscht werden. Bitte stattdessen deaktivieren.',
            ], 409);
        }

        // Clean up the worker's entire photo folder
        $tenant = \App\Services\TenantService::current();
        if ($tenant) {
            $folder = 'nu_workers/' . $tenant->slug . '/' . $id;
            try {
                \Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory($folder);
            } catch (\Exception $e) { /* ignore */ }
        }

        DB::connection('tenant')->table('nu_portal_mitarbeiter')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * POST /nu-portal/accept-shift — NU accepts a shift request.
     * Sets this NU's status to accepted. If ALL NUs accepted, marks einsatz as geplant.
     */
    public function acceptShift(Request $request)
    {
        $einsatzId = $request->input('einsatz_id');
        $nuId = $request->attributes->get('nu_id') ?? $request->input('nu_id');

        if (!$einsatzId) {
            return response()->json(['error' => 'einsatz_id erforderlich'], 400);
        }

        // Verify this NU is linked to this einsatz (via positions or direct nu_id)
        $hasPositions = DB::connection('tenant')->table('nu_schicht_positionen')
            ->where('einsatz_id', $einsatzId)
            ->where('nu_id', $nuId)
            ->exists();

        $hasDirectLink = false;
        if (!$hasPositions) {
            $hasDirectLink = DB::connection('tenant')->table('einsaetze')
                ->where('einsatz_id', $einsatzId)
                ->where('nu_id', $nuId)
                ->exists();
        }

        if (!$hasPositions && !$hasDirectLink) {
            return response()->json(['error' => 'Keine Zuordnung für diesen Einsatz gefunden'], 404);
        }

        // A confirmed shift can no longer be (re-)accepted — nothing to decide.
        if ($this->einsatzConfirmed($einsatzId)) {
            return response()->json(['error' => 'Einsatz ist bereits bestätigt'], 409);
        }
        if (DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->whereNotNull('bestell_storno_at')->exists()) {
            return response()->json(['error' => 'Einsatz wurde storniert'], 409);
        }

        // Update per-NU status
        DB::connection('tenant')->table('einsatz_nu_status')->updateOrInsert(
            ['einsatz_id' => $einsatzId, 'nu_id' => $nuId],
            ['status' => 'accepted']
        );

        // Note: pruefung='geplant' is NOT set here — only the dispatcher
        // can mark a shift as planned once workers are actually assigned.

        return response()->json(['success' => true]);
    }

    /**
     * POST /nu-portal/reject-shift — NU rejects a shift request.
     * Removes only this NU's positions and status.
     */
    public function rejectShift(Request $request)
    {
        $einsatzId = $request->input('einsatz_id');
        $nuId = $request->attributes->get('nu_id') ?? $request->input('nu_id');

        if (!$einsatzId) {
            return response()->json(['error' => 'einsatz_id erforderlich'], 400);
        }

        // Verify this NU is linked to this einsatz (via positions or direct nu_id)
        $hasPositions = DB::connection('tenant')->table('nu_schicht_positionen')
            ->where('einsatz_id', $einsatzId)
            ->where('nu_id', $nuId)
            ->exists();

        $hasDirectLink = false;
        if (!$hasPositions) {
            $hasDirectLink = DB::connection('tenant')->table('einsaetze')
                ->where('einsatz_id', $einsatzId)
                ->where('nu_id', $nuId)
                ->exists();
        }

        if (!$hasPositions && !$hasDirectLink) {
            return response()->json(['error' => 'Keine Zuordnung für diesen Einsatz gefunden'], 404);
        }

        // Rejecting a CONFIRMED shift would delete positions/zuweisungen that
        // are already part of the billing history (completed shifts, reports).
        if ($this->einsatzConfirmed($einsatzId)) {
            return response()->json(['error' => 'Einsatz ist bereits bestätigt und kann nicht mehr abgelehnt werden'], 409);
        }

        // Remove NU positions + zuweisungen for this task (if any exist)
        if ($hasPositions) {
            $positionIds = DB::connection('tenant')->table('nu_schicht_positionen')
                ->where('einsatz_id', $einsatzId)
                ->where('nu_id', $nuId)
                ->pluck('id')
                ->toArray();

            if (!empty($positionIds)) {
                DB::connection('tenant')->table('nu_position_zuweisungen')
                    ->whereIn('position_id', $positionIds)
                    ->delete();
            }

            DB::connection('tenant')->table('nu_schicht_positionen')
                ->where('einsatz_id', $einsatzId)
                ->where('nu_id', $nuId)
                ->delete();
        }

        // Remove per-NU status
        DB::connection('tenant')->table('einsatz_nu_status')
            ->where('einsatz_id', $einsatzId)
            ->where('nu_id', $nuId)
            ->delete();

        // If no more NUs remain (positions or direct link), clear the einsatz nu_id.
        // pruefung ('geplant') is only revoked when NO internal workers are
        // assigned either — an NU backing out must not silently un-plan a shift
        // that is still fully staffed with internal workers.
        $remainingPositions = DB::connection('tenant')->table('nu_schicht_positionen')
            ->where('einsatz_id', $einsatzId)
            ->exists();

        if (!$remainingPositions) {
            $hasInternalWorkers = DB::connection('tenant')->table('einsatz_mitarbeiter')
                ->where('einsatz_id', $einsatzId)
                ->exists();

            $update = ['nu_id' => null, 'nu_status' => null];
            if (!$hasInternalWorkers) {
                $update['pruefung'] = null;
                // Same "Plan aufgehoben" audit stamp as the dispatcher path —
                // the plan was published and is being pulled back.
                if (Schema::connection('tenant')->hasColumn('einsaetze', 'plan_aufgehoben_at')
                    && DB::connection('tenant')->table('einsaetze')
                        ->where('einsatz_id', $einsatzId)->value('pruefung') === 'geplant') {
                    $update['plan_aufgehoben_at'] = now();
                }
            }

            DB::connection('tenant')->table('einsaetze')
                ->where('einsatz_id', $einsatzId)
                ->update($update);
        } else {
            // Other NUs remain — the shift must not stay linked to the one
            // that just rejected.
            \App\Support\NuLink::sync((string) $einsatzId);
        }

        // Tell the dispatchers — an NU rejection was previously completely
        // silent; the planner only noticed when the worker count dropped.
        $this->notifyDispoOfNuRejection($einsatzId, $nuId);

        return response()->json(['success' => true]);
    }

    /**
     * In-app notification to Dispo managers when an NU rejects a shift request.
     * Best-effort: failures are logged, never break the rejection itself.
     */
    protected function notifyDispoOfNuRejection(string $einsatzId, $nuId): void
    {
        try {
            $tenant = \App\Services\TenantService::current();
            if (!$tenant || empty($tenant->id)) return;

            $einsatz = DB::connection('tenant')
                ->table('einsaetze as e')
                ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
                ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                ->where('e.einsatz_id', $einsatzId)
                ->select('e.datum', 'e.ist_start', 'e.ist_ende', 'k.kundenname', 'o.objektname')
                ->first();

            $firma = DB::connection('tenant')->table('nachunternehmer')
                ->where('nu_id', $nuId)->value('firmenname') ?? 'Nachunternehmer';

            $datum = !empty($einsatz->datum) ? date('d.m.Y', strtotime($einsatz->datum)) : '-';

            NotificationDispatcher::notifyDispoManagers(
                (int) $tenant->id,
                'dispo.nu.rejected',
                'NU-Anfrage abgelehnt',
                "{$firma} hat die Einsatzanfrage abgelehnt.\n"
                    . "Einsatz: {$einsatzId}\n"
                    . "Datum: {$datum}\n"
                    . "Kunde: " . ($einsatz->kundenname ?? '-') . "\n"
                    . "Objekt: " . ($einsatz->objektname ?? '-'),
                '#tasks?einsatz=' . urlencode($einsatzId),
                [
                    'einsatz_id' => $einsatzId,
                    'nu_id'      => $nuId,
                    'datum'      => $einsatz->datum ?? null,
                ]
            );
        } catch (\Throwable $e) {
            \Log::warning('notifyDispoOfNuRejection failed: ' . $e->getMessage());
        }
    }

    /**
     * GET /nu-portal/completed-shifts — Confirmed/completed shifts for this NU (order history).
     * Shows shifts where hms_bestaetigt='ja' and this NU had workers assigned.
     */
    public function completedShifts(Request $request)
    {
        $nuId = $request->attributes->get('nu_id') ?? $request->query('nu_id');
        if (!$nuId) return response()->json(['error' => 'nu_id erforderlich'], 400);

        // Determine month range (default: current month)
        $monat = intval($request->query('monat', date('n')));
        $jahr = intval($request->query('jahr', date('Y')));
        $startDate = sprintf('%04d-%02d-01', $jahr, $monat);
        $endDate = date('Y-m-t', strtotime($startDate));

        // Find confirmed einsätze where this NU had position assignments
        $shifts = DB::connection('tenant')
            ->table('einsaetze as e')
            ->join('nu_schicht_positionen as nsp', function ($join) use ($nuId) {
                $join->on('nsp.einsatz_id', '=', 'e.einsatz_id')
                     ->where('nsp.nu_id', '=', $nuId);
            })
            ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
            ->where('e.hms_bestaetigt', 'ja')
            ->whereBetween('e.datum', [$startDate, $endDate])
            ->select(
                'e.einsatz_id', 'e.bestell_id', 'e.datum', 'e.ist_start', 'e.ist_ende',
                'e.beschreibung', 'e.gesetzl_pause', 'e.ist_std_menge', 'e.ort',
                'k.kundenname', 'o.objektname', 'o.adresse', 'o.plz', 'o.stadt'
            )
            ->distinct()
            ->orderBy('e.datum')
            ->orderBy('e.ist_start')
            ->get();

        if ($shifts->isEmpty()) {
            return response()->json([
                'shifts' => [],
                'monat' => $monat,
                'jahr' => $jahr,
                'total_shifts' => 0,
                'total_workers' => 0,
                'total_std' => 0,
            ]);
        }

        $einsatzIds = $shifts->pluck('einsatz_id')->unique()->toArray();

        // Load positions for this NU in these shifts
        $positions = DB::connection('tenant')
            ->table('nu_schicht_positionen')
            ->where('nu_id', $nuId)
            ->whereIn('einsatz_id', $einsatzIds)
            ->orderBy('position_nr')
            ->get();

        $positionIds = $positions->pluck('id')->toArray();

        // Load worker assignments
        $zuweisungen = collect();
        if (!empty($positionIds)) {
            $zuweisungen = DB::connection('tenant')
                ->table('nu_position_zuweisungen as z')
                ->join('nu_portal_mitarbeiter as m', 'z.nu_mitarbeiter_id', '=', 'm.id')
                ->whereIn('z.position_id', $positionIds)
                ->select('z.position_id', 'z.nu_mitarbeiter_id', 'z.ist_std', 'z.ist_start', 'z.ist_ende', 'z.pause_minuten',
                         'm.vorname', 'm.name', 'm.personal_nr')
                ->get();
        }

        // Group by einsatz
        $posByEinsatz = $positions->groupBy('einsatz_id');
        $zByPos = $zuweisungen->groupBy('position_id');

        $totalWorkers = 0;
        $totalStd = 0;

        $result = $shifts->map(function ($shift) use ($posByEinsatz, $zByPos, &$totalWorkers, &$totalStd) {
            $shiftPositions = $posByEinsatz[$shift->einsatz_id] ?? collect();
            $workers = [];

            foreach ($shiftPositions as $pos) {
                $posWorkers = $zByPos[$pos->id] ?? collect();
                foreach ($posWorkers as $w) {
                    // ist_std <= 0 = "not recorded" (same as Controlling).
                    $std = $w->ist_std !== null && $w->ist_std !== '' && (float) $w->ist_std > 0
                        ? floatval($w->ist_std) : null;
                    if ($std) $totalStd += $std;
                    $workers[] = [
                        'nu_mitarbeiter_id' => $w->nu_mitarbeiter_id,
                        'vorname' => $w->vorname,
                        'name' => $w->name,
                        'personal_nr' => $w->personal_nr,
                        'position_name' => $pos->position_name,
                        'ist_std' => $std,
                        'ist_start' => $w->ist_start,
                        'ist_ende' => $w->ist_ende,
                        'pause_minuten' => $w->pause_minuten,
                    ];
                }
            }

            $totalWorkers += count($workers);

            $shift->positions_count = $shiftPositions->count();
            $shift->workers_assigned = $workers;
            $shift->worker_count = count($workers);

            return $shift;
        });

        return response()->json([
            'shifts' => $result->values(),
            'monat' => $monat,
            'jahr' => $jahr,
            'total_shifts' => $result->count(),
            'total_workers' => $totalWorkers,
            'total_std' => round($totalStd, 2),
        ]);
    }
}
