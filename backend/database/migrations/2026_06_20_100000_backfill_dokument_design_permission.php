<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokument-Design used to be gated on the frontend by `auftraege.bestellungen.view`
 * while its backend mutations required `users.manage`. It now has a dedicated key:
 *   system.dokument_design.manage
 *
 * To avoid silently removing branding access from anyone who relied on
 * `users.manage` (explicit-permission users and custom tenant roles), backfill
 * the new key wherever `users.manage` is already granted. Owners / admins keep
 * it implicitly via the '*' preset and need no data change.
 *
 * Central DB only. Idempotent: re-running adds nothing if the key already exists.
 */
return new class extends Migration
{
    private const SOURCE_KEY = 'users.manage';
    private const NEW_KEY    = 'system.dokument_design.manage';

    public function up(): void
    {
        $this->backfill('central_users');
        $this->backfill('tenant_roles');
    }

    public function down(): void
    {
        $this->strip('central_users');
        $this->strip('tenant_roles');
    }

    private function backfill(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'permissions')) {
            return;
        }

        DB::table($table)
            ->where('permissions', 'like', '%' . self::SOURCE_KEY . '%')
            ->orderBy('id')
            ->each(function ($row) use ($table) {
                $perms = json_decode($row->permissions ?? '[]', true);
                if (!is_array($perms)) {
                    return;
                }
                if (!in_array(self::SOURCE_KEY, $perms, true)) {
                    return; // LIKE matched a substring, not the actual key
                }
                if (in_array(self::NEW_KEY, $perms, true)) {
                    return; // already present
                }
                $perms[] = self::NEW_KEY;
                DB::table($table)->where('id', $row->id)->update([
                    'permissions' => json_encode(array_values($perms)),
                ]);
            });
    }

    private function strip(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'permissions')) {
            return;
        }

        DB::table($table)
            ->where('permissions', 'like', '%' . self::NEW_KEY . '%')
            ->orderBy('id')
            ->each(function ($row) use ($table) {
                $perms = json_decode($row->permissions ?? '[]', true);
                if (!is_array($perms)) {
                    return;
                }
                $filtered = array_values(array_filter($perms, fn ($p) => $p !== self::NEW_KEY));
                if (count($filtered) !== count($perms)) {
                    DB::table($table)->where('id', $row->id)->update([
                        'permissions' => json_encode($filtered),
                    ]);
                }
            });
    }
};
