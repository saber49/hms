<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantRole;
use App\Support\PermissionRegistry;
use App\Support\UserAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for per-tenant custom roles (Point 4).
 *
 * Every endpoint is gated by the `users.manage` permission at the route
 * layer and scoped to the caller's tenant_id here — a tenant can only ever
 * see and touch its own roles.
 */
class RoleController extends Controller
{
    private function tenantId(Request $request): int
    {
        $user = $request->user();
        return $user ? (int) $user->tenant_id : 0;
    }

    /**
     * GET /api/roles — list this tenant's custom roles + how many users hold
     * each one (so the UI can warn before deleting a role in use).
     */
    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);

        $roles = TenantRole::where('tenant_id', $tenantId)
            ->orderBy('label')
            ->get();

        // Count users per ui_role in one grouped query (cheap, indexed scan).
        $counts = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', $tenantId)
            ->whereRaw("name NOT LIKE 'deleted\\_%'")
            ->select('ui_role', DB::raw('COUNT(*) as c'))
            ->groupBy('ui_role')
            ->pluck('c', 'ui_role');

        return response()->json($roles->map(fn (TenantRole $r) => [
            'id'          => $r->id,
            'slug'        => $r->slug,
            'label'       => $r->label,
            'permissions' => $r->resolvedPermissions(),
            'user_count'  => (int) ($counts[$r->slug] ?? 0),
        ]));
    }

    /**
     * POST /api/roles — create a custom role.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'label'         => 'required|string|max:100',
            'permissions'   => 'required|array|min:1',
            'permissions.*' => 'string',
        ]);

        $tenantId = $this->tenantId($request);

        $label = trim($data['label']);
        if ($label === '') {
            return response()->json(['error' => 'Rollenname ist erforderlich'], 422);
        }

        // Duplicate label guard (case-insensitive) for a friendlier message
        // than the raw unique-slug fallback.
        $dupe = TenantRole::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->exists();
        if ($dupe) {
            return response()->json(['error' => 'Eine Rolle mit diesem Namen existiert bereits.'], 409);
        }

        $perms = PermissionRegistry::filterValid($data['permissions']);
        if (empty($perms)) {
            return response()->json(['error' => 'Bitte mindestens eine gültige Berechtigung wählen.'], 422);
        }

        $slug = TenantRole::makeSlug($tenantId, $label);

        $role = TenantRole::create([
            'tenant_id'   => $tenantId,
            'slug'        => $slug,
            'label'       => $label,
            'permissions' => $perms,
        ]);

        UserAuditLogger::log($request, 'role_create',
            (object) ['id' => $role->id, 'name' => $slug, 'tenant_id' => $tenantId],
            null,
            ['label' => $label, 'slug' => $slug, 'permissions' => $perms]
        );

        return response()->json([
            'success' => true,
            'id'      => $role->id,
            'slug'    => $slug,
        ], 201);
    }

    /**
     * PUT /api/roles/{id} — rename or re-scope a custom role. Permission
     * changes take effect immediately for every user that holds the role
     * (live resolution in CentralUser::getEffectivePermissions()).
     */
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'label'         => 'nullable|string|max:100',
            'permissions'   => 'nullable|array|min:1',
            'permissions.*' => 'string',
        ]);

        $tenantId = $this->tenantId($request);

        $role = TenantRole::where('tenant_id', $tenantId)->where('id', $id)->first();
        if (!$role) {
            return response()->json(['error' => 'Rolle nicht gefunden'], 404);
        }

        $before = ['label' => $role->label, 'permissions' => $role->resolvedPermissions()];
        $update = [];

        if (array_key_exists('label', $data) && $data['label'] !== null) {
            $label = trim($data['label']);
            if ($label === '') {
                return response()->json(['error' => 'Rollenname ist erforderlich'], 422);
            }
            $dupe = TenantRole::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
                ->where('id', '<>', $id)
                ->exists();
            if ($dupe) {
                return response()->json(['error' => 'Eine Rolle mit diesem Namen existiert bereits.'], 409);
            }
            $update['label'] = $label;
        }

        if (array_key_exists('permissions', $data) && $data['permissions'] !== null) {
            $perms = PermissionRegistry::filterValid($data['permissions']);
            if (empty($perms)) {
                return response()->json(['error' => 'Bitte mindestens eine gültige Berechtigung wählen.'], 422);
            }
            $update['permissions'] = $perms;
        }

        if (empty($update)) {
            return response()->json(['success' => true, 'unchanged' => true]);
        }

        $role->update($update);

        UserAuditLogger::log($request, 'role_update',
            (object) ['id' => $role->id, 'name' => $role->slug, 'tenant_id' => $tenantId],
            $before,
            ['label' => $role->label, 'permissions' => $role->resolvedPermissions()]
        );

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/roles/{id} — remove a custom role.
     *
     * Users currently holding the role are NOT left without access: we
     * snapshot the role's (last) permission list into their personal
     * `permissions` column and flip their ui_role to 'custom'. They keep
     * exactly the access they had; the admin can re-assign them later.
     */
    public function destroy(Request $request, int $id)
    {
        $tenantId = $this->tenantId($request);

        $role = TenantRole::where('tenant_id', $tenantId)->where('id', $id)->first();
        if (!$role) {
            return response()->json(['error' => 'Rolle nicht gefunden'], 404);
        }

        $snapshot = $role->resolvedPermissions();

        $affected = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', $tenantId)
            ->where('ui_role', $role->slug)
            ->update([
                'ui_role'     => 'custom',
                'permissions' => json_encode($snapshot),
                'updated_at'  => now(),
            ]);

        $role->delete();

        UserAuditLogger::log($request, 'role_delete',
            (object) ['id' => $id, 'name' => $role->slug, 'tenant_id' => $tenantId],
            ['label' => $role->label, 'permissions' => $snapshot],
            ['reassigned_users' => $affected]
        );

        return response()->json(['success' => true, 'reassigned_users' => $affected]);
    }
}
