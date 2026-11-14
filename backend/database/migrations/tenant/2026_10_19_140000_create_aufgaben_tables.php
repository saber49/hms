<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aufgaben (tasks) — an office user assigns a task (Bereich, title, details,
 * priority, due date) to one or more recipients: workers (mitarbeiter, done
 * in the worker app) and/or office users (central_users, done in the admin
 * system). Every recipient has his own status row.
 *
 *   aufgaben            — the task itself
 *   aufgabe_empfaenger  — one row per recipient (typ mitarbeiter|user)
 *
 * created_by / user_id reference central_users.id (cross-DB → plain bigint,
 * plus a name snapshot so deleted users still read correctly).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (!$s->hasTable('aufgaben')) {
            $s->create('aufgaben', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('titel', 200);
                $table->text('beschreibung')->nullable();
                $table->string('bereich', 190)->nullable();
                $table->string('prioritaet', 20)->default('normal'); // niedrig|normal|hoch|dringend
                $table->date('faellig_am')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name', 200)->nullable();
                $table->timestamps();
                $table->index('created_by');
            });
        }
        if (!$s->hasTable('aufgabe_empfaenger')) {
            $s->create('aufgabe_empfaenger', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('aufgabe_id');
                $table->string('typ', 20);                       // mitarbeiter | user
                $table->string('dienst_nr', 20)->nullable();     // typ = mitarbeiter
                $table->unsignedBigInteger('user_id')->nullable(); // typ = user
                $table->string('name', 200)->nullable();
                $table->string('status', 20)->default('offen');  // offen | in_arbeit | erledigt
                $table->text('kommentar')->nullable();
                $table->dateTime('gelesen_am')->nullable();
                $table->dateTime('erledigt_am')->nullable();
                $table->timestamps();
                $table->index('aufgabe_id');
                $table->index(['typ', 'dienst_nr']);
                $table->index(['typ', 'user_id']);
                $table->foreign('aufgabe_id')->references('id')->on('aufgaben')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('aufgabe_empfaenger');
        Schema::connection($this->connection)->dropIfExists('aufgaben');
    }
};
