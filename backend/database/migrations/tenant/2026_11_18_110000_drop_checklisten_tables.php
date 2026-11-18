<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The Checklisten feature was removed from code (office API + worker API +
 * app UI). Drop its tables — they were verified empty on every tenant.
 *
 * Idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    private const TABLES = ['checklisten_fotos', 'checklisten_protokolle', 'checklisten_zuordnungen', 'checklisten_vorlagen'];

    public function up(): void
    {
        $s = Schema::connection('tenant');
        foreach (self::TABLES as $t) {
            if ($s->hasTable($t)) $s->drop($t);
        }
    }

    public function down(): void
    {
        // Intentionally empty — feature removed permanently.
    }
};
