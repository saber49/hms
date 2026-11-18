<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Shared location lookup/geocoding for worker GPS checks and QM photos. */
class GeoLocation
{
    /**
     * Einsatzort used by the strict clock-in check: einsaetze.ort first,
     * then an "Ort: …" line in the Einsatz or Bestelleingang description.
     */
    public static function einsatzAdresse(string $einsatzId): ?string
    {
        $row = DB::connection('tenant')->selectOne(
            "SELECT e.beschreibung, e.bemerkung, e.ort, d.beschreibung AS plan_beschreibung
            FROM einsaetze e
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN bestelleingang_tage d ON d.eingang_id = b.eingang_id AND d.datum = e.datum
                AND (
                    (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                 OR (e.tag_nr IS NULL AND d.tag_nr = (
                        SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2
                         WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum
                    ))
                )
            WHERE e.einsatz_id = ?",
            [$einsatzId]
        );
        if (!$row) return null;
        if (trim((string) ($row->ort ?? '')) !== '') return trim((string) $row->ort);
        $text = trim(($row->beschreibung ?? '') . ' ' . ($row->plan_beschreibung ?? '') . ' ' . ($row->bemerkung ?? ''));
        return preg_match('/ort\s*:\s*(.+?)(?:\n|,\s*[a-zA-Z]+\s*:|$)/iu', $text, $m) ? trim($m[1]) : null;
    }

    /** Postal address of an Objekt — the reference point for non-shift QM entries. */
    public static function objektAdresse(string $objId): ?string
    {
        $o = DB::connection('tenant')->table('objekte')->where('obj_id', $objId)->first(['adresse', 'plz', 'stadt']);
        if (!$o) return null;
        $adresse = trim((string) ($o->adresse ?? ''));
        $stadt = trim((string) trim(($o->plz ?? '') . ' ' . ($o->stadt ?? '')));
        return trim($adresse . ($adresse !== '' && $stadt !== '' ? ', ' : '') . $stadt) ?: null;
    }

    /**
     * If a stored Einsatzort IS a coordinate pair — decimal
     * ("51.663750, 11.815917") or DMS ("51°39'49.5\"N, 11°48'57.3\"E",
     * German O=Ost accepted) — parse it directly. Mirrors beParseCoords()
     * in the wizard frontend so an exact pin entered there verifies
     * against the exact point instead of a Nominatim text search.
     */
    public static function parseCoordsString(string $s): ?array
    {
        $s = trim($s);
        $lat = null;
        $lon = null;
        $dms = '(\d{1,3})\s*[°:d]\s*(\d{1,2})\s*[\'′:\s]\s*(\d{1,2}(?:[.,]\d+)?)\s*["″”\']*';
        if (preg_match('/^' . $dms . '\s*([NSns])[,;\s]+' . $dms . '\s*([EWOewo])$/u', $s, $m)) {
            $lat = (float) $m[1] + (float) $m[2] / 60 + (float) str_replace(',', '.', $m[3]) / 3600;
            $lon = (float) $m[5] + (float) $m[6] / 60 + (float) str_replace(',', '.', $m[7]) / 3600;
            if (preg_match('/s/i', $m[4])) $lat = -$lat;
            if (preg_match('/w/i', $m[8])) $lon = -$lon;
        } elseif (preg_match('/^[\d\s.,;+-]+$/', $s)) {
            preg_match_all('/-?\d+(?:[.,]\d+)?/', $s, $m);
            if (count($m[0]) === 2) {
                $lat = (float) str_replace(',', '.', $m[0][0]);
                $lon = (float) str_replace(',', '.', $m[0][1]);
            }
        }
        if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) return null;
        return ['lat' => (string) $lat, 'lon' => (string) $lon];
    }

    /** Two-tier cached Nominatim lookup; the forever cache survives an outage. */
    public static function geocodeAddress($address): ?array
    {
        $coords = self::parseCoordsString((string) $address);
        if ($coords) return $coords;

        $addrHash = md5(mb_strtolower(trim((string) $address)));
        $key = 'geocode:' . $addrHash;
        $foreverKey = 'geocode:forever:' . $addrHash;
        if ($cached = Cache::get($key)) return $cached;

        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $address));
        $queries = [$normalized];
        if (mb_strpos($normalized, ',') !== false) {
            $queries[] = trim(mb_substr($normalized, mb_strpos($normalized, ',') + 1));
        }

        foreach (array_unique(array_filter($queries)) as $i => $q) {
            if ($i > 0) usleep(250000);
            if ($coords = self::nominatimLookup($q)) {
                Cache::put($key, $coords, now()->addDays(30));
                Cache::forever($foreverKey, $coords);
                return $coords;
            }
        }
        return Cache::get($foreverKey);
    }

    public static function nominatimLookup(string $query): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $query, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'de',
        ]);
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: HMS-Worker-App/1.0\r\n", 'timeout' => 5]]);
        $response = @file_get_contents($url, false, $ctx);
        $results = $response ? json_decode($response, true) : null;
        return !empty($results) ? ['lat' => $results[0]['lat'], 'lon' => $results[0]['lon']] : null;
    }

    public static function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
