<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantService;
use App\Support\Aueg;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AÜG-Fristen: Überlassungshöchstdauer + Equal Pay per worker and Entleiher
 * (calculation in App\Support\Aueg), and which customers are Entleiher.
 */
class AuegController extends Controller
{
    /** GET /api/aueg — overview (stammdaten.mitarbeiter.view). */
    public function index(Request $request)
    {
        abort_unless(Aueg::enabled(TenantService::current()), 410, 'AÜG gehört nicht zum Sicherheitsprodukt.');
        $user = $request->user();
        $tenant = TenantService::current();
        $base = [
            'aktiv' => Aueg::enabled($tenant),
            'regeln' => ['luecke_tage' => Aueg::LUECKE_TAGE, 'unterbrechung_monate' => Aueg::UNTERBRECHUNG_MONATE, 'warn_tage' => Aueg::WARN_TAGE],
            'entleiher' => [], 'rows' => [], 'kunden' => [],
        ];
        if (!Aueg::ready()) return response()->json($base);

        [$kSql, $kParams] = BereichScope::kundePredicate($user, 'k');
        $base['kunden'] = DB::connection('tenant')->table('kunden as k')
            ->where('k.aktiv', 1)->whereRaw($kSql, $kParams)
            ->orderBy('k.kundenname')->get(['k.kunde_id', 'k.kundenname']);
        $visibleKunden = $base['kunden']->pluck('kundenname', 'kunde_id')->all();

        $ent = DB::connection('tenant')->table('aueg_entleiher')->orderBy('kunde_id')->get()
            ->filter(fn ($r) => isset($visibleKunden[$r->kunde_id]))
            ->map(fn ($r) => [
                'kunde_id' => $r->kunde_id, 'kundenname' => $visibleKunden[$r->kunde_id],
                'aktiv' => (bool) $r->aktiv, 'hoechstdauer_monate' => (int) $r->hoechstdauer_monate,
                'equal_pay_monate' => (int) $r->equal_pay_monate, 'bemerkung' => $r->bemerkung,
            ])->values();
        $base['entleiher'] = $ent;

        $cfg = Aueg::entleiher($ent->where('aktiv', true)->pluck('kunde_id')->all());
        if (!$cfg) return response()->json($base);

        [$wSql, $wParams] = BereichScope::workerPredicate($user, 'm');
        $workers = DB::connection('tenant')->table('mitarbeiter as m')->whereRaw($wSql, $wParams)
            ->get(['m.dienst_nr', 'm.vorname', 'm.name', 'm.aktiv'])->keyBy('dienst_nr');

        $asOf = now('Europe/Berlin')->toDateString();
        $rows = [];
        foreach (Aueg::dates(array_keys($cfg), $workers->keys()->all()) as $kundeId => $byWorker) {
            foreach ($byWorker as $dienstNr => $dates) {
                $st = Aueg::evaluate($dates, $asOf, $cfg[$kundeId]);
                if (!$st) continue;
                $w = $workers[$dienstNr];
                $rows[] = $st + [
                    'dienst_nr' => $dienstNr,
                    'mitarbeiter' => trim(($w->vorname ?? '') . ' ' . ($w->name ?? '')),
                    'mitarbeiter_aktiv' => (bool) $w->aktiv,
                    'kunde_id' => $kundeId,
                    'kundenname' => $visibleKunden[$kundeId] ?? $kundeId,
                ];
            }
        }
        $order = ['ueberschritten' => 0, 'bald' => 1, 'equal_pay' => 2, 'geplant' => 3, 'ok' => 4];
        usort($rows, fn ($a, $b) => [$order[$a['status']] ?? 9, $a['hoechstdauer_bis']] <=> [$order[$b['status']] ?? 9, $b['hoechstdauer_bis']]);
        $base['rows'] = $rows;
        return response()->json($base);
    }

    /** PUT /api/aueg/entleiher/{kundeId} — mark / configure an Entleiher (stammdaten.kunden.edit). */
    public function saveEntleiher(Request $request, string $kundeId)
    {
        abort_unless(Aueg::enabled(TenantService::current()), 410, 'AÜG gehört nicht zum Sicherheitsprodukt.');
        if (!Aueg::ready()) return response()->json(['error' => 'AÜG-Modul ist noch nicht eingerichtet'], 409);
        if (!BereichScope::allowsKunde($request->user(), $kundeId)
            || !DB::connection('tenant')->table('kunden')->where('kunde_id', $kundeId)->exists()) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }
        $data = $request->validate([
            'aktiv'               => 'nullable|boolean',
            'hoechstdauer_monate' => 'nullable|integer|min:1|max:120',
            'equal_pay_monate'    => 'nullable|integer|min:1|max:120',
            'bemerkung'           => 'nullable|string|max:255',
        ]);
        $row = [
            'aktiv'               => array_key_exists('aktiv', $data) ? (int) (bool) $data['aktiv'] : 1,
            'hoechstdauer_monate' => $data['hoechstdauer_monate'] ?? 18,
            'equal_pay_monate'    => $data['equal_pay_monate'] ?? 9,
            'bemerkung'           => isset($data['bemerkung']) ? trim(strip_tags($data['bemerkung'])) : null,
            'updated_by'          => optional($request->user())->email,
            'updated_at'          => now(),
        ];
        $t = DB::connection('tenant')->table('aueg_entleiher');
        if ((clone $t)->where('kunde_id', $kundeId)->exists()) {
            (clone $t)->where('kunde_id', $kundeId)->update($row);
        } else {
            $t->insert($row + ['kunde_id' => $kundeId, 'created_at' => now()]);
        }
        return response()->json(['success' => true]);
    }

    /** DELETE /api/aueg/entleiher/{kundeId} — customer is no longer checked (configuration only). */
    public function deleteEntleiher(Request $request, string $kundeId)
    {
        abort_unless(Aueg::enabled(TenantService::current()), 410, 'AÜG gehört nicht zum Sicherheitsprodukt.');
        if (!Aueg::ready() || !BereichScope::allowsKunde($request->user(), $kundeId)) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }
        DB::connection('tenant')->table('aueg_entleiher')->where('kunde_id', $kundeId)->delete();
        return response()->json(['success' => true]);
    }
}
