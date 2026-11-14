<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user Bereich (area) restriction.
 *
 * A user may be limited to a subset of the company's Bereiche (e.g. only
 * "Bau"). The restriction applies across Dispo, Bestelleingang, Bestellungen
 * and Controlling — every place that surfaces Einsatz/order data carries a
 * Bereich (sourced from artikel.bereich, falling back to kunden.bereich).
 *
 * Semantics:
 *   - NULL or []  → unrestricted: the user sees ALL Bereiche (the previous
 *     behaviour, so existing users are unaffected).
 *   - ["Bau", …]  → the user only sees rows whose Bereich is in the list.
 *
 * Company admins / owners always bypass the restriction (enforced in
 * App\Support\BereichScope), so they can never be locked out of their own
 * data. Stored in the CENTRAL DB next to the user, scoped by tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            if (!Schema::hasColumn('central_users', 'allowed_bereiche')) {
                $table->json('allowed_bereiche')->nullable()->after('permissions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            if (Schema::hasColumn('central_users', 'allowed_bereiche')) {
                $table->dropColumn('allowed_bereiche');
            }
        });
    }
};
