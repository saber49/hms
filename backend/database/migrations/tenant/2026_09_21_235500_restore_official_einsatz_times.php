<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        foreach (['einsaetze', 'bestellungen', 'bestelleingang_tage'] as $table) {
            if (!Schema::connection('tenant')->hasTable($table)) return;
        }

        DB::connection('tenant')->affectingStatement("
            UPDATE einsaetze e
            INNER JOIN bestellungen b ON b.bestell_id = e.bestell_id
            INNER JOIN bestelleingang_tage d
                    ON d.eingang_id = b.eingang_id
                   AND d.datum = e.datum
                   AND (
                        (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                     OR (e.tag_nr IS NULL AND d.tag_nr = (
                            SELECT MIN(d2.tag_nr)
                            FROM bestelleingang_tage d2
                            WHERE d2.eingang_id = b.eingang_id
                              AND d2.datum = e.datum
                        ))
                   )
            SET e.ist_start = COALESCE(d.zeit_von, b.zeit_von),
                e.ist_ende = COALESCE(d.zeit_bis, b.zeit_bis),
                e.ist_std_menge = d.soll_std
        ");
    }

    public function down(): void
    {
    }
};
