<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('nu_schicht_positionen', 'geschlecht_mix')) {
            Schema::connection('tenant')->table('nu_schicht_positionen', function (Blueprint $table) {
                // Per-position gender split when geschlecht='gemischt',
                // stored as JSON e.g. {"maennlich":1,"weiblich":2}.
                $table->string('geschlecht_mix', 60)->nullable()->after('geschlecht');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('nu_schicht_positionen', 'geschlecht_mix')) {
            Schema::connection('tenant')->table('nu_schicht_positionen', function (Blueprint $table) {
                $table->dropColumn('geschlecht_mix');
            });
        }
    }
};
