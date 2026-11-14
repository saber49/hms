<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('nu_schicht_positionen', 'geschlecht')) {
            Schema::connection('tenant')->table('nu_schicht_positionen', function (Blueprint $table) {
                // Gender requirement per requested position, mirroring the
                // shift's Anforderungen vocabulary: maennlich/weiblich/gemischt.
                $table->string('geschlecht', 20)->nullable()->after('anzahl');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('nu_schicht_positionen', 'geschlecht')) {
            Schema::connection('tenant')->table('nu_schicht_positionen', function (Blueprint $table) {
                $table->dropColumn('geschlecht');
            });
        }
    }
};
