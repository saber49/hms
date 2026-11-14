<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('nachunternehmer', function (Blueprint $table) {
            $table->id();
            $table->string('nu_id', 20)->unique();
            $table->string('firmenname', 200);
            $table->string('ansprechpartner', 100)->nullable();
            $table->string('telefon_email', 200)->nullable();
            $table->string('bereich', 50)->nullable();
            $table->string('anschrift_typ', 20)->default('Firma');
            $table->string('zusatz', 100)->nullable();
            $table->string('strasse', 200)->nullable();
            $table->string('plz', 10)->nullable();
            $table->string('ort', 100)->nullable();
            $table->string('land', 10)->default('D');
            $table->string('telefon1', 50)->nullable();
            $table->string('telefon2', 50)->nullable();
            $table->string('fax', 50)->nullable();
            $table->string('mobil', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('website', 200)->nullable();
            $table->text('notiz')->nullable();
            $table->boolean('aktiv')->default(true);
            $table->string('portal_username', 50)->nullable();
            $table->string('portal_password', 255)->nullable();
            $table->boolean('portal_aktiv')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('nachunternehmer');
    }
};
