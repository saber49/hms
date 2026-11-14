<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Crm;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Lead-Modul — configurable lists + deadlines (spec §3 "nicht fest im Code",
 * §4 "Fristen änderbar"). Admin (stammdaten.interessenten.admin) only.
 * System entries (stages/phases the rules rely on) keep their key; their
 * name, order and probability stay editable, they cannot be deactivated.
 */
class CrmSettingsController extends Controller
{
    /** Types the admin may extend with own entries. */
    private const ERWEITERBAR = ['disq_grund', 'verlust_grund', 'quelle', 'branche', 'prioritaet', 'aufgabe_typ', 'deal_phase'];
    private const SETTINGS = [
        'a2_rueckruf_arbeitstage' => [0, 30], 'a7_vorlauf_tage' => [0, 730], 'a8_inaktiv_tage' => [1, 365],
        'a9_versuche' => [1, 50], 'a9_tage' => [1, 365], 'dsgvo_pruefung_monate' => [1, 120],
    ];

    private function guard(Request $request)
    {
        if (!Crm::ready()) return response()->json(['error' => 'Lead-Modul noch nicht eingerichtet'], 409);
        if (!Crm::can($request->user(), 'admin')) return response()->json(['error' => 'Nur für die Geschäftsleitung (Vertrieb-Admin)'], 403);
        return null;
    }

    public function index(Request $request)
    {
        if ($r = $this->guard($request)) return $r;
        $tenant = \App\Services\TenantService::current();
        if (!$tenant->crm_webform_key) { // key is created lazily, first time an admin opens settings
            $tenant->crm_webform_key = Str::random(40);
            $tenant->save();
        }
        return response()->json([
            'webform' => ['url' => url('/api/crm-form/' . $tenant->crm_webform_key), 'iframe' =>
                '<iframe src="' . url('/api/crm-form/' . $tenant->crm_webform_key) . '" style="width:100%;max-width:520px;height:640px;border:0" loading="lazy"></iframe>'],
            'listen' => Crm::t('crm_listen')->orderBy('typ')->orderBy('reihenfolge')->orderBy('id')->get()
                ->map(function ($r) { $r->zusatz = $r->zusatz ? json_decode($r->zusatz, true) : null; return $r; }),
            'einstellungen' => Crm::t('crm_einstellungen')->pluck('wert', 'schluessel'),
            'erweiterbar' => self::ERWEITERBAR,
            'zuweisung' => Crm::t('crm_zuweisung')->get()->map(function ($r) {
                $r->user_ids = json_decode((string) $r->user_ids, true) ?: [];
                $r->plz_regeln = json_decode((string) $r->plz_regeln, true) ?: [];
                return $r;
            })->keyBy('sparte'),
            'sparten' => Crm::sparten($request->user()),
            'owners' => Crm::usersWith((int) $request->user()->tenant_id, ['view', 'all'])
                ->map(fn ($x) => ['id' => (int) $x->id, 'name' => Crm::userName($x)])->values(),
        ]);
    }

    /**
     * PUT /crm/zuweisung {sparte, modus: ''|rotation|region|fest, user_ids[], plz_regeln[{von,bis,user_id}], fest_user_id}
     * Empty modus removes the rule (→ fallback Vertriebsleitung / creator).
     */
    public function zuweisungUpdate(Request $request)
    {
        if ($r = $this->guard($request)) return $r;
        $u = $request->user();
        [$d, $err] = Crm::pruefe($request->all(), [
            'sparte' => 'required|string|max:190', 'modus' => 'nullable|in:rotation,region,fest',
            'user_ids' => 'nullable|array|max:100', 'user_ids.*' => 'integer',
            'plz_regeln' => 'nullable|array|max:200', 'plz_regeln.*.von' => 'required|regex:/^\d{1,5}$/',
            'plz_regeln.*.bis' => 'required|regex:/^\d{1,5}$/', 'plz_regeln.*.user_id' => 'required|integer',
            'fest_user_id' => 'nullable|integer',
        ]);
        if ($err) return Crm::fehler($err);
        if (!Crm::sparteGueltig($u, $d['sparte'])) return Crm::fehler(['sparte' => ['Sparte: ungültiger Wert.']]);
        if (empty($d['modus'])) {
            Crm::t('crm_zuweisung')->where('sparte', $d['sparte'])->delete();
            Crm::changed();
            return response()->json(['ok' => true]);
        }
        $ids = array_values(array_unique(array_map('intval', $d['user_ids'] ?? [])));
        $regeln = array_values($d['plz_regeln'] ?? []);
        $alle = array_merge($ids, array_column($regeln, 'user_id'), [$d['fest_user_id'] ?? null]);
        foreach (array_filter($alle) as $id) {
            if (!Crm::ownerGueltig($u, $id)) return Crm::fehler(['user_ids' => ["Benutzer #$id ist kein aktiver Vertriebs-Benutzer."]]);
        }
        $fehlt = match ($d['modus']) {
            'rotation' => !$ids ? 'Für die Rotation mindestens einen Vertriebler wählen.' : null,
            'region' => !$regeln ? 'Mindestens einen PLZ-Bereich anlegen.' : null,
            'fest' => empty($d['fest_user_id']) ? 'Eine feste Person wählen.' : null,
        };
        if ($fehlt) return Crm::fehler(['modus' => [$fehlt]]);
        foreach ($regeln as $i => $reg) {
            if (str_pad($reg['von'], 5, '0') > str_pad($reg['bis'], 5, '9')) return Crm::fehler(["plz_regeln.$i.von" => ['PLZ von ist größer als bis.']]);
        }
        Crm::t('crm_zuweisung')->updateOrInsert(['sparte' => $d['sparte']], [
            'modus' => $d['modus'], 'user_ids' => json_encode($ids), 'plz_regeln' => json_encode($regeln),
            'fest_user_id' => $d['fest_user_id'] ?? null, 'updated_at' => now(),
        ]);
        Crm::changed();
        return response()->json(['ok' => true]);
    }

    public function listeStore(Request $request)
    {
        if ($r = $this->guard($request)) return $r;
        [$d, $err] = Crm::pruefe($request->all(), [
            'typ' => 'required|in:' . implode(',', self::ERWEITERBAR), 'name' => 'required|string|max:120',
            'wahrscheinlichkeit' => 'nullable|integer|min:0|max:100',
        ]);
        if ($err) return Crm::fehler($err);
        $key = Str::slug($d['name'], '_') ?: 'eintrag';
        $base = $key; $i = 2;
        while (Crm::t('crm_listen')->where('typ', $d['typ'])->where('schluessel', $key)->exists()) $key = $base . '_' . $i++;
        $max = (int) Crm::t('crm_listen')->where('typ', $d['typ'])->max('reihenfolge');
        // New deal phases are open phases placed before Gewonnen/Verloren.
        if ($d['typ'] === 'deal_phase') {
            $max = (int) Crm::t('crm_listen')->where('typ', 'deal_phase')->where('kategorie', 'offen')->max('reihenfolge');
            Crm::t('crm_listen')->where('typ', 'deal_phase')->where('kategorie', '!=', 'offen')->increment('reihenfolge', 10);
        }
        $id = Crm::t('crm_listen')->insertGetId([
            'typ' => $d['typ'], 'schluessel' => $key, 'name' => trim($d['name']), 'reihenfolge' => $max + 5,
            'kategorie' => $d['typ'] === 'deal_phase' ? 'offen' : null,
            'wahrscheinlichkeit' => $d['typ'] === 'deal_phase' ? ($d['wahrscheinlichkeit'] ?? 50) : null,
            'aktiv' => 1, 'system' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Crm::changed();
        return response()->json(['id' => $id, 'schluessel' => $key], 201);
    }

    public function listeUpdate(Request $request, int $id)
    {
        if ($r = $this->guard($request)) return $r;
        $row = Crm::t('crm_listen')->where('id', $id)->first();
        if (!$row) return response()->json(['error' => 'Eintrag nicht gefunden'], 404);
        [$d, $err] = Crm::pruefe($request->all(), [
            'name' => 'sometimes|required|string|max:120', 'reihenfolge' => 'sometimes|integer|min:0|max:100000',
            'aktiv' => 'sometimes|boolean', 'wahrscheinlichkeit' => 'sometimes|nullable|integer|min:0|max:100',
        ]);
        if ($err) return Crm::fehler($err);
        if (isset($d['aktiv']) && !$d['aktiv'] && $row->system) {
            return Crm::fehler(['aktiv' => ['Systemeintrag — wird von den Regeln benötigt und kann nicht deaktiviert werden.']]);
        }
        if (array_key_exists('wahrscheinlichkeit', $d) && $row->typ !== 'deal_phase') unset($d['wahrscheinlichkeit']);
        if (isset($d['aktiv'])) $d['aktiv'] = (int) $d['aktiv'];
        if ($d) Crm::t('crm_listen')->where('id', $id)->update($d + ['updated_at' => now()]);
        Crm::changed();
        return response()->json(['ok' => true]);
    }

    public function einstellungenUpdate(Request $request)
    {
        if ($r = $this->guard($request)) return $r;
        $rules = ['einsatzgebiet' => 'sometimes|nullable|string|max:500', 'automatik_aktiv' => 'sometimes|boolean',
            'webform_aktiv' => 'sometimes|boolean', 'webform_name' => 'sometimes|nullable|string|max:120',
            'webform_sparte' => 'sometimes|nullable|string|max:190'];
        foreach (self::SETTINGS as $k => [$min, $max]) $rules[$k] = "sometimes|required|integer|min:$min|max:$max";
        [$d, $err] = Crm::pruefe($request->all(), $rules);
        if ($err) return Crm::fehler($err);
        if (array_key_exists('webform_sparte', $d) && ($d['webform_sparte'] ?? '') !== ''
            && !Crm::sparteGueltig($request->user(), $d['webform_sparte'])) {
            return Crm::fehler(['webform_sparte' => ['Ungültige Sparte.']]);
        }
        foreach ($d as $k => $v) {
            $wert = in_array($k, ['automatik_aktiv', 'webform_aktiv'], true) ? ($v ? '1' : '0') : (string) $v;
            Crm::t('crm_einstellungen')->updateOrInsert(['schluessel' => $k], ['wert' => $wert, 'updated_at' => now()]);
        }
        Crm::changed();
        return response()->json(['ok' => true]);
    }

    /** POST /crm/webform/rotate — new form key; old embedded forms stop working. */
    public function webformRotate(Request $request)
    {
        if ($r = $this->guard($request)) return $r;
        $tenant = \App\Services\TenantService::current();
        $tenant->crm_webform_key = Str::random(40);
        $tenant->save();
        return response()->json(['url' => url('/api/crm-form/' . $tenant->crm_webform_key),
            'iframe' => '<iframe src="' . url('/api/crm-form/' . $tenant->crm_webform_key) . '" style="width:100%;max-width:520px;height:640px;border:0" loading="lazy"></iframe>']);
    }
}
