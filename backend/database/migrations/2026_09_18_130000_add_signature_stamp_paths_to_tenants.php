<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Company signature image (transparent PNG) shown above the
            // "Datum, Unterschrift <Firma>" line in Bestellung PDFs.
            $table->string('signature_path')->nullable()->after('logo_path');
            // Company stamp/seal image rendered next to the signature.
            $table->string('stamp_path')->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['signature_path', 'stamp_path']);
        });
    }
};
