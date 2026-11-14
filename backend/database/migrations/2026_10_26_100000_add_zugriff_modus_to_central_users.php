<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit access mode next to `allowed_bereiche`:
 *   alle     → every Bereich of the company
 *   bereiche → only the listed Bereiche; an EMPTY list now means NO access
 * Before this column an empty list meant "everything", so removing a legacy
 * Bereich from a restricted user silently widened his access. The backfill
 * keeps every user's current effective access unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('central_users', 'zugriff_modus')) {
            Schema::table('central_users', function (Blueprint $table) {
                $table->string('zugriff_modus', 20)->nullable()->after('allowed_bereiche');
            });
        }
        foreach (DB::table('central_users')->whereNull('zugriff_modus')->get(['id', 'allowed_bereiche']) as $u) {
            $list = json_decode((string) $u->allowed_bereiche, true);
            $restricted = is_array($list) && array_filter($list, fn ($v) => is_string($v) && trim($v) !== '');
            DB::table('central_users')->where('id', $u->id)->update(['zugriff_modus' => $restricted ? 'bereiche' : 'alle']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('central_users', 'zugriff_modus')) {
            Schema::table('central_users', fn (Blueprint $table) => $table->dropColumn('zugriff_modus'));
        }
    }
};
