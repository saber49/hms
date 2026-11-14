<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Crm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lead-Modul — Lead-Arbeitsbereich, Lead-Detail, Qualifizieren (A6),
 * Disqualifizieren (A7/A9). Spec 2.3, 3.2, 5.1, 5.2.
 * Vertrieb sees own leads, Vertriebsleitung all (Crm::scopeLeads).
 */
class CrmLeadController extends Controller
{
    /** Max rows per list response; narrow with filters/search beyond that. */
    private const LIMIT = 500;

    private function notReady()
    {
        return response()->json(['error' => 'Lead-Modul noch nicht eingerichtet (Migration ausstehend)'], 409);
    }

    /**
     * GET /crm/leads?tab=meine|heute|ueberfaellig|alle&stage=offen|abgeschlossen|alle|<key>
     *               &sparte=&quelle=&owner=&plz=&prioritaet=&q=
     */
    public function index(Request $request)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $all = Crm::can($u, 'all');
        $tab = (string) $request->query('tab', 'meine');
        $q = Crm::leadFlagsQuery($u); // derived table x: everything filtered + paged in SQL

        if ($tab === 'meine' || ($tab === 'alle' && !$all)) $q->where('x.owner_id', (int) $u->id);

        $stage = (string) $request->query('stage', 'offen');
        if ($stage === 'offen' || in_array($tab, ['heute', 'ueberfaellig'], true)) $q->whereIn('x.stage', Crm::LEAD_OFFEN);
        elseif ($stage === 'abgeschlossen') $q->whereNotIn('x.stage', Crm::LEAD_OFFEN);
        elseif ($stage !== 'alle') $q->where('x.stage', $stage);

        if ($request->filled('sparte')) $q->where('x.sparte', $request->sparte);
        if ($request->filled('prioritaet')) $q->where('x.prioritaet', $request->prioritaet);
        if ($request->filled('quelle')) $q->where('x.quelle', $request->quelle);
        if ($request->filled('owner') && $all) $q->where('x.owner_id', (int) $request->owner);
        if ($request->filled('plz')) $q->where('x.plz', 'like', addcslashes((string) $request->plz, '%_\\') . '%');
        if ($request->filled('q')) {
            $s = '%' . addcslashes((string) $request->q, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('x.firma_name', 'like', $s)->orWhere('x.nachname', 'like', $s)->orWhere('x.vorname', 'like', $s)
                ->orWhere('x.email', 'like', $s)->orWhere('x.telefon', 'like', $s)->orWhere('x.ort', 'like', $s));
        }
        if ($tab === 'heute') $q->having('f_heute', 1);
        if ($tab === 'ueberfaellig') $q->having('f_ueber', 1);

        $rows = Crm::leadZeilen($q->orderByDesc('x.stage_changed_at')->limit(self::LIMIT + 1)->get(), $u)->values();
        $mehr = $rows->count() > self::LIMIT;
        return response()->json([
            'data' => $rows->take(self::LIMIT)->values(),
            'mehr' => $mehr,
            'zaehler' => Crm::leadZaehler($u),
        ]);
    }

    public function show(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $row = Crm::leadsQuery($u)->where('l.id', $id)->first();
        if (!$row) return response()->json(['error' => 'Lead nicht gefunden'], 404);
        $lead = Crm::leadZeilen([$row], $u)->first();

        $lead->kontakt = Crm::t('crm_kontakte')->where('id', $lead->kontakt_id)->first();
        $lead->firma = $lead->firma_id ? Crm::t('crm_firmen')->where('id', $lead->firma_id)->first() : null;
        $lead->deal = $lead->deal_id ? Crm::t('crm_deals')->where('id', $lead->deal_id)->whereNull('deleted_at')->first() : null;
        $dealIds = $lead->deal ? [$lead->deal->id] : [];

        $stamm = app(CrmStammController::class);
        $lead->aktivitaeten = $stamm->timeline($u, ['lead_id' => [$id], 'kontakt_id' => [$lead->kontakt_id], 'deal_id' => $dealIds]);
        $lead->aufgaben = Crm::aufgabenFuer(['lead' => [$id], 'deal' => $dealIds, 'kontakt' => [$lead->kontakt_id]]);
        $lead->protokoll = Crm::t('crm_protokoll')
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('objekt', 'lead')->where('objekt_id', $id))
                ->orWhere(fn ($x) => $x->where('objekt', 'kontakt')->where('objekt_id', $lead->kontakt_id))
                ->orWhere(fn ($x) => $x->where('objekt', 'deal')->whereIn('objekt_id', $dealIds ?: ['-'])))
            ->orderByDesc('id')->limit(200)->get();

        // Sidebar: other leads + deals of the same firm (or contact without firm).
        $col = $lead->firma_id ? 'firma_id' : 'kontakt_id';
        $val = $lead->firma_id ?: $lead->kontakt_id;
        $lead->weitere_leads = Crm::leadZeilen(Crm::leadsQuery($u)->where("l.$col", $val)->where('l.id', '!=', $id)->orderByDesc('l.created_at')->get(), $u)->values();
        $dq = Crm::t('crm_deals as d')->where("d.$col", $val)->select('d.*');
        Crm::scopeDeals($dq, $u);
        $lead->weitere_deals = $dq->orderByDesc('d.created_at')->get();

        $lead->darf_bearbeiten = Crm::darfBearbeiten($u, $lead, 'leads');
        $lead->nicht_erreichbar_erlaubt = Crm::nichtErreichbarErlaubt($lead);
        $lead->a9_hinweis = $lead->offen && $lead->nicht_erreichbar_erlaubt && $lead->stage === 'kontaktversuch';
        return response()->json($lead);
    }

    /** POST /crm/leads {kontakt_id, sparte, prioritaet?, owner_id?} — manual lead (A3 rules). */
    public function store(Request $request)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $in = $request->all();
        $auto = Crm::can($u, 'all') && (empty($in['owner_id']) || $in['owner_id'] === 'auto');
        if (($in['owner_id'] ?? null) === 'auto') unset($in['owner_id']);
        [$d, $err] = Crm::pruefe($in, [
            'kontakt_id' => 'required|uuid', 'sparte' => 'required|string|max:190',
            'prioritaet' => 'nullable|string|max:60', 'owner_id' => 'nullable|integer',
        ]);
        if ($err) return Crm::fehler($err);
        $k = Crm::t('crm_kontakte')->where('id', $d['kontakt_id'])->whereNull('deleted_at')->first();
        if (!$k) return Crm::fehler(['kontakt_id' => ['Kontakt nicht gefunden.']], 404);
        if (!Crm::sparteGueltig($u, $d['sparte'])) return Crm::fehler(['sparte' => ['Sparte: ungültiger Wert.']]);
        if (!empty($d['prioritaet']) && !Crm::gueltig('prioritaet', $d['prioritaet'])) return Crm::fehler(['prioritaet' => ['Priorität: ungültiger Wert.']]);
        $owner = (int) $u->id;
        if (!empty($d['owner_id']) && (int) $d['owner_id'] !== (int) $u->id) {
            if (!Crm::can($u, 'all')) return Crm::fehler(['owner_id' => ['Zuweisung an andere nur durch die Vertriebsleitung.']], 403);
            if (!Crm::ownerGueltig($u, $d['owner_id'])) return Crm::fehler(['owner_id' => ['Ungültiger Owner.']]);
            $owner = (int) $d['owner_id'];
        }
        $regel = 'manuell';
        if ($auto) {
            $plz = $k->firma_id ? Crm::t('crm_firmen')->where('id', $k->firma_id)->value('plz') : null;
            [$owner, $regel] = Crm::zuweisen($d['sparte'], $plz, (int) $u->tenant_id, (int) $u->id);
        }
        $id = DB::connection('tenant')->transaction(fn () => app(CrmStammController::class)
            ->leadAnlegen($u, $k->id, $k->firma_id, $d['sparte'], $d['prioritaet'] ?? 'normal', $owner, false, $regel));
        Crm::changed();
        return response()->json(['id' => $id], 201);
    }

    /** PUT /crm/leads/{id} {prioritaet?, sparte?, owner_id?, checkliste?} */
    public function update(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $lead = Crm::lead($u, $id);
        if (!$lead) return response()->json(['error' => 'Lead nicht gefunden'], 404);
        if (!Crm::darfBearbeiten($u, $lead, 'leads')) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $offen = in_array($lead->stage, Crm::LEAD_OFFEN, true);

        [$d, $err] = Crm::pruefe($request->all(), [
            'prioritaet' => 'sometimes|nullable|string|max:60', 'sparte' => 'sometimes|required|string|max:190',
            'owner_id' => 'sometimes|nullable|integer', 'checkliste' => 'sometimes|array',
            'checkliste.bedarf' => 'sometimes|boolean', 'checkliste.entscheider' => 'sometimes|boolean',
            'checkliste.gebiet' => 'sometimes|boolean', 'checkliste.termin' => 'sometimes|boolean',
            'checkliste.termin_am' => 'sometimes|nullable|date_format:Y-m-d', 'checkliste.termin_zeit' => 'sometimes|nullable|date_format:H:i',
        ]);
        if ($err) return Crm::fehler($err);

        $upd = [];
        if (array_key_exists('prioritaet', $d)) {
            if ($d['prioritaet'] && !Crm::gueltig('prioritaet', $d['prioritaet'])) return Crm::fehler(['prioritaet' => ['Priorität: ungültiger Wert.']]);
            $upd['prioritaet'] = $d['prioritaet'];
        }
        if (array_key_exists('sparte', $d) && mb_strtolower((string) $lead->sparte) !== mb_strtolower($d['sparte'])) {
            if (!$offen) return Crm::fehler(['sparte' => ['Sparte nur bei offenen Leads änderbar.']]);
            if (!Crm::sparteGueltig($u, $d['sparte'])) return Crm::fehler(['sparte' => ['Sparte: ungültiger Wert.']]);
            $dup = Crm::t('crm_leads')->where('kontakt_id', $lead->kontakt_id)->where('id', '!=', $id)->whereNull('deleted_at')
                ->whereIn('stage', Crm::LEAD_OFFEN)->whereRaw('LOWER(sparte) = ?', [mb_strtolower($d['sparte'])])->exists();
            if ($dup) return Crm::fehler(['sparte' => ['Für diese Sparte gibt es bereits einen offenen Lead.']], 409);
            $upd['sparte'] = $d['sparte'];
            Crm::log('lead', $id, 'sparte', $lead->sparte, $d['sparte'], $u);
        }
        if (array_key_exists('checkliste', $d)) {
            if (!$offen) return Crm::fehler(['checkliste' => ['Checkliste nur bei offenen Leads änderbar.']]);
            $cur = json_decode((string) $lead->checkliste, true) ?: [];
            $neu = array_intersect_key($d['checkliste'], array_flip(['bedarf', 'entscheider', 'gebiet', 'termin', 'termin_am', 'termin_zeit']));
            $upd['checkliste'] = json_encode(array_merge($cur, $neu));
        }
        $neuerOwner = CrmStammController::ownerWechsel($u, $d['owner_id'] ?? null, (int) $lead->owner_id, $err);
        if ($err) return Crm::fehler($err, 403);

        if ($upd) Crm::t('crm_leads')->where('id', $id)->update($upd + ['updated_at' => now()]);
        if ($neuerOwner) Crm::setOwner('crm_leads', 'lead', $lead, $neuerOwner, $u);
        Crm::changed();
        return response()->json(['ok' => true]);
    }

    /**
     * POST /crm/leads/{id}/qualifizieren — A6.
     * {checkliste:{bedarf,entscheider,gebiet,termin,termin_am,termin_zeit},
     *  deal:{titel, objektadresse, besichtigung_am, erwarteter_abschluss}}
     */
    public function qualifizieren(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $lead = Crm::lead($u, $id);
        if (!$lead) return response()->json(['error' => 'Lead nicht gefunden'], 404);
        if (!Crm::darfBearbeiten($u, $lead, 'leads')) return response()->json(['error' => 'Keine Berechtigung'], 403);
        if (!in_array($lead->stage, Crm::LEAD_OFFEN, true)) return Crm::fehler(['stage' => ['Lead ist bereits abgeschlossen.']], 409);
        if (!$lead->sparte) return Crm::fehler(['sparte' => ['Vor dem Qualifizieren eine Sparte setzen.']]);

        [$d, $err] = Crm::pruefe($request->all(), [
            'checkliste.bedarf' => 'required|accepted', 'checkliste.entscheider' => 'required|accepted',
            'checkliste.gebiet' => 'required|accepted', 'checkliste.termin' => 'required|accepted',
            'checkliste.termin_am' => 'required|date_format:Y-m-d', 'checkliste.termin_zeit' => 'nullable|date_format:H:i',
            'deal.titel' => 'required|string|max:250', 'deal.objektadresse' => 'required|string|max:300',
            'deal.besichtigung_am' => 'required|date', 'deal.erwarteter_abschluss' => 'required|date_format:Y-m-d',
        ]);
        if ($err) {
            $msg = [
                'checkliste.bedarf' => 'Bedarf für eine konkrete Leistung bestätigen.',
                'checkliste.entscheider' => 'Entscheider oder Weg zum Entscheider bestätigen.',
                'checkliste.gebiet' => 'Objekt im Einsatzgebiet bestätigen.',
                'checkliste.termin' => 'Termin für Besichtigung/Folgegespräch bestätigen.',
                'checkliste.termin_am' => 'Datum des Termins ist Pflicht.',
            ];
            foreach ($err as $k => $v) if (isset($msg[$k])) $err[$k] = [$msg[$k]];
            return Crm::fehler($err);
        }
        $c = $d['checkliste'];
        $deal = $d['deal'];

        $dealId = DB::connection('tenant')->transaction(function () use ($u, $lead, $c, $deal) {
            $dealId = (string) Str::uuid();
            Crm::t('crm_deals')->insert([
                'id' => $dealId, 'lead_id' => $lead->id, 'firma_id' => $lead->firma_id, 'kontakt_id' => $lead->kontakt_id,
                'titel' => trim($deal['titel']), 'sparte' => $lead->sparte, 'phase' => 'besichtigung_geplant', 'phase_changed_at' => now(),
                'objektadresse' => trim($deal['objektadresse']),
                'besichtigung_am' => \Carbon\Carbon::parse($deal['besichtigung_am'])->format('Y-m-d H:i:s'),
                'erwarteter_abschluss' => $deal['erwarteter_abschluss'],
                'owner_id' => $lead->owner_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Crm::log('deal', $dealId, 'phase', null, 'besichtigung_geplant', null, 'A6');
            Crm::setLeadStage($lead, 'qualifiziert', $u, null, [
                'deal_id' => $dealId, 'checkliste' => json_encode([
                    'bedarf' => true, 'entscheider' => true, 'gebiet' => true, 'termin' => true,
                    'termin_am' => $c['termin_am'], 'termin_zeit' => $c['termin_zeit'] ?? null,
                ]),
            ]);
            Crm::setLifecycle($lead->kontakt_id, 'opportunity', $u, 'A6');
            Crm::aufgabe('deal', $dealId, 'termin', 'Termin: ' . trim($deal['titel']), $c['termin_am'], $c['termin_zeit'] ?? null,
                (int) $lead->owner_id, $u, $lead->sparte, 'Besichtigung / Folgegespräch (aus Qualifizierung)');
            return $dealId;
        });

        Crm::changed();
        return response()->json(['ok' => true, 'deal_id' => $dealId]);
    }

    /** POST /crm/leads/{id}/disqualifizieren {grund, notiz?, wiedervorlage_am?, duplikat_von?} — A7 / A9. */
    public function disqualifizieren(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $lead = Crm::lead($u, $id);
        if (!$lead) return response()->json(['error' => 'Lead nicht gefunden'], 404);
        if (!Crm::darfBearbeiten($u, $lead, 'leads')) return response()->json(['error' => 'Keine Berechtigung'], 403);
        if (!in_array($lead->stage, Crm::LEAD_OFFEN, true)) return Crm::fehler(['stage' => ['Lead ist bereits abgeschlossen.']], 409);

        [$d, $err] = Crm::pruefe($request->all(), [
            'grund' => 'required|string|max:60', 'notiz' => 'nullable|string|max:5000',
            'wiedervorlage_am' => 'nullable|date_format:Y-m-d', 'duplikat_von' => 'nullable|uuid',
        ]);
        if ($err) return Crm::fehler($err);
        $gruende = Crm::liste('disq_grund', true);
        $g = $gruende[$d['grund']] ?? null;
        if (!$g) return Crm::fehler(['grund' => ['Grund: ungültiger Wert.']]);
        $pflicht = $g->zusatz['pflicht'] ?? null;
        $notiz = trim((string) ($d['notiz'] ?? ''));
        $wv = $d['wiedervorlage_am'] ?? null;

        if ($pflicht === 'notiz' && $notiz === '') return Crm::fehler(['notiz' => ['Notiz ist bei diesem Grund Pflicht.']]);
        if ($pflicht === 'wiedervorlage') {
            if (!$wv) return Crm::fehler(['wiedervorlage_am' => ['Wiedervorlage-Datum ist Pflicht (Vertrag läuft).']]);
            if ($wv <= Crm::heute()) return Crm::fehler(['wiedervorlage_am' => ['Wiedervorlage muss in der Zukunft liegen.']]);
        }
        if ($pflicht === 'min_versuche' && !Crm::nichtErreichbarErlaubt($lead)) {
            return Crm::fehler(['grund' => ['„Nicht erreichbar“ erst nach ' . Crm::intSetting('a9_versuche', 6) . ' Kontaktversuchen wählbar (Regel A9).']]);
        }
        if ($pflicht === 'original') {
            $orig = $d['duplikat_von'] ?? null;
            if (!$orig || $orig === $id || !Crm::t('crm_leads')->where('id', $orig)->whereNull('deleted_at')->exists()) {
                return Crm::fehler(['duplikat_von' => ['Verweis auf den Original-Lead ist Pflicht.']]);
            }
        }

        DB::connection('tenant')->transaction(function () use ($u, $lead, $d, $notiz, $wv, $pflicht) {
            Crm::setLeadStage($lead, 'disqualifiziert', $u, null, [
                'disq_grund' => $d['grund'], 'disq_notiz' => $notiz !== '' ? $notiz : null,
                'wiedervorlage_am' => $wv, 'duplikat_von' => $pflicht === 'original' ? $d['duplikat_von'] : null,
            ]);
            Crm::log('lead', $lead->id, 'disq_grund', null, $d['grund'], $u);
            if ($pflicht === 'wiedervorlage' && $wv) {
                $faellig = \Carbon\Carbon::parse($wv)->subDays(Crm::intSetting('a7_vorlauf_tage', 90))->toDateString();
                if ($faellig < Crm::heute()) $faellig = Crm::heute();
                $k = Crm::t('crm_kontakte')->where('id', $lead->kontakt_id)->first();
                Crm::aufgabe('lead', $lead->id, 'wiedervorlage',
                    'Wiedervorlage: ' . trim(($k->vorname ?? '') . ' ' . ($k->nachname ?? '')) . ' (Vertrag läuft bis ' . \Carbon\Carbon::parse($wv)->format('d.m.Y') . ')',
                    $faellig, null, (int) $lead->owner_id, $u, $lead->sparte, 'Regel A7 — Wettbewerbervertrag läuft aus.');
            }
        });

        Crm::changed();
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        if (!Crm::can($u, 'admin')) return response()->json(['error' => 'Löschen nur durch die Geschäftsleitung'], 403);
        $n = Crm::t('crm_leads')->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        if (!$n) return response()->json(['error' => 'Lead nicht gefunden'], 404);
        Crm::log('lead', $id, 'deleted_at', null, now()->toDateTimeString(), $u);
        Crm::changed();
        return response()->json(['ok' => true]);
    }
}
