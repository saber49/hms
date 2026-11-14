<?php

use App\Support\Branchen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Industry per Hauptbereich (`bereiche_katalog.branche`, keys of
 * App\Support\Branchen::REGISTRY). Existing mains get a best guess from
 * their name and security switches; nothing else changes (no starter pack,
 * no flag changes) — the owner applies packs in Konto & Firma → Bereiche.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (!$s->hasTable('bereiche_katalog')) return;
        if (!$s->hasColumn('bereiche_katalog', 'branche')) {
            $s->table('bereiche_katalog', function (Blueprint $table) {
                $table->string('branche', 40)->nullable();
            });
        }
        $db = DB::connection($this->connection);
        $hasFlags = $s->hasColumn('bereiche_katalog', 'bewachung');
        foreach ($db->table('bereiche_katalog')->whereNull('parent_id')->whereNull('branche')->get() as $m) {
            $key = Branchen::guess((string) $m->name, $hasFlags && (int) $m->bewachung === 1, $hasFlags && (int) $m->stadiondienst === 1);
            if ($key) $db->table('bereiche_katalog')->where('id', $m->id)->update(['branche' => $key]);
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('bereiche_katalog', 'branche')) {
            Schema::connection($this->connection)->table('bereiche_katalog', function (Blueprint $table) {
                $table->dropColumn('branche');
            });
        }
    }
};
