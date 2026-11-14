<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a photo column to lager_artikel — item photo shown in the list,
 * the edit dialog and the issue dialog. The file itself lives on the
 * `public` disk under uploads/lager/{tenant}/{artikel_id}/ and is served
 * through the edge's /uploads/* handle (same pattern as worker photos).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('lager_artikel')
            && !Schema::connection($this->connection)->hasColumn('lager_artikel', 'foto')) {
            Schema::connection($this->connection)->table('lager_artikel', function (Blueprint $table) {
                $table->string('foto', 500)->nullable()->after('seriennummer');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('lager_artikel', 'foto')) {
            Schema::connection($this->connection)->table('lager_artikel', function (Blueprint $table) {
                $table->dropColumn('foto');
            });
        }
    }
};
