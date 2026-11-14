<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('nu_portal_mitarbeiter', function (Blueprint $table) {
            $table->string('mobil', 50)->nullable()->after('telefon');
            $table->string('barcode', 50)->nullable()->after('email');
            $table->string('ordner_nr', 50)->nullable()->after('barcode');
            $table->string('bewacher_id', 50)->nullable()->after('ordner_nr');
            $table->string('dfb_pin', 50)->nullable()->after('bewacher_id');
            $table->string('funkrufname', 100)->nullable()->after('dfb_pin');
            $table->string('strasse', 200)->nullable()->after('funkrufname');
            $table->string('plz', 10)->nullable()->after('strasse');
            $table->string('ort', 100)->nullable()->after('plz');
            $table->string('bundesland', 50)->nullable()->after('ort');
            $table->string('land', 50)->nullable()->after('bundesland');
            $table->date('geburtstag')->nullable()->after('land');
            $table->string('geburtsort', 100)->nullable()->after('geburtstag');
            $table->string('geburtsname', 100)->nullable()->after('geburtsort');
            $table->string('geburtsland', 50)->nullable()->after('geburtsname');
            $table->string('sv_nr', 30)->nullable()->after('geburtsland');
            $table->string('ausweis_nr', 50)->nullable()->after('sv_nr');
            $table->date('ausweis_gueltig_ab')->nullable()->after('ausweis_nr');
            $table->date('ausweis_gueltig_bis')->nullable()->after('ausweis_gueltig_ab');
            $table->string('foto', 500)->nullable()->after('ausweis_gueltig_bis');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('nu_portal_mitarbeiter', function (Blueprint $table) {
            $table->dropColumn([
                'mobil', 'barcode', 'ordner_nr', 'bewacher_id', 'dfb_pin', 'funkrufname',
                'strasse', 'plz', 'ort', 'bundesland', 'land',
                'geburtstag', 'geburtsort', 'geburtsname', 'geburtsland',
                'sv_nr', 'ausweis_nr', 'ausweis_gueltig_ab', 'ausweis_gueltig_bis', 'foto',
            ]);
        });
    }
};
