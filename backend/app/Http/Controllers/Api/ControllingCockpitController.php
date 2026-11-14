<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\ControllingAggregationService as Agg;
use App\Services\ControllingCockpitService as Cockpit;
use App\Support\BereichScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controlling-Cockpit (Deckungsbeitragsrechnung + Objektcontrolling).
 * Reads: controlling.view. Cost settings + Fixkosten writes: Inhaber only
 * (tenant.owner) — they decide what every report shows as result.
 */
class ControllingCockpitController extends Controller
{
    /** GET /api/controlling/cockpit?period|from&to&basis=operativ|abrechnung&compare=prev|year&filters… */
    public function cockpit(Request $request)
    {
        [$from, $to] = Agg::resolvePeriod($request);
        $filters = Agg::resolveFilters($request);
        $basis = $request->input('basis') === 'abrechnung' ? 'abrechnung' : 'operativ';
        $compare = $request->input('compare') === 'year' ? 'year' : 'prev';

        $payload = Cockpit::cockpit($from, $to, $filters, $basis, $compare);
        $payload['can_edit_settings'] = $this->isOwner($request);

        return Agg::jsonCached($payload);
    }

    /** GET /api/controlling/settings */
    public function settings(Request $request)
    {
        $s = Cockpit::settings();
        return response()->json([
            'settings'  => $s,
            'defaults'  => Cockpit::DEFAULTS,
            'vollkostenfaktor' => round(Cockpit::factor($s), 4),
            'can_edit'  => $this->isOwner($request),
        ]);
    }

    /** PUT /api/controlling/settings (Inhaber) */
    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'ag_anteil'      => 'required|numeric|min:0|max:60',
            'ausfall_faktor' => 'required|numeric|min:1|max:2',
            'ampel_rot'      => 'required|numeric|min:-100|max:100',
            'ampel_gelb'     => 'required|numeric|min:-100|max:100|gte:ampel_rot',
        ], [
            'ag_anteil.*'      => 'AG-Anteil muss zwischen 0 und 60 % liegen.',
            'ausfall_faktor.*' => 'Der Ausfallfaktor muss zwischen 1,0 und 2,0 liegen.',
            'ampel_rot.*'      => 'Die Ampel-Schwelle „rot“ muss zwischen -100 und 100 % liegen.',
            'ampel_gelb.gte'   => 'Die Schwelle „gelb“ darf nicht unter der Schwelle „rot“ liegen.',
            'ampel_gelb.*'     => 'Die Ampel-Schwelle „gelb“ muss zwischen -100 und 100 % liegen.',
        ]);

        $tenant = Tenant::findOrFail($request->user()->tenant_id);
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $settings['controlling'] = array_map('floatval', $data);
        $tenant->settings = $settings;
        $tenant->save();

        return response()->json(['success' => true, 'settings' => $settings['controlling']]);
    }

    // ============================================================
    // FIXKOSTEN
    // ============================================================

    /** GET /api/controlling/fixkosten */
    public function fixkostenIndex(Request $request)
    {
        // Company-level figures — Bereich-restricted users get nothing.
        if (BereichScope::for($request->user()) !== null) {
            return response()->json(['items' => [], 'monat_summe' => null, 'can_edit' => false]);
        }

        $rows = DB::connection('tenant')->table('controlling_fixkosten')
            ->orderByRaw('bis_monat IS NOT NULL, bis_monat DESC')
            ->orderBy('kategorie')->orderBy('bezeichnung')
            ->get();
        $month = Carbon::now('Europe/Berlin')->startOfMonth()->toDateString();
        $sum = $rows->filter(fn ($r) => $r->ab_monat <= $month && ($r->bis_monat === null || $r->bis_monat >= $month))
            ->sum(fn ($r) => (float) $r->betrag);

        return response()->json([
            'items' => $rows->map(fn ($r) => [
                'id'          => $r->id,
                'bezeichnung' => $r->bezeichnung,
                'kategorie'   => $r->kategorie,
                'betrag'      => (float) $r->betrag,
                'ab_monat'    => substr($r->ab_monat, 0, 7),
                'bis_monat'   => $r->bis_monat ? substr($r->bis_monat, 0, 7) : null,
                'aktiv'       => $r->ab_monat <= $month && ($r->bis_monat === null || $r->bis_monat >= $month),
            ])->values(),
            'monat_summe' => round($sum, 2),
            'can_edit'    => $this->isOwner($request),
        ]);
    }

    /** POST /api/controlling/fixkosten (Inhaber) */
    public function fixkostenStore(Request $request)
    {
        $d = $this->validateItem($request);
        $id = DB::connection('tenant')->table('controlling_fixkosten')->insertGetId($d + [
            'updated_by' => $this->actor($request), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['success' => true, 'id' => $id], 201);
    }

    /** PUT /api/controlling/fixkosten/{id} (Inhaber) — corrects the row for its whole validity. */
    public function fixkostenUpdate(Request $request, int $id)
    {
        $this->findItem($id);
        $d = $this->validateItem($request);
        DB::connection('tenant')->table('controlling_fixkosten')->where('id', $id)
            ->update($d + ['updated_by' => $this->actor($request), 'updated_at' => now()]);
        return response()->json(['success' => true]);
    }

    /**
     * POST /api/controlling/fixkosten/{id}/aendern {betrag, ab_monat} (Inhaber)
     * New amount from a month on — past months keep the old value.
     */
    public function fixkostenAendern(Request $request, int $id)
    {
        $row = $this->findItem($id);
        $d = $request->validate([
            'betrag'   => 'required|numeric|min:0|max:9999999',
            'ab_monat' => 'required|date_format:Y-m',
        ], [
            'betrag.*'   => 'Bitte einen gültigen Betrag (≥ 0 €) angeben.',
            'ab_monat.*' => 'Bitte einen gültigen Monat angeben.',
        ]);
        $ab = $d['ab_monat'] . '-01';
        if ($row->bis_monat !== null && $ab > $row->bis_monat) {
            return response()->json(['error' => 'Der Monat liegt nach dem Ende dieser Position.'], 422);
        }

        DB::connection('tenant')->transaction(function () use ($row, $ab, $d, $request) {
            $t = DB::connection('tenant')->table('controlling_fixkosten');
            if ($ab <= $row->ab_monat) {
                $t->where('id', $row->id)->update(['betrag' => $d['betrag'], 'updated_by' => $this->actor($request), 'updated_at' => now()]);
                return;
            }
            DB::connection('tenant')->table('controlling_fixkosten')->where('id', $row->id)->update([
                'bis_monat' => Carbon::parse($ab)->subMonthNoOverflow()->toDateString(),
                'updated_by' => $this->actor($request), 'updated_at' => now(),
            ]);
            DB::connection('tenant')->table('controlling_fixkosten')->insert([
                'bezeichnung' => $row->bezeichnung, 'kategorie' => $row->kategorie, 'betrag' => $d['betrag'],
                'ab_monat' => $ab, 'bis_monat' => $row->bis_monat,
                'updated_by' => $this->actor($request), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json(['success' => true]);
    }

    /** DELETE /api/controlling/fixkosten/{id} (Inhaber) */
    public function fixkostenDestroy(int $id)
    {
        $this->findItem($id);
        DB::connection('tenant')->table('controlling_fixkosten')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    // ============================================================

    private function validateItem(Request $request): array
    {
        $d = $request->validate([
            'bezeichnung' => 'required|string|max:150',
            'kategorie'   => 'nullable|string|max:50',
            'betrag'      => 'required|numeric|min:0|max:9999999',
            'ab_monat'    => 'required|date_format:Y-m',
            'bis_monat'   => 'nullable|date_format:Y-m',
        ], [
            'bezeichnung.*' => 'Bitte eine Bezeichnung (max. 150 Zeichen) angeben.',
            'kategorie.*'   => 'Die Kategorie darf max. 50 Zeichen lang sein.',
            'betrag.*'      => 'Bitte einen gültigen Betrag (≥ 0 €) angeben.',
            'ab_monat.*'    => 'Bitte einen gültigen Ab-Monat angeben.',
            'bis_monat.*'   => 'Bitte einen gültigen Bis-Monat angeben.',
        ]);
        if (!empty($d['bis_monat']) && $d['bis_monat'] < $d['ab_monat']) {
            abort(response()->json(['error' => '„Bis“ darf nicht vor „Ab“ liegen.'], 422));
        }
        return [
            'bezeichnung' => trim($d['bezeichnung']),
            'kategorie'   => isset($d['kategorie']) && trim($d['kategorie']) !== '' ? trim($d['kategorie']) : null,
            'betrag'      => round((float) $d['betrag'], 2),
            'ab_monat'    => $d['ab_monat'] . '-01',
            'bis_monat'   => !empty($d['bis_monat']) ? $d['bis_monat'] . '-01' : null,
        ];
    }

    private function findItem(int $id): object
    {
        $row = DB::connection('tenant')->table('controlling_fixkosten')->where('id', $id)->first();
        if (!$row) abort(response()->json(['error' => 'Position nicht gefunden'], 404));
        return $row;
    }

    private function isOwner(Request $request): bool
    {
        $u = $request->user();
        return $u && ($u->isOwner() || $u->role === 'super_admin');
    }

    private function actor(Request $request): ?string
    {
        $u = $request->user();
        return $u ? substr((string) ($u->full_name ?: $u->email), 0, 100) : null;
    }
}
