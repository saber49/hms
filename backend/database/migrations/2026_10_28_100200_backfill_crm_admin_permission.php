<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead-Modul (Spezifikation 27.10.2026) adds two keys:
 *   stammdaten.interessenten.admin      — Geschäftsleitung: lists, deadlines, soft delete, export
 *   stammdaten.interessenten.uebergabe  — Operative/Objektleitung: won deals of own Sparte
 *
 * Backfill: .admin ← users.manage (same people who already held view_all
 * through the 2026_10_10 backfill). .uebergabe is granted explicitly only.
 * Owners/admins hold everything via '*'. Central DB only, idempotent.
 */
return new class extends Migration
{
    private const SOURCE = 'users.manage';
    private const KEY = 'stammdaten.interessenten.admin';

    public function up(): void
    {
        foreach (['central_users', 'tenant_roles'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'permissions')) continue;
            DB::table($table)->where('permissions', 'like', '%' . self::SOURCE . '%')->orderBy('id')
                ->each(function ($row) use ($table) {
                    $perms = json_decode($row->permissions ?? '[]', true);
                    if (!is_array($perms) || !in_array(self::SOURCE, $perms, true) || in_array(self::KEY, $perms, true)) return;
                    $perms[] = self::KEY;
                    DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode(array_values($perms))]);
                });
        }
    }

    public function down(): void
    {
        foreach (['central_users', 'tenant_roles'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'permissions')) continue;
            DB::table($table)->where('permissions', 'like', '%' . self::KEY . '%')->orderBy('id')
                ->each(function ($row) use ($table) {
                    $perms = json_decode($row->permissions ?? '[]', true);
                    if (!is_array($perms)) return;
                    $f = array_values(array_filter($perms, fn ($p) => $p !== self::KEY));
                    if (count($f) !== count($perms)) DB::table($table)->where('id', $row->id)->update(['permissions' => json_encode($f)]);
                });
        }
    }
};
