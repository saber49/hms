<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Auftragsbestätigung — professional document handling:
     *
     *  - bestelleingang.ab_bearbeiter_id: the responsible person printed as
     *    "Bearbeiter" (central_users.id). NULL = the creator (created_by).
     *  - bestelleingang.ab_version: archived version currently "sent".
     *  - auftragsbestaetigung_versionen: one frozen row per sent version —
     *    the exact PDF binary that went to the customer + the document data
     *    (date, Bearbeiter, positions, totals) and a content hash to detect
     *    changes made after sending. Everything is Schema-guarded in
     *    OrderConfirmationController, so tenants without it keep working.
     */
    public function up(): void
    {
        $s = Schema::connection('tenant');
        if ($s->hasTable('bestelleingang')) {
            $s->table('bestelleingang', function (Blueprint $table) use ($s) {
                if (!$s->hasColumn('bestelleingang', 'ab_bearbeiter_id')) {
                    $table->unsignedBigInteger('ab_bearbeiter_id')->nullable()
                        ->comment('central_users.id printed as Bearbeiter (NULL = created_by)');
                }
                if (!$s->hasColumn('bestelleingang', 'ab_version')) {
                    $table->unsignedInteger('ab_version')->nullable()
                        ->comment('auftragsbestaetigung_versionen.version currently sent');
                }
            });
        }
        if (!$s->hasTable('auftragsbestaetigung_versionen')) {
            $s->create('auftragsbestaetigung_versionen', function (Blueprint $table) {
                $table->id();
                $table->string('eingang_id', 30);
                $table->unsignedInteger('version');
                $table->string('pdf_path', 255);
                $table->longText('data')->comment('frozen document payload (JSON)');
                $table->char('content_hash', 64);
                $table->dateTime('sent_at');
                $table->unsignedBigInteger('sent_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['eingang_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection('tenant');
        $s->dropIfExists('auftragsbestaetigung_versionen');
        if ($s->hasTable('bestelleingang')) {
            $s->table('bestelleingang', function (Blueprint $table) use ($s) {
                foreach (['ab_bearbeiter_id', 'ab_version'] as $c) {
                    if ($s->hasColumn('bestelleingang', $c)) $table->dropColumn($c);
                }
            });
        }
    }
};
