<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `ort` to `bestelleingang` — the order-level "Ort / Treffpunkt" of the
 * Schicht-Vorlage.
 *
 * Until now the header Ort was never persisted: on reopening an order the
 * wizard derived it from the first day's Ort. Since the header Ort and the
 * per-day Tag-Ort are now independent (header = default for newly generated
 * days only, Tag-Ort = what reaches Dispo), the header value needs its own
 * column so clearing/changing it survives a save + reopen.
 *
 * Idempotent — checks for the column before adding.
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
            if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'ort')) {
                $table->string('ort', 255)->nullable()->after('zeit_bis')
                    ->comment('Schicht-Vorlage Ort / Treffpunkt — default for newly generated days; per-day ort lives in bestelleingang_tage.');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('bestelleingang')) {
            return;
        }
        Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('bestelleingang', 'ort')) {
                $table->dropColumn('ort');
            }
        });
    }
};
