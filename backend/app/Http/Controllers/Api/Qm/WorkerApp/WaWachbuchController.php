<?php

namespace App\Http\Controllers\Api\Qm\WorkerApp;

use App\Http\Controllers\Api\Qm\Wachbuch\WachbuchblattController;
use App\Http\Controllers\Controller;
use App\Services\Qm\FotoService;
use App\Services\Qm\WachbuchService;
use App\Support\Qm\Qm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $visits = Qm::t('qm_wb_eintraege')->where('obj_id', $schicht->obj_id)->where('art', 'besucher')
            ->where('zeit', '>=', now()->subDays(2))->orderBy('id')->get(['id', 'details', 'zeit']);
        $closed = $visits->filter(fn ($v) => (json_decode((string) $v->details, true)['vorgang'] ?? '') === 'abmeldung')
            ->map(fn ($v) => (int) (json_decode((string) $v->details, true)['anmeldung_id'] ?? 0))->all();
        $open = $visits->filter(fn ($v) => (json_decode((string) $v->details, true)['vorgang'] ?? '') !== 'abmeldung'
            && !in_array((int) $v->id, $closed, true))
            ->map(fn ($v) => [
                'id' => (int) $v->id, 'name' => json_decode((string) $v->details, true)['name'] ?? '',
                'ziel' => json_decode((string) $v->details, true)['ziel'] ?? '', 'zeit' => $v->zeit,
            ])->values();
        $handoverRows = Qm::t('qm_wb_eintraege')->where('obj_id', $schicht->obj_id)->where('art', 'uebergabe')
            ->where('zeit', '>=', now()->subHours(36))->orderByDesc('id')->limit(100)->get(['id', 'dienst_nr', 'erfasst_von', 'details', 'zeit']);
        $accepted = $handoverRows->filter(fn ($v) => (json_decode((string) $v->details, true)['vorgang'] ?? '') === 'uebernommen')
            ->map(fn ($v) => (int) (json_decode((string) $v->details, true)['referenz_id'] ?? 0))->all();
        $handover = $handoverRows->filter(fn ($v) => $v->dienst_nr !== $dn
            && (json_decode((string) $v->details, true)['vorgang'] ?? '') === 'uebergeben'
            && !in_array((int) $v->id, $accepted, true))
            ->map(fn ($v) => ['id' => (int) $v->id, 'von' => $v->erfasst_von, 'zeit' => $v->zeit])->values();
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
            'fotopflicht' => (bool) $cfg->fotopflicht, 'gps_grenze_m' => (int) ($cfg->gps_grenze_m ?? 150),
            'notfallkontakte' => trim((string) ($cfg->notfallkontakte ?? '')) ?: null,
            'bericht_pflicht' => (bool) $cfg->bericht_pflicht,
            'besucher_ausweis_pflicht' => (bool) $cfg->besucher_ausweis_pflicht,
            'lieferung_freigabe_pflicht' => (bool) $cfg->lieferung_freigabe_pflicht,
            'uebergabe_checkliste' => (bool) $cfg->uebergabe_checkliste,
            'besuchszeit_ende' => $cfg->besuchszeit_ende,
            'offene_besucher' => $open, 'offene_uebergaben' => $handover,
            'schluesselregister' => Qm::t('qm_wb_schluessel')->where('obj_id', $schicht->obj_id)
                ->where('aktiv', 1)->orderBy('kennung')->get(['kennung', 'bezeichnung', 'bestand_geprueft_am']),
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

        $input = $request->all();
        if (is_string($input['details'] ?? null)) {
            $details = json_decode($input['details'], true);
            $input['details'] = is_array($details) ? $details : null;
        }
        [$d, $err] = WachbuchService::pruefeEintrag($input);
        if ($err) return Qm::fehler($err);
        $blatt = $this->zielBlatt($dn, $request->input('einsatz_id'), $request->attributes->get('qm_terminal_obj'));
        if (!$blatt) return response()->json(['error' => 'Kein laufender Dienst — das Wachbuch kann nur während der eigenen Schicht geführt werden.', 'locked' => true], 409);
        if ($blatt->gegengezeichnet_am) return response()->json(['error' => 'Das Blatt ist gegengezeichnet und eingefroren.', 'locked' => true], 409);
        if (!WachbuchService::berichtigungOk($blatt, $d['ref_id'] ?? null)) return response()->json(['error' => 'Eintrag nicht gefunden'], 404);
        if (!empty($d['ref_id']) && (int) Qm::t('qm_wb_eintraege')->where('id', $d['ref_id'])->value('blatt_id') !== (int) $blatt->id) {
            return response()->json(['error' => 'Berichtigen geht nur für Einträge der eigenen Schicht.'], 403);
        }

        $cfg = Qm::objektEinstellung((string) $blatt->obj_id);
        [$d, $vorgangError] = WachbuchService::pruefeObjektEintrag($blatt, $d);
        if ($vorgangError) return Qm::fehler($vorgangError);
        $fotoPflicht = $d['art'] === 'foto' || ($d['art'] === 'vorkommnis' && (bool) $cfg->fotopflicht);
        if ($fotoPflicht && !$request->hasFile('foto')) {
            return Qm::fehler(['foto' => ['Foto ist Pflicht.']]);
        }
        $fotoDaten = [];
        $foto = null;
        if ($request->hasFile('foto')) {
            [$fotoDaten, $err] = Qm::pruefe($request->all() + ['foto' => $request->file('foto')], [
                'foto' => FotoService::REGEL, 'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180',
                'genauigkeit_m' => 'nullable|numeric|min:0|max:100000', 'erfasst_am' => 'nullable|date',
            ]);
            if ($err) return Qm::fehler($err);
            $foto = $request->file('foto');
        }

        $quelle = $request->attributes->get('qm_terminal_obj') ? 'terminal' : 'app';
        try {
            [$row, $fotoRow] = DB::connection('tenant')->transaction(function () use ($blatt, $d, $quelle, $dn, $foto, $fotoDaten) {
                $row = WachbuchService::append($blatt, $d, [
                    'quelle' => $quelle,
                    'dienst_nr' => $dn, 'erfasst_von' => Qm::workerName($dn),
                ]);
                $fotoRow = null;
                // client_uuid retries must not duplicate the evidence photo.
                if ($foto && !Qm::t('qm_wb_fotos')->where('eintrag_id', $row->id)->exists()) {
                    $fotoRow = FotoService::speichern($row, $foto, $fotoDaten + ['quelle' => $quelle], Qm::workerName($dn));
                }
                return [$row, $fotoRow];
            });
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage(), 'locked' => true], 409);
        }
        return response()->json(['success' => true, 'eintrag' => WachbuchService::format($row, collect($fotoRow ? [$fotoRow] : []))]);
    }

    /** POST /api/worker-app/qm/wachbuch/eintraege/{id}/fotos (multipart foto, lat, lng, genauigkeit_m) */
    public function foto(Request $request, int $id)
    {
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        $e = Qm::t('qm_wb_eintraege')->find($id);
        $blatt = $e ? Qm::t('qm_wb_blaetter')->find($e->blatt_id) : null;
        if (!$e || !$blatt || $blatt->dienst_nr !== $dn) return response()->json(['error' => 'Eintrag nicht gefunden'], 404);
        if (!WachbuchService::aktiv((string) $blatt->obj_id)) return response()->json(['error' => 'Für dieses Objekt ist das Wachbuch nicht aktiv.', 'locked' => true], 409);
        if ($blatt->gegengezeichnet_am) return response()->json(['error' => 'Das Blatt ist gegengezeichnet und eingefroren.', 'locked' => true], 409);
        if (Qm::t('qm_wb_fotos')->where('eintrag_id', $id)->count() >= 6) return response()->json(['error' => 'Maximal 6 Fotos je Eintrag.'], 422);
        [$d, $err] = Qm::pruefe($request->all() + ['foto' => $request->file('foto')], [
            'foto' => FotoService::REGEL, 'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180',
            'genauigkeit_m' => 'nullable|numeric|min:0|max:100000', 'erfasst_am' => 'nullable|date',
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
        if (!$b || ($nurObjekt && $b->obj_id !== $nurObjekt) || !WachbuchService::aktiv((string) $b->obj_id)) return null;
        return $b->geschlossen_am && now()->subHours(self::NACHREICHEN_STD)->lte($b->geschlossen_am) ? $b : null;
    }
}
