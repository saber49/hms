<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers in which tenant a login identifier last authenticated, so logins
 * that have to scan all tenant DBs try that tenant first (see the
 * login_tenant_hints migration). Best-effort: failures never block a login.
 */
class LoginHint
{
    private static function hash(string $identifier): string
    {
        return hash('sha256', mb_strtolower(trim($identifier)));
    }

    private static function ready(): bool
    {
        static $ok = null;
        return $ok ??= Schema::connection('mysql')->hasTable('login_tenant_hints');
    }

    /** Tenant id of the last successful login, or null. */
    public static function tenantFor(string $typ, string $identifier): ?int
    {
        try {
            if (!self::ready()) return null;
            $id = DB::connection('mysql')->table('login_tenant_hints')
                ->where('typ', $typ)->where('identifier_hash', self::hash($identifier))->value('tenant_id');
            return $id !== null ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function remember(string $typ, string $identifier, int $tenantId): void
    {
        try {
            if (!self::ready()) return;
            DB::connection('mysql')->table('login_tenant_hints')->updateOrInsert(
                ['typ' => $typ, 'identifier_hash' => self::hash($identifier)],
                ['tenant_id' => $tenantId, 'updated_at' => now()]
            );
        } catch (\Throwable $e) {
            // optimisation only
        }
    }
}
