<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantService;
use App\Support\BereichScope;
use App\Support\Branchen;
use App\Support\Checklisten;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Checklisten & Protokolle — office side.
 * Read: stammdaten.objekte.view · write: stammdaten.objekte.edit (routes).
 * Protocols follow the Objekt's Bereich scope; templates of a main Bereich
 * outside a restricted user's whitelist are hidden.
 */
class ChecklistenController extends Controller
{
    private function db()
    {
        return DB::connection('tenant');
    }

    private function notReady()
    {
        return Checklisten::ready() ? null : response()->json(['error' => 'Checklisten noch nicht verfügbar (Migration ausstehend)'], 409);
    }

    /** Main Bereiche the user may see: [id => name]. */
    private function mains($user): array
    {
        $allowed = BereichScope::for($user);
        $rows = $this->db()->table('bereiche_katalog')->whereNull('parent_id')->orderBy('sortierung')->orderBy('name')->get();
        $out = [];
        foreach ($rows as $r) {
            if (is_array($allowed) && !BereichScope::listAllows($allowed, $r->name)) continue;
            $out[(int) $r->id] = ['id' => (int) $r->id, 'name' => $r->name, 'branche' => $r->branche ?? null];
        }
        return $out;
    }

    private function vorlageVisible($user, $v): bool
    {
        return $v->bereich_id === null || BereichScope::for($user) === null || isset($this->mains($user)[(int) $v->bereich_id]);
    }

    private function findVorlage(Request $request, int $id)
    {
        $v = $this->db()->table('checklisten_vorlagen')->where('id', $id)->first();
        return ($v && $this->vorlageVisible($request->user(), $v)) ? $v : null;
    }

    // ---------------------------------------------------------------- Vorlagen

    /** GET /checklisten/vorlagen */
    public function vorlagen(Request $request)
    {
        if ($r = $this->notReady()) return $r;
        $user = $request->user();
        $mains = $this->mains($user);
        $zu = $this->db()->table('checklisten_zuordnungen')->selectRaw('vorlage_id, COUNT(*) n, SUM(pflicht) p')->groupBy('vorlage_id')->get()->keyBy('vorlage_id');
        $pr = $this->db()->table('checklisten_protokolle')->selectRaw('vorlage_id, COUNT(*) n, MAX(abgeschlossen_at) letzte')->groupBy('vorlage_id')->get()->keyBy('vorlage_id');
        $rows = $this->db()->table('checklisten_vorlagen')->orderByDesc('aktiv')->orderBy('kategorie')->orderBy('name')->get()
            ->filter(fn ($v) => $this->vorlageVisible($user, $v))
            ->map(function ($v) use ($zu, $pr, $mains) {
                $r = Checklisten::formatVorlage($v, false);
                $r['felder_anzahl'] = count(array_filter(json_decode($v->felder, true) ?: [], fn ($f) => ($f['typ'] ?? '') !== 'abschnitt'));
                $r['bereich_name'] = $v->bereich_id ? ($mains[(int) $v->bereich_id]['name'] ?? null) : null;
                $r['objekte_anzahl'] = (int) ($zu[$v->id]->n ?? 0);
                $r['pflicht_anzahl'] = (int) ($zu[$v->id]->p ?? 0);
                $r['protokolle_anzahl'] = (int) ($pr[$v->id]->n ?? 0);
                $r['letztes_protokoll'] = $pr[$v->id]->letzte ?? null;
                return $r;
            })->values();
        $pakete = [];
        foreach (Branchen::REGISTRY as $key => $b) {
            $namen = Checklisten::paketNamen($key);
            if ($namen) $pakete[] = ['key' => $key, 'label' => $b['label'], 'vorlagen' => $namen];
        }
        return response()->json([
            'vorlagen' => $rows,
            'bereiche' => array_values($mains),
            'kategorien' => Checklisten::KATEGORIEN,
            'pakete' => $pakete,
        ]);
    }

    /** GET /checklisten/vorlagen/{id} */
    public function vorlage(Request $request, int $id)
    {
        if ($r = $this->notReady()) return $r;
        $v = $this->findVorlage($request, $id);
        if (!$v) return response()->json(['error' => 'Vorlage nicht gefunden'], 404);
        return response()->json(Checklisten::formatVorlage($v));
    }

    private function vorlageInput(Request $request, $user): array|string
    {
        $name = mb_substr(trim(strip_tags((string) $request->input('name', ''))), 0, 190);
        if ($name === '') return 'Name ist erforderlich';
        $kat = (string) $request->input('kategorie', 'sonstiges');
        if (!isset(Checklisten::KATEGORIEN[$kat])) return 'Ungültige Kategorie';
        $sig = (string) $request->input('unterschrift', 'keine');
        if (!in_array($sig, Checklisten::UNTERSCHRIFT, true)) return 'Ungültige Unterschrift-Einstellung';
        $bid = $request->input('bereich_id');
        $bid = ($bid === null || $bid === '') ? null : (int) $bid;
        $mains = $this->mains($user);
        if ($bid !== null && !isset($mains[$bid])) return 'Bereich nicht gefunden';
        if ($bid === null && BereichScope::for($user) !== null) return 'Bitte einen Bereich wählen';
        $felder = Checklisten::cleanFelder($request->input('felder'));
        if (is_string($felder)) return $felder;
        return [
            'name' => $name,
            'beschreibung' => mb_substr(trim(strip_tags((string) $request->input('beschreibung', ''))), 0, 2000) ?: null,
            'kategorie' => $kat, 'bereich_id' => $bid,
            'alle_objekte' => $request->boolean('alle_objekte', true) ? 1 : 0,
            'felder' => json_encode($felder, JSON_UNESCAPED_UNICODE),
            'unterschrift' => $sig,
            'gps' => $request->boolean('gps', true) ? 1 : 0,
            'im_kundenportal' => $request->boolean('im_kundenportal', true) ? 1 : 0,
            'aktiv' => $request->boolean('aktiv', true) ? 1 : 0,
        ];
    }

    /** POST /checklisten/vorlagen */
    public function storeVorlage(Request $request)
    {
        if ($r = $this->notReady()) return $r;
        $data = $this->vorlageInput($request, $request->user());
        if (is_string($data)) return response()->json(['error' => $data], 422);
        $id = $this->db()->table('checklisten_vorlagen')->insertGetId($data + [
            'version' => 1, 'created_by' => optional($request->user())->email, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Helpers\Realtime::changed('checklisten');
        return response()->json(['success' => true, 'id' => $id], 201);
    }

    /** PUT /checklisten/vorlagen/{id} — changed fields bump the version (protocols keep their snapshot). */
    public function updateVorlage(Request $request, int $id)
    {
        if ($r = $this->notReady()) return $r;
        $v = $this->findVorlage($request, $id);
        if (!$v) return response()->json(['error' => 'Vorlage nicht gefunden'], 404);
        // Quick toggle: {aktiv} alone.
        if (array_keys($request->all()) === ['aktiv']) {
            $this->db()->table('checklisten_vorlagen')->where('id', $id)->update(['aktiv' => $request->boolean('aktiv') ? 1 : 0, 'updated_at' => now(), 'updated_by' => optional($request->user())->email]);
            \App\Helpers\Realtime::changed('checklisten');
            return response()->json(['success' => true]);
        }
        $data = $this->vorlageInput($request, $request->user());
        if (is_string($data)) return response()->json(['error' => $data], 422);
        $changed = json_encode(json_decode($v->felder, true)) !== json_encode(json_decode($data['felder'], true));
        $this->db()->table('checklisten_vorlagen')->where('id', $id)->update($data + [
            'version' => (int) $v->version + ($changed ? 1 : 0),
            'updated_by' => optional($request->user())->email, 'updated_at' => now(),
        ]);
        \App\Helpers\Realtime::changed('checklisten');
        return response()->json(['success' => true, 'version' => (int) $v->version + ($changed ? 1 : 0)]);
    }

    /** DELETE /checklisten/vorlagen/{id} — only without protocols (otherwise deactivate). */
    public function destroyVorlage(Request $request, int $id)
    {
        if ($r = $this->notReady()) return $r;
        $v = $this->findVorlage($request, $id);
        if (!$v) return response()->json(['error' => 'Vorlage nicht gefunden'], 404);
        $n = $this->db()->table('checklisten_protokolle')->where('vorlage_id', $id)->count();
        if ($n > 0) {
            return response()->json(['error' => "Es gibt bereits {$n} Protokoll" . ($n === 1 ? '' : 'e') . ' zu dieser Vorlage — bitte deaktivieren statt löschen'], 409);
        }
        $this->db()->table('checklisten_zuordnungen')->where('vorlage_id', $id)->delete();
        $this->db()->table('checklisten_vorlagen')->where('id', $id)->delete();
        \App\Helpers\Realtime::changed('checklisten');
        return response()->json(['success' => true]);
    }

    /** GET /checklisten/vorlagen/{id}/objekte — assignable Objekte (scope) + current assignment. */
    public function objekte(Request $request, int $id)
    {
        if ($r = $this->notReady()) return $r;
        $v = $this->findVorlage($request, $id);
        if (!$v) return response()->json(['error' => 'Vorlage nicht gefunden'], 404);
        [$sql, $p] = BereichScope::objektPredicate($request->user(), 'o');
        $objekte = $this->db()->table('objekte as o')->leftJoin('kunden as k', 'k.kunde_id', '=', 'o.kunden_id')
            ->whereRaw($sql, $p)->where(fn ($q) => $q->whereNull('o.aktiv')->orWhere('o.aktiv', 1))
            ->orderBy('k.kundenname')->orderBy('o.objektname')
            ->get(['o.obj_id', 'o.objektname', 'o.stadt', 'o.bereich', 'k.kundenname']);
        $zu = $this->db()->table('checklisten_zuordnungen')->where('vorlage_id', $id)->pluck('pflicht', 'obj_id');
        return response()->json(['objekte' => $objekte->map(fn ($o) => [
            'obj_id' => $o->obj_id, 'objektname' => $o->objektname, 'stadt' => $o->stadt, 'bereich' => $o->bereich,
            'kundenname' => $o->kundenname, 'zugeordnet' => $zu->has($o->obj_id), 'pflicht' => (bool) ($zu[$o->obj_id] ?? false),
        ])->values()]);
    }

    /** PUT /checklisten/vorlagen/{id}/objekte {objekte: [{obj_id, pflicht}]} — replaces the assignments the user can see. */
    public function saveObjekte(Request $request, int $id)
    {
        if ($r = $this->notReady()) return $r;
        $v = $this->findVorlage($request, $id);
        if (!$v) return response()->json(['error' => 'Vorlage nicht gefunden'], 404);
        $user = $request->user();
        [$sql, $p] = BereichScope::objektPredicate($user, 'o');
        $visible = $this->db()->table('objekte as o')->whereRaw($sql, $p)->pluck('o.obj_id')->flip();
        $want = [];
        foreach ((array) $request->input('objekte', []) as $o) {
            $oid = (string) ($o['obj_id'] ?? '');
            if ($oid !== '' && $visible->has($oid)) $want[$oid] = !empty($o['pflicht']) ? 1 : 0;
        }
        $this->db()->transaction(function () use ($id, $want, $visible) {
            $this->db()->table('checklisten_zuordnungen')->where('vorlage_id', $id)
                ->whereIn('obj_id', $visible->keys()->all())->delete();
            $now = now();
            foreach (array_chunk(array_map(fn ($oid, $pf) => ['vorlage_id' => $id, 'obj_id' => $oid, 'pflicht' => $pf, 'created_at' => $now, 'updated_at' => $now], array_keys($want), $want), 500) as $chunk) {
                $this->db()->table('checklisten_zuordnungen')->insert($chunk);
            }
        });
        \App\Helpers\Realtime::changed('checklisten');
        return response()->json(['success' => true, 'anzahl' => count($want)]);
    }

    /** POST /checklisten/vorlagen/paket {branche, bereich_id?} — install an industry's templates. */
    public function paket(Request $request)
    {
        if ($r = $this->notReady()) return $r;
        $key = (string) $request->input('branche', '');
        if (!Checklisten::paketVorlagen($key)) return response()->json(['error' => 'Für diese Branche gibt es keine Vorlagen'], 422);
        $bid = $request->input('bereich_id');
        $bid = ($bid === null || $bid === '') ? null : (int) $bid;
        if ($bid !== null && !isset($this->mains($request->user())[$bid])) return response()->json(['error' => 'Bereich nicht gefunden'], 404);
        if ($bid === null && BereichScope::for($request->user()) !== null) return response()->json(['error' => 'Bitte einen Bereich wählen'], 422);
        $n = Checklisten::installPaket($key, $bid, optional($request->user())->email);
        \App\Helpers\Realtime::changed('checklisten');
        return response()->json(['success' => true, 'angelegt' => $n]);
    }

    // ---------------------------------------------------------------- Protokolle

    private function protokollQuery($user)
    {
        [$sql, $p] = BereichScope::objektPredicate($user, 'o');
        return $this->db()->table('checklisten_protokolle as p')
            ->join('objekte as o', 'o.obj_id', '=', 'p.obj_id')
            ->leftJoin('kunden as k', 'k.kunde_id', '=', 'o.kunden_id')
            ->leftJoin('mitarbeiter as m', 'm.dienst_nr', '=', 'p.created_by_dienst_nr')
            ->whereRaw($sql, $p);
    }

    private function listRow($r): array
    {
        return [
            'id' => (int) $r->id, 'vorlage_id' => $r->vorlage_id ? (int) $r->vorlage_id : null, 'vorlage_name' => $r->vorlage_name,
            'kategorie' => $r->kategorie, 'kategorie_label' => Checklisten::KATEGORIEN[$r->kategorie] ?? $r->kategorie,
            'datum' => $r->datum, 'obj_id' => $r->obj_id, 'objektname' => $r->objektname, 'kundenname' => $r->kundenname,
            'einsatz_id' => $r->einsatz_id,
            'mitarbeiter' => trim(($r->m_vorname ?? '') . ' ' . ($r->m_name ?? '')) ?: ($r->created_by ?? null),
            'ergebnis_prozent' => $r->ergebnis_prozent !== null ? (float) $r->ergebnis_prozent : null,
            'maengel' => (int) $r->maengel, 'fotos' => (int) ($r->fotos ?? 0),
            'unterschrift' => (bool) ($r->unterschrift_kunde_pfad || $r->unterschrift_ma_pfad),
            'gps' => $r->gps_lat !== null, 'abgeschlossen_at' => $r->abgeschlossen_at,
        ];
    }

    /** GET /checklisten/protokolle?von&bis&obj_id&vorlage_id&kategorie&maengel=1&q */
    public function protokolle(Request $request)
    {
        if ($r = $this->notReady()) return $r;
        $von = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('von')) ? $request->query('von') : now('Europe/Berlin')->subDays(30)->toDateString();
        $bis = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('bis')) ? $request->query('bis') : now('Europe/Berlin')->toDateString();
        $q = $this->protokollQuery($request->user())->whereBetween('p.datum', [$von, $bis]);
        if ($v = $request->query('obj_id')) $q->where('p.obj_id', $v);
        if ($v = $request->query('vorlage_id')) $q->where('p.vorlage_id', (int) $v);
        if ($v = $request->query('kategorie')) $q->where('p.kategorie', $v);
        if ($request->boolean('maengel')) $q->where('p.maengel', '>', 0);
        if ($s = trim((string) $request->query('q', ''))) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
            $q->where(fn ($w) => $w->where('o.objektname', 'like', $like)->orWhere('k.kundenname', 'like', $like)
                ->orWhere('p.vorlage_name', 'like', $like)->orWhere('m.name', 'like', $like)->orWhere('m.vorname', 'like', $like));
        }
        $summary = (clone $q)->selectRaw('COUNT(*) n, AVG(p.ergebnis_prozent) avg_p, SUM(p.maengel) m, SUM(p.maengel > 0) mit_m')->first();
        $rows = $q->select('p.*', 'o.objektname', 'k.kundenname', 'm.vorname as m_vorname', 'm.name as m_name')
            ->selectRaw('(SELECT COUNT(*) FROM checklisten_fotos f WHERE f.protokoll_id = p.id) AS fotos')
            ->orderByDesc('p.datum')->orderByDesc('p.id')->limit(1000)->get();
        return response()->json([
            'von' => $von, 'bis' => $bis,
            'summary' => [
                'anzahl' => (int) ($summary->n ?? 0),
                'durchschnitt' => $summary && $summary->avg_p !== null ? round((float) $summary->avg_p, 1) : null,
                'maengel' => (int) ($summary->m ?? 0),
                'mit_maengeln' => (int) ($summary->mit_m ?? 0),
            ],
            'protokolle' => $rows->map(fn ($r) => $this->listRow($r))->values(),
        ]);
    }

    private function findProtokoll(Request $request, int $id)
    {
        return $this->protokollQuery($request->user())->where('p.id', $id)
            ->select('p.*', 'o.objektname', 'o.adresse', 'o.plz', 'o.stadt', 'k.kundenname', 'm.vorname as m_vorname', 'm.name as m_name')
            ->first();
    }

    /** GET /checklisten/protokolle/{id} */
    public function protokoll(Request $request, int $id)
    {
        if ($r = $this->notReady()) return $r;
        $p = $this->findProtokoll($request, $id);
        if (!$p) return response()->json(['error' => 'Protokoll nicht gefunden'], 404);
        $fotos = $this->db()->table('checklisten_fotos')->where('protokoll_id', $id)->orderBy('id')->get(['id', 'feld_id']);
        return response()->json($this->listRow($p) + [
            'adresse' => trim(($p->adresse ?? '') . ', ' . trim(($p->plz ?? '') . ' ' . ($p->stadt ?? '')), ', '),
            'felder' => json_decode($p->felder, true) ?: [],
            'antworten' => json_decode($p->antworten, true) ?: (object) [],
            'fotos_je_feld' => $fotos->groupBy('feld_id')->map(fn ($g) => $g->pluck('id')->map(fn ($i) => (int) $i)->values()),
            'gps_lat' => $p->gps_lat !== null ? (float) $p->gps_lat : null,
            'gps_lng' => $p->gps_lng !== null ? (float) $p->gps_lng : null,
            'gps_genauigkeit' => $p->gps_genauigkeit !== null ? (int) $p->gps_genauigkeit : null,
            'unterschrift_kunde' => (bool) $p->unterschrift_kunde_pfad,
            'unterschrift_kunde_name' => $p->unterschrift_kunde_name,
            'unterschrift_ma' => (bool) $p->unterschrift_ma_pfad,
            'vorlage_version' => (int) $p->vorlage_version,
            'gestartet_at' => $p->gestartet_at,
        ]);
    }

    private function stream(?string $path, string $name)
    {
        if (!$path || !Storage::disk('local')->exists($path)) return response()->json(['error' => 'Datei nicht gefunden'], 404);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => $ext === 'png' ? 'image/png' : 'image/jpeg',
            'Content-Disposition' => 'inline; filename="' . $name . '.' . $ext . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** GET /checklisten/fotos/{id} */
    public function foto(Request $request, int $id)
    {
        if ($r = $this->notReady()) return $r;
        $f = $this->db()->table('checklisten_fotos')->where('id', $id)->first();
        if (!$f || !$this->findProtokoll($request, (int) $f->protokoll_id)) return response()->json(['error' => 'Foto nicht gefunden'], 404);
        return $this->stream($f->pfad, 'Foto_' . $id);
    }

    /** GET /checklisten/protokolle/{id}/unterschrift/{wer} (kunde|ma) */
    public function unterschrift(Request $request, int $id, string $wer)
    {
        if ($r = $this->notReady()) return $r;
        $p = $this->findProtokoll($request, $id);
        if (!$p) return response()->json(['error' => 'Protokoll nicht gefunden'], 404);
        return $this->stream($wer === 'kunde' ? $p->unterschrift_kunde_pfad : $p->unterschrift_ma_pfad, 'Unterschrift_' . $wer);
    }

    /** DELETE /checklisten/protokolle/{id} */
    public function destroyProtokoll(Request $request, int $id)
    {
        if ($r = $this->notReady()) return $r;
        if (!$this->findProtokoll($request, $id)) return response()->json(['error' => 'Protokoll nicht gefunden'], 404);
        Checklisten::deleteFiles($id);
        $this->db()->table('checklisten_protokolle')->where('id', $id)->delete();
        \App\Helpers\Realtime::changed('checklisten');
        return response()->json(['success' => true]);
    }

    private static function dataUri(?string $path): ?string
    {
        if (!$path || !Storage::disk('local')->exists($path)) return null;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return 'data:image/' . ($ext === 'png' ? 'png' : 'jpeg') . ';base64,' . base64_encode(Storage::disk('local')->get($path));
    }

    /** GET /checklisten/protokolle/{id}/pdf */
    public function pdf(Request $request, int $id)
    {
        if ($r = $this->notReady()) return $r;
        $p = $this->findProtokoll($request, $id);
        if (!$p) return response()->json(['error' => 'Protokoll nicht gefunden'], 404);
        $tenant = TenantService::current();
        $logo = null;
        if (!empty($tenant->logo_path)) {
            $full = storage_path('app/public/' . $tenant->logo_path);
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            if (is_file($full) && in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) {
                $logo = 'data:image/' . ($ext === 'jpg' ? 'jpeg' : $ext) . ';base64,' . base64_encode(file_get_contents($full));
            }
        }
        $fotos = [];
        foreach ($this->db()->table('checklisten_fotos')->where('protokoll_id', $id)->orderBy('id')->get() as $f) {
            if ($uri = self::dataUri($f->pfad)) $fotos[$f->feld_id][] = $uri;
        }
        $terms = Branchen::terms($tenant);
        $pdf = Pdf::loadView('pdf.checkliste', [
            'firma' => $tenant->name ?? '',
            'firmaInfo' => trim(implode(' | ', array_filter([
                trim(($tenant->company_address ?? '') . ', ' . trim(($tenant->company_zip ?? '') . ' ' . ($tenant->company_city ?? '')), ', '),
                !empty($tenant->company_phone) ? 'Tel: ' . $tenant->company_phone : '',
                $tenant->company_email ?? '',
            ]))),
            'logo' => $logo,
            'p' => $this->listRow($p),
            'adresse' => trim(($p->adresse ?? '') . ', ' . trim(($p->plz ?? '') . ' ' . ($p->stadt ?? '')), ', '),
            'felder' => json_decode($p->felder, true) ?: [],
            'antworten' => json_decode($p->antworten, true) ?: [],
            'fotos' => $fotos,
            'gps' => $p->gps_lat !== null ? ['lat' => (float) $p->gps_lat, 'lng' => (float) $p->gps_lng, 'genau' => $p->gps_genauigkeit] : null,
            'sigKunde' => self::dataUri($p->unterschrift_kunde_pfad),
            'sigKundeName' => $p->unterschrift_kunde_name,
            'sigMa' => self::dataUri($p->unterschrift_ma_pfad),
            'termKunde' => $terms['kunde'], 'termObjekt' => $terms['objekt'],
            'gestartet' => $p->gestartet_at, 'abgeschlossen' => $p->abgeschlossen_at,
        ])->setPaper('A4', 'portrait');
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $p->vorlage_name) . '_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $p->objektname ?? $p->obj_id) . '_' . $p->datum . '.pdf';
        return $pdf->download($name);
    }
}
