<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SystemMail;
use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Support\BereichScope;
use App\Support\PermissionRegistry;
use App\Support\UserAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /** Per-instance cache for the tenant.users.role ENUM members. */
    private ?array $tenantRoleEnumCache = null;

    /**
     * Map UI role -> central_users role enum.
     *
     * UI roles used: owner / admin / manager / dispo / buchhaltung / viewer / custom
     * central_users.role enum: super_admin / tenant_admin / user
     *
     * Only "owner" and "admin" map to tenant_admin in central_users — all
     * other roles (manager, dispo, buchhaltung, viewer, custom) are stored
     * as "user" and rely on the `permissions` JSON column for access control.
     */
    private function uiRoleToCentral(string $uiRole): string
    {
        return match ($uiRole) {
            'owner', 'admin' => 'tenant_admin',
            default          => 'user',
        };
    }

    /**
     * Map central_users -> UI role for display.
     *
     * `central_users.ui_role` is now the single source of truth (Point 5):
     * a built-in preset key (manager/dispo/...), a tenant custom-role slug,
     * or 'custom'. We no longer cross-join the legacy tenant.users mirror by
     * username — that join produced wrong labels on any mismatch.
     */
    private function centralRoleToUi(object $centralUser): string
    {
        if (!empty($centralUser->is_owner)) return 'owner';

        if (!empty($centralUser->ui_role)) {
            return $centralUser->ui_role;
        }

        // Legacy fallback for rows created before ui_role existed and not
        // yet backfilled (defensive only).
        return match ($centralUser->role) {
            'super_admin', 'tenant_admin' => 'admin',
            default                       => 'viewer',
        };
    }

    /**
     * Management hierarchy: an actor may only manage users STRICTLY below
     * his own rank. The owner (100) manages everyone; an admin (90) manages
     * manager/dispo/buchhaltung/custom/viewer — but never another admin,
     * himself, or the owner.
     */
    private function manageRank(string $uiRole): int
    {
        return match ($uiRole) {
            'owner'                => 100,
            'admin'                => 90,
            'manager'              => 70,
            'dispo', 'buchhaltung' => 60,
            'viewer', 'user'       => 40,
            default                => 50, // tenant custom roles
        };
    }

    private function actorRank($actor): int
    {
        if (!$actor) return 0;
        if ($actor->isOwner()) return 100;
        $uiRole = $actor->ui_role ?: ($actor->role === 'tenant_admin' ? 'admin' : 'user');
        return $this->manageRank($uiRole);
    }

    /** True when $actor may edit/deactivate/delete the given central row. */
    private function canManageTarget($actor, object $central): bool
    {
        if ($actor && $actor->isOwner()) return true;
        return $this->manageRank($this->centralRoleToUi($central)) < $this->actorRank($actor);
    }

    /**
     * True when $actor may SEE the given central row: own record, or any
     * user up to his own rank (peers are listed read-only). The owner sees
     * everyone; higher ranks stay invisible.
     */
    private function canSeeTarget($actor, object $central): bool
    {
        if (!$actor) return false;
        if ($actor->isOwner() || (int) $actor->id === (int) $central->id) return true;
        return $this->manageRank($this->centralRoleToUi($central)) <= $this->actorRank($actor);
    }

    /**
     * Validate + classify an incoming UI role for a tenant.
     * Returns 'builtin', 'custom' (a tenant-defined role), or null if the
     * role is neither a known preset nor an existing custom role.
     *
     * 'owner' is intentionally rejected here — ownership is implicit and can
     * never be assigned through the user form.
     */
    private function classifyRole(int $tenantId, string $uiRole): ?string
    {
        $assignableBuiltins = ['admin', 'manager', 'dispo', 'buchhaltung', 'viewer', 'custom'];
        if (in_array($uiRole, $assignableBuiltins, true)) {
            return 'builtin';
        }

        $exists = TenantRole::where('tenant_id', $tenantId)->where('slug', $uiRole)->exists();
        return $exists ? 'custom' : null;
    }

    /**
     * Get the current authenticated user's tenant_id.
     */
    private function accessModeReady(): bool
    {
        static $ready = null;
        return $ready ??= \Illuminate\Support\Facades\Schema::connection('mysql')->hasColumn('central_users', 'zugriff_modus');
    }

    /**
     * Canonical Bereich whitelist for storage. Names the user already holds
     * stay valid even when they no longer occur in the tenant's data (e.g.
     * an archived legacy Bereich). A non-empty selection that resolves to
     * nothing is refused — storing [] would mean "all Bereiche".
     *
     * @return array{0:?array<int,string>,1:?\Illuminate\Http\JsonResponse}
     */
    private function resolveAllowedBereiche(mixed $input, array $stored): array
    {
        $names = array_values(array_filter(is_array($input) ? $input : [], fn ($v) => is_string($v) && trim($v) !== ''));
        $clean = BereichScope::sanitiseForStorage($names);
        $have = array_map(fn ($v) => mb_strtolower($v), $clean);
        $storedLower = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $stored);
        foreach ($names as $n) {
            $l = mb_strtolower(trim($n));
            if (in_array($l, $storedLower, true) && !in_array($l, $have, true)) {
                $clean[] = trim($n);
                $have[] = $l;
            }
        }
        if ($names && !$clean) {
            return [null, response()->json([
                'error' => 'Die gewählten Bereiche existieren nicht. Ohne gültige Auswahl wird kein eingeschränkter Zugriff gespeichert.',
            ], 422)];
        }
        return [$clean, null];
    }

    private function tenantId(Request $request): int
    {
        $user = $request->user();
        return $user ? (int) $user->tenant_id : 0;
    }

    /**
     * Map a UI role to a value safe for tenant.users.role's ENUM.
     *
     * The migration `2026_05_15_230000_extend_users_role_enum` widens the
     * column to accept all 7 UI roles, but until every tenant DB has run
     * it (and as defense-in-depth in general) we coarsen unknown values
     * onto the legacy {admin, manager, viewer} set so an INSERT/UPDATE
     * never explodes with a "Data truncated" error.
     */
    private function tenantRoleEnum(string $uiRole): string
    {
        // Detect the actual ENUM definition lazily and cache on the instance
        // (not via `static` — that would persist across requests in PHP-FPM
        // and could leak the previous tenant's schema).
        $accepts = $this->tenantRoleEnumCache
            ??= $this->detectTenantRoleEnumValues();

        if (in_array($uiRole, $accepts, true)) {
            return $uiRole;
        }

        return match ($uiRole) {
            'owner', 'admin', 'tenant_admin'            => 'admin',
            'manager', 'dispo', 'buchhaltung'           => 'manager',
            default                                     => 'viewer',
        };
    }

    /** @return array<int,string> ENUM members on tenant.users.role. */
    private function detectTenantRoleEnumValues(): array
    {
        try {
            $row = DB::connection('tenant')->selectOne(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'users'
                    AND COLUMN_NAME  = 'role'"
            );
            if ($row && preg_match("/^enum\\((.*)\\)$/i", trim($row->COLUMN_TYPE), $m)) {
                preg_match_all("/'([^']+)'/", $m[1], $vals);
                return $vals[1] ?? ['admin', 'manager', 'viewer'];
            }
        } catch (\Throwable) {
            // fall through
        }
        return ['admin', 'manager', 'viewer'];
    }

    public function index(Request $request)
    {
        if ($request->has('id')) {
            return $this->show($request, (int) $request->id);
        }

        $tenantId = $this->tenantId($request);

        // Central users for this tenant (real login accounts). ui_role is the
        // single source of truth for the display role (Point 5) — no more
        // cross-DB join to the legacy tenant.users mirror.
        $centrals = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', $tenantId)
            ->whereRaw("name NOT LIKE 'deleted\\_%'")
            ->select('id', 'name as username', 'email', 'full_name', 'role', 'ui_role',
                     'permissions', 'allowed_bereiche', 'is_owner',
                     'aktiv', 'last_login', 'created_at', ...$this->qmRollenSelect())
            ->orderBy('is_owner', 'desc')
            ->orderBy('id')
            ->get();

        $actor = $request->user();
        $users = $centrals
            // Visibility follows the hierarchy: a non-owner sees users up to
            // his own rank (peers read-only, flagged manageable=false) plus
            // his own card. The owner and higher ranks stay invisible.
            ->filter(fn ($u) => $this->canSeeTarget($actor, $u))
            ->map(function ($u) use ($actor) {
                $u->role             = $this->centralRoleToUi($u);
                $u->is_owner         = (bool) $u->is_owner;
                $u->permissions      = $u->permissions ? PermissionRegistry::filterValid(json_decode($u->permissions, true) ?: []) : null;
                $u->allowed_bereiche = $u->allowed_bereiche ? json_decode($u->allowed_bereiche, true) : [];
                $u->aktiv            = (int) $u->aktiv;
                $this->qmRollenFuer($u, $actor);
                // Hierarchy: the actor may manage only users strictly below
                // his rank (owner manages everyone). The UI hides the action
                // buttons for cards flagged false; the same rule is enforced
                // server-side in update()/destroy()/store().
                $u->manageable       = $this->canManageTarget($actor, $u);
                return $u;
            })
            ->values();

        return response()->json($users);
    }

    public function show(Request $request, int $id)
    {
        $tenantId = $this->tenantId($request);

        $user = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', $tenantId)->where('id', $id)
            ->select('id', 'name as username', 'email', 'full_name', 'role', 'ui_role',
                     'permissions', 'allowed_bereiche', 'is_owner',
                     'aktiv', 'last_login', 'created_at', ...$this->qmRollenSelect())
            ->first();

        if (!$user) return response()->json(['error' => 'Benutzer nicht gefunden'], 404);

        // Visibility: a non-owner may only read users up to his own rank
        // (or his own record). Reads get 404 — like the index filter —
        // so higher ranks are not even enumerable.
        $actor = $request->user();
        if (!$this->canSeeTarget($actor, $user)) {
            return response()->json(['error' => 'Benutzer nicht gefunden'], 404);
        }

        $user->role             = $this->centralRoleToUi($user);
        $user->is_owner         = (bool) $user->is_owner;
        $user->permissions      = $user->permissions ? PermissionRegistry::filterValid(json_decode($user->permissions, true) ?: []) : null;
        $user->allowed_bereiche = $user->allowed_bereiche ? json_decode($user->allowed_bereiche, true) : [];
        $user->aktiv            = (int) $user->aktiv;
        $this->qmRollenFuer($user, $actor);

        return response()->json($user);
    }

    /**
     * Public catalog used by the frontend to render the permission picker
     * and the role-preset chips. Anyone authenticated may read this — it's
     * static metadata, not tenant data.
     *
     * GET /api/users/permissions
     */
    private function catalogMains(): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::connection('tenant')->hasTable('bereiche_katalog')) {
                return BereichScope::availableForTenant();
            }
            return DB::connection('tenant')->table('bereiche_katalog')
                ->whereNull('parent_id')->where('aktiv', 1)
                ->orderBy('sortierung')->orderBy('name')->pluck('name')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function permissions(Request $request)
    {
        $tenantId = $this->tenantId($request);

        $builtins = array_map(
            fn($k) => [
                'key'         => $k,
                'label'       => PermissionRegistry::PRESET_LABELS[$k] ?? $k,
                'permissions' => $k === 'owner'
                    ? PermissionRegistry::permissionsForPreset($k)
                    : array_values(array_diff(PermissionRegistry::permissionsForPreset($k), PermissionRegistry::OWNER_ONLY, PermissionRegistry::OWNER_GRANT)),
                'protected'   => in_array($k, ['owner', 'admin'], true),
                'custom'      => false,
            ],
            array_keys(PermissionRegistry::PRESETS)
        );

        // Append this tenant's own custom roles so they show up as selectable
        // preset chips alongside the built-ins.
        $custom = TenantRole::where('tenant_id', $tenantId)
            ->orderBy('label')
            ->get()
            ->map(fn(TenantRole $r) => [
                'key'         => $r->slug,
                'label'       => $r->label,
                'permissions' => $r->resolvedPermissions(),
                'protected'   => false,
                'custom'      => true,
            ])
            ->all();

        return response()->json([
            'groups'   => PermissionRegistry::asUiTree(),
            'presets'  => array_merge($builtins, $custom),
            // Live list of the tenant's Bereiche (from articles/customers).
            // Drives the per-user Bereich restriction picker. A new article
            // Bereich shows up here automatically.
            // Only the managed catalog mains (security-only: one main) —
            // stale tags on old rows ("VOD", "Verwaltung & Sicherheit")
            // are no selectable Bereiche.
            'bereiche' => $this->catalogMains(),
        ]);
    }

    /**
     * Returns the currently authenticated user's effective permissions.
     * Used by the frontend to gate the navigation and feature buttons.
     *
     * GET /api/users/me
     */
    public function me(Request $request)
    {
        $u = $request->user();
        if (!$u) return response()->json(['authenticated' => false], 401);

        return response()->json([
            'id'                    => $u->id,
            'email'                 => $u->email,
            'full_name'             => $u->full_name,
            'is_owner'              => $u->isOwner(),
            'role'                  => $u->role,
            'ui_role'               => $u->ui_role,
            'permissions'           => $u->getEffectivePermissions(),
            'allowed_bereiche'      => $u->allowed_bereiche ?: [],
            'tenant_id'             => $u->tenant_id,
        ]);
    }

    // ---------------------------------------------------------------
    // Qualitätsmanagement roles — visible and grantable by the Inhaber only
    // ---------------------------------------------------------------

    private function qmRollenSelect(): array
    {
        static $has = null; // schema fact, identical for every tenant (central DB)
        $has ??= \Illuminate\Support\Facades\Schema::connection('mysql')->hasColumn('central_users', 'qm_rollen');
        return $has ? ['qm_rollen'] : [];
    }

    /** Owner sees the QM grant of each user; everyone else never sees the field. */
    private function qmRollenFuer(object $u, $actor): void
    {
        $raw = $u->qm_rollen ?? null;
        unset($u->qm_rollen);
        if ($actor && $actor->isOwner()) {
            $u->qm_rollen = $u->is_owner ? PermissionRegistry::OWNER_GRANT
                : array_values(array_intersect(PermissionRegistry::OWNER_GRANT, json_decode($raw ?? 'null', true) ?: []));
        }
    }

    /** Sanitised QM grant from the payload, or null = do not touch (not the owner / not sent). */
    private function qmRollenInput($actor, array $data): ?array
    {
        if (!$actor || !$actor->isOwner() || !array_key_exists('qm_rollen', $data) || !$this->qmRollenSelect()) return null;
        return array_values(array_intersect(PermissionRegistry::OWNER_GRANT, is_array($data['qm_rollen']) ? $data['qm_rollen'] : []));
    }

    /**
     * Normalise an incoming permission list:
     *  - drop unknown keys (defensive)
     *  - return null if the role is owner/admin so we always fall back to "all"
     */
    private function sanitisePermissions(?array $perms, string $uiRole): ?array
    {
        // Owner/Admin always get full access — never store overrides for them.
        if (in_array($uiRole, ['owner', 'admin'], true)) {
            return null;
        }

        if (!is_array($perms)) return null;

        // filterValid also translates legacy keys (old Vertrieb all-in-one).
        return array_values(array_diff(PermissionRegistry::filterValid($perms), PermissionRegistry::OWNER_ONLY));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'username'             => 'required|string|max:50',
            'email'                => 'required|email|max:255',
            // Password is optional now: when omitted (or when the admin
            // explicitly opts-in to auto-generate), we mint a secure 12-char
            // temporary password and email it to the new user.
            'password'             => 'nullable|string|min:6',
            'full_name'            => 'required|string|max:100',
            // role may be a built-in preset OR a tenant custom-role slug;
            // it is validated against the tenant below via classifyRole().
            'role'                 => 'nullable|string|max:64',
            'permissions'          => 'nullable|array',
            'allowed_bereiche'     => 'nullable|array',
            'auto_password'        => 'nullable|boolean',
            'send_welcome_email'   => 'nullable|boolean',
        ]);

        $tenantId    = $this->tenantId($request);
        $actor       = $request->user();
        $uiRole      = $data['role'] ?? 'viewer';
        $roleType    = $this->classifyRole($tenantId, $uiRole);
        if ($roleType === null) {
            return response()->json(['error' => 'Ungültige Rolle'], 422);
        }

        // Hierarchy: a non-owner cannot create a user at or above his own
        // rank — 'admin' stays an owner-granted rank.
        if ($actor && !$actor->isOwner()
            && $this->manageRank($uiRole) >= $this->actorRank($actor)) {
            return response()->json([
                'error' => 'Sie können keine Benutzer auf oder über Ihrer eigenen Rangstufe anlegen.',
            ], 403);
        }

        $centralRole = $this->uiRoleToCentral($uiRole);
        $aktiv       = (int) $request->input('aktiv', 1);

        // Decide on the plaintext password.
        //  - autoPassword == true → always generate, ignore any submitted pw
        //  - submitted pw present  → use it
        //  - neither              → also generate (admin left it blank)
        $autoPassword     = (bool) ($data['auto_password'] ?? false);
        $plainPassword    = null;
        $generatedHere    = false;
        if ($autoPassword || empty($data['password'])) {
            $plainPassword = $this->generateTemporaryPassword();
            $generatedHere = true;
        } else {
            $plainPassword = $data['password'];
        }
        $hashed = Hash::make($plainPassword);

        // Send the welcome email by default whenever we generated the password
        // (otherwise the admin has no way to communicate it). When the admin
        // typed a password manually, email is opt-in via the checkbox.
        $sendWelcome = array_key_exists('send_welcome_email', $data)
            ? (bool) $data['send_welcome_email']
            : $generatedHere;

        // Permission resolution:
        //  - owner/admin            → null (full access, resolved at runtime)
        //  - tenant custom role     → null (resolved LIVE from the role's slug)
        //  - built-in preset/custom → explicit list (snapshot of the picks)
        if ($roleType === 'custom') {
            $permissions = null;
        } else {
            $permissions = $this->sanitisePermissions(
                $data['permissions'] ?? PermissionRegistry::permissionsForPreset($uiRole),
                $uiRole
            );
        }

        // Uniqueness checks
        if (DB::connection('mysql')->table('central_users')->where('email', $data['email'])->exists()) {
            return response()->json(['error' => 'E-Mail-Adresse ist bereits registriert'], 400);
        }
        if (DB::connection('mysql')->table('central_users')
                ->where('tenant_id', $tenantId)->where('name', $data['username'])->exists()) {
            return response()->json(['error' => 'Benutzername existiert bereits'], 400);
        }
        if (DB::connection('tenant')->table('users')->where('username', $data['username'])->exists()) {
            return response()->json(['error' => 'Benutzername existiert bereits'], 400);
        }

        // Per-user Bereich restriction (empty/absent = all Bereiche).
        [$allowedBereiche, $scopeError] = $this->resolveAllowedBereiche($data['allowed_bereiche'] ?? null, []);
        if ($scopeError) return $scopeError;

        // Create central_users (real login account)
        $centralId = DB::connection('mysql')->table('central_users')->insertGetId([
            'tenant_id'        => $tenantId,
            'name'             => $data['username'],
            'email'            => $data['email'],
            'password'         => $hashed,
            'full_name'        => $data['full_name'],
            'role'             => $centralRole,
            'ui_role'          => $uiRole,
            'permissions'      => $permissions !== null ? json_encode($permissions) : null,
            ...(($qmNeu = $this->qmRollenInput($request->user(), $request->only('qm_rollen'))) ? ['qm_rollen' => json_encode($qmNeu)] : []),
            'allowed_bereiche' => !empty($allowedBereiche) ? json_encode($allowedBereiche) : null,
            ...($this->accessModeReady() ? ['zugriff_modus' => $allowedBereiche ? 'bereiche' : 'alle'] : []),
            'is_owner'         => 0,                  // new users are never owners
            // Admin-created sub-users are implicitly trusted (the admin typed
            // and owns the address, and receives a welcome mail). They are
            // auto-verified so the EnsureEmailVerified gate never blocks them —
            // only self-registered owners go through double opt-in.
            'email_verified_at' => now(),
            'aktiv'       => $aktiv,
            // Invited sub-users must pick their own password on first login.
            // The admin-set/auto-generated password is a one-time starter that
            // the login flow forces them to replace before reaching the app.
            'must_change_password' => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Mirror to tenant.users (legacy table — keeps chat, audit logs aligned).
        // The UI role is preserved here so chat/notification queries can still
        // partition by role without touching central_users.
        DB::connection('tenant')->table('users')->insert([
            'username'      => $data['username'],
            'password_hash' => $hashed,
            'full_name'     => $data['full_name'],
            'role'          => $this->tenantRoleEnum($uiRole),
            'aktiv'         => $aktiv,
            'created_at'    => now(),
        ]);

        UserAuditLogger::log(
            $request,
            'create',
            (object) ['id' => $centralId, 'username' => $data['username'], 'tenant_id' => $tenantId],
            null,
            [
                'username'         => $data['username'],
                'email'            => $data['email'],
                'full_name'        => $data['full_name'],
                'role'             => $uiRole,
                'aktiv'            => $aktiv,
                'permissions'      => $permissions,
                'auto_password'    => $generatedHere,
                'welcome_email'    => $sendWelcome,
            ]
        );

        // Fire the welcome email if requested. We queue it so the API call
        // returns immediately; SMTP latency / temporary failures don't bubble
        // up to the admin who's just trying to save the form. Failures are
        // logged so ops can re-send manually.
        $emailQueued = false;
        if ($sendWelcome) {
            $emailQueued = $this->queueWelcomeEmail(
                tenantId:      $tenantId,
                email:         $data['email'],
                username:      $data['username'],
                fullName:      $data['full_name'],
                // Always hand the starter password to the welcome email — whether
                // we generated it or the admin typed one. Invited users are forced
                // to replace it on first login (must_change_password=1), so it is a
                // one-time secret either way; without it the recipient has no way to
                // sign in, and the "you'll set your own password" note (gated on this
                // value in the template) would also be hidden.
                tempPassword:  $plainPassword
            );
        }

        return response()->json([
            'success'        => true,
            'id'             => $centralId,
            // Surface the auto-generated password ONCE so the admin can
            // copy it from the modal even before the email arrives. This
            // is a deliberate trade-off: the admin who just created the
            // user is allowed to know its starter password.
            'temp_password'  => $generatedHere ? $plainPassword : null,
            'welcome_email_queued' => $emailQueued,
        ], 201);
    }

    /**
     * Generate a 12-character random password from a 64-symbol alphabet
     * (uppercase, lowercase, digits, two safe punctuation chars). We avoid
     * visually ambiguous characters (0/O, 1/I/l) since the password ends
     * up in an email that the user will retype on first login.
     *
     * cryptographic strength: 64^12 ≈ 4.7e21 combos — far beyond any
     * online attack and well above offline brute-force on bcrypt-12.
     */
    private function generateTemporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ' // 24 (no I, O)
                  . 'abcdefghjkmnpqrstuvwxyz'   // 23 (no i, l, o)
                  . '23456789'                  // 8  (no 0, 1)
                  . '!@#$%^&*';                 // 8 punctuation we know
                                                // play nice in mail clients
        $alphabetLen = strlen($alphabet);
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, $alphabetLen - 1)];
        }
        return $out;
    }

    /**
     * Queue the "Willkommen bei <Firma>" email. Returns true if the
     * job was successfully dispatched; false on any error (which is
     * already logged). NEVER throws — the caller treats email as a
     * best-effort side channel.
     */
    private function queueWelcomeEmail(
        int $tenantId,
        string $email,
        string $username,
        string $fullName,
        ?string $tempPassword
    ): bool {
        try {
            $tenant = Tenant::find($tenantId);
            $companyName = $tenant?->name ?? 'Clood-X';
            $loginUrl    = rtrim(config('app.url'), '/') . '/login.php';

            Mail::to($email, $fullName)->queue(new SystemMail(
                blade: 'emails.welcome-user',
                subjectLine: 'Willkommen bei ' . $companyName . ' (Clood-X)',
                payload: [
                    'company'       => $companyName,
                    'full_name'     => $fullName,
                    'username'      => $username,
                    'email'         => $email,
                    'temp_password' => $tempPassword,
                    'login_url'     => $loginUrl,
                ],
            ));
            return true;
        } catch (\Throwable $e) {
            Log::warning('welcome-user mail queue failed', [
                'tenant_id' => $tenantId,
                'email'     => $email,
                'err'       => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function update(Request $request, int $id)
    {
        $tenantId = $this->tenantId($request);
        $data     = $request->all();
        $actor    = $request->user();

        $central = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (!$central) return response()->json(['error' => 'Benutzer nicht gefunden'], 404);

        $targetIsOwner = (bool) $central->is_owner;

        // ----- Hard guards: protect the owner from anyone but themselves -----
        if ($targetIsOwner && (!$actor || $actor->id !== (int) $central->id)) {
            return response()->json([
                'error' => 'Der Firmen-Inhaber kann nur von sich selbst bearbeitet werden.',
            ], 403);
        }

        // Hierarchy: a non-owner may only manage users strictly below his
        // own rank (an admin can't touch another admin or the owner).
        if (!$this->canManageTarget($actor, $central)) {
            return response()->json([
                'error' => 'Sie können nur Benutzer unterhalb Ihrer eigenen Rangstufe verwalten.',
            ], 403);
        }

        // The owner's role / aktiv / permissions / EMAIL cannot be changed —
        // even by themselves — to prevent accidental lockout and to keep the
        // recovery address sacred against session-hijack account takeover.
        // They may still update full_name + password.
        if ($targetIsOwner) {
            // Reject any attempt to change the owner's e-mail outright.
            if (array_key_exists('email', $data)) {
                $candidate = trim((string) $data['email']);
                if ($candidate !== '' && $candidate !== $central->email) {
                    return response()->json([
                        'error' => 'Die E-Mail-Adresse des Firmen-Inhabers kann nicht geändert werden.',
                    ], 403);
                }
            }

            $newHash = null;
            if (!empty($data['password']) && strlen($data['password']) >= 6) {
                $newHash = Hash::make($data['password']);
            }

            $upd = ['updated_at' => now()];
            if (isset($data['full_name'])) $upd['full_name'] = $data['full_name'];
            if ($newHash)                  $upd['password']  = $newHash;

            DB::connection('mysql')->table('central_users')->where('id', $id)->update($upd);
            DB::connection('tenant')->table('users')->where('username', $central->name)->update(array_filter([
                'full_name'     => $data['full_name'] ?? null,
                'password_hash' => $newHash,
            ]));

            $auditAfter = [];
            if (isset($data['full_name']) && $data['full_name'] !== $central->full_name) {
                $auditAfter['full_name'] = $data['full_name'];
            }
            if ($newHash) {
                $auditAfter['password'] = '[changed]';
            }
            if (!empty($auditAfter)) {
                UserAuditLogger::log(
                    $request,
                    $newHash && count($auditAfter) === 1 ? 'password_reset' : 'update',
                    $central,
                    ['full_name' => $central->full_name],
                    $auditAfter
                );
            }
            return response()->json(['success' => true, 'protected' => true]);
        }

        // ----- Regular update -----
        // Resolve current UI role first so we can fall back to it whenever
        // the caller doesn't send a fresh one (e.g. a minimal "just toggle
        // aktiv" payload). This keeps role/permissions stable on partial
        // updates.
        $oldUiRole = $this->centralRoleToUi($central);
        $oldPerms  = $central->permissions ? json_decode($central->permissions, true) : null;

        $uiRole      = $data['role']      ?? $oldUiRole ?? 'viewer';
        $roleType    = $this->classifyRole($tenantId, $uiRole);
        if ($roleType === null) {
            return response()->json(['error' => 'Ungültige Rolle'], 422);
        }

        // Self-service guard: a user-admin must not change his OWN role,
        // permission set, Bereich restriction or aktiv flag — otherwise
        // `users.manage` would let him silently escalate himself to full
        // access. Another admin (or the owner) has to make these changes
        // for him.
        if ($actor && (int) $actor->id === (int) $central->id) {
            $permsChanged = array_key_exists('permissions', $data)
                && $this->sanitisePermissions($data['permissions'], $uiRole) != $oldPerms;
            $aktivChanged = array_key_exists('aktiv', $data)
                && (int) $data['aktiv'] !== (int) $central->aktiv;
            $bereicheChanged = false;
            if (array_key_exists('allowed_bereiche', $data)) {
                $oldBereiche = $central->allowed_bereiche
                    ? (json_decode($central->allowed_bereiche, true) ?: []) : [];
                [$newBereiche] = $this->resolveAllowedBereiche($data['allowed_bereiche'], $oldBereiche);
                $bereicheChanged = array_values($newBereiche ?? []) != array_values($oldBereiche);
            }
            if ($uiRole !== $oldUiRole || $permsChanged || $aktivChanged || $bereicheChanged) {
                return response()->json([
                    'error' => 'Sie können Ihre eigene Rolle, Berechtigungen, Bereichs-Einschränkung oder Ihren Status nicht ändern.',
                ], 403);
            }
        }
        $centralRole = $this->uiRoleToCentral($uiRole);
        $aktiv       = array_key_exists('aktiv', $data) ? (int) $data['aktiv'] : (int) $central->aktiv;
        $newHash     = null;
        if (!empty($data['password']) && strlen($data['password']) >= 6) {
            $newHash = Hash::make($data['password']);
        }

        // Optional email change. The address is contact info + login id,
        // and is safe to mutate (no other tables hold a FK to it). We do
        // require:
        //   - a syntactically valid e-mail
        //   - globally unique among non-deleted central_users
        // so the new value can still be used to log in.
        $newEmail = null;
        if (array_key_exists('email', $data)) {
            $candidate = trim((string) $data['email']);
            if ($candidate !== '' && $candidate !== $central->email) {
                if (!filter_var($candidate, FILTER_VALIDATE_EMAIL) || strlen($candidate) > 255) {
                    return response()->json(['error' => 'Ungültige E-Mail-Adresse'], 422);
                }
                $taken = DB::connection('mysql')->table('central_users')
                    ->where('email', $candidate)
                    ->where('id', '<>', $id)
                    ->whereRaw("name NOT LIKE 'deleted\\_%'")
                    ->exists();
                if ($taken) {
                    return response()->json(['error' => 'Diese E-Mail-Adresse wird bereits verwendet.'], 409);
                }
                // If the actor is changing their OWN email, require their
                // current password as a second factor. An admin editing
                // someone else's email is already gated by `users.manage`
                // and doesn't need this extra step.
                if ($actor && (int) $actor->id === (int) $id) {
                    $confirmation = (string) ($data['current_password'] ?? '');
                    if ($confirmation === '' || !Hash::check($confirmation, $actor->password)) {
                        return response()->json([
                            'error'             => 'Falsches aktuelles Passwort.',
                            'require_password'  => true,
                        ], 403);
                    }
                }
                $newEmail = $candidate;
            }
        }

        // Permission-resolution policy:
        //  - owner/admin           → null (full access).
        //  - tenant custom role    → null (resolved LIVE from the role slug).
        //  - explicit `permissions[]` sent → use that (built-in/custom pick).
        //  - role changed (no explicit perms) → recompute from preset.
        //  - else (partial update, role unchanged) → keep existing perms.
        if (in_array($uiRole, ['owner', 'admin'], true) || $roleType === 'custom') {
            $permissions = null;
        } elseif (array_key_exists('permissions', $data)) {
            $permissions = $this->sanitisePermissions($data['permissions'], $uiRole);
        } elseif ($uiRole !== $oldUiRole) {
            $permissions = $this->sanitisePermissions(
                PermissionRegistry::permissionsForPreset($uiRole),
                $uiRole
            );
        } else {
            $permissions = $oldPerms;
        }

        $centralUpdate = [
            'full_name'   => $data['full_name'] ?? $central->full_name,
            'role'        => $centralRole,
            'ui_role'     => $uiRole,
            'permissions' => $permissions !== null ? json_encode($permissions) : null,
            'aktiv'       => $aktiv,
            'updated_at'  => now(),
        ];
        if ($newHash)  $centralUpdate['password'] = $newHash;
        if ($newEmail) $centralUpdate['email']    = $newEmail;
        $oldQm = $this->qmRollenSelect() ? (json_decode($central->qm_rollen ?? 'null', true) ?: []) : [];
        $newQm = $this->qmRollenInput($actor, $data);
        if ($newQm !== null) $centralUpdate['qm_rollen'] = $newQm ? json_encode($newQm) : null;

        // Bereich restriction — only touched when the payload carries the key
        // (so partial updates like a simple aktiv-toggle keep it intact).
        if (array_key_exists('allowed_bereiche', $data)) {
            $oldBereiche = $central->allowed_bereiche ? (json_decode($central->allowed_bereiche, true) ?: []) : [];
            [$allowedBereiche, $scopeError] = $this->resolveAllowedBereiche($data['allowed_bereiche'], $oldBereiche);
            if ($scopeError) return $scopeError;
            $centralUpdate['allowed_bereiche'] = !empty($allowedBereiche)
                ? json_encode($allowedBereiche)
                : null;
            if ($this->accessModeReady()) {
                $centralUpdate['zugriff_modus'] = $allowedBereiche ? 'bereiche' : 'alle';
            }
        }

        DB::connection('mysql')->table('central_users')->where('id', $id)->update($centralUpdate);
        // Deactivated or password reset by an admin → end all sessions of that user.
        if ((int) $central->aktiv === 1 && $aktiv === 0 || !empty($newHash)) {
            DB::connection('mysql')->table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\CentralUser')->where('tokenable_id', $id)->delete();
        }

        $tenantUpdate = [
            'full_name' => $data['full_name'] ?? $central->full_name,
            'role'      => $this->tenantRoleEnum($uiRole),
            'aktiv'     => $aktiv,
        ];
        if ($newHash) $tenantUpdate['password_hash'] = $newHash;

        DB::connection('tenant')->table('users')->where('username', $central->name)->update($tenantUpdate);

        // Build a focused diff of the changed fields for the audit log.
        // ($oldUiRole + $oldPerms were already resolved above.)
        $before = []; $after = [];
        if (($central->full_name ?? null) !== ($data['full_name'] ?? $central->full_name)) {
            $before['full_name'] = $central->full_name;
            $after['full_name']  = $data['full_name'] ?? $central->full_name;
        }
        if ($oldUiRole !== $uiRole) {
            $before['role'] = $oldUiRole;
            $after['role']  = $uiRole;
        }
        if ((int) $central->aktiv !== $aktiv) {
            $before['aktiv'] = (int) $central->aktiv;
            $after['aktiv']  = $aktiv;
        }
        if ($oldPerms != $permissions) {
            $before['permissions'] = $oldPerms;
            $after['permissions']  = $permissions;
        }
        if ($newQm !== null && $newQm != $oldQm) {
            $before['qm_rollen'] = $oldQm;
            $after['qm_rollen']  = $newQm;
        }
        if ($newHash) {
            $after['password'] = '[changed]';
        }
        if ($newEmail) {
            $before['email'] = $central->email;
            $after['email']  = $newEmail;
        }

        if (!empty($before) || !empty($after)) {
            $action = 'update';
            if (isset($after['aktiv']) && count($after) === 1) {
                $action = $after['aktiv'] === 1 ? 'activate' : 'deactivate';
            } elseif (isset($after['permissions']) && !isset($after['role']) && count($after) === 1) {
                $action = 'permissions_change';
            } elseif (isset($after['role']) && !isset($after['permissions']) && count($after) === 1) {
                $action = 'role_change';
            } elseif (isset($after['password']) && count($after) === 1) {
                $action = 'password_reset';
            }
            UserAuditLogger::log($request, $action, $central, $before, $after);
        }

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, int $id)
    {
        $tenantId = $this->tenantId($request);
        $action   = $request->input('action', 'deactivate');
        $actor    = $request->user();

        $central = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (!$central) return response()->json(['error' => 'Benutzer nicht gefunden'], 404);

        // The owner is undeletable — full stop.
        if (!empty($central->is_owner)) {
            return response()->json([
                'error' => 'Der Firmen-Inhaber kann weder deaktiviert noch gelöscht werden.',
            ], 403);
        }

        // Prevent self-deletion
        if ($actor && $actor->id === (int) $central->id) {
            return response()->json(['error' => 'Sie können sich nicht selbst löschen'], 400);
        }

        // Hierarchy: only users strictly below the actor's rank are
        // deactivate-/deletable by him.
        if (!$this->canManageTarget($actor, $central)) {
            return response()->json([
                'error' => 'Sie können nur Benutzer unterhalb Ihrer eigenen Rangstufe verwalten.',
            ], 403);
        }

        if ($action === 'delete') {
            // Hard-anonymize central_users
            DB::connection('mysql')->table('central_users')->where('id', $id)->update([
                'name'        => 'deleted_' . $id,
                'email'       => 'deleted_' . $id . '@deleted.local',
                'password'    => '',
                'full_name'   => 'Gelöschter Benutzer #' . $id,
                'permissions' => null,
                'aktiv'       => 0,
                'updated_at'  => now(),
            ]);
            // Revoke any active tokens
            DB::connection('mysql')->table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\CentralUser')
                ->where('tokenable_id', $id)
                ->delete();

            // Mirror anonymize on tenant.users by old username
            DB::connection('tenant')->table('users')->where('username', $central->name)->update([
                'username'      => DB::raw("CONCAT('deleted_', id)"),
                'password_hash' => '',
                'full_name'     => DB::raw("CONCAT('Gelöschter Benutzer #', id)"),
                'aktiv'         => 0,
            ]);

            // Remove from chat participants (tenant-side id)
            $tenantUserId = DB::connection('tenant')->table('users')
                ->where('username', 'like', 'deleted\\_%')
                ->where('full_name', 'Gelöschter Benutzer #' . $id)
                ->value('id');
            if ($tenantUserId) {
                DB::connection('tenant')->table('chat_participants')->where('user_id', $tenantUserId)->delete();
            }
        } else {
            DB::connection('mysql')->table('central_users')->where('id', $id)
                ->update(['aktiv' => 0, 'updated_at' => now()]);
            DB::connection('tenant')->table('users')->where('username', $central->name)
                ->update(['aktiv' => 0]);
        }

        UserAuditLogger::log(
            $request,
            $action === 'delete' ? 'delete' : 'deactivate',
            $central,
            [
                'username'  => $central->name,
                'full_name' => $central->full_name,
                'aktiv'     => (int) $central->aktiv,
            ],
            $action === 'delete'
                ? ['anonymized' => true, 'username' => 'deleted_' . $id]
                : ['aktiv' => 0]
        );

        return response()->json(['success' => true]);
    }
}
