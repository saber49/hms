<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('artikel')
            || Schema::connection('tenant')->hasColumn('artikel', 'zuschlag_tag_pct')) return;

        Schema::connection('tenant')->table('artikel', function (Blueprint $table) {
            // Tagdienst (Mo–Sa) surcharge on the Grundpreis — default 0 keeps
            // the previous behaviour (day shifts = base rate).
            $table->decimal('zuschlag_tag_pct', 5, 2)->default(0.00)->after('zuschlaege_aktiv');
        });
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('artikel', 'zuschlag_tag_pct')) {
            Schema::connection('tenant')->table('artikel', function (Blueprint $table) {
                $table->dropColumn('zuschlag_tag_pct');
            });
        }
    }
};
