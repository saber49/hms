<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security service types (what is sold and performed) — independent of the
 * Bereich, which keeps its role as organisational unit / access scope.
 * `artikel.leistungsart_id` links a service position to its type; legacy
 * articles stay NULL until the reviewed migration assigns them.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    /** Frozen copy of App\Support\SecurityServices::DEFAULTS at creation time. */
    private const DEFAULTS = [
        'objektschutz'         => 'Objektschutz',
        'empfangsdienst'       => 'Empfangs- & Pfortendienst',
        'revierdienst'         => 'Revier- & Streifendienst',
        'werkschutz'           => 'Werkschutz',
        'baustellenbewachung'  => 'Baustellenbewachung',
        'veranstaltungsschutz' => 'Veranstaltungsschutz',
        'stadiondienst'        => 'Stadion- & Ordnerdienst',
        'alarmverfolgung'      => 'Alarmverfolgung & Intervention',
    ];

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (!$s->hasTable('sicherheitsleistungen')) {
            $s->create('sicherheitsleistungen', function (Blueprint $table) {
                $table->id();
                $table->string('schluessel', 40)->unique();
                $table->string('name', 100);
                $table->boolean('aktiv')->default(true);
                $table->unsignedSmallInteger('sortierung')->default(0);
                $table->timestamps();
            });
        }
        $db = DB::connection($this->connection);
        $i = 0;
        foreach (self::DEFAULTS as $key => $name) {
            $i++;
            if (!$db->table('sicherheitsleistungen')->where('schluessel', $key)->exists()) {
                $db->table('sicherheitsleistungen')->insert(['schluessel' => $key, 'name' => $name, 'aktiv' => 1,
                    'sortierung' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if ($s->hasTable('artikel') && !$s->hasColumn('artikel', 'leistungsart_id')) {
            $s->table('artikel', function (Blueprint $table) {
                $table->unsignedBigInteger('leistungsart_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasColumn('artikel', 'leistungsart_id')) {
            $s->table('artikel', function (Blueprint $table) {
                $table->dropIndex(['leistungsart_id']);
                $table->dropColumn('leistungsart_id');
            });
        }
        $s->dropIfExists('sicherheitsleistungen');
    }
};
