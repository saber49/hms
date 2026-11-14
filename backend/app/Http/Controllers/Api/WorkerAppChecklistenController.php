<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Checklisten;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Checklisten — worker side. Templates come from the Einsatz's Objekt and
 * Bereich (never from the client); a worker may only fill checklists for
 * shifts he is assigned to, from 30 min before the start until 6 h after the
 * (possibly extended) end. Every submit creates one protocol; `client_uuid`
 * makes retries idempotent.
 */
class WorkerAppChecklistenController extends Controller
{
    private const VORLAUF_MIN = 30;
    private const NACHLAUF_STD = 6;

    private function einsatz(string $dienstNr, string $einsatzId)
    {
        return DB::connection('tenant')->table('einsatz_mitarbeiter as em')
            ->join('einsaetze as e', 'e.einsatz_id', '=', 'em.einsatz_id')
            ->leftJoin('objekte as o', 'o.obj_id', '=', 'e.obj_id')
            ->leftJoin('kunden as k', 'k.kunde_id', '=', 'e.kunden_id')
            ->where('em.dienst_nr', $dienstNr)->where('em.einsatz_id', $einsatzId)
            ->select('e.einsatz_id', 'e.obj_id', 'e.artikel_id', 'e.kunden_id', 'e.datum', 'o.objektname', 'k.kundenname')
            ->first();
    }

    private function fenster(string $dienstNr, string $einsatzId): ?array
    {
        $w = WorkerAppWachbuchController::shiftWindow($dienstNr, $einsatzId);
        if (!$w) return null;
        $von = $w[0]->copy()->subMinutes(self::VORLAUF_MIN);
        $bis = $w[1]->copy()->addHours(self::NACHLAUF_STD);
        $now = now();
        return [
            'editable' => $now->gte($von) && $now->lte($bis),
            'status'   => $now->lt($von) ? 'vorher' : ($now->gt($bis) ? 'nachher' : 'offen'),
            'von'      => $von->copy()->setTimezone('Europe/Berlin')->format('Y-m-d H:i'),
            'bis'      => $bis->copy()->setTimezone('Europe/Berlin')->format('Y-m-d H:i'),
        ];
    }

    /** GET /api/worker-app/checklisten?einsatz_id= — templates of this shift + own protocols. */
    public function index(Request $request)
    {
        if (!Checklisten::ready()) return response()->json(['vorlagen' => [], 'protokolle' => []]);
        $dienstNr = $request->attributes->get('worker_dienst_nr');
        $einsatzId = (string) $request->query('einsatz_id', '');
        $e = $this->einsatz($dienstNr, $einsatzId);
        if (!$e || !$e->obj_id) return response()->json(['error' => 'Einsatz nicht gefunden'], 404);

        $vorlagen = Checklisten::vorlagenFuer($e->obj_id, Checklisten::einsatzBereiche($e->obj_id, $e->artikel_id, $e->kunden_id));
        $protokolle = DB::connection('tenant')->table('checklisten_protokolle')
            ->where('einsatz_id', $e->einsatz_id)->where('created_by_dienst_nr', $dienstNr)
            ->orderByDesc('id')->get(['id', 'vorlage_id', 'vorlage_name', 'ergebnis_prozent', 'maengel', 'abgeschlossen_at']);

        return response()->json([
            'einsatz'    => ['einsatz_id' => $e->einsatz_id, 'datum' => $e->datum, 'objektname' => $e->objektname, 'kundenname' => $e->kundenname],
            'fenster'    => $this->fenster($dienstNr, $e->einsatz_id),
            'vorlagen'   => array_map(fn ($v) => Checklisten::formatVorlage($v), $vorlagen),
            'protokolle' => $protokolle->map(fn ($p) => [
                'id' => (int) $p->id, 'vorlage_id' => $p->vorlage_id ? (int) $p->vorlage_id : null, 'vorlage_name' => $p->vorlage_name,
                'ergebnis_prozent' => $p->ergebnis_prozent !== null ? (float) $p->ergebnis_prozent : null,
                'maengel' => (int) $p->maengel, 'abgeschlossen_at' => $p->abgeschlossen_at,
            ])->values(),
        ]);
    }

    /**
     * POST /api/worker-app/checklisten (multipart)
     *   einsatz_id, vorlage_id, client_uuid, antworten (JSON), gestartet_at,
     *   gps_lat, gps_lng, gps_genauigkeit, unterschrift_kunde (data URL),
     *   unterschrift_kunde_name, unterschrift_ma (data URL), fotos[feld_id][]
     */
    public function store(Request $request)
    {
        if (!Checklisten::ready()) return response()->json(['error' => 'Checklisten noch nicht verfügbar'], 409);
        $dienstNr = $request->attributes->get('worker_dienst_nr');
        $db = DB::connection('tenant');

        $uuid = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('client_uuid', '')), 0, 64) ?: null;
        if ($uuid && ($dup = $db->table('checklisten_protokolle')->where('client_uuid', $uuid)->first())) {
            return response()->json(['success' => true, 'id' => (int) $dup->id, 'duplikat' => true]);
        }

        $e = $this->einsatz($dienstNr, (string) $request->input('einsatz_id', ''));
        if (!$e || !$e->obj_id) return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        $fenster = $this->fenster($dienstNr, $e->einsatz_id);
        if (!$fenster || !$fenster['editable']) {
            return response()->json([
                'error'  => $fenster && $fenster['status'] === 'vorher'
                    ? 'Die Checkliste kann erst kurz vor Schichtbeginn ausgefüllt werden (ab ' . substr($fenster['von'], 11) . ' Uhr).'
                    : 'Die Schicht ist zu lange vorbei — bitte das Büro informieren.',
                'locked' => true,
            ], 403);
        }

        $vorlageId = (int) $request->input('vorlage_id');
        $vorlage = collect(Checklisten::vorlagenFuer($e->obj_id, Checklisten::einsatzBereiche($e->obj_id, $e->artikel_id, $e->kunden_id)))
            ->firstWhere('id', $vorlageId);
        if (!$vorlage) return response()->json(['error' => 'Checkliste ist für diesen Einsatz nicht vorgesehen'], 404);
        $felder = json_decode($vorlage->felder, true) ?: [];

        // Photos: only image files, only for fields of this template.
        $feldIds = array_column($felder, 'id');
        $fotos = [];
        foreach ((array) $request->file('fotos', []) as $fid => $files) {
            if (!in_array((string) $fid, $feldIds, true)) continue;
            foreach (array_slice(is_array($files) ? $files : [$files], 0, Checklisten::MAX_FOTOS_JE_FELD) as $file) {
                $info = $file && $file->isValid() ? @getimagesize($file->getRealPath()) : false;
                if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) $fotos[(string) $fid][] = $file;
            }
        }
        $antworten = json_decode((string) $request->input('antworten', '{}'), true);
        $res = Checklisten::cleanAntworten($felder, $antworten, array_map('count', $fotos));
        if (is_string($res)) return response()->json(['error' => $res], 422);
        [$clean, $prozent, $maengel] = $res;

        $sigK = (string) $request->input('unterschrift_kunde', '');
        $sigKName = mb_substr(trim(strip_tags((string) $request->input('unterschrift_kunde_name', ''))), 0, 120);
        $sigM = (string) $request->input('unterschrift_ma', '');
        if (in_array($vorlage->unterschrift, ['kunde', 'beide'], true) && ($sigK === '' || $sigKName === '')) {
            return response()->json(['error' => 'Unterschrift und Name des Kunden fehlen'], 422);
        }
        if (in_array($vorlage->unterschrift, ['mitarbeiter', 'beide'], true) && $sigM === '') {
            return response()->json(['error' => 'Deine Unterschrift fehlt'], 422);
        }

        $lat = is_numeric($request->input('gps_lat')) ? (float) $request->input('gps_lat') : null;
        $lng = is_numeric($request->input('gps_lng')) ? (float) $request->input('gps_lng') : null;
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) { $lat = null; $lng = null; }
        $start = null;
        try { $start = \Carbon\Carbon::parse((string) $request->input('gestartet_at')); } catch (\Throwable $ex) {}
        if (!$start || $start->gt(now()) || $start->lt(now()->subHours(24))) $start = null;

        $id = $db->table('checklisten_protokolle')->insertGetId([
            'vorlage_id' => $vorlage->id, 'vorlage_name' => $vorlage->name, 'vorlage_version' => (int) $vorlage->version,
            'kategorie' => $vorlage->kategorie, 'felder' => json_encode($felder, JSON_UNESCAPED_UNICODE),
            'antworten' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'obj_id' => $e->obj_id, 'artikel_id' => $e->artikel_id, 'einsatz_id' => $e->einsatz_id,
            'datum' => substr((string) $e->datum, 0, 10),
            'ergebnis_prozent' => $prozent, 'maengel' => $maengel,
            'gps_lat' => $vorlage->gps ? $lat : null, 'gps_lng' => $vorlage->gps ? $lng : null,
            'gps_genauigkeit' => $vorlage->gps && $lat !== null && is_numeric($request->input('gps_genauigkeit')) ? min(100000, (int) $request->input('gps_genauigkeit')) : null,
            'unterschrift_kunde_name' => $sigKName !== '' ? $sigKName : null,
            'client_uuid' => $uuid, 'created_by_dienst_nr' => $dienstNr,
            'gestartet_at' => $start, 'abgeschlossen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $upd = [];
        if ($sigK !== '' && ($p = Checklisten::storeSignatur($sigK, $id, 'kunde'))) $upd['unterschrift_kunde_pfad'] = $p;
        if ($sigM !== '' && ($p = Checklisten::storeSignatur($sigM, $id, 'ma'))) $upd['unterschrift_ma_pfad'] = $p;
        if ($upd) $db->table('checklisten_protokolle')->where('id', $id)->update($upd);
        foreach ($fotos as $fid => $files) {
            foreach ($files as $file) {
                if ($path = Checklisten::storeFoto($file, $id)) {
                    $db->table('checklisten_fotos')->insert(['protokoll_id' => $id, 'feld_id' => $fid, 'pfad' => $path, 'created_at' => now()]);
                }
            }
        }
        \App\Helpers\Realtime::changed('checklisten');

        return response()->json(['success' => true, 'id' => $id, 'ergebnis_prozent' => $prozent, 'maengel' => $maengel], 201);
    }
}
