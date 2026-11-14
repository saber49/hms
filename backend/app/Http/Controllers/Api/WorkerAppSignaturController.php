<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationDispatcher;
use App\Helpers\Realtime;
use App\Http\Controllers\Controller;
use App\Services\DokumentSignaturService as Sig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Worker app side of the e-signature (see DokumentSignaturService). Every
 * query is pinned to the worker id of the token (ResolveWorkerToken) — a
 * worker only ever sees and signs his own requests.
 */
class WorkerAppSignaturController extends Controller
{
    /** GET /api/worker-app/signaturen — open requests (newest first). */
    public function index(Request $request): JsonResponse
    {
        if (!Sig::ready()) return response()->json(['offen' => []]);
        $rows = DB::connection('tenant')->table(Sig::TABLE)
            ->where('mitarbeiter_id', $request->attributes->get('worker_id'))
            ->where('status', 'offen')->orderByDesc('id')->limit(50)
            ->get(['id', 'doc_type', 'titel', 'created_at']);

        return response()->json(['offen' => $rows]);
    }

    /** GET /api/worker-app/signaturen/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $row = $this->own($request, $id);
        if (!$row) return response()->json(['error' => 'Anfrage nicht gefunden'], 404);

        return response()->json([
            'id'          => (int) $row->id,
            'doc_type'    => $row->doc_type,
            'titel'       => $row->titel,
            'status'      => $row->status,
            'daten'       => Sig::decodeDaten($row->daten),
            'created_at'  => $row->created_at,
            'signiert_am' => $row->signiert_am,
        ]);
    }

    /** POST /api/worker-app/signaturen/{id}/unterschreiben {signatur: data:image/png;base64,…} */
    public function sign(Request $request, int $id): JsonResponse
    {
        $png = Sig::decodeSignature($request->input('signatur'));
        if ($png === null) return response()->json(['error' => 'Unterschrift fehlt oder ist ungültig'], 422);
        $workerId = $request->attributes->get('worker_id');
        $tenantId = (int) $request->attributes->get('tenant_id');
        $db = DB::connection('tenant');

        $row = $db->transaction(function () use ($db, $id, $workerId, $tenantId, $png, $request) {
            $row = $db->table(Sig::TABLE)->where('id', $id)->where('mitarbeiter_id', $workerId)->lockForUpdate()->first();
            if (!$row || $row->status !== 'offen') return $row;
            $db->table(Sig::TABLE)->where('id', $id)->update([
                'status'          => 'signiert',
                'signatur_pfad'   => Sig::storeSignature($tenantId, $id, $png),
                'signatur_hash'   => hash('sha256', $png),
                'signiert_am'     => now(),
                'signatur_ip'     => mb_substr((string) $request->ip(), 0, 45),
                'signatur_geraet' => mb_substr((string) $request->userAgent(), 0, 255),
                'updated_at'      => now(),
            ]);
            $row->status = 'signiert-neu';
            return $row;
        });
        if (!$row) return response()->json(['error' => 'Anfrage nicht gefunden'], 404);
        if ($row->status !== 'signiert-neu') return response()->json(['error' => $this->closedText($row->status)], 409);

        $this->tellOffice($request, $row, 'unterschrieben');
        return response()->json(['success' => true]);
    }

    /** POST /api/worker-app/signaturen/{id}/ablehnen {grund} */
    public function reject(Request $request, int $id): JsonResponse
    {
        $grund = trim((string) $request->input('grund'));
        if (mb_strlen($grund) < 3) return response()->json(['error' => 'Bitte kurz angeben, was nicht stimmt'], 422);
        $row = $this->own($request, $id);
        if (!$row) return response()->json(['error' => 'Anfrage nicht gefunden'], 404);

        $n = DB::connection('tenant')->table(Sig::TABLE)->where('id', $id)->where('status', 'offen')->update([
            'status' => 'abgelehnt', 'ablehn_grund' => mb_substr($grund, 0, 500), 'abgelehnt_am' => now(), 'updated_at' => now(),
        ]);
        if (!$n) return response()->json(['error' => $this->closedText($row->status)], 409);

        $this->tellOffice($request, $row, 'abgelehnt', mb_substr($grund, 0, 200));
        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────

    private function own(Request $request, int $id): ?object
    {
        if (!Sig::ready()) return null;
        return DB::connection('tenant')->table(Sig::TABLE)->where('id', $id)
            ->where('mitarbeiter_id', $request->attributes->get('worker_id'))->first();
    }

    private function closedText(string $status): string
    {
        return match ($status) {
            'signiert'  => 'Bereits unterschrieben',
            'abgelehnt' => 'Bereits beantwortet',
            default     => 'Diese Anfrage ist nicht mehr aktuell',
        };
    }

    /** Bell + live refresh for the office user who asked. Best-effort. */
    private function tellOffice(Request $request, object $row, string $was, string $grund = ''): void
    {
        try {
            $w = $request->attributes->get('worker');
            $name = trim(($w->vorname ?? '') . ' ' . ($w->name ?? '')) ?: (string) $row->dienst_nr;
            $tenantId = (int) $request->attributes->get('tenant_id');
            if ($row->angefragt_von) {
                NotificationDispatcher::notifyUserIds($tenantId, [(int) $row->angefragt_von], 'worker.signatur.' . $was,
                    Sig::label((string) $row->doc_type) . ' ' . $was,
                    $name . ' · ' . $row->ref_id . ($grund !== '' ? "\n„" . $grund . '“' : ''),
                    '#orders', ['doc_type' => $row->doc_type, 'ref_id' => $row->ref_id, 'dienst_nr' => $row->dienst_nr]);
            }
            Realtime::changed('signaturen', ['doc_type' => $row->doc_type, 'ref_id' => $row->ref_id], $tenantId);
        } catch (\Throwable $e) {
            \Log::warning('WorkerAppSignaturController::tellOffice failed: ' . $e->getMessage());
        }
    }
}
