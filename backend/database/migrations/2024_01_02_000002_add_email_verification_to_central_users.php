<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->string('full_name', 100)->nullable()->after('password');
            $table->enum('role', ['super_admin', 'tenant_admin', 'user'])->default('user')->after('full_name');
            $table->string('verification_token', 64)->nullable()->after('remember_token');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Copy existing 'name' values to 'full_name'
        \Illuminate\Support\Facades\DB::statement("UPDATE central_users SET full_name = name WHERE full_name IS NULL");
    }

    public function down(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn(['tenant_id', 'full_name', 'role', 'verification_token']);
        });
    }
};
