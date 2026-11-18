<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QM › Kunden-PDF: per-Objekt switch allowing the customer portal to
 * download the Wachbuch day as PDF. OFF by default — the office grants it
 * explicitly under Objekte & Dienstanweisungen.
 *
 * Idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');
        if ($s->hasTable('qm_objekt_einstellungen') && !$s->hasColumn('qm_objekt_einstellungen', 'kunde_pdf')) {
            $s->table('qm_objekt_einstellungen', function (Blueprint $t) {
                $t->boolean('kunde_pdf')->default(false)->after('fotopflicht');
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection('tenant');
        if ($s->hasTable('qm_objekt_einstellungen') && $s->hasColumn('qm_objekt_einstellungen', 'kunde_pdf')) {
            $s->table('qm_objekt_einstellungen', function (Blueprint $t) {
                $t->dropColumn('kunde_pdf');
            });
        }
    }
};
