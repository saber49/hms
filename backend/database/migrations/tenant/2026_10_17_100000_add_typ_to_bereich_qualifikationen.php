<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bereich_qualifikationen.typ splits the per-Bereich catalog:
 *   'qualifikation' → shown in the Mitarbeiter form / profile (existing rows)
 *   'kleidung'      → Dienstkleidung, picked in Bestelleingang (not on workers)
 * Options carry their list's typ.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $schema = Schema::connection('tenant');
        if ($schema->hasTable('bereich_qualifikationen') && !$schema->hasColumn('bereich_qualifikationen', 'typ')) {
            $schema->table('bereich_qualifikationen', function (Blueprint $table) {
                $table->string('typ', 20)->default('qualifikation')->after('parent_id');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');
        if ($schema->hasTable('bereich_qualifikationen') && $schema->hasColumn('bereich_qualifikationen', 'typ')) {
            $schema->table('bereich_qualifikationen', function (Blueprint $table) {
                $table->dropColumn('typ');
            });
        }
    }
};
