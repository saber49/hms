<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wachbuch (guard log).
 *
 *  - `wachbuch_eintraege`  one entry per worker submission from the app
 *    (or created/edited by the office). Bound to an Objekt and — when the
 *    shift belongs to a specific service — to an Artikel. `mitarbeiter` is a
 *    JSON list of {name, von, bis}. `client_uuid` makes the app's upload
 *    idempotent (offline queue retries never create duplicates).
 *  - `wachbuch_share_links`  public read-only links for the customer, scoped
 *    to an Objekt (artikel_id NULL = whole object) or one Artikel.
 *
 * Idempotent — checks for each table before creating it.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');

        if (!$s->hasTable('wachbuch_eintraege')) {
            $s->create('wachbuch_eintraege', function (Blueprint $t) {
                $t->id();
                $t->string('obj_id', 20);
                $t->string('artikel_id', 20)->nullable();
                $t->string('einsatz_id', 30)->nullable();
                $t->date('datum');
                $t->json('mitarbeiter')->nullable();
                $t->text('text');
                $t->string('client_uuid', 64)->nullable()->unique();
                $t->string('created_by_dienst_nr', 30)->nullable();
                $t->string('created_by', 255)->nullable();
                $t->string('updated_by', 255)->nullable();
                $t->timestamps();
                $t->index(['obj_id', 'datum']);
                $t->index(['artikel_id', 'datum']);
                $t->index('einsatz_id');
            });
        }

        if (!$s->hasTable('wachbuch_share_links')) {
            $s->create('wachbuch_share_links', function (Blueprint $t) {
                $t->id();
                $t->char('token', 64)->unique();
                $t->string('obj_id', 20);
                $t->string('artikel_id', 20)->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->unsignedInteger('access_count')->default(0);
                $t->timestamp('last_accessed_at')->nullable();
                $t->string('created_by', 255)->nullable();
                $t->timestamps();
                $t->index(['obj_id', 'artikel_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('wachbuch_share_links');
        Schema::connection('tenant')->dropIfExists('wachbuch_eintraege');
    }
};
