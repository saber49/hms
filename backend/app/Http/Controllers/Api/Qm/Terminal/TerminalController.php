<?php

namespace App\Http\Controllers\Api\Qm\Terminal;

use App\Http\Controllers\Api\WorkerAppZeitController;
use App\Http\Controllers\Controller;
use App\Services\Qm\PinService;
use App\Services\Qm\TerminalService;
use App\Services\Qm\WachbuchService;
use App\Support\Qm\Qm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Objekt-Terminal (spec §5): Objekt login, "Wer bist du?" (only workers
 * planned here today) + personal PIN, check-in / check-out on the device at
 * the gate. Wachbuch + Dienstanweisung run through the worker-app QM
 * controllers (same rules, limited to this Objekt by QmTerminalAuth).
 */
class TerminalController extends Controller
{
    /** POST /api/qm-terminal/login {kennung, pin} — public, throttled. */
    public function login(Request $request)
    {
        $kennung = trim((string) $request->input('kennung'));
        $pin = (string) $request->input('pin');
        if ($kennung === '' || !preg_match('/^\d{6}$/', $pin)) return response()->json(['error' => 'Bitte Objekt-Kennung und 6-stellige PIN eingeben.'], 422);
        [$ok, $fehler] = TerminalService::login($kennung, $pin, mb_substr((string) $request->userAgent(), 0, 255), $request->ip());
        if ($fehler) return response()->json(['error' => $fehler[0]], $fehler[1]);
        return response()->json(['success' => true] + $ok);
    }

    /** POST /api/qm-terminal/logout */
    public function logout(Request $request)
    {
        $s = $request->attributes->get('qm_sitzung');
        Qm::t('qm_terminal_sitzungen')->where('id', $s->id)->update(['ende' => now()]);
        DB::connection('mysql')->table('personal_access_tokens')->where('token', hash('sha256', (string) $request->bearerToken()))->delete();
        return response()->json(['success' => true]);
    }

    /** GET /api/qm-terminal/status */
    public function status(Request $request)
    {
        $t = $request->attributes->get('qm_terminal');
        $s = $request->attributes->get('qm_sitzung');
        $obj = Qm::t('objekte')->where('obj_id', $t->obj_id)->first(['obj_id', 'objektname', 'adresse', 'plz', 'stadt']);
        $dn = $request->attributes->get('worker_dienst_nr');
        $stempel = Qm::t('zeiterfassung')->whereIn('einsatz_id', TerminalService::heuteGeplant($t->obj_id)->pluck('einsatz_id'))
            ->get(['einsatz_id', 'dienst_nr', 'status'])->keyBy(fn ($z) => $z->einsatz_id . '|' . $z->dienst_nr);
        return response()->json([
            'kennung' => $t->kennung,
            'objekt' => $obj,
            'label' => \App\Support\TenantProfile::objektbuchLabel(\App\Services\TenantService::current()),
            'wachbuch_aktiv' => WachbuchService::aktiv($t->obj_id),
            'notfallkontakte' => WachbuchService::aktiv($t->obj_id) ? trim((string) (Qm::objektEinstellung($t->obj_id)->notfallkontakte ?? '')) : '',
            'sitzung_bis' => $s->laeuft_ab,
            'wachperson' => $dn ? ['dienst_nr' => $dn, 'name' => Qm::workerName($dn), 'seit' => $s->wachperson_seit] : null,
            'geplant' => TerminalService::heuteGeplant($t->obj_id)->map(fn ($p) => [
                'dienst_nr' => $p->dienst_nr, 'name' => trim($p->vorname . ' ' . $p->name), 'einsatz_id' => $p->einsatz_id,
                'plan_von' => substr((string) $p->ist_start, 0, 5), 'plan_bis' => substr((string) $p->ist_ende, 0, 5),
                'status' => $stempel[$p->einsatz_id . '|' . $p->dienst_nr]->status ?? null,
            ])->values(),
        ]);
    }

    /** POST /api/qm-terminal/wachperson {dienst_nr, pin, neue_pin?} */
    public function anmelden(Request $request)
    {
        $t = $request->attributes->get('qm_terminal');
        $s = $request->attributes->get('qm_sitzung');
        $dn = (string) $request->input('dienst_nr');
        if (!TerminalService::heuteGeplant($t->obj_id)->contains('dienst_nr', $dn)) {
            return response()->json(['error' => 'Du bist jetzt nicht für dieses Objekt eingeplant.'], 403);
        }
        [$ok, $err] = PinService::pruefen($dn, (string) $request->input('pin'), $t->obj_id);
        if (!$ok) return response()->json(['error' => $err], 401);
        if (PinService::status($dn)->start_pin) {
            $neu = (string) $request->input('neue_pin', '');
            if ($neu === '') return response()->json(['neue_pin_noetig' => true, 'error' => 'Bitte lege jetzt deine eigene 4-stellige PIN fest.'], 409);
            if ($e = PinService::setzen($dn, $neu, 'Terminal ' . $t->kennung)) return response()->json(['neue_pin_noetig' => true, 'error' => $e], 422);
        }
        Qm::t('qm_terminal_sitzungen')->where('id', $s->id)->update(['dienst_nr' => $dn, 'wachperson_seit' => now()]);
        Qm::log('terminal', $t->id, 'wachperson', null, ['dienst_nr' => $dn]);
        return response()->json(['success' => true, 'name' => Qm::workerName($dn)]);
    }

    /** POST /api/qm-terminal/wachperson/abmelden — "Wachperson wechseln" */
    public function abmelden(Request $request)
    {
        $s = $request->attributes->get('qm_sitzung');
        Qm::t('qm_terminal_sitzungen')->where('id', $s->id)->update(['dienst_nr' => null, 'wachperson_seit' => null]);
        return response()->json(['success' => true]);
    }

    /** POST /api/qm-terminal/check-in — the device is at the Objekt, so no GPS is required. */
    public function checkIn(Request $request)
    {
        $t = $request->attributes->get('qm_terminal');
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        $plan = TerminalService::heuteGeplant($t->obj_id)->where('dienst_nr', $dn);
        $stempel = Qm::t('zeiterfassung')->where('dienst_nr', $dn)->whereIn('einsatz_id', $plan->pluck('einsatz_id'))->pluck('einsatz_id')->all();
        $einsatz = $plan->first(fn ($p) => !in_array($p->einsatz_id, $stempel, true));
        if (!$einsatz) return response()->json(['error' => 'Keine offene Schicht an diesem Objekt.'], 409);
        $sub = Request::create('/', 'POST', ['einsatz_id' => $einsatz->einsatz_id]);
        $sub->attributes->add($request->attributes->all());
        return app(WorkerAppZeitController::class)->clockIn($sub);
    }

    /** POST /api/qm-terminal/check-out {uebergabe?} — ends the guard's session on the device too. */
    public function checkOut(Request $request)
    {
        $t = $request->attributes->get('qm_terminal');
        $s = $request->attributes->get('qm_sitzung');
        $dn = (string) $request->attributes->get('worker_dienst_nr');
        $aktiv = Qm::t('zeiterfassung as z')->join('einsaetze as e', 'e.einsatz_id', '=', 'z.einsatz_id')
            ->where('z.dienst_nr', $dn)->whereIn('z.status', ['aktiv', 'pause'])->value('e.obj_id');
        if ($aktiv !== $t->obj_id) return response()->json(['error' => 'Kein laufender Dienst an diesem Objekt.'], 409);
        $sub = Request::create('/', 'POST', ['uebergabe' => $request->input('uebergabe')]);
        $sub->attributes->add($request->attributes->all());
        $res = app(WorkerAppZeitController::class)->clockOut($sub);
        if ($res->getStatusCode() === 200) Qm::t('qm_terminal_sitzungen')->where('id', $s->id)->update(['dienst_nr' => null, 'wachperson_seit' => null]);
        return $res;
    }
}
