<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantService;
use App\Support\Crm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lead-Modul A2 — public webform, no auth. URL: /api/crm-form/{key}
 * The tenant is resolved by the secret `tenants.crm_webform_key` (rotatable in
 * Einstellungen). GET serves a self-contained form page (iframe/link — CORS
 * stays closed for foreign origins); POST validates, runs the A1 duplicate
 * check, then creates Kontakt+Lead via the normal pipeline incl. Zuweisung.
 * Spam: honeypot field + crm-form rate limit; answers never reveal whether a
 * contact already exists.
 */
class CrmWebformController extends Controller
{
    private function resolve(string $key): ?Tenant
    {
        $tenant = Tenant::where('aktiv', true)->where('crm_webform_key', $key)->first();
        if (!$tenant) return null;
        TenantService::switchToTenant($tenant);
        if (!Crm::ready() || Crm::setting('webform_aktiv', '0') !== '1') return null;
        return $tenant;
    }

    /** GET /api/crm-form/{key} — standalone form page (embed via iframe or link). */
    public function form(Request $request, string $key)
    {
        $tenant = $this->resolve($key);
        if (!$tenant) return response()->view('crm-form-offen', [], 404);
        $titel = Crm::setting('webform_name') ?: $tenant->name;
        if ($request->query('format') === 'json') {
            return response()->json(['titel' => $titel, 'felder' => ['vorname', 'nachname*', 'firma', 'email', 'telefon', 'plz', 'nachricht', 'dsgvo*']]);
        }
        return response()->view('crm-form', ['titel' => $titel, 'key' => $key]);
    }

    /**
     * POST /api/crm-form/{key} {vorname, nachname*, firma, email, telefon, plz, nachricht, dsgvo}
     * Always 200 {ok:true} on success — existence of a contact is never disclosed.
     */
    public function store(Request $request, string $key)
    {
        $tenant = $this->resolve($key);
        if (!$tenant) return response()->json(['ok' => true]); // silent — no probing

        // Honeypot: bots fill the invisible "website" field → fake success.
        if ($request->filled('website')) return response()->json(['ok' => true]);

        [$d, $err] = Crm::pruefe($request->all(), [
            'vorname' => 'nullable|string|max:100', 'nachname' => 'required|string|max:100',
            'firma' => 'nullable|string|max:200', 'email' => 'nullable|email|max:200',
            'telefon' => 'nullable|string|max:60', 'plz' => 'nullable|string|max:10',
            'ort' => 'nullable|string|max:120', 'nachricht' => 'nullable|string|max:5000',
            'dsgvo' => 'nullable|boolean',
        ]);
        if ($err) return response()->json(['ok' => false, 'errors' => $err], 422);
        if (($d['email'] ?? '') === '' && ($d['telefon'] ?? '') === '') {
            return response()->json(['ok' => false, 'errors' => ['email' => ['Bitte E-Mail oder Telefon angeben.']]], 422);
        }
        if (!($d['dsgvo'] ?? false)) {
            return response()->json(['ok' => false, 'errors' => ['dsgvo' => ['Bitte der Verarbeitung zustimmen.']]], 422);
        }

        $sparte = Crm::setting('webform_sparte') ?: (Crm::sparten(null)[0] ?? '');
        $leitung = Crm::usersWith((int) $tenant->id, ['all'])->first();
        [$owner, $regel] = Crm::zuweisen($sparte, $d['plz'] ?? null, (int) $tenant->id, $leitung ? (int) $leitung->id : 0);

        DB::connection('tenant')->transaction(function () use ($d, $sparte, $owner, $regel, $tenant, $leitung) {
            // A1 — existing contact? Then no duplicate: attach to it instead.
            $dup = Crm::dubletten(['email' => $d['email'] ?? '', 'telefon' => $d['telefon'] ?? '', 'mobil' => '']);
            $kid = collect($dup)->firstWhere('art', 'kontakt')['id'] ?? null;
            if ($kid) {
                $fill = [];
                $k = Crm::t('crm_kontakte')->where('id', $kid)->first();
                foreach (['vorname', 'firma_id'] as $f) {
                    if (($k->$f ?? null) === null || $k->$f === '') {
                        $v = $f === 'vorname' ? ($d['vorname'] ?? null) : null;
                        if ($v !== null && $v !== '') $fill[$f] = $v;
                    }
                }
                if ($fill) Crm::t('crm_kontakte')->where('id', $kid)->update($fill + ['updated_at' => now()]);
                $firmaId = $k->firma_id;
            } else {
                $firmaId = null;
                if (($d['firma'] ?? '') !== '') {
                    $firmaId = Crm::t('crm_firmen')->whereNull('deleted_at')->where('name', $d['firma'])
                        ->when(($d['plz'] ?? '') !== '', fn ($q) => $q->where('plz', $d['plz']))->value('id')
                        ?? (string) Str::uuid();
                    if (!Crm::t('crm_firmen')->where('id', $firmaId)->exists()) {
                        Crm::t('crm_firmen')->insert(['id' => $firmaId, 'name' => $d['firma'], 'plz' => ($d['plz'] ?? '') ?: null,
                            'ort' => ($d['ort'] ?? '') ?: null, 'owner_id' => $owner, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                $kid = (string) Str::uuid();
                Crm::t('crm_kontakte')->insert([
                    'id' => $kid, 'firma_id' => $firmaId, 'vorname' => ($d['vorname'] ?? '') ?: null, 'nachname' => $d['nachname'],
                    'email' => ($d['email'] ?? '') ?: null, 'telefon' => ($d['telefon'] ?? '') ?: null,
                    'telefon_norm' => Crm::telNorm($d['telefon'] ?? null),
                    'lifecycle_stage' => 'interessent', 'lifecycle_changed_at' => now(), 'quelle' => 'website_formular',
                    'dsgvo_einwilligung' => 1, 'dsgvo_datum' => now(), 'owner_id' => $owner, 'created_at' => now(), 'updated_at' => now(),
                ]);
                Crm::log('kontakt', $kid, 'quelle', null, 'website_formular', null, 'A2');
            }

            // Lead per Sparte (A3): reuse an open one — the note lands there either way.
            $offen = $sparte !== '' ? Crm::t('crm_leads')->where('kontakt_id', $kid)->whereNull('deleted_at')
                ->whereIn('stage', Crm::LEAD_OFFEN)->whereRaw('LOWER(sparte) = ?', [mb_strtolower($sparte)])->value('id') : null;
            $leadId = $offen ?: ($sparte !== ''
                ? app(CrmStammController::class)->leadAnlegen(null, $kid, $firmaId, $sparte, 'normal', $owner, true, $regel, true, 'A2')
                : null);
            if ($leadId && ($d['nachricht'] ?? '') !== '') {
                Crm::t('crm_aktivitaeten')->insert(['kontakt_id' => $kid, 'lead_id' => $leadId, 'typ' => 'notiz',
                    'zeitpunkt' => now(), 'richtung' => 'eingehend', 'notiz' => 'Website-Formular: ' . $d['nachricht'],
                    'created_at' => now(), 'updated_at' => now()]);
                if ($offen) { // existing lead was touched again → re-arm A8 and tell the owner
                    Crm::t('crm_leads')->where('id', $leadId)->update(['letzte_aktivitaet_am' => now(), 'updated_at' => now()]);
                    Crm::hinweis(['all'], 'Erneute Anfrage (Website)', trim(($d['vorname'] ?? '') . ' ' . $d['nachname']) . ' hat erneut angefragt.', '#interessenten', null, $owner);
                }
            }
            if (!$offen && $leadId) {
                Crm::hinweis(['all'], 'Neue Anfrage (Website)', trim(($d['vorname'] ?? '') . ' ' . $d['nachname']) . ' — neuer Lead wurde zugewiesen.', '#interessenten', null, $owner);
            }
        });

        Crm::changed();
        return response()->json(['ok' => true]);
    }
}
