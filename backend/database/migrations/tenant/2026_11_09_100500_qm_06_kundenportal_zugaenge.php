<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kundenportal becomes part of the QM module: several logins per customer
 * (one per Ansprechpartner), each limited to selected objects, start password
 * must be changed on first login, locked after 5 failed attempts.
 *
 * Existing accesses keep working: objekte NULL = all objects of the customer,
 * passwort_aendern stays 0 for them.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');
        if (!$s->hasTable('kunden_portal_zugaenge')) {
            $s->create('kunden_portal_zugaenge', function (Blueprint $t) {
                $t->id();
                $t->string('kunde_id', 20)->index();
                $t->string('username', 50)->unique();
                $t->string('password', 255)->nullable();
                $t->boolean('aktiv')->default(false);
                $t->timestamp('last_login_at')->nullable();
                $t->timestamps();
            });
        } else {
            $hasUnique = collect(DB::connection('tenant')->select("SHOW INDEX FROM kunden_portal_zugaenge WHERE Key_name = 'kunden_portal_zugaenge_kunde_id_unique'"))->isNotEmpty();
            if ($hasUnique) {
                $s->table('kunden_portal_zugaenge', function (Blueprint $t) {
                    $t->dropUnique('kunden_portal_zugaenge_kunde_id_unique');
                    $t->index('kunde_id');
                });
            }
        }

        $s->table('kunden_portal_zugaenge', function (Blueprint $t) use ($s) {
            if (!$s->hasColumn('kunden_portal_zugaenge', 'name')) $t->string('name', 200)->nullable()->after('kunde_id');
            if (!$s->hasColumn('kunden_portal_zugaenge', 'email')) $t->string('email', 200)->nullable()->after('name');
            if (!$s->hasColumn('kunden_portal_zugaenge', 'objekte')) $t->json('objekte')->nullable()->after('email');
            if (!$s->hasColumn('kunden_portal_zugaenge', 'passwort_aendern')) $t->boolean('passwort_aendern')->default(false)->after('password');
            if (!$s->hasColumn('kunden_portal_zugaenge', 'fehlversuche')) $t->unsignedTinyInteger('fehlversuche')->default(0)->after('passwort_aendern');
            if (!$s->hasColumn('kunden_portal_zugaenge', 'gesperrt_seit')) $t->timestamp('gesperrt_seit')->nullable()->after('fehlversuche');
        });
    }

    public function down(): void
    {
        // Columns stay — older code ignores them.
    }
};
