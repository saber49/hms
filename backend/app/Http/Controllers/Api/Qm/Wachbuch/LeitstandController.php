<?php

namespace App\Http\Controllers\Api\Qm\Wachbuch;

use App\Http\Controllers\Api\Qm\Concerns\QmGuards;
use App\Http\Controllers\Controller;
use App\Services\Qm\WachbuchService;
use App\Support\Qm\Qm;
use App\Support\Qm\QmScope;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Wachbuch › Leitstand (spec §6.11): live view over every Objekt with an
 * active Wachbuch — who is on duty, missing check-ins, open incidents.
 * One aggregate query per fact, never per object.
 */
class LeitstandController extends Controller
{
    use QmGuards;

    private const CHECKIN_TOLERANZ_MIN = 10;

    /** GET /api/qm/wachbuch/leitstand */
    public function index(Request $request)
    {
        if ($e = $this->nichtBereit()) return $e;
        $u = $request->user();
        $heute = Qm::heute();
        $objekte = QmScope::objekte($u)->filter(fn ($o) => (bool) $o->wachbuch_aktiv)->keyBy('obj_id');
        $ids = $objekte->keys()->all();
        if (!$ids) return response()->json(['objekte' => [], 'vorkommnisse' => []]);

        $imDienst = Qm::t('zeiterfassung as z')->join('einsaetze as e', 'e.einsatz_id', '=', 'z.einsatz_id')
            ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'z.dienst_nr')
            ->whereIn('e.obj_id', $ids)->whereIn('z.status', ['aktiv', 'pause'])
            ->get(['e.obj_id', 'z.dienst_nr', 'z.status', 'z.clock_in', 'm.vorname', 'm.name'])->groupBy('obj_id');

        $geplant = Qm::t('einsatz_mitarbeiter as em')->join('einsaetze as e', 'e.einsatz_id', '=', 'em.einsatz_id')
            ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'em.dienst_nr')
            ->leftJoin('zeiterfassung as z', fn ($j) => $j->on('z.einsatz_id', '=', 'em.einsatz_id')->on('z.dienst_nr', '=', 'em.dienst_nr'))
            ->whereIn('e.obj_id', $ids)->where('e.datum', $heute)->where('e.pruefung', 'geplant')
            ->get(['e.obj_id', 'em.dienst_nr', 'e.ist_start', 'e.ist_ende', 'z.id as z_id', 'm.vorname', 'm.name'])->groupBy('obj_id');

        $vk = Qm::t('qm_wb_eintraege')->whereIn('obj_id', $ids)->whereIn('art', ['vorkommnis', 'alarm'])->where('status', 'offen')
            ->orderByDesc('zeit')->limit(200)->get();
        $heuteVon = Carbon::parse($heute . ' ' . Qm::setting('tag_beginn', '06:00'), 'Europe/Berlin')->utc();
        $eintraege = Qm::t('qm_wb_eintraege')->whereIn('obj_id', $ids)->where('zeit', '>=', $heuteVon)
            ->selectRaw('obj_id, COUNT(*) AS n, MAX(zeit) AS letzte')->groupBy('obj_id')->get()->keyBy('obj_id');

        $now = Carbon::now('Europe/Berlin');
        $rows = $objekte->map(function ($o) use ($imDienst, $geplant, $vk, $eintraege, $now, $heute) {
            $plan = $geplant[$o->obj_id] ?? collect();
            $fehlt = $plan->filter(fn ($p) => !$p->z_id && $p->ist_start
                && $now->gt(Carbon::parse($heute . ' ' . substr($p->ist_start, 0, 5), 'Europe/Berlin')->addMinutes(self::CHECKIN_TOLERANZ_MIN)));
            $offen = $vk->where('obj_id', $o->obj_id);
            return [
                'obj_id' => $o->obj_id, 'objektname' => $o->objektname, 'kundenname' => $o->kundenname,
                'im_dienst' => collect($imDienst[$o->obj_id] ?? [])->map(fn ($z) => [
                    'dienst_nr' => $z->dienst_nr, 'name' => trim($z->vorname . ' ' . $z->name), 'status' => $z->status,
                    'seit' => Carbon::parse($z->clock_in)->toIso8601String(),
                ])->values(),
                'geplant_heute' => $plan->count(),
                'fehlende_checkins' => $fehlt->map(fn ($p) => ['dienst_nr' => $p->dienst_nr, 'name' => trim($p->vorname . ' ' . $p->name), 'plan' => substr($p->ist_start, 0, 5)])->values(),
                'vorkommnisse_offen' => $offen->count(),
                'alarm_offen' => $offen->where('art', 'alarm')->count(),
                'eintraege_heute' => (int) ($eintraege[$o->obj_id]->n ?? 0),
                'letzter_eintrag' => isset($eintraege[$o->obj_id]) ? Carbon::parse($eintraege[$o->obj_id]->letzte)->toIso8601String() : null,
            ];
        })->sortByDesc(fn ($r) => [$r['alarm_offen'], $r['vorkommnisse_offen'], count($r['fehlende_checkins'])])->values();

        $blattDatum = Qm::t('qm_wb_blaetter')->whereIn('id', $vk->pluck('blatt_id')->unique())->pluck('datum', 'id');
        return response()->json([
            'objekte' => $rows,
            'vorkommnisse' => $vk->take(50)->map(fn ($r) => WachbuchService::format($r) + [
                'objektname' => $objekte[$r->obj_id]->objektname ?? $r->obj_id,
                'datum' => substr((string) ($blattDatum[$r->blatt_id] ?? ''), 0, 10),
            ])->values(),
        ]);
    }
}
