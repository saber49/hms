<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen `users.role` ENUM to match the UI roles served by central_users.
 *
 * Why:
 *   The UI exposes 7 roles (owner, admin, manager, dispo, buchhaltung,
 *   viewer, custom). Only 3 of them ('admin','manager','viewer') were
 *   valid in this column, so saving a user with role=custom (any user
 *   whose permissions diverge from a preset) failed with:
 *       SQLSTATE[01000] Warning: 1265 Data truncated for column 'role'
 *
 *   tenant.users is a MIRROR of central_users (used for chat partitioning
 *   and audit trails). The full UI role lives there so we can show the
 *   correct preset label without re-deriving it from permissions.
 *
 * Migration is idempotent: if the column already accepts 'custom', it
 * does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $col = DB::connection('tenant')->selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'users'
                AND COLUMN_NAME  = 'role'"
        );

        if ($col && stripos($col->COLUMN_TYPE, "'custom'") !== false) {
            // Already widened — no-op.
            return;
        }

        DB::connection('tenant')->statement(
            "ALTER TABLE users
              MODIFY COLUMN role
              ENUM('owner','admin','manager','dispo','buchhaltung','viewer','custom')
              NOT NULL DEFAULT 'viewer'"
        );
    }

    public function down(): void
    {
        // Collapse the new values back into the legacy 3-value enum so the
        // ALTER doesn't truncate. This is a destructive coarsening: dispo
        // and buchhaltung become 'manager', custom and owner become 'admin'.
        DB::connection('tenant')->statement(
            "UPDATE users SET role = CASE
                WHEN role IN ('owner','admin')                    THEN 'admin'
                WHEN role IN ('manager','dispo','buchhaltung')    THEN 'manager'
                ELSE 'viewer'
              END"
        );
        DB::connection('tenant')->statement(
            "ALTER TABLE users
              MODIFY COLUMN role
              ENUM('admin','manager','viewer')
              NOT NULL DEFAULT 'viewer'"
        );
    }
};
