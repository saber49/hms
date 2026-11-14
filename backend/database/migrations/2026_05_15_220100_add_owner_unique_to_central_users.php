<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce: at most ONE owner per tenant.
 *
 * MySQL doesn't support partial unique indexes, so we emulate one with a
 * generated column:
 *   owner_unique = (CASE WHEN is_owner = 1 THEN tenant_id ELSE NULL END)
 *
 * NULL values are not considered duplicates by a UNIQUE index, so all
 * non-owner rows can coexist freely while at most one row per tenant
 * may carry the same tenant_id in `owner_unique`.
 *
 * Defensive: before adding the index we sanity-check that no tenant
 * already has more than one owner. If it does, only the lowest-id row
 * keeps `is_owner = 1`; the others are demoted (and a notice logged so
 * an operator can investigate).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Sanity-check & repair existing duplicates.
        $duplicates = DB::connection('mysql')->table('central_users')
            ->select('tenant_id', DB::raw('COUNT(*) AS owners'))
            ->where('is_owner', 1)
            ->groupBy('tenant_id')
            ->having('owners', '>', 1)
            ->get();

        foreach ($duplicates as $d) {
            $rows = DB::connection('mysql')->table('central_users')
                ->where('tenant_id', $d->tenant_id)
                ->where('is_owner', 1)
                ->orderBy('id')
                ->pluck('id')
                ->all();
            // Keep the first; demote the rest.
            $keep = array_shift($rows);
            if (!empty($rows)) {
                DB::connection('mysql')->table('central_users')
                    ->whereIn('id', $rows)
                    ->update(['is_owner' => 0]);
                logger()->warning('OwnerUniqueMigration: demoted duplicate owners', [
                    'tenant_id' => $d->tenant_id,
                    'kept'      => $keep,
                    'demoted'   => $rows,
                ]);
            }
        }

        // 2) Add the generated column + unique index, if not already present.
        $hasColumn = Schema::connection('mysql')->hasColumn('central_users', 'owner_unique');
        if (!$hasColumn) {
            DB::connection('mysql')->statement(
                "ALTER TABLE central_users
                    ADD COLUMN owner_unique BIGINT UNSIGNED
                    GENERATED ALWAYS AS
                    (CASE WHEN is_owner = 1 THEN tenant_id ELSE NULL END)
                    VIRTUAL"
            );
        }

        // The index name is checked via information_schema so re-running
        // the migration is safe.
        $idxExists = DB::connection('mysql')->selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.STATISTICS
              WHERE table_schema = DATABASE()
                AND table_name   = 'central_users'
                AND index_name   = 'uniq_owner_per_tenant'"
        );
        if (!$idxExists || (int) $idxExists->c === 0) {
            DB::connection('mysql')->statement(
                "ALTER TABLE central_users
                    ADD UNIQUE INDEX uniq_owner_per_tenant (owner_unique)"
            );
        }
    }

    public function down(): void
    {
        $idxExists = DB::connection('mysql')->selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.STATISTICS
              WHERE table_schema = DATABASE()
                AND table_name   = 'central_users'
                AND index_name   = 'uniq_owner_per_tenant'"
        );
        if ($idxExists && (int) $idxExists->c > 0) {
            DB::connection('mysql')->statement(
                "ALTER TABLE central_users DROP INDEX uniq_owner_per_tenant"
            );
        }
        if (Schema::connection('mysql')->hasColumn('central_users', 'owner_unique')) {
            DB::connection('mysql')->statement(
                "ALTER TABLE central_users DROP COLUMN owner_unique"
            );
        }
    }
};
