<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('objekte', 'art')) {
            Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
                $table->string('art', 60)->nullable()->after('objektname');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('objekte', 'art')) {
            Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
                $table->dropColumn('art');
            });
        }
    }
};
