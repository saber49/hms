<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional invoice attachment to lager_artikel — companies keep
 * the purchase invoice (photo or PDF) with the item for warranty/audit.
 * Stored on the public disk under uploads/lager/{tenant}/{artikel_id}/
 * and served through the edge's /uploads/* handle (same as the photo).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('lager_artikel')
            && !Schema::connection($this->connection)->hasColumn('lager_artikel', 'rechnung')) {
            Schema::connection($this->connection)->table('lager_artikel', function (Blueprint $table) {
                $table->string('rechnung', 500)->nullable()->after('foto');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('lager_artikel', 'rechnung')) {
            Schema::connection($this->connection)->table('lager_artikel', function (Blueprint $table) {
                $table->dropColumn('rechnung');
            });
        }
    }
};
