<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dienstanweisung am Objekt — PDF + "Das Wichtigste", versioned.
 *
 *  - qm_dienstanweisungen   status entwurf → freigegeben (published, version
 *    set) → archiv when a newer version is published; zurueckgewiesen with
 *    reason. `token` = unguessable link part per version.
 *  - qm_da_bestaetigungen   worker confirmation (only after the PDF was opened).
 *  - qm_da_kunde            customer confirmation / change request (portal).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');

        if (!$s->hasTable('qm_dienstanweisungen')) {
            $s->create('qm_dienstanweisungen', function (Blueprint $t) {
                $t->id();
                $t->string('obj_id', 20)->index();
                $t->unsignedInteger('version')->nullable();
                $t->string('status', 20)->default('entwurf');
                $t->date('gueltig_ab');
                $t->json('kurz');
                $t->string('pdf_pfad', 300);
                $t->string('pdf_name', 250)->nullable();
                $t->unsignedSmallInteger('seiten')->default(0);
                $t->char('token', 48)->unique();
                $t->unsignedBigInteger('erstellt_von')->nullable();
                $t->string('erstellt_von_name', 200)->nullable();
                $t->unsignedBigInteger('freigegeben_von')->nullable();
                $t->timestamp('freigegeben_am')->nullable();
                $t->text('zurueckgewiesen_grund')->nullable();
                $t->date('pruefung_faellig_am')->nullable();
                $t->timestamps();
                $t->index(['obj_id', 'status']);
            });
        }

        if (!$s->hasTable('qm_da_bestaetigungen')) {
            $s->create('qm_da_bestaetigungen', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('dienstanweisung_id');
                $t->string('dienst_nr', 10);
                $t->timestamp('pdf_geoeffnet_am')->nullable();
                $t->timestamp('bestaetigt_am')->nullable();
                $t->string('quelle', 20)->nullable();   // app | terminal
                $t->timestamps();
                $t->unique(['dienstanweisung_id', 'dienst_nr']);
                $t->index('dienst_nr');
            });
        }

        if (!$s->hasTable('qm_da_kunde')) {
            $s->create('qm_da_kunde', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('dienstanweisung_id')->index();
                $t->unsignedBigInteger('zugang_id');
                $t->string('aktion', 20);               // bestaetigt | aenderung
                $t->text('text')->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        foreach (['qm_da_kunde', 'qm_da_bestaetigungen', 'qm_dienstanweisungen'] as $t) {
            Schema::connection('tenant')->dropIfExists($t);
        }
    }
};
