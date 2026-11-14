<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-Mail section — shared company mailboxes (info@, buchhaltung@ …).
 *
 * Messages are NOT stored: they are read live from the provider. This
 * table only holds the connection. `credentials` is Crypt-encrypted JSON
 * (IMAP password or OAuth tokens) and must never be selected into an API
 * response. Idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('mail_konten')) {
            Schema::connection('tenant')->create('mail_konten', function (Blueprint $t) {
                $t->id();
                $t->string('email', 190)->unique();
                $t->string('anzeige_name', 190)->nullable();
                $t->string('provider', 20);
                $t->string('imap_host', 190)->nullable();
                $t->unsignedSmallInteger('imap_port')->nullable();
                $t->string('imap_encryption', 10)->nullable();
                $t->string('smtp_host', 190)->nullable();
                $t->unsignedSmallInteger('smtp_port')->nullable();
                $t->string('smtp_encryption', 10)->nullable();
                $t->string('username', 190)->nullable();
                $t->text('credentials');
                $t->boolean('aktiv')->default(true);
                $t->string('last_error', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('mail_konten');
    }
};
