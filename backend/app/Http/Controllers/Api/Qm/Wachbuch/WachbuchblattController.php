<?php

namespace App\Http\Controllers\Api\Qm\Wachbuch;

use App\Http\Controllers\Api\Qm\Concerns\QmGuards;
use App\Http\Controllers\Controller;
use App\Services\Qm\DienstanweisungService;
use App\Services\Qm\WachbuchPdf;
use App\Services\Qm\WachbuchService;
use App\Services\Qm\WachbuchTagesbericht;
use App\Services\Qm\WachbuchTagesberichtPdf;
use App\Support\Qm\Qm;
use App\Support\Qm\QmRechte;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Wachbuch › Wachbuchblätter (spec §6.11): per Objekt, grouped by month and
 * day (newest first); a day expands to its full log (all sheets of that day).
 * Office may add entries and corrections, countersign, close incidents.
 */
class WachbuchblattController extends Controller
{
    use QmGuards;

    /** GET /api/qm/wachbuch/objekte/{objId} — Steckbrief + months with counts. */
    public function objekt(Request $request, string $objId)
    {
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($request->user(), $objId)) return $e;
        $o = Qm::t('objekte as o')->leftJoin('kunden as k', 'k.kunde_id', '=', 'o.kunden_id')
            ->where('o.obj_id', $objId)->first(['o.obj_id', 'o.objektname', 'o.adresse', 'o.plz', 'o.stadt', 'o.ansprechpartner', 'o.kontakt_telefon', 'k.kundenname']);
        $cfg = Qm::objektEinstellung($objId);
        $da = DienstanweisungService::aktuelle($objId);
        $monate = Qm::t('qm_wb_blaetter as b')->leftJoin('qm_wb_eintraege as w', 'w.blatt_id', '=', 'b.id')
            ->where('b.obj_id', $objId)
            ->selectRaw("DATE_FORMAT(b.datum, '%Y-%m') AS monat, COUNT(DISTINCT b.datum) AS tage, COUNT(w.id) AS eintraege,
                SUM(w.art IN ('vorkommnis','alarm')) AS vorkommnisse")
            ->groupBy('monat')->orderByDesc('monat')->limit(36)->get();
        return response()->json([
            'objekt' => $o, 'einstellung' => $cfg,
            'objektleiter' => $cfg->objektleiter_id ? (Qm::userNames()[(int) $cfg->objektleiter_id] ?? null) : null,
            'dienstanweisung' => $da ? DienstanweisungService::format($da) : null,
            'monate' => $monate,
        ]);
    }

    /** GET /api/qm/wachbuch/objekte/{objId}/tage?monat=YYYY-MM — one row per day. */
    public function tage(Request $request, string $objId)
    {
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($request->user(), $objId)) return $e;
        $monat = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('monat')) ? $request->query('monat') : Carbon::now('Europe/Berlin')->format('Y-m');
        $von = $monat . '-01';
        $bis = Carbon::parse($von)->endOfMonth()->toDateString();

        $blaetter = Qm::t('qm_wb_blaetter')->where('obj_id', $objId)->whereBetween('datum', [$von, $bis])->get();
        $zahlen = Qm::t('qm_wb_eintraege as w')->whereIn('w.blatt_id', $blaetter->pluck('id'))
            ->leftJoin('qm_wb_fotos as f', 'f.eintrag_id', '=', 'w.id')
            ->selectRaw("w.blatt_id, COUNT(DISTINCT w.id) AS n, COUNT(f.id) AS fotos,
                COUNT(DISTINCT CASE WHEN w.art IN ('vorkommnis','alarm') THEN w.id END) AS vk")
            ->groupBy('w.blatt_id')->get()->keyBy('blatt_id');
        $namen = Qm::t('mitarbeiter')->whereIn('dienst_nr', $blaetter->pluck('dienst_nr')->filter()->unique())
            ->get(['dienst_nr', 'vorname', 'name'])->mapWithKeys(fn ($m) => [$m->dienst_nr => trim($m->vorname . ' ' . $m->name)]);

        $tage = $blaetter->groupBy(fn ($b) => substr((string) $b->datum, 0, 10))->map(function ($bl, $datum) use ($zahlen, $namen) {
            $schicht = $bl->whereNotNull('einsatz_id');
            $status = $schicht->contains(fn ($b) => !$b->geschlossen_am) ? 'laeuft'
                : ($bl->every(fn ($b) => $b->gegengezeichnet_am) ? 'gegengezeichnet' : 'abgeschlossen');
            return [
                'datum' => $datum,
                'wachpersonen' => $schicht->pluck('dienst_nr')->map(fn ($d) => $namen[$d] ?? $d)->unique()->values(),
                'eintraege' => $bl->sum(fn ($b) => (int) ($zahlen[$b->id]->n ?? 0)),
                'fotos' => $bl->sum(fn ($b) => (int) ($zahlen[$b->id]->fotos ?? 0)),
                'vorkommnisse' => $bl->sum(fn ($b) => (int) ($zahlen[$b->id]->vk ?? 0)),
                'status' => $status,
            ];
        })->sortKeysDesc()->values();
        return response()->json(['monat' => $monat, 'tage' => $tage]);
    }

    /** GET /api/qm/wachbuch/objekte/{objId}/tag/{datum} — complete log of one day. */
    public function tag(Request $request, string $objId, string $datum)
    {
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($request->user(), $objId)) return $e;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) return response()->json(['error' => 'Ungültiges Datum'], 422);
        $blaetter = Qm::t('qm_wb_blaetter')->where('obj_id', $objId)->where('datum', $datum)->orderBy('geoeffnet_am')->get();
        $einsaetze = Qm::t('einsaetze')->whereIn('einsatz_id', $blaetter->pluck('einsatz_id')->filter())->get(['einsatz_id', 'ist_start', 'ist_ende'])->keyBy('einsatz_id');
        $users = Qm::userNames();
        // Countersignatures withdrawn by a later entry (see WachbuchService::append).
        $aufgehoben = Qm::t('qm_verlauf')->where('tabelle', 'wachbuch_blatt')->where('aktion', 'gegenzeichnung_aufgehoben')
            ->whereIn('datensatz_id', $blaetter->pluck('id')->map(fn ($i) => (string) $i))->orderBy('id')->pluck('zeit', 'datensatz_id');
        return response()->json([
            'datum' => $datum,
            'blaetter' => $blaetter->map(fn ($b) => [
                'id' => (int) $b->id, 'dienst_nr' => $b->dienst_nr, 'name' => $b->dienst_nr ? Qm::workerName($b->dienst_nr) : 'Büro',
                'plan_von' => isset($einsaetze[$b->einsatz_id]) ? substr((string) $einsaetze[$b->einsatz_id]->ist_start, 0, 5) : null,
                'plan_bis' => isset($einsaetze[$b->einsatz_id]) ? substr((string) $einsaetze[$b->einsatz_id]->ist_ende, 0, 5) : null,
                'geoeffnet_am' => $b->geoeffnet_am, 'geschlossen_am' => $b->geschlossen_am,
                'hinweis_nachfolger' => $b->hinweis_nachfolger,
                'gegengezeichnet_am' => $b->gegengezeichnet_am,
                'gegengezeichnet_von' => $b->gegengezeichnet_von ? ($users[(int) $b->gegengezeichnet_von] ?? null) : null,
                'gegenzeichnung_aufgehoben_am' => $b->gegengezeichnet_am ? null : ($aufgehoben[(string) $b->id] ?? null),
                'kette_ok' => WachbuchService::pruefeKette((int) $b->id, $b) === null,
                // The office sheet of a day stays open until the Wachbuch day is over.
                'laeuft' => $b->einsatz_id ? !$b->geschlossen_am : substr((string) $b->datum, 0, 10) >= Qm::wachbuchTag(),
            ])->values(),
            'fotopflicht' => (bool) Qm::objektEinstellung($objId)->fotopflicht,
            'eintraege' => WachbuchService::eintraege($blaetter->pluck('id')->all()),
        ]);
    }

    /** GET /api/qm/wachbuch/objekte/{objId}/tag/{datum}/pdf — office day report. */
    public function pdf(Request $request, string $objId, string $datum)
    {
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($request->user(), $objId)) return $e;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) return response()->json(['error' => 'Ungültiges Datum'], 422);
        if (WachbuchTagesbericht::aktivFuerTag(Qm::objektEinstellung($objId), $datum)) {
            return WachbuchTagesberichtPdf::download($objId, $datum, false, WachbuchTagesbericht::letzter($objId, $datum));
        }
        return WachbuchPdf::download($objId, $datum, false);
    }

    /** GET /api/qm/wachbuch/objekte/{objId}/tag/{datum}/bericht */
    public function bericht(Request $request, string $objId, string $datum)
    {
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($request->user(), $objId)) return $e;
        try {
            $daten = WachbuchTagesbericht::entwurf($objId, $datum);
            $last = WachbuchTagesbericht::letzter($objId, $datum);
            return response()->json([
                'entwurf' => $daten,
                'freigegeben' => $last ? [
                    'version' => $last->version, 'am' => $last->freigegeben_am,
                    'pruefsumme' => $last->pruefsumme, 'daten' => WachbuchTagesbericht::snapshot($last),
                ] : null,
                'darf_freigeben' => QmRechte::darf($request->user(), 'wachbuch_gegenzeichnen'),
            ]);
        } catch (\InvalidArgumentException $ex) {
            return response()->json(['error' => $ex->getMessage()], 422);
        } catch (\DomainException $ex) {
            return response()->json(['error' => $ex->getMessage()], 409);
        }
    }

    /** POST /api/qm/wachbuch/objekte/{objId}/tag/{datum}/bericht/freigeben */
    public function berichtFreigeben(Request $request, string $objId, string $datum)
    {
        $u = $request->user();
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($u, $objId) ?? $this->rechtOderFehler($u, 'wachbuch_gegenzeichnen')) return $e;
        [$d, $err] = Qm::pruefe($request->all(), ['ausnahme' => 'nullable|string|max:2000']);
        if ($err) return Qm::fehler($err);
        try {
            $row = WachbuchTagesbericht::freigeben($objId, $datum, $u, $d['ausnahme'] ?? null);
            return response()->json(['success' => true, 'version' => $row->version, 'pruefsumme' => $row->pruefsumme]);
        } catch (\InvalidArgumentException $ex) {
            return response()->json(['error' => $ex->getMessage()], 422);
        } catch (\DomainException $ex) {
            return response()->json(['error' => $ex->getMessage()], 409);
        }
    }

    /** POST /api/qm/wachbuch/eintraege {obj_id, datum, art, text, …} — office entry or correction. */
    public function store(Request $request)
    {
        $u = $request->user();
        $objId = (string) $request->input('obj_id');
        if ($e = $this->nichtBereit() ?? $this->objektOderFehler($u, $objId) ?? $this->rechtOderFehler($u, 'wachbuch_eintrag')) return $e;
        [$d, $err] = WachbuchService::pruefeEintrag($request->all());
        if ($err) return Qm::fehler($err);
        // No date given = the running Wachbuch day (before tag_beginn that is still yesterday's).
        $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('datum')) ? $request->input('datum') : Qm::wachbuchTag();
        if ($datum > Qm::wachbuchTag()) return Qm::fehler(['datum' => ['Das Datum darf nicht in der Zukunft liegen.']]);
        // Switched off (company or Objekt) = no new entries. Corrections of
        // existing entries stay possible — the record must remain correctable.
        if (empty($d['ref_id']) && !WachbuchService::aktiv($objId)) {
            return response()->json(['error' => 'Das ' . \App\Support\TenantProfile::objektbuchLabel(\App\Services\TenantService::current())
                . ' ist für dieses Objekt nicht aktiv (Einstellungen › Qualität bzw. Objekte & Dienstanweisungen).'], 409);
        }

        // A correction goes onto the sheet of the corrected entry (same chain).
        $blatt = null;
        if (!empty($d['ref_id'])) {
            $ref = Qm::t('qm_wb_eintraege')->find((int) $d['ref_id']);
            if (!$ref || $ref->obj_id !== $objId || $ref->art === 'berichtigung') return response()->json(['error' => 'Eintrag nicht gefunden'], 404);
            $blatt = Qm::t('qm_wb_blaetter')->find($ref->blatt_id);
            if ($blatt && $blatt->gegengezeichnet_am) {
                $datum = substr((string) $blatt->datum, 0, 10);
                $blatt = WachbuchService::bueroBlatt($objId, $datum);
            }
        }
        $blatt = $blatt ?: WachbuchService::bueroBlatt($objId, $datum);
        if ($blatt->gegengezeichnet_am) return response()->json(['error' => 'Das Blatt ist gegengezeichnet und eingefroren.'], 409);
        [$d, $vorgangError] = WachbuchService::pruefeObjektEintrag($blatt, $d);
        if ($vorgangError) return Qm::fehler($vorgangError);
        try {
            $row = WachbuchService::append($blatt, $d, ['quelle' => 'buero', 'user_id' => $u->id, 'erfasst_von' => Qm::userName($u)]);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        return response()->json(['success' => true, 'eintrag' => WachbuchService::format($row)]);
    }

    /** POST /api/qm/wachbuch/blaetter/{id}/gegenzeichnen */
    public function gegenzeichnen(Request $request, int $id)
    {
        $u = $request->user();
        $b = Qm::t('qm_wb_blaetter')->find($id);
        if (!$b) return response()->json(['error' => 'Blatt nicht gefunden'], 404);
        if ($e = $this->objektOderFehler($u, $b->obj_id) ?? $this->rechtOderFehler($u, 'wachbuch_gegenzeichnen')) return $e;
        if ($b->einsatz_id && !$b->geschlossen_am) return response()->json(['error' => 'Die Schicht läuft noch — gegenzeichnen erst nach dem Dienstende.'], 409);
        if (!$b->einsatz_id && substr((string) $b->datum, 0, 10) >= Qm::wachbuchTag()) {
            return response()->json(['error' => 'Der Tag läuft noch — das Büro-Blatt kann erst nach Tagesende gegengezeichnet werden.'], 409);
        }
        if ($b->gegengezeichnet_am) return response()->json(['error' => 'Bereits gegengezeichnet.'], 409);
        if (($nr = WachbuchService::pruefeKette($id, $b)) !== null) {
            return response()->json(['error' => 'Die Prüfkette ist ab Eintrag ' . $nr . ' beschädigt. Gegenzeichnen nicht möglich.'], 409);
        }
        Qm::t('qm_wb_blaetter')->where('id', $id)->update(['gegengezeichnet_von' => $u->id, 'gegengezeichnet_am' => now(), 'updated_at' => now()]);
        Qm::log('wachbuch_blatt', $id, 'gegengezeichnet', null, null, $u);
        Qm::changed('wachbuch');
        return response()->json(['success' => true]);
    }

    /** POST /api/qm/wachbuch/eintraege/{id}/erledigt — close an incident in the Leitstand (metadata, not hashed). */
    public function erledigt(Request $request, int $id)
    {
        $u = $request->user();
        $r = Qm::t('qm_wb_eintraege')->find($id);
        if (!$r || !in_array($r->art, ['vorkommnis', 'alarm'], true)) return response()->json(['error' => 'Eintrag nicht gefunden'], 404);
        if ($e = $this->objektOderFehler($u, $r->obj_id) ?? $this->rechtOderFehler($u, 'wachbuch_eintrag')) return $e;
        if (Qm::t('qm_wb_blaetter')->where('id', $r->blatt_id)->value('gegengezeichnet_am')) {
            return response()->json(['error' => 'Das Blatt ist gegengezeichnet und eingefroren.'], 409);
        }
        $neu = $request->boolean('offen') ? 'offen' : 'erledigt';
        Qm::t('qm_wb_eintraege')->where('id', $id)->update(['status' => $neu, 'bearbeitet_von' => $u->id, 'bearbeitet_am' => now()]);
        Qm::log('wachbuch_eintrag', $id, 'status', ['status' => $r->status], ['status' => $neu], $u);
        Qm::changed('wachbuch');
        return response()->json(['success' => true, 'status' => $neu]);
    }

    /** GET /api/qm/wachbuch/fotos/{id} — image bytes (scope re-checked). */
    public function foto(Request $request, int $id)
    {
        $f = Qm::t('qm_wb_fotos as f')->join('qm_wb_eintraege as w', 'w.id', '=', 'f.eintrag_id')->where('f.id', $id)->first(['f.pfad', 'w.obj_id']);
        if (!$f || !\App\Support\Qm\QmScope::allowsObjekt($request->user(), $f->obj_id)) abort(404);
        return self::fotoResponse($f->pfad);
    }

    public static function fotoResponse(string $pfad)
    {
        $disk = Storage::disk('local');
        if (!$disk->exists($pfad)) abort(404);
        return response()->file($disk->path($pfad), ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
