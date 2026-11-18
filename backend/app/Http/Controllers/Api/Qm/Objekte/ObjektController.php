<?php

namespace App\Http\Controllers\Api\Qm\Objekte;

use App\Http\Controllers\Api\Qm\Concerns\QmGuards;
use App\Http\Controllers\Controller;
use App\Services\Qm\DienstanweisungService;
use App\Support\Qm\Qm;
use App\Support\Qm\QmScope;
use Illuminate\Http\Request;

/**
 * "Meine Objekte" / "Objekte & Dienstanweisungen" (spec §6.2): per Objekt the
 * Wachbuch switches, who is on duty, the current Dienstanweisung with
 * confirmation counts, pending drafts, terminal state.
 */
class ObjektController extends Controller
{
    use QmGuards;

    /** GET /api/qm/objekte */
    public function index(Request $request)
    {
        if ($e = $this->nichtBereit()) return $e;
        $u = $request->user();
        $objekte = QmScope::objekte($u);
        $ids = $objekte->pluck('obj_id')->all();
        if (!$ids) return response()->json(['objekte' => []]);

        $das = Qm::t('qm_dienstanweisungen')->whereIn('obj_id', $ids)->whereIn('status', ['freigegeben', 'entwurf'])->get();
        $aktuell = $das->where('status', 'freigegeben')->sortByDesc(fn ($d) => DienstanweisungService::rang($d))->unique('obj_id')->keyBy('obj_id');
        $entwuerfe = $das->where('status', 'entwurf')->countBy('obj_id');
        $bestaetigt = Qm::t('qm_da_bestaetigungen')->whereIn('dienstanweisung_id', $aktuell->pluck('id'))->whereNotNull('bestaetigt_am')
            ->get(['dienstanweisung_id', 'dienst_nr'])->groupBy('dienstanweisung_id');
        $kunde = Qm::t('qm_da_kunde')->whereIn('dienstanweisung_id', $aktuell->pluck('id'))->orderBy('id')->get()->groupBy('dienstanweisung_id');
        $imDienst = Qm::t('zeiterfassung as z')->join('einsaetze as e', 'e.einsatz_id', '=', 'z.einsatz_id')
            ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'z.dienst_nr')
            ->whereIn('e.obj_id', $ids)->whereIn('z.status', ['aktiv', 'pause'])
            ->get(['e.obj_id', 'm.vorname', 'm.name'])->groupBy('obj_id');
        $terminals = Qm::t('qm_terminals')->whereIn('obj_id', $ids)->get()->keyBy('obj_id');
        $users = Qm::userNames();
        $mitarbeiter = DienstanweisungService::mitarbeiterJeObjekt($aktuell->keys()->all());

        return response()->json(['objekte' => $objekte->map(function ($o) use ($aktuell, $entwuerfe, $bestaetigt, $kunde, $imDienst, $terminals, $users, $mitarbeiter) {
            $da = $aktuell[$o->obj_id] ?? null;
            $soll = $da ? ($mitarbeiter[$o->obj_id] ?? []) : [];
            $ist = $da ? collect($bestaetigt[$da->id] ?? [])->pluck('dienst_nr')->intersect($soll)->count() : 0;
            $k = $da ? collect($kunde[$da->id] ?? [])->last() : null;
            $t = $terminals[$o->obj_id] ?? null;
            return [
                'obj_id' => $o->obj_id, 'objektname' => $o->objektname, 'kundenname' => $o->kundenname,
                'adresse' => trim(($o->adresse ?? '') . ', ' . trim(($o->plz ?? '') . ' ' . ($o->stadt ?? '')), ', '),
                'wachbuch_aktiv' => (bool) $o->wachbuch_aktiv, 'waechterkontrolle_aktiv' => (bool) $o->waechterkontrolle_aktiv,
                'objektleiter' => $o->objektleiter_id ? ($users[(int) $o->objektleiter_id] ?? null) : null,
                'objektleiter_id' => $o->objektleiter_id ? (int) $o->objektleiter_id : null,
                'im_dienst' => collect($imDienst[$o->obj_id] ?? [])->map(fn ($m) => trim($m->vorname . ' ' . $m->name))->values(),
                'dienstanweisung' => $da ? DienstanweisungService::format($da) + [
                    'ma_bestaetigt' => $ist, 'ma_soll' => count($soll),
                    'kunde' => $k ? ['aktion' => $k->aktion, 'am' => $k->created_at, 'text' => $k->text] : null,
                ] : null,
                'entwuerfe' => (int) ($entwuerfe[$o->obj_id] ?? 0),
                'terminal' => $t ? ['kennung' => $t->kennung, 'aktiv' => (bool) $t->aktiv && !$t->gesperrt_seit, 'gesperrt' => (bool) $t->gesperrt_seit, 'letzte_anmeldung' => $t->letzte_anmeldung] : null,
            ];
        })->values()]);
    }

    /** GET /api/qm/objekte/{objId} — settings + all DA versions + confirmations of the current one. */
    public function show(Request $request, string $objId)
    {
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($request->user(), $objId)) return $e;
        $versionen = Qm::t('qm_dienstanweisungen')->where('obj_id', $objId)->orderByDesc('id')->get();
        $da = $versionen->where('status', 'freigegeben')->sortByDesc(fn ($d) => DienstanweisungService::rang($d))->first();
        $best = [];
        if ($da) {
            $rows = Qm::t('qm_da_bestaetigungen')->where('dienstanweisung_id', $da->id)->get()->keyBy('dienst_nr');
            $namen = Qm::t('mitarbeiter')->whereIn('dienst_nr', DienstanweisungService::mitarbeiterDesObjekts($objId))
                ->orderBy('vorname')->get(['dienst_nr', 'vorname', 'name']);
            $best = $namen->map(fn ($m) => [
                'dienst_nr' => $m->dienst_nr, 'name' => trim($m->vorname . ' ' . $m->name),
                'pdf_geoeffnet_am' => $rows[$m->dienst_nr]->pdf_geoeffnet_am ?? null,
                'bestaetigt_am' => $rows[$m->dienst_nr]->bestaetigt_am ?? null,
                'quelle' => $rows[$m->dienst_nr]->quelle ?? null,
            ])->values();
        }
        return response()->json([
            'einstellung' => Qm::objektEinstellung($objId),
            'versionen' => $versionen->map(fn ($d) => DienstanweisungService::format($d))->values(),
            'bestaetigungen' => $best,
            'kunde' => $da ? Qm::t('qm_da_kunde as k')->leftJoin('kunden_portal_zugaenge as z', 'z.id', '=', 'k.zugang_id')
                ->where('k.dienstanweisung_id', $da->id)->orderByDesc('k.id')->get(['k.aktion', 'k.text', 'k.created_at', 'z.name', 'z.username']) : [],
        ]);
    }

    /** PUT /api/qm/objekte/{objId}/einstellung */
    public function einstellung(Request $request, string $objId)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($u, $objId) ?? $this->rechtOderFehler($u, 'objekt_einstellungen')) return $e;
        [$d, $err] = Qm::pruefe($request->all(), [
            'wachbuch_aktiv' => 'sometimes|boolean', 'waechterkontrolle_aktiv' => 'sometimes|boolean', 'fotopflicht' => 'sometimes|boolean',
            'kunde_pdf' => 'sometimes|boolean',
            'objektleiter_id' => 'sometimes|nullable|integer', 'bericht_empfaenger' => 'sometimes|nullable|string|max:500',
            'gps_grenze_m' => 'sometimes|integer|min:20|max:2000', 'notfallkontakte' => 'sometimes|nullable|string|max:2000',
            'soll_begehung_tage' => 'sometimes|nullable|integer|min:1|max:365', 'soll_besuch_tage' => 'sometimes|nullable|integer|min:1|max:365',
        ]);
        if ($err) return Qm::fehler($err);
        if (!empty($d['objektleiter_id']) && !isset(Qm::userNames()[(int) $d['objektleiter_id']])) {
            return Qm::fehler(['objektleiter_id' => ['Bitte einen aktiven Benutzer wählen.']]);
        }
        if (!empty($d['bericht_empfaenger'])) {
            $bad = collect(explode(',', $d['bericht_empfaenger']))->map('trim')->filter()->reject(fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL));
            if ($bad->isNotEmpty()) return Qm::fehler(['bericht_empfaenger' => ['Ungültige E-Mail: ' . $bad->implode(', ')]]);
        }
        $alt = Qm::objektEinstellung($objId);
        $diff = Qm::diff($alt, $d);
        if ($diff) {
            Qm::t('qm_objekt_einstellungen')->updateOrInsert(['obj_id' => $objId], $d + ['updated_by' => $u->id, 'updated_at' => now()]
                + ($alt->id ? [] : ['created_at' => now()]));
            Qm::log('objekt_einstellung', $objId, 'geaendert', $diff[0], $diff[1], $u);
            Qm::changed('objekte');
        }
        return response()->json(['success' => true, 'einstellung' => Qm::objektEinstellung($objId)]);
    }
}
