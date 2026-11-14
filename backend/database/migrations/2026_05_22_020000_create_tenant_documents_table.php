<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `tenant_documents` on the CENTRAL DB (not the tenant DBs) so that
 * legal company papers (Gewerbeschein, Handelsregisterauszug, Steuerbescheid,
 * insurance, …) live alongside the tenant master record itself, exactly like
 * `logo_path` does today.
 *
 * Each row points to a file on `storage/app/public/tenants/{slug}/documents/…`
 * served through the existing `/api/serve` endpoint (Bearer-auth + path
 * traversal protection).
 *
 * `tenant_id` is the hard isolation boundary — every read/write filters by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_documents')) {
            return;
        }

        Schema::create('tenant_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('category', 40)
                ->comment('gewerbe|handelsregister|steuer|versicherung|ausweis|sonstiges');
            $table->string('title', 200);
            $table->string('file_path', 500)
                ->comment('Path on the public disk, relative to storage/app/public');
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('uploaded_by')->nullable()
                ->comment('central_users.id of the actor who uploaded the file');
            $table->timestamps();

            $table->index('tenant_id', 'idx_td_tenant');
            $table->index(['tenant_id', 'category'], 'idx_td_tenant_category');

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_documents');
    }
};
