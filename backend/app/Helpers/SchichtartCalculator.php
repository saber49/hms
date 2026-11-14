<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pure helper that classifies an Einsatz into one of the six standard
 * German wage-surcharge categories (Schichtarten) and returns the
 * applicable surcharge percentage.
 *
 * Categories (priority high → low):
 *   nacht_feiertag  60 %  — night hours on a public holiday
 *   feiertag        50 %  — daytime hours on a public holiday
 *   nacht_sonntag   35 %  — night hours on a Sunday
 *   sonntag         25 %  — daytime hours on a Sunday
 *   nachtdienst     10 %  — night hours on a regular weekday or Saturday
 *   tagdienst        0 %  — base rate
 *
 * Defaults are configurable per artikel. Night window may cross midnight
 * (e.g. 22:00 → 06:00) and that is handled correctly.
 *
 * Usage:
 *   $artikel = DB::connection('tenant')->table('artikel')->where(...)->first();
 *   $feiertage = SchichtartCalculator::cachedFeiertage();
 *   $result = SchichtartCalculator::compute('2026-05-17', '23:00', '07:00', $artikel, $feiertage);
 *   // → ['schichtart' => 'nacht_sonntag', 'zuschlag_pct' => 35.00]
 */
class SchichtartCalculator
{
    /**
     * Compute the schichtart and surcharge percentage for a single Einsatz.
     *
     * @param string|null $datum     ISO date 'Y-m-d'
     * @param string|null $istStart  'HH:MM' or 'HH:MM:SS'
     * @param string|null $istEnde   'HH:MM' or 'HH:MM:SS' (currently unused; reserved for future proportional split)
     * @param object|array|null $artikel Row with at least:
     *      zuschlaege_aktiv, nacht_von, nacht_bis,
     *      zuschlag_nacht_pct, zuschlag_sonntag_pct, zuschlag_feiertag_pct,
     *      zuschlag_nacht_sonntag_pct, zuschlag_nacht_feiertag_pct
     * @param string[] $feiertage Sorted ISO dates 'Y-m-d' that are public holidays.
     *
     * @return array{schichtart: string, zuschlag_pct: float}
     */
    public static function compute(
        ?string $datum,
        ?string $istStart,
        ?string $istEnde,
        $artikel,
        array $feiertage = []
    ): array {
        // Without an artikel or with surcharges disabled, there's nothing to apply.
        if (!$artikel || !self::prop($artikel, 'zuschlaege_aktiv')) {
            return ['schichtart' => 'tagdienst', 'zuschlag_pct' => 0.00];
        }

        if (!$datum) {
            return ['schichtart' => 'tagdienst', 'zuschlag_pct' => 0.00];
        }

        $startSec = self::toSeconds($istStart);
        $endSec = self::toSeconds($istEnde);
        $nightFrom = self::toSeconds((string) self::prop($artikel, 'nacht_von', '22:00:00'));
        $nightTo = self::toSeconds((string) self::prop($artikel, 'nacht_bis', '06:00:00'));
        if ($startSec === null || $endSec === null || $nightFrom === null || $nightTo === null) {
            return ['schichtart' => 'tagdienst', 'zuschlag_pct' => 0.00];
        }

        $tz = new \DateTimeZone('Europe/Berlin');
        $start = new \DateTimeImmutable($datum . ' ' . substr((string) $istStart, 0, 8), $tz);
        $end = new \DateTimeImmutable($datum . ' ' . substr((string) $istEnde, 0, 8), $tz);
        if ($end <= $start) $end = $end->modify('+1 day');
        $duration = $end->getTimestamp() - $start->getTimestamp();
        if ($duration <= 0) return ['schichtart' => 'tagdienst', 'zuschlag_pct' => 0.00];

        $weighted = 0.0;
        $flags = ['holiday' => false, 'sunday' => false, 'night' => false,
                  'night_holiday' => false, 'night_sunday' => false];
        for ($cursor = $start; $cursor < $end;) {
            $next = $cursor->modify('+1 minute');
            if ($next > $end) $next = $end;
            $seconds = $next->getTimestamp() - $cursor->getTimestamp();
            $date = $cursor->format('Y-m-d');
            $secondOfDay = ((int) $cursor->format('H')) * 3600
                + ((int) $cursor->format('i')) * 60 + (int) $cursor->format('s');
            $isNight = $nightFrom > $nightTo
                ? ($secondOfDay >= $nightFrom || $secondOfDay < $nightTo)
                : ($secondOfDay >= $nightFrom && $secondOfDay < $nightTo);
            $isHoliday = in_array($date, $feiertage, true);
            $isSunday = (int) $cursor->format('w') === 0;

            if ($isHoliday) {
                $rate = (float) self::prop($artikel,
                    $isNight ? 'zuschlag_nacht_feiertag_pct' : 'zuschlag_feiertag_pct',
                    $isNight ? 60.00 : 50.00);
                $flags[$isNight ? 'night_holiday' : 'holiday'] = true;
            } elseif ($isSunday) {
                $rate = (float) self::prop($artikel,
                    $isNight ? 'zuschlag_nacht_sonntag_pct' : 'zuschlag_sonntag_pct',
                    $isNight ? 35.00 : 25.00);
                $flags[$isNight ? 'night_sunday' : 'sunday'] = true;
            } elseif ($isNight) {
                $rate = (float) self::prop($artikel, 'zuschlag_nacht_pct', 10.00);
                $flags['night'] = true;
            } else {
                // Tagdienst minutes — optional configurable surcharge on the
                // base rate (column zuschlag_tag_pct, default 0 = Grundpreis).
                $rate = (float) self::prop($artikel, 'zuschlag_tag_pct', 0.00);
            }
            $weighted += $rate * $seconds;
            $cursor = $next;
        }

        $schichtart = $flags['night_holiday'] ? 'nacht_feiertag'
            : ($flags['holiday'] ? 'feiertag'
            : ($flags['night_sunday'] ? 'nacht_sonntag'
            : ($flags['sunday'] ? 'sonntag'
            : ($flags['night'] ? 'nachtdienst' : 'tagdienst'))));

        return ['schichtart' => $schichtart, 'zuschlag_pct' => round($weighted / $duration, 2)];
    }

    /**
     * Return all active feiertage on the tenant connection as an array of
     * 'Y-m-d' strings (sorted ASC). Cached for the lifetime of the request.
     *
     * Never queries again within the same PHP request, even across many
     * einsaetze in a bulk update.
     */
    /**
     * @var array<string, array<int, string>> Keyed by tenant database name
     * so a worker that serves multiple tenants (Octane / FrankenPHP) can
     * never return Tenant A's holidays to Tenant B.
     */
    protected static array $feiertageCache = [];

    public static function cachedFeiertage(?string $objektId = null): array
    {
        // Bind the cache entry to the *current* tenant database. If the
        // tenant connection is not configured (e.g. a public route forgot
        // to call TenantService::switchToTenant()), bail out with an empty
        // list rather than risk caching cross-tenant data.
        $db = \Illuminate\Support\Facades\Config::get('database.connections.tenant.database');
        if (!$db) {
            return [];
        }

        $state = null;
        if ($objektId && Schema::connection('tenant')->hasColumn('objekte', 'bundesland')) {
            $state = DB::connection('tenant')->table('objekte')->where('obj_id', $objektId)->value('bundesland');
            $state = $state ? strtoupper(trim((string) $state)) : null;
        }
        $cacheKey = $db . ':' . ($state ?: 'DE');
        if (array_key_exists($cacheKey, static::$feiertageCache)) {
            return static::$feiertageCache[$cacheKey];
        }

        if (!Schema::connection('tenant')->hasTable('feiertage')) {
            return static::$feiertageCache[$cacheKey] = [];
        }

        $regions = ['DE'];
        if ($state) $regions[] = 'DE-' . $state;
        $rows = DB::connection('tenant')
            ->table('feiertage')
            ->where('aktiv', 1)
            ->whereIn('region', $regions)
            ->pluck('datum')
            ->all();

        $list = array_values(array_map(static function ($d) {
            // datum may come back as Carbon, DateTime, or string depending on driver.
            if (is_object($d) && method_exists($d, 'format')) return $d->format('Y-m-d');
            return is_string($d) ? substr($d, 0, 10) : (string) $d;
        }, $rows));

        sort($list);
        return static::$feiertageCache[$cacheKey] = array_values(array_unique($list));
    }

    /**
     * Drop all cached entries. Called by the Octane RequestTerminated
     * listener so workers never serve stale tenant data across requests.
     */
    public static function clearCache(): void
    {
        static::$feiertageCache = [];
    }

    /**
     * Read a property from either an object or an associative array, with
     * an optional default. Avoids a sprinkle of `is_object()` checks in
     * the calling code.
     */
    private static function prop($source, string $key, $default = null)
    {
        if (is_object($source)) {
            return $source->$key ?? $default;
        }
        if (is_array($source)) {
            return $source[$key] ?? $default;
        }
        return $default;
    }

    /**
     * Decide if the einsatz START time falls inside the night window
     * defined by $von and $bis. The window may wrap midnight (the common
     * 22:00 → 06:00 case).
     *
     * Kept as a public-ish predicate for backward compatibility; the
     * production code path now uses {@see nightOverlapRatio()} instead,
     * which detects partial overlaps as well.
     */
    private static function startsInNightWindow(?string $istStart, string $von, string $bis): bool
    {
        if (!$istStart) return false;

        $start = self::toSeconds($istStart);
        $a     = self::toSeconds($von);
        $b     = self::toSeconds($bis);

        if ($start === null || $a === null || $b === null) return false;

        if ($a > $b) {
            // wraps midnight: e.g. 22:00..06:00
            return $start >= $a || $start < $b;
        }
        return $start >= $a && $start < $b;
    }

    /**
     * Fraction (0.0 – 1.0) of the einsatz that falls inside the night
     * window. The shift may cross midnight (e.g. 22:00 → 06:00) and the
     * night window may cross midnight (e.g. 22:00 → 06:00) — both cases
     * are handled by computing overlap on a 48-hour timeline.
     *
     * Returns 0.0 when start/end are missing or malformed (so the einsatz
     * conservatively falls back to tagdienst at 0 %).
     *
     * Examples:
     *   • 15:00–24:00, night 22:00–06:00 → 2 of 9 hours = 0.222
     *   • 22:00–06:00, night 22:00–06:00 → 8 of 8 hours = 1.0
     *   • 08:00–17:00, night 22:00–06:00 → 0 of 9 hours = 0.0
     *   • 04:00–12:00, night 22:00–06:00 → 2 of 8 hours = 0.25
     */
    private static function nightOverlapRatio(?string $istStart, ?string $istEnde, string $von, string $bis): float
    {
        if (!$istStart || !$istEnde) return 0.0;

        $s = self::toSeconds($istStart);
        $e = self::toSeconds($istEnde);
        $a = self::toSeconds($von);
        $b = self::toSeconds($bis);
        if ($s === null || $e === null || $a === null || $b === null) return 0.0;

        // Normalise the shift to a [start, end] interval on a 48-hour line.
        // If end <= start the shift wraps midnight, so push the end into "day 2".
        // We also accept "00:00" as an end-time meaning "24:00" (very common
        // in this codebase since users type a 09:00–24:00 shift as 09:00–00:00).
        if ($e <= $s) $e += 86400;
        if ($e === $s) return 0.0; // zero-length shift

        // Build the union of night windows that intersect a 48-hour timeline.
        // The night window can wrap midnight (a > b) or not (a < b).
        $nightSegments = [];
        if ($a < $b) {
            // simple window on day 1 and (potentially) day 2
            $nightSegments[] = [$a,           $b];
            $nightSegments[] = [$a + 86400,   $b + 86400];
        } else {
            // wraps midnight: [a, 86400) ∪ [0, b) on day 1, repeat day 2
            $nightSegments[] = [$a,             86400];
            $nightSegments[] = [0,              $b];
            $nightSegments[] = [$a + 86400,     86400 + 86400];
            $nightSegments[] = [86400,          86400 + $b];
        }

        $overlap = 0;
        foreach ($nightSegments as [$ns, $ne]) {
            $lo = max($s, $ns);
            $hi = min($e, $ne);
            if ($hi > $lo) $overlap += ($hi - $lo);
        }

        $shiftLen = $e - $s;
        return max(0.0, min(1.0, $overlap / $shiftLen));
    }

    /**
     * Parse an 'HH:MM' or 'HH:MM:SS' string into seconds since midnight.
     * Returns null on malformed input.
     */
    private static function toSeconds(?string $time): ?int
    {
        if (!$time) return null;
        $parts = explode(':', $time);
        if (count($parts) < 2) return null;
        $h = (int) $parts[0];
        $m = (int) $parts[1];
        $s = isset($parts[2]) ? (int) $parts[2] : 0;
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59 || $s < 0 || $s > 59) return null;
        return $h * 3600 + $m * 60 + $s;
    }
}
