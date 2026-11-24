<?php

/**
 * Staging-only: forecast selection, cache/outage behavior, then authenticated
 * endpoint isolation. Creates only temporary auth tokens, removed in finally.
 */

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Services\ShiftForecast;
use App\Services\TenantService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!app()->environment('staging')) throw new RuntimeException('Staging-only test');

$passed = 0;
function weatherCheck(bool $ok, string $name): void
{
    global $passed;
    if (!$ok) throw new RuntimeException($name);
    echo "ok $name\n";
    $passed++;
}
function weatherHttp(string $id, ?string $token): array
{
    $ch = curl_init('https://staging.clood-x.com/api/tasks/' . rawurlencode($id) . '/weather');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_RESOLVE => ['staging.clood-x.com:443:127.0.0.1'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'] + ($token ? [1 => 'Authorization: Bearer ' . $token] : []),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $body === false ? '' : $body];
}

$keys = [];
$tokens = [];
$failure = null;
try {
    $ddr = Tenant::where('slug', 'ddr')->firstOrFail();
    TenantService::switchToTenant($ddr);
    $start = CarbonImmutable::now('Europe/Berlin')->addDay()->startOfDay()->addHours(12);
    $time = $start->utc()->format('Y-m-d\TH:i:s\Z');
    $coordinate = '67.1123,-9.8822';
    $failureCoordinate = '67.1124,-9.8822';
    foreach (['67.1123:-9.8822', '67.1124:-9.8822'] as $point) {
        $key = 'planner:weather:met:v1:' . $point;
        Cache::forget($key);
        $keys[] = $key;
    }
    Http::fake([
        '*lat=67.1123*' => Http::response(['properties' => ['timeseries' => [[
            'time' => $time, 'data' => ['instant' => ['details' => ['air_temperature' => -2.3]],
                'next_1_hours' => ['summary' => ['symbol_code' => 'clearsky_night']]],
        ]]]], 200),
        '*lat=67.1124*' => Http::response([], 503),
    ]);
    $shift = (object) ['ort' => $coordinate, 'datum' => $start->format('Y-m-d'), 'ist_start' => '12:00:00'];
    $forecast = ShiftForecast::forShift($shift);
    weatherCheck($forecast && $forecast['temperature'] === -2 && $forecast['symbol'] === 'clearsky_night',
        'forecast selects shift hour, rounded temperature and symbol');
    weatherCheck(ShiftForecast::forShift($shift) === $forecast, 'forecast reused from shared cache');
    $shift->ort = $failureCoordinate;
    weatherCheck(ShiftForecast::forShift($shift) === null && ShiftForecast::forShift($shift) === null,
        'upstream outage returns no badge, without throwing');
    weatherCheck(Http::recorded()->count() === 2, 'cached requests do not repeat provider calls');
    $shift->datum = $start->subDays(20)->format('Y-m-d');
    weatherCheck(ShiftForecast::forShift($shift) === null, 'past shift cannot claim a current forecast');
    TenantService::reset();

    $other = Tenant::where('slug', 'clood-x-system')->firstOrFail();
    foreach ([$ddr, $other] as $tenant) {
        $owner = CentralUser::where('tenant_id', $tenant->id)->where('is_owner', 1)->firstOrFail();
        $token = $owner->createToken('planner-weather-test-' . bin2hex(random_bytes(5)));
        $tokens[] = [$owner, $token->accessToken->id, $token->plainTextToken];
    }
    TenantService::switchToTenant($ddr);
    $id = DB::connection('tenant')->table('einsaetze')->value('einsatz_id');
    if (!$id) throw new RuntimeException('No DDR shift for HTTP scope test');
    TenantService::reset();
    [$status] = weatherHttp($id, null);
    weatherCheck($status === 401, 'anonymous weather request denied');
    [$status] = weatherHttp($id, $tokens[1][2]);
    weatherCheck($status === 404, 'another tenant cannot request shift location or forecast');
    [$status, $body] = weatherHttp($id, $tokens[0][2]);
    weatherCheck(in_array($status, [200, 204], true)
        && ($status !== 200 || isset(json_decode($body, true)['temperature'])),
        'own shift is available; missing forecast stays optional');
    echo "PASS $passed weather checks\n";
} catch (\Throwable $e) {
    $failure = $e;
} finally {
    TenantService::reset();
    foreach ($keys as $key) Cache::forget($key);
    foreach ($tokens as [$owner, $id]) $owner->tokens()->where('id', $id)->delete();
}
if ($failure) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
    exit(1);
}
