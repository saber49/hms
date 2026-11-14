<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\IdGenerator;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class CustomerController extends Controller
{
    /**
     * Allowed billing types.
     */
    private const ABRECHNUNGSTYPEN = [
        'Pro Einheit',
        'Pro Stunde',
        'Monatspauschale/gemischt',
        'Pauschal',
    ];

    private const LAENDER = ['D', 'AT', 'CH'];

    private const ANSCHRIFT_TYPEN = ['Firma', 'Privat'];

    public function index(Request $request)
    {
        if ($request->has('id')) {
            return $this->show($request->id);
        }

        $db = DB::connection('tenant');
        $hasUpdatedAt = Schema::connection('tenant')->hasColumn('kunden', 'updated_at');

        $select = ['k.*'];
        // Object counts subquery: per-customer total + active
        $select[] = DB::raw('(SELECT COUNT(*) FROM objekte WHERE objekte.kunden_id = k.kunde_id) AS objekte_total');
        $select[] = DB::raw('(SELECT COUNT(*) FROM objekte WHERE objekte.kunden_id = k.kunde_id AND objekte.aktiv = 1) AS objekte_aktiv');

        $query = $db->table('kunden as k')->select($select);

        if ($request->filled('aktiv')) {
            $query->where('k.aktiv', $request->aktiv);
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('k.kundenname', 'like', $s)
                  ->orWhere('k.kunde_id', 'like', $s)
                  ->orWhere('k.ort', 'like', $s)
                  ->orWhere('k.email', 'like', $s)
                  ->orWhere('k.kunden_nr_kunde', 'like', $s);
            });
        }
        if ($request->filled('bereich')) {
            $b = $request->bereich;
            $query->where(function ($q) use ($b) {
                [$tSql, $tP] = BereichScope::tagMatch(
                    'k.bereich',
                    Schema::connection('tenant')->hasColumn('kunden', 'bereiche') ? 'k.bereiche' : null,
                    [mb_strtolower(trim((string) $b))]
                );
                $q->whereRaw($tSql, $tP)
                  ->orWhereIn('k.kunde_id', function ($sub) use ($b) {
                      // Customers whose objects own at least one active artikel in this bereich
                      $sub->select('o.kunden_id')->from('objekte as o')
                          ->join('artikel as a', 'a.obj_id', '=', 'o.obj_id')
                          ->where('a.bereich', $b)
                          ->where('a.aktiv', 1)
                          ->distinct();
                  });
            });
        }

        // Per-user Bereich restriction: any of the customer's service tags
        // (primary bereich or bereiche list) must be in his whitelist.
        [$scopeSql, $scopeParams] = BereichScope::kundePredicate($request->user(), 'k');
        $query->whereRaw($scopeSql, $scopeParams);

        $orderCol = $hasUpdatedAt ? 'k.updated_at' : 'k.created_at';
        return response()->json($query->orderByDesc($orderCol)->orderBy('k.kunde_id')->get());
    }

    /**
     * GET /api/customers/betreuer-options — active users of the company that
     * can be the Kundenbetreuer (account manager) of a customer. Same user
     * set as the Auftragsbestätigung "Bearbeiter" picker.
     */
    public function betreuerOptions(Request $request)
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

    /** null = no Betreuer, false = not an active user of this company, else id. */
    private function betreuerInput(Request $request, $value): int|null|false
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') return null;
        if (!is_numeric($value)) return false;
        $ok = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', (int) $request->user()->tenant_id)->where('id', (int) $value)
            ->where('aktiv', 1)->whereRaw("name NOT LIKE 'deleted\\_%'")->exists();
        return $ok ? (int) $value : false;
    }

    public function show(string $id)
    {
        $customer = DB::connection('tenant')->table('kunden')->where('kunde_id', $id)->first();
        if (!$customer || !BereichScope::allowsKunde(request()->user(), $id)) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }

        // Inline counts for richer UI
        $customer->objekte_total = (int) DB::connection('tenant')->table('objekte')->where('kunden_id', $id)->count();
        $customer->objekte_aktiv = (int) DB::connection('tenant')->table('objekte')->where('kunden_id', $id)->where('aktiv', 1)->count();

        return response()->json($customer);
    }

    public function store(Request $request)
    {
        $data = $request->all();

        // Older clients may echo `bereiche` back as its raw JSON string.
        if (isset($data['bereiche']) && is_string($data['bereiche'])) {
            $data['bereiche'] = json_decode($data['bereiche'], true) ?: [];
        }

        $errors = $this->validateData($data, null);
        if (!empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        // Bereich restriction: a restricted user may only tag customers with
        // his own Bereiche; an empty tag would create a customer he cannot
        // see, so we default it to his first allowed Bereich.
        if (!BereichScope::bereichInputAllowed($request->user(), $data['bereich'] ?? null)) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        if (trim((string) ($data['bereich'] ?? '')) === ''
            && ($defaultBereich = BereichScope::defaultBereichFor($request->user())) !== null) {
            $data['bereich'] = $defaultBereich;
        }
        // Multi-service tags: restricted users may only tag their own Bereiche.
        if (!BereichScope::bereicheInputAllowed($request->user(), $data['bereiche'] ?? [])) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }

        $kunde_id = IdGenerator::next('kunden', 'kunde_id', 'K');
        $hasUpdatedAt = Schema::connection('tenant')->hasColumn('kunden', 'updated_at');

        $payload = [
            'kunde_id' => $kunde_id,
            'kundenname' => trim($data['kundenname']),
            'kunden_nr_kunde' => $this->nullable($data['kunden_nr_kunde'] ?? null),
            'kunden_nr_intern' => $kunde_id,
            'abrechnungstyp' => $data['abrechnungstyp'] ?? 'Pro Einheit',
            'anschrift_typ' => $data['anschrift_typ'] ?? 'Firma',
            'zusatz' => $this->nullable($data['zusatz'] ?? null),
            'strasse' => $this->nullable($data['strasse'] ?? null),
            'land' => $data['land'] ?? 'D',
            'plz' => $this->nullable($data['plz'] ?? null),
            'ort' => $this->nullable($data['ort'] ?? null),
            'telefon1' => $this->nullable($data['telefon1'] ?? null),
            'telefon2' => $this->nullable($data['telefon2'] ?? null),
            'fax' => $this->nullable($data['fax'] ?? null),
            'mobil' => $this->nullable($data['mobil'] ?? null),
            'email' => $this->nullable($data['email'] ?? null),
            'website' => $this->nullable($data['website'] ?? null),
            'haupt_ap' => $this->nullable($data['haupt_ap'] ?? null),
            'notiz' => $this->nullable($data['notiz'] ?? null),
            'bereich' => $this->nullable($data['bereich'] ?? null),
            'aktiv' => isset($data['aktiv']) ? (int) (bool) (int) $data['aktiv'] : 1,
        ];

        if ($hasUpdatedAt) {
            $payload['updated_at'] = now();
        }
        if (Schema::connection('tenant')->hasColumn('kunden', 'rechnung_email')) {
            $payload['rechnung_email'] = $this->nullable($data['rechnung_email'] ?? null);
        }
        if (Schema::connection('tenant')->hasColumn('kunden', 'farbe')) {
            $payload['farbe'] = $this->nullable($data['farbe'] ?? null);
        }
        if (Schema::connection('tenant')->hasColumn('kunden', 'bereiche')) {
            $list = BereichScope::bereicheForStorage($data['bereiche'] ?? [], $payload['bereich']);
            $payload['bereiche'] = $list ? json_encode($list) : null;
        }
        if (array_key_exists('betreuer_id', $data) && Schema::connection('tenant')->hasColumn('kunden', 'betreuer_id')) {
            $b = $this->betreuerInput($request, $data['betreuer_id']);
            if ($b === false) return response()->json(['errors' => ['betreuer_id' => 'Kundenbetreuer nicht gefunden']], 422);
            $payload['betreuer_id'] = $b;
        }

        DB::connection('tenant')->table('kunden')->insert($payload);
        \App\Helpers\Realtime::changed('kunden');

        return response()->json(['success' => true, 'kunde_id' => $kunde_id], 201);
    }

    public function update(Request $request, string $id)
    {
        $existing = DB::connection('tenant')->table('kunden')->where('kunde_id', $id)->first();
        if (!$existing || !BereichScope::allowsKunde($request->user(), $id)) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }

        $data = $request->all();
        // Older clients may echo `bereiche` back as its raw JSON string.
        if (isset($data['bereiche']) && is_string($data['bereiche'])) {
            $data['bereiche'] = json_decode($data['bereiche'], true) ?: [];
        }
        // Re-submitting the stored primary tag unchanged is fine; changing
        // it to an out-of-scope value is a scope write and gets rejected.
        if (!BereichScope::bereichWriteAllowed($request->user(), $data['bereich'] ?? null, $existing->bereich ?? null)) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        if (array_key_exists('bereiche', $data)
            && !BereichScope::bereicheWriteAllowed(
                $request->user(), $data['bereiche'] ?? [],
                json_decode($existing->bereiche ?? '[]', true) ?: [])) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        $errors = $this->validateData($data, $id);
        if (!empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        $hasUpdatedAt = Schema::connection('tenant')->hasColumn('kunden', 'updated_at');

        $payload = [
            'kundenname' => trim($data['kundenname']),
            'kunden_nr_kunde' => $this->nullable($data['kunden_nr_kunde'] ?? null),
            'abrechnungstyp' => $data['abrechnungstyp'] ?? 'Pro Einheit',
            'anschrift_typ' => $data['anschrift_typ'] ?? 'Firma',
            'zusatz' => $this->nullable($data['zusatz'] ?? null),
            'strasse' => $this->nullable($data['strasse'] ?? null),
            'land' => $data['land'] ?? 'D',
            'plz' => $this->nullable($data['plz'] ?? null),
            'ort' => $this->nullable($data['ort'] ?? null),
            'telefon1' => $this->nullable($data['telefon1'] ?? null),
            'telefon2' => $this->nullable($data['telefon2'] ?? null),
            'fax' => $this->nullable($data['fax'] ?? null),
            'mobil' => $this->nullable($data['mobil'] ?? null),
            'email' => $this->nullable($data['email'] ?? null),
            'website' => $this->nullable($data['website'] ?? null),
            'haupt_ap' => $this->nullable($data['haupt_ap'] ?? null),
            'notiz' => $this->nullable($data['notiz'] ?? null),
            // Absent `aktiv`/`bereich` keep the stored values — a partial update
            // must not silently un-archive the customer or strip his Bereich.
            'bereich' => array_key_exists('bereich', $data)
                ? $this->nullable($data['bereich'])
                : ($existing->bereich ?? null),
            'aktiv' => isset($data['aktiv'])
                ? (int) (bool) (int) $data['aktiv']
                : (int) ($existing->aktiv ?? 1),
        ];

        if ($hasUpdatedAt) {
            $payload['updated_at'] = now();
        }
        if (Schema::connection('tenant')->hasColumn('kunden', 'rechnung_email')) {
            $payload['rechnung_email'] = $this->nullable($data['rechnung_email'] ?? null);
        }
        if (Schema::connection('tenant')->hasColumn('kunden', 'farbe')) {
            $payload['farbe'] = $this->nullable($data['farbe'] ?? null);
        }
        if (Schema::connection('tenant')->hasColumn('kunden', 'bereiche')) {
            $existingList = json_decode($existing->bereiche ?? '[]', true) ?: [];
            // A restricted user's picker only shows HIS Bereiche — entries he
            // cannot see must be preserved verbatim, not silently stripped.
            $keep = BereichScope::outOfScopeValues($request->user(), $existingList);
            if (array_key_exists('bereiche', $data)) {
                $list = BereichScope::bereicheForStorage(
                    array_merge((array) ($data['bereiche'] ?? []), $keep), $payload['bereich']);
                $payload['bereiche'] = $list ? json_encode($list) : null;
            } elseif (array_key_exists('bereich', $data)) {
                // Primary tag changed but no list was sent (older client):
                // merge the new primary into the stored service list.
                $list = BereichScope::bereicheForStorage($existingList, $payload['bereich']);
                $payload['bereiche'] = $list ? json_encode($list) : null;
            }
        }
        // Only touched when sent — older clients never wipe the Betreuer.
        if (array_key_exists('betreuer_id', $data) && Schema::connection('tenant')->hasColumn('kunden', 'betreuer_id')) {
            $b = $this->betreuerInput($request, $data['betreuer_id']);
            if ($b === false) return response()->json(['errors' => ['betreuer_id' => 'Kundenbetreuer nicht gefunden']], 422);
            $payload['betreuer_id'] = $b;
        }

        DB::connection('tenant')->table('kunden')->where('kunde_id', $id)->update($payload);
        \App\Helpers\Realtime::changed('kunden');

        return response()->json(['success' => true]);
    }

    /**
     * Returns all distinct bereiche from kunden (legacy) + artikel (new home).
     */
    public function bereiche()
    {
        $db = DB::connection('tenant');

        $values = collect()
            ->merge($db->table('kunden')->whereNotNull('bereich')->where('bereich', '!=', '')->pluck('bereich'));

        if (Schema::connection('tenant')->hasTable('artikel')) {
            $values = $values->merge(
                $db->table('artikel')->whereNotNull('bereich')->where('bereich', '!=', '')->pluck('bereich')
            );
        }

        // Legacy fallbacks for tenants that still rely on these columns
        $values = $values
            ->merge($db->table('objekte')->whereNotNull('bereich')->where('bereich', '!=', '')->pluck('bereich'))
            ->merge($db->table('nachunternehmer')->whereNotNull('bereich')->where('bereich', '!=', '')->pluck('bereich'))
            ->merge($db->table('mitarbeiter')->whereNotNull('bereich')->where('bereich', '!=', '')->pluck('bereich'))
            // Multi-service tags stored in the JSON bereiche columns.
            ->merge(BereichScope::availableForTenant());

        $values = $values
            ->map(fn($v) => trim((string) $v))
            ->flatMap(fn($v) => str_contains($v, ',') ? array_map('trim', explode(',', $v)) : [$v])
            ->filter()
            ->unique(fn($v) => mb_strtolower($v))
            ->sort()
            ->values();

        // Restricted users only pick from their own Bereiche.
        $allowed = BereichScope::for(request()->user());
        if (is_array($allowed)) {
            $values = $values->filter(fn($v) => in_array(mb_strtolower($v), $allowed, true))->values();
        }

        return response()->json($values);
    }

    /**
     * Aggregate stats for a single customer (used in modal "Übersicht").
     */
    public function stats(string $id)
    {
        $db = DB::connection('tenant');
        if (!$db->table('kunden')->where('kunde_id', $id)->exists()
            || !BereichScope::allowsKunde(request()->user(), $id)) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }

        $objekteTotal = $db->table('objekte')->where('kunden_id', $id)->count();
        $objekteAktiv = $db->table('objekte')->where('kunden_id', $id)->where('aktiv', 1)->count();

        $artikelAktiv = 0;
        if (Schema::connection('tenant')->hasTable('artikel')) {
            $artikelAktiv = $db->table('artikel as a')
                ->join('objekte as o', 'o.obj_id', '=', 'a.obj_id')
                ->where('o.kunden_id', $id)
                ->where('a.aktiv', 1)
                ->count();
        }

        $bestellungenTotal = $db->table('bestellungen')->where('kunden_id', $id)->count();
        $bestellungenLaufend = $db->table('bestellungen')->where('kunden_id', $id)
            ->whereIn('status', ['offen', 'in_bearbeitung', 'aktiv'])->count();

        $einsaetzeTotal = $db->table('einsaetze as e')
            ->join('bestellungen as b', 'b.bestell_id', '=', 'e.bestell_id')
            ->where('b.kunden_id', $id)
            ->count();

        $lastEinsatz = $db->table('einsaetze as e')
            ->join('bestellungen as b', 'b.bestell_id', '=', 'e.bestell_id')
            ->where('b.kunden_id', $id)
            ->orderByDesc('e.datum')
            ->limit(1)
            ->value('e.datum');

        $documents = $db->table('kunden_dokumente')->where('kunde_id', $id)->count();

        return response()->json([
            'kunde_id' => $id,
            'objekte_total' => (int) $objekteTotal,
            'objekte_aktiv' => (int) $objekteAktiv,
            'artikel_aktiv' => (int) $artikelAktiv,
            'bestellungen_total' => (int) $bestellungenTotal,
            'bestellungen_laufend' => (int) $bestellungenLaufend,
            'einsaetze_total' => (int) $einsaetzeTotal,
            'last_einsatz_date' => $lastEinsatz,
            'documents' => (int) $documents,
        ]);
    }

    /**
     * Preview impact of permanent deletion.
     */
    public function deletePreview(string $id)
    {
        $db = DB::connection('tenant');
        if (!$db->table('kunden')->where('kunde_id', $id)->exists()
            || !BereichScope::allowsKunde(request()->user(), $id)) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }

        $objIds = $db->table('objekte')->where('kunden_id', $id)->pluck('obj_id')->toArray();

        $objekte = count($objIds);
        $artikel = !empty($objIds) && Schema::connection('tenant')->hasTable('artikel')
            ? $db->table('artikel')->whereIn('obj_id', $objIds)->count() : 0;
        $bestellungen = $db->table('bestellungen')->where('kunden_id', $id)->count();
        $einsaetze = $db->table('einsaetze as e')
            ->join('bestellungen as b', 'b.bestell_id', '=', 'e.bestell_id')
            ->where('b.kunden_id', $id)->count();
        $bestelleingang = $db->table('bestelleingang')->where('kunden_id', $id)->count();
        $documents = $db->table('kunden_dokumente')->where('kunde_id', $id)->count();

        return response()->json([
            'kunde_id' => $id,
            'objekte' => (int) $objekte,
            'artikel' => (int) $artikel,
            'bestellungen' => (int) $bestellungen,
            'einsaetze' => (int) $einsaetze,
            'bestelleingang' => (int) $bestelleingang,
            'documents' => (int) $documents,
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        if (!BereichScope::allowsKunde($request->user(), $id)) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }
        if ($request->boolean('permanent')) {
            DB::connection('tenant')->beginTransaction();
            try {
                $db = DB::connection('tenant');

                // Storage deletes are not transactional — collect the doc
                // paths now, sweep the files after a successful commit.
                $docPaths = $db->table('kunden_dokumente')->where('kunde_id', $id)
                    ->pluck('dateipfad')->filter()->toArray();

                // Object IDs for this customer (used for artikel cleanup)
                $objIds = $db->table('objekte')->where('kunden_id', $id)->pluck('obj_id')->toArray();

                // All einsaetze of this customer: via his bestellungen AND
                // via the direct kunden_id tag (nullable since 2026_07_03).
                $hasEKunde = Schema::connection('tenant')->hasColumn('einsaetze', 'kunden_id');
                $einsatzIds = $db->table('einsaetze as e')
                    ->leftJoin('bestellungen as b', 'b.bestell_id', '=', 'e.bestell_id')
                    ->where(function ($q) use ($id, $hasEKunde) {
                        $q->where('b.kunden_id', $id);
                        if ($hasEKunde) {
                            $q->orWhere('e.kunden_id', $id);
                        }
                    })
                    ->pluck('e.einsatz_id')->toArray();

                $msgPaths = [];
                $portalIds = [];
                if (!empty($einsatzIds)) {
                    if (Schema::connection('tenant')->hasTable('nu_schicht_positionen')
                        && Schema::connection('tenant')->hasTable('nu_position_zuweisungen')) {
                        $posIds = $db->table('nu_schicht_positionen')
                            ->whereIn('einsatz_id', $einsatzIds)->pluck('id')->toArray();
                        if (!empty($posIds)) {
                            $db->table('nu_position_zuweisungen')->whereIn('position_id', $posIds)->delete();
                        }
                    }
                    foreach (['einsatz_mitarbeiter', 'einsatz_nu_status', 'nu_schicht_positionen',
                              'zeiterfassung', 'einsatz_reminders_sent'] as $t) {
                        if (Schema::connection('tenant')->hasTable($t)) {
                            $db->table($t)->whereIn('einsatz_id', $einsatzIds)->delete();
                        }
                    }
                    if (Schema::connection('tenant')->hasTable('mitarbeiter_nachrichten')
                        && Schema::connection('tenant')->hasColumn('mitarbeiter_nachrichten', 'einsatz_id')) {
                        if (Schema::connection('tenant')->hasColumn('mitarbeiter_nachrichten', 'datei_pfad')) {
                            $msgPaths = $db->table('mitarbeiter_nachrichten')
                                ->whereIn('einsatz_id', $einsatzIds)
                                ->pluck('datei_pfad')->filter()->toArray();
                        }
                        $db->table('mitarbeiter_nachrichten')->whereIn('einsatz_id', $einsatzIds)->delete();
                    }
                }

                $einsaetzeQ = $db->table('einsaetze')->whereIn('bestell_id', function ($q) use ($id) {
                    $q->select('bestell_id')->from('bestellungen')->where('kunden_id', $id);
                });
                if ($hasEKunde) {
                    $einsaetzeQ->orWhere('kunden_id', $id);
                }
                $einsaetzeQ->delete();
                $db->table('bestellungen')->where('kunden_id', $id)->delete();
                $db->table('bestelleingang_tage')->whereIn('eingang_id', function ($q) use ($id) {
                    $q->select('eingang_id')->from('bestelleingang')->where('kunden_id', $id);
                })->delete();
                $db->table('bestelleingang')->where('kunden_id', $id)->delete();

                if (Schema::connection('tenant')->hasTable('preishistorie')) {
                    if (Schema::connection('tenant')->hasColumn('preishistorie', 'kunden_id')) {
                        $db->table('preishistorie')->where('kunden_id', $id)->delete();
                    }
                    if (!empty($objIds)) {
                        $db->table('preishistorie')->whereIn('obj_id', $objIds)->delete();
                    }
                }
                if (!empty($objIds)) {
                    if (Schema::connection('tenant')->hasTable('artikel')) {
                        $db->table('artikel')->whereIn('obj_id', $objIds)->delete();
                    }
                    // QM records (Wachbuch, Reklamationen, Maßnahmen …) are
                    // never deleted — legal retention (BewachV / HGB / ISO 9001).
                }

                $db->table('objekte')->where('kunden_id', $id)->delete();
                $db->table('kunden_dokumente')->where('kunde_id', $id)->delete();
                if (Schema::connection('tenant')->hasTable('kunden_favoriten')) {
                    $db->table('kunden_favoriten')->where('kunde_id', $id)->delete();
                }
                if (Schema::connection('tenant')->hasTable('kunden_portal_zugaenge')) {
                    $portalIds = $db->table('kunden_portal_zugaenge')->where('kunde_id', $id)
                        ->pluck('id')->toArray();
                    $db->table('kunden_portal_zugaenge')->where('kunde_id', $id)->delete();
                }
                $db->table('kunden')->where('kunde_id', $id)->delete();

                DB::connection('tenant')->commit();
                foreach (array_merge($docPaths, $msgPaths) as $p) {
                    try {
                        Storage::disk('public')->delete($p);
                        \App\Services\DocumentThumbnailer::delete('public', $p);
                    } catch (\Throwable $e) {}
                }
                if (!empty($portalIds)) {
                    try {
                        DB::table('personal_access_tokens')
                            ->where('tokenable_type', 'kunden_portal')
                            ->whereIn('tokenable_id', $portalIds)->delete();
                    } catch (\Throwable $e) {}
                }
                \App\Helpers\Realtime::changed('kunden');
                return response()->json(['success' => true]);
            } catch (\Exception $e) {
                DB::connection('tenant')->rollBack();
                return response()->json(['error' => 'Löschen fehlgeschlagen: ' . $e->getMessage()], 500);
            }
        }

        $hasUpdatedAt = Schema::connection('tenant')->hasColumn('kunden', 'updated_at');
        $payload = ['aktiv' => 0];
        if ($hasUpdatedAt) {
            $payload['updated_at'] = now();
        }
        DB::connection('tenant')->table('kunden')->where('kunde_id', $id)->update($payload);
        \App\Helpers\Realtime::changed('kunden');
        return response()->json(['success' => true]);
    }

    // ===========================================================
    // FAVORITE WORKERS (Stamm-Team) — per customer
    // ===========================================================

    /**
     * Returns the customer's favorite workers (dienst_nr list) plus the full
     * active-worker roster so the modal can render a selectable checklist.
     */
    public function favorites(string $id)
    {
        $db = DB::connection('tenant');
        if (!$db->table('kunden')->where('kunde_id', $id)->exists()
            || !BereichScope::allowsKunde(request()->user(), $id)) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }

        $favorites = [];
        if (Schema::connection('tenant')->hasTable('kunden_favoriten')) {
            $favorites = $db->table('kunden_favoriten')
                ->where('kunde_id', $id)
                ->pluck('dienst_nr')
                ->toArray();
        }

        [$wSql, $wParams] = BereichScope::workerPredicate(request()->user(), 'mitarbeiter');
        $workers = $db->table('mitarbeiter')
            ->where('aktiv', 1)
            ->whereRaw($wSql, $wParams)
            ->orderBy('vorname')->orderBy('name')
            ->select('dienst_nr', 'vorname', 'name', 'bereich', 'foto')
            ->get();

        return response()->json([
            'favorites' => $favorites,
            'workers'   => $workers,
        ]);
    }

    /**
     * Replaces the customer's favorite-worker set with the provided dienst_nr list.
     */
    public function saveFavorites(Request $request, string $id)
    {
        $db = DB::connection('tenant');
        if (!$db->table('kunden')->where('kunde_id', $id)->exists()
            || !BereichScope::allowsKunde($request->user(), $id)) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }
        if (!Schema::connection('tenant')->hasTable('kunden_favoriten')) {
            return response()->json(['error' => 'Favoriten-Tabelle fehlt. Bitte tenant:migrate ausführen.'], 503);
        }

        $ids = $request->input('dienst_nrs', []);
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(
            array_map('strval', $ids),
            fn($v) => $v !== ''
        )));

        // Keep only dienst_nr values that belong to real workers in this
        // tenant AND sit inside the caller's Bereich scope — the picker only
        // shows scoped workers, so hand-forged ids must be dropped too.
        if (!empty($ids)) {
            [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'mitarbeiter');
            $ids = $db->table('mitarbeiter')->whereIn('dienst_nr', $ids)
                ->whereRaw($wSql, $wParams)->pluck('dienst_nr')->toArray();
        }

        $db->transaction(function () use ($db, $id, $ids) {
            $db->table('kunden_favoriten')->where('kunde_id', $id)->delete();
            if (!empty($ids)) {
                $now = now();
                $rows = array_map(fn($dn) => [
                    'kunde_id'   => $id,
                    'dienst_nr'  => $dn,
                    'created_at' => $now,
                ], $ids);
                $db->table('kunden_favoriten')->insert($rows);
            }
        });

        \App\Helpers\Realtime::changed('kunden');
        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    // ===========================================================
    // Helpers
    // ===========================================================

    /**
     * Validates incoming customer payload. Returns ["field" => ["msg", ...]] on errors.
     */
    protected function validateData(array $data, ?string $currentId): array
    {
        $errors = [];

        // Required: kundenname
        $name = trim((string) ($data['kundenname'] ?? ''));
        if ($name === '') {
            $errors['kundenname'][] = 'Kundenname ist erforderlich.';
        } elseif (mb_strlen($name) > 200) {
            $errors['kundenname'][] = 'Kundenname darf maximal 200 Zeichen haben.';
        }

        // Email
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Ungültige E-Mail-Adresse.';
        }

        // Rechnungs-E-Mail (dedicated invoice/billing address)
        $rechnungEmail = trim((string) ($data['rechnung_email'] ?? ''));
        if ($rechnungEmail !== '' && !filter_var($rechnungEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['rechnung_email'][] = 'Ungültige Rechnungs-E-Mail-Adresse.';
        }

        // PLZ — 4-5 digits for D/AT/CH
        $plz = trim((string) ($data['plz'] ?? ''));
        if ($plz !== '' && !preg_match('/^\d{4,5}$/', $plz)) {
            $errors['plz'][] = 'PLZ muss aus 4 bis 5 Ziffern bestehen.';
        }

        // Land enum
        $land = (string) ($data['land'] ?? 'D');
        if (!in_array($land, self::LAENDER, true)) {
            $errors['land'][] = 'Ungültiges Land.';
        }

        // Anschrift typ enum
        $atyp = (string) ($data['anschrift_typ'] ?? 'Firma');
        if (!in_array($atyp, self::ANSCHRIFT_TYPEN, true)) {
            $errors['anschrift_typ'][] = 'Ungültiger Anschrift-Typ.';
        }

        // Abrechnungstyp enum
        $abr = (string) ($data['abrechnungstyp'] ?? 'Pro Einheit');
        if (!in_array($abr, self::ABRECHNUNGSTYPEN, true)) {
            $errors['abrechnungstyp'][] = 'Ungültiger Abrechnungstyp.';
        }

        // Website — accept empty or syntactically valid URL/host
        $website = trim((string) ($data['website'] ?? ''));
        if ($website !== '') {
            $candidate = preg_match('#^https?://#i', $website) ? $website : 'http://' . $website;
            if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
                $errors['website'][] = 'Ungültige Website-URL.';
            }
        }

        // Farbe — optional #RRGGBB hex color (used to tint Dispo cards)
        $farbe = trim((string) ($data['farbe'] ?? ''));
        if ($farbe !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $farbe)) {
            $errors['farbe'][] = 'Ungültige Farbe (erwartet #RRGGBB).';
        }

        // kunden_nr_kunde uniqueness (per tenant) — ignore current row on update
        $extKundenNr = trim((string) ($data['kunden_nr_kunde'] ?? ''));
        if ($extKundenNr !== '') {
            $q = DB::connection('tenant')->table('kunden')->where('kunden_nr_kunde', $extKundenNr);
            if ($currentId) {
                $q->where('kunde_id', '!=', $currentId);
            }
            if ($q->exists()) {
                $errors['kunden_nr_kunde'][] = 'Diese Kunden-Nr. wird bereits verwendet.';
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
