<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $db = DB::connection('tenant');

        // For each existing objekt, create one default artikel mirroring its current
        // service definition (bereich/modell/prices), then propagate artikel_id to
        // bestelleingang/bestellungen/einsaetze/preishistorie.
        $objekte = $db->table('objekte')->orderBy('id')->get();

        foreach ($objekte as $obj) {
            // Skip if an artikel for this obj already exists (idempotent)
            $existing = $db->table('artikel')->where('obj_id', $obj->obj_id)->first();
            $artikelId = $existing->artikel_id ?? null;

            if (!$artikelId) {
                // Generate ID: A- + max+1 (zero-padded to 5)
                $maxRow = $db->table('artikel')
                    ->whereRaw("artikel_id REGEXP '^A-[0-9]+$'")
                    ->orderByRaw("CAST(SUBSTRING(artikel_id, 3) AS UNSIGNED) DESC")
                    ->first();
                $next = $maxRow ? intval(substr($maxRow->artikel_id, 2)) + 1 : 1;
                $artikelId = 'A-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);

                $bereich = $obj->bereich ?? null;
                $modell  = $obj->modell  ?? 'Pro Einsatz';
                $name    = $obj->objektname ?: 'Standardleistung';
                // Distinguish artikel_name from objektname when they would be identical
                if ($bereich && stripos($name, $bereich) === false) {
                    $name = $name . ' — ' . $bereich;
                }

                $db->table('artikel')->insert([
                    'artikel_id'   => $artikelId,
                    'obj_id'       => $obj->obj_id,
                    'artikel_name' => $name,
                    'bereich'      => $bereich,
                    'modell'       => $modell,
                    'preis_kunde'  => $obj->preis_kunde ?? 0,
                    'nu_preis'     => $obj->nu_preis ?? 0,
                    'gewinn'       => $obj->gewinn ?? 0,
                    'aktiv'        => $obj->aktiv ?? 1,
                    'created_at'   => $obj->created_at ?? now(),
                ]);
            }

            // Propagate artikel_id to downstream rows that still have NULL
            $db->table('bestelleingang')
                ->where('obj_id', $obj->obj_id)
                ->whereNull('artikel_id')
                ->update(['artikel_id' => $artikelId]);

            $db->table('bestellungen')
                ->where('obj_id', $obj->obj_id)
                ->whereNull('artikel_id')
                ->update(['artikel_id' => $artikelId]);

            $db->table('einsaetze')
                ->where('obj_id', $obj->obj_id)
                ->whereNull('artikel_id')
                ->update(['artikel_id' => $artikelId]);

            if ($db->getSchemaBuilder()->hasTable('preishistorie')) {
                $db->table('preishistorie')
                    ->where('obj_id', $obj->obj_id)
                    ->whereNull('artikel_id')
                    ->update(['artikel_id' => $artikelId]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive on rollback — keep generated artikel rows.
        // (To fully undo, manually TRUNCATE artikel and clear artikel_id columns.)
    }
};
