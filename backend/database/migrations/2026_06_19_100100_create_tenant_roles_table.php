<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point 4 — per-tenant custom roles.
 *
 * Lets each company define its own reusable roles (e.g. "Schichtleiter")
 * without a code deploy. A custom role is just a named bundle of permission
 * keys from PermissionRegistry, scoped hard to one tenant via `tenant_id`.
 *
 * A central_user references a custom role by storing its `slug` in
 * central_users.ui_role. Permissions are resolved LIVE at request time, so
 * editing a role immediately propagates to every user that holds it.
 *
 * Lives in the CENTRAL database (alongside central_users) — the tenant_id
 * column is the isolation boundary, identical to central_users.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_roles')) {
            return;
        }

        Schema::create('tenant_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('slug', 64);            // e.g. "schichtleiter"
            $table->string('label', 100);          // e.g. "Schichtleiter"
            $table->json('permissions')->nullable();
            $table->timestamps();

            // A slug is unique within a tenant; different tenants may reuse it.
            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_roles');
    }
};
