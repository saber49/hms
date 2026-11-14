<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('objekte', 'geo_radius')) {
            Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
                // Allowed GPS radius in metres for clock-in at this object.
                // Default 50m; the dispatcher can widen it per object later.
                $table->unsignedSmallInteger('geo_radius')->default(100);
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('objekte', 'geo_radius')) {
            Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
                $table->dropColumn('geo_radius');
            });
        }
    }
};
