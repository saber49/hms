<?php

/**
 * Authenticated staging roundtrip for worker Wachbuch photo upload.
 * Creates a marked temporary running shift, posts a real multipart JPEG,
 * verifies the mandatory-photo error path, then removes every fixture row,
 * token and stored test photo. Never run against production.
 */

use App\Models\Tenant;
use App\Services\TenantService;
use App\Support\Qm\Qm;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!app()->environment('staging')) throw new RuntimeException('Staging-only test.');

$passes = 0;
function checkWf(bool $ok, string $label, string $detail = ''): void
{
    global $passes;
    if (!$ok) throw new RuntimeException($label . ($detail ? ': ' . $detail : ''));
    echo "ok $label\n";
    $passes++;
}

$tenant = Tenant::where('slug', 'ddr')->firstOrFail();
$mark = 'WF' . bin2hex(random_bytes(3));
$dn = $mark . 'W';
$eid = $mark . 'E';
$obj = $mark . 'O';
$customer = $mark . 'K';
$plain = bin2hex(random_bytes(32));
$tokenId = null;
$blattId = null;
$fotoPfade = [];

function wfCleanup(string $mark, string $dn, string $eid, string $obj, string $customer, ?int $tokenId, array $fotoPfade): void
{
    foreach ($fotoPfade as $pfad) {
        try { Storage::disk('local')->delete($pfad); } catch (Throwable) {}
    }
    try {
        $entryIds = Qm::t('qm_wb_eintraege')->where('obj_id', $obj)->pluck('id');
        Qm::t('qm_wb_fotos')->whereIn('eintrag_id', $entryIds)->delete();
        Qm::t('qm_wb_eintraege')->where('obj_id', $obj)->delete();
        Qm::t('qm_wb_blaetter')->where('obj_id', $obj)->delete();
        Qm::t('zeiterfassung')->where('einsatz_id', $eid)->where('dienst_nr', $dn)->delete();
        Qm::t('einsaetze')->where('einsatz_id', $eid)->delete();
        Qm::t('qm_objekt_einstellungen')->where('obj_id', $obj)->delete();
        Qm::t('objekte')->where('obj_id', $obj)->delete();
        Qm::t('kunden')->where('kunde_id', $customer)->delete();
        Qm::t('mitarbeiter')->where('dienst_nr', $dn)->delete();
    } catch (Throwable $e) {
        echo "cleanup warning: " . $e->getMessage() . "\n";
    }
    TenantService::reset();
    if ($tokenId) DB::connection('mysql')->table('personal_access_tokens')->where('id', $tokenId)->where('tokenable_type', 'worker')->delete();
}

TenantService::switchToTenant($tenant);
try {
    Qm::t('kunden')->insert(['kunde_id' => $customer, 'kundenname' => 'Wachbuch Foto HTTP Test']);
    Qm::t('objekte')->insert(['obj_id' => $obj, 'kunden_id' => $customer, 'objektname' => 'Wachbuch Foto Testobjekt', 'aktiv' => 1]);
    Qm::t('qm_objekt_einstellungen')->insert(['obj_id' => $obj, 'wachbuch_aktiv' => 1, 'fotopflicht' => 0, 'created_at' => now(), 'updated_at' => now()]);
    Qm::t('mitarbeiter')->insert(['dienst_nr' => $dn, 'name' => 'Foto', 'vorname' => 'HTTP Test', 'aktiv' => 1, 'app_zugang' => 1]);
    Qm::t('einsaetze')->insert([
        'einsatz_id' => $eid, 'obj_id' => $obj, 'kunden_id' => $customer,
        'datum' => now()->toDateString(), 'ist_start' => '00:00', 'ist_ende' => '23:59',
        'pruefung' => 'geplant', 'created_at' => now(),
    ]);
    Qm::t('zeiterfassung')->insert([
        'einsatz_id' => $eid, 'dienst_nr' => $dn, 'status' => 'aktiv',
        'clock_in' => now()->subHour(), 'clock_in_real' => now()->subHour(),
    ]);
    $workerId = Qm::t('mitarbeiter')->where('dienst_nr', $dn)->value('id');
    $tokenId = DB::connection('mysql')->table('personal_access_tokens')->insertGetId([
        'tokenable_type' => 'worker', 'tokenable_id' => $workerId, 'name' => 'wachbuch-foto-http-test',
        'token' => hash('sha256', $plain), 'abilities' => json_encode(['worker-app', 'tenant_id' => $tenant->id]),
        'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $client = Http::withOptions(['verify' => false, 'curl' => [CURLOPT_RESOLVE => ['staging.clood-x.com:443:127.0.0.1']]])
        ->acceptJson()->timeout(30)->withToken($plain);
    $url = 'https://staging.clood-x.com/api/worker-app/qm/wachbuch/eintraege';
    $baseFields = [
        'art' => 'foto', 'text' => 'HTTP Foto Roundtrip', 'details' => '{}',
        'client_uuid' => $mark . '-missing', 'erfasst_am' => now()->toIso8601String(), 'einsatz_id' => $eid,
    ];
    $missing = $client->post($url, $baseFields);
    checkWf($missing->status() === 422 && str_contains($missing->body(), 'Foto ist Pflicht'), 'missing photo is still rejected as mandatory', $missing->status() . ' ' . $missing->body());

    $img = imagecreatetruecolor(320, 220);
    imagefilledrectangle($img, 0, 0, 320, 220, imagecolorallocate($img, 30, 90, 180));
    ob_start(); imagejpeg($img, null, 88); $jpeg = ob_get_clean();
    checkWf(is_string($jpeg) && strlen($jpeg) > 500, 'valid JPEG fixture generated');

    $fields = $baseFields;
    $fields['client_uuid'] = $mark . '-ok';
    $r = $client->attach('foto', $jpeg, 'foto.jpg')->post($url, $fields);
    checkWf($r->ok() && !empty($r['eintrag']['id']), 'multipart JPEG entry accepted through public API', $r->status() . ' ' . $r->body());
    $entryId = (int) $r['eintrag']['id'];
    $blattId = (int) Qm::t('qm_wb_eintraege')->where('id', $entryId)->value('blatt_id');
    $foto = Qm::t('qm_wb_fotos')->where('eintrag_id', $entryId)->first();
    checkWf((bool) $foto, 'photo row stored for accepted entry');
    if ($foto) $fotoPfade[] = $foto->pfad;
    checkWf($foto && Storage::disk('local')->exists($foto->pfad), 'processed photo bytes stored privately');
    checkWf((bool) Qm::t('qm_wb_blaetter')->where('id', $blattId)->where('obj_id', $obj)->where('dienst_nr', $dn)->exists(), 'entry stayed inside the test worker sheet');

    echo "PASS: $passes worker photo upload roundtrips\n";
} finally {
    wfCleanup($mark, $dn, $eid, $obj, $customer, $tokenId, $fotoPfade);
}
