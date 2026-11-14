<?php

namespace App\Http\Controllers\Api\Qm\Massnahme;

use App\Http\Controllers\Api\Qm\Concerns\QmGuards;
use App\Http\Controllers\Controller;
use App\Services\Qm\MassnahmeService;
use App\Support\Qm\Qm;
use App\Support\Qm\QmScope;
use Illuminate\Http\Request;

/** Maßnahmen — list, detail with Verlauf, create (also from Reklamation / Wachbuch), edit, extend, complete, effectiveness. */
class MassnahmeController extends Controller
{
    use QmGuards;

    private const LIMIT = 500;

    /** GET /api/qm/massnahmen?tab=offen|ueberfaellig|pruefung|meine|abgeschlossen|alle&quelle=&obj_id=&q= */
    public function index(Request $request)
    {
        if ($e = $this->nichtBereit()) return $e;
        $u = $request->user();
        $heute = Qm::heute();
        $base = fn () => QmScope::apply(Qm::t('qm_massnahmen as m')->leftJoin('objekte as o', 'o.obj_id', '=', 'm.obj_id'), $u, 'm.obj_id');

        $z = $base()->selectRaw("SUM(m.status = 'offen') AS offen, SUM(m.status = 'offen' AND m.frist < ?) AS ueberfaellig,
            SUM(m.status = 'erledigt') AS pruefung, SUM(m.status IN ('offen','erledigt') AND m.verantwortlich_id = ?) AS meine,
            SUM(m.status IN ('wirksam','unwirksam')) AS abgeschlossen, COUNT(*) AS alle", [$heute, $u->id])->first();

        $q = $base();
        match ((string) $request->query('tab', 'offen')) {
            'ueberfaellig'  => $q->where('m.status', 'offen')->where('m.frist', '<', $heute),
            'pruefung'      => $q->where('m.status', 'erledigt'),
            'meine'         => $q->whereIn('m.status', ['offen', 'erledigt'])->where('m.verantwortlich_id', $u->id),
            'abgeschlossen' => $q->whereIn('m.status', ['wirksam', 'unwirksam']),
            'alle'          => null,
            default         => $q->where('m.status', 'offen'),
        };
        if ($request->filled('quelle')) $q->where('m.quelle_typ', (string) $request->query('quelle'));
        if ($request->filled('obj_id')) $q->where('m.obj_id', (string) $request->query('obj_id'));
        if ($request->filled('q')) {
            $s = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->query('q'))) . '%';
            $q->where(fn ($w) => $w->where('m.nr', 'like', $s)->orWhere('m.titel', 'like', $s)->orWhere('o.objektname', 'like', $s));
        }
        $rows = $q->orderBy('m.frist')->orderByDesc('m.id')->limit(self::LIMIT + 1)->get(['m.*', 'o.objektname']);
        $users = Qm::userNames();
        return response()->json([
            'zaehler' => array_map('intval', (array) $z),
            'mehr' => $rows->count() > self::LIMIT,
            'data' => $rows->take(self::LIMIT)->map(fn ($m) => $this->zeile($m, $users))->values(),
        ]);
    }

    /** GET /api/qm/massnahmen/{id} */
    public function show(Request $request, int $id)
    {
        $m = Qm::t('qm_massnahmen as m')->leftJoin('objekte as o', 'o.obj_id', '=', 'm.obj_id')->where('m.id', $id)->first(['m.*', 'o.objektname']);
        if (!$m || !$this->sichtbar($request->user(), $m)) return response()->json(['error' => 'Maßnahme nicht gefunden'], 404);
        $users = Qm::userNames();
        $quelle = null;
        if ($m->quelle_typ === 'reklamation' && $m->quelle_id) $quelle = Qm::t('qm_reklamationen')->where('id', (int) $m->quelle_id)->first(['id', 'nr', 'text']);
        if ($m->quelle_typ === 'wachbuch' && $m->quelle_id) $quelle = Qm::t('qm_wb_eintraege')->where('id', (int) $m->quelle_id)->first(['id', 'nr', 'art', 'text', 'zeit']);
        return response()->json($this->zeile($m, $users) + [
            'ursache' => $m->ursache, 'text' => $m->text, 'nachweis' => $m->nachweis,
            'erledigt_am' => $m->erledigt_am, 'erledigt_von' => $m->erledigt_von ? ($users[(int) $m->erledigt_von] ?? null) : null,
            'wirksamkeit_text' => $m->wirksamkeit_text, 'wirksamkeit_am' => $m->wirksamkeit_am,
            'wirksamkeit_von' => $m->wirksamkeit_von ? ($users[(int) $m->wirksamkeit_von] ?? null) : null,
            'folge' => $m->folge_id ? Qm::t('qm_massnahmen')->where('id', $m->folge_id)->first(['id', 'nr', 'titel', 'status']) : null,
            'quelle' => $quelle,
            'verlauf' => Qm::verlauf('massnahme', $id),
        ]);
    }

    /** POST /api/qm/massnahmen */
    public function store(Request $request)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->rechtOderFehler($u, 'massnahme_bearbeiten')) return $e;
        [$d, $err] = $this->pruefe($request->all(), true);
        if ($err) return Qm::fehler($err);
        if (!empty($d['obj_id']) && ($e = $this->objektOderFehler($u, $d['obj_id']))) return $e;
        if (empty($d['obj_id']) && !QmScope::alleObjekte($u)) return Qm::fehler(['obj_id' => ['Bitte ein Objekt wählen.']]);
        if ($e = $this->quelleOderFehler($u, $d)) return $e;
        $m = MassnahmeService::anlegen($d, $u);
        return response()->json(['success' => true, 'id' => (int) $m->id, 'nr' => $m->nr]);
    }

    /** PUT /api/qm/massnahmen/{id} */
    public function update(Request $request, int $id)
    {
        $u = $request->user();
        [$m, $e] = $this->laden($u, $id, 'massnahme_bearbeiten');
        if ($e) return $e;
        if (!in_array($m->status, ['offen', 'erledigt'], true)) return response()->json(['error' => 'Abgeschlossene Maßnahmen können nicht mehr geändert werden.'], 409);
        [$d, $err] = $this->pruefe($request->all(), false);
        if ($err) return Qm::fehler($err);
        if (!empty($d['obj_id']) && ($e = $this->objektOderFehler($u, $d['obj_id']))) return $e;
        MassnahmeService::bearbeiten($m, $d, $u);
        return response()->json(['success' => true]);
    }

    /** POST /api/qm/massnahmen/{id}/verlaengern {frist, grund} */
    public function verlaengern(Request $request, int $id)
    {
        $u = $request->user();
        [$m, $e] = $this->laden($u, $id, 'massnahme_bearbeiten');
        if ($e) return $e;
        [$d, $err] = Qm::pruefe($request->all(), ['frist' => 'required|date_format:Y-m-d', 'grund' => 'required|string|max:1000']);
        if ($err) return Qm::fehler($err);
        if ($msg = MassnahmeService::verlaengern($m, $d['frist'], $d['grund'], $u)) return response()->json(['error' => $msg], 422);
        return response()->json(['success' => true]);
    }

    /** POST /api/qm/massnahmen/{id}/erledigen {nachweis} */
    public function erledigen(Request $request, int $id)
    {
        $u = $request->user();
        [$m, $e] = $this->laden($u, $id, 'massnahme_bearbeiten');
        if ($e) return $e;
        [$d, $err] = Qm::pruefe($request->all(), ['nachweis' => 'required|string|max:4000']);
        if ($err) return Qm::fehler($err);
        if ($msg = MassnahmeService::erledigen($m, $d['nachweis'], $u)) return response()->json(['error' => $msg], 422);
        return response()->json(['success' => true]);
    }

    /** POST /api/qm/massnahmen/{id}/wirksamkeit {ergebnis, text, folge?{titel, frist, verantwortlich_id}} */
    public function wirksamkeit(Request $request, int $id)
    {
        $u = $request->user();
        [$m, $e] = $this->laden($u, $id, 'massnahme_wirksamkeit');
        if ($e) return $e;
        [$d, $err] = Qm::pruefe($request->all(), [
            'ergebnis' => 'required|in:wirksam,unwirksam', 'text' => 'required|string|max:4000',
            'folge' => 'nullable|array', 'folge.titel' => 'required_with:folge|string|max:200',
            'folge.frist' => 'required_with:folge|date_format:Y-m-d', 'folge.verantwortlich_id' => 'nullable|integer',
        ]);
        if ($err) return Qm::fehler($err);
        [$msg, $neu] = MassnahmeService::wirksamkeit($m, $d['ergebnis'], $d['text'], $u, $d['ergebnis'] === 'unwirksam' ? ($d['folge'] ?? null) : null);
        if ($msg) return response()->json(['error' => $msg], 422);
        return response()->json(['success' => true, 'folge_id' => $neu ? (int) $neu->id : null]);
    }

    // ---------------------------------------------------------------

    private function pruefe(array $in, bool $neu): array
    {
        $req = $neu ? 'required' : 'sometimes';
        [$d, $err] = Qm::pruefe($in, [
            'titel' => "$req|string|max:200", 'text' => 'sometimes|nullable|string|max:4000', 'ursache' => 'sometimes|nullable|string|max:4000',
            'art' => "$req|in:" . implode(',', array_keys(MassnahmeService::ARTEN)),
            'obj_id' => 'sometimes|nullable|string|max:20', 'verantwortlich_id' => "$req|integer",
            'frist' => ($neu ? 'required' : 'prohibited') . '|date_format:Y-m-d',
            'im_kundenportal' => 'sometimes|boolean',
            'quelle_typ' => ($neu ? 'sometimes' : 'prohibited') . '|in:' . implode(',', array_keys(MassnahmeService::QUELLEN)),
            'quelle_id' => ($neu ? 'sometimes' : 'prohibited') . '|nullable|string|max:40',
        ]);
        if ($err) return [null, $err];
        if (isset($d['verantwortlich_id']) && !isset(Qm::userNames()[(int) $d['verantwortlich_id']])) {
            return [null, ['verantwortlich_id' => ['Bitte einen aktiven Benutzer wählen.']]];
        }
        if ($neu && $d['frist'] < Qm::heute()) return [null, ['frist' => ['Die Frist darf nicht in der Vergangenheit liegen.']]];
        return [$d, []];
    }

    /** A source record must exist and lie in the user's scope. */
    private function quelleOderFehler($u, array &$d)
    {
        $typ = $d['quelle_typ'] ?? 'manuell';
        if (!in_array($typ, ['reklamation', 'wachbuch'], true) || empty($d['quelle_id'])) return null;
        $q = $typ === 'reklamation' ? Qm::t('qm_reklamationen')->find((int) $d['quelle_id']) : Qm::t('qm_wb_eintraege')->find((int) $d['quelle_id']);
        if (!$q || !QmScope::allowsObjekt($u, $q->obj_id)) return response()->json(['error' => 'Quelle nicht gefunden'], 404);
        $d['obj_id'] = $d['obj_id'] ?? $q->obj_id;
        return null;
    }

    private function sichtbar($u, object $m): bool
    {
        return $m->obj_id ? QmScope::allowsObjekt($u, $m->obj_id) : QmScope::alleObjekte($u);
    }

    private function laden($u, int $id, string $recht): array
    {
        $m = Qm::t('qm_massnahmen')->find($id);
        if (!$m || !$this->sichtbar($u, $m)) return [null, response()->json(['error' => 'Maßnahme nicht gefunden'], 404)];
        if ($e = $this->rechtOderFehler($u, $recht)) return [null, $e];
        return [$m, null];
    }

    private function zeile(object $m, array $users): array
    {
        return [
            'id' => (int) $m->id, 'nr' => $m->nr, 'titel' => $m->titel, 'art' => $m->art,
            'quelle_typ' => $m->quelle_typ, 'quelle_id' => $m->quelle_id, 'obj_id' => $m->obj_id, 'objektname' => $m->objektname ?? null,
            'verantwortlich_id' => $m->verantwortlich_id ? (int) $m->verantwortlich_id : null,
            'verantwortlich' => $m->verantwortlich_id ? ($users[(int) $m->verantwortlich_id] ?? null) : null,
            'frist' => substr((string) $m->frist, 0, 10), 'frist_verlaengert' => (int) $m->frist_verlaengert,
            'status' => $m->status, 'wirksam_pruefung_am' => $m->wirksam_pruefung_am ? substr((string) $m->wirksam_pruefung_am, 0, 10) : null,
            'im_kundenportal' => (bool) $m->im_kundenportal, 'created_at' => $m->created_at,
        ] + MassnahmeService::flags($m);
    }
}
