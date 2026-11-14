<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Crm;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Lead-Modul — Firmen, Kontakte, Lifecycle, Optionen (spec 2.1, 2.2, 3.1, 5.5).
 * Every Vertrieb user reads all firms/contacts; editing needs the edit right
 * and ownership (or Vertriebsleitung). Lifecycle only moves forward; a
 * reset is Vertriebsleitung/Admin. Delete = soft delete by Admin only.
 */
class CrmStammController extends Controller
{
    private function notReady()
    {
        return response()->json(['error' => 'Lead-Modul noch nicht eingerichtet (Migration ausstehend)'], 409);
    }

    /** GET /crm/optionen — lists, Sparten, owners, settings and my rights. */
    public function optionen(Request $request)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $listen = [];
        foreach (Crm::t('crm_listen')->orderBy('reihenfolge')->orderBy('id')->get() as $r) {
            $listen[$r->typ][] = [
                'key' => $r->schluessel, 'name' => $r->name, 'kategorie' => $r->kategorie,
                'wahrscheinlichkeit' => $r->wahrscheinlichkeit, 'aktiv' => (bool) $r->aktiv,
                'zusatz' => $r->zusatz ? (json_decode($r->zusatz, true) ?: []) : [],
            ];
        }
        $owners = Crm::usersWith((int) $u->tenant_id, ['view', 'all'])
            ->map(fn ($x) => ['id' => (int) $x->id, 'name' => Crm::userName($x)])->values();
        return response()->json([
            'listen' => $listen,
            'sparten' => Crm::sparten($u),
            'owners' => $owners,
            'einstellungen' => Crm::t('crm_einstellungen')->pluck('wert', 'schluessel'),
            'pflicht' => Crm::PHASE_PFLICHT,
            'heute' => Crm::heute(),
            'ich' => [
                'id' => (int) $u->id,
                'view' => Crm::can($u, 'view'), 'edit' => Crm::can($u, 'edit'), 'all' => Crm::can($u, 'all'),
                'uebergabe' => Crm::can($u, 'uebergabe'), 'admin' => Crm::can($u, 'admin'),
                'leads' => Crm::can($u, 'leads'), 'leads_edit' => Crm::can($u, 'leads_edit'),
                'deals' => Crm::can($u, 'deals'), 'deals_edit' => Crm::can($u, 'deals_edit'),
                'kontakte' => Crm::can($u, 'kontakte'), 'kontakte_edit' => Crm::can($u, 'kontakte_edit'),
                'kunden_edit' => $u->hasPermission('stammdaten.kunden.edit'),
            ],
        ]);
    }

    /** POST /crm/dubletten — A1 check before saving. */
    public function dubletten(Request $request)
    {
        if (!Crm::ready()) return $this->notReady();
        return response()->json(['treffer' => Crm::dubletten($request->all(), $request->input('ohne_kontakt'), $request->input('ohne_firma'))]);
    }

    // =============================================================
    // Firmen
    // =============================================================

    private function firmaRegeln(): array
    {
        return [
            'name' => 'required|string|max:200', 'strasse' => 'nullable|string|max:200',
            'plz' => 'nullable|string|max:10', 'ort' => 'nullable|string|max:120', 'bundesland' => 'nullable|string|max:60',
            'branche' => 'nullable|string|max:60', 'mitarbeiterzahl' => 'nullable|integer|min:0|max:1000000',
            'telefon' => 'nullable|string|max:60', 'website' => 'nullable|string|max:200',
        ];
    }

    public function firmen(Request $request)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $q = Crm::t('crm_firmen as f')->whereNull('f.deleted_at')
            ->select('f.*')
            ->selectSub(Crm::t('crm_kontakte as k')->whereColumn('k.firma_id', 'f.id')->whereNull('k.deleted_at')->selectRaw('COUNT(*)'), 'kontakte')
            ->selectSub(Crm::t('crm_leads as l')->whereColumn('l.firma_id', 'f.id')->whereNull('l.deleted_at')->whereIn('l.stage', Crm::LEAD_OFFEN)->selectRaw('COUNT(*)'), 'offene_leads')
            ->selectSub(Crm::t('crm_deals as d')->whereColumn('d.firma_id', 'f.id')->whereNull('d.deleted_at')->whereNotIn('d.phase', ['gewonnen', 'verloren'])->selectRaw('COUNT(*)'), 'offene_deals');
        if ($request->filled('q')) {
            $s = '%' . addcslashes((string) $request->q, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('f.name', 'like', $s)->orWhere('f.ort', 'like', $s)->orWhere('f.plz', 'like', $s));
        }
        $rows = $q->orderBy('f.name')->limit(1000)->get();
        $names = Crm::userNames($rows->pluck('owner_id'), (int) $u->tenant_id);
        foreach ($rows as $r) $r->owner_name = $names[(int) $r->owner_id] ?? null;
        return response()->json($rows);
    }

    public function firma(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $f = Crm::t('crm_firmen')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$f) return response()->json(['error' => 'Firma nicht gefunden'], 404);

        $f->owner_name = Crm::userNames([$f->owner_id], (int) $u->tenant_id)[(int) $f->owner_id] ?? null;
        $f->kontakte = Crm::t('crm_kontakte')->where('firma_id', $id)->whereNull('deleted_at')->orderBy('nachname')->get();
        $f->leads = Crm::leadZeilen(Crm::leadsQuery($u)->where('l.firma_id', $id)->orderByDesc('l.created_at')->get(), $u)->values();
        $dq = Crm::t('crm_deals as d')->where('d.firma_id', $id)->select('d.*');
        Crm::scopeDeals($dq, $u);
        $f->deals = $dq->orderByDesc('d.created_at')->get();
        $f->aktivitaeten = $this->timeline($u, ['kontakt_id' => $f->kontakte->pluck('id')->all(), 'lead_id' => $f->leads->pluck('id')->all(), 'deal_id' => $f->deals->pluck('id')->all()]);
        $f->darf_bearbeiten = Crm::darfBearbeiten($u, $f, 'kontakte');
        return response()->json($f);
    }

    public function firmaStore(Request $request)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        [$d, $err] = Crm::pruefe($request->all(), $this->firmaRegeln() + ['owner_id' => 'nullable|integer']);
        if ($err) return Crm::fehler($err);
        $owner = $this->ownerAus($u, $request->input('owner_id'), $err);
        if ($err) return Crm::fehler($err);
        if (!$request->boolean('trotzdem') && ($t = Crm::dubletten(['firma_name' => $d['name'], 'plz' => $d['plz'] ?? '']))) {
            return response()->json(['dubletten' => $t, 'error' => 'Mögliche Dublette gefunden'], 409);
        }
        $id = (string) Str::uuid();
        Crm::t('crm_firmen')->insert($this->clean($d) + ['id' => $id, 'owner_id' => $owner, 'created_at' => now(), 'updated_at' => now()]);
        Crm::log('firma', $id, 'owner_id', null, $owner, $u);
        Crm::changed();
        return response()->json(['id' => $id], 201);
    }

    public function firmaUpdate(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $f = Crm::t('crm_firmen')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$f) return response()->json(['error' => 'Firma nicht gefunden'], 404);
        if ($r = $this->nurOwner($request, $u, 'crm_firmen', 'firma', $f)) return $r;
        if (!Crm::darfBearbeiten($u, $f, 'kontakte')) return response()->json(['error' => 'Keine Berechtigung'], 403);
        [$d, $err] = Crm::pruefe($request->all(), $this->firmaRegeln() + ['owner_id' => 'nullable|integer']);
        if ($err) return Crm::fehler($err);
        $neuerOwner = $this->ownerWechsel($u, $request->input('owner_id'), (int) $f->owner_id, $err);
        if ($err) return Crm::fehler($err, 403);
        Crm::t('crm_firmen')->where('id', $id)->update($this->clean($d) + ['updated_at' => now()]);
        if ($neuerOwner) Crm::setOwner('crm_firmen', 'firma', $f, $neuerOwner, $u);
        Crm::changed();
        return response()->json(['ok' => true]);
    }

    public function firmaDestroy(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        if (!Crm::can($request->user(), 'admin')) return response()->json(['error' => 'Löschen nur durch die Geschäftsleitung'], 403);
        $n = Crm::t('crm_firmen')->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        if (!$n) return response()->json(['error' => 'Firma nicht gefunden'], 404);
        Crm::log('firma', $id, 'deleted_at', null, now()->toDateTimeString(), $request->user());
        Crm::changed();
        return response()->json(['ok' => true]);
    }

    // =============================================================
    // Kontakte
    // =============================================================

    private function kontaktRegeln(): array
    {
        return [
            'firma_id' => 'nullable|uuid', 'anrede' => 'nullable|string|max:20', 'vorname' => 'nullable|string|max:100',
            'nachname' => 'required|string|max:100', 'position' => 'nullable|string|max:150', 'entscheider' => 'nullable|boolean',
            'email' => 'nullable|email|max:200', 'telefon' => 'nullable|string|max:60', 'mobil' => 'nullable|string|max:60',
            'quelle' => 'required|string|max:60', 'dsgvo_einwilligung' => 'nullable|boolean', 'dsgvo_datum' => 'nullable|date',
        ];
    }

    /** Spec 2.2: at least one of e-mail / phone / mobile + valid list values. */
    private function kontaktExtraFehler(array $d): array
    {
        $err = [];
        if (trim(($d['email'] ?? '') . ($d['telefon'] ?? '') . ($d['mobil'] ?? '')) === '') {
            $err['email'] = ['Mindestens E-Mail, Telefon oder Mobil angeben.'];
        }
        if (!Crm::gueltig('quelle', $d['quelle'] ?? null)) $err['quelle'] = ['Quelle: ungültiger Wert.'];
        if (!empty($d['firma_id']) && !Crm::t('crm_firmen')->where('id', $d['firma_id'])->whereNull('deleted_at')->exists()) {
            $err['firma_id'] = ['Firma nicht gefunden.'];
        }
        if (!empty($d['dsgvo_einwilligung']) && empty($d['dsgvo_datum'])) $err['dsgvo_datum'] = ['Einwilligungsdatum ist Pflicht.'];
        return $err;
    }

    public function kontakte(Request $request)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $q = Crm::t('crm_kontakte as k')->leftJoin('crm_firmen as f', 'f.id', '=', 'k.firma_id')
            ->whereNull('k.deleted_at')->select('k.*', 'f.name as firma_name', 'f.plz', 'f.ort');
        if ($request->filled('q')) {
            $s = '%' . addcslashes((string) $request->q, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('k.nachname', 'like', $s)->orWhere('k.vorname', 'like', $s)
                ->orWhere('k.email', 'like', $s)->orWhere('k.telefon', 'like', $s)->orWhere('f.name', 'like', $s));
        }
        if ($request->filled('lifecycle')) $q->where('k.lifecycle_stage', $request->lifecycle);
        $rows = $q->orderBy('k.nachname')->limit(1000)->get();
        $names = Crm::userNames($rows->pluck('owner_id'), (int) $u->tenant_id);
        foreach ($rows as $r) $r->owner_name = $names[(int) $r->owner_id] ?? null;
        return response()->json($rows);
    }

    public function kontakt(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $k = Crm::t('crm_kontakte as k')->leftJoin('crm_firmen as f', 'f.id', '=', 'k.firma_id')
            ->where('k.id', $id)->whereNull('k.deleted_at')->select('k.*', 'f.name as firma_name')->first();
        if (!$k) return response()->json(['error' => 'Kontakt nicht gefunden'], 404);
        $k->owner_name = Crm::userNames([$k->owner_id], (int) $u->tenant_id)[(int) $k->owner_id] ?? null;
        $k->leads = Crm::leadZeilen(Crm::leadsQuery($u)->where('l.kontakt_id', $id)->orderByDesc('l.created_at')->get(), $u)->values();
        $dq = Crm::t('crm_deals as d')->where('d.kontakt_id', $id)->select('d.*');
        Crm::scopeDeals($dq, $u);
        $k->deals = $dq->orderByDesc('d.created_at')->get();
        $k->aktivitaeten = $this->timeline($u, ['kontakt_id' => [$id], 'lead_id' => $k->leads->pluck('id')->all(), 'deal_id' => $k->deals->pluck('id')->all()]);
        $k->protokoll = Crm::t('crm_protokoll')->where('objekt', 'kontakt')->where('objekt_id', $id)->orderByDesc('id')->limit(200)->get();
        $k->darf_bearbeiten = Crm::darfBearbeiten($u, $k, 'kontakte');
        return response()->json($k);
    }

    /**
     * POST /crm/kontakte — contact (+ optional new firm) + optional lead.
     * New contacts start at Lifecycle "Interessent"; with `lead.sparte`
     * they go to SQL and A3 creates the lead.
     */
    public function kontaktStore(Request $request)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $in = $request->all();
        // owner_id "auto" (or empty for Vertriebsleitung) = Zuweisungsregel of the Sparte (§6).
        $auto = ($in['owner_id'] ?? null) === 'auto' || (empty($in['owner_id']) && Crm::can($u, 'all'));
        if (($in['owner_id'] ?? null) === 'auto') unset($in['owner_id']);
        [$d, $err] = Crm::pruefe($in, $this->kontaktRegeln() + ['owner_id' => 'nullable|integer']);
        $err = ($err ?: []) + ($d ? $this->kontaktExtraFehler($d) : []);

        $neueFirma = is_array($in['firma'] ?? null) && trim((string) ($in['firma']['name'] ?? '')) !== '' ? $in['firma'] : null;
        $fd = null;
        if ($neueFirma) {
            [$fd, $ferr] = Crm::pruefe($neueFirma, $this->firmaRegeln());
            foreach ($ferr as $k => $m) $err['firma.' . $k] = $m;
        }
        $lead = is_array($in['lead'] ?? null) && !empty($in['lead']['sparte']) ? $in['lead'] : null;
        if ($lead && !Crm::can($u, 'leads_edit')) $err['lead.sparte'] = ['Keine Berechtigung, Leads anzulegen.'];
        if ($lead && !Crm::sparteGueltig($u, $lead['sparte'])) $err['lead.sparte'] = ['Sparte: ungültiger Wert.'];
        if ($lead && !empty($lead['prioritaet']) && !Crm::gueltig('prioritaet', $lead['prioritaet'])) $err['lead.prioritaet'] = ['Priorität: ungültiger Wert.'];
        $owner = $this->ownerAus($u, $in['owner_id'] ?? null, $err);
        if ($err) return Crm::fehler($err);

        if (!$request->boolean('trotzdem')) {
            $t = Crm::dubletten(($d ?? []) + ['firma_name' => $fd['name'] ?? '', 'plz' => $fd['plz'] ?? '']);
            if ($t) return response()->json(['dubletten' => $t, 'error' => 'Mögliche Dublette gefunden'], 409);
        }

        $regel = 'manuell';
        if ($lead && $auto && Crm::can($u, 'all')) {
            $plz = $fd['plz'] ?? (!empty($d['firma_id']) ? Crm::t('crm_firmen')->where('id', $d['firma_id'])->value('plz') : null);
            [$owner, $regel] = Crm::zuweisen($lead['sparte'], $plz, (int) $u->tenant_id, (int) $u->id);
        }

        $ids = \Illuminate\Support\Facades\DB::connection('tenant')->transaction(function () use ($d, $fd, $owner, $u, $lead, $regel) {
            $firmaId = $d['firma_id'] ?? null;
            if ($fd) {
                $firmaId = (string) Str::uuid();
                Crm::t('crm_firmen')->insert($this->clean($fd) + ['id' => $firmaId, 'owner_id' => $owner, 'created_at' => now(), 'updated_at' => now()]);
            }
            $kid = (string) Str::uuid();
            Crm::t('crm_kontakte')->insert($this->clean($d) + [
                'id' => $kid, 'firma_id' => $firmaId, 'entscheider' => !empty($d['entscheider']),
                'dsgvo_einwilligung' => !empty($d['dsgvo_einwilligung']),
                'telefon_norm' => Crm::telNorm($d['telefon'] ?? null), 'mobil_norm' => Crm::telNorm($d['mobil'] ?? null),
                'lifecycle_stage' => 'interessent', 'lifecycle_changed_at' => now(),
                'owner_id' => $owner, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Crm::log('kontakt', $kid, 'lifecycle_stage', null, 'interessent', $u);
            $leadId = $lead ? $this->leadAnlegen($u, $kid, $firmaId, $lead['sparte'], $lead['prioritaet'] ?? 'normal', $owner, false, $regel) : null;
            return [$kid, $leadId, $firmaId];
        });

        Crm::changed();
        return response()->json(['id' => $ids[0], 'lead_id' => $ids[1], 'firma_id' => $ids[2]], 201);
    }

    public function kontaktUpdate(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $k = Crm::t('crm_kontakte')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$k) return response()->json(['error' => 'Kontakt nicht gefunden'], 404);
        if ($r = $this->nurOwner($request, $u, 'crm_kontakte', 'kontakt', $k)) return $r;
        if (!Crm::darfBearbeiten($u, $k, 'kontakte')) return response()->json(['error' => 'Keine Berechtigung'], 403);
        [$d, $err] = Crm::pruefe($request->all(), $this->kontaktRegeln() + ['owner_id' => 'nullable|integer']);
        $err = ($err ?: []) + ($d ? $this->kontaktExtraFehler($d) : []);
        if ($err) return Crm::fehler($err);
        if (!$request->boolean('trotzdem') && ($t = Crm::dubletten($d, $id))) {
            return response()->json(['dubletten' => $t, 'error' => 'Mögliche Dublette gefunden'], 409);
        }
        $neuerOwner = $this->ownerWechsel($u, $request->input('owner_id'), (int) $k->owner_id, $err);
        if ($err) return Crm::fehler($err, 403);
        Crm::t('crm_kontakte')->where('id', $id)->update($this->clean($d) + [
            'entscheider' => !empty($d['entscheider']), 'dsgvo_einwilligung' => !empty($d['dsgvo_einwilligung']),
            'telefon_norm' => Crm::telNorm($d['telefon'] ?? null), 'mobil_norm' => Crm::telNorm($d['mobil'] ?? null), 'updated_at' => now(),
        ]);
        if ($neuerOwner) Crm::setOwner('crm_kontakte', 'kontakt', $k, $neuerOwner, $u);
        Crm::changed();
        return response()->json(['ok' => true]);
    }

    /**
     * PUT /crm/kontakte/{id}/lifecycle {stage, sparte?}
     * Manual stages: Interessent, MQL, SQL, Ehemaliger Kunde. SQL needs a
     * Sparte → A3 creates the lead unless one is open in that Sparte.
     * Backwards = Vertriebsleitung only (reset).
     */
    public function lifecycle(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $k = Crm::t('crm_kontakte')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$k) return response()->json(['error' => 'Kontakt nicht gefunden'], 404);
        if (!Crm::darfBearbeiten($u, $k, 'kontakte') && !Crm::can($u, 'all')) return response()->json(['error' => 'Keine Berechtigung'], 403);

        $neu = (string) $request->input('stage');
        $reset = Crm::can($u, 'all') || Crm::can($u, 'admin');
        $order = Crm::liste('lifecycle')->keys()->flip();
        if (!isset($order[$neu])) return Crm::fehler(['stage' => ['Ungültige Lifecycle Stage.']]);
        if (!in_array($neu, Crm::LIFECYCLE_MANUELL, true) && !$reset) {
            return Crm::fehler(['stage' => ['Diese Stage wird automatisch gesetzt.']]);
        }
        if ($order[$neu] < $order[$k->lifecycle_stage] && !$reset) {
            return Crm::fehler(['stage' => ['Die Lifecycle Stage bewegt sich nur vorwärts — Zurücksetzen nur durch die Vertriebsleitung.']]);
        }
        $leadId = null;
        if ($neu === 'sql') {
            $sparte = (string) $request->input('sparte');
            if (!Crm::sparteGueltig($u, $sparte)) return Crm::fehler(['sparte' => ['Für SQL ist eine Sparte Pflicht (Regel A3).']]);
            // Vertriebsleitung distributes by rule; a Vertrieb user keeps his own contact.
            [$owner, $regel] = Crm::can($u, 'all')
                ? Crm::zuweisen($sparte, $k->firma_id ? Crm::t('crm_firmen')->where('id', $k->firma_id)->value('plz') : null, (int) $u->tenant_id, (int) $k->owner_id)
                : [(int) $k->owner_id, 'manuell'];
            $leadId = $this->leadAnlegen($u, $id, $k->firma_id, $sparte, 'normal', $owner, true, $regel);
        }
        Crm::setLifecycle($id, $neu, $u, null, true);
        Crm::changed();
        return response()->json(['ok' => true, 'lead_id' => $leadId]);
    }

    public function kontaktDestroy(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        if (!Crm::can($request->user(), 'admin')) return response()->json(['error' => 'Löschen nur durch die Geschäftsleitung'], 403);
        $n = Crm::t('crm_kontakte')->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        if (!$n) return response()->json(['error' => 'Kontakt nicht gefunden'], 404);
        Crm::t('crm_leads')->where('kontakt_id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        Crm::log('kontakt', $id, 'deleted_at', null, now()->toDateTimeString(), $request->user());
        Crm::changed();
        return response()->json(['ok' => true]);
    }

    // =============================================================
    // Shared
    // =============================================================

    /**
     * A3 — lead for a contact in one Sparte (only if none is open there),
     * Stage Neu, task "Erstkontakt" due today, lifecycle → SQL.
     * Returns the existing open lead id when $reuse, else creates.
     */
    public function leadAnlegen($u, string $kontaktId, ?string $firmaId, string $sparte, ?string $prio, int $owner, bool $reuse = false,
                                string $zuweisung = 'manuell', bool $notify = true, ?string $regel = 'A3'): string
    {
        $offen = Crm::t('crm_leads')->where('kontakt_id', $kontaktId)->whereNull('deleted_at')
            ->whereIn('stage', Crm::LEAD_OFFEN)->whereRaw('LOWER(sparte) = ?', [mb_strtolower($sparte)])->value('id');
        if ($offen) {
            if ($reuse) return $offen;
            abort(response()->json(['errors' => ['sparte' => ['Für diese Sparte gibt es bereits einen offenen Lead.']], 'lead_id' => $offen, 'error' => 'Für diese Sparte gibt es bereits einen offenen Lead.'], 409));
        }
        $id = (string) Str::uuid();
        Crm::t('crm_leads')->insert([
            'id' => $id, 'kontakt_id' => $kontaktId, 'firma_id' => $firmaId, 'sparte' => $sparte,
            'stage' => 'neu', 'stage_changed_at' => now(), 'owner_id' => $owner, 'prioritaet' => $prio ?: 'normal',
            'anzahl_kontaktversuche' => 0, 'checkliste' => json_encode([]), 'zuweisung' => $zuweisung,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $creator = $u ?? Crm::automatik((int) \App\Services\TenantService::current()->id);
        Crm::log('lead', $id, 'stage', null, 'neu', $u, $regel);
        Crm::log('lead', $id, 'owner_id', null, $owner, $u, $zuweisung !== 'manuell' ? 'ZW-' . $zuweisung : $regel);
        Crm::setLifecycle($kontaktId, 'sql', $u, $regel);
        $k = Crm::t('crm_kontakte')->where('id', $kontaktId)->first();
        Crm::aufgabe('lead', $id, 'anruf', 'Erstkontakt: ' . trim(($k->vorname ?? '') . ' ' . ($k->nachname ?? '')), Crm::heute(), null,
            $owner, $creator, $sparte, null, $notify);
        return $id;
    }

    /** Owner for a new record: self, or anyone (Vertriebsleitung). */
    private function ownerAus($u, $wunsch, array &$err): int
    {
        if ($wunsch === null || $wunsch === '' || (int) $wunsch === (int) $u->id) return (int) $u->id;
        if (!Crm::can($u, 'all')) { $err['owner_id'] = ['Zuweisung an andere nur durch die Vertriebsleitung.']; return (int) $u->id; }
        if (!Crm::ownerGueltig($u, $wunsch)) { $err['owner_id'] = ['Ungültiger Owner.']; return (int) $u->id; }
        return (int) $wunsch;
    }

    /** PUT with only {owner_id} = pure reassignment (Vertriebsleitung), no field validation. */
    private function nurOwner(Request $request, $u, string $table, string $objekt, object $row)
    {
        if (!$request->has('owner_id') || array_keys($request->except('owner_id')) !== []) return null;
        $err = null;
        $neu = self::ownerWechsel($u, $request->input('owner_id'), (int) $row->owner_id, $err);
        if ($err) return Crm::fehler($err, 403);
        if ($neu) Crm::setOwner($table, $objekt, $row, $neu, $u);
        Crm::changed();
        return response()->json(['ok' => true]);
    }

    /** Reassignment on update: null = unchanged; Vertriebsleitung only. */
    public static function ownerWechsel($u, $wunsch, int $aktuell, ?array &$err): ?int
    {
        if ($wunsch === null || $wunsch === '' || (int) $wunsch === $aktuell) return null;
        if (!Crm::can($u, 'all')) { $err = ['owner_id' => ['Owner umverteilen darf nur die Vertriebsleitung.']]; return null; }
        if (!Crm::ownerGueltig($u, $wunsch)) { $err = ['owner_id' => ['Ungültiger Owner.']]; return null; }
        return (int) $wunsch;
    }

    private function clean(array $d): array
    {
        unset($d['owner_id'], $d['trotzdem']);
        return array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $d);
    }

    /** Activities for any of the given ids, newest first, with author names. */
    public function timeline($u, array $by): \Illuminate\Support\Collection
    {
        $q = Crm::t('crm_aktivitaeten')->where(function ($w) use ($by) {
            $any = false;
            foreach ($by as $col => $ids) {
                if ($ids) { $w->orWhereIn($col, $ids); $any = true; }
            }
            if (!$any) $w->whereRaw('1=0');
        });
        $rows = $q->orderByDesc('zeitpunkt')->orderByDesc('id')->limit(500)->get();
        $names = Crm::userNames($rows->pluck('user_id'), (int) $u->tenant_id);
        foreach ($rows as $r) {
            $r->user_name = $r->user_id ? ($names[(int) $r->user_id] ?? '#' . $r->user_id) : 'Automatik';
            $r->hat_datei = (bool) $r->datei_pfad;
            unset($r->datei_pfad, $r->datei_disk);
        }
        return $rows;
    }
}
