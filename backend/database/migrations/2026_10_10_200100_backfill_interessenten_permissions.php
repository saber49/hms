<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Interessentenverwaltung ships with three new permission keys:
 *   stammdaten.interessenten.view      — see the module and own assigned prospects
 *   stammdaten.interessenten.edit      — create/edit prospects + communication entries
 *   stammdaten.interessenten.view_all  — see ALL prospects and reassign the owner
 *                                        (Vertriebsleitung capability)
 *
 * Backfill so existing users keep a sensible access level instead of losing
 * the new module silently:
 *   .view     ← wherever stammdaten.kunden.view is already granted
 *   .edit     ← wherever stammdaten.kunden.edit is already granted
 *   .view_all ← wherever users.manage is already granted
 *
 * Owners / admins keep everything implicitly via the '*' preset and need no
 * data change.
 *
 * Central DB only. Idempotent: re-running adds nothing if the key already exists.
 */
return new class extends Migration
{
    private const PAIRS = [
        'stammdaten.kunden.view' => 'stammdaten.interessenten.view',
        'stammdaten.kunden.edit' => 'stammdaten.interessenten.edit',
        'users.manage'           => 'stammdaten.interessenten.view_all',
    ];

    public function up(): void
    {
        foreach (self::PAIRS as $source => $newKey) {
            $this->backfill('central_users', $source, $newKey);
            $this->backfill('tenant_roles', $source, $newKey);
        }
    }

    public function down(): void
    {
        foreach (self::PAIRS as $newKey) {
            $this->strip('central_users', $newKey);
            $this->strip('tenant_roles', $newKey);
        }
    }

    private function backfill(string $table, string $sourceKey, string $newKey): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'permissions')) {
            return;
        }

        DB::table($table)
            ->where('permissions', 'like', '%' . $sourceKey . '%')
            ->orderBy('id')
            ->each(function ($row) use ($table, $sourceKey, $newKey) {
                $perms = json_decode($row->permissions ?? '[]', true);
                if (!is_array($perms)) {
                    return;
                }
                if (!in_array($sourceKey, $perms, true)) {
                    return; // LIKE matched a substring, not the actual key
                }
                if (in_array($newKey, $perms, true)) {
                    return; // already present
                }
                $perms[] = $newKey;
                DB::table($table)->where('id', $row->id)->update([
                    'permissions' => json_encode(array_values($perms)),
                ]);
            });
    }

    private function strip(string $table, string $key): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'permissions')) {
            return;
        }

        DB::table($table)
            ->where('permissions', 'like', '%' . $key . '%')
            ->orderBy('id')
            ->each(function ($row) use ($table, $key) {
                $perms = json_decode($row->permissions ?? '[]', true);
                if (!is_array($perms)) {
                    return;
                }
                $filtered = array_values(array_filter($perms, fn ($p) => $p !== $key));
                if (count($filtered) !== count($perms)) {
                    DB::table($table)->where('id', $row->id)->update([
                        'permissions' => json_encode($filtered),
                    ]);
                }
            });
    }
};
