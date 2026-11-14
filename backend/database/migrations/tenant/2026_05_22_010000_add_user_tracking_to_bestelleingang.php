<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds audit columns `created_by` / `updated_by` to `bestelleingang`.
 *
 * They store the central_users.id of the actor who originally created the
 * Bestelleingang and who last modified it. Cross-DB FK is intentionally
 * omitted (central_users lives on the central MySQL DB), but the column
 * type matches central_users.id (BIGINT UNSIGNED) so JOINs work.
 *
 * Idempotent — checks for the columns before adding so re-running on
 * already-migrated tenants is a no-op.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('bestelleingang')) {
            return;
        }
        Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('status')
                    ->comment('central_users.id of the actor who created this entry');
            }
            if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by')
                    ->comment('central_users.id of the actor who last modified this entry');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('bestelleingang')) {
            return;
        }
        Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('bestelleingang', 'updated_by')) {
                $table->dropColumn('updated_by');
            }
            if (Schema::connection('tenant')->hasColumn('bestelleingang', 'created_by')) {
                $table->dropColumn('created_by');
            }
        });
    }
};