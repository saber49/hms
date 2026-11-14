<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationDispatcher;
use App\Helpers\Realtime;
use App\Http\Controllers\Controller;
use App\Services\SchichtboerseService as SB;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Schichtbörse — Dispo side. The office publishes understaffed shifts
 * (manually, user decision) and approves/rejects every request; the plan only
 * changes on approval. Worker side: WorkerAppSchichtboerseController.
 */
class SchichtboerseController extends Controller
{
    private function t(string $table)
    {
        return DB::connection('tenant')->table($table);
    }

    private static function userName($u): string
    {
        return trim((string) ($u->full_name ?? '')) ?: ((string) ($u->name ?? '') ?: (string) ($u->email ?? ''));
    }

    private function workerNames(array $dns): array
    {
        return $dns ? $this->t('mitarbeiter')->whereIn('dienst_nr', array_unique($dns))->get(['dienst_nr', 'vorname', 'name', 'foto'])
            ->keyBy('dienst_nr')->map(fn ($m) => ['name' => trim($m->vorname . ' ' . $m->name), 'foto' => $m->foto])->all() : [];
    }

    /** Offers + their open requests, Bereich-scoped through the shift. */
    private function offers(Request $request, ?string $einsatzId = null)
    {
        $q = $this->t('schicht_angebote')->where('status', 'aktiv')->orderBy('id');
        if ($einsatzId) $q->where('einsatz_id', $einsatzId);
        $out = [];
        foreach ($q->get() as $a) {
            if (!BereichScope::allowsEinsatz($request->user(), $a->einsatz_id)) continue;
            $e = SB::einsatz($a->einsatz_id);
            if (!$e) continue;
            $a->einsatz = [
                'einsatz_id' => $e->einsatz_id, 'bestell_id' => $e->bestell_id, 'datum' => $e->datum,
                'ist_start' => $e->ist_start, 'ist_ende' => $e->ist_ende, 'kundenname' => $e->kundenname,
                'objektname' => $e->objektname, 'label' => SB::label($e), 'offen' => SB::isOpen($e),
            ];
            $a->freie_plaetze = $a->typ === 'offen' ? SB::freePlaces($e) : 1;
            $a->anfragen = $this->t('schicht_anfragen')->where('angebot_id', $a->id)->where('status', 'angefragt')->orderBy('id')->get();
            $out[] = $a;
        }
        $names = $this->workerNames(collect($out)->flatMap(fn ($a) => array_merge(
            [$a->anbieter_dienst_nr], collect($a->anfragen)->pluck('dienst_nr')->all()))->filter()->all());
        foreach ($out as $a) {
            $a->anbieter_name = $a->anbieter_dienst_nr ? ($names[$a->anbieter_dienst_nr]['name'] ?? $a->anbieter_dienst_nr) : null;
            foreach ($a->anfragen as $r) { $r->name = $names[$r->dienst_nr]['name'] ?? $r->dienst_nr; $r->foto = $names[$r->dienst_nr]['foto'] ?? null; }
        }
        return collect($out);
    }

    /** GET /schichtboerse — all active offers (Planung toolbar dialog). */
    public function index(Request $request)
    {
        if (!SB::ready()) return response()->json(['angebote' => [], 'offene_anfragen' => 0]);
        $offers = $this->offers($request);
        return response()->json(['angebote' => $offers->values(), 'offene_anfragen' => $offers->sum(fn ($a) => count($a->anfragen))]);
    }

    /** GET /schichtboerse/count — open requests (toolbar badge). */
    public function count(Request $request)
    {
        if (!SB::ready()) return response()->json(['offene_anfragen' => 0]);
        $ids = $this->t('schicht_anfragen as r')->join('schicht_angebote as a', 'a.id', '=', 'r.angebot_id')
            ->where('r.status', 'angefragt')->where('a.status', 'aktiv')->pluck('a.einsatz_id')->all();
        $n = 0;
        foreach (array_count_values($ids) as $eid => $c) if (BereichScope::allowsEinsatz($request->user(), (string) $eid)) $n += $c;
        return response()->json(['offene_anfragen' => $n]);
    }

    /** GET /schichtboerse/einsatz/{id} — state for the shift modal. */
    public function einsatz(Request $request, $id)
    {
        if (!SB::ready()) return response()->json(['ready' => false]);
        if (!BereichScope::allowsEinsatz($request->user(), $id)) return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        $e = SB::einsatz($id);
        if (!$e) return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        $offers = $this->offers($request, $id);
        $offen = $offers->firstWhere('typ', 'offen');
        return response()->json([
            'ready' => true, 'kann_veroeffentlichen' => SB::isOpen($e) && !$offen && SB::freePlaces($e) > 0,
            'freie_plaetze' => SB::freePlaces($e), 'schicht_offen' => SB::isOpen($e),
            'passend' => $offen ? SB::eligibleWorkers($e)->count() : null,
            'angebote' => $offers->values(),
        ]);
    }

    /** POST /schichtboerse/einsatz/{id}/veroeffentlichen { notiz? } */
    public function publish(Request $request, $id)
    {
        if (!SB::ready()) return response()->json(['error' => 'Schichtbörse noch nicht verfügbar'], 409);
        $d = $request->validate(['notiz' => 'nullable|string|max:500']);
        if (!BereichScope::allowsEinsatz($request->user(), $id)) return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        $e = SB::einsatz($id);
        if (!$e) return response()->json(['error' => 'Einsatz nicht gefunden'], 404);
        if (!SB::isOpen($e)) return response()->json(['error' => 'Nur zukünftige, nicht bestätigte Schichten können veröffentlicht werden'], 422);
        if (SB::freePlaces($e) < 1) return response()->json(['error' => 'Die Schicht ist bereits voll besetzt'], 422);
        if ($this->t('schicht_angebote')->where('einsatz_id', $id)->where('typ', 'offen')->where('status', 'aktiv')->exists()) {
            return response()->json(['error' => 'Die Schicht ist bereits in der Schichtbörse'], 422);
        }
        $aid = $this->t('schicht_angebote')->insertGetId([
            'einsatz_id' => $id, 'typ' => 'offen', 'status' => 'aktiv', 'notiz' => $d['notiz'] ?? null,
            'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $eligible = SB::eligibleWorkers($e);
        SB::notifyWorkers($eligible, 'shift.boerse.neu', 'Offene Schicht: ' . SB::label($e),
            'In der Schichtbörse ist eine Schicht frei, die zu Ihnen passt. Jetzt in der App anfragen.', ['angebot_id' => $aid]);
        Realtime::changed('dispo', ['boerse' => true]);
        return response()->json(['id' => $aid, 'benachrichtigt' => $eligible->count()], 201);
    }

    /** POST /schichtboerse/angebote/{id}/zurueckziehen — open requests are rejected. */
    public function withdraw(Request $request, $id)
    {
        $a = SB::ready() ? $this->t('schicht_angebote')->where('id', $id)->first() : null;
        if (!$a || !BereichScope::allowsEinsatz($request->user(), $a->einsatz_id)) return response()->json(['error' => 'Angebot nicht gefunden'], 404);
        if ($a->status !== 'aktiv') return response()->json(['error' => 'Das Angebot ist nicht mehr aktiv'], 422);
        $this->t('schicht_angebote')->where('id', $id)->update(['status' => 'zurueckgezogen', 'updated_at' => now()]);
        $this->rejectOpen((int) $id, $request->user(), 'Angebot wurde vom Büro zurückgezogen');
        if ($a->typ === 'abgabe' && $a->anbieter_dienst_nr && ($e = SB::einsatz($a->einsatz_id))) {
            SB::notifyWorkers($this->t('mitarbeiter')->where('dienst_nr', $a->anbieter_dienst_nr)->get(['id', 'dienst_nr']),
                'shift.boerse.abgabe_beendet', 'Schichtabgabe beendet', 'Ihre Abgabe für ' . SB::label($e) . ' wurde vom Büro beendet — die Schicht bleibt bei Ihnen.');
        }
        Realtime::changed('dispo', ['boerse' => true]);
        return response()->json(['ok' => true]);
    }

    /** Reject every still-open request of an offer (+ tell the workers). */
    private function rejectOpen(int $angebotId, $user, string $grund, ?int $exceptId = null): void
    {
        $rows = $this->t('schicht_anfragen')->where('angebot_id', $angebotId)->where('status', 'angefragt')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->get();
        if ($rows->isEmpty()) return;
        $this->t('schicht_anfragen')->whereIn('id', $rows->pluck('id'))->update([
            'status' => 'abgelehnt', 'grund' => $grund, 'entschieden_von' => $user->id ?? null,
            'entschieden_von_name' => $user ? self::userName($user) : null, 'entschieden_am' => now(), 'updated_at' => now(),
        ]);
        $a = $this->t('schicht_angebote')->where('id', $angebotId)->first();
        $e = $a ? SB::einsatz($a->einsatz_id) : null;
        SB::notifyWorkers($this->t('mitarbeiter')->whereIn('dienst_nr', $rows->pluck('dienst_nr'))->get(['id', 'dienst_nr']),
            'shift.boerse.abgelehnt', 'Schichtanfrage abgelehnt', ($e ? SB::label($e) . ': ' : '') . $grund);
    }

    /** POST /schichtboerse/anfragen/{id}/genehmigen — the plan changes here. */
    public function approve(Request $request, $id)
    {
        if (!SB::ready()) return response()->json(['error' => 'Schichtbörse noch nicht verfügbar'], 409);
        $u = $request->user();
        $r = $this->t('schicht_anfragen')->where('id', $id)->first();
        $a = $r ? $this->t('schicht_angebote')->where('id', $r->angebot_id)->first() : null;
        if (!$r || !$a || !BereichScope::allowsEinsatz($u, $a->einsatz_id)) return response()->json(['error' => 'Anfrage nicht gefunden'], 404);

        $result = DB::connection('tenant')->transaction(function () use ($r, $a, $u) {
            // Lock the offer so two approvals cannot hand out the same place.
            $a = $this->t('schicht_angebote')->where('id', $a->id)->lockForUpdate()->first();
            $r = $this->t('schicht_anfragen')->where('id', $r->id)->lockForUpdate()->first();
            if ($a->status !== 'aktiv' || $r->status !== 'angefragt') return ['error' => 'Die Anfrage ist nicht mehr offen'];
            $e = SB::einsatz($a->einsatz_id);
            if (!$e || !SB::isOpen($e)) return ['error' => 'Die Schicht hat bereits begonnen, ist bestätigt oder storniert'];
            if (!SB::isEligible($e, $r->dienst_nr, $a->typ === 'abgabe' ? $a->anbieter_dienst_nr : null)) {
                return ['error' => 'Der Mitarbeiter passt nicht mehr (andere Schicht, Abwesenheit oder Anforderungen) — bitte ablehnen'];
            }
            $aufgabe = null;
            if ($a->typ === 'abgabe') {
                $own = $this->t('einsatz_mitarbeiter')->where('einsatz_id', $e->einsatz_id)->where('dienst_nr', $a->anbieter_dienst_nr)->first();
                if (!$own) return ['error' => 'Der abgebende Mitarbeiter ist nicht mehr auf dieser Schicht'];
                $aufgabe = $own->aufgabe ?? null;
                $this->t('einsatz_mitarbeiter')->where('id', $own->id)->delete();
            } elseif (SB::freePlaces($e) < 1) {
                return ['error' => 'Die Schicht ist inzwischen voll besetzt'];
            }
            $row = [
                'einsatz_id' => $e->einsatz_id, 'dienst_nr' => $r->dienst_nr,
                'sortierung' => (int) $this->t('einsatz_mitarbeiter')->where('einsatz_id', $e->einsatz_id)->max('sortierung') + 1,
            ];
            if ($aufgabe !== null) $row['aufgabe'] = $aufgabe;
            $this->t('einsatz_mitarbeiter')->insert($row);
            $this->t('schicht_anfragen')->where('id', $r->id)->update([
                'status' => 'genehmigt', 'entschieden_von' => $u->id, 'entschieden_von_name' => self::userName($u),
                'entschieden_am' => now(), 'updated_at' => now(),
            ]);
            $e->assigned[] = $r->dienst_nr;
            $done = $a->typ === 'abgabe' || SB::freePlaces($e) < 1;
            if ($done) {
                $this->t('schicht_angebote')->where('id', $a->id)->update(['status' => 'vergeben', 'updated_at' => now()]);
            }
            return ['ok' => true, 'done' => $done, 'e' => $e, 'a' => $a, 'r' => $r];
        });
        if (!empty($result['error'])) return response()->json(['error' => $result['error']], 422);

        $e = $result['e']; $a = $result['a']; $r = $result['r'];
        if ($result['done']) $this->rejectOpen((int) $a->id, $u, 'Die Schicht wurde bereits vergeben');
        $label = SB::label($e);
        SB::notifyWorkers($this->t('mitarbeiter')->where('dienst_nr', $r->dienst_nr)->get(['id', 'dienst_nr']),
            'shift.boerse.genehmigt', 'Schicht übernommen ✓', $label . ' — die Schicht steht jetzt in Ihrem Plan.');
        if ($a->typ === 'abgabe' && $a->anbieter_dienst_nr) {
            SB::notifyWorkers($this->t('mitarbeiter')->where('dienst_nr', $a->anbieter_dienst_nr)->get(['id', 'dienst_nr']),
                'shift.boerse.abgegeben', 'Schicht abgegeben ✓', $label . ' — wurde an einen Kollegen übergeben und ist nicht mehr in Ihrem Plan.');
        }
        Realtime::changed('dispo', ['boerse' => true]);
        return response()->json(['ok' => true, 'angebot_geschlossen' => $result['done']]);
    }

    /** POST /schichtboerse/anfragen/{id}/ablehnen { grund? } */
    public function reject(Request $request, $id)
    {
        $d = $request->validate(['grund' => 'nullable|string|max:500']);
        $r = SB::ready() ? $this->t('schicht_anfragen')->where('id', $id)->first() : null;
        $a = $r ? $this->t('schicht_angebote')->where('id', $r->angebot_id)->first() : null;
        if (!$r || !$a || !BereichScope::allowsEinsatz($request->user(), $a->einsatz_id)) return response()->json(['error' => 'Anfrage nicht gefunden'], 404);
        if ($r->status !== 'angefragt') return response()->json(['error' => 'Die Anfrage ist nicht mehr offen'], 422);
        $u = $request->user();
        $this->t('schicht_anfragen')->where('id', $id)->update([
            'status' => 'abgelehnt', 'grund' => $d['grund'] ?? null, 'entschieden_von' => $u->id,
            'entschieden_von_name' => self::userName($u), 'entschieden_am' => now(), 'updated_at' => now(),
        ]);
        $e = SB::einsatz($a->einsatz_id);
        SB::notifyWorkers($this->t('mitarbeiter')->where('dienst_nr', $r->dienst_nr)->get(['id', 'dienst_nr']),
            'shift.boerse.abgelehnt', 'Schichtanfrage abgelehnt', ($e ? SB::label($e) : 'Schicht') . (!empty($d['grund']) ? ': ' . $d['grund'] : ''));
        Realtime::changed('dispo', ['boerse' => true]);
        return response()->json(['ok' => true]);
    }

    /** Called by the worker app when a worker requests — bell + push to the Dispo. */
    public static function notifyOffice(object $e, string $workerName, string $typ, ?string $anbieterName = null): void
    {
        try {
            $tenant = \App\Services\TenantService::current();
            if (!$tenant) return;
            NotificationDispatcher::notifyDispoManagers((int) $tenant->id, 'dispo.boerse',
                'Schichtbörse: neue Anfrage',
                $workerName . ($typ === 'abgabe' && $anbieterName ? " möchte die Schicht von {$anbieterName} übernehmen" : ' möchte eine offene Schicht übernehmen') . ' · ' . SB::label($e),
                '#tasks', ['einsatz_id' => $e->einsatz_id]);
        } catch (\Throwable $ex) { /* best effort */ }
    }
}
