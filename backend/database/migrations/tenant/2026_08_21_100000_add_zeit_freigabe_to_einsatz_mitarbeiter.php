<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe')) {
            Schema::connection('tenant')->table('einsatz_mitarbeiter', function (Blueprint $table) {
                // Admin confirmation of the worker's recorded time for this shift
                // (set in Monatsabschluss, shown as a green check in the worker app).
                $table->boolean('zeit_freigabe')->default(false);
                $table->dateTime('zeit_freigabe_am')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe')) {
            Schema::connection('tenant')->table('einsatz_mitarbeiter', function (Blueprint $table) {
                $table->dropColumn(['zeit_freigabe', 'zeit_freigabe_am']);
            });
        }
    }
};
