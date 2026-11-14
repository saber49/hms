<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Auftragsbestätigung sent-tracking: records WHEN the order
     * confirmation PDF was marked as sent to the customer and by WHICH
     * central user. All reads/writes are Schema-guarded so tenants
     * without this migration keep the old behaviour.
     */
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('bestelleingang')
            && !Schema::connection('tenant')->hasColumn('bestelleingang', 'ab_sent_at')) {
            Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
                $table->timestamp('ab_sent_at')->nullable()->after('status')
                    ->comment('Auftragsbestätigung marked as sent to the customer');
                $table->unsignedBigInteger('ab_sent_by')->nullable()->after('ab_sent_at')
                    ->comment('central_users.id who marked the confirmation as sent');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('bestelleingang', 'ab_sent_at')) {
            Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
                $table->dropColumn(['ab_sent_at', 'ab_sent_by']);
            });
        }
    }
};
