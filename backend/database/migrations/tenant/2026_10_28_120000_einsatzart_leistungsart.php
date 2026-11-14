<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sicherheitsbranche platform catalog (18 types, stable keys) + mapping of
 * each company's Einsatzarten (subs in bereiche_katalog) to one type.
 * Article types are derived from that mapping afterwards (reviewed backfill).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    /** Frozen copy of App\Support\SecurityServices::DEFAULTS at creation time. */
    private const TYPES = [
        'objektschutz'          => 'Objektschutz / Separatwachdienst',
        'werkschutz'            => 'Werkschutz',
        'empfangsdienst'        => 'Empfangs- & Pfortendienst',
        'revierdienst'          => 'Revier- & Streifendienst',
        'city_streife'          => 'City-Streife / Kontrollgänge im öffentlichen Raum',
        'oepnv'                 => 'Sicherheitsdienst ÖPNV / Bahnhof',
        'ladendetektiv'         => 'Ladendetektiv / Schutz vor Ladendieben',
        'doorman'               => 'Doorman / Einlassdienst Gastronomie',
        'asylunterkunft'        => 'Bewachung Flüchtlings- / Asylunterkunft',
        'veranstaltungsschutz'  => 'Veranstaltungsschutz / Einlasskontrolle',
        'stadiondienst'         => 'Stadion- & Ordnerdienst',
        'baustellenbewachung'   => 'Baustellenbewachung',
        'alarmverfolgung'       => 'Alarmverfolgung & Intervention',
        'personenschutz'        => 'Personenschutz',
        'nsl'                   => 'Notruf- & Serviceleitstelle (NSL)',
        'brandsicherheitswache' => 'Brandsicherheitswache',
        'veranstaltungsservice' => 'Veranstaltungsservice (Garderobe, Auf-/Abbau)',
        'sonstiges'             => 'Sonstige Sicherheitsdienstleistung',
    ];

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (!$s->hasTable('sicherheitsleistungen')) return;
        $db = DB::connection($this->connection);
        $i = 0;
        foreach (self::TYPES as $key => $name) {
            $i++;
            if ($db->table('sicherheitsleistungen')->where('schluessel', $key)->exists()) {
                $db->table('sicherheitsleistungen')->where('schluessel', $key)->update(['name' => $name, 'sortierung' => $i, 'updated_at' => now()]);
            } else {
                $db->table('sicherheitsleistungen')->insert(['schluessel' => $key, 'name' => $name, 'aktiv' => 1,
                    'sortierung' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if ($s->hasTable('bereiche_katalog') && !$s->hasColumn('bereiche_katalog', 'leistungsart_id')) {
            $s->table('bereiche_katalog', function (Blueprint $table) {
                $table->unsignedBigInteger('leistungsart_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasColumn('bereiche_katalog', 'leistungsart_id')) {
            $s->table('bereiche_katalog', function (Blueprint $table) {
                $table->dropIndex(['leistungsart_id']);
                $table->dropColumn('leistungsart_id');
            });
        }
    }
};
