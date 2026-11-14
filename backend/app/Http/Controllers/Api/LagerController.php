<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Lagerverwaltung — warehouse & equipment management (Stammdaten → Lager).
 *
 * Tracks stock (lager_artikel) per tenant plus the issue/return log
 * (lager_ausgaben): which employee received how many units of an item,
 * when, and which back-office user recorded it.
 *
 * Read  : stammdaten.lager.view
 * Write : stammdaten.lager.edit (routes enforce both via middleware)
 */
class LagerController extends Controller
{
    private function t(string $table)
    {
        return DB::connection('tenant')->table($table);
    }

    private function hasTables(): bool
    {
        return Schema::connection('tenant')->hasTable('lager_artikel')
            && Schema::connection('tenant')->hasTable('lager_ausgaben');
    }

    // ---- Private files (invoices, signed return protocols) ----
    // Personal data (signatures, names, prices) — never on the public disk.
    // Stored on the private `local` disk under lager/{tenant_id}/…, streamed
    // only through authenticated endpoints. Legacy rows may still point at
    // the public disk ("uploads/…") until moved — both are handled.
    private function privDir(string $sub): string
    {
        $tenant = \App\Services\TenantService::current();
        return 'lager/' . (int) ($tenant->id ?? 0) . '/' . $sub;
    }

    private function fileDisk(?string $path): string
    {
        return ($path && str_starts_with($path, 'uploads/')) ? 'public' : 'local';
    }

    private function deleteFile(?string $path): void
    {
        if ($path) Storage::disk($this->fileDisk($path))->delete($path);
    }

    private function streamFile(?string $path, string $downloadName)
    {
        if (!$path) return response()->json(['error' => 'Keine Datei hinterlegt'], 404);
        $disk = Storage::disk($this->fileDisk($path));
        if (!$disk->exists($path)) return response()->json(['error' => 'Datei nicht gefunden'], 404);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
        return response($disk->get($path), 200, [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'inline; filename="' . $downloadName . '.' . $ext . '"',
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Extension from the validated MIME type, never from the client name. */
    private function safeExt($file): string
    {
        $ext = strtolower((string) $file->guessExtension());
        return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) ? ($ext === 'jpeg' ? 'jpg' : $ext) : 'bin';
    }

    /** Lager rows of workers outside the user's Bereich scope stay hidden. */
    private function scopeAusgaben($q, $user)
    {
        [$wSql, $wParams] = BereichScope::workerPredicate($user, '_lm');
        if ($wSql === '1=1') return $q;
        return $q->whereExists(function ($x) use ($wSql, $wParams) {
            $x->select(DB::raw(1))->from('mitarbeiter as _lm')
              ->whereColumn('_lm.dienst_nr', 'lager_ausgaben.mitarbeiter_dienst_nr')
              ->whereRaw($wSql, $wParams);
        });
    }

    /** Stock change log (who changed the owned quantity, when, why). */
    private function logBestand(int $artikelId, int $alt, int $neu, ?string $grund, Request $request): void
    {
        if ($alt === $neu || !Schema::connection('tenant')->hasTable('lager_bestand_log')) return;
        $u = $request->user();
        $this->t('lager_bestand_log')->insert([
            'artikel_id' => $artikelId,
            'menge_alt'  => $alt,
            'menge_neu'  => $neu,
            'grund'      => $grund !== null && trim($grund) !== '' ? mb_substr(trim($grund), 0, 500) : null,
            'user_id'    => optional($u)->id,
            'user_name'  => $u ? (trim((string) ($u->full_name ?? '')) ?: ($u->name ?? null)) : null,
            'created_at' => now(),
        ]);
    }

    /** Open-issue quantity per article — raw SQL fragment (no bindings). */
    private function offenSubquerySql(): string
    {
        return "select COALESCE(SUM(lager_ausgaben.menge),0) from lager_ausgaben"
            . " where lager_ausgaben.artikel_id = lager_artikel.artikel_id"
            . " and lager_ausgaben.status = 'offen'";
    }

    // ================= STATS =================

    public function stats()
    {
        if (!$this->hasTables()) {
            return response()->json(['artikel' => 0, 'bestand' => 0, 'ausgegeben' => 0, 'niedrig' => 0, 'lagerwert' => 0, 'wert_ausgegeben' => 0]);
        }
        $artikel = $this->t('lager_artikel')->where('aktiv', 1)->count();
        $bestand = (int) $this->t('lager_artikel')->where('aktiv', 1)->sum('menge_gesamt');
        $ausgegeben = (int) $this->t('lager_ausgaben')->where('status', 'offen')->sum('menge');
        // "Niedrig" = available stock below the configured minimum.
        $niedrig = $this->t('lager_artikel')
            ->where('aktiv', 1)->where('mindestbestand', '>', 0)
            ->whereRaw('(menge_gesamt - (' . $this->offenSubquerySql() . ')) < mindestbestand')
            ->count();
        // Monetary value: total stock value + value currently issued to workers.
        $lagerwert = (float) $this->t('lager_artikel')->where('aktiv', 1)
            ->whereNotNull('einzelpreis')
            ->sum(DB::raw('einzelpreis * menge_gesamt'));
        $wertAusgegeben = (float) $this->t('lager_ausgaben')
            ->join('lager_artikel', 'lager_ausgaben.artikel_id', '=', 'lager_artikel.artikel_id')
            ->where('lager_ausgaben.status', 'offen')
            ->whereNotNull('lager_artikel.einzelpreis')
            ->sum(DB::raw('lager_ausgaben.menge * lager_artikel.einzelpreis'));
        return response()->json([
            'artikel' => $artikel, 'bestand' => $bestand, 'ausgegeben' => $ausgegeben,
            'niedrig' => $niedrig, 'lagerwert' => $lagerwert, 'wert_ausgegeben' => $wertAusgegeben,
        ]);
    }

    // ================= KATEGORIEN =================

    public function kategorien()
    {
        if (!Schema::connection('tenant')->hasTable('lager_kategorien')) {
            return response()->json([]);
        }
        $rows = $this->t('lager_kategorien')
            ->select('lager_kategorien.*', DB::raw(
                '(select count(*) from lager_artikel where lager_artikel.kategorie_id = lager_kategorien.id'
                . ' and lager_artikel.aktiv = 1) as artikel_count'
            ))
            ->orderBy('lager_kategorien.sortierung')->orderBy('lager_kategorien.name')
            ->get();
        return response()->json($rows);
    }

    public function kategorieStore(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'icon' => 'nullable|string|max:50',
            'farbe' => 'nullable|string|max:20',
        ]);
        $id = $this->t('lager_kategorien')->insertGetId([
            'name' => $data['name'],
            'icon' => $data['icon'] ?? 'category',
            'farbe' => $data['farbe'] ?? null,
            'sortierung' => (int) ($this->t('lager_kategorien')->max('sortierung') ?? 0) + 1,
            'aktiv' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Helpers\Realtime::changed('lager');
        return response()->json(['id' => $id], 201);
    }

    public function kategorieUpdate(Request $request, $id)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'icon' => 'nullable|string|max:50',
            'farbe' => 'nullable|string|max:20',
        ]);
        $this->t('lager_kategorien')->where('id', $id)->update([
            'name' => $data['name'],
            'icon' => $data['icon'] ?? 'category',
            'farbe' => $data['farbe'] ?? null,
            'updated_at' => now(),
        ]);
        \App\Helpers\Realtime::changed('lager');
        return response()->json(['ok' => true]);
    }

    public function kategorieDestroy($id)
    {
        $inUse = $this->t('lager_artikel')->where('kategorie_id', $id)->count();
        if ($inUse > 0) {
            return response()->json(['error' => "Kategorie wird von {$inUse} Artikel(n) verwendet — erst neu zuordnen."], 422);
        }
        $this->t('lager_kategorien')->where('id', $id)->delete();
        \App\Helpers\Realtime::changed('lager');
        return response()->json(['ok' => true]);
    }

    // ================= ARTIKEL =================

    public function index(Request $request)
    {
        if (!$this->hasTables()) {
            return response()->json(['data' => []]);
        }
        $q = $this->t('lager_artikel')
            ->leftJoin('lager_kategorien', 'lager_artikel.kategorie_id', '=', 'lager_kategorien.id')
            ->select(
                'lager_artikel.*',
                'lager_kategorien.name as kategorie_name',
                'lager_kategorien.icon as kategorie_icon',
                'lager_kategorien.farbe as kategorie_farbe',
                DB::raw('(' . $this->offenSubquerySql() . ') as ausgegeben_menge'),
                DB::raw('(lager_artikel.menge_gesamt - (' . $this->offenSubquerySql() . ')) as verfuegbar')
            )
            ->where('lager_artikel.aktiv', 1);

        if ($request->filled('kategorie')) $q->where('lager_artikel.kategorie_id', (int) $request->kategorie);
        if ($request->filled('zustand')) $q->where('lager_artikel.zustand', $request->zustand);
        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $q->where(function ($w) use ($s) {
                $w->where('lager_artikel.name', 'like', $s)
                  ->orWhere('lager_artikel.art_nr', 'like', $s)
                  ->orWhere('lager_artikel.seriennummer', 'like', $s)
                  ->orWhere('lager_artikel.lagerort', 'like', $s);
            });
        }
        if ($request->boolean('nur_niedrig')) {
            $q->where('lager_artikel.mindestbestand', '>', 0)
              ->whereRaw('(lager_artikel.menge_gesamt - (' . $this->offenSubquerySql() . ')) < lager_artikel.mindestbestand');
        }
        $rows = $q->orderBy('lager_kategorien.sortierung')->orderBy('lager_artikel.name')->get();

        if ($request->query('format') === 'csv') {
            return $this->csv($rows);
        }
        return response()->json(['data' => $rows]);
    }

    public function show($id)
    {
        $artikel = $this->t('lager_artikel')->where('artikel_id', $id)->first();
        if (!$artikel) return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        $ausgaben = $this->scopeAusgaben($this->t('lager_ausgaben')->where('artikel_id', $id), request()->user())
            ->orderByDesc('ausgegeben_am')->limit(100)->get();
        $log = Schema::connection('tenant')->hasTable('lager_bestand_log')
            ? $this->t('lager_bestand_log')->where('artikel_id', $id)->orderByDesc('id')->limit(100)->get()
            : [];
        return response()->json(['artikel' => $artikel, 'ausgaben' => $ausgaben, 'bestand_log' => $log]);
    }

    private function artikelData(Request $request): array
    {
        return $request->validate([
            'kategorie_id' => 'nullable|integer',
            'name' => 'required|string|max:200',
            'art_nr' => 'nullable|string|max:40',
            'groesse' => 'nullable|string|max:20',
            'seriennummer' => 'nullable|string|max:100',
            'zustand' => 'nullable|string|in:neu,gut,abgenutzt,defekt,reparatur',
            'menge_gesamt' => 'required|integer|min:0',
            'einzelpreis' => 'nullable|numeric|min:0|max:99999999',
            'mindestbestand' => 'nullable|integer|min:0',
            'lagerort' => 'nullable|string|max:150',
            'bemerkung' => 'nullable|string|max:2000',
            'bestand_grund' => 'nullable|string|max:500',
        ]);
    }

    /** Business rules on top of the field validation; null = ok. */
    private function artikelRuleError(array $d, ?int $selfId)
    {
        if (!empty($d['seriennummer']) && (int) $d['menge_gesamt'] > 1) {
            return 'Eine Seriennummer gehört zu genau einem Gerät — Gesamtbestand muss 1 sein (für mehrere Geräte je einen Artikel anlegen).';
        }
        if (!empty($d['art_nr'])) {
            $dup = $this->t('lager_artikel')->where('aktiv', 1)
                ->whereRaw('LOWER(TRIM(art_nr)) = ?', [mb_strtolower(trim($d['art_nr']))])
                ->when($selfId, fn ($q) => $q->where('artikel_id', '!=', $selfId))
                ->value('name');
            if ($dup) return "Art.-Nr. „{$d['art_nr']}“ ist bereits vergeben ({$dup}).";
        }
        return null;
    }

    public function store(Request $request)
    {
        $d = $this->artikelData($request);
        if ($err = $this->artikelRuleError($d, null)) return response()->json(['error' => $err], 422);
        $id = $this->t('lager_artikel')->insertGetId([
            'kategorie_id' => $d['kategorie_id'] ?? null,
            'name' => $d['name'],
            'art_nr' => $d['art_nr'] ?? null,
            'groesse' => $d['groesse'] ?? null,
            'seriennummer' => $d['seriennummer'] ?? null,
            'zustand' => $d['zustand'] ?? 'neu',
            'menge_gesamt' => $d['menge_gesamt'],
            'einzelpreis' => $d['einzelpreis'] ?? null,
            'mindestbestand' => $d['mindestbestand'] ?? 0,
            'lagerort' => $d['lagerort'] ?? null,
            'bemerkung' => $d['bemerkung'] ?? null,
            'aktiv' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->logBestand((int) $id, 0, (int) $d['menge_gesamt'], $d['bestand_grund'] ?? 'Neu angelegt', $request);
        \App\Helpers\Realtime::changed('lager');
        return response()->json(['artikel_id' => $id], 201);
    }

    public function update(Request $request, $id)
    {
        $d = $this->artikelData($request);
        $artikel = $this->t('lager_artikel')->where('artikel_id', $id)->first();
        if (!$artikel) return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        if ($err = $this->artikelRuleError($d, (int) $id)) return response()->json(['error' => $err], 422);
        // Stock can't drop below what is currently issued.
        $offen = (int) $this->t('lager_ausgaben')->where('artikel_id', $id)->where('status', 'offen')->sum('menge');
        if ($d['menge_gesamt'] < $offen) {
            return response()->json(['error' => "Gesamtbestand ({$d['menge_gesamt']}) ist kleiner als die ausgegebene Menge ({$offen}). Erst Rückgaben erfassen."], 422);
        }
        $this->t('lager_artikel')->where('artikel_id', $id)->update([
            'kategorie_id' => $d['kategorie_id'] ?? null,
            'name' => $d['name'],
            'art_nr' => $d['art_nr'] ?? null,
            'groesse' => $d['groesse'] ?? null,
            'seriennummer' => $d['seriennummer'] ?? null,
            'zustand' => $d['zustand'] ?? 'neu',
            'menge_gesamt' => $d['menge_gesamt'],
            'einzelpreis' => $d['einzelpreis'] ?? null,
            'mindestbestand' => $d['mindestbestand'] ?? 0,
            'lagerort' => $d['lagerort'] ?? null,
            'bemerkung' => $d['bemerkung'] ?? null,
            'updated_at' => now(),
        ]);
        $this->logBestand((int) $id, (int) $artikel->menge_gesamt, (int) $d['menge_gesamt'], $d['bestand_grund'] ?? null, $request);
        \App\Helpers\Realtime::changed('lager');
        return response()->json(['ok' => true]);
    }

    public function destroy($id)
    {
        $offen = (int) $this->t('lager_ausgaben')->where('artikel_id', $id)->where('status', 'offen')->count();
        if ($offen > 0) {
            return response()->json(['error' => "Artikel hat {$offen} offene Ausgabe(n) — erst alle Rückgaben erfassen."], 422);
        }
        $this->t('lager_artikel')->where('artikel_id', $id)->update(['aktiv' => 0, 'updated_at' => now()]);
        \App\Helpers\Realtime::changed('lager');
        return response()->json(['ok' => true]);
    }

    // ================= FOTO =================

    /** Upload/replace the article photo (public disk, served via /uploads/*). */
    public function fotoUpload(Request $request, $id)
    {
        $artikel = $this->t('lager_artikel')->where('artikel_id', $id)->where('aktiv', 1)->first();
        if (!$artikel) return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        if (!$request->hasFile('foto')) return response()->json(['error' => 'Keine Datei empfangen'], 400);

        $request->validate(['foto' => 'required|image|mimes:jpeg,png,webp|max:5120']);

        try {
            $tenant = \App\Services\TenantService::current();
            $slug = $tenant ? $tenant->slug : 'default';
            $file = $request->file('foto');
            $filename = 'foto_' . time() . '.' . $file->getClientOriginalExtension();
            $dir = 'uploads/lager/' . $slug . '/' . $id;
            $filepath = $dir . '/' . $filename;

            if (!$file->storeAs($dir, $filename, 'public')) {
                return response()->json(['error' => 'Datei konnte nicht gespeichert werden'], 500);
            }
            if ($artikel->foto && $artikel->foto !== $filepath) {
                Storage::disk('public')->delete($artikel->foto);
            }
            $this->t('lager_artikel')->where('artikel_id', $id)->update(['foto' => $filepath, 'updated_at' => now()]);
            \App\Helpers\Realtime::changed('lager');
            return response()->json(['success' => true, 'foto' => '/' . $filepath]);
        } catch (\Exception $e) {
            \Log::error('Lager foto upload failed: ' . $e->getMessage());
            return response()->json(['error' => 'Upload fehlgeschlagen'], 500);
        }
    }

    public function fotoDelete(Request $request, $id)
    {
        $artikel = $this->t('lager_artikel')->where('artikel_id', $id)->first();
        if (!$artikel) return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        if ($artikel->foto) Storage::disk('public')->delete($artikel->foto);
        $this->t('lager_artikel')->where('artikel_id', $id)->update(['foto' => null, 'updated_at' => now()]);
        \App\Helpers\Realtime::changed('lager');
        return response()->json(['ok' => true]);
    }

    // ================= RECHNUNG (invoice attachment) =================

    /** Upload/replace the invoice file — image or PDF, up to 10 MB. */
    public function rechnungUpload(Request $request, $id)
    {
        $artikel = $this->t('lager_artikel')->where('artikel_id', $id)->where('aktiv', 1)->first();
        if (!$artikel) return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        if (!$request->hasFile('rechnung')) return response()->json(['error' => 'Keine Datei empfangen'], 400);

        $request->validate(['rechnung' => 'required|mimes:jpeg,png,webp,pdf|max:10240']);

        try {
            $file = $request->file('rechnung');
            $filename = 'rechnung_' . bin2hex(random_bytes(12)) . '.' . $this->safeExt($file);
            $dir = $this->privDir((int) $id);
            $filepath = $dir . '/' . $filename;

            if (!$file->storeAs($dir, $filename, 'local')) {
                return response()->json(['error' => 'Datei konnte nicht gespeichert werden'], 500);
            }
            if ($artikel->rechnung && $artikel->rechnung !== $filepath) {
                $this->deleteFile($artikel->rechnung);
            }
            $this->t('lager_artikel')->where('artikel_id', $id)->update(['rechnung' => $filepath, 'updated_at' => now()]);
            \App\Helpers\Realtime::changed('lager');
            return response()->json(['success' => true, 'rechnung' => $filepath]);
        } catch (\Exception $e) {
            \Log::error('Lager rechnung upload failed: ' . $e->getMessage());
            return response()->json(['error' => 'Upload fehlgeschlagen'], 500);
        }
    }

    public function rechnungDelete(Request $request, $id)
    {
        $artikel = $this->t('lager_artikel')->where('artikel_id', $id)->first();
        if (!$artikel) return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        $this->deleteFile($artikel->rechnung);
        $this->t('lager_artikel')->where('artikel_id', $id)->update(['rechnung' => null, 'updated_at' => now()]);
        \App\Helpers\Realtime::changed('lager');
        return response()->json(['ok' => true]);
    }

    /** GET /lager/{id}/rechnung — invoice, authenticated only. */
    public function rechnungFile($id)
    {
        $artikel = $this->t('lager_artikel')->where('artikel_id', $id)->first();
        if (!$artikel) return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        return $this->streamFile($artikel->rechnung, 'Rechnung_' . preg_replace('/[^\w-]+/', '_', (string) $artikel->name));
    }

    /** GET /lager/ausgaben/{id}/protokoll — signed return protocol, authenticated + Bereich-scoped. */
    public function protokollFile(Request $request, $ausgabeId)
    {
        $a = $this->scopeAusgaben($this->t('lager_ausgaben')->where('ausgabe_id', $ausgabeId), $request->user())->first();
        if (!$a) return response()->json(['error' => 'Rückgabe nicht gefunden'], 404);
        return $this->streamFile($a->rueckgabe_protokoll ?? null, 'Rueckgabeprotokoll_' . $a->mitarbeiter_dienst_nr . '_' . $ausgabeId);
    }

    // ================= AUSGABE / RÜCKGABE =================

    /** Worker list for the issue picker (minimal payload, Bereich-scoped). */
    public function mitarbeiterOptions(Request $request)
    {
        [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'mitarbeiter');
        $rows = $this->t('mitarbeiter')->where('aktiv', 1)
            ->whereRaw($wSql, $wParams)
            ->select('dienst_nr', 'vorname', 'name', 'bereich', 'foto')
            ->orderBy('name')->orderBy('vorname')->get();
        return response()->json($rows);
    }

    public function ausgabe(Request $request, $id)
    {
        $d = $request->validate([
            'mitarbeiter_dienst_nr' => 'required|string|max:10',
            'menge' => 'required|integer|min:1',
            'bemerkung' => 'nullable|string|max:1000',
        ]);
        $artikel = $this->t('lager_artikel')->where('artikel_id', $id)->where('aktiv', 1)->first();
        if (!$artikel) return response()->json(['error' => 'Artikel nicht gefunden'], 404);

        $worker = $this->t('mitarbeiter')->where('dienst_nr', $d['mitarbeiter_dienst_nr'])->first();
        if (!$worker || !BereichScope::allowsWorker($request->user(), $worker->dienst_nr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        // Check + insert under a row lock: two users issuing the last unit at
        // the same moment can no longer overbook the article.
        $res = DB::connection('tenant')->transaction(function () use ($id, $d, $worker, $request) {
            $art = $this->t('lager_artikel')->where('artikel_id', $id)->lockForUpdate()->first();
            $offen = (int) $this->t('lager_ausgaben')->where('artikel_id', $id)->where('status', 'offen')->sum('menge');
            $verfuegbar = $art->menge_gesamt - $offen;
            if ($d['menge'] > $verfuegbar) {
                return ['error' => "Nur {$verfuegbar} × {$art->name} verfügbar ({$art->menge_gesamt} gesamt, {$offen} ausgegeben)."];
            }
            return ['id' => $this->t('lager_ausgaben')->insertGetId([
                'artikel_id' => $id,
                'mitarbeiter_dienst_nr' => $worker->dienst_nr,
                'mitarbeiter_name' => trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? '')),
                'menge' => $d['menge'],
                'ausgegeben_am' => now(),
                'ausgegeben_von' => optional($request->user())->id,
                'status' => 'offen',
                'bemerkung' => $d['bemerkung'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ])];
        });
        if (isset($res['error'])) return response()->json(['error' => $res['error']], 422);
        \App\Helpers\Realtime::changed('lager');
        return response()->json(['ausgabe_id' => $res['id']], 201);
    }

    public function rueckgabe(Request $request, $ausgabeId)
    {
        $d = $request->validate([
            'menge' => 'nullable|integer|min:1',
            'bemerkung' => 'nullable|string|max:1000',
            'protokoll' => 'required|mimes:pdf,jpeg,jpg,png,webp|max:10240',
        ]);
        $a = $this->scopeAusgaben($this->t('lager_ausgaben')->where('ausgabe_id', $ausgabeId), $request->user())->first();
        if (!$a || $a->status !== 'offen') {
            return response()->json(['error' => 'Ausgabe nicht gefunden oder bereits zurückgegeben'], 404);
        }

        // The signed return protocol is mandatory — private disk, random name,
        // one file per logged return event (served via protokollFile()).
        $file = $request->file('protokoll');
        $filename = 'protokoll_' . (int) $ausgabeId . '_' . bin2hex(random_bytes(12)) . '.' . $this->safeExt($file);
        $dir = $this->privDir('protokolle');
        if (!$file->storeAs($dir, $filename, 'local')) {
            return response()->json(['error' => 'Protokoll konnte nicht gespeichert werden'], 500);
        }
        $protokoll = $dir . '/' . $filename;

        $menge = $d['menge'] ?? $a->menge;
        if ($menge >= $a->menge) {
            // Full return — close the row.
            $this->t('lager_ausgaben')->where('ausgabe_id', $ausgabeId)->update([
                'status' => 'zurueck', 'rueckgabe_am' => now(),
                'rueckgabe_von' => optional($request->user())->id,
                'rueckgabe_protokoll' => $protokoll,
                'bemerkung' => $d['bemerkung'] ?? $a->bemerkung,
                'updated_at' => now(),
            ]);
        } else {
            // Partial return: shrink the open row and log the returned part.
            $this->t('lager_ausgaben')->where('ausgabe_id', $ausgabeId)->update([
                'menge' => $a->menge - $menge, 'updated_at' => now(),
            ]);
            $this->t('lager_ausgaben')->insert([
                'artikel_id' => $a->artikel_id,
                'mitarbeiter_dienst_nr' => $a->mitarbeiter_dienst_nr,
                'mitarbeiter_name' => $a->mitarbeiter_name,
                'menge' => $menge,
                'ausgegeben_am' => $a->ausgegeben_am,
                'ausgegeben_von' => $a->ausgegeben_von,
                'rueckgabe_am' => now(),
                'rueckgabe_von' => optional($request->user())->id,
                'rueckgabe_protokoll' => $protokoll,
                'status' => 'zurueck',
                'bemerkung' => $d['bemerkung'] ?? $a->bemerkung,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        \App\Helpers\Realtime::changed('lager');
        return response()->json(['ok' => true]);
    }

    /** Issue/return log — filterable by status / article / worker / search. */
    public function ausgaben(Request $request)
    {
        if (!$this->hasTables()) return response()->json(['data' => []]);
        $q = $this->t('lager_ausgaben')
            ->join('lager_artikel', 'lager_ausgaben.artikel_id', '=', 'lager_artikel.artikel_id')
            ->select('lager_ausgaben.*', 'lager_artikel.name as artikel_name', 'lager_artikel.art_nr', 'lager_artikel.einzelpreis')
            ->orderByDesc('lager_ausgaben.ausgegeben_am');
        $this->scopeAusgaben($q, $request->user());
        if ($request->filled('status')) $q->where('lager_ausgaben.status', $request->status);
        if ($request->filled('artikel_id')) $q->where('lager_ausgaben.artikel_id', (int) $request->artikel_id);
        if ($request->filled('mitarbeiter')) $q->where('lager_ausgaben.mitarbeiter_dienst_nr', $request->mitarbeiter);
        // Zeitraum filter on the issue date — used by the Verlauf period
        // selector and the date-ranged CSV export.
        $from = $request->date('from');
        $to = $request->date('to');
        if ($from) $q->whereDate('lager_ausgaben.ausgegeben_am', '>=', $from->format('Y-m-d'));
        if ($to)   $q->whereDate('lager_ausgaben.ausgegeben_am', '<=', $to->format('Y-m-d'));
        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $q->where(function ($w) use ($s) {
                $w->where('lager_ausgaben.mitarbeiter_name', 'like', $s)
                  ->orWhere('lager_artikel.name', 'like', $s)
                  ->orWhere('lager_ausgaben.bemerkung', 'like', $s);
            });
        }
        $rows = $q->limit(500)->get();
        // Resolve the office users who issued / took back each row so the UI
        // can show "ausgegeben von X" / "zurückgenommen von Y".
        $uids = $rows->flatMap(fn($r) => [$r->ausgegeben_von, $r->rueckgabe_von])->filter()->unique()->values();
        $names = $uids->isEmpty()
            ? collect()
            : \App\Models\CentralUser::whereIn('id', $uids)->get()
                ->mapWithKeys(fn($u) => [$u->id => $u->full_name ?: $u->name]);
        $rows->transform(function ($r) use ($names) {
            $r->ausgegeben_von_name = $names[$r->ausgegeben_von] ?? null;
            $r->rueckgabe_von_name = $names[$r->rueckgabe_von] ?? null;
            return $r;
        });
        return response()->json(['data' => $rows]);
    }

    private function csv($rows)
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($out, ['Art.-Nr.', 'Name', 'Kategorie', 'Größe', 'Serien-Nr.', 'Zustand', 'Lagerort', 'Gesamt', 'Ausgegeben', 'Verfügbar', 'Mindestbestand'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r->art_nr, $r->name, $r->kategorie_name, $r->groesse, $r->seriennummer,
                $r->zustand, $r->lagerort, $r->menge_gesamt, $r->ausgegeben_menge, $r->verfuegbar, $r->mindestbestand,
            ], ';');
        }
        rewind($out);
        return response(stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="lager_' . date('Y-m-d') . '.csv"',
        ]);
    }
}
