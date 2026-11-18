<?php

namespace App\Http\Controllers\Api\Qm\KundenPortal;

use App\Http\Controllers\Api\Qm\Concerns\QmGuards;
use App\Http\Controllers\Controller;
use App\Services\Qm\PortalService;
use App\Services\TenantService;
use App\Support\BereichScope;
use App\Support\Qm\Qm;
use App\Support\Qm\QmScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * QM-Einstellungen › Kundenzugänge (spec §3): several logins per customer
 * (one per Ansprechpartner), each with an object selection. The office
 * issues a start password (shown once) that must be changed at first login;
 * locked accesses (5 failures) are unlocked here.
 */
class ZugangController extends Controller
{
    use QmGuards;

    /** GET /api/qm/kundenzugaenge?kunde_id= */
    public function index(Request $request)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->rechtOderFehler($u, 'kundenzugaenge')) return $e;
        [$kSql, $kParams] = BereichScope::kundePredicate($u, 'k');
        $q = Qm::t('kunden_portal_zugaenge as z')->join('kunden as k', 'k.kunde_id', '=', 'z.kunde_id')->whereRaw($kSql, $kParams);
        if (($eigene = $this->eigeneKunden($u)) !== null) $q->whereIn('z.kunde_id', $eigene ?: ['__none__']);
        if ($request->filled('kunde_id')) $q->where('z.kunde_id', (string) $request->query('kunde_id'));
        $rows = $q->orderBy('k.kundenname')->orderBy('z.name')->limit(2000)
            ->get(['z.id', 'z.kunde_id', 'z.name', 'z.email', 'z.username', 'z.objekte', 'z.aktiv', 'z.passwort_aendern',
                   'z.fehlversuche', 'z.gesperrt_seit', 'z.last_login_at', 'k.kundenname']);
        $rows = $rows->filter(fn ($z) => $this->zugangErlaubt($u, $z))->values();
        $objekte = Qm::t('objekte')->whereIn('kunden_id', $rows->pluck('kunde_id')->unique())->where('aktiv', 1)
            ->when(QmScope::eigeneObjekte($u) !== null, fn ($w) => $w->whereIn('obj_id', QmScope::eigeneObjekte($u) ?: ['__none__']))
            ->orderBy('objektname')->get(['obj_id', 'objektname', 'kunden_id'])->groupBy('kunden_id');
        $slug = TenantService::current()->slug ?? '';
        return response()->json([
            'login_url' => url('/' . $slug . '/kunden_login.php'),
            'zugaenge' => $rows->map(fn ($z) => [
                'id' => (int) $z->id, 'kunde_id' => $z->kunde_id, 'kundenname' => $z->kundenname,
                'name' => $z->name, 'email' => $z->email, 'username' => $z->username,
                'objekte' => json_decode($z->objekte ?? 'null', true),
                'aktiv' => (bool) $z->aktiv, 'passwort_aendern' => (bool) $z->passwort_aendern,
                'gesperrt' => (bool) $z->gesperrt_seit, 'fehlversuche' => (int) $z->fehlversuche, 'last_login_at' => $z->last_login_at,
            ])->values(),
            'kunden_objekte' => $objekte,
            'nur_eigene_objekte' => QmScope::eigeneObjekte($u) !== null,
        ]);
    }

    /** GET /api/qm/kundenzugaenge/kunden — customers + their objects for the "new access" dialog. */
    public function kunden(Request $request)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->rechtOderFehler($u, 'kundenzugaenge')) return $e;
        [$kSql, $kParams] = BereichScope::kundePredicate($u, 'k');
        $kunden = Qm::t('kunden as k')->where('k.aktiv', 1)->whereRaw($kSql, $kParams)
            ->when(($eigene = $this->eigeneKunden($u)) !== null, fn ($w) => $w->whereIn('k.kunde_id', $eigene ?: ['__none__']))
            ->orderBy('k.kundenname')->limit(3000)->get(['k.kunde_id', 'k.kundenname']);
        $obj = Qm::t('objekte')->whereIn('kunden_id', $kunden->pluck('kunde_id'))->where('aktiv', 1)
            ->when(QmScope::eigeneObjekte($u) !== null, fn ($w) => $w->whereIn('obj_id', QmScope::eigeneObjekte($u) ?: ['__none__']))
            ->orderBy('objektname')
            ->get(['obj_id', 'objektname', 'kunden_id'])->groupBy('kunden_id');
        return response()->json(['kunden' => $kunden->map(fn ($k) => ['kunde_id' => $k->kunde_id, 'kundenname' => $k->kundenname, 'objekte' => $obj[$k->kunde_id] ?? []])->values()]);
    }

    /** POST /api/qm/kundenzugaenge {kunde_id, name, email, username, objekte|null} → start password (once) */
    public function store(Request $request)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->rechtOderFehler($u, 'kundenzugaenge')) return $e;
        [$d, $err] = $this->pruefe($request->all(), null);
        if ($err) return Qm::fehler($err);
        $pw = PortalService::startPasswort();
        $id = Qm::t('kunden_portal_zugaenge')->insertGetId([
            'kunde_id' => $d['kunde_id'], 'name' => $d['name'], 'email' => $d['email'] ?? null, 'username' => $d['username'],
            'objekte' => $d['objekte'] === null ? null : json_encode(array_values($d['objekte'])),
            'password' => Hash::make($pw), 'passwort_aendern' => 1, 'aktiv' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Qm::log('kunden_portal_zugang', $id, 'angelegt', null, ['kunde_id' => $d['kunde_id'], 'username' => $d['username']], $u);
        return response()->json(['success' => true, 'id' => $id, 'username' => $d['username'], 'passwort' => $pw]);
    }

    /** PUT /api/qm/kundenzugaenge/{id} {name, email, username, objekte, aktiv} */
    public function update(Request $request, int $id)
    {
        $u = $request->user();
        [$z, $e] = $this->laden($u, $id);
        if ($e) return $e;
        [$d, $err] = $this->pruefe($request->all() + ['kunde_id' => $z->kunde_id], $z);
        if ($err) return Qm::fehler($err);
        $patch = ['name' => $d['name'], 'email' => $d['email'] ?? null, 'username' => $d['username'],
                  'objekte' => $d['objekte'] === null ? null : json_encode(array_values($d['objekte'])),
                  'aktiv' => $request->has('aktiv') ? $request->boolean('aktiv') : (bool) $z->aktiv];
        $diff = Qm::diff($z, $patch);
        Qm::t('kunden_portal_zugaenge')->where('id', $id)->update($patch + ['updated_at' => now()]);
        if ($diff) Qm::log('kunden_portal_zugang', $id, 'geaendert', $diff[0], $diff[1], $u);
        if (!$patch['aktiv'] || $patch['username'] !== $z->username) PortalService::beendeSitzungen($id);
        return response()->json(['success' => true]);
    }

    /** POST /api/qm/kundenzugaenge/{id}/{aktion} — passwort (new start password) | entsperren */
    public function aktion(Request $request, int $id, string $aktion)
    {
        $u = $request->user();
        [$z, $e] = $this->laden($u, $id);
        if ($e) return $e;
        if ($aktion === 'passwort') {
            $pw = PortalService::startPasswort();
            Qm::t('kunden_portal_zugaenge')->where('id', $id)->update([
                'password' => Hash::make($pw), 'passwort_aendern' => 1, 'fehlversuche' => 0, 'gesperrt_seit' => null, 'updated_at' => now(),
            ]);
            PortalService::beendeSitzungen($id);
            Qm::log('kunden_portal_zugang', $id, 'start_passwort', null, null, $u);
            return response()->json(['success' => true, 'username' => $z->username, 'passwort' => $pw]);
        }
        if ($aktion === 'entsperren') {
            Qm::t('kunden_portal_zugaenge')->where('id', $id)->update(['fehlversuche' => 0, 'gesperrt_seit' => null, 'updated_at' => now()]);
            Qm::log('kunden_portal_zugang', $id, 'entsperrt', null, null, $u);
            return response()->json(['success' => true]);
        }
        abort(404);
    }

    private function laden($u, int $id): array
    {
        if ($e = $this->nichtBereit() ?? $this->rechtOderFehler($u, 'kundenzugaenge')) return [null, $e];
        $z = Qm::t('kunden_portal_zugaenge')->find($id);
        if (!$z || !$this->zugangErlaubt($u, $z)) return [null, response()->json(['error' => 'Zugang nicht gefunden'], 404)];
        return [$z, null];
    }

    private function zugangErlaubt($u, object $z): bool
    {
        if (!$this->kundeErlaubt($u, (string) $z->kunde_id)) return false;
        $eigene = QmScope::eigeneObjekte($u);
        if ($eigene === null) return true;
        $auswahl = json_decode($z->objekte ?? 'null', true);
        return is_array($auswahl) && (bool) array_intersect($eigene, $auswahl);
    }

    /** kunde_ids an Objektleiter may manage (customers of his objects); null = no limit (QMB / Büro). */
    private function eigeneKunden($u): ?array
    {
        $eigene = QmScope::eigeneObjekte($u);
        if ($eigene === null) return null;
        return $eigene ? Qm::t('objekte')->whereIn('obj_id', $eigene)->pluck('kunden_id')->filter()->map(fn ($x) => (string) $x)->unique()->values()->all() : [];
    }

    private function kundeErlaubt($u, string $kundeId): bool
    {
        if (!BereichScope::allowsKunde($u, $kundeId)) return false;
        $eigene = $this->eigeneKunden($u);
        return $eigene === null || in_array($kundeId, $eigene, true);
    }

    private function pruefe(array $in, ?object $z): array
    {
        $in['username'] = trim((string) ($in['username'] ?? ''));
        [$d, $err] = Qm::pruefe($in, [
            'kunde_id' => 'required|string|max:20', 'name' => 'required|string|max:200',
            'email' => 'nullable|email|max:200', 'username' => ['required', 'regex:/^[A-Za-z0-9._-]{3,50}$/'],
            'objekte' => 'present|nullable|array', 'objekte.*' => 'string|max:20',
        ]);
        if ($err) return [null, $err];
        $u = request()->user();
        if (!Qm::t('kunden')->where('kunde_id', $d['kunde_id'])->where('aktiv', 1)->exists() || !$this->kundeErlaubt($u, (string) $d['kunde_id'])) {
            return [null, ['kunde_id' => ['Kunde nicht gefunden.']]];
        }
        if (Qm::t('kunden_portal_zugaenge')->where('username', $d['username'])->when($z, fn ($q) => $q->where('id', '!=', $z->id))->exists()) {
            return [null, ['username' => ['Benutzername ist bereits vergeben.']]];
        }
        // Objektleiter: only his own objects, and never "all objects of the customer".
        $eigeneObj = QmScope::eigeneObjekte($u);
        if ($eigeneObj !== null) {
            if (!is_array($d['objekte'])) return [null, ['objekte' => ['Bitte die Objekte einzeln wählen — „alle Objekte" kann nur QMB oder Büro vergeben.']]];
            // objects outside his scope that the access already has stay untouched
            $fremd = $z ? array_values(array_diff((array) (json_decode($z->objekte ?? 'null', true) ?: []), $eigeneObj)) : [];
            if ($z && json_decode($z->objekte ?? 'null', true) === null) return [null, ['objekte' => ['Dieser Zugang gilt für alle Objekte des Kunden und kann nur von QMB oder Büro geändert werden.']]];
            $d['objekte'] = array_values(array_unique(array_merge(array_intersect($d['objekte'], $eigeneObj), $fremd)));
        }
        if (is_array($d['objekte'])) {
            $gueltig = Qm::t('objekte')->where('kunden_id', $d['kunde_id'])->whereIn('obj_id', $d['objekte'])->pluck('obj_id')->all();
            if (!$gueltig) return [null, ['objekte' => ['Bitte mindestens ein Objekt wählen oder „alle Objekte".']]];
            $d['objekte'] = $gueltig;
        }
        return [$d, []];
    }
}
