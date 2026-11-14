<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lead-Modul Phase 3 (A2): webform defaults. The form is OFF until an admin
 * enables it in Einstellungen; webform_sparte = default Sparte for new leads,
 * webform_name = headline shown on the public form.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        DB::connection($this->connection)->table('crm_einstellungen')->insertOrIgnore([
            ['schluessel' => 'webform_aktiv', 'wert' => '0', 'created_at' => now(), 'updated_at' => now()],
            ['schluessel' => 'webform_sparte', 'wert' => '', 'created_at' => now(), 'updated_at' => now()],
            ['schluessel' => 'webform_name', 'wert' => '', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        DB::connection($this->connection)->table('crm_einstellungen')
            ->whereIn('schluessel', ['webform_aktiv', 'webform_sparte', 'webform_name'])->delete();
    }
};
