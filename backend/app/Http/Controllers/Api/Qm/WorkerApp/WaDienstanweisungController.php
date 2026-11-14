<?php

namespace App\Http\Controllers\Api\Qm\WorkerApp;

use App\Http\Controllers\Controller;
use App\Services\Qm\DienstanweisungService;
use App\Services\Qm\PinService;
use App\Support\Qm\Qm;
use Illuminate\Http\Request;

/**
 * Worker app › Dienstanweisungen (spec §6.7) + "Meine PIN" (spec §5).
 * Fetching the PDF marks it as opened — only then "Gelesen und verstanden"
 * is accepted. Also used by the Objekt-Terminal (limited to its Objekt).
 */
class WaDienstanweisungController extends Controller
{
    /** GET /api/worker-app/qm/dienstanweisungen */
    public function index(Request $request)
    {
        if (!Qm::ready()) return response()->json(['dienstanweisungen' => [], 'offen' => 0]);
        $list = collect(DienstanweisungService::fuerMitarbeiter((string) $request->attributes->get('worker_dienst_nr')));
        if ($obj = $request->attributes->get('qm_terminal_obj')) $list = $list->where('obj_id', $obj)->values();
        return response()->json(['dienstanweisungen' => $list, 'offen' => $list->whereNull('bestaetigt_am')->count()]);
    }

    /** GET /api/worker-app/qm/dienstanweisungen/{id}/pdf */
    public function pdf(Request $request, int $id)
    {
        $da = $this->laden($request, $id);
        DienstanweisungService::geoeffnet($da, (string) $request->attributes->get('worker_dienst_nr'));
        return DienstanweisungService::pdfResponse($da);
    }

    /**
     * GET /api/worker-app/qm/dienstanweisungen/{id}/link — signed URL (10 min)
     * for the native PDF viewer / a browser tab (they cannot send the bearer
     * token). Opening it marks the PDF as opened for this worker.
     */
    public function link(Request $request, int $id)
    {
        $da = $this->laden($request, $id);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('qm.da.pdf', now()->addMinutes(10), [
            't' => (int) $request->attributes->get('tenant_id'), 'da' => (int) $da->id,
            'dn' => (string) $request->attributes->get('worker_dienst_nr'),
            'q' => $request->attributes->get('qm_terminal_obj') ? 'terminal' : 'app',
        ]);
        return response()->json(['url' => $url, 'seiten' => (int) $da->seiten]);
    }

    /** GET /api/qm-da/{t}/{da}/{dn}?signature=… — public, `signed` middleware. */
    public function signiert(Request $request, int $t, int $da, string $dn)
    {
        $tenant = \App\Models\Tenant::find($t);
        if (!$tenant || !$tenant->aktiv) abort(404);
        \App\Services\TenantService::switchToTenant($tenant);
        $row = Qm::ready() ? Qm::t('qm_dienstanweisungen')->find($da) : null;
        if (!$row || $row->status !== 'freigegeben') abort(404);
        DienstanweisungService::geoeffnet($row, $dn);
        return DienstanweisungService::pdfResponse($row);
    }

    /** POST /api/worker-app/qm/dienstanweisungen/{id}/bestaetigen */
    public function bestaetigen(Request $request, int $id)
    {
        $da = $this->laden($request, $id);
        $quelle = $request->attributes->get('qm_terminal_obj') ? 'terminal' : 'app';
        if ($err = DienstanweisungService::bestaetigen($da, (string) $request->attributes->get('worker_dienst_nr'), $quelle)) {
            return response()->json(['error' => $err, 'pdf_noetig' => true], 422);
        }
        return response()->json(['success' => true]);
    }

    /** GET /api/worker-app/qm/pin */
    public function pinStatus(Request $request)
    {
        $p = Qm::ready() ? PinService::status((string) $request->attributes->get('worker_dienst_nr')) : null;
        return response()->json(['status' => !$p ? 'keine' : ($p->gesperrt ? 'gesperrt' : ($p->start_pin ? 'start' : 'aktiv')), 'geaendert_am' => $p->geaendert_am ?? null]);
    }

    /** POST /api/worker-app/qm/pin {alt, neu} — the old (or start) PIN is required. */
    public function pinAendern(Request $request)
    {
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        if (!Qm::ready() || !PinService::status($dn)) return response()->json(['error' => 'Für dich ist noch keine PIN vergeben. Bitte wende dich an die Objektleitung.'], 404);
        [$ok, $err] = PinService::pruefen($dn, (string) $request->input('alt', ''));
        if (!$ok) return Qm::fehler(['alt' => [$err]]);
        if ($err = PinService::setzen($dn, (string) $request->input('neu', ''), 'App')) return Qm::fehler(['neu' => [$err]]);
        return response()->json(['success' => true]);
    }

    /** Only current versions of objects the worker works at (and, on a terminal, only that Objekt). */
    private function laden(Request $request, int $id): object
    {
        $erlaubt = collect(DienstanweisungService::fuerMitarbeiter((string) $request->attributes->get('worker_dienst_nr')));
        if ($obj = $request->attributes->get('qm_terminal_obj')) $erlaubt = $erlaubt->where('obj_id', $obj);
        if (!Qm::ready() || !$erlaubt->contains('id', $id)) abort(404);
        return Qm::t('qm_dienstanweisungen')->find($id);
    }
}
