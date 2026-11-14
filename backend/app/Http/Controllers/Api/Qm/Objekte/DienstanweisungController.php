<?php

namespace App\Http\Controllers\Api\Qm\Objekte;

use App\Http\Controllers\Api\Qm\Concerns\QmGuards;
use App\Http\Controllers\Controller;
use App\Services\Qm\DienstanweisungService;
use App\Support\Qm\Qm;
use Illuminate\Http\Request;

/** Dienstanweisung — office side: upload (draft), release / reject, PDF. */
class DienstanweisungController extends Controller
{
    use QmGuards;

    /** POST /api/qm/objekte/{objId}/dienstanweisungen (multipart: pdf, gueltig_ab, kurz[]) */
    public function store(Request $request, string $objId)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($u, $objId) ?? $this->rechtOderFehler($u, 'da_anlegen')) return $e;
        $kurz = collect((array) $request->input('kurz', []))->map(fn ($x) => trim((string) $x))->filter()->values()->all();
        [$d, $err] = Qm::pruefe(['pdf' => $request->file('pdf'), 'gueltig_ab' => $request->input('gueltig_ab'), 'kurz' => $kurz], [
            'pdf' => 'required|file|mimes:pdf|max:' . DienstanweisungService::MAX_KB,
            'gueltig_ab' => 'required|date_format:Y-m-d',
            'kurz' => 'required|array|min:1|max:8',
            'kurz.*' => 'string|max:140',
        ]);
        if ($err) return Qm::fehler($err);
        if ($request->file('pdf')->getMimeType() !== 'application/pdf') return Qm::fehler(['pdf' => ['Nur PDF-Dateien sind erlaubt.']]);

        $da = DienstanweisungService::erstellen($u, $objId, $request->file('pdf'), $d['gueltig_ab'], $kurz);
        return response()->json(['success' => true, 'dienstanweisung' => DienstanweisungService::format($da)]);
    }

    /** POST /api/qm/dienstanweisungen/{id}/freigabe {aktion: freigeben|zurueckweisen, grund} */
    public function freigabe(Request $request, int $id)
    {
        $u = $request->user();
        $da = Qm::t('qm_dienstanweisungen')->find($id);
        if (!$da) return response()->json(['error' => 'Dienstanweisung nicht gefunden'], 404);
        if ($e = $this->objektOderFehler($u, $da->obj_id) ?? $this->rechtOderFehler($u, 'da_freigeben')) return $e;
        if ($da->status !== 'entwurf') return response()->json(['error' => 'Nur Entwürfe können freigegeben oder zurückgewiesen werden.'], 409);

        if ($request->input('aktion') === 'zurueckweisen') {
            $grund = trim((string) $request->input('grund'));
            if ($grund === '') return Qm::fehler(['grund' => ['Bitte eine Begründung angeben.']]);
            DienstanweisungService::zurueckweisen($da, mb_substr($grund, 0, 2000), $u);
            return response()->json(['success' => true]);
        }
        $da = DienstanweisungService::veroeffentlichen($da, $u);
        return response()->json(['success' => true, 'dienstanweisung' => DienstanweisungService::format($da)]);
    }

    /** GET /api/qm/dienstanweisungen/{id}/pdf */
    public function pdf(Request $request, int $id)
    {
        $da = Qm::t('qm_dienstanweisungen')->find($id);
        if (!$da || !\App\Support\Qm\QmScope::allowsObjekt($request->user(), $da->obj_id)) abort(404);
        return DienstanweisungService::pdfResponse($da);
    }
}
