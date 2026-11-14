<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-Artikel orders: one Bestelleingang may bundle several services
 * (e.g. Baustellenbewachung + Bau) — each with its own price model and its
 * own set of days, so each day row becomes an Einsatz of ITS artikel.
 *
 *   - `bestelleingang.artikel_ids`    JSON array of selected artikel_ids;
 *                                      [0] is the primary (mirrored into the
 *                                      legacy `artikel_id` column).
 *   - `bestelleingang.artikel_preise` JSON map {artikel_id: {modell,
 *                                      kundenpreis}} — per-service pricing.
 *   - `bestellungen.artikel_ids`      same list mirrored for order-level
 *                                      reporting/controlling.
 *   - `bestelleingang_tage.artikel_id` which service this day row belongs to;
 *                                      NULL = the order's primary artikel
 *                                      (legacy rows and single-service days).
 *
 * Idempotent — checks columns before adding; writers guard with hasColumn(),
 * so the feature stays dormant until this migration has run on a tenant.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('bestelleingang')) {
            Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
                if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'artikel_ids')) {
                    $table->json('artikel_ids')->nullable()
                        ->comment('All selected artikel_ids of a multi-service order; [0] = primary.');
                }
                if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'artikel_preise')) {
                    $table->json('artikel_preise')->nullable()
                        ->comment('Per-artikel pricing: {artikel_id: {modell, kundenpreis}}.');
                }
            });
        }
        if (Schema::connection('tenant')->hasTable('bestellungen')) {
            Schema::connection('tenant')->table('bestellungen', function (Blueprint $table) {
                if (!Schema::connection('tenant')->hasColumn('bestellungen', 'artikel_ids')) {
                    $table->json('artikel_ids')->nullable()
                        ->comment('All selected artikel_ids (mirror of bestelleingang.artikel_ids).');
                }
            });
        }
        if (Schema::connection('tenant')->hasTable('bestelleingang_tage')) {
            Schema::connection('tenant')->table('bestelleingang_tage', function (Blueprint $table) {
                if (!Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'artikel_id')) {
                    $table->string('artikel_id', 50)->nullable()
                        ->comment('Service this day row belongs to; NULL = primary order artikel.');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('bestelleingang')) {
            Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
                foreach (['artikel_ids', 'artikel_preise'] as $col) {
                    if (Schema::connection('tenant')->hasColumn('bestelleingang', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        if (Schema::connection('tenant')->hasTable('bestellungen')) {
            Schema::connection('tenant')->table('bestellungen', function (Blueprint $table) {
                if (Schema::connection('tenant')->hasColumn('bestellungen', 'artikel_ids')) {
                    $table->dropColumn('artikel_ids');
                }
            });
        }
        if (Schema::connection('tenant')->hasTable('bestelleingang_tage')) {
            Schema::connection('tenant')->table('bestelleingang_tage', function (Blueprint $table) {
                if (Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'artikel_id')) {
                    $table->dropColumn('artikel_id');
                }
            });
        }
    }
};
