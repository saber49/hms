<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QM roles granted by the Inhaber (owner-only field). Kept apart from
 * `permissions` because administrators never store an explicit list —
 * QM access must NOT come with "Vollzugriff", only by the owner's grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('central_users', 'qm_rollen')) {
            Schema::table('central_users', function (Blueprint $t) {
                $t->json('qm_rollen')->nullable()->after('permissions');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('central_users', 'qm_rollen')) {
            Schema::table('central_users', fn (Blueprint $t) => $t->dropColumn('qm_rollen'));
        }
    }
};
