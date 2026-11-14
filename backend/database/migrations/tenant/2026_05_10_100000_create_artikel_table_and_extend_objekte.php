<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // 1) Create artikel table — services performed at an object
        Schema::connection('tenant')->create('artikel', function (Blueprint $table) {
            $table->id();
            $table->string('artikel_id', 20)->unique();
            $table->string('obj_id', 20);
            $table->string('artikel_name', 200);
            $table->string('bereich', 50)->nullable();
            $table->string('modell', 50)->default('Pro Einsatz');
            $table->decimal('preis_kunde', 10, 2)->default(0);
            $table->decimal('nu_preis', 10, 2)->default(0);
            $table->decimal('gewinn', 10, 2)->default(0);
            $table->string('einheit', 30)->nullable(); // Stunde / Pauschale / Einsatz
            $table->boolean('aktiv')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('obj_id')->references('obj_id')->on('objekte')
                ->cascadeOnUpdate()->cascadeOnDelete();

            $table->index(['obj_id', 'aktiv']);
            $table->index('bereich');
        });

        // 2) Extend objekte with location-only fields
        Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
            $table->string('adresse', 255)->nullable()->after('objektname');
            $table->string('plz', 20)->nullable()->after('adresse');
            $table->string('stadt', 100)->nullable()->after('plz');
            $table->string('ansprechpartner', 200)->nullable()->after('stadt');
            $table->string('kontakt_telefon', 50)->nullable()->after('ansprechpartner');
            $table->string('kontakt_email', 100)->nullable()->after('kontakt_telefon');
            $table->text('hinweise')->nullable()->after('kontakt_email');
        });

        // 3) Add artikel_id (nullable) to downstream tables for backward compatibility
        Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
            $table->string('artikel_id', 20)->nullable()->after('obj_id');
            $table->index('artikel_id');
        });

        Schema::connection('tenant')->table('bestellungen', function (Blueprint $table) {
            $table->string('artikel_id', 20)->nullable()->after('obj_id');
            $table->index('artikel_id');
        });

        Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
            $table->string('artikel_id', 20)->nullable()->after('obj_id');
            $table->index('artikel_id');
        });

        // 4) Add artikel_id to preishistorie (price history is per-artikel from now on)
        if (Schema::connection('tenant')->hasTable('preishistorie')) {
            Schema::connection('tenant')->table('preishistorie', function (Blueprint $table) {
                $table->string('artikel_id', 20)->nullable()->after('obj_id');
                $table->index('artikel_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('preishistorie')) {
            Schema::connection('tenant')->table('preishistorie', function (Blueprint $table) {
                $table->dropIndex(['artikel_id']);
                $table->dropColumn('artikel_id');
            });
        }

        Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
            $table->dropIndex(['artikel_id']);
            $table->dropColumn('artikel_id');
        });

        Schema::connection('tenant')->table('bestellungen', function (Blueprint $table) {
            $table->dropIndex(['artikel_id']);
            $table->dropColumn('artikel_id');
        });

        Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
            $table->dropIndex(['artikel_id']);
            $table->dropColumn('artikel_id');
        });

        Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
            $table->dropColumn([
                'adresse', 'plz', 'stadt',
                'ansprechpartner', 'kontakt_telefon', 'kontakt_email', 'hinweise',
            ]);
        });

        Schema::connection('tenant')->dropIfExists('artikel');
    }
};
