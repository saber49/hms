<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsatz_reminders_sent')) {
            Schema::connection('tenant')->create('einsatz_reminders_sent', function (Blueprint $table) {
                // Dedup log for the "shift starts soon" worker reminder —
                // one row per (einsatz, worker) so the 5-min scheduler never
                // pushes the same reminder twice.
                $table->id();
                $table->string('einsatz_id', 30);
                $table->string('dienst_nr', 20);
                $table->timestamp('sent_at')->useCurrent();
                $table->unique(['einsatz_id', 'dienst_nr'], 'uq_einsatz_reminder');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('einsatz_reminders_sent');
    }
};
