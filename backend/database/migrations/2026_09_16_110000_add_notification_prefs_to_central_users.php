<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user notification preferences on central_users (recipient side).
 *
 * JSON shape (all keys optional — missing means "on", i.e. opt-out only
 * so newly introduced notification types start enabled):
 *
 *   {
 *     "email": true,                          // master: any e-mail
 *     "app":   true,                          // master: in-app bell rows
 *     "types": {
 *       "dispo.unplanned": {"email": false},
 *       "worker.absence":  {"app": false}     // group key — covers
 *                                             // worker.absence.* via
 *                                             // longest-prefix lookup
 *     }
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            if (!Schema::hasColumn('central_users', 'notification_prefs')) {
                $table->json('notification_prefs')->nullable()->after('permissions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            if (Schema::hasColumn('central_users', 'notification_prefs')) {
                $table->dropColumn('notification_prefs');
            }
        });
    }
};
