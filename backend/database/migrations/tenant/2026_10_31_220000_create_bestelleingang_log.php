<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-order change log for the Bestelleingang ("Geändert am" history).
 *
 * Every save through the wizard, lifecycle action (stornieren / wieder
 * öffnen / abschließen) and creation appends one row: actor, action and a
 * structured list of {feld, von, nach} changes (header fields + per-day
 * add/remove/field diffs). Shown in the order view dialog.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasTable('bestelleingang_log')) return;

        $s->create('bestelleingang_log', function (Blueprint $table) {
            $table->id();
            $table->string('eingang_id', 40)->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 190)->nullable();
            $table->string('aktion', 40);
            $table->json('aenderungen')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('bestelleingang_log');
    }
};
