<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Legal form (GmbH, UG, AG, GbR, e.K., etc.)
            $table->string('rechtsform', 50)->nullable()->after('company_country');
            // Managing director / authorized representative
            $table->string('geschaeftsfuehrer', 200)->nullable()->after('rechtsform');
            // Commercial Register: HRB / HRA number + court
            $table->string('handelsregister_nr', 50)->nullable()->after('geschaeftsfuehrer');
            $table->string('registergericht', 100)->nullable()->after('handelsregister_nr');
            // Tax IDs
            $table->string('ust_id', 30)->nullable()->after('registergericht'); // USt-IdNr.
            $table->string('steuernummer', 30)->nullable()->after('ust_id');
            // Bank details
            $table->string('bank_name', 100)->nullable()->after('steuernummer');
            $table->string('iban', 34)->nullable()->after('bank_name');
            $table->string('bic', 11)->nullable()->after('iban');
            // Web presence
            $table->string('website', 255)->nullable()->after('bic');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'rechtsform', 'geschaeftsfuehrer',
                'handelsregister_nr', 'registergericht',
                'ust_id', 'steuernummer',
                'bank_name', 'iban', 'bic', 'website',
            ]);
        });
    }
};
