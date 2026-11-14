<?php

namespace App\Http\Controllers\Api\Qm\WorkerApp;

use App\Http\Controllers\Api\Qm\Wachbuch\WachbuchblattController;
use App\Http\Controllers\Controller;
use App\Services\Qm\FotoService;
use App\Services\Qm\WachbuchService;
use App\Support\Qm\Qm;
use Illuminate\Http\Request;

/**
 * Worker app › Wachbuch — the guard writes ONLY into the sheet of his own
 * running shift. Offline entries may still arrive up to 12 h after the
 * check-out (flagged nachgereicht, device time kept).
 *
 * Also used by the Objekt-Terminal (QmTerminalAuth sets worker_dienst_nr and
 * qm_terminal_obj, so the same rules apply there).
 */
class WaWachbuchController extends Controller
{
    private const NACHREICHEN_STD = 12;

    /** GET /api/worker-app/qm/wachbuch */
    public function show(Request $request)
    {
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        if (!Qm::ready()) return response()->json(['aktiv' => false]);
        $schicht = $this->laufendeSchicht($dn, $request->attributes->get('qm_terminal_obj'));
        $label = \App\Support\TenantProfile::objektbuchLabel(\App\Services\TenantService::current());
        if (!$schicht) {
            return response()->json(['aktiv' => false, 'label' => $label, 'arten' => WachbuchService::ARTEN, 'freigabe_durch' => WachbuchService::FREIGABE_DURCH]);
        }
        $blatt = WachbuchService::blattFuerSchicht($schicht->einsatz_id, $dn);
        $cfg = Qm::objektEinstellung($schicht->obj_id);
        // Open customer notes for this Objekt (today) — the guard ticks them off.
        $hinweise = Qm::t('qm_wb_eintraege')->where('obj_id', $schicht->obj_id)->where('art', 'hinweis')
            ->where('quelle', 'kunde')->where('zeit', '>=', now()->subHours(24))->orderBy('zeit')->get()
            ->map(fn ($h) => WachbuchService::format($h) + ['umgesetzt_am' => $h->bearbeitet_am])->values();
        return response()->json([
            'aktiv' => true, 'label' => $label,
            'schicht' => [
                'einsatz_id' => $schicht->einsatz_id, 'obj_id' => $schicht->obj_id, 'objektname' => $schicht->objektname,
                'plan_von' => substr((string) $schicht->ist_start, 0, 5), 'plan_bis' => substr((string) $schicht->ist_ende, 0, 5),
                'status' => $schicht->status,
            ],
            'blatt_id' => (int) $blatt->id,
            'fotopflicht' => (bool) $cfg->fotopflicht,
            'eintraege' => WachbuchService::eintraege([(int) $blatt->id]),
            'hinweise' => $hinweise,
            'arten' => WachbuchService::ARTEN, 'freigabe_durch' => WachbuchService::FREIGABE_DURCH,
        ]);
    }

    /** POST /api/worker-app/qm/wachbuch/eintraege {art, text, details, client_uuid, erfasst_am, einsatz_id?} */
    public function store(Request $request)
    {
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        if (!Qm::ready()) return response()->json(['error' => 'Wachbuch ist nicht aktiv.'], 409);
        [$d, $err] = WachbuchService::pruefeEintrag($request->all());
        if ($err) return Qm::fehler($err);
        $blatt = $this->zielBlatt($dn, $request->input('einsatz_id'), $request->attributes->get('qm_terminal_obj'));
        if (!$blatt) return response()->json(['error' => 'Kein laufender Dienst — das Wachbuch kann nur während der eigenen Schicht geführt werden.', 'locked' => true], 409);
        if (!WachbuchService::berichtigungOk($blatt, $d['ref_id'] ?? null)) return response()->json(['error' => 'Eintrag nicht gefunden'], 404);
        if (!empty($d['ref_id']) && (int) Qm::t('qm_wb_eintraege')->where('id', $d['ref_id'])->value('blatt_id') !== (int) $blatt->id) {
            return response()->json(['error' => 'Berichtigen geht nur für Einträge der eigenen Schicht.'], 403);
        }
        $row = WachbuchService::append($blatt, $d, [
            'quelle' => $request->attributes->get('qm_terminal_obj') ? 'terminal' : 'app',
            'dienst_nr' => $dn, 'erfasst_von' => Qm::workerName($dn),
        ]);
        return response()->json(['success' => true, 'eintrag' => WachbuchService::format($row)]);
    }

    /** POST /api/worker-app/qm/wachbuch/eintraege/{id}/fotos (multipart foto, lat, lng, genauigkeit_m) */
    public function foto(Request $request, int $id)
    {
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        $e = Qm::t('qm_wb_eintraege')->find($id);
        $blatt = $e ? Qm::t('qm_wb_blaetter')->find($e->blatt_id) : null;
        if (!$e || !$blatt || $blatt->dienst_nr !== $dn) return response()->json(['error' => 'Eintrag nicht gefunden'], 404);
        if (Qm::t('qm_wb_fotos')->where('eintrag_id', $id)->count() >= 6) return response()->json(['error' => 'Maximal 6 Fotos je Eintrag.'], 422);
        [$d, $err] = Qm::pruefe($request->all() + ['foto' => $request->file('foto')], [
            'foto' => FotoService::REGEL, 'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180',
            'genauigkeit_m' => 'nullable|numeric|min:0|max:100000',
        ]);
        if ($err) return Qm::fehler($err);
        $f = FotoService::speichern($e, $request->file('foto'), $d + ['quelle' => $request->attributes->get('qm_terminal_obj') ? 'terminal' : 'app'], Qm::workerName($dn));
        return response()->json(['success' => true, 'foto' => ['id' => (int) $f->id, 'aufgenommen_am' => $f->aufgenommen_am]]);
    }

    /** GET /api/worker-app/qm/wachbuch/fotos/{id} — only photos of the worker's own sheets. */
    public function fotoAnzeigen(Request $request, int $id)
    {
        $f = Qm::t('qm_wb_fotos as f')->join('qm_wb_eintraege as w', 'w.id', '=', 'f.eintrag_id')
            ->join('qm_wb_blaetter as b', 'b.id', '=', 'w.blatt_id')->where('f.id', $id)->first(['f.pfad', 'b.dienst_nr']);
        if (!$f || $f->dienst_nr !== (string) $request->attributes->get('worker_dienst_nr')) abort(404);
        return WachbuchblattController::fotoResponse($f->pfad);
    }

    /** POST /api/worker-app/qm/wachbuch/hinweise/{id}/umgesetzt */
    public function umgesetzt(Request $request, int $id)
    {
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        $h = Qm::t('qm_wb_eintraege')->where('id', $id)->where('art', 'hinweis')->first();
        $schicht = $this->laufendeSchicht($dn, $request->attributes->get('qm_terminal_obj'));
        if (!$h || !$schicht || $h->obj_id !== $schicht->obj_id) return response()->json(['error' => 'Hinweis nicht gefunden'], 404);
        if (!$h->bearbeitet_am) {
            Qm::t('qm_wb_eintraege')->where('id', $id)->update(['status' => 'erledigt', 'bearbeitet_am' => now()]);
            $blatt = WachbuchService::blattFuerSchicht($schicht->einsatz_id, $dn);
            WachbuchService::append($blatt, ['art' => 'notiz', 'text' => 'Kundenhinweis umgesetzt: ' . mb_substr((string) $h->text, 0, 300), 'ref_id' => null, 'details' => []],
                ['quelle' => 'app', 'dienst_nr' => $dn, 'erfasst_von' => Qm::workerName($dn)]);
        }
        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------

    /** Running (or paused) shift of the worker at an Objekt with an active Wachbuch. */
    private function laufendeSchicht(string $dn, ?string $nurObjekt): ?object
    {
        $q = Qm::t('zeiterfassung as z')->join('einsaetze as e', 'e.einsatz_id', '=', 'z.einsatz_id')
            ->join('objekte as o', 'o.obj_id', '=', 'e.obj_id')
            ->where('z.dienst_nr', $dn)->whereIn('z.status', ['aktiv', 'pause'])->orderByDesc('z.clock_in');
        if ($nurObjekt) $q->where('e.obj_id', $nurObjekt);
        $s = $q->first(['z.einsatz_id', 'z.status', 'e.obj_id', 'e.ist_start', 'e.ist_ende', 'o.objektname']);
        return $s && WachbuchService::aktiv((string) $s->obj_id) ? $s : null;
    }

    /** Sheet an entry goes to: the running shift, or (offline catch-up) a recently closed own sheet. */
    private function zielBlatt(string $dn, $einsatzId, ?string $nurObjekt): ?object
    {
        if ($s = $this->laufendeSchicht($dn, $nurObjekt)) {
            if (!$einsatzId || (string) $einsatzId === (string) $s->einsatz_id) return WachbuchService::blattFuerSchicht($s->einsatz_id, $dn);
        }
        if (!$einsatzId) return null;
        $b = Qm::t('qm_wb_blaetter')->where('einsatz_id', (string) $einsatzId)->where('dienst_nr', $dn)->first();
        if (!$b || ($nurObjekt && $b->obj_id !== $nurObjekt)) return null;
        return $b->geschlossen_am && now()->subHours(self::NACHREICHEN_STD)->lte($b->geschlossen_am) ? $b : null;
    }
}
