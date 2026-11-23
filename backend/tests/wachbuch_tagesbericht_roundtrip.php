<?php

/**
 * Staging integration roundtrip, with a transaction per tenant:
 *   cd /var/www/hms-backend-staging && php tests/wachbuch_tagesbericht_roundtrip.php
 * No fixture survives; never run against production.
 */

use App\Models\Tenant;
use App\Services\Qm\WachbuchCoverage;
use App\Services\Qm\WachbuchService;
use App\Services\Qm\WachbuchTagesbericht;
use App\Services\Qm\WachbuchTagesberichtPdf;
use App\Services\TenantService;
use App\Support\Qm\Qm;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment('production')) throw new RuntimeException('Staging-only test.');

$passes = 0;
function checkTb(bool $ok, string $message): void
{
    global $passes;
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $passes++;
    echo "ok $message\n";
}

$tag = CarbonImmutable::now('Europe/Berlin')->subDays(5)->toDateString();
$mark = 'TBE2E' . bin2hex(random_bytes(3));
$tenantNames = ['clood-x-system', 'ddr'];
$firstTenantUuid = null;
foreach ($tenantNames as $tenantName) {
    $tenant = Tenant::where('slug', $tenantName)->firstOrFail();
    TenantService::switchToTenant($tenant);
    $conn = DB::connection('tenant');
    $conn->beginTransaction();
    try {
        [$von, $bis] = WachbuchTagesbericht::grenzen($tag);
        $customerId = $mark . 'K';
        $obj = $mark . 'A';
        $other = $mark . 'B';
        $dns = [1 => 'TB' . substr($mark, -6) . '1', 2 => 'TB' . substr($mark, -6) . '2'];
        $t = fn ($table) => Qm::t($table);
        $t('kunden')->insert(['kunde_id' => $customerId, 'kundenname' => 'QM Tagesbericht Test']);
        foreach ([$obj => 'A', $other => 'B'] as $id => $suffix) {
            $t('objekte')->insert(['obj_id' => $id, 'kunden_id' => $customerId,
                'objektname' => 'QM Tagesbericht Objekt ' . $suffix, 'aktiv' => 1]);
        }
        $t('qm_objekt_einstellungen')->insert([
            'obj_id' => $obj, 'wachbuch_aktiv' => 1, 'kunde_pdf' => 1, 'bericht_pflicht' => 1,
            'bericht_ab' => $tag, 'durchgehend_bewacht' => 1, 'besucher_ausweis_pflicht' => 1,
            'lieferung_freigabe_pflicht' => 1, 'uebergabe_checkliste' => 1, 'besuchszeit_ende' => '20:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $t('qm_wb_schluessel')->insert([
            'obj_id' => $obj, 'kennung' => 'K1', 'bezeichnung' => 'Objektschlüssel',
            'bestand_geprueft_am' => $von->subDay()->utc()->toDateTimeString(), 'bestand_geprueft_von' => 1,
            'aktiv' => 1, 'created_at' => $von->subDay()->utc()->toDateTimeString(),
            'updated_at' => $von->subDay()->utc()->toDateTimeString(),
        ]);
        foreach ([1, 2] as $n) {
            $eid = $mark . 'E' . $n;
            $dn = $dns[$n];
            $t('mitarbeiter')->insert([
                'dienst_nr' => $dn, 'name' => 'Test', 'vorname' => 'QM ' . $n, 'aktiv' => 1,
            ]);
            $beg = $n === 1 ? $von : $von->addHours(12);
            $end = $n === 1 ? $von->addHours(12) : $bis;
            $t('einsaetze')->insert([
                'einsatz_id' => $eid, 'obj_id' => $obj, 'kunden_id' => $customerId,
                'datum' => $tag, 'ist_start' => $n === 1 ? '06:00' : '18:00',
                'ist_ende' => $n === 1 ? '18:00' : '06:00', 'pruefung' => 'geplant',
                'created_at' => $von->utc()->toDateTimeString(),
            ]);
            $t('zeiterfassung')->insert([
                'einsatz_id' => $eid, 'dienst_nr' => $dn,
                'clock_in' => $beg->utc()->toDateTimeString(),
                'clock_out' => $end->utc()->toDateTimeString(),
                'clock_in_real' => $beg->utc()->toDateTimeString(),
                'clock_out_real' => $end->utc()->toDateTimeString(),
            ]);
        }
        $daId = $t('qm_dienstanweisungen')->insertGetId([
            'obj_id' => $obj, 'version' => 1, 'status' => 'freigegeben',
            'gueltig_ab' => $von->subDay()->toDateString(), 'kurz' => '["QM Test"]',
            'pdf_pfad' => 'qm-test-nicht-echt.pdf', 'token' => bin2hex(random_bytes(24)),
            'created_at' => $von->utc()->toDateTimeString(),
            'updated_at' => $von->utc()->toDateTimeString(),
        ]);
        foreach ([1, 2] as $n) {
            $confirmed = ($n === 1 ? $von : $von->addHours(12))->subHour()->utc()->toDateTimeString();
            $t('qm_da_bestaetigungen')->insert(['dienstanweisung_id' => $daId,
                'dienst_nr' => $dns[$n], 'bestaetigt_am' => $confirmed,
                'created_at' => $confirmed, 'updated_at' => $confirmed]);
        }
        Carbon::setTestNow($von->addHour());
        $office = WachbuchService::bueroBlatt($obj, $tag);
        $secondObj = WachbuchService::bueroBlatt($other, $tag);
        $first = WachbuchService::blattFuerSchicht($mark . 'E1', $dns[1]);
        $second = WachbuchService::blattFuerSchicht($mark . 'E2', $dns[2]);
        $ctx = ['quelle' => 'buero', 'user_id' => 1, 'erfasst_von' => 'QM Test'];
        $make = function (object $blatt, array $payload, array $actor) {
            [$d, $err] = WachbuchService::pruefeEintrag($payload);
            checkTb(!$err, 'entry shape accepted');
            [$d, $err] = WachbuchService::pruefeObjektEintrag($blatt, $d);
            checkTb(!$err, 'object rules accepted');
            return WachbuchService::append($blatt, $d, $actor);
        };
        $invalid = ['art' => 'besucher', 'details' => ['name' => 'Pilot Besucher', 'ziel' => 'Empfang', 'angemeldet' => true]];
        [$probe] = WachbuchService::pruefeEintrag($invalid);
        [, $error] = WachbuchService::pruefeObjektEintrag($office, $probe);
        checkTb(isset($error['details.ausweis_geprueft']), 'ID check enforced server-side');

        $visitUuid = $mark . '-' . $tenantName . '-visitor';
        $visit = $make($office, [
            'art' => 'besucher', 'client_uuid' => $visitUuid,
            'details' => ['name' => 'Pilot Besucher', 'ziel' => 'Empfang', 'angemeldet' => true,
                'ausweis_geprueft' => true],
        ], $ctx);
        if ($firstTenantUuid === null) $firstTenantUuid = $visitUuid;
        else checkTb($firstTenantUuid !== $visitUuid
            && !$t('qm_wb_eintraege')->where('client_uuid', $firstTenantUuid)->exists(),
            'other tenant entry UUID is not in this database');
        $exitPayload = ['art' => 'besucher', 'client_uuid' => $mark . '-exit',
            'details' => ['vorgang' => 'abmeldung', 'anmeldung_id' => (int) $visit->id]];
        [$exit] = WachbuchService::pruefeEintrag($exitPayload);
        [, $wrong] = WachbuchService::pruefeObjektEintrag($secondObj, $exit);
        checkTb(isset($wrong['details.anmeldung_id']), 'visitor checkout cannot reference another Objekt');
        $exitRow = $make($office, $exitPayload, $ctx);
        checkTb((int) $make($office, $exitPayload, $ctx)->id === (int) $exitRow->id,
            'offline retry is idempotent within the same sheet');
        [, $duplicate] = WachbuchService::pruefeObjektEintrag($office,
            WachbuchService::pruefeEintrag(array_replace($exitPayload, ['client_uuid' => $mark . '-again']))[0]);
        checkTb(isset($duplicate['details.anmeldung_id']), 'visitor cannot check out twice');
        try {
            WachbuchService::append($secondObj, ['art' => 'notiz', 'text' => 'bad',
                'client_uuid' => $visitUuid], $ctx);
            checkTb(false, 'offline UUID cannot leak another Objekt');
        } catch (DomainException $e) {
            checkTb(true, 'offline UUID cannot leak another Objekt');
        }

        $liefer = ['art' => 'lieferung', 'details' => ['firma' => 'Pilot Fremdfirma']];
        [$raw] = WachbuchService::pruefeEintrag($liefer);
        [, $error] = WachbuchService::pruefeObjektEintrag($office, $raw);
        checkTb(isset($error['details.freigabe']), 'delivery needs named approval');
        $make($office, ['art' => 'lieferung', 'details' => [
            'firma' => 'Pilot Fremdfirma', 'zweck' => 'Medizinlieferung',
            'freigabe_durch' => 'empfang', 'freigabe_name' => 'Empfang Test',
            'freigabe_bestaetigt' => true,
        ]], $ctx);
        Carbon::setTestNow($von->addHours(3));
        $make($office, ['art' => 'schluessel', 'details' => [
            'schluessel' => 'K1', 'aktion' => 'ausgabe', 'an' => 'Pilot Wachperson',
        ]], $ctx);
        [$duplicateKey] = WachbuchService::pruefeEintrag(['art' => 'schluessel',
            'details' => ['schluessel' => 'K1', 'aktion' => 'ausgabe', 'an' => 'Pilot Wachperson']]);
        [, $error] = WachbuchService::pruefeObjektEintrag($office, $duplicateKey);
        checkTb(isset($error['details.aktion']), 'same key cannot be issued twice');
        Carbon::setTestNow($von->addHours(5));
        $make($office, ['art' => 'schluessel', 'details' => [
            'schluessel' => 'K1', 'aktion' => 'ruecknahme', 'an' => 'Pilot Wachperson',
        ]], $ctx);
        Carbon::setTestNow($von->addHours(11)->addMinutes(55));
        $secret = 'NUR INTERN ' . $mark;
        $out = $make($first, ['art' => 'uebergabe', 'text' => $secret, 'details' => [
            'vorgang' => 'uebergeben', 'schluessel_ok' => true, 'funk_ok' => true, 'objekt_ok' => true,
        ]], ['quelle' => 'app', 'dienst_nr' => $dns[1], 'erfasst_von' => 'QM Schicht A']);
        Carbon::setTestNow($von->addHours(12)->addMinutes(5));
        $make($second, ['art' => 'uebergabe', 'details' => [
            'vorgang' => 'uebernommen', 'referenz_id' => (int) $out->id,
            'schluessel_ok' => true, 'funk_ok' => true, 'objekt_ok' => true,
        ]], ['quelle' => 'app', 'dienst_nr' => $dns[2], 'erfasst_von' => 'QM Schicht B']);
        Carbon::setTestNow($von->addHours(15));
        [$spat] = WachbuchService::pruefeEintrag(['art' => 'besucher', 'details' => [
            'name' => 'Später Besuch', 'ziel' => 'Notaufnahme', 'angemeldet' => true,
            'ausweis_geprueft' => true,
        ]]);
        [, $error] = WachbuchService::pruefeObjektEintrag($office, $spat);
        checkTb(isset($error['details.ausnahme_grund']), 'late visit needs documented exception');
        $lateVisitor = $make($office, ['art' => 'besucher', 'details' => [
            'name' => 'Später Besuch', 'ziel' => 'Notaufnahme', 'angemeldet' => true,
            'ausweis_geprueft' => true, 'ausnahme_grund' => 'Notfallversorgung notwendig',
        ]], $ctx);
        $make($office, ['art' => 'besucher', 'details' => [
            'vorgang' => 'abmeldung', 'anmeldung_id' => (int) $lateVisitor->id,
        ]], $ctx);
        $late = $make($second, ['art' => 'notiz', 'text' => 'Offline Testnachtrag',
            'client_uuid' => $mark . '-offline', 'erfasst_am' => $von->addHours(14)->toIso8601String()],
            ['quelle' => 'app', 'dienst_nr' => $dns[2], 'erfasst_von' => 'QM Schicht B']);
        checkTb((bool) $late->nachgereicht, 'offline event marked as delayed');
        Carbon::setTestNow($bis);
        foreach ([$first, $second] as $blatt) {
            $t('qm_wb_blaetter')->where('id', $blatt->id)->update(['geschlossen_am' => $bis->utc()->toDateTimeString()]);
            checkTb(WachbuchService::pruefeKette((int) $blatt->id) === null, 'worker hash chain intact');
        }
        checkTb(WachbuchService::pruefeKette((int) $office->id) === null, 'office hash chain intact');
        Carbon::setTestNow();
        $draft = WachbuchTagesbericht::entwurf($obj, $tag);
        checkTb($draft['deckung']['lueckenlos'], 'two shifts provide continuous coverage');
        checkTb($draft['schluessel_vollstaendig'] && $draft['schluessel_vorhanden'] === 1,
            'registered key returned to audited stock');
        checkTb(count($draft['besucher']) === 2 && $draft['besucher'][0]['gegangen'] && $draft['besucher'][1]['gegangen'],
            'visitor check-in and check-out paired');
        checkTb(collect($draft['pruefung'])->contains(fn ($i) => str_contains($i, 'nach Besuchszeit')),
            'late visit shown as a checked exception');
        checkTb(count($draft['lieferungen']) === 1 && $draft['lieferungen'][0]['freigabe_bestaetigt'],
            'recorded delivery approval aggregated');
        checkTb(count($draft['uebergaben']) === 2 && count($draft['anwesenheit']) === 2,
            'two-party handover and plan/actual rows aggregated');
        checkTb(collect($draft['pruefung'])->contains(fn ($i) => str_contains($i, 'Offline')),
            'offline event surfaced for manual review');
        $public = WachbuchTagesbericht::kundenSicht($draft);
        checkTb(!str_contains(json_encode($public), $secret)
            && !str_contains(json_encode($public), 'ausweis_geprueft')
            && !str_contains(json_encode($public), 'Notfallversorgung notwendig')
            && !array_key_exists('blatt_hashes', $public), 'customer projection excludes internal handover and ID checks');
        $user = (object) ['id' => 1, 'name' => 'QM Roundtrip'];
        try {
            WachbuchTagesbericht::freigeben($obj, $tag, $user);
            checkTb(false, 'exceptions require explanation');
        } catch (DomainException $e) {
            checkTb(true, 'exceptions require explanation');
        }
        $approved = WachbuchTagesbericht::freigeben($obj, $tag, $user, 'Offline-Eintrag geprüft und zugeordnet.');
        $initial = WachbuchTagesbericht::snapshot($approved);
        checkTb($approved->version === 1 && hash('sha256', $approved->daten) === $approved->pruefsumme,
            'approved snapshot is immutable and checksummed');
        $pdf = WachbuchTagesberichtPdf::download($obj, $tag, true, $approved);
        checkTb(str_starts_with($pdf->getContent(), '%PDF'), 'customer PDF renders signed snapshot');
        $raw = clone $approved; $raw->daten .= 'tampered';
        try {
            WachbuchTagesbericht::snapshot($raw);
            checkTb(false, 'tampered snapshot rejected');
        } catch (DomainException $e) {
            checkTb(true, 'tampered snapshot rejected');
        }
        Carbon::setTestNow($von->addHours(8));
        $make($office, ['art' => 'notiz', 'text' => 'Nachtrag nach Freigabe'], $ctx);
        Carbon::setTestNow();
        checkTb(WachbuchTagesbericht::snapshot($approved) === $initial
            && count(WachbuchTagesbericht::entwurf($obj, $tag)['eintraege']) > count($initial['eintraege']),
            'approved snapshot unchanged by later entries');
        $next = WachbuchTagesbericht::freigeben($obj, $tag, $user, 'Nachtrag zur gespeicherten Version geprüft.');
        checkTb($next->version === 2 && $next->pruefsumme !== $approved->pruefsumme,
            'changed facts create version two, without overwriting version one');
        $t('qm_wb_blaetter')->where('id', $office->id)->update(['gegengezeichnet_am' => now()]);
        $supplement = WachbuchService::bueroBlatt($obj, $tag);
        checkTb($supplement->id !== $office->id && WachbuchService::pruefeKette((int) $office->id) === null,
            'signed sheet stays immutable; office amendments use new sheet');
        checkTb(!$t('qm_wb_eintraege')->where('obj_id', $other)->exists(),
            'no data on second Objekt of the same tenant');
        echo "PASS tenant $tenantName\n";
    } finally {
        Carbon::setTestNow();
        $conn->rollBack();
        TenantService::reset();
    }
}
echo "PASS: $passes roundtrip assertions across two tenants\n";
