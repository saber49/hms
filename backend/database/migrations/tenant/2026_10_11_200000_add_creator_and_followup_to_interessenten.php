<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interessenten follow-up workflow:
 *   interessenten.erstellt_von                 — central_users.id of the creator
 *                                                (set automatically on create)
 *   interessent_kommunikation.naechster_schritt — next step noted with an entry
 *   interessent_kommunikation.wiedervorlage_am  — follow-up date noted with an entry
 *
 * The prospect's own naechster_schritt / wiedervorlage_am stay the single
 * "current" values shown under Akquise-Status; a communication entry that
 * carries them updates those columns (the edit dialog no longer does).
 *
 * Additive + nullable only; idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('interessenten') && !$schema->hasColumn('interessenten', 'erstellt_von')) {
            $schema->table('interessenten', function (Blueprint $table) {
                $table->unsignedBigInteger('erstellt_von')->nullable()->after('konvertiert_zu');
            });
        }

        if ($schema->hasTable('interessent_kommunikation')) {
            if (!$schema->hasColumn('interessent_kommunikation', 'naechster_schritt')) {
                $schema->table('interessent_kommunikation', function (Blueprint $table) {
                    $table->text('naechster_schritt')->nullable()->after('notiz');
                });
            }
            if (!$schema->hasColumn('interessent_kommunikation', 'wiedervorlage_am')) {
                $schema->table('interessent_kommunikation', function (Blueprint $table) {
                    $table->date('wiedervorlage_am')->nullable()->after('naechster_schritt');
                });
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasColumn('interessent_kommunikation', 'wiedervorlage_am')) {
            $schema->table('interessent_kommunikation', fn (Blueprint $t) => $t->dropColumn('wiedervorlage_am'));
        }
        if ($schema->hasColumn('interessent_kommunikation', 'naechster_schritt')) {
            $schema->table('interessent_kommunikation', fn (Blueprint $t) => $t->dropColumn('naechster_schritt'));
        }
        if ($schema->hasColumn('interessenten', 'erstellt_von')) {
            $schema->table('interessenten', fn (Blueprint $t) => $t->dropColumn('erstellt_von'));
        }
    }
};
