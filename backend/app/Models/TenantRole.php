<?php

namespace App\Models;

use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A per-tenant custom role: a named, reusable bundle of permission keys.
 *
 * Scoped hard to one tenant via `tenant_id` — the same isolation boundary
 * used by central_users. A central_user references a role by storing its
 * `slug` in central_users.ui_role; permissions resolve live at request time.
 */
class TenantRole extends Model
{
    protected $connection = 'mysql';
    protected $table = 'tenant_roles';

    protected $fillable = ['tenant_id', 'slug', 'label', 'permissions'];

    protected $casts = [
        'permissions' => 'array',
    ];

    /**
     * Slugs that may never be used by a custom role because they collide
     * with a built-in preset key (which would shadow tenant resolution).
     */
    public const RESERVED_SLUGS = [
        'owner', 'admin', 'manager', 'dispo',
        'buchhaltung', 'viewer', 'custom',
    ];

    /**
     * Build a safe, unique slug for a label within a tenant. Falls back to a
     * random suffix if the base slug is taken or reserved.
     */
    public static function makeSlug(int $tenantId, string $label, ?int $ignoreId = null): string
    {
        $base = Str::slug($label, '_');
        if ($base === '') {
            $base = 'rolle';
        }
        $base = substr($base, 0, 56);

        $slug = $base;
        $i = 1;
        while (
            in_array($slug, self::RESERVED_SLUGS, true)
            || self::where('tenant_id', $tenantId)
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '_' . (++$i);
        }

        return $slug;
    }

    /**
     * Resolve this role's permission list, intersected with the registry so
     * a stale key (e.g. one renamed in code) is silently dropped.
     *
     * @return array<int,string>
     */
    public function resolvedPermissions(): array
    {
        return PermissionRegistry::filterValid($this->permissions ?? []);
    }
}
