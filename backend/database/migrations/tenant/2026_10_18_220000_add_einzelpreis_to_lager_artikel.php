<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional unit price to lager_artikel — used for the warehouse
 * value KPI and the per-issue value shown in the Ausgaben/Verlauf log
 * (same "Warenwert" concept DISPONIC uses in its Ressourcenverfolgung).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('lager_artikel')
            && !Schema::connection($this->connection)->hasColumn('lager_artikel', 'einzelpreis')) {
            Schema::connection($this->connection)->table('lager_artikel', function (Blueprint $table) {
                $table->decimal('einzelpreis', 10, 2)->nullable()->after('menge_gesamt');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('lager_artikel', 'einzelpreis')) {
            Schema::connection($this->connection)->table('lager_artikel', function (Blueprint $table) {
                $table->dropColumn('einzelpreis');
            });
        }
    }
};
