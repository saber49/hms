<?php

namespace App\Models;

use App\Support\PermissionRegistry;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class CentralUser extends Authenticatable implements FilamentUser
{
    use HasApiTokens;

    protected $connection = 'mysql';
    protected $table = 'central_users';

    protected $fillable = [
        'tenant_id', 'name', 'email', 'password', 'full_name', 'role',
        'ui_role', 'permissions', 'allowed_bereiche', 'zugriff_modus', 'is_owner',
        'aktiv', 'email_verified_at', 'verification_token',
        'must_change_password', 'notification_prefs',
        'signature_path', 'stamp_path',
    ];

    protected $hidden = ['password', 'remember_token', 'verification_token'];

    protected $casts = [
        'aktiv' => 'boolean',
        'is_owner' => 'boolean',
        'must_change_password' => 'boolean',
        'last_login' => 'datetime',
        'email_verified_at' => 'datetime',
        'permissions' => 'array',
        'allowed_bereiche' => 'array',
        'notification_prefs' => 'array',
    ];

    /**
     * Per-instance memo for getEffectivePermissions(). Cleared automatically
     * when the model is re-hydrated (Eloquent constructs a new instance per
     * fetch) and reset by ::syncOriginal() on save, so this never returns
     * stale data within a single request.
     */
    private ?array $cachedEffectivePermissions = null;

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Filament admin panel access — platform super-admins only.
     * Tenant users (owners included) never reach the internal panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->role === 'super_admin'
            && (bool) $this->aktiv;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /* ----------------------------------------------------------------
     |  Permissions / RBAC
     | ----------------------------------------------------------------
     |
     |  Permission resolution order:
     |   1. Owner & super_admin  → all permissions (cannot be revoked).
     |   2. Explicit `permissions` JSON column → that list, intersected
     |      with the known registry (defends against stale keys).
     |   3. Fall back to the role's preset list.
     |
     |  The result never crosses tenant boundaries — every check happens
     |  against a single central_user row whose tenant_id is fixed.
     */

    /**
     * Was this user the original creator of the tenant?
     */
    public function isOwner(): bool
    {
        return (bool) $this->is_owner;
    }

    /**
     * Resolve the user's effective permission list for the current state.
     *
     * @return array<int,string>
     */
    public function getEffectivePermissions(): array
    {
        // Result is stable for the life of this hydrated model — every
        // permission check in a request hits the same instance, so caching
        // here saves dozens of array_flip/intersect calls per request
        // without any staleness risk (a refetch creates a new instance).
        if ($this->cachedEffectivePermissions !== null) {
            return $this->cachedEffectivePermissions;
        }

        $resolve = function (): array {
            // Owners and super_admins always have everything — by design.
            if ($this->is_owner || $this->role === 'super_admin') {
                return PermissionRegistry::all();
            }

            // Inactive users have zero permissions.
            if (!$this->aktiv) {
                return [];
            }

            $uiRole = $this->ui_role;

            // Tenant-defined custom role → resolve LIVE so that editing the
            // role immediately propagates to everyone who holds it. A custom
            // role is any ui_role that is NOT one of the built-in presets.
            if (is_string($uiRole) && $uiRole !== '' && !array_key_exists($uiRole, PermissionRegistry::PRESETS)) {
                $custom = $this->resolveCustomRolePermissions($uiRole);
                if ($custom !== null) {
                    return PermissionRegistry::filterValid($custom);
                }
                // Role was deleted out from under the user → fall through to
                // the explicit list / preset so they are never left dangling.
            }

            // Explicit allow-list takes precedence over role presets.
            $explicit = $this->permissions;
            if (is_array($explicit) && !empty($explicit)) {
                return PermissionRegistry::filterValid($explicit);
            }

            // tenant_admin (without explicit overrides) keeps everything.
            if ($this->role === 'tenant_admin') {
                return PermissionRegistry::all();
            }

            // Otherwise fall back to the role preset (manager / dispo / viewer …).
            return PermissionRegistry::permissionsForPreset($uiRole ?? $this->role ?? 'viewer');
        };

        $perms = $resolve();
        if (!$this->is_owner && $this->role !== 'super_admin') {
            $perms = array_values(array_diff($perms, PermissionRegistry::OWNER_ONLY, PermissionRegistry::OWNER_GRANT));
            // Qualitätsmanagement only by the owner's explicit grant.
            if ($this->aktiv) {
                $qm = $this->qm_rollen;
                $qm = is_string($qm) ? (json_decode($qm, true) ?: []) : (is_array($qm) ? $qm : []);
                $perms = array_values(array_unique(array_merge($perms, array_intersect(PermissionRegistry::OWNER_GRANT, $qm))));
            }
        }
        // Derived Vertrieb keys (section summary + admin-only settings).
        $perms = PermissionRegistry::withDerived($perms, $this->isAdminLevel());
        return $this->cachedEffectivePermissions = $perms;
    }

    /** Inhaber, Administrator (tenant_admin) or platform super_admin. */
    public function isAdminLevel(): bool
    {
        return (bool) $this->is_owner
            || ($this->aktiv && in_array($this->role, ['tenant_admin', 'super_admin'], true));
    }

    /**
     * Eloquent hook — invalidate the permission memo whenever the model is
     * refilled (e.g. ::refresh() or ::fill()). This prevents a long-lived
     * instance from serving stale permissions after admin edits.
     */
    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->cachedEffectivePermissions = null;
        return parent::setRawAttributes($attributes, $sync);
    }

    /**
     * Look up the permission list of a tenant custom role by slug.
     * Returns null when no such role exists for this user's tenant (so the
     * caller can fall back gracefully). The lookup hits the central DB and
     * is naturally memoised by getEffectivePermissions()'s per-request cache.
     *
     * @return array<int,string>|null
     */
    private function resolveCustomRolePermissions(string $slug): ?array
    {
        $raw = DB::connection('mysql')->table('tenant_roles')
            ->where('tenant_id', $this->tenant_id)
            ->where('slug', $slug)
            ->value('permissions');

        if ($raw === null) {
            return null;
        }

        $perms = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($perms) ? $perms : [];
    }

    /**
     * Quick check used by the EnsurePermission middleware and any
     * controller that wants to short-circuit.
     */
    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->getEffectivePermissions(), true);
    }

    /**
     * Personal notification preference for one dispatcher $type on one
     * $channel ('email' | 'app' | 'push').
     *
     * Opt-out model: every unset key means ON, so notification types
     * introduced later start enabled. The per-type lookup walks up the
     * dotted hierarchy — a stored 'worker.absence' row also governs
     * 'worker.absence.created', 'worker.absence.krank', …
     */
    public function wantsNotification(string $type, string $channel): bool
    {
        $prefs = $this->notification_prefs;
        if (!is_array($prefs)) return true;

        // Channel master switch ("alle E-Mails", "alle App-Meldungen").
        if (array_key_exists($channel, $prefs) && !$prefs[$channel]) {
            return false;
        }

        $types = $prefs['types'] ?? null;
        if (!is_array($types)) return true;

        $t = $type;
        while ($t !== '') {
            $row = $types[$t] ?? null;
            if (is_array($row) && array_key_exists($channel, $row)) {
                return (bool) $row[$channel];
            }
            $pos = strrpos($t, '.');
            if ($pos === false) break;
            $t = substr($t, 0, $pos);
        }
        return true;
    }
}
