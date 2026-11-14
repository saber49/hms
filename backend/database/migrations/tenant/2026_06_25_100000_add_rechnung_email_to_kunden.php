<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('kunden', 'rechnung_email')) {
            Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
                $table->string('rechnung_email', 100)->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('kunden', 'rechnung_email')) {
            Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
                $table->dropColumn('rechnung_email');
            });
        }
    }
};
