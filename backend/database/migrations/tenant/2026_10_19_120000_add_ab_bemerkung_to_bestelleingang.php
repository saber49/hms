<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Own "Bemerkungen" text of the Auftragsbestätigung (edited in its preview).
 * NULL = automatic (Bestelleingang Bemerkung + Objekt Bestellbeschreibung).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasTable('bestelleingang') && !$s->hasColumn('bestelleingang', 'ab_bemerkung')) {
            $s->table('bestelleingang', function (Blueprint $table) {
                $table->text('ab_bemerkung')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('bestelleingang', 'ab_bemerkung')) {
            Schema::connection($this->connection)->table('bestelleingang', function (Blueprint $table) {
                $table->dropColumn('ab_bemerkung');
            });
        }
    }
};
