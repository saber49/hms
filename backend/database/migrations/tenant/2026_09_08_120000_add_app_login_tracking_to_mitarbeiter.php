<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track whether / when a Mitarbeiter has actually used the worker app.
 *
 * `app_zugang` only says "access is enabled". The Stammdaten card needs to
 * show RED until the worker has logged in at least once, then GREEN — so we
 * record the first and most recent successful app login.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('mitarbeiter')) {
            return;
        }
        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'app_first_login')) {
                $table->timestamp('app_first_login')->nullable()->after('app_zugang');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'app_last_login')) {
                $table->timestamp('app_last_login')->nullable()->after('app_first_login');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('mitarbeiter')) {
            return;
        }
        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) {
            foreach (['app_last_login', 'app_first_login'] as $col) {
                if (Schema::connection('tenant')->hasColumn('mitarbeiter', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
