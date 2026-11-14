<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan', 50)->default('trial')->after('aktiv');
            $table->timestamp('trial_ends_at')->nullable()->after('plan');
            $table->timestamp('subscription_ends_at')->nullable()->after('trial_ends_at');
            $table->boolean('onboarding_completed')->default(false)->after('subscription_ends_at');
            $table->string('company_email')->nullable()->after('name');
            $table->string('company_phone', 50)->nullable()->after('company_email');
            $table->string('company_address')->nullable()->after('company_phone');
            $table->string('company_city', 100)->nullable()->after('company_address');
            $table->string('company_zip', 20)->nullable()->after('company_city');
            $table->string('company_country', 5)->default('DE')->after('company_zip');
            $table->string('logo_path')->nullable()->after('company_country');
            $table->integer('max_workers')->default(10)->after('settings');
            $table->integer('max_customers')->default(20)->after('max_workers');
            $table->integer('max_users')->default(3)->after('max_customers');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'plan', 'trial_ends_at', 'subscription_ends_at',
                'onboarding_completed', 'company_email', 'company_phone',
                'company_address', 'company_city', 'company_zip',
                'company_country', 'logo_path', 'max_workers',
                'max_customers', 'max_users',
            ]);
        });
    }
};
