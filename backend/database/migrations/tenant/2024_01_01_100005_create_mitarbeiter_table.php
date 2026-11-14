<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('mitarbeiter', function (Blueprint $table) {
            $table->id();
            $table->string('dienst_nr', 10)->unique();
            $table->string('name', 100);
            $table->string('vorname', 100);
            $table->string('telefon', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('bereich', 50)->nullable();
            $table->date('dfb_schulung')->nullable();
            $table->string('steuer_id', 50)->nullable();
            $table->string('sv_nummer', 50)->nullable();
            $table->string('krankenkasse', 100)->nullable();
            $table->string('bank_verbindung', 200)->nullable();
            $table->string('steuerklasse', 10)->nullable();
            $table->string('kinderzahl_freibetrag', 20)->nullable();
            $table->string('familienstand', 30)->nullable();
            $table->date('eintritt')->nullable();
            $table->string('art_der_beschaeftigung', 50)->nullable();
            $table->integer('urlaubstage')->nullable();
            $table->string('arbeitsstunden_typ', 20)->nullable();
            $table->decimal('arbeitsstunden', 8, 2)->nullable();
            $table->string('foto', 500)->nullable();
            $table->boolean('aktiv')->default(true);
            $table->string('password_hash', 255)->nullable();
            $table->string('password_plain', 255)->nullable();
            $table->boolean('app_zugang')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('mitarbeiter');
    }
};
