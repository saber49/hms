<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public webform key per tenant (Lead-Modul A2). The key is the secret in the
 * form URL /api/crm-form/{key}; rotating it invalidates embedded forms.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tenants', 'crm_webform_key')) {
            Schema::table('tenants', function (Blueprint $t) {
                $t->string('crm_webform_key', 48)->nullable()->unique()->after('domain');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'crm_webform_key')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->dropUnique(['crm_webform_key']));
            Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('crm_webform_key'));
        }
    }
};
