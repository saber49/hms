<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\IdGenerator;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class SubcontractorController extends Controller
{
    private const LAENDER = ['D', 'AT', 'CH'];
    private const ANSCHRIFT_TYPEN = ['Firma', 'Privat'];

    /**
     * Standard NU document types — used for the compliance checklist.
     */
    public const STANDARD_DOK_TYPEN = [
        'Handelsregisterauszug',
        'Gewerbean-/ummeldung',
        'Auszug aus dem Gewerbezentralregister',
        'Unbedenklichkeitsbescheinigung Finanzamt',
        'Unbedenklichkeitsbescheinigung BG',
        'Unbedenklichkeitsbescheinigung Krankenkassen',
        'Nachweis der Betriebshaftpflichtversicherung',
        'Verpflichtung auf das Datengeheimnis',
        'Selbständigkeitserklärung',
        'Kopie Personalausweis des Vertragsunterzeichners',
    ];

    public function index(Request $request)
    {
        if ($request->has('id')) {
            return $this->show($request->id);
        }

        $hasUpdatedAt = Schema::connection('tenant')->hasColumn('nachunternehmer', 'updated_at');
        $hasGueltigBis = Schema::connection('tenant')->hasColumn('nu_dokumente', 'gueltig_bis');

        $select = ['n.*'];
        $select[] = DB::raw('(SELECT COUNT(*) FROM nu_portal_mitarbeiter WHERE nu_portal_mitarbeiter.nu_id = n.nu_id) AS mitarbeiter_total');
        $select[] = DB::raw('(SELECT COUNT(*) FROM nu_portal_mitarbeiter WHERE nu_portal_mitarbeiter.nu_id = n.nu_id AND nu_portal_mitarbeiter.aktiv = 1) AS mitarbeiter_aktiv');
        $select[] = DB::raw('(SELECT COUNT(*) FROM nu_dokumente WHERE nu_dokumente.nu_id = n.nu_id) AS dokumente_total');

        if ($hasGueltigBis) {
            $select[] = DB::raw("(SELECT COUNT(*) FROM nu_dokumente WHERE nu_dokumente.nu_id = n.nu_id AND nu_dokumente.gueltig_bis IS NOT NULL AND nu_dokumente.gueltig_bis < CURDATE()) AS dokumente_abgelaufen");
            $select[] = DB::raw("(SELECT COUNT(*) FROM nu_dokumente WHERE nu_dokumente.nu_id = n.nu_id AND nu_dokumente.gueltig_bis IS NOT NULL AND nu_dokumente.gueltig_bis >= CURDATE() AND nu_dokumente.gueltig_bis <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)) AS dokumente_ablaufend");
        }

        $query = DB::connection('tenant')->table('nachunternehmer as n')->select($select);

        if ($request->filled('aktiv')) {
            $query->where('n.aktiv', $request->aktiv);
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('n.firmenname', 'like', $s)
                  ->orWhere('n.nu_id', 'like', $s)
                  ->orWhere('n.bereich', 'like', $s)
                  ->orWhere('n.email', 'like', $s)
                  ->orWhere('n.ort', 'like', $s);
            });
        }
        if ($request->filled('bereich')) {
            [$bSql, $bP] = BereichScope::tagMatch(
                'n.bereich',
                Schema::connection('tenant')->hasColumn('nachunternehmer', 'bereiche') ? 'n.bereiche' : null,
                [mb_strtolower(trim((string) $request->bereich))]
            );
            $query->whereRaw($bSql, $bP);
        }

        // Per-user Bereich restriction: untagged NUs are the shared pool;
        // tagged ones need a matching Bereich or an in-scope Einsatz.
        $query->whereRaw(... BereichScope::nuPredicate($request->user(), 'n'));

        $orderCol = $hasUpdatedAt ? 'n.updated_at' : 'n.created_at';
        return response()->json($query->orderByDesc($orderCol)->orderBy('n.nu_id')->get());
    }

    public function show(string $id)
    {
        $nu = DB::connection('tenant')->table('nachunternehmer')->where('nu_id', $id)->first();
        if (!$nu || !BereichScope::allowsNu(request()->user(), $id)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden'], 404);
        }

        $nu->mitarbeiter_total = (int) DB::connection('tenant')->table('nu_portal_mitarbeiter')->where('nu_id', $id)->count();
        $nu->mitarbeiter_aktiv = (int) DB::connection('tenant')->table('nu_portal_mitarbeiter')->where('nu_id', $id)->where('aktiv', 1)->count();
        $nu->dokumente_total = (int) DB::connection('tenant')->table('nu_dokumente')->where('nu_id', $id)->count();

        return response()->json($nu);
    }

    public function store(Request $request)
    {
        $data = $request->all();

        $errors = $this->validateData($data, null);
        if (!empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }
        if (!BereichScope::bereichInputAllowed($request->user(), $data['bereich'] ?? null)) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        if (!BereichScope::bereicheInputAllowed($request->user(), $data['bereiche'] ?? [])) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }

        $nu_id = IdGenerator::next('nachunternehmer', 'nu_id', 'NU');
        $hasUpdatedAt = Schema::connection('tenant')->hasColumn('nachunternehmer', 'updated_at');

        $payload = [
            'nu_id' => $nu_id,
            'firmenname' => trim($data['firmenname']),
            'ansprechpartner' => $this->nullable($data['ansprechpartner'] ?? null),
            'telefon_email' => $this->nullable($data['telefon_email'] ?? null),
            'bereich' => $this->nullable($data['bereich'] ?? null),
            'anschrift_typ' => $data['anschrift_typ'] ?? 'Firma',
            'zusatz' => $this->nullable($data['zusatz'] ?? null),
            'strasse' => $this->nullable($data['strasse'] ?? null),
            'plz' => $this->nullable($data['plz'] ?? null),
            'ort' => $this->nullable($data['ort'] ?? null),
            'land' => $data['land'] ?? 'D',
            'telefon1' => $this->nullable($data['telefon1'] ?? null),
            'telefon2' => $this->nullable($data['telefon2'] ?? null),
            'fax' => $this->nullable($data['fax'] ?? null),
            'mobil' => $this->nullable($data['mobil'] ?? null),
            'email' => $this->nullable($data['email'] ?? null),
            'website' => $this->nullable($data['website'] ?? null),
            'notiz' => $this->nullable($data['notiz'] ?? null),
            'aktiv' => isset($data['aktiv']) ? (int) (bool) (int) $data['aktiv'] : 1,
        ];
        if (Schema::connection('tenant')->hasColumn('nachunternehmer', 'bereiche')) {
            $list = BereichScope::bereicheForStorage($data['bereiche'] ?? [], $payload['bereich']);
            $payload['bereiche'] = $list ? json_encode($list) : null;
        }

        if ($hasUpdatedAt) $payload['updated_at'] = now();

        DB::connection('tenant')->table('nachunternehmer')->insert($payload);

        return response()->json(['success' => true, 'nu_id' => $nu_id], 201);
    }

    public function update(Request $request, string $id)
    {
        $existing = DB::connection('tenant')->table('nachunternehmer')->where('nu_id', $id)->first();
        if (!$existing || !BereichScope::allowsNu($request->user(), $id)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden'], 404);
        }

        $data = $request->all();
        if (array_key_exists('bereich', $data)
            && !BereichScope::bereichWriteAllowed($request->user(), $data['bereich'], $existing->bereich ?? null)) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        if (array_key_exists('bereiche', $data)
            && !BereichScope::bereicheWriteAllowed(
                $request->user(), $data['bereiche'] ?? [],
                json_decode($existing->bereiche ?? '[]', true) ?: [])) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        $errors = $this->validateData($data, $id);

        // Portal fields — validated and merged into the same update when
        // the frontend sends portal_aktiv (i.e. the Portal tab was open).
        // Ja → portal_aktiv=1 activates; Nein → portal_aktiv=0 deactivates.
        // No auto-activation: the select is the single source of truth.
        $portalUpdate = [];
        if (array_key_exists('portal_aktiv', $data)) {
            $portalResult = $this->validateAndBuildPortalUpdate($data, $id);
            $errors = array_merge($errors, $portalResult['errors']);
            $portalUpdate = $portalResult['update'];
        }

        if (!empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        $hasUpdatedAt = Schema::connection('tenant')->hasColumn('nachunternehmer', 'updated_at');

        $payload = [
            'firmenname' => trim($data['firmenname']),
            'ansprechpartner' => $this->nullable($data['ansprechpartner'] ?? null),
            'telefon_email' => $this->nullable($data['telefon_email'] ?? null),
            'bereich' => $this->nullable($data['bereich'] ?? null),
            'anschrift_typ' => $data['anschrift_typ'] ?? 'Firma',
            'zusatz' => $this->nullable($data['zusatz'] ?? null),
            'strasse' => $this->nullable($data['strasse'] ?? null),
            'plz' => $this->nullable($data['plz'] ?? null),
            'ort' => $this->nullable($data['ort'] ?? null),
            'land' => $data['land'] ?? 'D',
            'telefon1' => $this->nullable($data['telefon1'] ?? null),
            'telefon2' => $this->nullable($data['telefon2'] ?? null),
            'fax' => $this->nullable($data['fax'] ?? null),
            'mobil' => $this->nullable($data['mobil'] ?? null),
            'email' => $this->nullable($data['email'] ?? null),
            'website' => $this->nullable($data['website'] ?? null),
            'notiz' => $this->nullable($data['notiz'] ?? null),
            'aktiv' => isset($data['aktiv']) ? (int) (bool) (int) $data['aktiv'] : 1,
        ];

        // Multi-service tags — same keep-merge as customers/objects.
        if (Schema::connection('tenant')->hasColumn('nachunternehmer', 'bereiche')) {
            $existingList = json_decode($existing->bereiche ?? '[]', true) ?: [];
            if (array_key_exists('bereiche', $data)) {
                $keep = BereichScope::outOfScopeValues($request->user(), $existingList);
                $list = BereichScope::bereicheForStorage(
                    array_merge((array) ($data['bereiche'] ?? []), $keep), $payload['bereich']);
                $payload['bereiche'] = $list ? json_encode($list) : null;
            } elseif (array_key_exists('bereich', $data)) {
                $list = BereichScope::bereicheForStorage($existingList, $payload['bereich']);
                $payload['bereiche'] = $list ? json_encode($list) : null;
            }
        }

        // Merge portal fields into the same single DB update
        $payload = array_merge($payload, $portalUpdate);

        if ($hasUpdatedAt) $payload['updated_at'] = now();

        DB::connection('tenant')->table('nachunternehmer')->where('nu_id', $id)->update($payload);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, string $id)
    {
        if (!BereichScope::allowsNu($request->user(), $id)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden'], 404);
        }
        if ($request->boolean('permanent')) {
            DB::connection('tenant')->beginTransaction();
            try {
                $db = DB::connection('tenant');

                // 1) Detach NU references on einsaetze (set null) — preserve einsaetze themselves
                $db->table('einsaetze')->where('nu_id', $id)->update(['nu_id' => null]);
                if (Schema::connection('tenant')->hasTable('einsatz_nu_status')) {
                    $db->table('einsatz_nu_status')->where('nu_id', $id)->delete();
                }
                if (Schema::connection('tenant')->hasTable('nu_position_zuweisungen')) {
                    $positionIds = $db->table('nu_schicht_positionen')->where('nu_id', $id)->pluck('id');
                    if ($positionIds->isNotEmpty()) {
                        $db->table('nu_position_zuweisungen')->whereIn('position_id', $positionIds)->delete();
                    }
                }
                if (Schema::connection('tenant')->hasTable('nu_schicht_positionen')) {
                    $db->table('nu_schicht_positionen')->where('nu_id', $id)->delete();
                }

                // 2) NU portal workers
                if (Schema::connection('tenant')->hasTable('nu_portal_mitarbeiter')) {
                    $db->table('nu_portal_mitarbeiter')->where('nu_id', $id)->delete();
                }

                // 3) Documents
                $db->table('nu_dokumente')->where('nu_id', $id)->delete();

                // 4) NU portal access tokens (central DB — bound to nu.id)
                $nuRow = $db->table('nachunternehmer')->where('nu_id', $id)->first();
                if ($nuRow) {
                    DB::connection('mysql')->table('personal_access_tokens')
                        ->where('tokenable_type', 'nu_portal')
                        ->where('tokenable_id', $nuRow->id)
                        ->delete();
                }

                // 5) Finally — the NU row itself
                $db->table('nachunternehmer')->where('nu_id', $id)->delete();

                DB::connection('tenant')->commit();
                return response()->json(['success' => true]);
            } catch (\Exception $e) {
                DB::connection('tenant')->rollBack();
                return response()->json(['error' => 'Löschen fehlgeschlagen: ' . $e->getMessage()], 500);
            }
        }

        $hasUpdatedAt = Schema::connection('tenant')->hasColumn('nachunternehmer', 'updated_at');
        $payload = ['aktiv' => 0];
        if ($hasUpdatedAt) $payload['updated_at'] = now();

        DB::connection('tenant')->table('nachunternehmer')->where('nu_id', $id)->update($payload);
        return response()->json(['success' => true]);
    }

    public function savePortal(Request $request)
    {
        $data = $request->all();
        $nuId = $data['nu_id'] ?? '';
        if (!$nuId) return response()->json(['error' => 'nu_id erforderlich'], 400);
        if (!BereichScope::allowsNu($request->user(), $nuId)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden'], 404);
        }

        $portalUsername = trim($data['portal_username'] ?? '');
        $portalAktiv = intval($data['portal_aktiv'] ?? 0);

        if ($portalUsername && !empty($data['portal_password_new']) && !$portalAktiv) {
            $portalAktiv = 1;
        }

        if ($portalAktiv && !$portalUsername) {
            return response()->json(['error' => 'Benutzername ist erforderlich wenn Portal aktiv'], 400);
        }

        if ($portalUsername) {
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $portalUsername)) {
                return response()->json(['error' => 'Benutzername: nur Buchstaben/Ziffern/. _ - erlaubt (3–50 Zeichen)'], 400);
            }
            $exists = DB::connection('tenant')->table('nachunternehmer')
                ->where('portal_username', $portalUsername)->where('nu_id', '!=', $nuId)->exists();
            if ($exists) return response()->json(['error' => 'Benutzername ist bereits vergeben'], 400);
        }

        $update = [
            'portal_username' => $portalUsername ?: null,
            'portal_aktiv' => $portalAktiv,
        ];

        if (!empty($data['portal_password_new'])) {
            if (strlen($data['portal_password_new']) < 6) {
                return response()->json(['error' => 'Passwort muss mindestens 6 Zeichen haben'], 400);
            }
            $update['portal_password'] = Hash::make($data['portal_password_new']);
        }

        if (Schema::connection('tenant')->hasColumn('nachunternehmer', 'updated_at')) {
            $update['updated_at'] = now();
        }

        DB::connection('tenant')->table('nachunternehmer')->where('nu_id', $nuId)->update($update);

        return response()->json(['success' => true]);
    }

    public function listWorkers(Request $request)
    {
        $nuId = $request->query('nu_id');
        if (!$nuId) return response()->json(['error' => 'nu_id erforderlich'], 400);
        if (!BereichScope::allowsNu($request->user(), $nuId)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden'], 404);
        }

        $workers = DB::connection('tenant')->table('nu_portal_mitarbeiter')
            ->where('nu_id', $nuId)
            ->orderByDesc('aktiv')->orderBy('vorname')->orderBy('name')
            ->get();

        return response()->json($workers);
    }

    /**
     * Aggregated stats for a single NU (used in the modal "Übersicht" tab).
     */
    public function stats(string $id)
    {
        $db = DB::connection('tenant');
        if (!$db->table('nachunternehmer')->where('nu_id', $id)->exists()
            || !BereichScope::allowsNu(request()->user(), $id)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden'], 404);
        }

        $hasGueltigBis = Schema::connection('tenant')->hasColumn('nu_dokumente', 'gueltig_bis');

        $mitarbeiterTotal = $db->table('nu_portal_mitarbeiter')->where('nu_id', $id)->count();
        $mitarbeiterAktiv = $db->table('nu_portal_mitarbeiter')->where('nu_id', $id)->where('aktiv', 1)->count();
        $ausweisAblaufend = $db->table('nu_portal_mitarbeiter')->where('nu_id', $id)
            ->whereNotNull('ausweis_gueltig_bis')
            ->where('ausweis_gueltig_bis', '<=', now()->addDays(60))
            ->count();
        $ausweisAbgelaufen = $db->table('nu_portal_mitarbeiter')->where('nu_id', $id)
            ->whereNotNull('ausweis_gueltig_bis')
            ->where('ausweis_gueltig_bis', '<', now())
            ->count();

        $dokTotal = $db->table('nu_dokumente')->where('nu_id', $id)->count();
        $dokAbgelaufen = 0;
        $dokAblaufend = 0;
        if ($hasGueltigBis) {
            $dokAbgelaufen = $db->table('nu_dokumente')->where('nu_id', $id)
                ->whereNotNull('gueltig_bis')->whereDate('gueltig_bis', '<', now())->count();
            $dokAblaufend = $db->table('nu_dokumente')->where('nu_id', $id)
                ->whereNotNull('gueltig_bis')
                ->whereDate('gueltig_bis', '>=', now())
                ->whereDate('gueltig_bis', '<=', now()->addDays(60))
                ->count();
        }

        $einsaetzeTotal = $db->table('einsaetze')->where('nu_id', $id)->count();
        // "Offen" = nicht abgelehnt UND nicht bestätigt (ausstehend, ggf. mit pruefung='geplant').
        // hms_bestaetigt akzeptiert nur ja|nein|ausstehend; 'geplant' ist eine separate Spalte (pruefung).
        $einsaetzeOffen = $db->table('einsaetze')->where('nu_id', $id)
            ->where('hms_bestaetigt', 'ausstehend')->count();
        $lastEinsatz = $db->table('einsaetze')->where('nu_id', $id)->orderByDesc('datum')->value('datum');

        return response()->json([
            'nu_id' => $id,
            'mitarbeiter_total' => (int) $mitarbeiterTotal,
            'mitarbeiter_aktiv' => (int) $mitarbeiterAktiv,
            'ausweis_abgelaufen' => (int) $ausweisAbgelaufen,
            'ausweis_ablaufend' => (int) $ausweisAblaufend,
            'dokumente_total' => (int) $dokTotal,
            'dokumente_abgelaufen' => (int) $dokAbgelaufen,
            'dokumente_ablaufend' => (int) $dokAblaufend,
            'einsaetze_total' => (int) $einsaetzeTotal,
            'einsaetze_offen' => (int) $einsaetzeOffen,
            'last_einsatz_date' => $lastEinsatz,
        ]);
    }

    /**
     * Compliance checklist: returns each of the standard 10 NU document types
     * and whether the NU has a valid one on file.
     */
    public function checklist(string $id)
    {
        $db = DB::connection('tenant');
        if (!$db->table('nachunternehmer')->where('nu_id', $id)->exists()
            || !BereichScope::allowsNu(request()->user(), $id)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden'], 404);
        }

        $hasGueltigBis = Schema::connection('tenant')->hasColumn('nu_dokumente', 'gueltig_bis');

        $docs = $db->table('nu_dokumente')->where('nu_id', $id)->orderByDesc('hochgeladen_am')->get();

        $today = now()->toDateString();
        $warnDate = now()->addDays(60)->toDateString();

        $checklist = [];
        foreach (self::STANDARD_DOK_TYPEN as $typ) {
            $matching = $docs->filter(fn($d) => $d->dok_typ === $typ)->values();

            if ($matching->isEmpty()) {
                $checklist[] = [
                    'dok_typ' => $typ,
                    'status' => 'missing',
                    'doc' => null,
                ];
                continue;
            }

            $latest = $matching->first(); // already sorted by hochgeladen_am desc

            $status = 'ok';
            if ($hasGueltigBis && !empty($latest->gueltig_bis)) {
                if ($latest->gueltig_bis < $today) $status = 'expired';
                elseif ($latest->gueltig_bis <= $warnDate) $status = 'expiring';
            }

            $checklist[] = [
                'dok_typ' => $typ,
                'status' => $status,
                'doc' => [
                    'id' => $latest->id,
                    'titel' => $latest->titel,
                    'dateipfad' => $latest->dateipfad,
                    'hochgeladen_am' => $latest->hochgeladen_am,
                    'gueltig_ab' => $hasGueltigBis ? ($latest->gueltig_ab ?? null) : null,
                    'gueltig_bis' => $hasGueltigBis ? ($latest->gueltig_bis ?? null) : null,
                    'count' => $matching->count(),
                ],
            ];
        }

        $missing = collect($checklist)->where('status', 'missing')->count();
        $expired = collect($checklist)->where('status', 'expired')->count();
        $expiring = collect($checklist)->where('status', 'expiring')->count();
        $ok = collect($checklist)->where('status', 'ok')->count();

        return response()->json([
            'nu_id' => $id,
            'summary' => [
                'total_required' => count(self::STANDARD_DOK_TYPEN),
                'ok' => $ok,
                'expiring' => $expiring,
                'expired' => $expired,
                'missing' => $missing,
            ],
            'checklist' => $checklist,
        ]);
    }

    /**
     * Preview what would be deleted on permanent delete.
     */
    public function deletePreview(string $id)
    {
        $db = DB::connection('tenant');
        if (!$db->table('nachunternehmer')->where('nu_id', $id)->exists()
            || !BereichScope::allowsNu(request()->user(), $id)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden'], 404);
        }

        $mitarbeiter = $db->table('nu_portal_mitarbeiter')->where('nu_id', $id)->count();
        $dokumente = $db->table('nu_dokumente')->where('nu_id', $id)->count();
        $positionen = Schema::connection('tenant')->hasTable('nu_schicht_positionen')
            ? $db->table('nu_schicht_positionen')->where('nu_id', $id)->count() : 0;
        $einsaetzeRefs = $db->table('einsaetze')->where('nu_id', $id)->count();

        return response()->json([
            'nu_id' => $id,
            'mitarbeiter' => (int) $mitarbeiter,
            'dokumente' => (int) $dokumente,
            'positionen' => (int) $positionen,
            'einsaetze_refs' => (int) $einsaetzeRefs,
            'note' => 'Einsätze werden NICHT gelöscht — nur die NU-Verknüpfung wird entfernt.',
        ]);
    }

    // ====================================================================
    // Helpers
    // ====================================================================

    /**
     * Validate portal fields and build the update array.
     * Returns ['errors' => [...], 'update' => [...]].
     * No auto-activation — portal_aktiv is taken verbatim from the request.
     */
    protected function validateAndBuildPortalUpdate(array $data, string $nuId): array
    {
        $errors = [];
        $portalUsername = trim($data['portal_username'] ?? '');
        $portalAktiv = intval($data['portal_aktiv'] ?? 0);

        if ($portalAktiv && !$portalUsername) {
            $errors['portal_username'][] = 'Benutzername ist erforderlich wenn Portal aktiv';
        }

        if ($portalUsername) {
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $portalUsername)) {
                $errors['portal_username'][] = 'Benutzername: nur Buchstaben/Ziffern/. _ - erlaubt (3–50 Zeichen)';
            }
            $exists = DB::connection('tenant')->table('nachunternehmer')
                ->where('portal_username', $portalUsername)->where('nu_id', '!=', $nuId)->exists();
            if ($exists) {
                $errors['portal_username'][] = 'Benutzername ist bereits vergeben';
            }
        }

        if (!empty($data['portal_password_new']) && strlen($data['portal_password_new']) < 6) {
            $errors['portal_password'][] = 'Passwort muss mindestens 6 Zeichen haben';
        }

        $update = [
            'portal_username' => $portalUsername ?: null,
            'portal_aktiv' => $portalAktiv,
        ];

        if (!empty($data['portal_password_new']) && !isset($errors['portal_password'])) {
            $update['portal_password'] = Hash::make($data['portal_password_new']);
        }

        return ['errors' => $errors, 'update' => $update];
    }

    protected function validateData(array $data, ?string $currentId): array
    {
        $errors = [];

        $name = trim((string) ($data['firmenname'] ?? ''));
        if ($name === '') {
            $errors['firmenname'][] = 'Firmenname ist erforderlich.';
        } elseif (mb_strlen($name) > 200) {
            $errors['firmenname'][] = 'Firmenname darf maximal 200 Zeichen haben.';
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Ungültige E-Mail-Adresse.';
        }

        $plz = trim((string) ($data['plz'] ?? ''));
        if ($plz !== '' && !preg_match('/^\d{4,5}$/', $plz)) {
            $errors['plz'][] = 'PLZ muss aus 4 bis 5 Ziffern bestehen.';
        }

        $land = (string) ($data['land'] ?? 'D');
        if (!in_array($land, self::LAENDER, true)) {
            $errors['land'][] = 'Ungültiges Land.';
        }

        $atyp = (string) ($data['anschrift_typ'] ?? 'Firma');
        if (!in_array($atyp, self::ANSCHRIFT_TYPEN, true)) {
            $errors['anschrift_typ'][] = 'Ungültiger Anschrift-Typ.';
        }

        $website = trim((string) ($data['website'] ?? ''));
        if ($website !== '') {
            $candidate = preg_match('#^https?://#i', $website) ? $website : 'http://' . $website;
            if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
                $errors['website'][] = 'Ungültige Website-URL.';
            }
        }

        return $errors;
    }

    private function nullable($v)
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
