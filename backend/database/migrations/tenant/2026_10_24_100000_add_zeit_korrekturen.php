<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Office corrections of recorded working time (Korrekturbuchungen):
 *  - einsatz_mitarbeiter.zeit_korrektur*: which Ist fields the office set
 *    by hand — a later app clock-out / auto-close keeps those fields.
 *  - zeit_korrekturen: append-only audit trail (who, when, before/after)
 *    as required for the working-time records (§ 16 ArbZG, § 17 MiLoG).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');
        if ($s->hasTable('einsatz_mitarbeiter') && !$s->hasColumn('einsatz_mitarbeiter', 'zeit_korrektur')) {
            $s->table('einsatz_mitarbeiter', function (Blueprint $t) {
                $t->json('zeit_korrektur')->nullable();
                $t->timestamp('zeit_korrektur_am')->nullable();
                $t->string('zeit_korrektur_von', 150)->nullable();
            });
        }
        if (!$s->hasTable('zeit_korrekturen')) {
            $s->create('zeit_korrekturen', function (Blueprint $t) {
                $t->id();
                $t->string('einsatz_id', 30)->index();
                $t->string('dienst_nr', 10)->nullable()->index();
                $t->unsignedBigInteger('nu_worker_id')->nullable();
                $t->unsignedBigInteger('position_id')->nullable();
                $t->string('modus', 10); // ist | kunde
                $t->json('vorher')->nullable();
                $t->json('nachher')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('user_name', 150)->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection('tenant');
        $s->dropIfExists('zeit_korrekturen');
        if ($s->hasColumn('einsatz_mitarbeiter', 'zeit_korrektur')) {
            $s->table('einsatz_mitarbeiter', function (Blueprint $t) {
                $t->dropColumn(['zeit_korrektur', 'zeit_korrektur_am', 'zeit_korrektur_von']);
            });
        }
    }
};
