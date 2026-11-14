<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Site photos from the worker app are stored as chat messages. The
     * Einsatz they were taken on (derived server-side from the worker's
     * schedule) lets the Kundenportal show each customer only the photos
     * of his own shifts. NULL = not linked (text messages, old photos).
     */
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('mitarbeiter_nachrichten')
            && !Schema::connection('tenant')->hasColumn('mitarbeiter_nachrichten', 'einsatz_id')) {
            Schema::connection('tenant')->table('mitarbeiter_nachrichten', function (Blueprint $table) {
                $table->string('einsatz_id', 30)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('mitarbeiter_nachrichten', 'einsatz_id')) {
            Schema::connection('tenant')->table('mitarbeiter_nachrichten', function (Blueprint $table) {
                $table->dropIndex(['einsatz_id']);
                $table->dropColumn('einsatz_id');
            });
        }
    }
};
