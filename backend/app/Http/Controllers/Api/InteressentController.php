<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\IdGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Interessentenverwaltung — prospect/lead management under Stammdaten.
 *
 * Visibility (per specification):
 *   - Vertriebsmitarbeiter (`.view` only) see prospects assigned to them.
 *   - Vertriebsleitung / admins (`.view_all`) see every prospect and may
 *     reassign `zustaendiger_mitarbeiter`.
 *   - Owners/admins pass implicitly via hasPermission().
 *
 * "Zu Kunde konvertieren" copies the master data into the kunden table
 * inside a transaction and marks the prospect with `konvertiert_zu` —
 * the record is preserved for reporting, never deleted.
 */
class InteressentController extends Controller
{
    // Existing values must stay (stored rows); 'Sonstiges' last.
    private const BRANCHEN = [
        'Reinigung', 'Sicherheit', 'Facility', 'Personaldienstleistung', 'Veranstaltung',
        'Logistik', 'Industrie & Produktion', 'Handel', 'Gesundheit & Pflege',
        'Hotel & Gastronomie', 'Wohnungswirtschaft', 'Öffentliche Hand', 'Sonstiges',
    ];
    private const MA_BEREICHE = ['< 15', '16-50', '51-100', '> 100'];
    private const QUELLEN = ['Empfehlung', 'Kaltakquise', 'Website', 'Messe', 'Sonstiges'];
    private const PHASEN = ['Erstkontakt', 'Termin', 'Demo', 'Angebot', 'Verhandlung', 'Gewonnen', 'Verloren'];
    private const KOMM_TYPEN = ['Anruf', 'E-Mail', 'Termin', 'Demo', 'Notiz'];

    private const DATEI_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx'];
    private const DATEI_MAX_KB = 10240;

    // ---------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------

    /** True when the actor may see ALL prospects (Vertriebsleitung/admin). */
    private function canSeeAll(Request $request): bool
    {
        $user = $request->user();
        return $user && method_exists($user, 'hasPermission')
            && $user->hasPermission('stammdaten.interessenten.view_all');
    }

    /** Restrict a query to prospects visible to the actor. */
    private function scopeVisible($query, Request $request): void
    {
        if (!$this->canSeeAll($request)) {
            $query->where('zustaendiger_mitarbeiter', (int) $request->user()->id);
        }
    }

    /** Fetch one prospect if (and only if) the actor may see it. */
    private function visibleProspect(Request $request, string $id)
    {
        $row = DB::connection('tenant')->table('interessenten')->where('interessent_id', $id)->first();
        if (!$row) {
            return null;
        }
        if (!$this->canSeeAll($request) && (int) $row->zustaendiger_mitarbeiter !== (int) $request->user()->id) {
            return null;
        }
        return $row;
    }

    private function nullable($v)
    {
        if ($v === null) return null;
        $v = is_string($v) ? trim($v) : $v;
        return $v === '' ? null : $v;
    }

    /** null = ok/not provided, else validated active user of this tenant or 422. */
    private function mitarbeiterInput(Request $request, $value): int|null|false
    {
        if ($value === null || $value === '') return null;
        if (!is_numeric($value)) return false;
        $ok = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->where('id', (int) $value)
            ->where('aktiv', 1)
            ->whereRaw("name NOT LIKE 'deleted\\_%'")->exists();
        return $ok ? (int) $value : false;
    }

    private function mitarbeiterName(int $id, int $tenantId): ?string
    {
        $r = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', $tenantId)->where('id', $id)
            ->first(['full_name', 'name', 'email']);
        if (!$r) return null;
        return trim((string) $r->full_name) !== '' ? $r->full_name : ($r->name ?: $r->email);
    }

    /** Attach display names for responsible + entry authors. */
    private function decorate($rows, Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $ids = collect($rows)->pluck('zustaendiger_mitarbeiter')->filter()->unique()->values();
        $names = [];
        foreach ($ids as $id) {
            $names[(int) $id] = $this->mitarbeiterName((int) $id, $tenantId);
        }
        foreach ($rows as $r) {
            $r->zustaendiger_name = $names[(int) $r->zustaendiger_mitarbeiter] ?? ('#' . $r->zustaendiger_mitarbeiter);
        }
        return $rows;
    }

    // ---------------------------------------------------------
    // List / detail
    // ---------------------------------------------------------

    public function index(Request $request)
    {
        if ($request->has('id')) {
            return $this->show($request, (string) $request->id);
        }

        $query = DB::connection('tenant')->table('interessenten');
        $this->scopeVisible($query, $request);

        if ($request->filled('phase')) {
            $query->where('pipeline_phase', $request->phase);
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('firmenname', 'like', $s)
                  ->orWhere('interessent_id', 'like', $s)
                  ->orWhere('ansprechpartner_name', 'like', $s)
                  ->orWhere('einsatzgebiet', 'like', $s)
                  ->orWhere('email', 'like', $s);
            });
        }

        $sortable = [
            'firmenname', 'branche', 'einsatzgebiet', 'ansprechpartner_name',
            'quelle', 'pipeline_phase', 'wiedervorlage_am', 'created_at',
        ];
        $col = in_array($request->sort, $sortable, true) ? $request->sort : 'created_at';
        $dir = $request->dir === 'asc' ? 'asc' : 'desc';
        $query->orderBy($col, $dir);

        $rows = $query->get();
        $this->decorate($rows, $request);

        // Per-row stats for the list badges
        $ids = $rows->pluck('interessent_id');
        if ($ids->isNotEmpty()) {
            $counts = DB::connection('tenant')->table('interessent_kommunikation')
                ->whereIn('interessent_id', $ids)
                ->selectRaw('interessent_id, COUNT(*) AS n, MAX(datum) AS last_at')
                ->groupBy('interessent_id')->get()->keyBy('interessent_id');
            foreach ($rows as $r) {
                $c = $counts->get($r->interessent_id);
                $r->kontakte = $c ? (int) $c->n : 0;
                $r->letzter_kontakt = $c->last_at ?? null;
            }
        }

        return response()->json($rows);
    }

    /** Aggregate numbers for the page header cards — same visibility rules. */
    public function stats(Request $request)
    {
        $db = DB::connection('tenant')->table('interessenten');
        $this->scopeVisible($db, $request);
        $base = $db->get(['pipeline_phase', 'wiedervorlage_am', 'konvertiert_zu']);

        $today = now()->toDateString();
        $open = $base->whereNotIn('pipeline_phase', ['Gewonnen', 'Verloren']);
        return response()->json([
            'gesamt'      => $base->count(),
            'offen'       => $open->count(),
            'gewonnen'    => $base->where('pipeline_phase', 'Gewonnen')->count(),
            'verloren'    => $base->where('pipeline_phase', 'Verloren')->count(),
            'ueberfaellig'=> $open->filter(fn ($r) => $r->wiedervorlage_am && $r->wiedervorlage_am < $today)->count(),
            'konvertiert' => $base->filter(fn ($r) => $r->konvertiert_zu)->count(),
        ]);
    }

    public function show(Request $request, string $id)
    {
        $row = $this->visibleProspect($request, $id);
        if (!$row) {
            return response()->json(['error' => 'Interessent nicht gefunden'], 404);
        }
        $this->decorate([$row], $request);
        $row->erstellt_von_name = !empty($row->erstellt_von)
            ? $this->mitarbeiterName((int) $row->erstellt_von, (int) $request->user()->tenant_id)
            : null;

        $row->kommunikation = DB::connection('tenant')->table('interessent_kommunikation')
            ->where('interessent_id', $id)
            ->orderByDesc('datum')->orderByDesc('eintrag_id')
            ->get()
            ->map(function ($e) use ($request) {
                $e->erfasst_von_name = $this->mitarbeiterName((int) $e->erfasst_von, (int) $request->user()->tenant_id)
                    ?? ('#' . $e->erfasst_von);
                return $e;
            });

        return response()->json($row);
    }

    /** Options for the "Zuständiger Mitarbeiter" select — active tenant users. */
    public function mitarbeiterOptions(Request $request)
    {
        $rows = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', (int) $request->user()->tenant_id)->where('aktiv', 1)
            ->whereRaw("name NOT LIKE 'deleted\\_%'")->where('name', '!=', 'demo-viewer')
            ->orderBy('full_name')->get(['id', 'full_name', 'name', 'email']);
        return response()->json($rows->map(fn ($r) => [
            'id'   => (int) $r->id,
            'name' => trim((string) $r->full_name) !== '' ? $r->full_name : ($r->name ?: $r->email),
        ])->values());
    }

    /** Select-Optionen für die Formulare (spec-fixed value lists). */
    public function optionen()
    {
        return response()->json([
            'branchen'  => self::BRANCHEN,
            'ma_bereiche' => self::MA_BEREICHE,
            'quellen'   => self::QUELLEN,
            'phasen'    => self::PHASEN,
            'komm_typen' => self::KOMM_TYPEN,
        ]);
    }

    // ---------------------------------------------------------
    // Validation + persistence
    // ---------------------------------------------------------

    private function validateData(array $data, ?object $existing): array
    {
        $errors = [];

        $firmenname = trim((string) ($data['firmenname'] ?? ''));
        if ($firmenname === '') $errors['firmenname'] = 'Firmenname ist erforderlich';
        elseif (mb_strlen($firmenname) > 200) $errors['firmenname'] = 'Max. 200 Zeichen';

        $branche = $data['branche'] ?? '';
        if (!in_array($branche, self::BRANCHEN, true)) $errors['branche'] = 'Branche ist erforderlich';

        $ap = trim((string) ($data['ansprechpartner_name'] ?? ''));
        if ($ap === '') $errors['ansprechpartner_name'] = 'Ansprechpartner ist erforderlich';
        elseif (mb_strlen($ap) > 150) $errors['ansprechpartner_name'] = 'Max. 150 Zeichen';

        $quelle = $data['quelle'] ?? '';
        if (!in_array($quelle, self::QUELLEN, true)) $errors['quelle'] = 'Quelle ist erforderlich';

        $phase = $data['pipeline_phase'] ?? '';
        if (!in_array($phase, self::PHASEN, true)) $errors['pipeline_phase'] = 'Ungültige Pipeline-Phase';

        if (($data['mitarbeiterzahl_bereich'] ?? null) && !in_array($data['mitarbeiterzahl_bereich'], self::MA_BEREICHE, true)) {
            $errors['mitarbeiterzahl_bereich'] = 'Ungültiger Mitarbeiterzahl-Bereich';
        }
        if (($data['email'] ?? null) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Ungültige E-Mail-Adresse';
        }
        if (($data['wiedervorlage_am'] ?? null) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['wiedervorlage_am'])) {
            $errors['wiedervorlage_am'] = 'Datum im Format JJJJ-MM-TT erwartet';
        }

        return $errors;
    }

    public function store(Request $request)
    {
        $data = $request->all();
        $errors = $this->validateData($data, null);

        $ma = $this->mitarbeiterInput($request, $data['zustaendiger_mitarbeiter'] ?? null);
        if ($ma === null) $errors['zustaendiger_mitarbeiter'] = 'Zuständiger Mitarbeiter ist erforderlich';
        elseif ($ma === false) $errors['zustaendiger_mitarbeiter'] = 'Mitarbeiter nicht gefunden';
        // Without .view_all a new prospect may only be assigned to oneself —
        // otherwise it would vanish from the creator's own list.
        elseif (!$this->canSeeAll($request) && $ma !== (int) $request->user()->id) {
            $errors['zustaendiger_mitarbeiter'] = 'Zuordnung an andere Mitarbeiter nur durch Vertriebsleitung';
        }

        if ($errors) {
            return response()->json(['errors' => $errors], 422);
        }

        $id = IdGenerator::next('interessenten', 'interessent_id', 'I');
        DB::connection('tenant')->table('interessenten')->insert([
            'interessent_id'          => $id,
            'firmenname'              => trim($data['firmenname']),
            'branche'                 => $data['branche'],
            'mitarbeiterzahl_bereich' => $this->nullable($data['mitarbeiterzahl_bereich'] ?? null),
            'einsatzgebiet'           => $this->nullable($data['einsatzgebiet'] ?? null),
            'ansprechpartner_name'    => trim($data['ansprechpartner_name']),
            'ansprechpartner_position'=> $this->nullable($data['ansprechpartner_position'] ?? null),
            'telefon'                 => $this->nullable($data['telefon'] ?? null),
            'email'                   => $this->nullable($data['email'] ?? null),
            'quelle'                  => $data['quelle'],
            'zustaendiger_mitarbeiter'=> $ma,
            'pipeline_phase'          => $data['pipeline_phase'],
            'naechster_schritt'       => $this->nullable($data['naechster_schritt'] ?? null),
            'wiedervorlage_am'        => $this->nullable($data['wiedervorlage_am'] ?? null),
            'erstellt_von'            => (int) $request->user()->id,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        \App\Helpers\Realtime::changed('interessenten');
        return response()->json(['success' => true, 'interessent_id' => $id], 201);
    }

    public function update(Request $request, string $id)
    {
        $existing = $this->visibleProspect($request, $id);
        if (!$existing) {
            return response()->json(['error' => 'Interessent nicht gefunden'], 404);
        }
        if ($existing->konvertiert_zu) {
            return response()->json(['error' => 'Bereits konvertiert — Datensatz ist schreibgeschützt.'], 409);
        }

        $data = $request->all();
        $errors = $this->validateData($data, $existing);

        // Reassignment is a Vertriebsleitung capability (.view_all).
        $newMa = $existing->zustaendiger_mitarbeiter;
        if (array_key_exists('zustaendiger_mitarbeiter', $data)) {
            $ma = $this->mitarbeiterInput($request, $data['zustaendiger_mitarbeiter']);
            if ($ma === null || $ma === false) {
                $errors['zustaendiger_mitarbeiter'] = $ma === null
                    ? 'Zuständiger Mitarbeiter ist erforderlich'
                    : 'Mitarbeiter nicht gefunden';
            } elseif ($ma !== (int) $existing->zustaendiger_mitarbeiter && !$this->canSeeAll($request)) {
                $errors['zustaendiger_mitarbeiter'] = 'Nur die Vertriebsleitung kann die Zuordnung ändern.';
            } else {
                $newMa = $ma;
            }
        }

        if ($errors) {
            return response()->json(['errors' => $errors], 422);
        }

        DB::connection('tenant')->table('interessenten')->where('interessent_id', $id)->update([
            'firmenname'              => trim($data['firmenname']),
            'branche'                 => $data['branche'],
            'mitarbeiterzahl_bereich' => $this->nullable($data['mitarbeiterzahl_bereich'] ?? null),
            'einsatzgebiet'           => $this->nullable($data['einsatzgebiet'] ?? null),
            'ansprechpartner_name'    => trim($data['ansprechpartner_name']),
            'ansprechpartner_position'=> $this->nullable($data['ansprechpartner_position'] ?? null),
            'telefon'                 => $this->nullable($data['telefon'] ?? null),
            'email'                   => $this->nullable($data['email'] ?? null),
            'quelle'                  => $data['quelle'],
            'zustaendiger_mitarbeiter'=> $newMa,
            'pipeline_phase'          => $data['pipeline_phase'],
            'updated_at'              => now(),
        ] + array_map(fn ($v) => $this->nullable($v), array_intersect_key($data, array_flip(['naechster_schritt', 'wiedervorlage_am']))));

        \App\Helpers\Realtime::changed('interessenten');
        return response()->json(['success' => true, 'interessent_id' => $id]);
    }

    public function destroy(Request $request, string $id)
    {
        $existing = $this->visibleProspect($request, $id);
        if (!$existing) {
            return response()->json(['error' => 'Interessent nicht gefunden'], 404);
        }

        $files = DB::connection('tenant')->table('interessent_kommunikation')
            ->where('interessent_id', $id)->whereNotNull('datei_pfad')->pluck('datei_pfad');
        foreach ($files as $p) {
            if ($p && Storage::disk('public')->exists($p)) {
                Storage::disk('public')->delete($p);
            }
        }
        DB::connection('tenant')->table('interessent_kommunikation')->where('interessent_id', $id)->delete();
        DB::connection('tenant')->table('interessenten')->where('interessent_id', $id)->delete();

        \App\Helpers\Realtime::changed('interessenten');
        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------
    // Kommunikationsverlauf
    // ---------------------------------------------------------

    public function kommunikationStore(Request $request, string $id)
    {
        $prospect = $this->visibleProspect($request, $id);
        if (!$prospect) {
            return response()->json(['error' => 'Interessent nicht gefunden'], 404);
        }

        $typ = $request->input('typ');
        $notiz = trim((string) $request->input('notiz', ''));
        $errors = [];
        if (!in_array($typ, self::KOMM_TYPEN, true)) $errors['typ'] = 'Ungültiger Typ';
        if ($notiz === '') $errors['notiz'] = 'Notiz ist erforderlich';
        elseif (mb_strlen($notiz) > 5000) $errors['notiz'] = 'Max. 5000 Zeichen';

        $schritt = trim((string) $request->input('naechster_schritt', ''));
        if (mb_strlen($schritt) > 2000) $errors['naechster_schritt'] = 'Max. 2000 Zeichen';
        $wv = trim((string) $request->input('wiedervorlage_am', ''));
        if ($wv !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $wv, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
            $errors['wiedervorlage_am'] = 'Ungültiges Datum';
        }

        // New phase may ride along with the entry (per spec workflow).
        $newPhase = $request->input('pipeline_phase');
        if ($newPhase !== null && $newPhase !== '' && !in_array($newPhase, self::PHASEN, true)) {
            $errors['pipeline_phase'] = 'Ungültige Pipeline-Phase';
        }

        $filePath = null;
        $fileName = null;
        if ($request->hasFile('datei')) {
            $file = $request->file('datei');
            $ext = strtolower((string) $file->getClientOriginalExtension());
            if (!in_array($ext, self::DATEI_EXT, true)) {
                $errors['datei'] = 'Erlaubte Dateitypen: PDF, JPG, PNG, WebP, GIF, DOC, DOCX, XLS, XLSX';
            } elseif ($file->getSize() > self::DATEI_MAX_KB * 1024) {
                $errors['datei'] = 'Datei ist größer als 10 MB';
            } else {
                $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
                $fileName = $file->getClientOriginalName();
                // Public disk is shared across tenants — include the tenant id.
                $dir = 'uploads/interessenten/' . (int) $request->user()->tenant_id . '/' . $id;
                $filePath = $dir . '/' . $safeName . '_' . time() . '.' . $ext;
                $file->storeAs($dir, $safeName . '_' . time() . '.' . $ext, 'public');
            }
        }

        if ($errors) {
            return response()->json(['errors' => $errors], 422);
        }

        DB::connection('tenant')->transaction(function () use ($id, $typ, $notiz, $schritt, $wv, $newPhase, $filePath, $fileName, $request, $prospect) {
            DB::connection('tenant')->table('interessent_kommunikation')->insert([
                'interessent_id' => $id,
                'typ'            => $typ,
                'datum'          => now(),
                'notiz'          => $notiz,
                'naechster_schritt' => $schritt !== '' ? $schritt : null,
                'wiedervorlage_am'  => $wv !== '' ? $wv : null,
                'erfasst_von'    => (int) $request->user()->id,
                'datei_pfad'     => $filePath,
                'datei_name'     => $fileName,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            // The newest entry that carries a next step / follow-up date
            // becomes the prospect's current value (Akquise-Status). Empty
            // fields leave the current values untouched.
            $patch = [];
            if ($newPhase && !$prospect->konvertiert_zu) $patch['pipeline_phase'] = $newPhase;
            if ($schritt !== '') $patch['naechster_schritt'] = $schritt;
            if ($wv !== '') $patch['wiedervorlage_am'] = $wv;
            if ($patch) {
                DB::connection('tenant')->table('interessenten')
                    ->where('interessent_id', $id)
                    ->update($patch + ['updated_at' => now()]);
            }
        });

        \App\Helpers\Realtime::changed('interessenten');
        return response()->json(['success' => true]);
    }

    /** Download a communication attachment — same visibility rules as the record. */
    public function kommunikationDatei(Request $request, int $eintragId)
    {
        $eintrag = DB::connection('tenant')->table('interessent_kommunikation')
            ->where('eintrag_id', $eintragId)->first();
        if (!$eintrag || !$eintrag->datei_pfad) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }
        if (!$this->visibleProspect($request, (string) $eintrag->interessent_id)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }
        if (!Storage::disk('public')->exists($eintrag->datei_pfad)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }
        return response()->download(
            Storage::disk('public')->path($eintrag->datei_pfad),
            $eintrag->datei_name ?: basename($eintrag->datei_pfad)
        );
    }

    // ---------------------------------------------------------
    // Zu Kunde konvertieren
    // ---------------------------------------------------------

    public function konvertieren(Request $request, string $id)
    {
        // Converting creates a Kunde — same right as POST /customers.
        if (!$request->user()->hasPermission('stammdaten.kunden.edit')) {
            return response()->json(['error' => 'Keine Berechtigung, Kunden anzulegen'], 403);
        }
        $prospect = $this->visibleProspect($request, $id);
        if (!$prospect) {
            return response()->json(['error' => 'Interessent nicht gefunden'], 404);
        }
        if ($prospect->konvertiert_zu) {
            return response()->json(['error' => 'Bereits konvertiert', 'kunde_id' => $prospect->konvertiert_zu], 409);
        }
        // Per spec the happy path is "Gewonnen" — anything else needs the phase set first.
        if ($prospect->pipeline_phase !== 'Gewonnen') {
            return response()->json(['errors' => ['pipeline_phase' => 'Konvertieren ist nur aus der Phase „Gewonnen“ möglich.']], 422);
        }

        $kundeId = DB::connection('tenant')->transaction(function () use ($prospect, $id) {
            $kundeId = IdGenerator::next('kunden', 'kunde_id', 'K');

            $notizTeile = array_filter([
                'Aus Interessent ' . $id . ' konvertiert.',
                $prospect->branche ? 'Branche: ' . $prospect->branche : null,
                $prospect->quelle ? 'Quelle: ' . $prospect->quelle : null,
                $prospect->ansprechpartner_position ? 'Position: ' . $prospect->ansprechpartner_position : null,
                $prospect->mitarbeiterzahl_bereich ? 'Mitarbeiter: ' . $prospect->mitarbeiterzahl_bereich : null,
                $prospect->naechster_schritt ? 'Letzter Schritt: ' . $prospect->naechster_schritt : null,
            ]);

            $payload = [
                'kunde_id'       => $kundeId,
                'kundenname'     => $prospect->firmenname,
                'anschrift_typ'  => 'Firma',
                'haupt_ap'       => $prospect->ansprechpartner_name,
                'telefon1'       => $prospect->telefon,
                'email'          => $prospect->email,
                'ort'            => $prospect->einsatzgebiet,
                'notiz'          => implode("\n", $notizTeile),
                'aktiv'          => 1,
                'created_at'     => now(),
            ];
            if (Schema::connection('tenant')->hasColumn('kunden', 'updated_at')) {
                $payload['updated_at'] = now();
            }
            if (Schema::connection('tenant')->hasColumn('kunden', 'betreuer_id')) {
                $payload['betreuer_id'] = $prospect->zustaendiger_mitarbeiter;
            }

            DB::connection('tenant')->table('kunden')->insert($payload);
            DB::connection('tenant')->table('interessenten')
                ->where('interessent_id', $id)
                ->update(['konvertiert_zu' => $kundeId, 'updated_at' => now()]);

            return $kundeId;
        });

        \App\Helpers\Realtime::changed('interessenten');
        \App\Helpers\Realtime::changed('kunden');
        return response()->json(['success' => true, 'kunde_id' => $kundeId]);
    }
}
