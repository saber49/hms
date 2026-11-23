<?php

namespace App\Http\Controllers\Api\Qm\KundenPortal;

use App\Http\Controllers\Api\Qm\Wachbuch\WachbuchblattController;
use App\Http\Controllers\Controller;
use App\Services\Qm\DienstanweisungService;
use App\Services\Qm\MassnahmeService;
use App\Services\Qm\QmNotify;
use App\Services\Qm\ReklamationService;
use App\Services\Qm\WachbuchPdf;
use App\Services\Qm\WachbuchService;
use App\Services\Qm\WachbuchTagesbericht;
use App\Services\Qm\WachbuchTagesberichtPdf;
use App\Support\Qm\Qm;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Kundenportal — QM sections (spec §6.3): Wachbuch, Dienstanweisungen,
 * Reklamationen, freigegebene Maßnahmen. Built ONLY from released fields
 * (whitelist), never by hiding internal ones in the browser.
 */
class PortalQmController extends Controller
{
    /** Arten the customer sees in the Wachbuch. */
    private const WB_ARTEN = ['dienstbeginn', 'dienstende', 'notiz', 'vorkommnis', 'alarm', 'besucher', 'lieferung', 'schluessel', 'foto', 'hinweis', 'berichtigung'];

    /** GET /api/kunden-portal/wachbuch?datum=&obj_id= — one Wachbuch day (06:00–06:00) of own objects. */
    public function wachbuch(Request $request)
    {
        $objIds = PortalController::objekteFuer($request);
        $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('datum')) ? $request->query('datum') : Qm::wachbuchTag();
        if (!Qm::ready() || !Qm::boolSetting('modul_wachbuch')) return response()->json(['datum' => $datum, 'eintraege' => [], 'status' => []]);
        $cfgRows = Qm::t('qm_objekt_einstellungen')->whereIn('obj_id', $objIds)->where('wachbuch_aktiv', 1)
            ->get(['obj_id', 'kunde_pdf', 'bericht_pflicht', 'bericht_ab']);
        $aktiv = $cfgRows->pluck('obj_id')->all();
        $signiert = Qm::t('qm_wb_berichte')->whereIn('obj_id', $aktiv)->where('datum', $datum)->pluck('obj_id')->all();
        $wartet = $cfgRows->mapWithKeys(fn ($c) => [(string) $c->obj_id => (bool) $c->kunde_pdf
            && WachbuchTagesbericht::aktivFuerTag($c, $datum) && !in_array($c->obj_id, $signiert, true)]);
        $pdfErlaubt = $cfgRows->mapWithKeys(fn ($c) => [(string) $c->obj_id => (bool) $c->kunde_pdf
            && (!WachbuchTagesbericht::aktivFuerTag($c, $datum) || in_array($c->obj_id, $signiert, true))]);
        $blaetter = Qm::t('qm_wb_blaetter')->whereIn('obj_id', $aktiv)->where('datum', $datum)->pluck('id')->all();
        $namen = Qm::t('objekte')->whereIn('obj_id', $aktiv)->pluck('objektname', 'obj_id');
        $eintraege = collect(WachbuchService::eintraege($blaetter))->whereIn('art', self::WB_ARTEN)->map(fn ($e) => [
            'id' => $e['id'], 'obj_id' => $e['obj_id'],
            'art' => $e['art'], 'art_label' => $e['art_label'], 'zeit' => $e['zeit'], 'ort' => $e['ort'], 'text' => $e['text'],
            'details' => $this->kundenDetails($e['art'], (array) $e['details']), 'wichtig' => $e['wichtig'],
            'ref_id' => $e['ref_id'], 'berichtigt_durch' => $e['berichtigt_durch'], 'erfasst_von' => $e['erfasst_von'],
            'nachgereicht' => $e['nachgereicht'], 'fotos' => collect($e['fotos'])->map(fn ($f) => ['id' => $f['id'], 'aufgenommen_am' => $f['aufgenommen_am'], 'lat' => $f['lat'], 'lng' => $f['lng']])->all(),
        ])->values();
        $imDienst = Qm::t('zeiterfassung as z')->join('einsaetze as e', 'e.einsatz_id', '=', 'z.einsatz_id')
            ->whereIn('e.obj_id', $aktiv)->whereIn('z.status', ['aktiv', 'pause'])
            ->selectRaw('e.obj_id, COUNT(*) AS n, MIN(z.clock_in) AS seit')->groupBy('e.obj_id')->get()->keyBy('obj_id');
        $vk7 = Qm::t('qm_wb_eintraege')->whereIn('obj_id', $aktiv)->whereIn('art', ['vorkommnis', 'alarm'])->where('zeit', '>=', now()->subDays(7))
            ->selectRaw('obj_id, COUNT(*) AS n')->groupBy('obj_id')->pluck('n', 'obj_id');
        return response()->json([
            'datum' => $datum,
            'status' => collect($aktiv)->map(fn ($o) => [
                'obj_id' => $o, 'objektname' => $namen[$o] ?? $o, 'bewacht' => isset($imDienst[$o]),
                'wachpersonen' => (int) ($imDienst[$o]->n ?? 0), 'seit' => isset($imDienst[$o]) ? Carbon::parse($imDienst[$o]->seit)->toIso8601String() : null,
                'vorkommnisse_7_tage' => (int) ($vk7[$o] ?? 0),
                'pdf_erlaubt' => (bool) ($pdfErlaubt[$o] ?? false),
                'bericht_wartet' => (bool) ($wartet[$o] ?? false),
            ])->values(),
            'eintraege' => $eintraege,
        ]);
    }

    /** GET /api/kunden-portal/wachbuch/pdf?obj_id=&datum= — customer day report (needs the Objekt's "kunde_pdf" switch). */
    public function wachbuchPdf(Request $request)
    {
        $objId = (string) $request->query('obj_id');
        $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('datum')) ? $request->query('datum') : Qm::wachbuchTag();
        $cfg = Qm::objektEinstellung($objId);
        if (!in_array($objId, $request->attributes->get('kp_objekte') ?: [], true)
            || !WachbuchService::aktiv($objId)
            || !$cfg->kunde_pdf) {
            return response()->json(['error' => 'PDF ist für dieses Objekt nicht freigegeben.'], 404);
        }
        if (WachbuchTagesbericht::aktivFuerTag($cfg, $datum)) {
            $row = WachbuchTagesbericht::letzter($objId, $datum);
            if (!$row) return response()->json(['error' => 'Tagesbericht noch nicht freigegeben.'], 404);
            return WachbuchTagesberichtPdf::download($objId, $datum, true, $row);
        }
        return WachbuchPdf::download($objId, $datum, true);
    }

    /** Only a signed, explicitly customer-filtered daily report is available here. */
    public function wachbuchBericht(Request $request)
    {
        $objId = (string) $request->query('obj_id');
        $datum = (string) $request->query('datum');
        if (!in_array($objId, $request->attributes->get('kp_objekte') ?: [], true)
            || !WachbuchService::aktiv($objId) || !Qm::objektEinstellung($objId)->kunde_pdf
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) abort(404);
        $row = WachbuchTagesbericht::letzter($objId, $datum);
        if (!$row) abort(404);
        try {
            return response()->json(['version' => $row->version, 'pruefsumme' => $row->pruefsumme,
                'daten' => WachbuchTagesbericht::kundenSicht(WachbuchTagesbericht::snapshot($row))]);
        } catch (\DomainException $e) {
            return response()->json(['error' => 'Bericht konnte nicht geprüft werden.'], 409);
        }
    }

    /** GET /api/kunden-portal/wachbuch/fotos/{id} */
    public function wachbuchFoto(Request $request, int $id)
    {
        $f = Qm::t('qm_wb_fotos as f')->join('qm_wb_eintraege as w', 'w.id', '=', 'f.eintrag_id')->where('f.id', $id)->first(['f.pfad', 'w.obj_id', 'w.art']);
        if (!$f || !in_array($f->obj_id, $request->attributes->get('kp_objekte') ?: [], true) || !in_array($f->art, self::WB_ARTEN, true)
            || !WachbuchService::aktiv((string) $f->obj_id)) abort(404);
        return WachbuchblattController::fotoResponse($f->pfad);
    }

    /** POST /api/kunden-portal/wachbuch/hinweis {obj_id, text} — goes live to the guard on duty. */
    public function hinweis(Request $request)
    {
        $objId = (string) $request->input('obj_id');
        $text = trim(mb_substr((string) $request->input('text'), 0, 1000));
        if (!in_array($objId, $request->attributes->get('kp_objekte') ?: [], true) || !WachbuchService::aktiv($objId)) return response()->json(['error' => 'Objekt nicht gefunden'], 404);
        if ($text === '') return Qm::fehler(['text' => ['Bitte einen Hinweis eingeben.']]);
        $z = $request->attributes->get('kp_zugang');
        $laufend = Qm::t('qm_wb_blaetter as b')->join('zeiterfassung as zf', fn ($j) => $j->on('zf.einsatz_id', '=', 'b.einsatz_id')->on('zf.dienst_nr', '=', 'b.dienst_nr'))
            ->where('b.obj_id', $objId)->whereNull('b.geschlossen_am')->whereIn('zf.status', ['aktiv', 'pause'])->get(['b.*']);
        $ziele = $laufend->isNotEmpty() ? $laufend : collect([WachbuchService::bueroBlatt($objId, Qm::wachbuchTag())]);
        foreach ($ziele as $b) {
            WachbuchService::append($b, ['art' => 'hinweis', 'text' => $text, 'details' => ['von' => 'kunde']],
                ['quelle' => 'kunde', 'erfasst_von' => ($z->name ?: $z->username) . ' (Kunde)']);
            if ($b->dienst_nr) QmNotify::worker($b->dienst_nr, 'hinweis', 'Hinweis vom Kunden', $text, [], 'objekt', $objId);
        }
        QmNotify::users(QmNotify::objektleitung($objId), 'portal.hinweis', 'Hinweis vom Kunden', $text, '#qm_wachbuch', 'objekt', $objId);
        return response()->json(['success' => true, 'zugestellt' => $laufend->count()]);
    }

    /** GET /api/kunden-portal/dienstanweisungen */
    public function dienstanweisungen(Request $request)
    {
        if (!$this->portalAktiv('portal_dienstanweisungen')) return response()->json(['dienstanweisungen' => []]);
        $z = $request->attributes->get('kp_zugang');
        $das = Qm::t('qm_dienstanweisungen as d')->join('objekte as o', 'o.obj_id', '=', 'd.obj_id')
            ->whereIn('d.obj_id', $request->attributes->get('kp_objekte') ?: ['__none__'])->where('d.status', 'freigegeben')
            ->where('d.gueltig_ab', '<=', Qm::heute())
            ->orderBy('o.objektname')->orderByDesc('d.version')->get(['d.*', 'o.objektname'])
            ->sortByDesc(fn ($d) => DienstanweisungService::rang($d))->unique('obj_id')->sortBy('objektname')->values();
        $kunde = Qm::t('qm_da_kunde')->whereIn('dienstanweisung_id', $das->pluck('id'))->orderBy('id')->get()->groupBy('dienstanweisung_id');
        return response()->json(['dienstanweisungen' => $das->map(fn ($d) => [
            'id' => (int) $d->id, 'obj_id' => $d->obj_id, 'objektname' => $d->objektname, 'version' => (int) $d->version,
            'gueltig_ab' => substr((string) $d->gueltig_ab, 0, 10), 'kurz' => json_decode($d->kurz, true) ?: [], 'seiten' => (int) $d->seiten,
            'bestaetigt' => collect($kunde[$d->id] ?? [])->where('aktion', 'bestaetigt')->isNotEmpty(),
            'meine' => collect($kunde[$d->id] ?? [])->where('zugang_id', $z->id)->map(fn ($k) => ['aktion' => $k->aktion, 'text' => $k->text, 'am' => $k->created_at])->values(),
        ])->values()]);
    }

    /** GET /api/kunden-portal/dienstanweisungen/{id}/pdf */
    public function dienstanweisungPdf(Request $request, int $id)
    {
        $da = $this->da($request, $id);
        return DienstanweisungService::pdfResponse($da);
    }

    /** POST /api/kunden-portal/dienstanweisungen/{id} {aktion: bestaetigt|aenderung, text} */
    public function dienstanweisungAktion(Request $request, int $id)
    {
        $da = $this->da($request, $id);
        $z = $request->attributes->get('kp_zugang');
        $aktion = $request->input('aktion') === 'aenderung' ? 'aenderung' : 'bestaetigt';
        $text = trim(mb_substr((string) $request->input('text'), 0, 2000));
        if ($aktion === 'aenderung' && $text === '') return Qm::fehler(['text' => ['Bitte beschreiben Sie die gewünschte Änderung.']]);
        Qm::t('qm_da_kunde')->insert(['dienstanweisung_id' => $da->id, 'zugang_id' => $z->id, 'aktion' => $aktion, 'text' => $text ?: null, 'created_at' => now()]);
        Qm::log('dienstanweisung', $da->id, 'kunde_' . $aktion, null, ['zugang' => $z->username], null, $text ?: null);
        if ($aktion === 'aenderung') {
            $obj = Qm::t('objekte')->where('obj_id', $da->obj_id)->value('objektname') ?? $da->obj_id;
            QmNotify::users(QmNotify::objektleitung($da->obj_id), 'dienstanweisung.aenderung', 'Änderungswunsch zur Dienstanweisung: ' . $obj,
                $text . ' — ' . ($z->name ?: $z->username), '#qm?view=objekte', 'dienstanweisung', $da->id);
        }
        Qm::changed('dienstanweisung', (string) $da->obj_id);
        return response()->json(['success' => true]);
    }

    /** GET /api/kunden-portal/reklamationen */
    public function reklamationen(Request $request)
    {
        if (!$this->portalAktiv('portal_reklamationen')) return response()->json(['reklamationen' => []]);
        $rows = Qm::t('qm_reklamationen')->where('kunde_id', $request->attributes->get('kunde_id'))
            ->whereIn('obj_id', $request->attributes->get('kp_objekte') ?: ['__none__'])
            ->orderByDesc('eingang_am')->limit(300)->get();
        $namen = Qm::t('objekte')->whereIn('obj_id', $rows->pluck('obj_id')->unique())->pluck('objektname', 'obj_id');
        return response()->json(['reklamationen' => $rows->map(fn ($r) => ReklamationService::kundenFassung($r) + ['objektname' => $namen[$r->obj_id] ?? $r->obj_id])->values()]);
    }

    /** POST /api/kunden-portal/reklamationen {obj_id, text} */
    public function reklamationMelden(Request $request)
    {
        if (!$this->portalAktiv('portal_reklamationen')) return response()->json(['error' => 'Nicht verfügbar'], 409);
        $objId = (string) $request->input('obj_id');
        if (!in_array($objId, $request->attributes->get('kp_objekte') ?: [], true)) return response()->json(['error' => 'Objekt nicht gefunden'], 404);
        [$d, $err] = Qm::pruefe($request->all(), ['obj_id' => 'required|string', 'text' => 'required|string|min:5|max:5000']);
        if ($err) return Qm::fehler($err);
        $r = ReklamationService::anlegen(['obj_id' => $objId, 'text' => $d['text'], 'schwere' => 'mittel'], null, $request->attributes->get('kp_zugang'));
        return response()->json(['success' => true, 'nr' => $r->nr]);
    }

    /** GET /api/kunden-portal/massnahmen — only those released for the portal, without cause and names. */
    public function massnahmen(Request $request)
    {
        if (!$this->portalAktiv('portal_reklamationen')) return response()->json(['massnahmen' => []]);
        $rows = Qm::t('qm_massnahmen as m')->join('objekte as o', 'o.obj_id', '=', 'm.obj_id')
            ->whereIn('m.obj_id', $request->attributes->get('kp_objekte') ?: ['__none__'])->where('m.im_kundenportal', 1)
            ->orderByDesc('m.created_at')->limit(300)->get(['m.id', 'm.nr', 'm.titel', 'm.status', 'm.frist', 'm.erledigt_am', 'm.obj_id', 'o.objektname']);
        return response()->json(['massnahmen' => $rows->map(fn ($m) => [
            'nr' => $m->nr, 'titel' => $m->titel, 'objektname' => $m->objektname,
            // Overdue dates stay internal — the customer sees "in Planung".
            'status' => $m->status === 'offen' ? 'In Planung' : ($m->status === 'erledigt' ? 'Umgesetzt' : (MassnahmeService::STATUS[$m->status] ?? $m->status)),
            'umgesetzt_am' => $m->erledigt_am,
        ])->values()]);
    }

    private function da(Request $request, int $id): object
    {
        $da = $this->portalAktiv('portal_dienstanweisungen') ? Qm::t('qm_dienstanweisungen')->find($id) : null;
        if (!$da || $da->status !== 'freigegeben' || substr((string) $da->gueltig_ab, 0, 10) > Qm::heute()
            || !in_array($da->obj_id, $request->attributes->get('kp_objekte') ?: [], true)) abort(404);
        return $da;
    }

    /** Enforce each portal section on the server, including its direct PDF/action routes. */
    private function portalAktiv(string $key): bool
    {
        return Qm::ready() && Qm::boolSetting($key);
    }

    /** Details whitelist per Art (no ID-card checks or internal approvals). */
    private function kundenDetails(string $art, array $d): array
    {
        return WachbuchPdf::kundenDetails($art, $d);
    }
}
