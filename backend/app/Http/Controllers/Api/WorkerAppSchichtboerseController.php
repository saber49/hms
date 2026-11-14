<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationDispatcher;
use App\Helpers\Realtime;
use App\Http\Controllers\Controller;
use App\Services\SchichtboerseService as SB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Schichtbörse in the worker app. A worker sees only offers he is suitable
 * for, can request them (office decides) and can hand over his own shifts.
 */
class WorkerAppSchichtboerseController extends Controller
{
    private function t(string $table)
    {
        return DB::connection('tenant')->table($table);
    }

    private function me(Request $request): array
    {
        $w = $request->attributes->get('worker');
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        return [$dn, trim(($w->vorname ?? '') . ' ' . ($w->name ?? '')) ?: $dn];
    }

    private function shiftJson(object $e): array
    {
        return [
            'einsatz_id' => $e->einsatz_id, 'datum' => $e->datum, 'ist_start' => $e->ist_start, 'ist_ende' => $e->ist_ende,
            'pause_minuten' => $e->pause_minuten, 'objektname' => $e->objektname, 'kundenname' => $e->kundenname,
            'ort' => $e->ort, 'beschreibung' => $e->beschreibung, 'bereich' => $e->bereich, 'label' => SB::label($e),
        ];
    }

    /** GET /worker-app/schichtboerse */
    public function index(Request $request)
    {
        if (!SB::ready()) return response()->json(['angebote' => [], 'meine_abgaben' => [], 'count' => 0]);
        [$dn] = $this->me($request);
        $angebote = []; $abgaben = [];
        $mine = $this->t('schicht_anfragen')->where('dienst_nr', $dn)->where('status', 'angefragt')->pluck('id', 'angebot_id')->all();
        foreach ($this->t('schicht_angebote')->where('status', 'aktiv')->orderBy('id')->get() as $a) {
            $e = SB::einsatz($a->einsatz_id);
            if (!$e || !SB::isOpen($e)) continue;
            if ($a->typ === 'abgabe' && $a->anbieter_dienst_nr === $dn) {
                $abgaben[] = ['angebot_id' => $a->id, 'einsatz_id' => $a->einsatz_id, 'notiz' => $a->notiz,
                    'anfragen' => $this->t('schicht_anfragen')->where('angebot_id', $a->id)->where('status', 'angefragt')->count()] + ['schicht' => $this->shiftJson($e)];
                continue;
            }
            if ($a->typ === 'offen' && SB::freePlaces($e) < 1) continue;
            // Already requested → keep it visible even if a conflict appeared meanwhile.
            if (!isset($mine[$a->id]) && !SB::isEligible($e, $dn, $a->typ === 'abgabe' ? $a->anbieter_dienst_nr : null)) continue;
            $angebote[] = [
                'angebot_id' => $a->id, 'typ' => $a->typ, 'notiz' => $a->notiz,
                'angefragt' => isset($mine[$a->id]), 'schicht' => $this->shiftJson($e),
            ];
        }
        usort($angebote, fn ($x, $y) => strcmp($x['schicht']['datum'] . $x['schicht']['ist_start'], $y['schicht']['datum'] . $y['schicht']['ist_start']));
        return response()->json(['angebote' => $angebote, 'meine_abgaben' => $abgaben,
            'count' => count(array_filter($angebote, fn ($x) => !$x['angefragt']))]);
    }

    /** POST /worker-app/schichtboerse/angebote/{id}/anfragen */
    public function request(Request $request, $id)
    {
        if (!SB::ready()) return response()->json(['error' => 'Schichtbörse nicht verfügbar'], 409);
        [$dn, $name] = $this->me($request);
        $a = $this->t('schicht_angebote')->where('id', $id)->where('status', 'aktiv')->first();
        $e = $a ? SB::einsatz($a->einsatz_id) : null;
        if (!$a || !$e || !SB::isOpen($e) || $a->anbieter_dienst_nr === $dn) return response()->json(['error' => 'Diese Schicht ist nicht mehr verfügbar'], 404);
        if ($this->t('schicht_anfragen')->where('angebot_id', $id)->where('dienst_nr', $dn)->where('status', 'angefragt')->exists()) {
            return response()->json(['ok' => true]);
        }
        if (!SB::isEligible($e, $dn, $a->typ === 'abgabe' ? $a->anbieter_dienst_nr : null)) {
            return response()->json(['error' => 'Diese Schicht passt leider nicht (Anforderungen, andere Schicht oder Abwesenheit)'], 422);
        }
        $this->t('schicht_anfragen')->insert(['angebot_id' => $id, 'dienst_nr' => $dn, 'status' => 'angefragt', 'created_at' => now(), 'updated_at' => now()]);
        $anbieter = $a->anbieter_dienst_nr ? $this->t('mitarbeiter')->where('dienst_nr', $a->anbieter_dienst_nr)->first(['vorname', 'name']) : null;
        SchichtboerseController::notifyOffice($e, $name, $a->typ, $anbieter ? trim($anbieter->vorname . ' ' . $anbieter->name) : null);
        Realtime::changed('dispo', ['boerse' => true]);
        return response()->json(['ok' => true]);
    }

    /** DELETE /worker-app/schichtboerse/angebote/{id}/anfragen — withdraw own request. */
    public function cancelRequest(Request $request, $id)
    {
        if (!SB::ready()) return response()->json(['error' => 'Schichtbörse nicht verfügbar'], 409);
        [$dn] = $this->me($request);
        $this->t('schicht_anfragen')->where('angebot_id', $id)->where('dienst_nr', $dn)->where('status', 'angefragt')
            ->update(['status' => 'zurueckgezogen', 'updated_at' => now()]);
        Realtime::changed('dispo', ['boerse' => true]);
        return response()->json(['ok' => true]);
    }

    /** POST /worker-app/schichtboerse/abgeben { einsatz_id, notiz? } — offer own shift. */
    public function handOver(Request $request)
    {
        if (!SB::ready()) return response()->json(['error' => 'Schichtbörse nicht verfügbar'], 409);
        $d = $request->validate(['einsatz_id' => 'required|string|max:30', 'notiz' => 'nullable|string|max:500']);
        [$dn, $name] = $this->me($request);
        $e = SB::einsatz($d['einsatz_id']);
        if (!$e || !in_array($dn, $e->assigned, true)) return response()->json(['error' => 'Schicht nicht gefunden'], 404);
        if (!SB::isOpen($e)) return response()->json(['error' => 'Diese Schicht kann nicht mehr abgegeben werden'], 422);
        if ($this->t('schicht_angebote')->where('einsatz_id', $e->einsatz_id)->where('typ', 'abgabe')
            ->where('anbieter_dienst_nr', $dn)->where('status', 'aktiv')->exists()) {
            return response()->json(['ok' => true]);
        }
        $aid = $this->t('schicht_angebote')->insertGetId([
            'einsatz_id' => $e->einsatz_id, 'typ' => 'abgabe', 'anbieter_dienst_nr' => $dn, 'status' => 'aktiv',
            'notiz' => $d['notiz'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        SB::notifyWorkers(SB::eligibleWorkers($e, null, $dn), 'shift.boerse.neu', 'Schicht abzugeben: ' . SB::label($e),
            'Ein Kollege gibt eine Schicht ab, die zu Ihnen passt. Jetzt in der App anfragen.', ['angebot_id' => $aid]);
        try {
            $tenant = \App\Services\TenantService::current();
            if ($tenant) NotificationDispatcher::notifyDispoManagers((int) $tenant->id, 'dispo.boerse', 'Schichtbörse: Schicht wird abgegeben',
                $name . ' möchte die Schicht ' . SB::label($e) . ' abgeben' . (!empty($d['notiz']) ? ' (' . $d['notiz'] . ')' : '') . '. Sie bleibt bei ihm, bis Sie eine Übernahme genehmigen.',
                '#tasks', ['einsatz_id' => $e->einsatz_id]);
        } catch (\Throwable $ex) { /* best effort */ }
        Realtime::changed('dispo', ['boerse' => true]);
        return response()->json(['ok' => true, 'angebot_id' => $aid]);
    }

    /** POST /worker-app/schichtboerse/angebote/{id}/zurueckziehen — keep the shift after all. */
    public function withdrawHandOver(Request $request, $id)
    {
        if (!SB::ready()) return response()->json(['error' => 'Schichtbörse nicht verfügbar'], 409);
        [$dn] = $this->me($request);
        $a = $this->t('schicht_angebote')->where('id', $id)->where('typ', 'abgabe')->where('anbieter_dienst_nr', $dn)->where('status', 'aktiv')->first();
        if (!$a) return response()->json(['error' => 'Abgabe nicht gefunden'], 404);
        $this->t('schicht_angebote')->where('id', $id)->update(['status' => 'zurueckgezogen', 'updated_at' => now()]);
        $open = $this->t('schicht_anfragen')->where('angebot_id', $id)->where('status', 'angefragt')->get();
        if ($open->isNotEmpty()) {
            $this->t('schicht_anfragen')->whereIn('id', $open->pluck('id'))->update(['status' => 'abgelehnt', 'grund' => 'Abgabe wurde zurückgezogen', 'entschieden_am' => now(), 'updated_at' => now()]);
            $e = SB::einsatz($a->einsatz_id);
            SB::notifyWorkers($this->t('mitarbeiter')->whereIn('dienst_nr', $open->pluck('dienst_nr'))->get(['id', 'dienst_nr']),
                'shift.boerse.abgelehnt', 'Schichtanfrage beendet', ($e ? SB::label($e) . ': ' : '') . 'Der Kollege behält die Schicht.');
        }
        Realtime::changed('dispo', ['boerse' => true]);
        return response()->json(['ok' => true]);
    }
}
