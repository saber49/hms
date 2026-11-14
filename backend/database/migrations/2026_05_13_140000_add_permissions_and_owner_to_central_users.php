<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds fine-grained permission storage and an "owner" flag to central_users.
 *
 * Design choices:
 *   - `permissions` is a JSON column holding an explicit allow-list of
 *     permission keys (e.g. ["dashboard.view", "auftraege.dispo.edit"]).
 *     NULL means: derive from the role preset at runtime.
 *   - `is_owner` is set to 1 for the FIRST central_user of each tenant
 *     (the account who created the company). The owner can never be
 *     downgraded, deleted, or have permissions revoked, by anyone — even
 *     other tenant_admins. This protects the company from accidental
 *     lockout.
 *
 *   No tenant_id mixing risk: every central_user already carries a
 *   tenant_id; permissions are scoped to that single tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            // Permission allow-list (null = use role preset)
            if (!Schema::hasColumn('central_users', 'permissions')) {
                $table->json('permissions')->nullable()->after('role');
            }

            // The original tenant owner — protected from edits
            if (!Schema::hasColumn('central_users', 'is_owner')) {
                $table->boolean('is_owner')->default(false)->after('role');
            }
        });

        // Backfill: the earliest tenant_admin per tenant becomes the owner.
        // Falls back to the earliest user if no tenant_admin exists yet
        // (defensive — should never happen in practice).
        $rows = DB::table('central_users')
            ->select(DB::raw('MIN(id) AS first_id'), 'tenant_id')
            ->whereNotNull('tenant_id')
            ->where('role', 'tenant_admin')
            ->groupBy('tenant_id')
            ->get();

        foreach ($rows as $r) {
            DB::table('central_users')->where('id', $r->first_id)->update(['is_owner' => 1]);
        }

        // Safety net — any tenant that has no tenant_admin at all
        // gets its oldest user promoted to owner.
        $orphans = DB::table('central_users')
            ->select(DB::raw('MIN(id) AS first_id'), 'tenant_id')
            ->whereNotNull('tenant_id')
            ->whereNotIn('tenant_id', function ($q) {
                $q->select('tenant_id')->from('central_users')->where('is_owner', 1);
            })
            ->groupBy('tenant_id')
            ->get();

        foreach ($orphans as $r) {
            DB::table('central_users')->where('id', $r->first_id)->update(['is_owner' => 1]);
        }
    }

    public function down(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            if (Schema::hasColumn('central_users', 'permissions')) {
                $table->dropColumn('permissions');
            }
            if (Schema::hasColumn('central_users', 'is_owner')) {
                $table->dropColumn('is_owner');
            }
        });
    }
};
