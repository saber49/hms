<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central authority for per-user Bereich (area) restrictions.
 *
 * A user's `allowed_bereiche` is either NULL/empty (unrestricted — sees all
 * Bereiche, the default) or a whitelist of Bereich names. Company admins and
 * owners ALWAYS bypass the restriction so they can never be locked out.
 *
 * Bereich is a free-text value derived from artikel.bereich with a fallback
 * to kunden.bereich. This helper centralises every comparison so the rule is
 * applied identically across Dispo, Bestelleingang, Bestellungen and
 * Controlling — there is exactly one place to reason about.
 */
final class BereichScope
{
    /**
     * Resolve the lowercased whitelist for a user, or NULL when the user is
     * unrestricted (admin/owner, or empty allow-list).
     *
     * @return array<int,string>|null
     */
    public static function for($user): ?array
    {
        if (!$user) {
            return null;
        }

        // Admins & owners always see everything.
        if (!empty($user->is_owner)
            || in_array($user->role ?? '', ['super_admin', 'tenant_admin'], true)) {
            return null;
        }

        $raw = $user->allowed_bereiche ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        // Explicit access mode (central_users.zugriff_modus): 'alle' = whole
        // company; anything else is a whitelist where an empty list grants
        // NOTHING (fail closed). NULL = pre-migration row → legacy rule below.
        $mode = $user->zugriff_modus ?? null;
        if ($mode !== null) {
            return $mode === 'alle' ? null : self::normaliseList(is_array($raw) ? $raw : []);
        }
        if (!is_array($raw) || empty($raw)) {
            return null; // empty = unrestricted
        }

        return self::normaliseList($raw);
    }

    /** True if the user may see the given Bereich value. */
    public static function userAllows($user, ?string $bereich): bool
    {
        return self::listAllows(self::for($user), $bereich);
    }

    /**
     * True if a (already resolved) whitelist permits the given Bereich.
     * A NULL whitelist means unrestricted. Rows whose Bereich is empty are
     * hidden from restricted users (they only ever see their own Bereiche).
     * Comma-separated legacy tags ("Reinigung, Sicherheit") count as a list.
     */
    public static function listAllows(?array $allowed, ?string $bereich): bool
    {
        if ($allowed === null) {
            return true;
        }
        $b = (string) $bereich;
        foreach (explode(',', $b) as $part) {
            $p = mb_strtolower(trim($part));
            if ($p !== '' && in_array($p, $allowed, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Append a SQL predicate restricting a derived Bereich expression to the
     * user's whitelist. No-op when the user is unrestricted.
     *
     * @param string $bereichExpr A SQL expression yielding the row's Bereich,
     *                             e.g. "COALESCE(NULLIF(TRIM(a.bereich),''), NULLIF(TRIM(k.bereich),''))".
     * @param array  $where        Reference to the WHERE-clause fragment list.
     * @param array  $params       Reference to the bound-params list.
     */
    public static function applySql($user, string $bereichExpr, array &$where, array &$params): void
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return;
        }

        if (empty($allowed)) {
            // Defensive: a non-null but empty list should match nothing.
            $where[] = '1 = 0';
            return;
        }

        [$sql, $p] = self::matchBereichExpr($allowed, $bereichExpr);
        $where[] = $sql;
        foreach ($p as $v) {
            $params[] = $v;
        }
    }

    /**
     * Apply the restriction to a fluent query builder via whereRaw.
     * No-op when unrestricted; matches nothing for a non-null empty list.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $bereichExpr  SQL expression yielding the row's Bereich.
     * @return \Illuminate\Database\Query\Builder
     */
    public static function applyBuilder($user, $query, string $bereichExpr)
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return $query;
        }
        if (empty($allowed)) {
            return $query->whereRaw('1 = 0');
        }
        [$sql, $p] = self::matchBereichExpr($allowed, $bereichExpr);
        return $query->whereRaw($sql, $p);
    }

    /**
     * Canonical derived-Bereich expression for a row whose query already
     * JOINs artikel + kunden. Article Bereich wins; customer is the fallback.
     */
    public static function derivedExpr(string $artikelAlias = 'a', string $kundenAlias = 'k'): string
    {
        return "COALESCE(NULLIF(TRIM({$artikelAlias}.bereich), ''), NULLIF(TRIM({$kundenAlias}.bereich), ''))";
    }

    /**
     * Same derivation for a row exposing artikel_id/kunden_id columns but
     * having NO joins — two correlated subqueries (works on einsaetze,
     * bestellungen, bestelleingang — identical column names).
     */
    public static function derivedExprSub(string $alias): string
    {
        return "COALESCE(
            (SELECT NULLIF(TRIM(_ax.bereich), '') FROM artikel _ax WHERE _ax.artikel_id = {$alias}.artikel_id),
            (SELECT NULLIF(TRIM(_kx.bereich), '') FROM kunden  _kx WHERE _kx.kunde_id   = {$alias}.kunden_id)
        )";
    }

    /**
     * "expr matches whitelist" fragment + bound params — comma-aware.
     * '1=1' when unrestricted, '1=0' for a non-null empty list.
     *
     * @return array{0:string,1:array<int,string>}
     */
    public static function inList(?array $allowed, string $expr): array
    {
        if ($allowed === null) {
            return ['1=1', []];
        }
        return self::matchBereichExpr($allowed, $expr);
    }

    /**
     * Pool semantics: rows with an EMPTY Bereich stay visible — used for the
     * shared staffing pool (workers/NU without a tag can be planned by every
     * restricted dispatcher; hiding them would break scheduling).
     */
    public static function applySqlOrEmpty($user, string $bereichExpr, array &$where, array &$params): void
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return;
        }
        if (empty($allowed)) {
            $where[] = '1 = 0';
            return;
        }
        [$sql, $p] = self::matchBereichExpr($allowed, $bereichExpr);
        $where[] = "(TRIM(COALESCE($bereichExpr, '')) = '' OR $sql)";
        foreach ($p as $v) {
            $params[] = $v;
        }
    }

    /**
     * Worker visibility predicate for a mitarbeiter row (alias $alias).
     * Visible = untagged pool worker OR tagged in-scope OR assigned to at
     * least one in-scope Einsatz (needed for Monatsabschluss/history of
     * workers tagged to a different Bereich who still ran cleaning shifts).
     *
     * @return array{0:string,1:array<int,string>}
     */
    public static function workerPredicate($user, string $alias = 'm'): array
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return ['1=1', []];
        }
        if (empty($allowed)) {
            return ['1=0', []];
        }
        $expr = self::derivedExprSub('_ex');
        [$ownSql, $ownP] = self::tagMatchSql(
            "{$alias}.bereich",
            self::hasBereicheColumn('mitarbeiter') ? "{$alias}.bereiche" : null,
            $allowed
        );
        [$eSql, $eP] = self::matchBereichExpr($allowed, $expr);
        $sql = "(
            TRIM(COALESCE({$alias}.bereich, '')) = ''
            OR {$ownSql}
            OR EXISTS (
                SELECT 1 FROM einsatz_mitarbeiter _em
                INNER JOIN einsaetze _ex ON _ex.einsatz_id = _em.einsatz_id
                WHERE _em.dienst_nr = {$alias}.dienst_nr
                  AND {$eSql}
            )
        )";
        return [$sql, array_merge($ownP, $eP)];
    }

    /**
     * NU visibility predicate — same pool semantics as workers: untagged
     * subcontractors are a shared resource; tagged ones need a matching
     * Bereich or an in-scope Einsatz they already serve.
     *
     * @return array{0:string,1:array<int,string>}
     */
    public static function nuPredicate($user, string $alias = 'n'): array
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return ['1=1', []];
        }
        if (empty($allowed)) {
            return ['1=0', []];
        }
        $expr = self::derivedExprSub('_ex');
        [$ownSql, $ownP] = self::tagMatchSql(
            "{$alias}.bereich",
            self::hasBereicheColumn('nachunternehmer') ? "{$alias}.bereiche" : null,
            $allowed
        );
        [$eSql, $eP] = self::matchBereichExpr($allowed, $expr);
        $sql = "(
            TRIM(COALESCE({$alias}.bereich, '')) = ''
            OR {$ownSql}
            OR EXISTS (
                SELECT 1 FROM einsaetze _ex
                WHERE _ex.nu_id = {$alias}.nu_id
                  AND {$eSql}
            )
        )";
        return [$sql, array_merge($ownP, $eP)];
    }

    /**
     * Kunde visibility: STRICT on tags — the customer is visible when his
     * primary `bereich` OR any entry of his `bereiche` service list is in
     * the user's whitelist. (A multi-service customer like
     * [Hauswirtschaft, Reinigung] is legitimately visible to cleaning.)
     *
     * @return array{0:string,1:array<int,string>}
     */
    public static function kundePredicate($user, string $alias = 'k'): array
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return ['1=1', []];
        }
        if (empty($allowed)) {
            return ['1=0', []];
        }
        [$sql, $params] = self::tagMatchSql(
            "{$alias}.bereich",
            self::hasBereicheColumn('kunden') ? "{$alias}.bereiche" : null,
            $allowed
        );
        return ["($sql)", $params];
    }

    /**
     * Objekt visibility: tagged in-scope OR its customer is in-scope OR it
     * owns an in-scope Artikel. Self-contained (correlated subqueries).
     *
     * @return array{0:string,1:array<int,string>}
     */
    public static function objektPredicate($user, string $alias = 'o'): array
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return ['1=1', []];
        }
        if (empty($allowed)) {
            return ['1=0', []];
        }
        [$oSql, $oP] = self::tagMatchSql(
            "{$alias}.bereich",
            self::hasBereicheColumn('objekte') ? "{$alias}.bereiche" : null,
            $allowed
        );
        [$kSql, $kP] = self::tagMatchSql(
            '_kx.bereich',
            self::hasBereicheColumn('kunden') ? '_kx.bereiche' : null,
            $allowed
        );
        [$aSql, $aP] = self::tagMatchSql('_ax.bereich', null, $allowed);
        $sql = "(
            {$oSql}
            OR EXISTS (SELECT 1 FROM kunden _kx
                       WHERE _kx.kunde_id = {$alias}.kunden_id
                         AND {$kSql})
            OR EXISTS (SELECT 1 FROM artikel _ax
                       WHERE _ax.obj_id = {$alias}.obj_id
                         AND {$aSql})
        )";
        return [$sql, array_merge($oP, $kP, $aP)];
    }

    /**
     * Artikel visibility: artikel.bereich is authoritative; an untagged
     * article derives from its object's customer — identical to the Einsatz
     * derivation chain (article → customer).
     *
     * @return array{0:string,1:array<int,string>}
     */
    public static function artikelPredicate($user, string $alias = 'a'): array
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return ['1=1', []];
        }
        if (empty($allowed)) {
            return ['1=0', []];
        }
        // Article's own tags win; an untagged article derives its visibility
        // from its object's customer (primary bereich + bereiche list).
        [$aSql, $aP] = self::tagMatchSql("{$alias}.bereich", null, $allowed);
        [$kSql, $kP] = self::tagMatchSql(
            '_kx.bereich',
            self::hasBereicheColumn('kunden') ? '_kx.bereiche' : null,
            $allowed
        );
        $sql = "(
            {$aSql}
            OR (TRIM(COALESCE({$alias}.bereich, '')) = ''
                AND EXISTS (SELECT 1 FROM objekte _ox
                            INNER JOIN kunden _kx ON _kx.kunde_id = _ox.kunden_id
                            WHERE _ox.obj_id = {$alias}.obj_id
                              AND {$kSql}))
        )";
        return [$sql, array_merge($aP, $kP)];
    }

    // ---------------------------------------------------------------
    // Single-record guards for show/update/delete/write paths.
    // Each returns TRUE for unrestricted users and for missing rows is
    // treated as "not allowed" (callers respond 404/403).
    // ---------------------------------------------------------------

    public static function allowsEinsatz($user, string $einsatzId): bool
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return true;
        }
        [$sql, $p] = self::matchBereichExpr($allowed, self::derivedExprSub('e'));
        return (bool) DB::connection('tenant')->selectOne(
            "SELECT 1 FROM einsaetze e WHERE e.einsatz_id = ? AND $sql LIMIT 1",
            array_merge([$einsatzId], $p)
        );
    }

    public static function allowsBestellung($user, string $bestellId): bool
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return true;
        }
        [$sql, $p] = self::matchBereichExpr($allowed, self::derivedExprSub('b'));
        // Multi-service orders carry their full Leistung list in
        // bestellungen.artikel_ids — the same any-match rule as eingänge.
        if (Schema::connection('tenant')->hasColumn('bestellungen', 'artikel_ids')) {
            $subExpr = "COALESCE(
                NULLIF(TRIM(_ax2.bereich), ''),
                (SELECT NULLIF(TRIM(_kx2.bereich), '') FROM kunden _kx2 WHERE _kx2.kunde_id = b.kunden_id)
            )";
            [$subSql, $subP] = self::matchBereichExpr($allowed, $subExpr);
            $sql = "($sql OR EXISTS (SELECT 1 FROM artikel _ax2
                        WHERE JSON_CONTAINS(b.artikel_ids, JSON_QUOTE(_ax2.artikel_id))
                          AND $subSql))";
            $p = array_merge($p, $subP);
        }
        return (bool) DB::connection('tenant')->selectOne(
            "SELECT 1 FROM bestellungen b WHERE b.bestell_id = ? AND $sql LIMIT 1",
            array_merge([$bestellId], $p)
        );
    }

    public static function allowsEingang($user, string $eingangId): bool
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return true;
        }
        [$sql, $p] = self::matchBereichExpr($allowed, self::derivedExprSub('be'));
        // Multi-service orders: also allowed when ANY artikel of the stored
        // artikel_ids list derives into scope — same any-match rule as the
        // Bestelleingang list query. Without it a restricted dispatcher could
        // open the order (show/index match on any tag) but never save it.
        if (Schema::connection('tenant')->hasColumn('bestelleingang', 'artikel_ids')) {
            $subExpr = "COALESCE(
                NULLIF(TRIM(_ax2.bereich), ''),
                (SELECT NULLIF(TRIM(_kx2.bereich), '') FROM kunden _kx2 WHERE _kx2.kunde_id = be.kunden_id)
            )";
            [$subSql, $subP] = self::matchBereichExpr($allowed, $subExpr);
            $sql = "($sql OR EXISTS (SELECT 1 FROM artikel _ax2
                        WHERE JSON_CONTAINS(be.artikel_ids, JSON_QUOTE(_ax2.artikel_id))
                          AND $subSql))";
            $p = array_merge($p, $subP);
        }
        return (bool) DB::connection('tenant')->selectOne(
            "SELECT 1 FROM bestelleingang be WHERE be.eingang_id = ? AND $sql LIMIT 1",
            array_merge([$eingangId], $p)
        );
    }

    public static function allowsWorker($user, string $dienstNr): bool
    {
        [$sql, $p] = self::workerPredicate($user, 'm');
        return (bool) DB::connection('tenant')->selectOne(
            "SELECT 1 FROM mitarbeiter m WHERE m.dienst_nr = ? AND $sql LIMIT 1",
            array_merge([$dienstNr], $p)
        );
    }

    public static function allowsKunde($user, string $kundeId): bool
    {
        [$sql, $p] = self::kundePredicate($user, 'k');
        return (bool) DB::connection('tenant')->selectOne(
            "SELECT 1 FROM kunden k WHERE k.kunde_id = ? AND $sql LIMIT 1",
            array_merge([$kundeId], $p)
        );
    }

    public static function allowsObjekt($user, string $objId): bool
    {
        [$sql, $p] = self::objektPredicate($user, 'o');
        return (bool) DB::connection('tenant')->selectOne(
            "SELECT 1 FROM objekte o WHERE o.obj_id = ? AND $sql LIMIT 1",
            array_merge([$objId], $p)
        );
    }

    public static function allowsArtikel($user, string $artikelId): bool
    {
        [$sql, $p] = self::artikelPredicate($user, 'a');
        return (bool) DB::connection('tenant')->selectOne(
            "SELECT 1 FROM artikel a WHERE a.artikel_id = ? AND $sql LIMIT 1",
            array_merge([$artikelId], $p)
        );
    }

    public static function allowsNu($user, string $nuId): bool
    {
        [$sql, $p] = self::nuPredicate($user, 'n');
        return (bool) DB::connection('tenant')->selectOne(
            "SELECT 1 FROM nachunternehmer n WHERE n.nu_id = ? AND $sql LIMIT 1",
            array_merge([$nuId], $p)
        );
    }

    /**
     * Guard for user-submitted `bereich` values on create/update of tagged
     * entities (kunde/objekt/artikel/mitarbeiter/nachunternehmer):
     * restricted users may only write their own Bereiche. An empty value is
     * allowed for pool entities (workers/NU); callers that need a mandatory
     * tag (kunde/objekt/artikel) should substitute a default themselves.
     */
    public static function bereichInputAllowed($user, ?string $bereich): bool
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return true;
        }
        $b = trim((string) $bereich);
        if ($b === '') {
            return true; // pool / untagged is allowed
        }
        // Comma-separated input counts as a list — EVERY part must be allowed.
        foreach (explode(',', $b) as $part) {
            $p = mb_strtolower(trim($part));
            if ($p !== '' && !in_array($p, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Update-path guard for the primary `bereich`: re-submitting the stored
     * value unchanged is always allowed (a restricted user may EDIT a
     * multi-service entity he can see via a secondary tag); CHANGING it to
     * an out-of-scope value is not.
     */
    public static function bereichWriteAllowed($user, ?string $new, ?string $existing): bool
    {
        if (mb_strtolower(trim((string) $new)) === mb_strtolower(trim((string) $existing))) {
            return true;
        }
        return self::bereichInputAllowed($user, $new);
    }

    /**
     * Update-path guard for a submitted `bereiche` list: every entry must
     * either be inside the user's whitelist OR already present in the
     * stored list (re-submitting existing out-of-scope tags verbatim is
     * not a scope write — he cannot remove them anyway, they are
     * re-merged server-side).
     *
     * @param  mixed             $list         submitted value
     * @param  array<int,string> $existingList decoded stored `bereiche`
     */
    public static function bereicheWriteAllowed($user, $list, array $existingList): bool
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return true;
        }
        if (!is_array($list)) {
            return false;
        }
        $existingLower = array_map(
            fn ($v) => mb_strtolower(trim((string) $v)),
            array_filter($existingList, 'is_string')
        );
        foreach ($list as $v) {
            if (!is_string($v)) {
                return false;
            }
            foreach (explode(',', $v) as $part) {
                $p = mb_strtolower(trim($part));
                if ($p === ''
                    || in_array($p, $allowed, true)
                    || in_array($p, $existingLower, true)) {
                    continue;
                }
                return false;
            }
        }
        return true;
    }

    /**
     * TRUE when every entry of a submitted `bereiche` multi-select list is
     * within the user's whitelist (unrestricted users: always true).
     *
     * @param array<int,mixed> $list
     */
    public static function bereicheInputAllowed($user, $list): bool
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return true;
        }
        foreach ((array) $list as $v) {
            if (!self::listAllows($allowed, is_string($v) ? $v : null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Entries of a stored `bereiche` list the user may NOT touch — used to
     * preserve out-of-scope service tags when a restricted user edits a
     * multi-service entity (his picker never rendered them, so they are
     * absent from his submitted list but must not be deleted).
     *
     * @param  array<int,mixed> $existingList
     * @return array<int,string>
     */
    public static function outOfScopeValues($user, array $existingList): array
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return [];
        }
        $keep = [];
        foreach ($existingList as $v) {
            if (is_string($v) && !self::listAllows($allowed, $v)) {
                $keep[] = $v;
            }
        }
        return $keep;
    }

    /**
     * Normalise a `bereiche` payload for storage: canonical casing against
     * the tenant's known Bereiche, de-duplicated, and the PRIMARY `bereich`
     * merged in so the list always represents the full service set.
     * Unknown-but-allowed names are kept (the picker may lag a new name).
     *
     * @param  array<int,mixed> $input
     * @return array<int,string>
     */
    public static function bereicheForStorage($input, ?string $primary): array
    {
        $canon = [];
        foreach (self::availableForTenant() as $n) {
            $canon[mb_strtolower($n)] = $n;
        }
        $out = [];
        foreach ((array) $input as $v) {
            if (!is_string($v)) {
                continue;
            }
            $key = mb_strtolower(trim($v));
            if ($key === '') {
                continue;
            }
            $name = $canon[$key] ?? trim($v);
            if (!in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        $p = trim((string) $primary);
        if ($p !== '') {
            $pk = mb_strtolower($p);
            $found = false;
            foreach ($out as $o) {
                if (mb_strtolower($o) === $pk) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $out[] = $canon[$pk] ?? $p;
            }
        }
        return $out;
    }

    /**
     * Canonical display name of the user's first allowed Bereich — used to
     * auto-tag records a restricted user creates without picking a Bereich.
     */
    public static function defaultBereichFor($user): ?string
    {
        $allowed = self::for($user);
        if ($allowed === null || empty($allowed)) {
            return null;
        }
        $canon = [];
        foreach (self::availableForTenant() as $name) {
            $canon[mb_strtolower($name)] = $name;
        }
        return $canon[$allowed[0]] ?? $allowed[0];
    }

    /**
     * Filter a collection/array of rows (objects or arrays) by a `bereich`
     * key. Returns the input untouched when unrestricted.
     */
    public static function filterRows($user, iterable $rows, string $key = 'bereich'): array
    {
        $allowed = self::for($user);
        $out = [];
        foreach ($rows as $row) {
            $val = is_array($row) ? ($row[$key] ?? null) : ($row->$key ?? null);
            if (self::listAllows($allowed, $val)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * The distinct, human-readable Bereich names that currently exist for the
     * active tenant — sourced identically to the Dispo (article Bereich, with
     * a customer fallback). Used to render the permission picker.
     *
     * @return array<int,string>
     */
    public static function availableForTenant(): array
    {
        try {
            $schema = Schema::connection('tenant');
            $parts = [];
            // Every taggable entity contributes its `bereich` values — each
            // column goes through the comma-splitting doc so legacy
            // "A, B" strings surface as TWO picker entries, not one blob.
            // Every UNION branch must emit the same column name AND the
            // same collation — JSON_TABLE columns carry the connection
            // default (utf8mb4_0900_ai_ci) while table columns here are
            // utf8mb4_unicode_ci, and MySQL refuses to UNION mixed
            // collations (error 1271 → this function silently returned
            // []). COLLATE on every branch pins them all together.
            foreach (['artikel', 'kunden', 'objekte', 'mitarbeiter', 'nachunternehmer'] as $tbl) {
                if ($schema->hasTable($tbl) && $schema->hasColumn($tbl, 'bereich')) {
                    $doc = self::tagDocSql("{$tbl}.bereich");
                    $parts[] = "SELECT _jt.b COLLATE utf8mb4_unicode_ci AS bereich FROM {$tbl},
                        JSON_TABLE({$doc}, '$[*]' COLUMNS (b VARCHAR(100) PATH '$')) _jt";
                }
            }
            // Plus the multi-service `bereiche` JSON lists where migrated.
            foreach (['kunden', 'objekte', 'mitarbeiter', 'nachunternehmer'] as $tbl) {
                if ($schema->hasTable($tbl) && $schema->hasColumn($tbl, 'bereiche')) {
                    $parts[] = "SELECT _jt.b COLLATE utf8mb4_unicode_ci AS bereich FROM {$tbl},
                        JSON_TABLE(COALESCE({$tbl}.bereiche, CAST('[]' AS JSON)), '$[*]'
                            COLUMNS (b VARCHAR(100) PATH '$')) _jt";
                }
            }
            // Plus the managed catalog mains.
            if ($schema->hasTable('bereiche_katalog')) {
                $parts[] = "SELECT name COLLATE utf8mb4_unicode_ci AS bereich FROM bereiche_katalog
                            WHERE parent_id IS NULL AND aktiv = 1";
            }
            $rows = DB::connection('tenant')->select(
                'SELECT DISTINCT COALESCE(NULLIF(TRIM(bereich), \'\'), NULL) AS bereich FROM ('
                . implode(' UNION ALL ', $parts)
                . ") AS t WHERE TRIM(COALESCE(bereich, '')) <> ''"
            );
        } catch (\Throwable) {
            return [];
        }

        return collect($rows)
            ->map(fn ($r) => trim((string) ($r->bereich ?? '')))
            ->filter()
            ->unique(fn ($v) => mb_strtolower($v))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Normalise + validate an incoming list of Bereich names against what the
     * tenant actually has, returning canonical names. Unknown entries are
     * dropped. Returns [] when nothing valid remains (→ unrestricted).
     *
     * @return array<int,string>
     */
    public static function sanitiseForStorage(?array $input): array
    {
        if (!is_array($input) || empty($input)) {
            return [];
        }

        $available = self::availableForTenant();
        $byLower = [];
        foreach ($available as $name) {
            $byLower[mb_strtolower($name)] = $name;
        }

        $out = [];
        foreach ($input as $name) {
            if (!is_string($name)) {
                continue;
            }
            $key = mb_strtolower(trim($name));
            if ($key !== '' && isset($byLower[$key]) && !in_array($byLower[$key], $out, true)) {
                $out[] = $byLower[$key];
            }
        }
        return $out;
    }

    /**
     * Objektart options for the picker: catalog SUBS (parent_id != NULL)
     * filtered to the user's scope — a restricted user sees only subs of
     * HIS mains, admins see all. Falls back to existing objekte.art values
     * so legacy types remain selectable.
     *
     * @return array<int,string>
     */
    public static function subOptionsFor($user): array
    {
        $allowed = self::for($user);
        $out = [];
        try {
            if (Schema::connection('tenant')->hasTable('bereiche_katalog')) {
                $rows = DB::connection('tenant')->table('bereiche_katalog as s')
                    ->join('bereiche_katalog as p', 'p.id', '=', 's.parent_id')
                    ->where('s.aktiv', 1)
                    ->orderBy('s.sortierung')->orderBy('s.name')
                    ->get(['s.name', 'p.name as parent']);
                foreach ($rows as $r) {
                    if ($allowed === null || self::listAllows($allowed, $r->parent)) {
                        $out[] = trim((string) $r->name);
                    }
                }
            }
            // Legacy art values stay selectable (they are stored types).
            foreach (DB::connection('tenant')->table('objekte')->whereNotNull('art')->pluck('art') as $art) {
                $out[] = trim((string) $art);
            }
        } catch (\Throwable) {
            // keep whatever was collected
        }
        return array_values(array_unique(array_filter($out)));
    }

    /**
     * Guard for a submitted objekte.arten list — same semantics as
     * bereicheWriteAllowed but the whitelist is "subs of the user's mains".
     * A restricted user may not attach subs of foreign mains; unchanged
     * stored entries always pass.
     */
    public static function artenWriteAllowed($user, $list, array $existingList): bool
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return true;
        }
        if (!is_array($list)) {
            return false;
        }
        $existingLower = array_map(
            fn ($v) => mb_strtolower(trim((string) $v)),
            array_filter($existingList, 'is_string')
        );
        $subParent = self::subParentMap();
        foreach ($list as $v) {
            if (!is_string($v)) {
                return false;
            }
            $p = mb_strtolower(trim($v));
            if ($p === ''
                || in_array($p, $existingLower, true)
                || (isset($subParent[$p]) && in_array($subParent[$p], $allowed, true))) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * Stored `arten` entries a restricted user could not have written
     * himself (subs of foreign mains / non-catalog values) — re-merged on
     * update so his save never strips them.
     *
     * @return array<int,string>
     */
    public static function outOfScopeArten($user, array $existingList): array
    {
        $allowed = self::for($user);
        if ($allowed === null) {
            return [];
        }
        $map = self::subParentMap();
        $keep = [];
        foreach ($existingList as $v) {
            if (!is_string($v)) continue;
            $p = mb_strtolower(trim($v));
            $writable = $p !== '' && isset($map[$p]) && in_array($map[$p], $allowed, true);
            if (!$writable) {
                $keep[] = $v;
            }
        }
        return $keep;
    }

    /** lower(sub.name) → lower(parent main name) for the whole catalog. */
    private static function subParentMap(): array
    {
        try {
            if (!Schema::connection('tenant')->hasTable('bereiche_katalog')) {
                return [];
            }
            $map = [];
            foreach (DB::connection('tenant')->table('bereiche_katalog as s')
                    ->join('bereiche_katalog as p', 'p.id', '=', 's.parent_id')
                    ->get(['s.name', 'p.name as parent']) as $r) {
                $map[mb_strtolower(trim((string) $r->name))] = mb_strtolower(trim((string) $r->parent));
            }
            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Reset per-request memoisation (after catalog writes, in tests). */
    public static function invalidateStaticCache(): void
    {
        self::$bereicheColCache = [];
    }

    /** Tenant DBs migrate lazily — guard every bereiche-JSON reference. */
    private static array $bereicheColCache = [];

    private static function hasBereicheColumn(string $table): bool
    {
        if (!array_key_exists($table, self::$bereicheColCache)) {
            try {
                self::$bereicheColCache[$table] = Schema::connection('tenant')->hasColumn($table, 'bereiche');
            } catch (\Throwable) {
                self::$bereicheColCache[$table] = false;
            }
        }
        return self::$bereicheColCache[$table];
    }

    /**
     * Match predicate for a DERIVED effective-Bereich expression (single
     * string, possibly comma-separated) against the whitelist.
     * Fast path: exact IN; the comma-split JSON match only runs when the
     * value actually contains a comma.
     *
     * @param  array<int,string> $allowed lowercased whitelist (non-empty)
     * @return array{0:string,1:array<int,string>}
     */
    public static function matchBereichExpr(array $allowed, string $expr): array
    {
        if (empty($allowed)) {
            return ['1=0', []];
        }
        $ph = implode(', ', array_fill(0, count($allowed), '?'));
        // Comma fallback uses FIND_IN_SET — a plain scalar function, so the
        // expr may contain correlated subqueries at ANY nesting depth
        // (JSON_TABLE can only see one level up and broke on derived
        // expressions referencing outer aliases).
        $fis = implode(' OR ', array_fill(0, count($allowed),
            "FIND_IN_SET(REPLACE(?, ' ', ''), REPLACE(LOWER(TRIM(COALESCE(($expr), ''))), ' ', '')) > 0"));
        return [
            "(LOWER(TRIM(COALESCE(($expr), ''))) IN ($ph)
              OR (COALESCE(($expr), '') LIKE '%,%' AND ($fis)))",
            array_merge($allowed, $allowed),
        ];
    }

    /**
     * Public tag-match for ad-hoc filters (e.g. an explicit `bereich` request
     * param): `bereich` column merged with the JSON `bereiche` list when
     * present, comma-split legacy values included.
     *
     * @param  array<int,string> $values lowercased values (non-empty)
     * @return array{0:string,1:array<int,string>}
     */
    public static function tagMatch(string $col, ?string $jsonCol, array $values): array
    {
        return self::tagMatchSql($col, $jsonCol, $values);
    }

    /**
     * Match predicate for a TAGGED entity column set: exact match on
     * `bereich`, plus the JSON `bereiche` list, plus comma-split legacy
     * values. Returns '1=0' for an empty whitelist.
     *
     * @param  array<int,string> $allowed lowercased whitelist (non-empty)
     * @return array{0:string,1:array<int,string>}
     */
    private static function tagMatchSql(string $col, ?string $jsonCol, array $allowed): array
    {
        if (empty($allowed)) {
            return ['1=0', []];
        }
        $ph = implode(', ', array_fill(0, count($allowed), '?'));
        $sql = "(LOWER(TRIM(COALESCE({$col}, ''))) IN ($ph)";
        $params = $allowed;
        if ($jsonCol !== null) {
            $sql .= " OR EXISTS (SELECT 1 FROM JSON_TABLE(
                    COALESCE({$jsonCol}, CAST('[]' AS JSON)), '$[*]'
                    COLUMNS (_jb VARCHAR(190) PATH '$')) _jtl
                 WHERE LOWER(TRIM(_jtl._jb)) IN ($ph))";
            $params = array_merge($params, $allowed);
        }
        // Same FIND_IN_SET fallback as matchBereichExpr — scalar only, so it
        // stays valid when $col belongs to a deeply nested outer query.
        $fis = implode(' OR ', array_fill(0, count($allowed),
            "FIND_IN_SET(REPLACE(?, ' ', ''), REPLACE(LOWER(TRIM(COALESCE({$col}, ''))), ' ', '')) > 0"));
        $sql .= " OR (COALESCE({$col}, '') LIKE '%,%' AND ($fis))";
        $params = array_merge($params, $allowed);
        return [$sql . ')', $params];
    }

    /**
     * Build a JSON doc from a comma-separated text value ("A, B" → ["A","B"]).
     * Quotes/backslashes are stripped so user text can never break the doc.
     */
    private static function tagDocSql(string $col): string
    {
        return "CAST(CONCAT('[\"',
            REPLACE(REPLACE(REPLACE(COALESCE({$col}, ''), '\\\\', ' '), '\"', ' '), ',', '\",\"'),
            '\"]') AS JSON)";
    }

    /** @return array<int,string> lowercased, de-duplicated */
    private static function normaliseList(array $list): array
    {
        $out = [];
        foreach ($list as $b) {
            if (!is_string($b)) {
                continue;
            }
            $v = mb_strtolower(trim($b));
            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }
}
