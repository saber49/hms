<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `bestellbeschreibung` to `objekte` — a free-text order description
 * maintained on the Objekt (shown in the "Objekt bearbeiten" dialog).
 *
 * Idempotent — checks for the column before adding.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('objekte')) return;
        Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('objekte', 'bestellbeschreibung')) {
                $table->text('bestellbeschreibung')->nullable()->after('hinweise');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('objekte')) return;
        Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('objekte', 'bestellbeschreibung')) {
                $table->dropColumn('bestellbeschreibung');
            }
        });
    }
};
