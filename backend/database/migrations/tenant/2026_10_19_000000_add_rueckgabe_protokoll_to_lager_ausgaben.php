<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional return-protocol attachment to lager_ausgaben — the signed
 * Rückgabeprotokoll (photo or PDF) is kept with each closed issue row for
 * audit. Stored on the public disk under uploads/lager/{tenant}/protokolle/
 * and served through the edge's /uploads/* handle (same as item photos).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('lager_ausgaben')
            && !Schema::connection($this->connection)->hasColumn('lager_ausgaben', 'rueckgabe_protokoll')) {
            Schema::connection($this->connection)->table('lager_ausgaben', function (Blueprint $table) {
                $table->string('rueckgabe_protokoll', 500)->nullable()->after('rueckgabe_von');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('lager_ausgaben', 'rueckgabe_protokoll')) {
            Schema::connection($this->connection)->table('lager_ausgaben', function (Blueprint $table) {
                $table->dropColumn('rueckgabe_protokoll');
            });
        }
    }
};
