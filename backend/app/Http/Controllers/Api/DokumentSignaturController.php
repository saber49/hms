<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationDispatcher;
use App\Http\Controllers\Controller;
use App\Services\DokumentSignaturService as Sig;
use App\Services\TenantService;
use App\Support\BereichScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Office side of the worker e-signature (see DokumentSignaturService):
 * status per worker of a document, send / remind / withdraw requests.
 * Gate = permission of the document type + existence + Bereich scope.
 */
class DokumentSignaturController extends Controller
{
    private const PERMISSION = [
        'order' => ['view' => 'auftraege.bestellungen.view', 'edit' => 'auftraege.bestellungen.edit'],
    ];
    private const ID_RE = '/^[A-Za-z0-9_-]{1,64}$/';

    /** GET /api/dokument-signaturen?doc_type=&ref_id= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        TenantService::assertCurrentMatches((int) $user->tenant_id);
        [$type, $ref] = [(string) $request->query('doc_type'), (string) $request->query('ref_id')];
        if (!$this->allowed($user, $type, $ref, 'view')) return response()->json(['error' => 'Dokument nicht gefunden'], 404);

        return response()->json([
            'aktiv'    => Sig::ready(),
            'can_send' => Sig::ready() && $user->hasPermission(self::PERMISSION[$type]['edit']),
            'workers'  => $this->workerStates((int) $user->tenant_id, $type, $ref),
        ]);
    }

    /** POST /api/dokument-signaturen/anfragen {doc_type, ref_id, dienst_nrs?: []} */
    public function anfragen(Request $request): JsonResponse
    {
        $user = $request->user();
        TenantService::assertCurrentMatches((int) $user->tenant_id);
        $data = $request->validate([
            'doc_type'     => 'required|string|max:10',
            'ref_id'       => 'required|string|max:130',
            'dienst_nrs'   => 'nullable|array|max:500',
            'dienst_nrs.*' => 'string|max:40',
        ]);
        [$type, $ref] = [$data['doc_type'], $data['ref_id']];
        if (!$this->allowed($user, $type, $ref, 'edit')) return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        if (!Sig::ready()) return response()->json(['error' => 'Unterschrift per App ist auf diesem Mandanten noch nicht aktiv.'], 503);

        $tenantId = (int) $user->tenant_id;
        $snapshots = Sig::workerSnapshots($type, $ref);
        $states = collect($this->workerStates($tenantId, $type, $ref, $snapshots))->keyBy('dienst_nr');
        $only = isset($data['dienst_nrs']) ? array_map('strval', $data['dienst_nrs']) : null;
        $db = DB::connection('tenant');
        $out = ['gesendet' => 0, 'erinnert' => 0, 'uebersprungen' => []];

        foreach ($snapshots as $dienstNr => $snap) {
            $dienstNr = (string) $dienstNr;
            if ($only !== null && !in_array($dienstNr, $only, true)) continue;
            $state = $states[$dienstNr] ?? null;
            $worker = $db->table('mitarbeiter')->where('dienst_nr', $dienstNr)->first(['id', 'dienst_nr', 'aktiv', 'app_zugang']);
            $grund = ($state && $state['status'] === 'signiert') ? 'bereits unterschrieben'
                : ((!$worker || !$worker->aktiv || !($worker->app_zugang ?? 0)) ? 'kein App-Zugang' : null);
            if ($grund) {
                $out['uebersprungen'][] = ['dienst_nr' => $dienstNr, 'name' => $snap['daten']['mitarbeiter'], 'grund' => $grund];
                continue;
            }

            $hash = Sig::hash($snap['daten']);
            $reminder = $db->transaction(function () use ($db, $type, $ref, $worker, $snap, $hash, $user) {
                $open = $db->table(Sig::TABLE)->where('doc_type', $type)->where('ref_id', $ref)
                    ->where('mitarbeiter_id', $worker->id)->where('status', 'offen')->lockForUpdate()->get();
                $same = $open->first(fn ($r) => hash_equals((string) $r->daten_hash, $hash));
                // Content changed since the open request → the worker must see the new numbers.
                $stale = $open->filter(fn ($r) => !$same || $r->id !== $same->id)->pluck('id')->all();
                if ($stale) $db->table(Sig::TABLE)->whereIn('id', $stale)->update(['status' => 'ersetzt', 'updated_at' => now()]);
                if ($same) {
                    $db->table(Sig::TABLE)->where('id', $same->id)->update(['erinnert_am' => now(), 'updated_at' => now()]);
                    return [(int) $same->id, true];
                }
                $id = $db->table(Sig::TABLE)->insertGetId([
                    'doc_type'           => $type,
                    'ref_id'             => $ref,
                    'mitarbeiter_id'     => $worker->id,
                    'dienst_nr'          => $worker->dienst_nr,
                    'status'             => 'offen',
                    'titel'              => $snap['titel'],
                    'daten'              => json_encode($snap['daten'], JSON_UNESCAPED_UNICODE),
                    'daten_hash'         => $hash,
                    'angefragt_von'      => (int) $user->id,
                    'angefragt_von_name' => mb_substr((string) ($user->full_name ?: ($user->name ?: $user->email)), 0, 190),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
                return [(int) $id, false];
            });

            $d = $snap['daten'];
            // In-app row + device push only — no e-mail (id/dienst_nr-only object).
            NotificationDispatcher::notifyWorker(
                (object) ['id' => $worker->id, 'dienst_nr' => $worker->dienst_nr],
                'signatur.anfrage',
                $reminder[1] ? 'Erinnerung: Unterschrift erforderlich' : 'Unterschrift erforderlich',
                trim($d['objekt'] . ' · ' . $d['zeitraum'], ' ·') . "\n"
                    . $d['einsaetze'] . ($d['einsaetze'] === 1 ? ' Einsatz' : ' Einsätze') . ' · '
                    . number_format($d['std'], 2, ',', '.') . ' Std — bitte prüfen und unterschreiben',
                ['signatur_id' => $reminder[0]]
            );
            $out[$reminder[1] ? 'erinnert' : 'gesendet']++;
        }

        return response()->json(['success' => true] + $out + ['workers' => $this->workerStates($tenantId, $type, $ref)]);
    }

    /**
     * DELETE /api/dokument-signaturen/{id} — withdraw an open request, or
     * discard a signature (status `verworfen`; row + image stay as audit
     * trail) so the worker can be asked again.
     */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        TenantService::assertCurrentMatches((int) $user->tenant_id);
        $row = Sig::ready() ? DB::connection('tenant')->table(Sig::TABLE)->where('id', $id)->first() : null;
        if (!$row || !$this->allowed($user, (string) $row->doc_type, (string) $row->ref_id, 'edit')) {
            return response()->json(['error' => 'Anfrage nicht gefunden'], 404);
        }
        $by = mb_substr((string) ($user->full_name ?: ($user->name ?: $user->email)), 0, 190);
        $n = match ($row->status) {
            'offen'    => DB::connection('tenant')->table(Sig::TABLE)->where('id', $id)->where('status', 'offen')
                ->update(['status' => 'zurueckgezogen', 'updated_at' => now()]),
            'signiert' => DB::connection('tenant')->table(Sig::TABLE)->where('id', $id)->where('status', 'signiert')
                ->update(['status' => 'verworfen', 'ablehn_grund' => mb_substr('Verworfen von ' . $by, 0, 500), 'updated_at' => now()]),
            default    => 0,
        };
        if (!$n) return response()->json(['error' => 'Anfrage ist nicht mehr offen'], 409);

        return response()->json(['success' => true,
            'workers' => $this->workerStates((int) $user->tenant_id, (string) $row->doc_type, (string) $row->ref_id)]);
    }

    // ─────────────────────────────────────────────────────────────

    /**
     * Signature state of every internal worker on the document:
     * signiert (all his rows covered) · offen · abgelehnt · veraltet (signed,
     * but rows changed since) · keine. `keys` = rows carrying the signature.
     */
    private function workerStates(int $tenantId, string $type, string $ref, ?array $snapshots = null): array
    {
        $snapshots ??= Sig::workerSnapshots($type, $ref);
        $db = DB::connection('tenant');
        $requests = Sig::ready()
            ? $db->table(Sig::TABLE)->where('doc_type', $type)->where('ref_id', $ref)
                ->whereIn('status', ['offen', 'signiert', 'abgelehnt'])->orderByDesc('id')->get()->groupBy('dienst_nr')
            : collect();
        $access = $snapshots
            ? $db->table('mitarbeiter')->whereIn('dienst_nr', array_map('strval', array_keys($snapshots)))
                ->get(['dienst_nr', 'aktiv', 'app_zugang'])->keyBy('dienst_nr')
            : collect();

        $out = [];
        foreach ($snapshots as $dienstNr => $snap) {
            $dienstNr = (string) $dienstNr;
            $d = $snap['daten'];
            $rows = $requests[$dienstNr] ?? collect();
            $latest = $rows->first();
            $signed = $rows->firstWhere('status', 'signiert');
            $open = $rows->firstWhere('status', 'offen');
            $keys = $signed ? Sig::validKeys(Sig::decodeDaten($signed->daten), $d) : [];
            $image = $keys ? Sig::signatureDataUrl($tenantId, $signed->signatur_pfad) : null;
            if (!$image) $keys = [];

            $status = match (true) {
                $keys && count($keys) === count($d['rows'])    => 'signiert',
                (bool) $open                                   => 'offen',
                $latest && $latest->status === 'abgelehnt'     => 'abgelehnt',
                (bool) $signed                                 => 'veraltet',
                default                                        => 'keine',
            };
            $m = $access[$dienstNr] ?? null;
            $out[] = [
                'dienst_nr'    => $dienstNr,
                'name'         => $d['mitarbeiter'],
                'einsaetze'    => $d['einsaetze'],
                'std'          => $d['std'],
                'app_zugang'   => (bool) ($m && $m->aktiv && ($m->app_zugang ?? 0)),
                'status'       => $status,
                'offen_id'     => $open ? (int) $open->id : null,
                'signiert_id'  => $signed ? (int) $signed->id : null,
                'angefragt_am' => $open ? ($open->erinnert_am ?: $open->created_at) : null,
                'signiert_am'  => $signed->signiert_am ?? null,
                'abgelehnt_am' => $status === 'abgelehnt' ? $latest->abgelehnt_am : null,
                'grund'        => $status === 'abgelehnt' ? $latest->ablehn_grund : null,
                'keys'         => $keys,
                'signatur'     => $image,
            ];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** Permission + existence + Bereich scope of the referenced document. */
    private function allowed($user, string $type, string $ref, string $mode): bool
    {
        if (!isset(self::PERMISSION[$type]) || !Sig::supports($type) || !preg_match(self::ID_RE, $ref)) return false;
        if (!$user->hasPermission(self::PERMISSION[$type][$mode])) return false;
        return DB::connection('tenant')->table('bestellungen')->where('bestell_id', $ref)->exists()
            && BereichScope::allowsBestellung($user, $ref);
    }
}
