<?php

namespace App\Http\Controllers\Api;

use App\Helpers\IdGenerator;
use App\Http\Controllers\Controller;
use App\Support\BereichScope;
use App\Support\Crm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lead-Modul — Deals (spec 2.4, 3.3, 5.4). A deal comes from a qualified
 * lead (A6) or directly for an existing customer (Zusatzauftrag). Phase
 * changes ask for the required fields of every phase up to the target.
 * Gewonnen → A10 (Lifecycle Kunde, Übergabe-Aufgabe, optional Kunde in
 * Stammdaten with Bereich = Sparte). Verloren → A11 (Grund, Hinweis).
 */
class CrmDealController extends Controller
{
    /** Max rows per list response; narrow with filters/search beyond that. */
    private const LIMIT = 500;

    private function notReady()
    {
        return response()->json(['error' => 'Lead-Modul noch nicht eingerichtet (Migration ausstehend)'], 409);
    }

    private function base($u)
    {
        $q = Crm::t('crm_deals as d')
            ->join('crm_kontakte as k', 'k.id', '=', 'd.kontakt_id')
            ->leftJoin('crm_firmen as f', 'f.id', '=', 'd.firma_id')
            ->select('d.*', 'k.vorname', 'k.nachname', 'k.telefon', 'k.email', 'f.name as firma_name', 'f.plz', 'f.ort');
        Crm::scopeDeals($q, $u);
        return $q;
    }

    /** Derived: gesamtwert, wahrscheinlichkeit (from phase list), gewichteter wert, names. */
    private function zeilen($rows, $u)
    {
        $rows = collect($rows);
        $phasen = Crm::liste('deal_phase');
        $names = Crm::userNames($rows->pluck('owner_id'), (int) $u->tenant_id);
        return $rows->map(function ($r) use ($phasen, $names) {
            $p = $phasen[$r->phase] ?? null;
            $r->phase_name = $p->name ?? $r->phase;
            $r->kategorie = $p->kategorie ?? 'offen';
            $r->wahrscheinlichkeit = (int) ($p->wahrscheinlichkeit ?? 0);
            $r->gesamtwert_eur = $r->wert_monatlich_eur !== null && $r->laufzeit_monate !== null
                ? round((float) $r->wert_monatlich_eur * (int) $r->laufzeit_monate, 2) : null;
            $r->gewichtet_eur = $r->gesamtwert_eur !== null ? round($r->gesamtwert_eur * $r->wahrscheinlichkeit / 100, 2) : null;
            $r->owner_name = $names[(int) $r->owner_id] ?? ('#' . $r->owner_id);
            $r->kontakt_name = trim(($r->vorname ?? '') . ' ' . ($r->nachname ?? ''));
            $r->hat_angebot = (bool) $r->angebot_datei_pfad;
            unset($r->angebot_datei_pfad);
            return $r;
        });
    }

    /** GET /crm/deals?status=offen|gewonnen|verloren|alle&phase=&sparte=&owner=&von=&bis=&q= */
    public function index(Request $request)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $q = $this->base($u);
        $status = (string) $request->query('status', 'offen');
        if ($status === 'offen') $q->whereNotIn('d.phase', ['gewonnen', 'verloren']);
        elseif (in_array($status, ['gewonnen', 'verloren'], true)) $q->where('d.phase', $status);
        if ($request->filled('phase')) $q->whereIn('d.phase', explode(',', (string) $request->phase));
        if ($request->filled('sparte')) $q->where('d.sparte', $request->sparte);
        if ($request->filled('owner') && Crm::can($u, 'all')) $q->where('d.owner_id', (int) $request->owner);
        if ($request->filled('von')) $q->where('d.erwarteter_abschluss', '>=', $request->von);
        if ($request->filled('bis')) $q->where('d.erwarteter_abschluss', '<=', $request->bis);
        if ($request->filled('q')) {
            $s = '%' . addcslashes((string) $request->q, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('d.titel', 'like', $s)->orWhere('f.name', 'like', $s)->orWhere('k.nachname', 'like', $s));
        }
        $rows = $this->zeilen((clone $q)->orderBy('d.erwarteter_abschluss')->limit(self::LIMIT + 1)->get(), $u)->values();

        // Per-phase sums of the filtered list (spec 5.4) — aggregated in SQL.
        $agg = $this->aggregat(clone $q);
        $summen = [];
        foreach (Crm::liste('deal_phase') as $key => $p) {
            $a = $agg[$key] ?? null;
            $summen[] = ['phase' => $key, 'name' => $p->name, 'anzahl' => (int) ($a->n ?? 0),
                'gesamtwert_eur' => round((float) ($a->wert ?? 0), 2), 'gewichtet_eur' => round((float) ($a->gew ?? 0), 2)];
        }

        // Header tiles: whole visible pipeline, independent of the list filter.
        $all = $this->aggregat($this->base($u));
        $kat = Crm::liste('deal_phase')->map(fn ($p) => $p->kategorie ?: 'offen');
        $sum = fn (callable $f, string $col) => round((float) $all->filter(fn ($a, $ph) => $f($ph))->sum($col), 2);
        $offen = fn ($ph) => ($kat[$ph] ?? 'offen') === 'offen';
        $in = fn (array $list) => fn ($ph) => in_array($ph, $list, true);
        $kpi = [
            'offen' => (int) $sum($offen, 'n'), 'offen_eur' => $sum($offen, 'wert'), 'gewichtet_eur' => $sum($offen, 'gew'),
            'besichtigung' => (int) $sum($in(['besichtigung_geplant', 'besichtigung_erfolgt']), 'n'),
            'angebot' => (int) $sum($in(['angebot_versendet', 'verhandlung']), 'n'),
            'angebot_eur' => $sum($in(['angebot_versendet', 'verhandlung']), 'wert'),
            'gewonnen' => (int) $sum($in(['gewonnen']), 'n'), 'gewonnen_eur' => $sum($in(['gewonnen']), 'wert'),
            'verloren' => (int) $sum($in(['verloren']), 'n'),
        ];
        return response()->json([
            'data' => $rows->take(self::LIMIT)->values(), 'mehr' => $rows->count() > self::LIMIT,
            'summen' => $summen, 'kpi' => $kpi,
        ]);
    }

    /** phase => {n, wert (Σ monthly × months), gew (weighted by phase probability)} for a deal query. */
    private function aggregat($q)
    {
        return DB::connection('tenant')->query()
            ->fromSub($q->select('d.phase', 'd.wert_monatlich_eur', 'd.laufzeit_monate'), 'x')
            ->leftJoin('crm_listen as pl', fn ($j) => $j->on('pl.schluessel', '=', 'x.phase')->where('pl.typ', '=', 'deal_phase'))
            ->groupBy('x.phase')
            ->selectRaw('x.phase, COUNT(*) AS n,
                COALESCE(SUM(x.wert_monatlich_eur * x.laufzeit_monate), 0) AS wert,
                COALESCE(SUM(x.wert_monatlich_eur * x.laufzeit_monate * COALESCE(pl.wahrscheinlichkeit, 0) / 100), 0) AS gew')
            ->get()->keyBy('phase');
    }

    public function show(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $row = $this->base($u)->where('d.id', $id)->first();
        if (!$row) return response()->json(['error' => 'Deal nicht gefunden'], 404);
        $deal = $this->zeilen([$row], $u)->first();
        $deal->kontakt = Crm::t('crm_kontakte')->where('id', $deal->kontakt_id)->first();
        $deal->firma = $deal->firma_id ? Crm::t('crm_firmen')->where('id', $deal->firma_id)->first() : null;
        $deal->lead = $deal->lead_id ? Crm::t('crm_leads')->where('id', $deal->lead_id)->first(['id', 'stage', 'sparte', 'checkliste']) : null;
        $deal->aktivitaeten = app(CrmStammController::class)->timeline($u, ['deal_id' => [$id], 'lead_id' => $deal->lead_id ? [$deal->lead_id] : []]);
        $deal->aufgaben = Crm::aufgabenFuer(['deal' => [$id], 'lead' => $deal->lead_id ? [$deal->lead_id] : []]);
        $deal->protokoll = Crm::t('crm_protokoll')->where('objekt', 'deal')->where('objekt_id', $id)->orderByDesc('id')->limit(200)->get();
        $deal->darf_bearbeiten = Crm::darfBearbeiten($u, $deal, 'deals');
        $deal->darf_wiedereroeffnen = Crm::can($u, 'all');
        return response()->json($deal);
    }

    private function feldRegeln(): array
    {
        return [
            'titel' => 'sometimes|required|string|max:250', 'objektadresse' => 'sometimes|nullable|string|max:300',
            'besichtigung_am' => 'sometimes|nullable|date', 'besichtigung_notiz' => 'sometimes|nullable|string|max:5000',
            'wert_monatlich_eur' => 'sometimes|nullable|numeric|min:0|max:100000000', 'laufzeit_monate' => 'sometimes|nullable|integer|min:1|max:600',
            'erwarteter_abschluss' => 'sometimes|required|date_format:Y-m-d', 'vertragsbeginn' => 'sometimes|nullable|date_format:Y-m-d',
            'verlust_grund' => 'sometimes|nullable|string|max:60', 'verlust_notiz' => 'sometimes|nullable|string|max:5000',
        ];
    }

    private function normalize(array $d): array
    {
        if (!empty($d['besichtigung_am'])) $d['besichtigung_am'] = \Carbon\Carbon::parse($d['besichtigung_am'])->format('Y-m-d H:i:s');
        return array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $d);
    }

    /** POST /crm/deals — direct deal (Zusatzauftrag) only for existing customers. */
    public function store(Request $request)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        if (!Crm::can($u, 'deals_edit')) return response()->json(['error' => 'Keine Berechtigung'], 403);
        [$d, $err] = Crm::pruefe($request->all(), [
            'kontakt_id' => 'required|uuid', 'titel' => 'required|string|max:250', 'sparte' => 'required|string|max:190',
            'objektadresse' => 'required|string|max:300', 'besichtigung_am' => 'required|date',
            'erwarteter_abschluss' => 'required|date_format:Y-m-d',
        ]);
        if ($err) return Crm::fehler($err);
        $k = Crm::t('crm_kontakte')->where('id', $d['kontakt_id'])->whereNull('deleted_at')->first();
        if (!$k) return Crm::fehler(['kontakt_id' => ['Kontakt nicht gefunden.']], 404);
        $firma = $k->firma_id ? Crm::t('crm_firmen')->where('id', $k->firma_id)->first() : null;
        $bestand = in_array($k->lifecycle_stage, ['kunde', 'ehemaliger_kunde'], true) || ($firma && $firma->kunde_id);
        if (!$bestand) {
            return Crm::fehler(['kontakt_id' => ['Ein Deal entsteht nur aus einem qualifizierten Lead — direkt nur bei Bestandskunden (Zusatzauftrag).']]);
        }
        if (!Crm::sparteGueltig($u, $d['sparte'])) return Crm::fehler(['sparte' => ['Sparte: ungültiger Wert.']]);

        $id = (string) Str::uuid();
        Crm::t('crm_deals')->insert($this->normalize(array_diff_key($d, ['kontakt_id' => 1])) + [
            'id' => $id, 'kontakt_id' => $k->id, 'firma_id' => $k->firma_id, 'phase' => 'besichtigung_geplant',
            'phase_changed_at' => now(), 'owner_id' => (int) $u->id, 'kunde_id' => $firma->kunde_id ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Crm::log('deal', $id, 'phase', null, 'besichtigung_geplant', $u);
        Crm::changed();
        return response()->json(['id' => $id], 201);
    }

    /** PUT /crm/deals/{id} — fields only (phase via /phase). Closed deals are read-only. */
    public function update(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $deal = Crm::deal($u, $id);
        if (!$deal) return response()->json(['error' => 'Deal nicht gefunden'], 404);
        if (!Crm::darfBearbeiten($u, $deal, 'deals')) return response()->json(['error' => 'Keine Berechtigung'], 403);
        if (in_array($deal->phase, ['gewonnen', 'verloren'], true)) return Crm::fehler(['phase' => ['Abgeschlossener Deal ist schreibgeschützt.']], 409);
        [$d, $err] = Crm::pruefe($request->all(), $this->feldRegeln() + ['owner_id' => 'sometimes|nullable|integer']);
        if ($err) return Crm::fehler($err);
        $neuerOwner = CrmStammController::ownerWechsel($u, $d['owner_id'] ?? null, (int) $deal->owner_id, $err);
        if ($err) return Crm::fehler($err, 403);
        unset($d['owner_id'], $d['verlust_grund'], $d['verlust_notiz']);

        // Required fields of the CURRENT phase must not be emptied.
        $fehlt = $d ? Crm::fehlendePflichtfelder($deal, $this->normalize($d), $deal->phase) : [];
        if ($fehlt) return Crm::fehler(array_map(fn ($m) => [$m], $fehlt));

        if ($d) Crm::t('crm_deals')->where('id', $id)->update($this->normalize($d) + ['updated_at' => now()]);
        if ($neuerOwner) Crm::setOwner('crm_deals', 'deal', $deal, $neuerOwner, $u);
        Crm::changed();
        return response()->json(['ok' => true]);
    }

    /**
     * POST /crm/deals/{id}/phase {phase, …fields, kunde_anlegen?, wiedervorlage_am?}
     * 422 {fehlend:{feld:msg}} lists what the dialog must still ask for.
     */
    public function phase(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $deal = Crm::deal($u, $id);
        if (!$deal) return response()->json(['error' => 'Deal nicht gefunden'], 404);
        if (!Crm::darfBearbeiten($u, $deal, 'deals')) return response()->json(['error' => 'Keine Berechtigung'], 403);

        $ziel = (string) $request->input('phase');
        $phasen = Crm::liste('deal_phase', true);
        if (!isset($phasen[$ziel])) return Crm::fehler(['phase' => ['Ungültige Phase.']]);
        if ($ziel === $deal->phase) return response()->json(['ok' => true]);
        $geschlossen = in_array($deal->phase, ['gewonnen', 'verloren'], true);
        if ($geschlossen && !Crm::can($u, 'all')) {
            return Crm::fehler(['phase' => ['Abgeschlossene Deals kann nur die Vertriebsleitung wieder öffnen.']], 409);
        }

        [$d, $err] = Crm::pruefe($request->all(), $this->feldRegeln() + [
            'kunde_anlegen' => 'nullable|boolean', 'wiedervorlage_am' => 'nullable|date_format:Y-m-d',
        ]);
        if ($err) return Crm::fehler($err);
        $kundeAnlegen = (bool) ($d['kunde_anlegen'] ?? false);
        $wv = $d['wiedervorlage_am'] ?? null;
        unset($d['kunde_anlegen'], $d['wiedervorlage_am']);
        $patch = $this->normalize($d);
        if ($ziel === 'verloren' && !empty($patch['verlust_grund']) && !Crm::gueltig('verlust_grund', $patch['verlust_grund'])) {
            return Crm::fehler(['verlust_grund' => ['Verlustgrund: ungültiger Wert.']]);
        }
        $fehlt = Crm::fehlendePflichtfelder($deal, $patch, $ziel);
        if ($fehlt) return response()->json(['fehlend' => $fehlt, 'errors' => array_map(fn ($m) => [$m], $fehlt), 'error' => reset($fehlt)], 422);
        if ($ziel !== 'verloren') { $patch['verlust_grund'] = null; $patch['verlust_notiz'] = null; }

        $kundeId = DB::connection('tenant')->transaction(function () use ($u, $deal, $ziel, $patch, $kundeAnlegen, $wv) {
            Crm::t('crm_deals')->where('id', $deal->id)->update($patch + ['phase' => $ziel, 'phase_changed_at' => now(), 'updated_at' => now()]);
            Crm::log('deal', $deal->id, 'phase', $deal->phase, $ziel, $u);
            $kundeId = null;

            if ($ziel === 'gewonnen') {
                // A10: Lifecycle → Kunde, hand-over task for the Objektleitung of the Sparte.
                Crm::setLifecycle($deal->kontakt_id, 'kunde', $u, 'A10');
                if ($kundeAnlegen) $kundeId = $this->kundeAnlegen($u, $deal);
                $this->uebergabe($u, $deal);
            }
            if ($ziel === 'verloren' && $wv) {
                Crm::aufgabe('deal', $deal->id, 'wiedervorlage', 'Wiedervorlage: ' . $deal->titel, $wv, null, (int) $deal->owner_id, $u, $deal->sparte, 'Regel A11 — Deal verloren.');
            }
            return $kundeId;
        });

        if ($ziel === 'verloren') {
            // A11: lifecycle stays Opportunity; Vertriebsleitung decides on a reset.
            $grund = Crm::liste('verlust_grund')[$patch['verlust_grund'] ?? '']->name ?? ($patch['verlust_grund'] ?? '');
            Crm::hinweis(['all'], 'Deal verloren: ' . $deal->titel, 'Grund: ' . $grund . '. Lifecycle bleibt Opportunity — ggf. per Admin zurücksetzen.', '#interessenten', null, (int) $u->id);
        }
        Crm::changed();
        if ($kundeId) \App\Helpers\Realtime::changed('kunden');
        return response()->json(['ok' => true, 'kunde_id' => $kundeId]);
    }

    /** A10 hand-over: one task per Objektleitung user of the Sparte; fallback Vertriebsleitung, then owner. */
    private function uebergabe($u, object $deal): void
    {
        $tenantId = (int) $u->tenant_id;
        $ziel = Crm::usersWith($tenantId, ['uebergabe'])->filter(fn ($x) => !Crm::can($x, 'all') && (!$deal->sparte || BereichScope::userAllows($x, $deal->sparte)));
        if ($ziel->isEmpty()) $ziel = Crm::usersWith($tenantId, ['all'])->filter(fn ($x) => !$x->is_owner)->take(1);
        $ids = $ziel->pluck('id')->map(fn ($i) => (int) $i)->take(5)->all() ?: [(int) $deal->owner_id];
        $start = $deal->vertragsbeginn ?? Crm::t('crm_deals')->where('id', $deal->id)->value('vertragsbeginn');
        foreach ($ids as $an) {
            Crm::aufgabe('deal', $deal->id, 'sonstiges', 'Übergabe an Operative: ' . $deal->titel, Crm::heute(), null, $an, $u, $deal->sparte,
                'Regel A10 — Deal gewonnen' . ($start ? ', Vertragsbeginn ' . \Carbon\Carbon::parse($start)->format('d.m.Y') : '') . '.');
        }
    }

    /** Kunde in Stammdaten (Bereich = Sparte) unless the firm is already linked. */
    private function kundeAnlegen($u, object $deal): ?string
    {
        if (!$u->hasPermission('stammdaten.kunden.edit')) return null;
        $firma = $deal->firma_id ? Crm::t('crm_firmen')->where('id', $deal->firma_id)->first() : null;
        if ($firma && $firma->kunde_id) {
            Crm::t('crm_deals')->where('id', $deal->id)->update(['kunde_id' => $firma->kunde_id]);
            return $firma->kunde_id;
        }
        $k = Crm::t('crm_kontakte')->where('id', $deal->kontakt_id)->first();
        $bereich = $deal->sparte && BereichScope::bereichInputAllowed($u, $deal->sparte) ? $deal->sparte : BereichScope::defaultBereichFor($u);
        $name = $firma->name ?? trim(($k->vorname ?? '') . ' ' . ($k->nachname ?? ''));
        $sch = Schema::connection('tenant');

        $kundeId = null;
        for ($i = 0; $i < 3 && !$kundeId; $i++) {
            $kid = IdGenerator::next('kunden', 'kunde_id', 'K');
            $payload = [
                'kunde_id' => $kid, 'kundenname' => mb_substr($name, 0, 200), 'kunden_nr_intern' => $kid,
                'abrechnungstyp' => 'Pro Einheit', 'anschrift_typ' => $firma ? 'Firma' : 'Privat', 'strasse' => $firma->strasse ?? null, 'plz' => $firma->plz ?? null,
                'ort' => $firma->ort ?? null, 'land' => 'D', 'telefon1' => $firma->telefon ?? $k->telefon ?? null,
                'mobil' => $k->mobil ?? null, 'email' => $k->email ?? null, 'website' => $firma->website ?? null,
                'haupt_ap' => trim(($k->vorname ?? '') . ' ' . ($k->nachname ?? '')) ?: null,
                'notiz' => 'Aus Deal „' . $deal->titel . '“ (Lead-Modul) übernommen.', 'bereich' => $bereich, 'aktiv' => 1,
                'created_at' => now(),
            ];
            if ($sch->hasColumn('kunden', 'updated_at')) $payload['updated_at'] = now();
            if ($sch->hasColumn('kunden', 'betreuer_id')) $payload['betreuer_id'] = $deal->owner_id;
            if ($sch->hasColumn('kunden', 'bereiche')) $payload['bereiche'] = $bereich ? json_encode([$bereich]) : null;
            try {
                Crm::t('kunden')->insert($payload);
                $kundeId = $kid;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // concurrent create took the id — retry with the next one
            }
        }
        if (!$kundeId) abort(response()->json(['error' => 'Kunde konnte nicht angelegt werden — bitte erneut versuchen.'], 409));
        Crm::t('crm_deals')->where('id', $deal->id)->update(['kunde_id' => $kundeId]);
        if ($firma) Crm::t('crm_firmen')->where('id', $firma->id)->update(['kunde_id' => $kundeId, 'updated_at' => now()]);
        Crm::log('deal', $deal->id, 'kunde_id', null, $kundeId, $u, 'A10');
        return $kundeId;
    }

    /** POST /crm/deals/{id}/angebot (multipart `datei`) — private disk. */
    public function angebotUpload(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        $deal = Crm::deal($u, $id);
        if (!$deal) return response()->json(['error' => 'Deal nicht gefunden'], 404);
        if (!Crm::darfBearbeiten($u, $deal, 'deals')) return response()->json(['error' => 'Keine Berechtigung'], 403);
        [, $err] = Crm::pruefe($request->all(), ['datei' => 'required|' . Crm::DATEI_REGEL]);
        if ($err) return Crm::fehler($err);
        [, $pfad, $name] = Crm::datei($request->file('datei'), 'deals/' . $id);
        if ($deal->angebot_datei_pfad) Storage::disk('local')->delete($deal->angebot_datei_pfad);
        Crm::t('crm_deals')->where('id', $id)->update(['angebot_datei_pfad' => $pfad, 'angebot_datei_name' => $name, 'updated_at' => now()]);
        Crm::changed();
        return response()->json(['ok' => true, 'angebot_datei_name' => $name]);
    }

    public function angebotDownload(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $deal = Crm::deal($request->user(), $id);
        if (!$deal || !$deal->angebot_datei_pfad || !Storage::disk('local')->exists($deal->angebot_datei_pfad)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }
        return response()->download(Storage::disk('local')->path($deal->angebot_datei_pfad), $deal->angebot_datei_name ?: 'Angebot',
            ['Cache-Control' => 'no-store']);
    }

    public function destroy(Request $request, string $id)
    {
        if (!Crm::ready()) return $this->notReady();
        $u = $request->user();
        if (!Crm::can($u, 'admin')) return response()->json(['error' => 'Löschen nur durch die Geschäftsleitung'], 403);
        $n = Crm::t('crm_deals')->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        if (!$n) return response()->json(['error' => 'Deal nicht gefunden'], 404);
        Crm::log('deal', $id, 'deleted_at', null, now()->toDateTimeString(), $u);
        Crm::changed();
        return response()->json(['ok' => true]);
    }
}
