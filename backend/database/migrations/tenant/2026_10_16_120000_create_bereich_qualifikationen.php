<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qualifikationen-Katalog per Hauptbereich (manually maintained):
 *   list   (parent_id NULL)  e.g. "§34a"        under bereich_id = Sicherheit
 *   option (parent_id = list) e.g. "Sachkunde", "Unterrichtung"
 * Workers store the chosen OPTION ids in mitarbeiter.qualifikationen (JSON),
 * so renaming an option never touches worker rows.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $schema = Schema::connection('tenant');
        if (!$schema->hasTable('bereich_qualifikationen')) {
            $schema->create('bereich_qualifikationen', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('bereich_id');            // bereiche_katalog main
                $table->unsignedInteger('parent_id')->nullable(); // NULL = Liste
                $table->string('name', 190);
                $table->boolean('aktiv')->default(1);
                $table->integer('sortierung')->default(0);
                $table->timestamps();
                $table->index(['bereich_id', 'parent_id']);
            });
        }
        if ($schema->hasTable('mitarbeiter') && !$schema->hasColumn('mitarbeiter', 'qualifikationen')) {
            $schema->table('mitarbeiter', function (Blueprint $table) {
                $table->json('qualifikationen')->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');
        if ($schema->hasTable('mitarbeiter') && $schema->hasColumn('mitarbeiter', 'qualifikationen')) {
            $schema->table('mitarbeiter', function (Blueprint $table) {
                $table->dropColumn('qualifikationen');
            });
        }
        $schema->dropIfExists('bereich_qualifikationen');
    }
};
