<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-Hauptbereich switches (Konto & Firma → Bereiche) instead of matching
 * the word "Sicherheit" in the name:
 *   bewachung     → worker form shows the Sicherheit tab (§34a / BewachV data)
 *   stadiondienst → additionally DFB-Pin, Ordnernummer, Barcode, Stadionverbot
 * Existing mains keep today's behaviour: names containing "sicherheit" get
 * bewachung = 1; stadiondienst = 1 only where workers of that tenant already
 * carry stadium data.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (!$s->hasTable('bereiche_katalog')) return;
        if (!$s->hasColumn('bereiche_katalog', 'bewachung')) {
            $s->table('bereiche_katalog', function (Blueprint $table) {
                $table->boolean('bewachung')->default(false);
                $table->boolean('stadiondienst')->default(false);
            });
        }
        $db = DB::connection($this->connection);
        $db->table('bereiche_katalog')->whereNull('parent_id')
            ->whereRaw("LOWER(name) LIKE '%sicherheit%'")->update(['bewachung' => 1]);

        $stadium = false;
        if ($s->hasTable('mitarbeiter')) {
            $q = $db->table('mitarbeiter');
            $q->where(function ($w) use ($s) {
                foreach (['dfb_pin', 'ordnernummer'] as $c) {
                    if ($s->hasColumn('mitarbeiter', $c)) $w->orWhere(fn ($x) => $x->whereNotNull($c)->where($c, '<>', ''));
                }
                if ($s->hasColumn('mitarbeiter', 'quasod_module')) $w->orWhereNotNull('quasod_module');
                if ($s->hasColumn('mitarbeiter', 'stadion_gesperrt')) $w->orWhere('stadion_gesperrt', 1);
            });
            $stadium = $q->exists();
        }
        if ($stadium) {
            $db->table('bereiche_katalog')->whereNull('parent_id')->where('bewachung', 1)->update(['stadiondienst' => 1]);
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('bereiche_katalog', 'bewachung')) {
            Schema::connection($this->connection)->table('bereiche_katalog', function (Blueprint $table) {
                $table->dropColumn(['bewachung', 'stadiondienst']);
            });
        }
    }
};
