<?php

namespace App\Http\Controllers\Api\Qm\KundenPortal;

use App\Http\Controllers\Controller;
use App\Services\Qm\PortalService;
use App\Services\TenantService;
use App\Support\Qm\Qm;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Kundenportal — login, account and the live schedule (Einsätze + photos).
 *
 * Every query is pinned to the customer AND the objects of the access
 * (request attributes kunde_id / kp_objekte set by kunden.auth from the
 * token), never to request input. An obj_id filter is always intersected.
 */
class PortalController extends Controller
{
    private const MAX_RANGE_DAYS = 62;

    /** POST /api/kunden-portal/login — public, throttled. */
    public function login(Request $request)
    {
        [$d, $err] = Qm::pruefe($request->all(), [
            'username' => 'required|string|max:50', 'password' => 'required|string|max:200', 'tenant_slug' => 'nullable|string|max:100',
        ]);
        if ($err) return Qm::fehler($err);
        [$ok, $fehler] = PortalService::login($d['username'], $d['password'], $d['tenant_slug'] ?? null);
        if ($fehler) return response()->json(['error' => $fehler[0]], $fehler[1]);
        return response()->json(['success' => true] + $ok);
    }

    /** POST /api/kunden-portal/logout */
    public function logout(Request $request)
    {
        DB::connection('mysql')->table('personal_access_tokens')
            ->where('token', hash('sha256', (string) $request->bearerToken()))
            ->where('tokenable_type', 'kunden_portal')->delete();
        return response()->json(['success' => true]);
    }

    /** GET /api/kunden-portal/me */
    public function me(Request $request)
    {
        $kunde = $request->attributes->get('kunde');
        $z = $request->attributes->get('kp_zugang');
        $tenant = TenantService::current();
        $objekte = Qm::t('objekte')->whereIn('obj_id', $request->attributes->get('kp_objekte'))
            ->orderBy('objektname')->get(['obj_id', 'objektname', 'adresse', 'plz', 'stadt']);
        return response()->json([
            'kunde'   => ['kunde_id' => $kunde->kunde_id, 'kundenname' => $kunde->kundenname],
            'zugang'  => ['name' => $z->name ?? null, 'username' => $z->username, 'email' => $z->email ?? null],
            'passwort_aendern' => (bool) ($z->passwort_aendern ?? false),
            'firma'   => $tenant->name,
            'objektbuch_label' => \App\Support\TenantProfile::objektbuchLabel($tenant),
            'qm'      => Qm::ready() && Qm::boolSetting('portal_qm'),
            'wachbuch' => Qm::ready() && Qm::boolSetting('modul_wachbuch'),
            'objekte' => $objekte,
        ]);
    }

    /** POST /api/kunden-portal/passwort {alt, neu} — also the forced change after a start password. */
    public function passwort(Request $request)
    {
        $z = $request->attributes->get('kp_zugang');
        $neu = (string) $request->input('neu', '');
        if (!Hash::check((string) $request->input('alt', ''), (string) $z->password)) return Qm::fehler(['alt' => ['Das bisherige Passwort ist falsch.']]);
        if (strlen($neu) < 10 || !preg_match('/[A-Za-z]/', $neu) || !preg_match('/\d/', $neu)) {
            return Qm::fehler(['neu' => ['Mindestens 10 Zeichen mit Buchstaben und Ziffern.']]);
        }
        if (Hash::check($neu, (string) $z->password)) return Qm::fehler(['neu' => ['Bitte ein neues Passwort wählen.']]);
        Qm::t('kunden_portal_zugaenge')->where('id', $z->id)->update(['password' => Hash::make($neu), 'passwort_aendern' => 0, 'updated_at' => now()]);
        // Other devices of this login are signed out; the current token stays.
        DB::connection('mysql')->table('personal_access_tokens')->where('tokenable_type', 'kunden_portal')->where('tokenable_id', $z->id)
            ->where('token', '!=', hash('sha256', (string) $request->bearerToken()))->delete();
        return response()->json(['success' => true]);
    }

    /** GET /api/kunden-portal/einsaetze?von=&bis=&obj_id= */
    public function einsaetze(Request $request)
    {
        $kundeId = $request->attributes->get('kunde_id');
        [$von, $bis] = $this->range($request);
        $db = DB::connection('tenant');

        $q = $db->table('einsaetze as e')
            ->join('objekte as o', 'o.obj_id', '=', 'e.obj_id')
            ->leftJoin('artikel as a', 'a.artikel_id', '=', 'e.artikel_id')
            ->where('e.kunden_id', $kundeId)->where('o.kunden_id', $kundeId)
            ->whereIn('e.obj_id', $this->objekte($request))
            ->whereBetween('e.datum', [$von, $bis])
            ->where(fn ($w) => $w->whereNull('e.hms_bestaetigt')->orWhere('e.hms_bestaetigt', '!=', 'nein'));
        if (Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')) {
            $q->whereNull('e.bestell_storno_at');
        }
        $rows = $q->orderBy('e.datum')->orderBy('e.ist_start')
            ->limit(2000)
            ->get(['e.einsatz_id', 'e.datum', 'e.ist_start', 'e.ist_ende', 'e.obj_id', 'o.objektname', 'a.artikel_name']);

        $ids = $rows->pluck('einsatz_id')->all();
        $workers = [];
        if ($ids) {
            $live = $db->table('zeiterfassung')->whereIn('einsatz_id', $ids)
                ->whereIn('status', ['aktiv', 'pause'])->get(['einsatz_id', 'dienst_nr'])
                ->mapWithKeys(fn ($z) => [$z->einsatz_id . '|' . $z->dienst_nr => true]);

            foreach ($db->table('einsatz_mitarbeiter as em')
                ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'em.dienst_nr')
                ->whereIn('em.einsatz_id', $ids)
                ->orderBy('em.sortierung')->orderBy('m.vorname')
                ->get(['em.einsatz_id', 'em.dienst_nr', 'em.ist_start', 'em.ist_ende', 'm.vorname', 'm.name']) as $w) {
                $workers[$w->einsatz_id][] = [
                    'name'     => trim($w->vorname . ' ' . $w->name),
                    'ist_von'  => $this->hm($w->ist_start),
                    'ist_bis'  => $this->hm($w->ist_ende),
                    'im_dienst'=> isset($live[$w->einsatz_id . '|' . $w->dienst_nr]),
                ];
            }

            if (Schema::connection('tenant')->hasTable('nu_position_zuweisungen')) {
                foreach ($db->table('nu_position_zuweisungen as npz')
                    ->join('nu_schicht_positionen as nsp', 'nsp.id', '=', 'npz.position_id')
                    ->join('nu_portal_mitarbeiter as npm', 'npm.id', '=', 'npz.nu_mitarbeiter_id')
                    ->whereIn('nsp.einsatz_id', $ids)
                    ->orderBy('npm.vorname')
                    ->get(['nsp.einsatz_id', 'npz.ist_start', 'npz.ist_ende', 'npm.vorname', 'npm.name']) as $w) {
                    $workers[$w->einsatz_id][] = [
                        'name'     => trim($w->vorname . ' ' . $w->name),
                        'ist_von'  => $this->hm($w->ist_start),
                        'ist_bis'  => $this->hm($w->ist_ende),
                        'im_dienst'=> false,
                    ];
                }
            }
        }

        // Only staffed shifts are shown — open planning gaps stay internal.
        $out = $rows->filter(fn ($r) => !empty($workers[$r->einsatz_id]))->map(fn ($r) => [
            'einsatz_id'  => $r->einsatz_id,
            'datum'       => substr((string) $r->datum, 0, 10),
            'plan_von'    => $this->hm($r->ist_start),
            'plan_bis'    => $this->hm($r->ist_ende),
            'obj_id'      => $r->obj_id,
            'objektname'  => $r->objektname,
            'leistung'    => $r->artikel_name,
            'mitarbeiter' => $workers[$r->einsatz_id],
        ])->values();

        return response()->json(['von' => $von, 'bis' => $bis, 'einsaetze' => $out]);
    }

    /** GET /api/kunden-portal/fotos?von=&bis=&obj_id= */
    public function fotos(Request $request)
    {
        if (!Schema::connection('tenant')->hasColumn('mitarbeiter_nachrichten', 'einsatz_id')) {
            return response()->json(['fotos' => []]);
        }
        [$von, $bis] = $this->range($request);
        $q = $this->fotoQuery($request)->whereBetween('e.datum', [$von, $bis]);

        $rows = $q->orderByDesc('mn.created_at')->limit(500)
            ->get(['mn.id', 'mn.created_at', 'e.datum', 'e.obj_id', 'o.objektname', 'm.vorname', 'm.name']);

        return response()->json(['von' => $von, 'bis' => $bis, 'fotos' => $rows->map(fn ($r) => [
            'id'          => (int) $r->id,
            'datum'       => substr((string) $r->datum, 0, 10),
            'obj_id'      => $r->obj_id,
            'aufgenommen' => Carbon::parse($r->created_at)->setTimezone('Europe/Berlin')->format('d.m.Y H:i'),
            'objektname'  => $r->objektname,
            'mitarbeiter' => trim($r->vorname . ' ' . $r->name),
        ])->values()]);
    }

    /** GET /api/kunden-portal/fotos/{id} — image bytes, ownership re-checked. */
    public function foto(Request $request, int $id)
    {
        if (!Schema::connection('tenant')->hasColumn('mitarbeiter_nachrichten', 'einsatz_id')) abort(404);
        $path = $this->fotoQuery($request)->where('mn.id', $id)->value('mn.datei_pfad');
        if (!$path || !Storage::disk('public')->exists($path)) abort(404);

        return response()->file(Storage::disk('public')->path($path), [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function fotoQuery(Request $request)
    {
        $kundeId = $request->attributes->get('kunde_id');
        return DB::connection('tenant')->table('mitarbeiter_nachrichten as mn')
            ->join('einsaetze as e', 'e.einsatz_id', '=', 'mn.einsatz_id')
            ->join('objekte as o', 'o.obj_id', '=', 'e.obj_id')
            ->leftJoin('mitarbeiter as m', 'm.dienst_nr', '=', 'mn.dienst_nr')
            ->where('e.kunden_id', $kundeId)->where('o.kunden_id', $kundeId)
            ->whereIn('e.obj_id', $this->objekte($request))
            ->where('mn.richtung', 'worker')
            ->where('mn.datei_pfad', 'like', 'chat-fotos/%');
    }

    /** Objects of the access, narrowed by an optional obj_id filter. */
    public static function objekteFuer(Request $request): array
    {
        $erlaubt = $request->attributes->get('kp_objekte') ?: [];
        if ($request->filled('obj_id')) return array_values(array_intersect($erlaubt, [(string) $request->query('obj_id', $request->input('obj_id'))]));
        return $erlaubt ?: ['__none__'];
    }

    private function objekte(Request $request): array
    {
        return self::objekteFuer($request) ?: ['__none__'];
    }

    /** Validated [von, bis] (Y-m-d); default = current week Mon–Sun, capped. */
    private function range(Request $request): array
    {
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $von = (string) $request->query('von', '');
        $bis = (string) $request->query('bis', '');
        if (!preg_match($re, $von) || !preg_match($re, $bis)) {
            $now = Carbon::now('Europe/Berlin');
            return [$now->copy()->startOfWeek()->toDateString(), $now->copy()->endOfWeek()->toDateString()];
        }
        if (strcmp($von, $bis) > 0) [$von, $bis] = [$bis, $von];
        if (Carbon::parse($von)->diffInDays(Carbon::parse($bis)) > self::MAX_RANGE_DAYS) {
            $bis = Carbon::parse($von)->addDays(self::MAX_RANGE_DAYS)->toDateString();
        }
        return [$von, $bis];
    }

    private function hm($t): ?string
    {
        $t = trim((string) ($t ?? ''));
        return $t === '' ? null : substr($t, 0, 5);
    }
}
