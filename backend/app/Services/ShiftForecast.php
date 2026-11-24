<?php

namespace App\Services;

use App\Support\GeoLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Optional weather for the planner. The upstream services only receive a
 * rounded coordinate or a postal town, never tenant, customer or shift IDs.
 */
class ShiftForecast
{
    private const MET_URL = 'https://api.met.no/weatherapi/locationforecast/2.0/compact';
    private const GEOCODER_URL = 'https://photon.komoot.io/api/';

    public static function forShift(object $shift): ?array
    {
        TenantService::assertActive();
        $date = substr((string) ($shift->datum ?? ''), 0, 10);
        $time = substr((string) ($shift->ist_start ?? ''), 0, 5);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) return null;

        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "$date $time", 'Europe/Berlin');
        $now = CarbonImmutable::now('Europe/Berlin');
        if (!$start || $start->lt($now) || $start->gt($now->addDays(9))) return null;

        $point = self::coordinates($shift);
        if (!$point) return null;
        [$lat, $lon] = $point;
        // MET Norway requires >=3h cache outside the Nordics; its Expires
        // header can require longer. Coordinates are rounded for cache reuse.
        $key = 'planner:weather:met:v1:' . $lat . ':' . $lon;
        $series = Cache::get($key);
        if ($series === null) {
            try {
                $response = Http::withOptions(['allow_redirects' => false])->withHeaders([
                    'User-Agent' => 'Clood-X/1.0 (+https://clood-x.com/kontakt.html)',
                ])->acceptJson()->connectTimeout(2)->timeout(4)->get(self::MET_URL, [
                    'lat' => $lat, 'lon' => $lon,
                ]);
                $series = $response->ok() ? $response->json('properties.timeseries') : null;
                if (!is_array($series) || !$series) {
                    Cache::put($key, [], now()->addMinutes(5));
                    return null;
                }
                $expires = $response->header('Expires');
                $until = $expires ? strtotime($expires) : false;
                Cache::put($key, $series, now()->addSeconds(max(4 * 3600, $until ? $until - time() : 0)));
            } catch (\Throwable) {
                Cache::put($key, [], now()->addMinutes(5));
                return null;
            }
        }
        return self::closest(is_array($series) ? $series : [], $start);
    }

    /** @return array{0:string,1:string}|null */
    private static function coordinates(object $shift): ?array
    {
        $place = trim((string) ($shift->ort ?? ''));
        if ($place !== '') {
            $exact = GeoLocation::parseCoordsString($place);
            if ($exact) return self::rounded($exact['lat'], $exact['lon']);
            // Disclose only postal town to the geocoder, never the exact
            // customer/site address from the shift's free-text meeting point.
            if (!preg_match('/\b(\d{5})\s+([\p{L}][\p{L}\s.\-()]{1,60})(?:[,;]|$)/u', $place, $m)) return null;
            $zip = $m[1];
            $city = trim($m[2]);
        } else {
            $zip = trim((string) ($shift->plz ?? ''));
            $city = trim((string) ($shift->stadt ?? ''));
        }
        if (!preg_match('/^\d{5}$/', $zip) || $city === '') return null;

        $key = 'planner:weather:town:v1:' . hash('sha256', mb_strtolower("$zip $city"));
        $cached = Cache::get($key);
        if ($cached !== null) return $cached ?: null;
        // The public Photon instance has no SLA; cap cache misses globally.
        if (!Cache::add('planner:weather:geocode-rate', 1, now()->addSeconds(2))) return null;
        try {
            $response = Http::withOptions(['allow_redirects' => false])->acceptJson()
                ->connectTimeout(2)->timeout(3)->get(self::GEOCODER_URL, [
                'q' => "$zip $city, Deutschland", 'limit' => 1, 'lang' => 'de',
            ]);
            $feature = $response->ok() ? $response->json('features.0') : null;
            $p = $feature['properties'] ?? [];
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (!is_array($coordinates) || count($coordinates) < 2
                || (isset($p['postcode']) && (string) $p['postcode'] !== $zip)) {
                Cache::put($key, [], now()->addMinutes(5));
                return null;
            }
            $point = self::rounded($coordinates[1], $coordinates[0]);
            Cache::put($key, $point ?: [], $point ? now()->addDays(30) : now()->addMinutes(5));
            return $point;
        } catch (\Throwable) {
            Cache::put($key, [], now()->addMinutes(5));
            return null;
        }
    }

    private static function rounded(mixed $lat, mixed $lon): ?array
    {
        if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) return null;
        return [number_format((float) $lat, 4, '.', ''), number_format((float) $lon, 4, '.', '')];
    }

    /** Select the closest available forecast hour, not an invented reading. */
    public static function closest(array $series, CarbonImmutable $start): ?array
    {
        $closest = null;
        $distance = 3 * 3600 + 1;
        foreach ($series as $row) {
            if (!is_array($row) || empty($row['time'])) continue;
            try {
                $at = CarbonImmutable::parse($row['time']);
            } catch (\Throwable) {
                continue;
            }
            $delta = abs($at->timestamp - $start->timestamp);
            if ($delta < $distance && is_numeric($row['data']['instant']['details']['air_temperature'] ?? null)) {
                $distance = $delta;
                $closest = $row;
            }
        }
        if (!$closest || $distance > 3 * 3600) return null;
        $temperature = (float) $closest['data']['instant']['details']['air_temperature'];
        $symbol = (string) ($closest['data']['next_1_hours']['summary']['symbol_code']
            ?? $closest['data']['next_6_hours']['summary']['symbol_code'] ?? '');
        return [
            'temperature' => (int) round($temperature),
            'symbol' => preg_match('/^[a-z0-9_]{3,40}$/', $symbol) ? $symbol : 'cloudy',
            'forecast_at' => $closest['time'],
        ];
    }
}
