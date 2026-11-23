<?php

/**
 * Authenticated staging HTTP smoke: office tenant scope and customer PDF
 * ownership. Test tokens and the test-only customer access are removed in
 * finally; no Wachbuch or customer business data is modified.
 */

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Services\TenantService;
use App\Support\Qm\Qm;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (!app()->environment('staging')) throw new RuntimeException('Staging-only test.');

$base = 'https://staging.clood-x.com/api';
$passes = 0;
function verifyTbHttp(string $label, bool $ok, string $detail = ''): void
{
    global $passes;
    if (!$ok) throw new RuntimeException("$label: $detail");
    echo "ok $label\n";
    $passes++;
}
function clientTbHttp(?string $token = null)
{
    $client = Http::withOptions([
        'verify' => false, 'curl' => [CURLOPT_RESOLVE => ['staging.clood-x.com:443:127.0.0.1']],
    ])->acceptJson()->timeout(30);
    return $token ? $client->withToken($token) : $client;
}

$owners = [];
$accessId = null;
$portalId = null;
$ddr = Tenant::where('slug', 'ddr')->firstOrFail();
$system = Tenant::where('slug', 'clood-x-system')->firstOrFail();
try {
    foreach ([$ddr, $system] as $tenant) {
        $owner = CentralUser::where('tenant_id', $tenant->id)->where('is_owner', 1)->firstOrFail();
        $name = 'tagesbericht-http-' . bin2hex(random_bytes(6));
        $token = $owner->createToken($name);
        $owners[] = [$owner, $token->accessToken->id, $token->plainTextToken];
    }
    $ddrClient = clientTbHttp($owners[0][2]);
    $systemClient = clientTbHttp($owners[1][2]);
    $day = '2026-11-09'; // Existing staging-only QM-E2E sheet; no fixture writes.
    $path = "$base/qm/wachbuch/objekte/O903/tag/$day";
    $r = $ddrClient->get("$path/bericht");
    verifyTbHttp('DDR owner sees own Objekt report draft',
        $r->ok() && ($r['entwurf']['obj_id'] ?? null) === 'O903', $r->status() . ' ' . substr($r->body(), 0, 250));
    $r = $systemClient->get("$path/bericht");
    verifyTbHttp('another tenant cannot see DDR report', $r->status() === 404,
        $r->status() . ' ' . substr($r->body(), 0, 200));
    $r = $systemClient->get("$base/qm/objekte/O903/schluessel");
    verifyTbHttp('another tenant cannot see DDR key register', $r->status() === 404, (string) $r->status());
    $r = $ddrClient->get("$base/qm/objekte/O903/schluessel");
    verifyTbHttp('DDR owner sees own key register', $r->ok() && isset($r['schluessel']), $r->body());
    $r = $ddrClient->get("$path/pdf");
    verifyTbHttp('existing office PDF still downloads',
        $r->ok() && str_starts_with($r->body(), '%PDF'), (string) $r->status());
    verifyTbHttp('portal without token rejected', clientTbHttp()->get("$base/kunden-portal/wachbuch/bericht?obj_id=O903&datum=$day")->status() === 401);

    TenantService::switchToTenant($ddr);
    $customerId = (string) Qm::t('objekte')->where('obj_id', 'O903')->value('kunden_id');
    $username = 'tbhttp' . bin2hex(random_bytes(5));
    $accessId = Qm::t('kunden_portal_zugaenge')->insertGetId([
        'kunde_id' => $customerId, 'username' => $username, 'name' => 'Tagesbericht HTTP Test',
        'objekte' => json_encode(['O903']), 'aktiv' => true, 'passwort_aendern' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $plain = bin2hex(random_bytes(32));
    $portalId = DB::connection('mysql')->table('personal_access_tokens')->insertGetId([
        'tokenable_type' => 'kunden_portal', 'tokenable_id' => $accessId, 'name' => $username,
        'token' => hash('sha256', $plain),
        'abilities' => json_encode(['kunden-portal', 'tenant_id' => $ddr->id]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    TenantService::reset();
    $portal = clientTbHttp($plain);
    $r = $portal->get("$base/kunden-portal/wachbuch/pdf?obj_id=O903&datum=$day");
    verifyTbHttp('customer PDF of owned Objekt downloads',
        $r->ok() && str_starts_with($r->body(), '%PDF'), (string) $r->status() . ' ' . substr($r->body(), 0, 60));
    $r = $portal->get("$base/kunden-portal/wachbuch/pdf?obj_id=O905&datum=$day");
    verifyTbHttp('customer cannot download other Objekt PDF', $r->status() === 404,
        $r->status() . ' ' . substr($r->body(), 0, 150));
    $r = $portal->get("$base/kunden-portal/wachbuch/bericht?obj_id=O905&datum=$day");
    verifyTbHttp('customer cannot see other Objekt report', $r->status() === 404,
        $r->status() . ' ' . substr($r->body(), 0, 150));
    $r = $portal->get("$base/kunden-portal/wachbuch/bericht?obj_id=O903&datum=$day");
    verifyTbHttp('unsigned report is not exposed to customers', $r->status() === 404,
        $r->status() . ' ' . substr($r->body(), 0, 150));
    echo "PASS: $passes authenticated HTTP checks\n";
} finally {
    if ($portalId) DB::connection('mysql')->table('personal_access_tokens')->where('id', $portalId)->where('name', $username)->delete();
    if ($accessId) {
        TenantService::switchToTenant($ddr);
        Qm::t('kunden_portal_zugaenge')->where('id', $accessId)->where('username', $username)->delete();
    }
    TenantService::reset();
    foreach ($owners as [$owner, $id]) $owner->tokens()->where('id', $id)->delete();
}
