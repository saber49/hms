<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Per-Bereich colour (#RRGGBB) chosen under Konto & Firma → Bereiche.
     * Every Bereich chip / badge / chart in the frontend follows it; subs
     * inherit their main's colour. NULL = automatic palette colour. All
     * reads/writes are Schema-guarded (BereichCatalogController).
     */
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('bereiche_katalog')
            && !Schema::connection('tenant')->hasColumn('bereiche_katalog', 'farbe')) {
            Schema::connection('tenant')->table('bereiche_katalog', function (Blueprint $table) {
                $table->string('farbe', 7)->nullable()->after('name')
                    ->comment('#RRGGBB display colour of the Bereich (NULL = automatic)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('bereiche_katalog', 'farbe')) {
            Schema::connection('tenant')->table('bereiche_katalog', function (Blueprint $table) {
                $table->dropColumn('farbe');
            });
        }
    }
};
