<?php

namespace App\Services\Qm;

use App\Http\Middleware\ResolveKundenPortalToken;
use App\Models\Tenant;
use App\Services\TenantService;
use App\Support\Qm\Qm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Kundenportal (part of the QM module): logins per Ansprechpartner
 * (kunden_portal_zugaenge), each limited to selected objects of the customer.
 * Start passwords are issued by the office and must be changed on first
 * login; 5 wrong passwords lock the access until the office unlocks it.
 */
class PortalService
{
    public const MAX_FEHL = 5;

    /** @return array{0: ?array, 1: ?array} [[token, slug, passwort_aendern], [error, status]] */
    public static function login(string $username, string $password, ?string $slug): array
    {
        $tenants = $slug
            ? Tenant::where('slug', $slug)->where('aktiv', true)->get()
            : Tenant::where('aktiv', true)->get();
        if ($slug && $tenants->isEmpty()) return [null, ['Firma nicht gefunden', 404]];

        // 1) every access with this username (usernames are unique per company only)
        $kandidaten = [];
        foreach ($tenants as $tenant) {
            try {
                TenantService::switchToTenant($tenant);
                if (!Schema::connection('tenant')->hasTable('kunden_portal_zugaenge')) continue;
                $z = Qm::t('kunden_portal_zugaenge')->where('username', $username)->where('aktiv', 1)->first();
                if (!$z || !$z->password) continue;
                $kandidaten[] = [$tenant, $z, Qm::t('kunden')->where('kunde_id', $z->kunde_id)->where('aktiv', 1)->exists()];
            } catch (\Throwable $e) {
                report($e);
            }
        }
        // A password must never choose between companies sharing a username.
        if (!$slug && count($kandidaten) > 1) {
            TenantService::reset(); Qm::forget();
            return [null, ['Dieser Benutzername existiert in mehreren Firmen. Bitte verwenden Sie den direkten Link Ihres Dienstleisters.', 409]];
        }
        // 2) check the password; a failure is counted only when the username
        //    belongs to ONE company (direct link or unique name) — a login
        //    without the company link must never lock other companies' customers.
        $matches = [];
        $gesperrt = false;
        $falsch = [];
        foreach ($kandidaten as [$tenant, $z, $kundeAktiv]) {
            if (!empty($z->gesperrt_seit)) { $gesperrt = true; continue; }
            if (!Hash::check($password, $z->password)) { $falsch[] = [$tenant, $z]; continue; }
            if ($kundeAktiv) $matches[] = [$tenant, $z];
        }
        if (!$matches && count($kandidaten) === 1 && $falsch) {
            TenantService::switchToTenant($falsch[0][0]);
            self::fehlversuch($falsch[0][1]);
            TenantService::reset(); Qm::forget();
        }

        if (count($matches) > 1) {
            TenantService::reset(); Qm::forget();
            return [null, ['Dieser Benutzername existiert in mehreren Firmen. Bitte verwenden Sie den direkten Link Ihres Dienstleisters.', 409]];
        }
        if (count($matches) !== 1) {
            TenantService::reset(); Qm::forget();
            return [null, $gesperrt
                ? ['Der Zugang ist nach zu vielen Fehlversuchen gesperrt. Bitte wenden Sie sich an Ihren Dienstleister.', 423]
                : ['Ungültige Anmeldedaten oder Portal nicht aktiviert', 401]];
        }

        [$tenant, $z] = $matches[0];
        TenantService::switchToTenant($tenant);
        $plain = bin2hex(random_bytes(32));
        DB::connection('mysql')->table('personal_access_tokens')
            ->where('tokenable_type', 'kunden_portal')->where('tokenable_id', $z->id)
            ->where('abilities', 'like', '%"tenant_id":' . $tenant->id . '}')
            ->where('created_at', '<', now()->subDays(ResolveKundenPortalToken::TOKEN_TTL_DAYS))->delete();
        DB::connection('mysql')->table('personal_access_tokens')->insert([
            'tokenable_type' => 'kunden_portal', 'tokenable_id' => $z->id, 'name' => 'kunden-portal',
            'token' => hash('sha256', $plain),
            'abilities' => json_encode(['kunden-portal', 'tenant_id' => $tenant->id]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Qm::t('kunden_portal_zugaenge')->where('id', $z->id)->update(['last_login_at' => now(), 'fehlversuche' => 0]);
        TenantService::reset(); Qm::forget();
        return [['token' => $plain, 'tenant_slug' => $tenant->slug, 'passwort_aendern' => (bool) ($z->passwort_aendern ?? false)], null];
    }

    private static function fehlversuch(object $z): void
    {
        $n = (int) ($z->fehlversuche ?? 0) + 1;
        $upd = ['fehlversuche' => $n];
        if ($n >= self::MAX_FEHL) {
            $upd['gesperrt_seit'] = now();
            self::beendeSitzungen((int) $z->id);
            QmNotify::users(QmNotify::rollenUser(['qmb', 'buero'], 'kundenzugaenge'), 'portal.locked',
                'Kundenzugang gesperrt: ' . $z->username, 'Nach ' . $n . ' Fehlversuchen gesperrt. Entsperren unter Einstellungen › Qualität › Kundenzugänge.',
                '#einstellungen?tab=qualitaet&sub=kunden', 'kunden_portal_zugang', $z->id);
        }
        Qm::t('kunden_portal_zugaenge')->where('id', $z->id)->update($upd);
    }

    public static function beendeSitzungen(int $zugangId): void
    {
        DB::connection('mysql')->table('personal_access_tokens')
            ->where('tokenable_type', 'kunden_portal')->where('tokenable_id', $zugangId)
            ->where('abilities', 'like', '%"tenant_id":' . (TenantService::current()->id ?? 0) . '}')->delete();
    }

    public static function startPasswort(): string
    {
        // readable: no 0/O/1/l
        return Str::of(Str::random(40))->replaceMatches('/[^A-HJ-NP-Za-km-z2-9]/', '')->substr(0, 10)->value() . random_int(10, 99);
    }

    /** obj_ids the access may see (null in the row = all objects of the customer). */
    public static function objekte(object $z): array
    {
        $alle = Qm::t('objekte')->where('kunden_id', $z->kunde_id)->where('aktiv', 1)->pluck('obj_id')->map(fn ($x) => (string) $x)->all();
        $auswahl = json_decode($z->objekte ?? 'null', true);
        return is_array($auswahl) ? array_values(array_intersect($alle, array_map('strval', $auswahl))) : $alle;
    }

    public static function zugangSiehtObjekt(object $z, string $objId): bool
    {
        return in_array($objId, self::objekte($z), true);
    }
}
