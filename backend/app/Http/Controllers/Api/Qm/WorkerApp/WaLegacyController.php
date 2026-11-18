<?php

namespace App\Http\Controllers\Api\Qm\WorkerApp;

use App\Http\Controllers\Controller;
use App\Services\Qm\WachbuchService;
use App\Support\Qm\Qm;
use App\Support\ShiftWindow;
use Illuminate\Http\Request;

/**
 * Compatibility for INSTALLED apps that still have the old Wachbuch modal
 * (GET|POST /api/worker-app/wachbuch). Their free-text entry becomes a
 * "Notiz" in the new Wachbuch of that shift, so nothing written on an old
 * app is lost until every device runs the new "Dienst" tab.
 * Remove once all native/desktop builds are updated.
 */
class WaLegacyController extends Controller
{
    /** GET /api/worker-app/wachbuch?einsatz_id= */
    public function show(Request $request)
    {
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        $w = ShiftWindow::fuer($dn, (string) $request->query('einsatz_id', ''));
        if (!$w) return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        $now = now();
        return response()->json([
            'eintrag' => null,
            'label'   => \App\Support\TenantProfile::objektbuchLabel(\App\Services\TenantService::current()),
            'fenster' => [
                'editable' => $now->gte($w[0]) && $now->lte($w[1]),
                'status'   => $now->lt($w[0]) ? 'vorher' : ($now->gt($w[1]) ? 'nachher' : 'offen'),
                'von'      => $w[0]->copy()->setTimezone('Europe/Berlin')->format('Y-m-d H:i'),
                'bis'      => $w[1]->copy()->setTimezone('Europe/Berlin')->format('Y-m-d H:i'),
            ],
        ]);
    }

    /** POST /api/worker-app/wachbuch {einsatz_id, text, client_uuid} */
    public function store(Request $request)
    {
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        $eid = (string) $request->input('einsatz_id', '');
        $text = trim(mb_substr((string) $request->input('text', ''), 0, 4000));
        if ($text === '') return response()->json(['error' => 'Bitte einen Eintrag schreiben'], 422);
        $w = ShiftWindow::fuer($dn, $eid);
        if (!$w) return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        if (now()->lt($w[0]) || now()->gt($w[1]->copy()->addHours(12))) {
            return response()->json(['error' => 'Die Schicht läuft nicht.', 'locked' => true, 'fenster' => ['status' => now()->lt($w[0]) ? 'vorher' : 'nachher']], 409);
        }
        if (!Qm::ready()) return response()->json(['error' => 'Wachbuch nicht verfügbar'], 409);
        // Same switch as the current app: company setting + Objekt setting.
        $objId = (string) Qm::t('einsaetze')->where('einsatz_id', $eid)->value('obj_id');
        if ($objId === '' || !WachbuchService::aktiv($objId)) return response()->json(['error' => 'Für dieses Objekt ist das Wachbuch nicht aktiv.', 'locked' => true], 409);
        $blatt = WachbuchService::blattFuerSchicht($eid, $dn);
        if (!$blatt) return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        $uuid = $request->input('client_uuid');
        try {
            WachbuchService::append($blatt, [
                'art' => 'notiz', 'text' => $text, 'details' => [],
                'client_uuid' => is_string($uuid) && $uuid !== '' ? mb_substr('legacy-' . $uuid, 0, 64) : null,
            ], ['quelle' => 'app', 'dienst_nr' => $dn, 'erfasst_von' => Qm::workerName($dn)]);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage(), 'locked' => true], 409);
        }
        return response()->json(['success' => true]);
    }
}
