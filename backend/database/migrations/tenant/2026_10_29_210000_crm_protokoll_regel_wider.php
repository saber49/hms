<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * crm_protokoll.regel / crm_aktivitaeten.regel: varchar(10) was too short for
 * rule codes like "ZW-fallback" (11 chars) — inserts failed with SQLSTATE 22001.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        foreach (['crm_protokoll', 'crm_aktivitaeten'] as $tb) {
            if ($s->hasTable($tb) && $s->hasColumn($tb, 'regel')) {
                $s->table($tb, fn (Blueprint $t) => $t->string('regel', 30)->nullable()->change());
            }
        }
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        foreach (['crm_protokoll', 'crm_aktivitaeten'] as $tb) {
            if ($s->hasTable($tb) && $s->hasColumn($tb, 'regel')) {
                $s->table($tb, fn (Blueprint $t) => $t->string('regel', 10)->nullable()->change());
            }
        }
    }
};
