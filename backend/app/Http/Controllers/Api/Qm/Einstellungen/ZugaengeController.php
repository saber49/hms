<?php

namespace App\Http\Controllers\Api\Qm\Einstellungen;

use App\Http\Controllers\Api\Qm\Concerns\QmGuards;
use App\Http\Controllers\Controller;
use App\Services\Qm\PinService;
use App\Services\Qm\TerminalService;
use App\Support\Qm\Qm;
use App\Support\Qm\QmScope;
use Illuminate\Http\Request;

/**
 * Einstellungen › Objekt-Terminals + Mitarbeiter-PIN. QMB/Büro see all
 * objects, an Objektleiter only his own (and the workers planned there).
 * PINs are shown exactly once — right after they were generated.
 */
class ZugaengeController extends Controller
{
    use QmGuards;

    /** GET /api/qm/terminals */
    public function terminals(Request $request)
    {
        if ($e = $this->nichtBereit()) return $e;
        $u = $request->user();
        $objekte = QmScope::objekte($u)->keyBy('obj_id');
        $t = Qm::t('qm_terminals')->whereIn('obj_id', $objekte->keys())->get()->keyBy('obj_id');
        $offen = Qm::t('qm_terminal_sitzungen')->whereIn('terminal_id', $t->pluck('id'))->whereNull('ende')->where('laeuft_ab', '>', now())
            ->selectRaw('terminal_id, COUNT(*) AS n')->groupBy('terminal_id')->pluck('n', 'terminal_id');
        return response()->json(['adresse' => config('app.qm_terminal_url', url('/wachbuch/index.php')), 'terminals' => $objekte->map(fn ($o) => [
            'obj_id' => $o->obj_id, 'objektname' => $o->objektname, 'kundenname' => $o->kundenname,
            'wachbuch_aktiv' => (bool) $o->wachbuch_aktiv,
            'terminal' => isset($t[$o->obj_id]) ? [
                'id' => (int) $t[$o->obj_id]->id, 'kennung' => $t[$o->obj_id]->kennung, 'aktiv' => (bool) $t[$o->obj_id]->aktiv,
                'gesperrt_seit' => $t[$o->obj_id]->gesperrt_seit, 'fehlversuche' => (int) $t[$o->obj_id]->fehlversuche,
                'letzte_anmeldung' => $t[$o->obj_id]->letzte_anmeldung, 'sitzungen' => (int) ($offen[$t[$o->obj_id]->id] ?? 0),
            ] : null,
        ])->values()]);
    }

    /** POST /api/qm/terminals/{objId}/pin — create the terminal or issue a new PIN (shown once). */
    public function terminalPin(Request $request, string $objId)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($u, $objId) ?? $this->rechtOderFehler($u, 'terminal_pin')) return $e;
        [$t, $pin] = TerminalService::neuePin($objId, $u);
        Qm::changed('terminals');
        return response()->json(['success' => true, 'kennung' => $t->kennung, 'pin' => $pin]);
    }

    /** POST /api/qm/terminals/{objId}/aktiv {aktiv} — lock / unlock (unlock also clears failures). */
    public function terminalAktiv(Request $request, string $objId)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($u, $objId) ?? $this->rechtOderFehler($u, 'terminal_pin')) return $e;
        $t = Qm::t('qm_terminals')->where('obj_id', $objId)->first();
        if (!$t) return response()->json(['error' => 'Für dieses Objekt gibt es noch keinen Terminal-Zugang.'], 404);
        TerminalService::setAktiv($t, $request->boolean('aktiv'), $u);
        Qm::changed('terminals');
        return response()->json(['success' => true]);
    }

    /** GET /api/qm/pins — workers in scope with PIN state. */
    public function pins(Request $request)
    {
        if ($e = $this->nichtBereit()) return $e;
        $u = $request->user();
        $q = Qm::t('mitarbeiter as m')->leftJoin('qm_mitarbeiter_pins as p', 'p.dienst_nr', '=', 'm.dienst_nr')->where('m.aktiv', 1);
        [$wSql, $wParams] = \App\Support\BereichScope::workerPredicate($u, 'm');
        $q->whereRaw($wSql, $wParams);
        if (!QmScope::alleObjekte($u)) {
            $eigene = QmScope::eigeneObjekte($u) ?: ['__none__'];
            $dn = \App\Services\Qm\DienstanweisungService::mitarbeiterJeObjekt($eigene);
            $q->whereIn('m.dienst_nr', collect($dn)->flatten()->unique()->values()->all() ?: ['__none__']);
        }
        return response()->json(['mitarbeiter' => $q->orderBy('m.vorname')->orderBy('m.name')->limit(2000)
            ->get(['m.dienst_nr', 'm.vorname', 'm.name', 'p.id as pin_id', 'p.gesperrt', 'p.start_pin', 'p.fehlversuche', 'p.geaendert_am', 'p.geaendert_von'])
            ->map(fn ($r) => [
                'dienst_nr' => $r->dienst_nr, 'name' => trim($r->vorname . ' ' . $r->name),
                'status' => !$r->pin_id ? 'keine' : ($r->gesperrt ? 'gesperrt' : ($r->start_pin ? 'start' : 'aktiv')),
                'fehlversuche' => (int) ($r->fehlversuche ?? 0), 'geaendert_am' => $r->geaendert_am, 'geaendert_von' => $r->geaendert_von,
            ])->values()]);
    }

    /** POST /api/qm/pins/{dienstNr}/{aktion} — neu | sperren | entsperren */
    public function pinAktion(Request $request, string $dienstNr, string $aktion)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->rechtOderFehler($u, 'terminal_pin')) return $e;
        if (!$this->workerImScope($u, $dienstNr)) return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        if ($aktion === 'neu') {
            $pin = PinService::startPin($dienstNr, $u);
            return response()->json(['success' => true, 'pin' => $pin]);
        }
        if (!PinService::status($dienstNr)) return response()->json(['error' => 'Für diesen Mitarbeiter ist noch keine PIN vergeben.'], 404);
        match ($aktion) {
            'sperren' => PinService::sperren($dienstNr, $u),
            'entsperren' => PinService::entsperren($dienstNr, $u),
            default => abort(404),
        };
        return response()->json(['success' => true]);
    }

    private function workerImScope($u, string $dienstNr): bool
    {
        if (!Qm::t('mitarbeiter')->where('dienst_nr', $dienstNr)->where('aktiv', 1)->exists()) return false;
        if (!\App\Support\BereichScope::allowsWorker($u, $dienstNr)) return false;
        if (QmScope::alleObjekte($u)) return true;
        $dn = \App\Services\Qm\DienstanweisungService::mitarbeiterJeObjekt(QmScope::eigeneObjekte($u) ?: []);
        return in_array($dienstNr, collect($dn)->flatten()->all(), true);
    }
}
