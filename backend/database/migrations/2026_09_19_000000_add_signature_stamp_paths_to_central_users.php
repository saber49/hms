<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            $table->string('signature_path', 500)->nullable()->after('notification_prefs');
            $table->string('stamp_path', 500)->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            $table->dropColumn(['signature_path', 'stamp_path']);
        });
    }
};
