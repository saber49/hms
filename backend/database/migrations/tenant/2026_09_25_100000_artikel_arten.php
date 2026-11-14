<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // Unterbereiche on artikel — same model as objekte.arten: a JSON
        // list of catalog sub-area names scoped by the object's customer
        // mains. `bereich` stays the (single) authoritative scope tag.
        if (Schema::connection('tenant')->hasTable('artikel')
            && !Schema::connection('tenant')->hasColumn('artikel', 'arten')) {
            Schema::connection('tenant')->table('artikel', function (Blueprint $table) {
                $table->json('arten')->nullable()->after('bereich');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('artikel', 'arten')) {
            Schema::connection('tenant')->table('artikel', function (Blueprint $table) {
                $table->dropColumn('arten');
            });
        }
    }
};
