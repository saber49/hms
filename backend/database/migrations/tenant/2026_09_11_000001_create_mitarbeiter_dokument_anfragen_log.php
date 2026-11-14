<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for document-renewal notifications. Every send (initial request
 * or manual resend) is recorded so HR knows how many reminders a worker has
 * received and who triggered them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('mitarbeiter_dokument_anfragen_log')) {
            return;
        }

        Schema::connection('tenant')->create('mitarbeiter_dokument_anfragen_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('anfrage_id')->index();
            $table->string('aktion', 20)->default('resend'); // requested | resend
            $table->string('sender', 190)->nullable();       // user name or 'System'
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('mitarbeiter_dokument_anfragen_log');
    }
};
