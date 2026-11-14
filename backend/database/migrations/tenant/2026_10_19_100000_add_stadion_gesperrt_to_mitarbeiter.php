<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stadium ban ("Gesperrt") as its own flag — it used to be the 'gesperrt'
 * code inside quasod_module. Qualifications now live in the catalog
 * (mitarbeiter.qualifikationen); the ban is a warning, not a qualification.
 * Existing bans are carried over; quasod_module itself stays untouched.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (!$s->hasTable('mitarbeiter') || $s->hasColumn('mitarbeiter', 'stadion_gesperrt')) return;
        $s->table('mitarbeiter', function (Blueprint $table) {
            $table->boolean('stadion_gesperrt')->default(false);
        });
        if ($s->hasColumn('mitarbeiter', 'quasod_module')) {
            DB::connection($this->connection)->table('mitarbeiter')
                ->where('quasod_module', 'like', '%"gesperrt"%')
                ->update(['stadion_gesperrt' => 1]);
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('mitarbeiter', 'stadion_gesperrt')) {
            Schema::connection($this->connection)->table('mitarbeiter', function (Blueprint $table) {
                $table->dropColumn('stadion_gesperrt');
            });
        }
    }
};
