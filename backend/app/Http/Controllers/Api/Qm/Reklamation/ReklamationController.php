<?php

namespace App\Http\Controllers\Api\Qm\Reklamation;

use App\Http\Controllers\Api\Qm\Concerns\QmGuards;
use App\Http\Controllers\Controller;
use App\Services\Qm\MassnahmeService;
use App\Services\Qm\ReklamationService;
use App\Support\Qm\Qm;
use App\Support\Qm\QmScope;
use Illuminate\Http\Request;

/** Reklamationen — list (tabs + filters), detail with timeline + Verlauf, workflow steps. */
class ReklamationController extends Controller
{
    use QmGuards;

    private const LIMIT = 500;

    /** GET /api/qm/reklamationen?tab=offen|ueberfaellig|meine|abgeschlossen|alle&obj_id=&schwere=&q= */
    public function index(Request $request)
    {
        if ($e = $this->nichtBereit()) return $e;
        $u = $request->user();
        $heute = Qm::heute();
        $base = fn () => QmScope::apply(Qm::t('qm_reklamationen as r')->join('objekte as o', 'o.obj_id', '=', 'r.obj_id'), $u, 'r.obj_id');

        $z = $base()->selectRaw("SUM(r.status != 'abgeschlossen') AS offen,
            SUM(r.status != 'abgeschlossen' AND (r.frist_erledigung < ? OR (r.bestaetigt_am IS NULL AND r.frist_bestaetigung < ?))) AS ueberfaellig,
            SUM(r.status != 'abgeschlossen' AND r.zustaendig_id = ?) AS meine,
            SUM(r.status = 'abgeschlossen') AS abgeschlossen, COUNT(*) AS alle", [$heute, now(), $u->id])->first();

        $q = $base()->leftJoin('kunden as k', 'k.kunde_id', '=', 'r.kunde_id');
        match ((string) $request->query('tab', 'offen')) {
            'ueberfaellig'  => $q->where('r.status', '!=', 'abgeschlossen')->where(fn ($w) => $w->where('r.frist_erledigung', '<', $heute)
                                   ->orWhere(fn ($x) => $x->whereNull('r.bestaetigt_am')->where('r.frist_bestaetigung', '<', now()))),
            'meine'         => $q->where('r.status', '!=', 'abgeschlossen')->where('r.zustaendig_id', $u->id),
            'abgeschlossen' => $q->where('r.status', 'abgeschlossen'),
            'alle'          => null,
            default         => $q->where('r.status', '!=', 'abgeschlossen'),
        };
        if ($request->filled('obj_id')) $q->where('r.obj_id', (string) $request->query('obj_id'));
        if ($request->filled('schwere')) $q->where('r.schwere', (string) $request->query('schwere'));
        if ($request->filled('q')) {
            $s = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->query('q'))) . '%';
            $q->where(fn ($w) => $w->where('r.nr', 'like', $s)->orWhere('r.text', 'like', $s)->orWhere('o.objektname', 'like', $s)->orWhere('k.kundenname', 'like', $s));
        }
        $rows = $q->orderByRaw("r.status = 'abgeschlossen'")->orderBy('r.frist_erledigung')->orderByDesc('r.id')
            ->limit(self::LIMIT + 1)->get(['r.*', 'o.objektname', 'k.kundenname']);
        $users = Qm::userNames();
        return response()->json([
            'zaehler' => array_map('intval', (array) $z),
            'mehr' => $rows->count() > self::LIMIT,
            'data' => $rows->take(self::LIMIT)->map(fn ($r) => $this->zeile($r, $users))->values(),
        ]);
    }

    /** GET /api/qm/reklamationen/{id} */
    public function show(Request $request, int $id)
    {
        $u = $request->user();
        $r = Qm::t('qm_reklamationen as r')->join('objekte as o', 'o.obj_id', '=', 'r.obj_id')->leftJoin('kunden as k', 'k.kunde_id', '=', 'r.kunde_id')
            ->where('r.id', $id)->first(['r.*', 'o.objektname', 'k.kundenname']);
        if (!$r) return response()->json(['error' => 'Reklamation nicht gefunden'], 404);
        if ($e = $this->objektOderFehler($u, $r->obj_id)) return $e;
        $users = Qm::userNames();
        return response()->json($this->zeile($r, $users) + [
            'sofortmassnahme' => $r->sofortmassnahme, 'ursache' => $r->ursache, 'antwort' => $r->antwort,
            'kosten_eur' => $r->kosten_eur, 'beantwortet_am' => $r->beantwortet_am, 'abgeschlossen_am' => $r->abgeschlossen_am,
            'mitarbeiter' => Qm::workerName($r->dienst_nr),
            'schritte' => Qm::t('qm_reklamation_schritte')->where('reklamation_id', $id)->orderBy('am')->orderBy('id')->get(),
            'massnahmen' => Qm::t('qm_massnahmen')->where('quelle_typ', 'reklamation')->where('quelle_id', (string) $id)->orderBy('id')
                ->get(['id', 'nr', 'titel', 'status', 'frist'])->map(fn ($m) => (array) $m + MassnahmeService::flags($m))->values(),
            'verlauf' => Qm::verlauf('reklamation', $id),
        ]);
    }

    /** POST /api/qm/reklamationen */
    public function store(Request $request)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->rechtOderFehler($u, 'reklamation_anlegen')) return $e;
        [$d, $err] = Qm::pruefe($request->all(), [
            'obj_id' => 'required|string|max:20', 'text' => 'required|string|max:5000',
            'kategorie' => 'nullable|in:' . implode(',', array_keys(ReklamationService::KATEGORIEN)),
            'schwere' => 'required|in:' . implode(',', array_keys(ReklamationService::SCHWERE)),
            'kanal' => 'required|in:' . implode(',', array_keys(ReklamationService::KANAL)),
            'melder' => 'nullable|string|max:200', 'eingang_am' => 'nullable|date',
            'dienst_nr' => 'nullable|string|max:10', 'zustaendig_id' => 'nullable|integer',
        ]);
        if ($err) return Qm::fehler($err);
        if ($e = $this->objektOderFehler($u, $d['obj_id'])) return $e;
        if (!empty($d['zustaendig_id']) && !isset(Qm::userNames()[(int) $d['zustaendig_id']])) return Qm::fehler(['zustaendig_id' => ['Bitte einen aktiven Benutzer wählen.']]);
        if (!empty($d['dienst_nr']) && !Qm::t('mitarbeiter')->where('dienst_nr', $d['dienst_nr'])->exists()) return Qm::fehler(['dienst_nr' => ['Mitarbeiter nicht gefunden.']]);
        $r = ReklamationService::anlegen($d, $u);
        return response()->json(['success' => true, 'id' => (int) $r->id, 'nr' => $r->nr]);
    }

    /** POST /api/qm/reklamationen/{id}/{aktion} */
    public function aktion(Request $request, int $id, string $aktion)
    {
        $u = $request->user();
        $r = Qm::t('qm_reklamationen')->find($id);
        if (!$r) return response()->json(['error' => 'Reklamation nicht gefunden'], 404);
        if ($e = $this->objektOderFehler($u, $r->obj_id)) return $e;
        $d = $request->only(['text', 'berechtigt', 'zustaendig_id', 'kosten_eur', 'schwere']);
        if (array_key_exists('berechtigt', $d) && $d['berechtigt'] !== null) $d['berechtigt'] = filter_var($d['berechtigt'], FILTER_VALIDATE_BOOLEAN);
        if (isset($d['text'])) $d['text'] = mb_substr((string) $d['text'], 0, 5000);
        if ($err = ReklamationService::aktion($r, $aktion, $d, $u)) return response()->json(['error' => $err], 422);
        return response()->json(['success' => true]);
    }

    private function zeile(object $r, array $users): array
    {
        return [
            'id' => (int) $r->id, 'nr' => $r->nr, 'obj_id' => $r->obj_id, 'objektname' => $r->objektname,
            'kundenname' => $r->kundenname ?? null, 'eingang_am' => $r->eingang_am, 'kanal' => $r->kanal, 'melder' => $r->melder,
            'text' => $r->text, 'kategorie' => $r->kategorie, 'schwere' => $r->schwere, 'status' => $r->status,
            'berechtigt' => $r->berechtigt === null ? null : (bool) $r->berechtigt,
            'zustaendig_id' => $r->zustaendig_id ? (int) $r->zustaendig_id : null,
            'zustaendig' => $r->zustaendig_id ? ($users[(int) $r->zustaendig_id] ?? null) : null,
            'bestaetigt_am' => $r->bestaetigt_am, 'frist_bestaetigung' => $r->frist_bestaetigung,
            'frist_erledigung' => $r->frist_erledigung ? substr((string) $r->frist_erledigung, 0, 10) : null,
            'dienst_nr' => $r->dienst_nr,
        ] + ReklamationService::flags($r);
    }
}
