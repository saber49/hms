<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('kunden', 'farbe')) {
            Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
                // Hex color (#RRGGBB) used to tint this customer's shift cards in the Dispo.
                $table->string('farbe', 7)->nullable()->after('bereich');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('kunden', 'farbe')) {
            Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
                $table->dropColumn('farbe');
            });
        }
    }
};
