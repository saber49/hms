<?php

namespace App\Support;

use Carbon\Carbon;

/** Worked span between two wall-clock times of a shift (Europe/Berlin). */
final class ShiftSpan
{
    /**
     * Minutes from Von to Bis on $datum; Bis before Von ends the next day.
     * Uses the real clock, so the October DST night is one hour longer and
     * the March night one hour shorter. Von = Bis returns 0 (no working
     * time — an ambiguous entry is never read as 24 h).
     */
    public static function minutes(?string $datum, string $von, string $bis): int
    {
        $von = substr($von, 0, 5);
        $bis = substr($bis, 0, 5);
        if ($von === $bis) return 0;
        $wall = ((int) substr($bis, 0, 2) * 60 + (int) substr($bis, 3, 2)) - ((int) substr($von, 0, 2) * 60 + (int) substr($von, 3, 2));
        if ($wall < 0) $wall += 1440;
        $day = substr((string) $datum, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return $wall;
        $start = Carbon::parse("$day $von", 'Europe/Berlin');
        $end = Carbon::parse("$day $bis", 'Europe/Berlin');
        if ($bis < $von) $end->addDay();
        $real = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
        return $real > 0 && abs($real - $wall) <= 60 ? $real : $wall;
    }
}
