<?php

namespace App\Http\Controllers\Api\Qm\Live;

use App\Events\QmObjektChanged;
use App\Http\Controllers\Controller;
use App\Services\Qm\DienstanweisungService;
use App\Support\Qm\Qm;
use Illuminate\Http\Request;

/**
 * Reverb bootstrap + channel auth for the QM live channels of the worker
 * app, the Objekt-Terminal and the Kundenportal. Each client may subscribe
 * ONLY to the Objekte it is entitled to (computed server-side per request).
 */
class LiveController extends Controller
{
    private const MAX_KANAELE = 50;

    /** GET …/qm/live/config — {enabled, key, channels[]} */
    public function config(Request $request)
    {
        $enabled = config('broadcasting.default') === 'reverb' && !empty(config('broadcasting.connections.reverb.key'));
        return response()->json([
            'enabled'  => $enabled && Qm::ready(),
            'key'      => config('broadcasting.connections.reverb.key'),
            'channels' => array_map(fn ($o) => QmObjektChanged::kanal((int) $request->attributes->get('tenant_id'), $o), $this->objekte($request)),
        ]);
    }

    /** POST …/qm/live/auth {socket_id, channel_name} — pusher private-channel signature. */
    public function auth(Request $request)
    {
        $channel = (string) $request->input('channel_name', '');
        $socket  = (string) $request->input('socket_id', '');
        $erlaubt = array_map(fn ($o) => 'private-' . QmObjektChanged::kanal((int) $request->attributes->get('tenant_id'), $o), $this->objekte($request));
        if ($socket === '' || !preg_match('/^\d+\.\d+$/', $socket) || !in_array($channel, $erlaubt, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');
        if (!$key || !$secret) return response()->json(['error' => 'Realtime disabled'], 503);
        return response()->json(['auth' => $key . ':' . hash_hmac('sha256', $socket . ':' . $channel, $secret)]);
    }

    /** Objekte of the caller: terminal → its Objekt; portal → access objects; worker → objects of his shifts. */
    private function objekte(Request $request): array
    {
        if (!Qm::ready()) return [];
        if ($obj = $request->attributes->get('qm_terminal_obj')) return [(string) $obj];
        if ($request->attributes->has('kp_objekte')) return array_slice($request->attributes->get('kp_objekte') ?: [], 0, self::MAX_KANAELE);
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        if ($dn === '') return [];
        $laufend = Qm::t('zeiterfassung as z')->join('einsaetze as e', 'e.einsatz_id', '=', 'z.einsatz_id')
            ->where('z.dienst_nr', $dn)->whereIn('z.status', ['aktiv', 'pause'])->pluck('e.obj_id')->all();
        $da = array_column(DienstanweisungService::fuerMitarbeiter($dn), 'obj_id');
        return array_slice(array_values(array_unique(array_filter(array_map('strval', array_merge($laufend, $da))))), 0, self::MAX_KANAELE);
    }
}
