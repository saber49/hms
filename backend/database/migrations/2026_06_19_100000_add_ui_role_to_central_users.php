<?php

use App\Support\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point 5 — single source of truth for the *display* role.
 *
 * Until now the UI role (manager / dispo / buchhaltung / viewer / custom or
 * a tenant's own custom role) was inferred by joining central_users to the
 * legacy tenant.users mirror by `username` across two databases. That join
 * is fragile: a username mismatch or an out-of-sync mirror showed the wrong
 * role on screen.
 *
 * This adds `central_users.ui_role` so the display role lives right next to
 * the user, in the same row, in the central DB — no cross-DB join. Security
 * was never affected (effective permissions come from the `permissions`
 * column / role resolution), only the displayed label.
 *
 * Backfill is self-contained: historically `store()` snapshotted the preset's
 * permission list into the `permissions` JSON column, so we can recover the
 * original preset by matching that list back to a known preset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            if (!Schema::hasColumn('central_users', 'ui_role')) {
                $table->string('ui_role', 64)->nullable()->after('role');
            }
        });

        // Pre-compute a signature (sorted, comma-joined keys) for every
        // built-in preset so we can match a stored permission list back to
        // the preset that produced it.
        $presetSignatures = [];
        foreach (array_keys(PermissionRegistry::PRESETS) as $preset) {
            if (in_array($preset, ['owner', 'admin', 'custom'], true)) {
                continue; // handled separately below
            }
            $perms = PermissionRegistry::permissionsForPreset($preset);
            sort($perms);
            $presetSignatures[implode(',', $perms)] = $preset;
        }

        DB::table('central_users')->orderBy('id')->chunkById(200, function ($rows) use ($presetSignatures) {
            foreach ($rows as $u) {
                // Owners + platform/tenant admins → full access roles.
                if (!empty($u->is_owner)) {
                    DB::table('central_users')->where('id', $u->id)->update(['ui_role' => 'owner']);
                    continue;
                }
                if (in_array($u->role, ['super_admin', 'tenant_admin'], true)) {
                    DB::table('central_users')->where('id', $u->id)->update(['ui_role' => 'admin']);
                    continue;
                }

                // Regular users: recover the preset from the stored list.
                $perms = $u->permissions ? json_decode($u->permissions, true) : null;
                if (!is_array($perms) || empty($perms)) {
                    // No explicit list → legacy default was "viewer".
                    DB::table('central_users')->where('id', $u->id)->update(['ui_role' => 'viewer']);
                    continue;
                }

                $clean = PermissionRegistry::filterValid($perms);
                sort($clean);
                $sig = implode(',', $clean);

                DB::table('central_users')->where('id', $u->id)->update([
                    'ui_role' => $presetSignatures[$sig] ?? 'custom',
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            if (Schema::hasColumn('central_users', 'ui_role')) {
                $table->dropColumn('ui_role');
            }
        });
    }
};
